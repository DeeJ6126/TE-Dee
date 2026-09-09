<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/evidence_support.php';

final class PluginResultProjection
{
    private const PREVIEW_LIMIT = 8;
    private const EVIDENCE_LIMIT = 20;
    private const CITATION_LIMIT = 50;
    private const MESSAGE_LIMIT = 20;
    private const KEY_FINDING_LIMIT = 5;
    private const CANDIDATE_CLAIM_LIMIT = 10;
    private const RAW_EXCERPT_LIMIT = 10;

    public static function forUi(array $result): array
    {
        $pluginName = trim((string)($result['plugin_name'] ?? 'Unknown')) ?: 'Unknown';
        $displayDetails = (array)($result['display_details'] ?? []);
        $evidenceItems = [];
        foreach (array_slice((array)($displayDetails['evidence_items'] ?? $result['evidence_items'] ?? []), 0, self::EVIDENCE_LIMIT) as $item) {
            $normalized = tekg_agent_normalize_evidence_item($item, $pluginName);
            if ($normalized !== null) {
                $evidenceItems[] = $normalized;
            }
        }

        $payload = [
            'status' => (string)($result['status'] ?? 'unknown'),
            'summary' => (string)($displayDetails['summary'] ?? $result['display_summary'] ?? ''),
            'preview_items' => array_values(array_slice((array)($displayDetails['preview_items'] ?? []), 0, self::PREVIEW_LIMIT)),
            'evidence_items' => $evidenceItems,
            'citations' => array_values(array_slice(
                (array)($displayDetails['citations'] ?? $result['citations'] ?? []),
                0,
                self::CITATION_LIMIT
            )),
            'raw_result' => self::rawPreview($pluginName, $result, $displayDetails),
            'errors' => array_values(array_slice((array)($result['errors'] ?? []), 0, self::MESSAGE_LIMIT)),
            'warnings' => array_values(array_slice((array)($result['warnings'] ?? []), 0, self::MESSAGE_LIMIT)),
            'caveats' => array_values(array_slice((array)($result['caveats'] ?? []), 0, self::MESSAGE_LIMIT)),
            'executing_review_status' => (string)($result['executing_review_status'] ?? ''),
            'executing_review_reason' => (string)($result['executing_review_reason'] ?? ''),
            'executing_review_errors' => array_values(array_slice((array)($result['executing_review_errors'] ?? []), 0, self::MESSAGE_LIMIT)),
            'result_counts' => (array)($result['result_counts'] ?? []),
            'projection' => [
                'schema_version' => 'plugin_ui_projection.v1',
                'preview_limit' => self::PREVIEW_LIMIT,
                'evidence_limit' => self::EVIDENCE_LIMIT,
                'citation_limit' => self::CITATION_LIMIT,
            ],
        ];

        return tekg_agent_json_safe($payload);
    }

