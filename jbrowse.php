<?php
require __DIR__ . '/path_config.php';
require __DIR__ . '/site_i18n.php';
require __DIR__ . '/includes/jbrowse_session.php';

$pageTitle = TEKG_PRODUCT_NAME . ' JBrowse';
$activePage = 'genomic';
$protoCurrentPath = tekg_app_url('jbrowse.php');
$protoSubtitle = 'Standalone genome browser for TE loci';
$isEmbedded = trim((string) ($_GET['embed'] ?? '')) !== '';
if (!$isEmbedded) {
    $pageExtraStylesheets = [
        tekg_assets_url('css/pages/jbrowse.css'),
    ];
}


$siteLang = site_lang();
$root = __DIR__;
$jbrowseDir = TEKG_JBROWSE_FS_DIR;
$repeatsDir = $jbrowseDir . '/repeats';
$representativeIndex = jbrowse_read_json($repeatsDir . '/te_representative_index.json');
$hitManifest = jbrowse_read_json($repeatsDir . '/te_hits_manifest.json');
$locus = jbrowse_build_locus_from_params($representativeIndex, is_array($hitManifest) ? $hitManifest : []);

$regionKey = implode('__', [
    jbrowse_sanitize_slug($locus['te'] !== '' ? $locus['te'] : 'region'),
    $locus['chr'],
    $locus['view_start'],
    $locus['view_end'],
]);

$repeatsRows = jbrowse_collect_repeat_rows($repeatsDir . '/hg38.rmsk.repeats.bed', $locus['chr'], $locus['view_start'], $locus['view_end']);
$refseqRows = jbrowse_collect_refseq_rows($jbrowseDir . '/hg38.ncbiRefSeq.gtf/hg38.ncbiRefSeq.gtf', $locus['chr'], $locus['view_start'], $locus['view_end']);

$repeatCacheRel = jbrowse_project_relative_path('cache/repeats/' . $regionKey . '.gff3');
$refseqCacheRel = jbrowse_project_relative_path('cache/refseq/' . $regionKey . '.gff3');
$repeatCacheAbs = jbrowse_project_fs_path($repeatCacheRel);
$refseqCacheAbs = jbrowse_project_fs_path($refseqCacheRel);
jbrowse_write_gff3_cache($repeatCacheAbs, $repeatsRows);
jbrowse_write_gff3_cache($refseqCacheAbs, $refseqRows);

$repeatTrackUrl = jbrowse_project_url($repeatCacheRel);
$refseqTrackUrl = jbrowse_project_url($refseqCacheRel);
$fastaUrl = tekg_jbrowse_url('hg38.fa');
$faiUrl = tekg_jbrowse_url('hg38.fa.fai');
$clinvarMainUrl = tekg_jbrowse_url('clinvarMain.bb');
$clinvarCnvUrl = tekg_jbrowse_url('clinvarCnv.bb');
$defaultLoc = sprintf(
    '%s:%s..%s',
    $locus['chr'],
    number_format($locus['view_start'] + 1),
    number_format($locus['view_end'])
);

$defaultTracks = [
    'repeats_hg38',
    'ncbi_refseq_window',
    'clinvar_variants',
    'clinvar_cnv',
];
$pageMeta = [
    'te' => $locus['te'],
    'chr' => $locus['chr'],
    'start' => $locus['start'],
    'end' => $locus['end'],
    'viewStart' => $locus['view_start'],
    'viewEnd' => $locus['view_end'],
    'defaultLoc' => $defaultLoc,
    'totalHits' => (int) ($locus['entry']['total_hits'] ?? 0),
    'selectionRule' => (string) ($locus['entry']['selection_rule'] ?? ''),
    'sampledHits' => (int) ($locus['entry']['count_sampled'] ?? 0),
    'repeatFeatureCount' => count($repeatsRows),
    'refseqFeatureCount' => count($refseqRows),
];

