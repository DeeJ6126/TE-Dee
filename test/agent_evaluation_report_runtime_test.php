<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

$service = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('graph', 'graph_lookup', ['te' => ['L1HS'], 'disease' => 'cancer'])]), wrapper_finish(),
    wrapper_turn([], 'The supplied record reports an observation of L1HS in cancer (PMID 12345).'),
]));
wrapper_inject($service, ['Graph Plugin' => new WrapperFixturePlugin('Graph Plugin')]);
$session = 'agent-evaluation-native-' . bin2hex(random_bytes(6));
$response = $service->handle(['question' => 'Find local L1HS cancer evidence.', 'session_id' => $session]);
wrapper_same(false, $response['failed'], 'The real shared loop produces a validated Agent response.');
$report = $response['evaluation_report'] ?? [];
wrapper_assert($report !== [], 'The response exposes its actual evaluation report.');
foreach (['has_evidence_package', 'has_evidence_walk', 'has_report_plan', 'integrity_ok', 'answer_present'] as $key) {
    wrapper_same(true, $report[$key] ?? null, 'The evaluation reports real runtime field ' . $key);
}
wrapper_same(count($response['evidence_package']['claims']), $report['claim_count'], 'Evaluation claim count comes from the real package.');
wrapper_same(count($response['evidence_walk']['walk_steps']), $report['walk_step_count'], 'Evaluation walk count comes from the real walk.');
wrapper_same(count($response['report_plan']['sections']), $report['report_section_count'], 'Evaluation section count comes from the real report plan.');
wrapper_same($response['models'], $report['models'], 'Evaluation preserves the actual shared-model metadata.');
wrapper_same(['shared' => 'deepseek-flash'], $report['models'], 'Evaluation cannot imply a separate writer or polisher model.');
wrapper_same(false, $report['writing_failed'], 'Evaluation retains the actual writing outcome.');
wrapper_cleanup($session);

$badIntegrity = $response;
$badIntegrity['integrity_report'] = ['final' => ['ok' => false], 'user_facing' => ['ok' => true]];
wrapper_same(false, ModeComparisonEvaluation::fromAgentResponse($badIntegrity)['integrity_ok'], 'An invalid final report cannot receive a passing evaluation.');
$badPresentation = $response;
$badPresentation['integrity_report'] = ['final' => ['ok' => true], 'user_facing' => ['ok' => false]];
wrapper_same(false, ModeComparisonEvaluation::fromAgentResponse($badPresentation)['integrity_ok'], 'A failed user-facing audit cannot receive a passing evaluation.');
$legacyIntegrity = $response;
$legacyIntegrity['integrity_report'] = ['draft' => ['ok' => true]];
wrapper_same(true, ModeComparisonEvaluation::fromAgentResponse($legacyIntegrity)['integrity_ok'], 'Historical draft-only reports remain readable through the evaluator.');
$failedResponse = $response;
$failedResponse['answer'] = '';
$failedResponse['writing_failed'] = true;
$failedResponse['failure_stage'] = 'Writing';
$failedReport = ModeComparisonEvaluation::fromAgentResponse($failedResponse);
wrapper_same(false, $failedReport['answer_present'], 'The evaluator does not treat a missing answer as present.');
wrapper_same(true, $failedReport['writing_failed'], 'The evaluator preserves failure instead of hiding it.');
wrapper_same('Writing', $failedReport['failure_stage'], 'The evaluator preserves the failed stage.');
echo "Agent evaluation report native-loop runtime tests passed.\n";
