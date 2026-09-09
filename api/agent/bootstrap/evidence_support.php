<?php
declare(strict_types=1);

function tekg_agent_entity_candidate_groups(array $entity): array
{
    $strict = [];
    $broad = [];

    foreach ([
        (string)($entity['matched_alias'] ?? ''),
        (string)($entity['canonical_label'] ?? $entity['label'] ?? ''),
        (string)($entity['label'] ?? ''),
    ] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '') {
            $strict[] = $candidate;
        }
    }

    foreach ((array)($entity['aliases'] ?? []) as $alias) {
        $value = trim((string)$alias);
        if ($value !== '') {
            $strict[] = $value;
        }
    }

    foreach ((array)($entity['broad_aliases'] ?? []) as $alias) {
        $value = trim((string)$alias);
        if ($value !== '') {
            $broad[] = $value;
        }
    }

    $strict = array_values(array_unique($strict));
    $broad = array_values(array_filter(array_unique($broad), static fn(string $value): bool => !in_array($value, $strict, true)));

    return [
        'strict' => $strict,
        'broad' => $broad,
    ];
}

function tekg_agent_support_strength(string $level): string
{
    $value = strtolower(trim($level));
    return in_array($value, ['high', 'medium', 'low', 'none'], true) ? $value : 'medium';
}

function tekg_agent_plugin_status(bool $hasUsableData, array $errors = []): string
{
    if ($hasUsableData) {
        return $errors === [] ? 'ok' : 'partial';
    }
    return $errors === [] ? 'empty' : 'error';
}

function tekg_agent_make_evidence_item(
    string $sourcePlugin,
    string $claim,
    string $entityScope = '',
    string $supportStrength = 'medium',
    array $rawSourceRef = [],
    array $display = [],
    array $structured = []
): array {
    $title = trim((string)($display['title'] ?? ''));
    $meta = trim((string)($display['meta'] ?? ''));
    $body = trim((string)($display['body'] ?? ''));

    if ($title === '') {
        $title = $entityScope !== '' ? $entityScope : $sourcePlugin;
    }
    if ($body === '') {
        $body = $claim;
    }

    return [
        'source_plugin' => $sourcePlugin,
        'entity_scope' => trim($entityScope),
        'claim' => trim($claim),
        'support_strength' => tekg_agent_support_strength($supportStrength),
        'raw_source_ref' => $rawSourceRef,
        'title' => $title,
        'meta' => $meta,
        'body' => $body,
        'evidence_type' => tekg_agent_evidence_scalar($structured['evidence_type'] ?? 'claim', 'claim'),
        'coverage_dimension' => tekg_agent_evidence_scalar($structured['coverage_dimension'] ?? 'unknown', 'unknown'),
        'subject' => tekg_agent_evidence_nullable_scalar($structured['subject'] ?? null),
        'object' => tekg_agent_evidence_nullable_scalar($structured['object'] ?? null),
        'provenance' => tekg_agent_evidence_array($structured['provenance'] ?? []),
        'diagnostic' => tekg_agent_evidence_array($structured['diagnostic'] ?? []),
        'citations' => tekg_agent_evidence_array($structured['citations'] ?? []),
        'quality_flags' => tekg_agent_evidence_array($structured['quality_flags'] ?? []),
    ];
}

function tekg_agent_make_diagnostic_item(
    string $sourcePlugin,
    string $message,
    array $diagnostic = [],
    array $display = [],
    array $structured = []
): array {
    $qualityFlags = array_values(array_unique(array_merge(
        ['not_biological_claim'],
        array_map('strval', (array)($structured['quality_flags'] ?? []))
    )));

    return tekg_agent_make_evidence_item(
        $sourcePlugin,
        $message,
        (string)($structured['entity_scope'] ?? ''),
        'none',
        (array)($structured['raw_source_ref'] ?? []),
        $display,
        array_merge($structured, [
            'diagnostic' => $diagnostic,
            'quality_flags' => $qualityFlags,
        ])
    );
}

