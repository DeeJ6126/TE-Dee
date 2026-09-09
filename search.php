<?php
require_once __DIR__ . '/site_i18n.php';
require_once __DIR__ . '/path_config.php';
require_once __DIR__ . '/api/taxonomy_lib.php';
require_once __DIR__ . '/includes/search_detail_helpers.php';

$pageTitle = TEKG_PRODUCT_NAME . ' Detail';
$activePage = 'browse';
$protoCurrentPath = tekg_app_url('search.php');
$protoSubtitle = 'TE detail view';
$pageExtraStylesheets = [
    tekg_assets_url('css/pages/search.css'),
];


$siteLang = site_lang();
$query = tekg_request_scalar_proto($_GET, 'q', '');
$type = tekg_request_scalar_proto($_GET, 'type', 'all');
$repbase = tekg_repbase_lookup_proto($query);
$dfamSequence = tekg_dfam_lookup_proto($query, $type);
$repbaseStructureSvgUrl = tekg_repbase_structure_svg_url_proto($repbase, $query);
$genomeDistribution = tekg_karyotype_lookup_proto($query, $type, $repbase);
$jbrowseSession = tekg_jbrowse_lookup_proto($query, $type, $repbase, $siteLang);
$karyotypeHitMap = tekg_karyotype_bin_hit_map_proto($genomeDistribution, $jbrowseSession);
$classificationSession = tekg_tree_classification_lookup_proto($query, $type, $repbase, $dfamSequence);
$variantsEnabled = $query !== ''
    && !in_array(strtoupper(trim($type)), ['DISEASE', 'FUNCTION', 'PAPER'], true)
    && ($repbase !== null || $jbrowseSession !== null || $classificationSession !== null);
$searchGraphSrc = site_url_with_state(tekg_assets_url('html/preview_graph.html'), $siteLang, null, array_filter([
    'embed' => 'search-result',
    'q' => $query !== '' ? $query : null,
], static fn ($value) => $value !== null && $value !== ''));
$browseBackUrl = site_url_with_state(tekg_app_url('browse.php'), $siteLang);
$detailSections = [
    ['id' => 'search-summary-panel', 'label' => 'Summary'],
    ['id' => 'search-graph-panel', 'label' => 'Local Graph'],
];
if ($repbase !== null) {
    $detailSections[] = ['id' => 'search-sequence-panel', 'label' => 'Sequence'];
}
if ($genomeDistribution !== null) {
    $detailSections[] = ['id' => 'search-karyotype-panel', 'label' => 'Genome Annotation'];
}
if ($jbrowseSession !== null) {
    $detailSections[] = ['id' => 'search-jbrowse-panel', 'label' => 'Genome Browser'];
}
if ($variantsEnabled) {
    $detailSections[] = ['id' => 'search-variants-panel', 'label' => 'Variants'];
}

