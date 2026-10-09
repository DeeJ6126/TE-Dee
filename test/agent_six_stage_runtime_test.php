<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

$history = [];
$fixtures = [
    wrapper_turn([wrapper_call('page0', 'literature_search', ['te' => ['L1HS'], 'disease' => 'cancer', 'page' => 0])], '', 'private-research-thought'),
    static function (array $messages, array $tools, array $options) use (&$history): array {
        $history[] = $messages;
        wrapper_same('auto', $options['tool_choice'], 'Research allows explicit registered tools.');
        wrapper_assert($tools !== [], 'Research receives actual native tool schemas.');
        return wrapper_turn([wrapper_call('page1', 'literature_search', ['te' => ['L1HS'], 'disease' => 'cancer', 'page' => 1])]);
    },
    wrapper_finish(),
    static function (array $messages, array $tools, array $options) use (&$history): array {
        $history[] = $messages;
        wrapper_same('none', $options['tool_choice'], 'Final writing cannot initiate an extra retrieval.');
        return wrapper_turn([], 'The supplied study reports an observation of L1HS in cancer (PMID 12345).');
    },
];
$service = new TekgAcademicAgentService(wrapper_config($fixtures));
$entity = new WrapperFixturePlugin('Entity Resolver');
$literature = new WrapperFixturePlugin('Literature Plugin');
wrapper_inject($service, ['Entity Resolver' => $entity, 'Literature Plugin' => $literature]);
$session = 'agent-six-stage-native-' . bin2hex(random_bytes(6));
$events = [];
$response = $service->stream(['question' => 'Find local L1HS cancer evidence.', 'request_id' => 'agent-six-stage-native', 'session_id' => $session],
    static function (array $event) use (&$events): void { $events[] = $event; });
wrapper_same(false, $response['failed'], 'The Agent wrapper executes the real shared native loop.');
wrapper_same('deepseek-flash', $response['model'], 'Every phase uses the shared Flash model.');
wrapper_same(['Understanding', 'Planning', 'Collecting', 'Executing', 'Integrating', 'Writing'], wrapper_stages($events), 'All six UI phases describe actual workflow work.');
wrapper_same([], $response['six_stage_artifacts'], 'Removed independent JSON nodes have no fabricated artifacts.');
wrapper_same('native_tool_loop.v1', $response['tool_loop']['engine'], 'The response identifies the native tool engine.');
wrapper_same(2, $literature->runCount, 'The same plugin may run again with another explicit page.');
wrapper_same(1, $entity->runCount, 'Entity resolution remains bootstrap-only.');
wrapper_assert(in_array('Executing->Collecting', $response['workflow_state']['traversed_edges'], true), 'The workflow records a real return to evidence collection.');
wrapper_same('The supplied study reports an observation of L1HS in cancer (PMID 12345).', $response['answer'], 'The answer is produced by the final model turn.');
wrapper_terminal($events, false);
$serializedHistory = json_encode($history, JSON_THROW_ON_ERROR);
wrapper_assert(str_contains($serializedHistory, 'private-research-thought'), 'The final writer retains earlier reasoning in the continuous task history.');
wrapper_assert(str_contains($serializedHistory, '"role":"tool"') && str_contains($serializedHistory, 'page0'), 'Real executed tool messages survive until final writing.');
wrapper_assert(!str_contains(json_encode([$events, $response], JSON_THROW_ON_ERROR), 'private-research-thought'), 'Private model reasoning is not exposed in public responses.');
$detailIds = array_column(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_result'), 'detail_payload_id');
wrapper_same(count($detailIds), count(array_unique($detailIds)), 'Repeated calls keep independent inspector identities.');
wrapper_same(1, count(tekg_agent_load_session_memory($session)['recent_turns']), 'Only a successful final answer enters conversation memory.');
wrapper_cleanup($session);

$navigation = new WrapperFixturePlugin('Site Navigator Plugin', [
    'results' => ['candidate_routes' => [['title' => 'Expression', 'url' => '/TE-/expression_detail.php?te=L1HS']]],
    'citations' => [], 'display_details' => ['preview_items' => [], 'citations' => []],
]);
$navigationService = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('navigate', 'site_navigate', ['page' => 'expression', 'te' => 'L1HS'])]),
    wrapper_finish(),
    wrapper_turn([], 'Open [L1HS expression](/TE-/expression_detail.php?te=L1HS).'),
]));
wrapper_inject($navigationService, ['Site Navigator Plugin' => $navigation]);
$navigationSession = 'agent-navigation-native-' . bin2hex(random_bytes(6));
$navigationResponse = $navigationService->handle(['question' => 'Where can I open the L1HS expression page?', 'session_id' => $navigationSession]);
wrapper_same(false, $navigationResponse['failed'], 'The navigation tool remains callable through the shared loop.');
wrapper_same([], $navigationResponse['evidence_package']['claims'] ?? [], 'Site navigation does not generate scientific claims.');
wrapper_same('/TE-/expression_detail.php?te=L1HS', $navigationResponse['evidence_package']['route_map'][0]['route']['url'] ?? null, 'Navigation routes retain the original supplied URL.');
wrapper_cleanup($navigationSession);

