<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/../api/agent/contracts/EvidencePackage.php';
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

assert_true(function_exists('tekg_agent_make_diagnostic_item'), 'shared diagnostic item helper exists');
$diagnostic = tekg_agent_make_diagnostic_item(
    'Entity Resolver',
    'Resolved L1HS to a canonical TE entity.',
    ['match_confidence' => 1.0, 'match_type' => 'strict_alias'],
    ['title' => 'L1HS'],
    ['evidence_type' => 'entity_resolution', 'coverage_dimension' => 'routing']
);
assert_same('none', $diagnostic['support_strength'], 'diagnostic item has no scientific support strength');
assert_same(1.0, $diagnostic['diagnostic']['match_confidence'] ?? null, 'diagnostic metadata is preserved');
assert_true(in_array('not_biological_claim', $diagnostic['quality_flags'], true), 'diagnostic item is explicitly non-biological');
assert_true(function_exists('tekg_agent_is_diagnostic_evidence'), 'shared diagnostic classifier exists');
assert_same(true, tekg_agent_is_diagnostic_evidence($diagnostic), 'diagnostic helper output is classified as diagnostic');
$scientificFixture = tekg_agent_make_evidence_item('Graph Plugin', 'L1HS is associated with cancer.', 'L1HS', 'medium');
assert_same(false, tekg_agent_is_diagnostic_evidence($scientificFixture), 'scientific evidence is not classified as diagnostic');

$entityPlugin = new TekgAgentEntityResolverPlugin();
$entityResult = $entityPlugin->run(['analysis' => ['alias_chains' => [[
    'type' => 'TE',
    'canonical_label' => 'L1HS',
    'matched_alias' => 'L1HS',
    'aliases' => ['L1HS'],
    'confidence' => 1.0,
    'used_broad_alias' => false,
]]]]);
assert_same('none', $entityResult['evidence_items'][0]['support_strength'] ?? null, 'entity resolution confidence is not scientific evidence strength');
assert_same(1.0, $entityResult['evidence_items'][0]['diagnostic']['match_confidence'] ?? null, 'entity match confidence stays diagnostic');
$entityPackage = EvidencePackage::fromPluginResults('Resolve L1HS.', ['intent' => 'relationship'], [$entityResult]);
assert_same([], $entityPackage['claims'], 'entity-resolution diagnostics do not become claims');

tekg_agent_require_academic_agent_service();
tekg_agent_require_deepthink_service();
$aggregateFixture = [
    'Entity Resolver' => ['evidence_items' => [$diagnostic]],
    'Graph Plugin' => ['evidence_items' => [$scientificFixture]],
];
foreach ([
    'Agent' => new TekgAcademicAgentService([]),
    'DeepThink' => new TekgDeepThinkService([]),
] as $runtime => $service) {
    $aggregated = (new ReflectionMethod($service, 'aggregateEvidence'))->invoke($service, $aggregateFixture);
    assert_same(1, count($aggregated), "{$runtime} excludes diagnostics from scientific evidence aggregation");
    assert_same('L1HS is associated with cancer.', $aggregated[0]['claim'] ?? null, "{$runtime} retains scientific graph evidence");
}

$sitePlugin = new TekgAgentSiteNavigatorPlugin();
$siteResult = $sitePlugin->run([
    'question' => 'Where can I open the L1HS sequence panel?',
    'current_url' => 'http://localhost/TE-/index.php',
    'analysis' => [
        'intent' => 'sequence',
        'answer_language' => 'english',
        'normalized_entities' => [['type' => 'TE', 'canonical_label' => 'L1HS', 'label' => 'L1HS']],
    ],
]);
assert_same('none', $siteResult['evidence_items'][0]['support_strength'] ?? null, 'navigation confidence is not scientific evidence strength');
assert_same('site_navigation', $siteResult['evidence_items'][0]['evidence_type'] ?? null, 'navigation item is typed as diagnostic navigation');

