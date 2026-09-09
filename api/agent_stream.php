<?php
declare(strict_types=1);

require_once __DIR__ . '/agent/bootstrap.php';
require_once __DIR__ . '/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
header('Content-Encoding: none');
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

$emit = static function (array $event): void {
    echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush();
    @flush();
};

$requestId = null;
register_shutdown_function(static function () use (&$requestId, $emit): void {
    $lastError = error_get_last();
    if (!is_array($lastError)) {
        return;
    }
    $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];
    if (!in_array((int)($lastError['type'] ?? 0), $fatalTypes, true)) {
        return;
    }
    if ($requestId !== null) {
        tekg_agent_append_diagnostic_log($requestId, 'shutdown_fatal', [
            'type' => (int)($lastError['type'] ?? 0),
            'message' => (string)($lastError['message'] ?? ''),
            'file' => (string)($lastError['file'] ?? ''),
            'line' => (int)($lastError['line'] ?? 0),
        ]);
    }
    $emit([
        'type' => 'error',
        'request_id' => $requestId,
        'message' => 'The request terminated unexpectedly before the final answer could be delivered.',
    ]);
    $emit([
        'type' => 'done',
        'request_id' => $requestId,
        'payload' => ['failed' => true],
    ]);
});

echo ':' . str_repeat(' ', 2048) . "\n\n";
@ob_flush();
@flush();

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid JSON body.');
    }
    $requestId = trim((string)($payload['request_id'] ?? ''));
    if ($requestId === '') {
        $requestId = tekg_agent_make_request_id();
        $payload['request_id'] = $requestId;
    }

    $service = new TekgAcademicAgentService(tekg_agent_config());
    $service->stream($payload, $emit);
} catch (Throwable $error) {
    if ($requestId !== null) {
        tekg_agent_append_diagnostic_log($requestId, 'stream_exception', [
            'error' => $error->getMessage(),
        ]);
    }
    $emit([
        'type' => 'error',
        'request_id' => $requestId,
        'message' => $error->getMessage(),
    ]);
    $emit([
        'type' => 'done',
        'request_id' => $requestId,
        'payload' => ['failed' => true],
    ]);
}
