<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/evidence_support.php';

final class PluginResultEnvelope
{
    /**
     * Normalize heterogeneous legacy plugin payloads without removing legacy top-level fields.
     */
    public static function fromPluginResult(string $pluginName, mixed $rawResult, array $context = []): array
    {
        $result = is_array($rawResult) ? $rawResult : [];
        $legacyStatus = array_key_exists('status', $result) ? (string)$result['status'] : null;
        $resultCount = self::inferResultCount($result);

        return [
            'plugin' => $pluginName,
            'status' => self::normalizeStatus($legacyStatus, $result, $resultCount),
            'legacy_status' => $legacyStatus,
            'intent' => self::inferIntent($pluginName, $result, $context),
            'summary' => self::inferSummary($pluginName, $result),
            'raw' => self::summarizeRawResult($rawResult, $resultCount),
            'evidence_items' => self::normalizeEvidenceItems($result['evidence_items'] ?? [], $pluginName),
            'citations' => array_values((array)($result['citations'] ?? [])),
            'routes' => self::extractRoutes($result),
            'metrics' => [
                'duration_ms' => self::inferDurationMs($result),
                'result_count' => $resultCount,
                'confidence' => self::inferConfidence($result),
            ],
            'errors' => self::extractErrors($result),
        ];
    }

    private static function normalizeEvidenceItems(mixed $items, string $pluginName): array
    {
        $normalized = [];
        foreach ((array)$items as $item) {
            $evidence = tekg_agent_normalize_evidence_item($item, $pluginName);
            if ($evidence !== null) {
                $normalized[] = $evidence;
            }
        }

        return $normalized;
    }

    private static function normalizeStatus(?string $legacyStatus, array $result, int $resultCount): string
    {
        $status = strtolower(trim((string)$legacyStatus));
        if ($status === 'error' || $status === 'failed') {
            return 'failed';
        }
        if (in_array($status, ['ok', 'partial', 'empty'], true)) {
            return $status;
        }
        if (self::extractErrors($result) !== []) {
            return 'failed';
        }
        return $resultCount > 0 ? 'ok' : 'empty';
    }

    private static function inferIntent(string $pluginName, array $result, array $context): string
    {
        $intent = trim((string)($result['intent'] ?? $context['intent'] ?? $context['analysis']['intent'] ?? ''));
        if ($intent !== '') {
            return $intent;
        }

        $name = strtolower($pluginName);
        return match (true) {
            str_contains($name, 'site navigator') => 'navigation',
            str_contains($name, 'sequence') => 'sequence',
            str_contains($name, 'expression') => 'expression',
            str_contains($name, 'genome') => 'genome',
            str_contains($name, 'tree') => 'classification',
            str_contains($name, 'literature'), str_contains($name, 'citation') => 'literature',
            str_contains($name, 'analytics') => 'analytics',
            str_contains($name, 'graph') => 'relationship',
            default => 'unknown',
        };
    }

    private static function inferSummary(string $pluginName, array $result): string
    {
        foreach (['display_summary', 'query_summary', 'answer_markdown'] as $key) {
            $summary = trim((string)($result[$key] ?? ''));
            if ($summary !== '') {
                return $summary;
            }
        }

        $nestedSummary = trim((string)($result['results']['answer_markdown'] ?? ''));
        if ($nestedSummary !== '') {
            return $nestedSummary;
        }

        return $pluginName . ' result';
    }

    private static function inferResultCount(array $result): int
    {
        $counts = (array)($result['result_counts'] ?? []);
        $numericCounts = [];
        foreach ($counts as $value) {
            if (is_numeric($value)) {
                $numericCounts[] = (int)$value;
            }
        }
        if ($numericCounts !== []) {
            return max(0, max($numericCounts));
        }

        $results = (array)($result['results'] ?? []);
        foreach (['rows', 'matched_records', 'candidate_routes'] as $key) {
            if (isset($results[$key]) && is_array($results[$key])) {
                return count($results[$key]);
            }
        }
        if (isset($result['candidate_routes']) && is_array($result['candidate_routes'])) {
            return count($result['candidate_routes']);
        }
        if (isset($result['evidence_items']) && is_array($result['evidence_items'])) {
            return count($result['evidence_items']);
        }
        if (isset($result['citations']) && is_array($result['citations'])) {
            return count($result['citations']);
        }

        return 0;
    }

    private static function extractRoutes(array $result): array
    {
        $routes = [];
        foreach (['routes', 'candidate_routes'] as $key) {
            foreach ((array)($result[$key] ?? []) as $route) {
                if (is_array($route)) {
                    $routes[] = $route;
                }
            }
        }

        $results = (array)($result['results'] ?? []);
        foreach (['routes', 'candidate_routes'] as $key) {
            foreach ((array)($results[$key] ?? []) as $route) {
                if (is_array($route)) {
                    $routes[] = $route;
                }
            }
        }
        if (isset($results['primary_route']) && is_array($results['primary_route'])) {
            array_unshift($routes, $results['primary_route']);
        }

        $seen = [];
        $unique = [];
        foreach ($routes as $route) {
            $key = (string)($route['url'] ?? $route['href'] ?? $route['label'] ?? json_encode($route));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $route;
        }

        return $unique;
    }

    private static function summarizeRawResult(mixed $rawResult, int $resultCount): array
    {
        if (!is_array($rawResult)) {
            return [
                'type' => get_debug_type($rawResult),
                'keys' => [],
                'status' => null,
                'result_count' => $resultCount,
                'has_results' => false,
                'has_citations' => false,
                'has_evidence_items' => false,
                'has_errors' => false,
            ];
        }

        return [
            'type' => 'array',
            'keys' => array_keys($rawResult),
            'status' => array_key_exists('status', $rawResult) ? $rawResult['status'] : null,
            'result_count' => $resultCount,
            'has_results' => array_key_exists('results', $rawResult),
            'has_citations' => array_key_exists('citations', $rawResult),
            'has_evidence_items' => array_key_exists('evidence_items', $rawResult),
            'has_errors' => array_key_exists('errors', $rawResult) || array_key_exists('error', $rawResult),
        ];
    }

    private static function inferDurationMs(array $result): ?int
    {
        foreach (['duration_ms', 'latency_ms', 'elapsed_ms'] as $key) {
            if (isset($result[$key]) && is_numeric($result[$key])) {
                return (int)$result[$key];
            }
        }
        return null;
    }

    private static function inferConfidence(array $result): int|float|string|null
    {
        foreach (['confidence', 'confidence_score'] as $key) {
            if (array_key_exists($key, $result)) {
                return is_numeric($result[$key]) ? $result[$key] + 0 : (string)$result[$key];
            }
        }
        return null;
    }

    private static function extractErrors(array $result): array
    {
        $errors = array_values(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            (array)($result['errors'] ?? [])
        )));

        $error = trim((string)($result['error'] ?? ''));
        if ($error !== '') {
            $errors[] = $error;
        }

        return array_values(array_unique($errors));
    }
}
