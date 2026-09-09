<?php
declare(strict_types=1);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$path = __DIR__ . '/../docs/eval/dt_agent_golden_cases.jsonl';
assert_true(is_file($path), 'dt_agent_golden_cases.jsonl should exist');

$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
assert_true(is_array($lines), 'golden cases should be readable');
assert_true(count($lines) === 30, 'golden cases should contain exactly 30 rows');

$ids = [];
$categories = [];
$modes = [];

foreach ($lines as $index => $line) {
    $case = json_decode($line, true);
    assert_true(is_array($case), 'line ' . ($index + 1) . ' should be valid JSON');
    foreach (['case_id', 'question', 'category', 'expected_best_mode', 'dt_expectation', 'agent_expectation', 'comparison_metrics'] as $key) {
        assert_true(array_key_exists($key, $case), "line " . ($index + 1) . " missing {$key}");
    }
    assert_true(!isset($ids[$case['case_id']]), 'case_id should be unique: ' . $case['case_id']);
    $ids[$case['case_id']] = true;
    $categories[(string)$case['category']] = true;
    $modes[(string)$case['expected_best_mode']] = true;
    assert_true(is_array($case['dt_expectation']), 'dt_expectation should be object for ' . $case['case_id']);
    assert_true(is_array($case['agent_expectation']), 'agent_expectation should be object for ' . $case['case_id']);
    assert_true(is_array($case['comparison_metrics']) && count($case['comparison_metrics']) >= 3, 'comparison_metrics should have at least 3 entries for ' . $case['case_id']);
}

foreach ([
    'simple_sequence_lookup',
    'site_navigation',
    'mechanism_review',
    'evidence_audit',
    'graph_ranking',
    'batch_comparison',
    'research_report',
    'boundary_claim_verification',
] as $requiredCategory) {
    assert_true(isset($categories[$requiredCategory]), "missing category {$requiredCategory}");
}

foreach (['deep_think', 'agent', 'boundary_deep_think'] as $requiredMode) {
    assert_true(isset($modes[$requiredMode]), "missing expected_best_mode {$requiredMode}");
}

echo "DT vs Agent golden cases tests passed.\n";
