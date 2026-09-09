(function () {
  if (window.__TEKG_RENDERER_MODE !== 'g6') return;

  const G6Lib = window.G6;
  if (!G6Lib) return;

  const {
    Graph,
    treeToGraphData,
    register,
    ExtensionCategory,
    BaseNode,
    BaseBehavior,
    Badge,
    CanvasEvent,
    CommonEvent,
    NodeEvent,
    Polyline,
    subStyleProps,
  } = G6Lib;

  if (
    typeof Graph !== 'function' ||
    typeof treeToGraphData !== 'function' ||
    typeof register !== 'function' ||
    !ExtensionCategory ||
    !BaseNode ||
    !BaseBehavior ||
    !Badge ||
    !Polyline
  ) {
    return;
  }

  const COLORS = [
    '#5B8FF9',
    '#F6BD16',
    '#5AD8A6',
    '#945FB9',
    '#E86452',
    '#6DC8EC',
    '#FF99C3',
    '#1E9493',
    '#FF9845',
    '#5D7092',
  ];
  const TREE_EVENT = {
    COLLAPSE_EXPAND: 'tekg-collapse-expand',
  };
  const COLLAPSE_THRESHOLD = 6;
  const ROOT_BG = '#576286';
  const ROOT_TEXT = '#ffffff';
  const TEXT_COLOR = '#666666';
  const INDENT = 40;
  const V_GAP = 10;
  const NODE_HEIGHT = 20;
  const TREE_LEFT_PADDING = 56;
  const TREE_VERTICAL_PADDING = 56;
  const TREE_RIGHT_BUFFER_RATIO = 0.58;
  const TREE_RIGHT_BUFFER_MIN = 720;
  const TREE_INITIAL_LEFT_SHIFT_RATIO = 0.05;
  const TREE_INITIAL_LEFT_SHIFT_MIN = 44;
  const INTERACTION_TUNING = {
    zoomSensitivity: 1.18,
    focusDuration: 220,
    focusZoomDuration: 180,
    localFocusZoom: 1.18,
    dragSpeed: 1.45,
  };

  let g6Graph = null;
  let isBound = false;
  let registered = false;
  let rootId = null;
  let selectedNodeId = null;
  let activeTreeConfig = null;
  const taxonomyTreeElementsByVariant = new Map();
  const taxonomyTreePromiseByVariant = new Map();

  function getEl(id) {
    return document.getElementById(id);
  }

  function getCurrentTreeVariantKey() {
    return String(window.__TEKG_TREE_VARIANT || 'rmsk_repbase').trim() || 'rmsk_repbase';
  }

  function getCurrentTreeVariantPayload() {
    return null;
  }

  function getCurrentTreeElements() {
    return taxonomyTreeElementsByVariant.get(getCurrentTreeVariantKey()) || [];
  }

  function treeNodeId(name) {
    return `TREE_${String(name || '').trim().replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'node'}`;
  }

  function taxonomyPayloadToElements(payload) {
    const nodes = Array.isArray(payload?.nodes) ? payload.nodes : [];
    const edges = Array.isArray(payload?.edges) ? payload.edges : [];
    const elements = [];
    const yByName = new Map();
    nodes.forEach((node, index) => {
      const name = String(node?.name || '').trim();
      if (!name) return;
      const depth = Math.max(0, Number(node?.depth) || 0);
      yByName.set(name, index * 28);
      elements.push({
        position: { x: depth * 250, y: index * 28 },
        data: {
          id: treeNodeId(name),
          label: name,
          query_label: depth === 0 ? '' : name,
          type: 'TE',
          description: String(node?.description || ''),
          tree_depth: depth,
          tree_reference: true,
          tree_matched: depth > 0,
          tree_is_meta: depth < 4,
        },
      });
    });
    edges.forEach((edge) => {
      const child = String(edge?.child || '').trim();
      const parent = String(edge?.parent || '').trim();
      if (!child || !parent) return;
      elements.push({
        data: {
          id: `${treeNodeId(parent)}__SUBFAMILY_OF__${treeNodeId(child)}`,
          source: treeNodeId(parent),
          target: treeNodeId(child),
          relation: 'SUBFAMILY_OF',
          tree_reference: true,
        },
        position: { x: 0, y: yByName.get(child) || 0 },
      });
    });
    return elements;
  }

  async function ensureCurrentTreeElements() {
    const variant = getCurrentTreeVariantKey();
    const cachedElements = taxonomyTreeElementsByVariant.get(variant) || [];
    if (cachedElements.length) return cachedElements;
    if (!taxonomyTreePromiseByVariant.has(variant)) {
      const source = variant === 'tekg3' ? 'tekg3' : variant;
      const taxonomyUrl = window.__TEKG_PATHS.apiUrl('taxonomy.php?view=tree&source=' + encodeURIComponent(source));
      taxonomyTreePromiseByVariant.set(variant, fetch(taxonomyUrl, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) throw new Error(`taxonomy API HTTP ${response.status}`);
          return response.json();
        })
        .then((payload) => {
          const elements = taxonomyPayloadToElements(payload);
          taxonomyTreeElementsByVariant.set(variant, elements);
          return elements;
        }));
    }
    return taxonomyTreePromiseByVariant.get(variant);
  }

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  const TREE_DISPLAY_LABELS = new Map([
    ['TE', 'Transposable Elements - Human'],
    ['Retrotransposon', 'Class I: Retrotransposons'],
    ['DNA Transposon', 'Class II: DNA Transposons'],
    ['SINE', 'SINEs'],
  ]);

  function getRootLabel() {
    return 'Transposable Elements - Human';
  }

  function getTreeDisplayLabel(raw) {
    const key = String(raw || '').trim();
    return TREE_DISPLAY_LABELS.get(key) || key;
  }

  function normalizeTextWidth(text) {
    return Math.max(36, String(text || '').length * 8 + 6);
  }

  function getDisplayLabel(label, description, depth) {
    const raw = String(label || '');
    if (depth === 0) return getRootLabel();
    const mapped = getTreeDisplayLabel(raw);
    if (mapped !== raw) return mapped;
    if (typeof getName === 'function') return getName(raw, 'TE', description || '', '');
    return raw;
  }

  function getDescription(label, description) {
    if (typeof getDesc === 'function') return getDesc(label || '', 'TE', description || '', '');
    return String(description || '');
  }

  function buildDefaultTreeConfig() {
    const defaultDetailHtml = 'G6 indented default-tree view is active.';

    return {
      defaultDetailHtml,
      buildLabel(data, nodeId) {
        return getDisplayLabel(data.rawLabel || nodeId, data.description || '', data.treeDepth || 0);
      },
      buildDetailHtml(nodeData) {
        const data = nodeData?.data || {};
        const label = getDisplayLabel(data.rawLabel || nodeData.id, data.description || '', data.treeDepth || 0);
        const desc = getDescription(data.rawLabel || nodeData.id, data.description || '');
        return `<strong>${escapeHtml(label)}</strong> (Transposable Element)<br>${escapeHtml(desc)}`;
      },
    };
  }

  function getActiveTreeConfig() {
    if (!activeTreeConfig) activeTreeConfig = buildDefaultTreeConfig();
    return activeTreeConfig;
  }

  function resolveTreeLabel(datum) {
    const config = getActiveTreeConfig();
    const data = datum?.data || {};
    if (typeof config.buildLabel === 'function') {
      const label = config.buildLabel(data, datum?.id || '');
      if (label !== undefined && label !== null && label !== '') return String(label);
    }
    return String(data.displayLabel || data.rawLabel || datum?.id || '');
  }

  function resolveTreeLabelFill(datum) {
    const config = getActiveTreeConfig();
    const data = datum?.data || {};
    if (typeof config.buildLabelFill === 'function') {
      const fill = config.buildLabelFill(data, datum?.id || '');
      if (fill) return fill;
    }
    return datum?.id === rootId ? ROOT_TEXT : TEXT_COLOR;
  }

  function resolveTreeLabelFontWeight(datum) {
    const config = getActiveTreeConfig();
    const data = datum?.data || {};
    if (typeof config.buildLabelFontWeight === 'function') {
      const weight = config.buildLabelFontWeight(data, datum?.id || '');
      if (weight) return weight;
    }
    return 'normal';
  }

  function setRendererVisibility() {
    const cyHost = getEl('cy');
    const g6Host = getEl('g6-default-tree-surface');
    if (cyHost) cyHost.style.display = 'none';
    if (g6Host) {
      g6Host.classList.remove('hidden');
      g6Host.style.display = 'block';
      g6Host.style.width = '100%';
      g6Host.style.height = '100%';
    }
  }

  function buildStrictTreeSource() {
    const nodes = new Map();
    const children = new Map();
    const parentOf = new Map();
    let detectedRootId = null;

    for (const item of getCurrentTreeElements()) {
      const data = item && item.data ? item.data : null;
      if (!data || data.source) continue;
      nodes.set(data.id, {
        id: data.id,
        label: data.label,
        queryLabel: data.query_label,
        description: data.description,
        treeDepth: data.tree_depth || 0,
      });
      if (data.tree_depth === 0 && !detectedRootId) detectedRootId = data.id;
    }

    const getY = (id) => {
      const matched = getCurrentTreeElements().find((item) => item?.data?.id === id);
      return matched?.position?.y ?? 0;
    };

    const edges = [];
    for (const item of getCurrentTreeElements()) {
      const data = item && item.data ? item.data : null;
      if (!data || !data.source || !data.target) continue;
      if (!nodes.has(data.source) || !nodes.has(data.target)) continue;
      edges.push({ source: data.source, target: data.target, y: getY(data.target) });
    }

    edges.sort((a, b) => a.y - b.y);
    for (const edge of edges) {
      if (parentOf.has(edge.target)) continue;
      parentOf.set(edge.target, edge.source);
      if (!children.has(edge.source)) children.set(edge.source, []);
      children.get(edge.source).push(edge.target);
    }

    for (const [parentId, childIds] of children.entries()) {
      childIds.sort((a, b) => getY(a) - getY(b));
    }

    return { nodes, children, rootId: detectedRootId };
  }

  function buildTreeNode(nodeId, source) {
    const node = source.nodes.get(nodeId);
    if (!node) return null;
    const childIds = source.children.get(nodeId) || [];
    const depth = node.treeDepth || 0;
    const label = getDisplayLabel(node.label, node.description, depth);
    const children = childIds.map((childId) => buildTreeNode(childId, source)).filter(Boolean);

    return {
      id: node.id,
      data: {
        rawLabel: node.label,
        queryLabel: node.queryLabel,
        description: node.description,
        treeDepth: depth,
      },
      style: {
        collapsed: children.length > 0,
        labelText: label,
      },
      children,
    };
  }

  function taxonomyGraphNodeSize(depth) {
    const safeDepth = Math.max(0, Number(depth) || 0);
    return Math.max(18, 76 - safeDepth * 7);
  }

  function taxonomyGraphNodeColor(depth, maxDepth) {
    const safeDepth = Math.max(0, Number(depth) || 0);
    const safeMax = Math.max(1, Number(maxDepth) || 1);
    const t = Math.max(0, Math.min(1, safeDepth / safeMax));
    const start = [24, 53, 125];
    const end = [191, 215, 255];
    const rgb = start.map((value, index) => Math.round(value + (end[index] - value) * t));
    return `rgb(${rgb[0]}, ${rgb[1]}, ${rgb[2]})`;
  }

  function wrapTaxonomyGraphLabel(label, depth) {
    const raw = String(label || '').trim();
    if (!raw) return '';
    if (depth <= 1) return raw;
    const limit = depth <= 3 ? 14 : 11;
    if (raw.length <= limit) return raw;
    return `${raw.slice(0, Math.max(4, limit - 1))}...`;
  }

  function buildTaxonomyGraphData(source, width, height) {
    const nodes = [];
    const edges = [];
    const nodeEntries = [...source.nodes.values()];
    const maxDepth = nodeEntries.reduce((max, node) => Math.max(max, Number(node.treeDepth || 0)), 0);
    const root = source.rootId || nodeEntries.find((node) => Number(node.treeDepth || 0) === 0)?.id || '';
    const centerX = Math.max(200, width / 2);
    const centerY = Math.max(160, height / 2);
    const depthGroups = new Map();

    for (const node of nodeEntries) {
      const depth = Math.max(0, Number(node.treeDepth || 0));
      if (!depthGroups.has(depth)) depthGroups.set(depth, []);
      depthGroups.get(depth).push(node);
    }

    for (const group of depthGroups.values()) {
      group.sort((left, right) => String(left.label || '').localeCompare(String(right.label || '')));
    }

    for (const [depth, group] of depthGroups.entries()) {
      const radius = depth === 0 ? 0 : Math.max(120, Math.min(width, height) * 0.13 + depth * 120);
      const offset = depth % 2 === 0 ? Math.PI / Math.max(3, group.length) : 0;
      group.forEach((node, index) => {
        const angle = group.length <= 1 ? -Math.PI / 2 : (Math.PI * 2 * index) / group.length + offset;
        const x = depth === 0 ? centerX : centerX + Math.cos(angle) * radius;
        const y = depth === 0 ? centerY : centerY + Math.sin(angle) * radius;
        const label = depth === 0 ? 'Human TE' : getDisplayLabel(node.label, node.description, depth);
        const size = taxonomyGraphNodeSize(depth);
        nodes.push({
          id: node.id,
          data: {
            rawLabel: node.label,
            queryLabel: '',
            description: node.description,
            treeDepth: depth,
            taxonomyOnly: true,
          },
          style: {
            x,
            y,
            size,
            labelText: wrapTaxonomyGraphLabel(label, depth),
            fill: taxonomyGraphNodeColor(depth, maxDepth),
            stroke: depth === 0 ? '#0f172a' : '#1d4ed8',
            lineDash: depth === 0 ? [] : [5, 4],
          },
        });
      });
    }

    for (const [parentId, childIds] of source.children.entries()) {
      for (const childId of childIds) {
        edges.push({
          id: `${parentId}__taxonomy__${childId}`,
          source: parentId,
          target: childId,
          data: {
            relation: 'taxonomy parent',
          },
        });
      }
    }

    return { nodes, edges, rootId: root };
  }

  function buildTaxonomyGraphDetailHtml(nodeData) {
    if (!nodeData) {
      return [
        '<strong>TE classification graph</strong>',
        '<br>All nodes are rendered from the selected static taxonomy tree.',
        '<br><span class="meta">Dashed node outline: taxonomy-only display node. It is not inserted into Neo4j.</span>',
      ].join('');
    }
    const data = nodeData.data || {};
    const label = nodeData.style?.labelText || data.rawLabel || nodeData.id || '';
    const depth = Number(data.treeDepth || 0);
    const description = String(data.description || '').trim();
    return [
      `<strong>${escapeHtml(label)}</strong>`,
      `<br><span class="meta">Taxonomy level: ${escapeHtml(depth)}</span>`,
      '<br><span class="meta">Display status: taxonomy-only node; Neo4j is not modified.</span>',
      description ? `<br>${escapeHtml(description)}` : '',
      '<br>Use the Graph search box to open a TE relationship graph when this name exists as a database entity.',
    ].join('');
  }

  class TEKGIndentedNode extends BaseNode {
    static defaultStyleProps = {
      ports: [
        { key: 'in', placement: 'right-bottom' },
        { key: 'out', placement: 'left-bottom' },
      ],
      showIcon: true,
    };

    constructor(options) {
      Object.assign(options.style, TEKGIndentedNode.defaultStyleProps);
      super(options);
    }

    get childrenData() {
      return this.context.model.getChildrenData(this.id) || [];
    }

    getKeyStyle(attributes) {
      const [width, height] = this.getSize(attributes);
      const keyStyle = super.getKeyStyle(attributes);
      return {
        width,
        height,
        ...keyStyle,
        fill: 'transparent',
        stroke: 'transparent',
      };
    }

    drawKeyShape(attributes, container) {
      return this.upsert('key', 'rect', this.getKeyStyle(attributes), container);
    }

    getLabelStyle(attributes) {
      if (attributes.label === false || !attributes.labelText) return false;
      return subStyleProps(this.getGraphicStyle(attributes), 'label');
    }

    drawIconArea(attributes, container) {
      const [, h] = this.getSize(attributes);
      this.upsert(
        'icon-area',
        'rect',
        {
          fill: 'transparent',
          height: 30,
          width: 12,
          x: -6,
          y: h,
          zIndex: -1,
        },
        container,
      );
    }

    forwardEvent(target, type, listener) {
      if (target && !Reflect.has(target, '__bind__')) {
        Reflect.set(target, '__bind__', true);
        target.addEventListener(type, listener);
      }
    }

    getCountStyle(attributes) {
      const { collapsed, color } = attributes;
      if (!collapsed) return false;
      const [width, height] = this.getSize(attributes);
      return {
        backgroundFill: color,
        cursor: 'pointer',
        fill: '#fff',
        fontSize: 8,
        padding: [0, 10],
        text: '+',
        textAlign: 'center',
        x: width + 10,
        y: Math.round(height * 0.5) + 1,
      };
    }

    drawCountShape(attributes, container) {
      const countStyle = this.getCountStyle(attributes);
      const btn = this.upsert('count', Badge, countStyle, container);
      this.forwardEvent(btn, CommonEvent.CLICK, (event) => {
        event.stopPropagation();
        this.context.graph.emit(TREE_EVENT.COLLAPSE_EXPAND, { id: this.id, collapsed: false });
      });
    }

    isShowCollapse(attributes) {
      return !attributes.collapsed && this.childrenData.length > 0;
    }

    getCollapseStyle(attributes) {
      const { color } = attributes;
      if (!this.isShowCollapse(attributes)) return false;
      const [width, height] = this.getSize(attributes);
      return {
        visibility: 'visible',
        backgroundFill: color,
        backgroundHeight: 12,
        backgroundWidth: 12,
        cursor: 'pointer',
        fill: '#fff',
        fontSize: 10,
        text: '-',
        textAlign: 'center',
        x: width + 10,
        y: Math.round(height * 0.5) + 1,
      };
    }

    drawCollapseShape(attributes, container) {
      const iconStyle = this.getCollapseStyle(attributes);
      const btn = this.upsert('collapse-expand', Badge, iconStyle, container);
      this.forwardEvent(btn, CommonEvent.CLICK, (event) => {
        event.stopPropagation();
        this.context.graph.emit(TREE_EVENT.COLLAPSE_EXPAND, {
          id: this.id,
          collapsed: !attributes.collapsed,
        });
      });
    }

    render(attributes = this.parsedAttributes, container = this) {
      super.render(attributes, container);
      this.drawCountShape(attributes, container);
      this.drawIconArea(attributes, container);
      this.drawCollapseShape(attributes, container);
    }
  }

  class TEKGIndentedEdge extends Polyline {
    getControlPoints(attributes) {
      const [sourcePoint, targetPoint] = this.getEndpoints(attributes, false);
      const [sx] = sourcePoint;
      const [, ty] = targetPoint;
      return [[sx, ty]];
    }
  }

  class TEKGCollapseExpandTree extends BaseBehavior {
    constructor(context, options) {
      super(context, options);
      this.bindEvents();
    }

    update(options) {
      this.unbindEvents();
      super.update(options);
      this.bindEvents();
    }

    bindEvents() {
      const { graph } = this.context;
      graph.on(TREE_EVENT.COLLAPSE_EXPAND, this.onCollapseExpand);
    }

    unbindEvents() {
      const { graph } = this.context;
      graph.off(TREE_EVENT.COLLAPSE_EXPAND, this.onCollapseExpand);
    }

    status = 'idle';

    onCollapseExpand = async (event) => {
      this.status = 'busy';
      const { id, collapsed } = event;
      const { graph } = this.context;
      if (collapsed) await graph.collapseElement(id, { animation: false });
      else await graph.expandElement(id, { animation: false });
      await activateNode(id, { shouldFocus: false });
      this.status = 'idle';
    };
  }

  class TEKGDragCanvasFast extends BaseBehavior {
    constructor(context, options) {
      super(context, options);
      this.bindEvents();
    }

    update(options) {
      this.unbindEvents();
      super.update(options);
      this.bindEvents();
    }

    dragging = false;

    lastPoint = null;

    bindEvents() {
      const { graph } = this.context;
      graph.on(CanvasEvent.POINTER_DOWN, this.onPointerDown);
      graph.on(CommonEvent.POINTER_MOVE, this.onPointerMove);
      graph.on(CommonEvent.POINTER_UP, this.onPointerUp);
      graph.on(CommonEvent.POINTER_UP_OUTSIDE, this.onPointerUp);
      graph.on(CommonEvent.DRAG_END, this.onPointerUp);
    }

    unbindEvents() {
      const { graph } = this.context;
      graph.off(CanvasEvent.POINTER_DOWN, this.onPointerDown);
      graph.off(CommonEvent.POINTER_MOVE, this.onPointerMove);
      graph.off(CommonEvent.POINTER_UP, this.onPointerUp);
      graph.off(CommonEvent.POINTER_UP_OUTSIDE, this.onPointerUp);
      graph.off(CommonEvent.DRAG_END, this.onPointerUp);
    }

    getClientPoint(event) {
      const point = event?.client || event?.nativeEvent || {};
      const x = Number(point.x ?? point.clientX);
      const y = Number(point.y ?? point.clientY);
      if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
      return { x, y };
    }

    onPointerDown = (event) => {
      if (event?.targetType && event.targetType !== 'canvas') return;
      this.dragging = true;
      this.lastPoint = this.getClientPoint(event);
      const { graph } = this.context;
      if (typeof graph.setCursor === 'function') graph.setCursor('grabbing');
    };

    onPointerMove = (event) => {
      if (!this.dragging) return;
      const currentPoint = this.getClientPoint(event);
      if (!currentPoint || !this.lastPoint) return;
      const { graph } = this.context;
      const dx = (currentPoint.x - this.lastPoint.x) * INTERACTION_TUNING.dragSpeed;
      const dy = (currentPoint.y - this.lastPoint.y) * INTERACTION_TUNING.dragSpeed;
      this.lastPoint = currentPoint;
      if (typeof graph.translateBy === 'function') {
        graph.translateBy([dx, dy], false);
      }
    };

    onPointerUp = () => {
      this.dragging = false;
      this.lastPoint = null;
      const { graph } = this.context;
      if (typeof graph.setCursor === 'function') graph.setCursor('grab');
    };
  }

  function ensureRegistered() {
    if (registered) return;
    register(ExtensionCategory.NODE, 'tekg-indented', TEKGIndentedNode);
    register(ExtensionCategory.EDGE, 'tekg-indented', TEKGIndentedEdge);
    register(ExtensionCategory.BEHAVIOR, 'tekg-collapse-expand-tree', TEKGCollapseExpandTree);
    register(ExtensionCategory.BEHAVIOR, 'tekg-drag-canvas-fast', TEKGDragCanvasFast);
    registered = true;
  }

  function updateDetail(nodeData) {
    const detailEl = getEl('node-details');
    if (!detailEl) return;
    const config = getActiveTreeConfig();
    if (!nodeData) {
      detailEl.innerHTML = config.defaultDetailHtml || 'G6 indented default-tree view is active.';
      return;
    }
    if (typeof config.buildDetailHtml === 'function') {
      detailEl.innerHTML = config.buildDetailHtml(nodeData) || '';
      return;
    }
    detailEl.innerHTML = '';
  }

  function clearSelectedNode() {
    if (!g6Graph || typeof g6Graph.setElementState !== 'function' || !selectedNodeId) return;
    try {
      g6Graph.setElementState(selectedNodeId, []);
    } catch (_error) {
      // stale node ids after rerender can be ignored safely
    }
    selectedNodeId = null;
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

  function setSelectedNode(nodeId) {
    if (!g6Graph || !nodeId || typeof g6Graph.setElementState !== 'function') return;
    clearSelectedNode();
    try {
      g6Graph.setElementState(nodeId, ['selected']);
      selectedNodeId = nodeId;
    } catch (_error) {
      selectedNodeId = null;
    }
  }

  async function focusNode(nodeId) {
    if (!g6Graph || !nodeId || typeof g6Graph.focusElement !== 'function') return;
    try {
      await g6Graph.focusElement(nodeId, {
        duration: INTERACTION_TUNING.focusDuration,
        easing: 'ease-in-out',
      });
      if (typeof focusLevel !== 'undefined' && focusLevel === 100 && typeof g6Graph.getZoom === 'function' && typeof g6Graph.zoomTo === 'function') {
        const currentZoom = g6Graph.getZoom();
        const nextZoom = Math.max(currentZoom, INTERACTION_TUNING.localFocusZoom);
        if (nextZoom !== currentZoom) {
          await g6Graph.zoomTo(nextZoom, {
            duration: INTERACTION_TUNING.focusZoomDuration,
            easing: 'ease-out',
          });
        }
      }
    } catch (error) {
      console.warn('G6 focus failed:', error);
    }
  }

  async function activateNode(nodeId, { shouldFocus = true } = {}) {
    if (!g6Graph || !nodeId || typeof g6Graph.getNodeData !== 'function') return;
    const nodeData = g6Graph.getNodeData(nodeId);
    updateDetail(nodeData);
    setSelectedNode(nodeId);
    const fixedModeEnabled = typeof fixedView !== 'undefined' && fixedView === true;
    if (shouldFocus && !fixedModeEnabled) {
      await focusNode(nodeId);
    }
  }

  function destroyGraph() {
    if (g6Graph && typeof g6Graph.destroy === 'function') g6Graph.destroy();
    g6Graph = null;
  }

  function getTreeViewPadding(width, height) {
    const vertical = Math.max(TREE_VERTICAL_PADDING, Math.round((height || 0) * 0.08));
    const right = Math.max(TREE_RIGHT_BUFFER_MIN, Math.round((width || 0) * TREE_RIGHT_BUFFER_RATIO));
    return [vertical, right, vertical, TREE_LEFT_PADDING];
  }

  function alignTreeViewport(graph, width) {
    if (!graph || typeof graph.translateBy !== 'function') return;
    const shift = Math.max(TREE_INITIAL_LEFT_SHIFT_MIN, Math.round((width || 0) * TREE_INITIAL_LEFT_SHIFT_RATIO));
    if (shift <= 0) return;
    try {
      graph.translateBy([-shift, 0], false);
    } catch (_error) {
      // If the current G6 build rejects viewport translation here, keep the default fit result.
    }
  }

  async function renderTreeData(treeData, options = {}) {
    const detailEl = getEl('node-details');
    try {
      ensureRegistered();
      setRendererVisibility();
      if (window.__TEKG_G6_DYNAMIC_GRAPH && typeof window.__TEKG_G6_DYNAMIC_GRAPH.destroy === 'function') {
        window.__TEKG_G6_DYNAMIC_GRAPH.destroy();
      }

      const host = getEl('g6-default-tree-surface');
      if (!host) return;

      await new Promise((resolve) => requestAnimationFrame(resolve));

      const width = host.clientWidth || host.offsetWidth;
      const height = host.clientHeight || host.offsetHeight;
      if (!width || !height) {
        if (detailEl) detailEl.textContent = 'G6 container has no size yet.';
        return;
      }
      if (!treeData) {
        if (detailEl) detailEl.textContent = 'Failed to build G6 tree data.';
        return;
      }
      rootId = String(options.rootId || treeData.id || '');
      activeTreeConfig = {
        ...buildDefaultTreeConfig(),
        ...(options.config && typeof options.config === 'object' ? options.config : {}),
      };

      destroyGraph();
      host.innerHTML = '';

      const graph = new Graph({
        container: host,
        width,
        height,
        autoResize: true,
        autoFit: 'view',
        padding: getTreeViewPadding(width, height),
        animation: false,
        cursor: 'grab',
        data: treeToGraphData(treeData),
        node: {
          type: 'tekg-indented',
          style: {
            size: (datum) => [normalizeTextWidth(resolveTreeLabel(datum)), NODE_HEIGHT],
            labelBackground: true,
            labelBackgroundRadius: 0,
            labelBackgroundFill: (datum) => (datum.id === rootId ? ROOT_BG : '#ffffff'),
            labelFill: (datum) => resolveTreeLabelFill(datum),
            labelFontWeight: (datum) => resolveTreeLabelFontWeight(datum),
            labelText: (datum) => resolveTreeLabel(datum),
            labelTextAlign: (datum) => (datum.id === rootId ? 'center' : 'left'),
            labelTextBaseline: 'top',
            color: (datum) => {
              const depth = graph.getAncestorsData(datum.id, 'tree').length - 1;
              return COLORS[depth % COLORS.length] || ROOT_BG;
            },
            showIcon: false,
          },
          state: {
            selected: {
              lineWidth: 0,
              labelFill: '#40A8FF',
              labelBackground: true,
              labelFontWeight: 'normal',
              labelBackgroundFill: '#e8f7ff',
              labelBackgroundRadius: 10,
            },
          },
        },
        edge: {
          type: 'tekg-indented',
          style: {
            radius: 16,
            lineWidth: 2,
            sourcePort: 'out',
            targetPort: 'in',
            stroke: (datum) => {
              const depth = graph.getAncestorsData(datum.source, 'tree').length;
              return COLORS[depth % COLORS.length] || COLORS[0];
            },
          },
        },
        layout: {
          type: 'indented',
          direction: 'LR',
          isHorizontal: true,
          indent: INDENT,
          getHeight: () => NODE_HEIGHT,
          getVGap: () => V_GAP,
        },
        behaviors: [
          {
            type: 'tekg-drag-canvas-fast',
          },
          {
            type: 'zoom-canvas',
            sensitivity: INTERACTION_TUNING.zoomSensitivity,
          },
          'tekg-collapse-expand-tree',
          {
            type: 'click-select',
            enable: (event) => event.targetType === 'node' && event.target.id !== rootId,
          },
        ],
      });

      g6Graph = graph;
      await graph.render();
      alignTreeViewport(graph, width);
      clearSelectedNode();
      updateDetail(null);

      graph.on('node:click', async (event) => {
        const targetId = resolveEventNodeId(event);
        if (!targetId || typeof graph.getNodeData !== 'function') return;
        const nodeData = graph.getNodeData(targetId);
        try {
          if (typeof activeTreeConfig?.onNodeClick === 'function') {
            const handled = await activeTreeConfig.onNodeClick(nodeData, {
              nodeId: targetId,
            });
            if (handled) return;
          }
        } catch (error) {
          const detailEl = getEl('node-details');
          if (detailEl) {
            detailEl.textContent = `G6 tree click failed to load dynamic graph: ${error && error.message ? error.message : 'unknown error'}`;
          }
          console.error('G6 tree click failed to load dynamic graph:', error);
          return;
        }
        await activateNode(targetId, { shouldFocus: true });
      });
    } catch (error) {
      if (detailEl) {
        detailEl.textContent = `G6 default tree failed: ${error && error.message ? error.message : 'unknown error'}`;
      }
      console.error('G6 default tree failed:', error);
    }
  }

  async function renderTaxonomyGraph() {
    const detailEl = getEl('node-details');
    try {
      ensureRegistered();
      setRendererVisibility();
      if (window.__TEKG_G6_DYNAMIC_GRAPH && typeof window.__TEKG_G6_DYNAMIC_GRAPH.destroy === 'function') {
        window.__TEKG_G6_DYNAMIC_GRAPH.destroy();
      }

      await ensureCurrentTreeElements();
      const source = buildStrictTreeSource();
      if (!source.rootId) {
        if (detailEl) detailEl.textContent = 'Taxonomy graph data is unavailable.';
        return;
      }

      const host = getEl('g6-default-tree-surface');
      if (!host) return;
      await new Promise((resolve) => requestAnimationFrame(resolve));
      const width = host.clientWidth || host.offsetWidth;
      const height = host.clientHeight || host.offsetHeight;
      if (!width || !height) {
        if (detailEl) detailEl.textContent = 'G6 container has no size yet.';
        return;
      }

      destroyGraph();
      host.innerHTML = '';
      const graphData = buildTaxonomyGraphData(source, width, height);
      rootId = graphData.rootId;
      activeTreeConfig = {
        defaultDetailHtml: buildTaxonomyGraphDetailHtml(null),
        buildDetailHtml: buildTaxonomyGraphDetailHtml,
      };

      const graph = new Graph({
        container: host,
        width,
        height,
        autoResize: true,
        autoFit: 'view',
        padding: [80, 80, 80, 80],
        animation: false,
        cursor: 'grab',
        data: graphData,
        node: {
          type: 'circle',
          style: {
            x: (datum) => datum.style?.x,
            y: (datum) => datum.style?.y,
            size: (datum) => datum.style?.size || 24,
            fill: (datum) => datum.style?.fill || '#bfdbfe',
            stroke: (datum) => datum.style?.stroke || '#1d4ed8',
            lineWidth: (datum) => (datum.id === rootId ? 5 : 2),
            lineDash: (datum) => datum.style?.lineDash || [],
            shadowColor: (datum) => (datum.id === rootId ? 'rgba(37, 99, 235, 0.42)' : 'rgba(15, 23, 42, 0.08)'),
            shadowBlur: (datum) => (datum.id === rootId ? 28 : 0),
            labelText: (datum) => datum.style?.labelText || datum.data?.rawLabel || '',
            labelPlacement: 'center',
            labelFill: (datum) => (datum.id === rootId ? '#ffffff' : '#0f172a'),
            labelFontSize: (datum) => {
              const depth = Number(datum.data?.treeDepth || 0);
              return Math.max(7, 13 - depth * 0.6);
            },
            labelFontWeight: (datum) => (datum.id === rootId ? 800 : 650),
            labelStroke: '#ffffff',
            labelLineWidth: (datum) => (datum.id === rootId ? 0 : 3),
          },
        },
        edge: {
          style: {
            stroke: '#93c5fd',
            lineWidth: 1.6,
            opacity: 0.72,
          },
        },
        layout: {
          type: 'preset',
        },
        behaviors: [
          {
            type: 'tekg-drag-canvas-fast',
          },
          {
            type: 'zoom-canvas',
            sensitivity: INTERACTION_TUNING.zoomSensitivity,
          },
          {
            type: 'click-select',
            enable: (event) => event.targetType === 'node',
          },
        ],
      });

      g6Graph = graph;
      await graph.render();
      clearSelectedNode();
      updateDetail(null);

      graph.on('node:click', async (event) => {
        const targetId = resolveEventNodeId(event);
        if (!targetId || typeof graph.getNodeData !== 'function') return;
        await activateNode(targetId, { shouldFocus: false });
      });
    } catch (error) {
      if (detailEl) {
        detailEl.textContent = `G6 taxonomy graph failed: ${error && error.message ? error.message : 'unknown error'}`;
      }
      console.error('G6 taxonomy graph failed:', error);
    }
  }

  async function renderDefaultTree() {
    const detailEl = getEl('node-details');
    await ensureCurrentTreeElements();
    const source = buildStrictTreeSource();
    if (!source.rootId) {
      if (detailEl) detailEl.textContent = 'Default tree data is unavailable.';
      return;
    }

    const treeData = buildTreeNode(source.rootId, source);
    await renderTreeData(treeData, {
      rootId: source.rootId,
      config: buildDefaultTreeConfig(),
    });
  }

  async function renderStructuredTree(options = {}) {
    const treeData = options && typeof options === 'object' ? options.treeData : null;
    await renderTreeData(treeData, {
      rootId: options.rootId,
      config: options.config,
    });
  }

  function isExportVisible(id) {
    if (!g6Graph || typeof g6Graph.getElementVisibility !== 'function') return true;
    try {
      return g6Graph.getElementVisibility(id) !== 'hidden';
    } catch (_error) {
      return true;
    }
  }

  function getExportSnapshot() {
    if (!g6Graph || typeof g6Graph.getData !== 'function') {
      return { query: 'taxonomy_tree', nodes: [], edges: [], counts: { nodes: 0, edges: 0 } };
    }
    const data = g6Graph.getData() || {};
    const nodes = (Array.isArray(data.nodes) ? data.nodes : [])
      .filter((node) => isExportVisible(node.id))
      .map((node) => {
        const raw = node.data || {};
        const position = typeof g6Graph.getElementPosition === 'function'
          ? g6Graph.getElementPosition(node.id)
          : [node.style?.x, node.style?.y];
        return {
          id: String(node.id || ''),
          label: String(resolveTreeLabel(node) || raw.rawLabel || node.id || ''),
          rawLabel: String(raw.rawLabel || raw.label || node.id || ''),
          type: 'TE',
          taxonomyLevel: Number(raw.treeDepth || 0),
          description: String(raw.description || ''),
          x: Number(position?.[0]),
          y: Number(position?.[1]),
          size: Number(node.style?.size || 22),
          fill: String(node.style?.fill || '#bfdbfe'),
          stroke: String(node.style?.stroke || '#1d4ed8'),
        };
      });
    const nodeIds = new Set(nodes.map((node) => node.id));
    const edges = (Array.isArray(data.edges) ? data.edges : [])
      .filter((edge) => nodeIds.has(String(edge.source || '')) && nodeIds.has(String(edge.target || '')) && isExportVisible(edge.id))
      .map((edge) => ({
        id: String(edge.id || `${edge.source}__${edge.target}`),
        source: String(edge.source || ''),
        target: String(edge.target || ''),
        relation: 'classified under',
        relationType: 'TAXONOMY',
        pmids: [],
        evidence: '',
      }));
    return { query: 'taxonomy_tree', nodes, edges, counts: { nodes: nodes.length, edges: edges.length } };
  }

  async function exportPngDataUrl() {
    if (g6Graph && typeof g6Graph.toDataURL === 'function') {
      const result = await g6Graph.toDataURL({ type: 'image/png' });
      const dataUrl = typeof result === 'string' ? result : result?.dataURL;
      if (String(dataUrl || '').startsWith('data:image/png')) return dataUrl;
    }
    const host = getEl('g6-default-tree-surface');
    const canvas = Array.from(host?.querySelectorAll('canvas') || []).sort((a, b) => b.width * b.height - a.width * a.height)[0];
    if (!canvas || typeof canvas.toDataURL !== 'function') throw new Error('Taxonomy Tree canvas is unavailable.');
    return canvas.toDataURL('image/png');
  }

  function exportSvgString() {
    const snapshot = getExportSnapshot();
    const serializer = window.__TEKG_G6_SVG_EXPORT;
    if (!serializer || typeof serializer.serialize !== 'function') throw new Error('The shared SVG exporter is unavailable.');
    return serializer.serialize({
      title: 'TE-Dee taxonomy tree',
      description: 'Static vector export of the visible taxonomy Tree.',
      metadata: { graph_mode: 'taxonomy_tree', node_count: snapshot.nodes.length, edge_count: snapshot.edges.length },
      nodes: snapshot.nodes.map((node) => ({
        id: node.id,
        x: node.x,
        y: node.y,
        radius: Math.max(5, node.size / 2),
        fill: node.fill,
        stroke: node.stroke,
        strokeWidth: 2,
        opacity: 1,
        label: { text: node.label, fontSize: 11, fontWeight: 650, opacity: 1 },
      })),
      edges: snapshot.edges.map((edge) => ({ ...edge, stroke: '#93c5fd', strokeWidth: 1.6, opacity: 0.75, dash: [] })),
    });
  }

  function bindTriggers() {
    if (isBound) return;
    isBound = true;

    const resetBtn = getEl('reset-graph');
    if (resetBtn) resetBtn.addEventListener('click', () => setTimeout(renderDefaultTree, 0));

    const enBtn = getEl('lang-en');
    if (enBtn) enBtn.addEventListener('click', () => setTimeout(renderDefaultTree, 0));

    window.addEventListener('resize', () => {
      if (!g6Graph || typeof g6Graph.resize !== 'function') return;
      const host = getEl('g6-default-tree-surface');
      if (!host) return;
      const width = host.clientWidth || host.offsetWidth;
      const height = host.clientHeight || host.offsetHeight;
      if (width && height) g6Graph.resize(width, height);
    });

    window.addEventListener('tekg:shared-ready', () => {
      if (window.__TEKG_G6_BOOTSTRAP_OWN_TREE) return;
      renderDefaultTree();
    });

      if (!window.__TEKG_G6_BOOTSTRAP_OWN_TREE) {
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
          setTimeout(renderDefaultTree, 0);
        } else {
          window.addEventListener('DOMContentLoaded', () => setTimeout(renderDefaultTree, 0), { once: true });
        }
      }
    }

  bindTriggers();
  window.__TEKG_G6_DEFAULT_TREE = {
    render: renderDefaultTree,
    renderGraph: renderTaxonomyGraph,
    renderStructuredTree,
    destroy: destroyGraph,
    getGraph() {
      return g6Graph;
    },
    getStateTree() {
      return null;
    },
    getExportSnapshot,
    exportPngDataUrl,
    exportSvgString,
  };
}());
