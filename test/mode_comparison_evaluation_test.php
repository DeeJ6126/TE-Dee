<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/contracts/ModeComparisonEvaluation.php';

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

$simpleCase = [
    'case_id' => 'P5A_TEST_SIMPLE',
    'question' => 'L1HS 的序列是什么？',
    'category' => 'simple_sequence_lookup',
    'expected_best_mode' => 'deep_think',
    'comparison_metrics' => ['mode_boundary_accuracy', 'no_unnecessary_agent_workflow'],
];

$simpleDt = [
    'answer' => 'L1HS sequence is available in the sequence panel.',
    'used_plugins' => ['Sequence Plugin'],
    'citations' => [],
    'routes' => [['url' => 'http://localhost/TE-/search.php?q=L1HS&type=TE#search-sequence-panel']],
    'timings' => ['total_ms' => 1200],
];

$overkillAgent = [
    'answer' => 'Long research answer.',
    'analysis' => ['task_complexity' => 'simple_lookup'],
    'used_plugins' => ['Sequence Plugin', 'Graph Plugin', 'Literature Plugin', 'Literature Reading Plugin'],
    'evidence_package' => ['claims' => [['id' => 'claim_1']]],
    'evidence_walk' => ['walk_steps' => [['id' => 'walk_step_1']]],
    'report_plan' => ['sections' => [['title' => 'Evidence Review']]],
    'integrity_report' => ['draft' => ['ok' => true], 'polish' => ['ok' => true], 'warnings' => []],
    'writing_failed' => false,
    'models' => ['writer_draft' => 'deepseek-v4-pro', 'writer_polisher' => 'deepseek-v4-flash'],
    'timings' => ['writing_ms' => 5000],
];

$simpleComparison = ModeComparisonEvaluation::compare($simpleCase, $simpleDt, $overkillAgent);
assert_same('deep_think', $simpleComparison['recommended_mode'], 'simple case recommends Deep Think');
assert_same('low', $simpleComparison['agent_value_added'], 'simple overkill Agent has low value added');
assert_true($simpleComparison['comparison']['agent_overkill'] === true, 'simple overkill is detected');
assert_true($simpleComparison['comparison']['best_mode_match'] === true, 'expected simple best mode matches');

$researchCase = [
    'case_id' => 'P5A_TEST_RESEARCH',
    'question' => 'LINE-1 是如何导致癌症的？请给出机制链条。',
    'category' => 'mechanism_review',
    'expected_best_mode' => 'agent',
    'comparison_metrics' => ['artifact_success', 'citation_correctness', 'faithfulness'],
];

$briefDt = [
    'answer' => 'LINE-1 may contribute to cancer through genome instability.',
    'used_plugins' => ['Graph Plugin'],
    'citations' => [],
    'timings' => ['total_ms' => 1800],
];

$researchAgent = [
    'answer' => 'Evidence-walk report with PMID 12345 and route_id: route_1.',
    'analysis' => ['task_complexity' => 'research_synthesis', 'intent' => 'mechanism'],
    'used_plugins' => ['Graph Plugin', 'Literature Plugin', 'Literature Reading Plugin'],
    'citations' => [['pmid' => '12345']],
    'evidence_package' => [
        'claims' => [['id' => 'claim_1', 'citation_ids' => ['citation_1'], 'route_ids' => ['route_1']]],
        'citation_map' => [['id' => 'citation_1', 'citation' => ['pmid' => '12345']]],
        'route_map' => [['id' => 'route_1', 'route' => ['url' => 'http://localhost/TE-/preview.php']]],
        'metrics' => ['claim_count' => 1, 'evidence_count' => 1],
    ],
    'evidence_walk' => [
        'walk_steps' => [['id' => 'walk_step_1']],
        'claim_nodes' => [['id' => 'claim_node_1']],
        'support_edges' => [['id' => 'support_edge_1']],
    ],
    'report_plan' => ['sections' => [['title' => 'Mechanism Chain'], ['title' => 'Limitations']]],
    'integrity_report' => ['draft' => ['ok' => true], 'polish' => ['ok' => true], 'warnings' => []],
    'writing_failed' => false,
    'models' => ['writer_draft' => 'deepseek-v4-pro', 'writer_polisher' => 'deepseek-v4-flash'],
    'timings' => ['writing_ms' => 8000],
];

$researchComparison = ModeComparisonEvaluation::compare($researchCase, $briefDt, $researchAgent);
assert_same('agent', $researchComparison['recommended_mode'], 'research case recommends Agent');
assert_same('high', $researchComparison['agent_value_added'], 'research Agent has high value added');
assert_true($researchComparison['agent_report']['artifact_score'] > $researchComparison['dt_report']['artifact_score'], 'Agent artifact score is higher');
assert_true($researchComparison['comparison']['agent_deeper_than_dt'] === true, 'Agent depth delta is positive');
assert_same('deepseek-v4-pro', $researchComparison['agent_report']['models']['writer_draft'], 'writer draft model strategy is visible');
assert_same('deepseek-v4-flash', $researchComparison['agent_report']['models']['writer_polisher'], 'polisher model strategy is visible');

echo "ModeComparisonEvaluation tests passed.\n";
