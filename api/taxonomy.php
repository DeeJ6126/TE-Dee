<?php
declare(strict_types=1);

require_once __DIR__ . '/taxonomy_lib.php';

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

$view = trim((string)($_GET['view'] ?? 'items'));
$source = trim((string)($_GET['source'] ?? 'rmsk_repbase'));
$names = tekg_taxonomy_parse_names((string)($_GET['names'] ?? ''));

try {
    if ($view === 'loader_kinds') {
        if (!tekg_taxonomy_is_file_tree_source($source)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unsupported taxonomy Loader source'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $items = tekg_taxonomy_loader_kinds($names, $source);
        echo json_encode([
            'ok' => true,
            'source' => tekg_taxonomy_normalize_tree_source($source),
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($view === 'tree') {
        if (!tekg_taxonomy_is_file_tree_source($source)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unsupported taxonomy tree source'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $payload = [
            'ok' => true,
            'source' => tekg_taxonomy_normalize_tree_source($source),
        ] + tekg_taxonomy_file_tree_payload($source);

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $config = tekg_taxonomy_config();
    $items = tekg_taxonomy_fetch_items($names, $config);
    $payload = [
        'ok' => true,
        'source' => 'tekg3',
        'database' => tekg_taxonomy_database_name($config),
    ];

    if ($view === 'tree') {
        $payload += tekg_taxonomy_tree_payload($items);
    } elseif ($view === 'summary') {
        $payload['summary'] = tekg_taxonomy_summary_payload($items);
    } else {
        $payload['items'] = $items;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
