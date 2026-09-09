<?php
require_once __DIR__ . '/path_config.php';
require_once __DIR__ . '/site_i18n.php';

function repbase_svg_clean_label(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/<[^>]+>/', '', $value) ?? $value;
    $value = rtrim($value, ".;,");
    return preg_replace('/\s+/', ' ', $value) ?? $value;
}

function repbase_svg_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function repbase_svg_canonicalize_label(string $value): string
{
    return str_replace(['_', '-', ' '], '', repbase_svg_lower(repbase_svg_clean_label($value)));
}

function repbase_svg_lookup(string $query): ?array
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
    $strictKey = repbase_svg_lower(repbase_svg_clean_label($query));
    $canonicalKey = repbase_svg_canonicalize_label($query);
    $entryId = $payload['name_index'][$strictKey] ?? $payload['canonical_index'][$canonicalKey] ?? null;
    if (!$entryId || empty($payload['entries']) || !is_array($payload['entries'])) {
        return null;
    }
    foreach ($payload['entries'] as $entry) {
        if (($entry['id'] ?? '') !== $entryId) {
            continue;
        }
        return [
            'id' => (string) ($entry['id'] ?? ''),
            'nm' => (string) ($entry['name'] ?? ''),
        ];
    }
    return null;
}

function parse_repbase_entry(string $filePath, string $targetId): ?array {
    if (!is_file($filePath)) {
        return null;
    }
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        return null;
    }
    $entry = [];
    $collecting = false;
    while (($line = fgets($handle)) !== false) {
        $line = rtrim($line, "\r\n");
        if (preg_match('/^ID\s+([^\s]+)/', $line, $m)) {
            if ($collecting && !empty($entry)) {
                break;
            }
            $collecting = strcasecmp($m[1], $targetId) === 0;
            if ($collecting) {
                $entry = ['id' => $m[1], 'lines' => [$line]];
            }
            continue;
        }
        if ($collecting) {
            $entry['lines'][] = $line;
            if ($line === '//') {
                break;
            }
        }
    }
    fclose($handle);
    return ($collecting || !empty($entry)) ? $entry : null;
}

function extract_repbase_structure(array $entry): array {
    $sequenceLength = null;
    $features = [];
    $current = null;
    foreach ($entry['lines'] as $line) {
        if ($sequenceLength === null && preg_match('/^ID\s+\S+\s+repbase;\s+\S+;\s+\S+;\s+(\d+)\s+BP\./i', $line, $m)) {
            $sequenceLength = (int) $m[1];
        }
        if ($sequenceLength === null && preg_match('/^SQ\s+Sequence\s+(\d+)\s+BP;/i', $line, $m)) {
            $sequenceLength = (int) $m[1];
        }
        if (preg_match('/^FT\s+([A-Za-z_]+)\s+(\d+)\.\.(\d+)/', $line, $m)) {
            if ($current !== null) {
                $features[] = $current;
            }
            $current = [
                'feature_type' => strtoupper($m[1]),
                'start' => (int) $m[2],
                'end' => (int) $m[3],
                'label' => strtoupper($m[1]),
                'note' => '',
            ];
            continue;
        }
        if ($current !== null && preg_match('/^FT\s+\/note="([^"]+)"/i', $line, $m)) {
            $current['note'] = trim($m[1], ". \t");
            if ($current['note'] !== '') {
                $current['label'] = strtoupper($current['note']);
            }
            continue;
        }
    }
    if ($current !== null) {
        $features[] = $current;
    }
    return ['sequence_length' => $sequenceLength, 'features' => $features];
}

function normalize_structure_label(string $label, string $featureType): string {
    $value = strtolower(trim(rtrim($label, '.')));
    if ($value === '') {
        $value = strtolower($featureType);
    }
    $map = [
        'gag' => 'GAG',
        'pro' => 'PRO',
        'pol' => 'POL',
        'env' => 'ENV',
        'ltr' => 'LTR',
        'orf' => 'ORF',
        'orf1' => 'ORF1',
        'orf2' => 'ORF2',
        'cds' => 'CDS',
        'pbs' => 'PBS',
        'ppt' => 'PPT',
    ];
    return $map[$value] ?? strtoupper($value);
}

