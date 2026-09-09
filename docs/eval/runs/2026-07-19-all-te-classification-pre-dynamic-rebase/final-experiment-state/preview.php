<?php
require_once __DIR__ . '/path_config.php';
require_once __DIR__ . '/api/path_finder_service.php';
$pageTitle = 'TE-KG Preview';
$activePage = 'preview';
$protoCurrentPath = tekg_app_url('preview.php');
$protoSubtitle = 'Interactive graph preview';
$protoMainClass = 'preview-main';
$pageExtraStylesheets = [
    tekg_assets_url('css/tekg_runtime.css'),
    tekg_assets_url('css/components/te-autocomplete.css'),
    tekg_assets_url('css/components/side-deepthink.css'),
    tekg_assets_url('css/pages/preview.css'),
];
require __DIR__ . '/head.php';

$siteLang = site_lang();
$initialQuery = trim((string)($_GET['q'] ?? ''));
$graphSearchEntityTypes = path_finder_entity_type_options();
$previewVersion = max(
    (int)@filemtime(__FILE__),
    (int)@filemtime(tekg_assets_fs_path('css/components/te-autocomplete.css')),
    (int)@filemtime(tekg_assets_fs_path('css/pages/preview.css')),
    (int)@filemtime(tekg_assets_fs_path('js/components/te-autocomplete.js')),
    (int)@filemtime(tekg_assets_fs_path('js/components/deepthink-client.js')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/preview/preview-shell.js')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/preview/preview-deepthink.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/index-g6-type-meta.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/large-force-graph/large-force-graph-contract.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/large-force-graph/large-force-graph-layout.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/large-force-graph/large-force-graph-styles.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/large-force-graph/large-force-graph-core.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/large-force-graph/adapters/taxonomy-large-force-adapter.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/taxonomy-dynamic-prototype.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/default-tree-mindmap.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/index-g6.bootstrap.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/index-g6-shared.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/index-g6-embed.js'))
);
$previewConfig = [
    'deepThinkStreamApiUrl' => tekg_api_url('deep_think_stream.php'),
    'sessionStorageKey' => 'tekg-preview-deepthink-session',
    'sourcePage' => 'preview',
    'initialQuery' => $initialQuery,
    'defaultQuestion' => $initialQuery !== '' ? $initialQuery . ' related diseases' : 'LINE-1 related diseases',
    'defaultModel' => 'deepseek-v4-flash',
];
?>
      <section class="preview-stage" id="previewStage">
        <button class="preview-fullscreen-btn" id="previewFullscreenBtn" type="button" aria-label="Enter fullscreen preview">
          Fullscreen
        </button>

        <div class="main preview-graph-workspace" id="previewGraphWorkspace">
          <section class="panel preview-graph-panel" aria-label="TE-KG graph workspace">
            <div class="toolbar preview-graph-toolbar">
              <div class="search preview-entity-search">
                <div class="preview-entity-control">
                  <select id="graphSearchType" aria-label="Graph search entity type">
