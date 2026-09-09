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

function call_agent_private(object $service, string $methodName, array $args): mixed
{
    $method = new ReflectionMethod($service, $methodName);
    return $method->invokeArgs($service, $args);
}

$service = new TekgAcademicAgentService([]);

$simpleAnalysis = [
    'intent' => 'sequence',
    'task_complexity' => 'simple_lookup',
    'recommended_mode' => 'deepthink',
    'task_complexity_reason' => 'Direct lookup covered by Deep Think.',
];
$simpleQuestion = 'L1HS 的序列是什么？';

assert_true(
    call_agent_private($service, 'shouldUseCompactPreflightGate', [$simpleQuestion, $simpleAnalysis]) === false,
    'P5A_B_001 Agent simple sequence lookup should not be compact-gated to Deep Think.'
);

$siteNavigationAnalysis = [
    'intent' => 'site_navigation',
    'task_complexity' => 'simple_lookup',
    'recommended_mode' => 'deep_think',
    'task_complexity_reason' => 'Direct site navigation covered by Deep Think.',
];
assert_true(
    call_agent_private($service, 'shouldUseCompactPreflightGate', ['我想看 L1HS 的 Genome Annotation Distribution，应该点哪里？', $siteNavigationAnalysis]) === false,
    'P5A_B_005 Agent simple site navigation should not be compact-gated to Deep Think.'
);

$normalizer = new TekgAgentEntityNormalizer();
$actualSiteNavigationAnalysis = $normalizer->analyze('我想看 L1HS 的 Genome Annotation Distribution，应该点哪里？');
assert_true(
    call_agent_private($service, 'shouldUseCompactPreflightGate', ['我想看 L1HS 的 Genome Annotation Distribution，应该点哪里？', $actualSiteNavigationAnalysis]) === false,
    'P5A_B_005 actual normalizer output should keep analysis but not compact-gate Agent.'
);
assert_true(
    call_agent_private($service, 'shouldUseCompactPreflightGate', ['帮我找 search.php 里看表达数据的入口。', $siteNavigationAnalysis]) === false,
    'P5A_B_029 Agent simple site navigation should not use compact preflight gate.'
);

$directSiteNavigationAnswer = 'Open the [sequence panel](http://localhost/TE-/search.php?q=L1HS#search-sequence-panel) for L1HS.';
$directSiteNavigationRoute = [
    'title' => 'Sequence',
    'description' => 'Open the sequence panel.',
    'url' => 'http://localhost/TE-/search.php?q=L1HS#search-sequence-panel',
    'capability' => 'sequence',
];
$directSiteNavigationResult = [
    'plugin_name' => 'Site Navigator Plugin',
    'status' => 'ok',
    'query_summary' => 'Matched 1 TE-KG site route candidate.',
    'display_summary' => 'Matched the question to the Sequence site route.',
    'results' => [
        'primary_route' => $directSiteNavigationRoute,
        'candidate_routes' => [$directSiteNavigationRoute],
        'answer_markdown' => $directSiteNavigationAnswer,
    ],
    'evidence_items' => [[
        'claim' => $directSiteNavigationAnswer,
        'source' => 'site_navigation',
    ]],
    'citations' => [],
    'result_counts' => ['routes' => 1],
    'confidence' => 0.99,
];
$directSiteNavigationAnalysis = [
    'intent' => 'site_navigation',
    'asks_for_site_navigation' => true,
];
$directEvidencePackage = EvidencePackage::fromPluginResults(
    'Where can I open the L1HS sequence panel in TE-KG?',
    $directSiteNavigationAnalysis,
    ['Site Navigator Plugin' => $directSiteNavigationResult]
);
assert_true(($directEvidencePackage['claims'] ?? null) === [], 'Site navigation does not become a scientific claim in EvidencePackage.');
assert_true(($directEvidencePackage['evidence_items'] ?? null) === [], 'Site navigation does not become scientific evidence in EvidencePackage.');
assert_true(str_contains((string)($directEvidencePackage['route_map'][0]['route']['url'] ?? ''), 'search-sequence-panel'), 'Site navigation URL stays available in EvidencePackage route_map.');
$serviceSource = (string)file_get_contents(__DIR__ . '/../api/agent/orchestrator/AcademicAgentService.php');
assert_true(str_contains($serviceSource, '$directSiteNavigationWriting = null;'), 'Agent keeps site navigation on the LLM Writing path.');
assert_true(str_contains($serviceSource, '->runWritingDecisionNode('), 'Agent Writing decision remains LLM-driven.');
assert_true(str_contains($serviceSource, '->writeEvidenceWalkDraft('), 'Agent final writing remains LLM-driven.');

$mechanismQuestion = '请写一份 LINE-1 如何导致癌症的机制综述报告';
$mechanismAnalysis = [
    'intent' => 'mechanism',
    'task_complexity' => 'research_synthesis',
    'recommended_mode' => 'agent',
    'task_complexity_reason' => 'Research, evidence, comparison, analytics, or report task.',
];
assert_true(
    call_agent_private($service, 'shouldUseCompactPreflightGate', [$mechanismQuestion, $mechanismAnalysis]) === false,
    'Mechanism review/report prompt must continue through the full Agent workflow.'
);

$comparisonQuestion = 'Compare L1HS and AluY disease evidence and rank the strongest links';
$comparisonAnalysis = [
    'intent' => 'comparison',
    'task_complexity' => 'research_synthesis',
    'recommended_mode' => 'deepthink',
    'task_complexity_reason' => 'Forced boundary case.',
];
assert_true(
    call_agent_private($service, 'shouldUseCompactPreflightGate', [$comparisonQuestion, $comparisonAnalysis]) === false,
    'Comparison/ranking prompt must not be compact-gated even if recommended_mode is Deep Think.'
);

$response = call_agent_private($service, 'buildCompactPreflightResponse', [
    $simpleQuestion,
    'academic',
    'test-request',
    'zh',
    'test-session',
    $simpleAnalysis,
    ['selected_plugins' => ['Sequence Plugin'], 'narrative' => 'Lookup sequence.'],
    [['step' => 'planning', 'title' => 'Planning', 'status' => 'done', 'details' => 'Lookup sequence.']],
    [],
    [],
    'low',
    ['sequence evidence may need Deep Think display confirmation'],
    ['core' => 'test-model'],
]);

assert_true(($response['answer_structure']['response_mode'] ?? '') === 'compact_boundary', 'Compact response declares compact_boundary mode.');
assert_true(($response['analysis']['routing_decision'] ?? '') === 'compact_preflight_deepthink', 'Analysis records compact routing decision.');
assert_true(($response['confidence'] ?? '') === 'low', 'Compact response accepts pipeline confidence labels.');
assert_true(($response['evaluation_report']['has_evidence_walk'] ?? true) === false, 'Compact response should not expose full evidence_walk artifact.');
assert_true(($response['evaluation_report']['has_report_plan'] ?? true) === false, 'Compact response should not expose full report_plan artifact.');
assert_true(($response['writing_failed'] ?? true) === false, 'Compact response remains a non-failed Agent API response.');
assert_true(str_contains((string)($response['answer'] ?? ''), 'Deep Think'), 'Compact response suggests Deep Think.');
assert_true(str_contains((string)($response['answer'] ?? ''), '简单查询'), 'Compact response respects zh language code.');

echo "Agent simple preflight gate tests passed.\n";
