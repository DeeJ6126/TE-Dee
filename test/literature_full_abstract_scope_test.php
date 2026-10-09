<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/../api/agent/contracts/PluginResultContract.php';
tekg_agent_require_orchestrator_dependencies();
tekg_agent_require_plugin_files();

function literature_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function literature_private(object $plugin, string $method, array $arguments = []): mixed
{
    return (new ReflectionMethod($plugin, $method))->invokeArgs($plugin, $arguments);
}

$plugin = (new ReflectionClass(TekgAgentLiteraturePlugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'config'))->setValue($plugin, ['pubmed_cache_dir' => 'cache-fixture']);
$url = literature_private($plugin, 'pubmedAbstractUrl', [['12345'], ['db' => 'pubmed', 'retmode' => 'json', 'tool' => 'test']]);
parse_str((string)parse_url($url, PHP_URL_QUERY), $parameters);
literature_assert(($parameters['retmode'] ?? '') === 'xml', 'EFetch must override the JSON shared parameter with XML.');
literature_assert(($parameters['id'] ?? '') === '12345', 'EFetch must retain the requested PMID.');

$longText = str_repeat('The experiment measured retrotransposition. ', 12) . 'L1HS contributes to human cancer.';
$xml = '<PubmedArticleSet><PubmedArticle><MedlineCitation><PMID>12345</PMID><Article><Abstract>'
    . '<AbstractText Label="METHODS">We tested <i>LINE-1</i> and &amp; controls.</AbstractText>'
    . '<AbstractText Label="RESULTS">' . $longText . '</AbstractText>'
    . '</Abstract></Article></MedlineCitation></PubmedArticle></PubmedArticleSet>';
$abstracts = literature_private($plugin, 'parsePubMedAbstracts', [$xml]);
literature_assert(str_contains($abstracts['12345'], 'METHODS: We tested LINE-1 and & controls.'), 'Structured abstracts must retain labels and nested XML text.');
literature_assert(str_ends_with($abstracts['12345'], 'L1HS contributes to human cancer.'), 'The complete abstract must retain the findings after character 280.');
literature_assert(strlen($abstracts['12345']) > 500, 'The retrieval path must not truncate abstracts into preview snippets.');
$invalidXmlRejected = false;
try {
    literature_private($plugin, 'parsePubMedAbstracts', ['not XML']);
} catch (RuntimeException) {
    $invalidXmlRejected = true;
}
literature_assert($invalidXmlRejected, 'Invalid abstract responses must remain a retrieval error instead of silently caching empty abstracts.');
$cachePath = literature_private($plugin, 'pubmedCachePath', ['L1HS cancer', 8]);
literature_assert(!str_ends_with($cachePath, '/' . md5('L1HS cancer') . '.json'), 'Old empty-abstract query caches must be isolated.');
literature_assert($cachePath !== literature_private($plugin, 'pubmedCachePath', ['L1HS cancer', 4]), 'Query limits must be part of the cache key.');

$entities = [
    ['type' => 'TE', 'canonical_label' => 'L1HS', 'aliases' => ['L1HS']],
    ['type' => 'TE', 'canonical_label' => 'AluY', 'aliases' => ['AluY']],
    ['type' => 'TE', 'canonical_label' => 'HERVK', 'aliases' => ['HERVK']],
    ['type' => 'Disease', 'canonical_label' => 'Cancer', 'aliases' => ['Cancer']],
];
$queries = literature_private($plugin, 'buildPubMedQueries', ['Compare L1HS, AluY and HERVK cancer evidence.', ['intent' => 'comparison', 'compare_mode' => true], [], $entities]);
literature_assert(count($queries) === 3, 'A three-TE comparison must retrieve all three TE scopes.');
literature_assert(array_column(array_column($queries, 'entity_scope'), 0) === ['L1HS', 'AluY', 'HERVK'], 'Each query must record its own TE rather than the first entity only.');
foreach ($queries as $query) {
    literature_assert(!str_contains($query['query_term'], '"comparison"'), 'Comparative questions must not require a literal comparison keyword in PubMed.');
}
$candidate = ['pmid' => '12345', 'title' => 'Experimental observations', 'abstract_text' => $abstracts['12345'], 'abstract_summary' => 'A short preview without scope terms.'];
$filtered = literature_private($plugin, 'filterPubMedCitations', [[$candidate], $queries[0]['entities']]);
literature_assert(count($filtered['retained']) === 1, 'Entity filtering must inspect the full abstract, including late findings.');
$wrongScope = literature_private($plugin, 'filterPubMedCitations', [[$candidate], $queries[1]['entities']]);
literature_assert($wrongScope['retained'] === [], 'A L1HS paper must not satisfy the AluY query scope.');

