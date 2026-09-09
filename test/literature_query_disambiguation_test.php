<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
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

$plugin = (new ReflectionClass(TekgAgentLiteraturePlugin::class))->newInstanceWithoutConstructor();
$buildTerms = new ReflectionMethod($plugin, 'buildPubMedTerms');

$genericTerms = $buildTerms->invoke(
    $plugin,
    'Which transposable elements have the most disease associations?',
    [
        'intent' => 'literature',
        'needs_external_literature' => true,
        'normalized_entities' => [['type' => 'TE', 'canonical_label' => 'TE']],
        'question_keywords' => [],
    ],
    [],
    [[
        'type' => 'TE',
        'canonical_label' => 'TE',
        'aliases' => ['TE', 'Transposable elements', 'Human transposons'],
    ]]
);
$genericQuery = implode(' ', $genericTerms);
assert_true(stripos($genericQuery, 'transposable element') !== false, 'generic TE queries are expanded to the biological domain');
assert_true(!preg_match('/(^|\sAND\s)"TE"($|\sAND\s)/', $genericQuery), 'generic TE queries never search the bare abbreviation');

$lineTerms = $buildTerms->invoke(
    $plugin,
    'Which papers support an association between LINE-1 and cancer?',
    [
        'intent' => 'literature',
        'asks_for_papers' => true,
        'normalized_entities' => [['type' => 'TE', 'canonical_label' => 'SHOULD_NOT_WIN']],
        'question_keywords' => [],
    ],
    [],
    [
        [
            'type' => 'TE',
            'canonical_label' => 'LINE1',
            'aliases' => [],
            'broad_aliases' => ['L1'],
        ],
        ['type' => 'Disease', 'canonical_label' => 'Cancer', 'aliases' => ['Cancer']],
    ]
);
$lineQuery = implode(' ', $lineTerms);
assert_true(stripos($lineQuery, 'LINE-1') !== false, 'specific strict LINE-1 alias is retained');
assert_true(stripos($lineQuery, 'cancer') !== false, 'resolved disease remains in the query');
assert_true(stripos($lineQuery, '"evidence"') === false, 'generic literature fallback words do not narrow a resolved entity query');
assert_true(stripos($lineQuery, 'SHOULD_NOT_WIN') === false, 'resolved entities override the planner entity snapshot');

assert_true(method_exists($plugin, 'filterPubMedCitations'), 'Literature Plugin exposes an internal deterministic relevance gate');
$filter = new ReflectionMethod($plugin, 'filterPubMedCitations');
$candidateCitations = [
    [
        'pmid' => '100',
        'title' => 'LINE-1 retrotransposition and its deregulation in cancers',
        'abstract_summary' => 'Long interspersed nuclear element 1 is frequently dysregulated in human cancer.',
    ],
    [
        'pmid' => '101',
        'title' => 'Silencing of LINE-1 retrotransposons is a selective dependency of myeloid leukemia',
        'abstract_summary' => '',
    ],
    [
        'pmid' => '200',
        'title' => 'Plasticity of Bi2Te3-family thermoelectric crystals',
        'abstract_summary' => 'A study of thermoelectric materials.',
    ],
    [
        'pmid' => '300',
        'title' => 'Fusobacterium nucleatum in colorectal carcinoma tissue',
        'abstract_summary' => 'A microbiome association study of colorectal cancer prognosis.',
    ],
];
$filteredLine = $filter->invoke(
    $plugin,
    $candidateCitations,
    [
        [
            'type' => 'TE',
            'canonical_label' => 'LINE1',
            'aliases' => [],
            'broad_aliases' => ['L1'],
        ],
        ['type' => 'Disease', 'canonical_label' => 'Cancer', 'aliases' => ['Cancer', 'Cancers']],
    ]
);
assert_same(['100', '101'], array_column($filteredLine['retained'], 'pmid'), 'LINE-1 cancer gate recognizes identifier formatting and cancer-family terminology');
assert_same(2, count($filteredLine['excluded']), 'weakly related and abbreviation-confused records are excluded');

$filteredGeneric = $filter->invoke(
    $plugin,
    [
        [
            'pmid' => '400',
            'title' => 'TE Density: a tool to investigate the biology of transposable elements',
            'abstract_summary' => '',
        ],
        $candidateCitations[2],
    ],
    [[
        'type' => 'TE',
        'canonical_label' => 'TE',
        'aliases' => ['TE', 'Transposable elements'],
    ]]
);
assert_same(['400'], array_column($filteredGeneric['retained'], 'pmid'), 'generic TE gate keeps biological transposable-element records only');

$filteredAlu = $filter->invoke(
    $plugin,
    [
        ['pmid' => '500', 'title' => 'The value of screening in cancer care', 'abstract_summary' => ''],
        ['pmid' => '501', 'title' => 'Alu elements and cancer genome instability', 'abstract_summary' => ''],
    ],
    [
        ['type' => 'TE', 'canonical_label' => 'Alu', 'aliases' => ['Alu']],
        ['type' => 'Disease', 'canonical_label' => 'Cancer', 'aliases' => ['Cancer']],
    ]
);
assert_same(['501'], array_column($filteredAlu['retained'], 'pmid'), 'short TE aliases match complete words rather than substrings such as value');

echo "Literature query disambiguation tests passed.\n";
