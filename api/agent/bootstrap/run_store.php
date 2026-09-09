<?php
declare(strict_types=1);

function tekg_agent_pubmed_cache_dir(): string
{
    return tekg_agent_ensure_dir(TEKG_DATA_FS_DIR . '/cache/agent/pubmed');
}

function tekg_agent_session_cache_dir(): string
{
    return tekg_agent_ensure_dir(TEKG_DATA_FS_DIR . '/cache/agent/sessions');
}

function tekg_agent_diagnostics_dir(): string
{
    return tekg_agent_ensure_dir(TEKG_DATA_FS_DIR . '/cache/agent/diagnostics');
}

function tekg_agent_runs_dir(): string
{
    return tekg_agent_ensure_dir(TEKG_DATA_FS_DIR . '/cache/agent/runs');
}

function tekg_agent_make_run_id(): string
{
    try {
        return 'run_' . bin2hex(random_bytes(8));
    } catch (Throwable $_) {
        return 'run_' . md5((string)microtime(true) . '::' . (string)mt_rand());
    }
}

function tekg_agent_run_dir(string $runId): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $runId);
    return tekg_agent_ensure_dir(rtrim(tekg_agent_runs_dir(), '/\\') . '/' . $safe);
}

function tekg_agent_run_state_file(string $runId): string
{
    return tekg_agent_run_dir($runId) . '/state.json';
}

function tekg_agent_run_events_file(string $runId): string
{
    return tekg_agent_run_dir($runId) . '/events.jsonl';
}

function tekg_agent_run_payload_file(string $runId): string
{
    return tekg_agent_run_dir($runId) . '/payload.json';
}

function tekg_agent_default_workflow_state(): array
{
    return [
        'current_stage' => '',
        'stage_statuses' => [
            'Understanding' => 'pending',
            'Planning' => 'pending',
            'Collecting' => 'pending',
            'Executing' => 'pending',
            'Integrating' => 'pending',
            'Writing' => 'pending',
        ],
        'traversed_edges' => [],
        'complete' => false,
    ];
}

function tekg_agent_create_run_state(string $runId, array $payload): array
{
    $language = tekg_agent_detect_language($payload, (string)($payload['question'] ?? ''));
    return [
        'run_id' => $runId,
        'request_id' => (string)($payload['request_id'] ?? ''),
        'session_id' => (string)($payload['session_id'] ?? ''),
        'question' => (string)($payload['question'] ?? ''),
        'mode' => trim((string)($payload['mode'] ?? 'academic')) ?: 'academic',
        'status' => 'pending',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'started_at' => null,
        'finished_at' => null,
        'current_stage' => '',
        'workflow_state' => tekg_agent_default_workflow_state(),
        'events_count' => 0,
        'last_sequence' => 0,
        'answer' => '',
        'language' => $language,
        'writing_failed' => false,
        'failure_stage' => '',
        'failure_reason' => '',
        'presentation_failure_reason' => '',
        'error' => '',
        'used_plugins' => [],
    ];
}

function tekg_agent_run_presentation_failure_message(string $stage, string $language): string
{
    return tekg_agent_detect_language(['language' => $language], '') === 'chinese'
        ? $stage . ' 阶段失败，未生成学术回答。'
        : $stage . ' failed, so no academic answer was generated.';
}

