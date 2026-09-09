<?php
declare(strict_types=1);

if (!function_exists('tekg_agent_create_default_plugins')) {
    require_once dirname(__DIR__) . '/plugin_registry.php';
}

require_once __DIR__ . '/traits/DeepThinkRoutingTrait.php';
require_once __DIR__ . '/traits/DeepThinkEvidenceTrait.php';
require_once dirname(__DIR__) . '/contracts/UserFacingWritingContext.php';

final class TekgDeepThinkService
{
    use TekgDeepThinkRoutingTrait;
    use TekgDeepThinkEvidenceTrait;

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
        $runtimeConfig = $this->runtimeConfig($payload, $requestId);
        $this->llm = new TekgAgentLlmClient($runtimeConfig);
        $this->applyExecutionBudget($runtimeConfig);
        $model = $this->resolveModel($payload);
        $sessionId = trim((string)($payload['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = tekg_agent_make_session_id();
        }
        $answerLanguage = tekg_agent_detect_language($question, trim((string)($payload['language'] ?? 'english')));
        $eventSequence = 0;
        $detailCounter = 0;
        $reasoningTrace = [];
        $pluginResults = [];
        $artifacts = ['understanding' => null, 'planning' => null, 'executing' => [], 'writing' => null];
        $sessionMemory = tekg_agent_load_session_memory($sessionId);
        $contextResult = (new ConversationContextResolver($this->normalizer, $this->llm))->resolve(
            $originalQuestion,
            $answerLanguage,
            $sessionMemory,
            'deepthink',
            $model
        );
        $this->logDeepThinkConversationContext($requestId, $contextResult);
        if ($contextResult->status === 'needs_clarification') {
            return $this->buildDeepThinkContextClarificationResponse(
                $originalQuestion,
                $requestId,
                $sessionId,
                $answerLanguage,
                $model,
                $contextResult,
                $emit,
                $eventSequence
            );
        }
        $question = $contextResult->effectiveQuestion;
        $normalizerInput = $contextResult->applyToAnalysis($this->normalizer->analyze($question, $answerLanguage));
        $base = [
            'question' => $originalQuestion,
            'requestId' => $requestId,
            'sessionId' => $sessionId,
            'answerLanguage' => $answerLanguage,
            'model' => $model,
        ];

        $understanding = $this->runDeepThinkStage(
            'Understanding',
            fn(): NodeLlmResult => $this->llm->runDeepThinkUnderstandingNode($model, $answerLanguage, [
                'question' => $question,
                'answer_language' => $answerLanguage,
                'rule_normalizer' => $normalizerInput,
                'conversation_context' => $contextResult->toArray(),
            ]),
            $artifacts,
            $emit,
            $eventSequence,
            $sessionId,
            $requestId,
            $answerLanguage
        );
        if (!$understanding->ok) {
            return $this->failDeepThinkRun($base, 'Understanding', $understanding, $artifacts, $pluginResults, $emit, $eventSequence);
        }
        $analysis = $this->analysisFromUnderstanding((array)$understanding->parsed_json, $question);
        $analysis = $contextResult->applyToAnalysis($analysis);
        $analysis['conversation_context'] = $contextResult->toArray();
        $analysis['original_question'] = $originalQuestion;
        $analysis['effective_question'] = $question;
        $explicitLiterature = $this->hasExplicitLiteratureRequest($question);

        $planningNode = $this->runDeepThinkStage(
            'Planning',
            fn(): NodeLlmResult => $this->llm->runDeepThinkPlanningNode($model, $answerLanguage, [
                'question' => $question,
                'understanding' => $understanding->parsed_json,
                'available_business_plugins' => $this->deepThinkBusinessPluginNames(),
                'bootstrap_plugin' => 'Entity Resolver',
                'extra_resolver' => 'Citation Resolver',
                'explicit_literature_request' => $explicitLiterature,
                'plugin_directory' => tekg_agent_plugin_directory(),
            ]),
            $artifacts,
            $emit,
            $eventSequence,
            $sessionId,
            $requestId,
            $answerLanguage
        );
        if (!$planningNode->ok) {
            return $this->failDeepThinkRun($base, 'Planning', $planningNode, $artifacts, $pluginResults, $emit, $eventSequence);
        }
        try {
            $businessPlugins = $this->validateDeepThinkBusinessPlugins(
                (array)($planningNode->parsed_json['business_plugins'] ?? []),
                $explicitLiterature
            );
        } catch (Throwable $error) {
            return $this->failDeepThinkRun($base, 'Planning', $error->getMessage(), $artifacts, $pluginResults, $emit, $eventSequence);
        }
        $planning = [
            'business_plugins' => $businessPlugins,
            'citation_resolver_allowed' => (bool)($planningNode->parsed_json['citation_resolver_allowed'] ?? false),
        ];

        try {
            $pluginResults['Entity Resolver'] = $this->runPlugin(
                'Entity Resolver', $question, $analysis, $planning, $pluginResults, $model, $model,
                $answerLanguage, $sessionId, $eventSequence, $detailCounter, $requestId, $emit, $reasoningTrace
            );
            $this->assertDeepThinkPluginSucceeded('Entity Resolver', $pluginResults['Entity Resolver']);
        } catch (Throwable $error) {
            return $this->failDeepThinkRun($base, 'Executing', $error->getMessage(), $artifacts, $pluginResults, $emit, $eventSequence);
        }

        $remaining = $businessPlugins;
        $executedBusinessPlugins = [];
        for ($round = 0; $remaining !== []; $round++) {
            $executingNode = $this->runDeepThinkStage(
                'Executing',
                fn(): NodeLlmResult => $this->llm->runDeepThinkExecutingNode($model, $answerLanguage, [
                    'question' => $question,
                    'understanding' => $understanding->parsed_json,
                    'planning' => $planningNode->parsed_json,
                    'remaining_planned_plugins' => $remaining,
                    'plugin_results' => $this->compressedPluginResults($pluginResults),
                    'round' => $round + 1,
                    'plugin_directory' => tekg_agent_plugin_directory(),
                ]),
                $artifacts,
                $emit,
                $eventSequence,
                $sessionId,
                $requestId,
                $answerLanguage
            );
            if (!$executingNode->ok) {
                return $this->failDeepThinkRun($base, 'Executing', $executingNode, $artifacts, $pluginResults, $emit, $eventSequence);
            }
            $decision = (array)$executingNode->parsed_json;
            if ((bool)($decision['done'] ?? false)) {
                break;
            }
            $nextPlugin = trim((string)($decision['next_plugin'] ?? ''));
            if ($nextPlugin === '' || !in_array($nextPlugin, $remaining, true)) {
                return $this->failDeepThinkRun($base, 'Executing', 'Executing selected a plugin outside the remaining validated plan.', $artifacts, $pluginResults, $emit, $eventSequence);
            }
            if (isset($executedBusinessPlugins[$nextPlugin])) {
                return $this->failDeepThinkRun($base, 'Executing', 'Executing selected a business plugin that already ran: ' . $nextPlugin, $artifacts, $pluginResults, $emit, $eventSequence);
            }
            try {
                $this->assertDeepThinkBusinessPluginMayRun($nextPlugin, $explicitLiterature, $pluginResults);
                $executedBusinessPlugins[$nextPlugin] = true;
                $pluginResults[$nextPlugin] = $this->runPlugin(
                    $nextPlugin, $question, $analysis, $planning, $pluginResults, $model, $model,
                    $answerLanguage, $sessionId, $eventSequence, $detailCounter, $requestId, $emit, $reasoningTrace,
                    (string)($decision['reason'] ?? '')
                );
                $this->assertDeepThinkPluginSucceeded($nextPlugin, $pluginResults[$nextPlugin]);
            } catch (Throwable $error) {
                return $this->failDeepThinkRun($base, 'Executing', $error->getMessage(), $artifacts, $pluginResults, $emit, $eventSequence);
            }
            $remaining = array_values(array_filter($remaining, static fn(string $name): bool => $name !== $nextPlugin));
        }

        if ($planning['citation_resolver_allowed'] && $this->shouldRunCitationResolver($pluginResults)) {
            try {
                $pluginResults['Citation Resolver'] = $this->runPlugin(
                    'Citation Resolver', $question, $analysis, $planning, $pluginResults, $model, $model,
                    $answerLanguage, $sessionId, $eventSequence, $detailCounter, $requestId, $emit, $reasoningTrace
                );
                $this->assertDeepThinkPluginSucceeded('Citation Resolver', $pluginResults['Citation Resolver']);
            } catch (Throwable $error) {
                return $this->failDeepThinkRun($base, 'Executing', $error->getMessage(), $artifacts, $pluginResults, $emit, $eventSequence);
            }
        }

        $evidence = $this->aggregateEvidence($pluginResults);
        $citations = $this->aggregateCitations($pluginResults);
        $limits = $this->aggregateLimits($pluginResults, $evidence);
        $writingContext = UserFacingWritingContext::fromEvidenceItems(
            $question,
            $evidence,
            $citations,
            $limits,
            str_contains(tekg_agent_lower($question), 'report') || str_contains($question, '报告')
                ? 'research_report'
                : 'direct_answer'
        );
        $writingPayload = [
            'question' => $question,
            'writing_context' => $writingContext,
        ];
        $directNavigationAnswer = $this->buildDirectSiteNavigationAnswer($analysis, $pluginResults);
        $writing = $this->runDeepThinkStage(
            'Writing',
            fn(): NodeLlmResult => $directNavigationAnswer !== null
                ? new NodeLlmResult(
                    'writing',
                    '',
                    [
                        'schema_version' => 'dt_writing.v1',
                        'stage' => 'writing',
                        'answer_markdown' => $directNavigationAnswer,
                        'limitations' => [],
                    ],
                    true,
                    [],
                    'dt_writing.v1'
                )
                : $this->llm->runDeepThinkWritingNode($model, $answerLanguage, $writingPayload),
            $artifacts,
            $emit,
            $eventSequence,
            $sessionId,
            $requestId,
            $answerLanguage
        );
        if (!$writing->ok) {
            return $this->failDeepThinkRun($base, 'Writing', $writing, $artifacts, $pluginResults, $emit, $eventSequence);
        }
        $answer = trim((string)($writing->parsed_json['answer_markdown'] ?? ''));
        if ($answer === '') {
            $emptyRetry = $this->llm->runDeepThinkWritingNode($model, $answerLanguage, array_replace($writingPayload, [
                'draft_to_repair' => '',
                'mandatory_repairs' => [
                    'Return a direct, non-empty user-facing answer. If no scientific record was returned, state that limitation in plain language without treating it as biological absence.',
                ],
            ]));
            $answer = $emptyRetry->ok ? trim((string)($emptyRetry->parsed_json['answer_markdown'] ?? '')) : '';
            $artifacts['writing']['empty_answer_retry'] = [
                'attempted' => true,
                'ok' => $emptyRetry->ok && $answer !== '',
                'errors' => $emptyRetry->errors,
            ];
            if ($answer === '') {
                return $this->failDeepThinkRun($base, 'Writing', 'Writing artifact answer_markdown is empty after one retry.', $artifacts, $pluginResults, $emit, $eventSequence);
            }
        }
        $userFacingAudit = UserFacingWritingContext::auditAnswer($answer, $question, $writingContext);
        if (($userFacingAudit['ok'] ?? false) !== true) {
            $repair = $this->llm->runDeepThinkWritingNode($model, $answerLanguage, [
                'question' => $question,
                'writing_context' => $writingContext,
                'draft_to_repair' => $answer,
                'mandatory_repairs' => (array)($userFacingAudit['violations'] ?? []),
                'repair_instruction' => 'Rewrite the draft once as clear user-facing prose. Remove every listed presentation leak without adding or changing scientific claims, numbers, citations, or links.',
            ]);
            $repairedAnswer = $repair->ok ? trim((string)($repair->parsed_json['answer_markdown'] ?? '')) : '';
            $repairAudit = UserFacingWritingContext::auditAnswer($repairedAnswer, $question, $writingContext);
            $artifacts['writing']['user_facing_repair'] = [
                'attempted' => true,
                'ok' => $repair->ok && $repairedAnswer !== '' && ($repairAudit['ok'] ?? false) === true,
                'violations' => (array)($repairAudit['violations'] ?? []),
            ];
            if ($repair->ok && $repairedAnswer !== '' && ($repairAudit['ok'] ?? false) === true) {
                $answer = $repairedAnswer;
            } else {
                $sanitizedAnswer = UserFacingWritingContext::sanitizeAnswer($repairedAnswer !== '' ? $repairedAnswer : $answer);
                $sanitizedAudit = UserFacingWritingContext::auditAnswer($sanitizedAnswer, $question, $writingContext);
                if ($sanitizedAnswer !== '' && ($sanitizedAudit['ok'] ?? false) === true) {
                    $artifacts['writing']['user_facing_repair']['sanitized_fallback'] = true;
                    $answer = $sanitizedAnswer;
                } else {
                    return $this->failDeepThinkRun(
                        $base,
                        'Writing',
                        'The final report could not be converted into user-facing prose without internal presentation leakage.',
                        $artifacts,
                        $pluginResults,
                        $emit,
                        $eventSequence
                    );
                }
            }
        }

        $response = [
            'question' => $originalQuestion, 'mode' => 'deepthink', 'request_id' => $requestId, 'session_id' => $sessionId,
            'language' => $answerLanguage, 'model' => $model, 'models' => ['understanding' => $model, 'planning' => $model, 'executing' => $model, 'writing' => $model],
            'failed' => false, 'writing_failed' => false, 'failure_stage' => '', 'failure_reason' => '', 'answer' => $answer,
            'analysis' => $analysis, 'dt_artifacts' => $artifacts, 'used_plugins' => array_keys($pluginResults), 'plugin_calls' => array_values($pluginResults),
            'evidence' => $evidence, 'citations' => $citations, 'limits' => $limits, 'context_resolution' => $contextResult->toArray(),
        ];
        $updatedMemory = $this->updateSessionMemory($sessionMemory, $analysis, $pluginResults, $citations);
        $updatedMemory = ConversationMemory::appendCompletedTurn(
            $updatedMemory,
            'deepthink',
            $originalQuestion,
            $question,
            $answer,
            $analysis
        );
        tekg_agent_save_session_memory($sessionId, $updatedMemory);
        $this->logDiagnostic($requestId, 'conversation_turn_recorded', [
            'mode' => 'deepthink',
            'turn_count' => count((array)($updatedMemory['recent_turns'] ?? [])),
            'entities' => (array)($updatedMemory['topic_entities'] ?? []),
        ]);
        $this->emitEvent($emit, $eventSequence, ['type' => 'answer', 'request_id' => $requestId, 'session_id' => $sessionId, 'message' => $answer, 'language' => $answerLanguage]);
        $this->emitEvent($emit, $eventSequence, ['type' => 'done', 'request_id' => $requestId, 'session_id' => $sessionId, 'payload' => $this->terminalPayload(false, false, '', '', $answer, $answerLanguage)]);
        $this->logDiagnostic($requestId, 'deepthink_terminal_success', ['answer' => $answer, 'model' => $model]);
        return $response;
    }

    private function buildDeepThinkContextClarificationResponse(
        string $originalQuestion,
        string $requestId,
        string $sessionId,
        string $answerLanguage,
        string $model,
        ConversationContextResult $contextResult,
        ?callable $emit,
        int &$eventSequence
    ): array {
        $answer = $contextResult->clarificationMessage($answerLanguage);
        $response = [
            'question' => $originalQuestion,
            'mode' => 'deepthink',
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'language' => $answerLanguage,
            'model' => $model,
            'failed' => false,
            'writing_failed' => false,
            'failure_stage' => '',
            'failure_reason' => '',
            'answer' => $answer,
            'analysis' => [
                'intent' => 'clarification',
                'answer_language' => $answerLanguage,
                'normalized_entities' => [],
                'conversation_context' => $contextResult->toArray(),
            ],
            'dt_artifacts' => ['understanding' => null, 'planning' => null, 'executing' => [], 'writing' => null],
            'used_plugins' => [],
            'plugin_calls' => [],
            'evidence' => [],
            'citations' => [],
            'limits' => [],
            'context_resolution' => $contextResult->toArray(),
        ];
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'answer',
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'message' => $answer,
            'language' => $answerLanguage,
        ]);
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'done',
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'payload' => $this->terminalPayload(false, false, '', '', $answer, $answerLanguage),
        ]);
        return $response;
    }

    private function logDeepThinkConversationContext(
        string $requestId,
        ConversationContextResult $contextResult
    ): void {
        $event = match (true) {
            $contextResult->status === 'needs_clarification' => 'conversation_context_clarification_required',
            $contextResult->resolutionSource === 'deterministic_fallback' => 'conversation_context_fallback',
            $contextResult->status === 'resolved_follow_up' => 'conversation_context_resolved',
            default => 'conversation_context_standalone',
        };
        $this->logDiagnostic($requestId, $event, [
            'mode' => 'deepthink',
            'status' => $contextResult->status,
            'resolution_source' => $contextResult->resolutionSource,
            'explicit_entities' => $contextResult->explicitEntities,
            'inherited_entities' => $contextResult->inheritedEntities,
            'clarification_candidates' => $contextResult->clarificationCandidates,
            'reason' => $contextResult->reason,
        ]);
    }

    private function runDeepThinkStage(
        string $stage,
        callable $runner,
        array &$artifacts,
        ?callable $emit,
        int &$eventSequence,
        string $sessionId,
        string $requestId,
        string $language
    ): NodeLlmResult {
        $key = strtolower($stage);
        $this->emitStageState($emit, $eventSequence, $sessionId, $requestId, $stage, 'started', $language);
        $result = $runner();
        $artifact = [
            'stage' => $stage,
            'ok' => $result->ok,
            'schema_version' => $result->schema_version,
            'artifact' => $result->parsed_json,
            'errors' => $result->errors,
        ];
        if ($key === 'executing') {
            $artifacts[$key][] = $artifact;
        } else {
            $artifacts[$key] = $artifact;
        }
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'artifact',
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'node' => $stage,
            'source' => $stage,
            'payload' => $artifact,
        ]);
        $this->logDiagnostic($requestId, 'deepthink_stage_artifact', $artifact);
        $this->emitStageState($emit, $eventSequence, $sessionId, $requestId, $stage, $result->ok ? 'completed' : 'failed', $language);
        return $result;
    }

    private function emitStageState(
        ?callable $emit,
        int &$eventSequence,
        string $sessionId,
        string $requestId,
        string $stage,
        string $status,
        string $language
    ): void {
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'stage_state',
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'node' => $stage,
            'source' => $stage,
            'payload' => [
                'current_stage' => $stage,
                'display_label' => $this->stageDisplayLabel($stage, $language),
                'status' => $status,
                'language' => $language,
            ],
        ]);
    }

    private function failDeepThinkRun(
        array $base,
        string $failureStage,
        NodeLlmResult|string $failure,
        array $artifacts,
        array $pluginResults,
        ?callable $emit,
        int &$eventSequence
    ): array {
        $reason = is_string($failure)
            ? trim($failure)
            : trim(implode('; ', $failure->errors));
        if ($reason === '') {
            $reason = $failureStage . ' artifact failed.';
        }
        $writingFailed = $failureStage === 'Writing';
        $payload = $this->terminalPayload(true, $writingFailed, $failureStage, $reason, '', (string)$base['answerLanguage']);
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'error',
            'request_id' => (string)$base['requestId'],
            'session_id' => (string)$base['sessionId'],
            'node' => $failureStage,
            'source' => $failureStage,
            'message' => (string)$payload['presentation_failure_reason'],
            'payload' => $payload,
        ]);
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'done',
            'request_id' => (string)$base['requestId'],
            'session_id' => (string)$base['sessionId'],
            'node' => $failureStage,
            'source' => $failureStage,
            'payload' => $payload,
        ]);
        $this->logDiagnostic((string)$base['requestId'], 'deepthink_terminal_failure', $payload);
        return [
            'question' => (string)$base['question'],
            'mode' => 'deepthink',
            'request_id' => (string)$base['requestId'],
            'session_id' => (string)$base['sessionId'],
            'language' => (string)$base['answerLanguage'],
            'model' => (string)$base['model'],
            'failed' => true,
            'writing_failed' => $writingFailed,
            'failure_stage' => $failureStage,
            'failure_reason' => $reason,
            'answer' => '',
            'dt_artifacts' => $artifacts,
            'used_plugins' => array_keys($pluginResults),
            'plugin_calls' => array_values($pluginResults),
        ];
    }

    private function terminalPayload(
        bool $failed,
        bool $writingFailed,
        string $failureStage,
        string $failureReason,
        string $answer,
        string $language
    ): array {
        return [
            'failed' => $failed,
            'writing_failed' => $writingFailed,
            'failure_stage' => $failureStage,
            'failure_reason' => $failureReason,
            'presentation_failure_reason' => $failed ? $this->localizedFailureMessage($failureStage, $language) : '',
            'answer' => $answer,
            'language' => $language,
        ];
    }

    private function analysisFromUnderstanding(array $artifact, string $question): array
    {
        $entities = array_values((array)($artifact['entities'] ?? []));
        return [
            'intent' => (string)($artifact['intent'] ?? 'relationship'),
            'answer_language' => (string)($artifact['answer_language'] ?? 'en'),
            'language' => (string)($artifact['answer_language'] ?? 'en'),
            'normalized_entities' => $entities,
            'alias_chains' => $entities,
            'asks_for_papers' => $this->hasExplicitLiteratureRequest($question),
        ];
    }

    private function deepThinkBusinessPluginNames(): array
    {
        return array_values(array_filter(
            array_keys($this->plugins),
            static fn(string $name): bool => !in_array($name, ['Entity Resolver', 'Citation Resolver'], true)
        ));
    }

    private function validateDeepThinkBusinessPlugins(array $plugins, bool $explicitLiteratureRequest): array
    {
        $plugins = array_values(array_map(static fn($name): string => trim((string)$name), $plugins));
        if (count(array_unique($plugins)) !== count($plugins)) {
            throw new RuntimeException('Planning selected a duplicate business plugin.');
        }
        $available = $this->deepThinkBusinessPluginNames();
        foreach ($plugins as $plugin) {
            if ($plugin === '' || !in_array($plugin, $available, true)) {
                throw new RuntimeException('Planning selected an unknown or reserved business plugin: ' . $plugin);
            }
            if (in_array($plugin, ['Literature Plugin', 'Literature Reading Plugin'], true) && !$explicitLiteratureRequest) {
                throw new RuntimeException($plugin . ' requires an explicit literature request.');
            }
        }
        return $plugins;
    }

    private function assertDeepThinkBusinessPluginMayRun(string $pluginName, bool $explicitLiteratureRequest, array $pluginResults): void
    {
        if ($pluginName !== 'Literature Reading Plugin') {
            return;
        }
        $literature = (array)($pluginResults['Literature Plugin'] ?? []);
        if (!$explicitLiteratureRequest
            || !in_array((string)($literature['status'] ?? ''), ['ok', 'partial'], true)
            || tekg_agent_plugin_result_citations($literature) === []
        ) {
            throw new RuntimeException('Literature Reading Plugin requires an explicit literature request and usable Literature Plugin citations.');
        }
    }

    private function hasExplicitLiteratureRequest(string $question): bool
    {
        $normalized = tekg_agent_lower($question);
        foreach (['papers', 'literature', 'pubmed', 'citations', 'pmid', '文献', '论文', '引用'] as $needle) {
            if (str_contains($normalized, tekg_agent_lower($needle))) {
                return true;
            }
        }
        return false;
    }

    private function assertDeepThinkPluginSucceeded(string $pluginName, array $result): void
    {
        if ((string)($result['status'] ?? '') !== 'error') {
            return;
        }
        $errors = array_values(array_filter(array_map(
            static fn($error): string => trim((string)$error),
            (array)($result['errors'] ?? [])
        )));
        $detail = $errors !== [] ? implode('; ', $errors) : 'Plugin returned status=error.';
        throw new RuntimeException($pluginName . ' failed: ' . $detail);
    }

    private function runtimeConfig(array $payload, string $requestId): array
    {
        $config = $this->config;
        $config['request_id'] = $requestId;
        $config['agent_execution_timeout'] = max(90, (int)($payload['execution_timeout'] ?? $config['agent_execution_timeout'] ?? 300));
        $config['llm_narrator_timeout'] = max(4, (int)($payload['llm_narrator_timeout'] ?? $config['llm_narrator_timeout'] ?? 8));
        $config['llm_json_timeout'] = max(10, (int)($payload['llm_json_timeout'] ?? $config['llm_json_timeout'] ?? 20));
        $config['llm_answer_timeout'] = max(20, (int)($payload['llm_answer_timeout'] ?? $config['llm_answer_timeout'] ?? 40));
        return $config;
    }

    private function applyExecutionBudget(array $config): void
    {
        $timeout = max(60, (int)($config['agent_execution_timeout'] ?? 300));
        @ini_set('max_execution_time', (string)$timeout);
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout);
        }
    }

    private function logDiagnostic(string $requestId, string $event, array $payload = []): void
    {
        tekg_agent_append_diagnostic_log($requestId, $event, $payload);
    }

    private function resolveModel(array $payload): string
    {
        return 'deepseek-v4-flash';
    }

    private function resolveNarratorModel(array $payload, string $fallbackModel): string
    {
        return trim((string)($payload['narrator_model'] ?? $fallbackModel));
    }

    private function deterministicDiagnosticModel(array $deterministicAnswer): string
    {
        return (bool)($deterministicAnswer['summary_required'] ?? false) ? 'deterministic+summary' : 'deterministic';
    }

    private function resolveAnswerStructureModel(array $payload, array $analysis): string
    {
        if (trim((string)($payload['answer_structure_model'] ?? '')) !== '') {
            return trim((string)$payload['answer_structure_model']);
        }
        $intent = (string)($analysis['intent'] ?? 'relationship');
        return $this->isSimpleIntent($intent)
            ? trim((string)($this->config['deepseek_model'] ?? 'deepseek-chat'))
            : trim((string)($this->config['deepseek_model'] ?? $this->config['deepseek_reasoner_model'] ?? 'deepseek-chat'));
    }

    private function resolveWritingModel(array $payload, array $analysis): string
    {
        if (trim((string)($payload['writing_model'] ?? '')) !== '') {
            return trim((string)$payload['writing_model']);
        }
        $intent = (string)($analysis['intent'] ?? 'relationship');
        return $this->isSimpleIntent($intent)
            ? trim((string)($this->config['deepseek_model'] ?? 'deepseek-chat'))
            : trim((string)($this->config['deepseek_reasoner_model'] ?? $this->config['deepseek_model'] ?? 'deepseek-reasoner'));
    }

    private function resolveProcessLanguage(string $answerLanguage): string
    {
        return tekg_agent_detect_language(['language' => $answerLanguage], '');
    }

    private function answerTimeoutForModel(array $runtimeConfig, string $model): int
    {
        $provider = $this->inferProvider($model);
        if ($provider === 'deepseek' && stripos($model, 'reasoner') !== false) {
            return max(25, (int)($runtimeConfig['llm_answer_reasoner_timeout'] ?? $runtimeConfig['llm_answer_timeout'] ?? 35));
        }
        return max(12, (int)($runtimeConfig['llm_answer_chat_timeout'] ?? 18));
    }

    private function maxPluginSteps(array $payload, array $analysis): int
    {
        $intent = (string)($analysis['intent'] ?? 'relationship');
        $default = $this->isSimpleIntent($intent) ? 2 : 4;
        return max(1, min(5, (int)($payload['max_plugin_steps'] ?? $default)));
    }

    private function isSimpleIntent(string $intent): bool
    {
        return in_array($intent, ['sequence', 'relationship', 'classification', 'expression', 'genome'], true);
    }

    private function narrateEvent(string $model, string $language, array $event, string $fallback): string
    {
        if ($this->shouldUseDeterministicNarration($event)) {
            return $fallback;
        }
        $narrated = $this->llm->narrateEvent($model, $language, $event);
        return $narrated !== null && trim($narrated) !== '' ? trim($narrated) : $fallback;
    }

    private function shouldUseDeterministicNarration(array $event): bool
    {
        $type = (string)($event['type'] ?? '');
        if ($type === '') {
            return true;
        }

        if (in_array($type, ['tool_selected', 'tool_result', 'reflection', 'synthesizing'], true)) {
            return true;
        }

        if ($type !== 'analysis') {
            return false;
        }

        $intent = (string)($event['intent'] ?? '');
        if ($intent === '' && isset($event['entities'])) {
            return true;
        }

        return $this->isSimpleIntent($intent);
    }

    private function emitEvent(?callable $emit, int &$eventSequence, array $event): void
    {
        if (!isset($event['request_id']) && !empty($this->config['request_id'])) {
            $event['request_id'] = (string)$this->config['request_id'];
        }
        $event['node'] = (string)($event['node'] ?? 'Deep Think');
        $event['source'] = (string)($event['source'] ?? ($event['plugin_name'] ?? $event['node']));
        $event['inputs_used'] = array_values((array)($event['inputs_used'] ?? []));
        $event['outputs_changed'] = array_values((array)($event['outputs_changed'] ?? []));
        $event['message_payload'] = $event['message_payload'] ?? ($event['payload'] ?? []);
        $event['display_text'] = (string)($event['display_text'] ?? ($event['message'] ?? ''));
        $event['sequence'] = ++$eventSequence;
        if ($emit !== null) {
            $emit($event);
        }
    }

    private function formatSequenceCitations(array $citations): string
    {
        $labels = [];
        foreach (array_slice($citations, 0, 3) as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $pmid = trim((string)($citation['pmid'] ?? ''));
            $title = trim((string)($citation['title'] ?? ''));
            $year = trim((string)($citation['year'] ?? ''));
            if ($pmid !== '') {
                $labels[] = trim('PMID ' . $pmid . ($title !== '' ? ': ' . $title : '') . ($year !== '' ? ' (' . $year . ')' : ''));
                continue;
            }
            $labels[] = trim($title . ($year !== '' ? ' (' . $year . ')' : ''));
        }
        return implode('; ', array_filter($labels));
    }

}