$graphPlugin = new TekgAgentGraphPlugin(
    new TekgAgentNeo4jClient([]),
    new TekgAgentCitationResolver()
);
$graphFinish = new ReflectionMethod($graphPlugin, 'finish');
$graphResult = $graphFinish->invoke($graphPlugin, microtime(true), 'relationship', 'Fixture graph query.', [[
    'source_name' => 'L1HS',
    'target_name' => 'Cancer',
    'target_type' => 'Disease',
    'target_labels' => ['Disease'],
    'relation_type' => 'ASSOCIATED_WITH',
    'relation_description' => 'Stored graph association.',
    'pmids' => [],
    'evidence' => [],
    'matched_alias' => 'L1HS',
    'alias_mode' => 'strict',
]], []);
assert_same('medium', $graphResult['evidence_items'][0]['support_strength'] ?? null, 'strict alias does not elevate a graph association to high support');
assert_true(in_array('association_not_causality', $graphResult['evidence_items'][0]['quality_flags'] ?? [], true), 'graph relation warns against causal interpretation');

$analyticsPlugin = (new ReflectionClass(TekgAgentGraphAnalyticsPlugin::class))->newInstanceWithoutConstructor();
$analyticsEvidence = (new ReflectionMethod($analyticsPlugin, 'buildEvidenceItems'))->invoke($analyticsPlugin, [
    'query_class' => 'top_targets_for_te',
    'metric_definition' => 'Count stored relations.',
    'top_k' => [['rank' => 1, 'label' => 'Cancer', 'value' => 8, 'node_type' => 'Disease']],
]);
assert_same('medium', $analyticsEvidence[0]['support_strength'] ?? null, 'derived graph rank is medium support for the metric itself');
assert_same('graph_metric', $analyticsEvidence[0]['evidence_type'] ?? null, 'analytics evidence is explicitly typed');
assert_true(in_array('derived_metric', $analyticsEvidence[0]['quality_flags'] ?? [], true), 'analytics evidence records derivation');

$sequencePlugin = new TekgAgentSequencePlugin();
(new ReflectionProperty($sequencePlugin, 'dataset'))->setValue($sequencePlugin, [
    'db_to_repbase' => ['L1HS' => 'L1HS'],
    'entries_by_name' => ['L1HS' => [
        'name' => 'L1HS',
        'description' => 'LINE element with ORF1 and ORF2',
        'keywords' => ['LINE', 'ORF1', 'ORF2'],
        'sequence' => 'ACGTACGT',
        'references' => [],
    ]],
]);
$sequenceResult = $sequencePlugin->run([
    'question' => 'Describe the L1HS sequence structure.',
    'analysis' => ['alias_chains' => [[
        'type' => 'TE',
        'canonical_label' => 'L1HS',
        'matched_alias' => 'L1HS',
        'aliases' => ['L1HS'],
    ]]],
]);
assert_same(2, count($sequenceResult['evidence_items']), 'sequence record and keyword-derived hints are separate evidence items');
assert_same('sequence_record', $sequenceResult['evidence_items'][0]['evidence_type'] ?? null, 'exact sequence record is typed');
assert_same('structure_hint', $sequenceResult['evidence_items'][1]['evidence_type'] ?? null, 'structure hint is typed separately');
assert_same('low', $sequenceResult['evidence_items'][1]['support_strength'] ?? null, 'keyword-derived structure hints remain low support');
assert_true(in_array('keyword_derived', $sequenceResult['evidence_items'][1]['quality_flags'] ?? [], true), 'structure hint records keyword derivation');

$literatureSource = (string)file_get_contents(__DIR__ . '/../api/agent/plugins/LiteraturePlugin.php');
assert_true(str_contains($literatureSource, 'tekg_agent_make_diagnostic_item('), 'PubMed query execution uses a diagnostic item');

echo "Plugin evidence semantics tests passed.\n";
