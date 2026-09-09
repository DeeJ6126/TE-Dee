<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/contracts/EvidencePackage.php';
require_once __DIR__ . '/../api/agent/contracts/EvidenceWalk.php';
require_once __DIR__ . '/../api/agent/contracts/ReportPlan.php';
require_once __DIR__ . '/../api/agent/orchestrator/LlmClient.php';
require_once __DIR__ . '/../api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php';

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

final class AgentEvidencePackageRuntimeHarness
{
    use TekgAcademicAgentPluginResultTrait;

    public function nodePayloads(
        string $question,
        array $analysis,
        array $planning,
        array $pluginResults,
        array $evidence,
        array $citations,
        array $collectionState,
        array $sufficiencyDecision,
        array $answerStructure,
        array $synthesizedEvidence,
        array $evidencePackage
    ): array {
        return $this->buildNodePayloads(
            $question,
            $analysis,
            $planning,
            $pluginResults,
            $evidence,
            $citations,
            $collectionState,
            $sufficiencyDecision,
            $answerStructure,
            $synthesizedEvidence,
            $evidencePackage
        );
    }
}

$question = 'What evidence links LINE-1 to cancer?';
$analysis = ['intent' => 'literature', 'complexity' => 'moderate'];
$pluginResults = [
    'Literature Plugin' => [
        'plugin_name' => 'Literature Plugin',
        'status' => 'ok',
        'result_envelope' => [
            'plugin' => 'Literature Plugin',
            'status' => 'ok',
            'legacy_status' => 'ok',
            'intent' => 'literature',
            'summary' => 'Literature supports a LINE-1 cancer association.',
            'raw' => [
                'has_raw_result' => true,
                'raw_result_type' => 'array',
                'has_display_details' => true,
            ],
            'evidence_items' => [
                ['claim' => 'LINE-1 activity is associated with cancer.', 'support_strength' => 'high'],
            ],
            'citations' => [
                ['pmid' => '12345', 'title' => 'LINE-1 and cancer'],
            ],
            'routes' => [],
            'metrics' => ['duration_ms' => 7, 'result_count' => 1, 'confidence' => 0.9],
            'errors' => [],
        ],
        'raw_result' => ['must_not_reach_writer' => true],
        'display_details' => ['must_not_reach_writer' => true],
    ],
];

$evidencePackage = EvidencePackage::fromPluginResults($question, $analysis, $pluginResults, ['summary_max_chars' => 240]);
$validation = EvidencePackage::validate($evidencePackage);
assert_same(true, $validation['ok'], 'runtime evidence_package validates');
assert_same(1, $evidencePackage['metrics']['claim_count'], 'runtime evidence_package claim count');
assert_same('citation_1', $evidencePackage['claims'][0]['citation_ids'][0], 'runtime evidence_package citation mapping');

$evidenceWalk = EvidenceWalk::fromEvidencePackage($evidencePackage, ['intent' => 'literature'], ['selected_plugins' => ['Literature Plugin']], ['status' => 'sufficient']);
$walkValidation = EvidenceWalk::validate($evidenceWalk);
assert_same(true, $walkValidation['ok'], 'runtime evidence_walk validates');

$reportPlan = ReportPlan::fromEvidenceWalk($question, ['intent' => 'literature'], $evidenceWalk, ['response_mode' => 'literature_support']);
$planValidation = ReportPlan::validate($reportPlan);
assert_same(true, $planValidation['ok'], 'runtime report_plan validates');

$client = new TekgAgentLlmClient([]);
$reflection = new ReflectionClass($client);
assert_true(!$reflection->hasMethod('writeEvidencePackageAnswer'), 'old direct evidence_package writer method is removed');
assert_true(!$reflection->hasMethod('buildEvidencePackageAnswerPrompt'), 'old direct evidence_package prompt builder is removed');

$method = $reflection->getMethod('buildEvidenceWalkDraftPrompt');
$prompt = $method->invoke(
    $client,
    $question,
    ['intent' => 'literature'],
    $evidencePackage,
    $evidenceWalk,
    [],
    [],
    $reportPlan,
    'medium',
    ['No direct experimental validation in this package.']
);

assert_true(str_contains($prompt, 'evidence-walk draft report'), 'writer prompt uses evidence-walk draft path');
assert_true(str_contains($prompt, '"writing_context"'), 'writer prompt includes user-facing writing context');
assert_true(str_contains($prompt, '"writing_guidance"'), 'writer prompt includes sanitized writing guidance');
foreach (['raw_result', 'display_details', 'full plugin_results', '"plugin_results"', '"evidence_package"', '"evidence_walk"', '"claim_evidence_map"', '"report_plan"', '"writing_decision"'] as $forbidden) {
    assert_true(!str_contains($prompt, $forbidden), "writer prompt excludes {$forbidden}");
}

$invalidValidation = EvidencePackage::validate(['schema_version' => 'evidence_package.v1']);
assert_same(false, $invalidValidation['ok'], 'missing evidence_package fields fail validation');
assert_true(in_array('claims is required', $invalidValidation['errors'], true), 'validation reports missing claims');

$harness = new AgentEvidencePackageRuntimeHarness();
$nodePayloads = $harness->nodePayloads(
    $question,
    $analysis,
    ['tool_plan' => [['plugin_name' => 'Literature Plugin']]],
    $pluginResults,
    [['claim' => 'legacy fallback claim', 'source_plugin' => 'Literature Plugin']],
    [['pmid' => '12345']],
    ['executed_plugins' => ['Literature Plugin']],
    ['is_sufficient' => true, 'reason' => 'enough'],
    ['response_mode' => 'literature_support', 'section_plan' => ['Main evidence']],
    ['supported_claims' => ['legacy fallback claim'], 'conflicting_claims' => [], 'missing_evidence' => [], 'claim_clusters' => []],
    $evidencePackage
);
$writerInput = $nodePayloads['Answer Writer Node']['input'];
assert_true(isset($writerInput['evidence_package']), 'Answer Writer Node input includes evidence_package');
assert_true(!array_key_exists('supported_claims', $writerInput), 'Answer Writer Node input excludes supported_claims');
assert_true(!array_key_exists('citation_bundle', $writerInput), 'Answer Writer Node input excludes citation_bundle');
assert_true(!array_key_exists('plugin_results', $writerInput), 'Answer Writer Node input excludes plugin_results');
assert_same($evidencePackage, $writerInput['evidence_package'], 'Answer Writer Node input uses package exactly');

$agentServiceSource = file_get_contents(__DIR__ . '/../api/agent/orchestrator/AcademicAgentService.php');
assert_true(is_string($agentServiceSource) && str_contains($agentServiceSource, 'buildValidatedEvidencePackage('), 'Agent runtime still builds a validated evidence_package');
assert_true(is_string($agentServiceSource) && !str_contains($agentServiceSource, '->writeEvidencePackageAnswer('), 'Agent runtime no longer calls the direct evidence_package writer');
assert_true(is_string($agentServiceSource) && str_contains($agentServiceSource, '->writeEvidenceWalkDraft('), 'Agent runtime calls the evidence_walk draft writer');
assert_true(is_string($agentServiceSource) && !str_contains($agentServiceSource, '->writeStructuredAnswer('), 'Agent runtime does not call legacy structured writer');

echo "Agent EvidencePackage runtime tests passed.\n";