function tekg_agent_is_diagnostic_evidence(array $item): bool
{
    $flags = array_map('strval', (array)($item['quality_flags'] ?? []));
    if (array_intersect($flags, ['not_evidence', 'not_biological_claim']) !== []) {
        return true;
    }

    $type = trim((string)($item['evidence_type'] ?? ''));
    if (in_array($type, [
        'citation_normalization',
        'entity_resolution',
        'site_navigation',
        'literature_query',
        'literature_synthesis_status',
        'system_error',
        'empty_result',
    ], true)) {
        return true;
    }

    return (string)($item['support_strength'] ?? '') === 'none'
        && (($item['diagnostic'] ?? []) !== [] || ($item['provenance'] ?? []) !== []);
}

function tekg_agent_evidence_scalar(mixed $value, string $fallback): string
{
    $normalized = trim((string)$value);
    return $normalized !== '' ? $normalized : $fallback;
}

function tekg_agent_evidence_nullable_scalar(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $normalized = trim((string)$value);
    return $normalized !== '' ? $normalized : null;
}

function tekg_agent_evidence_array(mixed $value): array
{
    return is_array($value) ? tekg_agent_json_safe($value) : [];
}

function tekg_agent_normalize_evidence_item(mixed $item, string $defaultPlugin = 'Unknown'): ?array
{
    if (is_string($item)) {
        $value = trim($item);
        if ($value === '') {
            return null;
        }
        return tekg_agent_make_evidence_item(
            $defaultPlugin,
            $value,
            '',
            'medium',
            [],
            [],
            [
                'evidence_type' => 'legacy_text',
                'coverage_dimension' => 'unknown',
                'provenance' => ['legacy_string' => true],
            ]
        );
    }

    if (!is_array($item)) {
        return null;
    }

    if (isset($item['claim']) || isset($item['source_plugin'])) {
        return tekg_agent_make_evidence_item(
            (string)($item['source_plugin'] ?? $defaultPlugin),
            (string)($item['claim'] ?? $item['body'] ?? $item['title'] ?? ''),
            (string)($item['entity_scope'] ?? ''),
            (string)($item['support_strength'] ?? 'medium'),
            (array)($item['raw_source_ref'] ?? []),
            [
                'title' => (string)($item['title'] ?? ''),
                'meta' => (string)($item['meta'] ?? ''),
                'body' => (string)($item['body'] ?? ''),
            ],
            [
                'evidence_type' => $item['evidence_type'] ?? 'claim',
                'coverage_dimension' => $item['coverage_dimension'] ?? 'unknown',
                'subject' => $item['subject'] ?? null,
                'object' => $item['object'] ?? null,
                'provenance' => $item['provenance'] ?? [],
                'diagnostic' => $item['diagnostic'] ?? [],
                'citations' => $item['citations'] ?? [],
                'quality_flags' => $item['quality_flags'] ?? [],
            ]
        );
    }

    $title = trim((string)($item['title'] ?? $item['label'] ?? $item['name'] ?? ''));
    $body = trim((string)($item['body'] ?? $item['summary'] ?? $item['text'] ?? ''));
    if ($title === '' && $body === '') {
        return null;
    }

    return tekg_agent_make_evidence_item(
        $defaultPlugin,
        $body !== '' ? $body : $title,
        '',
        'medium',
        [],
        [
            'title' => $title,
            'meta' => (string)($item['meta'] ?? ''),
            'body' => $body,
        ],
        [
            'evidence_type' => $item['evidence_type'] ?? 'claim',
            'coverage_dimension' => $item['coverage_dimension'] ?? 'unknown',
            'subject' => $item['subject'] ?? null,
            'object' => $item['object'] ?? null,
            'provenance' => $item['provenance'] ?? [],
            'diagnostic' => $item['diagnostic'] ?? [],
            'citations' => $item['citations'] ?? [],
            'quality_flags' => $item['quality_flags'] ?? [],
        ]
    );
}

function tekg_agent_json_safe(mixed $value): mixed
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = tekg_agent_json_safe($item);
        }
        return $normalized;
    }

    if (is_object($value)) {
        return tekg_agent_json_safe(get_object_vars($value));
    }

    if (is_scalar($value) || $value === null) {
        return $value;
    }

    return (string)$value;
}

function tekg_agent_context_analysis(array $context): array
{
    return is_array($context['analysis'] ?? null) ? $context['analysis'] : [];
}

