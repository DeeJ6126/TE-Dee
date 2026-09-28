        <div class="main preview-graph-workspace" id="previewGraphWorkspace">
          <section class="panel preview-graph-panel" aria-label="TE-Dee graph workspace">
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
              <button id="toggle-expand-mode" class="expand-mode is-toggle preview-graph-command" type="button" aria-pressed="false"><span id="expand-mode-text">Expand mode: Off</span></button>
              <button id="toggle-non-key-nodes" class="non-key-legacy" type="button" style="display:none">
                <span id="non-key-nodes-text">Hide non-key nodes: Off</span>
              </button>
              <button id="toggle-edge-labels" class="is-toggle preview-graph-command graph-ui-command" type="button" aria-pressed="false"><span id="edge-labels-text">Show relations: Off</span></button>
              <button id="toggle-fixed-view" class="is-toggle preview-graph-command" type="button" aria-pressed="true"><span id="fixed-view-text">Fixed view: On</span></button>
              <div id="export-menu-wrap" class="graph-export-menu-wrap">
                <button id="export-menu-toggle" class="graph-export-action preview-graph-command graph-ui-command" type="button" aria-haspopup="true" aria-expanded="false" disabled>
                  <span id="export-menu-text">Export</span>
                </button>
                <div id="export-menu" class="graph-export-menu" role="menu" hidden>
                  <button id="export-menu-csv" type="button" role="menuitem">CSV</button>
                  <button id="export-menu-png" type="button" role="menuitem">PNG</button>
                  <button id="export-menu-svg" type="button" role="menuitem">SVG</button>
                </div>
              </div>
            </div>
            <div class="g6-surface-stack preview-g6-surface-stack">
              <div id="cy" style="display:none"></div>
              <div id="g6-default-tree-surface"></div>
              <div id="taxonomy-canvas-surface" aria-label="Taxonomy graph" hidden>
                <canvas id="taxonomy-canvas"></canvas>
                <div id="taxonomy-canvas-status" class="taxonomy-canvas-status" role="status" aria-live="polite"></div>
                <div id="taxonomy-canvas-tooltip" class="taxonomy-canvas-tooltip" hidden></div>
              </div>
              <div id="g6-dynamic-surface"></div>
              <div id="graph-preloader" class="graph-preloader" aria-hidden="true">
                <div class="graph-preloader-inner">
                  <div class="graph-preloader-icon" aria-hidden="true">
                    <span></span>
                    <span></span>
                  </div>
                  <div id="te-mechanism-loader-slot" class="te-mechanism-loader-slot" aria-hidden="true"></div>
                  <div class="graph-preloader-status" role="status" aria-live="polite">
                    <div id="graph-preloader-label" class="graph-preloader-label">Loading graph...</div>
                    <div id="graph-preloader-progress" class="graph-preloader-progress" role="progressbar" aria-label="Graph loading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                      <span class="graph-preloader-progress-fill"></span>
                    </div>
                    <div id="graph-preloader-phase" class="graph-preloader-phase">Requesting graph data...</div>
                  </div>
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
