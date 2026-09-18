<?php
require_once __DIR__ . '/path_config.php';
require_once __DIR__ . '/api/path_finder_service.php';
$previewVersion = max(
    (int)@filemtime(__FILE__),
    (int)@filemtime(__DIR__ . '/templates/preview/knowledge_graph_workspace.php'),
    (int)@filemtime(__DIR__ . '/templates/preview/coexpression_workspace.php'),
    (int)@filemtime(__DIR__ . '/templates/preview/integrated_graph_workspace.php'),
    (int)@filemtime(tekg_assets_fs_path('css/components/te-autocomplete.css')),
    (int)@filemtime(tekg_assets_fs_path('css/pages/preview.css')),
    (int)@filemtime(tekg_assets_fs_path('js/components/te-autocomplete.js')),
    (int)@filemtime(tekg_assets_fs_path('js/components/deepthink-client.js')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/preview/preview-shell.js')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/preview/te-loader.js')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/preview/preview-deepthink.js')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/preview/coexpression-mode.js')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/preview/gene-enrichment.js')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/preview/integrated-gene-enrichment.js')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/preview/integrated-mode.js')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/preview/preview-workspace-mode.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/index-g6-type-meta.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/default-tree-mindmap.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/canvas-force/taxonomy-canvas-renderer.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/canvas-force/disease-classification-adapter.js')),
    (int)@filemtime(tekg_assets_fs_path('html/preview_g6_embed.html')),
    (int)@filemtime(tekg_assets_fs_path('html/preview_coexpression_embed.html')),
    (int)@filemtime(tekg_assets_fs_path('html/preview_integrated_embed.html')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/g6-svg-export.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/index-g6.bootstrap.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/index-g6-shared.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/index-g6-embed.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/coexpression/coexpression-contract.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/coexpression/coexpression-dynamic-adapter.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/coexpression/coexpression-renderer.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/coexpression/coexpression-embed.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/integrated/integrated-contract.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/integrated/integrated-adapter.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/integrated/integrated-embed.js'))
);
$pageTitle = TEKG_PRODUCT_NAME . ' Graph';
$activePage = 'preview';
$protoCurrentPath = tekg_app_url('preview.php');
$protoSubtitle = 'Interactive graph preview';
$protoMainClass = 'preview-main';
$pageExtraStylesheets = [
    tekg_assets_url('css/tekg_runtime.css') . '?v=' . $previewVersion,
    tekg_assets_url('css/components/te-autocomplete.css') . '?v=' . $previewVersion,
    tekg_assets_url('css/components/side-deepthink.css') . '?v=' . $previewVersion,
    tekg_assets_url('css/pages/preview.css') . '?v=' . $previewVersion,
];
require __DIR__ . '/head.php';

$siteLang = site_lang();
$initialQuery = trim((string)($_GET['q'] ?? ''));
$graphSearchEntityTypes = path_finder_entity_type_options();
$previewConfig = [
    'deepThinkStreamApiUrl' => tekg_api_url('deep_think_stream.php'),
    'sessionStorageKey' => 'tekg-preview-deepthink-session',
    'sourcePage' => 'preview',
    'initialQuery' => $initialQuery,
    'defaultModel' => 'deepseek-v4-flash',
];
?>
      <section class="preview-stage" id="previewStage">
        <div class="preview-top-controls">
          <div class="preview-taxonomy-display-mode" id="previewTaxonomyDisplayMode" role="tablist" aria-label="Taxonomy display">
            <button id="preview-taxonomy-display-tree" class="preview-taxonomy-mode-tab is-active" type="button" role="tab" aria-selected="true" aria-pressed="true">Tree</button>
            <button id="preview-taxonomy-display-graph" class="preview-taxonomy-mode-tab" type="button" role="tab" aria-selected="false" aria-pressed="false">Graph</button>
          </div>
          <div class="preview-taxonomy-mode" id="previewTaxonomyMode" role="tablist" aria-label="Taxonomy source">
            <button id="preview-taxonomy-all" class="preview-taxonomy-mode-tab" type="button" role="tab" aria-selected="false" aria-pressed="false">All</button>
            <button id="preview-taxonomy-rmsk-repbase" class="preview-taxonomy-mode-tab is-active" type="button" role="tab" aria-selected="true" aria-pressed="true">RMSK + RepBase</button>
          </div>
          <label class="preview-workspace-mode" id="previewWorkspaceMode" for="preview-workspace-mode-select">
            <select id="preview-workspace-mode-select" aria-label="Graph mode">
              <option value="knowledge">Knowledge Graph</option>
              <option value="coexpression">TE-Gene Graph</option>
              <option value="integrated">Integrated Graph</option>
            </select>
          </label>
          <button id="back-graph" class="preview-graph-command" type="button" disabled hidden><span id="back-text">Back</span></button>
        </div>
        <button class="preview-fullscreen-btn" id="previewFullscreenBtn" type="button" aria-label="Enter fullscreen preview">
          Fullscreen
        </button>

