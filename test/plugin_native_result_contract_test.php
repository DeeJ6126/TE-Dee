<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';

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

$contractPath = __DIR__ . '/../api/agent/contracts/PluginResultContract.php';
assert_true(is_file($contractPath), 'PluginResultContract.php must exist');
require_once $contractPath;

$expectedPlugins = [
    'Entity Resolver' => TekgAgentEntityResolverPlugin::class,
    'Site Navigator Plugin' => TekgAgentSiteNavigatorPlugin::class,
    'Graph Plugin' => TekgAgentGraphPlugin::class,
    'Graph Analytics Plugin' => TekgAgentGraphAnalyticsPlugin::class,
    'Cypher Explorer Plugin' => TekgAgentCypherExplorerPlugin::class,
    'Literature Plugin' => TekgAgentLiteraturePlugin::class,
    'Literature Reading Plugin' => TekgAgentLiteratureReadingPlugin::class,
    'Tree Plugin' => TekgAgentTreePlugin::class,
    'Expression Plugin' => TekgAgentExpressionPlugin::class,
    'Genome Plugin' => TekgAgentGenomePlugin::class,
    'Sequence Plugin' => TekgAgentSequencePlugin::class,
    'Citation Resolver' => TekgAgentCitationResolverPlugin::class,
];

$catalog = (string)file_get_contents(__DIR__ . '/../api/agent/plugins/PLUGIN_CATALOG.md');
$catalogNames = [];
preg_match_all('/^### (.+)$/m', $catalog, $catalogMatches);
foreach ((array)($catalogMatches[1] ?? []) as $name) {
    $catalogNames[] = trim((string)$name);
}
assert_same(array_keys($expectedPlugins), $catalogNames, 'catalog names must match the public registry order');

$registryFiles = tekg_agent_plugin_files();
tekg_agent_require_plugin_files();
assert_same(count($expectedPlugins), count($registryFiles), 'registry must contain exactly twelve plugin files');
foreach ($expectedPlugins as $expectedName => $className) {
    $plugin = (new ReflectionClass($className))->newInstanceWithoutConstructor();
    assert_same($expectedName, $plugin->getName(), "{$className} public name");
}

$testConfig = [
    'deepseek_key' => '',
    'dashscope_key' => '',
    'llm_relay_url' => '',
    'pubmed_cache_dir' => sys_get_temp_dir(),
    'pubmed_tool' => 'TEKGContractTest',
    'pubmed_email' => '',
];
tekg_agent_require_orchestrator_dependencies();
$defaultPlugins = tekg_agent_create_default_plugins(
    $testConfig,
    new TekgAgentNeo4jClient([]),
    new TekgAgentLlmClient($testConfig),
    new TekgAgentCitationResolver()
);
assert_same(array_keys($expectedPlugins), array_keys($defaultPlugins), 'default registry names match catalog names');
foreach ($defaultPlugins as $pluginName => $plugin) {
    $native = $plugin->run([
        'question' => '',
        'analysis' => ['intent' => 'unknown', 'alias_chains' => [], 'normalized_entities' => []],
        'plugin_results' => [],
        'planning' => [],
        'config' => ['deepseek_model' => 'deepseek-chat'],
    ]);
    $nativeValidation = PluginResultContract::validate($pluginName, $native);
    assert_same(true, $nativeValidation['ok'], "{$pluginName} empty/error branch native contract: " . implode('; ', $nativeValidation['errors']));
}

$valid = [
    'plugin_name' => 'Graph Plugin',
    'status' => 'ok',
    'query_summary' => 'Queried the graph.',
    'results' => ['rows' => [['source_name' => 'L1HS', 'target_name' => 'Cancer']]],
    'display_label' => 'Queried 1 graph relation',
    'display_summary' => 'Found one graph relation.',
    'display_details' => ['preview_items' => []],
    'result_counts' => ['relations' => 1],
    'evidence_items' => [tekg_agent_make_evidence_item(
        'Graph Plugin',
        'L1HS is associated with cancer in the local graph.',
        'L1HS',
        'medium',
        [],
        [],
        ['evidence_type' => 'graph_relation']
    )],
    'citations' => [['pmid' => '12345', 'title' => 'LINE-1 and cancer']],
    'errors' => [],
    'latency_ms' => 12,
];

