<?php
declare(strict_types=1);

require_once __DIR__ . '/expression_repository.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, max-age=0');

function tekg_expression_catalog_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET') {
    tekg_expression_catalog_response(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$view = trim((string)($_GET['view'] ?? 'items'));
if ($view !== 'items') {
    tekg_expression_catalog_response(400, ['ok' => false, 'error' => 'Unsupported Expression catalog view']);
}

try {
    $query = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 180);
    $items = tekg_expression_fetch_catalog_items($query, $limit);
    tekg_expression_catalog_response(200, [
        'ok' => true,
        'source' => 'mysql',
        'query' => $query,
        'count' => count($items),
        'items' => $items,
    ]);
} catch (Throwable) {
    tekg_expression_catalog_response(503, [
        'ok' => false,
        'error' => 'Expression catalog unavailable',
    ]);
}
