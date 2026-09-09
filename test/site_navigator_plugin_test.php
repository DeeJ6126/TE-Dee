<?php
declare(strict_types=1);

require_once __DIR__ . '/../path_config.php';
require_once __DIR__ . '/../site_i18n.php';
require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/contracts/PluginResultEnvelope.php';

$pluginPath = __DIR__ . '/../api/agent/plugins/SiteNavigatorPlugin.php';
if (!is_file($pluginPath)) {
    fwrite(STDERR, "SiteNavigatorPlugin.php is missing.\n");
    exit(1);
}
require_once $pluginPath;

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

function navigator_context(string $question): array
{
    return [
        'question' => $question,
        'source_page' => 'index',
        'current_url' => 'http://localhost/tedee/index.php',
        'analysis' => [
            'intent' => 'genome',
            'normalized_entities' => [[
                'type' => 'TE',
                'label' => 'L1HS',
                'canonical_label' => 'L1HS',
                'display_label' => 'L1HS',
                'matched_alias' => 'L1HS',
                'aliases' => ['L1HS'],
            ]],
            'alias_chains' => [[
                'type' => 'TE',
                'canonical_label' => 'L1HS',
                'display_label' => 'L1HS',
                'matched_alias' => 'L1HS',
                'aliases' => ['L1HS'],
            ]],
        ],
    ];
}

$plugin = new TekgAgentSiteNavigatorPlugin();

$genome = $plugin->run(navigator_context('L1HS的Genome Annotation Distribution情况'));
assert_same('Site Navigator Plugin', $genome['plugin_name'] ?? '', 'plugin name');
assert_same('ok', $genome['status'] ?? '', 'genome navigation status');
assert_same('search-karyotype-panel', $genome['results']['primary_route']['fragment'] ?? '', 'genome distribution fragment');
assert_same('http://localhost/tedee/search.php?q=L1HS&type=TE#search-karyotype-panel', $genome['results']['primary_route']['url'] ?? '', 'absolute genome distribution URL');
assert_true(str_contains((string)($genome['results']['answer_markdown'] ?? ''), '[Genome Annotation Distribution]('), 'genome markdown link is clickable');

$sequence = $plugin->run(navigator_context('L1HS的完整序列在哪里看'));
assert_same('search-sequence-panel', $sequence['results']['primary_route']['fragment'] ?? '', 'sequence fragment');
assert_same('http://localhost/tedee/search.php?q=L1HS&type=TE#search-sequence-panel', $sequence['results']['primary_route']['url'] ?? '', 'absolute sequence URL');

$expression = $plugin->run(navigator_context('L1HS在哪些组织表达，Expression页面在哪里'));
assert_same('expression-detail-normal-tissue', $expression['results']['primary_route']['fragment'] ?? '', 'normal tissue expression fragment');
assert_same('http://localhost/tedee/expression_detail.php?te=L1HS#expression-detail-normal-tissue', $expression['results']['primary_route']['url'] ?? '', 'absolute normal tissue expression URL');

$browser = $plugin->run(navigator_context('L1HS的Genome Browser入口'));
assert_same('search-jbrowse-panel', $browser['results']['primary_route']['fragment'] ?? '', 'search JBrowse panel fragment');
assert_same('http://localhost/tedee/search.php?q=L1HS&type=TE#search-jbrowse-panel', $browser['results']['primary_route']['url'] ?? '', 'absolute JBrowse panel URL');

$envelope = PluginResultEnvelope::fromPluginResult($plugin->getName(), $genome, ['intent' => 'navigation']);
assert_same(count($genome['results']['candidate_routes']), $envelope['metrics']['result_count'], 'site navigator result_count must count routes');
assert_true(!array_key_exists('primary_confidence_percent', $genome['result_counts']), 'confidence percent must not be a result count');
assert_true(is_float($genome['confidence']) || is_int($genome['confidence']), 'top-level confidence is exposed');
assert_same((float)$genome['confidence'], $envelope['metrics']['confidence'], 'envelope confidence comes from top-level confidence');

$emptyPlugin = new TekgAgentSiteNavigatorPlugin();
$navigationMap = new ReflectionProperty(TekgAgentSiteNavigatorPlugin::class, 'navigationMap');
$navigationMap->setValue($emptyPlugin, ['routes' => []]);
$empty = $emptyPlugin->run(navigator_context('Unknown route please'));
assert_same('empty', $empty['status'], 'empty site navigation status');
assert_same(0, $empty['result_counts']['routes'], 'empty site navigation route count');
assert_same([], $empty['results']['candidate_routes'], 'empty site navigation candidates');
assert_true(is_array($empty['display_details']['preview_items']), 'empty display preview_items array');
assert_true(is_array($empty['evidence_items']), 'empty evidence_items array');
assert_true(is_array($empty['citations']), 'empty citations array');
assert_true(is_array($empty['errors']), 'empty errors array');
foreach ([
    'query_summary',
    'results',
    'display_label',
    'display_summary',
    'display_details',
    'result_counts',
    'evidence_items',
    'citations',
    'errors',
    'latency_ms',
] as $key) {
    assert_true(array_key_exists($key, $empty), "empty site navigation includes {$key}");
}
assert_same([], $empty['evidence_items'], 'empty site navigation does not fabricate evidence');

echo "Site Navigator Plugin tests passed.\n";
