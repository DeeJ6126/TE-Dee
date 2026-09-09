<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$gatePath = $root . '/api/agent/contracts/ReportIntegrityGate.php';

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

function assert_contains_string(string $needle, array $haystack, string $message): void
{
    foreach ($haystack as $item) {
        if (is_string($item) && str_contains($item, $needle)) {
            return;
        }
    }
    fwrite(STDERR, "Assertion failed: {$message}\nNeedle: {$needle}\nHaystack: " . var_export($haystack, true) . "\n");
    exit(1);
}

assert_true(is_file($gatePath), 'ReportIntegrityGate.php should exist');
require_once $gatePath;
assert_true(class_exists('ReportIntegrityGate'), 'ReportIntegrityGate class should be loadable');
assert_true(method_exists('ReportIntegrityGate', 'normalizeUrlsInText'), 'ReportIntegrityGate exposes normalizeUrlsInText');

$package = [
    'claims' => [
        [
            'id' => 'claim_1',
            'text' => 'LINE-1 activation is associated with cancer.',
            'citation_ids' => ['citation_1'],
            'route_ids' => ['route_1'],
        ],
    ],
    'citation_map' => [
        [
            'id' => 'citation_1',
            'citation' => [
                'pmid' => '12345',
                'url' => 'https://pubmed.ncbi.nlm.nih.gov/12345/',
            ],
        ],
    ],
    'route_map' => [
        [
            'id' => 'route_1',
            'route' => [
                'url' => 'http://localhost/TE-/search.php?q=L1HS&type=TE#search-literature-panel',
                'href' => '/TE-/search.php?q=L1HS&type=TE#search-literature-panel',
            ],
        ],
        [
            'id' => 'route_2',
            'route' => [
                'url' => 'http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-karyotype-panel',
                'href' => '/TE-/search.php?q=L1HS&type=TE#search-karyotype-panel',
            ],
        ],
        [
            'id' => 'route_3',
            'route' => [
                'url' => 'http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-sequence-panel',
                'href' => '/TE-/search.php?q=L1HS&type=TE#search-sequence-panel',
            ],
        ],
    ],
];

$valid = ReportIntegrityGate::check(
    "## Evidence Review\nSupported report cites PMID 12345 and [Literature](https://pubmed.ncbi.nlm.nih.gov/12345/) with citation_id: citation_1 and route_id: route_1.\nLINE-1 activation is associated with cancer.",
    $package,
    [
        'claim_nodes' => [
            ['id' => 'claim_node_1', 'claim_id' => 'claim_1', 'text' => 'LINE-1 activation is associated with cancer.'],
        ],
    ],
    [
        'sections' => [
            ['key' => 'evidence_review', 'title' => 'Evidence Review'],
        ],
    ]
);
assert_same(true, $valid['ok'], 'valid report passes');
assert_same([], $valid['errors'], 'valid report has no errors');
assert_same(['12345'], $valid['cited_pmids'], 'valid report returns cited PMIDs');
assert_same(['https://pubmed.ncbi.nlm.nih.gov/12345/'], $valid['linked_urls'], 'valid report returns linked URLs');
assert_same([], $valid['unsupported_markers'], 'valid report has no unsupported markers');

$longReportPackage = [
    'claims' => $package['claims'],
    'citation_map' => [
        ['id' => 'citation_24', 'citation' => ['pmid' => '22968929', 'url' => 'https://pubmed.ncbi.nlm.nih.gov/22968929/']],
        ['id' => 'citation_25', 'citation' => ['pmid' => '38759652', 'url' => 'https://pubmed.ncbi.nlm.nih.gov/38759652/']],
        ['id' => 'citation_26', 'citation' => ['pmid' => '37165451', 'url' => 'https://pubmed.ncbi.nlm.nih.gov/37165451/']],
    ],
    'route_map' => [],
];
$longMarkdownReport = <<<'REPORT'
## Literature evidence

