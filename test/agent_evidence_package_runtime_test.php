<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

$writerContext = [];
$service = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('literature', 'literature_search', ['te' => ['L1HS'], 'disease' => 'cancer'])]), wrapper_finish(),
    static function (array $messages) use (&$writerContext): array {
        $instruction = $messages[array_key_last($messages)]['content'];
        $jsonStart = strpos($instruction, '{');
        wrapper_assert($jsonStart !== false, 'The final writer receives the real user-facing evidence projection.');
        $writerContext = json_decode(substr($instruction, $jsonStart), true, 512, JSON_THROW_ON_ERROR);
        return wrapper_turn([], 'The supplied record reports an observation of L1HS in cancer (PMID 12345).');
    },
]));
wrapper_inject($service, ['Literature Plugin' => new WrapperFixturePlugin('Literature Plugin', ['results' => ['must_not_reach_writer' => true]])]);
$session = 'agent-package-native-' . bin2hex(random_bytes(6));
$response = $service->handle(['question' => 'Find local L1HS cancer evidence.', 'session_id' => $session]);
wrapper_same(false, $response['failed'], 'The real wrapper builds evidence and writes its supported answer.');
$package = $response['evidence_package'];
wrapper_same(true, EvidencePackage::validate($package)['ok'], 'Runtime-generated evidence_package validates.');
wrapper_same(1, $package['metrics']['claim_count'], 'One real supplied claim enters the package.');
wrapper_same(1, $package['metrics']['evidence_count'], 'One real evidence item enters the package.');
wrapper_same('Literature Plugin', $package['claims'][0]['source_plugin'], 'Claim provenance retains the actual native plugin.');
$claim = $package['claims'][0];
$citationById = array_column($package['citation_map'], null, 'id');
$evidenceById = array_column($package['evidence_items'], null, 'id');
wrapper_same('12345', $citationById[$claim['citation_ids'][0]]['citation']['pmid'], 'The claim points to the supplied PMID.');
wrapper_same($claim['id'], $evidenceById[$claim['evidence_ids'][0]]['claim_id'], 'Evidence maps back to its actual claim.');
wrapper_assert(str_contains($citationById[$claim['citation_ids'][0]]['citation']['abstract_text'], 'A measured observation'), 'Complete supplied abstract material survives evidence packaging.');
$encodedContext = json_encode($writerContext, JSON_THROW_ON_ERROR);
wrapper_assert(str_contains($encodedContext, '12345') && str_contains($encodedContext, 'A measured observation'), 'The writing projection retains citation identity and original abstract material.');
foreach (['must_not_reach_writer', 'raw_result', 'display_details', '"plugin_results"', '"evidence_package"', '"evidence_walk"', '"claim_evidence_map"', '"report_plan"', '"writing_decision"'] as $forbidden) {
    wrapper_assert(!str_contains($encodedContext, $forbidden), 'The final writing projection excludes internal field ' . $forbidden);
}
$invalid = EvidencePackage::validate(['schema_version' => 'evidence_package.v1']);
wrapper_same(false, $invalid['ok'], 'Missing package fields still fail deterministic validation.');
wrapper_assert(in_array('claims is required', $invalid['errors'], true), 'Validation identifies missing claims.');
wrapper_cleanup($session);
echo "Agent EvidencePackage native-loop runtime tests passed.\n";
