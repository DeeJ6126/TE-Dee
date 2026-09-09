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
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
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
        $abstractSummary = trim((string)($citation['abstract_summary'] ?? $citation['summary'] ?? ''));
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
            'abstract_summary' => $abstractSummary,
            'query_term' => $queryTerm,
        ];
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
