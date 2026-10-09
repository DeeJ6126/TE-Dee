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

$legacy = [
    'deepseek_model' => 'deepseek-v4-pro',
    'deepseek_reasoner_model' => 'deepseek-v4-pro',
    'agent_core_model' => 'deepseek-v4-pro',
    'agent_expert_model' => 'deepseek-v4-pro',
    'agent_writing_model' => 'deepseek-v4-pro',
    'agent_polisher_model' => 'deepseek-v4-pro',
    'agent_polisher_enabled' => false,
];
$agent = new TekgAcademicAgentService($legacy);
$override = ['model'=>'deepseek-v4-pro','core_model'=>'deepseek-v4-pro','control_model'=>'deepseek-v4-pro','expert_model'=>'deepseek-v4-pro','narrator_model'=>'deepseek-v4-pro','writing_model'=>'deepseek-v4-pro','answer_structure_model'=>'deepseek-v4-pro','sufficiency_model'=>'deepseek-v4-pro','polisher_model'=>'deepseek-v4-pro'];
foreach (['resolveControlModel','resolveCoreModel','resolveSufficiencyModel','resolveExpertModel','resolveNarratorModel','resolveAnswerStructureModel'] as $method) {
    assert_same('deepseek-flash', call_private_method($agent,$method,[$override]), $method . ' shares one trusted model, ignoring legacy stage and request overrides.');
}
assert_same('deepseek-flash',call_private_method($agent,'resolveWritingModel',[['intent'=>'mechanism'],$override,[]]),'Complex writing also uses Flash.');
assert_same('deepseek-flash',call_private_method($agent,'resolvePolisherModel',[$override,'deepseek-v4-pro']),'Polisher cannot choose a different model.');
assert_same(false,call_private_method($agent,'resolvePolisherEnabled',[[]]),'Polisher remains disabled by default.');
assert_same(true,call_private_method($agent,'resolvePolisherEnabled',[['polisher_enabled'=>true]]),'Optional polishing remains available.');
$dt = new TekgDeepThinkService($legacy);
assert_same('deepseek-flash',call_private_method($dt,'resolveModel',[$override]),'DeepThink uses the same trusted model.');
assert_same('deepseek-flash',call_private_method($dt,'resolveWritingModel',[$override,['intent'=>'sequence']]),'DeepThink writing ignores payload model overrides.');
assert_same('deepseek-flash',call_private_method($dt,'resolveAnswerStructureModel',[$override,['intent'=>'sequence']]),'DeepThink answer structure uses same model.');
// Dummy connection settings permit config-only testing without private runtime files.
putenv('NEO4J_HTTP_URL_BIOLOGY=http://127.0.0.1:1/db/tekg3/tx/commit');
putenv('NEO4J_PASSWORD_BIOLOGY=unit-test-placeholder');
$config=tekg_agent_config();
foreach (['agent_model','deepseek_model','deepseek_reasoner_model','agent_control_model','agent_core_model','agent_sufficiency_model','agent_expert_model','agent_narrator_model','agent_answer_structure_model','agent_writing_model','agent_polisher_model'] as $key) {
    assert_same('deepseek-flash',$config[$key]??null,'Runtime stage alias follows unified model: '.$key);
}
assert_same('deepseek-flash',AgentModelPolicy::resolve(['agent_model'=>'deepseek-v4-flash']),'Legacy Flash alias is normalized at the single policy entry.');
$chosen=new TekgAcademicAgentService(['agent_model'=>'deepseek-flash']+$legacy);
assert_same('deepseek-flash',call_private_method($chosen,'resolveExpertModel',[$override]),'Trusted shared choice takes priority over payload.');
echo "Agent unified model config tests passed.\n";