if (trim((string) ($_GET['format'] ?? '')) === 'config') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'pageMeta' => $pageMeta,
        'defaultTracks' => $defaultTracks,
        'fastaUrl' => $fastaUrl,
        'faiUrl' => $faiUrl,
        'repeatTrackUrl' => $repeatTrackUrl,
        'refseqTrackUrl' => $refseqTrackUrl,
        'clinvarMainUrl' => $clinvarMainUrl,
        'clinvarCnvUrl' => $clinvarCnvUrl,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($isEmbedded) {
    ?>
<!doctype html>
<html lang="<?= htmlspecialchars($siteLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body class="jbrowse-embed-body">
<?php
} else {
    require __DIR__ . '/head.php';
}
?>
      <section class="jbrowse-shell<?= $isEmbedded ? ' is-embedded' : '' ?>">
        <div class="jbrowse-container">
          <?php if (!$isEmbedded): ?>
          <h1 class="jbrowse-title">JBrowse</h1>
          <div class="jbrowse-crumbs">
            <a href="<?= htmlspecialchars(site_url_with_state(tekg_app_url('index.php'), $siteLang), ENT_QUOTES, 'UTF-8') ?>">Home</a>
            <span>/</span>
            <a href="<?= htmlspecialchars(site_url_with_state(tekg_app_url('genomic.php'), $siteLang), ENT_QUOTES, 'UTF-8') ?>">Genomic</a>
            <span>/</span>
            <span>JBrowse</span>
          </div>
          <?php endif; ?>

          <div class="jbrowse-topbar">
            <div class="jbrowse-summary">
              <h2>Genome browser session</h2>
              <div class="jbrowse-meta">
                <div class="jbrowse-meta-item">
                  <span class="jbrowse-meta-label">TE</span>
                  <span class="jbrowse-meta-value"><?= htmlspecialchars($pageMeta['te'] !== '' ? $pageMeta['te'] : 'Custom locus', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="jbrowse-meta-item">
                  <span class="jbrowse-meta-label">Representative locus</span>
                  <span class="jbrowse-meta-value"><?= htmlspecialchars($pageMeta['chr'] . ':' . number_format($pageMeta['start'] + 1) . '-' . number_format($pageMeta['end']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="jbrowse-meta-item">
                  <span class="jbrowse-meta-label">Initial browser window</span>
                  <span class="jbrowse-meta-value" id="jbrowseDefaultLoc"><?= htmlspecialchars($pageMeta['defaultLoc'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="jbrowse-meta-item">
                  <span class="jbrowse-meta-label">Total genomic hits</span>
                  <span class="jbrowse-meta-value"><?= htmlspecialchars((string) $pageMeta['totalHits'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="jbrowse-meta-item">
                  <span class="jbrowse-meta-label">Repeat features in window</span>
                  <span class="jbrowse-meta-value" id="jbrowseRepeatCount"><?= htmlspecialchars((string) $pageMeta['repeatFeatureCount'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="jbrowse-meta-item">
                  <span class="jbrowse-meta-label">RefSeq features in window</span>
                  <span class="jbrowse-meta-value" id="jbrowseRefseqCount"><?= htmlspecialchars((string) $pageMeta['refseqFeatureCount'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              </div>
              <div class="jbrowse-track-toolbar">
                <div class="jbrowse-control-row">
                  <div class="jbrowse-hit-picker">
                    <label class="jbrowse-hit-picker-label" for="jbrowseHitSelect">Genomic hit</label>
                    <select id="jbrowseHitSelect" class="jbrowse-hit-picker-select">
                      <?php
                        $jbrowseRepresentative = is_array($locus['representative'] ?? null) ? $locus['representative'] : [];
                        foreach ((($locus['entry']['sample_hits'] ?? []) ?: []) as $hitIndex => $hit):
                          if (!is_array($hit)) {
                            continue;
                          }
                          $hitChrom = trim((string) ($hit['chrom'] ?? ''));
                          $hitStart = (int) ($hit['start'] ?? -1);
                          $hitEnd = (int) ($hit['end'] ?? -1);
                          if ($hitChrom === '' || $hitStart < 0 || $hitEnd <= $hitStart) {
                            continue;
                          }
                          $isSelectedHit = $hitChrom === (string) ($jbrowseRepresentative['chrom'] ?? '')
                            && $hitStart === (int) ($jbrowseRepresentative['start'] ?? -2)
                            && $hitEnd === (int) ($jbrowseRepresentative['end'] ?? -3);
                          $hitLabel = sprintf(
                            '%s:%s-%s | %s | len %s bp | score %s',
                            $hitChrom,
                            number_format($hitStart + 1),
                            number_format($hitEnd),
                            (((string) ($hit['strand'] ?? '+')) === '-') ? 'reverse strand' : 'forward strand',
                            number_format(max(1, (int) ($hit['length'] ?? ($hitEnd - $hitStart)))),
                            number_format((int) ($hit['score'] ?? 0))
                          );
                      ?>
                      <option value="<?= (int) $hitIndex ?>"
                              data-chrom="<?= htmlspecialchars($hitChrom, ENT_QUOTES, 'UTF-8') ?>"
                              data-start="<?= (int) $hitStart ?>"
                              data-end="<?= (int) $hitEnd ?>"
                              <?= $isSelectedHit ? 'selected' : '' ?>><?= htmlspecialchars($hitLabel, ENT_QUOTES, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="jbrowse-track-list" id="jbrowseTrackControls">
                    <label class="jbrowse-track-item">
                      <input type="checkbox" data-track-id="repeats_hg38" checked>
                      <span class="jbrowse-track-dot" style="background:#d8a11a"></span>
                      <span class="jbrowse-track-name">Repeats</span>
                    </label>
                    <label class="jbrowse-track-item">
                      <input type="checkbox" data-track-id="ncbi_refseq_window" checked>
                      <span class="jbrowse-track-dot" style="background:#5fa1da"></span>
                      <span class="jbrowse-track-name">NCBI RefSeq</span>
                    </label>
                    <label class="jbrowse-track-item">
                      <input type="checkbox" data-track-id="clinvar_variants" checked>
                      <span class="jbrowse-track-dot" style="background:#73b36b"></span>
                      <span class="jbrowse-track-name">ClinVar variants</span>
                    </label>
                    <label class="jbrowse-track-item">
                      <input type="checkbox" data-track-id="clinvar_cnv" checked>
                      <span class="jbrowse-track-dot" style="background:#cc7f9f"></span>
                      <span class="jbrowse-track-name">ClinVar CNV</span>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="jbrowse-browser-stage">
            <div id="jbrowse_linear_genome_view">
              <div class="jbrowse-loading">Preparing standalone JBrowse session...</div>
            </div>
          </div>
        </div>
      </section>

      <script src="https://unpkg.com/@jbrowse/react-linear-genome-view2@3.5.0/dist/react-linear-genome-view.umd.production.min.js" crossorigin></script>
      <script id="jbrowse-page-meta" type="application/json"><?= json_encode($pageMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/jbrowse.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php
if ($isEmbedded) {
    ?>
</body>
</html>
<?php
} else {
    require __DIR__ . '/foot.php';
}
?>
