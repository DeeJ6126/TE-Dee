<?php
declare(strict_types=1);

final class GraphService
{
    private array $config;
    private array $diseaseNameTranslations = [];
    private ?array $teTaxonomyIndex = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->diseaseNameTranslations = $this->loadDiseaseNameTranslations();
    }

    public function search(string $query, int $keyLevel = 1, string $queryType = '', string $classQuery = '', array $options = []): array
    {
        $normalized = $this->normalizeQuery($query);
        $requestedType = $this->normalizeRequestedType($queryType);
        $requestedClass = trim($classQuery) !== '' ? trim($classQuery) : $normalized;
        $expandedSource = null;

        if ($requestedType === 'DiseaseClass') {
            $anchor = $this->findDiseaseClassAnchor($requestedClass);
        } else {
            $resolution = '';
            $anchor = $this->resolveExpandAnchor($query, $normalized, $options, $resolution);
            if ($anchor !== null && $resolution !== '') {
                $expandedSource = [
                    'id' => (string)($anchor['element_id'] ?? ''),
                    'name' => (string)($anchor['name'] ?? ''),
                    'type' => $this->normalizeType($anchor['labels'] ?? []),
                    'resolution' => $resolution,
                ];
            }
            if ($anchor === null) {
                $anchor = $this->findAnchorNode($query, $normalized, $requestedType);
            }
        }

        if ($anchor === null) {
            return [
                'query' => $query,
                'normalized_query' => $normalized,
                'requested_type' => $requestedType,
                'anchor' => null,
                'elements' => [],
                'matches' => [],
            ];
        }

        $anchorType = $requestedType === 'DiseaseClass' ? 'DiseaseClass' : $this->normalizeType($anchor['labels']);
        if ($anchorType === 'DiseaseClass') {
            $rows = $this->buildDiseaseClassContextRows($anchor);
        } elseif ($anchorType === 'Disease') {
            $rows = $this->buildDiseaseContextRows($anchor);
        } elseif ($anchorType === 'Paper') {
            $rows = $this->buildPaperContextRows($anchor);
        } else {
            $rows = $this->loadDirectRowsForAnchor($anchor);
            $rows = $this->pruneGenericRows($rows, $anchorType);
        }

        if ($keyLevel > 1) {
            $rows = $this->expandKeyNodeRows((string)$anchor['element_id'], $rows, $keyLevel);
        }

        $rows = $this->canonicalizeAnchorRows($rows, $anchor);
        $elements = $this->buildElements($anchor, $rows);
        $classificationPaths = $anchorType === 'Disease'
            ? $this->loadDiseaseClassificationPaths((string)($anchor['element_id'] ?? ''))
            : [];
        $classificationTopClasses = [];
        foreach ($classificationPaths as $path) {
            $topCategory = trim((string)($path['top_category'] ?? ''));
            if ($topCategory !== '') {
                $classificationTopClasses[$topCategory] = true;
            }
        }

        $payload = [
            'query' => $query,
            'normalized_query' => $normalized,
            'requested_type' => $requestedType,
            'anchor' => [
                'name' => $anchor['name'],
                'type' => $anchorType,
                'pmid' => $anchor['pmid'],
            ],
            'key_level' => $keyLevel,
            'key_node_threshold' => (int)($this->config['key_node_threshold'] ?? 15),
            'key_node_expand_limit' => (int)($this->config['key_node_expand_limit'] ?? 15),
            'classification_mode' => (!empty($classificationPaths) || !empty($anchor['hierarchy_top_element_id'])) ? 'hierarchy' : 'legacy',
            'classification_top_classes' => array_keys($classificationTopClasses),
            'classification_paths' => $classificationPaths,
            'elements' => $elements,
            'matches' => $anchor['matches'],
        ];

        if ($expandedSource !== null) {
            $payload['expanded_source'] = $expandedSource;
        }

        return $payload;
    }

    private function normalizeRequestedType(string $queryType): string
    {
        $normalized = mb_strtolower(trim($queryType));
        return match ($normalized) {
            'disease_class', 'diseaseclass' => 'DiseaseClass',
            'te' => 'TE',
            'disease' => 'Disease',
            'function' => 'Function',
            'gene' => 'Gene',
            'protein' => 'Protein',
            'rna' => 'RNA',
            'mutation' => 'Mutation',
            'pharmaceutical' => 'Pharmaceutical',
            'toxin' => 'Toxin',
            'lipid' => 'Lipid',
            'peptide' => 'Peptide',
            'carbohydrate' => 'Carbohydrate',
            default => '',
        };
    }

    private function findDiseaseClassHierarchyAnchor(string $className): ?array
    {
        $rows = $this->runNeo4j(
            <<<'CYPHER'
MATCH (top:DiseaseCategory)
WHERE coalesce(top.category_level, 0) = 1
  AND (
    toLower(trim(coalesce(top.category_label, ''))) = toLower($className)
    OR toLower(trim(coalesce(top.top_category, ''))) = toLower($className)
    OR toLower(trim(coalesce(top.category_label, ''))) CONTAINS toLower($className)
    OR toLower($className) CONTAINS toLower(trim(coalesce(top.category_label, '')))
    OR toLower(trim(coalesce(top.top_category, ''))) CONTAINS toLower($className)
    OR toLower($className) CONTAINS toLower(trim(coalesce(top.top_category, '')))
  )
OPTIONAL MATCH (top)-[:HAS_SUBCATEGORY*0..10]->(leaf:DiseaseCategory)<-[:CLASSIFIED_AS]-(d:Disease)
RETURN
  elementId(top) AS element_id,
  top.category_node_id AS category_node_id,
  top.category_label AS category_label,
  count(DISTINCT d) AS disease_count
ORDER BY disease_count DESC
LIMIT 1
CYPHER,
            ['className' => $className]
        );

        if (empty($rows)) {
            return null;
        }

        $canonicalClass = trim((string)($rows[0]['category_label'] ?? ''));
        if ($canonicalClass === '') {
            return null;
        }

        return [
            'element_id' => 'disease_class::' . mb_strtolower($canonicalClass),
            'labels' => ['DiseaseClass'],
            'name' => $canonicalClass,
            'description' => 'Top disease class node synthesized from ICD-11 disease classification hierarchy.',
            'pmid' => '',
            'disease_class' => $canonicalClass,
            'hierarchy_top_element_id' => (string)($rows[0]['element_id'] ?? ''),
            'hierarchy_category_node_id' => (string)($rows[0]['category_node_id'] ?? ''),
            'matches' => [[
                'name' => $canonicalClass,
                'type' => 'DiseaseClass',
                'pmid' => '',
            ]],
        ];
    }

    private function findDiseaseClassAnchor(string $className): ?array
    {
        $className = trim($className);
        if ($className === '') {
            return null;
        }

        $hierarchyAnchor = $this->findDiseaseClassHierarchyAnchor($className);
        if ($hierarchyAnchor !== null) {
            return $hierarchyAnchor;
        }

        $rows = $this->runNeo4j(
            <<<'CYPHER'
MATCH (d:Disease)
WHERE trim(coalesce(d.disease_class, '')) <> ''
  AND toLower(trim(d.disease_class)) = toLower($className)
RETURN trim(d.disease_class) AS disease_class, count(*) AS disease_count
ORDER BY disease_count DESC
LIMIT 1
CYPHER,
            ['className' => $className]
        );

        if (empty($rows)) {
            return null;
        }

        $canonicalClass = trim((string)($rows[0]['disease_class'] ?? ''));
        if ($canonicalClass === '') {
            return null;
        }

        return [
            'element_id' => 'disease_class::' . mb_strtolower($canonicalClass),
            'labels' => ['DiseaseClass'],
            'name' => $canonicalClass,
            'description' => 'Disease class node synthesized from disease_class grouping.',
            'pmid' => '',
            'disease_class' => $canonicalClass,
            'matches' => [[
                'name' => $canonicalClass,
                'type' => 'DiseaseClass',
                'pmid' => '',
            ]],
        ];
    }

    private function loadDirectRows(string $anchorId): array
    {
        return $this->runNeo4j(
            <<<'CYPHER'
MATCH (n)-[r]-(m)
WHERE elementId(n) = $anchorId
RETURN
  elementId(n) AS source_element_id,
  labels(n) AS source_labels,
  n.name AS source_name,
  n.description AS source_description,
  n.pmid AS source_pmid,
  n.disease_class AS source_disease_class,
  elementId(m) AS target_element_id,
  labels(m) AS target_labels,
  m.name AS target_name,
  m.description AS target_description,
  m.pmid AS target_pmid,
  m.disease_class AS target_disease_class,
  type(r) AS relation_type,
  coalesce(r.predicate, type(r)) AS relation_label,
  coalesce(r.pmids, []) AS relation_pmids,
  coalesce(r.evidence, '') AS relation_evidence,
  coalesce(r.support_pmid_count, 0) AS support_pmid_count,
  coalesce(r.support_metric_paper_count, 0) AS support_metric_paper_count,
  coalesce(r.support_metric_coverage, 0.0) AS support_metric_coverage,
  r.support_if_max AS support_if_max,
  r.support_if_mean AS support_if_mean,
  r.support_if_median AS support_if_median,
  coalesce(r.support_jcr_q1_count, 0) AS support_jcr_q1_count,
  coalesce(r.support_jcr_q2_count, 0) AS support_jcr_q2_count,
  coalesce(r.support_jcr_q3_count, 0) AS support_jcr_q3_count,
  coalesce(r.support_jcr_q4_count, 0) AS support_jcr_q4_count,
  coalesce(r.support_journal_count, 0) AS support_journal_count,
  r.support_publication_year_min AS support_publication_year_min,
  r.support_publication_year_max AS support_publication_year_max
CYPHER,
            ['anchorId' => $anchorId]
        );
    }

    private function loadDirectRowsForAnchor(array $anchor): array
    {
        $allRows = [];
        foreach ($this->getTeAliasNodesForAnchor($anchor) as $aliasNode) {
            foreach ($this->loadDirectRows((string)($aliasNode['element_id'] ?? '')) as $row) {
                $allRows[] = $this->remapAliasRowToAnchor($row, $aliasNode, $anchor);
            }
        }

        return $allRows;
    }

    private function findExactTeNodeByName(string $name): ?array
    {
        $rows = $this->runNeo4j(
            <<<'CYPHER'
MATCH (n:TE)
WHERE toLower(coalesce(n.name, '')) = toLower($name)
RETURN
  elementId(n) AS element_id,
  labels(n) AS labels,
  n.name AS name,
  n.description AS description,
  n.pmid AS pmid,
  n.disease_class AS disease_class
LIMIT 1
CYPHER,
            ['name' => $name]
        );

        return $rows[0] ?? null;
    }

    private function resolveExpandAnchor(string $query, string $normalized, array $options, string &$resolution): ?array
    {
        $resolution = '';
        $nodeId = trim((string)($options['expand_node_id'] ?? ''));
        $nodeType = trim((string)($options['expand_node_type'] ?? ''));
        $expandQuery = trim((string)($options['expand_query'] ?? ''));
        $name = $expandQuery !== '' ? $expandQuery : ($normalized !== '' ? $normalized : $query);

        if ($nodeId !== '') {
            $anchor = $this->findAnchorNodeByElementId($nodeId);
            if ($anchor !== null) {
                $anchorType = $this->normalizeType($anchor['labels'] ?? []);
                if ($nodeType === '' || $anchorType === $nodeType) {
                    $resolution = 'id';
                    return $anchor;
                }
            }
        }

        if ($name !== '' && $nodeType !== '') {
            $anchor = $this->findAnchorNodeByNameAndType($name, $nodeType);
            if ($anchor !== null) {
                $resolution = 'name_type';
                return $anchor;
            }
        }

        return null;
    }

    private function findAnchorNodeByElementId(string $nodeId): ?array
    {
        $rows = $this->runNeo4j(
            <<<'CYPHER'
MATCH (n)
WHERE elementId(n) = $nodeId
RETURN
  elementId(n) AS element_id,
  labels(n) AS labels,
  n.name AS name,
  n.description AS description,
  n.pmid AS pmid,
  n.disease_class AS disease_class
LIMIT 1
CYPHER,
            ['nodeId' => $nodeId]
        );

        if (empty($rows)) {
            return null;
        }

        $row = $rows[0];
        $row['matches'] = [$this->anchorMatch($row)];
        return $row;
    }

    private function findAnchorNodeByNameAndType(string $name, string $nodeType): ?array
    {
        $rows = $this->runNeo4j(
            <<<'CYPHER'
MATCH (n)
WHERE toLower(coalesce(n.name, '')) = toLower($name)
  AND $nodeType IN labels(n)
RETURN
  elementId(n) AS element_id,
  labels(n) AS labels,
  n.name AS name,
  n.description AS description,
  n.pmid AS pmid,
  n.disease_class AS disease_class
LIMIT 1
CYPHER,
            ['name' => $name, 'nodeType' => $nodeType]
        );

        if (empty($rows)) {
            return null;
        }

        $row = $rows[0];
        $row['matches'] = [$this->anchorMatch($row)];
        return $row;
    }

    private function anchorMatch(array $row): array
    {
        return [
            'name' => (string)($row['name'] ?? ''),
            'type' => $this->normalizeType($row['labels'] ?? []),
            'pmid' => (string)($row['pmid'] ?? ''),
        ];
    }

    private function getTeAliasNodesForAnchor(array $anchor): array
    {
        $anchorId = (string)($anchor['element_id'] ?? '');
        if ($this->normalizeType($anchor['labels'] ?? []) !== 'TE' || $anchorId === '') {
            return [$anchor];
        }

        $anchorName = trim((string)($anchor['name'] ?? ''));
        if (!$this->isLine1CanonicalTeName($anchorName)) {
            return [$anchor];
        }

        $aliases = [$anchor];
        foreach (['LINE-1', 'LINE1', 'L1'] as $aliasName) {
            $aliasNode = $this->findExactTeNodeByName($aliasName);
            if ($aliasNode === null) {
                continue;
            }

            if ((string)($aliasNode['element_id'] ?? '') === $anchorId) {
                continue;
            }

            $aliases[] = $aliasNode;
        }

        return $aliases;
    }

    private function remapAliasRowToAnchor(array $row, array $aliasNode, array $anchor): array
    {
        $lineageId = (string)($aliasNode['element_id'] ?? '');
        $anchorId = (string)($anchor['element_id'] ?? '');

        if ($lineageId === '' || $lineageId === $anchorId) {
            return $row;
        }

        if ((string)($row['source_element_id'] ?? '') === $lineageId) {
            $row['source_element_id'] = $anchorId;
            $row['source_labels'] = $anchor['labels'] ?? ['TE'];
            $row['source_name'] = $anchor['name'] ?? 'LINE1';
            $row['source_description'] = $anchor['description'] ?? '';
            $row['source_pmid'] = $anchor['pmid'] ?? '';
            $row['source_disease_class'] = $anchor['disease_class'] ?? '';
        }

        if ((string)($row['target_element_id'] ?? '') === $lineageId) {
            $row['target_element_id'] = $anchorId;
            $row['target_labels'] = $anchor['labels'] ?? ['TE'];
            $row['target_name'] = $anchor['name'] ?? 'LINE1';
            $row['target_description'] = $anchor['description'] ?? '';
            $row['target_pmid'] = $anchor['pmid'] ?? '';
            $row['target_disease_class'] = $anchor['disease_class'] ?? '';
        }

        return $row;
    }

    private function canonicalizeAnchorRows(array $rows, array $anchor): array
    {
        $anchorId = (string)($anchor['element_id'] ?? '');
        $anchorName = trim((string)($anchor['name'] ?? ''));
        if (
            $anchorId === ''
            || !$this->isLine1CanonicalTeName($anchorName)
            || $this->normalizeType($anchor['labels'] ?? []) !== 'TE'
        ) {
            return $rows;
        }

        $aliasNodes = array_values(array_filter(
            $this->getTeAliasNodesForAnchor($anchor),
            static fn(array $aliasNode): bool => (string)($aliasNode['element_id'] ?? '') !== $anchorId
        ));
        if (empty($aliasNodes)) {
            return $rows;
        }

        $canonicalRows = [];
        $seenRowKeys = [];
        foreach ($rows as $row) {
            foreach ($aliasNodes as $aliasNode) {
                $row = $this->remapAliasRowToAnchor($row, $aliasNode, $anchor);
            }

            if ((string)($row['source_element_id'] ?? '') === (string)($row['target_element_id'] ?? '')) {
                continue;
            }

            $rowKey = $this->buildRowKey($row);
            if (isset($seenRowKeys[$rowKey])) {
                continue;
            }
            $seenRowKeys[$rowKey] = true;
            $canonicalRows[] = $row;
        }

        return $canonicalRows;
    }

    private function loadDiseaseTeRows(string $anchorId): array
    {
        return $this->runNeo4j(
            <<<'CYPHER'
MATCH (d:Disease)
WHERE elementId(d) = $anchorId
MATCH (d)-[r1]-(te:TE)
RETURN
  elementId(d) AS source_element_id,
  labels(d) AS source_labels,
  d.name AS source_name,
  d.description AS source_description,
  d.pmid AS source_pmid,
  d.disease_class AS source_disease_class,
  elementId(te) AS target_element_id,
  labels(te) AS target_labels,
  te.name AS target_name,
  te.description AS target_description,
  te.pmid AS target_pmid,
  te.disease_class AS target_disease_class,
  type(r1) AS relation_type,
  coalesce(r1.predicate, type(r1)) AS relation_label,
  coalesce(r1.pmids, []) AS relation_pmids,
  coalesce(r1.evidence, '') AS relation_evidence,
  coalesce(r1.support_pmid_count, 0) AS support_pmid_count,
  coalesce(r1.support_metric_paper_count, 0) AS support_metric_paper_count,
  coalesce(r1.support_metric_coverage, 0.0) AS support_metric_coverage,
  r1.support_if_max AS support_if_max,
  r1.support_if_mean AS support_if_mean,
  r1.support_if_median AS support_if_median,
  coalesce(r1.support_jcr_q1_count, 0) AS support_jcr_q1_count,
  coalesce(r1.support_jcr_q2_count, 0) AS support_jcr_q2_count,
  coalesce(r1.support_jcr_q3_count, 0) AS support_jcr_q3_count,
  coalesce(r1.support_jcr_q4_count, 0) AS support_jcr_q4_count,
  coalesce(r1.support_journal_count, 0) AS support_journal_count,
  r1.support_publication_year_min AS support_publication_year_min,
  r1.support_publication_year_max AS support_publication_year_max
CYPHER,
            ['anchorId' => $anchorId]
        );
    }

    private function buildDiseaseContextRows(array $anchor): array
    {
        $rows = $this->loadDiseaseTeRows((string)$anchor['element_id']);
        $rows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['target_element_id'])));
        usort($rows, static function (array $a, array $b): int {
            $left = count($a['relation_pmids'] ?? []);
            $right = count($b['relation_pmids'] ?? []);
            if ($left === $right) {
                return strcasecmp((string)($a['target_name'] ?? ''), (string)($b['target_name'] ?? ''));
            }
            return $right <=> $left;
        });
        $rows = array_slice($rows, 0, 8);

        $teIds = [];
        $supportPmids = [];
        foreach ($rows as $row) {
            $teId = (string)($row['target_element_id'] ?? '');
            if ($teId !== '') {
                $teIds[] = $teId;
            }
            foreach (($row['relation_pmids'] ?? []) as $pmid) {
                $pmid = trim((string)$pmid);
                if ($pmid !== '') {
                    $supportPmids[$pmid] = true;
                }
            }
        }

        $functionRows = array_slice(
            $this->loadDiseaseFunctionRows(
                (string)$anchor['element_id'],
                (string)$anchor['name'],
                (string)($anchor['description'] ?? ''),
                (string)($anchor['pmid'] ?? ''),
                (string)($anchor['disease_class'] ?? ''),
                array_values(array_unique($teIds))
            ),
            0,
            12
        );

        $paperRows = array_slice(
            $this->loadPaperRowsByPmids(
                array_keys($supportPmids),
                (string)$anchor['element_id'],
                (string)$anchor['name'],
                (string)($anchor['disease_class'] ?? '')
            ),
            0,
            12
        );

        return array_merge($rows, $functionRows, $paperRows);
    }

    private function loadDiseaseClassificationPaths(string $diseaseId): array
    {
        if ($diseaseId === '') {
            return [];
        }

        $rows = $this->runNeo4j(
            <<<'CYPHER'
MATCH (d:Disease)
WHERE elementId(d) = $diseaseId
MATCH (d)-[r:CLASSIFIED_AS]->(leaf:DiseaseCategory)
OPTIONAL MATCH p=(top:DiseaseCategory)-[:HAS_SUBCATEGORY*0..10]->(leaf)
WHERE coalesce(top.category_level, 0) = 1
RETURN
  coalesce(r.top_category, top.category_label, leaf.top_category, '') AS top_category,
  leaf.category_label AS leaf_category,
  coalesce(r.source_status, '') AS source_status,
  coalesce(r.source_row, '') AS source_row,
  [n IN nodes(p) | {
    element_id: elementId(n),
    label: n.category_label,
    level: coalesce(n.category_level, 0)
  }] AS path
ORDER BY size(nodes(p)), leaf.category_label, source_row
CYPHER,
            ['diseaseId' => $diseaseId]
        );

        $paths = [];
        $seen = [];
        foreach ($rows as $row) {
            $path = is_array($row['path'] ?? null) ? $row['path'] : [];
            if (empty($path)) {
                continue;
            }
            $key = implode(' | ', array_map(
                static fn(array $node): string => trim((string)($node['label'] ?? '')),
                $path
            ));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $paths[] = [
                'top_category' => (string)($row['top_category'] ?? ''),
                'leaf_category' => (string)($row['leaf_category'] ?? ''),
                'source_status' => (string)($row['source_status'] ?? ''),
                'source_row' => (string)($row['source_row'] ?? ''),
                'path' => array_map(
                    static fn(array $node): array => [
                        'element_id' => (string)($node['element_id'] ?? ''),
                        'label' => (string)($node['label'] ?? ''),
                        'level' => (int)($node['level'] ?? 0),
                    ],
                    $path
                ),
            ];
        }

        return $paths;
    }


    private function loadTopDiseaseCategoriesForDiseases(array $diseaseIds): array
    {
        $diseaseIds = array_values(array_unique(array_filter(array_map('strval', $diseaseIds))));
        if (empty($diseaseIds)) {
            return [];
        }

        $rows = $this->runNeo4j(
            <<<'CYPHER'
MATCH (d:Disease)
WHERE elementId(d) IN $diseaseIds
MATCH (d)-[r:CLASSIFIED_AS]->(leaf:DiseaseCategory)
OPTIONAL MATCH p=(top:DiseaseCategory)-[:HAS_SUBCATEGORY*0..10]->(leaf)
WHERE coalesce(top.category_level, 0) = 1
WITH d, leaf, coalesce(top, leaf) AS displayCategory, r
RETURN
  elementId(d) AS disease_element_id,
  elementId(displayCategory) AS category_element_id,
  displayCategory.category_node_id AS category_node_id,
  displayCategory.category_label AS category_label,
  coalesce(displayCategory.description, '') AS category_description,
  coalesce(displayCategory.category_level, 0) AS category_level,
  coalesce(r.top_category, displayCategory.category_label, leaf.top_category, '') AS top_category
ORDER BY category_label, disease_element_id
CYPHER,
            ['diseaseIds' => $diseaseIds]
        );

        $deduped = [];
        $seen = [];
        foreach ($rows as $row) {
            $diseaseId = (string)($row['disease_element_id'] ?? '');
            $categoryId = (string)($row['category_element_id'] ?? '');
            if ($diseaseId === '' || $categoryId === '') {
                continue;
            }
            $key = $diseaseId . '__' . $categoryId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    private function appendTopLevelDiseaseCategories(array &$nodes, array &$edges): void
    {
        $diseaseIds = [];
        foreach ($nodes as $id => $node) {
            $data = $node['data'] ?? [];
            if (($data['type'] ?? '') !== 'Disease') {
                continue;
            }
            $diseaseIds[] = (string)$id;
        }

        if (empty($diseaseIds)) {
            return;
        }

        $rows = $this->loadTopDiseaseCategoriesForDiseases($diseaseIds);
        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $categoryId = (string)($row['category_element_id'] ?? '');
            $diseaseId = (string)($row['disease_element_id'] ?? '');
            if ($categoryId === '' || $diseaseId === '' || !isset($nodes[$diseaseId])) {
                continue;
            }

            $this->addNode($nodes, [
                'element_id' => $categoryId,
                'labels' => ['DiseaseCategory'],
                'name' => (string)($row['category_label'] ?? ''),
                'description' => (string)($row['category_description'] ?? ''),
                'pmid' => '',
                'disease_class' => (string)($row['top_category'] ?? ''),
                'category_level' => (int)($row['category_level'] ?? 0),
            ]);

            $edgeId = $categoryId . '__CLASSIFIED_AS_TOP_CATEGORY__' . $diseaseId;
            $edges[$edgeId] = [
                'data' => [
                    'id' => $edgeId,
                    'source' => $categoryId,
                    'target' => $diseaseId,
                    'relation' => 'classified disease',
                    'relationType' => 'CLASSIFIED_AS_TOP_CATEGORY',
                    'evidence' => '',
                    'pmids' => [],
                ],
            ];
        }
    }

    private function loadDiseaseClassHierarchyEntries(string $topElementId): array
    {
        if ($topElementId === '') {
            return [];
        }

        return $this->runNeo4j(
            <<<'CYPHER'
MATCH (top:DiseaseCategory)
WHERE elementId(top) = $topElementId
MATCH p=(top)-[:HAS_SUBCATEGORY*0..10]->(leaf:DiseaseCategory)
MATCH (leaf)<-[r:CLASSIFIED_AS]-(d:Disease)
OPTIONAL MATCH (d)-[bio:BIO_RELATION]-(:TE)
WITH top, leaf, d, r, p, count(bio) AS te_degree
ORDER BY te_degree DESC, toLower(coalesce(d.name, ''))
RETURN
  elementId(d) AS disease_element_id,
  labels(d) AS disease_labels,
  d.name AS disease_name,
  d.description AS disease_description,
  d.pmid AS disease_pmid,
  d.disease_class AS disease_class,
  coalesce(r.top_category, top.category_label) AS top_category,
  leaf.category_label AS leaf_category,
  [n IN nodes(p) | {
    element_id: elementId(n),
    labels: labels(n),
    name: n.category_label,
    description: coalesce(n.description, ''),
    pmid: coalesce(n.pmid, ''),
    category_level: coalesce(n.category_level, 0)
  }] AS category_path
LIMIT 12
CYPHER,
            ['topElementId' => $topElementId]
        );
    }

    private function buildDiseaseClassHierarchyContextRows(array $anchor): array
    {
        $entries = $this->loadDiseaseClassHierarchyEntries((string)($anchor['hierarchy_top_element_id'] ?? ''));
        if (empty($entries)) {
            return [];
        }

        $allRows = [];
        foreach ($entries as $entry) {
            $topCategory = trim((string)($entry['top_category'] ?? ''));
            $diseaseClass = $topCategory;
            $path = is_array($entry['category_path'] ?? null) ? $entry['category_path'] : [];
            if (empty($path)) {
                continue;
            }

            $topNode = $path[0];
            $anchorName = trim((string)($anchor['name'] ?? ''));
            $topNodeName = trim((string)($topNode['name'] ?? ''));
            $skipTopCategoryNode =
                (string)($topNode['element_id'] ?? '') === (string)($anchor['hierarchy_top_element_id'] ?? '')
                && $anchorName !== ''
                && mb_strtolower($anchorName) === mb_strtolower($topNodeName);

            if ($skipTopCategoryNode) {
                if (count($path) > 1) {
                    $nextNode = $path[1];
                    $allRows[] = [
                        'source_element_id' => (string)($anchor['element_id'] ?? ''),
                        'source_labels' => ['DiseaseClass'],
                        'source_name' => (string)($anchor['name'] ?? ''),
                        'source_description' => (string)($anchor['description'] ?? ''),
                        'source_pmid' => '',
                        'source_disease_class' => (string)($anchor['disease_class'] ?? ''),
                        'target_element_id' => (string)($nextNode['element_id'] ?? ''),
                        'target_labels' => ['DiseaseCategory'],
                        'target_name' => (string)($nextNode['name'] ?? ''),
                        'target_description' => (string)($nextNode['description'] ?? ''),
                        'target_pmid' => (string)($nextNode['pmid'] ?? ''),
                        'target_disease_class' => $topCategory,
                        'target_category_level' => (int)($nextNode['category_level'] ?? 0),
                        'relation_type' => 'TOP_CLASS_RELATION',
                        'relation_label' => 'top category',
                        'relation_pmids' => [],
                        'relation_evidence' => '',
                    ];
                }
            } else {
                $allRows[] = [
                    'source_element_id' => (string)($anchor['element_id'] ?? ''),
                    'source_labels' => ['DiseaseClass'],
                    'source_name' => (string)($anchor['name'] ?? ''),
                    'source_description' => (string)($anchor['description'] ?? ''),
                    'source_pmid' => '',
                    'source_disease_class' => (string)($anchor['disease_class'] ?? ''),
                    'target_element_id' => (string)($topNode['element_id'] ?? ''),
                    'target_labels' => ['DiseaseCategory'],
                    'target_name' => (string)($topNode['name'] ?? ''),
                    'target_description' => (string)($topNode['description'] ?? ''),
                    'target_pmid' => (string)($topNode['pmid'] ?? ''),
                    'target_disease_class' => $topCategory,
                    'target_category_level' => (int)($topNode['category_level'] ?? 0),
                    'relation_type' => 'TOP_CLASS_RELATION',
                    'relation_label' => 'top category',
                    'relation_pmids' => [],
                    'relation_evidence' => '',
                ];
            }

            $pathStartIndex = $skipTopCategoryNode ? 1 : 0;
            for ($i = $pathStartIndex; $i < count($path) - 1; $i++) {
                $sourceNode = $path[$i];
                $targetNode = $path[$i + 1];
                $allRows[] = [
                    'source_element_id' => (string)($sourceNode['element_id'] ?? ''),
                    'source_labels' => ['DiseaseCategory'],
                    'source_name' => (string)($sourceNode['name'] ?? ''),
                    'source_description' => (string)($sourceNode['description'] ?? ''),
                    'source_pmid' => (string)($sourceNode['pmid'] ?? ''),
                    'source_disease_class' => $topCategory,
                    'source_category_level' => (int)($sourceNode['category_level'] ?? 0),
                    'target_element_id' => (string)($targetNode['element_id'] ?? ''),
                    'target_labels' => ['DiseaseCategory'],
                    'target_name' => (string)($targetNode['name'] ?? ''),
                    'target_description' => (string)($targetNode['description'] ?? ''),
                    'target_pmid' => (string)($targetNode['pmid'] ?? ''),
                    'target_disease_class' => $topCategory,
                    'target_category_level' => (int)($targetNode['category_level'] ?? 0),
                    'relation_type' => 'HAS_SUBCATEGORY',
                    'relation_label' => 'has subcategory',
                    'relation_pmids' => [],
                    'relation_evidence' => '',
                ];
            }

            $leafNode = $path[count($path) - 1];
            $allRows[] = [
                'source_element_id' => ($skipTopCategoryNode && count($path) === 1)
                    ? (string)($anchor['element_id'] ?? '')
                    : (string)($leafNode['element_id'] ?? ''),
                'source_labels' => ($skipTopCategoryNode && count($path) === 1)
                    ? ['DiseaseClass']
                    : ['DiseaseCategory'],
                'source_name' => ($skipTopCategoryNode && count($path) === 1)
                    ? (string)($anchor['name'] ?? '')
                    : (string)($leafNode['name'] ?? ''),
                'source_description' => ($skipTopCategoryNode && count($path) === 1)
                    ? (string)($anchor['description'] ?? '')
                    : (string)($leafNode['description'] ?? ''),
                'source_pmid' => ($skipTopCategoryNode && count($path) === 1)
                    ? ''
                    : (string)($leafNode['pmid'] ?? ''),
                'source_disease_class' => $topCategory,
                'source_category_level' => ($skipTopCategoryNode && count($path) === 1)
                    ? 0
                    : (int)($leafNode['category_level'] ?? 0),
                'target_element_id' => (string)($entry['disease_element_id'] ?? ''),
                'target_labels' => $entry['disease_labels'] ?? ['Disease'],
                'target_name' => (string)($entry['disease_name'] ?? ''),
                'target_description' => (string)($entry['disease_description'] ?? ''),
                'target_pmid' => (string)($entry['disease_pmid'] ?? ''),
                'target_disease_class' => $diseaseClass,
                'relation_type' => 'CLASSIFIED_AS',
                'relation_label' => 'classified disease',
                'relation_pmids' => [],
                'relation_evidence' => '',
            ];
        }

        $deduped = [];
        $seenRowKeys = [];
        foreach ($allRows as $row) {
            $rowKey = $this->buildRowKey($row);
            if (isset($seenRowKeys[$rowKey])) {
                continue;
            }
            $seenRowKeys[$rowKey] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    private function loadDiseaseClassDiseaseRows(string $classAnchorId, string $className, string $classDescription): array
    {
        return $this->runNeo4j(
            <<<'CYPHER'
MATCH (d:Disease)
WHERE trim(coalesce(d.disease_class, '')) <> ''
  AND toLower(trim(d.disease_class)) = toLower($className)
OPTIONAL MATCH (d)-[r:BIO_RELATION]-(:TE)
WITH d, count(r) AS te_degree
RETURN
  $classAnchorId AS source_element_id,
  ['DiseaseClass'] AS source_labels,
  $className AS source_name,
  $classDescription AS source_description,
  '' AS source_pmid,
  $className AS source_disease_class,
  elementId(d) AS target_element_id,
  labels(d) AS target_labels,
  d.name AS target_name,
  d.description AS target_description,
  d.pmid AS target_pmid,
  d.disease_class AS target_disease_class,
  'DISEASE_CLASS_RELATION' AS relation_type,
  'includes disease' AS relation_label,
  [] AS relation_pmids,
  '' AS relation_evidence
ORDER BY te_degree DESC, toLower(coalesce(d.name, ''))
CYPHER,
            [
                'classAnchorId' => $classAnchorId,
                'className' => $className,
                'classDescription' => $classDescription,
            ]
        );
    }

    private function buildDiseaseClassContextRows(array $anchor): array
    {
        if (!empty($anchor['hierarchy_top_element_id'])) {
            $hierarchyRows = $this->buildDiseaseClassHierarchyContextRows($anchor);
            if (!empty($hierarchyRows)) {
                return $hierarchyRows;
            }
        }

        $classAnchorId = (string)($anchor['element_id'] ?? '');
        $className = (string)($anchor['name'] ?? '');
        $classDescription = (string)($anchor['description'] ?? '');

        $diseaseRows = array_slice(
            $this->loadDiseaseClassDiseaseRows($classAnchorId, $className, $classDescription),
            0,
            12
        );
        $deduped = [];
        $seenRowKeys = [];
        foreach ($diseaseRows as $row) {
            $rowKey = $this->buildRowKey($row);
            if (isset($seenRowKeys[$rowKey])) {
                continue;
            }
            $seenRowKeys[$rowKey] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    private function buildPaperContextRows(array $anchor): array
    {
        $rows = $this->loadDirectRows((string)$anchor['element_id']);
        $rows = $this->pruneGenericRows($rows, 'Paper');
        $pmid = trim((string)($anchor['pmid'] ?? ''));
        if ($pmid === '') {
            return $rows;
        }

        $rows = array_merge($rows, $this->loadPaperEvidenceRows((string)$anchor['element_id'], (string)$anchor['name'], $pmid));
        return $rows;
    }

    private function loadTeFunctionRows(string $teId): array
    {
        $rows = $this->runNeo4j(
            <<<'CYPHER'
MATCH (te:TE)-[r:BIO_RELATION]-(f:Function)
WHERE elementId(te) = $teId
RETURN
  elementId(te) AS source_element_id,
  labels(te) AS source_labels,
  te.name AS source_name,
  te.description AS source_description,
  te.pmid AS source_pmid,
  te.disease_class AS source_disease_class,
  elementId(f) AS target_element_id,
  labels(f) AS target_labels,
  f.name AS target_name,
  f.description AS target_description,
  f.pmid AS target_pmid,
  f.disease_class AS target_disease_class,
  type(r) AS relation_type,
  coalesce(r.predicate, type(r)) AS relation_label,
  coalesce(r.pmids, []) AS relation_pmids,
  coalesce(r.evidence, '') AS relation_evidence,
  coalesce(r.support_pmid_count, 0) AS support_pmid_count,
  coalesce(r.support_metric_paper_count, 0) AS support_metric_paper_count,
  coalesce(r.support_metric_coverage, 0.0) AS support_metric_coverage,
  r.support_if_max AS support_if_max,
  r.support_if_mean AS support_if_mean,
  r.support_if_median AS support_if_median,
  coalesce(r.support_jcr_q1_count, 0) AS support_jcr_q1_count,
  coalesce(r.support_jcr_q2_count, 0) AS support_jcr_q2_count,
  coalesce(r.support_jcr_q3_count, 0) AS support_jcr_q3_count,
  coalesce(r.support_jcr_q4_count, 0) AS support_jcr_q4_count,
  coalesce(r.support_journal_count, 0) AS support_journal_count,
  r.support_publication_year_min AS support_publication_year_min,
  r.support_publication_year_max AS support_publication_year_max
LIMIT 20
CYPHER,
            ['teId' => $teId]
        );
        usort($rows, static function (array $a, array $b): int {
            $left = count($a['relation_pmids'] ?? []);
            $right = count($b['relation_pmids'] ?? []);
            if ($left === $right) {
                return strcasecmp((string)($a['target_name'] ?? ''), (string)($b['target_name'] ?? ''));
            }
            return $right <=> $left;
        });
        return $rows;
    }

    private function loadDiseaseFunctionRows(
        string $diseaseId,
        string $diseaseName,
        string $diseaseDescription,
        string $diseasePmid,
        string $diseaseClass,
        array $teIds
    ): array {
        if (empty($teIds)) {
            return [];
        }

        $rows = $this->runNeo4j(
            <<<'CYPHER'
MATCH (d:Disease)-[:BIO_RELATION]-(te:TE)-[r:BIO_RELATION]-(f:Function)
WHERE elementId(d) = $diseaseId AND elementId(te) IN $teIds
RETURN
  $diseaseId AS source_element_id,
  ['Disease'] AS source_labels,
  $diseaseName AS source_name,
  $diseaseDescription AS source_description,
  $diseasePmid AS source_pmid,
  $diseaseClass AS source_disease_class,
  elementId(f) AS target_element_id,
  labels(f) AS target_labels,
  f.name AS target_name,
  f.description AS target_description,
  f.pmid AS target_pmid,
  f.disease_class AS target_disease_class,
  type(r) AS relation_type,
  coalesce(r.predicate, type(r)) AS relation_label,
  coalesce(r.pmids, []) AS relation_pmids,
  coalesce(r.evidence, '') AS relation_evidence,
  coalesce(r.support_pmid_count, 0) AS support_pmid_count,
  coalesce(r.support_metric_paper_count, 0) AS support_metric_paper_count,
  coalesce(r.support_metric_coverage, 0.0) AS support_metric_coverage,
  r.support_if_max AS support_if_max,
  r.support_if_mean AS support_if_mean,
  r.support_if_median AS support_if_median,
  coalesce(r.support_jcr_q1_count, 0) AS support_jcr_q1_count,
  coalesce(r.support_jcr_q2_count, 0) AS support_jcr_q2_count,
  coalesce(r.support_jcr_q3_count, 0) AS support_jcr_q3_count,
  coalesce(r.support_jcr_q4_count, 0) AS support_jcr_q4_count,
  coalesce(r.support_journal_count, 0) AS support_journal_count,
  r.support_publication_year_min AS support_publication_year_min,
  r.support_publication_year_max AS support_publication_year_max
CYPHER,
            [
                'diseaseId' => $diseaseId,
                'diseaseName' => $diseaseName,
                'diseaseDescription' => $diseaseDescription,
                'diseasePmid' => $diseasePmid,
                'diseaseClass' => $diseaseClass,
                'teIds' => $teIds,
            ]
        );

        usort($rows, static function (array $a, array $b): int {
            $left = count($a['relation_pmids'] ?? []);
            $right = count($b['relation_pmids'] ?? []);
            if ($left === $right) {
                return strcasecmp((string)($a['target_name'] ?? ''), (string)($b['target_name'] ?? ''));
            }
            return $right <=> $left;
        });

        return $rows;
    }

    private function loadPaperRowsByPmids(array $pmids, string $diseaseId, string $diseaseName, string $diseaseClass): array
    {
        if (empty($pmids)) {
            return [];
        }

        return $this->runNeo4j(
            <<<'CYPHER'
MATCH (p:Paper)
WHERE p.pmid IN $pmids
RETURN
  elementId(p) AS source_element_id,
  labels(p) AS source_labels,
  p.name AS source_name,
  p.description AS source_description,
  p.pmid AS source_pmid,
  '' AS source_disease_class,
  $diseaseId AS target_element_id,
  ['Disease'] AS target_labels,
  $diseaseName AS target_name,
  '' AS target_description,
  '' AS target_pmid,
  $diseaseClass AS target_disease_class,
  'EVIDENCE_RELATION' AS relation_type,
  'supports this association' AS relation_label,
  [p.pmid] AS relation_pmids,
  '' AS relation_evidence
ORDER BY p.pmid
CYPHER,
            [
                'pmids' => $pmids,
                'diseaseId' => $diseaseId,
                'diseaseName' => $diseaseName,
                'diseaseClass' => $diseaseClass,
            ]
        );
    }

    private function loadPaperEvidenceRows(string $paperId, string $paperName, string $pmid): array
    {
        return $this->runNeo4j(
            <<<'CYPHER'
MATCH (te:TE)-[r:BIO_RELATION]-(d:Disease)
WHERE $pmid IN coalesce(r.pmids, [])
RETURN
  $paperId AS source_element_id,
  ['Paper'] AS source_labels,
  $paperName AS source_name,
  '' AS source_description,
  $pmid AS source_pmid,
  '' AS source_disease_class,
  elementId(d) AS target_element_id,
  labels(d) AS target_labels,
  d.name AS target_name,
  d.description AS target_description,
  d.pmid AS target_pmid,
  d.disease_class AS target_disease_class,
  'EVIDENCE_RELATION' AS relation_type,
  'supports this association' AS relation_label,
  [$pmid] AS relation_pmids,
  '' AS relation_evidence
UNION
MATCH (te:TE)-[r:BIO_RELATION]-(d:Disease)
WHERE $pmid IN coalesce(r.pmids, [])
RETURN
  $paperId AS source_element_id,
  ['Paper'] AS source_labels,
  $paperName AS source_name,
  '' AS source_description,
  $pmid AS source_pmid,
  '' AS source_disease_class,
  elementId(te) AS target_element_id,
  labels(te) AS target_labels,
  te.name AS target_name,
  te.description AS target_description,
  te.pmid AS target_pmid,
  te.disease_class AS target_disease_class,
  'EVIDENCE_RELATION' AS relation_type,
  'supports this association' AS relation_label,
  [$pmid] AS relation_pmids,
  '' AS relation_evidence
LIMIT 16
CYPHER,
            [
                'paperId' => $paperId,
                'paperName' => $paperName,
                'pmid' => $pmid,
            ]
        );
    }

    private function pruneGenericRows(array $rows, string $anchorType): array
    {
        $rows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['target_element_id'])));
        $limits = [
            'TE' => [
                'TE' => 14,
                'Disease' => 18,
                'Function' => 10,
                'Gene' => 14,
                'Protein' => 12,
                'RNA' => 10,
                'Mutation' => 10,
                'Pharmaceutical' => 8,
                'Toxin' => 6,
                'Lipid' => 4,
                'Peptide' => 4,
                'Carbohydrate' => 4,
                'Paper' => 16,
            ],
            'Disease' => [
                'TE' => 12,
                'Disease' => 8,
                'Function' => 10,
                'Gene' => 10,
                'Protein' => 10,
                'RNA' => 8,
                'Mutation' => 10,
                'Pharmaceutical' => 8,
                'Toxin' => 6,
                'Lipid' => 4,
                'Peptide' => 4,
                'Carbohydrate' => 4,
                'Paper' => 8,
            ],
            'Function' => [
                'TE' => 10,
                'Disease' => 8,
                'Function' => 8,
                'Gene' => 10,
                'Protein' => 10,
                'RNA' => 8,
                'Mutation' => 8,
                'Pharmaceutical' => 6,
                'Toxin' => 4,
                'Lipid' => 4,
                'Peptide' => 4,
                'Carbohydrate' => 4,
                'Paper' => 10,
            ],
            'Paper' => [
                'TE' => 10,
                'Disease' => 8,
                'Function' => 8,
                'Gene' => 8,
                'Protein' => 8,
                'RNA' => 6,
                'Mutation' => 8,
                'Pharmaceutical' => 6,
                'Toxin' => 4,
                'Lipid' => 3,
                'Peptide' => 3,
                'Carbohydrate' => 3,
                'Paper' => 0,
            ],
            'Unknown' => [
                'TE' => 8,
                'Disease' => 8,
                'Function' => 8,
                'Gene' => 8,
                'Protein' => 8,
                'RNA' => 6,
                'Mutation' => 6,
                'Pharmaceutical' => 4,
                'Toxin' => 4,
                'Lipid' => 3,
                'Peptide' => 3,
                'Carbohydrate' => 3,
                'Paper' => 8,
            ],
        ];
        $typeLimits = $limits[$anchorType] ?? $limits['Unknown'];
        $buckets = [];

        foreach ($rows as $row) {
            $targetType = $this->normalizeType($row['target_labels'] ?? []);
            if (!isset($typeLimits[$targetType])) {
                continue;
            }
            $buckets[$targetType] ??= [];
            $buckets[$targetType][] = $row;
        }

        $selected = [];
        foreach ($buckets as $targetType => $bucketRows) {
            usort($bucketRows, static function (array $a, array $b): int {
                $left = count($a['relation_pmids'] ?? []);
                $right = count($b['relation_pmids'] ?? []);
                if ($left === $right) {
                    return strcasecmp((string)($a['target_name'] ?? ''), (string)($b['target_name'] ?? ''));
                }
                return $right <=> $left;
            });
            $selected = array_merge($selected, array_slice($bucketRows, 0, $typeLimits[$targetType]));
        }

        return $selected;
    }

    private function expandKeyNodeRows(string $anchorId, array $rows, int $keyLevel): array
    {
        if ($keyLevel <= 1 || empty($rows)) {
            return $rows;
        }

        $threshold = max(1, (int)($this->config['key_node_threshold'] ?? 15));
        $expandLimit = max(1, (int)($this->config['key_node_expand_limit'] ?? 15));
        $allRows = [];
        $seenRowKeys = [];
        $nodeDepths = [$anchorId => 1];
        $lineagePassNodeIds = [];

        foreach ($rows as $row) {
            $rowKey = $this->buildRowKey($row);
            $seenRowKeys[$rowKey] = true;
            $allRows[] = $row;
            $otherId = $this->getOtherNodeId($row, $anchorId);
            if ($otherId !== null && !isset($nodeDepths[$otherId])) {
                $nodeDepths[$otherId] = 2;
            }
            if ($otherId !== null && $this->isTeLineageRow($row)) {
                $lineagePassNodeIds[$otherId] = true;
            }
        }

        $frontier = array_keys(array_filter(
            $nodeDepths,
            static fn(int $depth): bool => $depth === 2
        ));
        $expandedNodeIds = [];

        for ($depth = 2; $depth <= $keyLevel; $depth++) {
            if (empty($frontier)) {
                break;
            }

            $degrees = $this->loadNodeDegrees($frontier);
            $expandIds = [];
            foreach ($frontier as $nodeId) {
                $shouldExpand = (($degrees[$nodeId] ?? 0) > $threshold) || isset($lineagePassNodeIds[$nodeId]);
                if ($shouldExpand && !isset($expandedNodeIds[$nodeId])) {
                    $expandIds[] = $nodeId;
                    $expandedNodeIds[$nodeId] = true;
                }
            }

            if (empty($expandIds)) {
                break;
            }

            $nextFrontier = [];
            foreach ($expandIds as $expandId) {
                $directRows = $this->loadDirectRows($expandId);
                $expandedType = $this->detectExpandedNodeType($directRows);
                $expandedRows = $this->limitExpandedRowsByType($directRows, $expandedType, $expandLimit);
                foreach ($expandedRows as $extraRow) {
                    $rowKey = $this->buildRowKey($extraRow);
                    if (isset($seenRowKeys[$rowKey])) {
                        continue;
                    }
                    $seenRowKeys[$rowKey] = true;
                    $allRows[] = $extraRow;

                    $otherId = $this->getOtherNodeId($extraRow, $expandId);
                    if ($otherId === null || isset($nodeDepths[$otherId])) {
                        continue;
                    }
                    $nodeDepths[$otherId] = $depth + 1;
                    if ($this->isTeLineageRow($extraRow)) {
                        $lineagePassNodeIds[$otherId] = true;
                    }
                    if ($depth + 1 <= $keyLevel) {
                        $nextFrontier[] = $otherId;
                    }
                }
            }

            $frontier = array_values(array_unique($nextFrontier));
        }

        return $allRows;
    }

    private function detectExpandedNodeType(array $rows): string
    {
        foreach ($rows as $row) {
            $labels = $row['source_labels'] ?? [];
            if (is_array($labels) && !empty($labels)) {
                return $this->normalizeType($labels);
            }
        }
        return 'Unknown';
    }

    private function isTeLineageRow(array $row): bool
    {
        if ((string)($row['relation_type'] ?? '') !== 'SUBFAMILY_OF') {
            return false;
        }
        return $this->normalizeType($row['source_labels'] ?? []) === 'TE'
            && $this->normalizeType($row['target_labels'] ?? []) === 'TE';
    }

    private function limitExpandedRowsByType(array $rows, string $anchorType, int $fallbackLimit): array
    {
        $rows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['target_element_id'])));
        if (empty($rows)) {
            return [];
        }

        $limits = [
            'TE' => [
                'TE' => 10,
                'Disease' => 50,
                'Function' => 8,
                'Gene' => 12,
                'Protein' => 10,
                'RNA' => 8,
                'Mutation' => 8,
                'Pharmaceutical' => 6,
                'Toxin' => 4,
                'Lipid' => 3,
                'Peptide' => 3,
                'Carbohydrate' => 3,
                'Paper' => 8,
            ],
            'Disease' => [
                'TE' => 8,
                'Disease' => 6,
                'Function' => 12,
                'Gene' => 8,
                'Protein' => 8,
                'RNA' => 6,
                'Mutation' => 8,
                'Pharmaceutical' => 6,
                'Toxin' => 4,
                'Lipid' => 3,
                'Peptide' => 3,
                'Carbohydrate' => 3,
                'Paper' => 10,
            ],
            'Function' => [
                'TE' => 10,
                'Disease' => 12,
                'Function' => 8,
                'Gene' => 10,
                'Protein' => 8,
                'RNA' => 8,
                'Mutation' => 8,
                'Pharmaceutical' => 6,
                'Toxin' => 4,
                'Lipid' => 3,
                'Peptide' => 3,
                'Carbohydrate' => 3,
                'Paper' => 8,
            ],
            'Paper' => [
                'TE' => 8,
                'Disease' => 8,
                'Function' => 8,
                'Gene' => 8,
                'Protein' => 8,
                'RNA' => 6,
                'Mutation' => 8,
                'Pharmaceutical' => 6,
                'Toxin' => 4,
                'Lipid' => 3,
                'Peptide' => 3,
                'Carbohydrate' => 3,
                'Paper' => 0,
            ],
            'Unknown' => [],
        ];

        if (!isset($limits[$anchorType]) || empty($limits[$anchorType])) {
            return $this->limitExpandedRows($rows, $fallbackLimit);
        }

        $selected = [];
        $buckets = [];
        foreach ($rows as $row) {
            $targetType = $this->normalizeType($row['target_labels'] ?? []);
            if (!array_key_exists($targetType, $limits[$anchorType])) {
                continue;
            }
            $typeLimit = (int)$limits[$anchorType][$targetType];
            if ($typeLimit <= 0) {
                continue;
            }
            $buckets[$targetType] ??= [];
            $buckets[$targetType][] = $row;
        }

        foreach ($buckets as $targetType => $bucketRows) {
            usort($bucketRows, static function (array $a, array $b): int {
                $left = count($a['relation_pmids'] ?? []);
                $right = count($b['relation_pmids'] ?? []);
                if ($left === $right) {
                    return strcasecmp((string)($a['target_name'] ?? ''), (string)($b['target_name'] ?? ''));
                }
                return $right <=> $left;
            });
            $selected = array_merge($selected, array_slice($bucketRows, 0, (int)$limits[$anchorType][$targetType]));
        }

        return $selected;
    }

    private function limitExpandedRows(array $rows, int $limit): array
    {
        $rows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['target_element_id'])));
        usort($rows, static function (array $a, array $b): int {
            $left = count($a['relation_pmids'] ?? []);
            $right = count($b['relation_pmids'] ?? []);
            if ($left === $right) {
                return strcasecmp((string)($a['target_name'] ?? ''), (string)($b['target_name'] ?? ''));
            }
            return $right <=> $left;
        });
        return array_slice($rows, 0, max(1, $limit));
    }

    private function loadNodeDegrees(array $elementIds): array
    {
        $elementIds = array_values(array_unique(array_filter(array_map('strval', $elementIds))));
        if (empty($elementIds)) {
            return [];
        }

        $rows = $this->runNeo4j(
            <<<'CYPHER'
MATCH (n)
WHERE elementId(n) IN $elementIds
OPTIONAL MATCH (n)--()
RETURN elementId(n) AS element_id, count(*) AS degree
CYPHER,
            ['elementIds' => $elementIds]
        );

        $degrees = [];
        foreach ($rows as $row) {
            $degrees[(string)($row['element_id'] ?? '')] = (int)($row['degree'] ?? 0);
        }
        return $degrees;
    }

    private function buildRowKey(array $row): string
    {
        return implode('__', [
            (string)($row['source_element_id'] ?? ''),
            (string)($row['relation_type'] ?? ''),
            (string)($row['target_element_id'] ?? ''),
        ]);
    }

    private function getOtherNodeId(array $row, string $knownId): ?string
    {
        $sourceId = (string)($row['source_element_id'] ?? '');
        $targetId = (string)($row['target_element_id'] ?? '');
        if ($sourceId === $knownId) {
            return $targetId !== '' ? $targetId : null;
        }
        if ($targetId === $knownId) {
            return $sourceId !== '' ? $sourceId : null;
        }
        return null;
    }

    private function normalizeQuery(string $query): string
    {
        $trimmed = trim($query);
        $lower = mb_strtolower($trimmed);
        $aliases = [
            'te' => 'TE',
            'line1' => 'LINE-1',
            'line-1' => 'LINE-1',
            'l1' => 'LINE-1',
            '阿尔兹海默症' => "Alzheimer's disease",
            '阿兹海默症' => "Alzheimer's disease",
            '阿尔茨海默症' => "Alzheimer's disease",
            '阿尔茨海默病' => "Alzheimer's disease",
            'alzheimer disease' => "Alzheimer's disease",
            'alzheimer\'s disease' => "Alzheimer's disease",
            '亨廷顿病' => "Huntington's Disease",
            'down syndrome' => 'Down syndrome',
            '唐氏综合征' => 'Down syndrome',
            'rett综合征' => 'Rett syndrome',
            'rett 综合征' => 'Rett syndrome',
            'autism' => 'autism spectrum disorder',
            '自闭症' => 'autism spectrum disorder',
            '自闭症谱系障碍' => 'autism spectrum disorder',
            '乳腺癌' => 'breast cancer',
            'breast cancer' => 'breast cancer',
            '口腔鳞状细胞癌' => 'Oral Squamous Cell Carcinoma',
            '共济失调毛细血管扩张症' => 'ataxia telangiectasia',
            'l1hs' => 'L1HS',
            'alu' => 'Alu',
            'sva' => 'SVA',
        ];

        return $aliases[$lower] ?? $trimmed;
    }

    private function isLine1CanonicalTeName(string $name): bool
    {
        return in_array(mb_strtolower(trim($name)), ['line1', 'line-1', 'l1'], true);
    }

    private function shouldAllowFuzzySearch(string $normalized): bool
    {
        $trimmed = trim($normalized);
        if ($trimmed === '') {
            return false;
        }

        // Very short queries like "TE" are too ambiguous for CONTAINS matching
        // and tend to anchor on unrelated entities such as "teratoma".
        if (mb_strlen($trimmed) < 3) {
            return false;
        }

        return true;
    }

    private function findAnchorNode(string $query, string $normalized, string $requestedType = ''): ?array
    {
        $nodeType = $requestedType !== 'DiseaseClass' ? $requestedType : '';
        $exact = $this->runNeo4j(
            <<<'CYPHER'
MATCH (n)
WHERE ($nodeType = '' OR $nodeType IN labels(n))
  AND (
    toLower(coalesce(n.name, '')) = toLower($exact)
    OR coalesce(n.pmid, '') = $pmid
  )
RETURN
  elementId(n) AS element_id,
  labels(n) AS labels,
  n.name AS name,
  n.description AS description,
  n.pmid AS pmid,
  n.disease_class AS disease_class
LIMIT 10
CYPHER,
            ['exact' => $normalized, 'pmid' => $query, 'nodeType' => $nodeType]
        );

        if (!empty($exact)) {
            $primary = $exact[0];
            $primary['matches'] = array_map(
                fn(array $row): array => [
                    'name' => (string)($row['name'] ?? ''),
                    'type' => $this->normalizeType($row['labels'] ?? []),
                    'pmid' => (string)($row['pmid'] ?? ''),
                ],
                $exact
            );
            return $primary;
        }

        if (!$this->shouldAllowFuzzySearch($normalized)) {
            return null;
        }

        $fuzzy = $this->runNeo4j(
            <<<'CYPHER'
MATCH (n)
WHERE ($nodeType = '' OR $nodeType IN labels(n))
  AND toLower(coalesce(n.name, '')) CONTAINS toLower($keyword)
RETURN
  elementId(n) AS element_id,
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
    WHEN 'Paper' IN labels(n) THEN 3
    ELSE 4
  END,
  size(coalesce(n.name, ''))
LIMIT 10
CYPHER,
            ['keyword' => $normalized, 'nodeType' => $nodeType]
        );

        if (empty($fuzzy)) {
            return null;
        }

        $primary = $fuzzy[0];
        $primary['matches'] = array_map(
            fn(array $row): array => [
                'name' => (string)($row['name'] ?? ''),
                'type' => $this->normalizeType($row['labels'] ?? []),
                'pmid' => (string)($row['pmid'] ?? ''),
            ],
            $fuzzy
        );
        return $primary;
    }

    private function buildElements(array $anchor, array $rows): array
    {
        $nodes = [];
        $edges = [];
        $evidenceRecordsByPmid = $this->loadEvidenceRecordsByPmids($this->collectRelationPmids($rows));

        $this->addNode($nodes, [
            'element_id' => $anchor['element_id'],
            'labels' => $anchor['labels'],
            'name' => $anchor['name'],
            'description' => $anchor['description'] ?? '',
            'pmid' => $anchor['pmid'] ?? '',
            'disease_class' => $anchor['disease_class'] ?? '',
            'category_level' => $anchor['category_level'] ?? 0,
        ]);

        foreach ($rows as $row) {
            if (($row['target_element_id'] ?? null) === null) {
                continue;
            }
            $this->addNode($nodes, [
                'element_id' => $row['source_element_id'],
                'labels' => $row['source_labels'],
                'name' => $row['source_name'],
                'description' => $row['source_description'] ?? '',
                'pmid' => $row['source_pmid'] ?? '',
                'disease_class' => $row['source_disease_class'] ?? '',
                'category_level' => $row['source_category_level'] ?? 0,
            ]);
            $this->addNode($nodes, [
                'element_id' => $row['target_element_id'],
                'labels' => $row['target_labels'],
                'name' => $row['target_name'],
                'description' => $row['target_description'] ?? '',
                'pmid' => $row['target_pmid'] ?? '',
                'disease_class' => $row['target_disease_class'] ?? '',
                'category_level' => $row['target_category_level'] ?? 0,
            ]);

            $edgeId = $row['source_element_id'] . '__' . $row['relation_type'] . '__' . $row['target_element_id'];
            $edges[$edgeId] = [
                'data' => [
                    'id' => $edgeId,
                    'source' => $row['source_element_id'],
                    'target' => $row['target_element_id'],
                    'relation' => $row['relation_label'] ?: $row['relation_type'],
                    'relationType' => $row['relation_type'],
                    'evidence' => $row['relation_evidence'],
                    'pmids' => $row['relation_pmids'],
                    'evidence_records' => $this->evidenceRecordsForPmids($row['relation_pmids'], $evidenceRecordsByPmid),
                ],
            ];
            $edges[$edgeId]['data'] += $this->evidenceSupportData($row);

            foreach (($row['expanded'] ?? []) as $extra) {
                if (($extra['source_element_id'] ?? null) === null || ($extra['target_element_id'] ?? null) === null) {
                    continue;
                }
                $this->addNode($nodes, $extra + [
                    'element_id' => $extra['source_element_id'],
                    'labels' => $extra['source_labels'],
                    'name' => $extra['source_name'],
                    'description' => $extra['source_description'] ?? '',
                    'pmid' => $extra['source_pmid'] ?? '',
                    'disease_class' => $extra['source_disease_class'] ?? '',
                    'category_level' => $extra['source_category_level'] ?? 0,
                ]);
                $this->addNode($nodes, [
                    'element_id' => $extra['target_element_id'],
                    'labels' => $extra['target_labels'],
                    'name' => $extra['target_name'],
                    'description' => $extra['target_description'] ?? '',
                    'pmid' => $extra['target_pmid'] ?? '',
                    'disease_class' => $extra['target_disease_class'] ?? '',
                    'category_level' => $extra['target_category_level'] ?? 0,
                ]);
                $extraEdgeId = $extra['source_element_id'] . '__' . $extra['relation_type'] . '__' . $extra['target_element_id'];
                $edges[$extraEdgeId] = [
                    'data' => [
                        'id' => $extraEdgeId,
                        'source' => $extra['source_element_id'],
                        'target' => $extra['target_element_id'],
                        'relation' => $extra['relation_label'] ?: $extra['relation_type'],
                        'relationType' => $extra['relation_type'],
                        'evidence' => $extra['relation_evidence'],
                        'pmids' => $extra['relation_pmids'],
                        'evidence_records' => $this->evidenceRecordsForPmids($extra['relation_pmids'], $evidenceRecordsByPmid),
                    ],
                ];
                $edges[$extraEdgeId]['data'] += $this->evidenceSupportData($extra);
            }
        }

        $anchorType = $this->normalizeType($anchor['labels'] ?? []);
        if ($anchorType !== 'DiseaseClass') {
            $this->appendTopLevelDiseaseCategories($nodes, $edges);
        }
        $this->collapseDuplicateDiseaseNodes($nodes, $edges);

        $nodeIds = array_keys($nodes);
        $degrees = $this->loadNodeDegrees($nodeIds);
        $anchorId = (string)($anchor['element_id'] ?? '');
        $threshold = max(1, (int)($this->config['key_node_threshold'] ?? 15));

        foreach ($nodes as $id => &$node) {
            $degree = (int)($degrees[$id] ?? 0);
            $node['data']['degree'] = $degree;
            $node['data']['isKeyNode'] = ($id === $anchorId) || ($degree > $threshold);
        }
        unset($node);

        return array_merge(array_values($nodes), array_values($edges));
    }

    private function evidenceSupportData(array $row): array
    {
        return [
            'support_pmid_count' => $this->intValue($row['support_pmid_count'] ?? 0),
            'support_metric_paper_count' => $this->intValue($row['support_metric_paper_count'] ?? 0),
            'support_metric_coverage' => $this->floatValue($row['support_metric_coverage'] ?? 0.0),
            'support_if_max' => $this->nullableFloatValue($row['support_if_max'] ?? null),
            'support_if_mean' => $this->nullableFloatValue($row['support_if_mean'] ?? null),
            'support_if_median' => $this->nullableFloatValue($row['support_if_median'] ?? null),
            'support_jcr_q1_count' => $this->intValue($row['support_jcr_q1_count'] ?? 0),
            'support_jcr_q2_count' => $this->intValue($row['support_jcr_q2_count'] ?? 0),
            'support_jcr_q3_count' => $this->intValue($row['support_jcr_q3_count'] ?? 0),
            'support_jcr_q4_count' => $this->intValue($row['support_jcr_q4_count'] ?? 0),
            'support_journal_count' => $this->intValue($row['support_journal_count'] ?? 0),
            'support_publication_year_min' => $this->nullableIntValue($row['support_publication_year_min'] ?? null),
            'support_publication_year_max' => $this->nullableIntValue($row['support_publication_year_max'] ?? null),
        ];
    }

    private function collectRelationPmids(array $rows): array
    {
        $pmids = [];
        foreach ($rows as $row) {
            foreach (($row['relation_pmids'] ?? []) as $pmid) {
                $key = trim((string)$pmid);
                if ($key !== '') {
                    $pmids[$key] = true;
                }
            }
            foreach (($row['expanded'] ?? []) as $extra) {
                foreach (($extra['relation_pmids'] ?? []) as $pmid) {
                    $key = trim((string)$pmid);
                    if ($key !== '') {
                        $pmids[$key] = true;
                    }
                }
            }
        }
        return array_keys($pmids);
    }

    private function loadEvidenceRecordsByPmids(array $pmids): array
    {
        $pmids = array_values(array_unique(array_filter(array_map(
            static fn($pmid): string => trim((string)$pmid),
            $pmids
        ))));
        if (empty($pmids)) {
            return [];
        }

        $records = [];
        foreach (array_chunk($pmids, 500) as $chunk) {
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
                ['pmids' => $chunk]
            );

            foreach ($rows as $row) {
                $pmid = trim((string)($row['pmid'] ?? ''));
                if ($pmid === '') {
                    continue;
                }
                $records[$pmid] = $this->normalizeEvidenceRecord($pmid, $row);
            }
        }

        return $records;
    }

    private function evidenceRecordsForPmids(mixed $pmids, array $recordsByPmid): array
    {
        if (!is_array($pmids)) {
            return [];
        }

        $records = [];
        $seen = [];
        foreach ($pmids as $pmid) {
            $key = trim((string)$pmid);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
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

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private function floatValue(mixed $value): float
    {
        return is_numeric($value) ? (float)$value : 0.0;
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

    private function addNode(array &$nodes, array $row): void
    {
        $id = (string)($row['element_id'] ?? '');
        if ($id === '') {
            return;
        }
        if (isset($nodes[$id])) {
            return;
        }

        $type = $this->normalizeType($row['labels'] ?? []);
        $label = (string)($row['name'] ?? '');
        if ($type === 'Disease') {
            $label = $this->canonicalDiseaseLabel($label);
        }

        $nodes[$id] = [
            'data' => [
                'id' => $id,
                'label' => $label,
                'rawLabel' => (string)($row['name'] ?? ''),
                'type' => $type,
                'description' => (string)($row['description'] ?? ''),
                'pmid' => (string)($row['pmid'] ?? ''),
                'disease_class' => (string)($row['disease_class'] ?? ''),
                'category_level' => (int)($row['category_level'] ?? 0),
            ],
        ];

        if ($type === 'TE') {
            $taxonomy = $this->taxonomyPayloadForTeName((string)($row['name'] ?? ''));
            if ($taxonomy !== null) {
                $nodes[$id]['data']['taxonomy'] = $taxonomy;
            }
        }
    }

    private function taxonomyPayloadForTeName(string $name): ?array
    {
        $index = $this->loadTeTaxonomyIndex();
        $item = $index[$name] ?? $index[tekg_taxonomy_canonical_key($name)] ?? null;
        if (!is_array($item)) {
            return null;
        }

        return [
            'group' => $item['taxonomy_group'] ?? null,
            'status' => $item['taxonomy_status'] ?? null,
            'source' => $item['taxonomy_source'] ?? null,
            'canonical_name' => $item['taxonomy_canonical_name'] ?? null,
            'path' => $item['path'] ?? [],
            'path_labels' => $item['path_labels'] ?? [],
            'display_path' => $item['display_path'] ?? '',
            'is_leaf_standard' => (bool)($item['is_leaf_standard'] ?? false),
            'homepage_chart_included' => (bool)($item['homepage_chart_included'] ?? false),
        ];
    }

    private function loadTeTaxonomyIndex(): array
    {
        if (is_array($this->teTaxonomyIndex)) {
            return $this->teTaxonomyIndex;
        }

        try {
            $items = tekg_taxonomy_fetch_items(null, $this->config);
            $this->teTaxonomyIndex = tekg_taxonomy_index_items($items);
        } catch (Throwable) {
            $this->teTaxonomyIndex = [];
        }

        return $this->teTaxonomyIndex;
    }

    private function collapseDuplicateDiseaseNodes(array &$nodes, array &$edges): void
    {
        $groups = [];
        foreach ($nodes as $id => $node) {
            $data = $node['data'] ?? [];
            if (($data['type'] ?? '') !== 'Disease') {
                continue;
            }
            $label = trim((string)($data['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $groups[$label][] = $id;
        }

        if (empty($groups)) {
            return;
        }

        $idRemap = [];
        foreach ($groups as $label => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $keepId = $this->pickPreferredDiseaseNodeId($ids, $nodes, $label);
            foreach ($ids as $id) {
                if ($id === $keepId) {
                    continue;
                }
                $idRemap[$id] = $keepId;
                unset($nodes[$id]);
            }
        }

        if (empty($idRemap)) {
            return;
        }

        $collapsedEdges = [];
        foreach ($edges as $edge) {
            $data = $edge['data'] ?? [];
            $source = (string)($data['source'] ?? '');
            $target = (string)($data['target'] ?? '');
            if (isset($idRemap[$source])) {
                $data['source'] = $idRemap[$source];
            }
            if (isset($idRemap[$target])) {
                $data['target'] = $idRemap[$target];
            }
            if (($data['source'] ?? '') === ($data['target'] ?? '')) {
                continue;
            }
            $collapsedKey = implode('__', [
                (string)($data['source'] ?? ''),
                (string)($data['relationType'] ?? ''),
                (string)($data['target'] ?? ''),
            ]);
            if (isset($collapsedEdges[$collapsedKey])) {
                $existingPmids = $collapsedEdges[$collapsedKey]['data']['pmids'] ?? [];
                $newPmids = $data['pmids'] ?? [];
                $collapsedEdges[$collapsedKey]['data']['pmids'] = array_values(array_unique(array_merge($existingPmids, $newPmids)));
                if (($collapsedEdges[$collapsedKey]['data']['evidence'] ?? '') === '' && ($data['evidence'] ?? '') !== '') {
                    $collapsedEdges[$collapsedKey]['data']['evidence'] = $data['evidence'];
                }
                continue;
            }
            $data['id'] = $collapsedKey;
            $collapsedEdges[$collapsedKey] = ['data' => $data];
        }

        $edges = $collapsedEdges;
    }

    private function pickPreferredDiseaseNodeId(array $ids, array $nodes, string $canonicalLabel): string
    {
        $preferred = $ids[0];
        foreach ($ids as $id) {
            $raw = (string)(($nodes[$id]['data']['rawLabel'] ?? ''));
            if ($raw === $canonicalLabel && !$this->containsChinese($raw)) {
                return $id;
            }
            if (!$this->containsChinese($raw) && $this->containsChinese((string)($nodes[$preferred]['data']['rawLabel'] ?? ''))) {
                $preferred = $id;
            }
        }
        return $preferred;
    }

    private function canonicalDiseaseLabel(string $label): string
    {
        return $this->diseaseNameTranslations[$label] ?? $label;
    }

    private function loadDiseaseNameTranslations(): array
    {
        $path = dirname(__DIR__) . '/data/processed/entity_description_key_translation_cache.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $map = [];
        foreach ($decoded as $source => $target) {
            if (!is_string($source) || !is_string($target) || trim($source) === '' || trim($target) === '') {
                continue;
            }
            $map[$source] = $target;
        }
        return $map;
    }

    private function containsChinese(string $text): bool
    {
        return preg_match('/\p{Han}/u', $text) === 1;
    }

    private function normalizeType(array $labels): string
    {
        foreach (['TE', 'DiseaseClass', 'DiseaseCategory', 'Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Pharmaceutical', 'Toxin', 'Lipid', 'Peptide', 'Carbohydrate', 'Paper'] as $preferred) {
            if (in_array($preferred, $labels, true)) {
                return $preferred;
            }
        }
        return $labels[0] ?? 'Unknown';
    }

    private function runNeo4j(string $statement, array $parameters): array
    {
        $payload = json_encode([
            'statements' => [[
                'statement' => $statement,
                'parameters' => $parameters,
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($this->config['neo4j_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->config['neo4j_user'] . ':' . $this->config['neo4j_password'],
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Neo4j request failed: ' . $error);
        }
        if ($status >= 400) {
            throw new RuntimeException('Neo4j HTTP ' . $status);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Neo4j response is not valid JSON');
        }
        if (!empty($decoded['errors'])) {
            $message = (string)($decoded['errors'][0]['message'] ?? 'Neo4j query failed');
            throw new RuntimeException($message);
        }

        $results = $decoded['results'][0] ?? [];
        $columns = $results['columns'] ?? [];
        $rows = [];
        foreach (($results['data'] ?? []) as $row) {
            $mapped = [];
            foreach ($columns as $index => $column) {
                $mapped[$column] = $row['row'][$index] ?? null;
            }
            $rows[] = $mapped;
        }
        return $rows;
    }
}
