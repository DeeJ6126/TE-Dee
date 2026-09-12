        <div class="main preview-graph-workspace preview-integrated-workspace" id="previewIntegratedWorkspace" aria-hidden="true" hidden>
          <section class="panel preview-graph-panel" aria-label="Integrated graph workspace">
            <div class="toolbar preview-graph-toolbar">
              <div class="search preview-entity-search">
                <div class="preview-entity-control">
                  <select id="integrated-search-type" aria-label="Integrated graph search entity type">
<?php foreach ($graphSearchEntityTypes as $entityType): ?>
                    <option value="<?= htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8') ?>"<?= $entityType === 'TE' ? ' selected' : '' ?>><?= htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
                  </select>
                  <div class="te-autocomplete" data-te-autocomplete-root data-te-autocomplete-source="integrated-entities" data-te-autocomplete-type-source="#integrated-search-type" data-te-autocomplete-clear-on-type-change="true">
                    <input id="integrated-search-input" type="text" autocomplete="off" placeholder="Select a TE entity" data-te-autocomplete>
                    <button class="te-autocomplete-toggle" type="button" aria-label="Show integrated graph entity names" aria-expanded="false" data-te-autocomplete-toggle></button>
                    <div class="te-autocomplete-menu" data-te-autocomplete-menu hidden></div>
                  </div>
                  <button id="integrated-search-submit" class="preview-search-submit" type="button">Search</button>
                </div>
              </div>
              <label class="integrated-context-control" for="integrated-context-select">
                <select id="integrated-context-select" aria-label="Expression dataset" disabled>
                  <option value="">No expression dataset</option>
                </select>
              </label>
              <button id="integrated-expression-layer" class="is-toggle is-active preview-graph-command" type="button" aria-pressed="true">Expression activity: On</button>
              <button id="integrated-expand-mode" class="is-toggle preview-graph-command" type="button" aria-pressed="false">Expand mode: Off</button>
              <button id="integrated-edge-labels" class="is-toggle preview-graph-command" type="button" aria-pressed="false">Show relations: Off</button>
              <button id="integrated-fixed-view" class="is-toggle is-active preview-graph-command" type="button" aria-pressed="true">Fixed view: On</button>
              <div id="integrated-export-menu-wrap" class="graph-export-menu-wrap">
                <button id="integrated-export-menu-toggle" class="graph-export-action preview-graph-command" type="button" aria-haspopup="true" aria-expanded="false" disabled>Export</button>
                <div id="integrated-export-menu" class="graph-export-menu" role="menu" hidden>
                  <button id="integrated-export-csv" type="button" role="menuitem">CSV</button>
                  <button id="integrated-export-png" type="button" role="menuitem">PNG</button>
                  <button id="integrated-export-svg" type="button" role="menuitem">SVG</button>
                </div>
              </div>
            </div>
            <div class="g6-surface-stack preview-g6-surface-stack">
              <div id="integrated-iframe-host" class="coexpression-iframe-host integrated-iframe-host"></div>
              <div id="integrated-preloader" class="graph-preloader" aria-hidden="true">
                <div class="graph-preloader-inner">
                  <div class="graph-preloader-icon" aria-hidden="true"><span></span><span></span></div>
                  <div class="graph-preloader-status" role="status" aria-live="polite">
                    <div class="graph-preloader-label">Loading integrated graph...</div>
                    <div class="graph-preloader-phase">Combining graph sources...</div>
                  </div>
                </div>
              </div>
              <div id="integrated-state" class="coexpression-state integrated-state" role="status" aria-live="polite" hidden></div>
              <aside id="integrated-legend" class="graph-legend-panel integrated-legend" aria-label="Integrated Graph legend" hidden>
                <div class="graph-legend-title">Integrated Graph Legend</div>
                <div id="integrated-legend-list" class="graph-legend-list"></div>
                <div class="graph-relation-controls">
                  <label for="integrated-min-pmids">Min PMID</label>
                  <input id="integrated-min-pmids" class="graph-relation-min-pmids" type="number" min="0" max="99" value="0">
                  <label for="integrated-edge-scope">TE-Gene edges</label>
                  <select id="integrated-edge-scope"><option value="all">All selected edges</option><option value="center">Center edges</option></select>
                </div>
              </aside>
            </div>
            <div class="detail" id="integrated-node-details">Select an entity to combine its available graph evidence.</div>
          </section>
        </div>
