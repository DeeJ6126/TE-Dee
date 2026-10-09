<?php
declare(strict_types=1);

final class TekgAgentLiteraturePlugin implements TekgAgentPluginInterface
{
    private const PUBMED_CACHE_VERSION = 'pubmed_full_abstract_paged_v3';
    private const MAX_PUBMED_QUERIES = 6;
    private const MAX_PUBMED_RECORDS = 24;

    public function __construct(
        private readonly TekgAgentNeo4jClient $neo4j,
        private readonly array $config,
        private readonly TekgAgentCitationResolver $citationResolver,
    ) {
    }

    public function getName(): string
    {
        return 'Literature Plugin';
    }

    public function run(array $context): array
    {
        $started = microtime(true);
        $analysis = tekg_agent_context_analysis($context);
        $entities = tekg_agent_context_resolved_entities($context);
        $graphResult = tekg_agent_context_plugin_result($context, 'Graph Plugin');
        $arguments = is_array($context['qa_tool_arguments'] ?? null) ? $context['qa_tool_arguments'] : [];
        $page = max(0, min(20, (int)($arguments['page'] ?? 0)));
        $requestedLimit = max(1, min(8, (int)($arguments['limit'] ?? 8)));
        $errors = [];
        try {
            $localCitations = $this->collectLocalCitations($graphResult, $entities, $arguments !== []);
        } catch (Throwable $error) {
            $localCitations = [];
            $errors[] = 'Local graph literature lookup failed: ' . $error->getMessage();
        }
        $queryPlan = $this->buildPubMedQueries((string)($context['question'] ?? ''), $analysis, $localCitations, $entities);
        $queries = array_slice($queryPlan, 0, self::MAX_PUBMED_QUERIES);
        $queryTerms = array_column($queries, 'query_term');
        $recordsPerQuery = $queries === [] ? 0 : min($requestedLimit, max(1, intdiv(self::MAX_PUBMED_RECORDS, count($queries))));
        $queryCoverage = [];

        $pubmedCitations = [];
        $pubmedTotalCount = 0;
        $pubmedRetrievedCount = 0;
        $pubmedFilteredCount = 0;
        $filteredPubmedRecords = [];
        $evidenceItems = [];
        $previewItems = [];

        foreach ($queries as $query) {
            $term = (string)$query['query_term'];
            $queryScope = (array)$query['entity_scope'];
            try {
                $result = $this->searchPubMed($term, $recordsPerQuery, $page);
                $pubmedTotalCount += (int)($result['total_count'] ?? 0);
                $retrieved = array_values(array_filter((array)($result['citations'] ?? []), 'is_array'));
                $filtered = $this->filterPubMedCitations($retrieved, (array)$query['entities']);
                foreach ($filtered['retained'] as &$citation) {
                    $citation['entity_scopes'] = $queryScope;
                    $citation['query_scopes'] = [['query_term' => $term, 'entity_scope' => $queryScope, 'page' => $page, 'retstart' => $page * $recordsPerQuery]];
                }
                unset($citation);
                $queryCoverage[] = ['query_term' => $term, 'entity_scope' => $queryScope, 'status' => 'completed', 'page' => $page, 'retstart' => $page * $recordsPerQuery, 'total_count' => (int)($result['total_count'] ?? 0), 'retrieved_count' => count($retrieved), 'retained_count' => count($filtered['retained'])];
                $pubmedRetrievedCount += count($retrieved);
                $pubmedFilteredCount += count($filtered['excluded']);
                $pubmedCitations = array_merge($pubmedCitations, $filtered['retained']);
                $filteredPubmedRecords = array_merge($filteredPubmedRecords, $filtered['excluded']);
                $evidenceItems[] = tekg_agent_make_diagnostic_item(
                    $this->getName(),
                    'Searched PubMed with the query "' . $term . '".',
                    [
                        'query_status' => 'completed',
                        'query_term' => $term,
                        'entity_scope' => $queryScope,
                        'page' => $page,
                        'retstart' => $page * $recordsPerQuery,
                        'total_count' => (int)($result['total_count'] ?? 0),
                        'retrieved_count' => count($retrieved),
                        'retained_count' => count($filtered['retained']),
                        'filtered_count' => count($filtered['excluded']),
                    ],
                    [
                        'title' => 'PubMed query',
                        'meta' => $term,
                        'body' => 'External literature search executed for this query.',
                    ],
                    [
                        'entity_scope' => $term,
                        'raw_source_ref' => ['query_term' => $term, 'entity_scope' => $queryScope],
                        'evidence_type' => 'literature_query',
                        'coverage_dimension' => 'retrieval',
                        'provenance' => ['source' => 'pubmed'],
                    ]
                );
            } catch (Throwable $error) {
                $errors[] = 'PubMed query failed for "' . $term . '": ' . $error->getMessage();
                $queryCoverage[] = ['query_term' => $term, 'entity_scope' => $queryScope, 'status' => 'error', 'page' => $page, 'retstart' => $page * $recordsPerQuery, 'retrieved_count' => 0, 'retained_count' => 0];
            }
        }
        foreach (array_slice($queryPlan, self::MAX_PUBMED_QUERIES) as $query) {
            $queryCoverage[] = ['query_term' => $query['query_term'], 'entity_scope' => $query['entity_scope'], 'status' => 'budget_excluded', 'retrieved_count' => 0, 'retained_count' => 0];
        }
        if (count($queryPlan) > self::MAX_PUBMED_QUERIES) {
            $errors[] = 'PubMed query budget limited retrieval to ' . self::MAX_PUBMED_QUERIES . ' entity-scoped queries; unsearched scopes are listed in query_coverage.';
        }

        $citations = $this->citationResolver->merge(
            $this->citationResolver->normalizeMany($localCitations, 'local_graph'),
            $this->citationResolver->normalizeMany($pubmedCitations, 'pubmed')
        );
        $citations = $this->balanceCitationScopes($citations);

        $reviewedCount = count($citations);
        $localCount = count($localCitations);
        $strictLocalHits = count(array_filter($localCitations, static fn(array $citation): bool => (($citation['match_mode'] ?? 'strict') === 'strict')));
        $broadLocalHits = count(array_filter($localCitations, static fn(array $citation): bool => (($citation['match_mode'] ?? 'strict') === 'broad')));

        foreach ($citations as $citation) {
            if (count($previewItems) < 5) {
                $previewItems[] = [
                    'title' => (string)(($citation['title'] ?? '') !== '' ? $citation['title'] : ('PMID ' . (string)($citation['pmid'] ?? ''))),
                    'meta' => trim(implode(' | ', array_filter([
                        (string)($citation['source'] ?? ''),
                        (string)($citation['journal'] ?? ''),
                        (string)($citation['year'] ?? ''),
                        ($citation['pmid'] ?? '') !== '' ? 'PMID ' . (string)$citation['pmid'] : '',
                    ]))),
                    'url' => (string)($citation['url'] ?? ''),
                    'body' => trim((string)($citation['abstract_summary'] ?? '')),
                ];
            }
            $title = trim((string)($citation['title'] ?? ''));
            $pmid = trim((string)($citation['pmid'] ?? ''));
            $evidenceItems[] = tekg_agent_make_evidence_item(
                $this->getName(),
                $title !== ''
                    ? 'Literature evidence includes "' . $title . '"' . ($pmid !== '' ? ' (PMID ' . $pmid . ').' : '.')
                    : ($pmid !== '' ? 'Literature evidence includes PMID ' . $pmid . '.' : 'A literature record was selected for synthesis.'),
                $title !== '' ? $title : ($pmid !== '' ? 'PMID ' . $pmid : 'Literature record'),
                'medium',
                [
                    'pmid' => $pmid,
                    'source' => (string)($citation['source'] ?? ''),
                    'query_term' => (string)($citation['query_term'] ?? ''),
                ],
                [
                    'title' => $title !== '' ? $title : ($pmid !== '' ? 'PMID ' . $pmid : 'Literature record'),
                    'meta' => trim(implode(' | ', array_filter([
                        (string)($citation['source'] ?? ''),
                        (string)($citation['journal'] ?? ''),
                        (string)($citation['year'] ?? ''),
                    ]))),
                    'body' => trim((string)($citation['abstract_summary'] ?? '')),
                    'url' => (string)($citation['url'] ?? ''),
                ],
                [
                    'evidence_type' => 'literature_record',
                    'coverage_dimension' => 'literature_evidence',
                    'subject' => $title !== '' ? $title : ($pmid !== '' ? 'PMID ' . $pmid : 'Literature record'),
                    'provenance' => ['source' => (string)($citation['source'] ?? '')],
                    'citations' => [$citation],
                ]
            );
        }

        $displaySummary = $this->buildDisplaySummary(
            $pubmedTotalCount,
            $pubmedRetrievedCount,
            count($pubmedCitations),
            $reviewedCount,
            $localCount,
            $queryTerms !== []
        );
        $resultMessage = $this->buildResultMessage($citations, $pubmedTotalCount);

        return [
            'plugin_name' => $this->getName(),
            'status' => tekg_agent_plugin_status($citations !== [], $errors),
            'query_summary' => $queryTerms === []
                ? 'Used local graph literature only.'
                : 'Collected local literature and queried PubMed via NCBI E-utilities.',
            'results' => [
                'query_terms' => $queryTerms,
                'query_coverage' => $queryCoverage,
                'retrieval_budget' => ['max_queries' => self::MAX_PUBMED_QUERIES, 'max_records' => self::MAX_PUBMED_RECORDS, 'records_per_query' => $recordsPerQuery, 'page' => $page, 'retstart' => $page * $recordsPerQuery],
                'local_citation_count' => $localCount,
                'pubmed_total_hits' => $pubmedTotalCount,
                'pubmed_total_hit_semantics' => 'sum_of_query_counts_may_overlap',
                'pubmed_retrieved_count' => $pubmedRetrievedCount,
                'pubmed_retained_count' => count($pubmedCitations),
                'pubmed_filtered_count' => $pubmedFilteredCount,
                'filtered_pubmed_records' => $filteredPubmedRecords,
                'reviewed_citation_count' => $reviewedCount,
                'citations' => $citations,
            ],
            'display_label' => 'Reviewed ' . $reviewedCount . ' literature records',
            'display_summary' => $displaySummary,
            'display_details' => [
                'summary' => $displaySummary,
                'preview_items' => $previewItems,
                'evidence_items' => $evidenceItems,
                'citations' => $citations,
                'raw_preview' => [
                    'query_terms' => $queryTerms,
                    'query_coverage' => $queryCoverage,
                    'local_citation_count' => $localCount,
                    'pubmed_total_hits' => $pubmedTotalCount,
                    'pubmed_retrieved_count' => $pubmedRetrievedCount,
                    'pubmed_retained_count' => count($pubmedCitations),
                    'pubmed_filtered_count' => $pubmedFilteredCount,
                    'reviewed_citation_count' => $reviewedCount,
                    'citations' => $citations,
                ],
                'result_message' => $resultMessage,
            ],
            'result_counts' => [
                'local_hits' => $localCount,
                'strict_local_hits' => $strictLocalHits,
                'broad_local_hits' => $broadLocalHits,
                'pubmed_candidates' => $pubmedTotalCount,
                'pubmed_retrieved' => $pubmedRetrievedCount,
                'pubmed_retained' => count($pubmedCitations),
                'pubmed_filtered' => $pubmedFilteredCount,
                'reviewed' => $reviewedCount,
            ],
            'evidence_items' => $evidenceItems,
            'citations' => $citations,
            'errors' => $errors,
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    private function collectLocalCitations(array $graphResult, array $entities, bool $explicitScope = false): array
    {
        $citations = tekg_agent_plugin_result_citations($graphResult);
        if ($citations !== []) {
            return $this->citationResolver->normalizeMany($citations, 'local_graph');
        }

        $hasTe = count(array_filter($entities, static fn(array $entity): bool => ($entity['type'] ?? '') === 'TE')) > 0;
        $hasDisease = count(array_filter($entities, static fn(array $entity): bool => ($entity['type'] ?? '') === 'Disease')) > 0;
        // Independent TE->Paper or Disease->Paper membership does not establish
        // the selected TE-disease pair. The registry supplies pair-scoped edges.
        if ($explicitScope && $hasTe && $hasDisease) { return []; }

        $rows = [];
        foreach ($entities as $entity) {
            $candidateGroups = $this->entityCandidateGroups($entity);
            $type = (string)($entity['type'] ?? 'TE');
            if ($explicitScope && $type === 'Disease') { continue; }
            $sourceLabel = $type === 'Disease' ? 'Disease' : 'TE';
            $cypher = "MATCH (a:$sourceLabel)-[r]->(p:Paper)
                       WHERE replace(replace(replace(toLower(trim(coalesce(a.name,''))), '-', ''), '_', ''), ' ', '') = replace(replace(replace(toLower(trim(\$entity)), '-', ''), '_', ''), ' ', '')
                       RETURN coalesce(p.pmid,'') AS pmid, coalesce(p.name,'') AS title, '' AS year, '' AS journal
                       LIMIT 24";
            foreach ($candidateGroups as $mode => $candidates) {
                foreach ($candidates as $candidate) {
                    $candidateRows = $this->neo4j->run($cypher, ['entity' => $candidate]);
                    if ($candidateRows === []) {
                        continue;
                    }
                    foreach ($candidateRows as $candidateRow) {
                        $candidateRow['matched_alias'] = $candidate;
                        $candidateRow['match_mode'] = $mode;
                        $candidateRow['entity_scope'] = (string)($entity['canonical_label'] ?? $entity['label'] ?? $candidate);
                        $rows[] = $candidateRow;
                    }
                    break 2;
                }
            }
        }

        foreach ($rows as $row) {
            $citations[] = [
                'source' => 'local_graph',
                'pmid' => trim((string)($row['pmid'] ?? '')),
                'title' => trim((string)($row['title'] ?? '')),
                'year' => trim((string)($row['year'] ?? '')),
                'journal' => trim((string)($row['journal'] ?? '')),
                'matched_alias' => trim((string)($row['matched_alias'] ?? '')),
                'match_mode' => trim((string)($row['match_mode'] ?? 'strict')),
                'entity_scopes' => [(string)($row['entity_scope'] ?? $row['matched_alias'] ?? '')],
            ];
        }

        return $this->citationResolver->normalizeMany($citations, 'local_graph');
    }

    private function buildPubMedTerms(string $question, array $analysis, array $localCitations, array $resolvedEntities = []): array
    {
        return array_column(array_slice($this->buildPubMedQueries($question, $analysis, $localCitations, $resolvedEntities), 0, self::MAX_PUBMED_QUERIES), 'query_term');
    }

    private function buildPubMedQueries(string $question, array $analysis, array $localCitations, array $resolvedEntities = []): array
    {
        $needsPubMed = ($analysis['needs_external_literature'] ?? false)
            || ($analysis['asks_for_papers'] ?? false)
            || ($analysis['compare_mode'] ?? false)
            || count($localCitations) < 3;

        if (!$needsPubMed) {
            return [];
        }

        $entities = $resolvedEntities !== []
            ? $resolvedEntities
            : (is_array($analysis['normalized_entities'] ?? null) ? $analysis['normalized_entities'] : []);
        $teEntities = [];
        $diseaseEntities = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            if (($entity['type'] ?? '') === 'TE') {
                $teEntities[] = $entity;
            } elseif (($entity['type'] ?? '') === 'Disease') {
                $diseaseEntities[] = $entity;
            }
        }

        $keywords = is_array($analysis['question_keywords'] ?? null) ? $analysis['question_keywords'] : [];
        $queries = [];
        // Disease-first order keeps every TE represented before expanding to another disease.
        foreach ($diseaseEntities !== [] ? $diseaseEntities : [[]] as $disease) {
            foreach ($teEntities !== [] ? $teEntities : [[]] as $te) {
                $scopeEntities = array_values(array_filter([$te, $disease], static fn(array $entity): bool => $entity !== []));
                $term = $this->composeQueryTerm($te, $disease, $keywords, (string)($analysis['intent'] ?? 'relationship'));
                if ($term === '') {
                    continue;
                }
                $queries[$term] = [
                    'query_term' => $term,
                    'entity_scope' => array_values(array_map(static fn(array $entity): string => trim((string)($entity['canonical_label'] ?? $entity['label'] ?? '')), $scopeEntities)),
                    'entities' => $scopeEntities,
                ];
            }
        }
        if ($queries !== []) {
            return array_values($queries);
        }
        $question = trim($question);
        return $question === '' ? [] : [['query_term' => $question, 'entity_scope' => [], 'entities' => []]];
    }

    private function composeQueryTerm(array $te, array $disease, array $keywords, string $intent = 'relationship'): string
    {
        $parts = [];
        $teClause = $this->entityQueryClause($te, true);
        if ($teClause !== '') {
            $parts[] = $teClause;
        }
        $diseaseClause = $this->entityQueryClause($disease, false);
        if ($diseaseClause !== '') {
            $parts[] = $diseaseClause;
        }
        $normalizedKeywords = [];
        foreach ($keywords as $keyword) {
            $normalized = $this->normalizeKeywordForQuery((string)$keyword);
            if ($normalized !== '' && !in_array($normalized, $normalizedKeywords, true)) {
                $normalizedKeywords[] = $normalized;
            }
        }
        if ($normalizedKeywords === []) {
            $fallback = $this->fallbackKeywordForIntent($intent);
            if ($fallback !== '') {
                $normalizedKeywords[] = $fallback;
            }
        }
        foreach ($normalizedKeywords as $keyword) {
            if (!$this->queryAlreadyContains($parts, $keyword)) {
                $parts[] = '"' . $keyword . '"[Title/Abstract]';
            }
        }
        return implode(' AND ', array_filter($parts));
    }

    private function entityQueryClause(array $entity, bool $isTe): string
    {
        if ($entity === []) {
            return '';
        }
        $canonical = trim((string)($entity['canonical_label'] ?? $entity['label'] ?? ''));
        $aliases = array_values(array_filter(array_map(
            fn($value): string => $this->normalizeKeywordForQuery((string)$value),
            (array)($entity['aliases'] ?? [])
        )));
        array_unshift($aliases, $this->normalizeKeywordForQuery($canonical));

        $normalizedCanonical = strtolower(preg_replace('/[^a-z0-9]+/i', '', $canonical) ?? '');
        if ($isTe && in_array($normalizedCanonical, ['te', 'tes', 'transposableelement', 'transposableelements'], true)) {
            $aliases = ['transposable element', 'transposable elements', 'transposon', 'retrotransposon'];
        } elseif ($isTe && in_array($normalizedCanonical, ['line1', 'l1'], true)) {
            $aliases[] = 'LINE-1';
            $aliases[] = 'LINE 1';
            $aliases[] = 'long interspersed nuclear element 1';
        }

        $safeAliases = [];
        foreach ($aliases as $alias) {
            $alias = trim($alias);
            if ($alias === '' || ($isTe && preg_match('/^TEs?$/i', $alias))) {
                continue;
            }
            $key = strtolower($alias);
            if (!isset($safeAliases[$key])) {
                $safeAliases[$key] = $alias;
            }
        }
        if ($safeAliases === []) {
            return '';
        }
        $clauses = array_map(
            static fn(string $alias): string => '"' . $alias . '"[Title/Abstract]',
            array_values($safeAliases)
        );
        return count($clauses) === 1 ? $clauses[0] : '(' . implode(' OR ', $clauses) . ')';
    }

    private function queryAlreadyContains(array $parts, string $keyword): bool
    {
        $needle = strtolower(preg_replace('/[^a-z0-9]+/i', '', $keyword) ?? '');
        if ($needle === '') {
            return true;
        }
        foreach ($parts as $part) {
            $haystack = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$part) ?? '');
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function filterPubMedCitations(array $citations, array $resolvedEntities): array
    {
        $tePhrases = [];
        $diseasePhrases = [];
        foreach ($resolvedEntities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            if (($entity['type'] ?? '') === 'TE') {
                $tePhrases = array_merge($tePhrases, $this->entityRelevancePhrases($entity, true));
            } elseif (($entity['type'] ?? '') === 'Disease') {
                $diseasePhrases = array_merge($diseasePhrases, $this->entityRelevancePhrases($entity, false));
            }
        }
        $tePhrases = array_values(array_unique($tePhrases));
        $diseasePhrases = array_values(array_unique($diseasePhrases));

        $retained = [];
        $excluded = [];
        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $text = trim((string)($citation['title'] ?? '') . ' ' . (string)($citation['abstract_text'] ?? $citation['abstract_summary'] ?? ''));
            $matchedTe = $this->matchedPhrase($text, $tePhrases);
            $matchedDisease = $this->matchedPhrase($text, $diseasePhrases);
            $tePass = $tePhrases === [] || $matchedTe !== '';
            $diseasePass = $diseasePhrases === [] || $matchedDisease !== '';
            if ($tePass && $diseasePass) {
                $matches = array_values(array_filter([$matchedTe, $matchedDisease]));
                $citation['relevance'] = $matches === []
                    ? 'PubMed external search; no resolved entity scope was available for deterministic filtering.'
                    : 'Matched resolved scope: ' . implode('; ', $matches) . '.';
                $retained[] = $citation;
                continue;
            }
            $citation['excluded_reason'] = !$tePass
                ? 'missing_resolved_te_scope'
                : 'missing_resolved_disease_scope';
            $excluded[] = $citation;
        }

        return ['retained' => $retained, 'excluded' => $excluded];
    }

