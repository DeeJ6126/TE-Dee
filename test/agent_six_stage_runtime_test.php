<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    assert_true(str_contains($haystack, $needle), $message . " missing {$needle}");
}

function assert_call_uses_arguments(string $source, string $callName, array $needles, string $message): void
{
    $position = strpos($source, $callName);
    assert_true($position !== false, $message . " missing {$callName}");
    $snippet = substr($source, $position, 900);
    foreach ($needles as $needle) {
        assert_true(str_contains($snippet, $needle), $message . " missing {$needle}");
    }
}

$serviceSource = (string)file_get_contents(__DIR__ . '/../api/agent/orchestrator/AcademicAgentService.php');
foreach ([
    'runUnderstandingNode',
    'runPlanningNode',
    'runCollectingNode',
    'runExecutingReviewNode',
    'runIntegratingNode',
    'runWritingDecisionNode',
] as $methodName) {
    assert_contains($methodName, $serviceSource, "AcademicAgentService calls {$methodName}");
}
assert_contains("'node_llm_result'", $serviceSource, 'AcademicAgentService emits node_llm_result events');
assert_contains("'node_llm_error'", $serviceSource, 'AcademicAgentService emits node_llm_error events');
assert_contains("'six_stage_artifacts'", $serviceSource, 'AcademicAgentService response exposes six_stage_artifacts');
assert_contains('tool_execution_review.v1', $serviceSource, 'AcademicAgentService requires executing review artifact');
assert_contains('pluginResultForLlmReview', $serviceSource, 'AcademicAgentService sends compact plugin review payloads');
assert_contains('$claimEvidenceMap = (array)$integratingNode->parsed_json;', $serviceSource, 'AcademicAgentService stores claimEvidenceMap from integrating node');
assert_contains('$writingDecision = (array)$writingDecisionNode->parsed_json;', $serviceSource, 'AcademicAgentService stores writingDecision from writing node');
assert_contains('fallbackUnderstandingNodeResult(', $serviceSource, 'AcademicAgentService can fallback from failed Understanding LLM');
assert_contains('fallbackPlanningNodeResult(', $serviceSource, 'AcademicAgentService can fallback from failed Planning LLM');
assert_call_uses_arguments($serviceSource, '->runPlanningNode(', ["'plugin_directory' => tekg_agent_plugin_directory()"], 'Agent Planning receives plugin directory');
assert_call_uses_arguments($serviceSource, '->runCollectingNode(', ["'plugin_directory' => tekg_agent_plugin_directory()"], 'Agent first Collecting receives plugin directory');
assert_call_uses_arguments($serviceSource, '->writeEvidenceWalkDraft(', ['$claimEvidenceMap', '$writingDecision'], 'Draft writer call receives claim map and writing decision');
assert_call_uses_arguments($serviceSource, '->polishEvidenceWalkAnswer(', ['$claimEvidenceMap', '$writingDecision'], 'Polisher call receives claim map and writing decision');
$evidenceTraitSource = (string)file_get_contents(__DIR__ . '/../api/agent/orchestrator/traits/AcademicAgentEvidenceTrait.php');
assert_contains("'plugin_directory' => tekg_agent_plugin_directory()", $evidenceTraitSource, 'Agent iterative sufficiency receives plugin directory');

$badUnderstandingFixture = [
    'schema_version' => 'understanding_result.v1',
    'stage' => 'understanding',
    'language' => 'en',
    'question_summary' => 'Rank TE-disease associations by graph evidence.',
    'entities' => [['name' => 'LINE-1', 'type' => 'transposable_element']],
    'ambiguities' => [],
    'mode_boundary' => 'agent_research',
    'required_evidence' => ['graph'],
    'warnings' => [],
];

