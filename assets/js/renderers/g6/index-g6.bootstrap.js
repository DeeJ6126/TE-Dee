(function () {
  if (window.__TEKG_RENDERER_MODE !== 'g6') return;

  const params = new URLSearchParams(window.location.search);
  const embedMode = typeof window.__TEKG_EMBED_MODE === 'string' ? window.__TEKG_EMBED_MODE : '';
  const initialQuery = String(window.__TEKG_INITIAL_QUERY || '').trim();
  const initialQueryType = String(params.get('type') || '').trim().toLowerCase();
  const initialClassQuery = String(params.get('class') || '').trim();
  const initialTreeVariant = String(params.get('tree') || window.__TEKG_TREE_VARIANT || 'rmsk_repbase').trim() || 'rmsk_repbase';
  const initialTaxonomyDisplayMode = params.get('taxonomy') === 'graph' ? 'graph' : 'tree';
  const initialRouteFilters = {
    resetDefaults: true,
    nodes: parseAppliedRouteValue(params, 'nodes'),
    relations: parseAppliedRouteValue(params, 'relations'),
    minPmids: params.has('min_pmids')
      ? Math.max(0, Number.parseInt(params.get('min_pmids') || '0', 10) || 0)
      : 0,
  };
  const taxonomyCanvasRenderer = window.__TEKG_CANVAS_TAXONOMY;
  const diseaseClassificationAdapter = window.__TEKG_DISEASE_CLASSIFICATION_ADAPTER;

  const els = {
    title: document.getElementById('page-title'),
    badge: document.getElementById('page-badge'),
    graphTitle: document.getElementById('graph-title'),
    graphSearchType: document.getElementById('graphSearchType'),
    searchInput: document.getElementById('node-search'),
    graphSearchSubmit: document.getElementById('graph-search-submit'),
    edgeLabelsBtn: document.getElementById('toggle-edge-labels'),
    edgeLabelsText: document.getElementById('edge-labels-text'),
    fixedBtn: document.getElementById('toggle-fixed-view'),
    fixedText: document.getElementById('fixed-view-text'),
    backBtn: document.getElementById('back-graph'),
    backText: document.getElementById('back-text'),
    resetBtn: document.getElementById('reset-graph'),
    resetText: document.getElementById('reset-text'),
    exportMenuWrap: document.getElementById('export-menu-wrap'),
    exportMenuToggle: document.getElementById('export-menu-toggle'),
    exportMenu: document.getElementById('export-menu'),
    exportMenuCsv: document.getElementById('export-menu-csv'),
    exportMenuPng: document.getElementById('export-menu-png'),
    exportMenuSvg: document.getElementById('export-menu-svg'),
    expandModeBtn: document.getElementById('toggle-expand-mode'),
    expandModeText: document.getElementById('expand-mode-text'),
    taxonomyDisplayBtn: document.getElementById('toggle-taxonomy-display'),
    taxonomyDisplayText: document.getElementById('taxonomy-display-text'),
    detail: document.getElementById('node-details'),
    treeSurface: document.getElementById('g6-default-tree-surface'),
    taxonomyCanvasSurface: document.getElementById('taxonomy-canvas-surface'),
    dynamicSurface: document.getElementById('g6-dynamic-surface'),
    graphLoader: document.getElementById('graph-preloader'),
    graphLoaderLabel: document.getElementById('graph-preloader-label'),
    mechanismLoaderSlot: document.getElementById('te-mechanism-loader-slot'),
    graphLegend: document.getElementById('graph-type-legend'),
    graphLegendTitle: document.getElementById('graph-legend-title'),
    graphLegendList: document.getElementById('graph-legend-list'),
    graphLegendTabs: document.querySelector('.graph-legend-mode-switch'),
    graphLegendApply: document.getElementById('graph-legend-apply'),
    graphRelationControls: document.getElementById('graph-relation-controls'),
    relationMinPmidsInput: document.getElementById('graph-relation-min-pmids'),
    main: document.querySelector('.main'),
  };

  const UI = {
    en: {
      pageTitle: 'TEKG G6 Workspace',
      badge: 'Tree-first preview with test-aligned dynamic graph',
      graphTitle: 'G6 Graph Workspace',
      searchPlaceholder: 'Search LINE1, L1HS, disease, or function',
      showEdgeLabelsOn: 'Show relations: On',
      showEdgeLabelsOff: 'Show relations: Off',
      fixedOn: 'Fixed view: On',
      fixedOff: 'Fixed view: Off',
      back: 'Back',
      backTo: (label) => `Back to ${label}`,
      backToTree: 'Back to taxonomy',
      reset: 'Reset',
      expandModeOn: 'Expand mode: On',
      expandModeOff: 'Expand mode: Off',
      taxonomyDisplayTree: 'Switch: Tree',
      taxonomyDisplayGraph: 'Switch: Graph',
      treeDetail:
        '<strong>No node selected</strong>Click a TE node to inspect it, then click again to enter the dynamic graph.',
      loadingDetail: (query) => buildLoadingDetailHtml(`Preparing the dynamic graph for ${escapeHtml(query)}.`),
      loadingOverlay: (query) => `Preparing ${escapeHtml(query)} ...`,
      graphError: (message) => `Failed: ${message || 'unknown error'}`,
      legendTitle: 'Entity Legend',
    },
  };

  let currentMode = 'tree';
  let currentTaxonomyDisplayMode = initialTaxonomyDisplayMode;
  let currentGraphSource = 'tree';
  let currentGraphQuery = '';
  let currentGraphQueryType = '';
  let currentGraphClassQuery = '';
  let currentTreeVariant = initialTreeVariant;
  let currentSelectedNode = null;
  let currentAnswerGraphElements = [];
  let currentQueryGraphElements = [];
  let currentRelationLegendMeta = [];
  let rawRelationLegendMeta = [];
  let graphHistory = [];
  let dynamicFrame = null;
  let dynamicBridgePromise = null;
  let activeLegendMode = 'entity';
  let relationLegendState = {};
  let taxonomyLegendState = {};
  let relationMinPmids = 0;
  let activeLegendHighlight = null;
  let expandModeEnabled = false;
  let expandedNodeKeys = new Set();
  let graphIsLoading = false;
  let dynamicNavigationEpoch = 0;
  let taxonomyNavigationEpoch = 0;
  let taxonomyTransitionQueue = Promise.resolve();
  let diseaseNavigationEpoch = 0;
  let diseaseTransitionQueue = Promise.resolve();
  let desiredTaxonomyRoute = {
    treeVariant: initialTreeVariant,
    taxonomyDisplayMode: initialTaxonomyDisplayMode,
  };
  let stableTaxonomyRoute = { ...desiredTaxonomyRoute };
  let pendingGraphRequest = null;
  let legendFilterPending = false;
  let pendingFilterBaseline = null;
  let exportMenuCloseTimer = null;
  let exportMenuWasOpenOnPointerDown = false;
  let exportMenuReasonOnPointerDown = '';
  let exportMenuOpenedAtOnPointerDown = 0;
  let exportMenuOpenReason = '';
  let exportMenuOpenedAt = 0;

  window.currentLang = 'en';
  window.fixedView = true;
  window.showEdgeLabels = false;
  window.currentKeyNodeLevel = 1;
  window.focusLevel = 0;
  if (typeof window.cy === 'undefined') {
    window.cy = { nodes: () => [] };
  }

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function buildLoadingDetailHtml(label) {
    return [
      '<div class="detail-loading">',
      '  <div class="detail-loading-icon" aria-hidden="true">',
      '    <span></span>',
      '    <span></span>',
      '  </div>',
      `  <div class="detail-loading-label">${label || 'Loading graph...'}</div>`,
      '</div>',
    ].join('');
  }

  function textSet() {
    return UI[window.currentLang] || UI.en;
  }

  const teLoader = window.__TEKG_TE_LOADER;
  const getTeLoaderKind = (nodeOrQuery) => teLoader?.classify(nodeOrQuery) || 'default';
  const renderTeMechanismLoader = (kind, label, colors = {}) => (
    teLoader?.render({
      overlay: els.graphLoader,
      slot: els.mechanismLoaderSlot,
      kind,
      label,
      colors,
    }) || { kind: 'default', label: String(label || ''), rendered: false }
  );
  const resolveLoaderContext = (label, context) => {
    if (context && typeof context === 'object') {
      const labelValue = String(context.label || context.queryLabel || context.rawLabel || context.displayLabel || teLoader?.labelFromText(label) || '').trim();
      return {
        kind: context.kind || getTeLoaderKind(context),
        label: labelValue,
      };
    }
    const labelValue = teLoader?.labelFromText(label) || String(label || '');
    return {
      kind: getTeLoaderKind(labelValue),
      label: labelValue,
    };
  };

  const LEGEND_FALLBACK_ORDER = ['TE', 'Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Pharmaceutical', 'Toxin', 'Lipid', 'Peptide', 'Carbohydrate', 'Paper'];
  const LEGEND_FALLBACK_LABELS = {
    TE: 'TE',
    Disease: 'Disease',
    Function: 'Function',
    Gene: 'Gene',
    Protein: 'Protein',
    RNA: 'RNA',
    Mutation: 'Mutation',
    Pharmaceutical: 'Pharmaceutical',
    Toxin: 'Toxin',
    Lipid: 'Lipid',
    Peptide: 'Peptide',
    Carbohydrate: 'Carbohydrate',
    Paper: 'Paper',
  };
  const LEGEND_FALLBACK_COLORS = {
    TE: '#4e79ff',
    Disease: '#ff7a7a',
    Function: '#41b883',
    Gene: '#8a7cf8',
    Protein: '#59bfb6',
    RNA: '#72b6ff',
    Mutation: '#ffb066',
    Pharmaceutical: '#a98cf6',
    Toxin: '#df8a78',
    Lipid: '#95c863',
    Peptide: '#54c9c0',
    Carbohydrate: '#d5b458',
    Paper: '#f2a93b',
  };

  const LEGEND_DISEASE_TYPES = new Set(['Disease', 'DiseaseClass']);
  const HIDDEN_ENTITY_LEGEND_TYPES = new Set(['DiseaseClass', 'DiseaseCategory']);
  let visibleTypeState = {};

  function getLegendTypeMeta() {
    const sharedMeta = window.__TEKG_G6_TYPE_META && typeof window.__TEKG_G6_TYPE_META === 'object'
      ? window.__TEKG_G6_TYPE_META
      : {};
    const order = Array.isArray(sharedMeta.legendOrder) && sharedMeta.legendOrder.length
      ? sharedMeta.legendOrder
      : LEGEND_FALLBACK_ORDER;
    const labels = sharedMeta.labels && typeof sharedMeta.labels === 'object'
      ? sharedMeta.labels
      : LEGEND_FALLBACK_LABELS;
    const colors = sharedMeta.colors && typeof sharedMeta.colors === 'object'
      ? sharedMeta.colors
      : LEGEND_FALLBACK_COLORS;

    const presentTypes = getCurrentLegendNodeTypes();
    const orderedTypes = presentTypes.size
      ? [...new Set(order)].filter((type) => presentTypes.has(type))
      : [...new Set(order)];

    return orderedTypes
      .filter((type) => (labels[type] || type) && colors[type])
      .filter((type) => !HIDDEN_ENTITY_LEGEND_TYPES.has(type))
      .map((type) => ({
        type,
        label: String(labels[type] || type),
        color: String(colors[type] || '#94a3b8'),
      }));
  }

  function getTaxonomyLegendMeta() {
    const renderer = taxonomyCanvasRenderer;
    if (renderer && typeof renderer.getLegendMeta === 'function') {
      return renderer.getLegendMeta().map((item) => ({
        key: String(item.key || '').trim(),
        depth: Math.max(0, Number(item.depth) || 0),
        label: String(item.label || item.key || '').trim(),
        color: String(item.color || '#94a3b8'),
        count: Math.max(0, Number(item.count) || 0),
      })).filter((item) => item.key && item.label);
    }
    return [];
  }

  function ensureTaxonomyLegendState(seed = taxonomyLegendState) {
    const next = seed && typeof seed === 'object' ? { ...seed } : {};
    for (const item of getTaxonomyLegendMeta()) {
      if (typeof next[item.key] !== 'boolean') next[item.key] = item.depth < 6;
    }
    taxonomyLegendState = next;
    return taxonomyLegendState;
  }

  function legendTypeForNodeType(nodeType) {
    const type = String(nodeType || '').trim();
    return LEGEND_DISEASE_TYPES.has(type) ? 'Disease' : type;
  }

  function getCurrentGraphElements() {
    if (currentGraphSource === 'answer') return currentAnswerGraphElements;
    return currentQueryGraphElements;
  }

  function getCurrentLegendNodeTypes() {
    const types = new Set();
    for (const item of Array.isArray(getCurrentGraphElements()) ? getCurrentGraphElements() : []) {
      const data = item && item.data ? item.data : null;
      if (!data || data.source || data.target) continue;
      const legendType = legendTypeForNodeType(data.type || 'TE');
      if (legendType && !HIDDEN_ENTITY_LEGEND_TYPES.has(legendType)) types.add(legendType);
    }
    return types;
  }

  function ensureVisibleTypeState() {
    const seed = visibleTypeState && typeof visibleTypeState === 'object' ? visibleTypeState : {};
    const next = seed && typeof seed === 'object'
      ? { ...seed }
      : {};
    for (const item of getLegendTypeMeta()) {
      if (typeof next[item.type] !== 'boolean') next[item.type] = true;
    }
    visibleTypeState = next;
    return visibleTypeState;
  }

  function syncDiseaseLegendStateFromEntityState() {
    const visible = ensureVisibleTypeState();
    const diseaseVisible = visible.Disease !== false;
    visible.Disease = diseaseVisible;
    visible.DiseaseClass = diseaseVisible;
    visible.DiseaseCategory = diseaseVisible;
    return visible;
  }

  function applyEntityLegendCheckState(type, checked) {
    const visible = syncDiseaseLegendStateFromEntityState();
    const normalizedType = String(type || '').trim();
    if (!normalizedType) return visible;
    if (LEGEND_DISEASE_TYPES.has(normalizedType)) {
      visible.Disease = !!checked;
      visible.DiseaseClass = !!checked;
      visible.DiseaseCategory = !!checked;
      return visible;
    }
    visible[normalizedType] = !!checked;
    return visible;
  }

  function getVisibleTypePayload() {
    return { ...ensureVisibleTypeState() };
  }

  function ensureRelationLegendState() {
    const next = relationLegendState && typeof relationLegendState === 'object'
      ? { ...relationLegendState }
      : {};
    for (const item of currentRelationLegendMeta) {
      const relationType = String(item.relationType || '').trim();
      if (relationType && typeof next[relationType] !== 'boolean') next[relationType] = true;
    }
    relationLegendState = next;
    return relationLegendState;
  }

  function getVisibleRelationPayload() {
    return { ...ensureRelationLegendState() };
  }

  function parseAppliedRouteValue(searchParams, key) {
    const present = searchParams.has(key);
    const raw = present ? String(searchParams.get(key) || '').trim() : '';
    if (!present) return { present: false, none: false, values: [] };
    if (raw.toLowerCase() === 'none') return { present: true, none: true, values: [] };
    return {
      present: true,
      none: false,
      values: [...new Set(raw.split(',').map((value) => value.trim()).filter(Boolean))],
    };
  }

  function beginLegendFilterDraft() {
    if (pendingFilterBaseline) return;
    pendingFilterBaseline = {
      visibleTypes: getVisibleTypePayload(),
      visibleRelations: getVisibleRelationPayload(),
      minPmids: Math.max(0, Number(relationMinPmids) || 0),
    };
  }

  function getAppliedVisibleTypePayload() {
    return pendingFilterBaseline
      ? { ...pendingFilterBaseline.visibleTypes }
      : getVisibleTypePayload();
  }

  function getAppliedVisibleRelationPayload() {
    return pendingFilterBaseline
      ? { ...pendingFilterBaseline.visibleRelations }
      : getVisibleRelationPayload();
  }

  function getAppliedMinPmids() {
    return pendingFilterBaseline
      ? Math.max(0, Number(pendingFilterBaseline.minPmids) || 0)
      : Math.max(0, Number(relationMinPmids) || 0);
  }

  function discardLegendFilterDraft() {
    if (!pendingFilterBaseline) return;
    visibleTypeState = { ...pendingFilterBaseline.visibleTypes };
    relationLegendState = { ...pendingFilterBaseline.visibleRelations };
    relationMinPmids = pendingFilterBaseline.minPmids;
    pendingFilterBaseline = null;
    legendFilterPending = false;
    if (els.relationMinPmidsInput) els.relationMinPmidsInput.value = String(relationMinPmids);
  }

  function routeStateForCatalog(spec, catalog) {
    const stableCatalog = [...new Set((Array.isArray(catalog) ? catalog : []).map((value) => String(value || '').trim()).filter(Boolean))];
    const allApplied = Object.fromEntries(stableCatalog.map((value) => [value, true]));
    if (!spec || spec.present !== true) return allApplied;
    if (spec.none === true) return Object.fromEntries(stableCatalog.map((value) => [value, false]));
    const recognized = new Set((Array.isArray(spec.values) ? spec.values : []).filter((value) => stableCatalog.includes(value)));
    if (!recognized.size) return allApplied;
    return Object.fromEntries(stableCatalog.map((value) => [value, recognized.has(value)]));
  }

  function applyRouteFilterState(routeFilters) {
    if (!routeFilters || routeFilters.resetDefaults !== true) return false;
    const nodeCatalog = getLegendTypeMeta().map((item) => item.type);
    const relationCatalog = (rawRelationLegendMeta.length ? rawRelationLegendMeta : currentRelationLegendMeta)
      .map((item) => String(item.relationType || '').trim()).filter(Boolean);
    visibleTypeState = routeStateForCatalog(routeFilters.nodes, nodeCatalog);
    if (Object.prototype.hasOwnProperty.call(visibleTypeState, 'Disease')) {
      visibleTypeState.DiseaseClass = visibleTypeState.Disease;
      visibleTypeState.DiseaseCategory = visibleTypeState.Disease;
    }
    relationLegendState = routeStateForCatalog(routeFilters.relations, relationCatalog);
    relationMinPmids = Math.max(0, Number.parseInt(routeFilters.minPmids || '0', 10) || 0);
    pendingFilterBaseline = null;
    legendFilterPending = false;
    if (els.relationMinPmidsInput) els.relationMinPmidsInput.value = String(relationMinPmids);
    return true;
  }

  function buildCurrentGraphDataOptions(extra = {}) {
    return Object.assign({
      showEdgeLabels: window.showEdgeLabels,
      allowInspectCard: true,
      visibleTypes: getAppliedVisibleTypePayload(),
      visibleRelations: getAppliedVisibleRelationPayload(),
      minRelationPmids: getAppliedMinPmids(),
    }, extra || {});
  }

  function relationStyleFallback(relationType) {
    const colors = ['#2563eb', '#dc2626', '#059669', '#7c3aed', '#ea580c', '#0891b2', '#be123c', '#4f46e5'];
    const text = String(relationType || 'RELATION');
    let hash = 0;
    for (let index = 0; index < text.length; index += 1) {
      hash = ((hash << 5) - hash + text.charCodeAt(index)) | 0;
    }
    return {
      color: colors[Math.abs(hash) % colors.length],
      dashed: /CLASSIFICATION|CATEGORY|TAXONOMY|SYNTHETIC/i.test(text),
    };
  }

  function relationLegendKeyForEdge(edge) {
    const relation = String(edge && edge.relation || '').trim();
    const relationType = String(edge && edge.relationType || '').trim();
    if (relation) return relation;
    if (relationType) return relationType;
    return 'RELATION';
  }

  function mergeRelationLegendMeta(left = [], right = []) {
    const merged = new Map();
    for (const item of [...(left || []), ...(right || [])]) {
      const relationType = String(item && item.relationType || '').trim();
      if (!relationType) continue;
      if (!merged.has(relationType)) merged.set(relationType, item);
    }
    return [...merged.values()];
  }

  function collectRelationLegendMetaFromElements(elements) {
    const relationTypes = new Set();
    for (const item of Array.isArray(elements) ? elements : []) {
      const data = item && item.data ? item.data : null;
      if (!data || !data.source || !data.target) continue;
      const relationType = relationLegendKeyForEdge(data);
      relationTypes.add(relationType);
    }
    return [...relationTypes]
      .sort((left, right) => left.localeCompare(right))
      .map((relationType) => ({
        relationType,
        ...relationStyleFallback(relationType),
      }));
  }

  function renderGraphLegend() {
    const t = textSet();
    const isTaxonomyMode = currentMode === 'taxonomy_graph' || currentMode === 'disease_class_graph';
    const isRelationMode = activeLegendMode === 'relation';
    if (els.graphLegendTitle) {
      els.graphLegendTitle.textContent = isTaxonomyMode
        ? 'Taxonomy Levels'
        : isRelationMode ? 'Relation Legend' : (t.legendTitle || 'Entity Legend');
    }
    if (els.graphRelationControls) {
      els.graphRelationControls.hidden = !isRelationMode || isTaxonomyMode;
    }
    if (els.graphLegendTabs) {
      els.graphLegendTabs.hidden = isTaxonomyMode;
      els.graphLegendTabs.style.display = isTaxonomyMode ? 'none' : '';
      els.graphLegendTabs.querySelectorAll('[data-legend-mode]').forEach((button) => {
        const mode = String(button.dataset.legendMode || 'entity');
        const active = mode === activeLegendMode;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    }
    if (!els.graphLegendList) return;

    if (isTaxonomyMode) {
      const visibleMap = ensureTaxonomyLegendState();
      const items = getTaxonomyLegendMeta();
      els.graphLegendList.innerHTML = items.length ? items.map((item) => {
        const safeKey = escapeHtml(item.key);
        const safeLabel = escapeHtml(item.label);
        const safeColor = escapeHtml(item.color);
        const safeCount = escapeHtml(item.count);
        const checked = visibleMap[item.key] !== false ? ' checked' : '';
        const activeClass = activeLegendHighlight
          && activeLegendHighlight.kind === 'taxonomy'
          && activeLegendHighlight.value === item.key
            ? ' is-highlight-active'
            : '';
        return [
          `<div class="graph-legend-item${activeClass}" data-highlight-kind="taxonomy" data-highlight-value="${safeKey}">`,
          `  <input class="graph-legend-check" type="checkbox" data-taxonomy-level="${safeKey}" aria-label="${safeLabel}"${checked}>`,
          `  <span class="graph-legend-swatch" style="--legend-color:${safeColor};"></span>`,
          `  <span class="graph-legend-text">${safeLabel} <span class="graph-legend-count">${safeCount}</span></span>`,
          '</div>',
        ].join('');
      }).join('') : '<div class="graph-legend-empty">No taxonomy levels in the current graph.</div>';
      return;
    }

    if (isRelationMode) {
      const visibleMap = ensureRelationLegendState();
      const items = rawRelationLegendMeta.length ? rawRelationLegendMeta : currentRelationLegendMeta;
      els.graphLegendList.innerHTML = items.length ? items.map((item) => {
        const relationType = String(item.relationType || '').trim();
        const style = item.color ? item : relationStyleFallback(relationType);
        const safeType = escapeHtml(relationType);
        const safeColor = escapeHtml(style.color || '#94a3b8');
        const checked = visibleMap[relationType] !== false ? ' checked' : '';
        const dashedClass = style.dashed ? ' is-dashed' : '';
        const activeClass = activeLegendHighlight
          && activeLegendHighlight.kind === 'relation'
          && activeLegendHighlight.value === relationType
            ? ' is-highlight-active'
            : '';
        return [
          `<div class="graph-legend-item${activeClass}" data-highlight-kind="relation" data-highlight-value="${safeType}">`,
          `  <input class="graph-legend-check" type="checkbox" data-relation="${safeType}" aria-label="${safeType}"${checked}>`,
          `  <span class="graph-relation-line${dashedClass}" style="--legend-color:${safeColor};"></span>`,
          `  <span class="graph-legend-text">${safeType}</span>`,
          '</div>',
        ].join('');
      }).join('') : '<div class="graph-legend-empty">No relation types in the visible graph.</div>';
      return;
    }

    const visibleMap = ensureVisibleTypeState();
    const items = getLegendTypeMeta();
    els.graphLegendList.innerHTML = items.map((item) => {
      const safeType = escapeHtml(item.type);
      const safeLabel = escapeHtml(item.label);
      const safeColor = escapeHtml(item.color);
      const checked = visibleMap[item.type] !== false ? ' checked' : '';
      const activeClass = activeLegendHighlight
        && activeLegendHighlight.kind === 'entity'
        && activeLegendHighlight.value === item.type
          ? ' is-highlight-active'
          : '';
      return [
        `<div class="graph-legend-item${item.type === 'Disease' ? ' is-disease-combined' : ''}${activeClass}" data-highlight-kind="entity" data-highlight-value="${safeType}">`,
        `  <input class="graph-legend-check" type="checkbox" data-type="${safeType}" aria-label="${safeLabel}"${checked}>`,
        `  <span class="graph-legend-swatch" style="--legend-color:${safeColor};"></span>`,
        `  <span class="graph-legend-text">${safeLabel}</span>`,
        '</div>',
      ].join('');
    }).join('');
  }

  function renderGraphLegendLoading() {
    if (!els.graphLegendList) return;
    els.graphLegendList.innerHTML = [
      '<div class="graph-legend-loading">',
      '  <div class="graph-legend-loading-icon" aria-hidden="true">',
      '    <span></span>',
      '    <span></span>',
      '  </div>',
      '  <span>Loading legend...</span>',
      '</div>',
    ].join('');
  }

  function syncLegendVisibility(mode = currentMode) {
    if (!els.graphLegend) return;
    const taxonomyMode = mode === 'taxonomy_graph' || mode === 'disease_class_graph';
    const hasItems = taxonomyMode ? (graphIsLoading || getTaxonomyLegendMeta().length > 0) : getLegendTypeMeta().length > 0;
    const shouldShow = (mode === 'dynamic' || taxonomyMode) && hasItems;
    els.graphLegend.hidden = !shouldShow;
    els.graphLegend.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
    if (shouldShow && graphIsLoading) {
      renderGraphLegendLoading();
    } else if (shouldShow) {
      renderGraphLegend();
    }
  }

  function applyLegendTypeFilter() {
    if (currentMode === 'taxonomy_graph' || currentMode === 'disease_class_graph') return applyTaxonomyLegendFilter();
    if (currentMode !== 'dynamic') return Promise.resolve(false);
    const sourceElements = currentGraphSource === 'answer'
      ? currentAnswerGraphElements
      : currentQueryGraphElements;
    return renderDynamicElementsFromCache(sourceElements, {
      source: currentGraphSource,
      request: buildCurrentGraphRequest(),
      deferStateChange: true,
    }).then(() => true);
  }

  function applyTaxonomyLegendFilter() {
    if (currentMode !== 'taxonomy_graph' && currentMode !== 'disease_class_graph') return Promise.resolve(false);
    const renderer = taxonomyCanvasRenderer;
    if (renderer && typeof renderer.applyLevelState === 'function') {
      return Promise.resolve(renderer.applyLevelState(ensureTaxonomyLegendState()));
    }
    return Promise.resolve(false);
  }

  function rerenderCurrentDynamicGraph() {
    if (currentMode !== 'dynamic') return Promise.resolve(false);
    const sourceElements = currentGraphSource === 'answer'
      ? currentAnswerGraphElements
      : currentQueryGraphElements;
    if (!Array.isArray(sourceElements) || sourceElements.length === 0) {
      return loadDynamicGraph(buildCurrentGraphRequest(), { pushHistory: false });
    }
    return renderDynamicElementsFromCache(sourceElements, {
      source: currentGraphSource,
      request: buildCurrentGraphRequest(),
    });
  }

  function updateCurrentGraphViewState() {
    updateButtons();
    notifyStateChange();
    if (currentMode !== 'dynamic') return Promise.resolve(false);
    const bridge = dynamicFrame && dynamicFrame.contentWindow
      ? dynamicFrame.contentWindow.__TEKG_G6_EMBED
      : null;
    if (bridge && typeof bridge.setViewState === 'function') {
      return Promise.resolve(bridge.setViewState({
        fixedView: window.fixedView,
        showEdgeLabels: window.showEdgeLabels,
        allowInspectCard: window.fixedView && !expandModeEnabled,
      }));
    }
    return Promise.resolve(false);
  }

  function markLegendFilterPending() {
    legendFilterPending = true;
    if (els.graphLegendApply) {
      els.graphLegendApply.disabled = graphIsLoading;
    }
  }

  function clearLegendFilterPending() {
    legendFilterPending = false;
    if (els.graphLegendApply) {
      els.graphLegendApply.disabled = true;
    }
  }

  function applyPendingLegendFilter() {
    if (graphIsLoading) return Promise.resolve(false);
    if (!legendFilterPending) return Promise.resolve(false);
    const previousApplied = pendingFilterBaseline;
    pendingFilterBaseline = null;
    clearLegendFilterPending();
    return applyLegendTypeFilter().then((applied) => {
      if (applied) notifyNavigation('push');
      return applied;
    }).catch(async (error) => {
      if (previousApplied) {
        visibleTypeState = { ...previousApplied.visibleTypes };
        relationLegendState = { ...previousApplied.visibleRelations };
        relationMinPmids = previousApplied.minPmids;
        if (els.relationMinPmidsInput) els.relationMinPmidsInput.value = String(relationMinPmids);
        renderGraphLegend();
        try {
          const sourceElements = currentGraphSource === 'answer'
            ? currentAnswerGraphElements
            : currentQueryGraphElements;
          await renderDynamicElementsFromCache(sourceElements, {
            source: currentGraphSource,
            request: buildCurrentGraphRequest(),
            deferStateChange: true,
          });
        } catch (rollbackError) {
          console.warn('Failed to confirm restoration of the previously applied Graph filters.', rollbackError);
        }
        notifyStateChange();
      }
      throw error;
    });
  }

  function setLegendHighlight(nextHighlight = null, options = {}) {
    const normalized = nextHighlight && typeof nextHighlight === 'object'
      ? {
          kind: (() => {
            const rawKind = String(nextHighlight.kind || '').trim();
            if (rawKind === 'relation') return 'relation';
            if (rawKind === 'taxonomy') return 'taxonomy';
            return 'entity';
          })(),
          value: String(nextHighlight.value || '').trim(),
        }
      : null;
    activeLegendHighlight = normalized && normalized.value ? normalized : null;
    if (options.renderLegend !== false) renderGraphLegend();

    if (currentMode === 'taxonomy_graph' || currentMode === 'disease_class_graph') return setTaxonomyLegendHighlight(activeLegendHighlight);
    if (currentMode !== 'dynamic') return Promise.resolve(false);
    const bridge = dynamicFrame && dynamicFrame.contentWindow
      ? dynamicFrame.contentWindow.__TEKG_G6_EMBED
      : null;
    if (bridge && typeof bridge.setLegendFocus === 'function') {
      return Promise.resolve(bridge.setLegendFocus(activeLegendHighlight));
    }
    return Promise.resolve(false);
  }

  function setTaxonomyLegendHighlight(nextHighlight = null) {
    const renderer = taxonomyCanvasRenderer;
    const key = nextHighlight && nextHighlight.kind === 'taxonomy' ? nextHighlight.value : null;
    if (renderer && typeof renderer.setLevelFocus === 'function') {
      return Promise.resolve(renderer.setLevelFocus(key));
    }
    return Promise.resolve(false);
  }

  function clearLegendHighlight(options = {}) {
    return setLegendHighlight(null, options);
  }

  function setDetail(html) {
    if (els.detail) {
      els.detail.innerHTML = html || '';
    }
  }

  function setGraphLoading(visible, label = '', context = null) {
    graphIsLoading = visible === true;
    syncLegendVisibility(currentMode);
    updateButtons();
    if (!els.graphLoader) return;
    els.graphLoader.classList.toggle('is-visible', graphIsLoading);
    els.graphLoader.setAttribute('aria-hidden', graphIsLoading ? 'false' : 'true');
    const displayLabel = label || 'Loading graph...';
    if (graphIsLoading) {
      const loaderContext = resolveLoaderContext(displayLabel, context);
      renderTeMechanismLoader(loaderContext.kind, loaderContext.label);
      teLoader?.progress?.({
        overlay: els.graphLoader,
        phase: context?.phase || 'request',
        text: context?.phaseText || 'Requesting graph data...',
      });
    }
    if (els.graphLoaderLabel) {
      els.graphLoaderLabel.textContent = displayLabel;
    }
    if (!graphIsLoading) {
      renderTeMechanismLoader('default', '');
      teLoader?.hide?.({ overlay: els.graphLoader, slot: els.mechanismLoaderSlot });
    }
  }

  function setGraphLoadingPhase(phase, phaseText) {
    if (!graphIsLoading) return;
    teLoader?.progress?.({ overlay: els.graphLoader, phase, text: phaseText });
  }

  function snapshotState() {
    const currentElements = currentGraphSource === 'answer'
      ? cloneAnswerElements(currentAnswerGraphElements)
      : cloneAnswerElements(currentQueryGraphElements);

    return {
      mode: currentMode,
      source: currentGraphSource,
      query: currentGraphQuery,
      queryType: currentGraphQueryType,
      classQuery: currentGraphClassQuery,
      treeVariant: currentTreeVariant,
      taxonomyDisplayMode: currentTaxonomyDisplayMode,
      fixedView: !!window.fixedView,
      expandModeEnabled,
      relationMinPmids: getAppliedMinPmids(),
      visibleTypes: getAppliedVisibleTypePayload(),
      visibleRelations: getAppliedVisibleRelationPayload(),
      legendTypes: getLegendTypeMeta().map((item) => item.type),
      relationTypes: (rawRelationLegendMeta.length ? rawRelationLegendMeta : currentRelationLegendMeta)
        .map((item) => String(item.relationType || '').trim()).filter(Boolean),
      selectedNode: currentSelectedNode,
      currentElements,
      lang: window.currentLang,
      historyDepth: graphHistory.length,
    };
  }

  function notifyStateChange() {
    try {
      window.dispatchEvent(new CustomEvent('tekg:g6-state-change', {
        detail: snapshotState(),
      }));
    } catch (_error) {}
  }

  function buildDetail(title, description) {
    return `<strong>${escapeHtml(title || '')}</strong>${escapeHtml(description || '')}`;
  }

  function buildQaDetail() {
    return buildDetail(
      'QA graph synchronized',
      'The graph now shows the nodes and edges used in the current answer.',
    );
  }

  function cloneAnswerElements(elements) {
    return JSON.parse(JSON.stringify(Array.isArray(elements) ? elements : []));
  }

  function filterElementsForLegend(elements) {
    const source = cloneAnswerElements(elements);
    const visibleMap = getAppliedVisibleTypePayload();
    const visibleRelations = getAppliedVisibleRelationPayload();
    const minPmids = getAppliedMinPmids();
    const visibleNodeIds = new Set();
    const filteredNodes = [];
    const filteredEdges = [];

    for (const item of source) {
      const data = item && item.data ? item.data : null;
      if (!data || data.source || data.target) continue;
      const nodeType = String(data.type || 'TE').trim() || 'TE';
      const legendType = legendTypeForNodeType(nodeType);
      if (visibleMap[legendType] === false) continue;
      filteredNodes.push(item);
      visibleNodeIds.add(String(data.id || ''));
    }

    for (const item of source) {
      const data = item && item.data ? item.data : null;
      if (!data || !data.source || !data.target) continue;
      if (!visibleNodeIds.has(String(data.source || '')) || !visibleNodeIds.has(String(data.target || ''))) continue;
      const relationType = String(data.relationType || data.relation || 'RELATION').trim() || 'RELATION';
      const relationKey = relationLegendKeyForEdge(data);
      const pmids = Array.isArray(data.pmids) ? data.pmids : [];
      const isClassificationRelation = /CLASSIFIED_AS|HAS_SUBCATEGORY|TOP_CLASS_RELATION|DISEASE_CLASSIFICATION/i.test(relationType);
      if (visibleRelations[relationKey] === false) continue;
      if (!isClassificationRelation && pmids.length < minPmids) continue;
      filteredEdges.push(item);
    }

    return [...filteredNodes, ...filteredEdges];
  }

  function syncToggleButtonState(button, active) {
    if (!button) return;
    button.classList.add('is-toggle');
    button.classList.toggle('is-active', active === true);
    button.setAttribute('aria-pressed', active === true ? 'true' : 'false');
  }

  async function renderDynamicElementsFromCache(elements, options = {}) {
    const source = options && options.source === 'answer' ? 'answer' : 'query';
    const request = normalizeGraphRequest(options && options.request ? options.request : buildCurrentGraphRequest());
    const renderElements = filterElementsForLegend(elements);

    activeLegendHighlight = null;
    currentSelectedNode = null;
    showDynamicSurface();
    updateButtons();
    setDetail('');
    if (options.deferStateChange !== true) notifyStateChange();
    setGraphLoading(true, textSet().loadingOverlay(currentGraphQuery || request.query || 'LINE1'), {
      label: currentGraphQuery || request.query || 'LINE1',
    });

    try {
      await waitForDynamicSurfaceSize();
      const frame = source === 'answer'
        ? (dynamicFrame || ensureDynamicFrame({ query: '' }))
        : ensureDynamicFrame(request);
      if (!dynamicBridgePromise) {
        dynamicBridgePromise = waitForEmbedBridge(frame);
      }

      const bridge = await dynamicBridgePromise;
      if (!bridge || typeof bridge.renderElements !== 'function') {
        throw new Error('G6 embed bridge cannot render cached graph elements');
      }
      if (typeof bridge.setLegendFocus === 'function') {
        await bridge.setLegendFocus(null);
      }

      const graphDataOptions = source === 'answer'
        ? buildCurrentGraphDataOptions({
            includePaperNodes: true,
            restrictToAnchorComponent: false,
            forceAnchorLabel: true,
          })
        : buildCurrentGraphDataOptions();

      const rendered = await bridge.renderElements(renderElements, request, {
        sourceLabel: source === 'answer' ? 'qa' : 'query',
        skipInitialStatus: true,
        claimGeneration: true,
        graphDataOptions,
      });
      rawRelationLegendMeta = collectRelationLegendMetaFromElements(source === 'answer' ? currentAnswerGraphElements : currentQueryGraphElements);
      currentRelationLegendMeta = mergeRelationLegendMeta(
        currentRelationLegendMeta,
        Array.isArray(rendered && rendered.relationLegendMeta) ? rendered.relationLegendMeta : []
      );
      ensureRelationLegendState();
      clearLegendFilterPending();
      renderGraphLegend();
      notifyStateChange();
      return true;
    } finally {
      setGraphLoading(false);
    }
  }

  function stateSignature(state) {
    if (!state || typeof state !== 'object') return 'none';
    if (state.kind === 'tree') return `tree|${state.treeVariant || currentTreeVariant || 'rmsk_repbase'}`;
    if (state.kind === 'taxonomy_graph') return `taxonomy_graph|${state.treeVariant || currentTreeVariant || 'rmsk_repbase'}`;
    if (state.kind === 'disease_class_tree' || state.kind === 'disease_class_graph') {
      return [
        state.kind,
        state.classQuery || '',
      ].join('|');
    }
    if (state.kind === 'query') {
      return [
        'query',
        state.query || '',
        state.queryType || '',
        state.classQuery || '',
        state.fixedView ? '1' : '0',
        state.expandModeEnabled ? '1' : '0',
        String(state.relationMinPmids || 0),
      ].join('|');
    }
    if (state.kind === 'answer') {
      return [
        'answer',
        state.query || '',
        state.fixedView ? '1' : '0',
        String((state.elements || []).length),
        state.expandModeEnabled ? '1' : '0',
        String(state.relationMinPmids || 0),
      ].join('|');
    }
    return 'unknown';
  }

  function captureCurrentGraphState() {
    if (currentMode === 'tree') {
      return { kind: 'tree', treeVariant: currentTreeVariant };
    }

    if (currentMode === 'taxonomy_graph') {
      return { kind: 'taxonomy_graph', treeVariant: currentTreeVariant };
    }

    if (currentMode === 'disease_class_tree' || currentMode === 'disease_class_graph') {
      return {
        kind: currentMode,
        query: currentGraphQuery,
        classQuery: currentGraphClassQuery || currentGraphQuery,
        elements: cloneAnswerElements(currentQueryGraphElements),
      };
    }

    if (currentGraphSource === 'answer') {
      return {
        kind: 'answer',
        query: currentGraphQuery,
        fixedView: !!window.fixedView,
        elements: cloneAnswerElements(currentAnswerGraphElements),
        expandModeEnabled,
        relationMinPmids: getAppliedMinPmids(),
      };
    }

    return {
      kind: 'query',
      query: currentGraphQuery,
      queryType: currentGraphQueryType,
      classQuery: currentGraphClassQuery,
      elements: cloneAnswerElements(currentQueryGraphElements),
      relationLegendMeta: dataClone(currentRelationLegendMeta),
      rawRelationLegendMeta: dataClone(rawRelationLegendMeta),
      visibleTypeState: getAppliedVisibleTypePayload(),
      relationLegendState: getAppliedVisibleRelationPayload(),
      expandedNodeKeys: [...expandedNodeKeys],
      fixedView: !!window.fixedView,
      expandModeEnabled,
      relationMinPmids: getAppliedMinPmids(),
    };
  }

  function pushGraphStateToHistory(snapshot) {
    if (!snapshot || typeof snapshot !== 'object') return;
    const nextSignature = stateSignature(snapshot);
    const lastSignature = graphHistory.length ? stateSignature(graphHistory[graphHistory.length - 1]) : '';
    if (nextSignature === lastSignature) return;
    graphHistory.push(snapshot);
  }

  function pushCurrentStateToHistory() {
    pushGraphStateToHistory(captureCurrentGraphState());
  }

  function describeHistoryState(state) {
    if (!state || typeof state !== 'object') return textSet().back || 'Back';
    if (state.kind === 'tree') {
      return textSet().backToTree || textSet().back || 'Back';
    }
    if (state.kind === 'taxonomy_graph') {
      return 'Back to taxonomy graph';
    }
    if (state.kind === 'disease_class_tree' || state.kind === 'disease_class_graph') {
      const label = String(state.classQuery || state.query || '').trim();
      if (!label) return textSet().backToTree || textSet().back || 'Back';
      return typeof textSet().backTo === 'function'
        ? textSet().backTo(label)
        : `Back to ${label}`;
    }
    const label = String(state.classQuery || state.query || '').trim();
    if (!label) {
      return textSet().back || 'Back';
    }
    return typeof textSet().backTo === 'function'
      ? textSet().backTo(label)
      : `Back to ${label}`;
  }

  function getTreeVariants() {
    return [
      {
        key: 'rmsk_repbase',
        label: 'RMSK + Repbase taxonomy',
        summary: 'Curated homepage TE tree parsed from tree_rmsk_repbase.txt.',
        source_tree: 'data/taxonomy/transposon_tree/tree_rmsk_repbase.txt',
        counts: {},
      },
      {
        key: 'all',
        label: 'All TE taxonomy',
        summary: 'Full TE tree parsed from tree_all.txt.',
        source_tree: 'data/taxonomy/transposon_tree/tree_all.txt',
        counts: {},
      },
    ];
  }

  function getCurrentTreeVariantPayload() {
    return getTreeVariants().find((item) => item.key === currentTreeVariant) || getTreeVariants()[0] || null;
  }

  function normalizeTreeVariantKey(value) {
    const key = String(value || '').trim();
    const variants = getTreeVariants();
    if (variants.some((item) => item.key === key)) return key;
    return variants[0]?.key || 'rmsk_repbase';
  }

  function buildTreeVariantDetailHtml() {
    const payload = getCurrentTreeVariantPayload();
    const label = payload && payload.label ? String(payload.label) : 'Tree';
    const summary = payload && payload.summary ? String(payload.summary) : 'Tree data is active.';
    const sourceTree = payload && payload.source_tree ? String(payload.source_tree) : '';
    const counts = payload && payload.counts && typeof payload.counts === 'object' ? payload.counts : {};
    const matched = Number(counts.matched_nodes || 0);
    const edges = Number(counts.lineage_edges || 0);
    const lines = [
      `<strong>${escapeHtml(label)}</strong>`,
      `<br>${escapeHtml(summary)}`,
    ];
    if (sourceTree) lines.push(`<br><span class="meta">Source: ${escapeHtml(sourceTree)}</span>`);
    lines.push(`<br><span class="meta">Matched TE nodes: ${matched} | Lineage edges: ${edges}</span>`);
    lines.push('<br>Click a TE node to inspect it.');
    return lines.join('');
  }

  function updateBackButton() {
    if (!els.backBtn) return;
    if (currentMode === 'tree' || currentMode === 'taxonomy_graph') {
      els.backBtn.hidden = true;
      els.backBtn.disabled = true;
      els.backBtn.classList.toggle('is-inactive', true);
      if (els.backText) els.backText.textContent = textSet().back || 'Back';
      return;
    }
    const hasHistory = graphHistory.length > 0;
    els.backBtn.hidden = !hasHistory;
    els.backBtn.disabled = !hasHistory;
    els.backBtn.classList.toggle('is-inactive', !hasHistory);
    if (els.backText) {
      const previousState = hasHistory ? graphHistory[graphHistory.length - 1] : null;
      els.backText.textContent = previousState ? describeHistoryState(previousState) : (textSet().back || 'Back');
    }
  }

  function notifyNavigation(history = 'push', override = {}) {
    try {
      window.dispatchEvent(new CustomEvent('tekg:g6-navigation', {
        detail: {
          ...snapshotState(),
          ...override,
          history,
        },
      }));
    } catch (_error) {}
  }

  function normalizeQueryType(value) {
    const normalized = String(value || '').trim().toLowerCase();
    if (normalized === 'disease_class' || normalized === 'diseaseclass') return 'disease_class';
    const graphTypes = {
      te: 'TE',
      disease: 'Disease',
      function: 'Function',
      gene: 'Gene',
      protein: 'Protein',
      rna: 'RNA',
      mutation: 'Mutation',
      pharmaceutical: 'Pharmaceutical',
      toxin: 'Toxin',
      lipid: 'Lipid',
      peptide: 'Peptide',
      carbohydrate: 'Carbohydrate',
    };
    if (Object.prototype.hasOwnProperty.call(graphTypes, normalized)) return graphTypes[normalized];
    return '';
  }

  function updateTaxonomyButtons() {
    const t = textSet();
    if (els.taxonomyDisplayText) {
      els.taxonomyDisplayText.textContent = currentTaxonomyDisplayMode === 'graph'
        ? (t.taxonomyDisplayTree || 'Switch: Tree')
        : (t.taxonomyDisplayGraph || 'Switch: Graph');
    }
    if (els.taxonomyDisplayBtn) {
      els.taxonomyDisplayBtn.setAttribute('aria-pressed', 'false');
      els.taxonomyDisplayBtn.classList.remove('is-active');
    }
  }

  const entityExamples = {
    TE: 'L1HS',
    Disease: "Alzheimer's disease",
    Function: 'A-to-I RNA editing',
    Gene: 'TP53',
    Protein: 'ORF1p',
    RNA: 'mRNA',
    Mutation: 'hypomethylation',
    Pharmaceutical: 'azacytidine',
    Toxin: 'oxidative stress',
    Lipid: 'cholesterol',
    Peptide: 'peptide',
    Carbohydrate: 'glucose',
  };

  function selectedGraphSearchType() {
    return normalizeQueryType(els.graphSearchType ? els.graphSearchType.value : '') || 'TE';
  }

  function syncGraphSearchType(type) {
    if (!els.graphSearchType) return;
    const normalized = normalizeQueryType(type);
    if (!normalized || normalized === 'disease_class') return;
    const hasOption = Array.from(els.graphSearchType.options || []).some((option) => option.value === normalized);
    if (hasOption) els.graphSearchType.value = normalized;
  }

  function updateGraphSearchPlaceholder() {
    if (!els.searchInput) return;
    const type = selectedGraphSearchType();
    const example = entityExamples[type] || type || 'entity';
    els.searchInput.placeholder = `Select a ${type} entity, e.g. ${example}`;
  }

  function submitGraphSearch() {
    if (!els.searchInput) return;
    const request = {
      query: els.searchInput.value,
      queryType: selectedGraphSearchType(),
    };
    navigateGraph(request, { history: 'push' }).catch((error) => {
      setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
    });
  }

  function buildCurrentGraphRequest() {
    if (currentGraphQueryType === 'disease_class') {
      const classQuery = String(currentGraphClassQuery || currentGraphQuery || '').trim();
      return {
        query: classQuery,
        queryType: 'disease_class',
        classQuery,
      };
    }
    return {
      query: String(currentGraphQuery || '').trim(),
      queryType: currentGraphQueryType || '',
      classQuery: '',
    };
  }

  function normalizeGraphRequest(requestLike) {
    if (requestLike && typeof requestLike === 'object' && !Array.isArray(requestLike)) {
      const queryType = normalizeQueryType(requestLike.type || requestLike.queryType);
      const classQuery = String(requestLike.classQuery || requestLike.class || '').trim();
      const query = String(requestLike.query || requestLike.q || classQuery || '').trim();
      if (queryType === 'disease_class') {
        const normalizedClassQuery = classQuery || query;
        return {
          query: normalizedClassQuery,
          queryType,
          classQuery: normalizedClassQuery,
        };
      }
      return {
        query,
        queryType,
        classQuery: '',
      };
    }

    if (typeof requestLike === 'string') {
      return {
        query: String(requestLike || '').trim(),
        queryType: selectedGraphSearchType(),
        classQuery: '',
      };
    }

    return buildCurrentGraphRequest();
  }

  function applyPageMode() {
    document.body.classList.add('tekg-g6-preview-ready');
  }

  function updateButtons() {
    const t = textSet();
    if (els.title) els.title.textContent = t.pageTitle;
    if (els.badge) els.badge.textContent = t.badge;
    if (els.graphTitle) els.graphTitle.textContent = t.graphTitle;
    updateGraphSearchPlaceholder();
    if (els.edgeLabelsText) els.edgeLabelsText.textContent = window.showEdgeLabels ? t.showEdgeLabelsOn : t.showEdgeLabelsOff;
    if (els.fixedText) els.fixedText.textContent = window.fixedView ? t.fixedOn : t.fixedOff;
    if (els.backText) els.backText.textContent = t.back || 'Back';
    if (els.resetText) els.resetText.textContent = t.reset;
    if (els.expandModeText) els.expandModeText.textContent = expandModeEnabled ? t.expandModeOn : t.expandModeOff;
    syncToggleButtonState(els.edgeLabelsBtn, window.showEdgeLabels);
    syncToggleButtonState(els.fixedBtn, window.fixedView);
    syncToggleButtonState(els.expandModeBtn, expandModeEnabled);
    updateTaxonomyButtons();
    const classificationMode = currentMode === 'tree' || currentMode === 'taxonomy_graph'
      || currentMode === 'disease_class_tree' || currentMode === 'disease_class_graph';
    if (els.edgeLabelsBtn) els.edgeLabelsBtn.hidden = classificationMode;
    if (els.fixedBtn) els.fixedBtn.hidden = classificationMode;
    if (els.expandModeBtn) els.expandModeBtn.hidden = classificationMode;
    const canExportClassification = classificationMode && !graphIsLoading;
    const canExportGraph = canExportClassification || (currentMode === 'dynamic' && !graphIsLoading && !!dynamicFrame);
    if (els.exportMenuToggle) els.exportMenuToggle.disabled = !canExportGraph;
    if (els.exportMenuCsv) els.exportMenuCsv.disabled = !canExportGraph;
    if (els.exportMenuPng) els.exportMenuPng.disabled = !canExportGraph;
    if (els.exportMenuSvg) {
      els.exportMenuSvg.disabled = !canExportGraph;
      els.exportMenuSvg.setAttribute('aria-disabled', canExportGraph ? 'false' : 'true');
    }
    if (els.graphLegendApply) {
      els.graphLegendApply.disabled = graphIsLoading || !legendFilterPending;
    }
    if (els.relationMinPmidsInput) els.relationMinPmidsInput.disabled = graphIsLoading;
    if (els.graphLegendList) {
      els.graphLegendList.querySelectorAll('input, button').forEach((control) => {
        control.disabled = graphIsLoading;
      });
    }
    if (!canExportGraph) closeExportMenu();
    updateBackButton();
  }

  function showTreeSurface() {
    if (els.treeSurface) els.treeSurface.style.display = 'block';
    if (els.taxonomyCanvasSurface) els.taxonomyCanvasSurface.hidden = true;
    if (els.dynamicSurface) els.dynamicSurface.style.display = 'none';
    taxonomyCanvasRenderer?.pause?.();
    syncLegendVisibility('tree');
  }

  function showTaxonomyCanvasSurface(mode = 'taxonomy_graph') {
    if (els.treeSurface) els.treeSurface.style.display = 'none';
    if (els.taxonomyCanvasSurface) els.taxonomyCanvasSurface.hidden = false;
    if (els.dynamicSurface) els.dynamicSurface.style.display = 'none';
    taxonomyCanvasRenderer?.resume?.();
    taxonomyCanvasRenderer?.resize?.();
    syncLegendVisibility(mode);
  }

  function showDynamicSurface() {
    if (els.treeSurface) els.treeSurface.style.display = 'none';
    if (els.taxonomyCanvasSurface) els.taxonomyCanvasSurface.hidden = true;
    if (els.dynamicSurface) els.dynamicSurface.style.display = 'block';
    taxonomyCanvasRenderer?.pause?.();
    syncLegendVisibility('dynamic');
  }

  function waitForDynamicSurfaceSize(maxAttempts = 60, delayMs = 50) {
    return new Promise((resolve, reject) => {
      let attempts = 0;
      const check = () => {
        attempts += 1;
        const width = els.dynamicSurface ? (els.dynamicSurface.clientWidth || 0) : 0;
        const height = els.dynamicSurface ? (els.dynamicSurface.clientHeight || 0) : 0;
        if (width > 24 && height > 24) {
          resolve({ width, height });
          return;
        }
        if (attempts >= maxAttempts) {
          reject(new Error('Dynamic graph surface has no size yet.'));
          return;
        }
        window.setTimeout(check, delayMs);
      };
      check();
    });
  }

  async function loadSharedResources() {
    const tasks = [];
    if (typeof loadTerminology === 'function') tasks.push(loadTerminology());
    if (typeof loadTeDescriptions === 'function') tasks.push(loadTeDescriptions());
    if (typeof loadEntityDescriptions === 'function') tasks.push(loadEntityDescriptions());
    if (typeof loadUiText === 'function') tasks.push(loadUiText());
    await Promise.all(tasks);
    try {
      window.dispatchEvent(new CustomEvent('tekg:shared-ready'));
    } catch (_error) {}
  }

  function buildDynamicFrameSrc(requestLike = buildCurrentGraphRequest()) {
    const request = normalizeGraphRequest(requestLike);
    const url = new URL(window.__TEKG_PATHS.assetsUrl('html/preview_g6_embed.html'), window.location.origin);
    const version = String(window.__TEKG_PREVIEW_VERSION || '').trim();
    if (version) url.searchParams.set('v', version);
    url.searchParams.set('key_level', String(window.currentKeyNodeLevel));
    url.searchParams.set('fixed', window.fixedView ? '1' : '0');
    const query = String(request.query || '').trim();
    if (query) {
      url.searchParams.set('q', query);
    } else {
      url.searchParams.delete('q');
    }
    if (request.queryType) url.searchParams.set('type', request.queryType);
    else url.searchParams.delete('type');
    if (request.queryType === 'disease_class' && request.classQuery) url.searchParams.set('class', request.classQuery);
    else url.searchParams.delete('class');
    return url.toString();
  }

  function waitForEmbedBridge(frame, maxAttempts = 60, delayMs = 50) {
    return new Promise((resolve, reject) => {
      let attempts = 0;
      const check = () => {
        attempts += 1;
        try {
          const bridge = frame.contentWindow && frame.contentWindow.__TEKG_G6_EMBED;
          if (bridge && typeof bridge.loadGraph === 'function') {
            resolve(bridge);
            return;
          }
        } catch (_error) {}
        if (attempts >= maxAttempts) {
          reject(new Error('G6 embed bridge is not available'));
          return;
        }
        window.setTimeout(check, delayMs);
      };
      check();
    });
  }

  function getLiveDynamicBridge(frame = dynamicFrame) {
    try {
      const bridge = frame && frame.contentWindow && frame.contentWindow.__TEKG_G6_EMBED;
      return bridge && typeof bridge.loadGraph === 'function' ? bridge : null;
    } catch (_error) {
      return null;
    }
  }

  function ensureDynamicFrame(requestLike = buildCurrentGraphRequest()) {
    const nextSrc = buildDynamicFrameSrc(requestLike);

    if (!dynamicFrame) {
      dynamicFrame = document.createElement('iframe');
      dynamicFrame.id = 'g6-dynamic-frame';
      dynamicFrame.title = 'TEKG G6 dynamic graph';
      dynamicFrame.setAttribute('scrolling', 'no');
      if (els.dynamicSurface) {
        els.dynamicSurface.innerHTML = '';
        els.dynamicSurface.appendChild(dynamicFrame);
      }
    }

    const currentSrc = dynamicFrame.getAttribute('src') || '';
    const liveBridge = getLiveDynamicBridge(dynamicFrame);
    if (liveBridge) {
      if (!dynamicBridgePromise) dynamicBridgePromise = Promise.resolve(liveBridge);
    } else if (currentSrc !== nextSrc) {
      dynamicBridgePromise = null;
      dynamicFrame.src = nextSrc;
    }

    return dynamicFrame;
  }

  function convertGraphActionSubgraphToElements(graphAction) {
    const elements = [];
    const subgraph = graphAction && typeof graphAction === 'object' ? graphAction.subgraph || {} : {};
    const nodes = Array.isArray(subgraph.nodes) ? subgraph.nodes : [];
    const edges = Array.isArray(subgraph.edges) ? subgraph.edges : [];

    for (const node of nodes) {
      if (!node || typeof node !== 'object') continue;
      elements.push({
        data: {
          id: String(node.id || ''),
          label: String(node.label || node.id || ''),
          rawLabel: String(node.label || node.id || ''),
          type: String(node.type || 'TE'),
          description: String(node.description || ''),
          pmid: String(node.pmid || ''),
        },
      });
    }

    for (const edge of edges) {
      if (!edge || typeof edge !== 'object') continue;
      elements.push({
        data: {
          id: String(edge.id || `${edge.source || ''}__${edge.relation || 'relation'}__${edge.target || ''}`),
          source: String(edge.source || ''),
          target: String(edge.target || ''),
          relation: String(edge.relation || ''),
          relationType: String(edge.relation || ''),
          evidence: String(edge.evidence || ''),
          pmids: Array.isArray(edge.pmids) ? edge.pmids : [],
        },
      });
    }

    return elements;
  }

  function extractAnswerGraphElements(result) {
    const graphAction = result && result.graph_action && typeof result.graph_action === 'object'
      ? result.graph_action
      : null;
    const graphActionElements = graphAction ? convertGraphActionSubgraphToElements(graphAction) : [];
    if (graphActionElements.length) return graphActionElements;

    const graphContextElements = result && result.graph_context && Array.isArray(result.graph_context.elements)
      ? result.graph_context.elements
      : [];
    return graphContextElements;
  }

  async function renderAnswerGraphElements(elements, query, options = {}) {
    const pushHistory = options.pushHistory !== false;
    if (pushHistory) pushCurrentStateToHistory();

    currentMode = 'dynamic';
    currentGraphSource = 'answer';
    currentGraphQuery = String(query || currentGraphQuery || '').trim() || 'LINE1';
    currentGraphQueryType = '';
    currentGraphClassQuery = '';
    currentSelectedNode = null;
    activeLegendHighlight = null;
    currentAnswerGraphElements = cloneAnswerElements(elements);
    currentQueryGraphElements = [];

    showDynamicSurface();
    updateButtons();
    setDetail('');
    notifyStateChange();
    setGraphLoading(true, textSet().loadingOverlay(currentGraphQuery), {
      label: currentGraphQuery,
    });

    try {
      await waitForDynamicSurfaceSize();

      const frame = dynamicFrame || ensureDynamicFrame({ query: '' });
      if (!dynamicBridgePromise) {
        dynamicBridgePromise = waitForEmbedBridge(frame);
      }

      const bridge = await dynamicBridgePromise;
      if (!bridge || typeof bridge.renderElements !== 'function') {
        throw new Error('G6 embed bridge cannot render QA elements');
      }
      if (typeof bridge.setLegendFocus === 'function') {
        await bridge.setLegendFocus(null);
      }

      const rendered = await bridge.renderElements(filterElementsForLegend(currentAnswerGraphElements), { query: currentGraphQuery }, {
        sourceLabel: 'qa',
        graphDataOptions: buildCurrentGraphDataOptions({
          includePaperNodes: true,
          restrictToAnchorComponent: false,
          forceAnchorLabel: true,
        }),
      });
      currentRelationLegendMeta = mergeRelationLegendMeta(
        currentRelationLegendMeta,
        Array.isArray(rendered && rendered.relationLegendMeta) ? rendered.relationLegendMeta : []
      );
      ensureRelationLegendState();
      clearLegendFilterPending();
      renderGraphLegend();
      notifyStateChange();
      return true;
    } finally {
      setGraphLoading(false);
    }
  }

  async function fetchDiseaseClassPayload(requestLike) {
    const request = normalizeGraphRequest(requestLike);
    const classQuery = String(request.classQuery || request.query || '').trim();
    if (!classQuery) {
      throw new Error('Disease class query is required.');
    }

    const endpoint = new URL(window.__TEKG_PATHS.apiUrl('graph.php'), window.location.origin);
    endpoint.searchParams.set('q', classQuery);
    endpoint.searchParams.set('type', 'disease_class');
    endpoint.searchParams.set('class', classQuery);
    endpoint.searchParams.set('key_level', String(window.currentKeyNodeLevel));

    const response = await fetch(endpoint.toString(), {
      credentials: 'same-origin',
    });
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    return response.json();
  }

  function buildDiseaseClassTreeModel(elements, classQuery) {
    const nodes = new Map();
    const children = new Map();
    const incoming = new Set();
    const typeRank = {
      DiseaseClass: 0,
      DiseaseCategory: 1,
      Disease: 2,
    };

    for (const item of Array.isArray(elements) ? elements : []) {
      const data = item && item.data ? item.data : null;
      if (!data) continue;
      if (data.source && data.target) {
        if (!children.has(data.source)) children.set(data.source, []);
        children.get(data.source).push(data.target);
        incoming.add(data.target);
        continue;
      }

      const nodeType = String(data.type || '');
      nodes.set(data.id, {
        id: data.id,
        rawLabel: String(data.rawLabel || data.label || data.id || ''),
        description: String(data.description || ''),
        nodeType,
        categoryLevel: Number(data.category_level || 0),
        diseaseClass: String(data.disease_class || classQuery || ''),
        queryLabel: nodeType === 'Disease' ? String(data.rawLabel || data.label || data.id || '') : '',
      });
    }

    let rootId = '';
    for (const node of nodes.values()) {
      if (node.nodeType === 'DiseaseClass') {
        rootId = node.id;
        break;
      }
    }
    if (!rootId) {
      for (const node of nodes.values()) {
        if (!incoming.has(node.id)) {
          rootId = node.id;
          break;
        }
      }
    }
    if (!rootId || !nodes.has(rootId)) {
      return { rootId: '', treeData: null };
    }

    function makeDiseaseTreeNodeId(sourceId, pathIds = []) {
      const safeSource = String(sourceId || '').replace(/[^A-Za-z0-9:_-]+/g, '_');
      const safePath = pathIds.map((item) => String(item || '').replace(/[^A-Za-z0-9:_-]+/g, '_')).join('__');
      return `disease-tree::${safePath || 'root'}::${safeSource || 'node'}`;
    }

    const visit = (nodeId, depth, path, treePath = []) => {
      if (!nodeId || path.has(nodeId)) return null;
      const node = nodes.get(nodeId);
      if (!node) return null;

      const nextPath = new Set(path);
      nextPath.add(nodeId);
      const nextTreePath = [...treePath, nodeId];
      const treeNodeId = makeDiseaseTreeNodeId(nodeId, treePath);

      const sortedChildIds = [...new Set(children.get(nodeId) || [])]
        .filter((childId) => nodes.has(childId))
        .sort((leftId, rightId) => {
          const left = nodes.get(leftId);
          const right = nodes.get(rightId);
          const leftRank = typeRank[left?.nodeType] ?? 99;
          const rightRank = typeRank[right?.nodeType] ?? 99;
          if (leftRank !== rightRank) return leftRank - rightRank;
          const leftLevel = Number(left?.categoryLevel || 0);
          const rightLevel = Number(right?.categoryLevel || 0);
          if (leftLevel !== rightLevel) return leftLevel - rightLevel;
          return String(left?.rawLabel || '').localeCompare(String(right?.rawLabel || ''));
        });

      const childNodes = sortedChildIds
        .map((childId) => visit(childId, depth + 1, nextPath, nextTreePath))
        .filter(Boolean);

      return {
        id: treeNodeId,
        data: {
          sourceId: node.id,
          rawLabel: node.rawLabel,
          displayLabel: node.rawLabel,
          description: node.description,
          treeDepth: depth,
          treePath: nextTreePath,
          queryLabel: node.queryLabel,
          nodeType: node.nodeType,
          diseaseClass: node.diseaseClass,
          treeKind: 'disease_class',
        },
        style: {
          collapsed: false,
        },
        children: childNodes,
      };
    };

    return {
      rootId: makeDiseaseTreeNodeId(rootId, []),
      treeData: visit(rootId, 0, new Set(), []),
    };
  }

  function buildDiseaseClassTreeConfigLegacy(classQuery) {
    const typeLabels = {
      DiseaseClass: 'Disease Class',
      DiseaseCategory: 'Disease Category',
      Disease: 'Disease',
    };

    return {
      defaultDetailHtml: `<strong>${escapeHtml(classQuery)}</strong><br>This disease-class tree is active. Click a disease leaf to open the dynamic graph.`,
      buildLabel(data, nodeId) {
        return truncateDiseaseTreeLabel(data.displayLabel || data.rawLabel || nodeId || '', data);
      },
      buildLabelFill(data) {
        return data.nodeType === 'Disease' ? '#c62828' : '';
      },
      buildDetailHtml(nodeData) {
        const data = nodeData?.data || {};
        const label = String(data.displayLabel || data.rawLabel || nodeData?.id || '');
        const typeLabel = typeLabels[data.nodeType] || data.nodeType || '';
        const description = String(data.description || '').trim();
        return [
          `<strong>${escapeHtml(label)}</strong>${typeLabel ? ` (${escapeHtml(typeLabel)})` : ''}`,
          description ? `<br>${escapeHtml(description)}` : '',
        ].join('');
      },
      async onNodeClick(nodeData, context) {
        const data = nodeData?.data || {};
        if (data.nodeType !== 'Disease') return false;
        const { fixedModeEnabled, homePreviewMode, loadDynamicGraph } = context || {};
        if (fixedModeEnabled || homePreviewMode || typeof loadDynamicGraph !== 'function') {
          return false;
        }
        const query = String(data.queryLabel || data.rawLabel || nodeData?.id || '').trim();
        if (!query) return false;
        await loadDynamicGraph({ query, queryType: 'Disease' });
        return true;
      },
    };
  }

  function buildDiseaseClassTreeConfig(classQuery) {
    const truncateDiseaseTreeLabel = (label, data) => {
      const text = String(label || '').trim();
      if (!text) return text;
      const depth = Number(data?.treeDepth || 0);
      const isDisease = String(data?.nodeType || '') === 'Disease';
      const limitsByDepth = { 0: 12, 1: 17, 2: 20, 3: 22, 4: 24 };
      const limit = isDisease ? 24 : (limitsByDepth[depth] || 24);
      if (text.length <= limit) return text;
      return `${text.slice(0, Math.max(1, limit - 1)).trimEnd()}…`;
    };
    const typeLabels = {
      DiseaseClass: 'Disease Class',
      DiseaseCategory: 'Disease Category',
      Disease: 'Disease',
    };

    return {
      defaultDetailHtml: `<strong>${escapeHtml(classQuery)}</strong><br>This disease-class tree is active. Click a disease leaf to open the dynamic graph.`,
      buildLabel(data, nodeId) {
        return truncateDiseaseTreeLabel(data.displayLabel || data.rawLabel || nodeId || '', data);
      },
      buildLabelFill(data) {
        return data.nodeType === 'Disease' ? '#c62828' : '';
      },
      buildLabelFontWeight(data) {
        return data.nodeType === 'Disease' ? 'bold' : 'normal';
      },
      expandAll: true,
      compactLayout: true,
      horizontalGap: 36,
      maxNodeWidth: 256,
      buildDetailHtml(nodeData) {
        const data = nodeData?.data || {};
        const label = String(data.displayLabel || data.rawLabel || nodeData?.id || '');
        const typeLabel = typeLabels[data.nodeType] || data.nodeType || '';
        const description = String(data.description || '').trim();
        return [
          `<strong>${escapeHtml(label)}</strong>${typeLabel ? ` (${escapeHtml(typeLabel)})` : ''}`,
          description ? `<br>${escapeHtml(description)}` : '',
        ].join('');
      },
      async onNodeClick(nodeData, context) {
        const data = nodeData?.data || {};
        if (data.nodeType !== 'Disease') return false;
        const { fixedModeEnabled, homePreviewMode, loadDynamicGraph } = context || {};
        if (fixedModeEnabled || homePreviewMode || typeof loadDynamicGraph !== 'function') {
          return false;
        }
        const query = String(data.queryLabel || data.rawLabel || nodeData?.id || '').trim();
        if (!query) return false;
        await loadDynamicGraph({ query, queryType: 'Disease' });
        return true;
      },
    };
  }

  async function applyAnswerGraph(result, options = {}) {
    const graphAction = result && result.graph_action && typeof result.graph_action === 'object'
      ? result.graph_action
      : null;
    if (!graphAction || graphAction.enabled !== true) return false;

    const elements = extractAnswerGraphElements(result);
    if (!elements.length) return false;

    const preset = graphAction.preset_state && typeof graphAction.preset_state === 'object'
      ? graphAction.preset_state
      : {};
    const query = String(graphAction.query || graphAction.anchor?.name || currentGraphQuery || '').trim() || 'LINE1';

    if (options.pushHistory !== false) {
      pushCurrentStateToHistory();
    }
    window.fixedView = preset.fixed_view !== false;
    updateButtons();
    return renderAnswerGraphElements(elements, query, { ...options, pushHistory: false });
  }

  async function restoreGraphState(state) {
    if (!state || typeof state !== 'object') return false;

    if (state.kind === 'tree') {
      return navigateTaxonomyRoute({
        treeVariant: state.treeVariant || currentTreeVariant,
        taxonomyDisplayMode: 'tree',
      }, { pushHistory: false });
    }

    if (state.kind === 'taxonomy_graph') {
      return navigateTaxonomyRoute({
        treeVariant: state.treeVariant || currentTreeVariant,
        taxonomyDisplayMode: 'graph',
      }, { pushHistory: false });
    }

    if (state.kind === 'disease_class_tree' || state.kind === 'disease_class_graph') {
      if (Array.isArray(state.elements) && state.elements.length) {
        return renderDiseaseClassView(
          state.elements,
          state.classQuery || state.query,
          state.kind === 'disease_class_graph' ? 'graph' : 'tree',
        );
      }
      return navigateDiseaseClass({
        query: state.classQuery || state.query,
        queryType: 'disease_class',
        classQuery: state.classQuery || state.query,
      }, { pushHistory: false, taxonomyDisplayMode: state.kind === 'disease_class_graph' ? 'graph' : 'tree' });
    }

    if (state.kind === 'query') {
      pendingFilterBaseline = null;
      legendFilterPending = false;
      window.fixedView = !!state.fixedView;
      expandModeEnabled = !!state.expandModeEnabled;
      relationMinPmids = Math.max(0, Number(state.relationMinPmids) || 0);
      if (els.relationMinPmidsInput) els.relationMinPmidsInput.value = String(relationMinPmids);
      updateButtons();
      if (Array.isArray(state.elements) && state.elements.length > 0) {
        const restoreEpoch = ++dynamicNavigationEpoch;
        currentMode = 'dynamic';
        currentGraphSource = 'query';
        currentGraphQuery = String(state.query || '').trim();
        currentGraphQueryType = normalizeQueryType(state.queryType);
        currentGraphClassQuery = currentGraphQueryType === 'disease_class'
          ? String(state.classQuery || state.query || '').trim()
          : '';
        currentSelectedNode = null;
        currentAnswerGraphElements = [];
        currentQueryGraphElements = cloneAnswerElements(state.elements);
        currentRelationLegendMeta = dataClone(state.relationLegendMeta || []);
        rawRelationLegendMeta = dataClone(state.rawRelationLegendMeta || []);
        visibleTypeState = { ...(state.visibleTypeState || {}) };
        relationLegendState = { ...(state.relationLegendState || {}) };
        expandedNodeKeys = new Set(Array.isArray(state.expandedNodeKeys) ? state.expandedNodeKeys : []);
        if (els.searchInput) els.searchInput.value = currentGraphQuery;
        pendingGraphRequest = { ...buildCurrentGraphRequest(), source: 'query', epoch: restoreEpoch };
        try {
          return await renderDynamicElementsFromCache(currentQueryGraphElements, {
            source: 'query',
            request: buildCurrentGraphRequest(),
          });
        } finally {
          if (pendingGraphRequest?.epoch === restoreEpoch) pendingGraphRequest = null;
        }
      }
      return loadDynamicGraph({
        query: state.query,
        queryType: state.queryType,
        classQuery: state.classQuery,
      }, { pushHistory: false });
    }

    if (state.kind === 'answer') {
      window.fixedView = !!state.fixedView;
      expandModeEnabled = !!state.expandModeEnabled;
      relationMinPmids = Math.max(0, Number(state.relationMinPmids) || 0);
      if (els.relationMinPmidsInput) els.relationMinPmidsInput.value = String(relationMinPmids);
      updateButtons();
      return renderAnswerGraphElements(state.elements || [], state.query || 'LINE1', { pushHistory: false });
    }

    return false;
  }

  async function goBackGraph() {
    if (!graphHistory.length) return false;
    const previousState = graphHistory.pop();
    updateButtons();
    const restored = await restoreGraphState(previousState);
    if (restored) notifyNavigation(
      previousState.kind === 'disease_class_tree' || previousState.kind === 'disease_class_graph'
        ? 'replace'
        : 'push',
    );
    return restored;
  }

  async function renderCurrentTaxonomyView(options = {}) {
    return currentTaxonomyDisplayMode === 'graph'
      ? renderTaxonomyGraph(options)
      : renderDefaultTree(options);
  }

  function normalizeTaxonomyRoute(route = {}) {
    return {
      treeVariant: normalizeTreeVariantKey(route.treeVariant || desiredTaxonomyRoute.treeVariant),
      taxonomyDisplayMode: route.taxonomyDisplayMode === 'graph' ? 'graph' : (
        route.taxonomyDisplayMode === 'tree'
          ? 'tree'
          : desiredTaxonomyRoute.taxonomyDisplayMode
      ),
    };
  }

  function renderTaxonomyRoute(route, options = {}) {
    const renderOptions = {
      ...options,
      variant: route.treeVariant,
      deferStateChange: true,
    };
    return route.taxonomyDisplayMode === 'graph'
      ? renderTaxonomyGraph(renderOptions)
      : renderDefaultTree(renderOptions);
  }

  function navigateTaxonomyRoute(route = {}, options = {}) {
    const target = normalizeTaxonomyRoute(route);
    desiredTaxonomyRoute = { ...target };
    const navigationEpoch = ++taxonomyNavigationEpoch;

    const transition = taxonomyTransitionQueue.then(async () => {
      if (navigationEpoch !== taxonomyNavigationEpoch) return false;
      const previous = { ...stableTaxonomyRoute };

      try {
        await renderTaxonomyRoute(target, options);
      } catch (error) {
        if (navigationEpoch !== taxonomyNavigationEpoch) return false;
        desiredTaxonomyRoute = { ...previous };
        try {
          await renderTaxonomyRoute(previous, { pushHistory: false });
        } catch (rollbackError) {
          console.error('Failed to restore the previous taxonomy view.', rollbackError);
        }
        stableTaxonomyRoute = { ...previous };
        notifyStateChange();
        throw error;
      }

      if (navigationEpoch !== taxonomyNavigationEpoch) return false;
      stableTaxonomyRoute = { ...target };
      notifyStateChange();
      return target.taxonomyDisplayMode;
    });

    taxonomyTransitionQueue = transition.catch(() => false);
    return transition;
  }

  async function renderDefaultTree(options = {}) {
    const requestedVariant = normalizeTreeVariantKey(options && options.variant ? options.variant : currentTreeVariant);
    currentTreeVariant = requestedVariant;
    currentTaxonomyDisplayMode = 'tree';
    window.__TEKG_TREE_VARIANT = currentTreeVariant;
    if (options.pushHistory === true && currentMode !== 'tree') {
      pushCurrentStateToHistory();
    }
    currentMode = 'tree';
    currentGraphSource = 'tree';
    currentGraphQuery = '';
    currentGraphQueryType = '';
    currentGraphClassQuery = '';
    currentSelectedNode = null;
    currentAnswerGraphElements = [];
    currentQueryGraphElements = [];
    showTreeSurface();
    updateButtons();
    setGraphLoading(true, textSet().loadingOverlay('tree'));

    try {
      if (window.__TEKG_G6_DEFAULT_TREE && typeof window.__TEKG_G6_DEFAULT_TREE.render === 'function') {
        await window.__TEKG_G6_DEFAULT_TREE.render();
      }
    } finally {
      setGraphLoading(false);
    }

    setDetail(buildTreeVariantDetailHtml());
    if (options.deferStateChange !== true) notifyStateChange();
    return true;
  }

  async function renderTaxonomyGraph(options = {}) {
    const requestedVariant = normalizeTreeVariantKey(options && options.variant ? options.variant : currentTreeVariant);
    currentTreeVariant = requestedVariant;
    currentTaxonomyDisplayMode = 'graph';
    window.__TEKG_TREE_VARIANT = currentTreeVariant;
    if (options.pushHistory === true && currentMode !== 'taxonomy_graph') pushCurrentStateToHistory();
    currentMode = 'taxonomy_graph';
    currentGraphSource = 'tree';
    currentGraphQuery = '';
    currentGraphQueryType = '';
    currentGraphClassQuery = '';
    currentSelectedNode = null;
    activeLegendHighlight = null;
    taxonomyLegendState = {};
    showTaxonomyCanvasSurface();
    updateButtons();
    setGraphLoading(true, textSet().loadingOverlay('taxonomy graph'));
    try {
      if (!taxonomyCanvasRenderer || typeof taxonomyCanvasRenderer.render !== 'function') {
        throw new Error('Canvas taxonomy renderer is unavailable');
      }
      await taxonomyCanvasRenderer.render({ source: currentTreeVariant });
      ensureTaxonomyLegendState();
      await taxonomyCanvasRenderer.applyLevelState(taxonomyLegendState);
      clearLegendFilterPending();
      renderGraphLegend();
    } finally {
      setGraphLoading(false);
    }
    setDetail(buildTreeVariantDetailHtml());
    if (options.deferStateChange !== true) notifyStateChange();
    return true;
  }

  function setTaxonomyDisplayMode(mode) {
    const nextMode = mode === 'graph' ? 'graph' : 'tree';
    if (currentMode === 'disease_class_tree' || currentMode === 'disease_class_graph') {
      if ((nextMode === 'tree' && currentMode === 'disease_class_tree')
        || (nextMode === 'graph' && currentMode === 'disease_class_graph')) return Promise.resolve(nextMode);
      return navigateDiseaseClass({
        query: currentGraphClassQuery || currentGraphQuery,
        queryType: 'disease_class',
        classQuery: currentGraphClassQuery || currentGraphQuery,
      }, { pushHistory: false, taxonomyDisplayMode: nextMode });
    }
    if (nextMode === desiredTaxonomyRoute.taxonomyDisplayMode && (
      (nextMode === 'tree' && currentMode === 'tree')
      || (nextMode === 'graph' && currentMode === 'taxonomy_graph')
    )) return Promise.resolve(nextMode);
    return navigateTaxonomyRoute({ taxonomyDisplayMode: nextMode }, { pushHistory: false });
  }

  async function renderDiseaseClassView(elements, classQuery, displayMode) {
    const graphMode = displayMode === 'graph';
    const nextMode = graphMode ? 'disease_class_graph' : 'disease_class_tree';
    if (graphMode) {
      if (!taxonomyCanvasRenderer || typeof taxonomyCanvasRenderer.renderModel !== 'function') {
        throw new Error('Disease classification Canvas renderer is unavailable.');
      }
      if (!diseaseClassificationAdapter || typeof diseaseClassificationAdapter.build !== 'function') {
        throw new Error('Disease classification adapter is unavailable.');
      }
      showTaxonomyCanvasSurface(nextMode);
      const model = diseaseClassificationAdapter.build(elements, classQuery, async (node) => {
        if (String(node?.nodeType || '') !== 'Disease') return false;
        const query = String(node?.queryLabel || node?.label || '').trim();
        if (!query) return false;
        return navigateGraph({ query, queryType: 'Disease' }, { history: 'push' });
      });
      await taxonomyCanvasRenderer.renderModel(model);
      taxonomyLegendState = Object.fromEntries(
        (taxonomyCanvasRenderer.getLegendMeta?.() || []).map((item) => [item.key, true]),
      );
      ensureTaxonomyLegendState();
      await taxonomyCanvasRenderer.applyLevelState(taxonomyLegendState);
      clearLegendFilterPending();
      renderGraphLegend();
    } else {
      const model = buildDiseaseClassTreeModel(elements, classQuery);
      if (!model.rootId || !model.treeData) throw new Error('Disease class tree data is unavailable.');
      if (!window.__TEKG_G6_DEFAULT_TREE || typeof window.__TEKG_G6_DEFAULT_TREE.renderStructuredTree !== 'function') {
        throw new Error('Structured tree renderer is unavailable.');
      }
      showTreeSurface();
      await window.__TEKG_G6_DEFAULT_TREE.renderStructuredTree({
        rootId: model.rootId,
        treeData: model.treeData,
        expandAll: true,
        config: buildDiseaseClassTreeConfig(classQuery),
      });
    }

    currentMode = nextMode;
    currentTaxonomyDisplayMode = graphMode ? 'graph' : 'tree';
    currentGraphSource = nextMode;
    currentGraphQuery = classQuery;
    currentGraphQueryType = 'disease_class';
    currentGraphClassQuery = classQuery;
    currentSelectedNode = null;
    currentAnswerGraphElements = [];
    currentQueryGraphElements = cloneAnswerElements(elements);
    if (els.searchInput) els.searchInput.value = classQuery;
    setDetail(`<strong>${escapeHtml(classQuery)}</strong><br>Disease classification ${graphMode ? 'graph' : 'tree'}.`);
    updateButtons();
    return true;
  }

  function navigateDiseaseClass(requestLike, options = {}) {
    const request = normalizeGraphRequest({
      ...(requestLike && typeof requestLike === 'object' ? requestLike : {}),
      queryType: 'disease_class',
    });
    const classQuery = String(request.classQuery || request.query || '').trim();
    if (!classQuery) return navigateTaxonomyRoute({ taxonomyDisplayMode: 'tree' }, options);
    const displayMode = options.taxonomyDisplayMode === 'graph' ? 'graph' : 'tree';
    const epoch = ++diseaseNavigationEpoch;
    const transition = diseaseTransitionQueue.then(async () => {
      if (epoch !== diseaseNavigationEpoch) return false;
      const previous = captureCurrentGraphState();
      const reusable = (currentMode === 'disease_class_tree' || currentMode === 'disease_class_graph')
        && String(currentGraphClassQuery || '').toLowerCase() === classQuery.toLowerCase()
        && currentQueryGraphElements.length > 0;
      setGraphLoading(true, textSet().loadingOverlay(classQuery));
      try {
        const elements = reusable
          ? cloneAnswerElements(currentQueryGraphElements)
          : cloneAnswerElements((await fetchDiseaseClassPayload(request))?.elements || []);
        if (epoch !== diseaseNavigationEpoch) return false;
        if (!elements.length) throw new Error('Disease class data is unavailable.');
        await renderDiseaseClassView(elements, classQuery, displayMode);
        if (epoch !== diseaseNavigationEpoch) return false;
        if (options.pushHistory !== false && stateSignature(previous) !== stateSignature(captureCurrentGraphState())) {
          pushGraphStateToHistory(previous);
        }
        notifyStateChange();
        if (options.history && options.history !== 'none') {
          notifyNavigation(options.history === 'replace' ? 'replace' : 'push');
        }
        return displayMode;
      } catch (error) {
        if (epoch === diseaseNavigationEpoch && previous) {
          try {
            if ((previous.kind === 'disease_class_tree' || previous.kind === 'disease_class_graph')
              && Array.isArray(previous.elements) && previous.elements.length) {
              await renderDiseaseClassView(
                previous.elements,
                previous.classQuery || previous.query,
                previous.kind === 'disease_class_graph' ? 'graph' : 'tree',
              );
            } else await restoreStableSurface(previous);
          } catch (_rollbackError) {}
        }
        throw error;
      } finally {
        setGraphLoading(false);
      }
    });
    diseaseTransitionQueue = transition.catch(() => false);
    return transition;
  }

  function renderDiseaseClassTree(requestLike, options = {}) {
    return navigateDiseaseClass(requestLike, { ...options, taxonomyDisplayMode: 'tree' });
  }

  async function navigateGraph(requestLike, options = {}) {
    const loaded = await loadDynamicGraph(requestLike, {
      ...options,
      pushHistory: options.pushHistory !== false,
    });
    if (loaded && options.history !== 'none') {
      notifyNavigation(options.history === 'replace' ? 'replace' : 'push');
    }
    return loaded;
  }

  function restoreTaxonomyRoute(route = {}) {
    return navigateTaxonomyRoute({
      treeVariant: route.treeVariant || 'rmsk_repbase',
      taxonomyDisplayMode: route.taxonomyDisplayMode === 'graph' ? 'graph' : 'tree',
    }, { pushHistory: false });
  }

  async function restoreStableSurface(state) {
    if (!state || typeof state !== 'object') return false;
    if (state.kind === 'tree') {
      return navigateTaxonomyRoute({
        treeVariant: state.treeVariant || currentTreeVariant,
        taxonomyDisplayMode: 'tree',
      }, { pushHistory: false });
    }
    if (state.kind === 'taxonomy_graph') {
      return navigateTaxonomyRoute({
        treeVariant: state.treeVariant || currentTreeVariant,
        taxonomyDisplayMode: 'graph',
      }, { pushHistory: false });
    }
    if (state.kind === 'disease_class_tree' || state.kind === 'disease_class_graph') {
      if (Array.isArray(state.elements) && state.elements.length) {
        await renderDiseaseClassView(
          state.elements,
          state.classQuery || state.query,
          state.kind === 'disease_class_graph' ? 'graph' : 'tree',
        );
      } else {
        await navigateDiseaseClass({
          query: state.classQuery || state.query,
          queryType: 'disease_class',
          classQuery: state.classQuery || state.query,
        }, { pushHistory: false, taxonomyDisplayMode: state.kind === 'disease_class_graph' ? 'graph' : 'tree' });
      }
      return true;
    }
    if (state.kind === 'answer') {
      pendingFilterBaseline = null;
      legendFilterPending = false;
      await renderAnswerGraphElements(state.elements || [], state.query || 'LINE1', { pushHistory: false });
      return true;
    }
    if (state.kind === 'query' && Array.isArray(state.elements) && state.elements.length > 0) {
      showDynamicSurface();
      await renderDynamicElementsFromCache(state.elements, {
        source: 'query',
        request: {
          query: state.query,
          queryType: state.queryType,
          classQuery: state.classQuery,
        },
      });
      return true;
    }
    return false;
  }

  async function loadDynamicGraph(requestLike, options = {}) {
    const request = normalizeGraphRequest(requestLike);
    const q = String(request.query || '').trim();
    if (!q) {
      await navigateTaxonomyRoute({ taxonomyDisplayMode: 'tree' }, options);
      return null;
    }

    const navigationEpoch = ++dynamicNavigationEpoch;
    const inheritedPending = pendingGraphRequest;
    const previousState = inheritedPending?.previousState || captureCurrentGraphState();
    const previousRuntime = inheritedPending?.previousRuntime || {
      mode: currentMode,
      source: currentGraphSource,
      query: currentGraphQuery,
      queryType: currentGraphQueryType,
      classQuery: currentGraphClassQuery,
      selectedNode: currentSelectedNode,
      answerElements: cloneAnswerElements(currentAnswerGraphElements),
      queryElements: cloneAnswerElements(currentQueryGraphElements),
      relationLegendMeta: dataClone(currentRelationLegendMeta),
      rawRelationLegendMeta: dataClone(rawRelationLegendMeta),
      visibleTypeState: getAppliedVisibleTypePayload(),
      relationLegendState: getAppliedVisibleRelationPayload(),
      relationMinPmids: getAppliedMinPmids(),
      expandedNodeKeys: new Set(expandedNodeKeys),
    };
    discardLegendFilterDraft();
    pendingGraphRequest = {
      ...request,
      source: 'query',
      epoch: navigationEpoch,
      previousState,
      previousRuntime,
    };

    currentMode = 'dynamic';
    currentGraphSource = 'query';
    currentGraphQuery = q;
    currentGraphQueryType = request.queryType || '';
    currentGraphClassQuery = currentGraphQueryType === 'disease_class' ? String(request.classQuery || q).trim() : '';
    syncGraphSearchType(currentGraphQueryType);
    currentSelectedNode = null;
    activeLegendHighlight = null;
    showDynamicSurface();
    if (els.searchInput) els.searchInput.value = q;
    updateButtons();
    setDetail(textSet().loadingDetail(q));
    notifyStateChange();
    const loaderKind = currentGraphQueryType === 'TE' && teLoader?.resolveKind
      ? await teLoader.resolveKind(q)
      : getTeLoaderKind(q);
    const loaderLabel = loaderKind === 'default'
      ? textSet().loadingOverlay(q)
      : `Loading ${escapeHtml(q)} network`;
    setGraphLoading(true, loaderLabel, {
      kind: loaderKind,
      label: q,
      phase: 'request',
      phaseText: 'Requesting graph data...',
    });

    try {
      await waitForDynamicSurfaceSize();
      const frame = ensureDynamicFrame(request);
      if (!dynamicBridgePromise) {
        dynamicBridgePromise = waitForEmbedBridge(frame);
      }

      const bridge = await dynamicBridgePromise;
      if (!bridge || typeof bridge.loadGraph !== 'function') {
        throw new Error('G6 embed bridge cannot load graph requests');
      }
      setGraphLoadingPhase('prepare', 'Preparing and transforming graph data...');
      if (typeof bridge.setLegendFocus === 'function') {
        await bridge.setLegendFocus(null);
      }

      setGraphLoadingPhase('render', 'Rendering graph and running force layout...');
      const payload = await bridge.loadGraph(request, {
        graphDataOptions: buildCurrentGraphDataOptions(),
      });
      if (navigationEpoch !== dynamicNavigationEpoch || payload?.superseded === true) {
        return false;
      }
      currentAnswerGraphElements = [];
      currentQueryGraphElements = cloneAnswerElements(Array.isArray(payload && payload.elements) ? payload.elements : []);
      rawRelationLegendMeta = collectRelationLegendMetaFromElements(currentQueryGraphElements);
      currentRelationLegendMeta = mergeRelationLegendMeta([], Array.isArray(payload && payload.relationLegendMeta) ? payload.relationLegendMeta : []);
      relationLegendState = {};
      expandedNodeKeys = new Set();
      ensureRelationLegendState();
      if (options.routeFilters && options.routeFilters.resetDefaults === true) {
        applyRouteFilterState(options.routeFilters);
      } else {
        visibleTypeState = { ...getAppliedVisibleTypePayload() };
        ensureVisibleTypeState();
      }
      renderGraphLegend();
      if (
        Object.values(getAppliedVisibleTypePayload()).some((isVisible) => isVisible === false) ||
        Object.values(getAppliedVisibleRelationPayload()).some((isVisible) => isVisible === false) ||
        getAppliedMinPmids() > 0
      ) {
        await renderDynamicElementsFromCache(currentQueryGraphElements, {
          source: 'query',
          request,
        });
      } else {
        notifyStateChange();
      }
      if (options.pushHistory !== false) {
        pushGraphStateToHistory(previousState);
      }
      if (options.resetHistory === true) graphHistory.length = 0;
      updateBackButton();
      return true;
    } catch (error) {
      if (navigationEpoch === dynamicNavigationEpoch) {
        currentMode = previousRuntime.mode;
        currentGraphSource = previousRuntime.source;
        currentGraphQuery = previousRuntime.query;
        currentGraphQueryType = previousRuntime.queryType;
        currentGraphClassQuery = previousRuntime.classQuery;
        currentSelectedNode = previousRuntime.selectedNode;
        currentAnswerGraphElements = previousRuntime.answerElements;
        currentQueryGraphElements = previousRuntime.queryElements;
        currentRelationLegendMeta = previousRuntime.relationLegendMeta;
        rawRelationLegendMeta = previousRuntime.rawRelationLegendMeta;
        visibleTypeState = previousRuntime.visibleTypeState;
        relationLegendState = previousRuntime.relationLegendState;
        relationMinPmids = previousRuntime.relationMinPmids;
        pendingFilterBaseline = null;
        legendFilterPending = false;
        expandedNodeKeys = previousRuntime.expandedNodeKeys;
        if (els.relationMinPmidsInput) els.relationMinPmidsInput.value = String(relationMinPmids);
        if (els.searchInput) els.searchInput.value = previousRuntime.query;
        syncGraphSearchType(previousRuntime.queryType);
        updateButtons();
        renderGraphLegend();
        try {
          await restoreStableSurface(previousState);
        } catch (restoreError) {
          console.error('Failed to restore the last stable Graph surface.', restoreError);
        }
        notifyStateChange();
      }
      throw error;
    } finally {
      if (navigationEpoch === dynamicNavigationEpoch) {
        pendingGraphRequest = null;
        setGraphLoading(false);
      }
    }
  }

  function mergeGraphElements(baseElements, nextElements) {
    const merged = new Map();
    for (const item of [...(baseElements || []), ...(nextElements || [])]) {
      const data = item && item.data ? item.data : null;
      if (!data) continue;
      const key = data.source && data.target
        ? `edge:${data.id || `${data.source}__${data.relationType || data.relation || 'RELATION'}__${data.target}`}`
        : `node:${data.id || data.label || data.rawLabel || ''}`;
      if (!key.endsWith(':')) merged.set(key, item);
    }
    return [...merged.values()];
  }

  async function expandSelectedNode(node) {
    if (currentMode !== 'dynamic' || currentGraphSource !== 'query') return false;
    const query = String(node?.queryLabel || node?.rawLabel || node?.displayLabel || '').trim();
    if (!query) return false;
    const expandNodeId = String(node?.id || '').trim();
    const expandNodeType = String(node?.nodeType || node?.type || '').trim();
    const expandKey = String(node?.id || query);
    if (expandedNodeKeys.has(expandKey)) return false;

    setGraphLoading(true, `Expanding ${escapeHtml(query)} ...`, {
      kind: getTeLoaderKind(node || query),
      label: query,
    });
    try {
      const frame = dynamicFrame || ensureDynamicFrame(buildCurrentGraphRequest());
      if (!dynamicBridgePromise) dynamicBridgePromise = waitForEmbedBridge(frame);
      const bridge = await dynamicBridgePromise;
      if (!bridge || typeof bridge.expandGraph !== 'function') {
        throw new Error('G6 embed bridge cannot expand graph requests');
      }
      const payload = await bridge.expandGraph({
        query,
        expandNodeId,
        expandNodeType,
        expandQuery: query,
      }, {
        graphDataOptions: buildCurrentGraphDataOptions(),
      });
      const nextElements = cloneAnswerElements(Array.isArray(payload && payload.elements) ? payload.elements : []);
      const beforeCount = currentQueryGraphElements.length;
      currentQueryGraphElements = mergeGraphElements(currentQueryGraphElements, nextElements);
      expandedNodeKeys.add(expandKey);
      rawRelationLegendMeta = collectRelationLegendMetaFromElements(currentQueryGraphElements);
      if (Array.isArray(payload && payload.relationLegendMeta)) {
        currentRelationLegendMeta = mergeRelationLegendMeta(currentRelationLegendMeta, payload.relationLegendMeta);
      }
      clearLegendFilterPending();
      renderGraphLegend();
      notifyStateChange();
      setDetail(buildDetail(
        node.displayLabel || node.rawLabel || query,
        `Expanded ${query}. Added ${Math.max(0, currentQueryGraphElements.length - beforeCount)} graph elements while keeping ${currentGraphQuery || 'the current center'} active.`
      ));
      return true;
    } finally {
      setGraphLoading(false);
    }
  }

  async function getDynamicEmbedBridge() {
    if (!dynamicFrame) return null;
    if (!dynamicBridgePromise) {
      dynamicBridgePromise = waitForEmbedBridge(dynamicFrame);
    }
    return dynamicBridgePromise;
  }

  function setExportMenuOpen(open) {
    if (!els.exportMenu || !els.exportMenuToggle) return;
    const isOpen = open === true && !els.exportMenuToggle.disabled;
    els.exportMenu.hidden = !isOpen;
    els.exportMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    if (!isOpen) {
      exportMenuOpenReason = '';
      exportMenuOpenedAt = 0;
    }
  }

  function openExportMenu(reason = 'direct') {
    if (exportMenuCloseTimer) {
      window.clearTimeout(exportMenuCloseTimer);
      exportMenuCloseTimer = null;
    }
    setExportMenuOpen(true);
    exportMenuOpenReason = reason;
    exportMenuOpenedAt = Date.now();
  }

  function closeExportMenu() {
    if (exportMenuCloseTimer) {
      window.clearTimeout(exportMenuCloseTimer);
      exportMenuCloseTimer = null;
    }
    setExportMenuOpen(false);
  }

  function scheduleExportMenuClose() {
    if (exportMenuCloseTimer) window.clearTimeout(exportMenuCloseTimer);
    exportMenuCloseTimer = window.setTimeout(() => {
      const active = document.activeElement;
      if (els.exportMenuWrap && active instanceof Node && els.exportMenuWrap.contains(active)) return;
      closeExportMenu();
    }, 120);
  }

  function toggleExportMenu() {
    if (!els.exportMenu || !els.exportMenuToggle || els.exportMenuToggle.disabled) return;
    setExportMenuOpen(els.exportMenu.hidden);
  }

  function setExportMenuOpenFromPointer() {
    if (!els.exportMenu || !els.exportMenuToggle || els.exportMenuToggle.disabled) return;
    const openedByImmediateHover = exportMenuWasOpenOnPointerDown
      && exportMenuReasonOnPointerDown === 'hover'
      && Date.now() - exportMenuOpenedAtOnPointerDown < 500;
    setExportMenuOpen(!exportMenuWasOpenOnPointerDown || openedByImmediateHover);
  }

  function normalizeCsvValue(value) {
    if (Array.isArray(value)) return value.map((item) => String(item || '').trim()).filter(Boolean).join(';');
    if (value === null || typeof value === 'undefined') return '';
    return String(value);
  }

  function csvEscape(value) {
    const text = normalizeCsvValue(value);
    return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
  }

  function buildCsv(rows, fields) {
    const header = fields.map((field) => field.key).join(',');
    const body = rows.map((row) => fields.map((field) => csvEscape(field.get(row))).join(','));
    return [header, ...body].join('\r\n') + '\r\n';
  }

  function safeExportName(value) {
    const text = String(value || 'graph').trim() || 'graph';
    return text.replace(/[^a-z0-9_.-]+/gi, '_').replace(/^_+|_+$/g, '') || 'graph';
  }

  function exportDateStamp() {
    return new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
  }

  function downloadText(filename, text, mime) {
    const blob = new Blob([text], { type: mime || 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function downloadDataUrl(filename, dataUrl) {
    const link = document.createElement('a');
    link.href = dataUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  function parentFallbackVisibleSubgraph() {
    const visibleElements = filterElementsForLegend(getCurrentGraphElements());
    const nodes = [];
    const edges = [];
    const nodeById = new Map();

    for (const item of visibleElements) {
      const data = item && item.data ? dataClone(item.data) : null;
      if (!data || data.source || data.target) continue;
      const node = {
        id: String(data.id || ''),
        label: String(data.label || data.rawLabel || data.id || ''),
        rawLabel: String(data.rawLabel || data.label || data.id || ''),
        type: String(data.type || 'TE'),
        description: String(data.description || ''),
        pmid: String(data.pmid || ''),
      };
      nodes.push(node);
      nodeById.set(node.id, node);
    }

    for (const item of visibleElements) {
      const data = item && item.data ? dataClone(item.data) : null;
      if (!data || !data.source || !data.target) continue;
      edges.push({
        id: String(data.id || `${data.source}__${data.relationType || data.relation || 'RELATION'}__${data.target}`),
        source: String(data.source || ''),
        target: String(data.target || ''),
        relation: String(data.relation || ''),
        relationType: String(data.relationType || data.relation || 'RELATION'),
        pmids: Array.isArray(data.pmids) ? data.pmids : [],
        evidence: String(data.evidence || ''),
        source_label: nodeById.get(String(data.source || ''))?.label || '',
        target_label: nodeById.get(String(data.target || ''))?.label || '',
      });
    }

    return {
      query: currentGraphQuery,
      nodes,
      edges,
      counts: { nodes: nodes.length, edges: edges.length },
      source: 'parent-filtered-fallback',
    };
  }

  function dataClone(value) {
    return JSON.parse(JSON.stringify(value || {}));
  }

  function getClassificationExporter() {
    if (currentMode === 'tree' || currentMode === 'disease_class_tree') return window.__TEKG_G6_DEFAULT_TREE || null;
    if (currentMode === 'taxonomy_graph' || currentMode === 'disease_class_graph') return taxonomyCanvasRenderer || null;
    return null;
  }

  async function getVisibleSubgraphForExport() {
    const classificationExporter = getClassificationExporter();
    if (classificationExporter && typeof classificationExporter.getExportSnapshot === 'function') {
      return classificationExporter.getExportSnapshot();
    }
    const bridge = await getDynamicEmbedBridge();
    if (bridge && typeof bridge.getVisibleSubgraph === 'function') {
      const subgraph = await bridge.getVisibleSubgraph();
      if (subgraph && subgraph.counts && (subgraph.counts.nodes || subgraph.counts.edges)) {
        return subgraph;
    }
  }

  function downloadEdgeEvidenceCsv(button) {
    const encoded = String(button?.getAttribute('data-evidence-csv') || '');
    if (!encoded) return;
    let text = '';
    try {
      text = decodeURIComponent(encoded);
    } catch (_error) {
      text = encoded;
    }
    const edgeId = String(button?.getAttribute('data-edge-id') || 'edge');
    const base = safeExportName(`${currentGraphQuery || 'graph'}_${edgeId.slice(0, 24)}`);
    downloadText(`tekg_${base}_edge_evidence_${exportDateStamp()}.csv`, text, 'text/csv;charset=utf-8');
  }
    return parentFallbackVisibleSubgraph();
  }

  function buildExportCsvPayload(subgraph) {
    const nodes = Array.isArray(subgraph && subgraph.nodes) ? subgraph.nodes : [];
    const edges = Array.isArray(subgraph && subgraph.edges) ? subgraph.edges : [];
    const nodeFields = [
      { key: 'id', get: (row) => row.id },
      { key: 'label', get: (row) => row.label || row.displayLabel || row.rawLabel || row.id },
      { key: 'rawLabel', get: (row) => row.rawLabel || row.label || row.id },
      { key: 'type', get: (row) => row.type || row.nodeType },
      { key: 'description', get: (row) => row.description },
      { key: 'pmid', get: (row) => row.pmid },
    ];
    const edgeFields = [
      { key: 'id', get: (row) => row.id },
      { key: 'source', get: (row) => row.source },
      { key: 'target', get: (row) => row.target },
      { key: 'relation', get: (row) => row.relation },
      { key: 'relationType', get: (row) => row.relationType || row.relationKey },
      { key: 'pmids', get: (row) => row.pmids },
      { key: 'evidence', get: (row) => row.evidence },
    ];
    return {
      query: subgraph?.query || currentGraphQuery || 'graph',
      counts: subgraph?.counts || { nodes: nodes.length, edges: edges.length },
      nodesCsv: buildCsv(nodes, nodeFields),
      edgesCsv: buildCsv(edges, edgeFields),
    };
  }

  async function exportVisibleCsv(options = {}) {
    const subgraph = await getVisibleSubgraphForExport();
    const payload = buildExportCsvPayload(subgraph);
    const counts = payload.counts || {};
    if (!counts.nodes && !counts.edges) {
      setDetail(buildDetail('Export unavailable', 'No visible graph nodes or edges are available to export.'));
      return payload;
    }
    if (options.download !== false) {
      const base = safeExportName(payload.query || currentGraphQuery || 'graph');
      const stamp = exportDateStamp();
      downloadText(`tekg_${base}_visible_nodes_${stamp}.csv`, payload.nodesCsv, 'text/csv;charset=utf-8');
      downloadText(`tekg_${base}_visible_edges_${stamp}.csv`, payload.edgesCsv, 'text/csv;charset=utf-8');
      setDetail(buildDetail('CSV export ready', `Exported ${counts.nodes || 0} nodes and ${counts.edges || 0} edges from the current visible graph.`));
    }
    return payload;
  }

  function dataUrlByteLength(dataUrl) {
    const marker = 'base64,';
    const index = String(dataUrl || '').indexOf(marker);
    if (index < 0) return 0;
    const base64 = String(dataUrl).slice(index + marker.length);
    return Math.floor((base64.length * 3) / 4) - (base64.endsWith('==') ? 2 : base64.endsWith('=') ? 1 : 0);
  }

  async function exportCanvasPng(options = {}) {
    const classificationExporter = getClassificationExporter();
    const bridge = classificationExporter || await getDynamicEmbedBridge();
    if (!bridge || typeof bridge.exportPngDataUrl !== 'function') {
      throw new Error('G6 PNG export bridge is not available');
    }
    const dataUrl = await bridge.exportPngDataUrl();
    if (!String(dataUrl || '').startsWith('data:image/png;base64,')) {
      throw new Error('G6 PNG export did not return a PNG data URL');
    }
    const payload = {
      query: currentGraphQuery || (currentMode === 'tree' ? 'taxonomy_tree' : currentMode === 'taxonomy_graph' ? 'taxonomy_graph' : 'graph'),
      dataUrl,
      byteLength: dataUrlByteLength(dataUrl),
    };
    if (options.download !== false) {
      const base = safeExportName(payload.query);
      downloadDataUrl(`tekg_${base}_canvas_${exportDateStamp()}.png`, dataUrl);
      setDetail(buildDetail('PNG export ready', 'Exported the current graph canvas as a PNG image.'));
    }
    return payload;
  }

  async function exportVisibleSvg(options = {}) {
    const classificationExporter = getClassificationExporter();
    const bridge = classificationExporter || await getDynamicEmbedBridge();
    if (!bridge || typeof bridge.exportSvgString !== 'function') {
      throw new Error('G6 SVG export bridge is not available');
    }
    const svg = await bridge.exportSvgString();
    if (!String(svg || '').startsWith('<svg') || !String(svg).includes('xmlns="http://www.w3.org/2000/svg"')) {
      throw new Error('G6 SVG export did not return a valid SVG document');
    }
    const payload = {
      query: currentGraphQuery || (currentMode === 'tree' ? 'taxonomy_tree' : currentMode === 'taxonomy_graph' ? 'taxonomy_graph' : 'graph'),
      svg,
      byteLength: new TextEncoder().encode(svg).byteLength,
    };
    if (options.download !== false) {
      const base = safeExportName(payload.query);
      downloadText(`tekg_${base}_visible_${exportDateStamp()}.svg`, svg, 'image/svg+xml;charset=utf-8');
      setDetail(buildDetail('SVG export ready', 'Exported the current visible graph as a vector SVG image.'));
    }
    return payload;
  }

  function bindEvents() {
    renderGraphLegend();
    syncLegendVisibility(currentMode);
    window.addEventListener('tekg:g6-state-change', (event) => {
      const nextMode = event && event.detail && typeof event.detail.mode === 'string' ? event.detail.mode : currentMode;
      syncLegendVisibility(nextMode);
    });

    if (els.graphLegendList) {
      els.graphLegendList.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        if (target.closest('.graph-legend-check')) return;
        const item = target.closest('.graph-legend-item[data-highlight-kind][data-highlight-value]');
        if (!(item instanceof HTMLElement)) return;
        const rawKind = String(item.dataset.highlightKind || '').trim();
        const kind = rawKind === 'relation' ? 'relation' : rawKind === 'taxonomy' ? 'taxonomy' : 'entity';
        const value = String(item.dataset.highlightValue || '').trim();
        if (!value) return;
        const isSame = activeLegendHighlight
          && activeLegendHighlight.kind === kind
          && activeLegendHighlight.value === value;
        setLegendHighlight(isSame ? null : { kind, value }).catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });

      els.graphLegendList.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || !target.classList.contains('graph-legend-check')) return;
        const type = String(target.dataset.type || '').trim();
        const relationType = String(target.dataset.relation || '').trim();
        const taxonomyLevel = String(target.dataset.taxonomyLevel || '').trim();
        beginLegendFilterDraft();
        if ((currentMode === 'taxonomy_graph' || currentMode === 'disease_class_graph') && taxonomyLevel) {
          ensureTaxonomyLegendState()[taxonomyLevel] = target.checked;
        } else if (activeLegendMode === 'relation' && relationType) {
          ensureRelationLegendState()[relationType] = target.checked;
        } else if (type) {
          applyEntityLegendCheckState(type, target.checked);
        } else {
          return;
        }
        markLegendFilterPending();
      });
    }

    if (els.detail) {
      els.detail.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const button = target.closest('.edge-evidence-download');
        if (!(button instanceof HTMLButtonElement)) return;
        downloadEdgeEvidenceCsv(button);
      });
    }

    if (els.graphLegendTabs) {
      els.graphLegendTabs.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLButtonElement)) return;
        const mode = String(target.dataset.legendMode || '').trim();
        if (!mode || mode === activeLegendMode) return;
        activeLegendMode = mode === 'relation' ? 'relation' : 'entity';
        setLegendHighlight(null, { renderLegend: false }).catch(() => {});
        updateButtons();
        renderGraphLegend();
      });
    }

    if (els.relationMinPmidsInput) {
      els.relationMinPmidsInput.addEventListener('change', () => {
        beginLegendFilterDraft();
        const nextValue = Math.max(0, Number.parseInt(els.relationMinPmidsInput.value || '0', 10) || 0);
        relationMinPmids = nextValue;
        updateButtons();
        markLegendFilterPending();
      });
    }

    if (els.graphLegendApply) {
      els.graphLegendApply.addEventListener('click', () => {
        applyPendingLegendFilter().catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });
    }

    if (els.expandModeBtn) {
      els.expandModeBtn.addEventListener('click', () => {
        expandModeEnabled = !expandModeEnabled;
        if (expandModeEnabled) {
          window.fixedView = false;
        }
        updateCurrentGraphViewState().catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });
    }

    if (els.searchInput) {
      els.searchInput.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        submitGraphSearch();
      });
    }

    if (els.graphSearchSubmit) {
      els.graphSearchSubmit.addEventListener('click', submitGraphSearch);
    }

    if (els.graphSearchType) {
      els.graphSearchType.addEventListener('change', updateGraphSearchPlaceholder);
    }

    if (els.resetBtn) {
      els.resetBtn.addEventListener('click', () => {
        navigateTaxonomyRoute(desiredTaxonomyRoute, { pushHistory: true }).catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });
    }

    if (els.exportMenuWrap) {
      els.exportMenuWrap.addEventListener('mouseenter', () => openExportMenu('hover'));
      els.exportMenuWrap.addEventListener('mouseleave', scheduleExportMenuClose);
      els.exportMenuWrap.addEventListener('focusin', () => openExportMenu('focus'));
      els.exportMenuWrap.addEventListener('focusout', scheduleExportMenuClose);
    }

    if (els.exportMenuToggle) {
      els.exportMenuToggle.addEventListener('pointerdown', () => {
        exportMenuWasOpenOnPointerDown = !!(els.exportMenu && !els.exportMenu.hidden);
        exportMenuReasonOnPointerDown = exportMenuOpenReason;
        exportMenuOpenedAtOnPointerDown = exportMenuOpenedAt;
      });
      els.exportMenuToggle.addEventListener('click', (event) => {
        event.preventDefault();
        setExportMenuOpenFromPointer();
      });
      els.exportMenuToggle.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowDown' && event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        openExportMenu('keyboard');
        if (els.exportMenuCsv) els.exportMenuCsv.focus();
      });
    }

    if (els.exportMenuCsv) {
      els.exportMenuCsv.addEventListener('click', () => {
        closeExportMenu();
        exportVisibleCsv().catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });
    }

    if (els.exportMenuPng) {
      els.exportMenuPng.addEventListener('click', () => {
        closeExportMenu();
        exportCanvasPng().catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });
    }

    if (els.exportMenuSvg) {
      els.exportMenuSvg.addEventListener('click', () => {
        closeExportMenu();
        exportVisibleSvg().catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });
    }

    if (els.exportMenu) {
      els.exportMenu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          event.preventDefault();
          closeExportMenu();
          if (els.exportMenuToggle) els.exportMenuToggle.focus();
        }
      });
    }

    if (els.backBtn) {
      els.backBtn.addEventListener('click', () => {
        if (graphHistory.length === 0) return;
        goBackGraph().catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });
    }

    if (els.fixedBtn) {
      els.fixedBtn.addEventListener('click', () => {
        window.fixedView = !window.fixedView;
        updateCurrentGraphViewState().catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });
    }

    if (els.edgeLabelsBtn) {
      els.edgeLabelsBtn.addEventListener('click', () => {
        window.showEdgeLabels = !window.showEdgeLabels;
        updateCurrentGraphViewState().catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });
    }

  }

  window.__TEKG_G6_GRAPH_HOST = {
    setDetail(title, description) {
      if (currentMode !== 'dynamic') return;
      setDetail(buildDetail(title, description));
    },
    setDetailHtml(html) {
      if (currentMode !== 'dynamic') return;
      setDetail(html || '');
    },
    setStatus(_text) {},
    setMode(mode, payload) {
      if (mode === 'dynamic') {
        const request = normalizeGraphRequest(payload);
        const nextQuery = String(request.query || '').trim();
        const nextQueryType = request.queryType || '';
        const nextClassQuery = nextQueryType === 'disease_class'
          ? String(request.classQuery || nextQuery || '').trim()
          : '';
        const nextSource = payload && payload.source === 'qa' ? 'answer' : 'query';
        if (
          pendingGraphRequest
          && nextSource === 'query'
          && (
            String(pendingGraphRequest.query || '').trim().toLowerCase() !== nextQuery.toLowerCase()
            || String(pendingGraphRequest.queryType || '') !== nextQueryType
          )
        ) return;

        currentMode = 'dynamic';
        currentGraphSource = nextSource;
        currentGraphQuery = nextQuery || String(currentGraphQuery || '').trim();
        currentGraphQueryType = nextQueryType;
        currentGraphClassQuery = nextClassQuery;
        syncGraphSearchType(currentGraphQueryType);
        notifyStateChange();
      }
    },
    onReady() {},
    onNodeSelect(node) {
      currentSelectedNode = node || null;
      notifyStateChange();
    },
    onDiseaseClassClick(node, request) {
      const classQuery = String(
        (request && request.classQuery)
        || (node && (node.classQuery || node.diseaseClass || node.queryLabel || node.displayLabel || node.rawLabel))
        || ''
      ).trim();
      if (!classQuery) return Promise.resolve(false);
      return navigateDiseaseClass({
        query: classQuery,
        queryType: 'disease_class',
        classQuery,
      }, { pushHistory: true, taxonomyDisplayMode: 'tree', history: 'push' }).then(() => true);
    },
    onNodeJump(node, request) {
      const query = String(
        request?.query
        || node?.queryLabel
        || node?.rawLabel
        || node?.displayLabel
        || ''
      ).trim();
      if (!query) return Promise.resolve(false);
      return navigateGraph({
        query,
        queryType: request?.queryType || normalizeQueryType(node?.nodeType || node?.type || ''),
        classQuery: request?.classQuery || '',
      }, { history: 'push' }).then((loaded) => loaded === true);
    },
    onNodeExpand(node) {
      return expandSelectedNode(node).catch((error) => {
        setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        return false;
      });
    },
  };

  window.__TEKG_LOAD_DYNAMIC_GRAPH = navigateGraph;
  window.__TEKG_G6_SHOW_TREE = renderDefaultTree;
  window.__TEKG_G6_EXPORT = {
    getVisibleSubgraph: getVisibleSubgraphForExport,
    exportCsv: exportVisibleCsv,
    exportPng: exportCanvasPng,
    exportSvg: exportVisibleSvg,
  };
  window.__TEKG_G6_BRIDGE = {
    loadGraph(query, options = {}) {
      return loadDynamicGraph(query, options);
    },
    navigateGraph(query, options = {}) {
      return navigateGraph(query, options);
    },
    applyAnswerGraph(result) {
      return applyAnswerGraph(result);
    },
    goBack() {
      return goBackGraph();
    },
    canGoBack() {
      return graphHistory.length > 0;
    },
    resetHistory() {
      graphHistory.length = 0;
      updateBackButton();
      return true;
    },
    refreshBackButton() {
      updateBackButton();
    },
    showTree() {
      return navigateTaxonomyRoute({ taxonomyDisplayMode: 'tree' });
    },
    showDiseaseClassTree(requestLike) {
      return navigateDiseaseClass(requestLike, { taxonomyDisplayMode: 'tree', history: 'push' });
    },
    reset() {
      return navigateTaxonomyRoute(desiredTaxonomyRoute);
    },
    setKeyNodeLevel(_level) {
      return Promise.resolve();
    },
    setFixedView(next) {
      window.fixedView = !!next;
      updateButtons();
      if (currentMode === 'dynamic') {
        return updateCurrentGraphViewState().then(() => window.fixedView);
      }
      return Promise.resolve(window.fixedView);
    },
    getFixedView() {
      return !!window.fixedView;
    },
    getShowEdgeLabels() {
      return !!window.showEdgeLabels;
    },
    getKeyNodeLevel() {
      return 1;
    },
    getMode() {
      return currentMode;
    },
    getCurrentQuery() {
      return currentGraphQuery;
    },
    getCurrentRequest() {
      return buildCurrentGraphRequest();
    },
    setTreeVariant(variant) {
      return navigateTaxonomyRoute({ treeVariant: variant }, { pushHistory: false });
    },
    setTaxonomyDisplayMode(mode) {
      return setTaxonomyDisplayMode(mode);
    },
    restoreTaxonomyRoute(route) {
      return restoreTaxonomyRoute(route || {});
    },
    restoreDiseaseClassRoute(route) {
      return navigateDiseaseClass({
        query: route?.classQuery || route?.query,
        queryType: 'disease_class',
        classQuery: route?.classQuery || route?.query,
      }, {
        pushHistory: false,
        taxonomyDisplayMode: route?.taxonomyDisplayMode === 'graph' ? 'graph' : 'tree',
      });
    },
    getTaxonomyDisplayMode() {
      return currentTaxonomyDisplayMode;
    },
    getTreeVariant() {
      return currentTreeVariant;
    },
    getSelectedNode() {
      return currentSelectedNode;
    },
    getState() {
      return snapshotState();
    },
    getTeLoaderKind(nodeOrQuery) {
      return getTeLoaderKind(nodeOrQuery);
    },
    previewTeLoader(nodeOrQuery) {
      const label = nodeOrQuery && typeof nodeOrQuery === 'object'
        ? String(nodeOrQuery.queryLabel || nodeOrQuery.rawLabel || nodeOrQuery.displayLabel || nodeOrQuery.label || '').trim()
        : String(nodeOrQuery || '').trim();
      const kind = getTeLoaderKind(nodeOrQuery);
      if (els.graphLoader) {
        els.graphLoader.classList.add('is-visible');
        els.graphLoader.setAttribute('aria-hidden', 'false');
      }
      if (els.graphLoaderLabel) {
        els.graphLoaderLabel.textContent = kind === 'default'
          ? (label ? textSet().loadingOverlay(label) : 'Loading graph...')
          : `Loading ${label || 'TE'} network`;
      }
      return renderTeMechanismLoader(kind, label || 'TE');
    },
    getVisibleSubgraph() {
      return getVisibleSubgraphForExport();
    },
    exportCsv(options) {
      return exportVisibleCsv(options || {});
    },
    exportPng(options) {
      return exportCanvasPng(options || {});
    },
    exportSvg(options) {
      return exportVisibleSvg(options || {});
    },
    getVisibleTypes() {
      return getVisibleTypePayload();
    },
    setVisibleTypes(nextState) {
      if (!nextState || typeof nextState !== 'object') return getVisibleTypePayload();
      visibleTypeState = { ...ensureVisibleTypeState(), ...nextState };
      renderGraphLegend();
      if (currentMode === 'dynamic') {
        if (currentGraphSource === 'answer') {
          void renderAnswerGraphElements(currentAnswerGraphElements, currentGraphQuery || 'LINE1', { pushHistory: false });
        } else {
          void loadDynamicGraph(buildCurrentGraphRequest(), { pushHistory: false });
        }
      }
      return getVisibleTypePayload();
    },
  };

  Promise.resolve()
    .then(applyPageMode)
    .then(() => {
      updateButtons();
      bindEvents();

      if (initialQuery && initialQueryType === 'disease_class') {
        return loadSharedResources().then(() => navigateDiseaseClass({
          query: initialClassQuery || initialQuery,
          queryType: 'disease_class',
          classQuery: initialClassQuery || initialQuery,
        }, { pushHistory: false, taxonomyDisplayMode: initialTaxonomyDisplayMode }));
      }

      if (initialQuery) {
        return loadSharedResources().then(() => loadDynamicGraph({
          query: initialQuery,
          queryType: initialQueryType,
          classQuery: initialClassQuery || initialQuery,
        }, { pushHistory: false, routeFilters: initialRouteFilters }));
      }

      navigateTaxonomyRoute(desiredTaxonomyRoute).catch((error) => {
        setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
      });
      return loadSharedResources();
    })
    .catch((error) => {
      console.error('Formal G6 bootstrap failed:', error);
      updateButtons();
      setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
    });
}());