<?php foreach ($graphSearchEntityTypes as $entityType): ?>
                    <option value="<?= htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8') ?>"<?= $entityType === 'TE' ? ' selected' : '' ?>><?= htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
                  </select>
                  <div class="te-autocomplete" data-te-autocomplete-root data-te-autocomplete-source="path-finder-entities" data-te-autocomplete-type-source="#graphSearchType" data-te-autocomplete-clear-on-type-change="true">
                    <input id="node-search" type="text" autocomplete="off" placeholder="Select a TE entity" data-te-autocomplete>
                    <button class="te-autocomplete-toggle" type="button" aria-label="Show graph entity names" aria-expanded="false" data-te-autocomplete-toggle></button>
                    <div class="te-autocomplete-menu" data-te-autocomplete-menu hidden></div>
                  </div>
                  <button id="graph-search-submit" class="preview-search-submit" type="button">Search</button>
                </div>
              </div>
              <button id="toggle-focus-view" class="focus-legacy" type="button" style="display:none">
                <span id="focus-view-text">Focus mode: Global</span>
              </button>
              <button id="toggle-expand-mode" class="expand-mode is-toggle" type="button" aria-pressed="false"><span id="expand-mode-text">Expand mode: Off</span></button>
              <button id="toggle-non-key-nodes" class="non-key-legacy" type="button" style="display:none">
                <span id="non-key-nodes-text">Hide non-key nodes: Off</span>
              </button>
              <button id="toggle-edge-labels" class="is-toggle" type="button" aria-pressed="false"><span id="edge-labels-text">Show relations: Off</span></button>
              <button id="graph-expression-layer" class="is-toggle" type="button" aria-pressed="false" aria-label="Expression evidence layer"><span id="graph-expression-layer-text">Expression: Off</span></button>
              <button id="toggle-fixed-view" class="is-toggle" type="button" aria-pressed="true"><span id="fixed-view-text">Fixed view: On</span></button>
              <button id="toggle-taxonomy-display" class="is-toggle taxonomy-display-toggle" type="button" aria-pressed="false"><span id="taxonomy-display-text">Switch: Tree</span></button>
              <button id="toggle-taxonomy-source" class="is-toggle taxonomy-source-toggle" type="button" aria-pressed="false"><span id="taxonomy-source-text">Switch taxonomy: All</span></button>
              <button id="back-graph" type="button" disabled hidden><span id="back-text">Back</span></button>
              <div id="export-menu-wrap" class="graph-export-menu-wrap">
                <button id="export-menu-toggle" class="graph-export-action" type="button" aria-haspopup="true" aria-expanded="false" disabled>
                  <span id="export-menu-text">Export</span>
                </button>
                <div id="export-menu" class="graph-export-menu" role="menu" hidden>
                  <button id="export-menu-csv" type="button" role="menuitem">CSV</button>
                  <button id="export-menu-png" type="button" role="menuitem">PNG</button>
                  <button id="export-menu-svg" type="button" role="menuitem" disabled aria-disabled="true">SVG <span>Soon</span></button>
                </div>
              </div>
            </div>
            <div class="g6-surface-stack preview-g6-surface-stack">
              <div id="cy" style="display:none"></div>
              <div id="g6-default-tree-surface"></div>
              <div id="g6-dynamic-surface"></div>
              <div id="graph-preloader" class="graph-preloader" aria-hidden="true">
                <div class="graph-preloader-inner">
                  <div class="graph-preloader-icon" aria-hidden="true">
                    <span></span>
                    <span></span>
                  </div>
                  <div id="te-mechanism-loader-slot" class="te-mechanism-loader-slot" aria-hidden="true"></div>
                  <div id="graph-preloader-label" class="graph-preloader-label">Loading graph...</div>
                </div>
              </div>
              <div id="graph-type-legend" class="graph-legend-panel" aria-label="Entity legend" aria-hidden="true" hidden>
                <div class="graph-legend-mode-switch" role="tablist" aria-label="Graph legend mode">
                  <button id="graph-legend-entity-tab" class="graph-legend-tab is-active" type="button" data-legend-mode="entity" aria-pressed="true">Entity</button>
                  <span class="graph-legend-mode-separator" aria-hidden="true">/</span>
                  <button id="graph-legend-relation-tab" class="graph-legend-tab" type="button" data-legend-mode="relation" aria-pressed="false">Relation</button>
                </div>
                <div id="graph-legend-title" class="graph-legend-title">Entity Legend</div>
                <div id="graph-relation-controls" class="graph-relation-controls" hidden>
                  <label class="graph-relation-min-pmids-label" for="graph-relation-min-pmids">Min PMID</label>
                  <input id="graph-relation-min-pmids" class="graph-relation-min-pmids" type="number" min="0" max="99" step="1" value="0">
                </div>
                <div id="graph-legend-list" class="graph-legend-list"></div>
                <div class="graph-legend-footer">
                  <button id="graph-legend-apply" class="graph-legend-apply" type="button" disabled>Apply</button>
                </div>
              </div>
            </div>
            <div class="nav" id="search-results-nav" style="display:none">
              <button id="prev-result" type="button">Prev</button>
              <span id="result-counter">0/0</span>
              <span id="result-name"></span>
              <button id="next-result" type="button">Next</button>
            </div>
            <div class="detail" id="node-details">Preparing graph workspace...</div>
          </section>
        </div>

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
                  <button class="preview-deepthink-clear side-dt-close" id="previewDeepThinkClearGraph" type="button">Back</button>
                </header>
                <div class="preview-deepthink-messages side-dt-messages" id="previewDeepThinkMessages" aria-live="polite"></div>
                <form class="preview-deepthink-form side-dt-form" id="previewDeepThinkForm">
                  <textarea id="previewDeepThinkInput" rows="2" placeholder="Ask about L1HS, LINE-1, diseases, locations, expression, or mechanisms."><?= htmlspecialchars($initialQuery !== '' ? $initialQuery . ' 和哪些疾病相关' : '', ENT_QUOTES, 'UTF-8') ?></textarea>
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
        window.__TEKG_COMPACT_EMBED = false;
        window.__TEKG_G6_BOOTSTRAP_OWN_TREE = true;
      </script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/components/deepthink-client.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/components/te-autocomplete.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('vendor/marked/marked.umd.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('vendor/g6/g6.min.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/tekg_runtime_data.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/index-g6-type-meta.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/large-force-graph/large-force-graph-contract.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/large-force-graph/large-force-graph-layout.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/large-force-graph/large-force-graph-styles.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/large-force-graph/large-force-graph-core.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/large-force-graph/adapters/taxonomy-large-force-adapter.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/taxonomy-dynamic-prototype.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/default-tree-mindmap.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/index-g6-runtime.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/index-g6.bootstrap.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/preview/preview-shell.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/preview/preview-deepthink.js') . '?v=' . $previewVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
  </main>
  </div>
</body>
</html>
