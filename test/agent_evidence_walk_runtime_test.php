<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap/evidence_support.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    assert_true(str_contains($haystack, $needle), $message);
}

function assert_not_contains(string $needle, string $haystack, string $message): void
{
    assert_true(!str_contains($haystack, $needle), $message);
}

function assert_call_uses_arguments(string $source, string $callName, array $needles, string $message): void
{
    $position = strpos($source, $callName);
    assert_true($position !== false, $message . " missing {$callName}");
    $snippet = substr($source, $position, 1000);
    foreach ($needles as $needle) {
        assert_true(str_contains($snippet, $needle), $message . " missing {$needle}");
    }
}

$serviceSource = (string)file_get_contents(__DIR__ . '/../api/agent/orchestrator/AcademicAgentService.php');
$traitSource = (string)file_get_contents(__DIR__ . '/../api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php');

foreach ([
    "contracts/EvidenceWalk.php",
    "contracts/ReportPlan.php",
    "contracts/ReportIntegrityGate.php",
] as $requiredContract) {
    assert_contains($requiredContract, $serviceSource, "AcademicAgentService requires {$requiredContract}");
}

foreach ([
    'EvidenceWalk::fromEvidencePackage(',
    'EvidenceWalk::validate(',
    'ReportPlan::fromEvidenceWalk(',
    'ReportPlan::validate(',
    '->writeEvidenceWalkDraft(',
    '->polishEvidenceWalkAnswer(',
    'ReportIntegrityGate::normalizeUrlsInText(',
    'ReportIntegrityGate::check(',
] as $runtimeNeedle) {
    assert_contains($runtimeNeedle, $serviceSource, "Agent runtime contains {$runtimeNeedle}");
}
assert_contains('$claimEvidenceMap = (array)$integratingNode->parsed_json;', $serviceSource, 'Agent runtime stores claimEvidenceMap from integrating node');
assert_contains('$writingDecision = (array)$writingDecisionNode->parsed_json;', $serviceSource, 'Agent runtime stores writingDecision from writing node');
assert_call_uses_arguments($serviceSource, '->writeEvidenceWalkDraft(', ['$claimEvidenceMap', '$writingDecision'], 'Draft writer call receives claim map and writing decision');
assert_call_uses_arguments($serviceSource, '->polishEvidenceWalkAnswer(', ['$claimEvidenceMap', '$writingDecision'], 'Polisher call receives claim map and writing decision');
assert_contains('$draftReport = ReportIntegrityGate::normalizeUrlsInText($draftReport);', $serviceSource, 'Draft report URL text is normalized before integrity checks');
assert_contains('$polishedReport = ReportIntegrityGate::normalizeUrlsInText($polishedReport);', $serviceSource, 'Polished report URL text is normalized before integrity checks');
assert_contains('$answer = ReportIntegrityGate::normalizeUrlsInText($answer);', $serviceSource, 'Final user-visible answer URL text is normalized before emit');
assert_contains('$workflowState[\'stage_statuses\'][\'Writing\'] = $writingFailed ? \'failed\' : \'done\';', $serviceSource, 'Writing workflow state reflects writing failure instead of always marking done');
assert_contains('buildDirectSiteNavigationWritingResult(', $serviceSource, 'Agent runtime has direct site navigation writing path');
assert_contains("'writing_strategy' => 'direct_site_navigation'", $serviceSource, 'Direct site navigation writing path records deterministic strategy');
assert_contains('$siteResult[\'results\'][\'answer_markdown\']', $serviceSource, 'Direct site navigation writing path uses plugin answer_markdown');
assert_contains('$directSiteNavigationWriting !== null', $serviceSource, 'Direct site navigation writing path bypasses normal writer calls');

assert_not_contains('->writeEvidencePackageAnswer(', $serviceSource, 'Agent runtime no longer calls old evidence_package writer');

foreach ([
    "'evidence_walk' => \$evidenceWalk",
    "'report_plan' => \$reportPlan",
    "'draft_report' => \$draftReport",
    "'polished_report' => \$polishedReport",
    "'integrity_report' => \$integrityReport",
    "'claim_evidence_map' => \$claimEvidenceMap",
    "'writing_decision' => \$writingDecision",
    "'writer_draft' => \$writingModel",
    "'writer_polisher' => \$polisherModel",
] as $responseNeedle) {
    assert_contains($responseNeedle, $serviceSource, "Agent response/models include {$responseNeedle}");
}

$contracts = tekg_agent_node_contracts();
$writerContract = $contracts['Answer Writer Node'] ?? null;
assert_true(is_array($writerContract), 'Answer Writer Node contract exists');
foreach (['evidence_package', 'evidence_walk', 'report_plan'] as $inputKey) {
    assert_true(in_array($inputKey, $writerContract['input'] ?? [], true), "Answer Writer Node contract input includes {$inputKey}");
}
foreach (['draft_report', 'polished_report', 'integrity_report'] as $outputKey) {
    assert_true(in_array($outputKey, $writerContract['output'] ?? [], true), "Answer Writer Node contract output includes {$outputKey}");
}
foreach (['supported_claims', 'citation_bundle'] as $legacyInput) {
    assert_true(!in_array($legacyInput, $writerContract['input'] ?? [], true), "Answer Writer Node contract input excludes {$legacyInput}");
}

foreach ([
    "'evidence_walk' => \$evidenceWalk",
    "'report_plan' => \$reportPlan",
    "'draft_report' => \$draftReport",
    "'polished_report' => \$polishedReport",
    "'integrity_report' => \$integrityReport",
    "'claim_evidence_map' => \$claimEvidenceMap",
    "'writing_decision' => \$writingDecision",
] as $nodePayloadNeedle) {
    assert_contains($nodePayloadNeedle, $traitSource, "Answer Writer Node payload contains {$nodePayloadNeedle}");
}

echo "Agent EvidenceWalk runtime tests passed.\n";
