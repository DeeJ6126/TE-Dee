<?php
declare(strict_types=1);

final class TekgAgentLiteraturePlugin implements TekgAgentPluginInterface
{
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
        $errors = [];
        try {
            $localCitations = $this->collectLocalCitations($graphResult, $entities);
        } catch (Throwable $error) {
            $localCitations = [];
            $errors[] = 'Local graph literature lookup failed: ' . $error->getMessage();
        }
        $queryTerms = $this->buildPubMedTerms((string)($context['question'] ?? ''), $analysis, $localCitations, $entities);

        $pubmedCitations = [];
        $pubmedTotalCount = 0;
        $pubmedRetrievedCount = 0;
        $pubmedFilteredCount = 0;
        $filteredPubmedRecords = [];
        $evidenceItems = [];
        $previewItems = [];

        foreach ($queryTerms as $term) {
            try {
                $result = $this->searchPubMed($term);
                $pubmedTotalCount += (int)($result['total_count'] ?? 0);
                $retrieved = array_values(array_filter((array)($result['citations'] ?? []), 'is_array'));
                $filtered = $this->filterPubMedCitations($retrieved, $entities);
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
                        'raw_source_ref' => ['query_term' => $term],
                        'evidence_type' => 'literature_query',
                        'coverage_dimension' => 'retrieval',
                        'provenance' => ['source' => 'pubmed'],
                    ]
                );
            } catch (Throwable $error) {
                $errors[] = 'PubMed query failed for "' . $term . '": ' . $error->getMessage();
            }
        }

        $citations = $this->citationResolver->merge(
            $this->citationResolver->normalizeMany($localCitations, 'local_graph'),
            $this->citationResolver->normalizeMany($pubmedCitations, 'pubmed')
        );

        $reviewedCount = count($citations);
        $localCount = count($localCitations);
        $strictLocalHits = count(array_filter($localCitations, static fn(array $citation): bool => (($citation['match_mode'] ?? 'strict') === 'strict')));
        $broadLocalHits = count(array_filter($localCitations, static fn(array $citation): bool => (($citation['match_mode'] ?? 'strict') === 'broad')));

        foreach (array_slice($citations, 0, 5) as $citation) {
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
                'local_citation_count' => $localCount,
                'pubmed_total_hits' => $pubmedTotalCount,
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

    private function collectLocalCitations(array $graphResult, array $entities): array
    {
        $citations = tekg_agent_plugin_result_citations($graphResult);
        if ($citations !== []) {
            return $this->citationResolver->normalizeMany($citations, 'local_graph');
        }

        $rows = [];
        foreach ($entities as $entity) {
            $candidateGroups = $this->entityCandidateGroups($entity);
            $type = (string)($entity['type'] ?? 'TE');
            $sourceLabel = $type === 'Disease' ? 'Disease' : 'TE';
            $cypher = "MATCH (a:$sourceLabel)-[r]->(p:Paper)
                       WHERE replace(replace(replace(toLower(trim(coalesce(a.name,''))), '-', ''), '_', ''), ' ', '') = replace(replace(replace(toLower(trim(\$entity)), '-', ''), '_', ''), ' ', '')
                       RETURN coalesce(p.pmid,'') AS pmid, coalesce(p.name,'') AS title, '' AS year, '' AS journal";
            foreach ($candidateGroups as $mode => $candidates) {
                foreach ($candidates as $candidate) {
                    $candidateRows = $this->neo4j->run($cypher, ['entity' => $candidate]);
                    if ($candidateRows === []) {
                        continue;
                    }
                    foreach ($candidateRows as $candidateRow) {
                        $candidateRow['matched_alias'] = $candidate;
                        $candidateRow['match_mode'] = $mode;
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
            ];
        }

        return $this->citationResolver->normalizeMany($citations, 'local_graph');
    }

    private function buildPubMedTerms(string $question, array $analysis, array $localCitations, array $resolvedEntities = []): array
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
        if (($analysis['compare_mode'] ?? false) && count($diseaseEntities) >= 2 && $teEntities !== []) {
            return [
                $this->composeQueryTerm($teEntities[0], $diseaseEntities[0], $keywords, (string)($analysis['intent'] ?? 'relationship')),
                $this->composeQueryTerm($teEntities[0], $diseaseEntities[1], $keywords, (string)($analysis['intent'] ?? 'relationship')),
            ];
        }

        $term = $this->composeQueryTerm($teEntities[0] ?? [], $diseaseEntities[0] ?? [], $keywords, (string)($analysis['intent'] ?? 'relationship'));
        if ($term !== '') {
            return [$term];
        }
        return [trim($question)];
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
            $text = trim((string)($citation['title'] ?? '') . ' ' . (string)($citation['abstract_summary'] ?? ''));
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
        if (!preg_match('/^[A-Za-z0-9 _\\-\\/]+$/', $value)) {
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
            'comparison' => 'comparison',
            default => '',
        };
    }

    private function searchPubMed(string $term): array
    {
        $cachePath = rtrim((string)$this->config['pubmed_cache_dir'], '/\\') . '/' . md5($term) . '.json';
        if (is_file($cachePath) && filemtime($cachePath) >= time() - 86400) {
            $cached = json_decode((string)file_get_contents($cachePath), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $base = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/';
        $common = ['db' => 'pubmed', 'retmode' => 'json', 'tool' => (string)$this->config['pubmed_tool']];
        if (($this->config['pubmed_email'] ?? '') !== '') {
            $common['email'] = (string)$this->config['pubmed_email'];
        }

        $search = $this->httpJson($base . 'esearch.fcgi?' . http_build_query($common + ['term' => $term, 'retmax' => 8, 'sort' => 'relevance']));
        $totalCount = (int)($search['esearchresult']['count'] ?? 0);
        $ids = array_values(array_filter((array)($search['esearchresult']['idlist'] ?? []), static fn($id): bool => trim((string)$id) !== ''));
        if ($ids === []) {
            $result = ['term' => $term, 'total_count' => $totalCount, 'citations' => []];
            file_put_contents($cachePath, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return $result;
        }

        $summary = $this->httpJson($base . 'esummary.fcgi?' . http_build_query($common + ['id' => implode(',', $ids)]));
        $abstracts = $this->fetchPubMedAbstracts($ids, $common);
        $citations = [];
        foreach ($ids as $pmid) {
            $doc = $summary['result'][$pmid] ?? [];
            if (!is_array($doc)) {
                continue;
            }
            $citations[] = [
                'source' => 'pubmed',
                'pmid' => (string)$pmid,
                'title' => trim((string)($doc['title'] ?? '')),
                'year' => trim((string)($doc['pubdate'] ?? '')),
                'journal' => trim((string)($doc['fulljournalname'] ?? '')),
                'url' => 'https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode((string)$pmid) . '/',
                'relevance' => 'PubMed external search',
                'abstract_summary' => $this->summarizeAbstract($abstracts[$pmid] ?? ''),
                'query_term' => $term,
            ];
        }

        $result = [
            'term' => $term,
            'total_count' => $totalCount,
            'citations' => $citations,
        ];
        file_put_contents($cachePath, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $result;
    }

    private function fetchPubMedAbstracts(array $ids, array $common): array
    {
        $base = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/';
        $xml = $this->httpText($base . 'efetch.fcgi?' . http_build_query($common + ['id' => implode(',', $ids), 'rettype' => 'abstract', 'retmode' => 'xml']));
        $parsed = @simplexml_load_string($xml);
        if ($parsed === false) {
            return [];
        }

        $abstracts = [];
        foreach ($parsed->PubmedArticle as $article) {
            $pmid = trim((string)($article->MedlineCitation->PMID ?? ''));
            $pieces = [];
            foreach ($article->MedlineCitation->Article->Abstract->AbstractText ?? [] as $fragment) {
                $pieces[] = trim((string)$fragment);
            }
            if ($pmid !== '') {
                $abstracts[$pmid] = trim(implode(' ', array_filter($pieces)));
            }
        }
        return $abstracts;
    }

    private function summarizeAbstract(string $abstract): string
    {
        $abstract = trim(preg_replace('/\s+/u', ' ', $abstract) ?? $abstract);
        if ($abstract === '') {
            return '';
        }
        return tekg_agent_strlen($abstract) > 280 ? tekg_agent_substr($abstract, 0, 277) . '...' : $abstract;
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
            return 'I combined local graph literature with a domain-qualified PubMed search. PubMed reported ' . $pubmedTotalCount . ' matches; the plugin inspected ' . $pubmedRetrievedCount . ' top records, retained ' . $pubmedRetainedCount . ' after entity-scope filtering, and carried ' . $reviewedCount . ' deduplicated citations into the answer.';
        }
        return 'This round mainly relied on local graph literature evidence and assembled ' . $localCount . ' directly citable records.';
    }

    private function buildResultMessage(array $citations, int $pubmedTotalCount): string
    {
        if ($citations === []) {
            return 'This round did not yield stable literature evidence, so I will need stronger local context or more specific search terms.';
        }
        return $pubmedTotalCount > 0
            ? 'These papers mainly cover mechanism, cancer relevance, and disease evidence, which is enough to support the next synthesis step.'
            : 'The current local literature is already strong enough to support a first evidence-backed answer.';
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
        $response = tekg_agent_http_request($url, 'GET', ['Accept: application/json, text/xml'], null, 45, (bool)($this->config['ssl_verify'] ?? false));
        if ((int)$response['status'] >= 400) {
            throw new RuntimeException('PubMed returned HTTP ' . (int)$response['status']);
        }
        return (string)$response['body'];
    }

    private function entityCandidateGroups(array $entity): array
    {
        return tekg_agent_entity_candidate_groups($entity);
    }
}
