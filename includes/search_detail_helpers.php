<?php
declare(strict_types=1);

function tekg_clean_label_proto(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/<[^>]+>/', '', $value) ?? $value;
    $value = rtrim($value, ".;,");
    return preg_replace('/\s+/', ' ', $value) ?? $value;
}

function tekg_lower_proto(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function tekg_canonicalize_label_proto(string $value): string
{
    return str_replace(['_', '-', ' '], '', tekg_lower_proto(tekg_clean_label_proto($value)));
}
function tekg_jbrowse_project_relative_path_proto(string $relativePath): string
{
    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($normalized === '') {
        return 'data/JBrowse';
    }
    if (str_starts_with($normalized, 'data/JBrowse/')) {
        return $normalized;
    }
    if ($normalized === 'data/JBrowse') {
        return $normalized;
    }
    if (str_starts_with($normalized, 'JBrowse/')) {
        return 'data/' . $normalized;
    }
    if ($normalized === 'JBrowse') {
        return 'data/JBrowse';
    }
    $marker = '/JBrowse/';
    $markerPos = strpos($normalized, $marker);
    if ($markerPos !== false) {
        return 'data/JBrowse/' . substr($normalized, $markerPos + strlen($marker));
    }
    if (str_ends_with($normalized, '/JBrowse')) {
        return 'data/JBrowse';
    }
    return 'data/JBrowse/' . $normalized;
}

function tekg_jbrowse_project_fs_path_proto(string $relativePath): string
{
    return tekg_fs_from_project_relative(tekg_jbrowse_project_relative_path_proto($relativePath));
}

function tekg_repbase_lookup_proto(string $query): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $file = tekg_data_fs_path('processed/te_repbase_db_matched.json');
    if (!is_file($file)) {
        return null;
    }

    $payload = json_decode((string) file_get_contents($file), true);
    if (!is_array($payload)) {
        return null;
    }

    $strictKey = tekg_lower_proto(tekg_clean_label_proto($query));
    $canonicalKey = tekg_canonicalize_label_proto($query);
    $entryId = $payload['name_index'][$strictKey] ?? $payload['canonical_index'][$canonicalKey] ?? null;
    if (!$entryId || empty($payload['entries']) || !is_array($payload['entries'])) {
        return null;
    }

    foreach ($payload['entries'] as $entry) {
        if (($entry['id'] ?? '') !== $entryId) {
            continue;
        }
        $sequenceSummary = (string) (($entry['sequence_summary']['raw'] ?? '') ?: '');
        $lengthBp = null;
        if ($sequenceSummary !== '' && preg_match('/(\d+)\s*BP/i', $sequenceSummary, $matches) === 1) {
            $lengthBp = (int) $matches[1];
        } else {
            $sequence = preg_replace('/\s+/', '', (string) ($entry['sequence'] ?? '')) ?? '';
            if ($sequence !== '') {
                $lengthBp = strlen($sequence);
            }
        }
        return [
            'matched' => $query,
            'id' => (string) ($entry['id'] ?? ''),
            'nm' => (string) ($entry['name'] ?? ''),
            'description' => (string) ($entry['description'] ?? ''),
            'keywords' => is_array($entry['keywords'] ?? null) ? implode(', ', $entry['keywords']) : '',
            'species' => (string) ($entry['species'] ?? ''),
            'classification' => is_array($entry['classification'] ?? null) ? implode(' > ', $entry['classification']) : '',
            'sequence_summary' => $sequenceSummary,
            'length_bp' => $lengthBp,
            'reference_count' => is_array($entry['references'] ?? null) ? count($entry['references']) : 0,
            'sequence' => (string) ($entry['sequence'] ?? ''),
        ];
    }

    return null;
}