function tekg_agent_context_plugin_results(array $context): array
{
    return is_array($context['plugin_results'] ?? null) ? $context['plugin_results'] : [];
}

function tekg_agent_context_plugin_result(array $context, string $pluginName): array
{
    $results = tekg_agent_context_plugin_results($context);
    return is_array($results[$pluginName] ?? null) ? $results[$pluginName] : [];
}

function tekg_agent_context_resolved_entities(array $context, ?string $type = null): array
{
    $entities = [];
    $entityResolution = is_array($context['entity_resolution'] ?? null) ? $context['entity_resolution'] : [];
    if (is_array($entityResolution['resolved_entities'] ?? null)) {
        $entities = $entityResolution['resolved_entities'];
    }

    if ($entities === []) {
        $resolver = tekg_agent_context_plugin_result($context, 'Entity Resolver');
        $results = is_array($resolver['results'] ?? null) ? $resolver['results'] : [];
        if (is_array($results['resolved_entities'] ?? null)) {
            $entities = $results['resolved_entities'];
        } elseif (is_array($results['alias_chains'] ?? null)) {
            $entities = $results['alias_chains'];
        }
    }

    $analysis = tekg_agent_context_analysis($context);
    if ($entities === [] && is_array($analysis['normalized_entities'] ?? null)) {
        $entities = $analysis['normalized_entities'];
    }
    if ($entities === [] && is_array($analysis['alias_chains'] ?? null)) {
        $entities = $analysis['alias_chains'];
    }

    $normalized = [];
    foreach ($entities as $entity) {
        if (!is_array($entity)) {
            continue;
        }
        $entityType = trim((string)($entity['type'] ?? $entity['entity_type'] ?? ''));
        if ($type !== null && strcasecmp($entityType, $type) !== 0) {
            continue;
        }
        $label = trim((string)($entity['label'] ?? $entity['canonical_label'] ?? $entity['name'] ?? ''));
        $canonical = trim((string)($entity['canonical_label'] ?? $entity['label'] ?? $entity['name'] ?? ''));
        if ($label === '' && $canonical === '') {
            continue;
        }
        $entity['label'] = $label !== '' ? $label : $canonical;
        $entity['canonical_label'] = $canonical !== '' ? $canonical : $label;
        if ($entityType !== '') {
            $entity['type'] = $entityType;
        }
        $normalized[] = tekg_agent_json_safe($entity);
    }

    return $normalized;
}

function tekg_agent_context_alias_chains(array $context): array
{
    $resolver = tekg_agent_context_plugin_result($context, 'Entity Resolver');
    $results = is_array($resolver['results'] ?? null) ? $resolver['results'] : [];
    if (is_array($results['alias_chains'] ?? null) && $results['alias_chains'] !== []) {
        return array_values(array_filter($results['alias_chains'], 'is_array'));
    }

    $analysis = tekg_agent_context_analysis($context);
    if (is_array($analysis['alias_chains'] ?? null)) {
        return array_values(array_filter($analysis['alias_chains'], 'is_array'));
    }

    return tekg_agent_context_resolved_entities($context);
}

