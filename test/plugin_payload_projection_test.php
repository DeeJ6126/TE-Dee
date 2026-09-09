<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';

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

$projectionPath = __DIR__ . '/../api/agent/contracts/PluginResultProjection.php';
assert_true(is_file($projectionPath), 'PluginResultProjection.php must exist');
require_once $projectionPath;

$rows = [];
$nodes = [];
$edges = [];
$evidence = [];
for ($i = 1; $i <= 20; $i++) {
    $rows[] = ['source_name' => 'L1HS', 'target_name' => 'Target ' . $i, 'relation_type' => 'ASSOCIATED_WITH', 'description' => str_repeat('row detail ', 8)];
    $nodes[] = ['id' => 'node-' . $i, 'label' => 'Target ' . $i, 'type' => 'Disease'];
    if ($i > 1) {
        $edges[] = ['id' => 'edge-' . $i, 'source' => 'node-1', 'target' => 'node-' . $i, 'label' => 'ASSOCIATED_WITH'];
    }
    $evidence[] = tekg_agent_make_evidence_item('Graph Plugin', 'Graph claim ' . $i, 'L1HS', 'medium');
}
$raw = ['rows' => $rows, 'graph_elements' => ['nodes' => $nodes, 'edges' => $edges]];
$graphResult = [
    'plugin_name' => 'Graph Plugin',
    'status' => 'ok',
    'display_summary' => 'Graph summary.',
    'display_details' => [
        'summary' => 'Graph summary.',
        'preview_items' => array_slice($rows, 0, 5),
        'evidence_items' => $evidence,
        'citations' => [['pmid' => '12345', 'title' => 'Paper']],
        'raw_preview' => $raw,
    ],
    'result_counts' => ['relations' => 20],
    'evidence_items' => $evidence,
    'citations' => [['pmid' => '12345', 'title' => 'Paper']],
    'errors' => [],
    'results' => $raw,
    'raw_result' => $raw,
    'compressed_result' => ['carry_forward_fields' => ['raw_result_excerpt' => $raw]],
];

$legacyPayload = [
    'summary' => $graphResult['display_details']['summary'],
    'preview_items' => $graphResult['display_details']['preview_items'],
    'evidence_items' => $evidence,
    'citations' => $graphResult['citations'],
    'compressed_result' => $graphResult['compressed_result'],
    'raw_result' => $raw,
    'raw_preview' => $raw,
    'errors' => [],
    'result_counts' => $graphResult['result_counts'],
    'display_details' => $graphResult['display_details'],
];
$graphUi = PluginResultProjection::forUi($graphResult);
assert_true(!array_key_exists('raw_preview', $graphUi), 'UI projection does not repeat raw data under a second key');
assert_true(!array_key_exists('compressed_result', $graphUi), 'UI projection does not include LLM carry-forward data');
assert_true(!array_key_exists('display_details', $graphUi), 'UI projection flattens required display fields');
assert_same($raw['graph_elements'], $graphUi['raw_result']['graph_elements'] ?? null, 'graph elements remain available once');
assert_same(20, count($graphUi['raw_result']['rows'] ?? []), 'graph rows remain available for graph inspection');
assert_same(20, count($graphUi['evidence_items']), 'graph evidence remains inspectable');
$legacyBytes = strlen((string)json_encode($legacyPayload));
$projectedBytes = strlen((string)json_encode($graphUi));
assert_true($projectedBytes < (int)($legacyBytes * 0.7), 'representative graph UI payload is reduced by at least 30 percent');

$llmContext = PluginResultProjection::forLlmContext(
    'Graph Plugin',
    $graphResult,
    ['intent' => 'relationship'],
    ['required_evidence' => ['relationship']]
);
assert_same('Graph claim 1', $llmContext['key_findings'][0] ?? null, 'LLM context keeps bounded findings');
assert_same(5, count($llmContext['key_findings'] ?? []), 'LLM key findings use a named bound');
assert_same(10, count($llmContext['candidate_claims'] ?? []), 'LLM candidate claims use a named bound');
assert_same(10, count($llmContext['carry_forward_fields']['raw_result_excerpt']['rows'] ?? []), 'LLM raw row excerpt is bounded');

$sequence = str_repeat('ACGT', 500);
$sequenceResult = [
    'plugin_name' => 'Sequence Plugin',
    'status' => 'ok',
    'display_summary' => 'Sequence summary.',
    'display_details' => [
        'summary' => 'Sequence summary.',
        'preview_items' => [['title' => 'L1HS']],
        'full_sequences' => [['title' => 'L1HS', 'length' => strlen($sequence), 'sequence' => $sequence]],
        'evidence_items' => [],
        'citations' => [],
        'raw_preview' => ['matched_records' => [[
            'repbase_name' => 'L1HS',
            'entry' => ['name' => 'L1HS', 'sequence' => $sequence, 'description' => 'LINE record'],
        ]]],
    ],
    'result_counts' => ['matched_records' => 1],
    'evidence_items' => [],
    'citations' => [],
    'errors' => [],
    'raw_result' => ['matched_records' => []],
    'compressed_result' => [],
];
$sequenceUi = PluginResultProjection::forUi($sequenceResult);
assert_same($sequence, $sequenceUi['raw_result']['full_sequences'][0]['sequence'] ?? null, 'full sequence remains available in UI projection');
assert_true(!isset($sequenceUi['raw_result']['matched_records'][0]['entry']['sequence']), 'full sequence is not duplicated inside matched record metadata');

$academicSource = (string)file_get_contents(__DIR__ . '/../api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php');
$deepThinkSource = (string)file_get_contents(__DIR__ . '/../api/agent/orchestrator/traits/DeepThinkEvidenceTrait.php');
assert_true(str_contains($academicSource, 'PluginResultProjection::forUi($result)'), 'Agent uses shared UI projection');
assert_true(str_contains($deepThinkSource, 'PluginResultProjection::forUi($result)'), 'DeepThink uses shared UI projection');
assert_true(str_contains($academicSource, 'PluginResultProjection::forLlmContext($pluginName, $result, $analysis, $planning)'), 'Agent uses shared LLM projection');
assert_true(str_contains($deepThinkSource, 'PluginResultProjection::forLlmContext($pluginName, $result, $analysis, $planning)'), 'DeepThink uses shared LLM projection');

echo "Plugin payload projection tests passed.\n";
