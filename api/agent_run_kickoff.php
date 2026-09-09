<?php
declare(strict_types=1);

require_once __DIR__ . '/agent_run_execute.php';

$remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
if (!in_array($remoteAddr, ['127.0.0.1', '::1', ''], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Forbidden.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$runId = trim((string)($_GET['run_id'] ?? $_POST['run_id'] ?? ''));
if ($runId === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'run_id is required.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

ignore_user_abort(true);
@set_time_limit((int)(tekg_agent_config()['agent_execution_timeout'] ?? 300) + 30);

$exitCode = tekg_agent_execute_run($runId);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => $exitCode === 0,
    'run_id' => $runId,
    'exit_code' => $exitCode,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit($exitCode);
