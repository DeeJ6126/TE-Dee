<?php
declare(strict_types=1);

require_once __DIR__ . '/PluginResultEnvelope.php';

final class EvidencePackage
{
    private const SCHEMA_VERSION = 'evidence_package.v1';
    private const DEFAULT_SUMMARY_MAX_CHARS = 640;

    public static function fromPluginResults(string $question, array $analysis, array $pluginResults, array $context = []): array
    {
        $summaryMaxChars = self::summaryMaxChars($context);
        $claims = [];
        $evidenceItems = [];
        $citationMap = [];
        $routeMap = [];
        $errors = [];
        $statuses = [];
        $emptyPluginCount = 0;
        $failedPluginCount = 0;
        $truncatedSummaries = [];

        foreach (array_values($pluginResults) as $index => $pluginResult) {
            $envelope = self::normalizePluginResult($pluginResult, $index, $analysis, $context);
            $plugin = self::stringValue($envelope['plugin'] ?? 'Plugin ' . ($index + 1));
            $status = self::stringValue($envelope['status'] ?? 'empty');
            $statuses[$plugin] = $status;

            if ($status === 'empty') {
                $emptyPluginCount++;
                continue;
            }

            if ($status === 'failed') {
                $failedPluginCount++;
                foreach ((array)($envelope['errors'] ?? []) as $message) {
                    $message = trim((string)$message);
                    if ($message !== '') {
                        $errors[] = [
                            'plugin' => $plugin,
                            'message' => $message,
                        ];
                    }
                }
                continue;
            }

            if ($plugin === 'Site Navigator Plugin') {
                self::appendRouteMap($routeMap, 'navigation', $plugin, (array)($envelope['routes'] ?? []));
                continue;
            }

            $sourceEvidenceItems = array_values((array)($envelope['evidence_items'] ?? []));
            if ($sourceEvidenceItems === []) {
                $summary = trim(self::stringValue($envelope['summary'] ?? ''));
                if ($summary === '') {
                    continue;
                }
                $sourceEvidenceItems[] = [
                    'claim' => $summary,
                    'support_strength' => null,
                    'source' => 'summary',
                ];
            }

            foreach ($sourceEvidenceItems as $sourceEvidence) {
                if (!is_array($sourceEvidence)) {
                    continue;
                }
                if (self::isDiagnosticEvidence($sourceEvidence)) {
                    continue;
                }

                $claimText = self::claimText($sourceEvidence, $envelope);
                if ($claimText === '') {
                    continue;
                }

                $claimId = 'claim_' . (count($claims) + 1);
                [$claimText, $wasTruncated, $originalLength] = self::truncateText($claimText, $summaryMaxChars);
                if ($wasTruncated) {
                    $truncatedSummaries[] = [
                        'claim_id' => $claimId,
                        'plugin' => $plugin,
                        'original_chars' => $originalLength,
                        'truncated_chars' => strlen($claimText),
                    ];
                }

                $evidenceId = 'evidence_' . (count($evidenceItems) + 1);
                $evidenceCitations = self::evidenceCitations($sourceEvidence);
                $citationIds = self::appendCitationMap(
                    $citationMap,
                    $claimId,
                    $plugin,
                    $evidenceCitations !== [] ? $evidenceCitations : (array)($envelope['citations'] ?? [])
                );
                $routeIds = self::appendRouteMap($routeMap, $claimId, $plugin, (array)($envelope['routes'] ?? []));

                $evidenceItems[] = [
                    'id' => $evidenceId,
                    'claim_id' => $claimId,
                    'plugin' => $plugin,
                    'intent' => self::stringValue($envelope['intent'] ?? ($analysis['intent'] ?? 'unknown')),
                    'text' => $claimText,
                    'support_strength' => $sourceEvidence['support_strength'] ?? null,
                    'raw' => $sourceEvidence,
                ];

                $claims[] = [
                    'id' => $claimId,
                    'text' => $claimText,
                    'source_plugin' => $plugin,
                    'intent' => self::stringValue($envelope['intent'] ?? ($analysis['intent'] ?? 'unknown')),
                    'status' => $status,
                    'confidence' => $envelope['metrics']['confidence'] ?? null,
                    'evidence_ids' => [$evidenceId],
                    'citation_ids' => $citationIds,
                    'route_ids' => $routeIds,
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'question' => $question,
            'generated_at' => gmdate('c'),
            'claims' => $claims,
            'evidence_items' => $evidenceItems,
            'citation_map' => $citationMap,
            'route_map' => $routeMap,
            'metrics' => [
                'plugin_count' => count($pluginResults),
                'claim_count' => count($claims),
                'evidence_count' => count($evidenceItems),
                'citation_count' => count($citationMap),
                'route_count' => count($routeMap),
                'empty_plugin_count' => $emptyPluginCount,
                'failed_plugin_count' => $failedPluginCount,
                'statuses' => $statuses,
            ],
            'limits' => [
                'summary_max_chars' => $summaryMaxChars,
                'truncation_count' => count($truncatedSummaries),
                'truncated_summaries' => $truncatedSummaries,
            ],
            'errors' => $errors,
        ];
    }

    public static function validate(array $package): array
    {
        $errors = [];
        $schema = require __DIR__ . '/../config/evidence_package_schema.php';

        foreach ((array)($schema['required'] ?? []) as $key) {
            if (!array_key_exists($key, $package)) {
                $errors[] = "{$key} is required";
            }
        }

        if (($package['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version must be evidence_package.v1';
        }
        if (!is_string($package['question'] ?? null)) {
            $errors[] = 'question must be a string';
        }
        if (!is_string($package['generated_at'] ?? null) || strtotime((string)$package['generated_at']) === false) {
            $errors[] = 'generated_at must be an ISO-8601 date-time string';
        }

        foreach (['claims', 'evidence_items', 'citation_map', 'route_map', 'errors'] as $key) {
            if (array_key_exists($key, $package) && !is_array($package[$key])) {
                $errors[] = "{$key} must be an array";
            }
        }
        foreach (['metrics', 'limits'] as $key) {
            if (array_key_exists($key, $package) && !is_array($package[$key])) {
                $errors[] = "{$key} must be an object";
            }
        }
        if (isset($package['metrics']) && is_array($package['metrics'])) {
            foreach (['plugin_count', 'claim_count', 'evidence_count', 'citation_count', 'route_count', 'empty_plugin_count', 'failed_plugin_count'] as $key) {
                if (!array_key_exists($key, $package['metrics']) || !is_int($package['metrics'][$key])) {
                    $errors[] = "metrics.{$key} must be an integer";
                }
            }
            if (!array_key_exists('statuses', $package['metrics']) || !is_array($package['metrics']['statuses'])) {
                $errors[] = 'metrics.statuses must be an object';
            }
        }
        if (isset($package['limits']) && is_array($package['limits'])) {
            foreach (['summary_max_chars', 'truncation_count'] as $key) {
                if (!array_key_exists($key, $package['limits']) || !is_int($package['limits'][$key])) {
                    $errors[] = "limits.{$key} must be an integer";
                }
            }
            if (!array_key_exists('truncated_summaries', $package['limits']) || !is_array($package['limits']['truncated_summaries'])) {
                $errors[] = 'limits.truncated_summaries must be an array';
            }
        }

        foreach ((array)($package['claims'] ?? []) as $index => $claim) {
            if (!is_array($claim)) {
                $errors[] = "claims[{$index}] must be an object";
                continue;
            }
            foreach (['id', 'text', 'source_plugin', 'intent', 'status'] as $key) {
                if (!array_key_exists($key, $claim) || !is_string($claim[$key]) || trim($claim[$key]) === '') {
                    $errors[] = "claims[{$index}].{$key} is required";
                }
            }
            foreach (['evidence_ids', 'citation_ids', 'route_ids'] as $key) {
                if (!array_key_exists($key, $claim) || !is_array($claim[$key])) {
                    $errors[] = "claims[{$index}].{$key} must be an array";
                }
            }
        }

        foreach ((array)($package['evidence_items'] ?? []) as $index => $evidence) {
            if (!is_array($evidence)) {
                $errors[] = "evidence_items[{$index}] must be an object";
                continue;
            }
            foreach (['id', 'claim_id', 'plugin', 'text'] as $key) {
                if (!array_key_exists($key, $evidence) || !is_string($evidence[$key]) || trim($evidence[$key]) === '') {
                    $errors[] = "evidence_items[{$index}].{$key} is required";
                }
            }
        }

        foreach ((array)($package['citation_map'] ?? []) as $index => $citation) {
            self::validateMappedItem($citation, "citation_map[{$index}]", 'citation', $errors);
        }
        foreach ((array)($package['route_map'] ?? []) as $index => $route) {
            self::validateMappedItem($route, "route_map[{$index}]", 'route', $errors);
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
        ];
    }

    private static function normalizePluginResult(mixed $pluginResult, int $index, array $analysis, array $context): array
    {
        if (is_array($pluginResult) && isset($pluginResult['result_envelope']) && is_array($pluginResult['result_envelope'])) {
            return $pluginResult['result_envelope'];
        }

        $pluginName = 'Plugin ' . ($index + 1);
        $rawResult = $pluginResult;
        if (is_array($pluginResult)) {
            $pluginName = self::stringValue($pluginResult['plugin'] ?? $pluginResult['plugin_name'] ?? $pluginResult['name'] ?? $pluginName);
            if (array_key_exists('result', $pluginResult)) {
                $rawResult = $pluginResult['result'];
            } elseif (array_key_exists('raw_result', $pluginResult)) {
                $rawResult = $pluginResult['raw_result'];
            }
        }

        return PluginResultEnvelope::fromPluginResult($pluginName, $rawResult, [
            'analysis' => $analysis,
            ...$context,
        ]);
    }

    private static function appendCitationMap(array &$citationMap, string $claimId, string $plugin, array $citations): array
    {
        $ids = [];
        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $id = 'citation_' . (count($citationMap) + 1);
            $citationMap[] = [
                'id' => $id,
                'claim_id' => $claimId,
                'plugin' => $plugin,
                'citation' => $citation,
            ];
            $ids[] = $id;
        }
        return $ids;
    }

    private static function evidenceCitations(array $sourceEvidence): array
    {
        $citations = [];
        foreach ((array)($sourceEvidence['citations'] ?? []) as $citation) {
            if (is_array($citation)) {
                $citations[] = $citation;
            }
        }
        return function_exists('tekg_agent_dedupe_citations') ? tekg_agent_dedupe_citations($citations) : $citations;
    }

    private static function isDiagnosticEvidence(array $sourceEvidence): bool
    {
        return tekg_agent_is_diagnostic_evidence($sourceEvidence);
    }

    private static function appendRouteMap(array &$routeMap, string $claimId, string $plugin, array $routes): array
    {
        $ids = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $id = 'route_' . (count($routeMap) + 1);
            $routeMap[] = [
                'id' => $id,
                'claim_id' => $claimId,
                'plugin' => $plugin,
                'route' => $route,
            ];
            $ids[] = $id;
        }
        return $ids;
    }

    private static function claimText(array $sourceEvidence, array $envelope): string
    {
        foreach (['claim', 'text', 'summary', 'statement'] as $key) {
            $value = trim(self::stringValue($sourceEvidence[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return trim(self::stringValue($envelope['summary'] ?? ''));
    }

    /**
     * @return array{0:string,1:bool,2:int}
     */
    private static function truncateText(string $text, int $maxChars): array
    {
        $length = strlen($text);
        if ($length <= $maxChars) {
            return [$text, false, $length];
        }
        return [substr($text, 0, $maxChars), true, $length];
    }

    private static function summaryMaxChars(array $context): int
    {
        $value = $context['summary_max_chars'] ?? self::DEFAULT_SUMMARY_MAX_CHARS;
        if (!is_numeric($value)) {
            return self::DEFAULT_SUMMARY_MAX_CHARS;
        }
        return max(80, (int)$value);
    }

    private static function validateMappedItem(mixed $item, string $path, string $payloadKey, array &$errors): void
    {
        if (!is_array($item)) {
            $errors[] = "{$path} must be an object";
            return;
        }
        foreach (['id', 'claim_id', 'plugin'] as $key) {
            if (!array_key_exists($key, $item) || !is_string($item[$key]) || trim($item[$key]) === '') {
                $errors[] = "{$path}.{$key} is required";
            }
        }
        if (!array_key_exists($payloadKey, $item) || !is_array($item[$payloadKey])) {
            $errors[] = "{$path}.{$payloadKey} must be an object";
        }
    }

    private static function stringValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }
        return '';
    }
}
