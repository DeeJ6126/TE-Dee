<?php
declare(strict_types=1);

final class TekgAgentGraphAnalyticsPlugin implements TekgAgentPluginInterface
{
    public function __construct(private readonly TekgAgentNeo4jClient $neo4j)
    {
    }

    public function getName(): string
    {
        return 'Graph Analytics Plugin';
    }

    public function run(array $context): array
    {
        $started = microtime(true);
        $analysis = tekg_agent_context_analysis($context);
        $question = trim((string)($context['question'] ?? ''));
        $entities = tekg_agent_context_resolved_entities($context);
        $targetTypes = is_array($analysis['requested_target_types'] ?? null) ? $analysis['requested_target_types'] : [];

        $queryPlan = $this->selectQueryPlan($question, $analysis, $entities, $targetTypes);
        $errors = [];

        try {
            $result = $this->neo4j->runReadOnlyQuery($queryPlan['cypher'], $queryPlan['params'] ?? [], (int)($queryPlan['default_limit'] ?? 15));
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
            $validatedFeatures = is_array($result['validated_features'] ?? null) ? $result['validated_features'] : [];
            $generatedCypher = (string)($result['generated_cypher'] ?? $queryPlan['cypher']);
        } catch (Throwable $error) {
            $rows = [];
            $validatedFeatures = [];
            $generatedCypher = (string)($queryPlan['cypher'] ?? '');
            $errors[] = $error->getMessage();
        }

        $analytics = $this->buildAnalyticsResult($queryPlan, $rows, $generatedCypher, $validatedFeatures);
        $evidenceItems = $this->buildEvidenceItems($analytics);
        $previewItems = $this->buildPreviewItems($analytics);

        return [
            'plugin_name' => $this->getName(),
            'status' => tekg_agent_plugin_status($rows !== [], $errors),
            'query_summary' => (string)($queryPlan['query_summary'] ?? 'Executed a graph analytics query.'),
            'results' => [
                'analytics_result' => $analytics,
                'graph_elements' => $analytics['graph_elements'],
                'rows' => $analytics['rows'],
            ],
            'display_label' => 'Computed ' . count($analytics['top_k']) . ' graph analytics rows',
            'display_summary' => $this->displaySummary($queryPlan, $analytics, $errors),
            'display_details' => [
                'summary' => $this->displaySummary($queryPlan, $analytics, $errors),
                'preview_items' => $previewItems,
                'evidence_items' => $evidenceItems,
                'citations' => [],
                'raw_preview' => $analytics,
                'result_message' => $this->resultMessage($queryPlan, $analytics),
            ],
            'result_counts' => [
                'rows' => count($analytics['rows']),
                'top_k' => count($analytics['top_k']),
                'graph_nodes' => count($analytics['graph_elements']['nodes'] ?? []),
                'graph_edges' => count($analytics['graph_elements']['edges'] ?? []),
            ],
            'evidence_items' => $evidenceItems,
            'citations' => [],
            'errors' => $errors,
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    private function selectQueryPlan(string $question, array $analysis, array $entities, array $targetTypes): array
    {
        $normalizedQuestion = tekg_agent_lower($question);
        $teEntities = array_values(array_filter($entities, static fn(array $entity): bool => ($entity['type'] ?? '') === 'TE'));
        $targetTypes = $targetTypes !== [] ? $targetTypes : ['Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Paper', 'TE'];

        if (str_contains($normalizedQuestion, 'relation type') || str_contains($normalizedQuestion, 'relation types') || str_contains($normalizedQuestion, 'most frequent relation')) {
            return [
                'query_class' => 'relation_type_distribution',
                'metric_definition' => 'Count outgoing graph relations by relation type.',
                'query_summary' => 'Counted the most frequent relation types in the current graph.',
                'cypher' => "MATCH ()-[r]->() RETURN type(r) AS label, count(r) AS value ORDER BY value DESC LIMIT 12",
                'params' => [],
                'default_limit' => 12,
            ];
        }

        if ($teEntities !== []) {
            $targetType = $this->preferredTargetType($targetTypes);
            $entity = $teEntities[0];
            return [
                'query_class' => 'top_targets_for_te',
                'metric_definition' => 'Count relations from the selected TE to connected target nodes of a chosen label.',
                'query_summary' => 'Ranked the most connected target nodes for the selected TE entity.',
                'cypher' => "MATCH (t:TE)-[r]->(m:$targetType)
                             WHERE replace(replace(replace(toLower(trim(coalesce(t.name,''))), '-', ''), '_', ''), ' ', '') = replace(replace(replace(toLower(trim(\$entity)), '-', ''), '_', ''), ' ', '')
                             RETURN coalesce(m.name,'') AS label,
                                    count(r) AS value,
                                    '$targetType' AS node_type,
                                    collect(DISTINCT type(r))[0..5] AS relation_types
                             ORDER BY value DESC
                             LIMIT 10",
                'params' => ['entity' => (string)($entity['canonical_label'] ?? $entity['label'] ?? '')],
                'default_limit' => 10,
            ];
        }

        if ((bool)($analysis['asks_for_graph_analytics'] ?? false) && $this->containsAny($normalizedQuestion, ['disease', 'diseases', '疾病'])) {
            return [
                'query_class' => 'top_diseases_by_te_count',
                'metric_definition' => 'Count distinct TE nodes connected to each disease.',
                'query_summary' => 'Ranked diseases by the number of linked TE entities.',
                'cypher' => "MATCH (t:TE)-[r]->(d:Disease)
                             RETURN coalesce(d.name,'') AS label,
                                    count(DISTINCT t) AS value,
                                    'Disease' AS node_type,
                                    collect(DISTINCT type(r))[0..5] AS relation_types
                             ORDER BY value DESC
                             LIMIT 10",
                'params' => [],
                'default_limit' => 10,
            ];
        }

        return [
            'query_class' => 'top_target_labels_from_te',
            'metric_definition' => 'Count outgoing TE relations grouped by target node label.',
            'query_summary' => 'Counted which node labels are most commonly linked from TE nodes.',
            'cypher' => "MATCH (:TE)-[r]->(m)
                         WITH labels(m) AS label_list, count(r) AS value, collect(DISTINCT type(r))[0..5] AS relation_types
                         RETURN case when size(label_list) = 0 then 'Unlabeled' else label_list[0] end AS label,
                                value,
                                case when size(label_list) = 0 then 'Unknown' else label_list[0] end AS node_type,
                                relation_types
                         ORDER BY value DESC
                         LIMIT 10",
            'params' => [],
            'default_limit' => 10,
        ];
    }

    private function buildAnalyticsResult(array $plan, array $rows, string $generatedCypher, array $validatedFeatures): array
    {
        $topK = [];
        $graphNodes = [];
        $graphEdges = [];
        $seenNodes = [];

        foreach ($rows as $index => $row) {
            $label = trim((string)($row['label'] ?? ''));
            $value = (int)($row['value'] ?? 0);
            $nodeType = trim((string)($row['node_type'] ?? 'Unknown')) ?: 'Unknown';
            $relationTypes = array_values(array_filter(array_map('strval', (array)($row['relation_types'] ?? []))));

            $topK[] = [
                'rank' => $index + 1,
                'label' => $label,
                'value' => $value,
                'node_type' => $nodeType,
                'relation_types' => $relationTypes,
            ];

            $rootId = 'analytics-root';
            if (!isset($seenNodes[$rootId])) {
                $graphNodes[] = ['id' => $rootId, 'label' => 'Analytics', 'type' => 'Analytics'];
                $seenNodes[$rootId] = true;
            }

            $nodeId = 'node-' . md5($nodeType . '::' . $label);
            if (!isset($seenNodes[$nodeId])) {
                $graphNodes[] = ['id' => $nodeId, 'label' => $label !== '' ? $label : ('Rank ' . ($index + 1)), 'type' => $nodeType];
                $seenNodes[$nodeId] = true;
            }

            $graphEdges[] = [
                'id' => 'edge-' . md5($rootId . '::' . $nodeId),
                'source' => $rootId,
                'target' => $nodeId,
                'label' => (string)$value,
                'relation_type' => 'ANALYTIC_SCORE',
            ];
        }

        return tekg_agent_json_safe([
            'query_class' => (string)($plan['query_class'] ?? 'graph_analytics'),
            'metric_definition' => (string)($plan['metric_definition'] ?? ''),
            'generated_cypher' => $generatedCypher,
            'validated_features' => $validatedFeatures,
            'top_k' => $topK,
            'breakdown' => [
                'row_count' => count($rows),
                'top_label' => $topK[0]['label'] ?? '',
                'top_value' => $topK[0]['value'] ?? 0,
            ],
            'graph_elements' => [
                'nodes' => $graphNodes,
                'edges' => $graphEdges,
            ],
            'rows' => $rows,
        ]);
    }

    private function buildEvidenceItems(array $analytics): array
    {
        $items = [];
        foreach (array_slice((array)($analytics['top_k'] ?? []), 0, 5) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            $value = (int)($row['value'] ?? 0);
            $nodeType = trim((string)($row['node_type'] ?? 'entity'));
            if ($label === '') {
                continue;
            }
            $items[] = tekg_agent_make_evidence_item(
                $this->getName(),
                $label . ' ranked with an analytics score of ' . $value . ' under the current graph metric.',
                $label,
                'medium',
                [
                    'query_class' => (string)($analytics['query_class'] ?? ''),
                    'value' => $value,
                    'node_type' => $nodeType,
                ],
                [
                    'title' => $label,
                    'meta' => $nodeType . ' | score ' . $value,
                    'body' => 'This row came from a graph analytics aggregation query.',
                ],
                [
                    'evidence_type' => 'graph_metric',
                    'coverage_dimension' => 'graph_analytics',
                    'subject' => $label,
                    'provenance' => [
                        'source' => 'derived_graph_query',
                        'query_class' => (string)($analytics['query_class'] ?? ''),
                        'metric_definition' => (string)($analytics['metric_definition'] ?? ''),
                    ],
                    'diagnostic' => [
                        'rank' => (int)($row['rank'] ?? 0),
                        'metric_value' => $value,
                    ],
                    'quality_flags' => ['derived_metric'],
                ]
            );
        }
        return $items;
    }

    private function buildPreviewItems(array $analytics): array
    {
        $items = [];
        foreach (array_slice((array)($analytics['top_k'] ?? []), 0, 5) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'title' => (string)($row['label'] ?? ''),
                'meta' => trim((string)($row['node_type'] ?? '') . ' | score ' . (string)($row['value'] ?? 0)),
                'body' => 'Relation types: ' . implode(', ', array_map('strval', (array)($row['relation_types'] ?? []))),
            ];
        }
        return $items;
    }

    private function displaySummary(array $plan, array $analytics, array $errors): string
    {
        if ($errors !== []) {
            return 'The graph analytics query failed.';
        }
        if ((int)(($analytics['breakdown']['row_count'] ?? 0)) === 0) {
            return 'The graph analytics layer did not find a ranked result for this topology question.';
        }
        return 'The graph analytics layer computed a ranked result set for ' . (string)($plan['query_class'] ?? 'this question') . '.';
    }

    private function resultMessage(array $plan, array $analytics): string
    {
        $top = (array)($analytics['top_k'][0] ?? []);
        if ($top === []) {
            return 'This round did not produce a stable analytics ranking.';
        }
        return (string)($top['label'] ?? 'The top row') . ' is currently the strongest result under the metric "' . (string)($plan['metric_definition'] ?? '') . '".';
    }

    private function preferredTargetType(array $targetTypes): string
    {
        foreach (['Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Paper', 'TE'] as $candidate) {
            if (in_array($candidate, $targetTypes, true)) {
                return $candidate;
            }
        }
        return 'Disease';
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, tekg_agent_lower((string)$needle))) {
                return true;
            }
        }
        return false;
    }
}
