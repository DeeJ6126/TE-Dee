<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

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
    'eQTL Plugin' => TekgAgentEqtlPlugin::class,
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
assert_same(count($expectedPlugins), count($registryFiles), 'registry file count matches the public plugin catalog');
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

final class MalformedNativeFixturePlugin implements TekgAgentPluginInterface
{
    public int $runCount = 0;
    public function __construct(private readonly array $invalidResult) {}
    public function getName(): string { return 'Graph Plugin'; }
    public function run(array $context): array { $this->runCount++; return $this->invalidResult; }
}
$malformedPlugin = new MalformedNativeFixturePlugin($missing);
$service = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('bad-native', 'graph_lookup', ['te' => ['L1HS']])]),
    wrapper_turn([wrapper_call('valid-native', 'literature_search', ['te' => ['L1HS'], 'disease' => 'cancer'])]), wrapper_finish(),
    wrapper_turn([], 'The supplied record reports an observation of L1HS in cancer (PMID 12345).'),
]));
wrapper_inject($service, ['Graph Plugin' => $malformedPlugin, 'Literature Plugin' => new WrapperFixturePlugin('Literature Plugin')]);
$session = 'plugin-native-contract-' . bin2hex(random_bytes(6));
$events = [];
$response = $service->stream(['question' => 'Find local L1HS cancer evidence.', 'session_id' => $session],
    static function (array $event) use (&$events): void { $events[] = $event; });
assert_same(false, $response['failed'], 'A malformed native result can be followed by a valid independent evidence lookup');
assert_same(1, $malformedPlugin->runCount, 'The actual registry invokes the injected native plugin');
$graphResult = array_values(array_filter($response['plugin_calls'], static fn(array $result): bool => $result['plugin_name'] === 'Graph Plugin'))[0] ?? [];
assert_same('error', $graphResult['status'] ?? null, 'The runtime registry enforces the native contract before storing the result');
assert_same([], $graphResult['evidence_items'] ?? null, 'The invalid native result cannot enter stored scientific evidence');
assert_true(str_contains($graphResult['errors'][0] ?? '', 'Plugin result contract violation'), 'The real runtime retains the contract failure explanation');
foreach ($response['evidence_package']['claims'] as $claim) {
    assert_same('Literature Plugin', $claim['source_plugin'], 'Only the valid independent source contributes package claims');
}
assert_true(!in_array('error', array_column($events, 'type'), true), 'A recoverable plugin contract failure does not trigger a terminal UI error');
$toolEvent = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_result' && $event['plugin_name'] === 'Graph Plugin'))[0] ?? [];
assert_same('error', $toolEvent['payload']['status'] ?? null, 'The inspector exposes the original native-contract failure state');
wrapper_terminal($events, false);
wrapper_cleanup($session);

echo "Plugin native result contract tests passed.\n";
