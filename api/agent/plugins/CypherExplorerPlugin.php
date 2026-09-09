<?php
declare(strict_types=1);

final class TekgAgentCypherExplorerPlugin implements TekgAgentPluginInterface
{
    public function __construct(
        private readonly TekgAgentNeo4jClient $neo4j,
        private readonly TekgAgentLlmClient $llm,
        private readonly array $config,
    ) {
    }

    public function getName(): string
    {
        return 'Cypher Explorer Plugin';
    }

    public function run(array $context): array
    {
        $started = microtime(true);
        $question = trim((string)($context['question'] ?? ''));
        $analysis = tekg_agent_context_analysis($context);
        $analysis['normalized_entities'] = tekg_agent_context_resolved_entities($context);
        $planning = is_array($context['planning'] ?? null) ? $context['planning'] : [];
        $model = trim((string)($context['config']['deepseek_model'] ?? $this->config['deepseek_model'] ?? 'deepseek-chat'));

        $generated = $this->generateCypher($question, $analysis, $planning, $model);
        $errors = [];

        try {
            $executed = $this->neo4j->runReadOnlyQuery((string)($generated['generated_cypher'] ?? ''), (array)($generated['params'] ?? []), 25);
            $rows = is_array($executed['rows'] ?? null) ? $executed['rows'] : [];
            $generatedCypher = (string)($executed['generated_cypher'] ?? ($generated['generated_cypher'] ?? ''));
            $validatedFeatures = is_array($executed['validated_features'] ?? null) ? $executed['validated_features'] : [];
        } catch (Throwable $error) {
            $rows = [];
            $generatedCypher = (string)($generated['generated_cypher'] ?? '');
            $validatedFeatures = [];
            $errors[] = $error->getMessage();
        }

        $columnSchema = $this->columnSchema($rows);
        $cypherResult = tekg_agent_json_safe([
            'query_intent' => (string)($generated['query_intent'] ?? 'graph_exploration'),
            'generated_cypher' => $generatedCypher,
            'validated_features' => $validatedFeatures,
            'rows' => array_slice($rows, 0, 25),
            'column_schema' => $columnSchema,
            'result_counts' => [
                'rows' => count($rows),
                'columns' => count($columnSchema),
            ],
            'errors' => $errors,
        ]);

        $previewItems = [];
        $evidenceItems = [];
        foreach (array_slice($rows, 0, 5) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $summary = [];
            foreach (array_slice(array_keys($row), 0, 4) as $key) {
                $summary[] = $key . '=' . $this->stringifyValue($row[$key] ?? null);
            }
            $previewItems[] = [
                'title' => 'Row ' . ($index + 1),
                'meta' => trim(implode(' | ', array_slice(array_keys($row), 0, 4))),
                'body' => implode('; ', $summary),
            ];
            $evidenceItems[] = tekg_agent_make_evidence_item(
                $this->getName(),
                'Cypher Explorer returned row ' . ($index + 1) . ': ' . implode('; ', $summary),
                'Cypher row ' . ($index + 1),
                'medium',
                [
                    'query_intent' => (string)($generated['query_intent'] ?? 'graph_exploration'),
                    'generated_cypher' => $generatedCypher,
                ],
                [
                    'title' => 'Cypher row ' . ($index + 1),
                    'meta' => trim(implode(' | ', array_slice(array_keys($row), 0, 4))),
                    'body' => implode('; ', $summary),
                ]
            );
        }

        $summary = $errors !== []
            ? 'The Cypher Explorer rejected or failed to execute the generated read-only query.'
            : 'The Cypher Explorer generated a read-only aggregation query and returned ' . count($rows) . ' rows.';

        return [
            'plugin_name' => $this->getName(),
            'status' => tekg_agent_plugin_status($rows !== [], $errors),
            'query_summary' => 'Generated and executed a validated read-only Cypher query for graph exploration.',
            'results' => [
                'cypher_result' => $cypherResult,
                'rows' => $cypherResult['rows'],
            ],
            'display_label' => 'Explored ' . count($rows) . ' Cypher rows',
            'display_summary' => $summary,
            'display_details' => [
                'summary' => $summary,
                'preview_items' => $previewItems,
                'evidence_items' => $evidenceItems,
                'citations' => [],
                'raw_preview' => $cypherResult,
                'result_message' => $errors === []
                    ? 'This read-only Cypher query expanded the graph exploration space beyond the fixed neighborhood plugins.'
                    : 'The read-only Cypher query could not be validated or executed in this round.',
            ],
            'result_counts' => $cypherResult['result_counts'],
            'evidence_items' => $evidenceItems,
            'citations' => [],
            'errors' => $errors,
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    private function generateCypher(string $question, array $analysis, array $planning, string $model): array
    {
        $heuristic = $this->heuristicCypher($question, $analysis);
        if ($heuristic !== null) {
            return $heuristic;
        }

        $payload = [
            'question' => $question,
            'answer_language' => (string)($analysis['answer_language'] ?? $analysis['language'] ?? 'english'),
            'process_language' => (string)($analysis['process_language'] ?? $analysis['answer_language'] ?? $analysis['language'] ?? 'english'),
            'analysis' => $analysis,
            'planning' => $planning,
            'allowed_clauses' => ['MATCH', 'OPTIONAL MATCH', 'WHERE', 'WITH', 'RETURN', 'ORDER BY', 'LIMIT', 'DISTINCT'],
            'allowed_aggregations' => ['count', 'collect', 'avg', 'sum', 'max', 'min'],
            'blocked_clauses' => ['CREATE', 'MERGE', 'SET', 'DELETE', 'DETACH', 'DROP', 'CALL dbms', 'CALL apoc'],
            'labels' => ['TE', 'Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Paper'],
        ];

        $generated = $this->llm->generateJson(
            $model,
            TekgAgentPromptLibrary::jsonInstructionPrompt('cypher_explorer', (string)($analysis['answer_language'] ?? $analysis['language'] ?? 'english')),
            $payload
        );

        if (is_array($generated) && trim((string)($generated['generated_cypher'] ?? '')) !== '') {
            return [
                'query_intent' => (string)($generated['query_intent'] ?? 'graph_exploration'),
                'generated_cypher' => trim((string)$generated['generated_cypher']),
                'params' => is_array($generated['params'] ?? null) ? $generated['params'] : [],
            ];
        }

        return [
            'query_intent' => 'graph_exploration',
            'generated_cypher' => "MATCH (:TE)-[r]->(m) RETURN labels(m)[0] AS target_label, count(r) AS relation_count ORDER BY relation_count DESC LIMIT 10",
            'params' => [],
        ];
    }

    private function heuristicCypher(string $question, array $analysis): ?array
    {
        $normalized = tekg_agent_lower($question);
        $entities = is_array($analysis['normalized_entities'] ?? null) ? $analysis['normalized_entities'] : [];
        $teEntities = array_values(array_filter($entities, static fn(array $entity): bool => ($entity['type'] ?? '') === 'TE'));

        if ($teEntities !== [] && str_contains($normalized, 'top 10 diseases')) {
            return [
                'query_intent' => 'top_diseases_for_te',
                'generated_cypher' => "MATCH (t:TE)-[r]->(d:Disease)
                                       WHERE replace(replace(replace(toLower(trim(coalesce(t.name,''))), '-', ''), '_', ''), ' ', '') = replace(replace(replace(toLower(trim(\$entity)), '-', ''), '_', ''), ' ', '')
                                       RETURN coalesce(d.name,'') AS disease, count(r) AS relation_count
                                       ORDER BY relation_count DESC
                                       LIMIT 10",
                'params' => ['entity' => (string)($teEntities[0]['canonical_label'] ?? $teEntities[0]['label'] ?? '')],
            ];
        }

        if (str_contains($normalized, 'node labels') || str_contains($normalized, 'most commonly linked')) {
            return [
                'query_intent' => 'target_label_distribution',
                'generated_cypher' => "MATCH (:TE)-[r]->(m)
                                       WITH labels(m) AS label_list, count(r) AS relation_count
                                       RETURN case when size(label_list) = 0 then 'Unknown' else label_list[0] end AS target_label,
                                              relation_count
                                       ORDER BY relation_count DESC
                                       LIMIT 10",
                'params' => [],
            ];
        }

        if ($teEntities !== [] && str_contains($normalized, 'protein') && str_contains($normalized, 'rna')) {
            return [
                'query_intent' => 'compare_target_types_for_te',
                'generated_cypher' => "MATCH (t:TE)-[r]->(m)
                                       WHERE replace(replace(replace(toLower(trim(coalesce(t.name,''))), '-', ''), '_', ''), ' ', '') = replace(replace(replace(toLower(trim(\$entity)), '-', ''), '_', ''), ' ', '')
                                       WITH labels(m) AS label_list, count(r) AS relation_count
                                       RETURN case when size(label_list) = 0 then 'Unknown' else label_list[0] end AS target_label,
                                              relation_count
                                       ORDER BY relation_count DESC
                                       LIMIT 10",
                'params' => ['entity' => (string)($teEntities[0]['canonical_label'] ?? $teEntities[0]['label'] ?? '')],
            ];
        }

        return null;
    }

    private function columnSchema(array $rows): array
    {
        if ($rows === [] || !is_array($rows[0])) {
            return [];
        }
        $schema = [];
        foreach (array_keys($rows[0]) as $column) {
            $schema[] = [
                'name' => (string)$column,
                'type' => gettype($rows[0][$column] ?? null),
            ];
        }
        return $schema;
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        return trim((string)$value);
    }
}
