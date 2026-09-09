<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/../api/agent/orchestrator/DeepThinkService.php';
tekg_agent_require_academic_agent_service();

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function call_private_method(object $service, string $methodName, array $args): mixed
{
    $method = new ReflectionMethod($service, $methodName);
    return $method->invokeArgs($service, $args);
}

$agentService = new TekgAcademicAgentService([
    'deepseek_model' => 'deepseek-v4-flash',
    'deepseek_reasoner_model' => 'deepseek-v4-flash',
    'agent_control_model' => 'deepseek-v4-flash',
    'agent_core_model' => 'deepseek-v4-pro',
    'agent_sufficiency_model' => 'deepseek-v4-flash',
    'agent_expert_model' => 'deepseek-v4-pro',
    'agent_narrator_model' => 'deepseek-v4-flash',
    'agent_answer_structure_model' => 'deepseek-v4-flash',
    'agent_writing_model' => 'deepseek-v4-pro',
    'agent_polisher_model' => 'deepseek-v4-flash',
    'agent_polisher_enabled' => false,
]);

assert_same('deepseek-v4-flash', call_private_method($agentService, 'resolveControlModel', [[]]), 'Agent control model uses agent_control_model.');
assert_same('deepseek-v4-flash', call_private_method($agentService, 'resolveControlModel', [['model' => 'deepseek-v4-pro']]), 'Agent control model ignores generic frontend model payload.');
assert_same('deepseek-v4-pro', call_private_method($agentService, 'resolveCoreModel', [[]]), 'Agent legacy core model remains independently configurable.');
assert_same('deepseek-v4-flash', call_private_method($agentService, 'resolveSufficiencyModel', [[]]), 'Agent sufficiency model defaults to lightweight control model.');
assert_same('deepseek-v4-pro', call_private_method($agentService, 'resolveExpertModel', [[]]), 'Agent expert model uses agent_expert_model.');
assert_same('deepseek-v4-flash', call_private_method($agentService, 'resolveNarratorModel', [[]]), 'Agent narrator model uses lightweight narrator model.');
assert_same('deepseek-v4-flash', call_private_method($agentService, 'resolveAnswerStructureModel', [[]]), 'Agent answer_structure model defaults to lightweight model.');
assert_same('deepseek-v4-pro', call_private_method($agentService, 'resolveWritingModel', [['intent' => 'sequence'], [], []]), 'Agent writing model uses agent_writing_model.');
assert_same('deepseek-v4-flash', call_private_method($agentService, 'resolvePolisherModel', [[], 'deepseek-v4-pro']), 'Agent polisher model defaults to flash.');
assert_same(false, call_private_method($agentService, 'resolvePolisherEnabled', [[]]), 'Agent polisher defaults to disabled.');
assert_same(true, call_private_method($agentService, 'resolvePolisherEnabled', [['polisher_enabled' => true]]), 'Agent polisher can be enabled per request.');
assert_same(
    'deepseek-v4-pro',
    call_private_method($agentService, 'resolveFinalAnswerModel', ['draft answer', 'draft answer', 'draft answer', 'deepseek-v4-pro', 'deepseek-v4-flash', false]),
    'Final answer metadata uses the draft writer when the polisher is disabled even if polished_report mirrors the draft.'
);
assert_same(
    'deepseek-v4-flash',
    call_private_method($agentService, 'resolveFinalAnswerModel', ['polished answer', 'draft answer', 'polished answer', 'deepseek-v4-pro', 'deepseek-v4-flash', true]),
    'Final answer metadata uses the polisher model only when enabled and the final answer is the polished report.'
);

$deepThinkService = new TekgDeepThinkService([
    'deepseek_model' => 'deepseek-v4-flash',
    'deepseek_reasoner_model' => 'deepseek-v4-flash',
    'agent_core_model' => 'deepseek-v4-pro',
    'agent_expert_model' => 'deepseek-v4-pro',
    'agent_writing_model' => 'deepseek-v4-pro',
]);

assert_same('deepseek-v4-flash', call_private_method($deepThinkService, 'resolveModel', [[]]), 'DeepThink core model remains flash.');
assert_same(
    'deepseek-v4-flash',
    call_private_method($deepThinkService, 'resolveWritingModel', [[], ['intent' => 'sequence']]),
    'DeepThink simple writing model remains flash.'
);
assert_same(
    'deepseek-v4-flash',
    call_private_method($deepThinkService, 'resolveAnswerStructureModel', [[], ['intent' => 'sequence']]),
    'DeepThink simple answer_structure model remains flash.'
);

$runtimeConfig = tekg_agent_config();
foreach ([
    'deepseek_model',
    'deepseek_reasoner_model',
] as $key) {
    assert_same('deepseek-v4-flash', $runtimeConfig[$key] ?? null, "Runtime {$key} remains flash for DeepThink.");
}
foreach ([
    'agent_control_model',
] as $key) {
    assert_same('deepseek-v4-flash', $runtimeConfig[$key] ?? null, "Runtime {$key} defaults Agent control path to flash.");
}
foreach ([
    'agent_core_model',
    'agent_sufficiency_model',
    'agent_expert_model',
    'agent_writing_model',
] as $key) {
    assert_same('deepseek-v4-pro', $runtimeConfig[$key] ?? null, "Runtime {$key} remains independently configurable for legacy/expert/writing work.");
}
assert_same(false, $runtimeConfig['agent_polisher_enabled'] ?? null, 'Runtime agent_polisher_enabled defaults to false.');

echo "Agent model config tests passed.\n";
