<?php
declare(strict_types=1);

final class TekgAgentGraphPlugin implements TekgAgentPluginInterface
{
    public function __construct(
        private readonly TekgAgentNeo4jClient $neo4j,
        private readonly TekgAgentCitationResolver $citationResolver,
    ) {
    }

    public function getName(): string
    {
        return 'Graph Plugin';
    }

    public function run(array $context): array
    {
        $started = microtime(true);
        $analysis = tekg_agent_context_analysis($context);
        $intent = (string)($analysis['intent'] ?? 'relationship');
        $entities = tekg_agent_context_resolved_entities($context);
        $targetTypes = is_array($analysis['requested_target_types'] ?? null) ? $analysis['requested_target_types'] : [];

        $teEntities = array_values(array_filter($entities, static fn(array $item): bool => ($item['type'] ?? '') === 'TE'));
        $diseaseEntities = array_values(array_filter($entities, static fn(array $item): bool => ($item['type'] ?? '') === 'Disease'));

        try {
            if (count($teEntities) >= 2) {
                $rows = $this->queryTePairRelationship($teEntities[0], $teEntities[1]);
                return $this->finish($started, $intent, 'Compared two TE entities in the local graph.', $rows);
            }

            if ($teEntities !== [] && $diseaseEntities !== []) {
                $rows = $this->queryDiseasePair($teEntities[0], $diseaseEntities[0]);
                return $this->finish($started, $intent, 'Queried a TE-disease evidence pair in the local graph.', $rows);
            }

            if ($teEntities !== []) {
                $rows = $this->queryTypedRelations($teEntities[0], $this->normalizeTargetTypes($targetTypes, $intent));
                return $this->finish($started, $intent, 'Collected structured relations from the local graph.', $rows);
            }

            if (($analysis['asks_for_mechanism'] ?? false) && ($analysis['question_keywords'] ?? []) !== []) {
                $rows = $this->queryCancerAssociatedTes();
                return $this->finish($started, $intent, 'Collected mechanism-related TE candidates from the local graph.', $rows);
            }

            return $this->finish($started, $intent, 'No graph entity candidates were recognized for this question.', []);
        } catch (Throwable $error) {
            return $this->finish($started, $intent, 'Graph query failed.', [], [$error->getMessage()]);
        }
    }

    private function queryTypedRelations(array $entity, array $targetTypes): array
    {
        $rows = [];
        foreach ($targetTypes as $targetType) {
            $cypher = sprintf(
                "MATCH (t:TE)-[r]->(m:%s)
                 WHERE %s = toLower(trim(\$entity))
                 RETURN coalesce(t.name,'') AS source_name,
                        labels(m) AS target_labels,
                        coalesce(m.name,'') AS target_name,
                        type(r) AS relation_type,
                        coalesce(r.description,'') AS relation_description,
                        coalesce(r.pmids, []) AS pmids,
                        coalesce(r.evidence, []) AS evidence
                 LIMIT 24",
                $targetType,
                $this->cypherNormalizedNameExpr('t')
            );
            foreach ($this->entityCandidateGroups($entity) as $mode => $candidates) {
                foreach ($candidates as $candidate) {
                    $candidateRows = $this->neo4j->run($cypher, ['entity' => $candidate]);
                    if ($candidateRows === []) {
                        continue;
                    }
                    foreach ($candidateRows as $row) {
                        $row['target_type'] = $targetType;
                        $row['matched_alias'] = $candidate;
                        $row['alias_mode'] = $mode;
                        $rows[] = $row;
                    }
                    break 2;
                }
            }
        }
        return $rows;
    }

