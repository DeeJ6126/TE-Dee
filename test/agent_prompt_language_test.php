<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/../api/agent/orchestrator/LlmClient.php';
tekg_agent_require_academic_agent_service();

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

function assert_source_contains(string $path, string $needle, string $message): void
{
    $source = file_get_contents($path);
    assert_true(is_string($source), "Source file can be read: {$path}");
    assert_contains($needle, $source, $message);
}

$promptLibraryPath = __DIR__ . '/../api/agent/config/agent_prompts.php';
assert_true(file_exists($promptLibraryPath), 'centralized Agent prompt library exists');

assert_true(tekg_agent_detect_language(['language' => 'zh-cn'], 'English question') === 'chinese', 'explicit zh-cn language beats English question heuristic');
assert_true(tekg_agent_detect_language(['language' => 'zh_cn'], 'English question') === 'chinese', 'explicit zh_cn language beats English question heuristic');
assert_true(tekg_agent_detect_language(['language' => '中文'], 'English question') === 'chinese', 'explicit 中文 language beats English question heuristic');
assert_true(tekg_agent_detect_language(['question' => '中文问题']) === 'chinese', 'payload question field drives Chinese heuristic when language is absent');
assert_true(tekg_agent_detect_language(['question' => 'English question']) === 'english', 'payload question field keeps English default when language is absent');
assert_true(tekg_agent_detect_language('English question', 'zh-cn') === 'chinese', 'zh-cn fallback is normalized to Chinese');
assert_true(tekg_agent_detect_language('English question', 'zh_cn') === 'chinese', 'zh_cn fallback is normalized to Chinese');

$client = new TekgAgentLlmClient([]);
$reflection = new ReflectionClass($client);
$service = new TekgAcademicAgentService([]);
$serviceReflection = new ReflectionClass($service);
assert_true($serviceReflection->hasMethod('resolveProcessLanguage'), 'AcademicAgentService exposes a testable process language resolver');
$processLanguageMethod = $serviceReflection->getMethod('resolveProcessLanguage');
assert_true($processLanguageMethod->invoke($service, 'chinese') === 'chinese', 'Agent process/narrator language follows Chinese answer language');
assert_true($processLanguageMethod->invoke($service, 'english') === 'english', 'Agent process/narrator language follows English answer language');

$question = 'LINE-1 是如何促进癌症发生的？';
$analysis = ['intent' => 'mechanism', 'answer_language' => 'chinese'];
$evidencePackage = [
    'schema_version' => 'evidence_package.v1',
    'claims' => [['id' => 'claim_1', 'text' => 'LINE-1 与癌症相关。']],
    'evidence_items' => [['id' => 'evidence_1', 'claim_id' => 'claim_1', 'text' => '证据摘要。']],
    'citation_map' => [],
];
$evidenceWalk = [
    'schema_version' => 'evidence_walk.v1',
    'walk_steps' => [['id' => 'walk_step_1', 'evidence_refs' => ['evidence_1']]],
    'gaps' => [],
];
$reportPlan = [
    'schema_version' => 'report_plan.v1',
    'sections' => [['key' => 'mechanism', 'title' => '机制链']],
];

$draftMethod = $reflection->getMethod('buildEvidenceWalkDraftPrompt');
$claimEvidenceMap = [
    'schema_version' => 'claim_evidence_map.v1',
    'supported_claims' => [],
    'unsupported_claims' => [],
    'limitations' => [],
];
$writingDecision = [
    'schema_version' => 'writing_decision.v1',
    'forbidden_claims' => [],
    'citation_requirements' => [],
    'final_checks' => [],
];
$zhDraftPrompt = $draftMethod->invoke(
    $client,
    $question,
    $analysis,
    $evidencePackage,
    $evidenceWalk,
    $claimEvidenceMap,
    $writingDecision,
    $reportPlan,
    'medium',
    ['max_words' => 900],
    'zh-cn'
);
assert_contains('使用 evidence-walk 草稿报告写作策略', $zhDraftPrompt, 'Chinese evidence-walk draft prompt uses Chinese instruction');
assert_not_contains('Write an evidence-walk draft report using evidence-grounded drafting policy.', $zhDraftPrompt, 'Chinese draft prompt does not use English opening');

$enDraftPrompt = $draftMethod->invoke(
    $client,
    'How does LINE-1 promote cancer?',
    ['intent' => 'mechanism', 'answer_language' => 'english'],
    $evidencePackage,
    $evidenceWalk,
    $claimEvidenceMap,
    $writingDecision,
    $reportPlan,
    'medium',
    ['max_words' => 900],
    'english'
);
assert_contains('Write an evidence-walk draft report using evidence-grounded drafting policy.', $enDraftPrompt, 'English evidence-walk draft prompt keeps English instruction');

