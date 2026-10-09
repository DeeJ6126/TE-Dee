<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/orchestrator/CitationResolver.php';

function tekg_agent_lower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function assert_citation_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$resolver = new TekgAgentCitationResolver();
$fullAbstract = str_repeat('The supplied abstract reports an observational association, without proof of causality. ', 8);
$pubmed = $resolver->normalizeOne([
    'source' => 'pubmed',
    'pmid' => '123456',
    'title' => 'LINE-1 and cancer',
    'year' => '2025',
    'abstract_text' => $fullAbstract,
    'abstract_summary' => substr($fullAbstract, 0, 280),
    'material_level' => 'abstract',
    'abstract_status' => 'available',
    'entity_scopes' => ['L1HS'],
    'query_term' => 'L1HS AND cancer',
    'query_scopes' => [['query_term' => 'L1HS AND cancer', 'entity_scope' => ['L1HS']]],
]);
assert_citation_same(trim($fullAbstract), $pubmed['abstract_text'], 'Normalization retains the complete abstract beyond 280 characters.');
assert_citation_same(trim($fullAbstract), $pubmed['abstract_summary'], 'Legacy consumers also receive the supplied complete abstract rather than an older excerpt.');
assert_citation_same('abstract', $pubmed['material_level'], 'Normalization retains the supplied material level.');
assert_citation_same(['L1HS'], $pubmed['entity_scopes'], 'Normalization retains the retrieved entity scope.');
assert_citation_same(['L1HS AND cancer'], $pubmed['query_terms'], 'Legacy query_term also enters the complete query list.');

$local = $resolver->normalizeOne([
    'source' => 'local_graph',
    'pmid' => '123456',
    'title' => 'LINE-1 and cancer',
    'abstract_summary' => '',
    'material_level' => 'metadata',
    'entity_scopes' => ['AluY'],
    'query_term' => 'AluY AND cancer',
    'query_scopes' => [['query_term' => 'AluY AND cancer', 'entity_scope' => ['AluY']]],
]);
$merged = $resolver->merge([$local], [$pubmed]);
assert_citation_same(1, count($merged), 'Duplicate PMID records remain one citation.');
assert_citation_same(trim($fullAbstract), $merged[0]['abstract_text'], 'A local metadata record cannot discard a later PubMed abstract.');
assert_citation_same(trim($fullAbstract), $merged[0]['abstract_summary'], 'Legacy readers receive the chosen full abstract after merging.');
assert_citation_same('abstract', $merged[0]['material_level'], 'Material level follows the retained abstract, rather than first metadata.');
assert_citation_same('available', $merged[0]['abstract_status'], 'Abstract availability follows the retained abstract.');
assert_citation_same('pubmed', $merged[0]['source'], 'Primary source identifies the retained abstract source.');
assert_citation_same(['local_graph', 'pubmed'], $merged[0]['sources'], 'Both original retrieval sources survive.');
assert_citation_same(['AluY', 'L1HS'], $merged[0]['entity_scopes'], 'Duplicate retrieval merges the entity scopes.');
assert_citation_same(['AluY AND cancer', 'L1HS AND cancer'], $merged[0]['query_terms'], 'Duplicate retrieval merges every query term.');
assert_citation_same([$local['query_scopes'][0], $pubmed['query_scopes'][0]], $merged[0]['query_scopes'], 'Query-to-entity assignments remain distinct rather than forming new combinations.');

$reverse = $resolver->merge([$pubmed], [$local]);
assert_citation_same(trim($fullAbstract), $reverse[0]['abstract_text'], 'The retained complete abstract does not depend on input ordering.');
assert_citation_same('abstract', $reverse[0]['material_level'], 'Later metadata cannot downgrade supplied abstract material.');

$shorterPubmed = $pubmed;
$shorterPubmed['abstract_text'] = 'Shorter returned abstract.';
$shorterPubmed['abstract_summary'] = 'Shorter returned abstract.';
$shorterPubmed['entity_scopes'] = ['HERVK'];
$shorterPubmed['query_term'] = 'HERVK AND cancer';
$shorterPubmed['query_terms'] = ['HERVK AND cancer'];
$shorterPubmed['query_scopes'] = [['query_term' => 'HERVK AND cancer', 'entity_scope' => ['HERVK']]];
$three = $resolver->merge($merged, [$shorterPubmed], [$shorterPubmed]);
assert_citation_same(trim($fullAbstract), $three[0]['abstract_text'], 'A shorter duplicate abstract does not replace fuller supplied material.');
assert_citation_same(['AluY', 'L1HS', 'HERVK'], $three[0]['entity_scopes'], 'Multiple scoped retrievals are all retained and deduplicated.');
assert_citation_same(3, count($three[0]['query_scopes']), 'Repeated identical query scope does not accumulate.');

$legacy = $resolver->normalizeOne(['pmid' => '999', 'abstract_summary' => 'Legacy abstract excerpt.']);
assert_citation_same('Legacy abstract excerpt.', $legacy['abstract_summary'], 'Older abstract_summary-only callers remain supported.');
assert_citation_same('', $legacy['abstract_text'], 'An old excerpt is not promoted into a full fetched abstract.');
assert_citation_same('', $legacy['material_level'], 'A missing material level is not fabricated.');
assert_citation_same([], $legacy['entity_scopes'], 'No TE query assignment is invented.');
assert_citation_same(null, $resolver->normalizeOne(['abstract_text' => 'Unidentifiable material.']), 'Material without PMID or title is still excluded.');

$metadataOnly = $resolver->merge(
    [$resolver->normalizeOne(['pmid' => '456', 'source' => 'local_graph', 'material_level' => 'metadata'])],
    [$resolver->normalizeOne(['pmid' => '456', 'source' => 'pubmed', 'title' => 'Supplied title', 'material_level' => 'metadata'])]
);
assert_citation_same('', $metadataOnly[0]['abstract_text'], 'Metadata merging never creates absent abstract text.');
assert_citation_same('metadata', $metadataOnly[0]['material_level'], 'Metadata-only merging does not elevate evidence material.');
assert_citation_same('Supplied title', $metadataOnly[0]['title'], 'Missing bibliographic fields are filled from the matching PMID.');

$titleDuplicates = $resolver->dedupe([
    ['title' => 'Same Title', 'abstract_summary' => 'Excerpt.', 'support_strength' => 'none'],
    ['title' => 'same title', 'abstract_text' => 'Complete supplied abstract.', 'material_level' => 'abstract', 'support_strength' => 'high'],
]);
assert_citation_same(1, count($titleDuplicates), 'Title-only deduplication remains case insensitive.');
assert_citation_same('none', $titleDuplicates[0]['support_strength'], 'Merging citation material never upgrades scientific support strength.');

echo "Citation material preservation tests passed.\n";
