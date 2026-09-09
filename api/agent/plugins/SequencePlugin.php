<?php
declare(strict_types=1);

final class TekgAgentSequencePlugin implements TekgAgentPluginInterface
{
    private ?array $dataset = null;

    public function getName(): string
    {
        return 'Sequence Plugin';
    }

    public function run(array $context): array
    {
        $started = microtime(true);
        $question = trim((string)($context['question'] ?? ''));
        $chains = tekg_agent_context_alias_chains($context);
        $dataset = $this->loadDataset();
        $showFullSequence = $this->wantsFullSequence($question);

        $matches = [];
        foreach ($chains as $chain) {
            if (($chain['type'] ?? '') !== 'TE') {
                continue;
            }
            $match = $this->resolveEntry($chain, $dataset);
            if ($match !== null) {
                $matches[] = $match;
            }
        }

        $matches = array_values(array_slice($matches, 0, 3));
        $previewItems = [];
        $evidenceItems = [];
        $citations = [];

        foreach ($matches as $match) {
            $entry = $match['entry'];
            $name = (string)($entry['name'] ?? $match['repbase_name'] ?? '');
            $length = $this->extractLength($entry);
            $headline = trim((string)($entry['sequence_summary']['headline'] ?? ''));
            $keywords = array_values(array_slice(is_array($entry['keywords'] ?? null) ? $entry['keywords'] : [], 0, 6));
            $structureHints = $this->structureHints($entry);
            $sequence = preg_replace('/\s+/u', '', (string)($entry['sequence'] ?? '')) ?? '';
            $sequencePreview = $this->sequencePreview((string)($entry['sequence'] ?? ''));

            $previewItems[] = [
                'title' => $name,
                'meta' => trim(implode(' | ', array_filter([
                    $match['entity_label'] !== $name ? 'matched from ' . $match['entity_label'] : '',
                    ($match['alias_mode'] ?? 'strict') === 'broad' ? 'broad alias match' : 'strict alias match',
                    $length !== null ? $length . ' bp' : '',
                    $headline,
                ]))),
                'body' => trim(implode(' | ', array_filter([
                    $structureHints !== '' ? 'Structure: ' . $structureHints : '',
                    $keywords !== [] ? 'Keywords: ' . implode(', ', array_map('strval', $keywords)) : '',
                    $showFullSequence && $sequence !== '' ? 'Sequence: ' . $sequence : ($sequencePreview !== '' ? 'Sequence: ' . $sequencePreview : ''),
                ]))),
            ];

            $evidenceItems[] = tekg_agent_make_evidence_item(
                $this->getName(),
                $name . ' maps to a Repbase-backed sequence record' .
                ($length !== null ? ' with a consensus length of ' . $length . ' bp' : '') . '.',
                $name,
                (($match['alias_mode'] ?? 'strict') === 'strict') ? 'high' : 'medium',
                [
                    'matched_alias' => (string)($match['matched_alias'] ?? ''),
                    'alias_mode' => (string)($match['alias_mode'] ?? 'strict'),
                    'repbase_name' => (string)($match['repbase_name'] ?? ''),
                    'length' => $length,
                ],
                [
                    'title' => $name,
                    'meta' => trim(implode(' | ', array_filter([
                        $length !== null ? $length . ' bp' : '',
                        $headline,
                    ]))),
                    'body' => trim(implode(' | ', array_filter([
                        $showFullSequence && $sequence !== '' ? 'Sequence: ' . $sequence : ($sequencePreview !== '' ? 'Sequence: ' . $sequencePreview : ''),
                    ]))),
                ],
                [
                    'evidence_type' => 'sequence_record',
                    'coverage_dimension' => 'sequence',
                    'subject' => $name,
                    'provenance' => ['source' => 'repbase_aligned_library'],
                ]
            );

            if ($structureHints !== '') {
                $evidenceItems[] = tekg_agent_make_evidence_item(
                    $this->getName(),
                    $name . ' record metadata contains keyword-derived structure hints: ' . $structureHints . '.',
                    $name,
                    'low',
                    [
                        'matched_alias' => (string)($match['matched_alias'] ?? ''),
                        'keywords' => $keywords,
                        'structure_hints' => $structureHints,
                    ],
                    [
                        'title' => $name . ' structure hints',
                        'meta' => 'keyword-derived',
                        'body' => 'Structure: ' . $structureHints,
                    ],
                    [
                        'evidence_type' => 'structure_hint',
                        'coverage_dimension' => 'sequence_structure',
                        'subject' => $name,
                        'provenance' => ['source' => 'repbase_record_metadata'],
                        'quality_flags' => ['keyword_derived'],
                    ]
                );
            }

            foreach ((array)($entry['references'] ?? []) as $reference) {
                if (!is_array($reference)) {
                    continue;
                }
                $citations[] = [
                    'source' => 'repbase',
                    'title' => trim((string)($reference['title'] ?? '')),
                    'journal' => trim((string)($reference['journal'] ?? '')),
                    'authors' => trim((string)($reference['authors'] ?? '')),
                    'year' => $this->extractYear((string)($reference['journal'] ?? '')),
                    'relevance' => 'Repbase sequence record reference',
                ];
            }
        }

        $summary = $matches !== []
            ? 'I matched ' . count($matches) . ' TE sequence records from the Repbase-aligned library and extracted consensus length, sequence, and structure hints.'
            : 'No Repbase-backed sequence record could be matched for the recognized TE entities in this round.';

        return [
            'plugin_name' => $this->getName(),
            'status' => $matches !== [] ? 'ok' : 'empty',
            'query_summary' => 'Matched recognized TE entities against the Repbase-backed sequence library.',
            'results' => [
                'matched_records' => $matches,
            ],
            'display_label' => 'Resolved ' . count($matches) . ' sequence records',
            'display_summary' => $summary,
            'display_details' => [
                'summary' => $summary,
                'preview_items' => $previewItems,
                'full_sequences' => array_values(array_map(static function (array $match): array {
                    $entry = (array)($match['entry'] ?? []);
                    $sequence = preg_replace('/\s+/u', '', (string)($entry['sequence'] ?? '')) ?? '';
                    return [
                        'title' => (string)($entry['name'] ?? $match['repbase_name'] ?? ''),
                        'length' => isset($entry['length']) ? (int)$entry['length'] : null,
                        'sequence' => $sequence,
                    ];
                }, $matches)),
                'evidence_items' => $evidenceItems,
                'citations' => $citations,
                'raw_preview' => ['matched_records' => $matches],
                'result_message' => $matches !== []
                    ? 'These sequence-backed records add consensus length, annotation, and structure hints that can stabilize TE-specific answers.'
                    : 'This round did not find a stable Repbase-backed sequence entry for the current TE entities.',
            ],
            'result_counts' => [
                'matched_records' => count($matches),
                'strict_matches' => count(array_filter($matches, static fn(array $match): bool => (($match['alias_mode'] ?? 'strict') === 'strict'))),
                'broad_matches' => count(array_filter($matches, static fn(array $match): bool => (($match['alias_mode'] ?? 'strict') === 'broad'))),
            ],
            'evidence_items' => $evidenceItems,
            'citations' => $citations,
            'errors' => [],
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    private function loadDataset(): array
    {
        if (is_array($this->dataset)) {
            return $this->dataset;
        }

        $path = TEKG_DATA_FS_DIR . '/processed/te_repbase_db_matched.json';
        $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        $decoded = is_array($decoded) ? $decoded : [];

        $entriesByName = [];
        foreach ((array)($decoded['entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string)($entry['name'] ?? $entry['id'] ?? ''));
            if ($name === '') {
                continue;
            }
            $entriesByName[$name] = $entry;
        }

        $this->dataset = [
            'db_to_repbase' => is_array($decoded['db_to_repbase'] ?? null) ? $decoded['db_to_repbase'] : [],
            'entries_by_name' => $entriesByName,
        ];
        return $this->dataset;
    }

    private function resolveEntry(array $chain, array $dataset): ?array
    {
        $canonical = trim((string)($chain['canonical_label'] ?? $chain['label'] ?? ''));

        $dbToRepbase = is_array($dataset['db_to_repbase'] ?? null) ? $dataset['db_to_repbase'] : [];
        $entriesByName = is_array($dataset['entries_by_name'] ?? null) ? $dataset['entries_by_name'] : [];

        foreach (tekg_agent_entity_candidate_groups($chain) as $mode => $candidates) {
            foreach ($candidates as $candidate) {
                $repbaseName = trim((string)($dbToRepbase[$candidate] ?? ''));
                if ($repbaseName !== '' && isset($entriesByName[$repbaseName])) {
                    $enriched = $this->enrichEntry($entriesByName[$repbaseName]);
                    return [
                        'entity_label' => $canonical !== '' ? $canonical : $candidate,
                        'matched_alias' => $candidate,
                        'alias_mode' => $mode,
                        'repbase_name' => $repbaseName,
                        'length' => $enriched['length'] ?? null,
                        'keywords' => $enriched['keywords'] ?? [],
                        'structure_hints' => $enriched['structure_hints'] ?? [],
                        'sequence_preview' => $enriched['sequence_preview'] ?? '',
                        'entry' => $enriched,
                    ];
                }
                if (isset($entriesByName[$candidate])) {
                    $enriched = $this->enrichEntry($entriesByName[$candidate]);
                    return [
                        'entity_label' => $canonical !== '' ? $canonical : $candidate,
                        'matched_alias' => $candidate,
                        'alias_mode' => $mode,
                        'repbase_name' => $candidate,
                        'length' => $enriched['length'] ?? null,
                        'keywords' => $enriched['keywords'] ?? [],
                        'structure_hints' => $enriched['structure_hints'] ?? [],
                        'sequence_preview' => $enriched['sequence_preview'] ?? '',
                        'entry' => $enriched,
                    ];
                }
                $normalizedCandidate = tekg_agent_normalize_lookup_token($candidate);
                foreach ($entriesByName as $name => $entry) {
                    if (tekg_agent_normalize_lookup_token((string)$name) === $normalizedCandidate) {
                        $enriched = $this->enrichEntry($entry);
                        return [
                            'entity_label' => $canonical !== '' ? $canonical : $candidate,
                            'matched_alias' => $candidate,
                            'alias_mode' => $mode,
                            'repbase_name' => $name,
                            'length' => $enriched['length'] ?? null,
                            'keywords' => $enriched['keywords'] ?? [],
                            'structure_hints' => $enriched['structure_hints'] ?? [],
                            'sequence_preview' => $enriched['sequence_preview'] ?? '',
                            'entry' => $enriched,
                        ];
                    }
                }
            }
        }

        return null;
    }

    private function enrichEntry(array $entry): array
    {
        $length = $this->extractLength($entry);
        $keywords = array_values(array_slice(is_array($entry['keywords'] ?? null) ? $entry['keywords'] : [], 0, 8));
        $structureHints = $this->structureHints($entry);
        $sequencePreview = $this->sequencePreview((string)($entry['sequence'] ?? ''));
        $entry['length'] = $length;
        $entry['keywords'] = $keywords;
        $entry['structure_hints'] = $structureHints !== '' ? array_values(array_filter(array_map('trim', explode(',', $structureHints)))) : [];
        $entry['sequence_preview'] = $sequencePreview;
        return $entry;
    }

    private function extractLength(array $entry): ?int
    {
        $summary = is_array($entry['sequence_summary'] ?? null) ? $entry['sequence_summary'] : [];
        if (isset($summary['headline']) && preg_match('/(\d+)\s*BP/i', (string)$summary['headline'], $matches) === 1) {
            return (int)$matches[1];
        }
        $sequence = preg_replace('/\s+/u', '', (string)($entry['sequence'] ?? '')) ?? '';
        return $sequence !== '' ? strlen($sequence) : null;
    }

    private function structureHints(array $entry): string
    {
        $text = tekg_agent_lower(trim(implode(' ', array_filter([
            (string)($entry['description'] ?? ''),
            implode(' ', array_map('strval', is_array($entry['keywords'] ?? null) ? $entry['keywords'] : [])),
        ]))));
        $hints = [];
        foreach (['ltr', 'orf1', 'orf2', 'env', 'gag', 'pol', 'sine', 'line', 'dna transposon', 'retrovirus', 'vntr'] as $keyword) {
            if (str_contains($text, $keyword)) {
                $hints[] = strtoupper($keyword);
            }
        }
        return implode(', ', array_values(array_unique($hints)));
    }

    private function sequencePreview(string $sequence): string
    {
        $compact = preg_replace('/\s+/u', '', trim($sequence)) ?? '';
        if ($compact === '') {
            return '';
        }
        return tekg_agent_strlen($compact) > 80 ? tekg_agent_substr($compact, 0, 80) . '...' : $compact;
    }

    private function extractYear(string $journal): string
    {
        return preg_match('/\b(19|20)\d{2}\b/', $journal, $matches) === 1 ? $matches[0] : '';
    }

    private function wantsFullSequence(string $question): bool
    {
        $normalized = tekg_agent_lower($question);
        foreach ([
            '完整序列',
            '完整的序列',
            'full sequence',
            'complete sequence',
            'entire sequence',
            'full-length sequence',
        ] as $needle) {
            if (str_contains($normalized, tekg_agent_lower($needle))) {
                return true;
            }
        }
        return false;
    }
}