$polishMethod = $reflection->getMethod('buildEvidenceWalkPolishPrompt');
$zhPolishPrompt = $polishMethod->invoke(
    $client,
    '草稿答案。',
    $analysis,
    $evidencePackage,
    $evidenceWalk,
    $claimEvidenceMap,
    $writingDecision,
    $reportPlan,
    ['ok' => true, 'errors' => []],
    'chinese'
);
assert_contains('使用保留证据的润色策略润色这份 evidence-walk 草稿', $zhPolishPrompt, 'Chinese polish prompt uses Chinese polishing constraint');
assert_not_contains('Polish this evidence-walk draft using evidence-preserving polishing policy.', $zhPolishPrompt, 'Chinese polish prompt does not use English opening');

$narratorSystemMethod = $reflection->getMethod('narratorSystemPrompt');
$zhNarratorSystem = $narratorSystemMethod->invoke($client, 'zh');
assert_contains('过程叙述器', $zhNarratorSystem, 'narrator system prompt normalizes zh to Chinese');
assert_not_contains('process narrator', $zhNarratorSystem, 'Chinese narrator system prompt does not use English body');

$systemMethod = $reflection->getMethod('systemPrompt');
$zhSystem = $systemMethod->invoke($client, 'zh_cn');
$enSystem = $systemMethod->invoke($client, 'english');
assert_contains('只能基于已经提供', $zhSystem, 'system prompt supports zh_cn Chinese branch');
assert_contains('中文问题必须使用中文回答', $zhSystem, 'Chinese system prompt locks final answer language');
assert_contains('不要输出俄文', $zhSystem, 'Chinese system prompt explicitly prevents Russian language drift');
assert_contains('Answer only from the structured plugin results', $enSystem, 'system prompt supports English branch');

$narratorPromptMethod = $reflection->getMethod('buildNarratorPrompt');
$zhNarratorPrompt = $narratorPromptMethod->invoke($client, ['stage' => 'collecting', 'status' => 'ok'], 'chinese');
assert_contains('用 1 到 2 句', $zhNarratorPrompt, 'narrator user prompt uses Chinese when requested');

$jsonSystemMethod = $reflection->getMethod('jsonSystemPrompt');
$jsonInstructionMethod = $reflection->getMethod('jsonInstructionPrompt');
$languageFromPayloadMethod = $reflection->getMethod('languageFromPayload');
$languageFromMixedAnalysis = $languageFromPayloadMethod->invoke($client, [
    'question' => 'English question',
    'analysis' => [
        'language' => 'english',
        'answer_language' => 'chinese',
    ],
]);
assert_true($languageFromMixedAnalysis === 'chinese', 'answer_language beats legacy analysis language for JSON prompt language');
$languageFromTopLevelPayload = $languageFromPayloadMethod->invoke($client, [
    'question' => 'English question',
    'answer_language' => 'chinese',
    'process_language' => 'chinese',
    'analysis' => [
        'language' => 'english',
    ],
]);
assert_true($languageFromTopLevelPayload === 'chinese', 'top-level answer_language drives JSON prompt language without question heuristic');
$zhJsonSystem = $jsonSystemMethod->invoke($client, 'zh-cn');
$zhSufficiency = $jsonInstructionMethod->invoke($client, 'sufficiency', 'zh-cn');
$zhAnswerStructure = $jsonInstructionMethod->invoke($client, 'answer_structure', 'chinese');
foreach ([$zhJsonSystem, $zhSufficiency, $zhAnswerStructure] as $jsonPrompt) {
    assert_contains('valid JSON', $jsonPrompt, 'Chinese JSON prompt keeps valid JSON constraint');
    assert_contains('Do not use Markdown fences', $jsonPrompt, 'Chinese JSON prompt keeps no Markdown fence constraint');
}
assert_contains('is_sufficient', $zhSufficiency, 'sufficiency JSON instruction keeps required key');
assert_contains('answer_structure', $zhAnswerStructure, 'answer_structure JSON instruction keeps required object name');

$structuredMethod = $reflection->getMethod('buildStructuredAnswerPrompt');
$zhStructured = $structuredMethod->invoke($client, $question, $analysis, ['section_plan' => ['机制链']], [], [], [], [], 'medium', [], 'zh_cn');
$enStructured = $structuredMethod->invoke($client, 'How does LINE-1 promote cancer?', ['intent' => 'mechanism'], ['section_plan' => ['Mechanism']], [], [], [], [], 'medium', [], 'english');
assert_contains('只根据下面的结构化答案计划和证据写最终回答', $zhStructured, 'structured answer prompt supports zh_cn Chinese branch');
assert_contains('Write the final answer only from the structured answer plan', $enStructured, 'structured answer prompt supports English branch');

$genericMethod = $reflection->getMethod('buildUserPrompt');
$zhGeneric = $genericMethod->invoke($client, $question, [], [], [], [], 'medium', [], '中文');
$enGeneric = $genericMethod->invoke($client, 'How does LINE-1 promote cancer?', [], [], [], [], 'medium', [], 'english');
assert_contains('请使用下面的结构化证据回答研究问题', $zhGeneric, 'generic prompt supports 中文 Chinese branch');
assert_contains('Use the following structured evidence', $enGeneric, 'generic prompt supports English branch');

