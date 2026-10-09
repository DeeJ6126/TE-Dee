<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/contracts/AgentModelPolicy.php";

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
        $model = AgentModelPolicy::resolve($this->config);

        $arguments = is_array($context['qa_tool_arguments'] ?? null) ? $context['qa_tool_arguments'] : [];
        $explicit = isset($arguments['query']) && is_string($arguments['query']);
        // The shared QA loop has already selected the query; no second planner call.
        $generated = $explicit ? [
            'query_intent' => 'explicit_read_only_query',
            'generated_cypher' => trim($arguments['query']),
            'params' => is_array($arguments['params'] ?? null) ? $arguments['params'] : [],
            'metric_definition' => (string)($arguments['metric_definition'] ?? 'Read-only query values; no biological association-strength measure is available.'),
        ] : $this->generateCypher($question, $analysis, $planning, $model);
        $errors = [];

        try {
            $this->assertGroundedMetricQuery((string)($generated['generated_cypher'] ?? ''));
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
            'metric_definition' => (string)($generated['metric_definition'] ?? 'Values are bounded by the returned read-only query; no biological association-strength metric is available.'),
            'metric_boundary' => 'Graph counts, publication counts, and journal metrics do not measure biological association strength, causal support, or evidence quality.',
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
                'Graph query row ' . ($index + 1) . ': ' . implode('; ', $summary)
                    . '. Metric: ' . $cypherResult['metric_definition']
                    . ' Graph counts and ranks do not measure biological association strength or causality.',
                'Cypher row ' . ($index + 1),
                'medium',
                [
                    'query_intent' => (string)($generated['query_intent'] ?? 'graph_exploration'),
                    'generated_cypher' => $generatedCypher,
                    'metric_definition' => $cypherResult['metric_definition'],
                ],
                [
                    'title' => 'Cypher row ' . ($index + 1),
                    'meta' => trim(implode(' | ', array_slice(array_keys($row), 0, 4))),
                    'body' => implode('; ', $summary),
                ],
                [
                    'evidence_type' => 'graph_query_row',
                    'coverage_dimension' => 'graph_analytics',
                    'provenance' => ['source' => 'read_only_graph_query', 'query' => $generatedCypher],
                    'quality_flags' => ['query_bounded', 'count_not_association_strength'],
                ]
            );
        }

        $summary = $errors !== []
            ? 'The Cypher Explorer rejected or failed to execute the generated read-only query.'
            : 'The Cypher Explorer executed a validated read-only query and returned ' . count($rows) . ' rows.';

        return [
            'plugin_name' => $this->getName(),
            'status' => tekg_agent_plugin_status($rows !== [], $errors),
            'query_summary' => $explicit ? 'Executed the explicitly supplied query after read-only validation.' : 'Generated and executed a validated read-only Cypher query for graph exploration.',
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
            'known_relationship_properties' => ['predicate', 'description', 'pmids', 'support_pmid_count', 'support_metric_paper_count', 'support_metric_coverage', 'support_journal_count', 'support_publication_year_min', 'support_publication_year_max', 'support_if_max', 'support_if_mean', 'support_if_median'],
            'metric_constraints' => [
                'Relationships have no score, strength, weight, confidence, or biological association-strength field. Never invent these properties or replace missing values with a fabricated score.',
                'For graph-wide disease ranking use count(DISTINCT t) of linked TE nodes, with a separate count(r) if needed. Return explicit count column names.',
                'Publication counts and impact factors describe retrieval provenance; they are not scientific evidence quality or biological effect strength.',
                'Do not report generic TE / Transposable elements as one specific selected TE node.',
            ],
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
        $teEntities = array_values(array_filter($entities, static fn(array $entity): bool => ($entity['type'] ?? '') === 'TE'
            && !in_array(tekg_agent_normalize_lookup_token((string)($entity['canonical_label'] ?? $entity['label'] ?? '')), ['te', 'tes', 'transposableelements', 'transposableelement', 'humantransposons', 'humantransposableelements'], true)));

        if ($teEntities === []
            && ((bool)($analysis['asks_for_graph_analytics'] ?? false) || ($analysis['intent'] ?? '') === 'graph_analytics')
            && preg_match('/\bdiseases?\b|疾病/u', $normalized) === 1
        ) {
            return [
                'query_intent' => 'top_diseases_by_te_count',
                'metric_definition' => 'Count distinct TE nodes with a stored outgoing relationship to each Disease node; relation_count separately counts stored relationship records.',
                'generated_cypher' => "MATCH (t:TE)-[r]->(d:Disease)
                                       RETURN coalesce(d.name,'') AS disease,
                                              count(DISTINCT t) AS distinct_te_count,
                                              count(r) AS relation_count
                                       ORDER BY distinct_te_count DESC, disease ASC
                                       LIMIT 10",
                'params' => [],
            ];
        }

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

    private function assertGroundedMetricQuery(string $cypher): void
    {
        // The active TE-Disease schema stores counts/provenance, not an association-strength score.
        if (preg_match('/\.\s*`?(?:score|strength|weight|confidence|evidence_score|association_score|association_strength|support_strength)`?\b/iu', $cypher) === 1
            || preg_match('/\bAS\s+`?(?:score|strength|confidence|evidence_score|association_score|association_strength|support_strength)`?\b/iu', $cypher) === 1
        ) {
            throw new RuntimeException('The graph query requested an undefined strength/score metric. Use explicitly defined graph counts or recorded provenance fields.');
        }
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
