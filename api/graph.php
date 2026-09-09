<?php
declare(strict_types=1);

require_once __DIR__ . '/taxonomy_lib.php';
require_once __DIR__ . '/runtime_config.php';
require_once __DIR__ . '/graph_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $localConfig = tekg_runtime_load_local_config();
    $config = array_merge(tekg_runtime_neo4j_config($localConfig), [
        'key_node_threshold' => (int)tekg_runtime_pick($localConfig, 'key_node_threshold', ['KEY_NODE_THRESHOLD_BIOLOGY', 'KEY_NODE_THRESHOLD'], '15'),
        'key_node_expand_limit' => (int)tekg_runtime_pick($localConfig, 'key_node_expand_limit', ['KEY_NODE_EXPAND_LIMIT_BIOLOGY', 'KEY_NODE_EXPAND_LIMIT'], '15'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'PHP cURL extension is required'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($config['neo4j_password'] === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Neo4j password is not configured'], JSON_UNESCAPED_UNICODE);
    exit;
}

$query = trim((string)($_GET['q'] ?? ''));
$queryType = trim((string)($_GET['type'] ?? ''));
$classQuery = trim((string)($_GET['class'] ?? ''));
$expandNodeId = trim((string)($_GET['expand_node_id'] ?? ''));
$expandNodeType = trim((string)($_GET['expand_node_type'] ?? ''));
$expandQuery = trim((string)($_GET['expand_query'] ?? ''));
$keyLevel = max(1, min(10, (int)($_GET['key_level'] ?? 1)));

if ($query === '' && strcasecmp($queryType, 'disease_class') === 0 && $classQuery !== '') {
    $query = $classQuery;
}

if ($query === '' && $expandQuery !== '') {
    $query = $expandQuery;
}

if ($query === '') {
    $query = 'LINE1';
}

try {
    $service = new GraphService($config);
    $payload = $service->search($query, $keyLevel, $queryType, $classQuery, [
        'expand_node_id' => $expandNodeId,
        'expand_node_type' => $expandNodeType,
        'expand_query' => $expandQuery,
    ]);
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
