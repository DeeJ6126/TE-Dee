<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$contextPath = $root . '/api/agent/contracts/UserFacingWritingContext.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    assert_true(str_contains($haystack, $needle), $message . "\nMissing: {$needle}");
}

function assert_not_contains(string $needle, string $haystack, string $message): void
{
    assert_true(!str_contains($haystack, $needle), $message . "\nForbidden: {$needle}");
}

assert_true(is_file($contextPath), 'UserFacingWritingContext exists');
require_once $contextPath;

$context = UserFacingWritingContext::fromInternal(
    'Generate a research report for L1HS including sequence, genomic location, expression, disease links, and literature evidence.',
    ['intent' => 'literature', 'asks_for_sequence' => true],
    [
        'claims' => [
            [
                'id' => 'claim_1',
                'text' => 'L1HS BIO_RELATION Cancer (L1HS is associated with cancer.)',
                'source_plugin' => 'Graph Plugin',
                'citation_ids' => ['citation_1'],
            ],
            [
                'id' => 'claim_2',
                'text' => 'L1HS record metadata contains keyword-derived structure hints: LTR, LINE.',
                'source_plugin' => 'Sequence Plugin',
                'citation_ids' => ['citation_2'],
            ],
        ],
        'evidence_items' => [
            [
                'id' => 'evidence_1',
                'claim_id' => 'claim_1',
                'plugin' => 'Graph Plugin',
                'text' => 'L1HS BIO_RELATION Cancer (L1HS is associated with cancer.)',
                'support_strength' => 'medium',
                'raw' => [
                    'evidence_type' => 'graph_relation',
                    'subject' => 'L1HS',
                    'object' => 'Cancer',
                    'quality_flags' => ['association_not_causality'],
                ],
            ],
            [
                'id' => 'evidence_2',
                'claim_id' => 'claim_2',
                'plugin' => 'Sequence Plugin',
                'text' => 'L1HS record metadata contains keyword-derived structure hints: LTR, LINE.',
                'support_strength' => 'low',
                'raw' => [
                    'evidence_type' => 'structure_hint',
                    'quality_flags' => ['keyword_derived'],
                ],
            ],
        ],
        'citation_map' => [
            ['id' => 'citation_1', 'claim_id' => 'claim_1', 'citation' => ['pmid' => '12345', 'title' => 'L1HS and cancer', 'url' => 'https://pubmed.ncbi.nlm.nih.gov/12345/']],
            ['id' => 'citation_2', 'claim_id' => 'claim_2', 'citation' => ['title' => 'L1HS sequence record']],
        ],
        'limitations' => [],
    ],
    ['gaps' => []],
    [
        'limitations' => [
            "Graph claims carry association_not_causality and claim_2 is keyword_derived.",
        ],
    ],
    ['report_type' => 'research_report']
);

$json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

assert_contains('L1HS is associated with cancer.', $json, 'natural scientific claim is retained');
assert_contains('PMID 12345', $json, 'real user-facing citation is retained');
assert_contains('These links describe reported associations and do not by themselves establish causation.', $json, 'raw association flag becomes one plain-language limitation');
assert_contains('do not infer completeness, ORFs, UTRs, motifs, or structural features', $json, 'sequence writing boundary blocks unsupported feature expansion');
assert_contains('Do not infer genome-wide distribution', $json, 'genome writing boundary blocks unsupported distribution claims');
assert_contains('Aim for roughly 600-900 words.', $json, 'research context carries a gentle presentation budget');

foreach ([
    'Graph Plugin',
    'Sequence Plugin',
    'claim_1',
    'claim_2',
    'evidence_1',
    'citation_1',
    'association_not_causality',
    'keyword_derived',
    'support_strength',
    'structure hints: LTR',
] as $forbidden) {
    assert_not_contains($forbidden, $json, "writing context hides {$forbidden}");
}

