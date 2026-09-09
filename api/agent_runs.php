<?php
declare(strict_types=1);

require_once __DIR__ . '/agent/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tekg_agent_json_response(405, [
        'ok' => false,
        'error' => 'Method not allowed. Use POST.',
    ]);
    exit;
}

try {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid JSON body.');
    }

    $question = trim((string)($payload['question'] ?? ''));
    if ($question === '') {
        throw new InvalidArgumentException('Question is required.');
    }

    $runId = tekg_agent_make_run_id();
    $requestId = trim((string)($payload['request_id'] ?? ''));
    if ($requestId === '') {
        $requestId = tekg_agent_make_request_id();
    }
    $sessionId = trim((string)($payload['session_id'] ?? ''));
    if ($sessionId === '') {
        $sessionId = tekg_agent_make_session_id();
    }

    $runPayload = $payload;
    $runPayload['request_id'] = $requestId;
    $runPayload['session_id'] = $sessionId;
    $runPayload['mode'] = trim((string)($payload['mode'] ?? 'academic')) ?: 'academic';

    tekg_agent_save_run_payload($runId, $runPayload);
    $state = tekg_agent_create_run_state($runId, $runPayload);
    tekg_agent_save_run_state($runId, $state);

    $kickoffPath = tekg_agent_run_kickoff_path($runId);
    $spawned = tekg_agent_fire_and_forget_local_request($kickoffPath);
    if (!$spawned) {
        $workerScript = __DIR__ . '/agent_run_worker.php';
        $spawned = tekg_agent_spawn_background_php([$workerScript, '--run-id=' . $runId]);
    }
    if (!$spawned) {
        $state['status'] = 'failed';
        $state['error'] = 'The background worker could not be started.';
        $state['failure_reason'] = 'The background worker could not be started.';
        $state['presentation_failure_reason'] = tekg_agent_run_presentation_failure_message('Startup', (string)($state['language'] ?? ''));
        $state['finished_at'] = gmdate('c');
        tekg_agent_save_run_state($runId, $state);
        tekg_agent_json_response(500, [
            'ok' => false,
            'error' => 'The background worker could not be started.',
            'presentation_failure_reason' => $state['presentation_failure_reason'],
            'language' => (string)($state['language'] ?? ''),
            'run_id' => $runId,
            'request_id' => $requestId,
            'session_id' => $sessionId,
        ]);
        exit;
    }

    if (!tekg_agent_wait_for_run_start($runId, 12000)) {
        $state = tekg_agent_load_run_state($runId) ?? $state;
        $state['status'] = 'failed';
        $reason = trim((string)($state['failure_reason'] ?? $state['error'] ?? ''));
        if ($reason === '') {
            $reason = 'The Agent worker did not acknowledge startup in time.';
        }
        $state['error'] = $reason;
        $state['failure_reason'] = $reason;
        $state['presentation_failure_reason'] = tekg_agent_run_presentation_failure_message('Startup', (string)($state['language'] ?? ''));
        $state['finished_at'] = gmdate('c');
        tekg_agent_save_run_state($runId, $state);
        tekg_agent_json_response(500, [
            'ok' => false,
            'error' => $reason,
            'presentation_failure_reason' => $state['presentation_failure_reason'],
            'language' => (string)($state['language'] ?? ''),
            'run_id' => $runId,
            'request_id' => $requestId,
            'session_id' => $sessionId,
        ]);
        exit;
    }

    tekg_agent_json_response(202, [
        'ok' => true,
        'run_id' => $runId,
        'request_id' => $requestId,
        'session_id' => $sessionId,
        'status' => 'pending',
    ]);
} catch (Throwable $error) {
    tekg_agent_json_response(400, [
        'ok' => false,
        'error' => $error->getMessage(),
    ]);
}
