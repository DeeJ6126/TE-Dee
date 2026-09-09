<?php
declare(strict_types=1);

require_once __DIR__ . '/expression_data.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

function tekg_graph_expression_number($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (float)$value : null;
}

function tekg_graph_expression_int($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (int)$value : null;
}

function tekg_graph_expression_context(array $summary, string $key): array
{
    $label = (string)($summary[$key . '_top_context_median_full_name'] ?? $summary[$key . '_top_context_median'] ?? '');
    $shortLabel = (string)($summary[$key . '_top_context_median'] ?? '');

    return [
        'available' => ((int)($summary[$key . '_available'] ?? 0)) > 0,
        'dataset_key' => $key,
        'label' => $label,
        'short_label' => $shortLabel,
        'median_value' => tekg_graph_expression_number($summary[$key . '_top_context_median_value'] ?? null),
        'mean_value' => tekg_graph_expression_number($summary[$key . '_top_context_mean_value'] ?? null),
        'max_value' => tekg_graph_expression_number($summary[$key . '_top_context_max_value'] ?? null),
        'context_count' => tekg_graph_expression_int($summary[$key . '_context_count'] ?? null),
        'breadth' => tekg_graph_expression_int($summary[$key . '_breadth_of_median'] ?? null),
    ];
}

function tekg_graph_expression_compact_record(string $requestedName, ?array $bundle): array
{
    if ($bundle === null) {
        return [
            'requested_name' => $requestedName,
            'te_name' => $requestedName,
            'available' => false,
            'missing_reason' => 'No expression summary row matched this TE name.',
        ];
    }

    $summary = is_array($bundle['browse_summary'] ?? null) ? $bundle['browse_summary'] : [];
    $datasets = ['normal_tissue', 'normal_cell_line', 'cancer_cell_line'];
    $contexts = [];
    foreach ($datasets as $datasetKey) {
        $contexts[$datasetKey] = tekg_graph_expression_context($summary, $datasetKey);
    }

    return [
        'requested_name' => $requestedName,
        'te_name' => (string)($bundle['te_name'] ?? $requestedName),
        'available' => true,
        'global' => [
            'dataset_label' => (string)($summary['global_top_context_median_dataset'] ?? ''),
            'label' => (string)($summary['global_top_context_median_full_name'] ?? $summary['global_top_context_median'] ?? ''),
            'short_label' => (string)($summary['global_top_context_median'] ?? ''),
            'median_value' => tekg_graph_expression_number($summary['global_top_context_median_value'] ?? null),
        ],
        'normal_tissue' => $contexts['normal_tissue'],
        'normal_cell_line' => $contexts['normal_cell_line'],
        'cancer_cell_line' => $contexts['cancer_cell_line'],
        'contexts_available' => array_values(array_filter(
            $datasets,
            static fn(string $datasetKey): bool => $contexts[$datasetKey]['available'] === true
        )),
    ];
}

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Request body must be a JSON object.');
    }

    $names = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        is_array($payload['te_names'] ?? null) ? $payload['te_names'] : []
    ), static fn(string $value): bool => $value !== '')));

    if ($names === []) {
        echo json_encode([
            'ok' => true,
            'records' => [],
            'counts' => ['requested' => 0, 'available' => 0],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $names = array_slice($names, 0, 80);
    $records = [];
    foreach ($names as $name) {
        $records[] = tekg_graph_expression_compact_record(
            $name,
            tekg_expression_fetch_detail_bundle($name, 'median', 'high_to_low', 'bar')
        );
    }

    $available = count(array_filter($records, static fn(array $record): bool => ($record['available'] ?? false) === true));
    echo json_encode([
        'ok' => true,
        'records' => $records,
        'counts' => [
            'requested' => count($names),
            'available' => $available,
        ],
        'evidence_boundary' => 'Expression values provide activity context only and do not prove causal graph relations.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
