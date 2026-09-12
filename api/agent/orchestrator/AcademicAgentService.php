<?php
declare(strict_types=1);

if (!function_exists('tekg_agent_create_default_plugins')) {
    require_once dirname(__DIR__) . '/plugin_registry.php';
}

require_once __DIR__ . '/traits/AcademicAgentWorkflowTrait.php';
require_once __DIR__ . '/traits/AcademicAgentPlanningTrait.php';
require_once __DIR__ . '/traits/AcademicAgentPluginResultTrait.php';
require_once __DIR__ . '/traits/AcademicAgentNarrationTrait.php';
require_once __DIR__ . '/traits/AcademicAgentEvidenceTrait.php';
require_once dirname(__DIR__) . '/contracts/PluginResultContract.php';
require_once dirname(__DIR__) . '/contracts/EvidencePackage.php';
require_once dirname(__DIR__) . '/contracts/EvidenceWalk.php';
require_once dirname(__DIR__) . '/contracts/ReportPlan.php';
require_once dirname(__DIR__) . '/contracts/ReportIntegrityGate.php';
require_once dirname(__DIR__) . '/contracts/UserFacingWritingContext.php';
require_once dirname(__DIR__) . '/contracts/ModeComparisonEvaluation.php';

final class TekgAcademicAgentService
{
    use TekgAcademicAgentWorkflowTrait;
    use TekgAcademicAgentPlanningTrait;
    use TekgAcademicAgentPluginResultTrait;
    use TekgAcademicAgentNarrationTrait;
    use TekgAcademicAgentEvidenceTrait;

    private TekgAgentEntityNormalizer $normalizer;
    private TekgAgentLlmClient $llm;
    private TekgAgentCitationResolver $citationResolver;
    /** @var array<string,TekgAgentPluginInterface> */
    private array $plugins;

    public function __construct(private readonly array $config)
    {
        $neo4j = new TekgAgentNeo4jClient($config);
        $this->normalizer = new TekgAgentEntityNormalizer();
        $this->llm = new TekgAgentLlmClient($config);
        $this->citationResolver = new TekgAgentCitationResolver();
        $this->plugins = tekg_agent_create_default_plugins($config, $neo4j, $this->llm, $this->citationResolver);
    }

    public function handle(array $payload): array
    {
        return $this->execute($payload, null);
    }

    public function stream(array $payload, callable $emit): array
    {
        return $this->execute($payload, $emit);
    }

