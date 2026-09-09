<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_orchestrator_dependencies();
tekg_agent_require_plugin_files();

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

assert_true(function_exists('tekg_agent_plugin_status'), 'shared plugin status helper exists');
assert_same('ok', tekg_agent_plugin_status(true, []), 'usable data without errors is ok');
assert_same('partial', tekg_agent_plugin_status(true, ['one operation failed']), 'usable data with errors is partial');
assert_same('empty', tekg_agent_plugin_status(false, []), 'no data without errors is empty');
assert_same('error', tekg_agent_plugin_status(false, ['query failed']), 'no data with errors is error');

$graph = new TekgAgentGraphPlugin(new TekgAgentNeo4jClient([]), new TekgAgentCitationResolver());
$finish = new ReflectionMethod($graph, 'finish');
$mixed = $finish->invoke($graph, microtime(true), 'relationship', 'Mixed fixture.', [[
    'source_name' => 'L1HS',
    'target_name' => 'Cancer',
    'target_type' => 'Disease',
    'target_labels' => ['Disease'],
    'relation_type' => 'ASSOCIATED_WITH',
    'relation_description' => '',
    'pmids' => [],
    'evidence' => [],
]], ['A secondary graph query failed.']);
assert_same('partial', $mixed['status'], 'graph mixed success is partial');
assert_same(1, $mixed['result_counts']['relations'], 'graph mixed success preserves usable rows');
assert_same(['A secondary graph query failed.'], $mixed['errors'], 'graph mixed success preserves errors');

$empty = $finish->invoke($graph, microtime(true), 'relationship', 'Empty fixture.', [], []);
assert_same('empty', $empty['status'], 'graph no-data result is empty');

$failed = $finish->invoke($graph, microtime(true), 'relationship', 'Failure fixture.', [], ['Neo4j unavailable.']);
assert_same('error', $failed['status'], 'graph failure without data is error');

echo "Plugin status semantics tests passed.\n";