$validation = PluginResultContract::validate('Graph Plugin', $valid);
assert_same(true, $validation['ok'], 'valid native result passes');
assert_same([], $validation['errors'], 'valid native result has no errors');
assert_same($valid, PluginResultContract::enforce('Graph Plugin', $valid), 'valid native result is preserved');

$missing = $valid;
unset($missing['result_counts']);
$missingValidation = PluginResultContract::validate('Graph Plugin', $missing);
assert_same(false, $missingValidation['ok'], 'missing native field fails');
assert_true(in_array('result_counts is required', $missingValidation['errors'], true), 'missing field is identified');

$wrongName = $valid;
$wrongName['plugin_name'] = 'Literature Plugin';
$wrongNameValidation = PluginResultContract::validate('Graph Plugin', $wrongName);
assert_same(false, $wrongNameValidation['ok'], 'wrong plugin name fails');
assert_true(in_array('plugin_name must equal Graph Plugin', $wrongNameValidation['errors'], true), 'wrong name is identified');

$negativeLatency = $valid;
$negativeLatency['latency_ms'] = -1;
assert_same(false, PluginResultContract::validate('Graph Plugin', $negativeLatency)['ok'], 'negative latency fails');

$invalidStatus = $valid;
$invalidStatus['status'] = 'failed';
assert_same(false, PluginResultContract::validate('Graph Plugin', $invalidStatus)['ok'], 'native failed status is rejected');

$okWithErrors = $valid;
$okWithErrors['errors'] = ['Neo4j timeout'];
assert_same(false, PluginResultContract::validate('Graph Plugin', $okWithErrors)['ok'], 'ok with errors fails');

$badEvidence = $valid;
$badEvidence['evidence_items'][0]['support_strength'] = 'certain';
assert_same(false, PluginResultContract::validate('Graph Plugin', $badEvidence)['ok'], 'invalid support strength fails');

$badCitation = $valid;
$badCitation['citations'] = [['source' => 'pubmed']];
assert_same(false, PluginResultContract::validate('Graph Plugin', $badCitation)['ok'], 'citation without stable identity fails');

$enforced = PluginResultContract::enforce('Graph Plugin', $missing);
assert_same('Graph Plugin', $enforced['plugin_name'], 'contract failure preserves expected plugin name');
assert_same('error', $enforced['status'], 'contract failure becomes native error');
assert_same([], $enforced['evidence_items'], 'contract failure does not fabricate evidence');
assert_true(str_contains($enforced['errors'][0] ?? '', 'Plugin result contract violation'), 'contract failure is visible');
foreach (PluginResultContract::requiredFields() as $field) {
    assert_true(array_key_exists($field, $enforced), "contract failure includes {$field}");
}

$academicSource = (string)file_get_contents(__DIR__ . '/../api/agent/orchestrator/AcademicAgentService.php');
$deepThinkSource = (string)file_get_contents(__DIR__ . '/../api/agent/orchestrator/traits/DeepThinkRoutingTrait.php');
foreach (['AcademicAgentService' => $academicSource, 'DeepThinkRoutingTrait' => $deepThinkSource] as $runtime => $source) {
    $runOffset = strpos($source, '$result = $plugin->run([');
    $enforceOffset = strpos($source, '$result = PluginResultContract::enforce($pluginName, $result);');
    $augmentOffset = strpos($source, '$result = $this->augmentPluginResult($pluginName, $result, $analysis, $planning);');
    assert_true($runOffset !== false, "{$runtime} runs plugins");
    assert_true($enforceOffset !== false, "{$runtime} enforces the native result contract");
    assert_true($augmentOffset !== false, "{$runtime} augments plugin results");
    assert_true($runOffset < $enforceOffset && $enforceOffset < $augmentOffset, "{$runtime} validates after run and before augmentation");
}
assert_same(2, substr_count($academicSource, 'PluginResultContract::enforce('), 'Agent validates business plugins and Citation Resolver');
assert_same(1, substr_count($deepThinkSource, 'PluginResultContract::enforce('), 'DeepThink validates its shared plugin execution path once');

echo "Plugin native result contract tests passed.\n";