function tekg_agent_plugin_result_citations(array $result): array
{
    $citations = [];
    foreach ([
        $result['citations'] ?? [],
        $result['result_envelope']['citations'] ?? [],
        $result['results']['citations'] ?? [],
        $result['display_details']['citations'] ?? [],
    ] as $source) {
        foreach ((array)$source as $citation) {
            if (is_array($citation)) {
                $citations[] = $citation;
            }
        }
    }

    foreach ((array)($result['evidence_items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        foreach ((array)($item['citations'] ?? []) as $citation) {
            if (is_array($citation)) {
                $citations[] = $citation;
            }
        }
    }
    foreach ((array)($result['result_envelope']['evidence_items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        foreach ((array)($item['citations'] ?? []) as $citation) {
            if (is_array($citation)) {
                $citations[] = $citation;
            }
        }
    }
    foreach ((array)($result['display_details']['evidence_items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        foreach ((array)($item['citations'] ?? []) as $citation) {
            if (is_array($citation)) {
                $citations[] = $citation;
            }
        }
    }

    return tekg_agent_dedupe_citations($citations);
}

function tekg_agent_context_citations(array $context, array $excludePlugins = []): array
{
    $excluded = array_fill_keys(array_map('strval', $excludePlugins), true);
    $citations = [];
    foreach (tekg_agent_context_plugin_results($context) as $pluginName => $result) {
        if (isset($excluded[(string)$pluginName]) || !is_array($result)) {
            continue;
        }
        foreach (tekg_agent_plugin_result_citations($result) as $citation) {
            $citations[] = $citation;
        }
    }
    return tekg_agent_dedupe_citations($citations);
}

function tekg_agent_dedupe_citations(array $citations): array
{
    $seen = [];
    $deduped = [];
    foreach ($citations as $citation) {
        if (!is_array($citation)) {
            continue;
        }
        $key = tekg_agent_citation_key($citation);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = tekg_agent_json_safe($citation);
    }
    return $deduped;
}

function tekg_agent_citation_key(array $citation): string
{
    foreach (['pmid', 'doi', 'url', 'title'] as $field) {
        $value = strtolower(trim((string)($citation[$field] ?? '')));
        if ($value !== '') {
            return $field . ':' . $value;
        }
    }
    $encoded = json_encode(tekg_agent_json_safe($citation), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? 'json:' . $encoded : '';
}

function tekg_agent_node_contracts(): array
{
    return [
        'Question Understanding Node' => [
            'input' => ['question'],
            'output' => ['analysis', 'entity_resolution'],
        ],
        'Planning Node' => [
            'input' => ['question', 'analysis', 'entity_resolution', 'session_context'],
            'output' => ['planning'],
        ],
        'Evidence Collection Node' => [
            'input' => ['question', 'analysis', 'planning', 'graph_result', 'analytics_result', 'cypher_result', 'literature_result', 'literature_synthesis', 'tree_result', 'expression_result', 'genome_result', 'sequence_result', 'citation_result', 'collected_results', 'evidence_bundle', 'citation_bundle'],
            'output' => ['collection_state', 'active_expert', 'sufficiency_decision', 'graph_result', 'analytics_result', 'cypher_result', 'literature_result', 'literature_synthesis', 'tree_result', 'expression_result', 'genome_result', 'sequence_result', 'citation_result', 'collected_results', 'evidence_bundle', 'citation_bundle', 'compressed_result'],
        ],
        'Evidence Synthesis Node' => [
            'input' => ['question', 'analysis', 'planning', 'graph_result', 'analytics_result', 'cypher_result', 'literature_result', 'literature_synthesis', 'tree_result', 'expression_result', 'genome_result', 'sequence_result', 'citation_result', 'collected_results', 'evidence_bundle', 'citation_bundle', 'compressed_result'],
            'output' => ['supported_claims', 'conflicting_claims', 'missing_evidence', 'claim_clusters'],
        ],
        'Answer Structuring Node' => [
            'input' => ['question', 'analysis', 'planning', 'collected_results', 'compressed_result', 'graph_result', 'analytics_result', 'cypher_result', 'literature_result', 'literature_synthesis', 'tree_result', 'expression_result', 'genome_result', 'sequence_result', 'citation_result', 'supported_claims', 'conflicting_claims', 'missing_evidence', 'claim_clusters'],
            'output' => ['answer_structure'],
        ],
        'Answer Writer Node' => [
            'input' => ['question', 'analysis', 'answer_structure', 'evidence_package', 'evidence_walk', 'report_plan'],
            'output' => ['draft_report', 'polished_report', 'integrity_report', 'answer'],
        ],
        'Process Narrator Node' => [
            'input' => ['event_stream', 'analysis', 'entity_resolution', 'planning', 'collection_state', 'active_expert', 'sufficiency_decision', 'graph_result', 'analytics_result', 'cypher_result', 'literature_result', 'literature_synthesis', 'tree_result', 'expression_result', 'genome_result', 'sequence_result', 'citation_result', 'supported_claims', 'conflicting_claims', 'missing_evidence', 'claim_clusters', 'answer_structure', 'answer'],
            'output' => ['trace_event'],
        ],
    ];
}

interface TekgAgentPluginInterface
{
    public function getName(): string;
    public function run(array $context): array;
}
