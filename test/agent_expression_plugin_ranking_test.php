<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_plugin_files();

function expression_ranking_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function expression_ranking_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$plugin = new TekgAgentExpressionPlugin();
$result = $plugin->run([
    'question' => 'In which tissues is L1HS expressed?',
    'analysis' => [
        'intent' => 'expression',
        'asks_for_expression' => true,
        'normalized_entities' => [[
            'type' => 'TE',
            'label' => 'L1HS',
            'canonical_label' => 'L1HS',
        ]],
    ],
    'plugin_results' => [],
]);

expression_ranking_assert_same('ok', $result['status'] ?? null, 'Expression Plugin returns a usable L1HS profile');
$datasets = $result['results'][0]['datasets'] ?? [];
$byKey = [];
foreach ($datasets as $dataset) {
    $byKey[(string)($dataset['dataset_key'] ?? '')] = $dataset;
}

$expected = [
    'normal_tissue' => ['skin', 'bone marrow'],
    'normal_cell_line' => ['cerebellar granule cell', 'endothelial cell of umbilical vein'],
    'cancer_cell_line' => ['Esophageal squamous cell carcinoma', 'Esophageal squamous cell carcinoma'],
];

foreach ($expected as $datasetKey => [$medianContext, $maxContext]) {
    $dataset = $byKey[$datasetKey] ?? null;
    expression_ranking_assert_true(is_array($dataset), "{$datasetKey} summary is present");
    expression_ranking_assert_same('median', $dataset['ranking_metric'] ?? null, "{$datasetKey} legacy top context is explicitly median-ranked");
    expression_ranking_assert_same($medianContext, $dataset['top_context'] ?? null, "{$datasetKey} top_context is the highest median context");
    expression_ranking_assert_same($medianContext, $dataset['top_median_context'] ?? null, "{$datasetKey} highest median context");
    expression_ranking_assert_same($maxContext, $dataset['top_max_context'] ?? null, "{$datasetKey} highest maximum context");
    expression_ranking_assert_true(is_numeric($dataset['top_median_value'] ?? null), "{$datasetKey} median ranking value is included");
    expression_ranking_assert_true(is_numeric($dataset['top_max_value'] ?? null), "{$datasetKey} maximum ranking value is included");
}

$claims = array_map(
    static fn(array $item): string => (string)($item['claim'] ?? ''),
    (array)($result['evidence_items'] ?? [])
);
$claimText = implode("\n", $claims);
expression_ranking_assert_true(str_contains($claimText, 'highest median expression'), 'Evidence names the median ranking metric');
expression_ranking_assert_true(str_contains($claimText, 'highest maximum observed expression'), 'Evidence names the maximum ranking metric');
expression_ranking_assert_true(!str_contains($claimText, 'prostate gland'), 'Default catalogue order is not reported as a top expression result');
expression_ranking_assert_true(!str_contains($claimText, 'foreskin fibroblast'), 'Default catalogue order does not leak into top expression evidence');
expression_ranking_assert_true(!str_contains($claimText, 'Anaplastic large cell lymphoma'), 'Default catalogue order does not leak into top expression evidence');

echo "Agent expression plugin ranking tests passed.\n";