function tekg_agent_save_run_state(string $runId, array $state): void
{
    $path = tekg_agent_run_state_file($runId);
    $state['updated_at'] = gmdate('c');
    file_put_contents($path, json_encode(tekg_agent_json_safe($state), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function tekg_agent_load_run_state(string $runId): ?array
{
    $path = tekg_agent_run_state_file($runId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function tekg_agent_save_run_payload(string $runId, array $payload): void
{
    $path = tekg_agent_run_payload_file($runId);
    file_put_contents($path, json_encode(tekg_agent_json_safe($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function tekg_agent_load_run_payload(string $runId): ?array
{
    $path = tekg_agent_run_payload_file($runId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function tekg_agent_append_run_event(string $runId, array $event): void
{
    $path = tekg_agent_run_events_file($runId);
    @file_put_contents($path, json_encode(tekg_agent_json_safe($event), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function tekg_agent_load_run_events(string $runId, int $afterSequence = 0): array
{
    $path = tekg_agent_run_events_file($runId);
    if (!is_file($path)) {
        return [];
    }
    $events = [];
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return [];
    }
    try {
        while (($line = @fgets($handle)) !== false) {
            $decoded = json_decode(trim($line), true);
            if (!is_array($decoded)) {
                continue;
            }
            if ((int)($decoded['sequence'] ?? 0) <= $afterSequence) {
                continue;
            }
            $events[] = $decoded;
        }
    } finally {
        fclose($handle);
    }
    return $events;
}

function tekg_agent_update_run_state_for_event(array $state, array $event): array
{
    $state['request_id'] = (string)($event['request_id'] ?? $state['request_id'] ?? '');
    $state['session_id'] = (string)($event['session_id'] ?? $state['session_id'] ?? '');
    $state['events_count'] = (int)($state['events_count'] ?? 0) + 1;
    $state['last_sequence'] = max((int)($state['last_sequence'] ?? 0), (int)($event['sequence'] ?? 0));

    $type = (string)($event['type'] ?? '');
    if ($type === 'stage_state' && is_array($event['payload'] ?? null)) {
        $state['workflow_state'] = tekg_agent_json_safe((array)$event['payload']);
        $state['current_stage'] = (string)($state['workflow_state']['current_stage'] ?? '');
    }
    if ($type === 'answer') {
        $state['answer'] = trim((string)($event['message'] ?? ''));
        $state['language'] = (string)($event['language'] ?? $state['language'] ?? '');
    }
    if ($type === 'error') {
        $state['error'] = trim((string)($event['message'] ?? ''));
        $payload = is_array($event['payload'] ?? null) ? (array)$event['payload'] : [];
        if ((bool)($payload['writing_failed'] ?? false)) {
            $state['writing_failed'] = true;
            $state['failure_stage'] = (string)($payload['failure_stage'] ?? 'Writing');
            $state['failure_reason'] = (string)($payload['failure_reason'] ?? $state['error']);
            $state['presentation_failure_reason'] = (string)($payload['presentation_failure_reason'] ?? $state['error']);
        }
    }
    if ($type === 'done' && is_array($event['payload'] ?? null)) {
        $payload = (array)$event['payload'];
        $state['answer'] = trim((string)($payload['answer'] ?? $state['answer'] ?? ''));
        $state['language'] = (string)($payload['language'] ?? $state['language'] ?? '');
        $state['used_plugins'] = array_values(array_map('strval', (array)($payload['used_plugins'] ?? $state['used_plugins'] ?? [])));
        $state['writing_failed'] = (bool)($payload['writing_failed'] ?? $state['writing_failed'] ?? false);
        $state['failure_stage'] = (string)($payload['failure_stage'] ?? $state['failure_stage'] ?? '');
        $state['failure_reason'] = (string)($payload['failure_reason'] ?? $state['failure_reason'] ?? '');
        $state['presentation_failure_reason'] = (string)($payload['presentation_failure_reason'] ?? $state['presentation_failure_reason'] ?? '');
        if (is_array($payload['workflow_state'] ?? null)) {
            $state['workflow_state'] = tekg_agent_json_safe((array)$payload['workflow_state']);
            $state['current_stage'] = (string)($state['workflow_state']['current_stage'] ?? '');
        }
        $state['status'] = $state['writing_failed'] ? 'failed' : 'completed';
        $state['finished_at'] = gmdate('c');
    }

    return $state;
}

function tekg_agent_mark_stale_run_if_needed(string $runId, array $state, array $config): array
{
    $status = (string)($state['status'] ?? '');
    if (!in_array($status, ['pending', 'running'], true)) {
        return $state;
    }

    $now = time();
    $createdAt = strtotime((string)($state['created_at'] ?? '')) ?: $now;
    $startedAt = strtotime((string)($state['started_at'] ?? '')) ?: null;
    $reason = '';
    $failureStage = '';

    if ($status === 'pending' && $startedAt === null && ($now - $createdAt) > 30) {
        $failureStage = 'Startup';
        $reason = 'The Agent worker did not acknowledge startup within 30 seconds.';
    }

    if ($status === 'running') {
        $runStartedAt = $startedAt ?? $createdAt;
        $timeoutSeconds = max(90, (int)($config['agent_execution_timeout'] ?? 7200)) + 30;
        if (($now - $runStartedAt) > $timeoutSeconds) {
            $failureStage = trim((string)($state['current_stage'] ?? ''));
            if ($failureStage === '') {
                $failureStage = 'Worker';
            }
            $reason = 'The Agent run exceeded the configured execution timeout and was marked stale.';
        }
    }

    if ($reason === '') {
        return $state;
    }

    $state['status'] = 'failed';
    $state['error'] = $reason;
    $state['failure_stage'] = $failureStage;
    $state['failure_reason'] = $reason;
    $state['presentation_failure_reason'] = tekg_agent_run_presentation_failure_message($failureStage, (string)($state['language'] ?? ''));
    $state['finished_at'] = gmdate('c');
    $state['updated_at'] = gmdate('c');
    tekg_agent_save_run_state($runId, $state);

    $requestId = trim((string)($state['request_id'] ?? ''));
    tekg_agent_append_diagnostic_log($requestId !== '' ? $requestId : tekg_agent_make_request_id(), 'agent_run_marked_stale', [
        'run_id' => $runId,
        'status_before' => $status,
        'failure_stage' => $failureStage,
        'reason' => $reason,
    ]);

    return $state;
}

function tekg_agent_cli_php_binary(): string
{
    $candidates = array_values(array_filter([
        tekg_agent_env_value(['PHP_CLI_BINARY'], null),
        preg_replace('/php-cgi\.exe$/i', 'php.exe', PHP_BINARY),
        preg_replace('/php\.exe$/i', 'php.exe', PHP_BINARY),
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe',
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cli.exe',
        'php',
    ], static fn($value): bool => is_string($value) && trim($value) !== ''));

    foreach ($candidates as $candidate) {
        if ($candidate === 'php') {
            return $candidate;
        }
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return 'php';
}

function tekg_agent_spawn_background_php(array $arguments): bool
{
    $phpBinary = tekg_agent_cli_php_binary();
    $commandParts = array_merge([$phpBinary], $arguments);

    if (DIRECTORY_SEPARATOR === '\\') {
        $powerShell = getenv('SystemRoot') !== false
            ? rtrim((string)getenv('SystemRoot'), '/\\') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe'
            : 'powershell.exe';
        if (!is_file($powerShell)) {
            $powerShell = 'powershell.exe';
        }
        $escapedExe = "'" . str_replace("'", "''", $phpBinary) . "'";
        $escapedArgs = array_map(static function (string $part): string {
            return "'" . str_replace("'", "''", $part) . "'";
        }, $arguments);
        $argumentList = '@(' . implode(', ', $escapedArgs) . ')';
        $psCommand = "Start-Process -WindowStyle Hidden -FilePath {$escapedExe} -ArgumentList {$argumentList}";
        $command = '"' . $powerShell . '" -NoProfile -ExecutionPolicy Bypass -Command "' . str_replace('"', '\"', $psCommand) . '"';
        @exec($command, $output, $code);
        if ((int)$code === 0) {
            return true;
        }
        return false;
    }

    $command = implode(' ', array_map('escapeshellarg', $commandParts)) . ' > /dev/null 2>&1 &';
    $process = @popen($command, 'r');
    if (is_resource($process)) {
        @pclose($process);
        return true;
    }
    @exec($command, $output, $code);
    return (int)$code === 0;
}

function tekg_agent_run_kickoff_path(string $runId): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? tekg_api_url('agent_runs.php')));
    $apiDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');
    if ($apiDir === '') {
        $apiDir = '/api';
    }
    return $apiDir . '/agent_run_kickoff.php?run_id=' . rawurlencode($runId);
}

function tekg_agent_fire_and_forget_local_request(string $path): bool
{
    $hostHeader = trim((string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $defaultPort = $https ? 443 : 80;
    $hostForHeader = $hostHeader !== '' ? $hostHeader : '127.0.0.1';
    $socketHost = '127.0.0.1';
    $port = $defaultPort;

    if (strpos($hostForHeader, ':') !== false) {
        [$headerHost, $headerPort] = explode(':', $hostForHeader, 2);
        $hostForHeader = trim($headerHost) !== '' ? trim($headerHost) : '127.0.0.1';
        $port = is_numeric($headerPort) ? (int)$headerPort : $defaultPort;
    }

    $transport = $https ? 'ssl://' : '';
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($transport . $socketHost, $port, $errno, $errstr, 1.5);
    if (!is_resource($fp)) {
        return false;
    }

    stream_set_blocking($fp, false);
    $request =
        "GET {$path} HTTP/1.1\r\n"
        . "Host: {$hostForHeader}" . ($port !== $defaultPort ? ':' . $port : '') . "\r\n"
        . "Connection: Close\r\n"
        . "User-Agent: TEKG-Agent-Kickoff/1.0\r\n\r\n";
    $written = @fwrite($fp, $request);
    @fclose($fp);
    return $written !== false;
}

function tekg_agent_wait_for_run_start(string $runId, int $timeoutMs = 2500): bool
{
    $deadline = microtime(true) + max(100, $timeoutMs) / 1000;
    while (microtime(true) < $deadline) {
        $state = tekg_agent_load_run_state($runId);
        if (is_array($state) && (
            (string)($state['status'] ?? '') === 'running'
            || (int)($state['events_count'] ?? 0) > 0
            || !empty($state['started_at'])
        )) {
            return true;
        }
        usleep(100000);
    }
    return false;
}
