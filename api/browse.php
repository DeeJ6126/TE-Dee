<?php
declare(strict_types=1);

require_once __DIR__ . '/browse_repository.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, max-age=0');

function tekg_browse_api_response(int $status, array $payload): never
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
    tekg_browse_api_response(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$view = trim((string)($_GET['view'] ?? 'items'));
if ($view !== 'items') {
    tekg_browse_api_response(400, ['ok' => false, 'error' => 'Unsupported Browse view']);
}

try {
    $catalog = tekg_browse_fetch_active_catalog();
    tekg_browse_api_response(200, [
        'ok' => true,
        'source' => 'mysql',
        'catalog' => [
            'version' => $catalog['version'],
            'importedAt' => $catalog['importedAt'],
            'rowCount' => $catalog['rowCount'],
            'sourceHash' => $catalog['sourceHash'],
        ],
        'items' => $catalog['items'],
    ]);
} catch (Throwable) {
    http_response_code(503);
    tekg_browse_api_response(503, ['ok' => false, 'error' => 'Catalog unavailable']);
}
