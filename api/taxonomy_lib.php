<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/path_config.php';
require_once __DIR__ . '/runtime_config.php';

function tekg_taxonomy_config(): array
{
    return tekg_runtime_neo4j_config();
}

function tekg_taxonomy_database_name(array $config): string
{
    return tekg_runtime_neo4j_database_name($config);
}

function tekg_taxonomy_run_neo4j(array $config, string $statement, array $parameters = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required');
    }
    if (trim((string)($config['neo4j_password'] ?? '')) === '') {
        throw new RuntimeException('Neo4j password is not configured');
    }

    $payload = json_encode([
        'statements' => [[
            'statement' => $statement,
            'parameters' => $parameters === [] ? new stdClass() : $parameters,
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init((string)$config['neo4j_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => (string)$config['neo4j_user'] . ':' . (string)$config['neo4j_password'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Neo4j request failed: ' . $error);
    }
    if ($status >= 400) {
        throw new RuntimeException('Neo4j HTTP ' . $status);
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Neo4j response is not valid JSON');
    }
    if (!empty($decoded['errors'])) {
        $message = (string)($decoded['errors'][0]['message'] ?? 'Neo4j query failed');
        throw new RuntimeException($message);
    }

    $result = $decoded['results'][0] ?? null;
    if (!is_array($result)) {
        return [];
    }

    $columns = $result['columns'] ?? [];
    $rows = [];
    foreach (($result['data'] ?? []) as $entry) {
        $values = $entry['row'] ?? [];
        $row = [];
        foreach ($columns as $index => $column) {
            $row[(string)$column] = $values[$index] ?? null;
        }
        $rows[] = $row;
    }

    return $rows;
}

function tekg_taxonomy_parse_names(string $raw): array
{
    $names = [];
    foreach (preg_split('/[,;\n\r\t]+/', $raw) ?: [] as $part) {
        $name = trim((string)$part);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return array_values(array_unique($names));
}

function tekg_taxonomy_normalize_item(array $row): array
{
    $path = [
        'class' => $row['taxonomy_class'] ?? null,
        'subclass' => $row['taxonomy_subclass'] ?? null,
        'order' => $row['taxonomy_order'] ?? null,
        'superfamily' => $row['taxonomy_superfamily'] ?? null,
        'family' => $row['taxonomy_family'] ?? null,
        'subclade' => $row['taxonomy_subclade'] ?? null,
    ];
    $name = trim((string)($row['name'] ?? ''));
    $pathLabels = array_values(array_filter(
        array_map(static fn($value): string => trim((string)$value), array_values($path)),
        static fn(string $value): bool => $value !== ''
    ));
    if ($name !== '' && (empty($pathLabels) || end($pathLabels) !== $name)) {
        $pathLabels[] = $name;
    }

    return [
        'name' => $name,
        'taxonomy_group' => $row['taxonomy_group'] ?? null,
        'taxonomy_status' => $row['taxonomy_status'] ?? null,
        'taxonomy_source' => $row['taxonomy_source'] ?? null,
        'taxonomy_canonical_name' => $row['taxonomy_canonical_name'] ?? $name,
        'path' => $path,
        'path_labels' => $pathLabels,
        'display_path' => implode(' --- ', array_merge(['TE'], $pathLabels)),
        'is_leaf_standard' => (bool)($row['is_leaf_standard'] ?? false),
        'homepage_chart_included' => (bool)($row['homepage_chart_included'] ?? false),
    ];
}

function tekg_taxonomy_fetch_items(?array $names = null, ?array $config = null): array
{
    $config ??= tekg_taxonomy_config();
    $names = array_values(array_filter(array_map('strval', $names ?? []), static fn(string $name): bool => trim($name) !== ''));
    $rows = tekg_taxonomy_run_neo4j(
        $config,
        <<<'CYPHER'
MATCH (t:TE)
WHERE size($names) = 0 OR t.name IN $names OR t.taxonomy_canonical_name IN $names
RETURN t.name AS name,
       t.taxonomy_group AS taxonomy_group,
       t.taxonomy_status AS taxonomy_status,
       t.taxonomy_source AS taxonomy_source,
       t.taxonomy_canonical_name AS taxonomy_canonical_name,
       t.taxonomy_class AS taxonomy_class,
       t.taxonomy_subclass AS taxonomy_subclass,
       t.taxonomy_order AS taxonomy_order,
       t.taxonomy_superfamily AS taxonomy_superfamily,
       t.taxonomy_family AS taxonomy_family,
       t.taxonomy_subclade AS taxonomy_subclade,
       t.is_leaf_standard AS is_leaf_standard,
       t.homepage_chart_included AS homepage_chart_included
ORDER BY toLower(t.name)
CYPHER,
        ['names' => $names]
    );

    return array_map('tekg_taxonomy_normalize_item', $rows);
}

function tekg_taxonomy_index_items(array $items): array
{
    $index = [];
    foreach ($items as $item) {
        $name = trim((string)($item['name'] ?? ''));
        if ($name !== '') {
            $index[$name] = $item;
            $index[tekg_taxonomy_canonical_key($name)] = $item;
        }
        $canonicalName = trim((string)($item['taxonomy_canonical_name'] ?? ''));
        if ($canonicalName !== '') {
            $index[$canonicalName] = $item;
            $index[tekg_taxonomy_canonical_key($canonicalName)] = $item;
        }
    }
    return $index;
}

function tekg_taxonomy_canonical_key(string $value): string
{
    $value = trim(strip_tags($value));
    $value = preg_replace('/[\\s_\\-]+/', '', $value) ?? $value;
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function tekg_taxonomy_find_item(string $query, array $items): ?array
{
    $index = tekg_taxonomy_index_items($items);
    return $index[$query] ?? $index[tekg_taxonomy_canonical_key($query)] ?? null;
}

function tekg_taxonomy_tree_sources(): array
{
    return [
        'rmsk_repbase' => [
            'path' => tekg_taxonomy_fs_path('tree_rmsk_repbase.txt'),
            'label' => 'RMSK + Repbase TE taxonomy',
            'root' => 'Transposable Elements - Human',
            'description' => 'TE taxonomy tree parsed from data/taxonomy/transposon_tree/tree_rmsk_repbase.txt.',
        ],
        'all' => [
            'path' => tekg_taxonomy_fs_path('tree_all.txt'),
            'label' => 'All TE taxonomy',
            'root' => 'Transposable Elements (Mobile element) - Human',
            'skip_root' => 'Mobile genetic element',
            'description' => 'Full TE taxonomy tree parsed from data/taxonomy/transposon_tree/tree_all.txt.',
        ],
    ];
}

function tekg_taxonomy_normalize_tree_source(string $source): string
{
    $source = strtolower(trim($source));
    $aliases = [
        'repbase' => 'rmsk_repbase',
        'rmsk-repbase' => 'rmsk_repbase',
        'rmsk_repbase' => 'rmsk_repbase',
        'all' => 'all',
    ];
    return $aliases[$source] ?? $source;
}

function tekg_taxonomy_is_file_tree_source(string $source): bool
{
    return array_key_exists(tekg_taxonomy_normalize_tree_source($source), tekg_taxonomy_tree_sources());
}

function tekg_taxonomy_parse_tree_line(string $line): ?array
{
    $line = rtrim($line);
    if (trim($line) === '') {
        return null;
    }
    if (preg_match('/^[\s│]*$/u', $line)) {
        return null;
    }

    if (preg_match('/^(.*?)(?:├──|└──)\s*(.+)$/u', $line, $matches)) {
        $prefix = (string)$matches[1];
        $label = trim((string)$matches[2]);
        if ($label === '') {
            return null;
        }
        return [
            'label' => $label,
            'depth' => intdiv(function_exists('mb_strlen') ? mb_strlen($prefix, 'UTF-8') : strlen($prefix), 4) + 1,
        ];
    }

    $label = trim($line);
    if ($label === '' || preg_match('/^[│├└─\s]+$/u', $label)) {
        return null;
    }
    return [
        'label' => $label,
        'depth' => 0,
    ];
}

function tekg_taxonomy_file_tree_payload(string $source): array
{
    $sourceKey = tekg_taxonomy_normalize_tree_source($source);
    $sources = tekg_taxonomy_tree_sources();
    if (!isset($sources[$sourceKey])) {
        throw new InvalidArgumentException('Unsupported taxonomy tree source: ' . $source);
    }

    $path = (string)$sources[$sourceKey]['path'];
    if (!is_file($path)) {
        throw new RuntimeException('Taxonomy tree file is missing: ' . $path);
    }

    $nodes = [];
    $edges = [];
    $stack = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read taxonomy tree file: ' . $path);
    }

    foreach ($lines as $line) {
        $parsed = tekg_taxonomy_parse_tree_line((string)$line);
        if ($parsed === null) {
            continue;
        }
        $label = (string)$parsed['label'];
        $depth = (int)$parsed['depth'];
        $nodes[$label] ??= [
            'name' => $label,
            'original_label' => $label,
            'depth' => $depth,
            'description' => $label . ' taxonomy node parsed from ' . $sourceKey . '.',
        ];
        if ($depth < (int)($nodes[$label]['depth'] ?? $depth)) {
            $nodes[$label]['depth'] = $depth;
        }

        $stack[$depth] = $label;
        foreach (array_keys($stack) as $stackDepth) {
            if ((int)$stackDepth > $depth) {
                unset($stack[$stackDepth]);
            }
        }

        if ($depth > 0 && isset($stack[$depth - 1])) {
            $parent = (string)$stack[$depth - 1];
            $edgeKey = $label . "\0" . $parent;
            $edges[$edgeKey] = [
                'child' => $label,
                'parent' => $parent,
                'relation' => 'SUBFAMILY_OF',
            ];
        }
    }

    $skipRoot = trim((string)($sources[$sourceKey]['skip_root'] ?? ''));
    if ($skipRoot !== '' && isset($nodes[$skipRoot])) {
        unset($nodes[$skipRoot]);
        foreach ($edges as $edgeKey => $edge) {
            if ((string)($edge['parent'] ?? '') === $skipRoot || (string)($edge['child'] ?? '') === $skipRoot) {
                unset($edges[$edgeKey]);
            }
        }
    }

    $root = (string)($sources[$sourceKey]['root'] ?? 'TE');
    if (isset($nodes[$root])) {
        $rootDepth = (int)($nodes[$root]['depth'] ?? 0);
        foreach ($nodes as &$node) {
            $node['depth'] = max(0, (int)($node['depth'] ?? 0) - $rootDepth);
        }
        unset($node);
    }

    $nodeList = array_values($nodes);
    usort($nodeList, static function (array $left, array $right): int {
        $depthCompare = ((int)($left['depth'] ?? 0)) <=> ((int)($right['depth'] ?? 0));
        return $depthCompare !== 0 ? $depthCompare : strcasecmp((string)$left['name'], (string)$right['name']);
    });

    $edgeList = array_values($edges);
    usort($edgeList, static fn(array $left, array $right): int => strcasecmp((string)$left['parent'] . (string)$left['child'], (string)$right['parent'] . (string)$right['child']));

    return [
        'root' => $root,
        'root_label' => $root,
        'tree_source' => $sourceKey,
        'label' => (string)$sources[$sourceKey]['label'],
        'description' => (string)$sources[$sourceKey]['description'],
        'node_count' => count($nodeList),
        'edge_count' => count($edgeList),
        'nodes' => $nodeList,
        'edges' => $edgeList,
    ];
}

function tekg_taxonomy_loader_kinds(array $names, string $source = 'rmsk_repbase'): array
{
    $tree = tekg_taxonomy_file_tree_payload($source);
    $root = (string)($tree['root'] ?? 'Transposable Elements - Human');
    $labels = [];
    foreach ((array)($tree['nodes'] ?? []) as $node) {
        $label = trim((string)($node['name'] ?? ''));
        if ($label !== '') {
            $labels[strtolower($label)] = $label;
        }
    }

    $parents = [];
    foreach ((array)($tree['edges'] ?? []) as $edge) {
        $child = trim((string)($edge['child'] ?? ''));
        $parent = trim((string)($edge['parent'] ?? ''));
        if ($child !== '' && $parent !== '') {
            $parents[strtolower($child)] = $parent;
        }
    }

    $topLevelFor = static function (string $label) use ($parents, $root): string {
        $current = $label;
        $seen = [];
        while ($current !== '') {
            $key = strtolower($current);
            if (isset($seen[$key])) {
                return '';
            }
            $seen[$key] = true;
            $parent = (string)($parents[$key] ?? '');
            if ($parent === '') {
                return '';
            }
            if (strcasecmp($parent, $root) === 0) {
                return $current;
            }
            $current = $parent;
        }
        return '';
    };

    $kindForTopLevel = static function (string $topLevel): string {
        if (stripos($topLevel, 'Class I:') === 0) {
            return 'retro';
        }
        if (stripos($topLevel, 'Class II:') === 0) {
            return 'dna';
        }
        return 'default';
    };

    $items = [];
    foreach ($names as $rawName) {
        $name = trim((string)$rawName);
        if ($name === '') {
            continue;
        }
        $nameKey = strtolower($name);
        $matchedLabels = [];
        foreach ([$name, 'Family: ' . $name, 'Superfamily: ' . $name] as $candidate) {
            $candidateKey = strtolower($candidate);
            if (isset($labels[$candidateKey])) {
                $matchedLabels[$candidateKey] = $labels[$candidateKey];
            }
        }
        if ($matchedLabels === []) {
            foreach ($labels as $labelKey => $label) {
                $plain = preg_replace('/^(?:Class I|Class II|Subclass|Order|Superfamily|Family|Subclade):\s*/i', '', $label) ?? $label;
                if (str_starts_with(strtolower($plain), $nameKey)) {
                    $matchedLabels[$labelKey] = $label;
                }
            }
        }

        $topLevels = [];
        foreach ($matchedLabels as $matchedLabel) {
            $topLevel = $topLevelFor($matchedLabel);
            if ($topLevel !== '') {
                $topLevels[strtolower($topLevel)] = $topLevel;
            }
        }
        $kinds = [];
        foreach ($topLevels as $topLevel) {
            $kind = $kindForTopLevel($topLevel);
            $kinds[$kind] = true;
        }
        $resolvedKind = count($kinds) === 1 ? (string)array_key_first($kinds) : 'default';
        $resolvedTopLevel = count($topLevels) === 1 ? (string)array_values($topLevels)[0] : null;
        $items[] = [
            'name' => $name,
            'kind' => $resolvedKind,
            'taxonomy_found' => $matchedLabels !== [] && $topLevels !== [],
            'top_level' => $resolvedTopLevel,
            'source' => (string)($tree['tree_source'] ?? $source),
        ];
    }
    return $items;
}

function tekg_taxonomy_tree_payload(array $items): array
{
    $nodes = [
        'TE' => [
            'name' => 'TE',
            'original_label' => 'TE',
            'depth' => 0,
            'description' => 'TE root synthesized from Neo4j taxonomy properties.',
        ],
    ];
    $edges = [];

    foreach ($items as $item) {
        $labels = array_values(array_filter(
            array_map(static fn($value): string => trim((string)$value), (array)($item['path_labels'] ?? [])),
            static fn(string $value): bool => $value !== ''
        ));
        $parent = 'TE';
        foreach ($labels as $index => $label) {
            $depth = $index + 1;
            $nodes[$label] ??= [
                'name' => $label,
                'original_label' => $label,
                'depth' => $depth,
                'description' => $label . ' taxonomy node synthesized from Neo4j tekg3.',
            ];
            $edgeKey = $label . "\0" . $parent;
            $edges[$edgeKey] = [
                'child' => $label,
                'parent' => $parent,
                'relation' => 'SUBFAMILY_OF',
            ];
            $parent = $label;
        }
    }

    $nodeList = array_values($nodes);
    usort($nodeList, static function (array $left, array $right): int {
        $depthCompare = ((int)($left['depth'] ?? 0)) <=> ((int)($right['depth'] ?? 0));
        return $depthCompare !== 0 ? $depthCompare : strcasecmp((string)$left['name'], (string)$right['name']);
    });
    $edgeList = array_values($edges);
    usort($edgeList, static fn(array $left, array $right): int => strcasecmp((string)$left['parent'] . (string)$left['child'], (string)$right['parent'] . (string)$right['child']));

    return [
        'root' => 'TE',
        'root_label' => 'TE',
        'node_count' => count($nodeList),
        'edge_count' => count($edgeList),
        'nodes' => $nodeList,
        'edges' => $edgeList,
    ];
}

function tekg_taxonomy_summary_payload(array $items): array
{
    $summary = [
        'total_te_nodes' => count($items),
        'with_taxonomy_class' => 0,
        'homepage_chart_included' => 0,
        'is_leaf_standard' => 0,
        'taxonomy_groups' => [],
        'taxonomy_sources' => [],
    ];
    foreach ($items as $item) {
        if (trim((string)($item['path']['class'] ?? '')) !== '') {
            $summary['with_taxonomy_class']++;
        }
        if (!empty($item['homepage_chart_included'])) {
            $summary['homepage_chart_included']++;
        }
        if (!empty($item['is_leaf_standard'])) {
            $summary['is_leaf_standard']++;
        }
        $group = (string)($item['taxonomy_group'] ?? '');
        if ($group !== '') {
            $summary['taxonomy_groups'][$group] = ($summary['taxonomy_groups'][$group] ?? 0) + 1;
        }
        $source = (string)($item['taxonomy_source'] ?? '');
        if ($source !== '') {
            $summary['taxonomy_sources'][$source] = ($summary['taxonomy_sources'][$source] ?? 0) + 1;
        }
    }
    ksort($summary['taxonomy_groups']);
    ksort($summary['taxonomy_sources']);
    return $summary;
}

function tekg_taxonomy_slugify(string $value): string
{
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'segment';
}

function tekg_taxonomy_ring_color(int $index): string
{
    $colors = ['#4f86df', '#80acef', '#a7c8ff', '#d2e3ff', '#5d97f6', '#bfd8ff'];
    return $colors[$index % count($colors)];
}

function tekg_taxonomy_counter(array $values): array
{
    $counter = [];
    foreach ($values as $value) {
        $name = trim((string)$value);
        if ($name === '') {
            continue;
        }
        $counter[$name] = ($counter[$name] ?? 0) + 1;
    }
    return $counter;
}

function tekg_taxonomy_sort_counter(array $counter): array
{
    uksort($counter, static function (string $left, string $right) use ($counter): int {
        $countCompare = ((int)$counter[$right]) <=> ((int)$counter[$left]);
        return $countCompare !== 0 ? $countCompare : strcasecmp($left, $right);
    });
    return $counter;
}

function tekg_taxonomy_chart_view(string $label, array $counter, ?string $nextPrefix = null, array $nextOverrides = []): array
{
    $counter = tekg_taxonomy_sort_counter($counter);
    $segments = [];
    $index = 0;
    foreach ($counter as $name => $count) {
        $segment = [
            'key' => tekg_taxonomy_slugify((string)$name),
            'label' => (string)$name,
            'count' => (int)$count,
            'color' => tekg_taxonomy_ring_color($index),
            'description' => (string)$name,
        ];
        if (isset($nextOverrides[$name])) {
            $segment['nextView'] = $nextOverrides[$name];
        } elseif ($nextPrefix !== null) {
            $segment['nextView'] = $nextPrefix . tekg_taxonomy_slugify((string)$name);
        }
        $segments[] = $segment;
        $index++;
    }

    return [
        'count' => array_sum(array_map('intval', $counter)),
        'label' => $label,
        'segments' => $segments,
    ];
}

function tekg_taxonomy_path_value(array $item, string $rank, string $fallback = 'Unclassified'): string
{
    $value = trim((string)($item['path'][$rank] ?? ''));
    return $value !== '' ? $value : $fallback;
}

function tekg_taxonomy_retro_primary_bucket(array $item): string
{
    $order = trim((string)($item['path']['order'] ?? ''));
    if ($order !== '') {
        return $order;
    }
    $superfamily = strtoupper((string)($item['path']['superfamily'] ?? ''));
    if (str_contains($superfamily, 'SINE')) {
        return 'SINEs';
    }
    return 'Unclassified';
}

function tekg_taxonomy_dna_primary_bucket(array $item): string
{
    $subclass = trim((string)($item['path']['subclass'] ?? ''));
    if ($subclass !== '') {
        return $subclass;
    }
    $order = trim((string)($item['path']['order'] ?? ''));
    return $order !== '' ? $order : 'Unclassified';
}

function tekg_taxonomy_deep_bucket(array $item): string
{
    $family = trim((string)($item['path']['family'] ?? ''));
    if ($family !== '') {
        return $family;
    }
    $subclade = trim((string)($item['path']['subclade'] ?? ''));
    return $subclade !== '' ? $subclade : 'Unclassified';
}

function tekg_taxonomy_homepage_views(array $items): array
{
    $included = array_values(array_filter($items, static function (array $item): bool {
        return !empty($item['homepage_chart_included']) && trim((string)($item['path']['class'] ?? '')) !== '';
    }));

    $views = [
        'root' => tekg_taxonomy_chart_view(
            'Classified TE',
            tekg_taxonomy_counter(array_map(static fn(array $item): string => (string)$item['path']['class'], $included)),
            'class::'
        ),
    ];

    $byClass = [];
    foreach ($included as $item) {
        $className = (string)$item['path']['class'];
        $byClass[$className] ??= [];
        $byClass[$className][] = $item;
    }

    foreach ($byClass as $className => $records) {
        $classViewKey = 'class::' . tekg_taxonomy_slugify((string)$className);
        $primaryBuckets = [];
        foreach ($records as $item) {
            if ($className === 'Retrotransposons') {
                $bucket = tekg_taxonomy_retro_primary_bucket($item);
            } elseif ($className === 'DNA Transposons') {
                $bucket = tekg_taxonomy_dna_primary_bucket($item);
            } else {
                $bucket = 'Unclassified';
            }
            $primaryBuckets[$bucket] ??= [];
            $primaryBuckets[$bucket][] = $item;
        }

        $primaryCounter = [];
        foreach ($primaryBuckets as $bucketName => $bucketRecords) {
            $primaryCounter[$bucketName] = count($bucketRecords);
        }
        $views[$classViewKey] = tekg_taxonomy_chart_view($className, $primaryCounter, $classViewKey . '::');

        foreach ($primaryBuckets as $bucketName => $bucketRecords) {
            $segmentKey = $classViewKey . '::' . tekg_taxonomy_slugify((string)$bucketName);
            $superfamilyBuckets = [];
            foreach ($bucketRecords as $item) {
                $superfamily = tekg_taxonomy_path_value($item, 'superfamily');
                $superfamilyBuckets[$superfamily] ??= [];
                $superfamilyBuckets[$superfamily][] = $item;
            }

            $superfamilyCounter = [];
            foreach ($superfamilyBuckets as $superfamilyName => $superfamilyRecords) {
                $superfamilyCounter[$superfamilyName] = count($superfamilyRecords);
            }

            $nextOverrides = [];
            foreach ($superfamilyBuckets as $superfamilyName => $superfamilyRecords) {
                $deepCounter = tekg_taxonomy_counter(array_map('tekg_taxonomy_deep_bucket', $superfamilyRecords));
                if (count($deepCounter) > 1) {
                    $deepKey = $segmentKey . '::' . tekg_taxonomy_slugify((string)$superfamilyName);
                    $nextOverrides[$superfamilyName] = $deepKey;
                    $views[$deepKey] = tekg_taxonomy_chart_view((string)$superfamilyName, $deepCounter);
                }
            }
            $views[$segmentKey] = tekg_taxonomy_chart_view((string)$bucketName, $superfamilyCounter, null, $nextOverrides);
        }
    }

    return $views;
}

function tekg_taxonomy_homepage_payload(array $items): array
{
    $included = array_values(array_filter($items, static function (array $item): bool {
        return !empty($item['homepage_chart_included']) && trim((string)($item['path']['class'] ?? '')) !== '';
    }));

    return [
        'views' => tekg_taxonomy_homepage_views($items),
        'summary' => [
            'total_te_nodes' => count($items),
            'classified_for_homepage' => count($included),
            'excluded_non_leaf' => count(array_filter($items, static fn(array $item): bool => (string)($item['taxonomy_status'] ?? '') === 'non_leaf')),
            'excluded_unresolved' => count(array_filter($items, static fn(array $item): bool => (string)($item['taxonomy_group'] ?? '') === 'unresolved')),
        ],
    ];
}
