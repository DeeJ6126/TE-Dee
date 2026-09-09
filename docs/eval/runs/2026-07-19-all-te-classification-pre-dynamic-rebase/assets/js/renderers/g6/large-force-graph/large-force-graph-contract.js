(function () {
  const root = window.__TEKG_LARGE_FORCE_GRAPH_CONTRACT || {};

  function stringId(value) {
    return String(value || '').trim();
  }

  function endpointId(endpoint) {
    if (endpoint && typeof endpoint === 'object') {
      return stringId(endpoint.id || endpoint.data?.id);
    }
    return stringId(endpoint);
  }

  function normalizeLegendItems(items) {
    return (Array.isArray(items) ? items : [])
      .map((item) => {
        const key = stringId(item?.key);
        if (!key) return null;
        return {
          ...item,
          key,
          kind: stringId(item?.kind || 'node-type'),
          label: stringId(item?.label || key),
          count: Math.max(0, Number(item?.count) || 0),
          visible: item?.visible !== false,
          focusable: item?.focusable !== false,
        };
      })
      .filter(Boolean);
  }

  function defaultLegendState(items, seed = {}) {
    const state = {};
    for (const item of normalizeLegendItems(items)) {
      state[item.key] = typeof seed[item.key] === 'boolean' ? seed[item.key] : item.visible !== false;
    }
    return state;
  }

  function normalizeGraphData(input = {}) {
    const report = { droppedNodes: 0, droppedEdges: 0 };
    const nodeById = new Map();
    const nodes = [];

    for (const rawNode of Array.isArray(input.nodes) ? input.nodes : []) {
      const id = stringId(rawNode?.id);
      if (!id || nodeById.has(id)) {
        report.droppedNodes += 1;
        continue;
      }
      const node = {
        ...rawNode,
        id,
        label: stringId(rawNode?.label || rawNode?.displayLabel || id),
        level: Math.max(0, Number(rawNode?.level) || 0),
        legendKeys: Array.isArray(rawNode?.legendKeys) ? rawNode.legendKeys.map(stringId).filter(Boolean) : [],
        payload: rawNode?.payload && typeof rawNode.payload === 'object' ? { ...rawNode.payload } : {},
      };
      node.degree = Math.max(0, Number(rawNode?.degree) || 0);
      nodes.push(node);
      nodeById.set(id, node);
    }

    const edges = [];
    for (const rawEdge of Array.isArray(input.edges) ? input.edges : []) {
      const source = endpointId(rawEdge?.source);
      const target = endpointId(rawEdge?.target);
      if (!source || !target || !nodeById.has(source) || !nodeById.has(target)) {
        report.droppedEdges += 1;
        continue;
      }
      const id = stringId(rawEdge?.id || `${source}__${target}`);
      edges.push({
        ...rawEdge,
        id,
        source,
        target,
        kind: stringId(rawEdge?.kind || 'edge'),
        legendKeys: Array.isArray(rawEdge?.legendKeys) ? rawEdge.legendKeys.map(stringId).filter(Boolean) : [],
        payload: rawEdge?.payload && typeof rawEdge.payload === 'object' ? { ...rawEdge.payload } : {},
      });
      nodeById.get(source).degree += 1;
      nodeById.get(target).degree += 1;
    }

    const legendItems = normalizeLegendItems(input.legend?.items);
    return {
      ...input,
      graphId: stringId(input.graphId || 'large-force-graph'),
      version: Number(input.version) || 1,
      meta: input.meta && typeof input.meta === 'object' ? { ...input.meta } : {},
      nodes,
      edges,
      legend: {
        items: legendItems,
        state: defaultLegendState(legendItems, input.legend?.state || {}),
      },
      options: input.options && typeof input.options === 'object' ? { ...input.options } : {},
      report,
    };
  }

  function buildAdjacency(data = {}) {
    const adjacency = new Map();
    const add = (a, b) => {
      if (!adjacency.has(a)) adjacency.set(a, new Set());
      adjacency.get(a).add(b);
    };
    for (const node of Array.isArray(data.nodes) ? data.nodes : []) {
      if (!adjacency.has(node.id)) adjacency.set(node.id, new Set());
    }
    for (const edge of Array.isArray(data.edges) ? data.edges : []) {
      const source = endpointId(edge.source);
      const target = endpointId(edge.target);
      if (!source || !target) continue;
      add(source, target);
      add(target, source);
    }
    return adjacency;
  }

  function filterByLegend(masterData = {}, legendState = {}) {
    const state = legendState && typeof legendState === 'object' ? legendState : {};
    const nodeVisible = (node) => (Array.isArray(node?.legendKeys) ? node.legendKeys : [])
      .every((key) => state[key] !== false);
    const edgeVisible = (edge) => (Array.isArray(edge?.legendKeys) ? edge.legendKeys : [])
      .every((key) => state[key] !== false);
    const nodes = (Array.isArray(masterData.nodes) ? masterData.nodes : []).filter(nodeVisible);
    const nodeIds = new Set(nodes.map((node) => String(node.id)));
    const edges = (Array.isArray(masterData.edges) ? masterData.edges : []).filter((edge) => (
      edgeVisible(edge)
      && nodeIds.has(endpointId(edge.source))
      && nodeIds.has(endpointId(edge.target))
    ));
    return {
      ...masterData,
      nodes: nodes.slice(),
      edges: edges.slice(),
      legend: {
        ...(masterData.legend || {}),
        items: Array.isArray(masterData.legend?.items) ? masterData.legend.items.slice() : [],
        state: { ...(masterData.legend?.state || {}), ...state },
      },
      meta: masterData.meta && typeof masterData.meta === 'object' ? { ...masterData.meta } : {},
      options: masterData.options && typeof masterData.options === 'object' ? { ...masterData.options } : {},
    };
  }

  root.endpointId = endpointId;
  root.normalizeGraphData = normalizeGraphData;
  root.buildAdjacency = buildAdjacency;
  root.defaultLegendState = defaultLegendState;
  root.filterByLegend = filterByLegend;
  window.__TEKG_LARGE_FORCE_GRAPH_CONTRACT = root;
}());
