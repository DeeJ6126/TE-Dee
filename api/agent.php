<?php
declare(strict_types=1);

require_once __DIR__ . '/agent/bootstrap.php';
require_once __DIR__ . '/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    tekg_agent_json_response(200, ['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tekg_agent_json_response(405, ['ok' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
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
    $response = $service->handle($payload);
    tekg_agent_json_response(200, ['ok' => true, 'data' => $response]);
} catch (Throwable $error) {
    if (!empty($requestId)) {
        tekg_agent_append_diagnostic_log($requestId, 'api_exception', [
            'error' => $error->getMessage(),
        ]);
    }
    tekg_agent_json_response(500, ['ok' => false, 'error' => $error->getMessage()]);
}