    private function entityRelevancePhrases(array $entity, bool $isTe): array
    {
        $canonical = trim((string)($entity['canonical_label'] ?? $entity['label'] ?? ''));
        $phrases = array_merge([$canonical], (array)($entity['aliases'] ?? []));
        $normalizedCanonical = strtolower(preg_replace('/[^a-z0-9]+/i', '', $canonical) ?? '');
        if ($isTe && in_array($normalizedCanonical, ['te', 'tes', 'transposableelement', 'transposableelements'], true)) {
            $phrases = ['transposable element', 'transposable elements', 'transposon', 'transposons', 'retrotransposon', 'retrotransposons'];
        } elseif ($isTe && in_array($normalizedCanonical, ['line1', 'l1'], true)) {
            $phrases[] = 'LINE-1';
            $phrases[] = 'LINE 1';
            $phrases[] = 'long interspersed nuclear element 1';
            $phrases[] = 'long interspersed element 1';
        } elseif (!$isTe && in_array($normalizedCanonical, ['cancer', 'cancers'], true)) {
            $phrases = array_merge($phrases, [
                'cancer',
                'cancers',
                'multicancer',
                'carcinoma',
                'adenocarcinoma',
                'tumor',
                'tumors',
                'tumour',
                'tumours',
                'neoplasm',
                'neoplasms',
                'leukemia',
                'leukaemia',
                'lymphoma',
                'oncogenic',
            ]);
        }

        $normalized = [];
        foreach ($phrases as $phrase) {
            $value = $this->normalizeSearchText((string)$phrase);
            if ($value === '' || ($isTe && in_array($value, ['te', 'tes'], true))) {
                continue;
            }
            $normalized[] = $value;
        }
        return array_values(array_unique($normalized));
    }

