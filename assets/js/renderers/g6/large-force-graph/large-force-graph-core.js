(function () {
  if (window.__TEKG_RENDERER_MODE && window.__TEKG_RENDERER_MODE !== 'g6') return;

  const contract = window.__TEKG_LARGE_FORCE_GRAPH_CONTRACT;
  const style = window.__TEKG_LARGE_FORCE_GRAPH_STYLES;
  const layout = window.__TEKG_LARGE_FORCE_GRAPH_LAYOUT;
  const G6Lib = window.G6;
  if (!contract || !style || !layout || !G6Lib || typeof G6Lib.Graph !== 'function') return;

  const { Graph, NodeEvent } = G6Lib;
  let rendererSequence = 0;
  const createdInstanceIds = new Set();
  const destroyedInstanceIds = new Set();
  const liveInstanceIds = new Set();
  const lifecycleEvents = [];

  function recordLifecycle(type, instanceId) {
    if (type === 'created') {
      createdInstanceIds.add(instanceId);
      liveInstanceIds.add(instanceId);
    } else if (type === 'destroyed') {
      destroyedInstanceIds.add(instanceId);
      liveInstanceIds.delete(instanceId);
    }
    lifecycleEvents.push({ type, instanceId });
  }

  function getLifecycleDiagnostics() {
    return {
      createdInstanceIds: [...createdInstanceIds],
      destroyedInstanceIds: [...destroyedInstanceIds],
      liveInstanceIds: [...liveInstanceIds],
      liveInstanceCount: liveInstanceIds.size,
      events: lifecycleEvents.map((event) => ({ ...event })),
    };
  }

  function resolveEventNodeId(event) {
    const directId = event?.target?.id;
    if (directId && typeof directId === 'string') return directId;
    const candidateIds = [
      event?.target?.config?.id,
      event?.target?.context?.element?.id,
      event?.target?.attributes?.id,
      event?.target?.style?.id,
    ];
    for (const value of candidateIds) {
      if (value && typeof value === 'string') return value;
    }
    return '';
  }

  function toG6Data(data) {
    return {
      nodes: data.nodes.map((node) => ({
        id: node.id,
        label: node.label,
        level: node.level,
        legendKeys: node.legendKeys,
        pinnedLabel: node.pinnedLabel === true,
        starLabel: node.starLabel === true,
        parentId: node.parentId || node.payload?.parentId || '',
        ancestryIds: node.ancestryIds || node.payload?.ancestryIds || [],
        ancestryLabels: node.ancestryLabels || node.payload?.ancestryLabels || [],
        directChildIds: node.directChildIds || node.payload?.directChildIds || [],
        degree: Number(node.degree ?? node.payload?.degree) || 0,
        displayLabel: node.displayLabel || node.label,
        style: {
          x: Number(node.x) || 0,
          y: Number(node.y) || 0,
          clusterX: Number(node.clusterX ?? node.x) || 0,
          clusterY: Number(node.clusterY ?? node.y) || 0,
          size: Number(node.size) || 16,
          fill: node.color || '#bfdbfe',
          stroke: node.stroke || '#1d4ed8',
          lineWidth: Number(node.strokeWidth) || (node.id === data.rootId ? 4 : 1.8),
          lineDash: Array.isArray(node.lineDash) ? node.lineDash : [],
        },
        data: {
          ...(node.payload || {}),
          id: node.id,
          rawLabel: node.rawLabel || node.label,
          displayLabel: node.displayLabel || node.label,
          queryLabel: node.payload?.queryLabel || node.queryLabel || '',
          description: node.description || node.payload?.description || '',
          treeDepth: node.level,
          taxonomyLevelKey: node.legendKeys?.[0] || '',
          taxonomyLevelLabel: node.payload?.taxonomyLevelLabel || '',
          taxonomyOnly: node.payload?.taxonomyOnly === true,
          hasGraphEntity: node.payload?.hasGraphEntity === true,
          isRoot: node.payload?.isRoot === true || node.level === 0,
          pinnedLabel: node.pinnedLabel === true,
          starLabel: node.starLabel === true,
          parentId: node.parentId || node.payload?.parentId || '',
          ancestryIds: node.ancestryIds || node.payload?.ancestryIds || [],
          ancestryLabels: node.ancestryLabels || node.payload?.ancestryLabels || [],
          directChildIds: node.directChildIds || node.payload?.directChildIds || [],
          degree: Number(node.degree ?? node.payload?.degree) || 0,
          legendKeys: node.legendKeys || [],
        },
      })),
      edges: data.edges.map((edge) => ({
        id: edge.id,
        source: edge.source,
        target: edge.target,
        kind: edge.kind,
        data: {
          ...(edge.payload || {}),
          relation: edge.label || edge.kind || '',
          kind: edge.kind,
          legendKeys: edge.legendKeys || [],
        },
      })),
    };
  }

  function createRenderer(options = {}) {
    let graph = null;
    let masterData = contract.normalizeGraphData(options.data || {});
    let sourceScopeKey = `${masterData.graphId || ''}::${masterData.meta?.treeVariant || ''}`;
    let visibleData = contract.filterByLegend(masterData, masterData.legend?.state || {});
    const expandedFamilyIds = new Set();
    let nodeById = new Map();
    let neighborsByNodeId = new Map();
    let incidentEdgeIdsByNodeId = new Map();
    let nodeIdsByLegendKey = new Map();
    let hoverTouched = new Map();
    let legendFocusTouched = new Set();
    let interactionEpoch = 0;
    let lifecycleEpoch = 0;
    let motionEpoch = 0;
    let motionTimer = null;
    let motionStartedAt = 0;
    let motionReleasedAt = 0;
    let activeMotionCount = 0;
    let lastStopMs = null;
    let activeDragNodeId = '';
    let activeDragSystemId = '';
    let initialSettleTimer = null;
    let initialSettleEpoch = 0;
    let initialSettleStartedAt = 0;
    let activeInitialSettleCount = 0;
    let lastInitialSettleMs = null;
    let initialSettleStopReason = null;
    let initialLayoutType = 'preset';
    const instanceId = `large-force-renderer-${++rendererSequence}`;
    const counters = {
      create: 0, destroy: 0, render: 0, setData: 0, draw: 0, layoutStart: 0,
      hover: 0, click: 0, dragStart: 0, dragEnd: 0,
      motionStart: 0, motionTick: 0, motionStop: 0, forcedStop: 0,
    };
    const lastTimings = {};
    const callbacks = options.callbacks && typeof options.callbacks === 'object' ? options.callbacks : {};
    const state = {
      legendState: { ...(masterData.legend?.state || {}) },
      legendFocus: options.legendFocus || null,
      hoverNodeId: null,
      selectedNodeId: null,
      dragging: false,
      nodeById,
      expandedFamilyIds,
    };

    function computeVisibleData() {
      const legendVisible = contract.filterByLegend(masterData, state.legendState);
      const baseNodes = legendVisible.nodes.filter((node) => Number(node.level) <= 4);
      const visibleIds = new Set(baseNodes.map((node) => String(node.id)));
      for (const familyId of expandedFamilyIds) {
        if (!visibleIds.has(familyId)) continue;
        for (const node of masterData.nodes) {
          if (Number(node.level) === 5 && String(node.payload?.parentId || node.parentId || '') === familyId) {
            baseNodes.push(node);
            visibleIds.add(String(node.id));
          }
        }
      }
      const edges = masterData.edges.filter((edge) => (
        visibleIds.has(contract.endpointId(edge.source)) && visibleIds.has(contract.endpointId(edge.target))
      ));
      return { ...masterData, nodes: baseNodes, edges };
    }

    function rebuildIndexes() {
      nodeById = new Map();
      neighborsByNodeId = new Map();
      incidentEdgeIdsByNodeId = new Map();
      nodeIdsByLegendKey = new Map();
      for (const node of visibleData.nodes) {
        const id = String(node.id);
        nodeById.set(id, node);
        neighborsByNodeId.set(id, new Set());
        incidentEdgeIdsByNodeId.set(id, new Set());
        for (const key of node.legendKeys || []) {
          if (!nodeIdsByLegendKey.has(key)) nodeIdsByLegendKey.set(key, new Set());
          nodeIdsByLegendKey.get(key).add(id);
        }
      }
      for (const edge of visibleData.edges) {
        const source = contract.endpointId(edge.source);
        const target = contract.endpointId(edge.target);
        if (!nodeById.has(source) || !nodeById.has(target)) continue;
        neighborsByNodeId.get(source).add(target);
        neighborsByNodeId.get(target).add(source);
        incidentEdgeIdsByNodeId.get(source).add(String(edge.id));
        incidentEdgeIdsByNodeId.get(target).add(String(edge.id));
      }
      state.nodeById = nodeById;
      state.neighborsByNodeId = neighborsByNodeId;
      state.incidentEdgeIdsByNodeId = incidentEdgeIdsByNodeId;
      state.nodeIdsByLegendKey = nodeIdsByLegendKey;
    }

    function eventClient(event) {
      const x = Number(event?.client?.x ?? event?.clientX ?? event?.originalEvent?.clientX);
      const y = Number(event?.client?.y ?? event?.clientY ?? event?.originalEvent?.clientY);
      return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : null;
    }

    async function setTaskStates(nextStates) {
      if (!graph || typeof graph.setElementState !== 'function' || !nextStates.size) return;
      const updates = {};
      for (const [id, taskState] of nextStates) {
        const current = typeof graph.getElementState === 'function' ? graph.getElementState(id) : [];
        const preserved = (Array.isArray(current) ? current : [current]).filter((name) => (
          name && name !== 'hover' && name !== 'neighbor' && name !== 'incident'
        ));
        updates[id] = taskState ? [...preserved, taskState] : preserved;
      }
      await graph.setElementState(updates, false);
    }

    function hoverStates(nodeId) {
      const next = new Map([[nodeId, 'hover']]);
      for (const neighborId of neighborsByNodeId.get(nodeId) || []) next.set(neighborId, 'neighbor');
      for (const edgeId of incidentEdgeIdsByNodeId.get(nodeId) || []) next.set(edgeId, 'incident');
      return next;
    }

    function stopTransientMotion(reason = 'forced') {
      if (motionTimer) clearTimeout(motionTimer);
      motionTimer = null;
      motionEpoch += 1;
      if (!activeMotionCount) return false;
      const activeGraph = graph;
      if (activeGraph && typeof activeGraph.stopLayout === 'function') activeGraph.stopLayout();
      if (activeGraph && typeof activeGraph.setLayout === 'function') activeGraph.setLayout({ type: 'preset' });
      activeMotionCount = 0;
      state.dragging = false;
      counters.motionStop += 1;
      if (reason !== 'cooled') counters.forcedStop += 1;
      const reference = motionReleasedAt || motionStartedAt;
      lastStopMs = reference ? Math.max(0, Date.now() - reference) : 0;
      return true;
    }

    function stopInitialSettle(reason = 'completed') {
      if (initialSettleTimer) clearTimeout(initialSettleTimer);
      initialSettleTimer = null;
      initialSettleEpoch += 1;
      if (!activeInitialSettleCount) return false;
      const activeGraph = graph;
      if (reason === 'deadline' && activeGraph && typeof activeGraph.stopLayout === 'function') {
        activeGraph.stopLayout();
      }
      if (activeGraph && typeof activeGraph.setLayout === 'function') activeGraph.setLayout({ type: 'preset' });
      activeInitialSettleCount = 0;
      lastInitialSettleMs = initialSettleStartedAt ? Math.max(0, Date.now() - initialSettleStartedAt) : 0;
      initialSettleStopReason = reason;
      return true;
    }

    function startInitialSettle(graphLayout) {
      initialLayoutType = String(graphLayout?.type || 'preset');
      if (initialLayoutType === 'preset') {
        activeInitialSettleCount = 0;
        lastInitialSettleMs = 0;
        initialSettleStopReason = 'preset';
        return false;
      }
      const epoch = ++initialSettleEpoch;
      initialSettleStartedAt = Date.now();
      activeInitialSettleCount = 1;
      lastInitialSettleMs = null;
      initialSettleStopReason = null;
      const deadline = Math.min(780, Math.max(1, Number(options.initialSettleDeadlineMs ?? 700) || 700));
      initialSettleTimer = setTimeout(() => {
        if (epoch === initialSettleEpoch) stopInitialSettle('deadline');
      }, deadline);
      return true;
    }

    function startTransientMotion(draggedNodeId = activeDragNodeId) {
      const resolvedDragNodeId = String(draggedNodeId || '');
      const draggedNode = nodeById.get(resolvedDragNodeId);
      const draggedDepth = Number(draggedNode?.level ?? draggedNode?.payload?.treeDepth ?? 0);
      activeDragNodeId = resolvedDragNodeId;
      activeDragSystemId = String(
        draggedNode?.superfamilyId
        || draggedNode?.payload?.superfamilyId
        || (draggedDepth === 3 ? draggedNode?.id : '')
        || '',
      );
      if (activeMotionCount) {
        if (!motionTimer) return true;
        clearTimeout(motionTimer);
        motionTimer = null;
        motionReleasedAt = 0;
        state.dragging = true;
        if (typeof graph.stopLayout === 'function') graph.stopLayout();
        const restartEpoch = ++motionEpoch;
        const restartGraph = graph;
        counters.motionStart += 1;
        counters.layoutStart += 1;
        graph.setLayout(layout.createTransientDragLayout({
          nodeById,
          activeSystemId: activeDragSystemId,
          onTick: () => {
            if (restartEpoch === motionEpoch && activeMotionCount) counters.motionTick += 1;
          },
        }));
        Promise.resolve(graph.layout()).catch(() => {
          if (restartEpoch === motionEpoch && graph === restartGraph) stopTransientMotion('layout-error');
        });
        return true;
      }
      if (!graph || typeof layout.createTransientDragLayout !== 'function') return false;
      if (typeof graph.setLayout !== 'function' || typeof graph.layout !== 'function' || typeof graph.stopLayout !== 'function') return false;
      const epoch = ++motionEpoch;
      motionStartedAt = Date.now();
      motionReleasedAt = 0;
      lastStopMs = null;
      activeMotionCount = 1;
      counters.motionStart += 1;
      counters.layoutStart += 1;
      const transientLayout = layout.createTransientDragLayout({
        nodeById,
        activeSystemId: activeDragSystemId,
        onTick: () => {
          if (epoch === motionEpoch && activeMotionCount) counters.motionTick += 1;
        },
      });
      const activeGraph = graph;
      graph.setLayout(transientLayout);
      Promise.resolve(graph.layout()).catch(() => {
        if (epoch === motionEpoch && graph === activeGraph) stopTransientMotion('layout-error');
      });
      return true;
    }

    function coolTransientMotion() {
      if (!activeMotionCount) {
        state.dragging = false;
        return false;
      }
      motionReleasedAt = Date.now();
      const delay = Math.min(700, Math.max(0, Number(options.motionStopDelayMs ?? 600) || 0));
      const epoch = motionEpoch;
      motionTimer = setTimeout(() => {
        if (epoch === motionEpoch) stopTransientMotion('cooled');
      }, delay);
      return true;
    }

    function redraw() {
      if (!graph || typeof graph.draw !== 'function') return Promise.resolve(false);
      counters.draw += 1;
      return Promise.resolve(graph.draw()).then(() => true);
    }

    function destroy() {
      if (!graph) return false;
      stopInitialSettle('destroy');
      stopTransientMotion('destroy');
      lifecycleEpoch += 1;
      interactionEpoch += 1;
      state.hoverNodeId = null;
      hoverTouched = new Map();
      legendFocusTouched = new Set();
      if (typeof graph.destroy === 'function') graph.destroy();
      counters.destroy += 1;
      graph = null;
      recordLifecycle('destroyed', instanceId);
      return true;
    }

    function getNodeData(nodeId) {
      if (!graph || typeof graph.getNodeData !== 'function') return null;
      return graph.getNodeData(nodeId);
    }

    async function render(nextData = masterData) {
      const started = Date.now();
      counters.render += 1;
      lifecycleEpoch += 1;
      interactionEpoch += 1;
      const nextMasterData = contract.normalizeGraphData(nextData || {});
      const nextSourceScopeKey = `${nextMasterData.graphId || ''}::${nextMasterData.meta?.treeVariant || ''}`;
      if (nextSourceScopeKey !== sourceScopeKey) expandedFamilyIds.clear();
      sourceScopeKey = nextSourceScopeKey;
      masterData = nextMasterData;
      state.legendState = { ...(masterData.legend?.state || state.legendState || {}) };
      visibleData = computeVisibleData();
      rebuildIndexes();
      if (typeof callbacks.onDroppedData === 'function' && masterData.report && (masterData.report.droppedNodes || masterData.report.droppedEdges)) {
        callbacks.onDroppedData(masterData.report);
      }

      destroy();
      const epoch = ++lifecycleEpoch;
      const container = options.container;
      if (!container) return false;
      const width = Number(options.width || container.clientWidth || container.offsetWidth || 0);
      const height = Number(options.height || container.clientHeight || container.offsetHeight || 0);
      const rightClearance = Math.min(500, Math.max(80, Number(options.rightClearance ?? width * 0.32) || 80));
      const rootId = String(visibleData.rootId || visibleData.nodes.find((node) => node.level === 0)?.id || '');
      const g6Data = toG6Data(visibleData);
      const graphLayout = layout.createLayout(masterData.options?.layoutProfile || 'taxonomy-large', {
        nodeById,
        performanceProfile: masterData.options?.performanceProfile,
      });
      const dragBehavior = graphLayout.type === 'preset' ? 'drag-element' : 'drag-element-force';

      graph = new Graph({
        container,
        width,
        height,
        autoResize: true,
        autoFit: 'view',
        padding: [56, rightClearance, 56, 56],
        animation: false,
        cursor: 'grab',
        data: g6Data,
        node: {
          type: 'circle',
          style: {
            x: (datum) => datum.style?.x,
            y: (datum) => datum.style?.y,
            size: (datum) => datum.style?.size || 16,
            fill: (datum) => datum.style?.fill || '#bfdbfe',
            stroke: (datum) => datum.style?.stroke || '#1d4ed8',
            lineWidth: (datum) => datum.style?.lineWidth || (datum.id === rootId ? 4 : 1.8),
            lineDash: (datum) => datum.style?.lineDash || [],
            opacity: (datum) => style.nodeOpacity(datum, state),
            fillOpacity: (datum) => style.nodeOpacity(datum, state),
            labelText: (datum) => style.displayLabel(datum, state),
            labelPlacement: 'center',
            labelFill: (datum) => (datum.id === rootId ? '#ffffff' : '#0f172a'),
            labelOpacity: (datum) => style.nodeOpacity(datum, state),
            labelFontSize: style.labelFontSize,
            labelFontWeight: (datum) => (datum.id === rootId ? 800 : 650),
            labelStroke: '#ffffff',
            labelLineWidth: (datum) => (datum.id === rootId ? 0 : 2),
          },
          state: {
            hover: { lineWidth: 3, shadowColor: '#2563eb', shadowBlur: 8 },
            neighbor: { lineWidth: 2.4, stroke: '#2563eb' },
            'legend-focus': { lineWidth: 3.2, stroke: '#0f766e', shadowColor: '#14b8a6', shadowBlur: 7 },
          },
        },
        edge: {
          type: 'line',
          style: {
            stroke: '#93c5fd',
            lineWidth: 1.05,
            opacity: (datum) => style.edgeOpacity(datum, state),
            labelText: '',
          },
          state: {
            incident: { stroke: '#2563eb', lineWidth: 2, opacity: 0.9 },
            'legend-focus': { stroke: '#0f766e', lineWidth: 2.2, opacity: 0.9 },
          },
        },
        layout: graphLayout,
        behaviors: [
          { type: dragBehavior, trigger: [], enable: (event) => event.targetType === 'node' },
          'drag-canvas',
          { type: 'zoom-canvas', sensitivity: 1 },
          { type: 'click-select', enable: (event) => event.targetType === 'node' },
        ],
      });
      counters.create += 1;
      recordLifecycle('created', instanceId);
      if (graphLayout.type !== 'preset') counters.layoutStart += 1;

      startInitialSettle(graphLayout);
      await graph.render();
      stopInitialSettle('completed');
      if (epoch !== lifecycleEpoch || !graph) return false;

      graph.on(NodeEvent.DRAG_START, async (event) => {
        counters.dragStart += 1;
        state.dragging = true;
        startTransientMotion(resolveEventNodeId(event));
      });
      graph.on(NodeEvent.DRAG_END, async () => {
        counters.dragEnd += 1;
        coolTransientMotion();
      });
      graph.on(NodeEvent.POINTER_ENTER, async (event) => {
        const nodeId = resolveEventNodeId(event);
        if (!nodeId) return;
        const client = eventClient(event);
        if (state.hoverNodeId === nodeId) return;
        counters.hover += 1;
        const epoch = ++interactionEpoch;
        const activeGraph = graph;
        const nextTouched = hoverStates(nodeId);
        const updates = new Map([...hoverTouched.keys()].map((id) => [id, null]));
        for (const [id, taskState] of nextTouched) updates.set(id, taskState);
        state.hoverNodeId = nodeId;
        hoverTouched = nextTouched;
        await setTaskStates(updates);
        if (epoch !== interactionEpoch || graph !== activeGraph || state.hoverNodeId !== nodeId) return;
        if (typeof callbacks.onNodeHover === 'function') callbacks.onNodeHover(getNodeData(nodeId), { nodeId, client });
      });
      graph.on(NodeEvent.POINTER_LEAVE, async (event) => {
        const nodeId = resolveEventNodeId(event);
        if (!nodeId || state.hoverNodeId !== nodeId) return;
        counters.hover += 1;
        const client = eventClient(event);
        interactionEpoch += 1;
        const touchedToClear = new Map([...hoverTouched.keys()].map((id) => [id, null]));
        hoverTouched = new Map();
        state.hoverNodeId = null;
        if (typeof callbacks.onNodeHover === 'function') callbacks.onNodeHover(null, { nodeId, client });
        await setTaskStates(touchedToClear);
      });
      graph.on('node:click', async (event) => {
        const nodeId = resolveEventNodeId(event);
        if (!nodeId) return;
        counters.click += 1;
        state.selectedNodeId = nodeId;
        const nodeData = getNodeData(nodeId);
        if (typeof callbacks.onNodeClick === 'function') await callbacks.onNodeClick(nodeData, { nodeId });
        if (typeof callbacks.onNodeSelect === 'function') callbacks.onNodeSelect(nodeData, { nodeId });
      });

      if (typeof callbacks.onRenderStats === 'function') {
        callbacks.onRenderStats({ nodes: visibleData.nodes.length, edges: visibleData.edges.length, graphId: masterData.graphId });
      }
      lastTimings.renderMs = Date.now() - started;
      return true;
    }

    async function setLegendState(nextState = {}) {
      const started = Date.now();
      stopTransientMotion('legend-state');
      state.legendState = { ...state.legendState, ...(nextState || {}) };
      visibleData = computeVisibleData();
      rebuildIndexes();
      interactionEpoch += 1;
      state.hoverNodeId = null;
      hoverTouched = new Map();
      const epoch = ++lifecycleEpoch;
      const activeGraph = graph;
      if (!activeGraph || typeof activeGraph.setData !== 'function' || typeof activeGraph.draw !== 'function') return false;
      counters.setData += 1;
      activeGraph.setData(toG6Data(visibleData));
      legendFocusTouched = new Set();
      counters.draw += 1;
      await activeGraph.draw();
      if (epoch !== lifecycleEpoch || graph !== activeGraph) return false;
      await reconcileSelection(activeGraph);
      if (epoch !== lifecycleEpoch || graph !== activeGraph) return false;
      await applyLegendFocus(state.legendFocus, activeGraph, true);
      if (epoch !== lifecycleEpoch || graph !== activeGraph) return false;
      lastTimings.setLegendStateMs = Date.now() - started;
      if (typeof callbacks.onRenderStats === 'function') {
        callbacks.onRenderStats({ nodes: visibleData.nodes.length, edges: visibleData.edges.length, graphId: masterData.graphId });
      }
      return true;
    }

    async function replaceVisibleData() {
      stopTransientMotion('visibility');
      visibleData = computeVisibleData();
      rebuildIndexes();
      interactionEpoch += 1;
      const epoch = ++lifecycleEpoch;
      const activeGraph = graph;
      if (!activeGraph || typeof activeGraph.setData !== 'function' || typeof activeGraph.draw !== 'function') return false;
      counters.setData += 1;
      activeGraph.setData(toG6Data(visibleData));
      counters.draw += 1;
      await activeGraph.draw();
      if (epoch !== lifecycleEpoch || graph !== activeGraph) return false;
      await reconcileSelection(activeGraph);
      return epoch === lifecycleEpoch && graph === activeGraph;
    }

    async function reconcileSelection(activeGraph = graph) {
      const selectedId = String(state.selectedNodeId || '');
      if (!selectedId) return true;
      if (!nodeById.has(selectedId)) {
        state.selectedNodeId = null;
        return true;
      }
      if (!activeGraph || typeof activeGraph.setElementState !== 'function') return false;
      const current = typeof activeGraph.getElementState === 'function'
        ? activeGraph.getElementState(selectedId)
        : [];
      const states = new Set(Array.isArray(current) ? current : [current]);
      states.add('selected');
      await activeGraph.setElementState(selectedId, [...states].filter(Boolean), false);
      return graph === activeGraph;
    }

    async function expandFamily(familyId) {
      const id = String(familyId || '');
      const family = masterData.nodes.find((node) => String(node.id) === id);
      if (!family || Number(family.level) !== 4 || expandedFamilyIds.has(id)) return false;
      const hasDirectChild = masterData.nodes.some((node) => (
        Number(node.level) === 5 && String(node.payload?.parentId || node.parentId || '') === id
      ));
      if (!hasDirectChild) return false;
      expandedFamilyIds.add(id);
      const applied = await replaceVisibleData();
      if (!applied) expandedFamilyIds.delete(id);
      return applied;
    }

    async function collapseFamily(familyId) {
      const id = String(familyId || '');
      if (!expandedFamilyIds.has(id)) return false;
      expandedFamilyIds.delete(id);
      const applied = await replaceVisibleData();
      if (!applied) expandedFamilyIds.add(id);
      return applied;
    }

    async function applyLegendFocus(nextKey, activeGraph = graph, dataWasReplaced = false) {
      const key = String(nextKey || '').trim() || null;
      state.legendFocus = key;
      if (!activeGraph || typeof activeGraph.setElementState !== 'function') {
        legendFocusTouched = new Set();
        return false;
      }
      const currentTouched = new Set();
      if (key) {
        for (const nodeId of nodeIdsByLegendKey.get(key) || []) {
          currentTouched.add(nodeId);
          for (const edgeId of incidentEdgeIdsByNodeId.get(nodeId) || []) currentTouched.add(edgeId);
        }
      }
      const previousTouched = dataWasReplaced ? new Set() : legendFocusTouched;
      const touched = new Set([...previousTouched, ...currentTouched]);
      legendFocusTouched = currentTouched;
      if (!touched.size) return true;
      const updates = {};
      for (const id of touched) {
        const current = typeof activeGraph.getElementState === 'function' ? activeGraph.getElementState(id) : [];
        const preserved = (Array.isArray(current) ? current : [current]).filter((name) => name && name !== 'legend-focus');
        updates[id] = currentTouched.has(id) ? [...preserved, 'legend-focus'] : preserved;
      }
      await activeGraph.setElementState(updates, false);
      return graph === activeGraph;
    }

    function setLegendFocus(nextKey = null) {
      return applyLegendFocus(nextKey);
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
        lastTimings: { ...lastTimings },
        activeMotionCount,
        lastStopMs,
        activeDragNodeId,
        activeDragSystemId,
        initialLayoutType,
        activeInitialSettleCount,
        lastInitialSettleMs,
        initialSettleStopReason,
        live: !!graph,
      };
    }

    return {
      render,
      destroy,
      redraw,
      getGraph() {
        return graph;
      },
      getData() {
        return visibleData;
      },
      getMasterData() {
        return masterData;
      },
      getState() {
        return { ...state };
      },
      getDiagnostics,
      setLegendState,
      setLegendFocus,
      expandFamily,
      collapseFamily,
    };
  }

  window.__TEKG_LARGE_FORCE_GRAPH_CORE = {
    createRenderer,
    getLifecycleDiagnostics,
  };
}());
