<?php
declare(strict_types=1);

final class TekgAgentGenomePlugin implements TekgAgentPluginInterface
{
    public function getName(): string
    {
        return 'Genome Plugin';
    }

    public function run(array $context): array
    {
        $started = microtime(true);
        $entities = tekg_agent_context_resolved_entities($context, 'TE');

        $results = [];
        $evidence = [];
        $previewItems = [];
        foreach ($entities as $entity) {
            $label = (string)($entity['canonical_label'] ?? $entity['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $hitBundle = $this->loadHitBundle($label);
            if ($hitBundle === null) {
                continue;
            }
            $locus = $hitBundle['representative_locus'] ?? [];
            $chrom = (string)($locus['chrom'] ?? '');
            $start = (int)($locus['start'] ?? 0);
            $end = (int)($locus['end'] ?? 0);
            $jbrowseUrl = site_url_with_state(tekg_app_url('jbrowse.php'), null, null, [
                'te' => $label,
                'chr' => $chrom,
                'start' => $start,
                'end' => $end,
            ]);
            $results[] = [
                'te_name' => $label,
                'total_hits' => (int)($hitBundle['total_hits'] ?? 0),
                'representative_locus' => $locus,
                'sample_hits' => array_slice((array)($hitBundle['sample_hits'] ?? []), 0, 5),
                'jbrowse_url' => $jbrowseUrl,
            ];
            if ($chrom !== '' && $start > 0 && $end > 0) {
                $evidence[] = tekg_agent_make_evidence_item(
                    $this->getName(),
                    $label . ' has a representative genomic locus at ' . $chrom . ':' . $start . '-' . $end . ' with ' . (int)($hitBundle['total_hits'] ?? 0) . ' total hits.',
                    $label,
                    'medium',
                    [
                        'representative_locus' => $locus,
                        'total_hits' => (int)($hitBundle['total_hits'] ?? 0),
                        'jbrowse_url' => $jbrowseUrl,
                    ],
                    [
                        'title' => $label,
                        'meta' => $chrom . ':' . $start . '-' . $end,
                        'body' => 'Representative locus from JBrowse hit bundle.',
                    ],
                    [
                        'evidence_type' => 'genomic_locus',
                        'coverage_dimension' => 'genomic_location',
                        'subject' => $label,
                        'provenance' => ['source' => 'jbrowse_hit_bundle'],
                        'quality_flags' => ['representative_locus'],
                    ]
                );
                $previewItems[] = [
                    'title' => $label,
                    'meta' => $chrom . ':' . $start . '-' . $end,
                    'url' => $jbrowseUrl,
                ];
            }
        }

        $displaySummary = $results === []
            ? 'The genome lookup did not add extra context in this round.'
            : 'I located representative genomic loci and browser entry points that can be used as supporting genomic context.';

        return [
            'plugin_name' => $this->getName(),
            'status' => $results === [] ? 'empty' : 'ok',
            'query_summary' => 'Loaded representative genomic hits and browser loci for recognized TE entities.',
            'results' => $results,
            'display_label' => 'Resolved ' . count($results) . ' genomic loci',
            'display_summary' => $displaySummary,
            'display_details' => [
                'summary' => $displaySummary,
                'preview_items' => array_slice($previewItems, 0, 5),
                'evidence_items' => $evidence,
                'citations' => [],
                'raw_preview' => $results,
                'result_message' => 'These loci are most useful as genomic context or browser entry points.',
            ],
            'result_counts' => [
                'loci' => count($results),
            ],
            'evidence_items' => $evidence,
            'citations' => [],
            'errors' => [],
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    private function loadHitBundle(string $teName): ?array
    {
        $path = tekg_jbrowse_fs_path('repeats/te_hits/' . $teName . '.json');
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }
}
