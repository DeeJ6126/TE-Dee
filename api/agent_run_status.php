<?php
declare(strict_types=1);

require_once __DIR__ . '/agent/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    tekg_agent_json_response(405, [
        'ok' => false,
        'error' => 'Method not allowed. Use GET.',
    ]);
    exit;
}

$runId = trim((string)($_GET['run_id'] ?? ''));
if ($runId === '') {
    tekg_agent_json_response(400, [
        'ok' => false,
        'error' => 'run_id is required.',
    ]);
    exit;
}

$after = max(0, (int)($_GET['after'] ?? 0));
$state = tekg_agent_load_run_state($runId);
if (!is_array($state)) {
    tekg_agent_json_response(404, [
        'ok' => false,
        'error' => 'Run not found.',
    ]);
    exit;
}
$state = tekg_agent_mark_stale_run_if_needed($runId, $state, tekg_agent_config());

tekg_agent_json_response(200, [
    'ok' => true,
    'run' => $state,
    'events' => tekg_agent_load_run_events($runId, $after),
]);
