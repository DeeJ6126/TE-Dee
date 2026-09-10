<?php
require_once __DIR__ . '/path_config.php';
$pageTitle = TEKG_PRODUCT_NAME . ' Expression Detail';
$activePage='expression';
$protoCurrentPath=tekg_app_url('expression_detail.php');
$protoSubtitle='Expression Detail View';
$expressionDetailVersion=max(
  (int)@filemtime(tekg_assets_fs_path('css/pages/expression_detail.css')),
  (int)@filemtime(tekg_assets_fs_path('js/pages/expression_detail.js')),
  (int)@filemtime(tekg_assets_fs_path('js/pages/expression_coexpression.js'))
);
$pageExtraStylesheets = [
  tekg_assets_url('css/pages/expression_detail.css') . '?v=' . $expressionDetailVersion,
];
require __DIR__ . '/site_i18n.php';
require __DIR__ . '/api/expression_data.php';
require __DIR__ . '/api/coexpression_repository.php';

function exd_dataset_labels_from_summary(array $summary): array {
  $labels = [];
  foreach (['normal_tissue' => 'Normal Tissue', 'normal_cell_line' => 'Normal Cell Line', 'cancer_cell_line' => 'Cancer Cell Line'] as $key => $label) {
    if (!empty($summary[$key . '_available'])) {
      $labels[] = $label;
    }
  }
  return $labels;
}

function exd_fmt($v,$d=2){return ($v===null||$v==='')?'-':number_format((float)$v,$d,'.',',');}
function exd_chart_type(string $chart): string {
  $value = strtolower(trim($chart));
  return $value === 'box' ? 'box' : 'bar';
}
function exd_metric_label(string $metric): string {
  return match (tekg_expression_normalize_metric($metric)) {
    'mean' => 'Mean',
    'max' => 'Max',
    default => 'Median',
  };
}
function exd_metric_value(array $row, string $metric): ?float {
  $key = match (tekg_expression_normalize_metric($metric)) {
    'mean' => 'mean_value',
    'max' => 'max_value',
    default => 'median_value',
  };
  return isset($row[$key]) && $row[$key] !== '' ? (float)$row[$key] : null;
}
function exd_chart_payload(array $dataset, string $metric, string $chart, string $title, string $teName): array {
  $labels = [];
  $sampleCounts = [];
  $chart = exd_chart_type($chart);
  $values = [];
  $mins = [];
  $q1s = [];
  $medians = [];
  $q3s = [];
  $maxes = [];
  foreach (($dataset['contexts'] ?? []) as $row) {
    $labels[] = (string)(($row['context_full_name'] ?? '') ?: ($row['context_label'] ?? '-'));
    $sampleCounts[] = (int)($row['sample_count'] ?? 0);
    if ($chart === 'box') {
      $mins[] = isset($row['min_value']) && $row['min_value'] !== '' ? (float)$row['min_value'] : null;
      $q1s[] = isset($row['q1_value']) && $row['q1_value'] !== '' ? (float)$row['q1_value'] : null;
      $medians[] = isset($row['median_value']) && $row['median_value'] !== '' ? (float)$row['median_value'] : null;
      $q3s[] = isset($row['q3_value']) && $row['q3_value'] !== '' ? (float)$row['q3_value'] : null;
      $maxes[] = isset($row['max_value']) && $row['max_value'] !== '' ? (float)$row['max_value'] : null;
    } else {
      $values[] = exd_metric_value($row, $metric) ?? 0.0;
    }
  }
  $payload = [
    'title' => $title . ' (' . $teName . ')',
    'chart_type' => $chart,
    'metric_label' => $chart === 'box' ? 'Distribution' : exd_metric_label($metric),
    'labels' => $labels,
    'sample_counts' => $sampleCounts,
  ];
  if ($chart === 'box') {
    $payload['min_values'] = $mins;
    $payload['q1_values'] = $q1s;
    $payload['median_values'] = $medians;
    $payload['q3_values'] = $q3s;
    $payload['max_values'] = $maxes;
  } else {
    $payload['values'] = $values;
  }
  return $payload;
}
function exd_render_section(string $id,string $title,array $dataset,string $metric,string $chart,string $teName,string $barColor): void {
  $s=$dataset['summary']??[]; $chartPayload = exd_chart_payload($dataset,$metric,$chart,$title,$teName); ?>
  <section id="<?= htmlspecialchars($id,ENT_QUOTES,'UTF-8') ?>" class="detail-panel">
    <h3><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?></h3>
    <div class="detail-cards">
      <div class="detail-card"><span>Top Median Context</span><strong><?= htmlspecialchars((string)(($s['top_context_median_full_name']??'')?:($s['top_context_median']??'-')),ENT_QUOTES,'UTF-8') ?></strong></div>
      <div class="detail-card"><span>Median Of Median</span><strong><?= htmlspecialchars(exd_fmt($s['median_of_median']??null),ENT_QUOTES,'UTF-8') ?></strong></div>
      <div class="detail-card"><span>Mean Of Mean</span><strong><?= htmlspecialchars(exd_fmt($s['mean_of_mean']??null),ENT_QUOTES,'UTF-8') ?></strong></div>
      <div class="detail-card"><span>Max Of Max</span><strong><?= htmlspecialchars(exd_fmt($s['max_of_max']??null),ENT_QUOTES,'UTF-8') ?></strong></div>
      <div class="detail-card"><span>Cv Of Median</span><strong><?= htmlspecialchars(exd_fmt($s['cv_of_median']??null),ENT_QUOTES,'UTF-8') ?></strong></div>
      <div class="detail-card"><span>Expression Breadth</span><strong><?= htmlspecialchars((string)($s['breadth_of_median']??'-'),ENT_QUOTES,'UTF-8') ?></strong></div>
    </div>
    <div class="detail-chart-shell">
      <div class="detail-chart-header">
        <h4><?= htmlspecialchars($title . ' Plot',ENT_QUOTES,'UTF-8') ?></h4>
        <span><?= htmlspecialchars(exd_chart_type($chart) === 'box' ? 'Basic Box Plot Of Normalized Expression' : exd_metric_label($metric) . ' Normalized Expression',ENT_QUOTES,'UTF-8') ?></span>
      </div>
      <div class="detail-chart-frame">
        <div id="<?= htmlspecialchars($id,ENT_QUOTES,'UTF-8') ?>-plot" class="detail-plot" data-plotly-payload="<?= htmlspecialchars(json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8') ?>" data-plot-color="<?= htmlspecialchars($barColor,ENT_QUOTES,'UTF-8') ?>"></div>
      </div>
    </div>
  </section>
<?php }

