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
    Circle,
    BaseBehavior,
    Badge,
    CommonEvent,
    NodeEvent,
    CubicHorizontal,
    subStyleProps,
  } = G6Lib;

  if (
    typeof Graph !== 'function' ||
    typeof treeToGraphData !== 'function' ||
    typeof register !== 'function' ||
    !ExtensionCategory ||
    !BaseNode ||
    !Circle ||
    !BaseBehavior ||
    !Badge ||
    !CubicHorizontal
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
    COLLAPSE_EXPAND: 'tekg-mindmap-collapse-expand',
  };

  const ROOT_BG = '#cfe0f8';
  const ROOT_TEXT = '#2f5e99';
  const TEXT_COLOR = '#2f3a52';
  const EDGE_COLOR = '#c7cedb';
  const ROOT_HEIGHT = 20;
  const NODE_HEIGHT = 18;
  const ROOT_FONT_SIZE = 12;
  const NODE_FONT_SIZE = 11;
  const ROOT_PADDING_X = 10;
  const NODE_PADDING_X = 8;
  const DEFAULT_TREE_PADDING = [96, 120, 96, 120];
  const COMPACT_TREE_PADDING = [56, 56, 56, 56];
  const DEFAULT_H_GAP = 112;
  const COMPACT_H_GAP = 52;
  const V_GAP = 12;
  const INITIAL_ROOT_LEFT_SHIFT = 120;
  const TAXONOMY_ALWAYS_LABELS = new Set([
    // Add exact display labels here when a taxonomy graph node should always show its name.
  ]);

  let g6Graph = null;
  let taxonomyLargeForceRenderer = null;
  let taxonomyGraphTooltip = null;
  let taxonomyGraphRenderEpoch = 0;
  let registered = false;
  let rootId = null;
  let selectedNodeId = null;
  let activeTreeConfig = null;
  let activeTaxonomyGraphConfig = null;
  let taxonomyGraphLevelState = {};
  let taxonomyGraphLevelFocus = null;
  let taxonomyGraphLegendItems = [];
  let taxonomyGraphNodeById = new Map();
  let taxonomyGraphDragging = false;
  let taxonomyGraphHoverNodeId = null;
  let taxonomyDetailClickHandler = null;
  let stateTreeRoot = null;
  let lastRenderOptions = null;
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

  function taxonomyNameHash(name) {
    let hash = 2166136261;
    const text = String(name || '');
    for (let index = 0; index < text.length; index += 1) {
      hash ^= text.charCodeAt(index);
      hash = Math.imul(hash, 16777619);
    }
    return (hash >>> 0).toString(36);
  }

  function taxonomyPayloadToElements(payload) {
    const nodes = Array.isArray(payload?.nodes) ? payload.nodes : [];
    const edges = Array.isArray(payload?.edges) ? payload.edges : [];
    const elements = [];
    const yByName = new Map();
    const namesByBaseId = new Map();
    for (const node of nodes) {
      const name = String(node?.name || '').trim();
      if (!name) continue;
      const baseId = treeNodeId(name);
      if (!namesByBaseId.has(baseId)) namesByBaseId.set(baseId, []);
      namesByBaseId.get(baseId).push(name);
    }
    const idByName = new Map();
    for (const [baseId, names] of namesByBaseId) {
      for (const name of names) {
        idByName.set(name, names.length > 1 ? `${baseId}_${taxonomyNameHash(name)}` : baseId);
      }
    }
    nodes.forEach((node, index) => {
      const name = String(node?.name || '').trim();
      if (!name) return;
      const depth = Math.max(0, Number(node?.depth) || 0);
      yByName.set(name, index * 28);
      elements.push({
        position: { x: depth * 250, y: index * 28 },
        data: {
          id: idByName.get(name),
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
      const parentId = idByName.get(parent);
      const childId = idByName.get(child);
      if (!parentId || !childId) return;
      elements.push({
        data: {
          id: `${parentId}__SUBFAMILY_OF__${childId}`,
          source: parentId,
          target: childId,
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
    return 'TE - Human';
  }

  function getTreeDisplayLabel(raw) {
    const key = String(raw || '').trim();
    return TREE_DISPLAY_LABELS.get(key) || key;
  }

  function normalizeTextWidth(text, isRoot) {
    const compact = !!getActiveTreeConfig()?.compactLayout;
    const length = Math.max(2, String(text || '').length);
    const avg = isRoot ? (compact ? 8.8 : 9.6) : (compact ? 6.2 : 7.1);
    const padding = isRoot ? (compact ? 8 : ROOT_PADDING_X) : (compact ? 6 : NODE_PADDING_X);
    return Math.round(length * avg + padding);
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
    const defaultDetailHtml = 'G6 mindmap tree view is active.';

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
      async onNodeClick(nodeData, context) {
        const { fixedModeEnabled, homePreviewMode } = context || {};
        if (fixedModeEnabled || homePreviewMode || typeof window.__TEKG_LOAD_DYNAMIC_GRAPH !== 'function') {
          return false;
        }
        const data = nodeData?.data || {};
        const query = data.queryLabel || data.rawLabel || nodeData?.id;
        const directChildCount = Math.max(
          0,
          Number(nodeData?.style?.directChildCount || nodeData?.data?.directChildCount || 0) || 0
        );
        const hasChildren = directChildCount > 0 || (Array.isArray(nodeData?.children) && nodeData.children.length > 0);
        if (!query || hasChildren) return false;
        await window.__TEKG_LOAD_DYNAMIC_GRAPH(query);
        return true;
      },
    };
  }

  function getActiveTreeConfig() {
    if (!activeTreeConfig) activeTreeConfig = buildDefaultTreeConfig();
    return activeTreeConfig;
  }

  function isCompactTreeLayout() {
    return !!getActiveTreeConfig()?.compactLayout;
  }

  function getTreePadding() {
    return isCompactTreeLayout() ? COMPACT_TREE_PADDING : DEFAULT_TREE_PADDING;
  }

  function getHorizontalGap() {
    return isCompactTreeLayout() ? COMPACT_H_GAP : DEFAULT_H_GAP;
  }

  function resolveTreeLabelFill(datum, isRoot) {
    const config = getActiveTreeConfig();
    const data = datum?.data || {};
    if (typeof config.buildLabelFill === 'function') {
      const color = config.buildLabelFill(data, datum?.id || '');
      if (color) return String(color);
    }
    return isRoot ? ROOT_TEXT : TEXT_COLOR;
  }

  function resolveTreeLabelFontWeight(datum, isRoot) {
    const config = getActiveTreeConfig();
    const data = datum?.data || {};
    if (typeof config.buildLabelFontWeight === 'function') {
      const weight = config.buildLabelFontWeight(data, datum?.id || '');
      if (weight) return String(weight);
    }
    return isRoot ? '600' : 'normal';
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
    const positionYById = new Map();
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
        treeIsMeta: data.tree_is_meta === true,
      });
      positionYById.set(data.id, Number(item?.position?.y) || 0);
      if (data.tree_depth === 0 && !detectedRootId) detectedRootId = data.id;
    }

    const getY = (id) => positionYById.get(id) || 0;

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

  function buildTreeNode(nodeId, source, parent = null) {
    const node = source.nodes.get(nodeId);
    if (!node) return null;
    const childIds = source.children.get(nodeId) || [];
    const depth = node.treeDepth || 0;
    const label = getDisplayLabel(node.label, node.description, depth);
    const treeNode = {
      id: node.id,
      name: label,
      data: {
        rawLabel: node.label,
        queryLabel: node.queryLabel,
        description: node.description,
        treeDepth: depth,
        treeIsMeta: node.treeIsMeta === true,
      },
      _collapsed: true,
      _hidden: false,
      _matched: false,
      _matched_path: false,
      _parent: parent,
      style: {
        collapsed: true,
        direction: depth === 0 ? 'center' : 'right',
        labelText: label,
      },
      children: [],
    };
    treeNode.children = childIds.map((childId) => buildTreeNode(childId, source, treeNode)).filter(Boolean);
    return treeNode;
  }

  function removeTaxonomyGraphTooltip() {
    if (taxonomyGraphTooltip && taxonomyGraphTooltip.parentNode) taxonomyGraphTooltip.parentNode.removeChild(taxonomyGraphTooltip);
    taxonomyGraphTooltip = null;
  }

  function ensureTaxonomyGraphTooltip() {
    const host = getEl('g6-default-tree-surface');
    if (!host) return null;
    if (window.getComputedStyle(host).position === 'static') host.style.position = 'relative';
    if (taxonomyGraphTooltip && taxonomyGraphTooltip.parentNode === host) return taxonomyGraphTooltip;
    removeTaxonomyGraphTooltip();
    const tooltip = document.createElement('div');
    tooltip.className = 'tekg-taxonomy-graph-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    Object.assign(tooltip.style, {
      position: 'absolute',
      display: 'none',
      zIndex: '12',
      maxWidth: '280px',
      padding: '6px 8px',
      border: '1px solid #cbd5e1',
      borderRadius: '4px',
      background: '#ffffff',
      color: '#0f172a',
      fontSize: '12px',
      lineHeight: '1.35',
      pointerEvents: 'none',
      boxShadow: '0 2px 8px rgba(15, 23, 42, 0.16)',
    });
    host.appendChild(tooltip);
    taxonomyGraphTooltip = tooltip;
    return tooltip;
  }

  function showTaxonomyGraphTooltip(nodeData, context = {}) {
    const host = getEl('g6-default-tree-surface');
    const tooltip = ensureTaxonomyGraphTooltip();
    if (!host || !tooltip || !nodeData) return;
    const label = String(nodeData.data?.rawLabel || nodeData.data?.displayLabel || nodeData.displayLabel || nodeData.label || nodeData.id || '');
    const taxonomyLevel = String(nodeData.data?.taxonomyLevelLabel || `Level ${Number(nodeData.level ?? nodeData.data?.treeDepth ?? 0) || 0}`);
    tooltip.textContent = `${label} - ${taxonomyLevel}`;
    tooltip.style.display = 'block';
    const hostRect = host.getBoundingClientRect();
    const client = context.client;
    const fallbackX = hostRect.width / 2;
    const fallbackY = hostRect.height / 2;
    const desiredX = Number.isFinite(client?.x) ? client.x - hostRect.left + 12 : fallbackX;
    const desiredY = Number.isFinite(client?.y) ? client.y - hostRect.top + 12 : fallbackY;
    const maxLeft = Math.max(8, hostRect.width - tooltip.offsetWidth - 8);
    const maxTop = Math.max(8, hostRect.height - tooltip.offsetHeight - 8);
    tooltip.style.left = `${Math.min(maxLeft, Math.max(8, desiredX))}px`;
    tooltip.style.top = `${Math.min(maxTop, Math.max(8, desiredY))}px`;
  }

  function hideTaxonomyGraphTooltip() {
    if (taxonomyGraphTooltip) taxonomyGraphTooltip.style.display = 'none';
  }

  function taxonomyLevelKey(depth) {
    return `level-${Math.max(0, Number(depth) || 0)}`;
  }

  function taxonomyLevelLabel(depth) {
    const safeDepth = Math.max(0, Number(depth) || 0);
    const labels = ['Human TE', 'Class', 'Order', 'Superfamily', 'Family', 'Subfamily'];
    return labels[safeDepth] || `Level ${safeDepth}`;
  }

  function taxonomyGraphNodeSize(depth, siblingCount = 0, childCount = 0) {
    const safeDepth = Math.max(0, Number(depth) || 0);
    const base = [68, 46, 34, 24, 15, 9][safeDepth] || 7;
    const siblingPenalty = siblingCount > 160 ? 4 : siblingCount > 90 ? 3 : siblingCount > 48 ? 2 : 0;
    const childBonus = childCount > 20 ? 3 : childCount > 8 ? 1 : 0;
    return Math.max(6, base - siblingPenalty + childBonus);
  }

  function taxonomyGraphNodeColor(depth, maxDepth) {
    const safeDepth = Math.max(0, Number(depth) || 0);
    const safeMax = Math.max(1, Number(maxDepth) || 1);
    const t = Math.max(0, Math.min(1, safeDepth / safeMax));
    const start = [20, 47, 124];
    const end = [37, 99, 235];
    const rgb = start.map((value, index) => Math.round(value + (end[index] - value) * t));
    return `rgb(${rgb[0]}, ${rgb[1]}, ${rgb[2]})`;
  }

  function isTaxonomyJumpableNode(datum) {
    const data = datum?.data || datum || {};
    const query = String(data.queryLabel || data.query_label || '').trim();
    return data.hasGraphEntity === true && !!query && data.isRoot !== true;
  }

  function isTaxonomyAlwaysLabeled(label) {
    const raw = String(label || '').trim();
    return !!raw && TAXONOMY_ALWAYS_LABELS.has(raw);
  }

  function wrapTaxonomyGraphLabel(label, depth, force = false) {
    const raw = String(label || '').trim();
    if (!raw) return '';
    if (force) return raw;
    if (!force && !isTaxonomyAlwaysLabeled(raw)) return '';
    const limit = depth <= 3 ? 16 : depth === 4 ? 12 : 10;
    if (raw.length <= limit) return raw;
    return `${raw.slice(0, Math.max(4, limit - 1))}...`;
  }

  function seededUnit(seed) {
    let hash = 2166136261;
    const text = String(seed || '');
    for (let index = 0; index < text.length; index += 1) {
      hash ^= text.charCodeAt(index);
      hash = Math.imul(hash, 16777619);
    }
    return ((hash >>> 0) % 10000) / 10000;
  }

  function buildTaxonomyLevelLegendItems(nodes, maxDepth) {
    const counts = new Map();
    for (const node of Array.isArray(nodes) ? nodes : []) {
      const key = node.data?.taxonomyLevelKey || taxonomyLevelKey(node.data?.treeDepth || 0);
      counts.set(key, (counts.get(key) || 0) + 1);
    }
    const safeMax = Math.max(0, Number(maxDepth) || 0);
    return Array.from({ length: safeMax + 1 }, (_, depth) => {
      const key = taxonomyLevelKey(depth);
      return {
        key,
        depth,
        label: taxonomyLevelLabel(depth),
        count: counts.get(key) || 0,
        color: taxonomyGraphNodeColor(depth, safeMax),
      };
    }).filter((item) => item.count > 0);
  }

  function normalizeTaxonomyLevelState(nextState = {}) {
    const normalized = {};
    for (const item of taxonomyGraphLegendItems) {
      normalized[item.key] = typeof nextState[item.key] === 'boolean'
        ? nextState[item.key]
        : item.depth < 6;
    }
    return normalized;
  }

  function taxonomyLevelIsVisible(levelKey) {
    if (!levelKey) return true;
    if (!Object.keys(taxonomyGraphLevelState || {}).length) taxonomyGraphLevelState = normalizeTaxonomyLevelState();
    return taxonomyGraphLevelState[levelKey] !== false;
  }

  function taxonomyNodeIsVisible(datum) {
    const key = datum?.data?.taxonomyLevelKey || taxonomyLevelKey(datum?.data?.treeDepth || 0);
    return taxonomyLevelIsVisible(key);
  }

  function taxonomyNodeMatchesFocus(datum) {
    if (!taxonomyGraphLevelFocus) return true;
    const key = datum?.data?.taxonomyLevelKey || taxonomyLevelKey(datum?.data?.treeDepth || 0);
    return key === taxonomyGraphLevelFocus;
  }

  function taxonomyEndpointId(endpoint) {
    if (endpoint && typeof endpoint === 'object') {
      return String(endpoint.id || endpoint.data?.id || '');
    }
    return String(endpoint || '');
  }

  function taxonomyNodeOpacity(datum) {
    if (!taxonomyNodeIsVisible(datum)) return 0;
    return taxonomyNodeMatchesFocus(datum) ? 1 : 0.16;
  }

  function taxonomyEdgeVisible(edge) {
    const sourceId = taxonomyEndpointId(edge?.source);
    const targetId = taxonomyEndpointId(edge?.target);
    const source = taxonomyGraphNodeById.get(sourceId);
    const target = taxonomyGraphNodeById.get(targetId);
    return taxonomyNodeIsVisible(source) && taxonomyNodeIsVisible(target);
  }

  function taxonomyEdgeMatchesFocus(edge) {
    if (!taxonomyGraphLevelFocus) return true;
    const source = taxonomyGraphNodeById.get(taxonomyEndpointId(edge?.source));
    const target = taxonomyGraphNodeById.get(taxonomyEndpointId(edge?.target));
    return taxonomyNodeMatchesFocus(source) || taxonomyNodeMatchesFocus(target);
  }

  function taxonomyEdgeOpacity(edge) {
    if (!taxonomyEdgeVisible(edge)) return 0;
    return taxonomyEdgeMatchesFocus(edge) ? 0.72 : 0.08;
  }

  function taxonomyGraphVisibleLabel(datum) {
    if (!taxonomyNodeIsVisible(datum)) return '';
    if (taxonomyGraphHoverNodeId && String(datum?.id || '') === taxonomyGraphHoverNodeId) {
      return wrapTaxonomyGraphLabel(datum?.data?.displayLabel || datum?.data?.rawLabel || datum?.id || '', Number(datum?.data?.treeDepth || 0), true);
    }
    if (taxonomyGraphDragging && Number(datum?.data?.treeDepth || 0) > 3) return '';
    return datum?.style?.labelText || '';
  }

  async function redrawTaxonomyGraph() {
    if (taxonomyLargeForceRenderer && typeof taxonomyLargeForceRenderer.redraw === 'function') {
      return taxonomyLargeForceRenderer.redraw();
    }
    if (!g6Graph || typeof g6Graph.draw !== 'function') return false;
    await g6Graph.draw();
    return true;
  }

  async function rerenderActiveTaxonomyGraph() {
    if (!activeTaxonomyGraphConfig) return redrawTaxonomyGraph();
    const nextOptions = Object.assign({}, activeTaxonomyGraphConfig, {
      visibleTaxonomyLevels: taxonomyGraphLevelState,
      taxonomyLevelFocus: taxonomyGraphLevelFocus,
    });
    await renderTaxonomyGraph(nextOptions);
    return true;
  }

  async function applyTaxonomyGraphLevelState(nextState = {}) {
    taxonomyGraphLevelState = normalizeTaxonomyLevelState(nextState);
    if (taxonomyLargeForceRenderer && typeof taxonomyLargeForceRenderer.setLegendState === 'function') {
      return taxonomyLargeForceRenderer.setLegendState(taxonomyGraphLevelState);
    }
    return rerenderActiveTaxonomyGraph();
  }

  async function setTaxonomyGraphLevelFocus(nextKey = null) {
    const key = String(nextKey || '').trim();
    taxonomyGraphLevelFocus = key || null;
    if (taxonomyLargeForceRenderer && typeof taxonomyLargeForceRenderer.setLegendFocus === 'function') {
      return taxonomyLargeForceRenderer.setLegendFocus(taxonomyGraphLevelFocus);
    }
    return redrawTaxonomyGraph();
  }

  function buildTaxonomyGraphData(source, width, height) {
    const root = source.rootId || [...source.nodes.values()].find((node) => Number(node.treeDepth || 0) === 0)?.id || '';
    const rootTree = root ? buildTreeNode(root, source) : null;
    if (!rootTree) return { nodes: [], edges: [], rootId: '' };
    const nodeEntries = [];
    const parentById = new Map();
    walkTree(rootTree, (node) => {
      nodeEntries.push(node);
      for (const child of Array.isArray(node.children) ? node.children : []) {
        parentById.set(child.id, node.id);
      }
    });
    const maxDepth = nodeEntries.reduce((max, node) => Math.max(max, Number(node.data?.treeDepth || 0)), 0);
    const centerX = Math.max(260, width * 0.42);
    const centerY = Math.max(160, height / 2);
    const nodes = [];
    const edges = [];
    const firstLevelChildren = Array.isArray(rootTree.children) ? rootTree.children : [];
    const branchIndexById = new Map(firstLevelChildren.map((child, index) => [child.id, index]));
    const branchById = new Map([[rootTree.id, rootTree.id]]);
    const branchSize = new Map();
    const positionById = new Map([[rootTree.id, { x: centerX, y: centerY }]]);
    const descendantCountById = new Map();

    function countDescendants(node) {
      const children = Array.isArray(node.children) ? node.children : [];
      const count = children.reduce((sum, child) => sum + 1 + countDescendants(child), 0);
      descendantCountById.set(node.id, count);
      return count;
    }
    countDescendants(rootTree);

    function assignBranch(node, branchId) {
      const depth = Math.max(0, Number(node.data?.treeDepth || 0));
      const nextBranch = depth === 1 ? node.id : branchId;
      branchById.set(node.id, nextBranch || rootTree.id);
      branchSize.set(nextBranch || rootTree.id, (branchSize.get(nextBranch || rootTree.id) || 0) + 1);
      for (const child of Array.isArray(node.children) ? node.children : []) {
        assignBranch(child, nextBranch);
      }
    }
    assignBranch(rootTree, rootTree.id);

    function seededAngle(id, fallback = 0) {
      return Math.PI * 2 * seededUnit(`${id}:angle`) + fallback;
    }

    walkTree(rootTree, (node) => {
        const depth = Math.max(0, Number(node.data?.treeDepth || 0));
        const childCount = Array.isArray(node.children) ? node.children.length : 0;
        const descendantCount = descendantCountById.get(node.id) || 0;
        const parentId = parentById.get(node.id) || '';
        const siblingCount = parentId && source.children.has(parentId) ? source.children.get(parentId).length : firstLevelChildren.length || 1;
        const branchId = branchById.get(node.id) || rootTree.id;
        const branchIndex = branchIndexById.has(branchId) ? branchIndexById.get(branchId) : 0;
        const branchCount = Math.max(1, firstLevelChildren.length);
        let x = centerX;
        let y = centerY;
        if (depth === 1) {
          const branchAngle = -Math.PI / 2 + (Math.PI * 2 * branchIndex) / branchCount;
          const branchRadius = Math.min(width, height) * 0.14 + Math.min(72, Math.sqrt(branchSize.get(branchId) || 1) * 2.5);
          x = centerX + Math.cos(branchAngle) * branchRadius;
          y = centerY + Math.sin(branchAngle) * branchRadius;
        } else if (depth > 1) {
          const parentPosition = positionById.get(parentId) || { x: centerX, y: centerY };
          const childIds = parentId && source.children.has(parentId) ? source.children.get(parentId) : [];
          const childIndex = Math.max(0, childIds.indexOf(node.id));
          const localAngle = (Math.PI * 2 * childIndex) / Math.max(1, childIds.length) + seededAngle(node.id, 0) * 0.18;
          const crowding = siblingCount > 120 ? 0.58 : siblingCount > 64 ? 0.72 : siblingCount > 32 ? 0.86 : 1;
          const localRadius = (24 + Math.min(58, Math.sqrt(Math.max(1, siblingCount)) * 4.5)) * crowding;
          x = parentPosition.x + Math.cos(localAngle) * localRadius;
          y = parentPosition.y + Math.sin(localAngle) * localRadius;
        }
        positionById.set(node.id, { x, y });
        const label = depth === 0 ? 'Human TE' : getDisplayLabel(node.data?.rawLabel || node.name, node.data?.description, depth);
        const size = taxonomyGraphNodeSize(depth, siblingCount, childCount);
        const treeIsMeta = node.data?.treeIsMeta === true;
        const jumpable = !!String(node.data?.queryLabel || '').trim() && !treeIsMeta && depth > 0;
        const levelKey = taxonomyLevelKey(depth);
        nodes.push({
          id: node.id,
          data: {
            rawLabel: node.data?.rawLabel || node.name,
            displayLabel: label,
            queryLabel: node.data?.queryLabel || node.data?.rawLabel || '',
            description: node.data?.description,
            treeDepth: depth,
            taxonomyLevelKey: levelKey,
            taxonomyLevelLabel: taxonomyLevelLabel(depth),
            taxonomyOnly: !jumpable,
            hasGraphEntity: jumpable,
            isRoot: depth === 0,
            directChildCount: childCount,
            descendantCount,
            siblingCount,
            parentId,
          },
          style: {
            x,
            y,
            clusterX: x,
            clusterY: y,
            size,
            labelText: '',
            fill: taxonomyGraphNodeColor(depth, maxDepth),
            stroke: depth === 0 ? '#0f172a' : jumpable ? '#1d4ed8' : '#1d4ed8',
            lineDash: depth === 0 || jumpable ? [] : [5, 4],
          },
        });
    });

    for (const [parentId, childIds] of source.children.entries()) {
      for (const childId of childIds) {
        edges.push({
          id: `${parentId}__taxonomy__${childId}`,
          source: parentId,
          target: childId,
          data: { relation: 'taxonomy parent' },
        });
      }
    }

    taxonomyGraphLegendItems = buildTaxonomyLevelLegendItems(nodes, maxDepth);
    taxonomyGraphLevelState = normalizeTaxonomyLevelState(
      activeTaxonomyGraphConfig?.visibleTaxonomyLevels || taxonomyGraphLevelState
    );
    const allNodeById = new Map(nodes.map((node) => [String(node.id), node]));
    const visibleDescendantCountById = new Map();

    function visibleChildIdsOf(nodeId) {
      const childIds = source.children.get(nodeId) || [];
      return childIds.filter((childId) => {
        const child = allNodeById.get(String(childId));
        return child && taxonomyNodeIsVisible(child);
      });
    }

    function countVisibleDescendants(nodeId) {
      if (visibleDescendantCountById.has(nodeId)) return visibleDescendantCountById.get(nodeId);
      const visibleChildren = visibleChildIdsOf(nodeId);
      const count = visibleChildren.reduce((sum, childId) => sum + 1 + countVisibleDescendants(childId), 0);
      visibleDescendantCountById.set(nodeId, count);
      return count;
    }

    for (const node of nodes) {
      const visibleDirectChildCount = visibleChildIdsOf(String(node.id)).length;
      const visibleDescendantCount = countVisibleDescendants(String(node.id));
      node.data.visibleDirectChildCount = visibleDirectChildCount;
      node.data.visibleDescendantCount = visibleDescendantCount;
      node.style.labelText = wrapTaxonomyGraphLabel(
        node.data.displayLabel,
        Number(node.data.treeDepth || 0),
        false
      );
    }
    const visibleNodes = nodes.filter((node) => taxonomyNodeIsVisible(node));
    const visibleNodeIds = new Set(visibleNodes.map((node) => String(node.id)));
    const visibleEdges = edges.filter((edge) => (
      visibleNodeIds.has(String(edge.source)) && visibleNodeIds.has(String(edge.target))
    ));
    taxonomyGraphNodeById = new Map(visibleNodes.map((node) => [String(node.id), node]));

    return {
      nodes: visibleNodes,
      edges: visibleEdges,
      rootId: root,
      maxDepth,
      taxonomyLegendItems: taxonomyGraphLegendItems,
      originalNodeCount: nodes.length,
      originalEdgeCount: edges.length,
    };
  }

  function buildTaxonomyGraphDetailHtml(nodeData) {
    if (!nodeData) {
      return [
        '<strong>TE classification graph</strong>',
        '<br>All nodes are rendered from the selected static taxonomy tree.',
        '<br><span class="meta">Dashed outline: taxonomy-only display node. It is not inserted into Neo4j.</span>',
      ].join('');
    }
    const data = nodeData.data || {};
    const label = nodeData.style?.labelText || data.rawLabel || nodeData.id || '';
    const depth = Number(data.treeDepth || 0);
    const description = String(data.description || '').trim();
    const rawLabel = String(data.rawLabel || label || '').trim();
    const jumpable = isTaxonomyJumpableNode(nodeData);
    const ancestry = Array.isArray(data.ancestryLabels) ? data.ancestryLabels.filter(Boolean) : [];
    const degree = Math.max(0, Number(data.degree) || 0);
    const isFamily = depth === 4;
    const hasDirectChildren = Array.isArray(data.directChildIds) && data.directChildIds.length > 0;
    const expanded = taxonomyLargeForceRenderer?.getDiagnostics?.().expandedFamilyIds?.includes(String(nodeData.id));
    if (isFamily) {
      const familyAction = hasDirectChildren
        ? `<div class="inspect-card__actions"><button class="inspect-card__button" type="button" data-taxonomy-graph-${expanded ? 'collapse' : 'expand'}="${escapeHtml(nodeData.id)}">${expanded ? 'Collapse' : 'Expand'}</button></div>`
        : '';
      return [
        `<strong>${escapeHtml(label)}</strong>`,
        `<br><span class="meta">TE &middot; degree ${degree}</span>`,
        description ? `<br>${escapeHtml(description)}` : '',
        ancestry.length ? `<br><span class="meta">${escapeHtml(ancestry.join(' \u2192 '))}</span>` : '',
        familyAction,
      ].join('');
    }
    const action = jumpable
      ? `<div class="inspect-card__actions"><button class="inspect-card__button" type="button" data-taxonomy-graph-jump="${escapeHtml(nodeData.id)}">Jump to dynamic graph</button></div>`
      : `<div class="inspect-card__actions"><button class="inspect-card__button" type="button" data-taxonomy-graph-no-jump="${escapeHtml(rawLabel)}">Taxonomy node only</button></div>`;
    return [
      `<strong>${escapeHtml(label)}</strong>`,
      `<br><span class="meta">${escapeHtml(data.taxonomyLevelLabel || taxonomyLevelLabel(depth))}</span>`,
      jumpable
        ? '<br><span class="meta">Display status: jumpable TE node in the taxonomy graph.</span>'
        : '<br><span class="meta">Display status: taxonomy-only node; Neo4j is not modified.</span>',
      description ? `<br>${escapeHtml(description)}` : '',
      action,
    ].join('');
  }

  function bindTaxonomyGraphDetailActions(detailEl) {
    if (!detailEl) return;
    if (taxonomyDetailClickHandler) {
      detailEl.removeEventListener('click', taxonomyDetailClickHandler);
    }
    taxonomyDetailClickHandler = (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      const expandFamilyId = target.getAttribute('data-taxonomy-graph-expand');
      const collapseFamilyId = target.getAttribute('data-taxonomy-graph-collapse');
      if (expandFamilyId || collapseFamilyId) {
        event.preventDefault();
        const familyId = expandFamilyId || collapseFamilyId;
        const operation = expandFamilyId ? 'expandFamily' : 'collapseFamily';
        Promise.resolve(taxonomyLargeForceRenderer?.[operation]?.(familyId)).then(() => {
          const nodeData = g6Graph && typeof g6Graph.getNodeData === 'function' ? g6Graph.getNodeData(familyId) : null;
          updateDetail(nodeData);
        }).catch((error) => {
          console.error(`Taxonomy Family ${expandFamilyId ? 'expand' : 'collapse'} failed:`, error);
        });
        return;
      }
      const jumpNodeId = target.getAttribute('data-taxonomy-graph-jump');
      if (jumpNodeId) {
        event.preventDefault();
        const nodeData = g6Graph && typeof g6Graph.getNodeData === 'function' ? g6Graph.getNodeData(jumpNodeId) : null;
        if (nodeData && activeTaxonomyGraphConfig && typeof activeTaxonomyGraphConfig.onJump === 'function') {
          Promise.resolve(activeTaxonomyGraphConfig.onJump(nodeData)).catch((error) => {
            console.error('Taxonomy graph jump failed:', error);
          });
        }
        return;
      }
      const label = target.getAttribute('data-taxonomy-graph-no-jump');
      if (!label) return;
      event.preventDefault();
      if (activeTaxonomyGraphConfig && typeof activeTaxonomyGraphConfig.onJumpUnavailable === 'function') {
        activeTaxonomyGraphConfig.onJumpUnavailable({ data: { rawLabel: label, label } });
      }
    };
    detailEl.addEventListener('click', taxonomyDetailClickHandler);
  }

  function walkTree(node, visitor) {
    if (!node || typeof visitor !== 'function') return;
    visitor(node);
    const children = Array.isArray(node.children) ? node.children : [];
    children.forEach((child) => walkTree(child, visitor));
  }

  function syncCollapsedStyle(node) {
    walkTree(node, (current) => {
      current.style ||= {};
      current.style.collapsed = !!current._collapsed;
    });
  }

  function initTreeState(root, options = {}) {
    const expandAll = !!(options && options.expandAll);
    walkTree(root, (node) => {
      node._matched = false;
      node._matched_path = false;
      node._hidden = false;
      node._collapsed = expandAll ? false : true;
    });
    if (root && !expandAll) {
      root._collapsed = false;
    }
    syncCollapsedStyle(root);
  }

  function findTreeStateNode(node, targetId) {
    if (!node || !targetId) return null;
    if (String(node.id) === String(targetId)) return node;
    const children = Array.isArray(node.children) ? node.children : [];
    for (const child of children) {
      const found = findTreeStateNode(child, targetId);
      if (found) return found;
    }
    return null;
  }

  function setTreeCollapsed(node, collapsed) {
    if (!node) return;
    node._collapsed = !!collapsed;
    node.style ||= {};
    node.style.collapsed = !!collapsed;
  }


  function buildVisibleGraphData(root, viewportWidth, viewportHeight, options = {}) {
    const nodes = [];
    const edges = [];
    let rowIndex = 0;
    const treePadding = getTreePadding();
    const left = treePadding[3];
    const top = treePadding[0];
    const bottom = treePadding[2];
    const rowGap = NODE_HEIGHT + V_GAP;

    function visit(node, depth) {
      if (!node || node._hidden) return null;
      const children = Array.isArray(node.children) ? node.children.filter((child) => !child._hidden) : [];
      const expandedChildren = node._collapsed ? [] : children;
      const childLayouts = [];
      for (const child of expandedChildren) {
        const childLayout = visit(child, depth + 1);
        if (childLayout) childLayouts.push(childLayout);
      }

      const y = childLayouts.length > 0
        ? (childLayouts[0].y + childLayouts[childLayouts.length - 1].y) / 2
        : rowIndex++ * rowGap;
      const labelText = node?.style?.labelText || node.name || node.id;
      const directChildCount = Array.isArray(node.children) ? node.children.length : 0;
      const isRoot = depth === 0;
      const nodeWidth = normalizeTextWidth(labelText, isRoot);

      nodes.push({
        id: node.id,
        data: {
          ...(node.data || {}),
          directChildCount,
          depth,
        },
        children: expandedChildren.map((child) => child.id),
        style: {
          ...node.style,
          x: 0,
          y,
          collapsed: !!node._collapsed,
          directChildCount,
          labelText,
          __depth: depth,
          __width: nodeWidth,
        },
      });

      for (const child of expandedChildren) {
        edges.push({
          id: `${node.id}__${child.id}`,
          source: node.id,
          target: child.id,
        });
      }

      return { id: node.id, y, depth, width: nodeWidth };
    }

    visit(root, 0);

    if (!nodes.length) {
      return { nodes: [], edges: [] };
    }

    const maxWidthByDepth = new Map();
    for (const node of nodes) {
      const depth = node.style.__depth || 0;
      const width = node.style.__width || 0;
      maxWidthByDepth.set(depth, Math.max(maxWidthByDepth.get(depth) || 0, width));
    }

    const xByDepth = new Map();
    const maxDepth = Math.max(...nodes.map((node) => node.style.__depth || 0));
    for (let depth = 0; depth <= maxDepth; depth += 1) {
      const width = maxWidthByDepth.get(depth) || 0;
      if (depth === 0) {
        xByDepth.set(depth, left + width / 2);
      } else {
        const prevWidth = maxWidthByDepth.get(depth - 1) || 0;
        const prevX = xByDepth.get(depth - 1) || left;
        xByDepth.set(depth, prevX + prevWidth / 2 + getHorizontalGap() + width / 2);
      }
    }

    const ys = nodes.map((node) => node.style.y);
    const minY = Math.min(...ys);
    let offsetY = (viewportHeight / 2) - minY;
    const centerNodeId = String(options.centerNodeId || '').trim();
    if (centerNodeId) {
      const centerNode = nodes.find((node) => String(node.id || '') === centerNodeId);
      if (centerNode) {
        offsetY = (viewportHeight / 2) - Number(centerNode.style.y || 0);
      }
    }

    nodes.forEach((node) => {
      const depth = node.style.__depth || 0;
      node.style.x = xByDepth.get(depth) || left;
      node.style.y += offsetY;
      delete node.style.__depth;
      delete node.style.__width;
    });

    return { nodes, edges };
  }

  async function rerenderFromStateTree(options = {}) {
    if (!stateTreeRoot || !lastRenderOptions) return;
    await renderTreeData(stateTreeRoot, { ...lastRenderOptions, ...options });
  }

  class TEKGMindmapNode extends BaseNode {
    static defaultStyleProps = {
      showIcon: false,
      ports: [{ placement: 'right' }, { placement: 'left' }],
    };

    constructor(options) {
      Object.assign(options.style, TEKGMindmapNode.defaultStyleProps);
      super(options);
    }

    get directChildCount() {
      return Number(this.parsedAttributes?.directChildCount || 0);
    }

    get rootNodeId() {
      return rootId;
    }

    getKeyStyle(attributes) {
      const [width, height] = this.getSize(attributes);
      return {
        width,
        height,
        ...super.getKeyStyle(attributes),
      };
    }

    drawKeyShape(attributes, container) {
      return this.upsert('key', 'rect', this.getKeyStyle(attributes), container);
    }

    getLabelStyle(attributes) {
      if (attributes.label === false || !attributes.labelText) return false;
      return subStyleProps(this.getGraphicStyle(attributes), 'label');
    }

    isShowCollapse(attributes) {
      const { collapsed, showIcon } = attributes;
      return !collapsed && showIcon && this.directChildCount > 0;
    }

    getCountStyle(attributes) {
      const { collapsed, color } = attributes;
      const directChildren = this.directChildCount;
      if (!collapsed || directChildren === 0) return false;
      const [width, height] = this.getSize(attributes);
      return {
        backgroundFill: color,
        backgroundHeight: 16,
        backgroundWidth: 16,
        cursor: 'pointer',
        fill: '#fff',
        fontSize: 11,
        text: '+',
        textAlign: 'center',
        x: width + 4,
        y: Math.round(height * 0.5),
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

    getCollapseStyle(attributes) {
      const { showIcon, color } = attributes;
      const directChildren = this.directChildCount;
      if (!this.isShowCollapse(attributes)) return false;
      const [width, height] = this.getSize(attributes);
      return {
        backgroundFill: color,
        backgroundHeight: 16,
        backgroundWidth: 16,
        cursor: 'pointer',
        fill: '#fff',
        fontSize: 11,
        text: '-',
        textAlign: 'center',
        visibility: showIcon ? 'visible' : 'hidden',
        x: width + 4,
        y: Math.round(height * 0.5),
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

    forwardEvent(target, type, listener) {
      if (target && !Reflect.has(target, '__bind__')) {
        Reflect.set(target, '__bind__', true);
        target.addEventListener(type, listener);
      }
    }

    render(attributes = this.parsedAttributes, container = this) {
      super.render(attributes, container);
      this.drawCollapseShape(attributes, container);
      this.drawCountShape(attributes, container);
    }
  }

  class TEKGMindmapEdge extends CubicHorizontal {
    getKeyPath(attributes) {
      return super.getKeyPath(attributes);
    }
  }

  class TEKGCollapseExpandMindmap extends BaseBehavior {
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
      graph.on(NodeEvent.POINTER_ENTER, this.showIcon);
      graph.on(NodeEvent.POINTER_LEAVE, this.hideIcon);
      graph.on(TREE_EVENT.COLLAPSE_EXPAND, this.onCollapseExpand);
    }

    unbindEvents() {
      const { graph } = this.context;
      graph.off(NodeEvent.POINTER_ENTER, this.showIcon);
      graph.off(NodeEvent.POINTER_LEAVE, this.hideIcon);
      graph.off(TREE_EVENT.COLLAPSE_EXPAND, this.onCollapseExpand);
    }

    status = 'idle';

    showIcon = (event) => {
      this.setIcon(event, true);
    };

    hideIcon = (event) => {
      this.setIcon(event, false);
    };

    setIcon = (event, show) => {
      if (this.status !== 'idle') return;
      const id = event?.target?.id;
      if (!id) return;
      const { graph, element } = this.context;
      graph.updateNodeData([{ id, style: { showIcon: show } }]);
      element.draw({ animation: false, silence: true });
    };

    onCollapseExpand = async (event) => {
      this.status = 'busy';
      const { id, collapsed } = event;
      const stateNode = findTreeStateNode(stateTreeRoot, id);
      setTreeCollapsed(stateNode, collapsed);
      await rerenderFromStateTree({ centerNodeId: id });
      this.status = 'idle';
    };
  }

  function ensureRegistered() {
    if (registered) return;
    register(ExtensionCategory.NODE, 'tekg-mindmap', TEKGMindmapNode);
    register(ExtensionCategory.EDGE, 'tekg-mindmap', TEKGMindmapEdge);
    register(ExtensionCategory.BEHAVIOR, 'tekg-mindmap-collapse-expand-tree', TEKGCollapseExpandMindmap);
    registered = true;
  }

  function updateDetail(nodeData) {
    const detailEl = getEl('node-details');
    if (!detailEl) return;
    const config = getActiveTreeConfig();
    if (!nodeData) {
      detailEl.innerHTML = config.defaultDetailHtml || 'G6 mindmap tree view is active.';
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
    } catch (_error) {}
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

  async function activateNode(nodeId) {
    if (!g6Graph || !nodeId || typeof g6Graph.getNodeData !== 'function') return;
    const nodeData = g6Graph.getNodeData(nodeId);
    updateDetail(nodeData);
    setSelectedNode(nodeId);
  }

  function destroyGraph(options = {}) {
    if (options.preserveTaxonomyRenderEpoch !== true) taxonomyGraphRenderEpoch += 1;
    removeTaxonomyGraphTooltip();
    if (taxonomyLargeForceRenderer && typeof taxonomyLargeForceRenderer.destroy === 'function') {
      taxonomyLargeForceRenderer.destroy();
      g6Graph = null;
    }
    taxonomyLargeForceRenderer = null;
    if (g6Graph && typeof g6Graph.destroy === 'function') g6Graph.destroy();
    g6Graph = null;
  }

  function alignRootToLeft(graph, viewportHeight) {
    if (!graph || typeof graph.translateBy !== 'function') return;
    try {
      graph.translateBy([INITIAL_ROOT_LEFT_SHIFT, Math.max(0, Math.round(viewportHeight * 0.5))], false);
    } catch (_error) {
      // fallback to default view when viewport translation is unavailable
    }
  }

  async function renderTreeData(treeData, options = {}) {
    const detailEl = getEl('node-details');
    try {
      ensureRegistered();
      setRendererVisibility();
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
        if (detailEl) detailEl.textContent = 'Failed to build G6 mindmap tree data.';
        return;
      }

      rootId = String(options.rootId || treeData.id || '');
      activeTreeConfig = {
        ...buildDefaultTreeConfig(),
        ...(options.config && typeof options.config === 'object' ? options.config : {}),
      };
      lastRenderOptions = {
        rootId,
        config: activeTreeConfig,
      };

      destroyGraph();
      host.innerHTML = '';
      const visibleData = buildVisibleGraphData(treeData, width, height, options);

      const graph = new Graph({
        container: host,
        width,
        height,
        autoResize: true,
        autoFit: false,
        padding: getTreePadding(),
        animation: false,
        cursor: 'grab',
        data: visibleData,
        node: {
          type: 'tekg-mindmap',
          style: (datum) => {
            const isRoot = datum.id === rootId;
            const labelText = resolveTreeLabel(datum);
            const compact = isCompactTreeLayout();
            return {
              direction: isRoot ? 'center' : 'right',
              labelText,
              size: [normalizeTextWidth(labelText, isRoot), isRoot ? ROOT_HEIGHT : NODE_HEIGHT],
              labelFontFamily: 'Gill Sans',
              labelFontSize: isRoot ? ROOT_FONT_SIZE : NODE_FONT_SIZE,
              labelPlacement: 'center',
              labelTextAlign: 'center',
              labelPadding: isRoot
                ? (compact ? [2, 4, 2, 4] : [2, 6, 2, 6])
                : (compact ? [1, 6, 1, 6] : [1, 8, 1, 8]),
              labelFill: resolveTreeLabelFill(datum, isRoot),
              labelFontWeight: resolveTreeLabelFontWeight(datum, isRoot),
              labelBackground: true,
              labelBackgroundFill: isRoot ? ROOT_BG : '#ffffff',
              fill: isRoot ? ROOT_BG : '#ffffff',
              stroke: isRoot ? ROOT_BG : COLORS[Math.max(0, (datum?.data?.treeDepth || 1) - 1) % COLORS.length],
              radius: 999,
              color: isRoot ? ROOT_BG : COLORS[Math.max(0, (datum?.data?.treeDepth || 1) - 1) % COLORS.length],
              lineWidth: isRoot ? 1.4 : 1.2,
            };
          },
          state: {
            selected: {
              lineWidth: 0,
              labelBackground: true,
              labelBackgroundFill: '#e8f7ff',
              labelBackgroundRadius: 10,
            },
          },
        },
        edge: {
          type: 'tekg-mindmap',
          style: {
            lineWidth: 1.4,
            stroke: EDGE_COLOR,
          },
        },
        behaviors: [
          'drag-canvas',
          {
            type: 'scroll-canvas',
            key: 'scroll-canvas',
            sensitivity: 1,
          },
          'tekg-mindmap-collapse-expand-tree',
          {
            type: 'click-select',
            enable: (event) => event.targetType === 'node' && event.target.id !== rootId,
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
        const nodeData = graph.getNodeData(targetId);
        try {
          if (typeof activeTreeConfig?.onNodeClick === 'function') {
            const handled = await activeTreeConfig.onNodeClick(nodeData, {
              nodeId: targetId,
              fixedModeEnabled: typeof fixedView !== 'undefined' && fixedView === true,
              homePreviewMode: window.__TEKG_EMBED_MODE === 'home-preview',
            });
            if (handled) return;
          }
        } catch (error) {
          if (detailEl) {
            detailEl.textContent = `G6 mindmap tree click failed: ${error && error.message ? error.message : 'unknown error'}`;
          }
          return;
        }
        await activateNode(targetId);
      });
    } catch (error) {
      if (detailEl) {
        detailEl.textContent = `G6 mindmap tree failed: ${error && error.message ? error.message : 'unknown error'}`;
      }
      console.error('G6 mindmap tree failed:', error);
    }
  }

  async function renderTaxonomyGraph(options = {}) {
    const detailEl = getEl('node-details');
    const renderEpoch = ++taxonomyGraphRenderEpoch;
    const renderVariantKey = getCurrentTreeVariantKey();
    const isCurrentRender = () => (
      renderEpoch === taxonomyGraphRenderEpoch
      && renderVariantKey === getCurrentTreeVariantKey()
    );
    try {
      setRendererVisibility();
      const host = getEl('g6-default-tree-surface');
      if (!host) return;

      await ensureCurrentTreeElements();
      if (!isCurrentRender()) return;
      const source = buildStrictTreeSource();
      if (!source.rootId) {
        if (detailEl) detailEl.textContent = 'Taxonomy graph data is unavailable.';
        return;
      }

      await new Promise((resolve) => requestAnimationFrame(resolve));
      if (!isCurrentRender()) return;
      const width = host.clientWidth || host.offsetWidth;
      const height = host.clientHeight || host.offsetHeight;
      if (!width || !height) {
        if (detailEl) detailEl.textContent = 'G6 container has no size yet.';
        return;
      }

      destroyGraph({ preserveTaxonomyRenderEpoch: true });
      taxonomyGraphDragging = false;
      taxonomyGraphHoverNodeId = null;
      host.innerHTML = '';
      stateTreeRoot = null;
      activeTaxonomyGraphConfig = options && typeof options === 'object' ? options : {};
      taxonomyGraphLevelFocus = String(activeTaxonomyGraphConfig.taxonomyLevelFocus || taxonomyGraphLevelFocus || '').trim() || null;
      bindTaxonomyGraphDetailActions(detailEl);
      const adapter = window.__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER;
      const core = window.__TEKG_LARGE_FORCE_GRAPH_CORE;
      if (!adapter || typeof adapter.fromTaxonomySource !== 'function' || !core || typeof core.createRenderer !== 'function') {
        throw new Error('TE taxonomy large-force renderer is unavailable.');
      }
      const graphData = adapter.fromTaxonomySource(source, {
        width,
        height,
        treeVariant: renderVariantKey,
        visibleTaxonomyLevels: activeTaxonomyGraphConfig?.visibleTaxonomyLevels || taxonomyGraphLevelState,
        getDisplayLabel,
      });
      rootId = graphData.rootId;
      taxonomyGraphLegendItems = (graphData.legend?.items || []).map((item) => ({
        key: String(item.key || '').trim(),
        depth: Math.max(0, Number(item.depth) || 0),
        kind: String(item.kind || 'taxonomy-level'),
        label: String(item.label || item.key || '').trim(),
        color: String(item.color || '#94a3b8'),
        count: Math.max(0, Number(item.count) || 0),
        visible: item.visible !== false,
        focusable: item.focusable !== false,
      })).filter((item) => item.key && item.label);
      taxonomyGraphLevelState = normalizeTaxonomyLevelState(
        activeTaxonomyGraphConfig?.visibleTaxonomyLevels || taxonomyGraphLevelState
      );
      taxonomyGraphNodeById = new Map((graphData.nodes || []).map((node) => [String(node.id), {
        id: node.id,
        data: {
          rawLabel: node.rawLabel || node.label,
          displayLabel: node.displayLabel || node.label,
          queryLabel: node.payload?.queryLabel || '',
          description: node.description || node.payload?.description || '',
          treeDepth: node.level,
          taxonomyLevelKey: node.legendKeys?.[0] || '',
          taxonomyLevelLabel: node.payload?.taxonomyLevelLabel || '',
          taxonomyOnly: node.payload?.taxonomyOnly === true,
          hasGraphEntity: node.payload?.hasGraphEntity === true,
          isRoot: node.payload?.isRoot === true,
        },
      }]));
      activeTreeConfig = {
        defaultDetailHtml: buildTaxonomyGraphDetailHtml(null),
        buildDetailHtml: buildTaxonomyGraphDetailHtml,
      };
      const renderer = core.createRenderer({
        container: host,
        width,
        height,
        data: graphData,
        legendFocus: taxonomyGraphLevelFocus,
        callbacks: {
          onNodeHover: (nodeData, context) => {
            if (nodeData) showTaxonomyGraphTooltip(nodeData, context);
            else hideTaxonomyGraphTooltip();
          },
          onNodeClick: async (nodeData) => {
            updateDetail(nodeData);
            setSelectedNode(nodeData?.id || '');
          },
        },
      });
      taxonomyLargeForceRenderer = renderer;
      await renderer.render(graphData);
      if (!isCurrentRender() || taxonomyLargeForceRenderer !== renderer) {
        renderer.destroy();
        return;
      }
      g6Graph = renderer.getGraph();
      clearSelectedNode();
      updateDetail(null);
    } catch (error) {
      if (!isCurrentRender()) return;
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
      if (detailEl) detailEl.textContent = 'Mindmap tree data is unavailable.';
      return;
    }

    stateTreeRoot = buildTreeNode(source.rootId, source);
    initTreeState(stateTreeRoot);
    await renderTreeData(stateTreeRoot, {
      rootId: source.rootId,
      config: buildDefaultTreeConfig(),
    });
  }

  async function renderStructuredTree(options = {}) {
    const treeData = options && typeof options === 'object' ? options.treeData : null;
    stateTreeRoot = treeData || null;
    if (stateTreeRoot) {
      initTreeState(stateTreeRoot, {
        expandAll: !!(options.expandAll || (options.config && options.config.expandAll)),
      });
    }
    await renderTreeData(stateTreeRoot, {
      rootId: options.rootId,
      config: options.config,
    });
  }

  window.__TEKG_G6_MINDMAP_TREE = {
    render: renderDefaultTree,
    renderGraph: renderTaxonomyGraph,
    getLevelLegendItems() {
      return taxonomyGraphLegendItems.slice();
    },
    applyLevelState: applyTaxonomyGraphLevelState,
    setLevelFocus: setTaxonomyGraphLevelFocus,
    renderStructuredTree,
    destroy: destroyGraph,
    getGraph() {
      return g6Graph;
    },
    getDiagnostics() {
      return taxonomyLargeForceRenderer && typeof taxonomyLargeForceRenderer.getDiagnostics === 'function'
        ? taxonomyLargeForceRenderer.getDiagnostics()
        : null;
    },
    getStateTree() {
      return stateTreeRoot;
    },
  };
  window.__TEKG_G6_DEFAULT_TREE = window.__TEKG_G6_MINDMAP_TREE;
}());
