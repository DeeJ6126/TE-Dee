(function () {
  const shared = window.__TEKG_G6_SHARED;
  if (!shared || typeof shared.createRunner !== 'function') return;

  const container = document.getElementById('container');
  if (!container) return;

  const params = new URLSearchParams(window.location.search);
  const initialRequest = {
    query: String(params.get('q') || '').trim(),
    queryType: String(params.get('type') || '').trim(),
    classQuery: String(params.get('class') || '').trim(),
  };

  function getHost() {
    try {
      return window.parent && window.parent !== window ? window.parent.__TEKG_G6_GRAPH_HOST : null;
    } catch (_error) {
      return null;
    }
  }

  function pushDetail(title, description) {
    const host = getHost();
    if (host && typeof host.setDetail === 'function') host.setDetail(title, description);
  }

  function pushDetailHtml(html) {
    const host = getHost();
    if (host && typeof host.setDetailHtml === 'function') host.setDetailHtml(html);
  }

  function pushStatus(text) {
    const host = getHost();
    if (host && typeof host.setStatus === 'function') host.setStatus(text);
  }

  function pushMode(mode, query) {
    const host = getHost();
    if (host && typeof host.setMode === 'function') host.setMode(mode, query);
  }

  function pushSelection(node) {
    const host = getHost();
    if (host && typeof host.onNodeSelect === 'function') host.onNodeSelect(node || null);
  }

  function pushDiseaseClassClick(node, request) {
    const host = getHost();
    if (host && typeof host.onDiseaseClassClick === 'function') {
      return host.onDiseaseClassClick(node || null, request || null);
    }
    return false;
  }

  function pushNodeExpand(node) {
    const host = getHost();
    if (host && typeof host.onNodeExpand === 'function') {
      return host.onNodeExpand(node || null);
    }
    return false;
  }

  function pushNodeJump(node, request) {
    const host = getHost();
    if (host && typeof host.onNodeJump === 'function') {
      return host.onNodeJump(node || null, request || null);
    }
    return false;
  }

  function pushReady() {
    const host = getHost();
    if (host && typeof host.onReady === 'function') host.onReady();
  }

  const runner = shared.createRunner({
    container,
    initialFixedView: params.get('fixed') === '1',
    initialShowAllLabels: params.get('show_labels') === '1',
    initialKeyNodeLevel: Math.max(1, Math.min(10, Number(params.get('key_level')) || 1)),
    initialQuery: String(params.get('q') || '').trim(),
    initialQueryType: String(params.get('type') || '').trim(),
    initialClassQuery: String(params.get('class') || '').trim(),
    initialLang: 'en',
    syncRouteState: ({ query, queryType, classQuery, keyNodeLevel, fixedView, showLabels, lang }) => {
      const next = new URLSearchParams(window.location.search);
      if (query) next.set('q', query);
      else next.delete('q');
      if (queryType) next.set('type', queryType);
      else next.delete('type');
      if (queryType === 'disease_class' && classQuery) next.set('class', classQuery);
      else next.delete('class');
      next.set('key_level', String(keyNodeLevel));
      next.set('fixed', fixedView ? '1' : '0');
      next.set('show_labels', showLabels ? '1' : '0');
      window.history.replaceState({}, '', `${window.location.pathname}?${next.toString()}`);
    },
    setStatus: pushStatus,
    setDetail: pushDetail,
    setDetailHtml: pushDetailHtml,
    setMode: pushMode,
    onSelection: pushSelection,
    onDiseaseClassClick: pushDiseaseClassClick,
    onNodeJump: pushNodeJump,
    onNodeExpand: pushNodeExpand,
    onReady: pushReady,
  });

  const bridge = {
    loadGraph(query, options = {}) {
      return runner.loadGraph(query, options);
    },
    renderElements(elements, requestLike, options = {}) {
      return runner.renderElements(elements, requestLike, options);
    },
    expandGraph(requestLike, options = {}) {
      return runner.expandGraph(requestLike, options);
    },
    setFixedView(next) {
      return runner.setFixedView(next);
    },
    setViewState(next) {
      return runner.setViewState(next);
    },
    setExpressionOverlay(overlay) {
      return runner.setExpressionOverlay(overlay);
    },
    setLegendFocus(focus) {
      return runner.setLegendFocus(focus);
    },
    setKeyNodeLevel(level) {
      return runner.setKeyNodeLevel(level);
    },
    setLanguage(lang) {
      return runner.setLanguage(lang);
    },
    setLegendHighlight(focus) {
      return runner.setLegendFocus(focus);
    },
    setLegendFocus(focus) {
      return runner.setLegendFocus(focus);
    },
    resize() {
      runner.resize();
      return Promise.resolve();
    },
    getVisibleSubgraph() {
      return runner.getVisibleSubgraph();
    },
    inspectNode(nodeId) {
      return runner.inspectNode(nodeId);
    },
    triggerNodeAction(nodeId, action) {
      return runner.triggerNodeAction(nodeId, action);
    },
    inspectEdge(edgeId) {
      return runner.inspectEdge(edgeId);
    },
    getEdgeVisuals(edgeId) {
      return runner.getEdgeVisuals(edgeId);
    },
    exportPngDataUrl() {
      return runner.exportPngDataUrl();
    },
    exportSvgString() {
      return runner.exportSvgString();
    },
    getCurrentQuery() {
      return runner.getCurrentQuery();
    },
    getFixedView() {
      return runner.getFixedView();
    },
    getKeyNodeLevel() {
      return runner.getKeyNodeLevel();
    },
  };

  window.__TEKG_G6_EMBED = bridge;

  runner.init().finally(() => {
    const parentDrivesInitialGraph = !!getHost();
    if (initialRequest.query && !parentDrivesInitialGraph) {
      const request = initialRequest.queryType
        ? initialRequest
        : initialRequest.query;
      runner.loadGraph(request).catch(() => {});
    }
  });
}());