    private function queryDiseasePair(array $teEntity, array $diseaseEntity): array
    {
        $cypher = "MATCH (t:TE)-[r]->(d:Disease)
                   WHERE {$this->cypherNormalizedNameExpr('t')} = toLower(trim(\$te))
                     AND {$this->cypherNormalizedNameExpr('d')} = toLower(trim(\$disease))
                   RETURN coalesce(t.name,'') AS source_name,
                          ['Disease'] AS target_labels,
                          'Disease' AS target_type,
                          coalesce(d.name,'') AS target_name,
                          type(r) AS relation_type,
                          coalesce(r.description,'') AS relation_description,
                          coalesce(r.pmids, []) AS pmids,
                          coalesce(r.evidence, []) AS evidence
                   LIMIT 20";

        foreach ($this->entityCandidateGroups($teEntity) as $teMode => $teCandidates) {
            foreach ($teCandidates as $teCandidate) {
                foreach ($this->entityCandidateGroups($diseaseEntity) as $diseaseMode => $diseaseCandidates) {
                    foreach ($diseaseCandidates as $diseaseCandidate) {
                        $rows = $this->neo4j->run($cypher, ['te' => $teCandidate, 'disease' => $diseaseCandidate]);
                        if ($rows === []) {
                            continue;
                        }
                        foreach ($rows as &$row) {
                            $row['matched_alias'] = $teCandidate;
                            $row['matched_disease_alias'] = $diseaseCandidate;
                            $row['alias_mode'] = $teMode === 'strict' && $diseaseMode === 'strict' ? 'strict' : 'broad';
                        }
                        unset($row);
                        return $rows;
                    }
                }
            }
        }
        return [];
    }

    private function queryTePairRelationship(array $leftEntity, array $rightEntity): array
    {
        foreach ($this->entityCandidateGroups($leftEntity) as $leftMode => $leftCandidates) {
            foreach ($leftCandidates as $left) {
                foreach ($this->entityCandidateGroups($rightEntity) as $rightMode => $rightCandidates) {
                    foreach ($rightCandidates as $right) {
                        $rows = $this->neo4j->run(
                            "MATCH (a:TE)-[:SUBFAMILY_OF*1..4]->(b:TE)
                             WHERE {$this->cypherNormalizedNameExpr('a')} = toLower(trim(\$left))
                               AND {$this->cypherNormalizedNameExpr('b')} = toLower(trim(\$right))
                             RETURN coalesce(a.name,'') AS source_name,
                                    ['TE'] AS target_labels,
                                    'TE' AS target_type,
                                    coalesce(b.name,'') AS target_name,
                                    'SUBFAMILY_OF' AS relation_type,
                                    'Tree lineage relationship' AS relation_description,
                                    [] AS pmids,
                                    [] AS evidence
                             LIMIT 5",
                            ['left' => $left, 'right' => $right]
                        );
                        if ($rows !== []) {
                            foreach ($rows as &$row) {
                                $row['matched_alias'] = $left;
                                $row['matched_right_alias'] = $right;
                                $row['alias_mode'] = $leftMode === 'strict' && $rightMode === 'strict' ? 'strict' : 'broad';
                            }
                            unset($row);
                            return $rows;
                        }

                        $rows = $this->neo4j->run(
                            "MATCH (b:TE)-[:SUBFAMILY_OF*1..4]->(a:TE)
                             WHERE {$this->cypherNormalizedNameExpr('a')} = toLower(trim(\$left))
                               AND {$this->cypherNormalizedNameExpr('b')} = toLower(trim(\$right))
                             RETURN coalesce(a.name,'') AS source_name,
                                    ['TE'] AS target_labels,
                                    'TE' AS target_type,
                                    coalesce(b.name,'') AS target_name,
                                    'HAS_SUBFAMILY' AS relation_type,
                                    'Tree lineage relationship' AS relation_description,
                                    [] AS pmids,
                                    [] AS evidence
                             LIMIT 5",
                            ['left' => $left, 'right' => $right]
                        );
                        if ($rows !== []) {
                            foreach ($rows as &$row) {
                                $row['matched_alias'] = $left;
                                $row['matched_right_alias'] = $right;
                                $row['alias_mode'] = $leftMode === 'strict' && $rightMode === 'strict' ? 'strict' : 'broad';
                            }
                            unset($row);
                            return $rows;
                        }
                    }
                }
            }
        }

        return [];
    }

