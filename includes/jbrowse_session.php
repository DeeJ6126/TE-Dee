<?php
declare(strict_types=1);

function jbrowse_primary_chr_order(): array
{
    $order = [];
    for ($i = 1; $i <= 22; $i++) {
        $order['chr' . $i] = $i;
    }
    $order['chrX'] = 23;
    $order['chrY'] = 24;
    return $order;
}

function jbrowse_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return [];
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function jbrowse_sanitize_slug(string $value): string
{
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value);
    $slug = trim((string) $slug, '_');
    return $slug !== '' ? $slug : 'track';
}

function jbrowse_normalize_te(?string $te): string
{
    $te = trim((string) $te);
    return $te;
}

function jbrowse_project_relative_path(string $relativePath): string
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

function jbrowse_project_fs_path(string $relativePath): string
{
    return tekg_fs_from_project_relative(jbrowse_project_relative_path($relativePath));
}

function jbrowse_project_url(string $relativePath): string
{
    return tekg_url_from_project_relative(jbrowse_project_relative_path($relativePath));
}

function jbrowse_load_hit_entry(string $te, array $hitManifest): ?array
{
    static $cache = [];
    if (array_key_exists($te, $cache)) {
        return $cache[$te];
    }

    $relativePath = $hitManifest[$te] ?? null;
    if (!is_string($relativePath) || $relativePath === '') {
        $cache[$te] = null;
        return null;
    }

    $absolutePath = jbrowse_project_fs_path($relativePath);
    if (!is_file($absolutePath)) {
        $cache[$te] = null;
        return null;
    }

    $decoded = jbrowse_read_json($absolutePath);
    $cache[$te] = is_array($decoded) ? $decoded : null;
    return $cache[$te];
}

function jbrowse_build_locus_from_params(array $representativeIndex, array $hitManifest = []): array
{
    $te = jbrowse_normalize_te($_GET['te'] ?? '');
    $chr = trim((string) ($_GET['chr'] ?? ''));
    $start = isset($_GET['start']) ? (int) $_GET['start'] : null;
    $end = isset($_GET['end']) ? (int) $_GET['end'] : null;

    $resolvedTeEntry = ($te !== '' && isset($representativeIndex[$te]) && is_array($representativeIndex[$te])) ? $representativeIndex[$te] : null;
    if ($te !== '' && $resolvedTeEntry) {
        $hitEntry = jbrowse_load_hit_entry($te, $hitManifest);
        if (is_array($hitEntry)) {
            $resolvedTeEntry = array_replace($resolvedTeEntry, $hitEntry);
        }
    }
    $representative = is_array($resolvedTeEntry['representative_locus'] ?? null) ? $resolvedTeEntry['representative_locus'] : null;

    if ($chr === '' && $representative) {
        $chr = (string) ($representative['chrom'] ?? '');
    }
    if ($start === null && $representative) {
        $start = (int) ($representative['start'] ?? 0);
    }
    if ($end === null && $representative) {
        $end = (int) ($representative['end'] ?? 0);
    }

    if ($chr === '' || $start === null || $end === null || $end <= $start) {
        $chr = 'chr1';
        $start = 231646101;
        $end = 231652225;
        if ($te === '') {
            $te = 'L1HS';
        }
        $resolvedTeEntry = ($te !== '' && isset($representativeIndex[$te]) && is_array($representativeIndex[$te])) ? $representativeIndex[$te] : null;
        if ($te !== '' && $resolvedTeEntry) {
            $hitEntry = jbrowse_load_hit_entry($te, $hitManifest);
            if (is_array($hitEntry)) {
                $resolvedTeEntry = array_replace($resolvedTeEntry, $hitEntry);
            }
        }
        $representative = is_array($resolvedTeEntry['representative_locus'] ?? null) ? $resolvedTeEntry['representative_locus'] : null;
    }

    $length = max(1, $end - $start);
    $padding = max(5000, (int) round($length * 2.5));
    $viewStart = max(0, $start - $padding);
    $viewEnd = $end + $padding;

    return [
        'te' => $te,
        'chr' => $chr,
        'start' => $start,
        'end' => $end,
        'view_start' => $viewStart,
        'view_end' => $viewEnd,
        'entry' => $resolvedTeEntry,
        'representative' => $representative,
    ];
}