$siteLang=site_lang();
$teQuery=trim((string)($_GET['te']??''));
$metric=tekg_expression_normalize_metric((string)($_GET['metric']??'median'));
$chart=exd_chart_type((string)($_GET['chart']??'bar'));
$sort=tekg_expression_normalize_sort((string)($_GET['sort']??'default'));
$detail=null; $error=null; $coexpressionAvailability=null;
try { if($teQuery!=='') $detail=tekg_expression_fetch_detail_bundle($teQuery,$metric,$sort,$chart); }
catch(Throwable $e){ $error=$e->getMessage(); }
if(is_array($detail)){
  try { $coexpressionAvailability=tekg_coexpression_te_availability((string)$detail['te_name']); }
  catch(Throwable $e){ $coexpressionAvailability=null; }
}
$sections=[['id'=>'expression-detail-summary','label'=>'Summary']];
if(is_array($detail)){
  if(is_array($coexpressionAvailability)) $sections[]=['id'=>'expression-detail-coexpression','label'=>'Co-expression graph'];
  if(isset($detail['datasets']['normal_tissue'])) $sections[]=['id'=>'expression-detail-normal-tissue','label'=>'Normal Tissue'];
  if(isset($detail['datasets']['normal_cell_line'])) $sections[]=['id'=>'expression-detail-normal-cell-line','label'=>'Normal Cell Line'];
  if(isset($detail['datasets']['cancer_cell_line'])) $sections[]=['id'=>'expression-detail-cancer-cell-line','label'=>'Cancer Cell Line'];
}
require __DIR__ . '/head.php';
?>
<section class="expression-shell">
  <div class="proto-container">
    <div id="expressionDetailPage">
      <section class="detail-toolbar">
        <a class="detail-back" href="<?= htmlspecialchars(site_url_with_state(tekg_app_url('expression.php'),$siteLang),ENT_QUOTES,'UTF-8') ?>">&larr; Back To Expression</a>
        <form class="detail-search-form" method="get" action="<?= htmlspecialchars(tekg_app_url('expression_detail.php'),ENT_QUOTES,'UTF-8') ?>">
          <input type="hidden" name="lang" value="<?= htmlspecialchars($siteLang,ENT_QUOTES,'UTF-8') ?>">
          <input type="hidden" name="metric" value="<?= htmlspecialchars($metric,ENT_QUOTES,'UTF-8') ?>">
          <input type="hidden" name="chart" value="<?= htmlspecialchars($chart,ENT_QUOTES,'UTF-8') ?>">
          <input type="hidden" name="sort" value="<?= htmlspecialchars($sort,ENT_QUOTES,'UTF-8') ?>">
          <div class="detail-search-box">
            <svg class="detail-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"></circle><path d="m20 20-3.8-3.8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>
            <input class="detail-query" type="text" name="te" value="<?= htmlspecialchars($teQuery,ENT_QUOTES,'UTF-8') ?>" placeholder="Search A TE For Expression Detail">
          </div>
        </form>
      </section>

      <div id="expressionDetailResults">
        <?php if($teQuery==='' ): ?>
          <div class="detail-panel"><h3>Expression Detail</h3><p class="expression-empty">Search For A TE To Open Its Expression Detail View.</p></div>
        <?php elseif($error!==null || !is_array($detail)): ?>
          <div class="expression-error"><?= htmlspecialchars($error ?? 'No Expression Detail Is Available For The Requested TE Yet.',ENT_QUOTES,'UTF-8') ?></div>
        <?php else: ?>
          <?php $summary=$detail['browse_summary']; $datasetLabels=exd_dataset_labels_from_summary($summary); ?>
          <div class="detail-layout">
            <aside class="detail-sidebar">
              <nav class="detail-nav" aria-label="Expression Detail Sections">
                <div class="detail-nav-title">Detail Sections</div>
                <?php foreach($sections as $section): ?>
                  <a class="detail-nav-link" data-detail-nav-link href="#<?= htmlspecialchars($section['id'],ENT_QUOTES,'UTF-8') ?>"><?= htmlspecialchars($section['label'],ENT_QUOTES,'UTF-8') ?></a>
                <?php endforeach; ?>
              </nav>
              <form class="detail-controls" method="get" action="<?= htmlspecialchars(tekg_app_url('expression_detail.php'),ENT_QUOTES,'UTF-8') ?>">
                <input type="hidden" name="lang" value="<?= htmlspecialchars($siteLang,ENT_QUOTES,'UTF-8') ?>">
                <input type="hidden" name="te" value="<?= htmlspecialchars($teQuery,ENT_QUOTES,'UTF-8') ?>">
                <div class="detail-controls-title">Display Controls</div>
                <div class="detail-control-group">
                  <label class="detail-control-label" for="expression-detail-chart">Chart Type</label>
                  <select class="detail-select" id="expression-detail-chart" name="chart" data-detail-auto-submit>
                    <option value="bar" <?= $chart==='bar'?'selected':'' ?>>Bar</option>
                    <option value="box" <?= $chart==='box'?'selected':'' ?>>Box</option>
                  </select>
                  <?php if($chart==='box'): ?>
                    <small class="detail-control-note">Box Plot Uses Distribution Quartiles And Does Not Switch By Metric.</small>
                  <?php endif; ?>
                </div>
                <?php if($chart==='box'): ?><input type="hidden" name="metric" value="<?= htmlspecialchars($metric,ENT_QUOTES,'UTF-8') ?>"><?php endif; ?>
                <div class="detail-control-group">
                  <label class="detail-control-label" for="expression-detail-metric">Metric</label>
                  <select class="detail-select" id="expression-detail-metric" name="metric" data-detail-auto-submit <?= $chart==='box'?'disabled':'' ?> >
                    <option value="median" <?= $metric==='median'?'selected':'' ?>>Median</option>
                    <option value="mean" <?= $metric==='mean'?'selected':'' ?>>Mean</option>
                    <option value="max" <?= $metric==='max'?'selected':'' ?>>Max</option>
                  </select>
                </div>
                <div class="detail-control-group">
                  <label class="detail-control-label" for="expression-detail-sort">Order</label>
                  <select class="detail-select" id="expression-detail-sort" name="sort" data-detail-auto-submit>
                    <option value="default" <?= $sort==='default'?'selected':'' ?>>Default Order</option>
                    <option value="high_to_low" <?= $sort==='high_to_low'?'selected':'' ?>>High To Low</option>
                    <option value="low_to_high" <?= $sort==='low_to_high'?'selected':'' ?>>Low To High</option>
                  </select>
                </div>
              </form>
            </aside>
            <div class="detail-content">
              <section id="expression-detail-summary" class="detail-panel">
                <h3>Summary</h3>
                <?php require __DIR__ . '/templates/components/expression_detail_summary_cards.php'; ?>
              </section>
              <?php if(is_array($coexpressionAvailability)): ?>
                <?php require __DIR__ . '/templates/components/expression_coexpression_graph.php'; ?>
              <?php endif; ?>
              <?php if(isset($detail['datasets']['normal_tissue'])) exd_render_section('expression-detail-normal-tissue','Normal Tissue',$detail['datasets']['normal_tissue'],$metric,$chart,(string)$detail['te_name'],'#7fa6d8'); ?>
              <?php if(isset($detail['datasets']['normal_cell_line'])) exd_render_section('expression-detail-normal-cell-line','Normal Cell Line',$detail['datasets']['normal_cell_line'],$metric,$chart,(string)$detail['te_name'],'#6fb6a6'); ?>
              <?php if(isset($detail['datasets']['cancer_cell_line'])) exd_render_section('expression-detail-cancer-cell-line','Cancer Cell Line',$detail['datasets']['cancer_cell_line'],$metric,$chart,(string)$detail['te_name'],'#d38fb4'); ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<script src="https://cdn.plot.ly/plotly-2.35.2.min.js"></script>
<?php if(is_array($coexpressionAvailability)): ?>
<script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/coexpression/coexpression-contract.js') . '?v=' . $expressionDetailVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(tekg_assets_url('js/pages/expression_coexpression.js') . '?v=' . $expressionDetailVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
<script src="<?= htmlspecialchars(tekg_assets_url('js/pages/expression_detail.js') . '?v=' . $expressionDetailVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require __DIR__ . '/foot.php'; ?>
