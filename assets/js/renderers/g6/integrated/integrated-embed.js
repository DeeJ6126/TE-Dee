(function () {
  'use strict';
  const core = window.__TEKG_COEXPRESSION_RENDERER_CORE;
  const container = document.getElementById('container');
  if (!core || !container) return;
  let masterElements = [];
  let currentOptions = { visibleTypes: {}, visibleRelations: {}, minPmids: 0, edgeScope: 'all' };
  let renderCount = 0;
  let layoutStopped = false;

  function host() {
    try { return window.parent !== window ? window.parent.__TEKG_INTEGRATED_GRAPH_HOST : null; } catch (_error) { return null; }
  }
  function notify(name, payload) {
    const target = host();
    if (target && typeof target[name] === 'function') return target[name](payload);
    return undefined;
  }
  const runner = core.createRunner({
    container: container,
    renderProfile: 'integrated',
    initialFixedView: true,
    initialAllowNodeActions: true,
    setStatus: function (value) { notify('setStatus', String(value || '')); },
    setDetail: function (title, description) { notify('setDetail', { title: title, description: description }); },
    setDetailHtml: function (html) { notify('setDetailHtml', html); },
    onSelection: function (node) { notify('onSelection', node || null); },
    onNodeExpand: function (node) { return notify('onNodeExpand', node || null); },
    onNodeJump: function (node, request) { return notify('onNodeJump', { node: node || null, request: request || null }); },
    onReady: function () { notify('onReady'); },
    setMode: function () {},
    setQueryUi: function () {},
    syncRouteState: function () {},
  });

  function isEdge(element) { return !!(element && element.data && element.data.source && element.data.target); }
  function visibleElements() {
    const candidateNodes = masterElements.filter(function (element) {
      return !isEdge(element) && currentOptions.visibleTypes[element.data.type || 'TE'] !== false;
    });
    const nodeIds = new Set(candidateNodes.map(function (element) { return String(element.data.id); }));
    const centerIds = new Set(candidateNodes.filter(function (element) {
      return element.data.coexpressionIsCenter === true;
    }).map(function (element) { return String(element.data.id); }));
    const edges = masterElements.filter(function (element) {
      if (!isEdge(element)) return false;
      const data = element.data;
      if (!nodeIds.has(String(data.source)) || !nodeIds.has(String(data.target))) return false;
      const relation = String(data.relationKey || data.relationType || data.relation || 'RELATION');
      if (currentOptions.visibleRelations[relation] === false) return false;
      if (data.sourceGraph === 'knowledge' && (Array.isArray(data.pmids) ? data.pmids.length : Number(data.support_pmid_count || 0)) < currentOptions.minPmids) return false;
      if (data.sourceGraph === 'te_gene' && currentOptions.edgeScope === 'center') {
        return centerIds.has(String(data.source)) || centerIds.has(String(data.target));
      }
      return true;
    });
    const connectedIds = new Set(centerIds);
    edges.forEach(function (element) {
      connectedIds.add(String(element.data.source));
      connectedIds.add(String(element.data.target));
    });
    const nodes = candidateNodes.filter(function (element) { return connectedIds.has(String(element.data.id)); });
    return nodes.concat(edges);
  }
  async function render(elements, request, options) {
    masterElements = (Array.isArray(elements) ? elements : []).map(function (element) {
      return { data: { ...(element.data || {}) } };
    });
    renderCount += 1;
    await runner.renderElements(visibleElements(), request || {}, {
      sourceLabel: 'query',
      skipInitialStatus: true,
      graphDataOptions: {
        restrictToAnchorComponent: false,
        forceAnchorLabel: true,
        graphRippleAnchor: false,
        allowInspectCard: true,
        allowNodeActions: true,
        showEdgeLabels: options && options.showEdgeLabels === true,
      },
    });
    layoutStopped = false;
    const result = runner.getVisibleSubgraph();
    notify('onNonblank', { nodeCount: result.nodes.length, edgeCount: result.edges.length, nonblank: result.nodes.length > 0 });
    return result;
  }
  async function setFilters(options) {
    currentOptions = {
      visibleTypes: { ...(options && options.visibleTypes || {}) },
      visibleRelations: { ...(options && options.visibleRelations || {}) },
      minPmids: Math.max(0, Number(options && options.minPmids) || 0),
      edgeScope: options && options.edgeScope === 'center' ? 'center' : 'all',
    };
    const filtered = visibleElements();
    return runner.setElementVisibility({
      nodeIds: filtered.filter(function (element) { return !isEdge(element); }).map(function (element) { return String(element.data.id); }),
      edgeIds: filtered.filter(isEdge).map(function (element) { return String(element.data.id); }),
    });
  }
  window.__TEKG_INTEGRATED_EMBED = {
    renderElements: render,
    setFilters: setFilters,
    setExpressionOverlay: function (overlay) { return runner.setExpressionOverlay(overlay); },
    setViewState: function (state) { return runner.setViewState(state || {}); },
    setFixedView: function (fixed) { return runner.setFixedView(fixed === true); },
    setLegendFocus: function (focus) { return runner.setLegendFocus(focus); },
    inspectNode: function (id) { return runner.inspectNode(id); },
    inspectEdge: function (id) { return runner.inspectEdge(id); },
    exportPngDataUrl: function () { return runner.exportPngDataUrl(); },
    exportSvgString: function () { return runner.exportSvgString(); },
    getVisibleSubgraph: function () { return runner.getVisibleSubgraph(); },
    setVisible: function (visible) {
      const graph = runner.getGraph();
      if (!visible && graph && typeof graph.stopLayout === 'function') { graph.stopLayout(); layoutStopped = true; }
      if (visible) { layoutStopped = false; runner.resize(); }
      return Promise.resolve();
    },
    resize: function () { runner.resize(); return Promise.resolve(); },
    getDiagnostics: function () {
      const visible = runner.getVisibleSubgraph();
      return { renderCount: renderCount, nodeCount: visible.nodes.length, edgeCount: visible.edges.length, layoutStopped: layoutStopped };
    },
  };
  runner.init().catch(function (error) { notify('onError', error && error.message ? error.message : 'Unable to initialize Integrated Graph.'); });
}());
