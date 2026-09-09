(function () {
  'use strict';

  const core = window.__TEKG_COEXPRESSION_RENDERER_CORE;
  const adapter = window.__TEKG_COEXPRESSION_DYNAMIC_ADAPTER;
  const container = document.getElementById('container');
  if (!core || typeof core.createRunner !== 'function' || !adapter || !container) return;

  let currentNetwork = null;
  let nonblankGeneration = 0;
  let layoutStopped = false;
  let renderCount = 0;
  let graphIdentity = 0;
  let lastGraph = null;
  let currentExpressionOverlay = { enabled: false, context: 'off', records: {}, min_value: 0, max_value: 0 };
  let currentViewOptions = { showTE: true, showGene: true, edgeScope: 'all' };

  function getHost() {
    try {
      return window.parent && window.parent !== window
        ? window.parent.__TEKG_COEXPRESSION_GRAPH_HOST
        : null;
    } catch (_error) {
      return null;
    }
  }

  function notify(name, payload) {
    const host = getHost();
    if (host && typeof host[name] === 'function') host[name](payload);
  }

  function canvasIsNonblank() {
    const canvases = Array.from(container.querySelectorAll('canvas'));
    if (!canvases.length) return false;
    const sample = document.createElement('canvas');
    sample.width = 32;
    sample.height = 32;
    const context = sample.getContext('2d');
    return canvases.some((canvas) => {
      context.clearRect(0, 0, 32, 32);
      context.drawImage(canvas, 0, 0, 32, 32);
      const pixels = context.getImageData(0, 0, 32, 32).data;
      for (let index = 3; index < pixels.length; index += 4) {
        if (pixels[index] > 0) return true;
      }
      return false;
    });
  }

  function waitForNonblank(generation, maxFrames = 180) {
    return new Promise((resolve, reject) => {
      let frames = 0;
      const check = () => {
        if (generation !== nonblankGeneration) {
          reject(new Error('Co-expression render was superseded.'));
          return;
        }
        frames += 1;
        if (canvasIsNonblank()) {
          resolve(true);
          return;
        }
        if (frames >= maxFrames) {
          reject(new Error('Co-expression Canvas stayed blank.'));
          return;
        }
        requestAnimationFrame(check);
      };
      requestAnimationFrame(check);
    });
  }

  const runner = core.createRunner({
    container,
    initialAllowNodeActions: false,
    setStatus(text) {
      notify('setStatus', String(text || ''));
    },
    setDetail(title, description) {
      notify('setDetail', { title: String(title || ''), description: String(description || '') });
    },
    setDetailHtml(html) {
      notify('setDetailHtml', String(html || ''));
    },
    setMode() {},
    onSelection(node) {
      notify('onSelection', node || null);
    },
    onReady() {
      notify('onReady');
    },
    setQueryUi() {},
    syncRouteState() {},
  });

  function filterNetwork(network) {
    const sourceNodes = Array.isArray(network?.nodes) ? network.nodes : [];
    const sourceEdges = Array.isArray(network?.edges) ? network.edges : [];
    const centerId = String(sourceNodes.find((node) => node.isCenter === true)?.id || '');
    const nodes = sourceNodes.filter((node) => {
      if (node.isCenter === true) return true;
      if (node.kind === 'gene') return currentViewOptions.showGene;
      return currentViewOptions.showTE;
    });
    const nodeIds = new Set(nodes.map((node) => String(node.id || '')));
    const edges = sourceEdges.filter((edge) => {
      if (!nodeIds.has(String(edge.source || '')) || !nodeIds.has(String(edge.target || ''))) return false;
      if (currentViewOptions.edgeScope === 'center') {
        return String(edge.source || '') === centerId || String(edge.target || '') === centerId;
      }
      return true;
    });
    return { ...network, nodes, edges };
  }

  async function renderNetwork(network) {
    renderCount += 1;
    const generation = ++nonblankGeneration;
    currentNetwork = network;
    const visibleNetwork = filterNetwork(network);
    await runner.setExpressionOverlay(currentExpressionOverlay);
    const elements = adapter.toGraphElements(visibleNetwork);
    const selection = network && network.selection ? network.selection : {};
    const selectedFeature = selection.feature || selection.gene || selection.te || 'Co-expression';
    await runner.renderElements(elements, { query: selectedFeature }, {
      sourceLabel: 'query',
      skipInitialStatus: true,
      graphDataOptions: {
        restrictToAnchorComponent: true,
        forceAnchorLabel: true,
        graphRippleAnchor: false,
        allowInspectCard: true,
        allowNodeActions: false,
        showEdgeLabels: false,
      },
    });
    const renderedGraph = runner.getGraph();
    if (renderedGraph && renderedGraph !== lastGraph) {
      lastGraph = renderedGraph;
      graphIdentity += 1;
    }
    layoutStopped = false;
    await waitForNonblank(generation);
    const report = {
      selection: { feature: selectedFeature, featureType: selection.feature_type || 'TE', context: selection.context || '' },
      nodeCount: visibleNetwork.nodes.length,
      edgeCount: visibleNetwork.edges.length,
      nonblank: true,
    };
    notify('onNonblank', report);
    return report;
  }

  async function applyViewOptions() {
    if (!currentNetwork) return null;
    const visibleNetwork = filterNetwork(currentNetwork);
    const graph = await runner.setElementVisibility({
      nodeIds: visibleNetwork.nodes.map((node) => String(node.id || '')),
      edgeIds: visibleNetwork.edges.map((edge) => String(edge.id || '')),
    });
    const report = {
      selection: { ...(currentNetwork.selection || {}) },
      nodeCount: graph.nodes.length,
      edgeCount: graph.edges.length,
      nonblank: graph.nodes.length > 0 && canvasIsNonblank(),
    };
    notify('onNonblank', report);
    return report;
  }

  function setVisible(visible) {
    const graph = runner.getGraph();
    if (visible === false && graph && typeof graph.stopLayout === 'function') {
      graph.stopLayout();
      layoutStopped = true;
    }
    if (visible !== false) {
      layoutStopped = false;
      runner.resize();
    }
    return Promise.resolve();
  }

  function getDiagnostics() {
    const graph = runner.getGraph();
    const visible = runner.getVisibleSubgraph();
    return {
      graphActive: !!graph,
      nodeCount: Array.isArray(visible.nodes) ? visible.nodes.length : 0,
      edgeCount: Array.isArray(visible.edges) ? visible.edges.length : 0,
      nonblank: canvasIsNonblank(),
      layoutStopped,
      renderCount,
      graphIdentity,
      expressionEnabled: currentExpressionOverlay.enabled === true,
      viewOptions: { ...currentViewOptions },
      selection: currentNetwork && currentNetwork.selection ? currentNetwork.selection : null,
    };
  }

  window.__TEKG_COEXPRESSION_EMBED = {
    renderNetwork,
    setExpressionOverlay(overlay) {
      currentExpressionOverlay = overlay && typeof overlay === 'object'
        ? { ...overlay }
        : { enabled: false, context: 'off', records: {}, min_value: 0, max_value: 0 };
      return runner.setExpressionOverlay(currentExpressionOverlay);
    },
    setViewOptions(options) {
      currentViewOptions = {
        showTE: options?.showTE !== false,
        showGene: options?.showGene !== false,
        edgeScope: options?.edgeScope === 'center' ? 'center' : 'all',
      };
      return applyViewOptions();
    },
    setLegendFocus(focus) {
      return runner.setLegendFocus(focus);
    },
    setVisible,
    stopLayout() {
      const graph = runner.getGraph();
      if (graph && typeof graph.stopLayout === 'function') graph.stopLayout();
      layoutStopped = true;
      return Promise.resolve();
    },
    resize() {
      runner.resize();
      return Promise.resolve();
    },
    exportPngDataUrl() {
      return runner.exportPngDataUrl();
    },
    exportSvgString() {
      return runner.exportSvgString();
    },
    getVisibleSubgraph() {
      return runner.getVisibleSubgraph();
    },
    getInteractionSnapshot(nodeId) {
      const graph = runner.getGraph();
      const visible = runner.getVisibleSubgraph();
      if (!graph || typeof graph.getElementPosition !== 'function') return null;
      const positions = {};
      for (const node of visible.nodes || []) {
        const position = graph.getElementPosition(node.id);
        if (!position) continue;
        positions[node.id] = [Number(position[0]), Number(position[1])];
      }
      const targetPosition = positions[String(nodeId || '')];
      const viewport = targetPosition && typeof graph.getViewportByCanvas === 'function'
        ? graph.getViewportByCanvas(targetPosition)
        : null;
      return {
        positions,
        viewport: viewport ? [Number(viewport[0]), Number(viewport[1])] : null,
      };
    },
    inspectNode(nodeId) {
      return runner.inspectNode(nodeId);
    },
    inspectEdge(edgeId) {
      return runner.inspectEdge(edgeId);
    },
    destroy() {
      nonblankGeneration += 1;
      currentNetwork = null;
      lastGraph = null;
      runner.destroy();
    },
    getDiagnostics,
  };

  runner.init().catch((error) => {
    notify('onError', error && error.message ? error.message : 'Unable to initialize Co-expression graph.');
  });
}());