function structure_color(string $label): string {
    static $palette = [
        'GAG' => '#f6df86',
        'PRO' => '#f5c29a',
        'POL' => '#eea7a2',
        'ENV' => '#aee0c9',
        'LTR' => '#cfd7e6',
        'ORF' => '#b8d6f2',
        'ORF1' => '#b8d6f2',
        'ORF2' => '#d8e6fb',
        'CDS' => '#d8c8f1',
        'PBS' => '#f3d2e6',
        'PPT' => '#c9ebd0',
    ];
    $key = strtoupper($label);
    if (isset($palette[$key])) {
        return $palette[$key];
    }
    $fallback = ['#d8e6fb', '#d8f2e4', '#f9ddc5', '#ecd3f8', '#f8e6a8', '#cfe2f3'];
    return $fallback[abs(crc32($key)) % count($fallback)];
}

function assign_feature_lanes(array $features): array {
    usort($features, static function (array $a, array $b): int {
        if ($a['start'] === $b['start']) {
            return $a['end'] <=> $b['end'];
        }
        return $a['start'] <=> $b['start'];
    });
    $laneEnds = [];
    foreach ($features as &$feature) {
        $lane = 0;
        while (isset($laneEnds[$lane]) && $feature['start'] <= $laneEnds[$lane] + 40) {
            $lane++;
        }
        $feature['lane'] = $lane;
        $laneEnds[$lane] = $feature['end'];
    }
    unset($feature);
    return $features;
}

function nice_axis_step(int $length, int $targetTicks = 8): int {
    if ($length <= 0) {
        return 1;
    }
    $raw = max(1.0, $length / max(2, $targetTicks));
    $power = pow(10, floor(log10($raw)));
    $fraction = $raw / $power;
    foreach ([1, 2, 2.5, 4, 5, 8, 10] as $nice) {
        if ($fraction <= $nice) {
            return (int) max(1, round($nice * $power));
        }
    }
    return (int) max(1, round(10 * $power));
}