Somatic LINE-1 activity has been studied in colorectal tumors [PMID 22968929](https://pubmed.ncbi.nlm.nih.gov/22968929/).\n\nEpigenetic observations are discussed separately in a second record [PMID 38759652](https://pubmed.ncbi.nlm.nih.gov/38759652/).\n\nBeyond these examples, a third record provides additional context [PMID 37165451](https://pubmed.ncbi.nlm.nih.gov/37165451/).\n\nOverall, these citations must remain individually traceable in a long report.
REPORT;
$longMarkdownLinks = ReportIntegrityGate::check($longMarkdownReport, $longReportPackage);
assert_same(true, $longMarkdownLinks['ok'], 'long report with Markdown PubMed links and escaped paragraph breaks passes');
assert_same([], $longMarkdownLinks['errors'], 'Markdown PubMed links do not create duplicate malformed bare URLs');
assert_same(
    [
        'https://pubmed.ncbi.nlm.nih.gov/22968929/',
        'https://pubmed.ncbi.nlm.nih.gov/38759652/',
        'https://pubmed.ncbi.nlm.nih.gov/37165451/',
    ],
    $longMarkdownLinks['linked_urls'],
    'long report extracts each Markdown PubMed destination exactly once'
);

$a34Package = [
    'claims' => $package['claims'],
    'citation_map' => [
        ['id' => 'citation_a34', 'citation' => ['pmid' => '41681929', 'url' => 'https://pubmed.ncbi.nlm.nih.gov/41681929/']],
    ],
    'route_map' => [],
];
$a34Mismatch = ReportIntegrityGate::check(
    'LSQCC had the highest total L1HS transcript levels [PMID:4181929](https://pubmed.ncbi.nlm.nih.gov/41681929/).',
    $a34Package
);
assert_same(false, $a34Mismatch['ok'], 'displayed PMID must match the PMID encoded by its PubMed Markdown URL');
assert_contains_string(
    'Displayed PMID 4181929 does not match PubMed URL PMID 41681929',
    $a34Mismatch['errors'],
    'A34-style PMID and PubMed URL mismatch is reported explicitly'
);
assert_same(['4181929'], $a34Mismatch['cited_pmids'], 'colon-form PMID markers participate in integrity validation');

$a34Normalized = ReportIntegrityGate::normalizeUrlsInText(
    'LSQCC had the highest total L1HS transcript levels [PMID:4181929](https://pubmed.ncbi.nlm.nih.gov/41681929/).'
);
assert_same(
    'LSQCC had the highest total L1HS transcript levels [PMID:41681929](https://pubmed.ncbi.nlm.nih.gov/41681929/).',
    $a34Normalized,
    'normalization aligns a displayed PMID with its validated PubMed destination'
);
assert_same(
    true,
    ReportIntegrityGate::check($a34Normalized, $a34Package)['ok'],
    'deterministically corrected PMID link passes integrity validation'
);

$a34Corrected = ReportIntegrityGate::check(
    'LSQCC had the highest total L1HS transcript levels [PMID:41681929](https://pubmed.ncbi.nlm.nih.gov/41681929/).',
    $a34Package
);
assert_same(true, $a34Corrected['ok'], 'matching colon-form PMID and PubMed URL passes');

$missingPlannedSection = ReportIntegrityGate::check('LINE-1 activation is associated with cancer. PMID 12345', $package, [], [
    'sections' => [
        ['key' => 'limitations', 'title' => 'Limitations'],
    ],
]);
assert_same(true, $missingPlannedSection['ok'], 'missing planned section warns but does not fail');
assert_same([], $missingPlannedSection['errors'], 'missing planned section is not a hard error');
assert_contains_string('planned section', $missingPlannedSection['warnings'], 'missing planned section warning is reported');

$translatedSectionTitle = ReportIntegrityGate::check(
    '## 证据综述' . "\n" . 'LINE-1 activation is associated with cancer. PMID 12345',
    $package,
    [],
    [
        'sections' => [
            ['key' => 'evidence_review', 'title' => 'Evidence Review'],
        ],
    ]
);
assert_same(true, $translatedSectionTitle['ok'], 'translated section titles do not block Chinese reports');
assert_contains_string('planned section', $translatedSectionTitle['warnings'], 'translated section title warning is reported');

$missingWalkClaim = ReportIntegrityGate::check('## Evidence Review' . "\n" . 'PMID 12345 only.', $package, [
    'claim_nodes' => [
        ['id' => 'claim_node_1', 'claim_id' => 'claim_1', 'text' => 'LINE-1 activation is associated with cancer.'],
    ],
], []);
assert_same(true, $missingWalkClaim['ok'], 'missing walk claim is warning, not blocking');
assert_contains_string('walk claim', $missingWalkClaim['warnings'], 'missing walk claim warning is reported');

$badPmid = ReportIntegrityGate::check('Unsupported PMID 99999 is cited.', $package);
assert_same(false, $badPmid['ok'], 'unknown PMID fails');
assert_contains_string('PMID 99999', $badPmid['errors'], 'unknown PMID error is reported');

$badUrl = ReportIntegrityGate::check('Unsupported URL https://example.org/missing is linked.', $package);
assert_same(false, $badUrl['ok'], 'unknown URL fails');
assert_contains_string('https://example.org/missing', $badUrl['errors'], 'unknown URL error is reported');

$routeUrlWithTrailingBacktick = ReportIntegrityGate::check(
    'Open route_id: route_2 at http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-karyotype-panel` for karyotype context.',
    $package
);
assert_same(true, $routeUrlWithTrailingBacktick['ok'], 'route_map URL with trailing Markdown backtick passes');
assert_same(
    ['http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-karyotype-panel'],
    $routeUrlWithTrailingBacktick['linked_urls'],
    'route_map URL with trailing Markdown backtick is normalized'
);

$routeUrlWithTrailingBold = ReportIntegrityGate::check(
    'Open route_id: route_2 at http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-karyotype-panel** for karyotype context.',
    $package
);
assert_same(true, $routeUrlWithTrailingBold['ok'], 'route_map URL with trailing Markdown bold punctuation passes');
assert_same(
    ['http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-karyotype-panel'],
    $routeUrlWithTrailingBold['linked_urls'],
    'route_map URL with trailing Markdown bold punctuation is normalized'
);

$routeUrlInMarkdown = ReportIntegrityGate::check(
    'Open [karyotype panel](http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-karyotype-panel) or malformed http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-karyotype-panel](http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-karyotype-panel).',
    $package
);
assert_same(true, $routeUrlInMarkdown['ok'], 'route_map URL inside Markdown and malformed Markdown fragment passes');
assert_same(
    ['http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-karyotype-panel'],
    $routeUrlInMarkdown['linked_urls'],
    'route_map URL inside Markdown and malformed Markdown fragment is recognized once'
);

$routeUrlWithoutFragment = ReportIntegrityGate::check(
    'Open route_id: route_2 at http://127.0.0.1/TE-/search.php?q=L1HS&type=TE for the L1HS search page.',
    $package
);
assert_same(true, $routeUrlWithoutFragment['ok'], 'route_map URL without fragment passes when evidence has the same route plus fragment');
assert_same(
    ['http://127.0.0.1/TE-/search.php?q=L1HS&type=TE'],
    $routeUrlWithoutFragment['linked_urls'],
    'route_map URL without fragment is preserved in extracted linked URLs'
);

$nonBreakingHyphen = html_entity_decode('&#x2011;', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$routeUrlWithUnicodeHyphens = ReportIntegrityGate::check(
    'Open route_id: route_3 at http://127.0.0.1/TE' . $nonBreakingHyphen . '/search.php?q=L1HS&type=TE#search' . $nonBreakingHyphen . 'sequence' . $nonBreakingHyphen . 'panel for sequence context.',
    $package
);
assert_same(true, $routeUrlWithUnicodeHyphens['ok'], 'route_map URL with Unicode non-breaking hyphens passes');
assert_same(
    ['http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-sequence-panel'],
    $routeUrlWithUnicodeHyphens['linked_urls'],
    'route_map URL with Unicode non-breaking hyphens is normalized'
);

$rightDoubleQuote = html_entity_decode('&#x201D;', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$routeUrlWithTrailingMarkdownAndUnicodeQuote = ReportIntegrityGate::check(
    'Open route_id: route_3 at http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-sequence-panel).' . $rightDoubleQuote,
    $package
);
assert_same(true, $routeUrlWithTrailingMarkdownAndUnicodeQuote['ok'], 'route_map URL with trailing Markdown punctuation and Unicode quote passes');
assert_same(
    ['http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-sequence-panel'],
    $routeUrlWithTrailingMarkdownAndUnicodeQuote['linked_urls'],
    'route_map URL with trailing Markdown punctuation and Unicode quote is normalized'
);

$emDash = html_entity_decode('&#x2014;', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$unicodeUrlText = 'Natural language A' . $emDash . 'B stays untouched. '
    . 'Open http://127.0.0.1/TE' . $emDash . '/search.php?q=L1HS&type=TE#search' . $emDash . 'sequence' . $emDash . 'panel '
    . 'or [Sequence](http://127.0.0.1/TE' . $nonBreakingHyphen . '/search.php?q=L1HS&type=TE#search' . $nonBreakingHyphen . 'sequence' . $nonBreakingHyphen . 'panel).';
$normalizedUrlText = ReportIntegrityGate::normalizeUrlsInText($unicodeUrlText);
assert_same(
    'Natural language A' . $emDash . 'B stays untouched. '
        . 'Open http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-sequence-panel '
        . 'or [Sequence](http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-sequence-panel).',
    $normalizedUrlText,
    'normalizeUrlsInText normalizes Unicode dashes inside URLs only'
);

$unrelatedUrlWithMarkdownPunctuation = ReportIntegrityGate::check(
    'Unsupported URL https://example.org/missing** is linked.',
    $package
);
assert_same(false, $unrelatedUrlWithMarkdownPunctuation['ok'], 'unknown URL with benign Markdown punctuation still fails');
assert_contains_string('https://example.org/missing', $unrelatedUrlWithMarkdownPunctuation['errors'], 'unknown normalized URL error is reported');

$emptyClaimsPackage = $package;
$emptyClaimsPackage['claims'] = [];
$strongConclusion = ReportIntegrityGate::check('This report demonstrates a causal relationship.', $emptyClaimsPackage);
assert_same(false, $strongConclusion['ok'], 'strong conclusion without claims fails');
assert_contains_string('strong conclusion', $strongConclusion['errors'], 'strong conclusion error is reported');

$badMarkers = ReportIntegrityGate::check('Uses citation_id: citation_404 and route_id: route_404.', $package);
assert_same(false, $badMarkers['ok'], 'unknown citation_id and route_id markers fail');
assert_same(['citation_id: citation_404', 'route_id: route_404'], $badMarkers['unsupported_markers'], 'unsupported markers are returned');

echo "report_integrity_gate_test passed\n";
