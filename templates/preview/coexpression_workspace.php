        <div class="main preview-graph-workspace preview-coexpression-workspace" id="previewCoexpressionWorkspace" aria-hidden="true" hidden>
          <section class="panel preview-graph-panel" aria-label="TE-Dee co-expression graph workspace">
            <div class="toolbar preview-graph-toolbar">
              <div class="search preview-entity-search">
                <div class="preview-entity-control">
                  <select id="coexpression-search-type" aria-label="Co-expression search entity type">
                    <option value="TE" selected>TE</option>
                    <option value="Gene">Gene</option>
                  </select>
                  <div class="te-autocomplete" data-te-autocomplete-root data-te-autocomplete-source="coexpression-catalog" data-te-autocomplete-type-source="#coexpression-search-type" data-te-autocomplete-clear-on-type-change="true">
                    <input id="coexpression-te-search" type="text" autocomplete="off" placeholder="Select a TE" aria-label="Co-expression feature" data-te-autocomplete disabled>
                    <button class="te-autocomplete-toggle" type="button" aria-label="Show Co-expression feature names" aria-expanded="false" data-te-autocomplete-toggle></button>
                    <div class="te-autocomplete-menu" data-te-autocomplete-menu hidden></div>
                  </div>
                  <button id="coexpression-load" class="preview-search-submit" type="button" disabled>Search</button>
                </div>
              </div>
              <label class="coexpression-context-control" for="coexpression-context-select">
                <select id="coexpression-context-select" aria-label="Expression dataset" disabled>
                  <option value="">Select dataset</option>
                </select>
              </label>
              <button id="coexpression-expression-layer" class="is-toggle is-active preview-graph-command" type="button" aria-pressed="true">
                <span id="coexpression-expression-layer-text">Expression activity: On</span>
              </button>
              <div id="coexpression-export-menu-wrap" class="graph-export-menu-wrap">
                <button id="coexpression-export-menu-toggle" class="graph-export-action preview-graph-command graph-ui-command" type="button" aria-haspopup="true" aria-expanded="false" disabled>
                  Export
                </button>
                <div id="coexpression-export-menu" class="graph-export-menu" role="menu" hidden>
                  <button id="coexpression-export-csv" type="button" role="menuitem">CSV</button>
                  <button id="coexpression-export-png" type="button" role="menuitem">PNG</button>
                  <button id="coexpression-export-svg" type="button" role="menuitem">SVG</button>
                </div>
              </div>
            </div>
            <div class="g6-surface-stack preview-g6-surface-stack">
              <div id="coexpression-iframe-host" class="coexpression-iframe-host"></div>
              <div id="coexpression-preloader" class="graph-preloader" aria-hidden="true">
                <div class="graph-preloader-inner">
                  <div id="coexpression-mechanism-loader-slot" class="te-mechanism-loader-slot" aria-hidden="true"></div>
                  <div class="graph-preloader-icon" aria-hidden="true">
                    <span></span>
                    <span></span>
                  </div>
                  <div class="graph-preloader-status" role="status" aria-live="polite">
                    <div id="coexpression-preloader-label" class="graph-preloader-label">Loading co-expression network...</div>
                    <div id="coexpression-preloader-progress" class="graph-preloader-progress" role="progressbar" aria-label="Co-expression loading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                      <span class="graph-preloader-progress-fill"></span>
                    </div>
                    <div id="coexpression-preloader-phase" class="graph-preloader-phase">Requesting co-expression data...</div>
                  </div>
                </div>
              </div>
              <div id="coexpression-state" class="coexpression-state" role="status" aria-live="polite" hidden>
                <span id="coexpression-state-message"></span>
                <button id="coexpression-retry" type="button" hidden>Retry</button>
              </div>
              <aside id="coexpression-legend" class="graph-legend-panel coexpression-legend" aria-label="TE-Gene Graph legend">
                <div class="graph-legend-mode-switch" aria-label="TE-Gene legend pages">
                  <button class="graph-legend-tab is-active" data-enrichment-pane="legend" type="button" aria-pressed="true">Legend</button>
                  <span class="graph-legend-mode-separator" aria-hidden="true">/</span>
                  <button class="graph-legend-tab" data-enrichment-pane="enrichment" type="button" aria-pressed="false">GO/KEGG</button>
                </div>
                <div id="coexpression-legend-pane" class="coexpression-legend-pane">
                <div class="graph-legend-title">TE-Gene Graph Legend</div>
                <label class="graph-legend-item" data-highlight-kind="entity" data-highlight-value="TE" tabindex="0" aria-pressed="false">
                  <input id="coexpression-show-te" class="graph-legend-check" type="checkbox" checked>
                  <span class="graph-legend-swatch coexpression-legend-te" aria-hidden="true"></span>
                  <span class="graph-legend-text">TE nodes</span>
                </label>
                <label class="graph-legend-item" data-highlight-kind="entity" data-highlight-value="Gene" tabindex="0" aria-pressed="false">
                  <input id="coexpression-show-gene" class="graph-legend-check" type="checkbox" checked>
                  <span class="graph-legend-swatch coexpression-legend-gene" aria-hidden="true"></span>
                  <span class="graph-legend-text">Gene nodes</span>
                </label>
                <div class="graph-legend-item coexpression-legend-static" data-highlight-kind="module-hub" data-highlight-value="module-hub" tabindex="0" aria-pressed="false">
                  <span class="coexpression-hub-ring" aria-hidden="true"></span>
                  <span class="graph-legend-text">Module hub</span>
                  <span class="coexpression-legend-help" role="tooltip">One of the top 5% of features by weighted within-module degree (at least one hub in an eligible module of size 3 or more).</span>
                </div>
                <div class="graph-legend-item coexpression-legend-static" data-highlight-kind="relative-expression" data-highlight-value="relative-expression" tabindex="0" aria-pressed="false">
                  <span class="coexpression-activity-ring" aria-hidden="true"></span>
                  <span class="graph-legend-text">Relative expression activity</span>
                  <span class="coexpression-legend-help" role="tooltip">Median expression for the selected context, log-normalized against the other visible TE and Gene nodes. It does not encode correlation or causality.</span>
                </div>
                <div class="coexpression-legend-divider" aria-hidden="true"></div>
                <div class="graph-legend-item coexpression-legend-static" data-highlight-kind="relation" data-highlight-value="Co-expression" tabindex="0" aria-pressed="false">
                  <span class="graph-relation-line coexpression-edge-line" aria-hidden="true"></span>
                  <span class="graph-legend-text">Co-expression</span>
                </div>
                <div class="graph-legend-item coexpression-legend-static" data-highlight-kind="relation" data-highlight-value="eQTL" tabindex="0" aria-pressed="false">
                  <span class="graph-relation-line coexpression-edge-line coexpression-edge-eqtl" aria-hidden="true"></span>
                  <span class="graph-legend-text">eQTL</span>
                </div>
                <div class="graph-legend-item coexpression-legend-static" data-highlight-kind="relation" data-highlight-value="Both" tabindex="0" aria-pressed="false">
                  <span class="graph-relation-line coexpression-edge-line coexpression-edge-both" aria-hidden="true"></span>
                  <span class="graph-legend-text">Both</span>
                </div>
                <label class="coexpression-edge-scope-control" for="coexpression-edge-scope">
                  <span>Edges</span>
                  <select id="coexpression-edge-scope">
                    <option value="center">Center edges</option>
                    <option value="all" selected>All selected edges</option>
                  </select>
                </label>
                <div class="graph-legend-footer">
                  <button id="coexpression-legend-apply" class="graph-legend-apply" type="button" disabled>Apply</button>
                </div>
                </div>
                <div id="coexpression-enrichment-pane" hidden>
                  <div class="enrichment-selection-tools">
                    <button id="coexpression-gene-select" class="graph-legend-apply enrichment-secondary" type="button" aria-pressed="false">Select</button>
                    <button id="coexpression-gene-list-toggle" class="graph-legend-apply enrichment-secondary" type="button" aria-expanded="false">List</button>
                  </div>
                  <p id="coexpression-gene-selection-count" class="enrichment-selection-count" aria-live="polite">Genes selected: 0</p>
                  <label class="coexpression-edge-scope-control" for="coexpression-enrichment-provider"><span>Provider</span><select id="coexpression-enrichment-provider"><option value="string">STRING</option><option value="enrichr">Enrichr</option></select></label>
                  <div class="graph-legend-footer enrichment-selection-tools">
                    <button id="coexpression-run-enrichment" class="graph-legend-apply" type="button" disabled>Run</button>
                    <button id="coexpression-gene-clear" class="graph-legend-apply enrichment-secondary" type="button">Reset</button>
                  </div>
                  <section id="coexpression-gene-list-panel" class="graph-legend-panel enrichment-gene-list-panel" hidden aria-label="Gene List">
                    <div class="enrichment-list-heading"><span class="graph-legend-title">Gene List</span><button id="coexpression-gene-select-all" class="graph-legend-tab" type="button">Select All</button></div>
                    <div id="coexpression-gene-list" class="graph-legend-list enrichment-gene-grid"></div>
                  </section>
                </div>
              </aside>
              <div id="coexpression-correlation-notice" class="coexpression-correlation-notice">
                <strong id="coexpression-method-summary">Spearman correlation</strong>
              </div>
            </div>
            <div class="detail" id="coexpression-node-details">Preparing co-expression workspace...</div>
          </section>
        </div>
        <aside id="gene-enrichment-panel" class="gene-enrichment-panel" hidden aria-label="Gene enrichment results">
          <span class="enrichment-resize enrichment-resize-nw" data-enrichment-resize="nw" aria-hidden="true"></span><span class="enrichment-resize enrichment-resize-ne" data-enrichment-resize="ne" aria-hidden="true"></span><span class="enrichment-resize enrichment-resize-sw" data-enrichment-resize="sw" aria-hidden="true"></span><span class="enrichment-resize enrichment-resize-se" data-enrichment-resize="se" aria-hidden="true"></span><header class="gene-enrichment-head"><h2>Gene enrichment</h2><div><button id="gene-enrichment-minimize" type="button" aria-label="Minimize enrichment">-</button><button id="gene-enrichment-close" type="button" aria-label="Close enrichment">×</button></div></header>
          <div id="gene-enrichment-status" class="gene-enrichment-status" role="status"></div>
          <div class="gene-enrichment-controls"><button data-enrichment-tab="go" class="is-active" type="button">GO</button><button data-enrichment-tab="kegg" type="button">KEGG</button><button id="gene-enrichment-csv" type="button">CSV</button><label>Top <select id="gene-enrichment-top"><option>5</option><option selected>10</option><option>15</option><option>20</option><option value="all">All</option></select></label></div>
          <div class="gene-enrichment-body"><div id="gene-enrichment-table"></div><div id="gene-enrichment-plot"></div></div>
          <div id="gene-enrichment-pagination" class="gene-enrichment-pagination"></div>
        </aside>
