<?php
$repbasePath = __DIR__ . '/../data/raw/TE_Repbase.txt';
$teName = isset($_GET['te']) && is_string($_GET['te']) && $_GET['te'] !== '' ? $_GET['te'] : 'HERV-K14CI';

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
                'product' => '',
                'is_ft' => true,
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
        if ($current !== null && preg_match('/^FT\s+\/product="([^"]+)"/i', $line, $m)) {
            $current['product'] = trim($m[1]);
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
    if (end($ticks) !== $length && ($length - end($ticks)) >= (int) round($step * 0.35)) {
        $ticks[] = $length;
    }
    return ['step' => $step, 'ticks' => $ticks];
}

$entry = parse_repbase_entry($repbasePath, $teName);
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

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Repbase Structure Prototype</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 24px;
      background: linear-gradient(180deg, #f5f8ff 0%, #edf3ff 100%);
      font-family: 'Segoe UI', system-ui, sans-serif;
    }
    .shell {
      max-width: 1520px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #d8e4f8;
      border-radius: 18px;
      padding: 16px;
      box-shadow: 0 18px 42px rgba(43, 88, 166, 0.08);
    }
    .canvas {
      background: #fff;
      border: 1px solid #dde7f7;
      border-radius: 12px;
      padding: 10px;
      overflow-x: auto;
    }
    svg {
      display: block;
      width: 100%;
      height: auto;
      min-width: 1100px;
    }
    .feature-group {
      transform-box: fill-box;
      transform-origin: center;
      transition: transform 0.18s ease, filter 0.18s ease;
      cursor: pointer;
    }
    .feature-group:hover {
      transform: translateY(-5px);
      filter: drop-shadow(0 6px 10px rgba(34, 53, 91, 0.16));
    }
    .feature-range {
      opacity: 0;
      transition: opacity 0.18s ease;
      pointer-events: none;
    }
    .feature-group:hover .feature-range {
      opacity: 1;
    }
    .feature-label, .feature-range, .axis-label {
      user-select: none;
      pointer-events: none;
    }
  </style>
</head>
<body>
  <main class="shell">
    <?php if (!empty($features) && $sequenceLength > 0): ?>
      <div class="canvas">
        <svg viewBox="0 0 <?= $svgWidth ?> <?= $svgHeight ?>" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Repbase structure prototype for <?= htmlspecialchars($teName, ENT_QUOTES, 'UTF-8') ?>">
          <?php foreach (range(0, max(0, $laneCount - 1)) as $lane):
            $y = $rowTop + ($lane * $rowGap) + ($rectHeight / 2);
          ?>
            <line x1="<?= $padLeft ?>" y1="<?= $y ?>" x2="<?= $svgWidth - $padRight ?>" y2="<?= $y ?>" stroke="#e3ebf8" stroke-width="2" />
          <?php endforeach; ?>

          <?php foreach ($features as $feature):
            $lane = (int) $feature['lane'];
            $x = $padLeft + ((($feature['start'] - 1) / $sequenceLength) * $usableWidth);
            $w = max(84.0, ((($feature['end'] - $feature['start'] + 1) / $sequenceLength) * $usableWidth));
            if ($x + $w > $svgWidth - $padRight) {
                $w = max(84.0, ($svgWidth - $padRight) - $x);
            }
            $y = $rowTop + ($lane * $rowGap);
            $rangeX = $x + ($w / 2);
            $rangeY = $y - 10;
            $label = (string) ($feature['normalized_label'] ?? '');
            $title = $label !== '' ? $label : (string) ($feature['feature_type'] ?? 'FEATURE');
          ?>
            <g class="feature-group">
              <rect x="<?= htmlspecialchars(number_format($x, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" y="<?= htmlspecialchars(number_format($y, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" width="<?= htmlspecialchars(number_format($w, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" height="<?= $rectHeight ?>" fill="<?= htmlspecialchars((string) $feature['color'], ENT_QUOTES, 'UTF-8') ?>" stroke="<?= !empty($feature['is_ft']) ? '#1e2d45' : '#8f8f8f' ?>" stroke-width="2" rx="0" ry="0" />
              <?php if (!empty($feature['is_ft']) && $label !== ''): ?>
                <text class="feature-label" x="<?= htmlspecialchars(number_format($x + ($w / 2), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" y="<?= htmlspecialchars(number_format($y + 22, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" text-anchor="middle" font-size="18" font-weight="700" fill="#233654"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></text>
              <?php endif; ?>
              <g class="feature-range">
                <rect x="<?= htmlspecialchars(number_format(max(14, $rangeX - 56), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" y="<?= htmlspecialchars(number_format($rangeY - 20, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" width="112" height="22" fill="rgba(20,33,58,0.94)" rx="0" ry="0" />
                <text x="<?= htmlspecialchars(number_format($rangeX, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" y="<?= htmlspecialchars(number_format($rangeY - 5, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" text-anchor="middle" font-size="12" font-weight="600" fill="#ffffff"><?= htmlspecialchars((string) $feature['start'] . ' - ' . (string) $feature['end'], ENT_QUOTES, 'UTF-8') ?></text>
              </g>
              <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
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
            <line x1="<?= htmlspecialchars(number_format($tickX, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" y1="<?= $trackStartY + 10 ?>" x2="<?= htmlspecialchars(number_format($tickX, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" y2="<?= $trackStartY + 22 ?>" stroke="#8e99ad" stroke-width="1.4" />
            <text class="axis-label" x="<?= htmlspecialchars(number_format($tickX, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" y="<?= $trackStartY + 48 ?>" text-anchor="<?= $anchor ?>" font-size="16" font-weight="500" fill="#8a95a8"><?= htmlspecialchars(number_format((float) $tick, 0, '.', ','), ENT_QUOTES, 'UTF-8') ?></text>
          <?php endforeach; ?>
        </svg>
      </div>
    <?php else: ?>
      <div style="padding:24px;border:1px dashed #d8e4f8;color:#7186a5;background:#f8fbff;">No coordinate-level Repbase structure blocks were found for <?= htmlspecialchars($teName, ENT_QUOTES, 'UTF-8') ?>.</div>
    <?php endif; ?>
  </main>
</body>
</html>