$guidance = UserFacingWritingContext::writingGuidance([
    'writing_strategy' => 'Enumerate claim_1 and its medium support strength.',
    'required_sections' => ['Overview', 'Findings', 'Limitations'],
    'forbidden_claims' => [
        'Do not expose association_not_causality or citation_1.',
        'Do not claim that an association proves causation.',
    ],
    'citation_requirements' => ['Use real PMID references.'],
    'tone' => 'Clear and concise.',
    'final_checks' => ['Remove Graph Plugin names.', 'Answer every requested dimension.'],
]);
$guidanceJson = json_encode($guidance, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
assert_contains('Do not claim that an association proves causation.', $guidanceJson, 'scientific constraint remains in writing guidance');
assert_contains('Answer every requested dimension.', $guidanceJson, 'user-facing final check remains in writing guidance');
foreach (['claim_1', 'citation_1', 'association_not_causality', 'Graph Plugin', 'support strength'] as $forbidden) {
    assert_not_contains($forbidden, $guidanceJson, "writing guidance hides {$forbidden}");
}

$internalSourceGuidance = UserFacingWritingContext::writingGuidance([
    'citation_requirements' => [
        "If no publication exists, label it as 'internal expression data'.",
        'Use a real PMID when available.',
    ],
]);
$internalSourceGuidanceJson = json_encode($internalSourceGuidance, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
assert_not_contains('internal expression data', $internalSourceGuidanceJson, 'guidance cannot instruct the answer to expose an internal source label');
assert_contains('Use a real PMID when available.', $internalSourceGuidanceJson, 'valid citation guidance remains after filtering');

$badAnswerAudit = UserFacingWritingContext::auditAnswer(
    "## Evidence Inventory\nTwenty-six claims across five evidence dimensions were returned by the Graph Plugin. "
    . "The association_not_causality flag and support strength are recorded in [citation_24]. "
    . "Structure hints were keyword_derived from internal data.",
    'Generate a research report for L1HS including sequence, genomic location, expression, disease links, and literature evidence.'
);
assert_true($badAnswerAudit['ok'] === false, 'opaque internal writing fails the user-facing audit');
assert_true(count($badAnswerAudit['violations']) >= 6, 'user-facing audit reports each class of internal leakage');

$sanitizedAnswer = UserFacingWritingContext::sanitizeAnswer(
    "## Evidence Inventory\nThe Graph Plugin returned a claim-evidence map with association_not_causality and keyword_derived flags in [citation_24]."
);
assert_not_contains('Evidence Inventory', $sanitizedAnswer, 'sanitizer removes an internal audit heading');
assert_not_contains('Graph Plugin', $sanitizedAnswer, 'sanitizer replaces registered plugin names');
assert_not_contains('claim-evidence map', $sanitizedAnswer, 'sanitizer replaces internal mapping language');
assert_not_contains('association_not_causality', $sanitizedAnswer, 'sanitizer translates the association flag');
assert_not_contains('keyword_derived', $sanitizedAnswer, 'sanitizer translates the keyword flag');
assert_not_contains('citation_24', $sanitizedAnswer, 'sanitizer converts internal citation IDs');
assert_true(UserFacingWritingContext::auditAnswer($sanitizedAnswer)['ok'] === true, 'sanitized prose passes the user-facing audit');

$goodAnswerAudit = UserFacingWritingContext::auditAnswer(
    "## Disease links\nL1HS activity has been reported in several cancers, but these observations do not by themselves establish causation (PMID 22968929).",
    'Generate a research report for L1HS including disease links and literature evidence.'
);
assert_true($goodAnswerAudit['ok'] === true, 'plain-language scientific caution passes the user-facing audit');

$badChineseAudit = UserFacingWritingContext::auditAnswer(
    "## 证据清单\n图谱插件返回五个证据维度，支持强度为低；这是内部数据。",
    '请生成 L1HS 研究报告。'
);
assert_true($badChineseAudit['ok'] === false, 'Chinese internal audit language also fails the user-facing audit');

$internalDatabaseAudit = UserFacingWritingContext::auditAnswer(
    'These mapping data come from an internal database.',
    'Where is L1HS located?'
);
assert_true($internalDatabaseAudit['ok'] === false, 'internal database is not a user-facing source label');

$unsupportedStructureAudit = UserFacingWritingContext::auditAnswer(
    'The 6,064 bp record is a full-length element with ORF1, ORF2, and 5-prime and 3-prime UTRs.',
    'Report the L1HS sequence.',
    $context
);
assert_true($unsupportedStructureAudit['ok'] === false, 'sequence details absent from projected facts fail the user-facing audit');

$researchGuidance = UserFacingWritingContext::writingGuidance([
    'required_sections' => ['Sequence', 'Genomic Location', 'Expression', 'Disease Links', 'Literature Evidence'],
], ['report_type' => 'research_report']);
assert_true(
    $researchGuidance['required_sections'] === ['Sequence', 'Genomic Location', 'Expression', 'Disease Links', 'References'],
    'research reports use references instead of a duplicate literature-summary section'
);

$citationCleanupContext = UserFacingWritingContext::fromEvidenceItems(
    'Summarize the sequence evidence.',
    [],
    [
        ['title' => 'sequences.";'],
        ['title' => '"Human L1HS complete L1 consensus.";', 'authors' => 'Repbase curators'],
    ]
);
$citationCleanupJson = json_encode($citationCleanupContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
assert_not_contains('sequences.', $citationCleanupJson, 'malformed one-word citation fragments are omitted');
assert_contains('Human L1HS complete L1 consensus.', $citationCleanupJson, 'usable citation titles are cleaned and retained');
assert_contains('Repbase curators', $citationCleanupJson, 'available citation authors are retained rather than inviting invention');

echo "User-facing writing context tests passed.\n";
