<?php
declare(strict_types=1);
require_once __DIR__ . '/variant_repository.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
function tekg_variants_out(int $status, array $payload, bool $cache = false): never { http_response_code($status); header($cache ? 'Cache-Control: public, max-age=120' : 'Cache-Control: no-store, max-age=0'); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function tekg_variants_param(string $key, string $default = ''): string { $value = $_GET[$key] ?? $default; return is_scalar($value) ? trim((string)$value) : $default; }
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'GET') tekg_variants_out(405, ['ok' => false, 'error' => ['code' => 'method_not_allowed', 'message' => 'Only GET and OPTIONS requests are supported.']]);
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$pageSize = filter_var($_GET['page_size'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 100]]) ?: 10;
try {
    $payload = tekg_variant_load(tekg_variants_param('te'), tekg_variants_param('source', 'eqtl'), tekg_variants_param('view', 'variant'), $page, $pageSize);
    tekg_variants_out(200, ['ok' => true] + $payload, true);
} catch (TeVariantRepositoryException $e) {
    $status = match ($e->codeName()) { 'unknown_te' => 404, 'invalid_source', 'invalid_view' => 400, default => 500 };
    tekg_variants_out($status, ['ok' => false, 'error' => ['code' => $e->codeName(), 'message' => $e->getMessage()] + $e->details()]);
} catch (Throwable) {
    tekg_variants_out(500, ['ok' => false, 'error' => ['code' => 'data_contract_error', 'message' => 'Variant data could not be served.']]);
}
