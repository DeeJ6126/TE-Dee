<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/../api/agent/contracts/PluginResultContract.php';
tekg_agent_require_orchestrator_dependencies();
tekg_agent_require_plugin_files();

function eqtl_plugin_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function eqtl_plugin_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$fixtureRows = [];
for ($index = 0; $index < 10; $index++) {
    $fixtureRows[] = [
        'variant_id' => 'chr1_' . (100 + $index) . '_A_G_b38',
        'chrom' => 'chr1',
        'variant_start' => 99 + $index,
        'variant_end' => 100 + $index,
        'ref' => 'A',
        'alt' => 'G',
        'gene_id' => 'ENSG0000000000' . $index . '.1',
        'gene_name' => 'GENE' . $index,
        'tissue_key' => 'Liver',
        'tissue_name' => 'Liver',
        'pval_nominal' => pow(10, -12 + $index),
        'slope' => $index % 2 === 0 ? 0.2 : -0.2,
        'slope_se' => 0.01,
        'af' => 0.1,
        'ma_count' => 20,
    ];
}

$loaderCalls = [];
$plugin = new TekgAgentEqtlPlugin(static function (string $teName, int $limit) use (&$loaderCalls, $fixtureRows): array {
    $loaderCalls[] = [$teName, $limit];
    return [
        'scope' => [
            'resolved_te_name' => $teName,
            'scope' => 'instance',
            'variant_available' => true,
            'reason' => null,
        ],
        'rows' => $fixtureRows,
        'total' => 276,
        'available' => true,
        'metadata' => [
            'eqtl_version' => TEKG_VARIANT_EQTL_VERSION,
            'genome_build' => 'hg38',
            'tissue_scope' => 'all',
            'representative_order' => 'pval_nominal_asc',
        ],
    ];
});

$result = $plugin->run([
    'question' => 'Which eQTL associations are available for L1HS?',
    'analysis' => [
        'intent' => 'eqtl',
        'asks_for_eqtl' => true,
        'normalized_entities' => [[
            'type' => 'TE',
            'label' => 'L1HS',
            'canonical_label' => 'L1HS',
        ]],
    ],
    'plugin_results' => [],
]);

eqtl_plugin_assert_same([['L1HS', 10]], $loaderCalls, 'plugin queries one resolved TE with a ten-row limit');
eqtl_plugin_assert_same('ok', $result['status'] ?? null, 'usable associations return ok');
eqtl_plugin_assert_same(276, $result['result_counts']['associations'] ?? null, 'total association count is retained');
eqtl_plugin_assert_same(10, $result['result_counts']['representative_associations'] ?? null, 'ten representative rows are returned');
eqtl_plugin_assert_same('te_overlap_eqtl_association', $result['evidence_items'][0]['evidence_type'] ?? null, 'evidence type is explicit');
eqtl_plugin_assert_true(in_array('strict_te_variant_overlap', (array)($result['evidence_items'][0]['quality_flags'] ?? []), true), 'strict overlap provenance is retained');
$validation = PluginResultContract::validate('eQTL Plugin', $result);
eqtl_plugin_assert_same(true, $validation['ok'], 'usable result satisfies the native contract: ' . implode('; ', $validation['errors']));

$pluginBasenames = array_map('basename', tekg_agent_plugin_files());
eqtl_plugin_assert_true(
    in_array('EqtlPlugin.php', $pluginBasenames, true),
    'eQTL plugin is included in the shared Agent and DeepThink registry'
);
$routingPolicy = tekg_agent_routing_policy();
eqtl_plugin_assert_same(
    ['Entity Resolver', 'eQTL Plugin'],
    $routingPolicy['question_types']['eqtl']['primary_path'] ?? null,
    'eQTL intent routes directly through entity resolution and the eQTL plugin'
);

$normalizer = new TekgAgentEntityNormalizer();
$english = $normalizer->analyze('Show the eQTL data for L1HS.');
eqtl_plugin_assert_same('eqtl', $english['intent'] ?? null, 'English eQTL question uses the eQTL intent');
eqtl_plugin_assert_same(true, $english['asks_for_eqtl'] ?? null, 'English eQTL flag is set');
eqtl_plugin_assert_same(false, $english['asks_for_expression'] ?? null, 'eQTL is not misrouted to expression summaries');
$chinese = $normalizer->analyze('查询 L1HS 的表达数量性状位点。');
eqtl_plugin_assert_same('eqtl', $chinese['intent'] ?? null, 'Chinese eQTL question uses the eQTL intent');

$emptyPlugin = new TekgAgentEqtlPlugin(static fn(string $teName, int $limit): array => [
    'scope' => ['resolved_te_name' => $teName, 'scope' => 'instance', 'variant_available' => true],
    'rows' => [],
    'total' => 0,
    'available' => true,
    'metadata' => ['eqtl_version' => TEKG_VARIANT_EQTL_VERSION, 'genome_build' => 'hg38'],
]);
$empty = $emptyPlugin->run([
    'analysis' => ['normalized_entities' => [['type' => 'TE', 'canonical_label' => 'AluY']]],
    'plugin_results' => [],
]);
eqtl_plugin_assert_same('empty', $empty['status'] ?? null, 'normal zero-result lookup returns empty');
eqtl_plugin_assert_same('none', $empty['evidence_items'][0]['support_strength'] ?? null, 'empty result is diagnostic rather than biological evidence');

echo "Agent eQTL plugin tests passed.\n";
