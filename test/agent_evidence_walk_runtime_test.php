<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

$service = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('graph', 'graph_lookup', ['te' => ['L1HS'], 'disease' => 'cancer'])]),
    wrapper_turn([wrapper_call('literature', 'literature_search', ['te' => ['L1HS'], 'disease' => 'cancer'])]), wrapper_finish(),
    wrapper_turn([], 'The supplied records report an observation of L1HS in cancer (PMID 12345).'),
]));
wrapper_inject($service, ['Graph Plugin' => new WrapperFixturePlugin('Graph Plugin'), 'Literature Plugin' => new WrapperFixturePlugin('Literature Plugin')]);
$session = 'agent-walk-native-' . bin2hex(random_bytes(6));
$response = $service->handle(['question' => 'Find local L1HS cancer evidence.', 'session_id' => $session]);
wrapper_same(false, $response['failed'], 'The wrapper uses the real evidence pipeline.');
$package = $response['evidence_package'];
$walk = $response['evidence_walk'];
$plan = $response['report_plan'];
wrapper_same(true, EvidencePackage::validate($package)['ok'], 'The actual runtime package validates.');
wrapper_same(true, EvidenceWalk::validate($walk)['ok'], 'The actual runtime evidence walk validates.');
wrapper_same(true, ReportPlan::validate($plan)['ok'], 'The actual runtime report plan validates.');
wrapper_same(count($package['claims']), count($walk['claim_nodes']), 'Every package claim creates a walk claim node.');
wrapper_same(count($package['evidence_items']), count($walk['support_edges']), 'Every original evidence item supplies an actual support edge.');
$claims = array_column($package['claims'], null, 'id');
$evidence = array_column($package['evidence_items'], null, 'id');
$nodes = array_column($walk['claim_nodes'], null, 'id');
$citations = array_column($package['citation_map'], null, 'id');
foreach ($walk['support_edges'] as $edge) {
    wrapper_assert(isset($claims[$edge['claim_id']], $nodes[$edge['claim_node_id']], $evidence[$edge['evidence_id']]), 'Support edges refer to real claim/node/evidence IDs.');
    wrapper_same($edge['claim_id'], $evidence[$edge['evidence_id']]['claim_id'], 'A support edge never maps evidence to a different claim.');
}
foreach ($walk['citation_refs'] as $ref) {
    wrapper_assert(isset($citations[$ref['citation_id']]), 'Every citation reference points to a supplied package citation.');
    wrapper_same('12345', $ref['citation']['pmid'], 'Citation references retain supplied stable identities.');
}
foreach ($plan['claim_sequence'] as $item) {
    wrapper_assert(isset($claims[$item['claim_id']], $nodes[$item['claim_node_id']]), 'Report order uses real evidence walk IDs.');
}
wrapper_same(true, $response['integrity_report']['final']['ok'] ?? null, 'The published final answer passes the actual integrity gate.');
wrapper_same(true, $response['integrity_report']['user_facing']['ok'] ?? null, 'The published answer passes the actual user-facing audit.');
wrapper_same($response['answer'], $response['draft_report'] ?? null, 'The preserved draft is the real final-writer text.');
wrapper_same('', $response['polished_report'] ?? null, 'No separate removed polisher is represented as having run.');
wrapper_same([], $response['six_stage_artifacts'], 'Removed independent review nodes do not produce fabricated claim maps.');
wrapper_cleanup($session);
echo "Agent EvidenceWalk native-loop runtime tests passed.\n";
