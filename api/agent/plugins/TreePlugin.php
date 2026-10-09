<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/taxonomy_lib.php';

final class TekgAgentTreePlugin implements TekgAgentPluginInterface
{
    private ?array $taxonomyItems = null;
    private ?array $diseaseTopMap = null;

    public function getName(): string
    {
        return 'Tree Plugin';
    }

    public function run(array $context): array
    {
        $started = microtime(true);
        $analysis = tekg_agent_context_analysis($context);
        $entities = tekg_agent_context_resolved_entities($context);
        $teEntities = array_values(array_filter($entities, static fn(array $item): bool => ($item['type'] ?? '') === 'TE'));
        $diseaseEntities = array_values(array_filter($entities, static fn(array $item): bool => ($item['type'] ?? '') === 'Disease'));

        $results = [];
        $evidence = [];
        $previewItems = [];

        if (($analysis['asks_for_classification'] ?? false) && $teEntities === []) {
            foreach ($this->topTeClasses() as $name) {
                $results[] = ['kind' => 'te_top_class', 'label' => $name];
                $evidence[] = tekg_agent_make_evidence_item(
                    $this->getName(),
                    'Top-level TE class: ' . $name . '.',
                    $name,
                    'medium',
                    ['kind' => 'te_top_class', 'label' => $name],
                    ['title' => $name, 'meta' => 'top-level TE class'],
                    [
                        'evidence_type' => 'classification_context',
                        'coverage_dimension' => 'classification_context',
                        'subject' => $name,
                        'provenance' => ['source' => 'taxonomy_runtime'],
                    ]
                );
            }
        }

        foreach ($teEntities as $entity) {
            $classification = $this->teClassification((string)$entity['label']);
            if ($classification !== null) {
                $path = $classification['path'];
                $rankText = implode('; ', array_map(
                    static fn(string $rank, string $label): string => $rank . ': ' . $label,
                    array_keys($classification['ranks']),
                    array_values($classification['ranks'])
                ));
                $missingText = $classification['unrecorded_ranks'] === [] ? ''
                    : ' The taxonomy record does not provide ' . implode(', ', $classification['unrecorded_ranks']) . '; do not infer these ranks from path position or the leaf name.';
                $claim = (string)$entity['label'] . ' recorded taxonomy ranks: ' . $rankText . '.' . $missingText;
                $record = ['kind' => 'te_path', 'label' => (string)$entity['label']] + $classification;
                $results[] = $record;
                $evidence[] = tekg_agent_make_evidence_item(
                    $this->getName(),
                    $claim,
                    (string)$entity['label'],
                    'medium',
                    $record,
                    ['title' => (string)$entity['label'], 'meta' => $rankText, 'body' => $claim],
                    [
                        'evidence_type' => 'classification_context',
                        'coverage_dimension' => 'classification_context',
                        'subject' => (string)$entity['label'],
                        'object' => end($path) ?: null,
                        'provenance' => [
                            'source' => 'taxonomy_runtime',
                            'taxonomy_source' => $classification['taxonomy_source'],
                            'taxonomy_status' => $classification['taxonomy_status'],
                        ],
                        'quality_flags' => ['recorded_ranks_only'],
                    ]
                );
                $previewItems[] = [
                    'title' => (string)$entity['label'],
                    'meta' => $rankText,
                ];
            }
        }

        foreach ($diseaseEntities as $entity) {
            $topClass = $this->diseaseTopClass((string)$entity['label']);
            if ($topClass !== null) {
                $results[] = ['kind' => 'disease_top_class', 'label' => (string)$entity['label'], 'top_class' => $topClass];
                $evidence[] = tekg_agent_make_evidence_item(
                    $this->getName(),
                    (string)$entity['label'] . ' top disease class: ' . $topClass . '.',
                    (string)$entity['label'],
                    'medium',
                    ['kind' => 'disease_top_class', 'label' => (string)$entity['label'], 'top_class' => $topClass],
                    ['title' => (string)$entity['label'], 'meta' => $topClass],
                    [
                        'evidence_type' => 'classification_context',
                        'coverage_dimension' => 'classification_context',
                        'subject' => (string)$entity['label'],
                        'object' => $topClass,
                        'provenance' => ['source' => 'disease_top_class_map'],
                    ]
                );
                $previewItems[] = [
                    'title' => (string)$entity['label'],
                    'meta' => $topClass,
                ];
            }
        }

        $displaySummary = $results === []
            ? 'The tree lookup did not add extra context in this round.'
            : 'I resolved the classification context of the current entities. This helps with lineage background, although it is not the core evidence for the answer.';

        return [
            'plugin_name' => $this->getName(),
            'status' => $results === [] ? 'empty' : 'ok',
            'query_summary' => 'Resolved TE and disease classification tree context.',
            'results' => $results,
            'display_label' => 'Resolved ' . count($results) . ' classification paths',
            'display_summary' => $displaySummary,
            'display_details' => [
                'summary' => $displaySummary,
                'preview_items' => array_slice($previewItems, 0, 5),
                'evidence_items' => $evidence,
                'citations' => [],
                'raw_preview' => $results,
                'result_message' => 'These tree paths help locate the entity in its lineage, but mechanism questions still rely more on relation and literature evidence.',
            ],
            'result_counts' => [
                'paths' => count($results),
            ],
            'evidence_items' => $evidence,
            'citations' => [],
            'errors' => [],
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    private function topTeClasses(): array
    {
        $top = [];
        foreach ($this->loadTaxonomyItems() as $item) {
            $class = trim((string)($item['path']['class'] ?? ''));
            if ($class !== '') {
                $top[] = $class;
            }
        }
        return array_values(array_unique($top));
    }

    private function tePath(string $name): array
    {
        return $this->teClassification($name)['path'] ?? [];
    }

    private function teClassification(string $name): ?array
    {
        $item = tekg_taxonomy_find_item($name, $this->loadTaxonomyItems());
        if (!is_array($item)) {
            return null;
        }
        $ranks = [];
        foreach (['class', 'subclass', 'order', 'superfamily', 'family', 'subclade', 'subfamily'] as $rank) {
            $label = trim((string)($item['path'][$rank] ?? ''));
            if ($label !== '') {
                $ranks[$rank] = $label;
            }
        }
        return [
            'path' => array_values(array_filter(array_merge(['TE'], (array)($item['path_labels'] ?? [])))),
            'ranks' => $ranks,
            'unrecorded_ranks' => array_values(array_diff(['class', 'order', 'superfamily', 'family', 'subfamily'], array_keys($ranks))),
            'leaf_name' => (string)($item['name'] ?? $name),
            'leaf_rank' => null,
            'taxonomy_source' => (string)($item['taxonomy_source'] ?? 'none'),
            'taxonomy_status' => (string)($item['taxonomy_status'] ?? 'unresolved'),
        ];
    }

    private function diseaseTopClass(string $name): ?string
    {
        $map = $this->loadDiseaseTopMap();
        return $map[$name] ?? null;
    }

    private function loadTaxonomyItems(): array
    {
        if (is_array($this->taxonomyItems)) {
            return $this->taxonomyItems;
        }
        try {
            $this->taxonomyItems = tekg_taxonomy_fetch_items();
        } catch (Throwable) {
            $this->taxonomyItems = [];
        }
        return $this->taxonomyItems;
    }

    private function loadDiseaseTopMap(): array
    {
        if (is_array($this->diseaseTopMap)) {
            return $this->diseaseTopMap;
        }
        $path = TEKG_DATA_FS_DIR . '/processed/disease_top_class_map.json';
        $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
        $this->diseaseTopMap = is_array($decoded) ? $decoded : [];
        return $this->diseaseTopMap;
    }
}