function tekg_dfam_lookup_index_proto(): ?array
{
    static $lookup = null;
    static $loaded = false;
    if ($loaded) {
        return $lookup;
    }
    $loaded = true;
    $file = tekg_data_fs_path('processed/dfam/dfam_lookup_index.json');
    if (!is_file($file)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    $lookup = is_array($decoded) ? $decoded : null;
    return $lookup;
}

function tekg_dfam_entry_proto(string $accession): ?array
{
    static $cache = [];
    if (isset($cache[$accession])) {
        return $cache[$accession];
    }
    $file = tekg_data_fs_path('processed/dfam/entries/' . $accession . '.json');
    if (!is_file($file)) {
        $cache[$accession] = null;
        return null;
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    $cache[$accession] = is_array($decoded) ? $decoded : null;
    return $cache[$accession];
}

function tekg_dfam_model_label_proto(string $modelType): string
{
    $labels = [
        'full' => 'Full consensus model',
        'fragment_3end' => "3' end fragment model",
        'fragment_5end' => "5' end fragment model",
        'fragment_internal' => 'Internal fragment model',
        'fragment_ltr' => 'LTR fragment model',
        'unknown_fragment' => 'Fragment model',
    ];
    return $labels[$modelType] ?? 'Consensus model';
}

function tekg_dfam_plot_relative_path_proto(string $accession): string
{
    return tekg_data_url('processed/dfam/plots/' . rawurlencode($accession) . '.svg');
}

function tekg_dfam_plot_filesystem_path_proto(string $accession): string
{
    return tekg_data_fs_path('processed/dfam/plots/' . $accession . '.svg');
}

function tekg_run_python_for_dfam_plot_proto(string $accession): bool
{
    $script = tekg_scripts_fs_path('plot/render_dfam_structure_svg.py');
    if (!is_file($script)) {
        return false;
    }

    $commands = [
        'py -3',
        'python',
    ];

    foreach ($commands as $command) {
        @shell_exec($command . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($accession) . ' 2>&1');
        if (is_file(tekg_dfam_plot_filesystem_path_proto($accession))) {
            return true;
        }
    }

    return false;
}

function tekg_dfam_structure_svg_path_proto(array $entry): ?string
{
    $accession = trim((string) ($entry['accession'] ?? ''));
    if ($accession === '' || empty($entry['sequence']) || empty($entry['length_bp'])) {
        return null;
    }

    $svgFile = tekg_dfam_plot_filesystem_path_proto($accession);
    $catalogFile = tekg_data_fs_path('processed/dfam/dfam_curated_catalog.json');
    $rendererScript = tekg_scripts_fs_path('plot/render_dfam_structure_svg.py');
    $baseRenderer = tekg_scripts_fs_path('plot/base_SVG.py');
    $needsRender = !is_file($svgFile);

    if (!$needsRender) {
        $svgTime = @filemtime($svgFile) ?: 0;
        $sourceTime = max(
            @filemtime($catalogFile) ?: 0,
            @filemtime($rendererScript) ?: 0,
            @filemtime($baseRenderer) ?: 0
        );
        $needsRender = $svgTime < $sourceTime;
    }

    if ($needsRender && !tekg_run_python_for_dfam_plot_proto($accession)) {
        return null;
    }

    return is_file($svgFile) ? tekg_dfam_plot_relative_path_proto($accession) : null;
}


function tekg_repbase_structure_svg_url_proto(?array $repbase, string $query): ?string
{
    $candidate = '';
    if (is_array($repbase)) {
        foreach (['nm', 'id'] as $key) {
            $value = trim((string) ($repbase[$key] ?? ''));
            if ($value !== '') {
                $candidate = $value;
                break;
            }
        }
    }

    if ($candidate === '') {
        $candidate = trim($query);
    }
    if ($candidate === '') {
        return null;
    }

    return tekg_app_url('repbase_structure_svg.php') . '?te=' . rawurlencode($candidate);
}

function tekg_dfam_lookup_proto(string $query, string $type = 'all'): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $normalizedType = strtoupper(trim($type));
    if (in_array($normalizedType, ['DISEASE', 'FUNCTION', 'PAPER'], true)) {
        return null;
    }

    $lookup = tekg_dfam_lookup_index_proto();
    if (!is_array($lookup)) {
        return null;
    }

    $strictKey = tekg_lower_proto(tekg_clean_label_proto($query));
    $canonicalKey = tekg_canonicalize_label_proto($query);
    $accession = $lookup['name_index'][$strictKey] ?? $lookup['canonical_index'][$canonicalKey] ?? null;
    if (!is_string($accession) || $accession === '') {
        return null;
    }

    $entry = tekg_dfam_entry_proto($accession);
    if (!is_array($entry)) {
        return null;
    }

    $entry['matched_query'] = $query;
    $entry['sequence_length_bp'] = (int) ($entry['length_bp'] ?? 0);
    $entry['model_type_label'] = tekg_dfam_model_label_proto((string) ($entry['model_type'] ?? 'full'));
    $entry['structure_svg_path'] = tekg_dfam_structure_svg_path_proto($entry);
    return $entry;
}

function tekg_karyotype_index_proto(): ?array
{
    static $lookup = null;
    static $loaded = false;
    if ($loaded) {
        return $lookup;
    }
    $loaded = true;
    $file = tekg_data_fs_path('processed/rmsk/karyotype_index.json');
    if (!is_file($file)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    $lookup = is_array($decoded) ? $decoded : null;
    return $lookup;
}

function tekg_karyotype_lookup_proto(string $query, string $type = 'all', ?array $repbase = null): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $normalizedType = strtoupper(trim($type));
    if (in_array($normalizedType, ['DISEASE', 'FUNCTION', 'PAPER'], true)) {
        return null;
    }

    $lookup = tekg_karyotype_index_proto();
    if (!is_array($lookup)) {
        return null;
    }

    $candidates = [$query];
    if (is_array($repbase)) {
        foreach (['nm', 'id'] as $key) {
            $candidate = trim((string) ($repbase[$key] ?? ''));
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }
    }

    $teName = null;
    foreach ($candidates as $candidate) {
        $strictKey = tekg_lower_proto(tekg_clean_label_proto($candidate));
        $canonicalKey = tekg_canonicalize_label_proto($candidate);
        $teName = $lookup['name_index'][$strictKey] ?? $lookup['canonical_index'][$canonicalKey] ?? null;
        if (is_string($teName) && $teName !== '') {
            break;
        }
    }

    if (!is_string($teName) || $teName === '') {
        return null;
    }

    $entry = $lookup['entries'][$teName] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $entry['matched_query'] = $query;
    $entry['resolved_te_name'] = $teName;
    $relativeDataPath = tekg_project_relative_from_site_path_proto((string) ($entry['data_json_path'] ?? ''));
    if ($relativeDataPath !== '') {
        $entry['data_json_path'] = tekg_url_from_project_relative($relativeDataPath);
    }
    return $entry;
}


function tekg_project_relative_from_site_path_proto(string $path): string
{
    $normalized = trim(str_replace('\\', '/', $path));
    if ($normalized === '') {
        return '';
    }
    $path = parse_url($normalized, PHP_URL_PATH);
    $normalized = is_string($path) ? $path : $normalized;
    foreach (['/api/', '/assets/', '/data/'] as $marker) {
        $position = strpos($normalized, $marker);
        if ($position !== false) {
            return ltrim(substr($normalized, $position), '/');
        }
    }
    return ltrim($normalized, '/');
}

function tekg_jbrowse_bin_size_proto(): int
{
    return 1000000;
}

function tekg_jbrowse_bin_cache_directory_proto(): string
{
    $dir = tekg_jbrowse_project_fs_path_proto('repeats/bin_hits');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function tekg_jbrowse_bin_cache_path_proto(string $teName): string
{
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', $teName) ?? 'te';
    $slug = trim($slug, '_');
    if ($slug === '') {
        $slug = 'te';
    }
    return tekg_jbrowse_bin_cache_directory_proto() . DIRECTORY_SEPARATOR . $slug . '__' . substr(sha1($teName), 0, 12) . '.json';
}

function tekg_jbrowse_hit_label_proto(string $chrom, int $start, int $end, string $strand, int $length, int $score): string
{
    return sprintf(
        '%s:%s-%s | %s | len %s bp | score %s',
        $chrom,
        number_format($start + 1),
        number_format($end),
        $strand === '-' ? 'reverse strand' : 'forward strand',
        number_format($length),
        number_format($score)
    );
}

function tekg_jbrowse_build_bin_hits_for_te_proto(string $teName, int $binSize): ?array
{
    $rmskPath = tekg_data_fs_path('rmsk.txt');
    if (!is_file($rmskPath)) {
        return null;
    }

    $primaryChroms = array_fill_keys(array_merge(
        array_map(static fn (int $i): string => 'chr' . $i, range(1, 22)),
        ['chrX', 'chrY']
    ), true);

    $handle = @fopen($rmskPath, 'r');
    if ($handle === false) {
        return null;
    }

    $bins = [];
    $totalHits = 0;

    try {
        while (($raw = fgets($handle)) !== false) {
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $parts = explode("	", rtrim($raw, "

"));
            if (count($parts) < 15) {
                continue;
            }

            $repName = (string) ($parts[10] ?? '');
            if ($repName !== $teName) {
                continue;
            }

            $chrom = (string) ($parts[5] ?? '');
            if ($chrom === '' || !isset($primaryChroms[$chrom])) {
                continue;
            }

            $start = isset($parts[6]) ? (int) $parts[6] : -1;
            $end = isset($parts[7]) ? (int) $parts[7] : -1;
            if ($start < 0 || $end <= $start) {
                continue;
            }

            $strand = ((string) ($parts[9] ?? '+')) === '-' ? '-' : '+';
            $length = max(1, $end - $start);
            $score = isset($parts[1]) ? (int) $parts[1] : 0;

            $hit = [
                'chrom' => $chrom,
                'start' => $start,
                'end' => $end,
                'strand' => $strand,
                'length' => $length,
                'score' => $score,
                'label' => tekg_jbrowse_hit_label_proto($chrom, $start, $end, $strand, $length, $score),
            ];

            $totalHits += 1;
            $startBin = intdiv(max(0, $start), $binSize);
            $endBin = intdiv(max(0, $end - 1), $binSize);
            for ($binIndex = $startBin; $binIndex <= $endBin; $binIndex++) {
                $binStart = ($binIndex * $binSize) + 1;
                $binEnd = ($binIndex + 1) * $binSize;
                $key = $chrom . ':' . $binStart . '-' . $binEnd;
                if (!isset($bins[$key])) {
                    $bins[$key] = [
                        'chrom' => $chrom,
                        'start' => $binStart,
                        'end' => $binEnd,
                        'count' => 0,
                        'hits' => [],
                    ];
                }
                $bins[$key]['hits'][] = $hit;
                $bins[$key]['count'] += 1;
            }
        }
    } finally {
        fclose($handle);
    }

    if ($bins === []) {
        return [
            'te' => $teName,
            'bin_size_bp' => $binSize,
            'total_hits' => 0,
            'bins' => [],
        ];
    }

    ksort($bins);
    return [
        'te' => $teName,
        'bin_size_bp' => $binSize,
        'total_hits' => $totalHits,
        'bins' => $bins,
    ];
}

function tekg_jbrowse_load_bin_hits_for_te_proto(string $teName): ?array
{
    static $cache = [];
    if (array_key_exists($teName, $cache)) {
        return $cache[$teName];
    }

    $cachePath = tekg_jbrowse_bin_cache_path_proto($teName);
    $rmskPath = tekg_data_fs_path('rmsk.txt');
    $sourceTime = is_file($rmskPath) ? ((int) @filemtime($rmskPath)) : 0;
    $decoded = null;

    if (is_file($cachePath) && ((int) @filemtime($cachePath)) >= $sourceTime) {
        $decoded = json_decode((string) file_get_contents($cachePath), true);
        if (!is_array($decoded) || (int) ($decoded['bin_size_bp'] ?? 0) !== tekg_jbrowse_bin_size_proto()) {
            $decoded = null;
        }
    }

    if (!is_array($decoded)) {
        $decoded = tekg_jbrowse_build_bin_hits_for_te_proto($teName, tekg_jbrowse_bin_size_proto());
        if (is_array($decoded)) {
            @file_put_contents(
                $cachePath,
                json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
        }
    }

    $cache[$teName] = is_array($decoded) ? $decoded : null;
    return $cache[$teName];
}

function tekg_karyotype_bin_hit_map_proto(?array $genomeDistribution, ?array $jbrowseSession): array
{
    $result = [
        'available' => false,
        'bin_size_bp' => (int) ($genomeDistribution['bin_size_bp'] ?? ($jbrowseSession['bin_size_bp'] ?? 0)),
        'sample_hit_total' => is_array($jbrowseSession['sample_hits'] ?? null) ? count($jbrowseSession['sample_hits']) : 0,
        'total_hits' => (int) ($jbrowseSession['total_hits'] ?? ($genomeDistribution['total_hits'] ?? 0)),
        'bins' => [],
    ];

    if (!is_array($genomeDistribution) || !is_array($jbrowseSession)) {
        return $result;
    }

    $rawBinHits = is_array($jbrowseSession['bin_hits'] ?? null) ? $jbrowseSession['bin_hits'] : [];
    if ($rawBinHits === []) {
        return $result;
    }

    $dataJsonPath = tekg_project_relative_from_site_path_proto((string) ($genomeDistribution['data_json_path'] ?? ''));
    if ($dataJsonPath === '') {
        return $result;
    }

    $absolutePath = tekg_fs_from_project_relative($dataJsonPath);
    if (!is_file($absolutePath)) {
        return $result;
    }

    $payload = json_decode((string) file_get_contents($absolutePath), true);
    if (!is_array($payload)) {
        return $result;
    }

    $countByBinKey = [];
    foreach ((array) ($payload['singleton_contigs'] ?? []) as $contig) {
        if (!is_array($contig)) {
            continue;
        }
        $chrom = trim((string) ($contig['name'] ?? ''));
        if ($chrom === '') {
            continue;
        }
        foreach ((array) ($contig['hit_clusters'] ?? []) as $cluster) {
            if (!is_array($cluster) || count($cluster) < 3) {
                continue;
            }
            $start = (int) ($cluster[0] ?? 0);
            $end = (int) ($cluster[1] ?? 0);
            $count = (int) ($cluster[2] ?? 0);
            if ($start <= 0 || $end < $start) {
                continue;
            }
            $countByBinKey[$chrom . ':' . $start . '-' . $end] = $count;
        }
    }

    foreach ($rawBinHits as $key => $bin) {
        if (!is_string($key) || !is_array($bin)) {
            continue;
        }
        $chrom = trim((string) ($bin['chrom'] ?? ''));
        $start = (int) ($bin['start'] ?? 0);
        $end = (int) ($bin['end'] ?? 0);
        $hits = [];
        foreach ((array) ($bin['hits'] ?? []) as $index => $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $hitChrom = trim((string) ($hit['chrom'] ?? ''));
            $hitStart = (int) ($hit['start'] ?? -1);
            $hitEnd = (int) ($hit['end'] ?? -1);
            if ($hitChrom === '' || $hitStart < 0 || $hitEnd <= $hitStart) {
                continue;
            }
            $hitStrand = ((string) ($hit['strand'] ?? '+')) === '-' ? '-' : '+';
            $hitLength = max(1, (int) ($hit['length'] ?? ($hitEnd - $hitStart)));
            $hitScore = (int) ($hit['score'] ?? 0);
            $hits[] = [
                'hitIndex' => (int) $index,
                'chrom' => $hitChrom,
                'start' => $hitStart,
                'end' => $hitEnd,
                'strand' => $hitStrand,
                'length' => $hitLength,
                'score' => $hitScore,
                'label' => (string) ($hit['label'] ?? tekg_jbrowse_hit_label_proto($hitChrom, $hitStart, $hitEnd, $hitStrand, $hitLength, $hitScore)),
            ];
        }

        if ($chrom === '' || $start <= 0 || $end < $start || $hits === []) {
            continue;
        }

        $result['bins'][$key] = [
            'chrom' => $chrom,
            'start' => $start,
            'end' => $end,
            'count' => (int) ($countByBinKey[$key] ?? count($hits)),
            'hits' => $hits,
        ];
    }

    $result['available'] = $result['bins'] !== [];
    return $result;
}


function tekg_tree_classification_items_proto(): ?array
{
    static $items = null;
    static $loaded = false;
    if ($loaded) {
        return $items;
    }
    $loaded = true;

    try {
        $items = tekg_taxonomy_fetch_items();
    } catch (Throwable) {
        return null;
    }
    return $items;
}

function tekg_tree_classification_display_label_proto(string $label): string
{
    $map = [
        'TE' => 'TE - Human',
        'Retrotransposon' => 'Class I: Retrotransposons',
        'DNA Transposon' => 'Class II: DNA Transposons',
        'SINE' => 'SINEs',
    ];
    return $map[$label] ?? $label;
}

function tekg_tree_classification_lookup_proto(string $query, string $type = 'all', ?array $repbase = null, ?array $dfam = null): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $normalizedType = strtoupper(trim($type));
    if (in_array($normalizedType, ['DISEASE', 'FUNCTION', 'PAPER'], true)) {
        return null;
    }

    $items = tekg_tree_classification_items_proto();
    if (!is_array($items)) {
        return null;
    }

    $candidates = [$query];
    if (is_array($repbase)) {
        foreach (['nm', 'id'] as $key) {
            $candidate = trim((string) ($repbase[$key] ?? ''));
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }
    }
    if (is_array($dfam)) {
        foreach (['name', 'accession', 'matched_query'] as $key) {
            $candidate = trim((string) ($dfam[$key] ?? ''));
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }
    }

    $taxonomyIndex = tekg_taxonomy_index_items($items);
    $taxonomyItem = null;
    foreach ($candidates as $candidate) {
        $strictKey = tekg_lower_proto(tekg_clean_label_proto($candidate));
        $canonicalKey = tekg_canonicalize_label_proto($candidate);
        $taxonomyItem = $taxonomyIndex[$candidate]
            ?? $taxonomyIndex[$strictKey]
            ?? $taxonomyIndex[$canonicalKey]
            ?? $taxonomyIndex[tekg_taxonomy_canonical_key($candidate)]
            ?? null;
        if (is_array($taxonomyItem)) {
            break;
        }
    }

    if (!is_array($taxonomyItem)) {
        return null;
    }

    $path = [];
    $labels = array_merge(['TE'], (array)($taxonomyItem['path_labels'] ?? []));
    foreach ($labels as $depth => $label) {
        $label = trim((string)$label);
        if ($label === '') {
            continue;
        }
        $path[] = [
            'name' => $label,
            'display_label' => tekg_tree_classification_display_label_proto($label),
            'depth' => (int)$depth,
            'description' => $label === 'TE'
                ? 'TE taxonomy root synthesized from Neo4j tekg3.'
                : $label . ' taxonomy node synthesized from Neo4j tekg3.',
        ];
    }

    if ($path === []) {
        return null;
    }

    return [
        'matched_query' => $query,
        'resolved_te_name' => (string)($taxonomyItem['name'] ?? ''),
        'path' => $path,
        'display_path' => implode(' --- ', array_map(static fn(array $node): string => (string) ($node['display_label'] ?? ''), $path)),
    ];
}

function tekg_jbrowse_index_proto(): ?array
{
    static $lookup = null;
    static $loaded = false;
    if ($loaded) {
        return $lookup;
    }
    $loaded = true;

    $representativeFile = tekg_jbrowse_project_fs_path_proto('repeats/te_representative_index.json');
    $manifestFile = tekg_jbrowse_project_fs_path_proto('repeats/te_hits_manifest.json');
    if (!is_file($representativeFile)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents($representativeFile), true);
    if (!is_array($decoded)) {
        return null;
    }
    $manifest = is_file($manifestFile)
        ? json_decode((string) file_get_contents($manifestFile), true)
        : [];
    if (!is_array($manifest)) {
        $manifest = [];
    }

    $nameIndex = [];
    $canonicalIndex = [];
    foreach ($decoded as $name => $entry) {
        if (!is_string($name) || $name === '' || !is_array($entry)) {
            continue;
        }
        $strictKey = tekg_lower_proto(tekg_clean_label_proto($name));
        $canonicalKey = tekg_canonicalize_label_proto($name);
        if ($strictKey !== '') {
            $nameIndex[$strictKey] = $name;
        }
        if ($canonicalKey !== '') {
            $canonicalIndex[$canonicalKey] = $name;
        }
    }

    $lookup = [
        'entries' => $decoded,
        'name_index' => $nameIndex,
        'canonical_index' => $canonicalIndex,
        'hit_manifest' => $manifest,
    ];
    return $lookup;
}

function tekg_jbrowse_load_hit_entry_proto(string $teName, array $lookup): ?array
{
    static $cache = [];
    if (array_key_exists($teName, $cache)) {
        return $cache[$teName];
    }

    $relativePath = $lookup['hit_manifest'][$teName] ?? null;
    if (!is_string($relativePath) || $relativePath === '') {
        $cache[$teName] = null;
        return null;
    }

    $absolutePath = tekg_jbrowse_project_fs_path_proto($relativePath);
    if (!is_file($absolutePath)) {
        $cache[$teName] = null;
        return null;
    }

    $decoded = json_decode((string) file_get_contents($absolutePath), true);
    $cache[$teName] = is_array($decoded) ? $decoded : null;
    return $cache[$teName];
}

function tekg_jbrowse_lookup_proto(string $query, string $type = 'all', ?array $repbase = null, ?string $lang = null): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $normalizedType = strtoupper(trim($type));
    if (in_array($normalizedType, ['DISEASE', 'FUNCTION', 'PAPER'], true)) {
        return null;
    }

    $lookup = tekg_jbrowse_index_proto();
    if (!is_array($lookup)) {
        return null;
    }

    $candidates = [$query];
    if (is_array($repbase)) {
        foreach (['nm', 'id'] as $key) {
            $candidate = trim((string) ($repbase[$key] ?? ''));
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }
    }

    $teName = null;
    foreach ($candidates as $candidate) {
        $strictKey = tekg_lower_proto(tekg_clean_label_proto($candidate));
        $canonicalKey = tekg_canonicalize_label_proto($candidate);
        $teName = $lookup['name_index'][$strictKey] ?? $lookup['canonical_index'][$canonicalKey] ?? null;
        if (is_string($teName) && $teName !== '') {
            break;
        }
    }

    if (!is_string($teName) || $teName === '') {
        return null;
    }

    $entry = $lookup['entries'][$teName] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $hitEntry = tekg_jbrowse_load_hit_entry_proto($teName, $lookup);
    if (is_array($hitEntry)) {
        $entry = array_replace($entry, $hitEntry);
    }

    $binHitEntry = tekg_jbrowse_load_bin_hits_for_te_proto($teName);
    if (is_array($binHitEntry)) {
        $entry['bin_size_bp'] = (int) ($binHitEntry['bin_size_bp'] ?? tekg_jbrowse_bin_size_proto());
        $entry['bin_hits'] = is_array($binHitEntry['bins'] ?? null) ? $binHitEntry['bins'] : [];
    }

    $locus = is_array($entry['representative_locus'] ?? null) ? $entry['representative_locus'] : null;
    if (!is_array($locus)) {
        return null;
    }

    $sampleHits = [];
    foreach (($entry['sample_hits'] ?? []) as $hit) {
        if (!is_array($hit)) {
            continue;
        }
        $chrom = trim((string) ($hit['chrom'] ?? ''));
        $start = (int) ($hit['start'] ?? -1);
        $end = (int) ($hit['end'] ?? -1);
        if ($chrom === '' || $start < 0 || $end <= $start) {
            continue;
        }
        $strand = ((string) ($hit['strand'] ?? '+')) === '-' ? '-' : '+';
        $length = max(1, (int) ($hit['length'] ?? ($end - $start)));
        $score = (int) ($hit['score'] ?? 0);
        $sampleHits[] = [
            'chrom' => $chrom,
            'start' => $start,
            'end' => $end,
            'strand' => $strand,
            'length' => $length,
            'score' => $score,
            'label' => sprintf(
                '%s:%s-%s | %s | len %s bp | score %s',
                $chrom,
                number_format($start + 1),
                number_format($end),
                $strand === '-' ? 'reverse strand' : 'forward strand',
                number_format($length),
                number_format($score)
            ),
        ];
    }
    if ($sampleHits === []) {
        $fallbackChrom = (string) ($locus['chrom'] ?? '');
        $fallbackStart = (int) ($locus['start'] ?? 0);
        $fallbackEnd = (int) ($locus['end'] ?? 0);
        $fallbackStrand = ((string) ($locus['strand'] ?? '+')) === '-' ? '-' : '+';
        $fallbackLength = max(1, (int) ($locus['length'] ?? ($fallbackEnd - $fallbackStart)));
        $fallbackScore = (int) ($locus['score'] ?? 0);
        $sampleHits[] = [
            'chrom' => $fallbackChrom,
            'start' => $fallbackStart,
            'end' => $fallbackEnd,
            'strand' => $fallbackStrand,
            'length' => $fallbackLength,
            'score' => $fallbackScore,
            'label' => sprintf(
                '%s:%s-%s | %s | len %s bp | score %s',
                $fallbackChrom,
                number_format($fallbackStart + 1),
                number_format($fallbackEnd),
                $fallbackStrand === '-' ? 'reverse strand' : 'forward strand',
                number_format($fallbackLength),
                number_format($fallbackScore)
            ),
        ];
    }

    $browserParams = array_filter([
        'te' => $teName,
        'chr' => (string) ($locus['chrom'] ?? ''),
        'start' => array_key_exists('start', $locus) ? (string) ((int) $locus['start']) : null,
        'end' => array_key_exists('end', $locus) ? (string) ((int) $locus['end']) : null,
    ], static fn ($value) => $value !== null && $value !== '');

    $entry['matched_query'] = $query;
    $entry['resolved_te_name'] = $teName;
    $entry['sample_hits'] = $sampleHits;
    $entry['locus_label'] = sprintf(
        '%s:%s-%s',
        (string) ($locus['chrom'] ?? '-'),
        number_format(((int) ($locus['start'] ?? 0)) + 1),
        number_format((int) ($locus['end'] ?? 0))
    );
    $entry['browser_url'] = site_url_with_state(tekg_app_url('jbrowse.php'), $lang ?? site_lang(), null, $browserParams);
    $entry['config_url'] = site_url_with_state(tekg_app_url('jbrowse.php'), $lang ?? site_lang(), null, $browserParams + ['format' => 'config']);
    return $entry;
}

function tekg_format_sequence_proto(string $sequence, int $wrap = 80): string
{
    $sequence = preg_replace('/\s+/', '', strtolower(trim($sequence))) ?? '';
    if ($sequence === '') {
        return '';
    }
    return rtrim(chunk_split($sequence, $wrap, "\n"));
}

function tekg_request_scalar_proto(array $source, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $source)) {
        return $default;
    }
    $value = $source[$key];
    if (is_array($value)) {
        foreach ($value as $item) {
            if (is_scalar($item)) {
                return trim((string) $item);
            }
        }
        return $default;
    }
    if (!is_scalar($value)) {
        return $default;
    }
    return trim((string) $value);
}