$service = new TekgAcademicAgentService(['agent_test_mode' => true]);
$pluginDirectory = tekg_agent_plugin_directory();
assert_true($pluginDirectory !== '', 'Plugin directory catalog is available to orchestrators');
assert_contains('Call each plugin at most once per run.', $pluginDirectory, 'Plugin directory documents the single-call rule');
assert_contains('Navigation-only.', $pluginDirectory, 'Plugin directory excludes Site Navigator from scientific evidence');
assert_contains('Literature Plugin returned usable citations', $pluginDirectory, 'Plugin directory documents Literature Reading prerequisites');
$reflection = new ReflectionClass($service);
assert_true($reflection->hasMethod('fallbackUnderstandingNodeResult'), 'AcademicAgentService exposes Understanding fallback builder');
assert_true($reflection->hasMethod('fallbackPlanningNodeResult'), 'AcademicAgentService exposes Planning fallback builder');
assert_true($reflection->hasMethod('nodeLlmSummary'), 'AcademicAgentService exposes node summary presentation builder');
assert_true($reflection->hasMethod('academicPresentationFailureMessage'), 'AcademicAgentService exposes localized presentation failure builder');

$understandingFallbackMethod = $reflection->getMethod('fallbackUnderstandingNodeResult');
$planningFallbackMethod = $reflection->getMethod('fallbackPlanningNodeResult');
$registeredRecommendationsMethod = $reflection->getMethod('registeredRecommendedExperts');
$academicPluginGateMethod = $reflection->getMethod('academicBusinessPluginMayRun');
$aggregateEvidenceMethod = $reflection->getMethod('aggregateEvidence');
$minimumEvidenceGateMethod = $reflection->getMethod('evaluateMinimumEvidenceGate');
$evaluateSufficiencyMethod = $reflection->getMethod('evaluateSufficiency');
$buildValidatedEvidencePackageMethod = $reflection->getMethod('buildValidatedEvidencePackage');
$buildSynthesizedEvidenceFromPackageMethod = $reflection->getMethod('buildSynthesizedEvidenceFromPackage');
$maybeAppendPluginsMethod = $reflection->getMethod('maybeAppendPlugins');
$nodeLlmSummaryMethod = $reflection->getMethod('nodeLlmSummary');
$academicPresentationFailureMessageMethod = $reflection->getMethod('academicPresentationFailureMessage');

$failedUnderstanding = new NodeLlmResult(
    'understanding',
    '',
    null,
    false,
    ['llm: stage=understanding provider=deepseek model=deepseek-v4-pro: Relay timed out.'],
    'understanding_result.v1'
);
$deterministicAnalysis = [
    'intent' => 'mechanism',
    'answer_language' => 'english',
    'normalized_entities' => [
        ['canonical_label' => 'LINE-1', 'type' => 'transposable_element'],
    ],
];
$understandingFallback = $understandingFallbackMethod->invoke(
    $service,
    'How can LINE-1 contribute to disease?',
    'english',
    $deterministicAnalysis,
    $failedUnderstanding
);
assert_true($understandingFallback instanceof NodeLlmResult, 'Understanding fallback returns NodeLlmResult');
assert_same(true, $understandingFallback->ok, 'Understanding fallback is accepted as conservative success');
assert_same('understanding_result.v1', $understandingFallback->schema_version, 'Understanding fallback keeps schema contract');
assert_same('mechanism', (string)($understandingFallback->parsed_json['intent'] ?? ''), 'Understanding fallback preserves normalizer intent');
assert_true(in_array('llm_unavailable_conservative_fallback', (array)($understandingFallback->parsed_json['warnings'] ?? []), true), 'Understanding fallback records warning');

