<?php
declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/api/agent/config/agent_prompts.php';
require_once $root . '/api/agent/contracts/ReportPlan.php';

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

$policyPath = $root . '/api/agent/config/user_facing_writing_policy.php';
assert_true(is_file($policyPath), 'shared user-facing writing policy exists');

$policy = require $policyPath;
assert_contains('Do not expose internal workflow vocabulary in the final answer.', (string)($policy['en'] ?? ''), 'English policy addresses internal vocabulary');
assert_contains('association_not_causality', (string)($policy['en'] ?? ''), 'English policy names a known raw flag only as forbidden input vocabulary');
assert_contains('keyword_derived', (string)($policy['en'] ?? ''), 'English policy names the keyword flag only as forbidden input vocabulary');
assert_contains('only factual content', (string)($policy['en'] ?? ''), 'English policy makes projected facts the factual boundary');
assert_contains('Do not repeat scientific content', (string)($policy['en'] ?? ''), 'English policy prevents literature-section duplication');
assert_contains('exact numerical values', (string)($policy['en'] ?? ''), 'English policy prevents unsupported rounding');
assert_contains('silent writing constraints', (string)($policy['en'] ?? ''), 'English policy keeps factual boundaries out of visible prose');
assert_contains('roughly 600-900 words', (string)($policy['en'] ?? ''), 'English policy applies gentle report-length guidance');
assert_contains('不要在最终回答中暴露内部工作流词汇。', (string)($policy['zh'] ?? ''), 'Chinese policy addresses internal vocabulary');

$payload = [
    'question' => 'Generate a research report for L1HS.',
    'evidence_package' => ['claims' => []],
    'evidence_walk' => ['claim_nodes' => []],
    'claim_evidence_map' => ['limitations' => []],
    'writing_decision' => ['final_checks' => []],
    'report_plan' => ['sections' => []],
];

$agentPrompts = [
    TekgAgentPromptLibrary::systemPrompt('en'),
    TekgAgentPromptLibrary::structuredAnswerPrompt('en', $payload),
    TekgAgentPromptLibrary::evidenceWalkDraftPrompt('en', $payload),
    TekgAgentPromptLibrary::evidenceWalkPolishPrompt('en', $payload),
    TekgAgentPromptLibrary::directAnswerPrompt('en', $payload),
];
foreach ($agentPrompts as $index => $prompt) {
    assert_contains('Do not expose internal workflow vocabulary in the final answer.', $prompt, "Agent final-writing prompt {$index} includes the shared presentation policy");
    assert_contains('PMID', $prompt, "Agent final-writing prompt {$index} requires a user-facing citation form");
}

$draftPrompt = TekgAgentPromptLibrary::evidenceWalkDraftPrompt('en', $payload);
assert_not_contains('Make a claim-evidence map explicit in the report', $draftPrompt, 'claim-evidence map remains internal');
assert_contains('Use the supplied facts and real references internally', $draftPrompt, 'draft verifies claims from user-facing facts and references');

$agentNodePrompts = require $root . '/api/agent/config/agent_node_prompts.php';
$dtNodePrompts = require $root . '/api/agent/config/dt_node_prompts.php';
assert_contains('Do not expose internal workflow vocabulary in the final answer.', (string)$agentNodePrompts['writing']['en'], 'WritingDecision follows the shared presentation policy');
assert_contains('Do not expose internal workflow vocabulary in the final answer.', (string)$dtNodePrompts['writing']['en'], 'DeepThink Writing follows the shared presentation policy');
assert_contains('不要在最终回答中暴露内部工作流词汇。', (string)$agentNodePrompts['writing']['zh'], 'Chinese WritingDecision follows the shared presentation policy');
assert_contains('不要在最终回答中暴露内部工作流词汇。', (string)$dtNodePrompts['writing']['zh'], 'Chinese DeepThink Writing follows the shared presentation policy');

$sharedLoop = file_get_contents($root . '/api/agent/orchestrator/QaResearchLoop.php');
assert_true(is_string($sharedLoop), 'Shared native workflow source is readable');
assert_contains('UserFacingWritingContext::fromInternal(', $sharedLoop, 'Both modes project original retrieved material into a writing context');
assert_contains('UserFacingWritingContext::auditAnswer($answer, $this->question, $context)', $sharedLoop, 'Both modes audit generated and repaired prose');
assert_contains("\$this->request(\$tools, 'none', 'qa_writing')", $sharedLoop, 'Final model turn receives a continuous history with tool calls disabled');
assert_contains("\$this->request(\$tools, 'none', 'qa_writing_repair')", $sharedLoop, 'Presentation repair runs on the same model history');
assert_contains('cite only supplied PMIDs/URLs', $sharedLoop, 'Final writing limits references to original retrieved sources');
assert_contains('private reasoning_content', $sharedLoop, 'Private reasoning is excluded from final prose');
foreach (['DeepThinkService.php', 'AcademicAgentService.php'] as $serviceFile) {
    $service = file_get_contents($root . '/api/agent/orchestrator/' . $serviceFile);
    assert_true(is_string($service), 'Service wrapper is readable');
    assert_contains('TekgAgentQaResearchLoop', $service, 'Both services execute the shared native writing constraints');
}

$reportPlan = ReportPlan::fromEvidenceWalk(
    'Generate a research report for L1HS including sequence, genomic location, expression, disease links, and literature evidence.',
    ['intent' => 'literature', 'task_complexity' => 'research_synthesis'],
    ['claim_nodes' => [], 'gaps' => []],
    []
);
assert_true(($reportPlan['report_type'] ?? '') === 'research_report', 'explicit research-report request is not converted into evidence_audit');
assert_true(
    array_column((array)($reportPlan['sections'] ?? []), 'key') === ['overview', 'requested_findings', 'limitations'],
    'research report plan avoids duplicated audit and final-answer sections'
);

echo "Agent user-facing writing prompt tests passed.\n";