    private function execute(array $payload, ?callable $emit): array
    {
        $question = trim((string)($payload['question'] ?? ''));
        if ($question === '') {
            throw new InvalidArgumentException('Question is required.');
        }
        $originalQuestion = $question;

        $requestId = trim((string)($payload['request_id'] ?? ''));
        if ($requestId === '') {
            $requestId = tekg_agent_make_request_id();
        }
        $startedAt = microtime(true);
        $runtimeConfig = $this->runtimeConfig($payload, $requestId);
        $this->llm = new TekgAgentLlmClient($runtimeConfig);
        $this->applyExecutionBudget($runtimeConfig);
        $this->logDiagnostic($requestId, 'request_started', [
            'question' => $question,
            'mode' => (string)($payload['mode'] ?? 'academic'),
            'execution_timeout' => (int)($runtimeConfig['agent_execution_timeout'] ?? 0),
            'llm_json_timeout' => (int)($runtimeConfig['llm_json_timeout'] ?? 0),
            'llm_six_stage_node_timeout' => (int)($runtimeConfig['llm_six_stage_node_timeout'] ?? 0),
            'llm_answer_timeout' => (int)($runtimeConfig['llm_answer_timeout'] ?? 0),
            'llm_narrator_timeout' => (int)($runtimeConfig['llm_narrator_timeout'] ?? 0),
        ]);

        $answerLanguage = tekg_agent_detect_language($question, trim((string)($payload['language'] ?? 'english')));
        $processLanguage = $this->resolveProcessLanguage($answerLanguage);
        $controlModel = $this->resolveControlModel($payload);
        $coreModel = $this->resolveCoreModel($payload);
        $sufficiencyModel = $this->resolveSufficiencyModel($payload);
        $expertModel = $this->resolveExpertModel($payload);
        $narratorModel = $this->resolveNarratorModel($payload);
        $answerStructureModel = $this->resolveAnswerStructureModel($payload);
        $sessionId = trim((string)($payload['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = tekg_agent_make_session_id();
        }

        $sessionMemory = tekg_agent_load_session_memory($sessionId);
        $eventSequence = 0;
        $contextResult = (new ConversationContextResolver($this->normalizer, $this->llm))->resolve(
            $originalQuestion,
            $answerLanguage,
            $sessionMemory,
            'agent',
            $controlModel
        );
        $this->logConversationContext($requestId, 'agent', $contextResult);
        if ($contextResult->status === 'needs_clarification') {
            return $this->buildContextClarificationResponse(
                $originalQuestion,
                $payload,
                $requestId,
                $answerLanguage,
                $sessionId,
                $controlModel,
                $contextResult,
                $emit,
                $eventSequence
            );
        }
        $question = $contextResult->effectiveQuestion;
        $analysis = $this->normalizer->analyze($question, $answerLanguage);
        $analysis = $contextResult->applyToAnalysis($analysis);
        $analysis['answer_language'] = $answerLanguage;
        $analysis['language'] = 'english';
        $analysis['session_memory'] = $sessionMemory;
        $analysis['conversation_context'] = $contextResult->toArray();
        $analysis['original_question'] = $originalQuestion;
        $analysis['effective_question'] = $question;
        $analysis['request_context'] = [
            'source_page' => (string)($payload['source_page'] ?? ''),
            'current_url' => (string)($payload['current_url'] ?? ''),
            'page_context' => (array)($payload['page_context'] ?? []),
            'graph_context' => (array)($payload['graph_context'] ?? []),
        ];

        $planning = $this->buildPlan($question, $analysis, $sessionMemory);
        $routingPolicy = $this->routingPolicyFor($analysis);
        $pluginQueue = $this->initialPluginQueue($analysis, $planning, $routingPolicy);
        $pluginResults = [];
        $pluginCalls = [];
        $reasoningTrace = [];
        $detailCounter = 0;
        $workflowState = $this->initialWorkflowState();
        $sixStageArtifacts = [];
        $collectionState = $this->initialCollectionState($analysis, $planning, $routingPolicy, $pluginQueue);
        $sufficiencyDecision = [
            'is_sufficient' => false,
            'reason' => 'No expert evidence has been collected yet.',
            'missing_dimensions' => array_values((array)($collectionState['active_gaps'] ?? [])),
            'recommended_next_experts' => array_values((array)($collectionState['remaining_candidates'] ?? [])),
        ];

        if ($this->shouldUseCompactPreflightGate($question, $analysis)) {
            $response = $this->buildCompactPreflightResponse(
                $question,
                trim((string)($payload['mode'] ?? 'academic')) ?: 'academic',
                $requestId,
                $answerLanguage,
                $sessionId,
                $analysis,
                $planning,
                $reasoningTrace,
                $pluginCalls,
                [],
                'low',
                ['Full Agent workflow skipped by compact preflight before six-stage LLM nodes.'],
                [
                    'control' => $controlModel,
                    'core' => $coreModel,
                    'sufficiency' => $sufficiencyModel,
                    'expert' => $expertModel,
                    'narrator' => $narratorModel,
                    'answer_structure' => $answerStructureModel,
                    'writer' => $coreModel,
                    'writer_draft' => $coreModel,
                    'writer_polisher' => $coreModel,
                ],
                [],
                $pluginResults,
                $collectionState,
                $sufficiencyDecision,
                $workflowState
            );
            $response['question'] = $originalQuestion;
            $response['context_resolution'] = $contextResult->toArray();
            $response['six_stage_artifacts'] = [];
            $response['answer'] = ReportIntegrityGate::normalizeUrlsInText((string)$response['answer']);
            $updatedMemory = $this->updateSessionMemory($sessionMemory, $response['analysis'], $planning, $pluginResults, [], [], $collectionState, []);
            $updatedMemory = ConversationMemory::appendCompletedTurn(
                $updatedMemory,
                'agent',
                $originalQuestion,
                $question,
                (string)$response['answer'],
                (array)$response['analysis']
            );
            tekg_agent_save_session_memory($sessionId, $updatedMemory);
            $this->logConversationTurnRecorded($requestId, 'agent', $updatedMemory);
            $this->emitEvent($emit, $eventSequence, [
                'type' => 'answer',
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'language' => $answerLanguage,
                'message' => (string)$response['answer'],
            ]);
            $this->emitEvent($emit, $eventSequence, [
                'type' => 'done',
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'payload' => [
                    'confidence' => $response['confidence'],
                    'used_plugins' => $response['used_plugins'],
                    'answer' => (string)$response['answer'],
                    'language' => $answerLanguage,
                    'writing_failed' => false,
                    'failure_stage' => '',
                    'failure_reason' => '',
                    'workflow_state' => $response['workflow_state'],
                ],
            ]);
            return $response;
        }

        $this->activateWorkflowStage($workflowState, 'Understanding', null, $emit, $eventSequence, $sessionId);
        $understandingNode = $this->llm->runUnderstandingNode($controlModel, $processLanguage, [
            'question' => $question,
            'deterministic_analysis' => $analysis,
            'session_memory' => $sessionMemory,
        ]);
        $this->recordSixStageArtifact($sixStageArtifacts, 'understanding', $understandingNode, $emit, $eventSequence, $sessionId, '', $processLanguage);
        if (!$understandingNode->ok) {
            $this->logDiagnostic($requestId, 'six_stage_control_fallback', [
                'stage' => 'Understanding',
                'model' => $controlModel,
                'errors' => $understandingNode->errors,
            ]);
            $understandingNode = $this->fallbackUnderstandingNodeResult($question, $answerLanguage, $analysis, $understandingNode);
            $this->recordSixStageArtifact($sixStageArtifacts, 'understanding', $understandingNode, $emit, $eventSequence, $sessionId, '', $processLanguage);
        }
        $analysis['six_stage_understanding'] = $understandingNode->parsed_json;
        $this->emitAnalysisThoughtFlow($emit, $sessionId, $narratorModel, $processLanguage, $analysis, $eventSequence);
        $this->activateWorkflowStage($workflowState, 'Planning', 'Understanding', $emit, $eventSequence, $sessionId);
        $planningNode = $this->llm->runPlanningNode($controlModel, $processLanguage, [
            'question' => $question,
            'understanding_result' => $understandingNode->parsed_json,
            'deterministic_plan' => $planning,
            'candidate_plugin_queue' => $pluginQueue,
            'routing_policy' => $routingPolicy,
            'plugin_directory' => tekg_agent_plugin_directory(),
        ]);
        $this->recordSixStageArtifact($sixStageArtifacts, 'planning', $planningNode, $emit, $eventSequence, $sessionId, '', $processLanguage);
        if (!$planningNode->ok) {
            $this->logDiagnostic($requestId, 'six_stage_control_fallback', [
                'stage' => 'Planning',
                'model' => $controlModel,
                'errors' => $planningNode->errors,
            ]);
            $planningNode = $this->fallbackPlanningNodeResult($planning, $pluginQueue, $planningNode);
            $this->recordSixStageArtifact($sixStageArtifacts, 'planning', $planningNode, $emit, $eventSequence, $sessionId, '', $processLanguage);
        }
        $planning['six_stage_plan'] = $planningNode->parsed_json;
        $this->emitPlanningThoughtFlow($emit, $sessionId, $narratorModel, $processLanguage, $planning, $eventSequence);
        $this->activateWorkflowStage($workflowState, 'Collecting', 'Planning', $emit, $eventSequence, $sessionId);
        $collectingNode = $this->llm->runCollectingNode($sufficiencyModel, $processLanguage, [
            'question' => $question,
            'understanding_result' => $understandingNode->parsed_json,
            'research_plan' => $planningNode->parsed_json,
            'collection_state' => $collectionState,
            'plugin_results' => $pluginResults,
            'remaining_plugins' => $pluginQueue,
            'plugin_directory' => tekg_agent_plugin_directory(),
        ]);
        $this->recordSixStageArtifact($sixStageArtifacts, 'collecting', $collectingNode, $emit, $eventSequence, $sessionId, '', $processLanguage);
        if (!$collectingNode->ok) {
            return $this->buildSixStageFailureResponse($question, $payload, $requestId, $answerLanguage, $sessionId, $sufficiencyModel, 'Collecting', $collectingNode, $sixStageArtifacts, $workflowState);
        }
        $collectionState['six_stage_collection_decisions'][] = $collectingNode->parsed_json;
        $this->logDiagnostic($requestId, 'planning_completed', [
            'intent' => (string)($analysis['intent'] ?? ''),
            'complexity' => (string)($analysis['complexity'] ?? ''),
            'plugin_queue' => $pluginQueue,
            'knowledge_gaps' => (array)($planning['knowledge_gaps'] ?? []),
        ]);

        $reasoningTrace[] = [
            'step' => 'planning',
            'title' => 'Planning',
            'status' => 'done',
            'details' => $planning['narrative'],
        ];

        $executedBusinessPlugins = [];
        for ($index = 0; $index < count($pluginQueue); $index++) {
            $pluginName = $pluginQueue[$index];
            $plugin = $this->plugins[$pluginName] ?? null;
            if (!$plugin instanceof TekgAgentPluginInterface) {
                continue;
            }
            if (isset($executedBusinessPlugins[$pluginName])
                || !$this->academicBusinessPluginMayRun($pluginName, $analysis, $pluginResults)
            ) {
                continue;
            }
            $executedBusinessPlugins[$pluginName] = true;

            if (($workflowState['current_stage'] ?? '') !== 'Collecting') {
                $previousStage = ($workflowState['current_stage'] ?? '') === 'Executing' ? 'Executing' : null;
                $this->activateWorkflowStage($workflowState, 'Collecting', $previousStage, $emit, $eventSequence, $sessionId);
            }
            $this->activateWorkflowStage($workflowState, 'Executing', 'Collecting', $emit, $eventSequence, $sessionId);
            $this->emitEvent($emit, $eventSequence, [
                'type' => 'tool_selected',
                'session_id' => $sessionId,
                'plugin_name' => $pluginName,
                'message' => $this->narrateEvent(
                    $narratorModel,
                    $processLanguage,
                    [
                        'type' => 'tool_selected',
                        'plugin_name' => $pluginName,
                        'planning' => $planning,
                    ],
                    $this->toolSelectedMessage($pluginName, $planning, $processLanguage)
                ),
            ]);
            $result = $plugin->run([
                'question' => $question,
                'analysis' => $analysis,
                'plugin_results' => $pluginResults,
                'planning' => $planning,
                'config' => $this->expertConfig($expertModel),
            ]);
            $result = PluginResultContract::enforce($pluginName, $result);
            $result = $this->augmentPluginResult($pluginName, $result, $analysis, $planning);
            $this->logDiagnostic($requestId, 'plugin_completed', [
                'plugin_name' => $pluginName,
                'status' => (string)($result['status'] ?? 'unknown'),
                'result_counts' => (array)($result['result_counts'] ?? []),
                'latency_ms' => (int)($result['latency_ms'] ?? 0),
            ]);

            $pluginResults[$pluginName] = $result;
            $pluginCalls[] = $result;
            $collectionState = $this->updateCollectionState($collectionState, $pluginName, $result);
            if ($this->executingReviewRequired($pluginName, $result)) {
                $executingReviewNode = $this->llm->runExecutingReviewNode($expertModel, $processLanguage, [
                    'question' => $question,
                    'plugin_name' => $pluginName,
                    'plugin_result' => $this->pluginResultForLlmReview($pluginName, $result),
                    'collection_state' => $collectionState,
                    'research_plan' => $planningNode->parsed_json,
                ]);
                $this->recordSixStageArtifact($sixStageArtifacts, 'executing', $executingReviewNode, $emit, $eventSequence, $sessionId, $pluginName, $processLanguage);
                if (!$executingReviewNode->ok) {
                    if ($this->shouldContinueAfterExecutingReviewFailure($result, $executingReviewNode)) {
                        $result = $this->applyExecutingReviewFailureCaveat($result, $executingReviewNode);
                        $pluginResults[$pluginName] = $result;
                        $lastPluginCallIndex = array_key_last($pluginCalls);
                        if ($lastPluginCallIndex !== null) {
                            $pluginCalls[$lastPluginCallIndex] = $result;
                        }
                        $this->logDiagnostic($requestId, 'executing_review_failed_nonfatal', [
                            'plugin_name' => $pluginName,
                            'errors' => $executingReviewNode->errors,
                        ]);
                    } else {
                        return $this->buildSixStageFailureResponse($question, $payload, $requestId, $answerLanguage, $sessionId, $expertModel, 'Executing', $executingReviewNode, $sixStageArtifacts, $workflowState);
                    }
                }
            } else {
                $result = $this->markExecutingReviewNotRequired($result, 'This deterministic or empty plugin result does not require LLM interpretation.');
                $pluginResults[$pluginName] = $result;
                $lastPluginCallIndex = array_key_last($pluginCalls);
                if ($lastPluginCallIndex !== null) {
                    $pluginCalls[$lastPluginCallIndex] = $result;
                }
                $this->logDiagnostic($requestId, 'executing_review_not_required', [
                    'plugin_name' => $pluginName,
                    'reason' => $result['executing_review_reason'],
                ]);
            }

            $detailId = 'tool-' . (++$detailCounter);
            $payloadForUi = $this->toolPayloadForUi($result);

            $this->emitEvent($emit, $eventSequence, [
                'type' => 'tool_result',
                'session_id' => $sessionId,
                'plugin_name' => $pluginName,
                'display_label' => (string)($result['display_label'] ?? $pluginName),
                'summary' => (string)($result['display_summary'] ?? $result['query_summary'] ?? ''),
                'message' => $this->narrateEvent(
                    $narratorModel,
                    $processLanguage,
                    [
                        'type' => 'tool_result',
                        'plugin_name' => $pluginName,
                        'result' => $result,
                    ],
                    (string)(($result['display_details']['result_message'] ?? '') ?: ($result['display_summary'] ?? $result['query_summary'] ?? ''))
                ),
                'detail_payload_id' => $detailId,
                'payload' => $payloadForUi,
            ]);

            $reasoningTrace[] = [
                'step' => 'querying_plugins',
                'title' => $pluginName,
                'status' => (string)($result['status'] ?? 'ok'),
                'details' => (string)($result['display_summary'] ?? $result['query_summary'] ?? ''),
            ];

            foreach ($this->maybeAppendPlugins($analysis, $planning, $pluginName, $result, $pluginQueue) as $additionalPlugin) {
                if (isset($this->plugins[$additionalPlugin])
                    && $this->routingPolicyAllowsPlugin($additionalPlugin, $analysis, $routingPolicy)
                    && $this->academicBusinessPluginMayRun($additionalPlugin, $analysis, $pluginResults)
                    && !in_array($additionalPlugin, $pluginQueue, true)
                ) {
                    $pluginQueue[] = $additionalPlugin;
                } elseif (!$this->routingPolicyAllowsPlugin($additionalPlugin, $analysis, $routingPolicy)) {
                    $this->logDiagnostic($requestId, 'plugin_recommendation_rejected', [
                        'plugin_name' => $additionalPlugin,
                        'source' => 'deterministic_append',
                        'reason' => 'The current routing policy forbids this plugin and no explicit user requirement overrides it.',
                    ]);
                }
            }

            $sufficiencyDecision = $this->evaluateSufficiency(
                $sufficiencyModel,
                $question,
                $analysis,
                $planning,
                $pluginResults,
                $collectionState,
                $routingPolicy
            );
            $collectionState['sufficiency_decision'] = $sufficiencyDecision;
            $this->logDiagnostic($requestId, 'sufficiency_evaluated', [
                'plugin_name' => $pluginName,
                'is_sufficient' => (bool)($sufficiencyDecision['is_sufficient'] ?? false),
                'reason' => (string)($sufficiencyDecision['reason'] ?? ''),
                'recommended_next_experts' => (array)($sufficiencyDecision['recommended_next_experts'] ?? []),
                'rejected_recommended_experts' => (array)($sufficiencyDecision['rejected_recommended_experts'] ?? []),
            ]);
            foreach (array_values((array)($sufficiencyDecision['recommended_next_experts'] ?? [])) as $recommendedPlugin) {
                if ($recommendedPlugin !== ''
                    && isset($this->plugins[$recommendedPlugin])
                    && $this->routingPolicyAllowsPlugin($recommendedPlugin, $analysis, $routingPolicy)
                    && $this->academicBusinessPluginMayRun($recommendedPlugin, $analysis, $pluginResults)
                    && !in_array($recommendedPlugin, $pluginQueue, true)
                    && !in_array($recommendedPlugin, array_keys($pluginResults), true)
                ) {
                    $pluginQueue[] = $recommendedPlugin;
                } elseif (!$this->routingPolicyAllowsPlugin($recommendedPlugin, $analysis, $routingPolicy)) {
                    $this->logDiagnostic($requestId, 'plugin_recommendation_rejected', [
                        'plugin_name' => $recommendedPlugin,
                        'source' => 'sufficiency_assessor',
                        'reason' => 'The current routing policy forbids this plugin and no explicit user requirement overrides it.',
                    ]);
                }
            }

            $reflection = $this->reflectionMessage($pluginName, $result, $pluginQueue, $index, $processLanguage);
            if ($reflection !== '') {
                $this->emitEvent($emit, $eventSequence, [
                    'type' => 'reflection',
                    'session_id' => $sessionId,
                    'plugin_name' => $pluginName,
                    'node' => 'Evidence Collection Node',
                    'source' => 'Evidence Collection Node',
                    'inputs_used' => ['collection_state', 'compressed_result', 'routing_policy'],
                    'outputs_changed' => ['sufficiency_decision', 'remaining_candidates', 'closed_gaps'],
                    'message' => $this->narrateEvent(
                        $narratorModel,
                        $processLanguage,
                        [
                            'type' => 'reflection',
                            'plugin_name' => $pluginName,
                            'result' => $result,
                            'sufficiency_decision' => $sufficiencyDecision,
                            'remaining_tools' => array_slice($pluginQueue, $index + 1),
                        ],
                        $reflection . ' Sufficiency: ' . (string)($sufficiencyDecision['reason'] ?? '')
                    ),
                    'payload' => [
                        'collection_state' => $collectionState,
                        'sufficiency_decision' => $sufficiencyDecision,
                    ],
                ]);
            }

            if (($sufficiencyDecision['is_sufficient'] ?? false) !== true
                && (
                    array_values((array)($sufficiencyDecision['recommended_next_experts'] ?? [])) !== []
                    || array_slice($pluginQueue, $index + 1) !== []
                )
            ) {
                $this->activateWorkflowStage($workflowState, 'Collecting', 'Executing', $emit, $eventSequence, $sessionId);
            }

            if (($sufficiencyDecision['is_sufficient'] ?? false) === true
                && ((bool)($routingPolicy['stop_conditions']['stop_on_sufficient'] ?? true))
            ) {
                break;
            }
        }

        if ($this->shouldRunCitationResolver($pluginResults)) {
            $citationPlugin = $this->plugins['Citation Resolver'] ?? null;
            if ($citationPlugin instanceof TekgAgentPluginInterface) {
                if (($workflowState['current_stage'] ?? '') !== 'Executing') {
                    $fromStage = ($workflowState['current_stage'] ?? '') === 'Collecting' ? 'Collecting' : null;
                    $this->activateWorkflowStage($workflowState, 'Executing', $fromStage, $emit, $eventSequence, $sessionId);
                }
                $this->emitEvent($emit, $eventSequence, [
                    'type' => 'tool_selected',
                    'session_id' => $sessionId,
                    'plugin_name' => 'Citation Resolver',
                    'message' => $this->narrateEvent(
                        $narratorModel,
                        $processLanguage,
                        [
                            'type' => 'tool_selected',
                            'plugin_name' => 'Citation Resolver',
                            'planning' => $planning,
                        ],
                        $this->toolSelectedMessage('Citation Resolver', $planning, $processLanguage)
                    ),
                ]);
                $citationResult = $citationPlugin->run([
                    'question' => $question,
                    'analysis' => $analysis,
                    'plugin_results' => $pluginResults,
                    'planning' => $planning,
                    'config' => $this->expertConfig($expertModel),
                ]);
                $citationResult = PluginResultContract::enforce('Citation Resolver', $citationResult);
                $citationResult = $this->augmentPluginResult('Citation Resolver', $citationResult, $analysis, $planning);

                $pluginResults['Citation Resolver'] = $citationResult;
                $pluginCalls[] = $citationResult;
                $citationResult = $this->markExecutingReviewNotRequired(
                    $citationResult,
                    'Citation normalization is deterministic post-processing and does not require LLM review.'
                );
                $pluginResults['Citation Resolver'] = $citationResult;
                $lastPluginCallIndex = array_key_last($pluginCalls);
                if ($lastPluginCallIndex !== null) {
                    $pluginCalls[$lastPluginCallIndex] = $citationResult;
                }
                $this->logDiagnostic($requestId, 'executing_review_not_required', [
                    'plugin_name' => 'Citation Resolver',
                    'reason' => $citationResult['executing_review_reason'],
                ]);
                $detailId = 'tool-' . (++$detailCounter);
                $payloadForUi = $this->toolPayloadForUi($citationResult);

                $this->emitEvent($emit, $eventSequence, [
                    'type' => 'tool_result',
                    'session_id' => $sessionId,
                    'plugin_name' => 'Citation Resolver',
                    'display_label' => (string)($citationResult['display_label'] ?? 'Citation Resolver'),
                    'summary' => (string)($citationResult['display_summary'] ?? $citationResult['query_summary'] ?? ''),
                    'message' => $this->narrateEvent(
                        $narratorModel,
                        $processLanguage,
                        [
                            'type' => 'tool_result',
                            'plugin_name' => 'Citation Resolver',
                            'result' => $citationResult,
                        ],
                        (string)(($citationResult['display_details']['result_message'] ?? '') ?: ($citationResult['display_summary'] ?? $citationResult['query_summary'] ?? ''))
                    ),
                    'detail_payload_id' => $detailId,
                    'payload' => $payloadForUi,
                ]);

                $reasoningTrace[] = [
                    'step' => 'querying_plugins',
                    'title' => 'Citation Resolver',
                    'status' => (string)($citationResult['status'] ?? 'ok'),
                    'details' => (string)($citationResult['display_summary'] ?? $citationResult['query_summary'] ?? ''),
                ];
            }
        }

        $evidence = $this->aggregateEvidence($pluginResults);
        $citations = $this->aggregateCitations($pluginResults);
        $limits = $this->aggregateLimits($pluginResults, $evidence);
        $confidence = $this->inferConfidence($pluginResults, $evidence, $citations);
        $writingModel = $this->resolveWritingModel($analysis, $payload, $pluginResults);
        $polisherModel = $this->resolvePolisherModel($payload, $writingModel);
        $polisherEnabled = $this->resolvePolisherEnabled($payload);
        if ($this->shouldUseCompactPreflightGate($question, $analysis)) {
            $this->logDiagnostic($requestId, 'compact_preflight_gate_triggered', [
                'recommended_mode' => (string)($analysis['recommended_mode'] ?? ''),
                'task_complexity' => (string)($analysis['task_complexity'] ?? ''),
                'reason' => (string)($analysis['task_complexity_reason'] ?? ''),
            ]);
            $response = $this->buildCompactPreflightResponse(
                $question,
                trim((string)($payload['mode'] ?? 'academic')) ?: 'academic',
                $requestId,
                $answerLanguage,
                $sessionId,
                $analysis,
                $planning,
                $reasoningTrace,
                $pluginCalls,
                $evidence,
                $confidence,
                $limits,
                [
                    'control' => $controlModel,
                    'core' => $coreModel,
                    'sufficiency' => $sufficiencyModel,
                    'expert' => $expertModel,
                    'narrator' => $narratorModel,
                    'answer_structure' => $answerStructureModel,
                    'writer' => $writingModel,
                    'writer_draft' => $writingModel,
                    'writer_polisher' => $polisherModel,
                ],
                $citations,
                $pluginResults,
                $collectionState,
                $sufficiencyDecision,
                $workflowState
            );

            $updatedMemory = $this->updateSessionMemory($sessionMemory, $response['analysis'], $planning, $pluginResults, $citations, $evidence, $collectionState, []);
            $response['question'] = $originalQuestion;
            $response['context_resolution'] = $contextResult->toArray();
            $updatedMemory = ConversationMemory::appendCompletedTurn(
                $updatedMemory,
                'agent',
                $originalQuestion,
                $question,
                (string)$response['answer'],
                (array)$response['analysis']
            );
            tekg_agent_save_session_memory($sessionId, $updatedMemory);
            $this->logConversationTurnRecorded($requestId, 'agent', $updatedMemory);

            $response['answer'] = ReportIntegrityGate::normalizeUrlsInText((string)$response['answer']);
            $this->emitEvent($emit, $eventSequence, [
                'type' => 'answer',
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'language' => $answerLanguage,
                'message' => (string)$response['answer'],
            ]);
            $this->emitEvent($emit, $eventSequence, [
                'type' => 'done',
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'payload' => [
                    'confidence' => $confidence,
                    'used_plugins' => $response['used_plugins'],
                    'answer' => (string)$response['answer'],
                    'language' => $answerLanguage,
                    'writing_failed' => false,
                    'failure_stage' => '',
                    'failure_reason' => '',
                    'workflow_state' => $response['workflow_state'],
                ],
            ]);
            $this->logDiagnostic($requestId, 'request_completed', [
                'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                'used_plugins' => $response['used_plugins'],
                'answer_length' => tekg_agent_strlen((string)$response['answer']),
                'routing_decision' => 'compact_preflight_deepthink',
            ]);

            return $response;
        }
        $this->activateWorkflowStage($workflowState, 'Integrating', (string)($workflowState['current_stage'] ?? 'Executing'), $emit, $eventSequence, $sessionId);
        $evidencePackage = $this->buildValidatedEvidencePackage($question, $analysis, $pluginResults, $requestId);
        $evidenceWalk = EvidenceWalk::fromEvidencePackage($evidencePackage, $analysis, $planning, $sufficiencyDecision);
        $evidenceWalkValidation = EvidenceWalk::validate($evidenceWalk);
        $synthesizedEvidence = $this->buildSynthesizedEvidenceFromPackage($evidencePackage);
        $answerStructureStartedAt = microtime(true);
        $this->logDiagnostic($requestId, 'answer_structure_started', [
            'model' => $answerStructureModel,
            'supported_claim_count' => count((array)($synthesizedEvidence['supported_claims'] ?? [])),
            'citation_count' => count($citations),
        ]);
        $answerStructure = $this->generateAnswerStructure(
            $answerStructureModel,
            $question,
            $analysis,
            $synthesizedEvidence,
            $this->citationsFromEvidencePackage($evidencePackage),
            $sufficiencyDecision,
            $requestId
        );
        $answerStructureDurationMs = (int)round((microtime(true) - $answerStructureStartedAt) * 1000);
        $this->logDiagnostic($requestId, 'answer_structure_completed', [
            'response_mode' => (string)($answerStructure['response_mode'] ?? ''),
            'section_count' => count((array)($answerStructure['section_plan'] ?? [])),
            'duration_ms' => $answerStructureDurationMs,
        ]);
        $reportPlan = ReportPlan::fromEvidenceWalk($question, $analysis, $evidenceWalk, $answerStructure);
        $reportPlanValidation = ReportPlan::validate($reportPlan);
        $integratingNode = $this->llm->runIntegratingNode($controlModel, $processLanguage, [
            'question' => $question,
            'evidence_package' => $evidencePackage,
            'evidence_walk' => $evidenceWalk,
            'report_plan' => $reportPlan,
            'synthesized_evidence' => $synthesizedEvidence,
        ]);
        $this->recordSixStageArtifact($sixStageArtifacts, 'integrating', $integratingNode, $emit, $eventSequence, $sessionId, '', $processLanguage);
        if (!$integratingNode->ok) {
            return $this->buildSixStageFailureResponse($question, $payload, $requestId, $answerLanguage, $sessionId, $controlModel, 'Integrating', $integratingNode, $sixStageArtifacts, $workflowState);
        }
        $claimEvidenceMap = (array)$integratingNode->parsed_json;

        $synthesizingMessage = $this->synthesizingMessage($planning, $pluginResults, $evidence, $processLanguage);
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'synthesizing',
            'session_id' => $sessionId,
            'node' => 'Evidence Synthesis Node',
            'source' => 'Evidence Synthesis Node',
            'inputs_used' => ['result_envelopes', 'evidence_package'],
            'outputs_changed' => ['evidence_package', 'evidence_walk', 'report_plan', 'supported_claims', 'conflicting_claims', 'missing_evidence', 'claim_clusters', 'answer_structure'],
            'message' => $this->narrateEvent(
                $narratorModel,
                $processLanguage,
                [
                    'type' => 'synthesizing',
                    'planning' => $planning,
                    'plugin_results' => $pluginResults,
                    'evidence_count' => count($evidence),
                    'answer_structure' => $answerStructure,
                ],
                $synthesizingMessage
            ),
            'payload' => [
                'evidence_package' => $evidencePackage,
                'evidence_walk' => $evidenceWalk,
                'report_plan' => $reportPlan,
                'synthesized_evidence' => $synthesizedEvidence,
                'answer_structure' => $answerStructure,
            ],
        ]);
        $this->activateWorkflowStage($workflowState, 'Writing', 'Integrating', $emit, $eventSequence, $sessionId);
        $directSiteNavigationWriting = null;
        $analysisForWriting = $this->analysisForWriting($analysis);
        $userFacingWritingContext = UserFacingWritingContext::fromInternal(
            $question,
            $analysisForWriting,
            $evidencePackage,
            $evidenceWalk,
            $claimEvidenceMap,
            $reportPlan
        );
        $writingDecisionNode = $directSiteNavigationWriting !== null
            ? $directSiteNavigationWriting['writing_decision_node']
            : $this->llm->runWritingDecisionNode($writingModel, $processLanguage, [
                'question' => $question,
                'writing_context' => $userFacingWritingContext,
                'limits' => $limits,
                'confidence' => $confidence,
            ]);
        $this->recordSixStageArtifact($sixStageArtifacts, 'writing', $writingDecisionNode, $emit, $eventSequence, $sessionId, '', $processLanguage);
        if (!$writingDecisionNode->ok) {
            return $this->buildSixStageFailureResponse($question, $payload, $requestId, $answerLanguage, $sessionId, $writingModel, 'Writing', $writingDecisionNode, $sixStageArtifacts, $workflowState);
        }
        $writingDecision = (array)$writingDecisionNode->parsed_json;

        $this->logDiagnostic($requestId, 'answer_generation_started', [
            'model' => $writingModel,
            'polisher_model' => $polisherModel,
            'response_mode' => (string)($answerStructure['response_mode'] ?? ''),
            'citation_count' => count($citations),
            'confidence' => $confidence,
        ]);
        $answer = '';
        $draftReport = '';
        $polishedReport = '';
        $draftLlm = [
            'ok' => false,
            'provider' => $this->inferProvider($writingModel),
            'model' => $writingModel,
            'content' => '',
            'error' => null,
        ];
        $polishLlm = [
            'ok' => false,
            'provider' => $this->inferProvider($polisherModel),
            'model' => $polisherModel,
            'content' => '',
            'error' => null,
        ];
        $integrityReport = [
            'evidence_walk_validation' => $evidenceWalkValidation,
            'report_plan_validation' => $reportPlanValidation,
            'draft' => null,
            'polish' => null,
            'draft_user_facing' => null,
            'polish_user_facing' => null,
            'warnings' => [],
        ];
        $writingFailed = false;
        $failureReason = '';
        $presentationRepairRequired = false;
        $polisherUsed = false;
        $writingStartedAt = microtime(true);

        if ($directSiteNavigationWriting !== null) {
            $answer = (string)$directSiteNavigationWriting['answer'];
            $draftReport = (string)$directSiteNavigationWriting['draft_report'];
            $polishedReport = (string)$directSiteNavigationWriting['polished_report'];
            $draftLlm['ok'] = true;
            $draftLlm['content'] = $draftReport;
            $polishLlm['ok'] = true;
            $polishLlm['content'] = $polishedReport;
            $integrityReport['draft'] = (array)$directSiteNavigationWriting['integrity'];
            $integrityReport['polish'] = (array)$directSiteNavigationWriting['integrity'];
            $integrityReport['warnings'][] = 'direct_site_navigation';
            if (($directSiteNavigationWriting['integrity']['ok'] ?? false) !== true) {
                $writingFailed = true;
                $failureReason = 'The direct site-navigation answer failed integrity checks: ' . implode('; ', (array)($directSiteNavigationWriting['integrity']['errors'] ?? []));
            }
        } elseif (($evidenceWalkValidation['ok'] ?? false) !== true || ($reportPlanValidation['ok'] ?? false) !== true) {
            $writingFailed = true;
            $failureReason = 'EvidenceWalk or ReportPlan validation failed before writing.';
        }

        if (!$writingFailed && $directSiteNavigationWriting === null) {
            try {
                $draftLlm = $this->llm->writeEvidenceWalkDraft(
                    $writingModel,
                    $answerLanguage,
                    $question,
                    $analysisForWriting,
                    $evidencePackage,
                    $evidenceWalk,
                    $claimEvidenceMap,
                    $writingDecision,
                    $reportPlan,
                    $confidence,
                    $limits,
                    $this->answerTimeoutForModel($writingModel)
                );
            } catch (Throwable $error) {
                $draftLlm = [
                    'ok' => false,
                    'provider' => $this->inferProvider($writingModel),
                    'model' => $writingModel,
                    'content' => '',
                    'error' => $error->getMessage(),
                ];
                $this->logDiagnostic($requestId, 'answer_generation_error', [
                    'stage' => 'draft',
                    'error' => $error->getMessage(),
                ]);
            }

            if (($draftLlm['ok'] ?? false) === true) {
                $draftReport = trim((string)($draftLlm['content'] ?? ''));
                $draftReport = ReportIntegrityGate::normalizeUrlsInText($draftReport);
            }
            if ($draftReport === '') {
                $writingFailed = true;
                $failureReason = trim((string)($draftLlm['error'] ?? 'The evidence-walk draft writer did not return usable content.'));
            } else {
                $draftIntegrity = ReportIntegrityGate::check($draftReport, $evidencePackage, $evidenceWalk, $reportPlan);
                $integrityReport['draft'] = $draftIntegrity;
                if (($draftIntegrity['ok'] ?? false) !== true) {
                    $writingFailed = true;
                    $failureReason = 'The evidence-walk draft failed integrity checks: ' . implode('; ', (array)($draftIntegrity['errors'] ?? []));
                } else {
                    $draftUserFacing = UserFacingWritingContext::auditAnswer($draftReport, $question, $userFacingWritingContext);
                    $integrityReport['draft_user_facing'] = $draftUserFacing;
                    $presentationRepairRequired = ($draftUserFacing['ok'] ?? false) !== true;
                    if ($presentationRepairRequired) {
                        $integrityReport['warnings'][] = 'Draft contained internal presentation vocabulary and was sent through a mandatory user-facing repair pass.';
                    }
                }
            }
        }

        if (!$writingFailed && $directSiteNavigationWriting === null && !$polisherEnabled && !$presentationRepairRequired) {
            $polishedReport = $draftReport;
            $answer = $draftReport;
            $polishLlm = [
                'ok' => true,
                'provider' => $this->inferProvider($polisherModel),
                'model' => $polisherModel,
                'content' => $polishedReport,
                'error' => null,
                'skipped' => true,
                'stage' => 'polish',
            ];
            $integrityReport['polish'] = (array)$integrityReport['draft'];
            $integrityReport['warnings'][] = 'Polisher skipped by configuration; using the validated draft report.';
        }

        if (!$writingFailed && $directSiteNavigationWriting === null && ($polisherEnabled || $presentationRepairRequired)) {
            $polisherUsed = true;
            try {
                $polishLlm = $this->llm->polishEvidenceWalkAnswer(
                    $polisherModel,
                    $answerLanguage,
                    $draftReport,
                    $analysisForWriting,
                    $evidencePackage,
                    $evidenceWalk,
                    $claimEvidenceMap,
                    $writingDecision,
                    $reportPlan,
                    array_replace(
                        (array)$integrityReport['draft'],
                        ['user_facing_violations' => (array)($integrityReport['draft_user_facing']['violations'] ?? [])]
                    ),
                    $this->answerTimeoutForModel($polisherModel)
                );
            } catch (Throwable $error) {
                $polishLlm = [
                    'ok' => false,
                    'provider' => $this->inferProvider($polisherModel),
                    'model' => $polisherModel,
                    'content' => '',
                    'error' => $error->getMessage(),
                ];
                $this->logDiagnostic($requestId, 'answer_generation_error', [
                    'stage' => 'polish',
                    'error' => $error->getMessage(),
                ]);
            }

            if (($polishLlm['ok'] ?? false) === true) {
                $polishedReport = trim((string)($polishLlm['content'] ?? ''));
                $polishedReport = ReportIntegrityGate::normalizeUrlsInText($polishedReport);
            }
            if ($polishedReport === '') {
                $writingFailed = true;
                $failureReason = trim((string)($polishLlm['error'] ?? 'The evidence-walk polisher did not return usable content.'));
            } else {
                $polishIntegrity = ReportIntegrityGate::check($polishedReport, $evidencePackage, $evidenceWalk, $reportPlan);
                $integrityReport['polish'] = $polishIntegrity;
                $polishUserFacing = UserFacingWritingContext::auditAnswer($polishedReport, $question, $userFacingWritingContext);
                $integrityReport['polish_user_facing'] = $polishUserFacing;
                if (($polishIntegrity['ok'] ?? false) === true && ($polishUserFacing['ok'] ?? false) === true) {
                    $answer = $polishedReport;
                } elseif (($integrityReport['draft_user_facing']['ok'] ?? false) === true) {
                    $answer = $draftReport;
                    $integrityReport['warnings'][] = 'Polished report failed integrity or presentation checks; using the validated user-facing draft report.';
                } else {
                    foreach ([$polishedReport, $draftReport] as $candidateReport) {
                        $sanitizedReport = UserFacingWritingContext::sanitizeAnswer($candidateReport);
                        if ($sanitizedReport === '') {
                            continue;
                        }
                        $sanitizedIntegrity = ReportIntegrityGate::check($sanitizedReport, $evidencePackage, $evidenceWalk, $reportPlan);
                        $sanitizedUserFacing = UserFacingWritingContext::auditAnswer($sanitizedReport, $question, $userFacingWritingContext);
                        if (($sanitizedIntegrity['ok'] ?? false) === true && ($sanitizedUserFacing['ok'] ?? false) === true) {
                            $answer = $sanitizedReport;
                            $integrityReport['sanitized_user_facing'] = $sanitizedUserFacing;
                            $integrityReport['warnings'][] = 'Deterministic presentation cleanup removed residual internal vocabulary after the repair pass.';
                            break;
                        }
                    }
                    if ($answer === '') {
                        $writingFailed = true;
                        $failureReason = 'The final report could not be converted into user-facing prose without internal presentation leakage.';
                    }
                }
            }
        }
        if (!$writingFailed && $answer === '') {
            $writingFailed = true;
            $failureReason = 'The final writing node did not produce a validated answer.';
        }
        if ($answer !== '') {
            $answer = ReportIntegrityGate::normalizeUrlsInText($answer);
        }
        $this->logDiagnostic($requestId, 'answer_generation_completed', [
            'draft_ok' => (bool)($draftLlm['ok'] ?? false),
            'polish_ok' => (bool)($polishLlm['ok'] ?? false),
            'draft_integrity_ok' => (bool)($integrityReport['draft']['ok'] ?? false),
            'polish_integrity_ok' => (bool)($integrityReport['polish']['ok'] ?? false),
            'writing_failed' => $writingFailed,
            'writing_duration_ms' => (int)round((microtime(true) - $writingStartedAt) * 1000),
            'answer_length' => tekg_agent_strlen($answer),
        ]);

        $workflowState['stage_statuses']['Writing'] = $writingFailed ? 'failed' : 'done';
        $workflowState['current_stage'] = 'Writing';
        $workflowState['complete'] = true;

        $reasoningTrace[] = [
            'step' => 'synthesizing',
            'title' => 'Synthesis',
            'status' => $writingFailed ? 'failed' : 'done',
            'details' => $synthesizingMessage,
        ];

        $finalAnswerModel = $this->resolveFinalAnswerModel(
            $answer,
            $draftReport,
            $polishedReport,
            $writingModel,
            $polisherModel,
            $polisherEnabled || $polisherUsed
        );
        $response = [
            'question' => $originalQuestion,
            'mode' => trim((string)($payload['mode'] ?? 'academic')) ?: 'academic',
            'request_id' => $requestId,
            'language' => $answerLanguage,
            'session_id' => $sessionId,
            'model' => $writingModel,
            'model_provider' => $this->inferProvider($finalAnswerModel),
            'models' => [
                'control' => $controlModel,
                'core' => $coreModel,
                'sufficiency' => $sufficiencyModel,
                'expert' => $expertModel,
                'narrator' => $narratorModel,
                'answer_structure' => $answerStructureModel,
                'writer' => $writingModel,
                'writer_draft' => $writingModel,
                'writer_polisher' => $polisherModel,
            ],
            'analysis' => $analysis,
            'answer' => $answer,
            'writing_failed' => $writingFailed,
            'failure_stage' => $writingFailed ? 'Writing' : '',
            'failure_reason' => $failureReason,
            'presentation_failure_reason' => $writingFailed ? $this->academicPresentationFailureMessage('Writing', $processLanguage) : '',
            'reasoning_trace' => $reasoningTrace,
            'used_plugins' => array_map(static fn(array $call): string => (string)($call['plugin_name'] ?? ''), $pluginCalls),
            'plugin_calls' => $pluginCalls,
            'evidence' => $evidence,
            'citations' => $citations,
            'evidence_package' => $evidencePackage,
            'evidence_walk' => $evidenceWalk,
            'claim_evidence_map' => $claimEvidenceMap,
            'writing_decision' => $writingDecision,
            'report_plan' => $reportPlan,
            'draft_report' => $draftReport,
            'polished_report' => $polishedReport,
            'integrity_report' => $integrityReport,
            'confidence' => $confidence,
            'limits' => array_values(array_unique($limits)),
            'planning' => $planning,
            'collection_state' => $collectionState,
            'sufficiency_decision' => $sufficiencyDecision,
            'answer_structure' => $answerStructure,
            'synthesized_evidence' => $synthesizedEvidence,
            'timings' => [
                'answer_structure_ms' => $answerStructureDurationMs,
                'writing_ms' => (int)round((microtime(true) - $writingStartedAt) * 1000),
            ],
            'workflow_state' => $workflowState,
            'six_stage_artifacts' => $sixStageArtifacts,
            'node_contracts' => tekg_agent_node_contracts(),
            'context_resolution' => $contextResult->toArray(),
            'node_payloads' => tekg_agent_json_safe($this->buildNodePayloads(
                $question,
                $analysis,
                $planning,
                $pluginResults,
                $evidence,
                $citations,
                $collectionState,
                $sufficiencyDecision,
                $answerStructure,
                $synthesizedEvidence,
                $evidencePackage,
                $evidenceWalk,
                $reportPlan,
                $claimEvidenceMap,
                $writingDecision,
                $draftReport,
                $polishedReport,
                $integrityReport,
                $answer
            )),
        ];
        $evaluationReport = ModeComparisonEvaluation::fromAgentResponse($response, [
            'question' => $originalQuestion,
            'category' => (string)($analysis['intent'] ?? ''),
            'expected_best_mode' => 'agent',
        ]);
        $response['evaluation_report'] = $evaluationReport;

        if (!$writingFailed) {
            $updatedMemory = $this->updateSessionMemory($sessionMemory, $analysis, $planning, $pluginResults, $citations, $evidence, $collectionState, $synthesizedEvidence);
            $updatedMemory = ConversationMemory::appendCompletedTurn(
                $updatedMemory,
                'agent',
                $originalQuestion,
                $question,
                $answer,
                $analysis
            );
            tekg_agent_save_session_memory($sessionId, $updatedMemory);
            $this->logConversationTurnRecorded($requestId, 'agent', $updatedMemory);
        }

        $this->completeWorkflowStage($workflowState, 'Writing', $emit, $eventSequence, $sessionId);
        $this->logDiagnostic($requestId, 'answer_event_emitting', [
            'answer_length' => tekg_agent_strlen($answer),
            'writing_failed' => $writingFailed,
            'workflow_complete' => true,
        ]);
        if (!$writingFailed) {
            $this->emitEvent($emit, $eventSequence, [
                'type' => 'answer',
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'language' => $answerLanguage,
                'message' => $answer,
            ]);
        } else {
            $this->emitEvent($emit, $eventSequence, [
                'type' => 'error',
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'node' => 'Answer Writer Node',
                'source' => 'Answer Writer Node',
                'message' => $response['presentation_failure_reason'],
                'payload' => [
                    'writing_failed' => true,
                    'failure_stage' => 'Writing',
                    'failure_reason' => $failureReason,
                    'presentation_failure_reason' => $response['presentation_failure_reason'],
                ],
            ]);
        }
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'done',
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'payload' => [
                'confidence' => $confidence,
                'used_plugins' => $response['used_plugins'],
                'answer' => $answer,
                'language' => $answerLanguage,
                'writing_failed' => $writingFailed,
                'failure_stage' => $writingFailed ? 'Writing' : '',
                'failure_reason' => $failureReason,
                'presentation_failure_reason' => $response['presentation_failure_reason'],
                'workflow_state' => $workflowState,
            ],
        ]);
        $this->logDiagnostic($requestId, 'request_completed', [
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'used_plugins' => $response['used_plugins'],
            'answer_length' => tekg_agent_strlen($answer),
        ]);

        return $response;
    }

    private function buildContextClarificationResponse(
        string $originalQuestion,
        array $payload,
        string $requestId,
        string $answerLanguage,
        string $sessionId,
        string $model,
        ConversationContextResult $contextResult,
        ?callable $emit,
        int &$eventSequence
    ): array {
        $answer = $contextResult->clarificationMessage($answerLanguage);
        $workflowState = $this->initialWorkflowState();
        $workflowState['current_stage'] = 'Clarification';
        $workflowState['complete'] = true;
        $response = [
            'question' => $originalQuestion,
            'mode' => trim((string)($payload['mode'] ?? 'academic')) ?: 'academic',
            'request_id' => $requestId,
            'language' => $answerLanguage,
            'session_id' => $sessionId,
            'model' => $model,
            'model_provider' => $this->inferProvider($model),
            'models' => ['control' => $model],
            'analysis' => [
                'intent' => 'clarification',
                'answer_language' => $answerLanguage,
                'normalized_entities' => [],
                'conversation_context' => $contextResult->toArray(),
            ],
            'answer' => $answer,
            'writing_failed' => false,
            'failure_stage' => '',
            'failure_reason' => '',
            'presentation_failure_reason' => '',
            'reasoning_trace' => [],
            'used_plugins' => [],
            'plugin_calls' => [],
            'evidence' => [],
            'citations' => [],
            'limits' => [],
            'workflow_state' => $workflowState,
            'six_stage_artifacts' => [],
            'context_resolution' => $contextResult->toArray(),
        ];

        $this->emitEvent($emit, $eventSequence, [
            'type' => 'answer',
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'language' => $answerLanguage,
            'message' => $answer,
        ]);
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'done',
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'payload' => [
                'confidence' => 'low',
                'used_plugins' => [],
                'answer' => $answer,
                'language' => $answerLanguage,
                'writing_failed' => false,
                'failure_stage' => '',
                'failure_reason' => '',
                'workflow_state' => $workflowState,
            ],
        ]);
        return $response;
    }

    private function logConversationContext(
        string $requestId,
        string $mode,
        ConversationContextResult $contextResult
    ): void {
        $event = match (true) {
            $contextResult->status === 'needs_clarification' => 'conversation_context_clarification_required',
            $contextResult->resolutionSource === 'deterministic_fallback' => 'conversation_context_fallback',
            $contextResult->status === 'resolved_follow_up' => 'conversation_context_resolved',
            default => 'conversation_context_standalone',
        };
        $this->logDiagnostic($requestId, $event, [
            'mode' => $mode,
            'status' => $contextResult->status,
            'resolution_source' => $contextResult->resolutionSource,
            'explicit_entities' => $contextResult->explicitEntities,
            'inherited_entities' => $contextResult->inheritedEntities,
            'clarification_candidates' => $contextResult->clarificationCandidates,
            'reason' => $contextResult->reason,
        ]);
    }

    private function logConversationTurnRecorded(string $requestId, string $mode, array $memory): void
    {
        $turns = (array)($memory['recent_turns'] ?? []);
        $lastTurn = $turns === [] ? [] : (array)$turns[array_key_last($turns)];
        $this->logDiagnostic($requestId, 'conversation_turn_recorded', [
            'mode' => $mode,
            'turn_count' => count($turns),
            'entities' => (array)($lastTurn['entities'] ?? []),
            'original_question_length' => tekg_agent_strlen((string)($lastTurn['original_question'] ?? '')),
            'effective_question_length' => tekg_agent_strlen((string)($lastTurn['effective_question'] ?? '')),
            'answer_summary_length' => tekg_agent_strlen((string)($lastTurn['answer_summary'] ?? '')),
        ]);
    }

    private function buildDirectSiteNavigationWritingResult(
        array $analysis,
        array $pluginResults,
        array $evidencePackage,
        array $evidenceWalk,
        array $reportPlan
    ): ?array {
        if (($analysis['asks_for_site_navigation'] ?? false) !== true) {
            return null;
        }

        $siteResult = (array)($pluginResults['Site Navigator Plugin'] ?? []);
        if (!in_array((string)($siteResult['status'] ?? ''), ['ok', 'partial'], true)) {
            return null;
        }

        $answer = trim((string)($siteResult['results']['answer_markdown'] ?? ''));
        if ($answer === '') {
            return null;
        }

        $answer = ReportIntegrityGate::normalizeUrlsInText($answer);
        $integrity = ReportIntegrityGate::check($answer, $evidencePackage, $evidenceWalk, $reportPlan);
        $writingDecision = [
            'schema_version' => 'writing_decision.v1',
            'stage' => 'writing',
            'writing_strategy' => 'direct_site_navigation',
            'required_sections' => ['site_navigation_answer'],
            'forbidden_claims' => [
                'Do not rewrite Site Navigator Plugin URLs.',
                'Do not add mode handoff copy.',
            ],
            'citation_requirements' => [
                'Use only the route URLs already present in Site Navigator Plugin results.answer_markdown.',
            ],
            'tone' => 'direct',
            'final_checks' => [
                'ReportIntegrityGate::normalizeUrlsInText applied.',
                'ReportIntegrityGate::check applied.',
            ],
        ];
        $schemas = require dirname(__DIR__) . '/config/agent_node_schemas.php';
        $rawJson = json_encode($writingDecision, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $writingDecisionNode = NodeLlmResult::fromRawJson('writing', $rawJson, (array)($schemas['writing_decision.v1'] ?? []));

        return [
            'answer' => $answer,
            'draft_report' => $answer,
            'polished_report' => $answer,
            'integrity' => $integrity,
            'writing_decision' => $writingDecision,
            'writing_decision_node' => $writingDecisionNode,
        ];
    }

    private function fallbackUnderstandingNodeResult(
        string $question,
        string $answerLanguage,
        array $analysis,
        NodeLlmResult $failedResult
    ): NodeLlmResult {
        $entities = [];
        foreach ((array)($analysis['normalized_entities'] ?? []) as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $name = trim((string)($entity['canonical_label'] ?? $entity['label'] ?? $entity['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $entities[] = [
                'name' => $name,
                'type' => (string)($entity['type'] ?? 'entity'),
            ];
        }

        $payload = [
            'schema_version' => 'understanding_result.v1',
            'stage' => 'understanding',
            'language' => $answerLanguage === 'chinese' ? 'zh' : 'en',
            'question_summary' => $question,
            'intent' => (string)($analysis['intent'] ?? 'relationship'),
            'entities' => $entities,
            'ambiguities' => array_values(array_map('strval', (array)($analysis['ambiguities'] ?? []))),
            'mode_boundary' => 'agent_research_conservative_fallback',
            'required_evidence' => array_values(array_map('strval', (array)($analysis['required_evidence'] ?? []))),
            'warnings' => array_values(array_unique(array_merge(
                ['llm_unavailable_conservative_fallback'],
                array_map('strval', $failedResult->errors)
            ))),
        ];

        return new NodeLlmResult(
            'understanding',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            $payload,
            true,
            [],
            'understanding_result.v1'
        );
    }

    private function fallbackPlanningNodeResult(array $planning, array $pluginQueue, NodeLlmResult $failedResult): NodeLlmResult
    {
        $payload = [
            'schema_version' => 'research_plan.v1',
            'stage' => 'planning',
            'research_goal' => (string)($planning['summary'] ?? 'Continue with the deterministic TE-Dee research plan.'),
            'evidence_dimensions' => array_values(array_map('strval', (array)($planning['required_evidence'] ?? []))),
            'plugin_route' => array_values(array_map('strval', $pluginQueue)),
            'required_plugins' => array_values(array_map('strval', array_slice($pluginQueue, 0, 2))),
            'optional_plugins' => array_values(array_map('strval', array_slice($pluginQueue, 2))),
            'success_criteria' => ['Use only deterministic plugin evidence and mark uncertain claims conservatively.'],
            'risks' => array_values(array_unique(array_merge(
                ['llm_unavailable_conservative_fallback'],
                array_map('strval', $failedResult->errors)
            ))),
        ];

        return new NodeLlmResult(
            'planning',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            $payload,
            true,
            [],
            'research_plan.v1'
        );
    }

    private function recordSixStageArtifact(
        array &$sixStageArtifacts,
        string $stage,
        NodeLlmResult $result,
        ?callable $emit,
        int &$eventSequence,
        string $sessionId,
        string $pluginName = '',
        string $processLanguage = 'english'
    ): void {
        $artifact = $this->nodeLlmArtifact($result, $pluginName, $processLanguage);
        if ($stage === 'executing') {
            $artifact['required_schema'] = 'tool_execution_review.v1';
            $sixStageArtifacts[$stage][] = $artifact;
        } else {
            $sixStageArtifacts[$stage] = $artifact;
        }

        $this->emitEvent($emit, $eventSequence, [
            'type' => $result->ok ? 'node_llm_result' : 'node_llm_error',
            'session_id' => $sessionId,
            'node' => $this->nodeNameForSixStageArtifact($stage),
            'source' => $this->nodeNameForSixStageArtifact($stage),
            'stage' => $this->stageLabelForSixStageArtifact($stage),
            'schema_version' => (string)($result->schema_version ?? ''),
            'ok' => $result->ok,
            'artifact' => $result->parsed_json,
            'errors' => $result->errors,
            'summary' => $artifact['summary'],
            'message' => $artifact['summary'],
            'payload' => [
                'stage' => $this->stageLabelForSixStageArtifact($stage),
                'schema_version' => (string)($result->schema_version ?? ''),
                'ok' => $result->ok,
                'artifact' => $result->parsed_json,
                'errors' => $result->errors,
                'summary' => $artifact['summary'],
            ],
        ]);
    }

    private function executingReviewRequired(string $pluginName, array $pluginResult): bool
    {
        if (!in_array((string)($pluginResult['status'] ?? ''), ['ok', 'partial'], true)) {
            return false;
        }

        if (in_array($pluginName, [
            'Entity Resolver',
            'Site Navigator Plugin',
            'Tree Plugin',
            'Genome Plugin',
            'Citation Resolver',
        ], true)) {
            return false;
        }

        if ($pluginName === 'Sequence Plugin') {
            foreach ((array)($pluginResult['evidence_items'] ?? []) as $item) {
                $normalized = tekg_agent_normalize_evidence_item($item, $pluginName);
                if ($normalized === null) {
                    continue;
                }
                if ((string)($normalized['evidence_type'] ?? '') === 'structure_hint'
                    || in_array('keyword_derived', (array)($normalized['quality_flags'] ?? []), true)
                ) {
                    return true;
                }
            }
            return false;
        }

        return in_array($pluginName, [
            'Graph Plugin',
            'Graph Analytics Plugin',
            'Cypher Explorer Plugin',
            'Literature Plugin',
            'Literature Reading Plugin',
            'Expression Plugin',
            'eQTL Plugin',
        ], true);
    }

    private function markExecutingReviewNotRequired(array $pluginResult, string $reason): array
    {
        $pluginResult['executing_review_status'] = 'not_required';
        $pluginResult['executing_review_reason'] = trim($reason);
        $pluginResult['executing_review_errors'] = [];
        return $pluginResult;
    }

    private function shouldContinueAfterExecutingReviewFailure(array $pluginResult, NodeLlmResult $reviewResult): bool
    {
        if ($reviewResult->ok || $reviewResult->stage !== 'executing') {
            return false;
        }

        return in_array((string)($pluginResult['status'] ?? ''), ['ok', 'partial'], true);
    }

    private function applyExecutingReviewFailureCaveat(array $pluginResult, NodeLlmResult $reviewResult): array
    {
        $errors = array_values(array_filter(array_map('strval', $reviewResult->errors)));
        $errorText = implode('; ', $errors);
        if ($errorText === '') {
            $errorText = 'ExecutingReview unavailable.';
        }

        $pluginResult['executing_review_status'] = 'review_failed';
        $pluginResult['executing_review_errors'] = $errors;
        $pluginResult['warnings'] = array_values(array_unique(array_merge(
            (array)($pluginResult['warnings'] ?? []),
            ['review_failed']
        )));
        $pluginResult['caveats'] = array_values(array_unique(array_filter(array_merge(
            (array)($pluginResult['caveats'] ?? []),
            ['ExecutingReview unavailable; plugin evidence was retained without a meta-review artifact. ' . $errorText]
        ))));

        return $pluginResult;
    }

    private function nodeLlmArtifact(NodeLlmResult $result, string $pluginName = '', string $processLanguage = 'english'): array
    {
        return [
            'stage' => $this->stageLabelForSixStageArtifact($result->stage),
            'schema_version' => (string)($result->schema_version ?? ''),
            'ok' => $result->ok,
            'artifact' => $result->parsed_json,
            'errors' => $result->errors,
            'summary' => $this->nodeLlmSummary($result, $pluginName, $processLanguage),
            'plugin_name' => $pluginName,
        ];
    }

    private function nodeLlmSummary(NodeLlmResult $result, string $pluginName = '', string $processLanguage = 'english'): string
    {
        if (!$result->ok) {
            $message = implode('; ', $result->errors);
            return $this->isChineseProcessLanguage($processLanguage)
                ? $this->stageLabelForSixStageArtifact($result->stage) . ' LLM 产物失败' . ($message !== '' ? '：' . $message : '。')
                : $this->stageLabelForSixStageArtifact($result->stage) . ' LLM artifact failed' . ($message !== '' ? ': ' . $message : '.');
        }

        $artifact = $result->parsed_json ?? [];
        foreach (['question_summary', 'research_goal', 'decision_rationale', 'evidence_summary', 'writing_strategy'] as $key) {
            $value = trim((string)($artifact[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if ($result->stage === 'integrating') {
            return $this->isChineseProcessLanguage($processLanguage) ? '已生成主张与证据映射。' : 'Claim-evidence map generated.';
        }
        if ($result->stage === 'executing' && $pluginName !== '') {
            return $this->isChineseProcessLanguage($processLanguage) ? "已为 {$pluginName} 生成工具执行审查。" : "Tool execution review generated for {$pluginName}.";
        }
        return $this->isChineseProcessLanguage($processLanguage)
            ? $this->stageLabelForSixStageArtifact($result->stage) . ' LLM 产物已生成。'
            : $this->stageLabelForSixStageArtifact($result->stage) . ' LLM artifact generated.';
    }

    private function academicPresentationFailureMessage(string $stage, string $processLanguage): string
    {
        return $this->isChineseProcessLanguage($processLanguage)
            ? $stage . ' 阶段失败，未生成学术回答。'
            : $stage . ' failed, so no academic answer was generated.';
    }

    private function nodeNameForSixStageArtifact(string $stage): string
    {
        return match ($stage) {
            'understanding' => 'Question Understanding Node',
            'planning' => 'Planning Node',
            'collecting' => 'Evidence Collection Node',
            'executing' => 'Expert Execution Review Node',
            'integrating' => 'Evidence Synthesis Node',
            'writing' => 'Answer Writer Node',
            default => 'AcademicAgentService',
        };
    }

    private function stageLabelForSixStageArtifact(string $stage): string
    {
        return match ($stage) {
            'understanding' => 'Understanding',
            'planning' => 'Planning',
            'collecting' => 'Collecting',
            'executing' => 'ExecutingReview',
            'integrating' => 'Integrating',
            'writing' => 'WritingDecision',
            default => $stage,
        };
    }

    private function buildSixStageFailureResponse(
        string $question,
        array $payload,
        string $requestId,
        string $answerLanguage,
        string $sessionId,
        string $model,
        string $failureStage,
        NodeLlmResult $result,
        array $sixStageArtifacts,
        array $workflowState
    ): array {
        $workflowStage = $failureStage === 'Executing' ? 'Executing' : $failureStage;
        if (isset($workflowState['stage_statuses'][$workflowStage])) {
            $workflowState['stage_statuses'][$workflowStage] = 'failed';
        }
        $workflowState['current_stage'] = $workflowStage;
        $workflowState['complete'] = true;

        $failureReason = implode('; ', $result->errors);
        if ($failureReason === '') {
            $failureReason = 'Required six-stage LLM artifact failed.';
        }

        return [
            'question' => $question,
            'mode' => trim((string)($payload['mode'] ?? 'academic')) ?: 'academic',
            'request_id' => $requestId,
            'language' => $answerLanguage,
            'session_id' => $sessionId,
            'model' => $model,
            'model_provider' => $this->inferProvider($model),
            'models' => ['core' => $model],
            'analysis' => [],
            'answer' => '',
            'writing_failed' => true,
            'failure_stage' => $failureStage,
            'failure_reason' => $failureReason,
            'presentation_failure_reason' => $this->academicPresentationFailureMessage($failureStage, $answerLanguage),
            'reasoning_trace' => [[
                'step' => 'six_stage_llm',
                'title' => $failureStage,
                'status' => 'failed',
                'details' => $failureReason,
            ]],
            'used_plugins' => [],
            'plugin_calls' => [],
            'evidence' => [],
            'citations' => [],
            'evidence_package' => [],
            'evidence_walk' => [],
            'report_plan' => [],
            'draft_report' => '',
            'polished_report' => '',
            'integrity_report' => [
                'warnings' => ['Required six-stage LLM artifact failed before the run could continue.'],
            ],
            'confidence' => 'low',
            'limits' => [],
            'planning' => [],
            'collection_state' => [],
            'sufficiency_decision' => [],
            'answer_structure' => [],
            'synthesized_evidence' => [],
            'timings' => [
                'answer_structure_ms' => 0,
                'writing_ms' => 0,
            ],
            'workflow_state' => $workflowState,
            'six_stage_artifacts' => $sixStageArtifacts,
            'node_contracts' => tekg_agent_node_contracts(),
            'node_payloads' => tekg_agent_json_safe([
                'six_stage_failure' => [
                    'node' => $this->nodeNameForSixStageArtifact($result->stage),
                    'output' => $this->nodeLlmArtifact($result, '', $answerLanguage),
                ],
            ]),
        ];
    }

    private function runtimeConfig(array $payload, string $requestId): array
    {
        $config = $this->config;
        $config['request_id'] = $requestId;

        $executionTimeout = (int)($payload['execution_timeout'] ?? $config['agent_execution_timeout'] ?? 300);
        $config['agent_execution_timeout'] = max(90, $executionTimeout);
        $config['llm_narrator_timeout'] = max(4, (int)($config['llm_narrator_timeout'] ?? 6));
        $config['llm_json_timeout'] = max(10, (int)($config['llm_json_timeout'] ?? 15));
        $config['llm_six_stage_node_timeout'] = max(20, (int)($config['llm_six_stage_node_timeout'] ?? 45));
        $config['llm_answer_timeout'] = max(15, (int)($config['llm_answer_timeout'] ?? 20));
        $config['llm_answer_chat_timeout'] = max(15, (int)($config['llm_answer_chat_timeout'] ?? 18));
        $config['llm_answer_reasoner_timeout'] = max(25, (int)($config['llm_answer_reasoner_timeout'] ?? 35));

        if (isset($payload['llm_json_timeout'])) {
            $config['llm_json_timeout'] = max(5, (int)$payload['llm_json_timeout']);
        }
        if (isset($payload['llm_six_stage_node_timeout'])) {
            $config['llm_six_stage_node_timeout'] = max(10, (int)$payload['llm_six_stage_node_timeout']);
        }
        if (isset($payload['llm_answer_timeout'])) {
            $config['llm_answer_timeout'] = max(5, (int)$payload['llm_answer_timeout']);
        }
        if (isset($payload['llm_answer_chat_timeout'])) {
            $config['llm_answer_chat_timeout'] = max(5, (int)$payload['llm_answer_chat_timeout']);
        }
        if (isset($payload['llm_answer_reasoner_timeout'])) {
            $config['llm_answer_reasoner_timeout'] = max(5, (int)$payload['llm_answer_reasoner_timeout']);
        }
        if (isset($payload['llm_narrator_timeout'])) {
            $config['llm_narrator_timeout'] = max(2, (int)$payload['llm_narrator_timeout']);
        }

        return $config;
    }

    private function applyExecutionBudget(array $config): void
    {
        $timeout = max(60, (int)($config['agent_execution_timeout'] ?? 240));
        @ini_set('max_execution_time', (string)$timeout);
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout);
        }
    }

    private function logDiagnostic(string $requestId, string $event, array $payload = []): void
    {
        tekg_agent_append_diagnostic_log($requestId, $event, $payload);
    }

    private function resolveControlModel(array $payload): string
    {
        $payloadControlModel = trim((string)($payload['control_model'] ?? $payload['agent_control_model'] ?? ''));
        if ($payloadControlModel !== '') {
            return $payloadControlModel;
        }

        return trim((string)($this->config['agent_control_model'] ?? $this->config['deepseek_model'] ?? 'deepseek-v4-flash'));
    }

    private function resolveCoreModel(array $payload): string
    {
        $payloadCoreModel = trim((string)($payload['core_model'] ?? $payload['agent_core_model'] ?? ''));
        if ($payloadCoreModel !== '') {
            return $payloadCoreModel;
        }

        return trim((string)($this->config['agent_core_model'] ?? $this->config['deepseek_reasoner_model'] ?? $this->config['deepseek_model'] ?? 'deepseek-v4-pro'));
    }

    private function resolveSufficiencyModel(array $payload): string
    {
        $payloadSufficiencyModel = trim((string)($payload['sufficiency_model'] ?? $payload['collecting_model'] ?? ''));
        if ($payloadSufficiencyModel !== '') {
            return $payloadSufficiencyModel;
        }

        if (trim((string)($this->config['agent_collecting_model'] ?? '')) !== '') {
            return trim((string)$this->config['agent_collecting_model']);
        }

        return $this->resolveControlModel($payload);
    }

    private function resolveExpertModel(array $payload): string
    {
        return trim((string)($payload['expert_model'] ?? $this->config['agent_expert_model'] ?? 'deepseek-v4-pro'));
    }

    private function resolveNarratorModel(array $payload): string
    {
        return trim((string)($payload['narrator_model'] ?? $this->config['agent_narrator_model'] ?? 'deepseek-v4-pro'));
    }

    private function resolveAnswerStructureModel(array $payload): string
    {
        return trim((string)($payload['answer_structure_model'] ?? $this->config['agent_answer_structure_model'] ?? $this->resolveControlModel($payload)));
    }

    private function resolveWritingModel(array $analysis, array $payload, array $pluginResults): string
    {
        if (trim((string)($payload['writing_model'] ?? '')) !== '') {
            return trim((string)$payload['writing_model']);
        }

        if (trim((string)($this->config['agent_writing_model'] ?? '')) !== '') {
            return trim((string)($this->config['agent_writing_model'] ?? ''));
        }

        $intent = (string)($analysis['intent'] ?? 'relationship');
        $reasonerIntents = ['mechanism', 'comparison', 'graph_analytics'];
        if (in_array($intent, $reasonerIntents, true) || isset($pluginResults['Cypher Explorer Plugin'])) {
            return trim((string)($this->config['deepseek_reasoner_model'] ?? $this->config['deepseek_model'] ?? 'deepseek-reasoner'));
        }

        return trim((string)($this->config['deepseek_model'] ?? 'deepseek-chat'));
    }

    private function resolvePolisherModel(array $payload, string $writingModel): string
    {
        if (trim((string)($payload['polisher_model'] ?? '')) !== '') {
            return trim((string)$payload['polisher_model']);
        }

        if (trim((string)($this->config['agent_polisher_model'] ?? '')) !== '') {
            return trim((string)($this->config['agent_polisher_model'] ?? ''));
        }

        return $writingModel;
    }

    private function resolvePolisherEnabled(array $payload): bool
    {
        if (array_key_exists('polisher_enabled', $payload)) {
            return tekg_agent_bool_value($payload['polisher_enabled'], false);
        }
        if (array_key_exists('agent_polisher_enabled', $payload)) {
            return tekg_agent_bool_value($payload['agent_polisher_enabled'], false);
        }
        return tekg_agent_bool_value($this->config['agent_polisher_enabled'] ?? false, false);
    }

    private function resolveFinalAnswerModel(
        string $answer,
        string $draftReport,
        string $polishedReport,
        string $writingModel,
        string $polisherModel,
        bool $polisherEnabled
    ): string {
        if ($polisherEnabled && $polishedReport !== '' && $answer === $polishedReport) {
            return $polisherModel;
        }

        if ($draftReport !== '' && $answer === $draftReport) {
            return $writingModel;
        }

        return $writingModel;
    }

    private function resolveProcessLanguage(string $answerLanguage): string
    {
        return tekg_agent_detect_language(['language' => $answerLanguage], '');
    }

    private function answerTimeoutForModel(string $model): int
    {
        $provider = $this->inferProvider($model);
        if ($provider === 'deepseek' && stripos($model, 'reasoner') !== false) {
            return max(25, (int)($this->config['llm_answer_reasoner_timeout'] ?? 35));
        }
        return max(15, (int)($this->config['llm_answer_chat_timeout'] ?? 18));
    }

    private function expertConfig(string $expertModel): array
    {
        $config = $this->config;
        $config['deepseek_model'] = $expertModel;
        return $config;
    }

    private function collectFailedBroadAliases(array $analysis, array $pluginResults): array
    {
        $failed = [];
        $relations = (int)($pluginResults['Graph Plugin']['result_counts']['relations'] ?? 0);
        if ($relations > 0) {
            return [];
        }

        foreach ((array)($analysis['alias_chains'] ?? []) as $chain) {
            if (!is_array($chain) || !(bool)($chain['used_broad_alias'] ?? false)) {
                continue;
            }
            $matched = trim((string)($chain['matched_alias'] ?? ''));
            if ($matched !== '') {
                $failed[] = $matched;
            }
        }
        return $failed;
    }

    private function firstEntityLabel(array $analysis): string
    {
        $entities = (array)($analysis['normalized_entities'] ?? []);
        if ($entities === []) {
            return 'the recognized TE';
        }
        $first = $entities[0];
        return (string)($first['canonical_label'] ?? $first['label'] ?? 'the recognized TE');
    }

    private function inferProvider(string $model): string
    {
        $value = strtolower(trim($model));
        if (str_contains($value, 'qwen')) {
            return 'qwen';
        }
        return 'deepseek';
    }

    private function emit(?callable $emit, array $event): void
    {
        if ($emit !== null) {
            $emit($event);
        }
    }

    private function shouldRunCitationResolver(array $pluginResults): bool
    {
        foreach ($pluginResults as $pluginName => $result) {
            if (in_array($pluginName, ['Entity Resolver', 'Citation Resolver'], true)) {
                continue;
            }
            if ((array)($result['citations'] ?? []) !== []) {
                return true;
            }
        }
        return false;
    }

    private function shouldUseCompactPreflightGate(string $question, array $analysis): bool
    {
        return false;
    }

    private function hasResearchTaskSignal(string $question, array $analysis): bool
    {
        $intent = strtolower(trim((string)($analysis['intent'] ?? '')));
        if (in_array($intent, ['mechanism', 'comparison', 'graph_analytics', 'literature'], true)) {
            return true;
        }

        $text = tekg_agent_lower($question);
        $signals = [
            'research',
            'report',
            'audit',
            'ranking',
            'rank',
            'batch',
            'compare',
            'comparison',
            'versus',
            ' vs ',
            'mechanism',
            'literature review',
            'review',
            'dossier',
            'evidence walk',
            'evidence audit',
            'graph ranking',
            'centrality',
            'topology',
            'paper',
            'papers',
            'citation',
            'citations',
            'pubmed',
            '研究',
            '报告',
            '审计',
            '排名',
            '批量',
            '比较',
            '对比',
            '机制',
            '综述',
            '文献综述',
            '证据审计',
            '图谱排名',
            '中心性',
            '拓扑',
            '论文',
            '文献',
            '引用',
        ];
        foreach ($signals as $signal) {
            if ($signal !== '' && str_contains($text, tekg_agent_lower($signal))) {
                return true;
            }
        }

        return false;
    }

    private function buildCompactPreflightResponse(
        string $question,
        string $mode,
        string $requestId,
        string $answerLanguage,
        string $sessionId,
        array $analysis,
        array $planning,
        array $reasoningTrace,
        array $pluginCalls,
        array $evidence,
        string $confidence,
        array $limits,
        array $models,
        array $citations = [],
        array $pluginResults = [],
        array $collectionState = [],
        array $sufficiencyDecision = [],
        array $workflowState = []
    ): array {
        $analysis['routing_decision'] = 'compact_preflight_deepthink';
        $analysis['routing_decision_reason'] = 'recommended_mode=deep_think with no research/report/audit/ranking/batch/comparison/mechanism signal; skipped full Evidence Walk Writing.';

        $answer = $this->compactPreflightAnswer($question, $answerLanguage, $evidence, $pluginCalls, $analysis);
        $workflowState = $workflowState === [] ? $this->initialWorkflowState() : $workflowState;
        $workflowState['stage_statuses']['Writing'] = 'skipped';
        $workflowState['current_stage'] = 'Compact Preflight';
        $workflowState['complete'] = true;
        $reasoningTrace[] = [
            'step' => 'compact_preflight',
            'title' => 'Compact Deep Think Boundary',
            'status' => 'done',
            'details' => $analysis['routing_decision_reason'],
        ];

        $response = [
            'question' => $question,
            'mode' => $mode,
            'request_id' => $requestId,
            'language' => $answerLanguage,
            'session_id' => $sessionId,
            'model' => (string)($models['writer'] ?? $models['core'] ?? ''),
            'model_provider' => $this->inferProvider((string)($models['writer'] ?? $models['core'] ?? '')),
            'models' => $models,
            'analysis' => $analysis,
            'answer' => $answer,
            'writing_failed' => false,
            'failure_stage' => '',
            'failure_reason' => '',
            'reasoning_trace' => $reasoningTrace,
            'used_plugins' => array_map(static fn(array $call): string => (string)($call['plugin_name'] ?? ''), $pluginCalls),
            'plugin_calls' => $pluginCalls,
            'evidence' => $evidence,
            'citations' => $citations,
            'evidence_package' => [],
            'evidence_walk' => [],
            'report_plan' => [],
            'draft_report' => '',
            'polished_report' => '',
            'integrity_report' => [
                'evidence_walk_validation' => ['ok' => true, 'skipped' => true],
                'report_plan_validation' => ['ok' => true, 'skipped' => true],
                'draft' => ['ok' => true, 'skipped' => true],
                'polish' => ['ok' => true, 'skipped' => true],
                'warnings' => ['Full Evidence Walk Writing skipped by simple-task preflight gate.'],
            ],
            'confidence' => $confidence,
            'limits' => array_values(array_unique($limits)),
            'planning' => $planning,
            'collection_state' => $collectionState,
            'sufficiency_decision' => $sufficiencyDecision,
            'answer_structure' => [
                'response_mode' => 'compact_boundary',
                'preferred_report_type' => 'compact_answer',
                'section_plan' => [],
            ],
            'synthesized_evidence' => [],
            'timings' => [
                'answer_structure_ms' => 0,
                'writing_ms' => 0,
            ],
            'workflow_state' => $workflowState,
            'node_contracts' => tekg_agent_node_contracts(),
            'node_payloads' => tekg_agent_json_safe([
                'routing_decision' => [
                    'node' => 'Preflight Gate',
                    'output' => [
                        'routing_decision' => $analysis['routing_decision'],
                        'routing_decision_reason' => $analysis['routing_decision_reason'],
                    ],
                ],
            ]),
        ];
        $response['evaluation_report'] = ModeComparisonEvaluation::fromAgentResponse($response, [
            'question' => $question,
            'category' => (string)($analysis['intent'] ?? ''),
            'expected_best_mode' => 'deep_think',
        ]);

        return $response;
    }

    private function compactPreflightAnswer(string $question, string $answerLanguage, array $evidence, array $pluginCalls, array $analysis): string
    {
        $evidenceLine = $this->compactEvidenceLine($evidence, $pluginCalls);
        $reason = trim((string)($analysis['task_complexity_reason'] ?? 'This is a simple lookup or single-hop task.'));
        if (in_array(strtolower(trim($answerLanguage)), ['chinese', 'zh', 'zh-cn', 'zh_cn'], true)) {
            $answer = '这是一个简单查询，建议使用 Deep Think 获取更快的直接答案。Agent 已跳过完整 Evidence Walk Writing。';
            if ($evidenceLine !== '') {
                $answer .= "\n\n已有轻量证据：" . $evidenceLine;
            }
            return $answer . "\n\n路由原因：" . $reason;
        }

        $answer = 'This is a simple lookup, so Deep Think is the recommended path for a faster direct answer. Agent skipped full Evidence Walk Writing.';
        if ($evidenceLine !== '') {
            $answer .= "\n\nLight evidence already collected: " . $evidenceLine;
        }
        return $answer . "\n\nRouting reason: " . $reason;
    }

    private function compactEvidenceLine(array $evidence, array $pluginCalls): string
    {
        foreach ($evidence as $item) {
            if (is_array($item)) {
                foreach (['text', 'claim', 'summary', 'label', 'value'] as $key) {
                    $value = trim((string)($item[$key] ?? ''));
                    if ($value !== '') {
                        return tekg_agent_substr($value, 0, 220);
                    }
                }
            } elseif (trim((string)$item) !== '') {
                return tekg_agent_substr(trim((string)$item), 0, 220);
            }
        }

        foreach ($pluginCalls as $call) {
            $summary = trim((string)($call['display_summary'] ?? $call['query_summary'] ?? ''));
            if ($summary !== '') {
                return tekg_agent_substr($summary, 0, 220);
            }
        }

        return '';
    }

    private function normalizeModeName(string $mode): string
    {
        $normalized = strtolower(trim($mode));
        $normalized = str_replace(['-', ' '], '_', $normalized);
        return $normalized === 'deepthink' ? 'deep_think' : $normalized;
    }

}