    public static function forLlmContext(string $pluginName, array $result, array $analysis, array $planning): array
    {
        $rawResult = tekg_agent_json_safe((array)($result['results'] ?? []));
        $evidenceItems = [];
        foreach ((array)($result['evidence_items'] ?? []) as $item) {
            $normalized = tekg_agent_normalize_evidence_item($item, $pluginName);
            if ($normalized !== null) {
                $evidenceItems[] = $normalized;
            }
        }

        $keyFindings = [];
        foreach (array_slice($evidenceItems, 0, self::KEY_FINDING_LIMIT) as $item) {
            $claim = trim((string)($item['claim'] ?? ''));
            if ($claim !== '') {
                $keyFindings[] = $claim;
            }
        }
        if ($keyFindings === []) {
            foreach (array_slice((array)($result['display_details']['preview_items'] ?? []), 0, self::KEY_FINDING_LIMIT) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $title = trim((string)($item['title'] ?? ''));
                if ($title !== '') {
                    $keyFindings[] = $title;
                }
            }
        }
        if ($keyFindings === []) {
            $summary = trim((string)($result['display_summary'] ?? $result['query_summary'] ?? ''));
            if ($summary !== '') {
                $keyFindings[] = $summary;
            }
        }

        $limitations = array_values(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            (array)($result['errors'] ?? [])
        )));
        if (in_array((string)($result['status'] ?? ''), ['empty', 'error'], true)) {
            $limitations[] = trim((string)($result['display_summary'] ?? $result['query_summary'] ?? ''));
        }

        $previewItems = array_values(array_slice((array)($result['display_details']['preview_items'] ?? []), 0, self::PREVIEW_LIMIT));
        $citationPreview = array_values(array_slice((array)($result['citations'] ?? []), 0, 12));
        $evidencePreview = array_values(array_map(
            static fn(array $item): array => [
                'claim' => (string)($item['claim'] ?? ''),
                'title' => (string)($item['title'] ?? ''),
                'meta' => (string)($item['meta'] ?? ''),
                'support_strength' => (string)($item['support_strength'] ?? 'medium'),
                'evidence_type' => (string)($item['evidence_type'] ?? 'claim'),
                'quality_flags' => array_values((array)($item['quality_flags'] ?? [])),
                'citations' => array_values(array_slice((array)($item['citations'] ?? []), 0, 4)),
            ],
            array_slice($evidenceItems, 0, self::PREVIEW_LIMIT)
        ));

        $carryForward = [
            'plugin_name' => $pluginName,
            'status' => (string)($result['status'] ?? 'unknown'),
            'query_summary' => (string)($result['query_summary'] ?? ''),
            'display_summary' => (string)($result['display_summary'] ?? ''),
            'result_counts' => (array)($result['result_counts'] ?? []),
            'preview_items' => $previewItems,
            'evidence_preview' => $evidencePreview,
            'citations' => $citationPreview,
        ];

        if ($pluginName === 'Cypher Explorer Plugin') {
            $cypherResult = (array)($rawResult['cypher_result'] ?? []);
            $carryForward['query_purpose'] = (string)($cypherResult['query_intent'] ?? 'graph_exploration');
            $carryForward['result_shape'] = [
                'row_count' => (int)($cypherResult['result_counts']['rows'] ?? 0),
                'columns' => (array)($cypherResult['column_schema'] ?? []),
            ];
            $carryForward['top_rows'] = array_slice((array)($cypherResult['rows'] ?? []), 0, self::RAW_EXCERPT_LIMIT);
            $carryForward['why_it_matters'] = $keyFindings[0] ?? trim((string)($result['display_summary'] ?? ''));
        } else {
            $carryForward['raw_result_excerpt'] = self::rawResultExcerpt($rawResult);
        }

        return tekg_agent_json_safe([
            'schema_version' => 'plugin_llm_projection.v1',
            'key_findings' => array_values(array_unique(array_filter($keyFindings))),
            'coverage' => [
                'question_type' => (string)($analysis['intent'] ?? 'relationship'),
                'status' => (string)($result['status'] ?? 'unknown'),
                'result_counts' => (array)($result['result_counts'] ?? []),
                'required_evidence' => (array)($planning['required_evidence'] ?? []),
                'latency_ms' => (int)($result['latency_ms'] ?? 0),
            ],
            'limitations' => array_values(array_unique(array_filter($limitations))),
            'candidate_claims' => array_values(array_unique(array_filter(array_map(
                static fn(array $item): string => trim((string)($item['claim'] ?? '')),
                array_slice($evidenceItems, 0, self::CANDIDATE_CLAIM_LIMIT)
            )))),
            'carry_forward_fields' => $carryForward,
        ]);
    }

    private static function rawResultExcerpt(array $rawResult): array
    {
        $excerpt = [];
        foreach ($rawResult as $key => $value) {
            $excerpt[$key] = is_array($value) ? array_slice($value, 0, self::RAW_EXCERPT_LIMIT) : $value;
        }
        return tekg_agent_json_safe($excerpt);
    }

    private static function rawPreview(string $pluginName, array $result, array $displayDetails): mixed
    {
        $rawPreview = $displayDetails['raw_preview'] ?? null;
        $rawResult = (array)($result['raw_result'] ?? $result['results'] ?? []);

        if ($pluginName === 'Literature Plugin' || $pluginName === 'Citation Resolver') {
            $metadata = $rawResult;
            unset($metadata['citations']);
            return $metadata !== [] ? $metadata : null;
        }

        if ($pluginName === 'Literature Reading Plugin') {
            return $rawResult !== [] ? $rawResult : null;
        }

        if ($pluginName === 'Sequence Plugin') {
            $preview = is_array($rawPreview) ? $rawPreview : [];
            $preview['matched_records'] = array_values(array_map(
                static function (array $record): array {
                    if (isset($record['entry']) && is_array($record['entry'])) {
                        unset($record['entry']['sequence']);
                    }
                    unset($record['sequence']);
                    return $record;
                },
                array_slice((array)($preview['matched_records'] ?? $rawResult['matched_records'] ?? []), 0, self::PREVIEW_LIMIT)
            ));
            $preview['full_sequences'] = array_values((array)($displayDetails['full_sequences'] ?? []));
            return $preview;
        }

        if ($rawPreview !== null) {
            return $rawPreview;
        }
        return $rawResult !== [] ? $rawResult : null;
    }
}