$llm = new TekgAgentLlmClient(['agent_test_mode' => true, 'agent_json_fixtures' => ['literature_reading' => [
    'claim_clusters' => [['claim' => 'Invented title-only mechanism.', 'summary' => 'Unsupported.', 'citations' => ['99999']]],
    'supported_claims' => ['Invented title-only mechanism.'], 'conflicting_claims' => [], 'missing_evidence' => [],
]]]);
$reading = new TekgAgentLiteratureReadingPlugin($llm, ['deepseek_model' => 'deepseek-flash']);
$manyCitations = [];
foreach (['L1HS', 'AluY', 'HERVK'] as $scopeIndex => $scope) {
    for ($index = 0; $index < 8; $index++) {
        $manyCitations[] = ['pmid' => (string)(10000 + $scopeIndex * 100 + $index), 'title' => $scope . ' study', 'entity_scopes' => [$scope, 'Cancer'], 'abstract_text' => $longText];
    }
}
$selected = literature_private($reading, 'selectCitations', [$manyCitations, 12]);
$scopeCounts = array_count_values(array_map(static fn(array $citation): string => $citation['entity_scopes'][0], $selected));
literature_assert($scopeCounts === ['L1HS' => 4, 'AluY' => 4, 'HERVK' => 4], 'The reading budget must sample all TE scopes evenly.');
$balanced = literature_private($plugin, 'balanceCitationScopes', [$manyCitations]);
literature_assert(array_map(static fn(array $citation): string => $citation['entity_scopes'][0], array_slice($balanced, 0, 3)) === ['L1HS', 'AluY', 'HERVK'], 'Bounded downstream citation previews must preserve all requested TE scopes.');
literature_assert(count($balanced) === count($manyCitations), 'Scope balancing must retain every citation.');
$titleOnly = [['pmid' => '99999', 'title' => 'A mechanistic title with no abstract']];
$context = ['question' => 'Explain the mechanism.', 'analysis' => ['intent' => 'mechanism'], 'plugin_results' => ['Literature Plugin' => ['citations' => $titleOnly]]];
$result = $reading->run($context);
literature_assert($result['results']['generation_mode'] === 'metadata_fallback', 'Title-only records must not create supported scientific claims even with an LLM fixture.');
literature_assert($result['results']['supported_claims'] === [], 'Title-only metadata must preserve an empty supported-claim set.');
literature_assert(PluginResultContract::validate('Literature Reading Plugin', $result)['ok'], 'Metadata-only reading must satisfy the native contract.');

$invalidSynthesis = ['claim_clusters' => [
    ['claim' => 'A title-only mechanism.', 'citations' => ['99999']],
    ['claim' => 'An unknown reference.', 'citations' => ['00000']],
    ['claim' => 'Supplied abstract observation.', 'citations' => ['12345']],
], 'supported_claims' => ['A title-only mechanism.', 'An unknown reference.', 'Supplied abstract observation.', 'An unclustered claim.'], 'conflicting_claims' => [], 'missing_evidence' => []];
$checked = literature_private($reading, 'validateSynthesisCitations', [$invalidSynthesis, [$candidate, $titleOnly[0]]]);
literature_assert($checked['supported_claims'] === ['Supplied abstract observation.'], 'Scientific synthesis must reject unknown, title-only, and unclustered citations.');
literature_assert(count($checked['claim_clusters']) === 1, 'Only abstract-backed clusters enter evidence synthesis.');
literature_assert($checked['citation_validation_errors'] !== [], 'Unknown and title-only claim clusters must keep a visible partial-result warning.');

$paraphrasedSynthesis = ['claim_clusters' => [
    ['claim' => 'Supplied abstract observation.', 'citations' => ['12345']],
], 'supported_claims' => ['A synonymous restatement of the abstract finding.', 'An unclustered claim.'], 'conflicting_claims' => [], 'missing_evidence' => []];
$canonical = literature_private($reading, 'validateSynthesisCitations', [$paraphrasedSynthesis, [$candidate]]);
literature_assert($canonical['supported_claims'] === ['Supplied abstract observation.'], 'Canonical supported claims must derive directly from validated clusters and ignore duplicate paraphrases.');
literature_assert($canonical['citation_validation_errors'] === [], 'Valid clusters must not degrade to partial because the duplicate supported_claims field uses different wording.');
literature_assert($canonical['missing_evidence'] === [], 'Duplicate-field wording differences must not create scientific evidence gaps.');

echo "Literature full abstract and scope tests passed.\n";
