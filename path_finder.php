<?php
require_once __DIR__ . '/path_config.php';
require_once __DIR__ . '/api/path_finder_service.php';

$pageTitle = TEKG_PRODUCT_NAME . ' Path';
$activePage = 'path_finder';
$protoCurrentPath = tekg_app_url('path_finder.php');
$protoSubtitle = 'Find interpretable paths between TE-Dee entities';
$pathFinderEntityTypes = path_finder_entity_type_options();
$pathFinderVersion = max(
    (int)@filemtime(__FILE__),
    (int)@filemtime(tekg_assets_fs_path('css/pages/path_finder.css')),
    (int)@filemtime(tekg_assets_fs_path('css/components/graph-ui-controls.css')),
    (int)@filemtime(tekg_assets_fs_path('js/components/te-autocomplete.js')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/path_finder.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/index-g6-type-meta.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/index-g6-shared.js')),
    (int)@filemtime(tekg_assets_fs_path('js/renderers/g6/g6-svg-export.js'))
);
$pageExtraStylesheets = [
    tekg_assets_url('css/components/te-autocomplete.css') . '?v=' . $pathFinderVersion,
    tekg_assets_url('css/pages/path_finder.css') . '?v=' . $pathFinderVersion,
    tekg_assets_url('css/components/graph-ui-controls.css') . '?v=' . $pathFinderVersion,
];

require __DIR__ . '/head.php';
?>
        <section class="path-finder-shell">
          <div class="proto-container">
            <h1 class="path-finder-title">Path</h1>

            <section class="path-finder-panel" aria-label="Path Finder query controls">
              <form class="path-finder-form" id="pathFinderForm">
                <label class="path-field">
                  <span>Source entity</span>
                  <div class="path-entity-control">
                    <select id="pathSourceType" name="source_type" aria-label="Source entity type">
<?php foreach ($pathFinderEntityTypes as $entityType): ?>
                      <option value="<?= htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8') ?>"<?= $entityType === 'TE' ? ' selected' : '' ?>><?= htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
                    </select>
                    <div class="te-autocomplete" data-te-autocomplete-root data-te-autocomplete-source="path-finder-entities" data-te-autocomplete-type-source="#pathSourceType" data-te-autocomplete-clear-on-type-change="true" data-te-autocomplete-connected-source="#pathTarget" data-te-autocomplete-connected-source-type="#pathTargetType" data-te-autocomplete-connected-target-type="#pathSourceType" data-te-autocomplete-connected-max-depth-source="#pathMaxDepth">
                      <input id="pathSource" name="source" type="text" autocomplete="off" placeholder="Select a TE entity" data-te-autocomplete>
                      <button class="te-autocomplete-toggle" type="button" aria-label="Show source entity names" aria-expanded="false" data-te-autocomplete-toggle></button>
                      <div class="te-autocomplete-menu" data-te-autocomplete-menu hidden></div>
                    </div>
                  </div>
                </label>
                <label class="path-field">
                  <span>Target entity</span>
                  <div class="path-entity-control">
                    <select id="pathTargetType" name="target_type" aria-label="Target entity type">
<?php foreach ($pathFinderEntityTypes as $entityType): ?>
                      <option value="<?= htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8') ?>"<?= $entityType === 'Disease' ? ' selected' : '' ?>><?= htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
                    </select>
                    <div class="te-autocomplete" data-te-autocomplete-root data-te-autocomplete-source="path-finder-entities" data-te-autocomplete-type-source="#pathTargetType" data-te-autocomplete-clear-on-type-change="true" data-te-autocomplete-connected-source="#pathSource" data-te-autocomplete-connected-source-type="#pathSourceType" data-te-autocomplete-connected-target-type="#pathTargetType" data-te-autocomplete-connected-max-depth-source="#pathMaxDepth">
                      <input id="pathTarget" name="target" type="text" autocomplete="off" placeholder="Select a Disease entity" data-te-autocomplete>
                      <button class="te-autocomplete-toggle" type="button" aria-label="Show target entity names" aria-expanded="false" data-te-autocomplete-toggle></button>
                      <div class="te-autocomplete-menu" data-te-autocomplete-menu hidden></div>
                    </div>
                  </div>
                </label>
                <label class="path-field path-depth-field">
                  <span>Max hops</span>
                  <select id="pathMaxDepth" name="max_depth">
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3" selected>3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
                    <option value="6">6</option>
                    <option value="7">7</option>
                    <option value="8">8</option>
                    <option value="9">9</option>
                    <option value="10">10</option>
                  </select>
                </label>
                <div class="path-actions">
                  <button class="path-submit path-command-control" id="pathSubmit" type="submit">Find paths</button>
                </div>
              </form>
            </section>

            <section class="path-results-panel" id="pathResultsPanel" aria-live="polite" hidden>
              <div class="path-results-head">
                <div class="path-results-status" id="pathStatus"></div>
                <div class="path-results-view-toggle path-command-control" id="pathViewToggle" role="group" aria-label="Path result view" hidden>
                  <button class="path-view-toggle path-command-control is-active" id="pathTableView" type="button" aria-pressed="true">Table</button>
                  <button class="path-view-toggle path-command-control" id="pathGraphView" type="button" aria-pressed="false">Graph</button>
                </div>
              </div>
              <div class="path-results-list" id="pathResults"></div>
              <section class="path-graph-panel" id="pathGraphPanel" aria-label="Path graph results" hidden>
                <div class="path-graph-toolbar">
                  <div class="path-graph-title">
                    <strong>Graph result</strong>
                  </div>
                  <div class="path-graph-actions">
                    <button class="graph-ui-command is-active" id="pathGraphShowRelations" type="button" aria-pressed="true">Show relations: On</button>
                    <div class="graph-export-menu-wrap" id="pathGraphExportWrap">
                      <button class="graph-ui-command" id="pathGraphExport" type="button" aria-haspopup="true" aria-expanded="false" disabled>Export</button>
                      <div class="graph-export-menu" id="pathGraphExportMenu" role="menu" hidden>
                        <button id="pathGraphExportCsv" type="button" role="menuitem">CSV</button>
                        <button id="pathGraphExportPng" type="button" role="menuitem">PNG</button>
                        <button id="pathGraphExportSvg" type="button" role="menuitem">SVG</button>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="path-graph-surface" id="pathGraphSurface" aria-label="Path result graph"></div>
              </section>
            </section>
          </div>
        </section>
      </main>
    </div>
    <script src="<?= htmlspecialchars(tekg_assets_url('js/components/te-autocomplete.js') . '?v=' . $pathFinderVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="<?= htmlspecialchars(tekg_assets_url('vendor/g6/g6.min.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/index-g6-type-meta.js') . '?v=' . $pathFinderVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/g6-svg-export.js') . '?v=' . $pathFinderVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/g6/index-g6-shared.js') . '?v=' . $pathFinderVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/path_finder.js') . '?v=' . $pathFinderVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
  </body>
</html>
