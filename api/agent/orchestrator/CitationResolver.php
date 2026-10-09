<?php
declare(strict_types=1);

final class TekgAgentCitationResolver
{
    public function normalizeMany(array $citations, string $defaultSource = ''): array
    {
        $normalized = [];
        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $item = $this->normalizeOne($citation, $defaultSource);
            if ($item !== null) {
                $normalized[] = $item;
            }
        }
        return $this->dedupe($normalized);
    }

    public function dedupe(array $citations): array
    {
        $seen = [];
        $unique = [];
        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $pmid = trim((string)($citation['pmid'] ?? ''));
            $title = tekg_agent_lower(trim((string)($citation['title'] ?? '')));
            $key = $pmid !== '' ? 'pmid:' . $pmid : ($title !== '' ? 'title:' . $title : '');
            if ($key === '') {
                continue;
            }
            if (isset($seen[$key])) {
                $index = $seen[$key];
                $unique[$index] = $this->mergeCitationRecords($unique[$index], $citation);
                continue;
            }
            $seen[$key] = count($unique);
            $unique[] = $citation;
        }
        return $unique;
    }

    public function merge(array ...$groups): array
    {
        $all = [];
        foreach ($groups as $group) {
            $all = array_merge($all, $group);
        }
        return $this->dedupe($all);
    }

    public function fromPmids(array $pmids, string $defaultSource = 'local_graph', array $titleMap = []): array
    {
        $citations = [];
        foreach ($pmids as $pmid) {
            $value = trim((string)$pmid);
            if ($value === '') {
                continue;
            }
            $citations[] = [
                'source' => $defaultSource,
                'pmid' => $value,
                'title' => trim((string)($titleMap[$value] ?? '')),
                'year' => '',
                'journal' => '',
                'url' => $this->pmidUrl($value),
                'relevance' => '',
                'abstract_summary' => '',
            ];
        }
        return $this->normalizeMany($citations, $defaultSource);
    }

    public function normalizeOne(array $citation, string $defaultSource = ''): ?array
    {
        $source = trim((string)($citation['source'] ?? $defaultSource));
        $pmid = trim((string)($citation['pmid'] ?? ''));
        $title = trim((string)($citation['title'] ?? ''));
        $year = trim((string)($citation['year'] ?? ''));
        $journal = trim((string)($citation['journal'] ?? ''));
        $relevance = trim((string)($citation['relevance'] ?? ''));
        $abstractText = trim((string)($citation['abstract_text'] ?? ''));
        $abstractSummary = trim((string)($citation['abstract_summary'] ?? $citation['summary'] ?? ''));
        if ($abstractText !== '') {
            $abstractSummary = $abstractText;
        }
        $queryTerm = trim((string)($citation['query_term'] ?? ''));
        $authors = trim((string)($citation['authors'] ?? ''));
        $url = trim((string)($citation['url'] ?? ''));

        if ($pmid === '' && $title === '') {
            return null;
        }
        if ($url === '' && $pmid !== '') {
            $url = $this->pmidUrl($pmid);
        }

        return [
            'source' => $source !== '' ? $source : 'local_graph',
            'pmid' => $pmid,
            'title' => $title,
            'year' => $year,
            'journal' => $journal,
            'authors' => $authors,
            'url' => $url,
            'relevance' => $relevance,
            'abstract_text' => $abstractText,
            'abstract_summary' => $abstractSummary,
            'material_level' => trim((string)($citation['material_level'] ?? '')),
            'abstract_status' => trim((string)($citation['abstract_status'] ?? '')),
            'query_term' => $queryTerm,
            'sources' => $this->stringList($citation['sources'] ?? [], [$source !== '' ? $source : 'local_graph']),
            'entity_scopes' => $this->stringList($citation['entity_scopes'] ?? []),
            'query_terms' => $this->stringList($citation['query_terms'] ?? [], [$queryTerm]),
            'query_scopes' => $this->queryScopes($citation['query_scopes'] ?? []),
        ];
    }

    /** Merge records whose identity was already matched by the caller. */
    public function mergeCitationRecords(array $first, array $incoming): array
    {
        $merged = $first;
        foreach ($incoming as $field => $value) {
            if (!array_key_exists($field, $merged) || $merged[$field] === '' || $merged[$field] === null || $merged[$field] === []) {
                $merged[$field] = $value;
            }
        }

        $firstText = trim((string)($first['abstract_text'] ?? ''));
        $incomingText = trim((string)($incoming['abstract_text'] ?? ''));
        $firstMaterial = $firstText !== '' ? $firstText : trim((string)($first['abstract_summary'] ?? $first['summary'] ?? ''));
        $incomingMaterial = $incomingText !== '' ? $incomingText : trim((string)($incoming['abstract_summary'] ?? $incoming['summary'] ?? ''));
        // A fetched abstract outranks a legacy summary; among abstracts, retain
        // the fuller supplied text. This only chooses material, not claim support.
        $preferIncoming = $incomingMaterial !== '' && (
            $firstMaterial === ''
            || ($incomingText !== '' && $firstText === '')
            || (($incomingText !== '') === ($firstText !== '') && strlen($incomingMaterial) > strlen($firstMaterial))
        );
        $preferred = $preferIncoming ? $incoming : $first;
        if ($firstMaterial !== '' || $incomingMaterial !== '') {
            $merged['abstract_text'] = trim((string)($preferred['abstract_text'] ?? ''));
            $merged['abstract_summary'] = $merged['abstract_text'] !== ''
                ? $merged['abstract_text']
                : trim((string)($preferred['abstract_summary'] ?? $preferred['summary'] ?? ''));
            foreach (['material_level', 'abstract_status'] as $field) {
                $merged[$field] = trim((string)($preferred[$field] ?? ''));
            }
            if (trim((string)($preferred['source'] ?? '')) !== '') {
                $merged['source'] = trim((string)$preferred['source']);
            }
        }

        $merged['sources'] = $this->stringList(
            $first['sources'] ?? [],
            [(string)($first['source'] ?? '')],
            $incoming['sources'] ?? [],
            [(string)($incoming['source'] ?? '')]
        );
        $merged['entity_scopes'] = $this->stringList($first['entity_scopes'] ?? [], $incoming['entity_scopes'] ?? []);
        $merged['query_terms'] = $this->stringList(
            $first['query_terms'] ?? [],
            [(string)($first['query_term'] ?? '')],
            $incoming['query_terms'] ?? [],
            [(string)($incoming['query_term'] ?? '')]
        );
        $merged['query_scopes'] = $this->queryScopes(array_merge(
            is_array($first['query_scopes'] ?? null) ? $first['query_scopes'] : [],
            is_array($incoming['query_scopes'] ?? null) ? $incoming['query_scopes'] : []
        ));
        return $merged;
    }

    private function stringList(mixed ...$groups): array
    {
        $values = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $value) {
                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }
                $value = trim((string)$value);
                if ($value !== '' && !in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }
        return $values;
    }

    private function queryScopes(mixed $scopes): array
    {
        $result = [];
        $seen = [];
        foreach (is_array($scopes) ? $scopes : [] as $scope) {
            if (!is_array($scope)) {
                continue;
            }
            $query = trim((string)($scope['query_term'] ?? ''));
            if ($query === '') {
                continue;
            }
            $entities = $this->stringList($scope['entity_scope'] ?? []);
            $keyEntities = $entities;
            sort($keyEntities, SORT_STRING);
            $key = json_encode([$query, $keyEntities], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = ['query_term' => $query, 'entity_scope' => $entities];
        }
        return $result;
    }

    public function summarize(array $citations): array
    {
        $pmidCount = 0;
        $titleOnlyCount = 0;
        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            if (trim((string)($citation['pmid'] ?? '')) !== '') {
                $pmidCount++;
            } else {
                $titleOnlyCount++;
            }
        }
        return [
            'total' => count($citations),
            'pmid' => $pmidCount,
            'title_only' => $titleOnlyCount,
        ];
    }

    private function pmidUrl(string $pmid): string
    {
        return 'https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode($pmid) . '/';
    }
}
