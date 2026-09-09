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

$service = new TekgAcademicAgentService(['agent_test_mode' => true]);
$reflection = new ReflectionClass($service);
assert_true($reflection->hasMethod('routingPolicyAllowsPlugin'), 'Agent has one shared routing-policy gate');

$allows = $reflection->getMethod('routingPolicyAllowsPlugin');
$registered = $reflection->getMethod('registeredRecommendedExperts');
$missingResearch = $reflection->getMethod('missingResearchSynthesisPlugins');
$initialQueue = $reflection->getMethod('initialPluginQueue');
$hardStop = $reflection->getMethod('evaluateHardStopCondition');
$minimumGate = $reflection->getMethod('evaluateMinimumEvidenceGate');
$recommendedNext = $reflection->getMethod('recommendedNextExperts');

$graphPolicy = [
    'primary_path' => ['Entity Resolver', 'Graph Analytics Plugin'],
    'fallback_path' => ['Cypher Explorer Plugin'],
    'candidate_experts' => ['Entity Resolver', 'Graph Analytics Plugin', 'Cypher Explorer Plugin'],
    'forbidden_path' => ['Literature Plugin', 'Literature Reading Plugin', 'Graph Plugin'],
    'minimum_evidence_gate' => [
        'require_any_plugins' => ['Graph Analytics Plugin', 'Cypher Explorer Plugin'],
        'require_sortable_statistics' => true,
    ],
    'hard_stop_conditions' => [
        'primary_plugin' => 'Graph Analytics Plugin',
        'allow_statuses' => ['ok', 'partial'],
        'min_result_count_key' => 'top_k',
        'min_result_count' => 1,
        'require_sortable_statistics' => true,
    ],
];
$graphAnalysis = [
    'intent' => 'graph_analytics',
    'task_complexity' => 'research_synthesis',
    'asks_for_papers' => false,
];

assert_same(false, $allows->invoke($service, 'Literature Plugin', $graphAnalysis, $graphPolicy), 'generic research_synthesis does not override forbidden literature');
assert_same(false, $allows->invoke($service, 'Graph Plugin', $graphAnalysis, $graphPolicy), 'graph analytics does not add ordinary Graph Plugin after planning');
assert_same(true, $allows->invoke($service, 'Cypher Explorer Plugin', $graphAnalysis, $graphPolicy), 'configured fallback remains available');
$graphWithPapers = array_merge($graphAnalysis, ['asks_for_papers' => true]);
assert_same(true, $allows->invoke($service, 'Literature Plugin', $graphWithPapers, $graphPolicy), 'explicit literature request overrides the route prohibition');

$plannedResearch = [
    'tool_plan' => [
        ['plugin' => 'Entity Resolver'],
        ['plugin' => 'Graph Analytics Plugin'],
        ['plugin' => 'Graph Plugin'],
        ['plugin' => 'Literature Plugin'],
    ],
];
$analyticsResult = [
    'status' => 'ok',
    'result_counts' => ['top_k' => 5],
    'results' => [
        'analytics_result' => [
            'metric_definition' => 'Count distinct Disease nodes connected to each TE.',
            'top_k' => [['label' => 'L1', 'value' => 219]],
        ],
    ],
];
$pluginResults = [
    'Entity Resolver' => ['status' => 'ok'],
    'Graph Analytics Plugin' => $analyticsResult,
];

assert_same(
    [],
    $missingResearch->invoke($service, $graphAnalysis, $plannedResearch, $pluginResults, $graphPolicy),
    'forbidden planner extras are not treated as required research layers'
);
assert_same(
    ['Literature Plugin'],
    $missingResearch->invoke($service, $graphWithPapers, $plannedResearch, $pluginResults, $graphPolicy),
    'explicit literature remains a required research layer'
);
assert_same(
    ['Cypher Explorer Plugin'],
    $registered->invoke(
        $service,
        ['Graph Plugin', 'Literature Plugin', 'Cypher Explorer Plugin'],
        $graphAnalysis,
        $pluginResults,
        $graphPolicy
    ),
    'model recommendations cannot re-add forbidden plugins but retain fallback'
);

assert_same(
    ['Entity Resolver', 'Graph Analytics Plugin'],
    $initialQueue->invoke($service, $graphAnalysis, $plannedResearch, $graphPolicy),
    'graph analytics initial queue remains minimal even when planning over-selects'
);
$explicitPlan = $plannedResearch;
assert_same(
    ['Entity Resolver', 'Graph Analytics Plugin', 'Literature Plugin'],
    $initialQueue->invoke($service, $graphWithPapers, $explicitPlan, $graphPolicy),
    'explicit literature survives initial queue policy filtering'
);

$stopDecision = $hardStop->invoke($service, $graphAnalysis, $pluginResults, $graphPolicy);
assert_same(true, $stopDecision['is_sufficient'] ?? null, 'sortable Graph Analytics result activates existing hard stop');
assert_same([], $stopDecision['recommended_next_experts'] ?? null, 'successful hard stop recommends no more plugins');

$emptyResults = [
    'Entity Resolver' => ['status' => 'ok'],
    'Graph Analytics Plugin' => [
        'status' => 'empty',
        'result_counts' => ['top_k' => 0],
        'results' => ['analytics_result' => ['top_k' => []]],
    ],
];
assert_same(null, $hardStop->invoke($service, $graphAnalysis, $emptyResults, $graphPolicy), 'empty primary result does not activate hard stop');
$failedGate = $minimumGate->invoke($service, $emptyResults, $graphPolicy);
assert_same(false, $failedGate['passed'] ?? null, 'empty primary result leaves minimum evidence gate open');
assert_same(
    ['Cypher Explorer Plugin'],
    $recommendedNext->invoke($service, $graphPolicy, $emptyResults, (array)($failedGate['missing_dimensions'] ?? []), $graphAnalysis),
    'empty primary result retains Cypher Explorer fallback'
);

echo "Agent routing stop-condition tests passed.\n";
