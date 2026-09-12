<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/variant_repository.php';

final class TekgAgentEqtlPlugin implements TekgAgentPluginInterface
{
    private const ROW_LIMIT = 10;
    private $loader;

    public function __construct(?callable $loader = null)
    {
        $this->loader = $loader ?? 'tekg_variant_load_top_eqtl_associations';
    }

    public function getName(): string
    {
        return 'eQTL Plugin';
    }

    public function run(array $context): array
    {
        $started = microtime(true);
        $entities = tekg_agent_context_resolved_entities($context, 'TE');
        $teNames = [];
        foreach ($entities as $entity) {
            $label = trim((string)($entity['canonical_label'] ?? $entity['label'] ?? ''));
            if ($label !== '') {
                $teNames[] = $label;
            }
        }
        $teNames = array_values(array_unique(array_slice($teNames, 0, 1)));

        $results = [];
        $evidence = [];
        $previewItems = [];
        $errors = [];
        if ($teNames === []) {
            $errors[] = 'No TE entity was resolved for the eQTL lookup.';
        }

        foreach ($teNames as $teName) {
            try {
                $bundle = ($this->loader)($teName, self::ROW_LIMIT);
                $rows = array_values((array)($bundle['rows'] ?? []));
                $scope = (array)($bundle['scope'] ?? []);
                $metadata = (array)($bundle['metadata'] ?? []);
                $available = (bool)($bundle['available'] ?? false);
                $resolvedName = trim((string)($scope['resolved_te_name'] ?? $teName));
                $total = (int)($bundle['total'] ?? 0);

                $results[] = [
                    'te_name' => $teName,
                    'resolved_te_name' => $resolvedName,
                    'scope' => (string)($scope['scope'] ?? ''),
                    'available' => $available,
                    'unavailable_reason' => (string)($bundle['unavailable_reason'] ?? ''),
                    'total_associations' => $total,
                    'representative_associations' => $rows,
                    'metadata' => $metadata,
                ];

                if (!$available || $rows === []) {
                    $reason = trim((string)($bundle['unavailable_reason'] ?? ''));
                    $evidence[] = tekg_agent_make_evidence_item(
                        $this->getName(),
                        $reason !== ''
                            ? 'The eQTL dataset is not available for the resolved TE scope.'
                            : 'The active eQTL dataset returned no associations for the resolved TE.',
                        $teName,
                        'none',
                        ['reason' => $reason, 'total_associations' => $total],
                        ['title' => $resolvedName, 'meta' => $reason !== '' ? $reason : 'No matching eQTL associations'],
                        [
                            'evidence_type' => $available ? 'empty_result' : 'unsupported_scope',
                            'coverage_dimension' => 'eqtl',
                            'subject' => $resolvedName,
                            'provenance' => ['source' => (string)($metadata['eqtl_version'] ?? TEKG_VARIANT_EQTL_VERSION)],
                            'diagnostic' => ['reason' => $reason !== '' ? $reason : 'no_associations'],
                            'quality_flags' => ['not_evidence'],
                        ]
                    );
                    continue;
                }

                foreach ($rows as $row) {
                    $variant = trim((string)($row['variant_id'] ?? ''));
                    $gene = trim((string)($row['gene_name'] ?? $row['gene_id'] ?? ''));
                    $tissue = trim((string)($row['tissue_name'] ?? $row['tissue_key'] ?? ''));
                    $pValue = $row['pval_nominal'] ?? null;
                    $slope = $row['slope'] ?? null;
                    $evidence[] = tekg_agent_make_evidence_item(
                        $this->getName(),
                        'GTEx reports an eQTL association between variant ' . $variant
                            . ' and expression of ' . $gene . ' in ' . $tissue
                            . '; the variant strictly overlaps a mapped ' . $resolvedName . ' instance.',
                        $resolvedName,
                        'medium',
                        $row,
                        [
                            'title' => $gene . ' in ' . $tissue,
                            'meta' => $variant . ' | p=' . (string)$pValue . ' | slope=' . (string)$slope,
                        ],
                        [
                            'evidence_type' => 'te_overlap_eqtl_association',
                            'coverage_dimension' => 'eqtl',
                            'subject' => $resolvedName,
                            'object' => $gene,
                            'provenance' => [
                                'source' => (string)($metadata['eqtl_version'] ?? TEKG_VARIANT_EQTL_VERSION),
                                'genome_build' => (string)($metadata['genome_build'] ?? ''),
                                'variant' => $variant,
                                'tissue' => $tissue,
                            ],
                            'quality_flags' => ['strict_te_variant_overlap', 'statistical_association'],
                        ]
                    );
                    $previewItems[] = [
                        'title' => $gene . ' in ' . $tissue,
                        'meta' => $variant . ' | p=' . (string)$pValue . ' | slope=' . (string)$slope,
                    ];
                }
            } catch (TeVariantRepositoryException $error) {
                $errors[] = $teName . ': ' . $error->getMessage();
            } catch (Throwable $error) {
                $errors[] = $teName . ': eQTL data could not be retrieved.';
            }
        }

        $usable = array_values(array_filter(
            $results,
            static fn(array $result): bool => (bool)($result['available'] ?? false)
                && (int)($result['total_associations'] ?? 0) > 0
        ));
        $totalAssociations = array_sum(array_map(
            static fn(array $result): int => (int)($result['total_associations'] ?? 0),
            $usable
        ));
        $status = tekg_agent_plugin_status($usable !== [], $errors);
        $displaySummary = $usable !== []
            ? 'I found ' . $totalAssociations . ' matching GTEx eQTL association records and selected the most significant records for inspection.'
            : ($errors !== []
                ? 'The eQTL lookup did not produce usable evidence because the TE could not be resolved or the data could not be retrieved.'
                : 'The active GTEx eQTL dataset returned no usable associations for the recognized TE scope.');

        foreach ($errors as $errorMessage) {
            $evidence[] = tekg_agent_make_diagnostic_item(
                $this->getName(),
                'eQTL lookup did not produce usable evidence because a system or entity-resolution error occurred.',
                ['error' => $errorMessage],
                [
                    'title' => 'eQTL lookup failed',
                    'meta' => 'system_error',
                    'body' => $errorMessage,
                ],
                [
                    'evidence_type' => 'system_error',
                    'coverage_dimension' => 'eqtl',
                    'quality_flags' => ['not_evidence'],
                ]
            );
        }

        return [
            'plugin_name' => $this->getName(),
            'status' => $status,
            'query_summary' => 'Retrieved GTEx v11 eQTL associations whose variants strictly overlap mapped TE instances and ranked representative records by nominal p value.',
            'results' => $results,
            'display_label' => 'Found ' . $totalAssociations . ' eQTL associations',
            'display_summary' => $displaySummary,
            'display_details' => [
                'summary' => $displaySummary,
                'preview_items' => array_slice($previewItems, 0, self::ROW_LIMIT),
                'evidence_items' => $evidence,
                'citations' => [],
                'raw_preview' => $results,
                'result_message' => 'Representative associations are ordered by nominal p value; the total count describes all matching association records.',
            ],
            'result_counts' => [
                'te_scopes' => count($usable),
                'associations' => $totalAssociations,
                'representative_associations' => count($previewItems),
            ],
            'evidence_items' => $evidence,
            'citations' => [],
            'errors' => $errors,
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }
}
