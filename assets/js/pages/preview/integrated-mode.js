(function () {
  'use strict';
  const paths = window.__TEKG_PATHS;
  const contract = window.__TEKG_INTEGRATED_CONTRACT;
  const coexpressionContract = window.__TEKG_COEXPRESSION_CONTRACT;
  const coexpressionAdapter = window.__TEKG_COEXPRESSION_DYNAMIC_ADAPTER;
  const workspace = document.getElementById('previewIntegratedWorkspace');
  if (!paths || !contract || !coexpressionContract || !coexpressionAdapter || !workspace) return;
  const PREFERRED_CONTEXT = 'normal_tissue';
  const els = {
    type: document.getElementById('integrated-search-type'),
    input: document.getElementById('integrated-search-input'),
    submit: document.getElementById('integrated-search-submit'),
    context: document.getElementById('integrated-context-select'),
    expression: document.getElementById('integrated-expression-layer'),
    labels: document.getElementById('integrated-edge-labels'),
    exportToggle: document.getElementById('integrated-export-menu-toggle'),
    exportMenu: document.getElementById('integrated-export-menu'),
    exportCsv: document.getElementById('integrated-export-csv'),
    exportPng: document.getElementById('integrated-export-png'),
    exportSvg: document.getElementById('integrated-export-svg'),
    host: document.getElementById('integrated-iframe-host'),
    preloader: document.getElementById('integrated-preloader'),
    state: document.getElementById('integrated-state'),
    legend: document.getElementById('integrated-legend'),
    legendTabs: Array.from(document.querySelectorAll('[data-integrated-legend-mode]')),
    legendList: document.getElementById('integrated-legend-list'),
    relationControls: document.getElementById('integrated-relation-controls'),
    minPmids: document.getElementById('integrated-min-pmids'),
    edgeScopeControl: document.getElementById('integrated-edge-scope-control'),
    edgeScope: document.getElementById('integrated-edge-scope'),
    detail: document.getElementById('integrated-node-details'),
  };
  let frame = null;
  let bridgePromise = null;
  let catalogPromise = null;
  let catalog = null;
  const resultCache = new Map();
  const RESULT_CACHE_LIMIT = 6;
  let epoch = 0;
  let visible = false;
  let selection = null;
  let stableSelection = null;
  let masterElements = [];
  let knowledgeElements = [];
  let teGeneElements = [];
  let sourceAvailability = { knowledge: false, te_gene: false };
  let requestCounts = { knowledge: 0, teGene: 0, catalog: 0 };
  let expressionEnabled = true;
  let showLabels = false;
  let activeLegendMode = 'entity';
  let visibleTypes = {};
  let visibleRelations = {};
  let renderCount = 0;
  let frameIdentity = 0;
  let activeLegendFocus = null;
  let currentExpressionOverlay = { enabled: false, context: 'off', records: {}, min_value: 0, max_value: 0 };

  function cacheKey(feature, featureType, context) {
    return [normalizeType(featureType), String(feature || '').trim().toLocaleLowerCase('en-US'), String(context || '')].join('\u0000');
  }
  function readCachedResult(key) {
    if (!resultCache.has(key)) return null;
    const value = resultCache.get(key);
    resultCache.delete(key);
    resultCache.set(key, value);
    return value;
  }
  function writeCachedResult(key, value) {
    resultCache.delete(key);
    resultCache.set(key, value);
    while (resultCache.size > RESULT_CACHE_LIMIT) resultCache.delete(resultCache.keys().next().value);
  }

  function normalizeType(value) {
    const wanted = String(value || '').trim();
    return wanted.toLowerCase() === 'gene' ? 'Gene' : (wanted || 'TE');
  }
  function setToggle(button, active, label) {
    button.classList.toggle('is-active', active);
    button.setAttribute('aria-pressed', active ? 'true' : 'false');
    button.textContent = label + ': ' + (active ? 'On' : 'Off');
  }
  function setLoading(loading, message) {
    els.preloader.classList.toggle('is-active', loading);
    els.preloader.setAttribute('aria-hidden', loading ? 'false' : 'true');
    if (loading) {
      els.state.hidden = true;
      els.detail.textContent = message || 'Combining graph sources...';
    }
  }
  function setState(message, fatal) {
    els.state.textContent = message || '';
    els.state.hidden = !message;
    els.state.classList.toggle('is-error', fatal === true);
  }
  function fetchJson(url, signal) {
    return fetch(url, { headers: { Accept: 'application/json' }, signal: signal }).then(function (response) {
      return response.json().catch(function () { return null; }).then(function (payload) {
        if (!response.ok || !payload || payload.ok === false) {
          throw new Error((payload && payload.error && (payload.error.message || payload.error)) || 'Graph request failed.');
        }
        return payload;
      });
    });
  }
  function ensureFrame() {
    if (frame) return frame;
    frame = document.createElement('iframe');
    frame.id = 'integrated-graph-frame';
    frame.title = 'Integrated Graph';
    frame.setAttribute('scrolling', 'no');
    const url = new URL(paths.assetsUrl('html/preview_integrated_embed.html'), window.location.origin);
    const version = String(window.__TEKG_PREVIEW_VERSION || '');
    if (version) url.searchParams.set('v', version);
    frame.src = url.toString();
    els.host.replaceChildren(frame);
    frameIdentity += 1;
    return frame;
  }
  function waitForBridge() {
    if (bridgePromise) return bridgePromise;
    ensureFrame();
    bridgePromise = new Promise(function (resolve, reject) {
      let attempts = 0;
      function check() {
        attempts += 1;
        try {
          const bridge = frame.contentWindow && frame.contentWindow.__TEKG_INTEGRATED_EMBED;
          if (bridge && typeof bridge.renderElements === 'function') { resolve(bridge); return; }
        } catch (_error) {}
        if (attempts >= 100) { reject(new Error('Integrated Graph iframe is unavailable.')); return; }
        window.setTimeout(check, 50);
      }
      check();
    }).catch(function (error) { bridgePromise = null; throw error; });
    return bridgePromise;
  }
  function loadCatalog(signal) {
    if (catalog) return Promise.resolve(catalog);
    if (catalogPromise) return catalogPromise;
    requestCounts.catalog += 1;
    catalogPromise = fetchJson(paths.apiUrl('coexpression.php') + '?action=catalog', signal)
      .then(function (payload) { catalog = coexpressionContract.normalizeCatalog(payload); return catalog; })
      .catch(function (error) { catalogPromise = null; throw error; });
    return catalogPromise;
  }
  function catalogItem(feature, featureType) {
    if (!catalog) return null;
    const type = normalizeType(featureType);
    const key = type === 'Gene' ? 'gene' : 'te';
    const items = type === 'Gene' ? catalog.geneItems : catalog.items;
    const wanted = String(feature || '').trim().toLowerCase();
    return items.find(function (item) { return String(item[key] || '').toLowerCase() === wanted; }) || null;
  }
  async function defaultSelection() {
    const nextCatalog = await loadCatalog();
    const fallback = nextCatalog.defaultSelection || {};
    const feature = String(fallback.feature || fallback.te || '').trim();
    return feature ? { feature: feature, featureType: 'TE', context: PREFERRED_CONTEXT } : null;
  }
  function itemName(item, featureType) {
    return String(normalizeType(featureType) === 'Gene' ? item && item.gene : item && item.te).trim();
  }
  function populateContexts(item, preferred) {
    const available = new Set(item && item.availableContexts || []);
    els.context.replaceChildren();
    if (!item || !available.size) {
      const option = document.createElement('option'); option.value = ''; option.textContent = 'No expression dataset'; els.context.appendChild(option);
      els.context.disabled = true; return '';
    }
    catalog.contexts.forEach(function (context) {
      if (!available.has(context.id)) return;
      const option = document.createElement('option'); option.value = context.id; option.textContent = context.label; els.context.appendChild(option);
    });
    const next = available.has(preferred) ? preferred : (available.has(PREFERRED_CONTEXT) ? PREFERRED_CONTEXT : Array.from(available)[0]);
    els.context.value = next; els.context.disabled = false; return next;
  }
  function graphUrl(feature, featureType) {
    const url = new URL(paths.apiUrl('graph.php'), window.location.origin);
    url.searchParams.set('q', feature); url.searchParams.set('type', featureType); return url.toString();
  }
  function coexpressionUrl(feature, featureType, context) {
    const url = new URL(paths.apiUrl('coexpression.php'), window.location.origin);
    url.searchParams.set('action', 'network'); url.searchParams.set('feature_type', featureType);
    url.searchParams.set(featureType === 'Gene' ? 'gene' : 'te', feature);
    url.searchParams.set('context', context); return url.toString();
  }
  function pathFinderEntitiesUrl(featureType, query) {
    const url = new URL(paths.apiUrl('path_finder.php'), window.location.origin);
    url.searchParams.set('view', 'entities');
    url.searchParams.set('type', normalizeType(featureType));
    url.searchParams.set('q', String(query || '').trim());
    url.searchParams.set('limit', '180');
    return url.toString();
  }
  function integratedEntityOptions(featureType, query) {
    const type = normalizeType(featureType);
    const wanted = String(query || '').trim().toLocaleLowerCase('en-US');
    const knowledge = fetchJson(pathFinderEntitiesUrl(type, query)).then(function (payload) {
      return (payload.items || []).map(function (item) { return { name: String(item.name || item.label || '').trim() }; });
    }).catch(function () { return []; });
    const teGene = (type === 'TE' || type === 'Gene')
      ? loadCatalog().then(function (nextCatalog) {
        const items = type === 'Gene' ? nextCatalog.geneItems : nextCatalog.items;
        const key = type === 'Gene' ? 'gene' : 'te';
        return items.map(function (item) { return { name: String(item[key] || '').trim() }; });
      }).catch(function () { return []; })
      : Promise.resolve([]);
    return Promise.all([knowledge, teGene]).then(function (groups) {
      const unique = new Map();
      groups.flat().forEach(function (item) {
        const name = String(item.name || '').trim();
        const key = name.toLocaleLowerCase('en-US');
        if (name && (!wanted || key.includes(wanted)) && !unique.has(key)) unique.set(key, { name: name });
      });
      return Array.from(unique.values()).sort(function (left, right) {
        return left.name.localeCompare(right.name, undefined, { sensitivity: 'base' });
      }).slice(0, 180);
    });
  }
  let currentLegendModel = null;

  function setLegendMode(mode) {
    activeLegendMode = mode === 'relation' ? 'relation' : 'entity';
    const relationMode = activeLegendMode === 'relation';
    els.legendTabs.forEach(function (tab) {
      const active = tab.dataset.integratedLegendMode === activeLegendMode;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    els.relationControls.hidden = !relationMode;
    els.edgeScopeControl.hidden = !relationMode || !sourceAvailability.te_gene;
    renderLegendList();
  }

  function renderLegend() {
    const entityTypes = new Map();
    const relations = new Map();
    const typeMeta = window.__TEKG_G6_TYPE_META || {};
    const typeColors = typeMeta.colors || {};
    const typeLabels = typeMeta.labels || {};
    const typeOrder = Array.isArray(typeMeta.legendOrder) ? typeMeta.legendOrder : [];
    const fallbackEntityColors = ['#4e79ff', '#ff7a7a', '#41b883', '#8a7cf8', '#59bfb6', '#72b6ff', '#ffb066', '#a98cf6'];
  
    masterElements.forEach(function (element) {
      const data = element.data || {};
      if (data.source && data.target) {
        const relationType = String(data.relationType || '').trim();
        const relation = String(data.relation || data.relationKey || relationType || 'RELATION').trim();
        const classification = /CLASSIFIED_AS|HAS_SUBCATEGORY|TOP_CLASS_RELATION|DISEASE_CLASSIFICATION/i.test(relationType);
        if (classification) {
          visibleRelations[relation] = false;
          return;
        }
        relations.set(relation, (relations.get(relation) || 0) + 1);
        if (!(relation in visibleRelations)) visibleRelations[relation] = true;
        return;
      }
      const rawType = String(data.type || 'TE');
      const type = rawType === 'DiseaseClass' || rawType === 'DiseaseCategory' ? 'Disease' : rawType;
      const sources = data.integrated && Array.isArray(data.integrated.sources)
        ? data.integrated.sources
        : [data.sourceGraph || 'knowledge'];
      const record = entityTypes.get(type) || { count: 0, sources: new Set() };
      record.count += 1;
      sources.forEach(function (source) { record.sources.add(source); });
      entityTypes.set(type, record);
      if (!(rawType in visibleTypes)) visibleTypes[rawType] = true;
    });
  
    currentLegendModel = { entityTypes: entityTypes, relations: relations, typeColors: typeColors, typeLabels: typeLabels, typeOrder: typeOrder, fallbackEntityColors: fallbackEntityColors };
    renderLegendList();
    els.legend.hidden = masterElements.length === 0;
  }

  function renderLegendList() {
    if (!currentLegendModel) return;
    const model = currentLegendModel;
    function html(value) {
      return String(value || '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
    }
    function hashString(input) {
      let hash = 0;
      const text = String(input || '');
      for (let index = 0; index < text.length; index += 1) hash = ((hash << 5) - hash + text.charCodeAt(index)) | 0;
      return Math.abs(hash);
    }
    const relationColors = ['#2563eb', '#dc2626', '#059669', '#7c3aed', '#ea580c', '#0891b2', '#be123c', '#4f46e5', '#65a30d', '#9333ea'];
    function relationStyle(relation) {
      const fixed = {
        'Co-expression': { color: '#2563eb', dashed: false },
        eQTL: { color: '#d97706', dashed: false },
        Both: { color: '#0f766e', dashed: false },
      };
      return fixed[relation] || {
        color: relationColors[hashString(relation) % relationColors.length],
        dashed: /CLASSIFICATION|CATEGORY|TAXONOMY|SYNTHETIC/i.test(relation),
      };
    }
    if (activeLegendMode === 'relation') {
      const teGeneOrder = ['Co-expression', 'eQTL', 'Both'];
      const relationNames = Array.from(model.relations.keys()).sort(function (left, right) {
        const li = teGeneOrder.indexOf(left);
        const ri = teGeneOrder.indexOf(right);
        if (li >= 0 || ri >= 0) return (li >= 0 ? 100 + li : 0) - (ri >= 0 ? 100 + ri : 0);
        return left.localeCompare(right);
      });
      const rows = relationNames.map(function (relation, index) {
        const style = relationStyle(relation);
        const checked = visibleRelations[relation] !== false ? ' checked' : '';
        const active = activeLegendFocus && activeLegendFocus.kind === 'relation' && activeLegendFocus.value === relation
          ? ' is-highlight-active'
          : '';
        const previous = index > 0 ? relationNames[index - 1] : '';
        const divider = teGeneOrder.includes(relation) && !teGeneOrder.includes(previous) && index > 0
          ? '<div class="coexpression-legend-divider" aria-hidden="true"></div>'
          : '';
        return divider + '<div class="graph-legend-item' + active + '" data-highlight-kind="relation" data-highlight-value="' + html(relation) + '"><input class="graph-legend-check" type="checkbox" data-integrated-relation="' + html(relation) + '" aria-label="' + html(relation) + '"' + checked + '><span class="graph-relation-line' + (style.dashed ? ' is-dashed' : '') + '" style="--legend-color:' + style.color + ';"></span><span class="graph-legend-text">' + html(relation) + '</span></div>';
      });
      els.legendList.innerHTML = rows.length ? rows.join('') : '<div class="graph-legend-empty">No relation types in the current graph.</div>';
      return;
    }
  
    const orderedTypes = Array.from(model.entityTypes.keys()).sort(function (left, right) {
      const li = model.typeOrder.indexOf(left); const ri = model.typeOrder.indexOf(right);
      if (li >= 0 || ri >= 0) return (li >= 0 ? li : 999) - (ri >= 0 ? ri : 999);
      return left.localeCompare(right);
    });
    const rows = orderedTypes.map(function (type, index) {
      const record = model.entityTypes.get(type);
      const teGeneStyle = record.sources.has('te_gene');
      const specialClass = teGeneStyle && type === 'TE'
        ? ' coexpression-legend-te'
        : (teGeneStyle && type === 'Gene' ? ' coexpression-legend-gene' : '');
      const color = teGeneStyle && type === 'Gene'
        ? '#1aa486'
        : (teGeneStyle && type === 'TE' ? '#3b66c4' : (model.typeColors[type] || model.fallbackEntityColors[index % model.fallbackEntityColors.length]));
      const checked = visibleTypes[type] !== false ? ' checked' : '';
      const active = activeLegendFocus && activeLegendFocus.kind === 'entity' && activeLegendFocus.value === type
        ? ' is-highlight-active'
        : '';
      return '<div class="graph-legend-item' + (type === 'Disease' ? ' is-disease-combined' : '') + active + '" data-highlight-kind="entity" data-highlight-value="' + html(type) + '"><input class="graph-legend-check" type="checkbox" data-integrated-type="' + html(type) + '" aria-label="' + html(model.typeLabels[type] || type) + '"' + checked + '><span class="graph-legend-swatch' + specialClass + '" style="--legend-color:' + html(color) + ';"></span><span class="graph-legend-text">' + html(model.typeLabels[type] || type) + '</span></div>';
    });
    const hasTeGene = Array.from(model.entityTypes.values()).some(function (record) { return record.sources.has('te_gene'); });
    if (hasTeGene) {
      rows.push('<div class="graph-legend-item coexpression-legend-static" data-highlight-kind="module-hub" data-highlight-value="module-hub" tabindex="0" aria-pressed="false"><span class="coexpression-hub-ring" aria-hidden="true"></span><span class="graph-legend-text">Module hub</span></div>');
      rows.push('<div class="graph-legend-item coexpression-legend-static" data-highlight-kind="relative-expression" data-highlight-value="relative-expression" tabindex="0" aria-pressed="false"><span class="coexpression-activity-ring" aria-hidden="true"></span><span class="graph-legend-text">Relative expression activity</span></div>');
    }
    els.legendList.innerHTML = rows.join('');
  }
  async function expressionOverlay(network, signal) {
    const names = Array.from(new Set((network && network.nodes || []).map(function (node) {
      return String(node.label || node.id || '').trim();
    }).filter(Boolean))).slice(0, 80);
    if (!names.length || !selection || !selection.context) {
      return { enabled: false, context: 'off', records: {}, min_value: 0, max_value: 0 };
    }
    const response = await fetch(paths.apiUrl('graph_expression.php'), {
      method: 'POST', signal: signal, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ te_names: names, context: selection.context }),
    });
    const payload = await response.json().catch(function () { return null; });
    if (!response.ok || !payload || payload.ok !== true) return { enabled: false, context: selection.context, records: {}, min_value: 0, max_value: 0 };
    const records = {};
    const values = [];
    (payload.records || []).forEach(function (record) {
      [record.requested_name, record.te_name].forEach(function (name) {
        const key = String(name || '').trim().toLowerCase();
        if (key) records[key] = record;
      });
      const value = Number(record && record[selection.context] && record[selection.context].median_value);
      if (record && record.available === true && Number.isFinite(value)) values.push(value);
    });
    return { enabled: expressionEnabled, context: selection.context, context_label: selection.context.replaceAll('_', ' '),
      records: records, min_value: values.length ? Math.min.apply(null, values) : 0, max_value: values.length ? Math.max.apply(null, values) : 0 };
  }
  async function applyFilters() {
    const bridge = await waitForBridge();
    return bridge.setFilters({ visibleTypes: visibleTypes, visibleRelations: visibleRelations, minPmids: els.minPmids.value, edgeScope: els.edgeScope.value });
  }
  async function setLegendFocus(focus) {
    const normalized = focus && focus.kind && focus.value ? { kind: String(focus.kind), value: String(focus.value) } : null;
    activeLegendFocus = normalized;
    els.legendList.querySelectorAll('[data-highlight-kind][data-highlight-value]').forEach(function (row) {
      const active = normalized && row.dataset.highlightKind === normalized.kind && row.dataset.highlightValue === normalized.value;
      row.classList.toggle('is-highlight-active', !!active);
      row.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    return (await waitForBridge()).setLegendFocus(normalized);
  }
  function applyRouteFilters(routeFilters) {
    const filters = routeFilters || {};
    function applyVisibility(state, parsed) {
      if (!parsed || parsed.present !== true) return;
      Object.keys(state).forEach(function (key) { state[key] = false; });
      if (!parsed.none) parsed.values.forEach(function (key) { if (key in state) state[key] = true; });
    }
    applyVisibility(visibleTypes, filters.nodes);
    applyVisibility(visibleRelations, filters.relations);
    els.minPmids.value = String(Math.max(0, Number.parseInt(filters.minPmids || '0', 10) || 0));
    els.edgeScope.value = filters.edgeScope === 'center' ? 'center' : 'all';
    if (Object.prototype.hasOwnProperty.call(filters, 'expressionEnabled')) {
      expressionEnabled = filters.expressionEnabled !== false;
    }
    setToggle(els.expression, expressionEnabled, 'Expression activity');
  }
  function routeSelection() {
    return selection ? {
      ...selection,
      visibleTypes: { ...visibleTypes },
      visibleRelations: { ...visibleRelations },
      nodeTypes: Object.keys(visibleTypes),
      relationTypes: Object.keys(visibleRelations),
      minPmids: Math.max(0, Number.parseInt(els.minPmids.value || '0', 10) || 0),
      edgeScope: els.edgeScope.value === 'center' ? 'center' : 'all',
      expressionEnabled,
    } : null;
  }
  function syncRoute() {
    const coordinator = window.__TEKG_PREVIEW_WORKSPACE_MODE;
    if (visible && coordinator && selection) coordinator.writeRoute('replace', { mode: 'integrated', selection: routeSelection() });
  }
  async function activate(options) {
    options = options || {};
    visible = true;
    workspace.hidden = false;
    workspace.setAttribute('aria-hidden', 'false');
    const requestEpoch = ++epoch;
    const feature = String(options.feature || options.q || options.query || '').trim();
    const featureType = normalizeType(options.featureType || options.type || 'TE');
    if (!feature) {
      setState('Select an entity to build the integrated graph.', false);
      setLoading(false);
      return null;
    }
    els.type.value = featureType; els.input.value = feature;
    setLoading(true, 'Combining available graph evidence for ' + feature + '...');
    setState('', false);
    const controller = new AbortController();
    const timeout = window.setTimeout(function () { controller.abort(); }, 15000);
    try {
      let item = null; let context = '';
      if (featureType === 'TE' || featureType === 'Gene') {
        try { await loadCatalog(controller.signal); item = catalogItem(feature, featureType); } catch (_error) {}
        context = populateContexts(item, String(options.context || ''));
      } else {
        populateContexts(null, '');
      }
      els.expression.disabled = !context;
      const exactFeature = item ? itemName(item, featureType) : feature;
      const key = cacheKey(exactFeature, featureType, context);
      const cached = readCachedResult(key);
      let network = null;
      if (cached) {
        knowledgeElements = cached.knowledgeElements;
        teGeneElements = cached.teGeneElements;
        network = cached.network;
        sourceAvailability = { ...cached.sourceAvailability };
      } else {
        const requests = [];
        requestCounts.knowledge += 1;
        requests.push(fetchJson(graphUrl(feature, featureType), controller.signal));
        if (item && context) {
          requestCounts.teGene += 1;
          requests.push(fetchJson(coexpressionUrl(exactFeature, featureType, context), controller.signal));
        } else {
          requests.push(Promise.reject(new Error('No TE-Gene data is available for this entity.')));
        }
        const results = await Promise.allSettled(requests);
        if (requestEpoch !== epoch) return null;
        knowledgeElements = results[0].status === 'fulfilled' ? (results[0].value.elements || []) : [];
        teGeneElements = [];
        if (results[1].status === 'fulfilled') {
          network = coexpressionContract.normalizeNetwork(results[1].value);
          teGeneElements = coexpressionAdapter.toGraphElements(network);
        }
        sourceAvailability = { knowledge: knowledgeElements.length > 0, te_gene: teGeneElements.length > 0 };
        writeCachedResult(key, { knowledgeElements: knowledgeElements, teGeneElements: teGeneElements, network: network, sourceAvailability: sourceAvailability });
      }
      els.expression.disabled = !context;
      if (!sourceAvailability.knowledge && !sourceAvailability.te_gene) throw new Error('No graph evidence is available for this entity.');
      els.minPmids.disabled = !sourceAvailability.knowledge;
      els.edgeScope.disabled = !sourceAvailability.te_gene;
      masterElements = contract.mergeSourceElements({ knowledgeElements: knowledgeElements, teGeneElements: teGeneElements, query: { q: feature, type: featureType } });
      selection = { feature: exactFeature, featureType: featureType, context: context };
      stableSelection = { ...selection };
      visibleTypes = {}; visibleRelations = {};
      renderLegend();
      applyRouteFilters(options.routeFilters);
      const bridge = await waitForBridge();
      await bridge.renderElements(masterElements, { query: feature, queryType: featureType }, { showEdgeLabels: showLabels });
      currentExpressionOverlay = network ? await expressionOverlay(network, controller.signal) : { enabled: false, context: 'off', records: {}, min_value: 0, max_value: 0 };
      await bridge.setExpressionOverlay(currentExpressionOverlay);
      await applyFilters();
      renderCount += 1;
      els.exportToggle.disabled = false;
      const missing = [];
      if (!sourceAvailability.knowledge) missing.push('Knowledge Graph');
      if (!sourceAvailability.te_gene) missing.push('TE-Gene Graph');
      setState(missing.length ? missing.join(' and ') + ' data are unavailable for this entity; the available source is shown.' : '', false);
      els.detail.textContent = sourceAvailability.knowledge && sourceAvailability.te_gene
        ? 'Knowledge and TE-Gene evidence are shown together. Select a node or edge for details.'
        : 'The available graph evidence is shown. Select a node or edge for details.';
      return routeSelection();
    } catch (error) {
      if (requestEpoch === epoch) setState(error && error.message ? error.message : 'Unable to load Integrated Graph.', true);
      return null;
    } finally {
      window.clearTimeout(timeout);
      if (requestEpoch === epoch) setLoading(false);
    }
  }
  async function expandKnowledgeNode(node) {
    if (!selection) return false;
    const sourceId = String(node && node.integrated && node.integrated.sourceIds && node.integrated.sourceIds.knowledge || '');
    if (!sourceId) return false;
    const url = new URL(graphUrl(selection.feature, selection.featureType), window.location.origin);
    url.searchParams.set('expand_node_id', sourceId);
    url.searchParams.set('expand_node_type', String(node.nodeType || ''));
    url.searchParams.set('expand_query', String(node.rawLabel || node.displayLabel || selection.feature));
    const payload = await fetchJson(url.toString());
    const byId = new Map();
    knowledgeElements.concat(payload.elements || []).forEach(function (element) {
      const data = element && element.data || {};
      const key = data.source && data.target
        ? 'edge:' + String(data.id || (data.source + ':' + data.target + ':' + (data.relationType || data.relation || '')))
        : 'node:' + String(data.id || '');
      byId.set(key, element);
    });
    knowledgeElements = Array.from(byId.values());
    masterElements = contract.mergeSourceElements({ knowledgeElements: knowledgeElements, teGeneElements: teGeneElements, query: selection });
    renderLegend();
    const bridge = await waitForBridge();
    await bridge.renderElements(masterElements, { query: selection.feature, queryType: selection.featureType }, { showEdgeLabels: showLabels });
    await bridge.setExpressionOverlay(currentExpressionOverlay);
    await applyFilters();
    return true;
  }
  async function deactivate() {
    visible = false; epoch += 1;
    if (frame && frame.contentWindow && frame.contentWindow.__TEKG_INTEGRATED_EMBED) await frame.contentWindow.__TEKG_INTEGRATED_EMBED.setVisible(false);
  }
  async function resume() {
    visible = true;
    if (frame && frame.contentWindow && frame.contentWindow.__TEKG_INTEGRATED_EMBED) await frame.contentWindow.__TEKG_INTEGRATED_EMBED.setVisible(true);
  }
  function csvText() {
    const bridge = frame && frame.contentWindow && frame.contentWindow.__TEKG_INTEGRATED_EMBED;
    const visibleGraph = bridge && typeof bridge.getVisibleSubgraph === 'function' ? bridge.getVisibleSubgraph() : null;
    const rows = [['kind','id','label','type','source_graphs','source','target','relation','source_graph','correlation','fdr','eqtl_supporting_variants','eqtl_tissues','pmid_count']];
    if (visibleGraph && Array.isArray(visibleGraph.nodes) && Array.isArray(visibleGraph.edges)) {
      visibleGraph.nodes.forEach(function (d) {
        rows.push(['node',d.id,d.label || d.rawLabel || '',d.type || '',Array.isArray(d.source_graphs) ? d.source_graphs.join('|') : d.source_graph || '','','','','','','','','','']);
      });
      visibleGraph.edges.forEach(function (d) {
        const eqtl = d.eqtl_evidence || {};
        rows.push(['edge',d.id,'','','',d.source,d.target,d.relation || d.relationType || '',d.source_graph || '',d.correlation ?? '',d.fdr ?? '',eqtl.supporting_variant_count ?? '',eqtl.tissue_count ?? '',d.pmid_count || d.support_pmid_count || 0]);
      });
    } else {
      masterElements.forEach(function (element) {
        const d = element.data || {};
        const eqtl = d.eqtlEvidence || {};
        if (d.source && d.target) rows.push(['edge',d.id,'','','',d.source,d.target,d.relation || d.relationType || '',d.sourceGraph || '',d.correlation ?? '',d.fdr ?? '',eqtl.supporting_variant_count ?? '',eqtl.tissue_count ?? '',Array.isArray(d.pmids) ? d.pmids.length : Number(d.support_pmid_count || 0)]);
        else rows.push(['node',d.id,d.label || d.rawLabel || '',d.type || '',d.integrated && d.integrated.sources ? d.integrated.sources.join('|') : d.sourceGraph || '','','','','','','','','','']);
      });
    }
    return rows.map(function (row) { return row.map(function (value) { return '"' + String(value == null ? '' : value).replaceAll('"','""') + '"'; }).join(','); }).join('\n');
  }
  function download(content, type, name) {
    const url = URL.createObjectURL(new Blob([content], { type: type }));
    const link = document.createElement('a'); link.href = url; link.download = name; link.click(); window.setTimeout(function () { URL.revokeObjectURL(url); }, 0);
  }
  window.__TEKG_INTEGRATED_GRAPH_HOST = {
    setStatus: function () {},
    setDetail: function (payload) { if (payload) els.detail.textContent = [payload.title, payload.description].filter(Boolean).join(' — '); },
    setDetailHtml: function (html) { els.detail.innerHTML = String(html || ''); },
    onSelection: function () {},
    onReady: function () {},
    onNonblank: function () {},
    onError: function (message) { setState(String(message || 'Unable to render Integrated Graph.'), true); },
    onNodeExpand: function (node) { return expandKnowledgeNode(node); },
    onNodeJump: async function (payload) {
      const coordinator = window.__TEKG_PREVIEW_WORKSPACE_MODE;
      if (!coordinator) return false;
      await coordinator.setMode('knowledge', { history: 'push' });
      return coordinator.navigateGraph(payload && payload.request || {}, { history: 'push' });
    },
  };
  window.__TEKG_INTEGRATED_MODE = {
    activate: activate, deactivate: deactivate, resume: resume,
    getDiagnostics: function () {
      const nodes = masterElements.filter(function (element) { return !(element.data && element.data.source); }).length;
      const edges = masterElements.length - nodes;
      return { visible: visible, selection: selection, stableSelection: stableSelection, sourceAvailability: { ...sourceAvailability },
        nodeCount: nodes, edgeCount: edges, renderCount: renderCount, frameIdentity: frameIdentity, requestCounts: { ...requestCounts } };
    },
    getSelection: function () { return selection ? { ...selection } : null; },
    getDefaultSelection: defaultSelection,
    getRouteSelection: routeSelection,
    exportCsvText: csvText,
  };
  els.submit.addEventListener('click', function () {
    const coordinator = window.__TEKG_PREVIEW_WORKSPACE_MODE;
    const next = { feature: els.input.value.trim(), featureType: els.type.value, context: els.context.value };
    if (next.feature && coordinator && coordinator.requestIntegratedSelection) coordinator.requestIntegratedSelection(next, { history: 'push' });
  });
  els.input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); els.submit.click(); } });
  els.type.addEventListener('change', function () { els.input.value = ''; populateContexts(null, ''); });
  els.context.addEventListener('change', function () {
    if (!selection) return;
    window.__TEKG_PREVIEW_WORKSPACE_MODE.requestIntegratedSelection({ ...selection, context: els.context.value }, { history: 'push' });
  });
  els.expression.addEventListener('click', async function () {
    expressionEnabled = !expressionEnabled; setToggle(els.expression, expressionEnabled, 'Expression activity');
    currentExpressionOverlay = { ...currentExpressionOverlay, enabled: expressionEnabled };
    const bridge = await waitForBridge(); await bridge.setExpressionOverlay(currentExpressionOverlay); syncRoute();
  });
  els.labels.addEventListener('click', async function () {
    showLabels = !showLabels; setToggle(els.labels, showLabels, 'Show relations');
    if (stableSelection) {
      const currentRoute = routeSelection();
      await activate({ ...stableSelection, routeFilters: currentRoute ? {
        nodes: { present: true, none: currentRoute.nodeTypes.every(function (key) { return currentRoute.visibleTypes[key] === false; }), values: currentRoute.nodeTypes.filter(function (key) { return currentRoute.visibleTypes[key] !== false; }) },
        relations: { present: true, none: currentRoute.relationTypes.every(function (key) { return currentRoute.visibleRelations[key] === false; }), values: currentRoute.relationTypes.filter(function (key) { return currentRoute.visibleRelations[key] !== false; }) },
        minPmids: currentRoute.minPmids,
        edgeScope: currentRoute.edgeScope,
        expressionEnabled: currentRoute.expressionEnabled,
      } : undefined });
      syncRoute();
    }
  });
  els.legend.addEventListener('change', function (event) {
    const type = event.target.dataset.integratedType;
    const relation = event.target.dataset.integratedRelation;
    if (!type && !relation) return;
    if (type === 'Disease') {
      ['Disease', 'DiseaseClass', 'DiseaseCategory'].forEach(function (diseaseType) {
        if (diseaseType in visibleTypes) visibleTypes[diseaseType] = event.target.checked;
      });
    } else if (type) {
      visibleTypes[type] = event.target.checked;
    }
    if (relation) visibleRelations[relation] = event.target.checked;
    applyFilters();
    syncRoute();
  });
  els.legend.addEventListener('click', function (event) {
    if (event.target.closest('.graph-legend-check, select, button')) return;
    const row = event.target.closest('[data-highlight-kind][data-highlight-value]');
    if (!row) return;
    const focus = { kind: row.dataset.highlightKind, value: row.dataset.highlightValue };
    const active = activeLegendFocus && activeLegendFocus.kind === focus.kind && activeLegendFocus.value === focus.value;
    void setLegendFocus(active ? null : focus);
  });
  els.legend.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const row = event.target.closest('[data-highlight-kind][data-highlight-value]');
    if (!row || event.target.matches('input, select, button')) return;
    event.preventDefault(); row.click();
  });
  els.legendTabs.forEach(function (tab) {
    tab.addEventListener('click', function () { setLegendMode(tab.dataset.integratedLegendMode); });
  });
  els.minPmids.addEventListener('change', function () { applyFilters(); syncRoute(); });
  els.edgeScope.addEventListener('change', function () { applyFilters(); syncRoute(); });
  els.exportToggle.addEventListener('click', function () { els.exportMenu.hidden = !els.exportMenu.hidden; els.exportToggle.setAttribute('aria-expanded', els.exportMenu.hidden ? 'false' : 'true'); });
  els.exportCsv.addEventListener('click', function () { download(csvText(), 'text/csv;charset=utf-8', 'integrated-graph.csv'); els.exportMenu.hidden = true; });
  els.exportPng.addEventListener('click', async function () {
    const dataUrl = await (await waitForBridge()).exportPngDataUrl(); const link = document.createElement('a'); link.href = dataUrl; link.download = 'integrated-graph.png'; link.click();
  });
  els.exportSvg.addEventListener('click', async function () { download(await (await waitForBridge()).exportSvgString(), 'image/svg+xml', 'integrated-graph.svg'); });
  setToggle(els.expression, true, 'Expression activity');
  setToggle(els.labels, false, 'Show relations');
  setLegendMode(activeLegendMode);
  window.TEKGTeAutocomplete?.registerSource?.('integrated-entities', {
    label: 'Integrated graph entity',
    queryAware: true,
    loadOptions: function (request) { return integratedEntityOptions(request.entityType, request.query); },
  });
}());