$directMethod = $reflection->getMethod('buildDirectAnswerPrompt');
$zhDirect = $directMethod->invoke($client, $question, $analysis, [], [], [], [], 'medium', [], [], 'zh-cn');
$enDirect = $directMethod->invoke($client, 'How does LINE-1 promote cancer?', ['intent' => 'mechanism'], [], [], [], [], 'medium', [], [], 'english');
assert_contains('直接根据下面的证据写最终回答', $zhDirect, 'direct answer prompt supports zh-cn Chinese branch');
assert_contains('不要在回答中写出 extra_context', $zhDirect, 'direct answer prompt prevents internal context key leakage');
assert_contains('Write the final answer directly from the evidence below', $enDirect, 'direct answer prompt supports English branch');
assert_contains('Do not write the literal word extra_context', $enDirect, 'English direct answer prompt prevents internal context key leakage');

$summaryMethod = $reflection->getMethod('buildEvidenceSummaryPrompt');
$zhSummary = $summaryMethod->invoke($client, $question, $analysis, [], [], [], [], 'medium', [], '', 'zh_cn');
$enSummary = $summaryMethod->invoke($client, 'How does LINE-1 promote cancer?', ['intent' => 'mechanism'], [], [], [], [], 'medium', [], '', 'english');
assert_contains('写一个不超过 3 句的简短证据摘要', $zhSummary, 'summary prompt supports zh_cn Chinese branch');
assert_contains('Write a short evidence-based summary', $enSummary, 'summary prompt supports English branch');

assert_contains('single read-only Cypher query', TekgAgentPromptLibrary::jsonInstructionPrompt('cypher_explorer', 'english'), 'Cypher Explorer JSON instruction is centralized');
assert_contains('supported_claims', TekgAgentPromptLibrary::jsonInstructionPrompt('literature_reading', 'english'), 'Literature Reading JSON instruction is centralized');
assert_contains('single-model tool-using academic assistant', TekgAgentPromptLibrary::jsonInstructionPrompt('deepthink_router', 'english'), 'DeepThink router JSON instruction is centralized');

$answerStructurePayloadMethod = $serviceReflection->getMethod('buildAnswerStructurePayload');
$agentAnswerStructurePayload = $answerStructurePayloadMethod->invoke(
    $service,
    'English wording should not override explicit payload language',
    ['intent' => 'mechanism', 'answer_language' => 'chinese', 'language' => 'english'],
    ['supported_claims' => ['Claim'], 'conflicting_claims' => [], 'missing_evidence' => []],
    [],
    ['is_sufficient' => true, 'reason' => 'ok', 'missing_dimensions' => []]
);
assert_true(($agentAnswerStructurePayload['answer_language'] ?? '') === 'chinese', 'Agent answer_structure payload carries top-level answer_language');
assert_true(($agentAnswerStructurePayload['process_language'] ?? '') === 'chinese', 'Agent answer_structure payload carries top-level process_language');
assert_true($languageFromPayloadMethod->invoke($client, $agentAnswerStructurePayload) === 'chinese', 'Agent answer_structure payload resolves Chinese from top-level language fields');

assert_source_contains(
    __DIR__ . '/../api/agent/orchestrator/traits/AcademicAgentEvidenceTrait.php',
    "'answer_language' => (string)(\$analysis['answer_language']",
    'Agent sufficiency payload carries explicit top-level answer_language'
);
assert_source_contains(
    __DIR__ . '/../api/agent/orchestrator/traits/AcademicAgentEvidenceTrait.php',
    "'process_language' => (string)(\$analysis['process_language']",
    'Agent sufficiency payload carries explicit top-level process_language'
);
assert_source_contains(
    __DIR__ . '/../api/agent/orchestrator/traits/DeepThinkEvidenceTrait.php',
    "'answer_language' => (string)(\$analysis['answer_language']",
    'DeepThink answer_structure payload carries explicit top-level answer_language'
);
assert_source_contains(
    __DIR__ . '/../api/agent/orchestrator/traits/DeepThinkEvidenceTrait.php',
    "'process_language' => (string)(\$analysis['process_language']",
    'DeepThink answer_structure payload carries explicit top-level process_language'
);
assert_source_contains(
    __DIR__ . '/../api/agent/plugins/CypherExplorerPlugin.php',
    "'answer_language' => (string)(\$analysis['answer_language']",
    'Cypher Explorer JSON payload carries explicit top-level answer_language'
);
assert_source_contains(
    __DIR__ . '/../api/agent/plugins/CypherExplorerPlugin.php',
    "'process_language' => (string)(\$analysis['process_language']",
    'Cypher Explorer JSON payload carries explicit top-level process_language'
);
assert_source_contains(
    __DIR__ . '/../api/agent/plugins/LiteratureReadingPlugin.php',
    "'answer_language' => (string)(\$analysis['answer_language']",
    'Literature Reading JSON payload carries explicit top-level answer_language'
);
assert_source_contains(
    __DIR__ . '/../api/agent/plugins/LiteratureReadingPlugin.php',
    "'process_language' => (string)(\$analysis['process_language']",
    'Literature Reading JSON payload carries explicit top-level process_language'
);

echo "Agent prompt language tests passed.\n";