<?php require __DIR__ . '/templates/preview/knowledge_graph_workspace.php'; ?>
<?php require __DIR__ . '/templates/preview/coexpression_workspace.php'; ?>
<?php require __DIR__ . '/templates/preview/integrated_graph_workspace.php'; ?>

        <div class="qa-overlay-layer side-dt preview-side-dt-root is-open" id="qaOverlay">
          <aside class="qa-drawer side-dt-drawer" id="qaDrawer">
            <button class="qa-drawer-drag side-dt-drag" id="qaDrawerDrag" type="button" aria-label="Move Deep Think assistant"></button>
            <div class="qa-drawer-body">
              <section class="preview-deepthink" id="previewDeepThink" aria-label="Deep Think assistant">
                <header class="preview-deepthink-head side-dt-head">
                  <div class="preview-deepthink-titlebar">
                    <h2>Deep Think</h2>
                    <p class="preview-deepthink-status" id="previewDeepThinkStatus">Ready</p>
                  </div>
                  <div class="preview-deepthink-actions">
                    <button class="side-dt-minimize" id="previewDeepThinkMinimize" type="button" aria-label="Minimize Deep Think assistant" title="Minimize">-</button>
                  </div>
                </header>
                <div class="preview-deepthink-messages side-dt-messages" id="previewDeepThinkMessages" aria-live="polite"></div>
                <form class="preview-deepthink-form side-dt-form" id="previewDeepThinkForm">
                  <textarea id="previewDeepThinkInput" rows="2" placeholder="Ask about L1HS, LINE-1, diseases, locations, expression, or mechanisms."></textarea>
                  <button id="previewDeepThinkSubmit" type="submit">Send</button>
                </form>
              </section>
            </div>
            <button class="qa-drawer-resize qa-drawer-resize-w side-dt-resize side-dt-resize-w" id="qaDrawerResizeW" type="button" aria-label="Resize Deep Think assistant width"></button>
            <button class="qa-drawer-resize qa-drawer-resize-e side-dt-resize side-dt-resize-e" id="qaDrawerResizeE" type="button" aria-label="Resize Deep Think assistant width"></button>
            <button class="qa-drawer-resize qa-drawer-resize-s side-dt-resize side-dt-resize-s" id="qaDrawerResizeS" type="button" aria-label="Resize Deep Think assistant height"></button>
            <button class="qa-drawer-resize qa-drawer-resize-nw side-dt-resize side-dt-resize-nw" id="qaDrawerResizeNW" type="button" aria-label="Resize Deep Think assistant"></button>
            <button class="qa-drawer-resize qa-drawer-resize-ne side-dt-resize side-dt-resize-ne" id="qaDrawerResizeNE" type="button" aria-label="Resize Deep Think assistant"></button>
            <button class="qa-drawer-resize qa-drawer-resize-sw side-dt-resize side-dt-resize-sw" id="qaDrawerResizeSW" type="button" aria-label="Resize Deep Think assistant"></button>
            <button class="qa-drawer-resize qa-drawer-resize-se side-dt-resize side-dt-resize-se" id="qaDrawerResizeSE" type="button" aria-label="Resize Deep Think assistant"></button>
          </aside>

          <button class="qa-fab side-dt-fab" id="qaFab" type="button" aria-label="Toggle Deep Think assistant">
            <span class="qa-fab-icon side-dt-fab-icon" aria-hidden="true">AI</span>
          </button>
        </div>
      </section>

      <script id="preview-config" type="application/json"><?= json_encode($previewConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
      <script>
        window.__TEKG_RENDERER_MODE = 'g6';
        window.__TEKG_EMBED_MODE = 'preview-direct';
        window.__TEKG_INITIAL_QUERY = <?= json_encode($initialQuery, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__TEKG_PREVIEW_VERSION = <?= json_encode((string)$previewVersion, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__TEKG_COMPACT_EMBED = false;
        window.__TEKG_G6_BOOTSTRAP_OWN_TREE = true;
      </script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/components/deepthink-client.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/components/te-autocomplete.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('vendor/marked/marked.umd.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('vendor/g6/g6.min.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/tekg_runtime_data.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/index-g6-type-meta.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/g6-svg-export.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/default-tree-mindmap.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/canvas-force/taxonomy-canvas-renderer.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/canvas-force/disease-classification-adapter.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/index-g6-runtime.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/preview/te-loader.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/index-g6.bootstrap.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/coexpression/coexpression-contract.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/coexpression/coexpression-dynamic-adapter.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/integrated/integrated-contract.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/integrated/integrated-adapter.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/preview/coexpression-mode.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/preview/integrated-mode.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/preview/gene-enrichment.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/preview/integrated-gene-enrichment.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/preview/preview-workspace-mode.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/preview/preview-shell.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/preview/preview-deepthink.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
  </main>
  </div>
</body>
</html>
