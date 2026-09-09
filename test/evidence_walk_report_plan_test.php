<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/contracts/EvidenceWalk.php';
require_once __DIR__ . '/../api/agent/contracts/ReportPlan.php';

$evidenceWalkSchema = require __DIR__ . '/../api/agent/config/evidence_walk_schema.php';
$reportPlanSchema = require __DIR__ . '/../api/agent/config/report_plan_schema.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_has_keys(array $required, array $actual, string $caseName): void
{
    foreach ($required as $key) {
        assert_true(array_key_exists($key, $actual), "{$caseName}: missing {$key}");
    }
}

assert_same(
    ['schema_version', 'generated_at', 'walk_steps', 'claim_nodes', 'support_edges', 'citation_refs', 'route_refs', 'gaps', 'coverage_metrics'],
    $evidenceWalkSchema['required'],
    'evidence walk schema required keys'
);
assert_same(
    ['schema_version', 'question', 'report_type', 'generated_at', 'sections', 'claim_sequence', 'citation_policy', 'gap_policy', 'coverage_metrics'],
    $reportPlanSchema['required'],
    'report plan schema required keys'
);

$literaturePackage = [
    'schema_version' => 'evidence_package.v1',
    'question' => 'What papers support LINE-1 and Alzheimer disease?',
    'generated_at' => '2026-05-26T00:00:00+00:00',
    'claims' => [
        [
            'id' => 'claim_1',
            'text' => 'LINE-1 activation is reported in Alzheimer disease tissue.',
            'source_plugin' => 'Literature Plugin',
            'intent' => 'literature',
            'status' => 'partial',
            'confidence' => 0.82,
            'evidence_ids' => ['evidence_1'],
            'citation_ids' => ['citation_1'],
            'route_ids' => [],
        ],
    ],
    'evidence_items' => [
        [
            'id' => 'evidence_1',
            'claim_id' => 'claim_1',
            'plugin' => 'Literature Plugin',
            'intent' => 'literature',
            'text' => 'LINE-1 activation is reported in Alzheimer disease tissue.',
            'support_strength' => 'high',
            'raw' => ['pmid' => '12345'],
        ],
    ],
    'citation_map' => [
        [
            'id' => 'citation_1',
            'claim_id' => 'claim_1',
            'plugin' => 'Literature Plugin',
            'citation' => ['pmid' => '12345', 'title' => 'LINE-1 and Alzheimer disease'],
        ],
    ],
    'route_map' => [],
    'metrics' => [
        'plugin_count' => 1,
        'claim_count' => 1,
        'evidence_count' => 1,
        'citation_count' => 1,
        'route_count' => 0,
        'empty_plugin_count' => 0,
        'failed_plugin_count' => 0,
        'statuses' => ['Literature Plugin' => 'partial'],
    ],
    'limits' => ['summary_max_chars' => 640, 'truncation_count' => 0, 'truncated_summaries' => []],
    'errors' => [],
];

$literatureWalk = EvidenceWalk::fromEvidencePackage(
    $literaturePackage,
    ['intent' => 'literature', 'task_complexity' => 'complex'],
    ['selected_plugins' => ['Literature Plugin']],
    ['status' => 'partial']
);
assert_has_keys($evidenceWalkSchema['required'], $literatureWalk, 'literature evidence walk');
assert_same('evidence_walk.v1', $literatureWalk['schema_version'], 'evidence walk schema version');
assert_same('walk_step_1', $literatureWalk['walk_steps'][0]['id'], 'deterministic walk step id');
assert_same('claim_node_1', $literatureWalk['claim_nodes'][0]['id'], 'deterministic claim node id');
assert_same('citation_ref_1', $literatureWalk['citation_refs'][0]['id'], 'deterministic citation ref id');
assert_same('citation_1', $literatureWalk['walk_steps'][0]['citation_refs'][0], 'walk step keeps citation ref');
assert_same(1, $literatureWalk['coverage_metrics']['citation_ref_count'], 'citation ref counted');
assert_same(true, EvidenceWalk::validate($literatureWalk)['ok'], 'literature walk validates');

