<?php
$coexpressionContexts = is_array($coexpressionAvailability['contexts'] ?? null)
    ? $coexpressionAvailability['contexts']
    : [];
$coexpressionPreferred = (string)($coexpressionAvailability['preferred_context'] ?? '');
$coexpressionContextOrder = ['normal_tissue', 'normal_cell_line', 'cancer_cell_line'];
$coexpressionVersion = max(
    (int)@filemtime(tekg_assets_fs_path('js/pages/expression_coexpression.js')),
    (int)@filemtime(tekg_assets_fs_path('html/preview_coexpression_embed.html')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/coexpression/coexpression-renderer.js'))
);
$coexpressionConfig = [
    'te' => (string)$detail['te_name'],
    'preferredContext' => $coexpressionPreferred,
    'networkApiUrl' => tekg_api_url('coexpression.php'),
    'expressionApiUrl' => tekg_api_url('graph_expression.php'),
    'frameUrl' => tekg_assets_url('html/preview_coexpression_embed.html'),
    'version' => (string)$coexpressionVersion,
];
?>
<section id="expression-detail-coexpression" class="detail-panel expression-coexpression-panel is-collapsed" data-expression-coexpression>
  <div class="expression-coexpression-head">
    <h3>Co-expression graph</h3>
    <button id="expression-coexpression-toggle" class="expression-coexpression-toggle" type="button" aria-label="Expand co-expression graph" aria-expanded="false" title="Expand co-expression graph">
      <span id="expression-coexpression-toggle-icon" aria-hidden="true">&#9662;</span>
    </button>
  </div>
  <div class="expression-coexpression-body">
    <div class="expression-coexpression-toolbar">
      <select id="expression-coexpression-context" class="expression-coexpression-select" aria-label="Expression dataset">
        <?php foreach($coexpressionContextOrder as $contextKey): ?>
          <?php if(in_array($contextKey,$coexpressionContexts,true)): ?>
            <option value="<?= htmlspecialchars($contextKey,ENT_QUOTES,'UTF-8') ?>" <?= $contextKey===$coexpressionPreferred?'selected':'' ?>><?= htmlspecialchars(TEKG_COEXPRESSION_CONTEXTS[$contextKey],ENT_QUOTES,'UTF-8') ?></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <button id="expression-coexpression-activity" class="expression-coexpression-command is-active" type="button" aria-pressed="true">Expression activity: On</button>
      <div id="expression-coexpression-export-wrap" class="expression-coexpression-export-wrap">
        <button id="expression-coexpression-export-toggle" class="expression-coexpression-command" type="button" aria-haspopup="true" aria-expanded="false" disabled>Export</button>
        <div id="expression-coexpression-export-menu" class="expression-coexpression-export-menu" role="menu" hidden>
          <button id="expression-coexpression-export-csv" type="button" role="menuitem">CSV</button>
          <button id="expression-coexpression-export-png" type="button" role="menuitem">PNG</button>
          <button id="expression-coexpression-export-svg" type="button" role="menuitem">SVG</button>
        </div>
      </div>
    </div>
    <div class="expression-coexpression-stage">
      <div id="expression-coexpression-frame-host" class="expression-coexpression-frame-host"></div>
      <div id="expression-coexpression-state" class="expression-coexpression-state" role="status" aria-live="polite" hidden>
        <span id="expression-coexpression-state-text"></span>
        <button id="expression-coexpression-retry" type="button" hidden>Retry</button>
      </div>
      <div class="expression-coexpression-legend" aria-label="Co-expression graph legend">
        <span><i class="expression-coexpression-dot is-te" aria-hidden="true"></i>TE</span>
        <span><i class="expression-coexpression-dot is-gene" aria-hidden="true"></i>Gene</span>
        <span><i class="expression-coexpression-hub" aria-hidden="true"></i>Module hub</span>
        <span><i class="expression-coexpression-ring" aria-hidden="true"></i>Expression activity</span>
        <span><i class="expression-coexpression-line" aria-hidden="true"></i>Co-expression</span>
      </div>
    </div>
  </div>
  <script class="expression-coexpression-config" type="application/json"><?= json_encode($coexpressionConfig,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?></script>
</section>
