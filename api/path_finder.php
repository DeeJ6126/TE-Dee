<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_config.php';
require_once __DIR__ . '/path_finder_service.php';

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
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $view = trim((string)($_GET['view'] ?? 'paths'));
    if ($view === 'entity_types') {
        echo json_encode([
            'ok' => true,
            'source' => 'tekg3',
            'entity_types' => path_finder_entity_type_options(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $localConfig = tekg_runtime_load_local_config();
    $config = tekg_runtime_neo4j_config($localConfig);
    if (trim((string)($config['neo4j_password'] ?? '')) === '') {
        throw new RuntimeException('Neo4j password is not configured');
    }

    $service = new PathFinderService($config);

    if ($view === 'entities') {
        $entityTypeRaw = trim((string)($_GET['type'] ?? 'TE'));
        $entityType = path_finder_normalize_entity_type($entityTypeRaw);
        if ($entityType === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unsupported entity type'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $query = trim((string)($_GET['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 180);
        echo json_encode([
            'ok' => true,
            'source' => 'tekg3',
            'database' => tekg_runtime_neo4j_database_name($config),
            'entity_type' => $entityType,
            'items' => $service->suggestEntities($entityType, $query, $limit),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($view === 'connected_candidates') {
        $source = trim((string)($_GET['source'] ?? ''));
        $sourceTypeRaw = trim((string)($_GET['source_type'] ?? ''));
        $targetTypeRaw = trim((string)($_GET['target_type'] ?? ''));
        $sourceType = $sourceTypeRaw === '' ? '' : path_finder_normalize_entity_type($sourceTypeRaw);
        $targetType = path_finder_normalize_entity_type($targetTypeRaw);
        if ($sourceTypeRaw !== '' && $sourceType === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unsupported source entity type'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($targetType === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unsupported target entity type'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $query = trim((string)($_GET['q'] ?? ''));
        $maxDepth = (int)($_GET['max_depth'] ?? 3);
        $limit = (int)($_GET['limit'] ?? 180);
        echo json_encode([
            'ok' => true,
            'source' => 'tekg3',
            'database' => tekg_runtime_neo4j_database_name($config),
            'source_query' => $source,
            'source_type' => $sourceType,
            'target_type' => $targetType,
            'max_depth' => path_finder_clamp_depth($maxDepth),
            'items' => $service->suggestConnectedCandidates($source, $sourceType, $targetType, $query, $maxDepth, $limit),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($view !== '' && $view !== 'paths') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unsupported view'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $source = trim((string)($_GET['source'] ?? ''));
    $target = trim((string)($_GET['target'] ?? ''));
    $sourceType = trim((string)($_GET['source_type'] ?? ''));
    $targetType = trim((string)($_GET['target_type'] ?? ''));
    $maxDepth = (int)($_GET['max_depth'] ?? 3);
    if ($sourceType !== '' && path_finder_normalize_entity_type($sourceType) === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unsupported source entity type'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($targetType !== '' && path_finder_normalize_entity_type($targetType) === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unsupported target entity type'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $payload = $service->find($source, $target, $maxDepth, $sourceType, $targetType);
    if (($payload['ok'] ?? false) !== true) {
        http_response_code(404);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