$failedPlanning = new NodeLlmResult(
    'planning',
    '',
    null,
    false,
    ['llm: stage=planning provider=deepseek model=deepseek-v4-pro: Relay returned an empty response.'],
    'research_plan.v1'
);
$deterministicPlan = [
    'summary' => 'Question: How can LINE-1 contribute to disease?; intent=mechanism',
    'required_evidence' => ['structured relations', 'mechanism literature'],
    'knowledge_gaps' => [],
];
$planningFallback = $planningFallbackMethod->invoke(
    $service,
    $deterministicPlan,
    ['Entity Resolver', 'Graph Plugin', 'Literature Plugin'],
    $failedPlanning
);
assert_true($planningFallback instanceof NodeLlmResult, 'Planning fallback returns NodeLlmResult');
assert_same(true, $planningFallback->ok, 'Planning fallback is accepted as conservative success');
assert_same('research_plan.v1', $planningFallback->schema_version, 'Planning fallback keeps schema contract');
assert_true(in_array('llm_unavailable_conservative_fallback', (array)($planningFallback->parsed_json['risks'] ?? []), true), 'Planning fallback records risk');
assert_same(
    '已为 Graph Plugin 生成工具执行审查。',
    $nodeLlmSummaryMethod->invoke($service, new NodeLlmResult('executing', '', [], true, [], 'tool_execution_review.v1'), 'Graph Plugin', 'chinese'),
    'Chinese Agent node summary localizes narration while preserving plugin registry names'
);
assert_same(
    'Writing 阶段失败，未生成学术回答。',
    $academicPresentationFailureMessageMethod->invoke($service, 'Writing', 'chinese'),
    'Chinese Agent Writing failure uses localized presentation copy with English shell stage'
);
assert_same(
    'Writing failed, so no academic answer was generated.',
    $academicPresentationFailureMessageMethod->invoke($service, 'Writing', 'english'),
    'English Agent Writing failure keeps English presentation copy'
);

