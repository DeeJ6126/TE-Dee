(function () {
  if (window.__TEKG_RENDERER_MODE !== 'g6') return;

  const params = new URLSearchParams(window.location.search);
  const embedMode = typeof window.__TEKG_EMBED_MODE === 'string' ? window.__TEKG_EMBED_MODE : '';
  const initialQuery = String(window.__TEKG_INITIAL_QUERY || '').trim();
  const initialQueryType = String(params.get('type') || '').trim().toLowerCase();
  const initialClassQuery = String(params.get('class') || '').trim();
  const initialTreeVariant = String(params.get('tree') || window.__TEKG_TREE_VARIANT || 'rmsk_repbase').trim() || 'rmsk_repbase';

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
    expressionLayerButton: document.getElementById('graph-expression-layer'),
    expressionLayerText: document.getElementById('graph-expression-layer-text'),
    taxonomyDisplayBtn: document.getElementById('toggle-taxonomy-display'),
    taxonomyDisplayText: document.getElementById('taxonomy-display-text'),
    taxonomySourceBtn: document.getElementById('toggle-taxonomy-source'),
    taxonomySourceText: document.getElementById('taxonomy-source-text'),
    detail: document.getElementById('node-details'),
    treeSurface: document.getElementById('g6-default-tree-surface'),
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
      backToTree: 'Back to tree',
      reset: 'Reset',
      expandModeOn: 'Expand mode: On',
      expandModeOff: 'Expand mode: Off',
      taxonomyDisplayTree: 'Switch: Tree',
      taxonomyDisplayGraph: 'Switch: Graph',
      taxonomySource: (label) => `Switch taxonomy: ${label}`,
      treeDetail:
        '<strong>No node selected</strong>Click a TE node to inspect it, then click again to enter the dynamic graph.',
      loadingDetail: (query) => buildLoadingDetailHtml(`Preparing the dynamic graph for ${escapeHtml(query)}.`),
      loadingOverlay: (query) => `Preparing ${escapeHtml(query)} ...`,
      graphError: (message) => `Failed: ${message || 'unknown error'}`,
      legendTitle: 'Entity Legend',
    },
  };

  let currentMode = 'tree';
  let currentTaxonomyDisplayMode = 'graph';
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
  let legendFilterPending = false;
  let exportMenuCloseTimer = null;
  let exportMenuWasOpenOnPointerDown = false;
  let exportMenuReasonOnPointerDown = '';
  let exportMenuOpenedAtOnPointerDown = 0;
  let exportMenuOpenReason = '';
  let exportMenuOpenedAt = 0;
  let expressionLayerContext = 'off';
  let expressionOverlaySeq = 0;
  const expressionSummaryCache = new Map();
  const EXPRESSION_LAYER_CONTEXTS = ['off', 'global', 'normal_tissue', 'normal_cell_line', 'cancer_cell_line'];

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

  const TE_LOADER_KEYWORDS = Object.freeze({
    retro: [
      'LINE',
      'LINE1',
      'LINE-1',
      'L1',
      'L1HS',
      'SINE',
      'Alu',
      'SVA',
      'ERV',
      'HERV',
      'LTR',
      'retrotransposon',
    ],
    dna: [
      'DNA transposon',
      'Tc1',
      'Mariner',
      'hAT',
      'piggyBac',
      'PIF',
      'Harbinger',
      'Merlin',
      'Mutator',
    ],
  });

  function getTeColor() {
    const sharedMeta = window.__TEKG_G6_TYPE_META && typeof window.__TEKG_G6_TYPE_META === 'object'
      ? window.__TEKG_G6_TYPE_META
      : {};
    const colors = sharedMeta.colors && typeof sharedMeta.colors === 'object' ? sharedMeta.colors : LEGEND_FALLBACK_COLORS;
    return String(colors.TE || LEGEND_FALLBACK_COLORS.TE || '#4e79ff');
  }

  function getTeStrokeColor() {
    const sharedMeta = window.__TEKG_G6_TYPE_META && typeof window.__TEKG_G6_TYPE_META === 'object'
      ? window.__TEKG_G6_TYPE_META
      : {};
    const strokes = sharedMeta.strokes && typeof sharedMeta.strokes === 'object' ? sharedMeta.strokes : {};
    return String(strokes.TE || '#1f3f99');
  }

  function normalizeLoaderText(value) {
    return String(value || '')
      .replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'")
      .replace(/\s+/g, ' ')
      .trim();
  }

  function normalizeLoaderMatchText(value) {
    return ` ${normalizeLoaderText(value)
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, ' ')
      .replace(/\bretrotransposons\b/g, 'retrotransposon')
      .replace(/\btransposons\b/g, 'transposon')
      .replace(/\bsines\b/g, 'sine')
      .replace(/\blines\b/g, 'line')
      .replace(/\bltrs\b/g, 'ltr')
      .replace(/\bervs\b/g, 'erv')
      .replace(/\bhervs\b/g, 'herv')
      .replace(/\s+/g, ' ')
      .trim()} `;
  }

  function loaderPhraseMatch(normalizedText, phrase) {
    const normalizedPhrase = normalizeLoaderMatchText(phrase).trim();
    return !!normalizedPhrase && normalizedText.includes(` ${normalizedPhrase} `);
  }

  function labelFromLoaderText(label) {
    const raw = normalizeLoaderText(label)
      .replace(/^Preparing\s+/i, '')
      .replace(/^Loading\s+/i, '')
      .replace(/^Expanding\s+/i, '')
      .replace(/\s+network\s*$/i, '')
      .replace(/\s*\.\.\.\s*$/i, '')
      .trim();
    return raw || normalizeLoaderText(label);
  }

  function loaderKeywordMatch(text, keywords) {
    const raw = String(text || '');
    if (!raw) return false;
    const normalizedText = normalizeLoaderMatchText(raw);
    return keywords.some((keyword) => {
      const normalized = String(keyword || '').trim().toLowerCase();
      if (!normalized) return false;
      return loaderPhraseMatch(normalizedText, normalized);
    });
  }

  function getSemanticTeLoaderKind(text) {
    const normalizedText = normalizeLoaderMatchText(text);
    if (!normalizedText.trim()) return 'default';
    if (
      loaderPhraseMatch(normalizedText, 'class ii')
      || loaderPhraseMatch(normalizedText, 'class 2')
      || loaderPhraseMatch(normalizedText, 'dna transposon')
      || loaderPhraseMatch(normalizedText, 'tir')
    ) {
      return 'dna';
    }
    if (
      loaderPhraseMatch(normalizedText, 'class i')
      || loaderPhraseMatch(normalizedText, 'class 1')
      || loaderPhraseMatch(normalizedText, 'retrotransposon')
    ) {
      return 'retro';
    }
    if (loaderKeywordMatch(text, TE_LOADER_KEYWORDS.dna)) return 'dna';
    if (loaderKeywordMatch(text, TE_LOADER_KEYWORDS.retro)) return 'retro';
    return 'default';
  }

  function getTeLoaderKind(nodeOrQuery) {
    const parts = [];
    if (nodeOrQuery && typeof nodeOrQuery === 'object') {
      const nodeType = String(nodeOrQuery.nodeType || nodeOrQuery.type || '').trim();
      const label = String(nodeOrQuery.queryLabel || nodeOrQuery.rawLabel || nodeOrQuery.displayLabel || nodeOrQuery.label || '').trim();
      const taxonomy = nodeOrQuery.taxonomy && typeof nodeOrQuery.taxonomy === 'object' ? nodeOrQuery.taxonomy : {};
      const pathLabels = Array.isArray(taxonomy.path_labels) ? taxonomy.path_labels.join(' ') : '';
      parts.push(label, nodeType, taxonomy.canonical_name || '', taxonomy.display_path || '', pathLabels);
    } else {
      parts.push(String(nodeOrQuery || '').trim());
    }
    const text = parts.filter(Boolean).join(' ');
    if (!text) return 'default';
    return getSemanticTeLoaderKind(text);
  }

  function truncateTeLoaderLabel(label) {
    const raw = normalizeLoaderText(label);
    if (raw.length <= 8) return raw;
    return `${raw.slice(0, 3).trim()}...`;
  }

  function buildTeMechanismLoaderSvg(kind, label) {
    const fullLabel = normalizeLoaderText(label) || 'TE';
    const displayLabel = truncateTeLoaderLabel(fullLabel);
    if (kind === 'retro') {
      return [
        `<svg class="te-mechanism-loader__svg" viewBox="0 0 560 300" role="img" aria-label="Retrotransposon-inspired loader for ${escapeHtml(fullLabel)}">`,
        `  <title>Retrotransposon-inspired loader for ${escapeHtml(fullLabel)}</title>`,
        '  <g class="te-loader-source-dna">',
        '    <path class="te-loader-dna-backbone" d="M48 72 H250" />',
        '    <rect class="te-loader-te-segment" x="102" y="50" width="96" height="44" rx="12" />',
        `    <text class="te-loader-label" x="150" y="72">${escapeHtml(displayLabel)}</text>`,
        '    <text class="te-loader-muted-label" x="96" y="34">source DNA</text>',
        '  </g>',
        '  <g class="te-loader-retro-complex">',
        '    <path class="te-loader-rna" d="M132 104 C164 132, 194 122, 224 150" />',
        '    <text class="te-loader-muted-label te-loader-rna-label" x="166" y="142">RNA</text>',
        '    <circle class="te-loader-enzyme-fill" cx="224" cy="150" r="18" />',
        '    <text class="te-loader-label te-loader-rt-label" x="224" y="150">RT</text>',
        '  </g>',
        '  <g class="te-loader-target-dna">',
        '    <path class="te-loader-dna-backbone te-loader-target-left" d="M250 232 H354" />',
        '    <path class="te-loader-dna-backbone te-loader-target-right" d="M432 232 H510" />',
        '    <path class="te-loader-target-open" d="M362 232 C374 206, 412 206, 424 232" />',
        '    <text class="te-loader-muted-label" x="388" y="268">target DNA</text>',
        '  </g>',
        '  <g class="te-loader-copy">',
        '    <rect class="te-loader-te-segment" x="354" y="210" width="78" height="44" rx="10" />',
        `    <text class="te-loader-label te-loader-copy-label" x="393" y="232">${escapeHtml(displayLabel)}</text>`,
        '  </g>',
        '</svg>',
      ].join('');
    }
    if (kind === 'dna') {
      return [
        `<svg class="te-mechanism-loader__svg" viewBox="0 0 560 300" role="img" aria-label="DNA transposon-inspired loader for ${escapeHtml(fullLabel)}">`,
        `  <title>DNA transposon-inspired loader for ${escapeHtml(fullLabel)}</title>`,
        '  <g class="te-loader-source-dna">',
        '    <path class="te-loader-dna-backbone te-loader-source-left" d="M48 72 H102" />',
        '    <path class="te-loader-dna-backbone te-loader-source-right" d="M198 72 H250" />',
        '    <text class="te-loader-muted-label" x="96" y="34">source DNA</text>',
        '    <text class="te-loader-muted-label" x="150" y="120">donor gap</text>',
        '  </g>',
        '  <g class="te-loader-target-dna">',
        '    <path class="te-loader-dna-backbone te-loader-target-left" d="M250 232 H344" />',
        '    <path class="te-loader-dna-backbone te-loader-target-right" d="M440 232 H510" />',
        '    <path class="te-loader-target-open" d="M352 232 C366 206, 418 206, 432 232" />',
        '    <text class="te-loader-muted-label" x="388" y="268">target DNA</text>',
        '  </g>',
        '  <g class="te-loader-dna-segment-moving">',
        '    <rect class="te-loader-te-segment" x="102" y="50" width="96" height="44" rx="12" />',
        `    <text class="te-loader-label" x="150" y="72">${escapeHtml(displayLabel)}</text>`,
        '  </g>',
        '</svg>',
      ].join('');
    }
    return '';
  }

  function renderTeMechanismLoader(kind, label, colors = {}) {
    const safeKind = kind === 'retro' || kind === 'dna' ? kind : 'default';
    const teColor = String(colors.te || getTeColor());
    const teStroke = String(colors.teStroke || getTeStrokeColor());
    if (els.graphLoader) {
      els.graphLoader.classList.remove('te-loader-retro', 'te-loader-dna', 'te-loader-default');
      els.graphLoader.classList.add(`te-loader-${safeKind}`);
    }
    if (!els.mechanismLoaderSlot) {
      return { kind: safeKind, label: normalizeLoaderText(label), rendered: false };
    }
    if (safeKind === 'default') {
      els.mechanismLoaderSlot.innerHTML = '';
      els.mechanismLoaderSlot.setAttribute('aria-hidden', 'true');
      els.mechanismLoaderSlot.removeAttribute('style');
      return { kind: safeKind, label: normalizeLoaderText(label), rendered: false };
    }
    els.mechanismLoaderSlot.style.setProperty('--te-loader-te-color', teColor);
    els.mechanismLoaderSlot.style.setProperty('--te-loader-te-stroke', teStroke);
    els.mechanismLoaderSlot.innerHTML = `<div class="te-mechanism-loader te-loader-${safeKind}">${buildTeMechanismLoaderSvg(safeKind, label)}</div>`;
    els.mechanismLoaderSlot.setAttribute('aria-hidden', 'false');
    return {
      kind: safeKind,
      label: normalizeLoaderText(label),
      rendered: true,
      teColor,
      teStroke,
    };
  }

  function resolveLoaderContext(label, context) {
    if (context && typeof context === 'object') {
      const labelValue = String(context.label || context.queryLabel || context.rawLabel || context.displayLabel || labelFromLoaderText(label) || '').trim();
      return {
        kind: context.kind || getTeLoaderKind(context),
        label: labelValue,
      };
    }
    const labelValue = labelFromLoaderText(label);
    return {
      kind: getTeLoaderKind(labelValue),
      label: labelValue,
    };
  }

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

  const VISIBLE_TYPE_STATE_STORAGE_KEY = 'tekg:g6-visible-types';
  const LEGEND_DISEASE_TYPES = new Set(['Disease', 'DiseaseClass']);
  const HIDDEN_ENTITY_LEGEND_TYPES = new Set(['DiseaseClass', 'DiseaseCategory']);
  let visibleTypeState = null;

  function loadPersistedVisibleTypeState() {
    try {
      const raw = window.sessionStorage.getItem(VISIBLE_TYPE_STATE_STORAGE_KEY);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (_error) {
      return null;
    }
  }

  function persistVisibleTypeState() {
    try {
      window.sessionStorage.setItem(VISIBLE_TYPE_STATE_STORAGE_KEY, JSON.stringify(visibleTypeState || {}));
    } catch (_error) {}
  }

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
    const renderer = window.__TEKG_G6_DEFAULT_TREE;
    if (renderer && typeof renderer.getLevelLegendItems === 'function') {
      return renderer.getLevelLegendItems().map((item) => ({
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

  function expressionContextLabel(context) {
    return {
      off: 'Off',
      global: 'Global',
      normal_tissue: 'Normal Tissue',
      normal_cell_line: 'Normal Cell Line',
      cancer_cell_line: 'Cancer Cell Line',
    }[context] || 'Off';
  }

  function expressionToggleLabel(context) {
    return `Expression: ${expressionContextLabel(context)}`;
  }

  function getNextExpressionLayerContext(context) {
    const index = EXPRESSION_LAYER_CONTEXTS.indexOf(context);
    return EXPRESSION_LAYER_CONTEXTS[(index + 1) % EXPRESSION_LAYER_CONTEXTS.length];
  }

  function expressionNodeName(data) {
    return String(data?.rawLabel || data?.label || data?.id || '')
      .replace(/^(Class I|Class II|Subclass|Order|Superfamily|Family):\s*/i, '')
      .trim();
  }

  function collectVisibleTeNamesForExpression(elements = filterElementsForLegend(getCurrentGraphElements())) {
    const names = new Set();
    for (const item of Array.isArray(elements) ? elements : []) {
      const data = item && item.data ? item.data : null;
      if (!data || data.source || data.target) continue;
      if (String(data.type || 'TE') !== 'TE') continue;
      const name = expressionNodeName(data);
      if (name) names.add(name);
    }
    return [...names].slice(0, 80);
  }

  function uniqueExpressionNames(names) {
    return [...new Set((names || []).map((name) => String(name || '').trim()).filter(Boolean))];
  }

  function expressionRecordMap(records) {
    const mapped = {};
    for (const record of Array.isArray(records) ? records : []) {
      if (!record || typeof record !== 'object') continue;
      for (const key of [record.requested_name, record.te_name]) {
        const normalized = String(key || '').trim().toLowerCase();
        if (normalized) mapped[normalized] = record;
      }
    }
    return mapped;
  }

  function expressionOverlayPayload(context, records, names) {
    const recordMap = expressionRecordMap(records);
    const values = [];
    for (const name of names) {
      const record = recordMap[String(name || '').trim().toLowerCase()];
      if (!record || record.available !== true) continue;
      const bucket = context === 'global' ? record.global : record[context];
      const value = Number(bucket && bucket.median_value);
      if (Number.isFinite(value)) values.push(value);
    }
    return {
      enabled: context !== 'off',
      context,
      context_label: expressionContextLabel(context),
      records: recordMap,
      max_value: values.length ? Math.max(...values) : 0,
      evidence_boundary: 'Expression values provide activity context only and do not prove causal graph relations.',
    };
  }

  async function fetchExpressionSummaries(names, context) {
    const cleanNames = uniqueExpressionNames(names);
    if (!cleanNames.length || context === 'off') return [];
    const key = `${context}|${cleanNames.slice().sort((a, b) => a.localeCompare(b)).join('|')}`;
    if (expressionSummaryCache.has(key)) return expressionSummaryCache.get(key);

    const endpoint = window.__TEKG_PATHS?.apiUrl
      ? window.__TEKG_PATHS.apiUrl('graph_expression.php')
      : '/TE-/api/graph_expression.php';
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ te_names: cleanNames, context }),
    });
    const payload = await response.json();
    if (!response.ok || !payload || payload.ok !== true) {
      throw new Error(payload && payload.error ? payload.error : 'Expression layer request failed.');
    }
    const records = Array.isArray(payload.records) ? payload.records : [];
    expressionSummaryCache.set(key, records);
    return records;
  }

  async function pushExpressionOverlayToBridge(renderedElements = filterElementsForLegend(getCurrentGraphElements())) {
    const seq = ++expressionOverlaySeq;
    const context = expressionLayerContext;
    const names = collectVisibleTeNamesForExpression(renderedElements);
    const bridge = dynamicFrame ? await getDynamicEmbedBridge() : null;

    if (context === 'off') {
      if (bridge && typeof bridge.setExpressionOverlay === 'function') {
        await bridge.setExpressionOverlay(expressionOverlayPayload('off', [], []));
      }
      return;
    }

    if (!names.length) {
      if (bridge && typeof bridge.setExpressionOverlay === 'function') {
        await bridge.setExpressionOverlay(expressionOverlayPayload(context, [], []));
      }
      return;
    }

    try {
      const records = await fetchExpressionSummaries(names, context);
      if (seq !== expressionOverlaySeq || context !== expressionLayerContext) return;
      if (bridge && typeof bridge.setExpressionOverlay === 'function') {
        await bridge.setExpressionOverlay(expressionOverlayPayload(context, records, names));
      }
    } catch (error) {
      if (seq !== expressionOverlaySeq) return;
      if (bridge && typeof bridge.setExpressionOverlay === 'function') {
        await bridge.setExpressionOverlay(expressionOverlayPayload('off', [], []));
      }
      console.warn('Expression evidence layer failed:', error);
    }
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
    const seed = visibleTypeState && typeof visibleTypeState === 'object'
      ? visibleTypeState
      : loadPersistedVisibleTypeState();
    const next = seed && typeof seed === 'object'
      ? { ...seed }
      : {};
    for (const item of getLegendTypeMeta()) {
      if (typeof next[item.type] !== 'boolean') next[item.type] = true;
    }
    visibleTypeState = next;
    persistVisibleTypeState();
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

  function buildCurrentGraphDataOptions(extra = {}) {
    return Object.assign({
      showEdgeLabels: window.showEdgeLabels,
      allowInspectCard: true,
      visibleTypes: getVisibleTypePayload(),
      visibleRelations: getVisibleRelationPayload(),
      minRelationPmids: relationMinPmids,
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
    const isTaxonomyMode = currentMode === 'taxonomy_graph';
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
    const taxonomyMode = mode === 'taxonomy_graph';
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
    if (currentMode === 'taxonomy_graph') return applyTaxonomyLegendFilter();
    if (currentMode !== 'dynamic') return Promise.resolve(false);
    const sourceElements = currentGraphSource === 'answer'
      ? currentAnswerGraphElements
      : currentQueryGraphElements;
    return renderDynamicElementsFromCache(sourceElements, {
      source: currentGraphSource,
      request: buildCurrentGraphRequest(),
    }).then(() => true);
  }

  function applyTaxonomyLegendFilter() {
    if (currentMode !== 'taxonomy_graph') return Promise.resolve(false);
    const renderer = window.__TEKG_G6_DEFAULT_TREE;
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
      els.graphLegendApply.disabled = false;
    }
  }

  function clearLegendFilterPending() {
    legendFilterPending = false;
    if (els.graphLegendApply) {
      els.graphLegendApply.disabled = true;
    }
  }

  function applyPendingLegendFilter() {
    if (!legendFilterPending) return Promise.resolve(false);
    clearLegendFilterPending();
    return applyLegendTypeFilter();
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

    if (currentMode === 'taxonomy_graph') return setTaxonomyLegendHighlight(activeLegendHighlight);
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
    const renderer = window.__TEKG_G6_DEFAULT_TREE;
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
    }
    if (els.graphLoaderLabel) {
      els.graphLoaderLabel.textContent = displayLabel;
    }
    if (!graphIsLoading) {
      renderTeMechanismLoader('default', '');
    }
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
      fixedView: !!window.fixedView,
      expandModeEnabled,
      relationMinPmids,
      visibleRelations: getVisibleRelationPayload(),
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
    const visibleMap = getVisibleTypePayload();
    const visibleRelations = getVisibleRelationPayload();
    const minPmids = Math.max(0, Number(relationMinPmids) || 0);
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

  function syncExpressionLayerButtonState() {
    syncToggleButtonState(els.expressionLayerButton, expressionLayerContext !== 'off');
    if (els.expressionLayerText) {
      els.expressionLayerText.textContent = expressionToggleLabel(expressionLayerContext);
    }
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
    notifyStateChange();
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
      await pushExpressionOverlayToBridge(renderElements);

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
    if (state.kind === 'disease_class_tree') {
      return [
        'disease_class_tree',
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

    if (currentMode === 'disease_class_tree') {
      return {
        kind: 'disease_class_tree',
        query: currentGraphQuery,
        classQuery: currentGraphClassQuery || currentGraphQuery,
      };
    }

    if (currentGraphSource === 'answer') {
      return {
        kind: 'answer',
        query: currentGraphQuery,
        fixedView: !!window.fixedView,
        elements: cloneAnswerElements(currentAnswerGraphElements),
        expandModeEnabled,
        relationMinPmids,
      };
    }

    return {
      kind: 'query',
      query: currentGraphQuery,
      queryType: currentGraphQueryType,
      classQuery: currentGraphClassQuery,
      fixedView: !!window.fixedView,
      expandModeEnabled,
      relationMinPmids,
    };
  }

  function pushCurrentStateToHistory() {
    const snapshot = captureCurrentGraphState();
    const nextSignature = stateSignature(snapshot);
    const lastSignature = graphHistory.length ? stateSignature(graphHistory[graphHistory.length - 1]) : '';
    if (nextSignature === lastSignature) return;
    graphHistory.push(snapshot);
  }

  function describeHistoryState(state) {
    if (!state || typeof state !== 'object') return textSet().back || 'Back';
    if (state.kind === 'tree') {
      return textSet().backToTree || textSet().back || 'Back';
    }
    if (state.kind === 'taxonomy_graph') {
      return 'Back to taxonomy graph';
    }
    if (state.kind === 'disease_class_tree') {
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

  function getNextTreeVariantKey() {
    const variants = getTreeVariants();
    if (!variants.length) return currentTreeVariant;
    const currentIndex = Math.max(0, variants.findIndex((item) => item.key === currentTreeVariant));
    return variants[(currentIndex + 1) % variants.length].key;
  }

  function getCurrentTaxonomyDisplayMode() {
    return currentTaxonomyDisplayMode === 'graph' ? 'graph' : 'tree';
  }

  function getNextTaxonomyDisplayMode() {
    return getCurrentTaxonomyDisplayMode() === 'tree' ? 'graph' : 'tree';
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
    lines.push('<br>Click a TE node to inspect it, then click again to enter the dynamic graph.');
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
    if (els.taxonomySourceText) {
      const label = currentTreeVariant === 'all'
        ? 'RMSK+Repbase'
        : 'All';
      els.taxonomySourceText.textContent = typeof t.taxonomySource === 'function'
        ? t.taxonomySource(label)
        : `Switch taxonomy: ${label}`;
    }
    if (els.taxonomySourceBtn) {
      els.taxonomySourceBtn.setAttribute('aria-pressed', 'false');
      els.taxonomySourceBtn.classList.remove('is-active');
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
    loadDynamicGraph({
      query: els.searchInput.value,
      queryType: selectedGraphSearchType(),
    }).catch((error) => {
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
    syncExpressionLayerButtonState();
    const canExportGraph = currentMode === 'dynamic' && !graphIsLoading && !!dynamicFrame;
    if (els.exportMenuToggle) els.exportMenuToggle.disabled = !canExportGraph;
    if (els.exportMenuCsv) els.exportMenuCsv.disabled = !canExportGraph;
    if (els.exportMenuPng) els.exportMenuPng.disabled = !canExportGraph;
    if (!canExportGraph) closeExportMenu();
    updateBackButton();
  }

  function showTreeSurface() {
    if (els.treeSurface) els.treeSurface.style.display = 'block';
    if (els.dynamicSurface) els.dynamicSurface.style.display = 'none';
    syncLegendVisibility('tree');
  }

  function showDynamicSurface() {
    if (els.treeSurface) els.treeSurface.style.display = 'none';
    if (els.dynamicSurface) els.dynamicSurface.style.display = 'block';
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
    if (currentSrc !== nextSrc) {
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
      await pushExpressionOverlayToBridge(filterElementsForLegend(currentAnswerGraphElements));

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
        await loadDynamicGraph(query);
        return true;
      },
    };
  }

  function buildDiseaseClassTreeConfig(classQuery) {
    const truncateDiseaseTreeLabel = (label, data) => {
      const text = String(label || '').trim();
      if (!text) return text;
      if (String(data?.nodeType || '') === 'Disease') return text;
      const depth = Number(data?.treeDepth || 0);
      const limitsByDepth = { 0: 12, 1: 18, 2: 22, 3: 26, 4: 30 };
      const limit = limitsByDepth[depth] || 32;
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
        await loadDynamicGraph(query);
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
      return renderDefaultTree({ pushHistory: false, variant: state.treeVariant || currentTreeVariant });
    }

    if (state.kind === 'taxonomy_graph') {
      return renderTaxonomyGraph({ pushHistory: false, variant: state.treeVariant || currentTreeVariant });
    }

    if (state.kind === 'disease_class_tree') {
      return renderDiseaseClassTree({
        query: state.classQuery || state.query,
        queryType: 'disease_class',
        classQuery: state.classQuery || state.query,
      }, { pushHistory: false });
    }

    if (state.kind === 'query') {
      window.fixedView = !!state.fixedView;
      expandModeEnabled = !!state.expandModeEnabled;
      relationMinPmids = Math.max(0, Number(state.relationMinPmids) || 0);
      if (els.relationMinPmidsInput) els.relationMinPmidsInput.value = String(relationMinPmids);
      updateButtons();
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
    return restoreGraphState(previousState);
  }

  async function cycleTreeVariant() {
    const nextKey = getNextTreeVariantKey();
    if (!nextKey || nextKey === currentTreeVariant) return false;
    return renderCurrentTaxonomyView({ pushHistory: false, variant: nextKey });
  }

  async function toggleTaxonomyDisplayMode() {
    currentTaxonomyDisplayMode = getNextTaxonomyDisplayMode();
    return renderCurrentTaxonomyView({ pushHistory: false, variant: currentTreeVariant });
  }

  async function toggleTaxonomySource() {
    const nextKey = getNextTreeVariantKey();
    if (!nextKey || nextKey === currentTreeVariant) return false;
    return renderCurrentTaxonomyView({ pushHistory: false, variant: nextKey });
  }

  async function renderCurrentTaxonomyView(options = {}) {
    return getCurrentTaxonomyDisplayMode() === 'graph'
      ? renderTaxonomyGraph(options)
      : renderDefaultTree(options);
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
    notifyStateChange();
  }

  async function renderTaxonomyGraph(options = {}) {
    const requestedVariant = normalizeTreeVariantKey(options && options.variant ? options.variant : currentTreeVariant);
    currentTreeVariant = requestedVariant;
    currentTaxonomyDisplayMode = 'graph';
    window.__TEKG_TREE_VARIANT = currentTreeVariant;
    if (options.pushHistory === true && currentMode !== 'taxonomy_graph') {
      pushCurrentStateToHistory();
    }
    currentMode = 'taxonomy_graph';
    currentGraphSource = 'taxonomy_graph';
    currentGraphQuery = '';
    currentGraphQueryType = '';
    currentGraphClassQuery = '';
    currentSelectedNode = null;
    currentAnswerGraphElements = [];
    currentQueryGraphElements = [];
    showTreeSurface();
    updateButtons();
    setGraphLoading(true, 'Preparing TE taxonomy graph ...');

    try {
      const renderer = window.__TEKG_G6_DEFAULT_TREE;
      if (!renderer || typeof renderer.renderGraph !== 'function') {
        throw new Error('TE taxonomy graph renderer is unavailable.');
      }
      await renderer.renderGraph({
        variant: currentTreeVariant,
        visibleTaxonomyLevels: ensureTaxonomyLegendState(),
        taxonomyLevelFocus: activeLegendHighlight && activeLegendHighlight.kind === 'taxonomy'
          ? activeLegendHighlight.value
          : null,
        onJumpUnavailable(node) {
          const label = String(node?.data?.rawLabel || node?.data?.label || node?.id || 'this taxonomy node');
          setDetail(`<strong>${escapeHtml(label)}</strong><br>This is a taxonomy-only node in the current TE taxonomy graph. It has not been verified as a Neo4j graph entity in this first version.`);
        },
        onJump(node) {
          const label = String(node?.data?.queryLabel || node?.data?.rawLabel || node?.data?.label || '').trim();
          if (!label) return Promise.resolve(false);
          return loadDynamicGraph({ query: label, queryType: 'TE' }, { pushHistory: true });
        },
      });
      ensureTaxonomyLegendState();
      clearLegendFilterPending();
      renderGraphLegend();
    } finally {
      setGraphLoading(false);
    }

    setDetail(buildTreeVariantDetailHtml().replace(
      'Click a TE node to inspect it, then click again to enter the dynamic graph.',
      'Click a taxonomy node to inspect its classification position. Dashed borders mark taxonomy-only nodes in this first version.'
    ));
    notifyStateChange();
  }

  async function renderDiseaseClassTree(requestLike, options = {}) {
    const request = normalizeGraphRequest({
      ...(requestLike && typeof requestLike === 'object' ? requestLike : {}),
      queryType: 'disease_class',
    });
    const classQuery = String(request.classQuery || request.query || '').trim();
    if (!classQuery) {
      return renderDefaultTree(options);
    }

    if (options.pushHistory !== false) {
      pushCurrentStateToHistory();
    }

    currentMode = 'disease_class_tree';
    currentGraphSource = 'disease_class_tree';
    currentGraphQuery = classQuery;
    currentGraphQueryType = 'disease_class';
    currentGraphClassQuery = classQuery;
    currentSelectedNode = null;
    currentAnswerGraphElements = [];
    currentQueryGraphElements = [];
    showTreeSurface();
    if (els.searchInput) els.searchInput.value = classQuery;
    updateButtons();
    setDetail(buildLoadingDetailHtml(`Preparing the disease classification tree for ${escapeHtml(classQuery)}.`));
    notifyStateChange();
    setGraphLoading(true, textSet().loadingOverlay(classQuery));

    try {
      const payload = await fetchDiseaseClassPayload(request);
      currentQueryGraphElements = cloneAnswerElements(Array.isArray(payload && payload.elements) ? payload.elements : []);
      const model = buildDiseaseClassTreeModel(currentQueryGraphElements, classQuery);
      if (!model.rootId || !model.treeData) {
        throw new Error('Disease class tree data is unavailable.');
      }
      if (!window.__TEKG_G6_DEFAULT_TREE || typeof window.__TEKG_G6_DEFAULT_TREE.renderStructuredTree !== 'function') {
        throw new Error('Structured tree renderer is unavailable.');
      }
      await window.__TEKG_G6_DEFAULT_TREE.renderStructuredTree({
        rootId: model.rootId,
        treeData: model.treeData,
        expandAll: true,
        config: buildDiseaseClassTreeConfig(classQuery),
      });
      notifyStateChange();
      return true;
    } finally {
      setGraphLoading(false);
    }
  }

  async function loadDynamicGraph(requestLike, options = {}) {
    const request = normalizeGraphRequest(requestLike);
    const q = String(request.query || '').trim();
    if (!q) {
      await renderDefaultTree(options);
      return null;
    }

    if (options.pushHistory !== false) {
      pushCurrentStateToHistory();
    }

    currentMode = 'dynamic';
    currentGraphSource = 'query';
    currentGraphQuery = q;
    currentGraphQueryType = request.queryType || '';
    currentGraphClassQuery = currentGraphQueryType === 'disease_class' ? String(request.classQuery || q).trim() : '';
    syncGraphSearchType(currentGraphQueryType);
    currentSelectedNode = null;
    currentAnswerGraphElements = [];
    currentQueryGraphElements = [];
    currentRelationLegendMeta = [];
    rawRelationLegendMeta = [];
    relationLegendState = {};
    activeLegendHighlight = null;
    expandedNodeKeys = new Set();
    showDynamicSurface();
    if (els.searchInput) els.searchInput.value = q;
    updateButtons();
    setDetail(textSet().loadingDetail(q));
    notifyStateChange();
    const loaderKind = getTeLoaderKind(q);
    const loaderLabel = loaderKind === 'default'
      ? textSet().loadingOverlay(q)
      : `Loading ${escapeHtml(q)} network`;
    setGraphLoading(true, loaderLabel, {
      kind: loaderKind,
      label: q,
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
      if (typeof bridge.setLegendFocus === 'function') {
        await bridge.setLegendFocus(null);
      }

      const payload = await bridge.loadGraph(request, {
        graphDataOptions: buildCurrentGraphDataOptions(),
      });
      currentQueryGraphElements = cloneAnswerElements(Array.isArray(payload && payload.elements) ? payload.elements : []);
      rawRelationLegendMeta = collectRelationLegendMetaFromElements(currentQueryGraphElements);
      currentRelationLegendMeta = mergeRelationLegendMeta([], Array.isArray(payload && payload.relationLegendMeta) ? payload.relationLegendMeta : []);
      ensureRelationLegendState();
      renderGraphLegend();
      if (
        Object.values(getVisibleTypePayload()).some((isVisible) => isVisible === false) ||
        Object.values(getVisibleRelationPayload()).some((isVisible) => isVisible === false) ||
        relationMinPmids > 0
      ) {
        await renderDynamicElementsFromCache(currentQueryGraphElements, {
          source: 'query',
          request,
        });
      } else {
        await pushExpressionOverlayToBridge(filterElementsForLegend(currentQueryGraphElements));
        notifyStateChange();
      }
      return true;
    } finally {
      setGraphLoading(false);
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
      await pushExpressionOverlayToBridge(filterElementsForLegend(currentQueryGraphElements));
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

  async function getVisibleSubgraphForExport() {
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
    const bridge = await getDynamicEmbedBridge();
    if (!bridge || typeof bridge.exportPngDataUrl !== 'function') {
      throw new Error('G6 PNG export bridge is not available');
    }
    const dataUrl = await bridge.exportPngDataUrl();
    if (!String(dataUrl || '').startsWith('data:image/png;base64,')) {
      throw new Error('G6 PNG export did not return a PNG data URL');
    }
    const payload = {
      query: currentGraphQuery || 'graph',
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
        if (currentMode === 'taxonomy_graph' && taxonomyLevel) {
          ensureTaxonomyLegendState()[taxonomyLevel] = target.checked;
        } else if (activeLegendMode === 'relation' && relationType) {
          ensureRelationLegendState()[relationType] = target.checked;
          persistVisibleTypeState();
        } else if (type) {
          applyEntityLegendCheckState(type, target.checked);
          persistVisibleTypeState();
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
        const nextValue = Math.max(0, Number.parseInt(els.relationMinPmidsInput.value || '0', 10) || 0);
        relationMinPmids = nextValue;
        updateButtons();
        markLegendFilterPending();
      });
    }

    if (els.expressionLayerButton) {
      els.expressionLayerButton.addEventListener('click', () => {
        expressionLayerContext = getNextExpressionLayerContext(expressionLayerContext);
        updateButtons();
        pushExpressionOverlayToBridge().catch((error) => {
          console.warn('Expression layer update failed:', error);
        });
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
        renderCurrentTaxonomyView({ pushHistory: true }).catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });
    }

    if (els.taxonomyDisplayBtn) {
      els.taxonomyDisplayBtn.addEventListener('click', () => {
        toggleTaxonomyDisplayMode().catch((error) => {
          setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        });
      });
    }

    if (els.taxonomySourceBtn) {
      els.taxonomySourceBtn.addEventListener('click', () => {
        toggleTaxonomySource().catch((error) => {
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

        const hasGraphState = currentMode === 'dynamic' && !!String(currentGraphQuery || '').trim();
        const queryChanged =
          String(currentGraphQuery || '').trim() !== nextQuery ||
          String(currentGraphQueryType || '') !== nextQueryType ||
          String(currentGraphClassQuery || '') !== nextClassQuery ||
          String(currentGraphSource || '') !== nextSource;

        if (hasGraphState && queryChanged) {
          pushCurrentStateToHistory();
          updateBackButton();
        }

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
      return renderDiseaseClassTree({
        query: classQuery,
        queryType: 'disease_class',
        classQuery,
      }, { pushHistory: true }).then(() => true);
    },
    onNodeExpand(node) {
      return expandSelectedNode(node).catch((error) => {
        setDetail(`<strong>${textSet().graphError(error && error.message)}</strong>`);
        return false;
      });
    },
  };

  window.__TEKG_LOAD_DYNAMIC_GRAPH = loadDynamicGraph;
  window.__TEKG_G6_SHOW_TREE = renderDefaultTree;
  window.__TEKG_G6_EXPORT = {
    getVisibleSubgraph: getVisibleSubgraphForExport,
    exportCsv: exportVisibleCsv,
    exportPng: exportCanvasPng,
  };
  window.__TEKG_G6_BRIDGE = {
    loadGraph(query) {
      return loadDynamicGraph(query);
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
    showTree() {
      return renderDefaultTree();
    },
    showDiseaseClassTree(requestLike) {
      return renderDiseaseClassTree(requestLike);
    },
    reset() {
      return renderCurrentTaxonomyView();
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
    getVisibleTypes() {
      return getVisibleTypePayload();
    },
    setVisibleTypes(nextState) {
      if (!nextState || typeof nextState !== 'object') return getVisibleTypePayload();
      visibleTypeState = { ...ensureVisibleTypeState(), ...nextState };
      persistVisibleTypeState();
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

      if (initialQuery) {
        return loadSharedResources().then(() => loadDynamicGraph({
          query: initialQuery,
          queryType: initialQueryType,
          classQuery: initialClassQuery || initialQuery,
        }, { pushHistory: false }));
      }

      renderCurrentTaxonomyView().catch((error) => {
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
