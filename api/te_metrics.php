<?php

declare(strict_types=1);

require_once __DIR__ . '/runtime_config.php';

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
    $config = tekg_runtime_neo4j_config();
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

try {
    $rows = run_neo4j(
        $config,
        <<<'CYPHER'
MATCH (n:TE)
OPTIONAL MATCH (n)--()
RETURN n.name AS name, count(*) AS degree
ORDER BY n.name
CYPHER,
        []
    );

    $metrics = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $metrics[$name] = max(0, (int)($row['degree'] ?? 0));
    }

    echo json_encode(['ok' => true, 'metrics' => $metrics], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function run_neo4j(array $config, string $statement, array $parameters): array
{
    $normalizedParameters = $parameters === [] ? new stdClass() : $parameters;
    $payload = json_encode([
        'statements' => [[
            'statement' => $statement,
            'parameters' => $normalizedParameters,
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($config['neo4j_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $config['neo4j_user'] . ':' . $config['neo4j_password'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Neo4j request failed: ' . $error);
    }
    if ($status >= 400) {
        throw new RuntimeException('Neo4j HTTP ' . $status);
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Neo4j response is not valid JSON');
    }
    if (!empty($decoded['errors'])) {
        $message = (string)($decoded['errors'][0]['message'] ?? 'Neo4j query failed');
        throw new RuntimeException($message);
    }

    $result = $decoded['results'][0] ?? null;
    if (!is_array($result)) {
        return [];
    }

    $columns = $result['columns'] ?? [];
    $rows = [];
    foreach (($result['data'] ?? []) as $entry) {
        $values = $entry['row'] ?? [];
        $row = [];
        foreach ($columns as $index => $column) {
            $row[(string)$column] = $values[$index] ?? null;
        }
        $rows[] = $row;
    }

    return $rows;
}