function jbrowse_collect_repeat_rows(string $bedPath, string $chr, int $start, int $end, int $limit = 1200): array
{
    if (!is_file($bedPath)) {
        return [];
    }
    $rows = [];
    $handle = fopen($bedPath, 'rb');
    if ($handle === false) {
        return [];
    }

    while (($line = fgets($handle)) !== false) {
        $parts = explode("\t", rtrim($line, "\r\n"));
        if (count($parts) < 8) {
            continue;
        }
        $rowChr = $parts[0];
        if ($rowChr !== $chr) {
            continue;
        }
        $rowStart = (int) $parts[1];
        $rowEnd = (int) $parts[2];
        if ($rowEnd < $start) {
            continue;
        }
        if ($rowStart > $end) {
            break;
        }
        $rows[] = [
            'seqid' => $rowChr,
            'source' => 'RepeatMasker',
            'type' => 'repeat_region',
            'start' => $rowStart + 1,
            'end' => $rowEnd,
            'score' => '.',
            'strand' => ($parts[5] === '-' ? '-' : '+'),
            'phase' => '.',
            'attributes' => [
                'ID' => 'repeat_' . count($rows),
                'Name' => $parts[3],
                'te_name' => $parts[3],
                'class' => $parts[6],
                'family' => $parts[7],
            ],
        ];
        if (count($rows) >= $limit) {
            break;
        }
    }

    fclose($handle);
    return $rows;
}

function jbrowse_parse_gtf_attributes(string $attributeText): array
{
    $attributes = [];
    if (preg_match_all('/([A-Za-z0-9_]+) "([^"]*)"/', $attributeText, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $attributes[$match[1]] = $match[2];
        }
    }
    return $attributes;
}

function jbrowse_collect_refseq_rows(string $gtfPath, string $chr, int $start, int $end, int $limit = 4000): array
{
    if (!is_file($gtfPath)) {
        return [];
    }
    $rows = [];
    $handle = fopen($gtfPath, 'rb');
    if ($handle === false) {
        return [];
    }

    while (($line = fgets($handle)) !== false) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode("\t", rtrim($line, "\r\n"));
        if (count($parts) < 9) {
            continue;
        }
        if ($parts[0] !== $chr) {
            continue;
        }
        $featureStart = (int) $parts[3];
        $featureEnd = (int) $parts[4];
        if ($featureEnd < $start + 1) {
            continue;
        }
        if ($featureStart > $end) {
            break;
        }

        $attrs = jbrowse_parse_gtf_attributes($parts[8]);
        $geneName = $attrs['gene_name'] ?? ($attrs['gene_id'] ?? ($attrs['transcript_id'] ?? 'feature'));
        $transcriptId = $attrs['transcript_id'] ?? null;
        $featureType = $parts[2];
        $featureId = $transcriptId
            ? ($featureType . '_' . $transcriptId . '_' . $featureStart . '_' . $featureEnd)
            : ($featureType . '_' . $geneName . '_' . $featureStart . '_' . $featureEnd);

        $gff3Attributes = [
            'ID' => $featureId,
            'Name' => $geneName,
            'gene_name' => $geneName,
        ];
        if (!empty($attrs['gene_id'])) {
            $gff3Attributes['gene_id'] = $attrs['gene_id'];
        }
        if ($transcriptId) {
            $gff3Attributes['transcript_id'] = $transcriptId;
        }

        $rows[] = [
            'seqid' => $parts[0],
            'source' => $parts[1] !== '' ? $parts[1] : 'NCBI_RefSeq',
            'type' => $featureType,
            'start' => $featureStart,
            'end' => $featureEnd,
            'score' => ($parts[5] !== '' ? $parts[5] : '.'),
            'strand' => ($parts[6] === '-' ? '-' : '+'),
            'phase' => ($parts[7] !== '' ? $parts[7] : '.'),
            'attributes' => $gff3Attributes,
        ];

        if (count($rows) >= $limit) {
            break;
        }
    }

    fclose($handle);
    return $rows;
}

function jbrowse_write_gff3_cache(string $cachePath, array $rows): void
{
    if (is_file($cachePath)) {
        return;
    }
    $dir = dirname($cachePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $handle = fopen($cachePath, 'wb');
    if ($handle === false) {
        return;
    }
    fwrite($handle, "##gff-version 3\n");
    foreach ($rows as $row) {
        $attrs = [];
        foreach (($row['attributes'] ?? []) as $key => $value) {
            $attrs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        $attrText = implode(';', $attrs);
        $line = implode("\t", [
            $row['seqid'],
            $row['source'],
            $row['type'],
            (string) $row['start'],
            (string) $row['end'],
            (string) $row['score'],
            (string) $row['strand'],
            (string) $row['phase'],
            $attrText,
        ]);
        fwrite($handle, $line . "\n");
    }
    fclose($handle);
}
