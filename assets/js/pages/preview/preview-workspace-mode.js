(() => {
  'use strict';

  const knowledgeWorkspace = document.getElementById('previewGraphWorkspace');
  const coexpressionWorkspace = document.getElementById('previewCoexpressionWorkspace');
  const workspaceControl = document.getElementById('previewWorkspaceMode');
  const topControls = workspaceControl?.closest('.preview-top-controls');
  const knowledgeTab = document.getElementById('preview-mode-knowledge');
  const coexpressionTab = document.getElementById('preview-mode-coexpression');
  const taxonomyControl = document.getElementById('previewTaxonomyMode');
  const taxonomyDisplayControl = document.getElementById('previewTaxonomyDisplayMode');
  const taxonomyTreeTab = document.getElementById('preview-taxonomy-display-tree');
  const taxonomyGraphTab = document.getElementById('preview-taxonomy-display-graph');
  const taxonomyAllTab = document.getElementById('preview-taxonomy-all');
  const taxonomyRmskRepbaseTab = document.getElementById('preview-taxonomy-rmsk-repbase');
  const knowledgeToolbar = knowledgeWorkspace?.querySelector('.preview-graph-toolbar');
  const coexpressionToolbar = coexpressionWorkspace?.querySelector('.preview-graph-toolbar');
  const edgeLabelsButton = document.getElementById('toggle-edge-labels');
  const coexpressionContextControl = coexpressionWorkspace?.querySelector('.coexpression-context-control');
  const coexpressionExpressionButton = document.getElementById('coexpression-expression-layer');
  const sharedBack = document.getElementById('back-graph');
  const sharedBackText = document.getElementById('back-text');
  const coexpressionMode = window.__TEKG_COEXPRESSION_MODE;
  if (
    !knowledgeWorkspace || !coexpressionWorkspace || !workspaceControl || !topControls
    || !knowledgeTab || !coexpressionTab || !taxonomyControl || !taxonomyDisplayControl
    || !taxonomyTreeTab || !taxonomyGraphTab || !taxonomyAllTab
    || !taxonomyRmskRepbaseTab || !knowledgeToolbar || !coexpressionToolbar
    || !edgeLabelsButton || !coexpressionContextControl || !coexpressionExpressionButton || !sharedBack
    || !sharedBackText || !coexpressionMode
  ) return;

  let currentMode = 'knowledge';
  let knowledgeGraphMode = String(window.__TEKG_G6_BRIDGE?.getState?.().mode || 'tree');
  let transitionEpoch = 0;
  let switchCount = 0;
  let pendingKnowledgeLoads = 0;
  let knowledgeLoadKey = '';
  let knowledgeLoadPromise = null;
  let retainedKnowledgeState = window.__TEKG_G6_BRIDGE?.getState?.() || {};
  const sharedBackHistory = [];
  let restoringBrowserRoute = false;

  function normalizeBackTarget(selection = {}) {
    const mode = String(selection.mode || '').trim();
    if (selection.kind === 'taxonomy' || mode === 'tree' || mode === 'taxonomy_graph') {
      return {
        kind: 'taxonomy',
        treeVariant: selection.treeVariant === 'all' ? 'all' : 'rmsk_repbase',
        taxonomyDisplayMode: mode === 'taxonomy_graph' || selection.taxonomyDisplayMode === 'graph' ? 'graph' : 'tree',
      };
    }
    const featureType = String(selection.featureType || selection.feature_type || selection.queryType || (selection.gene ? 'Gene' : 'TE')).toLowerCase() === 'gene'
      ? 'Gene'
      : 'TE';
    const feature = String(selection.feature || selection.gene || selection.te || selection.query || '').trim();
    if (!feature) return null;
    return {
      kind: 'entity',
      feature,
      featureType,
      ...(featureType === 'Gene' ? { gene: feature } : { te: feature }),
      context: String(selection.context || '').trim(),
    };
  }

  function sameBackTarget(left, right) {
    const a = normalizeBackTarget(left);
    const b = normalizeBackTarget(right);
    if (!a || !b || a.kind !== b.kind) return false;
    if (a.kind === 'taxonomy') {
      return a.treeVariant === b.treeVariant && a.taxonomyDisplayMode === b.taxonomyDisplayMode;
    }
    return a.featureType === b.featureType && a.feature.toLowerCase() === b.feature.toLowerCase();
  }

  function pushBackTarget(selection, nextSelection = null) {
    const normalized = normalizeBackTarget(selection);
    if (!normalized || (nextSelection && sameBackTarget(normalized, nextSelection))) return false;
    const last = sharedBackHistory[sharedBackHistory.length - 1];
    if (!sameBackTarget(last, normalized)) sharedBackHistory.push(normalized);
    updateSharedBackButton();
    return true;
  }

  function recordKnowledgeTransition(previousState, nextState) {
    if (String(nextState?.mode || '') !== 'dynamic') return false;
    return pushBackTarget(previousState, nextState);
  }

  function updateSharedBackButton() {
    if (currentMode !== 'coexpression') {
      window.__TEKG_G6_BRIDGE?.refreshBackButton?.();
      return;
    }
    const previous = sharedBackHistory[sharedBackHistory.length - 1] || null;
    sharedBack.hidden = !previous;
    sharedBack.disabled = !previous;
    sharedBack.classList.toggle('is-inactive', !previous);
    sharedBackText.textContent = previous
      ? (previous.kind === 'taxonomy' ? 'Back to taxonomy' : `Back to ${previous.feature}`)
      : 'Back';
  }

  function resetSharedBackHistory() {
    sharedBackHistory.length = 0;
    updateSharedBackButton();
  }

  async function goBackEntity() {
    const previous = sharedBackHistory.pop() || null;
    updateSharedBackButton();
    if (!previous) return false;
    if (previous.kind === 'taxonomy') {
      await setMode('knowledge', {
        history: 'push',
        restoreRoute: { treeVariant: previous.treeVariant },
      });
      if (previous.taxonomyDisplayMode === 'graph') {
        await setTaxonomyDisplayMode('graph');
      }
      return true;
    }
    const current = coexpressionMode.getDiagnostics().selection || {};
    await setMode('coexpression', {
      ...previous,
      context: previous.context || current.context || '',
      history: 'push',
      recordEntityHistory: false,
    });
    return true;
  }

  async function loadKnowledgeGraph(request, options = {}) {
    const bridge = window.__TEKG_G6_BRIDGE;
    if (!bridge?.loadGraph) return null;
    const filterKey = options.routeFilters ? JSON.stringify(options.routeFilters) : '';
    const key = `${String(request?.queryType || 'TE').toUpperCase()}\u0000${String(request?.query || '').toLowerCase()}\u0000${filterKey}`;
    if (knowledgeLoadPromise && knowledgeLoadKey === key) return knowledgeLoadPromise;
    pendingKnowledgeLoads += 1;
    knowledgeLoadKey = key;
    const promise = Promise.resolve(bridge.loadGraph(request, options));
    knowledgeLoadPromise = promise;
    try {
      return await promise;
    } finally {
      pendingKnowledgeLoads = Math.max(0, pendingKnowledgeLoads - 1);
      if (knowledgeLoadPromise === promise) {
        knowledgeLoadPromise = null;
        knowledgeLoadKey = '';
      }
    }
  }

  function updateTab(tab, selected) {
    tab.classList.toggle('is-active', selected);
    tab.setAttribute('aria-selected', selected ? 'true' : 'false');
    tab.setAttribute('aria-pressed', selected ? 'true' : 'false');
    tab.tabIndex = selected ? 0 : -1;
  }

  function placeTopControls(mode) {
    if (mode === 'coexpression') {
      coexpressionToolbar.insertBefore(topControls, coexpressionContextControl);
      coexpressionToolbar.insertBefore(sharedBack, coexpressionExpressionButton);
    } else {
      knowledgeToolbar.insertBefore(topControls, edgeLabelsButton);
      topControls.appendChild(sharedBack);
    }
  }

  function syncTopControlVisibility() {
    const showTaxonomySource = currentMode === 'knowledge'
      && (knowledgeGraphMode === 'tree' || knowledgeGraphMode === 'taxonomy_graph');
    const showClassificationDisplay = showTaxonomySource || (currentMode === 'knowledge'
      && (knowledgeGraphMode === 'disease_class_tree' || knowledgeGraphMode === 'disease_class_graph'));
    taxonomyControl.hidden = !showTaxonomySource;
    taxonomyControl.setAttribute('aria-hidden', showTaxonomySource ? 'false' : 'true');
    taxonomyDisplayControl.hidden = !showClassificationDisplay;
    taxonomyDisplayControl.setAttribute('aria-hidden', showClassificationDisplay ? 'false' : 'true');
    workspaceControl.hidden = showClassificationDisplay;
    workspaceControl.setAttribute('aria-hidden', showClassificationDisplay ? 'true' : 'false');
  }

  function setWorkspaceVisibility(mode) {
    const showKnowledge = mode === 'knowledge';
    placeTopControls(mode);
    knowledgeWorkspace.hidden = !showKnowledge;
    knowledgeWorkspace.setAttribute('aria-hidden', showKnowledge ? 'false' : 'true');
    coexpressionWorkspace.hidden = showKnowledge;
    coexpressionWorkspace.setAttribute('aria-hidden', showKnowledge ? 'true' : 'false');
    updateTab(knowledgeTab, showKnowledge);
    updateTab(coexpressionTab, !showKnowledge);
    syncTopControlVisibility();
    updateSharedBackButton();
  }

  function notifyModeChange() {
    window.dispatchEvent(new CustomEvent('tekg:preview-workspace-mode-change', {
      detail: { mode: currentMode },
    }));
    window.dispatchEvent(new CustomEvent('tekg:preview-layout-change'));
  }

  function resizeKnowledgeGraph() {
    try {
      window.__TEKG_G6_BRIDGE?.resize?.();
    } catch (_error) {}
  }

  function clearGraphParams(url) {
    ['mode', 'te', 'gene', 'context', 'q', 'type', 'class', 'tree', 'taxonomy', 'nodes', 'relations', 'min_pmids'].forEach((key) => {
      url.searchParams.delete(key);
    });
    return url;
  }

  function parseAppliedRouteValue(params, key, rawValue = params.get(key)) {
    const present = params.has(key);
    const raw = present ? String(rawValue || '').trim() : '';
    if (!present) return { present: false, none: false, values: [] };
    if (raw.toLowerCase() === 'none') return { present: true, none: true, values: [] };
    return {
      present: true,
      none: false,
      values: [...new Set(raw.split(',').map((value) => value.trim()).filter(Boolean))],
    };
  }

  function parseKnowledgeRoute(params = new URLSearchParams(window.location.search)) {
    const nodes = params.get('nodes');
    const relations = params.get('relations');
    return {
      query: params.get('q') || '',
      queryType: params.get('type') || 'TE',
      classQuery: params.get('class') || '',
      treeVariant: params.get('tree') === 'all' ? 'all' : 'rmsk_repbase',
      taxonomyDisplayMode: params.get('taxonomy') === 'graph' ? 'graph' : 'tree',
      routeFilters: {
        resetDefaults: true,
        nodes: parseAppliedRouteValue(params, 'nodes', nodes),
        relations: parseAppliedRouteValue(params, 'relations', relations),
        minPmids: params.has('min_pmids')
          ? Math.max(0, Number.parseInt(params.get('min_pmids') || '0', 10) || 0)
          : 0,
      },
    };
  }

  function serializeAppliedRouteValue(url, key, catalog, state) {
    const stableCatalog = [...new Set((Array.isArray(catalog) ? catalog : []).map((value) => String(value || '').trim()).filter(Boolean))];
    if (!stableCatalog.length) return;
    const applied = stableCatalog.filter((value) => !state || state[value] !== false);
    if (applied.length === stableCatalog.length) return;
    const serialized = applied.length ? applied.join(',') : 'none';
    if (key === 'nodes') url.searchParams.set('nodes', serialized);
    if (key === 'relations') url.searchParams.set('relations', serialized);
  }

  function routeForKnowledge(state = {}) {
    const url = clearGraphParams(new URL(window.location.href));
    const mode = String(state.mode || 'tree');
    if (mode === 'disease_class_tree' || mode === 'disease_class_graph') {
      const classQuery = String(state.classQuery || state.query || '').trim();
      if (classQuery) {
        url.searchParams.set('q', classQuery);
        url.searchParams.set('type', 'disease_class');
        url.searchParams.set('class', classQuery);
      }
      if (mode === 'disease_class_graph' || state.taxonomyDisplayMode === 'graph') {
        url.searchParams.set('taxonomy', 'graph');
      }
    } else if (mode === 'dynamic') {
      const query = String(state.query || '').trim();
      const queryType = String(state.queryType || 'TE').trim() || 'TE';
      if (query) {
        url.searchParams.set('q', query);
        url.searchParams.set('type', queryType);
      }
      if (queryType === 'disease_class' && state.classQuery) {
        url.searchParams.set('class', String(state.classQuery));
      }
      serializeAppliedRouteValue(url, 'nodes', state.legendTypes, state.visibleTypes);
      serializeAppliedRouteValue(url, 'relations', state.relationTypes, state.visibleRelations);
      const minPmids = Math.max(0, Number.parseInt(state.relationMinPmids || '0', 10) || 0);
      if (minPmids > 0) url.searchParams.set('min_pmids', String(minPmids));
    } else {
      url.searchParams.set('tree', state.treeVariant === 'all' ? 'all' : 'rmsk_repbase');
      if (state.taxonomyDisplayMode === 'graph' || mode === 'taxonomy_graph') {
        url.searchParams.set('taxonomy', 'graph');
      }
    }
    return url;
  }

  function routeForCoexpression(selection = {}) {
    selection = selection || {};
    const url = clearGraphParams(new URL(window.location.href));
    url.searchParams.set('mode', 'coexpression');
    const featureType = String(selection.featureType || selection.feature_type || (selection.gene ? 'Gene' : 'TE')).toLowerCase() === 'gene' ? 'Gene' : 'TE';
    const feature = String(selection.feature || selection.gene || selection.te || '').trim();
    const context = String(selection.context || '').trim();
    if (featureType === 'Gene' && feature) url.searchParams.set('gene', feature);
    if (featureType === 'TE' && feature) url.searchParams.set('te', feature);
    if (context) url.searchParams.set('context', context);
    return url;
  }

  function writeRoute(action, state) {
    if (action !== 'push' && action !== 'replace') return false;
    const url = state.mode === 'coexpression'
      ? routeForCoexpression(state.selection)
      : routeForKnowledge(state.knowledge);
    const next = `${url.pathname}${url.search}${url.hash}`;
    const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
    if (next === current) return false;
    window.history[action === 'replace' ? 'replaceState' : 'pushState'](
      { tekgPreviewMode: state.mode, selection: state.selection || null },
      '',
      next,
    );
    return true;
  }

  function updateTaxonomyTab(tab, selected) {
    tab.classList.toggle('is-active', selected);
    tab.setAttribute('aria-selected', selected ? 'true' : 'false');
    tab.setAttribute('aria-pressed', selected ? 'true' : 'false');
    tab.tabIndex = selected ? 0 : -1;
  }

  function syncTaxonomyMode(variant) {
    const selected = variant === 'all' ? 'all' : 'rmsk_repbase';
    updateTaxonomyTab(taxonomyAllTab, selected === 'all');
    updateTaxonomyTab(taxonomyRmskRepbaseTab, selected === 'rmsk_repbase');
  }

  function syncTaxonomyDisplayMode(mode) {
    const graphSelected = mode === 'graph' || mode === 'taxonomy_graph';
    updateTaxonomyTab(taxonomyTreeTab, !graphSelected);
    updateTaxonomyTab(taxonomyGraphTab, graphSelected);
  }

  async function setTaxonomyDisplayMode(mode, options = {}) {
    if (currentMode !== 'knowledge') return false;
    const bridge = window.__TEKG_G6_BRIDGE;
    if (!bridge || typeof bridge.setTaxonomyDisplayMode !== 'function') return false;
    const selected = mode === 'graph' ? 'graph' : 'tree';
    const changed = await bridge.setTaxonomyDisplayMode(selected);
    if (changed === false) return false;
    syncTaxonomyDisplayMode(selected);
    retainedKnowledgeState = bridge.getState?.() || retainedKnowledgeState;
    writeRoute(options.history || 'push', { mode: 'knowledge', knowledge: retainedKnowledgeState });
    return true;
  }

  async function setTreeVariant(variant, options = {}) {
    if (currentMode !== 'knowledge') return false;
    const bridge = window.__TEKG_G6_BRIDGE;
    if (!bridge || typeof bridge.setTreeVariant !== 'function') return false;
    const nextVariant = variant === 'all' ? 'all' : 'rmsk_repbase';
    const changed = await bridge.setTreeVariant(nextVariant);
    if (changed === false) return false;
    syncTaxonomyMode(nextVariant);
    const state = bridge.getState?.() || { mode: 'tree', treeVariant: nextVariant };
    retainedKnowledgeState = state;
    writeRoute(options.history || 'push', { mode: 'knowledge', knowledge: state });
    return true;
  }

  async function selectionFromKnowledge(options = {}) {
    const state = window.__TEKG_G6_BRIDGE?.getState?.() || retainedKnowledgeState || {};
    retainedKnowledgeState = state;
    if (String(state.mode) !== 'dynamic') return null;
    const rawFeatureType = String(state.queryType || 'TE').trim().toLowerCase();
    if (rawFeatureType !== 'te' && rawFeatureType !== 'gene') return null;
    const featureType = rawFeatureType === 'gene' ? 'Gene' : 'TE';
    const query = String(state.query || '').trim();
    if (!query) return null;
    const exact = await coexpressionMode.resolveExactFeature(query, featureType);
    return {
      feature: exact?.feature || query,
      featureType,
      ...(featureType === 'Gene' ? { gene: exact?.feature || query } : { te: exact?.feature || query }),
      context: String(options.context || '').trim(),
    };
  }

  async function setMode(mode, options = {}) {
    const requestedMode = mode === 'coexpression' ? 'coexpression' : 'knowledge';
    const epoch = ++transitionEpoch;
    const changed = requestedMode !== currentMode;
    const explicitFeatureType = String(options.featureType || (options.gene ? 'Gene' : 'TE')).toLowerCase() === 'gene' ? 'Gene' : 'TE';
    const explicitFeature = String(options.feature || options.gene || options.te || '').trim();
    const explicitContext = String(options.context || '').trim();

    if (requestedMode === 'knowledge') {
      const coexpressionState = coexpressionMode.getDiagnostics();
      const hasStableCoexpressionView = ['ready', 'empty', 'unavailable'].includes(
        coexpressionState.state,
      );
      const coexpressionSelection = !options.restoreRoute && hasStableCoexpressionView
        ? (coexpressionState.stableSelection || coexpressionState.selection)
        : null;
      currentMode = 'knowledge';
      if (changed) switchCount += 1;
      setWorkspaceVisibility('knowledge');
      await coexpressionMode.deactivate();
      if (epoch !== transitionEpoch) return currentMode;

      const selectedFeature = String(coexpressionSelection?.feature || coexpressionSelection?.gene || coexpressionSelection?.te || '').trim();
      const selectedFeatureType = String(coexpressionSelection?.featureType || coexpressionSelection?.feature_type || (coexpressionSelection?.gene ? 'Gene' : 'TE')).toLowerCase() === 'gene' ? 'Gene' : 'TE';
      if (selectedFeature) {
        const knowledge = {
          mode: 'dynamic',
          query: selectedFeature,
          queryType: selectedFeatureType,
          classQuery: '',
        };
        retainedKnowledgeState = knowledge;
        writeRoute(options.history, { mode: 'knowledge', knowledge });
        const bridge = window.__TEKG_G6_BRIDGE;
        const state = bridge?.getState?.() || {};
        const alreadyLoaded = state.mode === 'dynamic'
          && String(state.query || '').toLowerCase() === selectedFeature.toLowerCase()
          && String(state.queryType || 'TE').toLowerCase() === selectedFeatureType.toLowerCase();
        if (!alreadyLoaded) {
          await loadKnowledgeGraph({
            query: selectedFeature,
            queryType: selectedFeatureType,
          });
        }
      } else if (options.restoreRoute) {
        const route = options.restoreRoute;
        if (route.query && route.queryType === 'disease_class') {
          await window.__TEKG_G6_BRIDGE?.restoreDiseaseClassRoute?.(route);
        } else if (route.query) {
          await loadKnowledgeGraph({
            query: route.query,
            queryType: route.queryType || 'TE',
            classQuery: route.classQuery || '',
          }, {
            pushHistory: false,
            resetHistory: options.history === 'none',
            routeFilters: route.routeFilters,
          });
        } else {
          await window.__TEKG_G6_BRIDGE?.restoreTaxonomyRoute?.({
            treeVariant: route.treeVariant || 'rmsk_repbase',
            taxonomyDisplayMode: route.taxonomyDisplayMode || 'tree',
          });
        }
      }
      if (epoch !== transitionEpoch) return currentMode;
      resizeKnowledgeGraph();
      notifyModeChange();
      return currentMode;
    }

    let selection = explicitFeature ? {
      feature: explicitFeature,
      featureType: explicitFeatureType,
      ...(explicitFeatureType === 'Gene' ? { gene: explicitFeature } : { te: explicitFeature }),
      context: explicitContext,
    } : null;
    if (!selection && currentMode === 'knowledge') {
      selection = await selectionFromKnowledge({ context: explicitContext });
      if (epoch !== transitionEpoch) return currentMode;
    }
    if (!selection) {
      const retained = coexpressionMode.getDiagnostics().selection;
      if (retained?.feature || retained?.gene || retained?.te) selection = { ...retained };
    }
    if (knowledgeLoadPromise) {
      try {
        await knowledgeLoadPromise;
      } catch (_error) {}
      if (epoch !== transitionEpoch) return currentMode;
    }

    currentMode = 'coexpression';
    if (changed) switchCount += 1;
    setWorkspaceVisibility('coexpression');
    const selectedFeature = String(selection?.feature || selection?.gene || selection?.te || '').trim();
    const selectedFeatureType = String(selection?.featureType || selection?.feature_type || (selection?.gene ? 'Gene' : 'TE')).toLowerCase() === 'gene' ? 'Gene' : 'TE';
    if (selectedFeature) {
      const existing = coexpressionMode.getDiagnostics();
      const accepted = {
        feature: selectedFeature,
        featureType: selectedFeatureType,
        ...(selectedFeatureType === 'Gene' ? { gene: selectedFeature } : { te: selectedFeature }),
        context: selection.context || existing.selection?.context || '',
      };
      writeRoute(options.history, { mode: 'coexpression', selection: accepted });
      const stable = ![
        'idle', 'loading-catalog', 'loading-network', 'loading-expression',
        'loading-iframe', 'rendering',
      ].includes(existing.state);
      const sameSelection = String(existing.selection?.feature || existing.selection?.gene || existing.selection?.te || '').toLowerCase() === accepted.feature.toLowerCase()
        && String(existing.selection?.featureType || existing.selection?.feature_type || (existing.selection?.gene ? 'Gene' : 'TE')).toLowerCase() === accepted.featureType.toLowerCase()
        && String(existing.selection?.context || '') === String(accepted.context || '');
      if (changed && stable && sameSelection) {
        await coexpressionMode.resume();
      } else {
        await coexpressionMode.activate(accepted);
      }
      const resolvedSelection = coexpressionMode.getDiagnostics().selection;
      if (resolvedSelection?.feature || resolvedSelection?.gene || resolvedSelection?.te) {
        writeRoute('replace', { mode: 'coexpression', selection: resolvedSelection });
      }
    } else {
      writeRoute(options.history, { mode: 'coexpression', selection: null });
      await coexpressionMode.activate({});
    }
    if (epoch !== transitionEpoch) return currentMode;
    notifyModeChange();
    return currentMode;
  }

  function requestCoexpressionSelection(selection, options = {}) {
    const current = coexpressionMode.getDiagnostics().selection;
    if (options.recordEntityHistory !== false) pushBackTarget(current, selection);
    return setMode('coexpression', {
      feature: selection?.feature || selection?.gene || selection?.te,
      featureType: selection?.featureType || selection?.feature_type || (selection?.gene ? 'Gene' : 'TE'),
      context: selection?.context,
      history: options.history || 'push',
      recordEntityHistory: false,
    });
  }

  function ensureKnowledgeForGraphAction() {
    return setMode('knowledge');
  }

  function navigateGraph(request, options = {}) {
    const bridge = window.__TEKG_G6_BRIDGE;
    if (!bridge?.navigateGraph) return Promise.resolve(false);
    return Promise.resolve(bridge.navigateGraph(request, options));
  }

  function getDiagnostics() {
    return {
      mode: currentMode,
      switchCount,
      knowledgeVisible: !knowledgeWorkspace.hidden,
      coexpressionVisible: !coexpressionWorkspace.hidden,
      knowledgeSelected: knowledgeTab.getAttribute('aria-selected') === 'true',
      coexpressionSelected: coexpressionTab.getAttribute('aria-selected') === 'true',
      pendingKnowledgeLoads,
      sharedBackHistory: sharedBackHistory.map((item) => ({ ...item })),
    };
  }

  knowledgeTab.addEventListener('click', () => {
    void setMode('knowledge', { history: 'push' });
  });

  coexpressionToolbar.addEventListener('click', (event) => {
    if (currentMode !== 'coexpression' || !event.target.closest('#back-graph')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    void goBackEntity();
  }, true);
  coexpressionTab.addEventListener('click', () => {
    void setMode('coexpression', { history: 'push' });
  });
  taxonomyAllTab.addEventListener('click', () => {
    void setTreeVariant('all', { history: 'push' });
  });
  taxonomyRmskRepbaseTab.addEventListener('click', () => {
    void setTreeVariant('rmsk_repbase', { history: 'push' });
  });
  taxonomyTreeTab.addEventListener('click', () => {
    void setTaxonomyDisplayMode('tree');
  });
  taxonomyGraphTab.addEventListener('click', () => {
    void setTaxonomyDisplayMode('graph');
  });

  window.addEventListener('tekg:g6-state-change', (event) => {
    const nextKnowledgeState = event.detail || retainedKnowledgeState;
    if (!restoringBrowserRoute) recordKnowledgeTransition(retainedKnowledgeState, nextKnowledgeState);
    retainedKnowledgeState = nextKnowledgeState;
    knowledgeGraphMode = String(event.detail?.mode || knowledgeGraphMode);
    syncTaxonomyMode(event.detail?.treeVariant);
    syncTaxonomyDisplayMode(event.detail?.taxonomyDisplayMode || event.detail?.mode);
    syncTopControlVisibility();
  });

  window.addEventListener('tekg:g6-navigation', (event) => {
    const previousKnowledgeState = retainedKnowledgeState;
    const nextKnowledgeState = event.detail || retainedKnowledgeState;
    if (!restoringBrowserRoute) recordKnowledgeTransition(previousKnowledgeState, nextKnowledgeState);
    retainedKnowledgeState = nextKnowledgeState;
    knowledgeGraphMode = String(nextKnowledgeState?.mode || knowledgeGraphMode);
    syncTaxonomyMode(nextKnowledgeState?.treeVariant);
    syncTaxonomyDisplayMode(nextKnowledgeState?.taxonomyDisplayMode || nextKnowledgeState?.mode);
    syncTopControlVisibility();
    if (currentMode !== 'knowledge') return;
    writeRoute(event.detail?.history || 'push', {
      mode: 'knowledge',
      knowledge: retainedKnowledgeState,
    });
  });

  [knowledgeTab, coexpressionTab].forEach((tab) => {
    tab.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
      event.preventDefault();
      const nextMode = currentMode === 'knowledge' ? 'coexpression' : 'knowledge';
      (nextMode === 'knowledge' ? knowledgeTab : coexpressionTab).focus();
      void setMode(nextMode, { history: 'push' });
    });
  });

  window.__TEKG_PREVIEW_WORKSPACE_MODE = {
    setMode,
    requestCoexpressionSelection,
    goBackEntity,
    navigateGraph,
    getMode: () => currentMode,
    getDiagnostics,
    ensureKnowledgeForGraphAction,
    setTreeVariant,
    setTaxonomyDisplayMode,
    getTaxonomyDisplayMode: () => window.__TEKG_G6_BRIDGE?.getTaxonomyDisplayMode?.() || 'tree',
    routeForKnowledge,
    routeForCoexpression,
    writeRoute,
  };

  knowledgeGraphMode = String(retainedKnowledgeState.mode || 'tree');
  updateSharedBackButton();
  setWorkspaceVisibility('knowledge');
  syncTaxonomyMode(retainedKnowledgeState.treeVariant);
  syncTaxonomyDisplayMode(retainedKnowledgeState.taxonomyDisplayMode || retainedKnowledgeState.mode);

  const params = new URLSearchParams(window.location.search);
  if (params.get('mode') === 'coexpression') {
    const gene = params.get('gene') || '';
    void setMode('coexpression', {
      feature: gene || params.get('te') || undefined,
      featureType: gene ? 'Gene' : 'TE',
      context: params.get('context') || undefined,
      history: 'none',
    });
  }

  window.addEventListener('popstate', () => {
    const route = new URLSearchParams(window.location.search);
    restoringBrowserRoute = true;
    resetSharedBackHistory();
    if (route.get('mode') === 'coexpression') {
      const gene = route.get('gene') || '';
      void setMode('coexpression', {
        feature: gene || route.get('te') || undefined,
        featureType: gene ? 'Gene' : 'TE',
        context: route.get('context') || undefined,
        history: 'none',
      }).finally(() => {
        restoringBrowserRoute = false;
        resetSharedBackHistory();
      });
      return;
    }
    window.__TEKG_G6_BRIDGE?.resetHistory?.();
    void setMode('knowledge', {
      history: 'none',
      restoreRoute: parseKnowledgeRoute(route),
    }).then(() => window.__TEKG_G6_BRIDGE?.resetHistory?.()).finally(() => {
      restoringBrowserRoute = false;
      resetSharedBackHistory();
    });
  });
})();
