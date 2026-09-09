(function () {
  if (window.__TEKG_RENDERER_MODE && window.__TEKG_RENDERER_MODE !== 'g6') return;

  const G6Lib = window.G6;
  const contract = window.__TEKG_LARGE_FORCE_GRAPH_CONTRACT;
  if (!G6Lib || typeof G6Lib.Graph !== 'function' || !contract) return;

  const { Graph, NodeEvent } = G6Lib;
  let sequence = 0;
  const liveInstanceIds = new Set();
  const createdInstanceIds = new Set();
  const destroyedInstanceIds = new Set();

  function endpointId(value) {
    return contract.endpointId ? contract.endpointId(value) : String(value?.id || value || '');
  }

  function levelOf(node) {
    return Math.max(0, Number(node?.level ?? node?.payload?.treeDepth ?? node?.data?.treeDepth ?? 0) || 0);
  }

  function nodeSize(node) {
    return [64, 48, 34, 26, 14, 10][levelOf(node)] || 9;
  }

  function projectData(data) {
    return {
      nodes: data.nodes.map((node) => {
        const depth = levelOf(node);
        return {
          id: String(node.id),
          level: depth,
          style: {
            size: nodeSize(node),
            fill: node.color || '#2563eb',
            stroke: depth <= 2 ? '#172554' : (node.stroke || node.color || '#1d4ed8'),
            lineWidth: depth <= 0 ? 4 : depth === 1 ? 3.2 : depth === 2 ? 2.5 : depth === 3 ? 2 : 1.2,
          },
          data: {
            ...(node.payload || {}),
            rawLabel: node.rawLabel || node.label || node.id,
            displayLabel: node.displayLabel || node.label || node.id,
            description: node.description || node.payload?.description || '',
            treeDepth: depth,
            degree: Number(node.payload?.degree ?? node.degree) || 0,
            parentId: node.parentId || node.payload?.parentId || '',
            ancestryLabels: node.ancestryLabels || node.payload?.ancestryLabels || [],
            directChildIds: node.directChildIds || node.payload?.directChildIds || [],
          },
        };
      }),
      edges: data.edges.map((edge) => ({
        id: String(edge.id),
        source: endpointId(edge.source),
        target: endpointId(edge.target),
        data: {
          relation: '',
          kind: edge.kind || 'taxonomy-parent',
        },
      })),
    };
  }

  function createForceLayout(nodeById, width, height) {
    const resolve = (value) => {
      const id = endpointId(value);
      return nodeById.get(id) || (value && typeof value === 'object' ? value : null);
    };
    return {
      type: 'd3-force',
      iterations: 600,
      alpha: 1,
      alphaMin: 0.001,
      alphaDecay: 0.035,
      velocityDecay: 0.28,
      link: {
        distance: (edge) => {
          const target = resolve(edge?.target);
          const depth = levelOf(target);
          if (depth <= 1) return 230;
          if (depth === 2) return 180;
          if (depth === 3) return 125;
          if (depth === 4) return 58;
          return 38;
        },
        strength: (edge) => {
          const target = resolve(edge?.target);
          const depth = levelOf(target);
          if (depth >= 5) return 0.55;
          if (depth === 4) return 0.42;
          return 0.2;
        },
        iterations: 3,
      },
      manyBody: {
        strength: (node) => {
          const resolved = resolve(node) || node;
          const depth = levelOf(resolved);
          if (depth <= 0) return -1100;
          if (depth === 1) return -820;
          if (depth === 2) return -520;
          if (depth === 3) return -300;
          if (depth === 4) return -92;
          return -55;
        },
        distanceMax: Math.max(width, height) * 1.4,
      },
      collide: {
        radius: (node) => nodeSize(resolve(node) || node) / 2 + (levelOf(resolve(node) || node) <= 3 ? 13 : 7),
        strength: 1,
        iterations: 8,
      },
    };
  }

  function createRenderer(options = {}) {
    const instanceId = `taxonomy-dynamic-prototype-${++sequence}`;
    let graph = null;
    const acceptData = (input) => (
      input?.report && input?.graphId ? input : contract.normalizeGraphData(input || {})
    );
    let masterData = acceptData(options.data);
    let visibleData = masterData;
    let nodeById = new Map();
    const expandedFamilyIds = new Set();
    const callbacks = options.callbacks && typeof options.callbacks === 'object' ? options.callbacks : {};
    const counters = {
      create: 0,
      destroy: 0,
      render: 0,
      setData: 0,
      draw: 0,
      layoutStart: 0,
      click: 0,
      hover: 0,
    };

    function computeVisibleData() {
      const state = masterData.legend?.state || {};
      const nodes = masterData.nodes.filter((node) => {
        const depth = levelOf(node);
        if (depth > 4) return false;
        const key = node.legendKeys?.[0];
        return !key || state[key] !== false;
      });
      const ids = new Set(nodes.map((node) => String(node.id)));
      for (const familyId of expandedFamilyIds) {
        if (!ids.has(familyId)) continue;
        for (const node of masterData.nodes) {
          if (levelOf(node) === 5 && String(node.parentId || node.payload?.parentId || '') === familyId) {
            nodes.push(node);
            ids.add(String(node.id));
          }
        }
      }
      const edges = masterData.edges.filter((edge) => (
        ids.has(endpointId(edge.source)) && ids.has(endpointId(edge.target))
      ));
      return { ...masterData, nodes, edges };
    }

    function rebuildIndex() {
      nodeById = new Map(visibleData.nodes.map((node) => [String(node.id), node]));
    }

    function makeGraphData() {
      return projectData(visibleData);
    }

    function graphOptions() {
      const container = options.container;
      const width = Number(options.width || container?.clientWidth || container?.offsetWidth || 0);
      const height = Number(options.height || container?.clientHeight || container?.offsetHeight || 0);
      return {
        container,
        width,
        height,
        autoResize: true,
        autoFit: {
          type: 'view',
          options: { when: 'overflow' },
        },
        padding: 72,
        animation: false,
        cursor: 'grab',
        data: makeGraphData(),
        layout: createForceLayout(nodeById, width, height),
        node: {
          type: 'circle',
          style: {
            size: (datum) => datum.style?.size || 14,
            fill: (datum) => datum.style?.fill || '#2563eb',
            stroke: (datum) => datum.style?.stroke || '#1d4ed8',
            lineWidth: (datum) => datum.style?.lineWidth || 1.2,
            labelText: (datum) => {
              const depth = levelOf(datum);
              return depth <= 3 ? String(datum.data?.displayLabel || datum.data?.rawLabel || datum.id) : '';
            },
            labelPlacement: 'center',
            labelFill: '#172033',
            labelFontSize: (datum) => (levelOf(datum) <= 1 ? 14 : 11),
            labelFontWeight: 700,
            labelStroke: '#ffffff',
            labelLineWidth: 3,
          },
          state: {
            selected: {
              halo: true,
              haloFill: 'rgba(37, 99, 235, 0.16)',
              lineWidth: 3,
              stroke: '#172554',
            },
            active: {
              lineWidth: 2.4,
              stroke: '#0f766e',
            },
          },
        },
        edge: {
          type: 'line',
          style: {
            stroke: '#9fb2d8',
            lineWidth: 1.1,
            opacity: 0.58,
            labelText: '',
          },
          state: {
            active: {
              stroke: '#2563eb',
              lineWidth: 2,
              opacity: 0.9,
            },
          },
        },
        behaviors: [
          'drag-canvas',
          {
            type: 'drag-element-force',
            trigger: [],
            enable: (event) => event.targetType === 'node',
          },
          { type: 'zoom-canvas', sensitivity: 1.14 },
          {
            type: 'click-select',
            degree: 1,
            state: 'selected',
            neighborState: 'active',
          },
        ],
      };
    }

    function bindEvents(activeGraph) {
      activeGraph.on('node:click', async (event) => {
        const id = endpointId(event?.target?.id);
        if (!id) return;
        counters.click += 1;
        const node = activeGraph.getNodeData?.(id) || null;
        if (typeof callbacks.onNodeClick === 'function') await callbacks.onNodeClick(node, { nodeId: id });
      });
      activeGraph.on(NodeEvent.POINTER_ENTER, (event) => {
        const id = endpointId(event?.target?.id);
        if (!id) return;
        counters.hover += 1;
        const clientX = Number(event?.clientX ?? event?.originalEvent?.clientX);
        const clientY = Number(event?.clientY ?? event?.originalEvent?.clientY);
        const client = Number.isFinite(clientX) && Number.isFinite(clientY) ? { x: clientX, y: clientY } : null;
        callbacks.onNodeHover?.(activeGraph.getNodeData?.(id) || null, { nodeId: id, client });
      });
      activeGraph.on(NodeEvent.POINTER_LEAVE, (event) => {
        counters.hover += 1;
        callbacks.onNodeHover?.(null, { nodeId: endpointId(event?.target?.id) });
      });
    }

    async function render(nextData = masterData) {
      masterData = acceptData(nextData);
      expandedFamilyIds.clear();
      visibleData = computeVisibleData();
      rebuildIndex();
      if (graph) destroy();
      graph = new Graph(graphOptions());
      createdInstanceIds.add(instanceId);
      liveInstanceIds.add(instanceId);
      counters.create += 1;
      counters.render += 1;
      counters.layoutStart += 1;
      bindEvents(graph);
      await graph.render();
      callbacks.onRenderStats?.({
        nodes: visibleData.nodes.length,
        edges: visibleData.edges.length,
        graphId: masterData.graphId,
      });
      return true;
    }

    async function replaceVisibleData() {
      if (!graph) return false;
      visibleData = computeVisibleData();
      rebuildIndex();
      graph.setData(makeGraphData());
      counters.setData += 1;
      await graph.draw();
      counters.draw += 1;
      if (typeof graph.setLayout === 'function' && typeof graph.layout === 'function') {
        const container = options.container;
        const width = Number(options.width || container?.clientWidth || 0);
        const height = Number(options.height || container?.clientHeight || 0);
        graph.setLayout(createForceLayout(nodeById, width, height));
        counters.layoutStart += 1;
        await graph.layout();
      }
      return true;
    }

    async function setLegendState(nextState = {}) {
      masterData = {
        ...masterData,
        legend: {
          ...(masterData.legend || {}),
          state: { ...(masterData.legend?.state || {}), ...(nextState || {}) },
        },
      };
      return replaceVisibleData();
    }

    async function expandFamily(familyId) {
      const id = String(familyId || '');
      if (expandedFamilyIds.has(id)) return false;
      const family = masterData.nodes.find((node) => String(node.id) === id && levelOf(node) === 4);
      const hasChildren = masterData.nodes.some((node) => (
        levelOf(node) === 5 && String(node.parentId || node.payload?.parentId || '') === id
      ));
      if (!family || !hasChildren) return false;
      expandedFamilyIds.add(id);
      return replaceVisibleData();
    }

    async function collapseFamily(familyId) {
      const id = String(familyId || '');
      if (!expandedFamilyIds.delete(id)) return false;
      return replaceVisibleData();
    }

    function destroy() {
      if (!graph) return false;
      graph.destroy?.();
      graph = null;
      liveInstanceIds.delete(instanceId);
      destroyedInstanceIds.add(instanceId);
      counters.destroy += 1;
      return true;
    }

    function getDiagnostics() {
      return {
        instanceId,
        graphId: masterData.graphId,
        source: String(masterData.meta?.treeVariant || ''),
        sourceKind: String(masterData.meta?.source || ''),
        master: { nodes: masterData.nodes.length, edges: masterData.edges.length },
        visible: { nodes: visibleData.nodes.length, edges: visibleData.edges.length },
        expandedFamilyIds: [...expandedFamilyIds],
        counters: { ...counters },
        prototype: 'ordinary-dynamic-force',
        live: !!graph,
      };
    }

    return {
      render,
      destroy,
      getGraph: () => graph,
      getData: () => visibleData,
      getMasterData: () => masterData,
      getDiagnostics,
      setLegendState,
      setLegendFocus: () => Promise.resolve(true),
      expandFamily,
      collapseFamily,
      redraw: () => (graph?.draw ? graph.draw() : Promise.resolve(false)),
    };
  }

  window.__TEKG_TAXONOMY_DYNAMIC_PROTOTYPE = {
    createRenderer,
    getLifecycleDiagnostics() {
      return {
        createdInstanceIds: [...createdInstanceIds],
        destroyedInstanceIds: [...destroyedInstanceIds],
        liveInstanceIds: [...liveInstanceIds],
        liveInstanceCount: liveInstanceIds.size,
        events: [],
      };
    },
  };
}());
