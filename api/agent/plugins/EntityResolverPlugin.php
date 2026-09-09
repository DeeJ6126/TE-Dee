<?php
declare(strict_types=1);

final class TekgAgentEntityResolverPlugin implements TekgAgentPluginInterface
{
    public function getName(): string
    {
        return 'Entity Resolver';
    }

    public function run(array $context): array
    {
        $started = microtime(true);
        $analysis = is_array($context['analysis'] ?? null) ? $context['analysis'] : [];
        $chains = is_array($analysis['alias_chains'] ?? null) ? $analysis['alias_chains'] : [];

        $previewItems = [];
        $evidenceItems = [];
        $resolvedEntities = [];
        $aliasCount = 0;

        foreach ($chains as $chain) {
            if (!is_array($chain)) {
                continue;
            }
            $canonical = trim((string)($chain['canonical_label'] ?? $chain['label'] ?? ''));
            $type = trim((string)($chain['type'] ?? ''));
            $aliases = array_values(array_unique(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                is_array($chain['aliases'] ?? null) ? $chain['aliases'] : []
            ))));
            $matchedAlias = trim((string)($chain['matched_alias'] ?? ''));
            $broadAliases = array_values(array_unique(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                is_array($chain['broad_aliases'] ?? null) ? $chain['broad_aliases'] : []
            ))));
            $confidence = (float)($chain['confidence'] ?? 0.0);
            $usedBroadAlias = (bool)($chain['used_broad_alias'] ?? false);
            $aliasCount += count($aliases);

            if ($canonical === '') {
                continue;
            }

            $resolvedEntities[] = [
                'label' => $canonical,
                'canonical_label' => $canonical,
                'type' => $type,
                'matched_alias' => $matchedAlias,
                'aliases' => $aliases,
                'broad_aliases' => $broadAliases,
                'confidence' => $confidence,
                'used_broad_alias' => $usedBroadAlias,
            ];

            $claim = $matchedAlias !== '' && tekg_agent_lower($matchedAlias) !== tekg_agent_lower($canonical)
                ? 'Resolved "' . $matchedAlias . '" to the canonical ' . $type . ' entity ' . $canonical . '.'
                : 'Resolved the ' . $type . ' entity ' . $canonical . ' with ' . count($aliases) . ' strict alias variants.';
            $evidenceItems[] = tekg_agent_make_diagnostic_item(
                $this->getName(),
                $claim,
                [
                    'match_confidence' => $confidence,
                    'match_type' => $usedBroadAlias ? 'broad_alias' : 'strict_alias',
                    'used_broad_alias' => $usedBroadAlias,
                ],
                [
                    'title' => $canonical,
                    'meta' => trim($type . ($matchedAlias !== '' ? ' | matched via ' . $matchedAlias : '') . ' | confidence ' . number_format($confidence, 2)),
                    'body' => $aliases !== [] ? 'Strict aliases: ' . implode(', ', $aliases) : '',
                ],
                [
                    'entity_scope' => $canonical,
                    'raw_source_ref' => [
                        'matched_alias' => $matchedAlias,
                        'used_broad_alias' => $usedBroadAlias,
                        'confidence' => $confidence,
                    ],
                    'evidence_type' => 'entity_resolution',
                    'coverage_dimension' => 'routing',
                    'subject' => $canonical,
                    'provenance' => ['source' => 'entity_normalizer'],
                ]
            );

            $previewItems[] = [
                'title' => $canonical,
                'meta' => trim($type . ($matchedAlias !== '' ? ' | matched via ' . $matchedAlias : '') . ' | confidence ' . number_format($confidence, 2)),
                'body' => trim(
                    ($aliases !== [] ? 'Strict aliases: ' . implode(', ', $aliases) : '') .
                    ($broadAliases !== [] ? ($aliases !== [] ? ' | ' : '') . 'Broad aliases: ' . implode(', ', $broadAliases) : '')
                ),
            ];
        }

        $resolvedCount = count($previewItems);
        $summary = $resolvedCount > 0
            ? 'I resolved ' . $resolvedCount . ' entities and prepared ' . $aliasCount . ' strict alias variants so the downstream plugins can retry stable names before considering broad aliases.'
            : 'No stable named entities were resolved from the question, so downstream plugins will have to rely on broader keyword context.';

        return [
            'plugin_name' => $this->getName(),
            'status' => $resolvedCount > 0 ? 'ok' : 'empty',
            'query_summary' => 'Resolved canonical entities and alias chains before the knowledge plugins ran.',
            'results' => [
                'alias_chains' => $chains,
                'resolved_entities' => $resolvedEntities,
            ],
            'display_label' => 'Resolved ' . $resolvedCount . ' entities',
            'display_summary' => $summary,
            'display_details' => [
                'summary' => $summary,
                'preview_items' => $previewItems,
                'evidence_items' => $evidenceItems,
                'citations' => [],
                'raw_preview' => ['alias_chains' => $chains],
                'result_message' => $resolvedCount > 0
                    ? 'These aliases will be reused by the graph, literature, genome, and sequence layers.'
                    : 'No stable entity aliases were available for reuse in this round.',
            ],
            'result_counts' => [
                'resolved_entities' => $resolvedCount,
                'alias_variants' => $aliasCount,
            ],
            'evidence_items' => $evidenceItems,
            'citations' => [],
            'errors' => [],
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }
}
