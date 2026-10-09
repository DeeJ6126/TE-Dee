<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_deepthink_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

$history = [];
$service = new TekgDeepThinkService(wrapper_config([
    wrapper_turn([wrapper_call('disease', 'graph_lookup', ['te' => ['L1HS'], 'target_types' => ['Disease']])], '', 'private-dt-reasoning'),
    wrapper_turn([wrapper_call('gene', 'graph_lookup', ['te' => ['L1HS'], 'target_types' => ['Gene']])]),
    wrapper_finish(),
    static function (array $messages, array $tools, array $options) use (&$history): array {
        $history = $messages;
        wrapper_same('none', $options['tool_choice'], 'The final DeepThink turn is a writer with retrieval disabled.');
        return wrapper_turn([], 'The supplied record describes an observation of L1HS in cancer (PMID 12345).');
    },
], ['deepseek_model' => 'ignored-frontend-model']));
$entity = new WrapperFixturePlugin('Entity Resolver');
$graph = new WrapperFixturePlugin('Graph Plugin');
wrapper_inject($service, ['Entity Resolver' => $entity, 'Graph Plugin' => $graph]);
$session = 'dt-four-stage-native-' . bin2hex(random_bytes(6));
$events = [];
$response = $service->stream(['question' => 'Find local L1HS disease and gene records.', 'request_id' => 'dt-four-stage-native', 'session_id' => $session, 'model' => 'deepseek-v4-pro'],
    static function (array $event) use (&$events): void { $events[] = $event; });
wrapper_same(false, $response['failed'], 'DeepThink executes the actual native shared loop.');
wrapper_same('deepseek-flash', $response['model'], 'Request and legacy role model overrides do not replace the shared model.');
wrapper_same(['Understanding', 'Planning', 'Executing', 'Writing'], wrapper_stages($events), 'DeepThink retains exactly four visible phases.');
wrapper_same([], $response['dt_artifacts'], 'No removed independent node artifacts are fabricated.');
wrapper_same(2, $graph->runCount, 'DeepThink can revisit one plugin for different explicit evidence scopes.');
wrapper_same(1, $entity->runCount, 'The entity resolver remains bootstrap-only.');
wrapper_same(['Disease'], $graph->contexts[0]['analysis']['requested_target_types'], 'The first request retains its disease scope.');
wrapper_same(['Gene'], $graph->contexts[1]['analysis']['requested_target_types'], 'The second request retains its gene scope.');
wrapper_assert(str_contains(json_encode($history, JSON_THROW_ON_ERROR), 'private-dt-reasoning'), 'The writer receives the continuous prior model/tool context.');
wrapper_assert(!str_contains(json_encode([$events, $response], JSON_THROW_ON_ERROR), 'private-dt-reasoning'), 'Private reasoning does not appear in browser events.');
wrapper_terminal($events, false);
wrapper_same(1, count(tekg_agent_load_session_memory($session)['recent_turns']), 'Successful writing is recorded once.');
wrapper_cleanup($session);

$emptyRetryService = new TekgDeepThinkService(wrapper_config([
    wrapper_turn([wrapper_call('graph', 'graph_lookup', ['te' => ['L1HS'], 'disease' => 'cancer'])]),
    wrapper_finish(), wrapper_turn(),
    static function (array $messages): array {
        wrapper_assert(str_contains(json_encode($messages, JSON_THROW_ON_ERROR), 'Repair the final answer once'), 'An empty answer triggers the real one-repair boundary.');
        return wrapper_turn([], 'The supplied record reports L1HS in cancer (PMID 12345).');
    },
]));
wrapper_inject($emptyRetryService, ['Graph Plugin' => new WrapperFixturePlugin('Graph Plugin')]);
$retrySession = 'dt-empty-retry-native-' . bin2hex(random_bytes(6));
$retried = $emptyRetryService->handle(['question' => 'Find local L1HS cancer evidence.', 'session_id' => $retrySession]);
wrapper_same(false, $retried['failed'], 'A nonempty validated model repair can recover the first empty writing result.');
wrapper_assert(str_contains($retried['answer'], '12345'), 'The repaired answer still cites supplied material.');
wrapper_cleanup($retrySession);