    private function queryCancerAssociatedTes(): array
    {
        $cypher = "MATCH (t:TE)-[r]->(d:Disease)
                   WHERE toLower(coalesce(d.name,'')) CONTAINS 'cancer'
                      OR toLower(coalesce(d.name,'')) CONTAINS 'carcinoma'
                      OR toLower(coalesce(d.name,'')) CONTAINS 'tumor'
                      OR toLower(coalesce(d.name,'')) CONTAINS 'tumour'
                   RETURN coalesce(t.name,'') AS source_name,
                          ['Disease'] AS target_labels,
                          'Disease' AS target_type,
                          coalesce(d.name,'') AS target_name,
                          type(r) AS relation_type,
                          coalesce(r.description,'') AS relation_description,
                          coalesce(r.pmids, []) AS pmids,
                          coalesce(r.evidence, []) AS evidence
                   LIMIT 30";
        return $this->neo4j->run($cypher);
    }

    private function finish(float $started, string $intent, string $querySummary, array $rows, array $errors = []): array
    {
        $grouped = [];
        $evidenceItems = [];
        $previewItems = [];
        $citations = [];
        $pmidTitleMap = $this->loadPmidTitleMap($rows);

        foreach ($rows as $row) {
            $targetType = $this->resolveTargetType($row);
            $grouped[$targetType] = ($grouped[$targetType] ?? 0) + 1;
            $sentence = $this->rowSentence($row);
            $rowCitations = $this->backfillCitationTitles(
                $this->citationResolver->normalizeMany($this->rowCitations($row, $pmidTitleMap), 'local_graph'),
                $pmidTitleMap
            );
            if ($sentence !== '') {
                $evidenceItems[] = tekg_agent_make_evidence_item(
                    $this->getName(),
                    $sentence,
                    trim((string)($row['source_name'] ?? '')),
                    'medium',
                    [
                        'relation_type' => (string)($row['relation_type'] ?? ''),
                        'matched_alias' => (string)($row['matched_alias'] ?? ''),
                        'target_type' => $targetType,
                    ],
                    [
                        'title' => trim((string)($row['source_name'] ?? '')) !== '' ? trim((string)($row['source_name'] ?? '')) : $sentence,
                        'meta' => trim($targetType . ' | ' . (string)($row['relation_type'] ?? 'related_to')),
                        'body' => $sentence,
                    ],
                    [
                        'evidence_type' => 'graph_relation',
                        'coverage_dimension' => 'relationship',
                        'subject' => trim((string)($row['source_name'] ?? '')),
                        'object' => trim((string)($row['target_name'] ?? '')),
                        'provenance' => ['source' => 'local_graph'],
                        'citations' => $rowCitations,
                        'quality_flags' => ['association_not_causality'],
                    ]
                );
                if (count($previewItems) < 5) {
                    $previewItems[] = ['title' => $sentence, 'meta' => $targetType];
                }
            }
            $citations = array_merge($citations, $rowCitations);
        }

        $citations = $this->citationResolver->normalizeMany($citations, 'local_graph');
        $citations = $this->backfillCitationTitles($citations, $pmidTitleMap);
        $resultCounts = [
            'relations' => count($rows),
            'entity_types' => count($grouped),
            'strict_matches' => count(array_filter($rows, static fn(array $row): bool => ($row['alias_mode'] ?? 'strict') === 'strict')),
            'broad_matches' => count(array_filter($rows, static fn(array $row): bool => ($row['alias_mode'] ?? 'strict') === 'broad')),
        ];
        foreach ($grouped as $type => $count) {
            $resultCounts[$type] = $count;
        }

        $displayLabel = 'Queried ' . count($rows) . ' graph relations';
        $displaySummary = $this->buildDisplaySummary($intent, $grouped, count($rows), $errors);
        $resultMessage = $this->buildResultMessage($intent, $grouped, count($rows));
        $graphElements = $this->buildGraphElements($rows);

        return [
            'plugin_name' => $this->getName(),
            'status' => tekg_agent_plugin_status($rows !== [], $errors),
            'query_summary' => $querySummary,
            'results' => [
                'rows' => $rows,
                'by_type' => $grouped,
                'graph_elements' => $graphElements,
            ],
            'display_label' => $displayLabel,
            'display_summary' => $displaySummary,
            'display_details' => [
                'summary' => $displaySummary,
                'preview_items' => $previewItems,
                'evidence_items' => $evidenceItems,
                'citations' => $citations,
                'raw_preview' => ['rows' => $rows, 'by_type' => $grouped, 'graph_elements' => $graphElements],
                'result_message' => $resultMessage,
            ],
            'result_counts' => $resultCounts,
            'evidence_items' => $evidenceItems,
            'citations' => $citations,
            'errors' => $errors,
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    private function buildDisplaySummary(string $intent, array $grouped, int $count, array $errors): string
    {
        if ($errors !== []) {
            return 'The graph query failed.';
        }
        if ($count === 0) {
            return 'The structured graph did not provide enough direct hits under the current strict alias chain, so the next step should rely on broader aliases or external evidence.';
        }
        $types = implode(', ', array_keys($grouped));
        return $intent === 'mechanism'
            ? 'I first collected ' . $count . ' structured relations from the local graph, mainly across ' . $types . '.'
            : 'The local graph returned ' . $count . ' structured relations, mainly across ' . $types . '.';
    }

    private function buildResultMessage(string $intent, array $grouped, int $count): string
    {
        if ($count === 0) {
            return 'This round did not produce a strong local relation chain, so the answer should lean on literature or other supporting layers.';
        }
        $topTypes = array_slice(array_keys($grouped), 0, 4);
        return $intent === 'mechanism'
            ? 'These relations suggest that the next mechanism draft should focus on ' . implode(', ', $topTypes) . '.'
            : 'These relations are enough for a first structured judgment, especially along ' . implode(', ', $topTypes) . '.';
    }

    private function rowSentence(array $row): string
    {
        $source = trim((string)($row['source_name'] ?? ''));
        $target = trim((string)($row['target_name'] ?? ''));
        $relation = trim((string)($row['relation_type'] ?? 'related_to'));
        if ($source === '' || $target === '') {
            return '';
        }
        $description = trim((string)($row['relation_description'] ?? ''));
        $summary = $source . ' ' . $relation . ' ' . $target;
        if ($description !== '') {
            $summary .= ' (' . $description . ')';
        }
        return $summary;
    }

    private function rowCitations(array $row, array $pmidTitleMap): array
    {
        $citations = $this->citationResolver->fromPmids((array)($row['pmids'] ?? []), 'local_graph', $pmidTitleMap);
        foreach ((array)($row['evidence'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $citations[] = [
                'source' => 'local_graph',
                'pmid' => trim((string)($item['pmid'] ?? '')),
                'title' => trim((string)($item['title'] ?? '')),
                'year' => trim((string)($item['year'] ?? '')),
                'journal' => trim((string)($item['journal'] ?? '')),
                'url' => trim((string)($item['url'] ?? '')),
                'abstract_summary' => trim((string)($item['summary'] ?? '')),
                'relevance' => 'Graph evidence item',
            ];
        }
        return $citations;
    }

    private function loadPmidTitleMap(array $rows): array
    {
        $pmids = [];
        foreach ($rows as $row) {
            foreach ((array)($row['pmids'] ?? []) as $pmid) {
                $value = trim((string)$pmid);
                if ($value !== '') {
                    $pmids[$value] = true;
                }
            }
            foreach ((array)($row['evidence'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $value = trim((string)($item['pmid'] ?? ''));
                if ($value !== '') {
                    $pmids[$value] = true;
                }
            }
        }
        if ($pmids === []) {
            return [];
        }
        $map = [];
        $rows = $this->neo4j->run(
            "MATCH (p:Paper)
             WHERE coalesce(p.pmid,'') IN \$pmids
             RETURN coalesce(p.pmid,'') AS pmid, coalesce(p.name,'') AS title",
            ['pmids' => array_values(array_map('strval', array_keys($pmids)))]
        );
        foreach ($rows as $row) {
            $pmid = trim((string)($row['pmid'] ?? ''));
            $title = trim((string)($row['title'] ?? ''));
            if ($pmid !== '' && $title !== '') {
                $map[$pmid] = $title;
            }
        }
        return $map;
    }

    private function backfillCitationTitles(array $citations, array $pmidTitleMap): array
    {
        $missingPmids = [];
        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $pmid = trim((string)($citation['pmid'] ?? ''));
            $title = trim((string)($citation['title'] ?? ''));
            if ($pmid !== '' && $title === '' && !isset($pmidTitleMap[$pmid])) {
                $missingPmids[$pmid] = true;
            }
        }

        if ($missingPmids !== []) {
            $rows = $this->neo4j->run(
                "MATCH (p:Paper)
                 WHERE coalesce(p.pmid,'') IN \$pmids
                 RETURN coalesce(p.pmid,'') AS pmid, coalesce(p.name,'') AS title",
                ['pmids' => array_values(array_map('strval', array_keys($missingPmids)))]
            );
            foreach ($rows as $row) {
                $pmid = trim((string)($row['pmid'] ?? ''));
                $title = trim((string)($row['title'] ?? ''));
                if ($pmid !== '' && $title !== '') {
                    $pmidTitleMap[$pmid] = $title;
                }
            }
        }

        foreach ($citations as &$citation) {
            if (!is_array($citation)) {
                continue;
            }
            $pmid = trim((string)($citation['pmid'] ?? ''));
            $title = trim((string)($citation['title'] ?? ''));
            if ($pmid !== '' && $title === '' && isset($pmidTitleMap[$pmid])) {
                $citation['title'] = $pmidTitleMap[$pmid];
            }
        }
        unset($citation);

        return $citations;
    }

    private function normalizeTargetTypes(array $targetTypes, string $intent): array
    {
        $allowed = ['Disease', 'Function', 'Paper', 'Gene', 'Protein', 'RNA', 'Mutation', 'Pharmaceutical', 'Toxin', 'Lipid', 'Peptide', 'Carbohydrate', 'TE'];
        $targetTypes = array_values(array_intersect($allowed, array_values(array_unique(array_map('strval', $targetTypes)))));
        if ($targetTypes !== []) {
            return $targetTypes;
        }
        return match ($intent) {
            'literature' => ['Paper'],
            'mechanism' => ['Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Disease'],
            default => ['Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Paper', 'TE'],
        };
    }

    private function resolveTargetType(array $row): string
    {
        if (!empty($row['target_type'])) {
            return (string)$row['target_type'];
        }
        $labels = array_map('strval', (array)($row['target_labels'] ?? []));
        foreach (['Disease', 'Function', 'Paper', 'Gene', 'Protein', 'RNA', 'Mutation', 'Pharmaceutical', 'Toxin', 'Lipid', 'Peptide', 'Carbohydrate', 'TE'] as $candidate) {
            if (in_array($candidate, $labels, true)) {
                return $candidate;
            }
        }
        return 'Unknown';
    }

    private function entityCandidateGroups(array $entity): array
    {
        return tekg_agent_entity_candidate_groups($entity);
    }

    private function cypherNormalizedNameExpr(string $alias): string
    {
        return "replace(replace(replace(toLower(trim(coalesce($alias.name,''))), '-', ''), '_', ''), ' ', '')";
    }

    private function buildGraphElements(array $rows): array
    {
        $nodes = [];
        $edges = [];
        foreach ($rows as $index => $row) {
            $source = trim((string)($row['source_name'] ?? ''));
            $target = trim((string)($row['target_name'] ?? ''));
            if ($source === '' || $target === '') {
                continue;
            }
            $sourceId = 'graph:' . md5('node:' . $source . ':TE');
            $targetType = $this->resolveTargetType($row);
            $targetId = 'graph:' . md5('node:' . $target . ':' . $targetType);
            $nodes[$sourceId] = [
                'id' => $sourceId,
                'label' => $source,
                'displayLabel' => $source,
                'nodeType' => 'TE',
                'description' => 'Source TE entity in the current graph result set.',
            ];
            $nodes[$targetId] = [
                'id' => $targetId,
                'label' => $target,
                'displayLabel' => $target,
                'nodeType' => $targetType,
                'description' => trim((string)($row['relation_description'] ?? '')),
            ];
            $edges[] = [
                'id' => 'graph-edge-' . $index,
                'source' => $sourceId,
                'target' => $targetId,
                'label' => trim((string)($row['relation_type'] ?? 'related_to')),
                'relationType' => trim((string)($row['relation_type'] ?? 'related_to')),
            ];
        }

        return [
            'nodes' => array_values($nodes),
            'edges' => $edges,
        ];
    }
}