function build_axis_ticks(int $length): array {
    $step = nice_axis_step($length, 8);
    $ticks = [];
    for ($value = 0; $value <= $length; $value += $step) {
        $ticks[] = $value;
    }
    if ($length > 0 && end($ticks) !== $length && ($length - end($ticks)) >= (int) round($step * 0.35)) {
        $ticks[] = $length;
    }
    return ['step' => $step, 'ticks' => $ticks];
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$query = isset($_GET['te']) && is_scalar($_GET['te']) ? trim((string) $_GET['te']) : '';
$repbase = repbase_svg_lookup($query);
$candidateIds = array_values(array_unique(array_filter([
    $query,
    $repbase['nm'] ?? '',
    $repbase['id'] ?? '',
], static fn($v) => is_string($v) && trim($v) !== '')));
$entry = null;
foreach ($candidateIds as $candidate) {
    $entry = parse_repbase_entry(tekg_data_fs_path('raw/TE_Repbase.txt'), $candidate);
    if ($entry !== null) {
        break;
    }
}
$structure = $entry !== null ? extract_repbase_structure($entry) : ['sequence_length' => null, 'features' => []];
$sequenceLength = (int) ($structure['sequence_length'] ?? 0);
$features = [];
foreach ($structure['features'] as $feature) {
    $normalized = normalize_structure_label((string) ($feature['label'] ?? ''), (string) ($feature['feature_type'] ?? 'CDS'));
    $feature['normalized_label'] = $normalized;
    $feature['color'] = structure_color($normalized);
    $features[] = $feature;
}
$features = assign_feature_lanes($features);
$laneCount = 0;
foreach ($features as $feature) {
    $laneCount = max($laneCount, (int) $feature['lane'] + 1);
}
$svgWidth = 1400;
$padLeft = 30;
$padRight = 30;
$rowGap = 58;
$rowTop = 34;
$rectHeight = 34;
$trackStartY = $rowTop + ($laneCount * $rowGap) + 18;
$svgHeight = max(180, $trackStartY + 55);
$usableWidth = $svgWidth - $padLeft - $padRight;
$axis = build_axis_ticks($sequenceLength);

header('Content-Type: image/svg+xml; charset=UTF-8');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 <?= $svgWidth ?> <?= $svgHeight ?>" role="img" aria-label="Repbase structure plot for <?= esc((string) ($entry['id'] ?? $query)) ?>">
  <style>
    .feature-group { transform-box: fill-box; transform-origin: center; transition: transform 0.18s ease, filter 0.18s ease; cursor: pointer; }
    .feature-group:hover { transform: translateY(-5px); filter: drop-shadow(0 6px 10px rgba(34,53,91,0.16)); }
    .feature-range { opacity: 0; transition: opacity 0.18s ease; pointer-events: none; }
    .feature-group:hover .feature-range { opacity: 1; }
    .feature-label, .feature-range, .axis-label { user-select: none; pointer-events: none; }
  </style>
  <rect x="0" y="0" width="<?= $svgWidth ?>" height="<?= $svgHeight ?>" fill="#ffffff"/>
  <?php if (!empty($features) && $sequenceLength > 0): ?>
    <?php foreach (range(0, max(0, $laneCount - 1)) as $lane):
      $y = $rowTop + ($lane * $rowGap) + ($rectHeight / 2);
    ?>
      <line x1="<?= $padLeft ?>" y1="<?= $y ?>" x2="<?= $svgWidth - $padRight ?>" y2="<?= $y ?>" stroke="#e3ebf8" stroke-width="2" />
    <?php endforeach; ?>

    <?php foreach ($features as $feature):
      $x = $padLeft + ((($feature['start'] - 1) / $sequenceLength) * $usableWidth);
      $w = max(84.0, ((($feature['end'] - $feature['start'] + 1) / $sequenceLength) * $usableWidth));
      if ($x + $w > $svgWidth - $padRight) {
          $w = max(84.0, ($svgWidth - $padRight) - $x);
      }
      $y = $rowTop + (((int) $feature['lane']) * $rowGap);
      $rangeX = $x + ($w / 2);
      $rangeY = $y - 10;
      $label = (string) ($feature['normalized_label'] ?? '');
    ?>
      <g class="feature-group">
        <rect x="<?= esc(number_format($x, 2, '.', '')) ?>" y="<?= esc(number_format($y, 2, '.', '')) ?>" width="<?= esc(number_format($w, 2, '.', '')) ?>" height="<?= $rectHeight ?>" fill="<?= esc((string) $feature['color']) ?>" stroke="#1e2d45" stroke-width="2" rx="0" ry="0" />
        <text class="feature-label" x="<?= esc(number_format($x + ($w / 2), 2, '.', '')) ?>" y="<?= esc(number_format($y + 22, 2, '.', '')) ?>" text-anchor="middle" font-size="18" font-weight="700" fill="#233654"><?= esc($label) ?></text>
        <g class="feature-range">
          <rect x="<?= esc(number_format(max(14, $rangeX - 56), 2, '.', '')) ?>" y="<?= esc(number_format($rangeY - 20, 2, '.', '')) ?>" width="112" height="22" fill="rgba(20,33,58,0.94)" rx="0" ry="0" />
          <text x="<?= esc(number_format($rangeX, 2, '.', '')) ?>" y="<?= esc(number_format($rangeY - 5, 2, '.', '')) ?>" text-anchor="middle" font-size="12" font-weight="600" fill="#ffffff"><?= esc((string) $feature['start'] . ' - ' . (string) $feature['end']) ?></text>
        </g>
      </g>
    <?php endforeach; ?>

    <rect x="<?= $padLeft ?>" y="<?= $trackStartY ?>" width="<?= $usableWidth ?>" height="10" fill="#edf3ff" stroke="#d3def0" stroke-width="1.5" rx="0" ry="0" />
    <?php foreach ($axis['ticks'] as $tick):
      $tickX = $padLeft + (($tick / max(1, $sequenceLength)) * $usableWidth);
      $anchor = 'middle';
      if ($tick === 0) {
          $anchor = 'start';
      } elseif ($tick === $sequenceLength) {
          $anchor = 'end';
      }
    ?>
      <line x1="<?= esc(number_format($tickX, 2, '.', '')) ?>" y1="<?= $trackStartY + 10 ?>" x2="<?= esc(number_format($tickX, 2, '.', '')) ?>" y2="<?= $trackStartY + 22 ?>" stroke="#8e99ad" stroke-width="1.4" />
      <text class="axis-label" x="<?= esc(number_format($tickX, 2, '.', '')) ?>" y="<?= $trackStartY + 48 ?>" text-anchor="<?= $anchor ?>" font-size="16" font-weight="500" fill="#8a95a8"><?= esc(number_format((float) $tick, 0, '.', ',')) ?></text>
    <?php endforeach; ?>
  <?php else: ?>
    <rect x="24" y="24" width="<?= $svgWidth - 48 ?>" height="<?= $svgHeight - 48 ?>" fill="#f8fbff" stroke="#d8e4f8" stroke-dasharray="6 6" />
    <text x="<?= (int) ($svgWidth / 2) ?>" y="<?= (int) ($svgHeight / 2) ?>" text-anchor="middle" font-size="22" font-weight="600" fill="#6f82a0">Repbase does not provide FT-level structure blocks for <?= esc((string) ($query !== '' ? $query : 'this TE')) ?></text>
  <?php endif; ?>
</svg>
