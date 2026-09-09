<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/contracts/ModeComparisonEvaluation.php';
require_once __DIR__ . '/../api/agent/bootstrap.php';

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

$serviceSource = (string)file_get_contents(__DIR__ . '/../api/agent/orchestrator/AcademicAgentService.php');
$bootstrapSource = (string)file_get_contents(__DIR__ . '/../api/agent/bootstrap.php');

assert_contains("contracts/ModeComparisonEvaluation.php", $serviceSource, 'AcademicAgentService requires ModeComparisonEvaluation');
assert_contains("\$response['evaluation_report'] = \$evaluationReport", $serviceSource, 'Agent response includes evaluation_report');
assert_contains('ModeComparisonEvaluation::fromAgentResponse(', $serviceSource, 'Agent runtime builds evaluation_report from same response artifacts');
assert_contains("'agent_polisher_model'", $bootstrapSource, 'bootstrap resolves agent_polisher_model');
assert_contains('TEKG_AGENT_POLISHER_MODEL', $bootstrapSource, 'bootstrap supports polisher model env override');
assert_contains("'agent_writing_model' => trim((string)(\$local['agent_writing_model'] ?? tekg_agent_env_value(['TEKG_AGENT_WRITING_MODEL'], 'deepseek-v4-pro')))", $bootstrapSource, 'bootstrap default policy keeps Agent writer configurable with stable pro fallback');
assert_contains("'agent_polisher_model' => trim((string)(\$local['agent_polisher_model'] ?? tekg_agent_env_value(['TEKG_AGENT_POLISHER_MODEL'], \$controlModel)))", $bootstrapSource, 'bootstrap default policy keeps polisher configurable and defaults to the control model');
assert_contains("'agent_polisher_enabled' => tekg_agent_bool_value(\$local['agent_polisher_enabled'] ?? tekg_agent_env_value(['TEKG_AGENT_POLISHER_ENABLED'], 'false'), false)", $bootstrapSource, 'bootstrap default policy keeps the Agent polisher disabled');
assert_not_contains('config.local.php', $serviceSource, 'AcademicAgentService must not depend on private local config paths');

$agentResponse = [
    'question' => 'LINE-1 是如何导致癌症的？',
    'analysis' => ['intent' => 'mechanism', 'task_complexity' => 'research_synthesis'],
    'answer' => 'Evidence-walk answer.',
    'used_plugins' => ['Graph Plugin', 'Literature Plugin', 'Literature Reading Plugin'],
    'citations' => [['pmid' => '12345']],
    'evidence_package' => [
        'claims' => [['id' => 'claim_1']],
        'citation_map' => [['id' => 'citation_1']],
        'route_map' => [['id' => 'route_1']],
    ],
    'evidence_walk' => [
        'walk_steps' => [['id' => 'walk_step_1']],
        'claim_nodes' => [['id' => 'claim_node_1']],
        'support_edges' => [['id' => 'support_edge_1']],
    ],
    'report_plan' => ['sections' => [['title' => 'Mechanism Chain']]],
    'integrity_report' => ['draft' => ['ok' => true], 'polish' => ['ok' => true], 'warnings' => []],
    'writing_failed' => false,
    'models' => ['writer_draft' => 'deepseek-v4-flash', 'writer_polisher' => 'deepseek-v4-flash'],
    'timings' => ['writing_ms' => 7000],
];
$report = ModeComparisonEvaluation::fromAgentResponse($agentResponse, ['expected_best_mode' => 'agent']);
assert_true($report['has_evidence_package'] === true, 'fromAgentResponse detects evidence_package');
assert_true($report['has_evidence_walk'] === true, 'fromAgentResponse detects evidence_walk');
assert_true($report['has_report_plan'] === true, 'fromAgentResponse detects report_plan');
assert_true($report['integrity_ok'] === true, 'fromAgentResponse detects integrity status');

echo "Agent evaluation report runtime tests passed.\n";
