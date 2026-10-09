<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

$sequence = new WrapperFixturePlugin('Sequence Plugin', [
    'evidence_items'=>[tekg_agent_make_evidence_item('Sequence Plugin','The L1HS record has a consensus length of 6064 bp.','L1HS','high',[],['title'=>'L1HS'],['evidence_type'=>'sequence_record'])],
    'citations'=>[], 'display_details'=>['preview_items'=>[],'citations'=>[]],
]);
$writerCalled = false;
$service = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('sequence','sequence_lookup',['te'=>['L1HS']])]), wrapper_finish(),
    static function(array $messages, array $tools, array $options) use (&$writerCalled): array {
        $writerCalled=true;
        wrapper_same('none',$options['tool_choice'],'A simple lookup still uses an actual model writer.');
        wrapper_assert(str_contains($messages[array_key_last($messages)]['content'],'6064'),'The writer receives retrieved data rather than a mode recommendation.');
        return wrapper_turn([], 'L1HS has a recorded consensus length of 6064 bp.');
    },
]));
wrapper_inject($service,['Sequence Plugin'=>$sequence]);
$session='native-simple-'.bin2hex(random_bytes(6));
$response=$service->handle(['question'=>'What is the consensus length of L1HS?','session_id'=>$session]);
wrapper_same(false,$response['failed'],'Agent answers a simple supported lookup successfully.');
wrapper_same(1,$sequence->runCount,'The scientific lookup actually runs.');
wrapper_assert($writerCalled,'No deterministic scientific preflight answer replaces model writing.');
wrapper_assert(str_contains($response['answer'],'6064') && !str_contains($response['answer'],'recommended'),'The answer contains the fact, not a recommendation to change modes.');
wrapper_cleanup($session);
echo "Agent native simple lookup tests passed.\n";
