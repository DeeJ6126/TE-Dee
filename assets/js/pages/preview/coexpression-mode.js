(function () {
  'use strict';

  const contract = window.__TEKG_COEXPRESSION_CONTRACT;
  const paths = window.__TEKG_PATHS;
  const workspace = document.getElementById('previewCoexpressionWorkspace');
  if (!contract || !paths || !workspace) return;

  const CACHE_LIMIT = 6;
  const PREFERRED_CONTEXT = 'normal_tissue';
  const LOADING_STATES = new Set(['loading-catalog', 'loading-network', 'loading-iframe', 'rendering']);
  const els = {
    te: document.getElementById('coexpression-te-search'),
    searchType: document.getElementById('coexpression-search-type'),
    autocompleteRoot: workspace.querySelector('[data-te-autocomplete-root]'),
    context: document.getElementById('coexpression-context-select'),
    load: document.getElementById('coexpression-load'),
    frameHost: document.getElementById('coexpression-iframe-host'),
    preloader: document.getElementById('coexpression-preloader'),
    mechanismLoaderSlot: document.getElementById('coexpression-mechanism-loader-slot'),
    preloaderLabel: document.getElementById('coexpression-preloader-label'),
    state: document.getElementById('coexpression-state'),
    stateMessage: document.getElementById('coexpression-state-message'),
    retry: document.getElementById('coexpression-retry'),
    detail: document.getElementById('coexpression-node-details'),
    expression: document.getElementById('coexpression-expression-layer'),
    expressionText: document.getElementById('coexpression-expression-layer-text'),
    showTe: document.getElementById('coexpression-show-te'),
    showGene: document.getElementById('coexpression-show-gene'),
    edgeScope: document.getElementById('coexpression-edge-scope'),
    legendApply: document.getElementById('coexpression-legend-apply'),
    methodSummary: document.getElementById('coexpression-method-summary'),
    exportWrap: document.getElementById('coexpression-export-menu-wrap'),
    exportToggle: document.getElementById('coexpression-export-menu-toggle'),
    exportMenu: document.getElementById('coexpression-export-menu'),
    exportCsv: document.getElementById('coexpression-export-csv'),
    exportPng: document.getElementById('coexpression-export-png'),
    exportSvg: document.getElementById('coexpression-export-svg'),
    legend: document.getElementById('coexpression-legend'),
  };

  let catalogPromise = null;
  let catalog = null;
  let frame = null;
  let frameBridgePromise = null;
  let frameIdentity = 0;
  let requestEpoch = 0;
  let abortController = null;
  let currentState = 'idle';
  let currentSelection = null;
  let stableSelection = null;
  let currentNetwork = null;
  let currentAvailableContexts = [];
  let currentNonblank = false;
  let visible = false;
  let renderQueue = Promise.resolve();
  let expressionEnabled = true;
  let expressionEpoch = 0;
  let expressionAbortController = null;
  let currentExpressionOverlay = { enabled: false, context: 'off', records: {}, min_value: 0, max_value: 0 };
  let currentViewOptions = { showTE: true, showGene: true, edgeScope: 'all' };
  let filterBusy = false;
  let filterEpoch = 0;
  let activeLegendFocus = null;
  let loaderKind = 'default';
  const networkCache = new Map();
  const expressionSummaryCache = new Map();
  const requestCounts = { catalog: 0, network: 0 };

  async function fetchWithDeadline(url, options = {}, timeoutMs = 15000, label = 'Co-expression request') {
    const controller = new AbortController();
    const externalSignal = options.signal;
    let timedOut = false;
    const abortFromCaller = () => controller.abort();
    if (externalSignal) {
      if (externalSignal.aborted) controller.abort();
      else externalSignal.addEventListener('abort', abortFromCaller, { once: true });
    }
    const timeoutId = window.setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, Math.max(1, Number(timeoutMs) || 15000));
    try {
      return await fetch(url, { ...options, signal: controller.signal });
    } catch (error) {
      if (timedOut) throw new Error(`${label} timed out. Retry to continue.`);
      throw error;
    } finally {
      window.clearTimeout(timeoutId);
      externalSignal?.removeEventListener?.('abort', abortFromCaller);
    }
  }

  function setWorkspaceVisible(next) {
    visible = next === true;
    workspace.hidden = !visible;
    workspace.setAttribute('aria-hidden', visible ? 'false' : 'true');
  }

  function normalizeFeatureType(value) {
    return String(value || '').trim().toLowerCase() === 'gene' ? 'Gene' : 'TE';
  }

  function selectionFeature(selection) {
    return String(selection?.feature || selection?.gene || selection?.te || '').trim();
  }

  function setState(state, message = '') {
    currentState = state;
    const loading = LOADING_STATES.has(state);
    const loader = window.__TEKG_TE_LOADER;
    const progress = {
      'loading-catalog': { phase: 'request', text: 'Checking available co-expression data...' },
      'loading-network': { phase: 'request', text: 'Requesting co-expression data...' },
      'loading-iframe': { phase: 'prepare', text: 'Preparing and transforming graph data...' },
      rendering: { phase: 'render', text: 'Rendering graph and running force layout...' },
    }[state] || { phase: 'request', text: 'Requesting co-expression data...' };
    const loadingFeature = selectionFeature(currentSelection) || String(els.te?.value || '').trim();
    const publicMessage = loading
      ? (loadingFeature ? `Loading ${loadingFeature} co-expression network...` : 'Loading co-expression network...')
      : message;
    if (loading) {
      loader?.show({
        overlay: els.preloader,
        slot: els.mechanismLoaderSlot,
        label: publicMessage,
        nodeOrQuery: loadingFeature || normalizeFeatureType(els.searchType?.value),
        kind: loaderKind,
        phase: progress.phase,
        phaseText: progress.text,
      });
    } else {
      loader?.hide({ overlay: els.preloader, slot: els.mechanismLoaderSlot });
      if (!loader) {
        els.preloader.classList.remove('is-visible');
        els.preloader.setAttribute('aria-hidden', 'true');
      }
    }
    if (publicMessage) els.preloaderLabel.textContent = publicMessage;
    const showMessage = state === 'awaiting-selection'
      || state === 'unavailable'
      || state === 'empty'
      || state === 'error';
    els.state.hidden = !showMessage;
    els.stateMessage.textContent = showMessage ? message : '';
    els.retry.hidden = state !== 'error';
    els.exportToggle.disabled = state !== 'ready';
    if (state !== 'ready') closeExportMenu();
  }

  async function fetchPayload(url, signal) {
    const response = await fetchWithDeadline(url, {
      signal,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    }, 15000, 'Co-expression data request');
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.ok !== true) {
      const error = new Error(payload?.error?.message || `Request failed with HTTP ${response.status}.`);
      error.code = payload?.error?.code || 'request_failed';
      error.availableContexts = Array.isArray(payload?.error?.available_contexts)
        ? payload.error.available_contexts.slice()
        : [];
      throw error;
    }
    return payload;
  }

  function catalogUrl() {
    return `${paths.apiUrl('coexpression.php')}?action=catalog`;
  }

  function networkUrl(feature, featureType, context) {
    const url = new URL(paths.apiUrl('coexpression.php'), window.location.origin);
    url.searchParams.set('action', 'network');
    const type = normalizeFeatureType(featureType);
    url.searchParams.set('feature_type', type);
    url.searchParams.set(type === 'Gene' ? 'gene' : 'te', feature);
    url.searchParams.set('context', context);
    return url.toString();
  }

  function syncExpressionButton() {
    els.expression.classList.toggle('is-active', expressionEnabled);
    els.expression.setAttribute('aria-pressed', expressionEnabled ? 'true' : 'false');
    els.expressionText.textContent = `Expression activity: ${expressionEnabled ? 'On' : 'Off'}`;
  }

  function syncMethodSummary() {
    if (!catalog) return;
    const threshold = Number(catalog.thresholds?.absolute_correlation ?? catalog.thresholds?.abs_correlation ?? 0.4);
    const fdr = Number(catalog.thresholds?.fdr ?? catalog.thresholds?.fdr_threshold ?? 0.05);
    els.methodSummary.textContent = `${catalog.method || 'Spearman correlation'} · r ≥ ${threshold || 0.4} · FDR ≤ ${fdr || 0.05}`;
  }

  function expressionNames(network) {
    return [...new Set((network?.nodes || [])
      .filter((node) => ['te', 'gene'].includes(node.kind))
      .map((node) => String(node.label || node.id || '').trim())
      .filter(Boolean))].slice(0, 80);
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

  function buildExpressionOverlay(network, records) {
    const context = String(network?.selection?.context || 'off');
    const names = expressionNames(network);
    const mapped = expressionRecordMap(records);
    const values = names.map((name) => {
      const record = mapped[name.toLowerCase()];
      const value = Number(record?.[context]?.median_value);
      return record?.available === true && Number.isFinite(value) ? value : null;
    }).filter((value) => value !== null);
    return {
      enabled: expressionEnabled,
      context,
      context_label: context.replaceAll('_', ' '),
      records: mapped,
      min_value: values.length ? Math.min(...values) : 0,
      max_value: values.length ? Math.max(...values) : 0,
      evidence_boundary: 'Expression values provide activity context only and do not prove causal graph relations.',
    };
  }

  async function fetchExpressionSummaries(network, signal) {
    const names = expressionNames(network);
    if (!names.length) return [];
    const context = String(network?.selection?.context || '');
    const key = `${network?.version || catalog?.version || ''}|${context}|${names.slice().sort((a, b) => a.localeCompare(b)).join('|')}`;
    if (expressionSummaryCache.has(key)) {
      const cached = expressionSummaryCache.get(key);
      expressionSummaryCache.delete(key);
      expressionSummaryCache.set(key, cached);
      return cached;
    }
    const response = await fetchWithDeadline(paths.apiUrl('graph_expression.php'), {
      method: 'POST',
      signal,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ te_names: names, context }),
    }, 8000, 'Expression activity request');
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.ok !== true) {
      throw new Error(payload?.error || 'Expression activity request failed.');
    }
    const records = Array.isArray(payload.records) ? payload.records : [];
    expressionSummaryCache.set(key, records);
    while (expressionSummaryCache.size > CACHE_LIMIT) {
      expressionSummaryCache.delete(expressionSummaryCache.keys().next().value);
    }
    return records;
  }

  async function expressionOverlayForNetwork(network, epoch) {
    if (!expressionEnabled) return buildExpressionOverlay(network, []);
    if (expressionAbortController) expressionAbortController.abort();
    expressionAbortController = new AbortController();
    try {
      const records = await fetchExpressionSummaries(network, expressionAbortController.signal);
      if (epoch !== expressionEpoch) return null;
      return buildExpressionOverlay(network, records);
    } finally {
      if (epoch === expressionEpoch) expressionAbortController = null;
    }
  }

  function enableFeatureInput() {
    els.te.disabled = false;
    els.searchType.disabled = false;
    els.load.disabled = !itemForFeature(els.te.value, els.searchType.value);
  }

  function itemForFeature(feature, featureType = els.searchType?.value) {
    const type = normalizeFeatureType(featureType);
    const wanted = String(feature || '').trim().toLowerCase();
    const items = type === 'Gene' ? catalog?.geneItems : catalog?.items;
    const key = type === 'Gene' ? 'gene' : 'te';
    return items?.find((item) => item[key].toLowerCase() === wanted) || null;
  }

  function featureItemName(item, featureType) {
    return String(normalizeFeatureType(featureType) === 'Gene' ? item?.gene : item?.te).trim();
  }

  function syncSearchType(featureType = els.searchType?.value) {
    const type = normalizeFeatureType(featureType);
    els.searchType.value = type;
    els.te.placeholder = `Select a ${type}`;
    if (els.autocompleteRoot) {
      els.autocompleteRoot.dataset.teAutocompleteSource = type === 'Gene'
        ? 'coexpression-gene-catalog'
        : 'coexpression-catalog';
    }
    return type;
  }

  function populateContextOptions(item, preferredContext = '') {
    const available = new Set(item ? item.availableContexts : []);
    const fragment = document.createDocumentFragment();
    catalog.contexts.forEach((context) => {
      const option = document.createElement('option');
      option.value = context.id;
      option.textContent = context.label;
      option.disabled = !available.has(context.id);
      fragment.appendChild(option);
    });
    els.context.replaceChildren(fragment);
    const nextContext = available.has(preferredContext)
      ? preferredContext
      : (available.has(PREFERRED_CONTEXT)
        ? PREFERRED_CONTEXT
        : (available.has(catalog.defaultSelection.context) ? catalog.defaultSelection.context : [...available][0] || ''));
    els.context.value = nextContext;
    els.context.disabled = !item || !nextContext;
    currentAvailableContexts = item ? item.availableContexts.slice() : [];
    return nextContext;
  }

  function syncPendingFeatureControls(preferredContext = PREFERRED_CONTEXT) {
    const type = syncSearchType(els.searchType.value);
    const item = itemForFeature(els.te.value, type);
    if (!item) {
      els.context.disabled = true;
      els.load.disabled = true;
      return null;
    }
    const context = populateContextOptions(item, preferredContext);
    els.load.disabled = !context;
    if (currentState === 'awaiting-selection') setState('idle');
    return {
      feature: featureItemName(item, type),
      featureType: type,
      context,
    };
  }

  function loadCatalog() {
    if (catalogPromise) return catalogPromise;
    requestCounts.catalog += 1;
    catalogPromise = fetchPayload(catalogUrl())
      .then((payload) => contract.normalizeCatalog(payload))
      .then((normalized) => {
        catalog = normalized;
        enableFeatureInput();
        syncMethodSummary();
        return normalized;
      })
      .catch((error) => {
        catalogPromise = null;
        throw error;
      });
    return catalogPromise;
  }

  async function resolveExactTe(name) {
    await loadCatalog();
    return itemForFeature(name, 'TE')?.te || null;
  }

  async function resolveExactFeature(name, featureType = 'TE') {
    await loadCatalog();
    const type = normalizeFeatureType(featureType);
    const item = itemForFeature(name, type);
    return item ? { feature: featureItemName(item, type), featureType: type } : null;
  }

  function cacheGet(key) {
    if (!networkCache.has(key)) return null;
    const value = networkCache.get(key);
    networkCache.delete(key);
    networkCache.set(key, value);
    return value;
  }

  function cacheSet(key, value) {
    if (networkCache.has(key)) networkCache.delete(key);
    networkCache.set(key, value);
    while (networkCache.size > CACHE_LIMIT) {
      networkCache.delete(networkCache.keys().next().value);
    }
  }

  function waitForBridge(maxAttempts = 80, delayMs = 50) {
    if (frameBridgePromise) return frameBridgePromise;
    const pending = new Promise((resolve, reject) => {
      let attempts = 0;
      const check = () => {
        attempts += 1;
        try {
          const bridge = frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED;
          if (bridge && typeof bridge.renderNetwork === 'function') {
            resolve(bridge);
            return;
          }
        } catch (_error) {}
        if (attempts >= maxAttempts) {
          reject(new Error('Co-expression iframe bridge is unavailable.'));
          return;
        }
        window.setTimeout(check, delayMs);
      };
      check();
    });
    frameBridgePromise = pending.catch((error) => {
      frameBridgePromise = null;
      throw error;
    });
    return frameBridgePromise;
  }

  function enqueueRender(bridge, network, epoch) {
    const queued = renderQueue
      .catch(() => undefined)
      .then(async () => {
        if (epoch !== requestEpoch) return { cancelled: true, report: null };
        const report = await bridge.renderNetwork(network);
        return { cancelled: false, report };
      });
    renderQueue = queued.then(() => undefined, () => undefined);
    return queued;
  }

  function ensureFrame() {
    if (frame) return frame;
    frame = document.createElement('iframe');
    frame.id = 'coexpression-graph-frame';
    frame.title = 'TE-Dee Co-expression graph';
    frame.setAttribute('scrolling', 'no');
    const version = String(window.__TEKG_PREVIEW_VERSION || '').trim();
    const frameUrl = paths.assetsUrl('html/preview_coexpression_embed.html');
    frame.src = version
      ? frameUrl + `?v=${encodeURIComponent(version)}`
      : frameUrl;
    els.frameHost.replaceChildren(frame);
    frameIdentity += 1;
    return frame;
  }

  function unavailableSelection(item, featureType, requestedContext) {
    const type = normalizeFeatureType(featureType);
    const feature = featureItemName(item, type);
    const labels = item.availableContexts
      .map((id) => catalog.contexts.find((context) => context.id === id)?.label || id)
      .join(', ');
    currentSelection = { feature, featureType: type, ...(type === 'Gene' ? { gene: feature } : { te: feature }), context: requestedContext };
    stableSelection = { ...currentSelection };
    currentAvailableContexts = item.availableContexts.slice();
    currentNonblank = false;
    populateContextOptions(item, item.availableContexts[0]);
    syncSearchType(type);
    els.te.value = feature;
    setState('unavailable', `${feature} is unavailable for this context. Available: ${labels}.`);
  }

  function unavailableFeature(requestedFeature, featureType, requestedContext = '') {
    const type = normalizeFeatureType(featureType);
    const feature = String(requestedFeature || '').trim();
    const option = document.createElement('option');
    option.value = '';
    option.textContent = 'No context available';
    els.context.replaceChildren(option);
    els.context.disabled = true;
    syncSearchType(type);
    els.te.value = feature;
    currentSelection = { feature, featureType: type, ...(type === 'Gene' ? { gene: feature } : { te: feature }), context: String(requestedContext || '').trim() };
    stableSelection = { ...currentSelection };
    currentAvailableContexts = [];
    currentNetwork = null;
    currentNonblank = false;
    setState('unavailable', `No co-expression data is available for ${feature}.`);
  }

  function awaitFeatureSelection(featureType = els.searchType?.value) {
    const type = syncSearchType(featureType);
    const option = document.createElement('option');
    option.value = '';
    option.textContent = `Select a ${type} first`;
    els.context.replaceChildren(option);
    els.context.disabled = true;
    els.load.disabled = true;
    els.te.value = '';
    currentSelection = null;
    stableSelection = null;
    currentAvailableContexts = [];
    currentNetwork = null;
    currentNonblank = false;
    if (type === 'Gene') {
      setState('idle');
    } else {
      setState('awaiting-selection', `Select a ${type} to explore its co-expression network.`);
    }
  }

  async function activate(options = {}) {
    const epoch = ++requestEpoch;
    const requestedTypeAtStart = normalizeFeatureType(options.featureType || (options.gene ? 'Gene' : 'TE'));
    const requestedFeatureAtStart = String(options.feature || options.gene || options.te || '').trim();
    const requestedContextAtStart = String(options.context || '').trim();
    const previous = {
      state: currentState,
      selection: currentSelection ? { ...currentSelection } : null,
      stableSelection: stableSelection ? { ...stableSelection } : null,
      network: currentNetwork,
      availableContexts: currentAvailableContexts.slice(),
      nonblank: currentNonblank,
      expressionOverlay: currentExpressionOverlay,
      message: els.stateMessage.textContent || els.preloaderLabel.textContent || '',
    };
    if (requestedFeatureAtStart) {
      syncSearchType(requestedTypeAtStart);
      currentSelection = { feature: requestedFeatureAtStart, featureType: requestedTypeAtStart, ...(requestedTypeAtStart === 'Gene' ? { gene: requestedFeatureAtStart } : { te: requestedFeatureAtStart }), context: requestedContextAtStart };
      els.te.value = requestedFeatureAtStart;
    }
    const restorePrevious = () => {
      currentSelection = previous.selection;
      stableSelection = previous.stableSelection;
      currentNetwork = previous.network;
      currentAvailableContexts = previous.availableContexts.slice();
      currentNonblank = previous.nonblank;
      currentExpressionOverlay = previous.expressionOverlay;
      if (previous.selection && catalog) {
        const previousType = normalizeFeatureType(previous.selection.featureType || (previous.selection.gene ? 'Gene' : 'TE'));
        const item = itemForFeature(selectionFeature(previous.selection), previousType);
        if (item) {
          syncSearchType(previousType);
          els.te.value = featureItemName(item, previousType);
          populateContextOptions(item, previous.selection.context);
        }
      }
      const previousNodeCount = Array.isArray(previous.network?.nodes) ? previous.network.nodes.length : 0;
      const restoreState = LOADING_STATES.has(previous.state)
        ? (previous.stableSelection && previous.network ? (previousNodeCount > 0 ? 'ready' : 'empty') : 'idle')
        : previous.state;
      setState(restoreState, restoreState === 'empty' ? previous.message : '');
    };
    if (abortController) abortController.abort();
    abortController = null;
    setWorkspaceVisible(true);

    try {
      const catalogRequest = loadCatalog();
      loaderKind = requestedTypeAtStart === 'TE'
        ? await (window.__TEKG_TE_LOADER?.resolveKind?.(requestedFeatureAtStart) || 'default')
        : 'default';
      if (epoch !== requestEpoch) {
        if (!visible) restorePrevious();
        return null;
      }
      setState('loading-catalog', 'Loading co-expression catalog...');
      const nextCatalog = await catalogRequest;
      if (epoch !== requestEpoch) {
        if (!visible) restorePrevious();
        return null;
      }
      const requestedFeature = requestedFeatureAtStart;
      if (!requestedFeature) {
        awaitFeatureSelection(requestedTypeAtStart);
        return null;
      }
      const item = itemForFeature(requestedFeature, requestedTypeAtStart);
      if (!item) {
        unavailableFeature(requestedFeature, requestedTypeAtStart, options.context);
        return null;
      }
      const resolved = contract.resolveFeatureSelection(nextCatalog, requestedFeature, requestedTypeAtStart, requestedContextAtStart);
      const feature = resolved.feature;

      const explicitlyRequestedContext = requestedContextAtStart;
      if (explicitlyRequestedContext && !item.availableContexts.includes(explicitlyRequestedContext)) {
        unavailableSelection(item, requestedTypeAtStart, explicitlyRequestedContext);
        return null;
      }

      const context = populateContextOptions(item, explicitlyRequestedContext);
      syncSearchType(requestedTypeAtStart);
      els.te.value = feature;
      currentSelection = { feature, featureType: requestedTypeAtStart, ...(requestedTypeAtStart === 'Gene' ? { gene: feature } : { te: feature }), context };
      currentAvailableContexts = item.availableContexts.slice();
      const key = `${nextCatalog.version}\u0000${requestedTypeAtStart}\u0000${feature}\u0000${context}`;
      let network = cacheGet(key);

      if (!network) {
        setState('loading-network', `Loading ${feature} in ${context.replaceAll('_', ' ')}...`);
        abortController = new AbortController();
        requestCounts.network += 1;
        const payload = await fetchPayload(networkUrl(feature, requestedTypeAtStart, context), abortController.signal);
        if (epoch !== requestEpoch) {
          if (!visible) restorePrevious();
          return null;
        }
        network = contract.normalizeNetwork(payload);
        cacheSet(key, network);
      }

      if (epoch !== requestEpoch) {
        if (!visible) restorePrevious();
        return null;
      }
      if (network.nodes.length === 0) {
        currentNetwork = network;
        stableSelection = { ...currentSelection };
        currentNonblank = false;
        setState('empty', 'This Co-expression network has no visible nodes.');
        return network;
      }

      const nextExpressionEpoch = ++expressionEpoch;
      let expressionOverlay = buildExpressionOverlay(network, []);
      try {
        expressionOverlay = await expressionOverlayForNetwork(network, nextExpressionEpoch) || expressionOverlay;
      } catch (error) {
        if (error?.name !== 'AbortError') {
          console.warn('Co-expression Expression activity is unavailable:', error);
        }
      }
      if (epoch !== requestEpoch) return null;
      currentExpressionOverlay = expressionOverlay;

      setState('loading-iframe', 'Preparing Co-expression graph surface...');
      currentNonblank = false;
      ensureFrame();
      const bridge = await waitForBridge();
      if (epoch !== requestEpoch) {
        if (!visible) restorePrevious();
        return null;
      }
      await bridge.setVisible(true);
      if (typeof bridge.setExpressionOverlay === 'function') {
        await bridge.setExpressionOverlay(currentExpressionOverlay);
      }
      setState('rendering', `Rendering ${feature} co-expression network...`);
      let timeoutId = 0;
      const renderResult = await Promise.race([
        enqueueRender(bridge, network, epoch),
        new Promise((_, reject) => {
          timeoutId = window.setTimeout(() => reject(new Error('Co-expression rendering timed out. Retry to continue.')), 10_000);
        }),
      ]).finally(() => window.clearTimeout(timeoutId));
      if (renderResult.cancelled) {
        if (!visible) restorePrevious();
        return null;
      }
      const report = renderResult.report;
      if (epoch !== requestEpoch) {
        if (!visible) {
          if (previous.network) {
            if (typeof bridge.setExpressionOverlay === 'function') {
              await bridge.setExpressionOverlay(previous.expressionOverlay);
            }
            await bridge.renderNetwork(previous.network);
          }
          restorePrevious();
          await bridge.setVisible(false);
        }
        return null;
      }
      currentNetwork = network;
      currentSelection = { feature, featureType: requestedTypeAtStart, ...(requestedTypeAtStart === 'Gene' ? { gene: feature } : { te: feature }), context };
      stableSelection = { ...currentSelection };
      currentNonblank = report?.nonblank === true;
      setState(
        report?.nodeCount > 0 ? 'ready' : 'empty',
        report?.nodeCount > 0 ? '' : 'This Co-expression network has no visible nodes.',
      );
      return network;
    } catch (error) {
      if (error?.name === 'AbortError' || epoch !== requestEpoch) {
        if (!visible) restorePrevious();
        return null;
      }
      if (String(error?.message || '').includes('timed out')) {
        try {
          await frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED?.stopLayout?.();
        } catch (_stopError) {}
        renderQueue = Promise.resolve();
      }
      currentNonblank = false;
      setState('error', error && error.message ? error.message : 'Unable to load Co-expression network.');
      return null;
    }
  }

  function deactivate() {
    requestEpoch += 1;
    expressionEpoch += 1;
    if (abortController) abortController.abort();
    abortController = null;
    if (expressionAbortController) expressionAbortController.abort();
    expressionAbortController = null;
    if (LOADING_STATES.has(currentState)) {
      const stableNodeCount = Array.isArray(currentNetwork?.nodes) ? currentNetwork.nodes.length : 0;
      if (stableSelection && currentNetwork) {
        currentSelection = { ...stableSelection };
        currentNonblank = stableNodeCount > 0;
        setState(stableNodeCount > 0 ? 'ready' : 'empty', stableNodeCount > 0 ? '' : 'This Co-expression network has no visible nodes.');
      } else {
        setState('idle');
      }
    }
    setWorkspaceVisible(false);
    if (frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED) {
      return frame.contentWindow.__TEKG_COEXPRESSION_EMBED.setVisible(false);
    }
    return Promise.resolve();
  }

  function resume() {
    setWorkspaceVisible(true);
    const bridge = frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED;
    if (bridge && typeof bridge.setVisible === 'function') {
      return bridge.setVisible(true).then(() => currentNetwork);
    }
    return Promise.resolve(currentNetwork);
  }

  async function setExpressionEnabled(enabled) {
    expressionEnabled = enabled === true;
    syncExpressionButton();
    const epoch = ++expressionEpoch;
    if (expressionAbortController) expressionAbortController.abort();
    expressionAbortController = null;
    if (!currentNetwork) return false;
    let overlay = buildExpressionOverlay(currentNetwork, []);
    try {
      overlay = await expressionOverlayForNetwork(currentNetwork, epoch) || overlay;
    } catch (error) {
      if (error?.name !== 'AbortError') {
        console.warn('Co-expression Expression activity is unavailable:', error);
      }
    }
    if (epoch !== expressionEpoch) return false;
    currentExpressionOverlay = overlay;
    const bridge = frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED;
    if (bridge && typeof bridge.setExpressionOverlay === 'function') {
      await bridge.setExpressionOverlay(overlay);
    }
    return true;
  }

  async function setViewOptions(options = {}) {
    if (filterBusy) return { ...currentViewOptions };
    const previous = { ...currentViewOptions };
    const previousState = currentState;
    const next = {
      showTE: options.showTE !== false,
      showGene: options.showGene !== false,
      edgeScope: options.edgeScope === 'center' ? 'center' : 'all',
    };
    const bridge = frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED;
    if (!bridge || typeof bridge.setViewOptions !== 'function') return previous;
    const epoch = ++filterEpoch;
    let failed = false;
    filterBusy = true;
    els.legendApply.disabled = true;
    els.legendApply.setAttribute('aria-busy', 'true');
    try {
      const report = await bridge.setViewOptions(next);
      if (epoch !== filterEpoch) return { ...currentViewOptions };
      currentViewOptions = next;
      currentNonblank = report?.nonblank === true;
      setState(report?.nodeCount > 0 ? 'ready' : 'empty', report?.nodeCount > 0 ? '' : 'No nodes match the current filters.');
      return { ...currentViewOptions };
    } catch (error) {
      failed = true;
      currentViewOptions = previous;
      try {
        await bridge.setViewOptions(previous);
      } catch (_restoreError) {}
      els.detail.textContent = `Co-expression filters were not applied: ${error?.message || 'Unknown error'}`;
      setState(previousState === 'empty' ? 'empty' : 'ready');
      return { ...currentViewOptions };
    } finally {
      filterBusy = false;
      els.legendApply.disabled = !failed;
      els.legendApply.setAttribute('aria-busy', 'false');
    }
  }

  async function setLegendFocus(focus) {
    const normalized = focus && focus.kind && focus.value
      ? { kind: String(focus.kind), value: String(focus.value) }
      : null;
    activeLegendFocus = normalized;
    els.legend.querySelectorAll('[data-highlight-kind][data-highlight-value]').forEach((row) => {
      const active = !!normalized
        && row.dataset.highlightKind === normalized.kind
        && row.dataset.highlightValue === normalized.value;
      row.classList.toggle('is-highlight-active', active);
      row.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    const bridge = frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED;
    if (bridge && typeof bridge.setLegendFocus === 'function') {
      await bridge.setLegendFocus(normalized);
    }
    return normalized;
  }

  function csvCell(value) {
    const text = value === null || value === undefined ? '' : String(value);
    return /[",\r\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
  }

  async function getVisibleExportGraph() {
    const bridge = frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED;
    if (!bridge || typeof bridge.getVisibleSubgraph !== 'function') {
      throw new Error('The Co-expression renderer is not ready for export.');
    }
    return bridge.getVisibleSubgraph();
  }

  async function exportCsvText() {
    const graph = await getVisibleExportGraph();
    const fields = [
      'record_type', 'id', 'label', 'feature_type', 'role', 'source', 'target',
      'correlation', 'fdr', 'pair_type', 'expression_context', 'expression_median',
      'analysis_version', 'method', 'thresholds', 'interpretation_limit',
    ];
    const version = String(currentNetwork?.version || catalog?.version || '');
    const method = String(catalog?.method || 'Spearman correlation');
    const thresholds = JSON.stringify(catalog?.thresholds || {});
    const limit = String(currentNetwork?.interpretation?.limit || catalog?.interpretationLimit || 'Correlation does not imply causation.');
    const lines = [fields.join(',')];
    for (const node of graph.nodes || []) {
      lines.push([
        'node', node.id, node.label, node.type, node.role, '', '', '', '', '',
        node.expression_context, node.expression_value, version, method, thresholds, limit,
      ].map(csvCell).join(','));
    }
    for (const edge of graph.edges || []) {
      lines.push([
        'edge', edge.id, '', '', edge.edge_role, edge.source, edge.target,
        edge.correlation, edge.fdr, edge.pair_type, '', '', version, method, thresholds, limit,
      ].map(csvCell).join(','));
    }
    return `${lines.join('\r\n')}\r\n`;
  }

  function safeFilename(value) {
    return String(value || 'coexpression').trim().replace(/[^a-z0-9_.-]+/gi, '_').replace(/^_+|_+$/g, '') || 'coexpression';
  }

  function downloadBlob(filename, content, mime) {
    const blob = content instanceof Blob ? content : new Blob([content], { type: mime });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  async function exportCsvFile() {
    const text = await exportCsvText();
    const selection = currentSelection || {};
    downloadBlob(
      `tekg_${safeFilename(selectionFeature(selection))}_${safeFilename(selection.context)}_coexpression.csv`,
      `\uFEFF${text}`,
      'text/csv;charset=utf-8',
    );
    return text;
  }

  async function exportPngFile() {
    const bridge = frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED;
    if (!bridge || typeof bridge.exportPngDataUrl !== 'function') {
      throw new Error('The Co-expression renderer is not ready for PNG export.');
    }
    const dataUrl = await bridge.exportPngDataUrl();
    if (!String(dataUrl || '').startsWith('data:image/png')) {
      throw new Error('The Co-expression PNG export is blank.');
    }
    const response = await fetch(dataUrl);
    const blob = await response.blob();
    const selection = currentSelection || {};
    downloadBlob(
      `tekg_${safeFilename(selectionFeature(selection))}_${safeFilename(selection.context)}_coexpression.png`,
      blob,
      'image/png',
    );
    return dataUrl;
  }

  async function exportSvgText() {
    const bridge = frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED;
    if (!bridge || typeof bridge.exportSvgString !== 'function') {
      throw new Error('The Co-expression renderer is not ready for SVG export.');
    }
    const svg = await bridge.exportSvgString();
    if (!String(svg || '').startsWith('<svg') || !String(svg).includes('xmlns="http://www.w3.org/2000/svg"')) {
      throw new Error('The Co-expression SVG export is blank or invalid.');
    }
    return svg;
  }

  async function exportSvgFile() {
    const svg = await exportSvgText();
    const selection = currentSelection || {};
    downloadBlob(
      `tekg_${safeFilename(selectionFeature(selection))}_${safeFilename(selection.context)}_coexpression.svg`,
      svg,
      'image/svg+xml;charset=utf-8',
    );
    return svg;
  }

  function closeExportMenu() {
    els.exportMenu.hidden = true;
    els.exportToggle.setAttribute('aria-expanded', 'false');
  }

  function toggleExportMenu() {
    if (els.exportToggle.disabled) return;
    const open = els.exportMenu.hidden;
    els.exportMenu.hidden = !open;
    els.exportToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function handleDocumentPointerDown(event) {
    if (!els.exportWrap.contains(event.target)) closeExportMenu();
  }

  function destroy() {
    requestEpoch += 1;
    expressionEpoch += 1;
    if (abortController) abortController.abort();
    abortController = null;
    if (expressionAbortController) expressionAbortController.abort();
    expressionAbortController = null;
    const bridge = frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED;
    if (bridge && typeof bridge.destroy === 'function') bridge.destroy();
    if (frame) frame.remove();
    frame = null;
    frameBridgePromise = null;
    currentNetwork = null;
    currentNonblank = false;
    document.removeEventListener('pointerdown', handleDocumentPointerDown);
    setWorkspaceVisible(false);
    setState('idle');
  }

  function getDiagnostics() {
    const bridge = frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED;
    const graph = bridge && typeof bridge.getDiagnostics === 'function'
      ? bridge.getDiagnostics()
      : {};
    return {
      state: currentState,
      visible,
      selection: currentSelection,
      stableSelection,
      availableContexts: currentAvailableContexts.slice(),
      iframeCount: els.frameHost.querySelectorAll('iframe').length,
      frameIdentity,
      nodeCount: Number(graph.nodeCount || currentNetwork?.nodes?.length || 0),
      edgeCount: Number(graph.edgeCount || currentNetwork?.edges?.length || 0),
      nonblank: currentNonblank && graph.nonblank !== false,
      layoutStopped: graph.layoutStopped === true,
      renderCount: Number(graph.renderCount || 0),
      loaderVisible: els.preloader.classList.contains('is-visible'),
      cacheKeys: [...networkCache.keys()],
      expressionEnabled,
      expressionCacheKeys: [...expressionSummaryCache.keys()],
      expressionAvailableCount: Object.values(currentExpressionOverlay.records || {}).filter((record) => record?.available === true).length,
      viewOptions: { ...currentViewOptions },
      requestCounts: { ...requestCounts },
      filterBusy,
      activeLegendFocus,
    };
  }

  window.__TEKG_COEXPRESSION_GRAPH_HOST = {
    setStatus(text) {
      if (currentState === 'rendering' && text) els.preloaderLabel.textContent = text;
    },
    setDetail(payload) {
      els.detail.textContent = payload?.description ? `${payload.title}: ${payload.description}` : String(payload?.title || '');
    },
    setDetailHtml(html) {
      els.detail.innerHTML = String(html || '');
    },
    onSelection() {},
    onReady() {},
    onNonblank(report) {
      currentNonblank = report?.nonblank === true;
    },
    onError(message) {
      setState('error', String(message || 'Unable to initialize Co-expression graph.'));
    },
  };

  els.searchType.addEventListener('change', () => {
    syncSearchType(els.searchType.value);
    awaitFeatureSelection(els.searchType.value);
  });
  els.te.addEventListener('input', () => {
    syncPendingFeatureControls();
  });
  els.te.addEventListener('change', () => {
    syncPendingFeatureControls();
  });
  els.load.addEventListener('click', () => {
    const pending = syncPendingFeatureControls(els.context.value || PREFERRED_CONTEXT);
    if (!pending) return;
    const coordinator = window.__TEKG_PREVIEW_WORKSPACE_MODE;
    if (coordinator && typeof coordinator.requestCoexpressionSelection === 'function') {
      void coordinator.requestCoexpressionSelection({
        feature: pending.feature,
        featureType: pending.featureType,
        context: pending.context,
      }, { history: 'push' });
      return;
    }
    void activate(pending);
  });
  els.te.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    els.load.click();
  });
  els.context.addEventListener('change', () => {
    const coordinator = window.__TEKG_PREVIEW_WORKSPACE_MODE;
    if (!selectionFeature(currentSelection) || !coordinator?.requestCoexpressionSelection) return;
    void coordinator.requestCoexpressionSelection({
      feature: selectionFeature(currentSelection),
      featureType: normalizeFeatureType(currentSelection.featureType),
      context: els.context.value,
    }, { history: 'push' });
  });
  els.expression.addEventListener('click', () => {
    void setExpressionEnabled(!expressionEnabled);
  });
  [els.showTe, els.showGene, els.edgeScope].forEach((control) => {
    control.addEventListener('change', () => {
      els.legendApply.disabled = false;
    });
  });
  els.legendApply.addEventListener('click', () => {
    void setViewOptions({
      showTE: els.showTe.checked,
      showGene: els.showGene.checked,
      edgeScope: els.edgeScope.value,
    });
  });
  els.legend.addEventListener('click', (event) => {
    if (event.target.closest('.graph-legend-check, select, button')) return;
    const row = event.target.closest('[data-highlight-kind][data-highlight-value]');
    if (!row) return;
    const focus = {
      kind: row.dataset.highlightKind,
      value: row.dataset.highlightValue,
    };
    const active = activeLegendFocus
      && activeLegendFocus.kind === focus.kind
      && activeLegendFocus.value === focus.value;
    void setLegendFocus(active ? null : focus);
  });
  els.legend.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const row = event.target.closest('[data-highlight-kind][data-highlight-value]');
    if (!row || event.target.matches('input, select, button')) return;
    event.preventDefault();
    row.click();
  });
  els.retry.addEventListener('click', () => {
    if (!selectionFeature(currentSelection)) return;
    void activate({ feature: selectionFeature(currentSelection), featureType: currentSelection.featureType, context: currentSelection.context });
  });
  els.exportToggle.addEventListener('click', toggleExportMenu);
  els.exportCsv.addEventListener('click', () => {
    closeExportMenu();
    void exportCsvFile().catch((error) => setState('error', error?.message || 'CSV export failed.'));
  });
  els.exportPng.addEventListener('click', () => {
    closeExportMenu();
    void exportPngFile().catch((error) => setState('error', error?.message || 'PNG export failed.'));
  });
  els.exportSvg.addEventListener('click', () => {
    closeExportMenu();
    void exportSvgFile().catch((error) => setState('error', error?.message || 'SVG export failed.'));
  });
  document.addEventListener('pointerdown', handleDocumentPointerDown);

  window.__TEKG_COEXPRESSION_MODE = {
    activate,
    deactivate,
    resume,
    destroy,
    setExpressionEnabled,
    setViewOptions,
    setLegendFocus,
    resolveExactTe,
    resolveExactFeature,
    exportCsvText,
    exportCsvFile,
    exportPngFile,
    exportSvgText,
    exportSvgFile,
    getDiagnostics,
  };

  window.TEKGTeAutocomplete?.registerSource?.('coexpression-catalog', {
    label: 'Co-expression TE',
    loadOptions() {
      return loadCatalog().then((nextCatalog) => nextCatalog.items.map((item) => ({ name: item.te })));
    },
  });
  window.TEKGTeAutocomplete?.registerSource?.('coexpression-gene-catalog', {
    label: 'Co-expression Gene',
    loadOptions() {
      return loadCatalog().then((nextCatalog) => nextCatalog.geneItems.map((item) => ({ name: item.gene })));
    },
  });
  syncSearchType();
  syncExpressionButton();
}());