    private function matchedPhrase(string $text, array $phrases): string
    {
        if ($phrases === []) {
            return '';
        }
        $normalizedText = $this->normalizeSearchText($text);
        $paddedText = ' ' . $normalizedText . ' ';
        $compactText = str_replace(' ', '', $normalizedText);
        foreach ($phrases as $phrase) {
            $compactPhrase = str_replace(' ', '', $phrase);
            if ($phrase !== '' && (
                str_contains($paddedText, ' ' . $phrase . ' ')
                || (preg_match('/\d/', $phrase) === 1 && $compactPhrase !== '' && str_contains($compactText, $compactPhrase))
            )) {
                return $phrase;
            }
        }
        return '';
    }

    private function normalizeSearchText(string $value): string
    {
        $value = tekg_agent_lower($value);
        return trim(preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '');
    }

    private function normalizeKeywordForQuery(string $keyword): string
    {
        $value = trim($keyword);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9 _\\-\\/\x{0027}\x{2019}]+$/u', $value)) {
            return '';
        }
        return $value;
    }

    private function fallbackKeywordForIntent(string $intent): string
    {
        return match ($intent) {
            'sequence' => 'sequence',
            'mechanism' => 'mechanism',
            'literature' => '',
            'classification' => 'classification',
            'expression' => 'expression',
            'genome' => 'genome',
            'comparison' => '',
            default => '',
        };
    }

    private function searchPubMed(string $term, int $retmax = 8, int $page = 0): array
    {
        $retmax = max(1, min(8, $retmax));
        $page = max(0, min(20, $page));
        $cachePath = $this->pubmedCachePath($term, $retmax, $page);
        if (is_file($cachePath) && filemtime($cachePath) >= time() - 86400) {
            $cached = json_decode((string)file_get_contents($cachePath), true);
            if (is_array($cached) && ($cached['cache_version'] ?? '') === self::PUBMED_CACHE_VERSION) {
                return $cached;
            }
        }

        $base = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/';
        $common = ['db' => 'pubmed', 'retmode' => 'json', 'tool' => (string)$this->config['pubmed_tool']];
        if (($this->config['pubmed_email'] ?? '') !== '') {
            $common['email'] = (string)$this->config['pubmed_email'];
        }

        $search = $this->httpJson($this->pubmedSearchUrl($term, $retmax, $page, $common));
        $totalCount = (int)($search['esearchresult']['count'] ?? 0);
        $ids = array_values(array_filter((array)($search['esearchresult']['idlist'] ?? []), static fn($id): bool => trim((string)$id) !== ''));
        if ($ids === []) {
            $result = ['cache_version' => self::PUBMED_CACHE_VERSION, 'term' => $term, 'page' => $page, 'retstart' => $page * $retmax, 'total_count' => $totalCount, 'citations' => []];
            file_put_contents($cachePath, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return $result;
        }

        $summary = $this->httpJson($base . 'esummary.fcgi?' . http_build_query(array_replace($common, ['id' => implode(',', $ids)])));
        $abstracts = $this->fetchPubMedAbstracts($ids, $common);
        $citations = [];
        foreach ($ids as $pmid) {
            $doc = $summary['result'][$pmid] ?? [];
            if (!is_array($doc)) {
                continue;
            }
            $abstract = $this->normalizeAbstract($abstracts[$pmid] ?? '');
            $citations[] = [
                'source' => 'pubmed',
                'pmid' => (string)$pmid,
                'title' => trim((string)($doc['title'] ?? '')),
                'year' => trim((string)($doc['pubdate'] ?? '')),
                'journal' => trim((string)($doc['fulljournalname'] ?? '')),
                'url' => 'https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode((string)$pmid) . '/',
                'relevance' => 'PubMed external search',
                'abstract_text' => $abstract,
                // Preserve the legacy field as complete abstract text for existing consumers.
                'abstract_summary' => $abstract,
                'material_level' => $abstract !== '' ? 'abstract' : 'metadata',
                'abstract_status' => $abstract !== '' ? 'available' : 'not_provided',
                'query_term' => $term,
                'query_page' => $page,
                'query_retstart' => $page * $retmax,
            ];
        }

        $result = [
            'cache_version' => self::PUBMED_CACHE_VERSION,
            'term' => $term,
            'page' => $page,
            'retstart' => $page * $retmax,
            'total_count' => $totalCount,
            'citations' => $citations,
        ];
        file_put_contents($cachePath, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $result;
    }

    private function fetchPubMedAbstracts(array $ids, array $common): array
    {
        $xml = $this->httpText($this->pubmedAbstractUrl($ids, $common));
        return $this->parsePubMedAbstracts($xml);
    }

    private function balanceCitationScopes(array $citations): array
    {
        $groups = [];
        foreach ($citations as $citation) {
            $scopes = array_values((array)($citation['entity_scopes'] ?? []));
            $groups[$scopes[0] ?? '_unscoped'][] = $citation;
        }
        if (isset($groups['_unscoped'])) {
            $unscoped = $groups['_unscoped'];
            unset($groups['_unscoped']);
            $groups['_unscoped'] = $unscoped;
        }
        $balanced = [];
        while ($groups !== []) {
            foreach ($groups as $key => &$group) {
                $balanced[] = array_shift($group);
                if ($group === []) {
                    unset($groups[$key]);
                }
            }
            unset($group);
        }
        return $balanced;
    }

    private function pubmedAbstractUrl(array $ids, array $common): string
    {
        return 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?' . http_build_query(array_replace($common, ['id' => implode(',', $ids), 'rettype' => 'abstract', 'retmode' => 'xml']));
    }

    private function pubmedCachePath(string $term, int $retmax, int $page = 0): string
    {
        return rtrim((string)$this->config['pubmed_cache_dir'], '/\\') . '/' . self::PUBMED_CACHE_VERSION . '_' . md5($term . ':' . $retmax . ':' . $page) . '.json';
    }

    private function pubmedSearchUrl(string $term, int $retmax, int $page, array $common): string
    {
        return 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?' . http_build_query(array_replace($common, [
            'term' => $term, 'retmax' => $retmax, 'retstart' => $page * $retmax, 'sort' => 'relevance',
        ]));
    }

    private function parsePubMedAbstracts(string $xml): array
    {
        $parsed = @simplexml_load_string($xml);
        if ($parsed === false) {
            throw new RuntimeException('PubMed abstract fetch returned invalid XML.');
        }

        $abstracts = [];
        foreach ($parsed->PubmedArticle as $article) {
            $pmid = trim((string)($article->MedlineCitation->PMID ?? ''));
            $pieces = [];
            foreach ($article->MedlineCitation->Article->Abstract->AbstractText ?? [] as $fragment) {
                // asXML/strip_tags retain nested italic/sub/sup text that SimpleXML casts omit.
                $text = html_entity_decode(strip_tags((string)$fragment->asXML()), ENT_QUOTES | ENT_XML1, 'UTF-8');
                $text = $this->normalizeAbstract($text);
                $label = trim((string)($fragment['Label'] ?? ''));
                if ($text !== '') {
                    $pieces[] = ($label !== '' ? $label . ': ' : '') . $text;
                }
            }
            if ($pmid !== '') {
                $abstracts[$pmid] = trim(implode(' ', array_filter($pieces)));
            }
        }
        return $abstracts;
    }

    private function normalizeAbstract(string $abstract): string
    {
        $abstract = trim(preg_replace('/\s+/u', ' ', $abstract) ?? $abstract);
        if ($abstract === '') {
            return '';
        }
        return $abstract;
    }

    private function buildDisplaySummary(
        int $pubmedTotalCount,
        int $pubmedRetrievedCount,
        int $pubmedRetainedCount,
        int $reviewedCount,
        int $localCount,
        bool $usedPubMed
    ): string
    {
        if ($usedPubMed) {
            return 'I combined local graph literature with entity-scoped PubMed searches. The sum of query hit counts was ' . $pubmedTotalCount . ' (queries may overlap); the plugin inspected ' . $pubmedRetrievedCount . ' top records, retained ' . $pubmedRetainedCount . ' after entity-scope filtering, and carried ' . $reviewedCount . ' deduplicated citations into the answer.';
        }
        return 'This round mainly relied on local graph literature evidence and assembled ' . $localCount . ' directly citable records.';
    }

    private function buildResultMessage(array $citations, int $pubmedTotalCount): string
    {
        if ($citations === []) {
            return 'This round did not yield stable literature evidence, so I will need stronger local context or more specific search terms.';
        }
        return 'Retrieved citation metadata and available complete abstracts are ready for scope-aware synthesis; scientific claims must be supported by their supplied text.';
    }

    private function httpJson(string $url): array
    {
        $text = $this->httpText($url);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('PubMed returned invalid JSON.');
        }
        return $decoded;
    }

    private function httpText(string $url): string
    {
        $timeout = $this->remainingHttpTimeout();
        $response = tekg_agent_http_request($url, 'GET', ['Accept: application/json, text/xml'], null, $timeout, (bool)($this->config['ssl_verify'] ?? false));
        if ((int)$response['status'] >= 400) {
            throw new RuntimeException('PubMed returned HTTP ' . (int)$response['status']);
        }
        return (string)$response['body'];
    }

    private function remainingHttpTimeout(): int
    {
        $deadline = (float)($this->config['qa_deadline'] ?? 0);
        if ($deadline <= 0) { return 45; }
        $remaining = (int)floor($deadline - microtime(true));
        if ($remaining < 1) { throw new RuntimeException('Literature retrieval stopped because the QA run time budget expired.'); }
        return min(45, $remaining);
    }

    private function entityCandidateGroups(array $entity): array
    {
        return tekg_agent_entity_candidate_groups($entity);
    }
}