require __DIR__ . '/head.php';
?>
      <section class="search-shell">
        <div class="proto-container">
          <section class="query-panel">
            <div class="detail-toolbar">
              <a class="detail-back-link" href="<?= htmlspecialchars($browseBackUrl, ENT_QUOTES, 'UTF-8') ?>">&larr; Back to Browse</a>
              <form id="search-form" class="detail-search-form" method="GET">
                <input type="hidden" name="type" value="all">
                <input type="hidden" name="lang" value="<?= htmlspecialchars($siteLang, ENT_QUOTES, 'UTF-8') ?>">
                <div class="detail-search-box">
                  <svg class="detail-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"></circle><path d="m20 20-3.8-3.8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>
                  <input id="search-query" class="query-control" type="text" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search a TE, disease, function, or PMID">
                </div>
              </form>
            </div>
          </section>

          <div id="search-results" class="detail-layout<?= $query === '' ? ' is-hidden' : '' ?>">
            <aside class="detail-sidebar">
              <nav class="detail-nav" aria-label="Detail sections">
                <div class="detail-nav-title">Detail Sections</div>
                <?php foreach ($detailSections as $section): ?>
                  <a class="detail-nav-link" data-detail-nav-link href="#<?= htmlspecialchars($section['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8') ?></a>
                <?php endforeach; ?>
              </nav>
            </aside>

            <div class="detail-content">
              <section id="search-summary-panel" class="data-panel">
                <h3>Summary</h3>
                <?php require __DIR__ . '/templates/components/search_summary_meta.php'; ?>
              </section>

              <?php require __DIR__ . '/templates/components/search_graph_panel.php'; ?>

              <?php if ($repbase !== null): ?>
                <section id="search-sequence-panel" class="data-panel sequence-panel">
                  <h3>Sequence</h3>
                  <?php if (!empty($repbase['sequence_summary'])): ?>
                    <div class="sequence-meta">
                      <div><strong>Sequence summary: </strong><?= htmlspecialchars((string) $repbase['sequence_summary'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($repbaseStructureSvgUrl)): ?>
                    <div class="sequence-plot">
                      <object
                        class="sequence-plot-object"
                        data="<?= htmlspecialchars((string) $repbaseStructureSvgUrl, ENT_QUOTES, 'UTF-8') ?>"
                        type="image/svg+xml"
                        aria-label="Sequence structure plot for <?= htmlspecialchars((string) ($repbase['nm'] ?? $query), ENT_QUOTES, 'UTF-8') ?>">
                      </object>
                    </div>
                  <?php endif; ?>
                  <?php $rawRepbaseSequence = preg_replace('/\s+/u', '', (string) ($repbase['sequence'] ?? '')) ?? ''; ?>
                  <div class="sequence-code-wrap">
                    <button
                      type="button"
                      class="sequence-copy-button"
                      id="search-sequence-copy"
                      aria-label="Copy complete sequence"
                      title="Copy complete sequence"
                    >
                      <svg viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="9" y="9" width="11" height="11" rx="2"></rect>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                      </svg>
                      <span data-copy-label>Copy</span>
                    </button>
                    <span class="sr-only" id="search-sequence-copy-status" role="status" aria-live="polite"></span>
                    <pre class="sequence-code" data-raw-sequence="<?= htmlspecialchars($rawRepbaseSequence, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($rawRepbaseSequence, ENT_QUOTES, 'UTF-8') ?></pre>
                  </div>
                </section>
              <?php endif; ?>

              <?php if ($genomeDistribution !== null): ?>
                <section id="search-karyotype-panel" class="data-panel distribution-panel">
                  <h3>Genome Annotation Distribution</h3>
                  <div class="distribution-meta">
                    <div><strong>Assembly: </strong><?= htmlspecialchars((string) ($genomeDistribution['assembly_label'] ?? 'Homo sapiens [hg38]'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div><strong>Mode: </strong>All Hits</div>
                    <div><strong>Bin size: </strong><?= htmlspecialchars(number_format(((int) ($genomeDistribution['bin_size_bp'] ?? 1000000)) / 1000000, 0) . ' Mb', ENT_QUOTES, 'UTF-8') ?></div>
                    <div><strong>Total hits: </strong><?= htmlspecialchars(number_format((int) ($genomeDistribution['total_hits'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></div>
                  </div>
                  <p id="search-karyotype-status" class="distribution-status">Loading genome annotation distribution...</p>
                  <p id="search-karyotype-feedback" class="distribution-feedback" role="status" aria-live="polite" hidden></p>
                  <div class="distribution-karyotype-wrap">
                    <div
                      id="search-karyotype-view"
                      class="distribution-karyotype"
                      data-karyotype-path="<?= htmlspecialchars((string) ($genomeDistribution['data_json_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    ></div>
                  </div>
                </section>
              <?php endif; ?>
              <?php if ($jbrowseSession !== null): ?>
                <section id="search-jbrowse-panel" class="data-panel jbrowse-panel">
                  <div class="jbrowse-panel-head">
                    <h3>Genome Browser</h3>
                  </div>
                  <div class="jbrowse-summary">
                    <h2>Genome browser session</h2>
                    <div class="jbrowse-meta">
                      <div class="jbrowse-meta-item">
                        <span class="jbrowse-meta-label">TE</span>
                        <span class="jbrowse-meta-value"><?= htmlspecialchars((string) ($jbrowseSession['resolved_te_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                      </div>
                      <div class="jbrowse-meta-item">
                        <span class="jbrowse-meta-label">Representative locus</span>
                        <span class="jbrowse-meta-value"><?= htmlspecialchars((string) ($jbrowseSession['locus_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                      </div>
                      <div class="jbrowse-meta-item">
                        <span class="jbrowse-meta-label">Initial browser window</span>
                        <span class="jbrowse-meta-value" id="searchJBrowseDefaultLoc">-</span>
                      </div>
                      <div class="jbrowse-meta-item">
                        <span class="jbrowse-meta-label">Total genomic hits</span>
                        <span class="jbrowse-meta-value"><?= htmlspecialchars(number_format((int) ($jbrowseSession['total_hits'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></span>
                      </div>
                      <div class="jbrowse-meta-item">
                        <span class="jbrowse-meta-label">Repeat features in window</span>
                        <span class="jbrowse-meta-value" id="searchJBrowseRepeatCount">-</span>
                      </div>
                      <div class="jbrowse-meta-item">
                        <span class="jbrowse-meta-label">RefSeq features in window</span>
                        <span class="jbrowse-meta-value" id="searchJBrowseRefseqCount">-</span>
                      </div>
                    </div>
                    <div class="jbrowse-track-toolbar">
                      <div class="jbrowse-control-row">
                        <div class="jbrowse-hit-picker">
                          <div class="jbrowse-hit-picker-head">
                            <label class="jbrowse-hit-picker-label" for="searchJBrowseHitSelect">Genomic hit</label>
                            <button type="button" class="jbrowse-hit-restore" id="searchJBrowseRestoreHits" hidden>Back to sampled hits</button>
                          </div>
                          <select id="searchJBrowseHitSelect" class="jbrowse-hit-picker-select">
                            <?php
                              $jbrowseRepresentative = is_array($jbrowseSession['representative_locus'] ?? null) ? $jbrowseSession['representative_locus'] : [];
                              foreach (($jbrowseSession['sample_hits'] ?? []) as $hitIndex => $hit):
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
                            ?>
                            <option value="<?= (int) $hitIndex ?>"
                                    data-chrom="<?= htmlspecialchars($hitChrom, ENT_QUOTES, 'UTF-8') ?>"
                                    data-start="<?= (int) $hitStart ?>"
                                    data-end="<?= (int) $hitEnd ?>"
                                    <?= $isSelectedHit ? 'selected' : '' ?>><?= htmlspecialchars((string) ($hit['label'] ?? ($hitChrom . ':' . ($hitStart + 1) . '-' . $hitEnd)), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                          </select>
                          <div class="jbrowse-hit-scope" id="searchJBrowseHitScope" hidden></div>
                        </div>
                        <div class="jbrowse-track-list" id="searchJBrowseTrackControls">
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
                  <div class="jbrowse-browser-stage">
                    <div id="search_jbrowse_linear_genome_view">
                      <div class="jbrowse-loading">Preparing genome browser session...</div>
                    </div>
                  </div>
                </section>
              <?php endif; ?>
              <?php if ($variantsEnabled): ?>
                <?php require __DIR__ . '/templates/components/search_variants_panel.php'; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <?php if ($genomeDistribution !== null): ?>
      <script src="<?= htmlspecialchars(tekg_assets_url('vendor/karyotype/Karyotype.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
      <?php endif; ?>

      

      
      <?php if ($jbrowseSession !== null): ?>
      <script src="https://unpkg.com/@jbrowse/react-linear-genome-view2@3.5.0/dist/react-linear-genome-view.umd.production.min.js" crossorigin></script>
      
      <?php endif; ?>

      

      <script id="search-page-config" type="application/json"><?= json_encode([
        'browserBaseUrl' => (string) ($jbrowseSession['browser_url'] ?? ''),
        'configUrl' => (string) ($jbrowseSession['config_url'] ?? ''),
        'karyotypeHitMap' => $karyotypeHitMap,
        'variantsApiUrl' => tekg_api_url('variants.php'),
        'variantsQuery' => $query,
        'variantsType' => $type,
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/search.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    </main>
  </div>
</body>
</html>




