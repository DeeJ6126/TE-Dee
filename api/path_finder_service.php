<?php
declare(strict_types=1);

const PATH_FINDER_MAX_DEPTH = 10;
const PATH_FINDER_QUERY_BUDGET_SECONDS = 15;
const PATH_FINDER_PER_DEPTH_TIMEOUT_SECONDS = 5;

function path_finder_clamp_depth(int $depth): int
{
    return max(1, min(PATH_FINDER_MAX_DEPTH, $depth));
}

function path_finder_entity_type_options(): array
{
    return ['TE', 'Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Pharmaceutical', 'Toxin', 'Lipid', 'Peptide', 'Carbohydrate'];
}

function path_finder_normalize_entity_type(string $type): string
{
    $type = trim($type);
    if ($type === '') {
        return '';
    }
    foreach (path_finder_entity_type_options() as $option) {
        if (strcasecmp($type, $option) === 0) {
            return $option;
        }
    }
    return '';
}

final class PathFinderService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function find(string $sourceQuery, string $targetQuery, int $maxDepth, string $sourceType = '', string $targetType = ''): array
    {
        $sourceQuery = trim($sourceQuery);
        $targetQuery = trim($targetQuery);
        $sourceType = path_finder_normalize_entity_type($sourceType);
        $targetType = path_finder_normalize_entity_type($targetType);
        $maxDepth = path_finder_clamp_depth($maxDepth);

        if ($sourceQuery === '' || $targetQuery === '') {
            return [
                'ok' => false,
                'error' => 'Both source and target are required.',
                'max_depth' => $maxDepth,
            ];
        }

        $source = $this->resolveEntity($sourceQuery, $sourceType);
        $target = $this->resolveEntity($targetQuery, $targetType);

        if ($source === null || $target === null) {
            return [
                'ok' => false,
                'error' => $source === null
                    ? 'Source entity was not found in the selected entity type.'
                    : 'Target entity was not found in the selected entity type.',
                'source_query' => $sourceQuery,
                'target_query' => $targetQuery,
                'source_type' => $sourceType,
                'target_type' => $targetType,
                'source' => $source,
                'target' => $target,
                'max_depth' => $maxDepth,
                'paths' => [],
            ];
        }

        $pathResult = $this->loadPaths((string)$source['element_id'], (string)$target['element_id'], $maxDepth);
        $paths = $pathResult['paths'];

        return [
            'ok' => true,
            'source_query' => $sourceQuery,
            'target_query' => $targetQuery,
            'source_type' => $sourceType,
            'target_type' => $targetType,
            'source' => $source,
            'target' => $target,
            'max_depth' => $maxDepth,
            'path_count' => count($paths),
            'paths' => $paths,
            'search_truncated' => $pathResult['search_truncated'],
            'searched_through_hop' => $pathResult['searched_through_hop'],
        ];
    }

    public function suggestEntities(string $entityType, string $query = '', int $limit = 180): array
    {
        $entityType = path_finder_normalize_entity_type($entityType);
        if ($entityType === '') {
            throw new InvalidArgumentException('Unsupported entity type.');
        }

        $query = $this->normalizeQuery($query);
        $match = $this->autocompleteMatchConfig($entityType, $query);
        $limit = max(1, min(300, $limit));
        $rows = $this->runNeo4j(
            sprintf(
                <<<'CYPHER'
MATCH (n)
WHERE $entity_type IN labels(n)
  AND NOT 'Paper' IN labels(n)
  AND trim(toString(coalesce(n.name, ''))) <> ''
WITH n, trim(toString(n.name)) AS name, toLower(trim(toString(n.name))) AS lower_name
WHERE $query = ''
   OR any(term IN $terms WHERE lower_name STARTS WITH term)
   OR any(term IN $terms WHERE size(term) >= 3 AND lower_name CONTAINS term)
WITH name, collect(n)[0] AS n,
     min(CASE
       WHEN lower_name IN $preferred_names THEN 0
       WHEN any(term IN $terms WHERE lower_name = term) THEN 1
       WHEN any(term IN $terms WHERE lower_name STARTS WITH term) THEN 2
       WHEN any(term IN $terms WHERE size(term) >= 3 AND lower_name CONTAINS term) THEN 3
       ELSE 4
     END) AS match_rank
RETURN elementId(n) AS element_id,
       labels(n) AS labels,
       name AS name,
       n.description AS description,
       n.pmid AS pmid,
       n.disease_class AS disease_class
ORDER BY match_rank ASC, toLower(name)
LIMIT %d
CYPHER,
                $limit
            ),
            [
                'entity_type' => $entityType,
                'query' => $query,
                'terms' => $match['terms'],
                'preferred_names' => $match['preferred_names'],
            ]
        );

        return array_map(fn(array $row): array => $this->normalizeNode($row), $rows);
    }

    public function suggestConnectedCandidates(
        string $sourceQuery,
        string $sourceType,
        string $targetType,
        string $query = '',
        int $maxDepth = 3,
        int $limit = 180
    ): array {
        $sourceQuery = trim($sourceQuery);
        $sourceType = path_finder_normalize_entity_type($sourceType);
        $targetType = path_finder_normalize_entity_type($targetType);
        if ($targetType === '') {
            throw new InvalidArgumentException('Unsupported target entity type.');
        }

        if ($sourceQuery === '') {
            return [];
        }

        $source = $this->resolveEntity($sourceQuery, $sourceType);
        if ($source === null || trim((string)($source['element_id'] ?? '')) === '') {
            return [];
        }

        $query = $this->normalizeQuery($query);
        $match = $this->autocompleteMatchConfig($targetType, $query);
        $depth = path_finder_clamp_depth($maxDepth);
        $limit = max(1, min(180, $limit));
        $rows = $this->runNeo4j(
            sprintf(
                <<<'CYPHER'
MATCH (source)
WHERE elementId(source) = $source_id
MATCH (candidate)
WHERE elementId(candidate) <> elementId(source)
  AND $target_type IN labels(candidate)
  AND NOT 'Paper' IN labels(candidate)
  AND trim(toString(coalesce(candidate.name, ''))) <> ''
WITH source,
     candidate,
     toLower(trim(toString(candidate.name))) AS lower_name
WHERE $query = ''
   OR any(term IN $terms WHERE lower_name STARTS WITH term)
   OR any(term IN $terms WHERE size(term) >= 3 AND lower_name CONTAINS term)
MATCH p = allShortestPaths((source)-[:BIO_RELATION*1..%d]-(candidate))
WHERE ALL(node IN nodes(p) WHERE NOT 'Paper' IN labels(node))
WITH candidate,
     lower_name,
     length(p) AS hop,
     reduce(path_pmids = [], rel IN relationships(p) | path_pmids + coalesce(rel.pmids, [])) AS path_pmids
WITH candidate,
     min(CASE
       WHEN lower_name IN $preferred_names THEN 0
       WHEN any(term IN $terms WHERE lower_name = term) THEN 1
       WHEN any(term IN $terms WHERE lower_name STARTS WITH term) THEN 2
       WHEN any(term IN $terms WHERE size(term) >= 3 AND lower_name CONTAINS term) THEN 3
       ELSE 4
     END) AS match_rank,
     min(hop) AS min_hop,
     count(*) AS path_count,
     reduce(all_pmids = [], pmids IN collect(path_pmids) | all_pmids + pmids) AS all_pmids
WITH candidate,
     match_rank,
     min_hop,
     path_count,
     reduce(unique_pmids = [], pmid IN all_pmids |
       CASE
         WHEN pmid IS NULL OR trim(toString(pmid)) = '' OR trim(toString(pmid)) IN unique_pmids THEN unique_pmids
         ELSE unique_pmids + trim(toString(pmid))
       END
     ) AS unique_pmids
RETURN elementId(candidate) AS element_id,
       labels(candidate) AS labels,
       trim(toString(candidate.name)) AS name,
       candidate.description AS description,
       candidate.pmid AS pmid,
       candidate.disease_class AS disease_class,
       min_hop AS min_hop,
       path_count AS path_count,
       size(unique_pmids) AS pmid_count
ORDER BY match_rank ASC, min_hop ASC, pmid_count DESC, path_count DESC, toLower(name)
LIMIT %d
CYPHER,
                $depth,
                $limit
            ),
            [
                'source_id' => (string)$source['element_id'],
                'target_type' => $targetType,
                'query' => $query,
                'terms' => $match['terms'],
                'preferred_names' => $match['preferred_names'],
            ]
        );

        return array_map(fn(array $row): array => $this->normalizeConnectedCandidate($row), $rows);
    }

    private function resolveEntity(string $query, string $entityType = ''): ?array
    {
        $normalized = $this->normalizeQuery($query);
        $entityType = path_finder_normalize_entity_type($entityType);
        $rows = $this->runNeo4j(
            <<<'CYPHER'
MATCH (n)
WHERE NOT 'Paper' IN labels(n)
  AND ($entity_type = '' OR $entity_type IN labels(n))
  AND (toLower(coalesce(n.name, '')) = toLower($exact) OR coalesce(n.pmid, '') = $pmid)
RETURN elementId(n) AS element_id,
       labels(n) AS labels,
       n.name AS name,
       n.description AS description,
       n.pmid AS pmid,
       n.disease_class AS disease_class
ORDER BY
  CASE
    WHEN 'TE' IN labels(n) THEN 0
    WHEN 'Disease' IN labels(n) THEN 1
    WHEN 'Function' IN labels(n) THEN 2
    ELSE 3
  END,
  size(coalesce(n.name, ''))
LIMIT 10
CYPHER,
            ['exact' => $normalized, 'pmid' => $query, 'entity_type' => $entityType]
        );

        if (empty($rows) && $this->allowFuzzy($normalized)) {
            $rows = $this->runNeo4j(
                <<<'CYPHER'
MATCH (n)
WHERE NOT 'Paper' IN labels(n)
  AND ($entity_type = '' OR $entity_type IN labels(n))
  AND toLower(coalesce(n.name, '')) CONTAINS toLower($keyword)
RETURN elementId(n) AS element_id,
       labels(n) AS labels,
       n.name AS name,
       n.description AS description,
       n.pmid AS pmid,
       n.disease_class AS disease_class
ORDER BY
  CASE
    WHEN 'TE' IN labels(n) THEN 0
    WHEN 'Disease' IN labels(n) THEN 1
    WHEN 'Function' IN labels(n) THEN 2
    ELSE 3
  END,
  size(coalesce(n.name, ''))
LIMIT 10
CYPHER,
                ['keyword' => $normalized, 'entity_type' => $entityType]
            );
        }

        if (empty($rows)) {
            return null;
        }

        $matches = array_map(fn(array $row): array => $this->normalizeNode($row), $rows);
        $primary = $matches[0];
        $primary['matches'] = $matches;
        return $primary;
    }

    private function loadPaths(string $sourceId, string $targetId, int $maxDepth): array
    {
        $depth = path_finder_clamp_depth($maxDepth);
        $deadline = microtime(true) + PATH_FINDER_QUERY_BUDGET_SECONDS;
        try {
            $shortestRows = $this->runNeo4j(
                sprintf(
                    <<<'CYPHER'
MATCH (source), (target)
WHERE elementId(source) = $sourceId AND elementId(target) = $targetId
MATCH p = shortestPath((source)-[:BIO_RELATION*1..%d]-(target))
WHERE ALL(node IN nodes(p) WHERE NONE(label IN labels(node) WHERE label = 'Paper'))
WITH p, nodes(p) AS pathNodes, relationships(p) AS pathRels
RETURN
  [node IN pathNodes | {
    element_id: elementId(node),
    labels: labels(node),
    name: node.name,
    description: node.description,
    pmid: node.pmid,
    disease_class: node.disease_class
  }] AS nodes,
  [i IN range(0, size(pathRels) - 1) | {
    source: elementId(pathNodes[i]),
    target: elementId(pathNodes[i + 1]),
    relation_type: type(pathRels[i]),
    relation_label: coalesce(pathRels[i].predicate, type(pathRels[i])),
    evidence: coalesce(pathRels[i].evidence, ''),
    pmids: coalesce(pathRels[i].pmids, [])
  }] AS edges,
  length(p) AS hop_count,
  reduce(total = 0, rel IN pathRels | total + size(coalesce(rel.pmids, []))) AS pmid_count
LIMIT 1
CYPHER,
                    $depth
                ),
                ['sourceId' => $sourceId, 'targetId' => $targetId],
                $this->remainingQuerySeconds($deadline)
            );
        } catch (RuntimeException $error) {
            if (stripos($error->getMessage(), 'timed out') !== false) {
                return [
                    'paths' => [],
                    'search_truncated' => true,
                    'searched_through_hop' => 0,
                ];
            }
            throw $error;
        }
        if ($shortestRows === []) {
            return [
                'paths' => [],
                'search_truncated' => false,
                'searched_through_hop' => $depth,
            ];
        }

        $minimumDepth = max(1, (int)($shortestRows[0]['hop_count'] ?? 1));
        $rows = $minimumDepth > 3 ? $shortestRows : [];
        $resultLimit = 25;
        $searchTruncated = $minimumDepth > 3;
        $searchedThroughHop = $minimumDepth > 3 ? $minimumDepth : max(0, $minimumDepth - 1);
        $supplementalDepth = min($depth, 3);
        for ($hop = $minimumDepth; $hop <= $supplementalDepth && count($rows) < $resultLimit; $hop++) {
            $remainingSeconds = $this->remainingQuerySeconds($deadline);
            if ($remainingSeconds < 1) {
                $searchTruncated = true;
                break;
            }
            $remaining = $resultLimit - count($rows);
            $sampleLimit = min(250, max(50, $remaining * 10));
            $orderClause = $hop <= 3 ? 'ORDER BY pmid_count DESC, path_key ASC' : '';
            try {
                $depthRows = $this->runNeo4j(
                    sprintf(
            <<<'CYPHER'
MATCH (source), (target)
WHERE elementId(source) = $sourceId AND elementId(target) = $targetId
MATCH p = (source)-[:BIO_RELATION*%d..%d]-(target)
WHERE ALL(node IN nodes(p) WHERE NONE(label IN labels(node) WHERE label = 'Paper'))
  AND size(nodes(p)) = size(reduce(unique_ids = [], node IN nodes(p) |
    CASE
      WHEN elementId(node) IN unique_ids THEN unique_ids
      ELSE unique_ids + elementId(node)
    END
  ))
WITH p, nodes(p) AS pathNodes, relationships(p) AS pathRels
WITH p, pathNodes, pathRels,
     reduce(pathKey = '', node IN pathNodes | pathKey + '|' + coalesce(node.name, '')) AS path_key
RETURN
  [node IN pathNodes | {
    element_id: elementId(node),
    labels: labels(node),
    name: node.name,
    description: node.description,
    pmid: node.pmid,
    disease_class: node.disease_class
  }] AS nodes,
  [i IN range(0, size(pathRels) - 1) | {
    source: elementId(pathNodes[i]),
    target: elementId(pathNodes[i + 1]),
    relation_type: type(pathRels[i]),
    relation_label: coalesce(pathRels[i].predicate, type(pathRels[i])),
    evidence: coalesce(pathRels[i].evidence, ''),
    pmids: coalesce(pathRels[i].pmids, [])
  }] AS edges,
  length(p) AS hop_count,
  reduce(total = 0, rel IN pathRels | total + size(coalesce(rel.pmids, []))) AS pmid_count
%s
LIMIT %d
CYPHER,
                        $hop,
                        $hop,
                        $orderClause,
                        $sampleLimit
                    ),
                    ['sourceId' => $sourceId, 'targetId' => $targetId],
                    min($remainingSeconds, PATH_FINDER_PER_DEPTH_TIMEOUT_SECONDS)
                );
            } catch (RuntimeException $error) {
                if (stripos($error->getMessage(), 'timed out') !== false) {
                    $searchTruncated = true;
                    break;
                }
                throw $error;
            }
            $rows = array_merge($rows, $depthRows);
            $searchedThroughHop = $hop;
            if (count($depthRows) >= $sampleLimit) {
                $searchTruncated = true;
            }
        }

        if (count($rows) >= $resultLimit || $searchedThroughHop < $depth) {
            $searchTruncated = true;
        }

        $evidenceResult = $this->loadEvidenceRecordsByPmids($this->collectPathPmids($rows), $deadline);
        $evidenceRecordsByPmid = $evidenceResult['records'];
        $searchTruncated = $searchTruncated || $evidenceResult['truncated'];
        $paths = [];
        foreach ($rows as $row) {
            $nodes = array_map(fn(array $node): array => $this->normalizeNode($node), (array)($row['nodes'] ?? []));
            $edges = array_map(fn(array $edge): array => $this->normalizeEdge($edge, $evidenceRecordsByPmid), (array)($row['edges'] ?? []));
            $pmids = [];
            foreach ($edges as $edge) {
                $pmids = array_merge($pmids, $edge['pmids']);
            }
            $pmids = $this->normalizePmids($pmids);
            $paths[] = [
                'id' => '',
                'hop_count' => (int)($row['hop_count'] ?? count($edges)),
                'pmid_count' => count($pmids),
                'pmids' => $pmids,
                'nodes' => $nodes,
                'edges' => $edges,
            ];
        }

        usort($paths, static function (array $left, array $right): int {
            $hopCompare = ((int)$left['hop_count']) <=> ((int)$right['hop_count']);
            if ($hopCompare !== 0) {
                return $hopCompare;
            }
            $pmidCompare = ((int)$right['pmid_count']) <=> ((int)$left['pmid_count']);
            if ($pmidCompare !== 0) {
                return $pmidCompare;
            }
            return strcasecmp(
                implode('|', array_map(static fn(array $node): string => (string)$node['name'], $left['nodes'])),
                implode('|', array_map(static fn(array $node): string => (string)$node['name'], $right['nodes']))
            );
        });

        $paths = array_slice($paths, 0, $resultLimit);
        foreach ($paths as $index => &$path) {
            $path['id'] = 'path_' . ($index + 1);
        }
        unset($path);

        return [
            'paths' => $paths,
            'search_truncated' => $searchTruncated,
            'searched_through_hop' => $searchedThroughHop,
        ];
    }

    private function normalizeNode(array $row): array
    {
        return [
            'element_id' => (string)($row['element_id'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'type' => $this->normalizeType((array)($row['labels'] ?? [])),
            'labels' => array_values(array_map('strval', (array)($row['labels'] ?? []))),
            'description' => (string)($row['description'] ?? ''),
            'pmid' => (string)($row['pmid'] ?? ''),
            'disease_class' => (string)($row['disease_class'] ?? ''),
        ];
    }

    private function normalizeConnectedCandidate(array $row): array
    {
        $node = $this->normalizeNode($row);
        $node['min_hop'] = path_finder_clamp_depth((int)($row['min_hop'] ?? 3));
        $node['path_count'] = max(0, (int)($row['path_count'] ?? 0));
        $node['pmid_count'] = max(0, (int)($row['pmid_count'] ?? 0));
        return $node;
    }

    private function normalizeEdge(array $row, array $evidenceRecordsByPmid = []): array
    {
        $pmids = $this->normalizePmids((array)($row['pmids'] ?? []));
        return [
            'source' => (string)($row['source'] ?? ''),
            'target' => (string)($row['target'] ?? ''),
            'relation_type' => (string)($row['relation_type'] ?? ''),
            'relation_label' => (string)($row['relation_label'] ?? ($row['relation_type'] ?? '')),
            'evidence' => (string)($row['evidence'] ?? ''),
            'pmid_count' => count($pmids),
            'pmids' => $pmids,
            'evidence_records' => $this->evidenceRecordsForPmids($pmids, $evidenceRecordsByPmid),
        ];
    }

    private function collectPathPmids(array $rows): array
    {
        $pmids = [];
        foreach ($rows as $row) {
            foreach ((array)($row['edges'] ?? []) as $edge) {
                foreach ((array)($edge['pmids'] ?? []) as $pmid) {
                    $key = trim((string)$pmid);
                    if ($key !== '') {
                        $pmids[$key] = true;
                    }
                }
            }
        }
        return array_keys($pmids);
    }

    private function loadEvidenceRecordsByPmids(array $pmids, float $deadline): array
    {
        $pmids = array_values(array_unique(array_filter(array_map(
            static fn($pmid): string => trim((string)$pmid),
            $pmids
        ))));
        if ($pmids === []) {
            return ['records' => [], 'truncated' => false];
        }

        $records = [];
        $truncated = false;
        foreach (array_chunk($pmids, 500) as $chunk) {
            $remainingSeconds = $this->remainingQuerySeconds($deadline);
            if ($remainingSeconds < 1) {
                $truncated = true;
                break;
            }
            try {
                $rows = $this->runNeo4j(
                    <<<'CYPHER'
MATCH (p:Paper)
WHERE p.pmid IN $pmids
RETURN
  p.pmid AS pmid,
  p.pubmed_title AS pubmed_title,
  p.pubmed_journal_title AS pubmed_journal_title,
  p.pubmed_publication_year AS pubmed_publication_year,
  p.journal_metric_value AS journal_metric_value,
  p.journal_metric_source AS journal_metric_source,
  p.journal_metric_year AS journal_metric_year,
  p.journal_jcr_quartile AS journal_jcr_quartile,
  p.journal_metric_match_method AS journal_metric_match_method
CYPHER,
                    ['pmids' => $chunk],
                    $remainingSeconds
                );
            } catch (RuntimeException $error) {
                if (stripos($error->getMessage(), 'timed out') !== false) {
                    $truncated = true;
                    break;
                }
                throw $error;
            }

            foreach ($rows as $row) {
                $pmid = trim((string)($row['pmid'] ?? ''));
                if ($pmid === '') {
                    continue;
                }
                $records[$pmid] = $this->normalizeEvidenceRecord($pmid, $row);
            }
        }

        return ['records' => $records, 'truncated' => $truncated];
    }

    private function evidenceRecordsForPmids(array $pmids, array $recordsByPmid): array
    {
        $records = [];
        foreach ($pmids as $pmid) {
            $key = trim((string)$pmid);
            if ($key === '') {
                continue;
            }
            $records[] = $recordsByPmid[$key] ?? $this->normalizeEvidenceRecord($key, []);
        }
        return $records;
    }

    private function normalizeEvidenceRecord(string $pmid, array $row): array
    {
        return [
            'pmid' => $pmid,
            'pubmed_url' => 'https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode($pmid) . '/',
            'pubmed_title' => $this->nullableStringValue($row['pubmed_title'] ?? null),
            'pubmed_journal_title' => $this->nullableStringValue($row['pubmed_journal_title'] ?? null),
            'pubmed_publication_year' => $this->nullableIntValue($row['pubmed_publication_year'] ?? null),
            'journal_metric_value' => $this->nullableFloatValue($row['journal_metric_value'] ?? null),
            'journal_metric_source' => $this->nullableStringValue($row['journal_metric_source'] ?? null),
            'journal_metric_year' => $this->nullableIntValue($row['journal_metric_year'] ?? null),
            'journal_jcr_quartile' => $this->nullableStringValue($row['journal_jcr_quartile'] ?? null),
            'journal_metric_match_method' => $this->nullableStringValue($row['journal_metric_match_method'] ?? null),
        ];
    }

    private function normalizePmids(array $pmids): array
    {
        $seen = [];
        $normalized = [];
        foreach ($pmids as $pmid) {
            $value = trim((string)$pmid);
            if ($value !== '' && preg_match('/^\d{4,12}$/', $value) === 1 && !isset($seen[$value])) {
                $seen[$value] = true;
                $normalized[] = $value;
            }
        }
        sort($normalized, SORT_NATURAL);
        return $normalized;
    }

    private function normalizeType(array $labels): string
    {
        foreach (['TE', 'Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Pharmaceutical', 'Toxin', 'Lipid', 'Peptide', 'Carbohydrate'] as $type) {
            if (in_array($type, $labels, true)) {
                return $type;
            }
        }
        return $labels[0] ?? 'Node';
    }

    private function normalizeQuery(string $query): string
    {
        return trim(preg_replace('/\s+/', ' ', $query) ?? $query);
    }

    private function autocompleteMatchConfig(string $entityType, string $query): array
    {
        $normalized = mb_strtolower($this->normalizeQuery($query));
        $terms = [];
        if ($normalized !== '') {
            $terms[] = $normalized;
        }

        $preferredNames = [];
        if ($entityType === 'TE' && in_array($normalized, ['l1', 'line1', 'line-1', 'line 1'], true)) {
            $terms = array_merge($terms, ['l1', 'line1', 'line-1', 'line 1']);
            $preferredNames[] = 'l1 (line-1)';
        }

        $terms = array_values(array_unique(array_filter(
            $terms,
            static fn(string $term): bool => trim($term) !== ''
        )));

        return [
            'terms' => $terms,
            'preferred_names' => array_values(array_unique($preferredNames)),
        ];
    }

    private function allowFuzzy(string $query): bool
    {
        return mb_strlen($query) >= 3 && !preg_match('/^\d+$/', $query);
    }

    private function nullableFloatValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    private function nullableIntValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    private function nullableStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function remainingQuerySeconds(float $deadline): int
    {
        return max(0, min(30, (int)ceil($deadline - microtime(true))));
    }

    private function runNeo4j(string $statement, array $parameters = [], int $timeoutSeconds = 30): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required');
        }

        $timeoutSeconds = max(1, min(30, $timeoutSeconds));
        $payload = json_encode([
            'statements' => [[
                'statement' => $statement,
                'parameters' => $parameters === [] ? new stdClass() : $parameters,
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init((string)$this->config['neo4j_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => (string)$this->config['neo4j_user'] . ':' . (string)$this->config['neo4j_password'],
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $timeoutSeconds,
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

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Neo4j response is not valid JSON');
        }
        if (!empty($decoded['errors'])) {
            throw new RuntimeException((string)($decoded['errors'][0]['message'] ?? 'Neo4j query failed'));
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
}