$repairService = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('graph', 'graph_lookup', ['te' => ['L1HS'], 'disease' => 'cancer'])]),
    wrapper_finish(),
    wrapper_turn([], 'The observation is documented in PMID 99999.'),
    static function (array $messages): array {
        wrapper_assert(str_contains(json_encode($messages, JSON_THROW_ON_ERROR), 'Repair the final answer once'), 'An invented citation triggers the actual integrity repair step.');
        return wrapper_turn([], 'The supplied record describes L1HS in cancer (PMID 12345).');
    },
]));
wrapper_inject($repairService, ['Graph Plugin' => new WrapperFixturePlugin('Graph Plugin')]);
$repairSession = 'agent-repair-native-' . bin2hex(random_bytes(6));
$repairResponse = $repairService->handle(['question' => 'Find local L1HS cancer evidence.', 'session_id' => $repairSession]);
wrapper_same(false, $repairResponse['failed'], 'The repaired answer passes the retained citation integrity gate.');
wrapper_assert(!str_contains($repairResponse['answer'], '99999'), 'The unsupported citation is never published.');
wrapper_cleanup($repairSession);

foreach (['research', 'writing'] as $failureCase) {
    $failureFixtures = $failureCase === 'research'
        ? [['ok' => false, 'error' => 'Fixture model transport unavailable.', 'error_type' => 'transport_error']]
        : [wrapper_turn([wrapper_call('graph', 'graph_lookup', ['te' => ['L1HS'], 'disease' => 'cancer'])]), wrapper_finish(), wrapper_turn(), wrapper_turn()];
    $failureService = new TekgAcademicAgentService(wrapper_config($failureFixtures));
    $failureGraph = new WrapperFixturePlugin('Graph Plugin');
    wrapper_inject($failureService, ['Graph Plugin' => $failureGraph]);
    $failureSession = 'agent-failed-native-' . $failureCase . '-' . bin2hex(random_bytes(6));
    $failureEvents = [];
    $failed = $failureService->stream(['question' => 'Find local L1HS cancer evidence.', 'session_id' => $failureSession],
        static function (array $event) use (&$failureEvents): void { $failureEvents[] = $event; });
    wrapper_same(true, $failed['failed'], 'Model or final-writing failure remains visible.');
    wrapper_same(true, $failed['writing_failed'], 'Terminal failure preserves worker failure signaling.');
    wrapper_same('', $failed['answer'], 'A failed model cannot be replaced by a rule-generated scientific answer.');
    wrapper_same($failureCase === 'writing' ? 'Writing' : 'Planning', $failed['failure_stage'], 'The failure is attributed to its actual UI phase.');
    wrapper_terminal($failureEvents, true);
    wrapper_same([], tekg_agent_load_session_memory($failureSession)['recent_turns'], 'Failed research never enters successful conversation memory.');
    wrapper_cleanup($failureSession);
}

echo "Agent six-stage shared-loop runtime tests passed.\n";
