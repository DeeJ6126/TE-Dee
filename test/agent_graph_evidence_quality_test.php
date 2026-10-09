<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/../api/agent/contracts/PluginResultContract.php';
tekg_agent_require_orchestrator_dependencies();
tekg_agent_require_plugin_files();

function check_graph(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "Assertion failed: $message\n");
        exit(1);
    }
}

function graph_private(object $object, string $method, array $args = []): mixed
{
    return (new ReflectionMethod($object, $method))->invokeArgs($object, $args);
}

$tes = array_map(static fn(string $name): array => ['type' => 'TE', 'label' => $name, 'canonical_label' => $name], ['L1HS', 'AluY', 'HERVK']);
$diseases = [['type' => 'Disease', 'label' => 'Cancer', 'canonical_label' => 'Cancer']];
$graph = new TekgAgentGraphPlugin(new TekgAgentNeo4jClient([]), new TekgAgentCitationResolver());
$requests = graph_private($graph, 'diseasePairRequests', [$tes, $diseases]);
check_graph(count($requests) === 3, 'Three-way comparison queries all three TE-disease pairs.');
check_graph(array_column(array_column($requests, 'te'), 'label') === ['L1HS', 'AluY', 'HERVK'], 'Batch scope preserves every requested TE.');
check_graph(array_column(array_column($requests, 'disease'), 'label') === ['Cancer', 'Cancer', 'Cancer'], 'Batch relation target stays the requested disease.');

$tree = new TekgAgentTreePlugin();
(new ReflectionProperty($tree, 'taxonomyItems'))->setValue($tree, [tekg_taxonomy_normalize_item([
    'name' => 'L1HS', 'taxonomy_source' => 'tree_rmsk_repbase', 'taxonomy_status' => 'leaf',
    'taxonomy_class' => 'Retrotransposons', 'taxonomy_order' => 'Non-LTR Retrotransposons (LINEs)',
    'taxonomy_superfamily' => 'L1 (LINE-1)', 'taxonomy_family' => 'L1PA',
])]);
$treeResult = $tree->run(['analysis' => ['asks_for_classification' => true, 'normalized_entities' => [$tes[0]]]]);
$classification = $treeResult['results'][0] ?? [];
check_graph(($classification['ranks']['family'] ?? '') === 'L1PA', 'L1PA is the recorded family rank.');
check_graph(!isset($classification['ranks']['subfamily']), 'Missing subfamily remains absent.');
check_graph(($classification['leaf_name'] ?? '') === 'L1HS' && array_key_exists('leaf_rank', $classification) && $classification['leaf_rank'] === null, 'Leaf name is not assigned an inferred taxonomy rank.');
check_graph(in_array('subfamily', $classification['unrecorded_ranks'] ?? [], true), 'Missing subfamily is visible to writing.');
check_graph(str_contains($treeResult['evidence_items'][0]['claim'] ?? '', 'family: L1PA'), 'Evidence preserves explicit rank labels.');
check_graph(str_contains($treeResult['evidence_items'][0]['claim'] ?? '', 'does not provide subfamily'), 'Evidence states subfamily cannot be inferred.');
check_graph(PluginResultContract::validate('Tree Plugin', $treeResult)['ok'], 'Classification retains native plugin contract.');

$genericAnalysis = ['intent' => 'graph_analytics', 'asks_for_graph_analytics' => true, 'normalized_entities' => [['type' => 'TE', 'label' => 'TE', 'canonical_label' => 'TE']]];
$question = 'Which disease has the strongest association with transposable elements in the knowledge graph?';
$analytics = new TekgAgentGraphAnalyticsPlugin(new TekgAgentNeo4jClient([]));
$plan = graph_private($analytics, 'selectQueryPlan', [$question, $genericAnalysis, $genericAnalysis['normalized_entities'], ['Disease']]);
check_graph(($plan['query_class'] ?? '') === 'top_diseases_by_te_count', 'Generic transposable elements means graph-wide disease scope.');
check_graph(str_contains($plan['cypher'], 'count(DISTINCT t)'), 'Disease ranking uses real distinct linked TE counts.');
check_graph(($plan['params'] ?? []) === [], 'Ranking does not filter on a generic TE node.');
$analyticsResult = graph_private($analytics, 'buildAnalyticsResult', [$plan, [['label' => 'Cancer', 'value' => 12, 'relation_count' => 18, 'node_type' => 'Disease']], $plan['cypher'], ['AGGREGATION']]);
$evidence = graph_private($analytics, 'buildEvidenceItems', [$analyticsResult]);
check_graph(str_contains($evidence[0]['claim'], '12 distinct linked TE nodes'), 'Answer evidence carries metric and unit.');
check_graph(str_contains($evidence[0]['claim'], 'does not measure biological association strength'), 'Ranking caveat survives the evidence projection.');

$cypher = new TekgAgentCypherExplorerPlugin(new TekgAgentNeo4jClient([]), new TekgAgentLlmClient([]), []);
$cypherPlan = graph_private($cypher, 'heuristicCypher', [$question, $genericAnalysis]);
check_graph(($cypherPlan['query_intent'] ?? '') === 'top_diseases_by_te_count', 'Cypher ranking shares the graph-wide metric.');
graph_private($cypher, 'assertGroundedMetricQuery', [$cypherPlan['generated_cypher']]);
foreach ([
    'MATCH (t:TE)-[r]->(d:Disease) RETURN coalesce(r.score,r.strength,r.weight,r.confidence,1.0) AS strength',
    'MATCH (t:TE)-[r]->(d:Disease) RETURN 1.0 AS score',
    'MATCH (t:TE)-[r]->(d:Disease) RETURN r.`association_strength` AS metric',
] as $invalidQuery) {
    $rejected = false;
    try { graph_private($cypher, 'assertGroundedMetricQuery', [$invalidQuery]); }
    catch (RuntimeException) { $rejected = true; }
    check_graph($rejected, 'Fabricated strength/score query is rejected.');
}
graph_private($cypher, 'assertGroundedMetricQuery', ['MATCH (t:TE)-[r]->(d:Disease) RETURN count(r) AS relation_count, count(DISTINCT t) AS distinct_te_count']);

echo "Agent graph evidence quality tests passed.\n";
