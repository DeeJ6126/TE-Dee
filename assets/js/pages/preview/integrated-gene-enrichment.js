(function () {
  'use strict';
  var root = document.getElementById('previewIntegratedWorkspace');
  var shared = window.__TEKG_GENE_ENRICHMENT;
  var host = window.__TEKG_INTEGRATED_GRAPH_HOST;
  if (!root || !shared || !host) return;

  var byId = function (id) { return document.getElementById(id); };
  var els = {
    count: byId('integrated-gene-selection-count'),
    list: byId('integrated-gene-list'),
    toggle: byId('integrated-gene-list-toggle'),
    all: byId('integrated-gene-select-all'),
    clear: byId('integrated-gene-clear'),
    run: byId('integrated-run-enrichment'),
    provider: byId('integrated-enrichment-provider'),
    select: byId('integrated-gene-select'),
    listPanel: byId('integrated-gene-list-panel'),
    legendPane: byId('integrated-legend-pane'),
    enrichmentPane: byId('integrated-enrichment-pane'),
  };
  var selecting = false;
  var networkKey = null;
  var selected = new Set();
  var genes = [];

  function text(value) { return String(value == null ? '' : value); }
  function key(value) { return text(value).normalize('NFKC').trim().toLowerCase(); }
  function esc(value) {
    return text(value).replace(/[&<>\"]/g, function (character) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;' })[character];
    });
  }
  function bridge() {
    var frame = document.querySelector('#integrated-iframe-host iframe');
    return frame && frame.contentWindow && frame.contentWindow.__TEKG_INTEGRATED_EMBED;
  }
  function sourcesFor(node) {
    var sources = Array.isArray(node.source_graphs) ? node.source_graphs.slice() : [];
    if (!sources.length && node.source_graph) sources.push(node.source_graph);
    return sources.map(function (source) { return text(source).toLowerCase(); });
  }
  function visibleGenes() {
    var api = bridge();
    var graph = api && api.getVisibleSubgraph ? api.getVisibleSubgraph() : {};
    var unique = new Map();
    (graph.nodes || []).filter(function (node) {
      return key(node.kind || node.feature_type || node.nodeType || node.type) === 'gene';
    }).forEach(function (node) {
      var name = text(node.label || node.displayLabel || node.rawLabel || node.id).trim();
      var geneKey = key(name);
      if (!geneKey) return;
      if (!unique.has(geneKey)) unique.set(geneKey, { key: geneKey, name: name, nodeIds: [], sources: new Set() });
      var gene = unique.get(geneKey);
      gene.nodeIds.push(text(node.id));
      sourcesFor(node).forEach(function (source) { gene.sources.add(source); });
    });
    return Array.from(unique.values()).sort(function (left, right) {
      return left.name.localeCompare(right.name, undefined, { sensitivity: 'base' });
    });
  }
  function pushSelection() {
    var api = bridge();
    if (!api || typeof api.setEnrichmentSelection !== 'function') return;
    var nodeIds = genes.filter(function (gene) { return selected.has(gene.key); }).flatMap(function (gene) { return gene.nodeIds; });
    Promise.resolve(api.setEnrichmentSelection({ active: selecting && !root.hidden, nodeIds: nodeIds })).catch(function () {});
  }
  function fitList() {
    if (els.listPanel.hidden || !root.getBoundingClientRect) return;
    var surface = root.querySelector('.g6-surface-stack');
    if (!surface) return;
    var bounds = surface.getBoundingClientRect();
    var panel = els.listPanel.getBoundingClientRect();
    var top = Math.max(0, bounds.top) + 24;
    els.listPanel.style.maxHeight = Math.max(120, Math.min(window.innerHeight - 20, panel.bottom) - top) + 'px';
  }
  function sync() {
    fitList();
    pushSelection();
    els.select.setAttribute('aria-pressed', String(selecting));
    els.count.textContent = 'Genes selected: ' + selected.size;
    els.run.disabled = selected.size < 1;
  }
  function geneItem(gene, sourceClass) {
    return '<label class="graph-legend-item"><input class="graph-legend-check" type="checkbox" data-gene="' + esc(gene.key) + '" ' + (selected.has(gene.key) ? 'checked' : '') + '><span class="graph-legend-swatch ' + sourceClass + '" aria-hidden="true"></span><span class="graph-legend-text" title="' + esc(gene.name) + '">' + esc(gene.name) + '</span></label>';
  }
  function geneGroup(title, items, sourceClass) {
    return '<section class="enrichment-gene-source-group"><div class="enrichment-gene-source-title">' + title + '</div><div class="enrichment-gene-source-items">' + (items.length ? items.map(function (gene) { return geneItem(gene, sourceClass); }).join('') : '<span class="enrichment-gene-source-empty">No visible Gene nodes</span>') + '</div></section>';
  }
  function renderList() {
    genes = visibleGenes();
    var visibleKeys = new Set(genes.map(function (gene) { return gene.key; }));
    Array.from(selected).forEach(function (geneKey) { if (!visibleKeys.has(geneKey)) selected.delete(geneKey); });
    var knowledge = genes.filter(function (gene) { return gene.sources.has('knowledge'); });
    var teGene = genes.filter(function (gene) { return gene.sources.has('te_gene'); });
    els.list.innerHTML = genes.length
      ? geneGroup('Knowledge Graph', knowledge, 'integrated-enrichment-knowledge') + '<div class="enrichment-gene-source-divider" aria-hidden="true"></div>' + geneGroup('TE-Gene Graph', teGene, 'coexpression-legend-gene')
      : '<span>No visible Gene nodes</span>';
    Array.prototype.forEach.call(els.list.querySelectorAll('input[data-gene]'), function (input) {
      input.addEventListener('change', function () {
        var geneKey = input.getAttribute('data-gene');
        if (input.checked) selected.add(geneKey); else selected.delete(geneKey);
        renderList();
      });
    });
    sync();
  }
  function setPane(enrichment, activeButton) {
    els.legendPane.hidden = enrichment;
    els.enrichmentPane.hidden = !enrichment;
    root.querySelectorAll('[data-enrichment-pane]').forEach(function (button) {
      var active = button === activeButton;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', String(active));
    });
    if (!enrichment) {
      selecting = false;
      els.listPanel.hidden = true;
      els.toggle.setAttribute('aria-expanded', 'false');
    }
    renderList();
  }

  els.toggle.addEventListener('click', function () {
    els.listPanel.hidden = !els.listPanel.hidden;
    els.toggle.setAttribute('aria-expanded', String(!els.listPanel.hidden));
    renderList();
    fitList();
  });
  els.select.addEventListener('click', function () { selecting = !selecting; sync(); });
  root.querySelectorAll('[data-enrichment-pane]').forEach(function (button) {
    button.addEventListener('click', function () { setPane(button.getAttribute('data-enrichment-pane') === 'enrichment', button); });
  });
  els.all.addEventListener('click', function () { visibleGenes().forEach(function (gene) { selected.add(gene.key); }); renderList(); });
  els.clear.addEventListener('click', function () { selected.clear(); selecting = false; renderList(); });
  els.run.addEventListener('click', function () {
    var names = genes.filter(function (gene) { return selected.has(gene.key); }).map(function (gene) { return gene.name; });
    shared.run(names, els.provider.value);
  });

  var oldSelection = host.onSelection;
  host.onSelection = function (node) {
    if (oldSelection) oldSelection(node);
    if (!selecting || key(node && (node.kind || node.nodeType || node.type || node.coexpressionFeatureType)) !== 'gene') return;
    var geneKey = key(node && (node.label || node.displayLabel || node.rawLabel || node.id));
    if (!geneKey) return;
    if (selected.has(geneKey)) selected.delete(geneKey); else selected.add(geneKey);
    renderList();
  };
  var oldNonblank = host.onNonblank;
  host.onNonblank = function (report) {
    if (oldNonblank) oldNonblank(report);
    var api = bridge();
    var diagnostics = api && api.getDiagnostics ? api.getDiagnostics() : {};
    var nextKey = text(diagnostics.renderCount);
    if (networkKey !== nextKey) { selected.clear(); selecting = false; networkKey = nextKey; }
    window.setTimeout(renderList, 0);
  };

  window.addEventListener('resize', fitList);
  window.addEventListener('scroll', fitList, true);
  if (typeof ResizeObserver !== 'undefined') new ResizeObserver(fitList).observe(root);
  new MutationObserver(function () { if (root.hidden) selecting = false; sync(); }).observe(root, { attributes: true, attributeFilter: ['hidden'] });
  window.setTimeout(renderList, 800);
  sync();
}());