$protectedRaw = ['entity' => 'L1HS', 'paper_title' => 'A retrieved L1HS paper', 'url' => 'https://example.test/L1HS', 'sequence' => 'ACGTN', 'relation_type' => 'ASSOCIATED_WITH'];
$zhService = new TekgDeepThinkService(wrapper_config([
    wrapper_turn([wrapper_call('graph', 'graph_lookup', ['te' => ['L1HS']])]), wrapper_finish(),
    wrapper_turn([], '所供记录报道了 L1HS 在癌症中的观察结果（PMID 12345）。'),
]));
wrapper_inject($zhService, ['Graph Plugin' => new WrapperFixturePlugin('Graph Plugin', ['results' => $protectedRaw])]);
$zhSession = 'dt-language-native-' . bin2hex(random_bytes(6));
$zhEvents = [];
$zhResponse = $zhService->stream(['question' => 'L1HS 和哪些疾病相关？', 'session_id' => $zhSession], static function (array $event) use (&$zhEvents): void { $zhEvents[] = $event; });
wrapper_same(false, $zhResponse['failed'], 'Chinese question succeeds through the same native loop.');
wrapper_same('chinese', $zhResponse['language'], 'The output retains detected request language.');
wrapper_same(['Understanding', 'Planning', 'Executing', 'Writing'], wrapper_stages($zhEvents), 'Chinese requests preserve English stage IDs.');
$zhTool = array_values(array_filter($zhEvents, static fn(array $event): bool => $event['type'] === 'tool_result'))[0];
foreach ($protectedRaw as $key => $value) {
    wrapper_same($value, $zhTool['payload']['raw_result'][$key] ?? null, 'Presentation retains original scientific field ' . $key);
}
wrapper_cleanup($zhSession);

$navigationService = new TekgDeepThinkService(wrapper_config([
    wrapper_turn([wrapper_call('navigate', 'site_navigate', ['page' => 'expression', 'te' => 'L1HS'])]), wrapper_finish(),
    wrapper_turn([], 'Open [L1HS expression](/TE-/expression_detail.php?te=L1HS).'),
]));
wrapper_inject($navigationService, ['Site Navigator Plugin' => new WrapperFixturePlugin('Site Navigator Plugin', [
    'results' => ['candidate_routes' => [['title' => 'Expression', 'url' => '/TE-/expression_detail.php?te=L1HS']]],
    'citations' => [], 'display_details' => ['preview_items' => [], 'citations' => []],
])]);
$navigationSession = 'dt-navigation-native-' . bin2hex(random_bytes(6));
$navigation = $navigationService->handle(['question' => 'Where can I open the L1HS expression page?', 'session_id' => $navigationSession]);
wrapper_same(false, $navigation['failed'], 'Validated navigation remains available.');
wrapper_same([], $navigation['evidence_package']['claims'] ?? [], 'Site navigation never becomes scientific evidence.');
wrapper_assert(str_contains($navigation['answer'], '/TE-/expression_detail.php?te=L1HS'), 'The writer preserves the supplied navigation URL.');
wrapper_cleanup($navigationSession);

foreach (['research', 'writing', 'no_evidence'] as $failureCase) {
    $fixtures = match ($failureCase) {
        'research' => [['ok' => false, 'error' => 'Fixture model unavailable.', 'error_type' => 'transport_error']],
        'writing' => [wrapper_turn([wrapper_call('graph', 'graph_lookup', ['te' => ['L1HS']])]), wrapper_finish(), wrapper_turn(), wrapper_turn()],
        default => [wrapper_turn([], 'This prior model text is not scientific evidence.'), wrapper_turn([], 'Still no lookup performed.')],
    };
    $failureService = new TekgDeepThinkService(wrapper_config($fixtures));
    $failureGraph = new WrapperFixturePlugin('Graph Plugin');
    wrapper_inject($failureService, ['Graph Plugin' => $failureGraph]);
    $failureSession = 'dt-failed-native-' . $failureCase . '-' . bin2hex(random_bytes(6));
    $failureEvents = [];
    $failed = $failureService->stream(['question' => 'Find local L1HS cancer evidence.', 'session_id' => $failureSession],
        static function (array $event) use (&$failureEvents): void { $failureEvents[] = $event; });
    wrapper_same(true, $failed['failed'], 'Failed research or writing remains terminally failed.');
    wrapper_same('', $failed['answer'], 'DeepThink does not invent an answer after model or retrieval failure.');
    wrapper_same($failureCase === 'writing' ? 'Writing' : 'Planning', $failed['failure_stage'], 'Failure names the actual UI phase.');
    wrapper_terminal($failureEvents, true);
    wrapper_same([], tekg_agent_load_session_memory($failureSession)['recent_turns'], 'Failed responses do not enter memory.');
    wrapper_cleanup($failureSession);
}

$endpointSource = (string)file_get_contents(__DIR__ . '/../api/deep_think_stream.php');
foreach (["'failed' => true", "'failure_stage' => 'Endpoint'", "'failure_reason'", "'answer' => ''", "'presentation_failure_reason'"] as $needle) {
    wrapper_assert(str_contains($endpointSource, $needle), 'The SSE endpoint retains terminal failure field ' . $needle);
}

echo "DeepThink four-stage shared-loop runtime tests passed.\n";
