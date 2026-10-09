<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/../api/agent/orchestrator/QaToolRegistry.php';
tekg_agent_require_orchestrator_dependencies();
tekg_agent_require_plugin_files();

function qa_registry_assert(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException('Assertion failed: ' . $message); }
}

function qa_registry_reject(callable $call, string $message): void
{
    try { $call(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Expected argument rejection: ' . $message);
}

final class QaRegistryFixturePlugin implements TekgAgentPluginInterface
{
    public array $contexts = [];
    public function __construct(private readonly string $name) {}
    public function getName(): string { return $this->name; }
    public function run(array $context): array
    {
        $this->contexts[] = $context;
        return ['plugin_name' => $this->name, 'status' => 'empty', 'query_summary' => 'Fixture', 'results' => [],
            'display_label' => 'Fixture', 'display_summary' => 'Fixture', 'display_details' => [], 'result_counts' => [],
            'evidence_items' => [], 'citations' => [], 'errors' => [], 'latency_ms' => 0];
    }
}

$plugins = [];
foreach (['Graph Plugin', 'Literature Plugin', 'Cypher Explorer Plugin', 'Tree Plugin', 'Expression Plugin', 'eQTL Plugin', 'Genome Plugin', 'Sequence Plugin', 'Graph Analytics Plugin', 'Site Navigator Plugin', 'Entity Resolver', 'Citation Resolver', 'Literature Reading Plugin'] as $name) {
    $plugins[$name] = new QaRegistryFixturePlugin($name);
}
$registry = new TekgAgentQaToolRegistry($plugins);
qa_registry_assert(count($registry->schemas()) === 10, 'Only parameterized business tools are exposed.');
$eqtlSchema = array_values(array_filter($registry->schemas(), static fn(array $tool): bool => $tool['function']['name'] === 'eqtl_lookup'))[0];
qa_registry_assert($eqtlSchema['function']['parameters']['properties']['te']['maxItems'] === 1, 'The eQTL schema exposes its actual single-TE contract.');
qa_registry_assert(!in_array('Entity Resolver', $registry->names(), true) && !in_array('Literature Reading Plugin', $registry->names(), true), 'Resolvers and nested LLM readers are not model-selected tools.');
$base = ['question' => 'Compare L1HS, AluY and HERVK in cancer.', 'analysis' => [
    'normalized_entities' => [
        ['type' => 'TE', 'label' => 'L1HS', 'canonical_label' => 'L1HS', 'aliases' => ['L1HS'], 'broad_aliases' => ['LINE-1']],
        ['type' => 'TE', 'label' => 'AluY', 'canonical_label' => 'AluY', 'aliases' => ['AluY']],
        ['type' => 'TE', 'label' => 'HERVK', 'canonical_label' => 'HERVK', 'aliases' => ['HERV-K']],
        ['type' => 'Disease', 'label' => 'cancer', 'canonical_label' => 'Cancer', 'aliases' => ['cancer']],
    ], 'alias_chains' => [['type' => 'TE', 'label' => 'L1HS']],
    'asks_for_classification' => true, 'question_keywords' => ['inherited unwanted keyword'],
], 'plugin_results' => ['Graph Plugin' => ['citations' => [['pmid' => 'wrong-scope']]], 'Entity Resolver' => ['results' => ['alias_chains' => [['type' => 'TE', 'label' => 'L1HS']]]]]];

$args = $registry->validate('literature_search', ['te' => 'AluY']);
qa_registry_assert($args['te'] === ['AluY'] && $args['page'] === 0 && $args['limit'] === 8 && $args['focus_keywords'] === [], 'Explicit scalar TE is normalized and defaults are stable.');
qa_registry_assert($args === $registry->validate('literature_search', $args), 'Validation normalization is idempotent.');
$context = $registry->buildContext('literature_search', ['te' => ['HERV-K'], 'disease' => 'cancer', 'focus_keywords' => ['insertion'], 'page' => 1], $base);
qa_registry_assert(array_column(tekg_agent_context_resolved_entities($context), 'canonical_label') === ['HERVK', 'Cancer'], 'Requested alias preserves normalized entities and excludes unrelated TEs.');
qa_registry_assert(array_column(tekg_agent_context_alias_chains($context), 'canonical_label') === ['HERVK', 'Cancer'], 'Sequence consumers receive selected alias chains.');
qa_registry_assert(!isset($context['plugin_results']['Graph Plugin']), 'Prior graph citations do not contaminate a refined scope.');
qa_registry_assert($context['analysis']['question_keywords'] === ['insertion'], 'Refinement replaces inherited question keywords.');
$graphHistory = $base;
$graphHistory['plugin_results']['Graph Plugin'] = ['results' => ['rows' => [
    ['source_name' => 'L1HS', 'requested_te' => 'L1HS', 'target_name' => 'Cancer', 'requested_disease' => 'Cancer', 'target_type' => 'Disease', 'pmids' => ['101010']],
    ['source_name' => 'AluY', 'requested_te' => 'AluY', 'target_name' => 'Cancer', 'requested_disease' => 'Cancer', 'target_type' => 'Disease', 'pmids' => ['202020']],
    ['source_name' => 'AluY', 'requested_te' => 'AluY', 'target_name' => 'Alzheimer', 'requested_disease' => 'Alzheimer', 'target_type' => 'Disease', 'pmids' => ['303030']],
]], 'citations' => [['pmid' => '101010', 'title' => 'L1HS graph evidence'], ['pmid' => '202020', 'title' => 'AluY graph evidence', 'abstract_summary' => 'Stored graph evidence excerpt, not a fetched abstract.'], ['pmid' => '303030', 'title' => 'Another disease evidence'], ['pmid' => '404040', 'title' => 'Generic cancer paper']]];
$selectedContext = $registry->buildContext('literature_search', ['te' => 'AluY', 'disease' => 'Cancer'], $graphHistory);
qa_registry_assert(array_column($selectedContext['plugin_results']['Graph Plugin']['citations'] ?? [], 'pmid') === ['202020'], 'Graph reuse excludes another TE, another disease and unmatched generic cancer citations.');
qa_registry_assert($selectedContext['plugin_results']['Graph Plugin']['citations'][0]['entity_scopes'] === ['AluY', 'Cancer'], 'Retained graph citation explicitly carries the selected pair scope.');
qa_registry_assert($selectedContext['plugin_results']['Graph Plugin']['citations'][0]['material_level'] === 'graph_summary' && $selectedContext['plugin_results']['Graph Plugin']['citations'][0]['abstract_status'] === 'not_fetched', 'Graph excerpts are not labelled as fetched abstracts.');
$diseaseTaxonomy = $registry->buildContext('taxonomy_lookup', ['disease' => 'Cancer'], $base);
qa_registry_assert(array_column(tekg_agent_context_resolved_entities($diseaseTaxonomy), 'type') === ['Disease'] && !$diseaseTaxonomy['analysis']['asks_for_classification'], 'Disease-only taxonomy remains available without adding top-level TE classifications.');
$context = $registry->buildContext('graph_lookup', ['te' => ['L1HS', 'AluY'], 'target_types' => ['Protein']], $base);
qa_registry_assert($context['analysis']['intent'] === 'comparison', 'Multi-TE graph calls retrieve independent scopes rather than pair lineage.');
qa_registry_assert(tekg_agent_context_alias_chains($context)[1]['canonical_label'] === 'L1HS' && tekg_agent_context_alias_chains($context)[1]['broad_aliases'] === ['LINE-1'], 'Original aliases are retained for selected entities.');

foreach ([
    ['literature_search', ['te' => ['AluY'], 'url' => 'https://example.org']],
    ['literature_search', ['te' => ['AluY'], 'api_key' => 'secret']],
    ['literature_search', ['te' => ['AluY'], 'page' => 21]],
    ['literature_search', ['te' => ['AluY'], 'limit' => 100]],
    ['literature_search', ['te' => ['AluY'], 'focus_keywords' => ['https://example.org']]],
    ['graph_lookup', ['te' => ['AluY'], 'target_types' => ['UnknownLabel']]],
    ['sequence_lookup', ['te' => [], 'full_sequence' => true]],
    ['sequence_lookup', ['te' => ['AluY'], 'full_sequence' => 'yes']],
    ['eqtl_lookup', ['te' => ['L1HS', 'AluY']]],
    ['graph_statistics', ['metric' => 'top_targets_for_te']],
    ['taxonomy_lookup', []],
    ['graph_statistics', ['metric' => 'diseases_by_te_count', 'te' => 'AluY']],
    ['cypher_query', ['query' => 'MATCH (t) CALL db.labels() RETURN t']],
    ['cypher_query', ['query' => 'MATCH (t) RETURN t LIMIT 1000']],
    ['cypher_query', ['query' => 'MATCH (t) RETURN t LIMIT $limit', 'params' => ['limit' => 500]]],
    ['cypher_query', ['query' => 'MATCH (t) RETURN t LIMIT 1 + 100000']],
    ['cypher_query', ['query' => 'MATCH (t) RETURN t', 'params' => ['api_key' => 'secret']]],
] as [$tool, $invalid]) {
    qa_registry_reject(fn() => $registry->validate($tool, $invalid), 'Bounded closed arguments for ' . $tool);
}
qa_registry_reject(fn() => $registry->buildContext('expression_lookup', ['te' => ['invented TE']], $base), 'A new scope cannot be invented.');
qa_registry_reject(fn() => $registry->buildContext('sequence_lookup', ['te' => ['AluY'], 'full_sequence' => true], $base), 'An unrequested full-sequence delivery is rejected.');
$known = $base + ['discovered_entities' => [['type' => 'TE', 'label' => 'AluJb', 'canonical_label' => 'AluJb']]];
qa_registry_assert(tekg_agent_context_resolved_entities($registry->buildContext('expression_lookup', ['te' => ['AluJb']], $known))[0]['label'] === 'AluJb', 'A TE explicitly discovered in tool evidence can be followed up.');
$unresolved = ['question' => 'Look up TEST_TE consensus length.', 'analysis' => []];
qa_registry_assert(tekg_agent_context_resolved_entities($registry->buildContext('sequence_lookup', ['te' => 'TEST_TE'], $unresolved))[0]['canonical_label'] === 'TEST_TE', 'An unrecognized explicit question name remains an exact lookup without guessed aliases.');

$first = $registry->execute('literature_search', ['te' => 'AluY', 'page' => 0], $base);
$second = $registry->execute('literature_search', ['te' => 'AluY', 'page' => 1], $base);
qa_registry_assert(count($plugins['Literature Plugin']->contexts) === 2 && $plugins['Literature Plugin']->contexts[1]['qa_tool_arguments']['page'] === 1, 'The same plugin can execute again with changed arguments.');
qa_registry_assert(PluginResultContract::validate('Literature Plugin', $first)['ok'] && PluginResultContract::validate('Literature Plugin', $second)['ok'], 'Existing plugin result contract remains enforced.');

$nativeCypher = new TekgAgentCypherExplorerPlugin(new TekgAgentNeo4jClient([]), new TekgAgentLlmClient([]), []);
$directRegistry = new TekgAgentQaToolRegistry(['Cypher Explorer Plugin' => $nativeCypher]);
$direct = $directRegistry->execute('cypher_query', ['query' => 'MATCH (t:TE) CREATE (t)-[:FAKE]->(t) RETURN t'], $base);
qa_registry_assert($direct['status'] === 'error' && str_contains(implode(' ', $direct['errors']), 'non-read-only'), 'Explicit query still passes through existing read-only rejection.');
qa_registry_assert(($direct['results']['cypher_result']['query_intent'] ?? '') === 'explicit_read_only_query', 'Explicit query takes the direct path without a second LLM plan.');
qa_registry_assert(PluginResultContract::validate('Cypher Explorer Plugin', $direct)['ok'], 'Rejected explicit query preserves the scientific plugin contract.');

$literature = new TekgAgentLiteraturePlugin(new TekgAgentNeo4jClient([]), ['pubmed_cache_dir' => sys_get_temp_dir()], new TekgAgentCitationResolver());
$private = static fn(object $object, string $method, array $arguments = []): mixed => (new ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
$jointEntities = tekg_agent_context_resolved_entities($selectedContext);
qa_registry_assert($private($literature, 'collectLocalCitations', [[], $jointEntities, true]) === [], 'Explicit pair searches never substitute independent Disease->Paper or TE->Paper citations.');
qa_registry_assert(array_column($private($literature, 'collectLocalCitations', [$selectedContext['plugin_results']['Graph Plugin'], $jointEntities, true]), 'pmid') === ['202020'], 'The actual pair-linked graph citation remains usable local provenance.');
$queryContext = $registry->buildContext('literature_search', ['te' => ['AluY'], 'disease' => 'Cancer', 'focus_keywords' => ['insertion'], 'page' => 2, 'limit' => 4], $base);
$queryPlan = $private($literature, 'buildPubMedQueries', [$queryContext['question'], $queryContext['analysis'], [], tekg_agent_context_resolved_entities($queryContext)]);
qa_registry_assert(count($queryPlan) === 1 && $queryPlan[0]['entity_scope'] === ['AluY', 'Cancer'], 'Refined PubMed scope stays exactly the selected normalized entities.');
qa_registry_assert(str_contains($queryPlan[0]['query_term'], '"insertion"[Title/Abstract]') && !str_contains($queryPlan[0]['query_term'], 'L1HS'), 'Keyword refinement does not inherit the first TE or the original broad question.');
$apostropheBase = $base;
$apostropheBase['question'] = "Find AluY literature in Alzheimer's disease.";
$apostropheContext = $registry->buildContext('literature_search', ['te' => 'AluY', 'disease' => "Alzheimer's disease"], $apostropheBase);
$apostropheQueries = $private($literature, 'buildPubMedQueries', [$apostropheContext['question'], $apostropheContext['analysis'], [], tekg_agent_context_resolved_entities($apostropheContext)]);
qa_registry_assert(str_contains($apostropheQueries[0]['query_term'], "\"Alzheimer's disease\"[Title/Abstract]"), 'Apostrophes in real disease names retain the required disease query clause.');
$url = $private($literature, 'pubmedSearchUrl', ['AluY cancer', 4, 2, ['db' => 'pubmed', 'retmode' => 'json']]);
parse_str((string)parse_url($url, PHP_URL_QUERY), $queryParams);
qa_registry_assert($queryParams['retstart'] === '8' && $queryParams['retmax'] === '4', 'Page controls actual ESearch offsets.');
qa_registry_assert($private($literature, 'pubmedCachePath', ['AluY cancer', 8, 0]) !== $private($literature, 'pubmedCachePath', ['AluY cancer', 8, 1]), 'Page is part of the versioned cache identity.');
qa_registry_assert($private($literature, 'pubmedCachePath', ['AluY cancer', 8, 0]) !== $private($literature, 'pubmedCachePath', ['AluY cancer insertion', 8, 0]), 'Refinement has a separate cache identity.');
$expired = new TekgAgentLiteraturePlugin(new TekgAgentNeo4jClient([]), ['qa_deadline' => microtime(true) - 1], new TekgAgentCitationResolver());
$failed = false;
try { $private($expired, 'remainingHttpTimeout'); } catch (RuntimeException $error) { $failed = str_contains($error->getMessage(), 'time budget expired'); }
qa_registry_assert($failed, 'Expired run rejects HTTP work before sending a request.');
$remaining = new TekgAgentLiteraturePlugin(new TekgAgentNeo4jClient([]), ['qa_deadline' => microtime(true) + 10], new TekgAgentCitationResolver());
qa_registry_assert($private($remaining, 'remainingHttpTimeout') <= 10, 'Every HTTP request is bounded by remaining QA budget.');

// Cached native retrieval exercises the whole plugin without network dependencies.
$cacheDirectory = sys_get_temp_dir() . '/qa_tool_registry_' . bin2hex(random_bytes(6));
mkdir($cacheDirectory, 0700);
$cachedLiterature = new TekgAgentLiteraturePlugin(new TekgAgentNeo4jClient([]), ['pubmed_cache_dir' => $cacheDirectory], new TekgAgentCitationResolver());
$cacheContext = $registry->buildContext('literature_search', ['te' => ['AluY'], 'disease' => 'Cancer', 'page' => 1], $base);
$cacheQueries = $private($cachedLiterature, 'buildPubMedQueries', [$cacheContext['question'], $cacheContext['analysis'], [], tekg_agent_context_resolved_entities($cacheContext)]);
$term = $cacheQueries[0]['query_term'];
$cachePath = $private($cachedLiterature, 'pubmedCachePath', [$term, 8, 1]);
$records = [];
for ($index = 0; $index < 8; $index++) {
    $records[] = ['source' => 'pubmed', 'pmid' => (string)(10000001 + $index), 'title' => 'AluY insertion in cancer sample ' . $index,
        'abstract_text' => 'AluY insertion was measured in cancer cells in this fixture abstract. ' . str_repeat('Complete experimental detail. ', 30),
        'abstract_summary' => 'AluY insertion was measured in cancer cells in this fixture abstract. ' . str_repeat('Complete experimental detail. ', 30),
        'material_level' => 'abstract', 'abstract_status' => 'available', 'query_term' => $term, 'query_page' => 1, 'query_retstart' => 8];
}
$cacheVersion = (new ReflectionClass($cachedLiterature))->getConstant('PUBMED_CACHE_VERSION');
file_put_contents($cachePath, json_encode(['cache_version' => $cacheVersion, 'term' => $term, 'page' => 1, 'retstart' => 8, 'total_count' => 20, 'citations' => $records]));
try {
    $nativeLiterature = $cachedLiterature->run($cacheContext);
    qa_registry_assert(count($nativeLiterature['citations']) === 8, 'All eight fetched abstract-backed records are retained.');
    qa_registry_assert(count($nativeLiterature['display_details']['preview_items']) === 5, 'UI previews remain bounded to five records.');
    $recordEvidence = array_values(array_filter($nativeLiterature['evidence_items'], static fn(array $item): bool => ($item['evidence_type'] ?? '') === 'literature_record'));
    qa_registry_assert(count($recordEvidence) === 8 && ($recordEvidence[7]['citations'][0]['pmid'] ?? '') === '10000008', 'The eighth record also has real citation-backed evidence for final integrity mapping.');
    qa_registry_assert(strlen((string)$nativeLiterature['citations'][7]['abstract_text']) > 280, 'The native result preserves complete abstracts beyond the old 280-character truncation.');
    qa_registry_assert(($nativeLiterature['results']['query_coverage'][0]['retstart'] ?? -1) === 8, 'Native coverage exposes the actual queried page.');
    qa_registry_assert(PluginResultContract::validate('Literature Plugin', $nativeLiterature)['ok'], 'Paged native results preserve the plugin contract including usable partial results.');
} finally {
    unlink($cachePath);
    rmdir($cacheDirectory);
}

echo "QA tool registry, explicit Cypher and literature refinement tests passed.\n";