$filteredRecommendations = $registeredRecommendationsMethod->invoke(
    $service,
    ['Unknown Dynamic Plugin', 'Graph Plugin', 'Graph Plugin'],
    [],
    []
);
assert_same(['Graph Plugin'], $filteredRecommendations, 'Agent dynamic recommendations grant execution only to registered unique plugins');
foreach ([
    [false, ['Literature Plugin' => ['status' => 'ok', 'citations' => [['pmid' => '12345']]]]],
    [true, ['Literature Plugin' => ['status' => 'error', 'citations' => [['pmid' => '12345']]]]],
    [true, ['Literature Plugin' => ['status' => 'ok', 'citations' => []]]],
] as [$asksForPapers, $pluginResults]) {
    assert_same(
        false,
        $academicPluginGateMethod->invoke($service, 'Literature Reading Plugin', ['asks_for_papers' => $asksForPapers], $pluginResults),
        'Agent Literature Reading requires explicit papers and usable Literature Plugin citations'
    );
}
assert_same(
    true,
    $academicPluginGateMethod->invoke($service, 'Literature Reading Plugin', ['asks_for_papers' => true], [
        'Literature Plugin' => ['status' => 'partial', 'citations' => [['pmid' => '12345']]],
    ]),
    'Agent Literature Reading allows partial Literature Plugin results with citations'
);
foreach ([
    ['intent' => 'mechanism'],
    ['intent' => 'comparison'],
    ['intent' => 'relationship', 'task_complexity' => 'research_synthesis'],
] as $researchAnalysis) {
    assert_same(
        true,
        $academicPluginGateMethod->invoke($service, 'Literature Reading Plugin', $researchAnalysis, [
            'Literature Plugin' => ['status' => 'ok', 'citations' => [['pmid' => '12345']]],
        ]),
        'Agent Literature Reading allows research semantics with usable Literature Plugin citations'
    );
}
$navigationOnlyGate = $minimumEvidenceGateMethod->invoke($service, [
    'Site Navigator Plugin' => [
        'status' => 'ok',
        'evidence_items' => [['claim' => 'Open a TE-KG page.']],
        'citations' => [['url' => '/TE-/search.php?q=L1HS']],
    ],
], [
    'minimum_evidence_gate' => [
        'require_all_plugins' => ['Site Navigator Plugin'],
        'require_any_plugins' => ['Site Navigator Plugin'],
        'min_evidence_items' => 1,
        'min_citations' => 1,
    ],
]);
assert_same(false, $navigationOnlyGate['passed'] ?? null, 'Site Navigator does not satisfy Agent scientific minimum gate');
assert_true(in_array('insufficient evidence items', (array)($navigationOnlyGate['missing_dimensions'] ?? []), true), 'Site Navigator evidence is excluded from gate counts');
assert_true(in_array('insufficient traceable citations', (array)($navigationOnlyGate['missing_dimensions'] ?? []), true), 'Site Navigator citations are excluded from gate counts');
$navigationOnlySufficiency = $evaluateSufficiencyMethod->invoke($service, 'unused-model', 'Open the L1HS page.', [], [], [
    'Site Navigator Plugin' => [
        'status' => 'ok',
        'evidence_items' => [['claim' => 'Open a TE-KG page.']],
        'citations' => [['url' => '/TE-/search.php?q=L1HS']],
    ],
], [], [
    'minimum_evidence_gate' => [
        'min_evidence_items' => 1,
        'min_citations' => 1,
    ],
]);
assert_same(false, $navigationOnlySufficiency['is_sufficient'] ?? null, 'Agent production sufficiency path excludes Site Navigator from scientific minimum gate');
$navigationEvidencePackage = $buildValidatedEvidencePackageMethod->invoke($service, 'Open the L1HS page.', ['intent' => 'site_navigation'], [
    'Site Navigator Plugin' => [
        'plugin_name' => 'Site Navigator Plugin',
        'status' => 'ok',
        'results' => [
            'candidate_routes' => [['title' => 'Search', 'url' => '/TE-/search.php?q=L1HS']],
        ],
        'evidence_items' => [['claim' => 'Open a TE-KG page.']],
        'result_counts' => ['routes' => 1],
    ],
], 'agent-six-stage-runtime-navigation-package');
assert_same([], $navigationEvidencePackage['claims'] ?? null, 'Agent production EvidencePackage excludes Site Navigator scientific claims');
assert_same('/TE-/search.php?q=L1HS', $navigationEvidencePackage['route_map'][0]['route']['url'] ?? null, 'Agent production EvidencePackage retains Site Navigator URL');
$navigationSynthesis = $buildSynthesizedEvidenceFromPackageMethod->invoke($service, $navigationEvidencePackage);
assert_same([], $navigationSynthesis['supported_claims'] ?? null, 'Agent production synthesis excludes Site Navigator supported claims');
foreach ([
    ['intent' => 'mechanism'],
    ['intent' => 'comparison'],
    ['intent' => 'relationship', 'task_complexity' => 'research_synthesis'],
] as $researchAnalysis) {
    assert_same(
        ['Literature Reading Plugin'],
        $maybeAppendPluginsMethod->invoke($service, $researchAnalysis, [], 'Literature Plugin', [
            'status' => 'ok',
            'result_counts' => ['reviewed' => 1],
            'citations' => [['pmid' => '12345']],
        ], []),
        'Agent production plugin queue appends Literature Reading for research semantics with citations'
    );
}
assert_same(
    [],
    $maybeAppendPluginsMethod->invoke($service, ['intent' => 'relationship'], [], 'Literature Plugin', [
        'status' => 'ok',
        'result_counts' => ['reviewed' => 1],
        'citations' => [['pmid' => '12345']],
    ], []),
    'Agent production plugin queue does not append Literature Reading for ordinary simple questions'
);
$agentEvidence = $aggregateEvidenceMethod->invoke($service, [
    'Site Navigator Plugin' => ['evidence_items' => [['claim' => 'Open a TE-KG page.', 'support_strength' => 'high']]],
    'Graph Plugin' => ['evidence_items' => [['claim' => 'LINE-1 has a graph relation.', 'support_strength' => 'medium']]],
]);
assert_same(1, count($agentEvidence), 'Site Navigator Plugin does not enter Agent scientific evidence');
assert_same('Graph Plugin', $agentEvidence[0]['source_plugin'] ?? null, 'Agent evidence keeps the scientific source only');

echo "Six-stage runtime tests passed.\n";
