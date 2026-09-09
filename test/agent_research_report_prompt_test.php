<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/orchestrator/LlmClient.php';

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

$client = new TekgAgentLlmClient([]);
$reflection = new ReflectionClass($client);

assert_true($reflection->hasMethod('writeEvidenceWalkDraft'), 'writer public method exists');
assert_true($reflection->getMethod('writeEvidenceWalkDraft')->isPublic(), 'writer method is public');
assert_true($reflection->hasMethod('polishEvidenceWalkAnswer'), 'polisher public method exists');
assert_true($reflection->getMethod('polishEvidenceWalkAnswer')->isPublic(), 'polisher method is public');

$question = 'How does LINE-1 promote cancer?';
$analysis = ['intent' => 'mechanism', 'task_complexity' => 'complex'];
$evidencePackage = [
    'schema_version' => 'evidence_package.v1',
    'claims' => [
        [
            'id' => 'claim_1',
            'text' => 'LINE-1 activity is associated with cancer.',
            'citation_ids' => ['citation_1'],
        ],
    ],
    'evidence_items' => [
        ['id' => 'evidence_1', 'claim_id' => 'claim_1', 'text' => 'Evidence summary.'],
    ],
    'citation_map' => [
        ['id' => 'citation_1', 'citation' => ['pmid' => '12345', 'url' => 'https://pubmed.ncbi.nlm.nih.gov/12345/']],
    ],
    'plugin_results' => ['must_not_reach_prompt' => true],
    'raw_result' => ['must_not_reach_prompt' => true],
    'display_details' => ['must_not_reach_prompt' => true],
];
$evidenceWalk = [
    'schema_version' => 'evidence_walk.v1',
    'walk_steps' => [
        ['id' => 'walk_step_1', 'claim_node_id' => 'claim_node_1', 'evidence_refs' => ['evidence_1'], 'citation_refs' => ['citation_1']],
    ],
    'gaps' => [['id' => 'gap_1', 'type' => 'missing_direct_mechanism']],
];
$claimEvidenceMap = [
    'schema_version' => 'claim_evidence_map.v1',
    'unsupported_claims' => ['Do not claim causality without evidence'],
    'limitations' => ['Expression evidence is missing'],
    'evidence_links' => [['claim_id' => 'claim_1', 'evidence_ids' => ['evidence_1']]],
];
$writingDecision = [
    'schema_version' => 'writing_decision.v1',
    'forbidden_claims' => ['Do not claim causality without evidence'],
    'citation_requirements' => ['Every major claim needs a linked evidence item'],
    'final_checks' => ['Apply forbidden_claims before final answer'],
];
$reportPlan = [
    'schema_version' => 'report_plan.v1',
    'report_type' => 'mechanism_review',
    'sections' => [
        ['key' => 'mechanism_chain', 'title' => 'Mechanism chain'],
        ['key' => 'limitations', 'title' => 'Limitations'],
    ],
    'claim_sequence' => [['claim_node_id' => 'claim_node_1']],
];
$limits = ['max_words' => 900];
$integrityReport = [
    'ok' => false,
    'errors' => ['Unsupported strong conclusion without evidence.'],
];

$writerPromptMethod = $reflection->getMethod('buildEvidenceWalkDraftPrompt');
$writerPrompt = $writerPromptMethod->invoke(
    $client,
    $question,
    $analysis,
    $evidencePackage,
    $evidenceWalk,
    $claimEvidenceMap,
    $writingDecision,
    $reportPlan,
    'medium',
    $limits
);

assert_contains('"writing_context"', $writerPrompt, 'writer prompt includes user-facing writing context');
assert_contains('"writing_guidance"', $writerPrompt, 'writer prompt includes sanitized writing guidance');
assert_contains('evidence first', $writerPrompt, 'writer prompt uses evidence-grounded drafting policy');
assert_contains('Build the argument before prose', $writerPrompt, 'writer prompt requires argument before prose');
assert_contains('supplied facts and real references', $writerPrompt, 'writer prompt verifies claims without exposing the internal map');
assert_contains('bounded claims', $writerPrompt, 'writer prompt requires bounded claims');
assert_contains('missing inputs and evidence gaps', $writerPrompt, 'writer prompt requires gap handling');
assert_contains('Do not add evidence', $writerPrompt, 'writer prompt forbids new evidence');
assert_contains('forbidden_claims', $writerPrompt, 'writer prompt exposes forbidden_claims');
assert_contains('citation_requirements', $writerPrompt, 'writer prompt exposes citation_requirements');
assert_contains('final_checks', $writerPrompt, 'writer prompt exposes final_checks');
assert_contains('Do not claim causality without evidence', $writerPrompt, 'writer prompt includes forbidden claim sentinel');

foreach (['raw_result', 'display_details', 'full plugin_results', '"plugin_results"', '"evidence_package"', '"evidence_walk"', '"claim_evidence_map"', '"report_plan"'] as $forbidden) {
    assert_not_contains($forbidden, $writerPrompt, "writer prompt excludes {$forbidden}");
}

$polisherPromptMethod = $reflection->getMethod('buildEvidenceWalkPolishPrompt');
$polisherPrompt = $polisherPromptMethod->invoke(
    $client,
    'Draft answer with PMID 12345 and [paper](https://pubmed.ncbi.nlm.nih.gov/12345/).',
    $analysis,
    $evidencePackage,
    $evidenceWalk,
    $claimEvidenceMap,
    $writingDecision,
    $reportPlan,
    $integrityReport
);

assert_contains('"writing_context"', $polisherPrompt, 'polisher prompt includes user-facing writing context');
assert_contains('"writing_guidance"', $polisherPrompt, 'polisher prompt includes sanitized writing guidance');
assert_contains('no new claims', $polisherPrompt, 'polisher prompt forbids new claims');
assert_contains('no new PMID', $polisherPrompt, 'polisher prompt forbids new PMID');
assert_contains('no new URLs', $polisherPrompt, 'polisher prompt forbids new URLs');
assert_contains('no new citations', $polisherPrompt, 'polisher prompt forbids new citations');
assert_contains('Preserve links and citations', $polisherPrompt, 'polisher prompt preserves links and citations');
assert_contains('downgrade unsupported claims', $polisherPrompt, 'polisher prompt downgrades unsupported claims');
assert_contains('Return only the final polished report text', $polisherPrompt, 'polisher prompt returns answer text only');
assert_contains('forbidden_claims', $polisherPrompt, 'polisher prompt exposes forbidden_claims');
assert_contains('citation_requirements', $polisherPrompt, 'polisher prompt exposes citation_requirements');
assert_contains('final_checks', $polisherPrompt, 'polisher prompt exposes final_checks');
assert_contains('Do not claim causality without evidence', $polisherPrompt, 'polisher prompt includes forbidden claim sentinel');
assert_not_contains('revision notes if possible', $polisherPrompt, 'polisher prompt does not ask for revision notes in final answer');

foreach (['raw_result', 'display_details', 'full plugin_results', '"plugin_results"', '"evidence_package"', '"evidence_walk"', '"claim_evidence_map"', '"report_plan"'] as $forbidden) {
    assert_not_contains($forbidden, $polisherPrompt, "polisher prompt excludes {$forbidden}");
}

echo "Agent research report prompt tests passed.\n";