$routePackage = $literaturePackage;
$routePackage['claims'][0]['route_ids'] = ['route_1'];
$routePackage['route_map'] = [
    [
        'id' => 'route_1',
        'claim_id' => 'claim_1',
        'plugin' => 'Site Navigator Plugin',
        'route' => ['label' => 'Graph preview', 'url' => '/TE-/preview.php?q=LINE-1'],
    ],
];
$routePackage['metrics']['route_count'] = 1;
$routeWalk = EvidenceWalk::fromEvidencePackage($routePackage, ['intent' => 'navigation']);
assert_same('route_ref_1', $routeWalk['route_refs'][0]['id'], 'deterministic route ref id');
assert_same('route_1', $routeWalk['walk_steps'][0]['route_refs'][0], 'walk step keeps route ref');
assert_same(1, $routeWalk['coverage_metrics']['route_ref_count'], 'route ref counted');

$emptyPackage = $literaturePackage;
$emptyPackage['claims'] = [];
$emptyPackage['evidence_items'] = [];
$emptyPackage['citation_map'] = [];
$emptyPackage['route_map'] = [];
$emptyPackage['metrics']['claim_count'] = 0;
$emptyPackage['metrics']['evidence_count'] = 0;
$emptyPackage['metrics']['citation_count'] = 0;
$emptyPackage['metrics']['route_count'] = 0;
$emptyPackage['metrics']['empty_plugin_count'] = 1;
$emptyWalk = EvidenceWalk::fromEvidencePackage($emptyPackage, ['intent' => 'sequence'], [], ['required_evidence' => ['sequence']]);
assert_same([], $emptyWalk['walk_steps'], 'empty package creates no walk steps');
assert_same('gap_1', $emptyWalk['gaps'][0]['id'], 'empty package creates deterministic gap');
assert_same('no_claims', $emptyWalk['gaps'][0]['type'], 'empty package gap type');
assert_same(false, $emptyWalk['coverage_metrics']['has_minimum_evidence'], 'empty package lacks minimum evidence');

$mechanismPlan = ReportPlan::fromEvidenceWalk(
    'How does LINE-1 promote cancer?',
    ['intent' => 'mechanism', 'task_complexity' => 'complex'],
    $literatureWalk,
    ['preferred_report_type' => 'mechanism_review']
);
assert_has_keys($reportPlanSchema['required'], $mechanismPlan, 'mechanism report plan');
assert_same('report_plan.v1', $mechanismPlan['schema_version'], 'report plan schema version');
assert_same('mechanism_review', $mechanismPlan['report_type'], 'mechanism report type');
assert_same(['background', 'mechanism_chain', 'evidence_review', 'limitations', 'answer'], array_column($mechanismPlan['sections'], 'key'), 'mechanism sections');
assert_same('claim_node_1', $mechanismPlan['claim_sequence'][0]['claim_node_id'], 'claim sequence uses claim node');
assert_same('inline_required', $mechanismPlan['citation_policy']['mode'], 'mechanism citations required');
assert_same(true, ReportPlan::validate($mechanismPlan)['ok'], 'mechanism plan validates');

$analyticsPlan = ReportPlan::fromEvidenceWalk(
    'Which disease has the highest graph association with transposable elements?',
    ['intent' => 'graph_analytics', 'task_complexity' => 'complex'],
    $routeWalk
);
assert_same('graph_ranking', $analyticsPlan['report_type'], 'graph analytics report type');
assert_same(['question_scope', 'ranking_method', 'top_entities', 'evidence_paths', 'caveats', 'answer'], array_column($analyticsPlan['sections'], 'key'), 'graph analytics sections');

$invalidWalk = $literatureWalk;
unset($invalidWalk['walk_steps'][0]['id']);
$invalidWalkValidation = EvidenceWalk::validate($invalidWalk);
assert_same(false, $invalidWalkValidation['ok'], 'invalid walk fails validation');
assert_true(in_array('walk_steps[0].id is required', $invalidWalkValidation['errors'], true), 'invalid walk reports missing step id');

$invalidPlan = $mechanismPlan;
$invalidPlan['report_type'] = 'unsupported';
$invalidPlan['sections'] = [];
$invalidPlanValidation = ReportPlan::validate($invalidPlan);
assert_same(false, $invalidPlanValidation['ok'], 'invalid plan fails validation');
assert_true(in_array('report_type must be one of mechanism_review, evidence_audit, batch_comparison, graph_ranking, research_report', $invalidPlanValidation['errors'], true), 'invalid plan reports report type');
assert_true(in_array('sections must contain at least one section', $invalidPlanValidation['errors'], true), 'invalid plan reports sections');

echo "EvidenceWalk and ReportPlan tests passed.\n";
