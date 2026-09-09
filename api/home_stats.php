<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
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
    $config = tekg_runtime_neo4j_config($localConfig);
    $database = tekg_runtime_neo4j_database_name($config);
    if ($database !== 'tekg3') {
        throw new RuntimeException("Homepage stats require Neo4j database tekg3; configured database is {$database}.");
    }
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

$teLevel = home_stats_te_level();
$teProperty = home_stats_te_property($teLevel);

try {
    $results = home_stats_run_neo4j_statements($config, [
        [
            'statement' => 'MATCH (n) RETURN count(n) AS count',
            'parameters' => [],
        ],
        [
            'statement' => 'MATCH ()-[r]->() RETURN count(r) AS count',
            'parameters' => [],
        ],
        [
            'statement' =>
                <<<'CYPHER'
MATCH (n)
WHERE NOT 'Paper' IN labels(n)
WITH labels(n) AS node_labels
WITH CASE
  WHEN size(node_labels) = 0 THEN 'Other'
  ELSE node_labels[0]
END AS label
RETURN label, count(*) AS count
ORDER BY count DESC, label ASC
CYPHER,
            'parameters' => [],
        ],
        [
            'statement' =>
                <<<'CYPHER'
MATCH ()-[r:BIO_RELATION]->()
WITH CASE
  WHEN trim(toString(coalesce(r.predicate, ''))) = '' THEN type(r)
  ELSE trim(toString(r.predicate))
END AS label
RETURN label, count(*) AS count
ORDER BY count DESC, label ASC
CYPHER,
            'parameters' => [],
        ],
        [
            'statement' =>
                <<<'CYPHER'
MATCH (t:TE)
WHERE coalesce(t.homepage_chart_included, false) = true
  AND coalesce(t.taxonomy_source, '') = 'tree_rmsk_repbase'
  AND trim(toString(coalesce(t[$te_property], ''))) <> ''
WITH trim(toString(t[$te_property])) AS label
RETURN label, count(*) AS count
ORDER BY count DESC, label ASC
CYPHER,
            'parameters' => ['te_property' => $teProperty],
        ],
    ]);

    $nodesTotal = max(0, (int)($results[0][0]['count'] ?? 0));
    $relationshipsTotal = max(0, (int)($results[1][0]['count'] ?? 0));
    $entityRows = $results[2] ?? [];
    $relationRows = $results[3] ?? [];
    $teClassificationRows = $results[4] ?? [];

    echo json_encode([
        'ok' => true,
        'nodes_total' => $nodesTotal,
        'relationships_total' => $relationshipsTotal,
        'te_level' => $teLevel,
        'entity_composition' => home_stats_entity_composition($entityRows),
        'relation_composition' => home_stats_relation_composition($relationRows),
        'te_classification_composition' => home_stats_te_classification_composition($teClassificationRows),
        'generated_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function home_stats_te_level(): string
{
    $level = strtolower(trim((string)($_GET['te_level'] ?? 'class')));
    return array_key_exists($level, home_stats_te_level_map()) ? $level : 'class';
}

function home_stats_te_property(string $level): string
{
    $map = home_stats_te_level_map();
    return $map[$level] ?? $map['class'];
}

function home_stats_te_level_map(): array
{
    return [
        'class' => 'taxonomy_class',
        'order' => 'taxonomy_order',
        'superfamily' => 'taxonomy_superfamily',
        'family' => 'taxonomy_family',
    ];
}

function home_stats_entity_composition(array $rows): array
{
    $counts = [];
    foreach ($rows as $row) {
        $label = trim((string)($row['label'] ?? 'Other'));
        if ($label === '') {
            $label = 'Other';
        }
        if (home_stats_is_paper_label($label)) {
            continue;
        }
        if (!array_key_exists($label, $counts)) {
            $counts[$label] = 0;
        }
        $counts[$label] += max(0, (int)($row['count'] ?? 0));
    }
    $total = array_sum($counts);

    $composition = [];
    foreach ($counts as $label => $count) {
        $composition[] = [
            'label' => $label,
            'count' => $count,
            'percentage' => home_stats_percentage($count, $total),
        ];
    }
    return $composition;
}

function home_stats_relation_composition(array $rows): array
{
    $broadPredicates = [
        'associate with' => true,
        'participate in' => true,
        'involve in' => true,
    ];
    $normalizedRows = [];
    foreach ($rows as $row) {
        $label = trim((string)($row['label'] ?? ''));
        if ($label === '') {
            $label = 'BIO_RELATION';
        }
        if (isset($broadPredicates[mb_strtolower($label)])) {
            continue;
        }
        $normalizedRows[] = [
            'label' => $label,
            'count' => max(0, (int)($row['count'] ?? 0)),
        ];
    }

    $total = 0;
    foreach ($normalizedRows as $row) {
        $total += (int)$row['count'];
    }

    $composition = [];
    $otherCount = 0;
    foreach ($normalizedRows as $row) {
        $count = (int)$row['count'];
        if ($total > 0 && ($count / $total) < 0.01) {
            $otherCount += $count;
            continue;
        }
        $composition[] = [
            'label' => (string)$row['label'],
            'count' => $count,
            'percentage' => home_stats_percentage($count, $total),
        ];
    }
    if ($otherCount > 0) {
        $composition[] = [
            'label' => 'others',
            'count' => $otherCount,
            'percentage' => home_stats_percentage($otherCount, $total),
        ];
    }
    return $composition;
}

function home_stats_is_paper_label(string $label): bool
{
    $normalized = mb_strtolower(trim($label));
    return in_array($normalized, ['paper', 'publication', 'literature'], true);
}

function home_stats_te_classification_composition(array $rows): array
{
    $total = 0;
    foreach ($rows as $row) {
        $total += max(0, (int)($row['count'] ?? 0));
    }

    $composition = [];
    foreach ($rows as $row) {
        $count = max(0, (int)($row['count'] ?? 0));
        $composition[] = [
            'label' => home_stats_clean_taxonomy_label((string)($row['label'] ?? 'Other')),
            'count' => $count,
            'percentage' => home_stats_percentage($count, $total),
        ];
    }
    return $composition;
}

function home_stats_clean_taxonomy_label(string $label): string
{
    $label = trim($label);
    $label = preg_replace('/^Class\s+/i', 'Class ', $label) ?? $label;
    return $label !== '' ? $label : 'Other';
}

function home_stats_percentage(int $count, int $total): float
{
    if ($total <= 0) {
        return 0.0;
    }
    return round(($count / $total) * 100.0, 2);
}

function home_stats_run_neo4j_statements(array $config, array $statements): array
{
    $payloadStatements = [];
    foreach ($statements as $statement) {
        $parameters = $statement['parameters'] ?? [];
        $payloadStatements[] = [
            'statement' => (string)($statement['statement'] ?? ''),
            'parameters' => $parameters === [] ? new stdClass() : $parameters,
        ];
    }

    $payload = json_encode([
        'statements' => $payloadStatements,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($config['neo4j_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $config['neo4j_user'] . ':' . $config['neo4j_password'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 20,
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

    $mappedResults = [];
    foreach (($decoded['results'] ?? []) as $result) {
        $mappedResults[] = home_stats_map_neo4j_result(is_array($result) ? $result : []);
    }

    return $mappedResults;
}

function home_stats_map_neo4j_result(array $result): array
{
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
