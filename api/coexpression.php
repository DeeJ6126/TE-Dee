<?php
declare(strict_types=1);

require_once __DIR__ . '/coexpression_repository.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

function tekg_coexpression_api_response(int $status, array $payload, bool $cacheable = false): never
{
    http_response_code($status);
    header($cacheable ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tekg_coexpression_api_error(int $status, string $code, string $message, array $details = []): never
{
    $error = ['code' => $code, 'message' => $message];
    if (isset($details['available_contexts']) && is_array($details['available_contexts'])) {
        $error['available_contexts'] = array_values($details['available_contexts']);
    }

    tekg_coexpression_api_response($status, ['ok' => false, 'error' => $error]);
}

function tekg_coexpression_api_query_string(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Cache-Control: no-store, max-age=0');
    exit;
}
if ($method !== 'GET') {
    tekg_coexpression_api_error(405, 'method_not_allowed', 'Only GET and OPTIONS requests are supported.');
}

$action = tekg_coexpression_api_query_string('action');
if (!in_array($action, ['catalog', 'network'], true)) {
    tekg_coexpression_api_error(400, 'invalid_action', 'The requested co-expression action is invalid.');
}

try {
    if ($action === 'catalog') {
        tekg_coexpression_api_response(200, ['ok' => true] + tekg_coexpression_catalog(), true);
    }

    $featureType = tekg_coexpression_api_query_string('feature_type');
    $featureType = strcasecmp($featureType, 'Gene') === 0 ? 'Gene' : 'TE';
    $feature = $featureType === 'Gene'
        ? tekg_coexpression_api_query_string('gene')
        : tekg_coexpression_api_query_string('te');
    $context = tekg_coexpression_api_query_string('context');
    if (!array_key_exists($context, TEKG_COEXPRESSION_CONTEXTS)) {
        tekg_coexpression_api_error(400, 'invalid_context', 'The requested co-expression context is invalid.');
    }
    if ($feature === '') {
        $code = $featureType === 'Gene' ? 'unknown_gene' : 'unknown_te';
        tekg_coexpression_api_error(404, $code, "The requested {$featureType} is not present in the approved co-expression catalog.");
    }

    tekg_coexpression_api_response(200, ['ok' => true] + tekg_coexpression_load_feature_network($feature, $featureType, $context), true);
} catch (CoexpressionRepositoryException $exception) {
    $status = match ($exception->errorCode()) {
        'invalid_context' => 400,
        'unknown_te', 'unknown_gene', 'network_unavailable' => 404,
        default => 500,
    };
    $code = in_array($exception->errorCode(), ['invalid_context', 'unknown_te', 'unknown_gene', 'network_unavailable'], true)
        ? $exception->errorCode()
        : 'data_contract_error';
    tekg_coexpression_api_error($status, $code, $exception->getMessage(), $exception->details());
} catch (Throwable) {
    tekg_coexpression_api_error(500, 'data_contract_error', 'The approved co-expression data could not be served.');
}
