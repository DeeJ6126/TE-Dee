<?php
declare(strict_types=1);

// Load the standalone contract path first: it must not depend on the runtime
// bootstrap, credentials, registry setup, or the resolver having been loaded.
require_once __DIR__ . '/../api/agent/contracts/EvidencePackage.php';
require_once __DIR__ . '/../api/agent/plugins/CitationResolverPlugin.php';

function tekg_agent_lower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function assert_chain_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$local = [
    'pmid' => '123456',
    'source' => 'local_graph',
    'title' => 'Supplied bibliographic record',
    'abstract_text' => '',
    'abstract_summary' => '',
    'material_level' => 'metadata',
    'entity_scopes' => ['AluY'],
    'query_term' => 'AluY AND cancer',
    'query_scopes' => [['query_term' => 'AluY AND cancer', 'entity_scope' => ['AluY']]],
];
$abstract = str_repeat('This complete fixture abstract states an association, with no established causality. ', 10);
$pubmed = [
    ...$local,
    'source' => 'pubmed',
    'abstract_text' => trim($abstract),
    'abstract_summary' => trim($abstract),
    'material_level' => 'abstract',
    'abstract_status' => 'available',
    'entity_scopes' => ['L1HS'],
    'query_term' => 'L1HS AND cancer',
    'query_scopes' => [['query_term' => 'L1HS AND cancer', 'entity_scope' => ['L1HS']]],
];

assert_chain_same(false, class_exists(TekgAgentCitationResolver::class, false), 'Standalone evidence contracts do not require an initialized resolver.');
$helperCitations = tekg_agent_dedupe_citations([$local, $pubmed]);
assert_chain_same(true, class_exists(TekgAgentCitationResolver::class, false), 'Shared helper loads the canonical resolver when merging a duplicate.');
assert_chain_same(1, count($helperCitations), 'Shared helper preserves duplicate PMID identity.');
assert_chain_same(trim($abstract), $helperCitations[0]['abstract_text'], 'Shared helper no longer discards the later complete abstract.');
assert_chain_same(['AluY', 'L1HS'], $helperCitations[0]['entity_scopes'], 'Shared helper retains the retrieval scopes.');

$context = ['plugin_results' => [
    'Graph Plugin' => [
        'plugin_name' => 'Graph Plugin',
        'status' => 'ok',
        'citations' => [$local],
        'results' => ['citations' => [$local]],
    ],
    'Literature Plugin' => [
        'plugin_name' => 'Literature Plugin',
        'status' => 'ok',
        'citations' => [$pubmed],
        'results' => ['citations' => [$pubmed]],
        'display_details' => ['citations' => [$pubmed]],
        'evidence_items' => [['citations' => [$pubmed]]],
    ],
]];
$contextCitations = tekg_agent_context_citations($context);
assert_chain_same(trim($abstract), $contextCitations[0]['abstract_text'], 'Graph-first context aggregation preserves Literature Plugin abstract material.');
assert_chain_same(['local_graph', 'pubmed'], $contextCitations[0]['sources'], 'Context aggregation retains both sources.');
assert_chain_same(['AluY AND cancer', 'L1HS AND cancer'], $contextCitations[0]['query_terms'], 'Context aggregation retains the originating queries.');

$citationPlugin = new TekgAgentCitationResolverPlugin(new TekgAgentCitationResolver());
$result = $citationPlugin->run($context);
assert_chain_same(trim($abstract), $result['citations'][0]['abstract_text'], 'Citation Resolver Plugin retains the complete upstream abstract through actual context collection.');
assert_chain_same(trim($abstract), $result['results']['citations'][0]['abstract_summary'], 'Legacy reader material survives the actual plugin chain.');
assert_chain_same('abstract', $result['citations'][0]['material_level'], 'Citation Resolver Plugin does not downgrade the supplied material level.');

$package = EvidencePackage::fromPluginResults('Compare the supplied records.', ['intent' => 'literature'], [[
    'plugin_name' => 'Literature Plugin',
    'status' => 'ok',
    'citations' => [$local, $pubmed],
    'evidence_items' => [[
        'claim' => 'The supplied abstract states an association.',
        'support_strength' => 'medium',
        'citations' => [$local, $pubmed],
    ]],
]]);
assert_chain_same(true, EvidencePackage::validate($package)['ok'], 'Evidence package contract remains valid.');
assert_chain_same(1, count($package['citation_map']), 'Evidence citations deduplicate without adding claims.');
assert_chain_same(trim($abstract), $package['citation_map'][0]['citation']['abstract_text'], 'EvidencePackage item-level citation deduplication retains full material.');
assert_chain_same(['AluY', 'L1HS'], $package['citation_map'][0]['citation']['entity_scopes'], 'EvidencePackage retains all explicit retrieval scopes.');
assert_chain_same('medium', $package['evidence_items'][0]['support_strength'], 'Material merging does not change claim support strength.');

// Generic helper identity rules also cover records that the PMID/title resolver
// cannot identify. Preserve those existing DOI/URL/JSON contracts.
$generic = tekg_agent_dedupe_citations([
    ['doi' => '10.1234/example', 'source' => 'metadata'],
    ['doi' => '10.1234/EXAMPLE', 'source' => 'abstract_feed', 'abstract_text' => 'Supplied DOI-linked abstract.'],
    ['url' => 'https://example.org/record', 'source' => 'metadata'],
    ['url' => 'https://example.org/record', 'source' => 'abstract_feed', 'abstract_text' => 'Supplied URL-linked abstract.'],
    ['unidentified_record' => true],
    ['unidentified_record' => true],
]);
assert_chain_same(3, count($generic), 'DOI/URL/JSON helper identity rules remain stable.');
assert_chain_same('Supplied DOI-linked abstract.', $generic[0]['abstract_text'], 'DOI-only citations retain richer supplied material.');
assert_chain_same('Supplied URL-linked abstract.', $generic[1]['abstract_text'], 'URL-only citations retain richer supplied material.');
assert_chain_same(true, $generic[2]['unidentified_record'], 'Unidentified records remain preserved under existing JSON identity.');

echo "Citation evidence chain tests passed.\n";
