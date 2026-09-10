(function () {
  'use strict';

  const contract = window.__TEKG_COEXPRESSION_CONTRACT;
  const paths = window.__TEKG_PATHS;
  if (!contract || !paths) return;

  let activeController = null;

  function readConfig(root) {
    const node = root.querySelector('.expression-coexpression-config');
    if (!node) return null;
    try {
      return JSON.parse(node.textContent || '{}');
    } catch (_error) {
      return null;
    }
  }

  function createController(root) {
    const config = readConfig(root);
    if (!config || !config.te || !config.networkApiUrl || !config.frameUrl) return null;

    const elements = {
      toggle: root.querySelector('#expression-coexpression-toggle'),
      toggleIcon: root.querySelector('#expression-coexpression-toggle-icon'),
      context: root.querySelector('#expression-coexpression-context'),
      activity: root.querySelector('#expression-coexpression-activity'),
      frameHost: root.querySelector('#expression-coexpression-frame-host'),
      state: root.querySelector('#expression-coexpression-state'),
      stateText: root.querySelector('#expression-coexpression-state-text'),
      retry: root.querySelector('#expression-coexpression-retry'),
      exportWrap: root.querySelector('#expression-coexpression-export-wrap'),
      exportToggle: root.querySelector('#expression-coexpression-export-toggle'),
      exportMenu: root.querySelector('#expression-coexpression-export-menu'),
      exportCsv: root.querySelector('#expression-coexpression-export-csv'),
      exportPng: root.querySelector('#expression-coexpression-export-png'),
      exportSvg: root.querySelector('#expression-coexpression-export-svg'),
    };
    if (Object.values(elements).some((element) => !element)) return null;

    let frame = null;
    let bridgePromise = null;
    let network = null;
    let expressionEnabled = true;
    let requestEpoch = 0;
    let requestController = null;
    let started = false;
    let expanded = false;
    let destroyed = false;

    function setState(message, retryable) {
      const text = String(message || '').trim();
      elements.state.hidden = text === '';
      elements.stateText.textContent = text;
      elements.retry.hidden = !retryable;
      elements.exportToggle.disabled = text !== '' || !network;
      if (text !== '') closeExportMenu();
    }

    function networkUrl(context) {
      const url = new URL(config.networkApiUrl, window.location.origin);
      url.searchParams.set('action', 'network');
      url.searchParams.set('feature_type', 'TE');
      url.searchParams.set('te', config.te);
      url.searchParams.set('context', context);
      url.searchParams.set('evidence', 'coexpression');
      return url.toString();
    }

    async function fetchJson(url, options, timeoutMs) {
      const timeoutController = new AbortController();
      const callerSignal = options && options.signal;
      let timedOut = false;
      const abortFromCaller = function () { timeoutController.abort(); };
      if (callerSignal) {
        if (callerSignal.aborted) timeoutController.abort();
        else callerSignal.addEventListener('abort', abortFromCaller, { once: true });
      }
      const timeout = window.setTimeout(function () {
        timedOut = true;
        timeoutController.abort();
      }, timeoutMs);
      try {
        let response;
        try {
          response = await fetch(url, { ...options, signal: timeoutController.signal });
        } catch (error) {
          if (timedOut) throw new Error('The co-expression request timed out.');
          throw error;
        }
        const payload = await response.json().catch(function () { return null; });
        if (!response.ok || !payload || payload.ok !== true) {
          throw new Error(payload && payload.error && payload.error.message
            ? payload.error.message
            : 'The co-expression request failed.');
        }
        return payload;
      } finally {
        window.clearTimeout(timeout);
        if (callerSignal) callerSignal.removeEventListener('abort', abortFromCaller);
      }
    }

    function ensureFrame() {
      if (frame) return frame;
      frame = document.createElement('iframe');
      frame.title = 'Co-expression graph';
      frame.setAttribute('scrolling', 'no');
      const url = new URL(config.frameUrl, window.location.origin);
      if (config.version) url.searchParams.set('v', config.version);
      frame.src = url.toString();
      elements.frameHost.replaceChildren(frame);
      return frame;
    }

    function waitForBridge() {
      if (bridgePromise) return bridgePromise;
      ensureFrame();
      bridgePromise = new Promise(function (resolve, reject) {
        let attempts = 0;
        const check = function () {
          if (destroyed) {
            reject(new Error('The co-expression graph was closed.'));
            return;
          }
          attempts += 1;
          try {
            const bridge = frame && frame.contentWindow && frame.contentWindow.__TEKG_COEXPRESSION_EMBED;
            if (bridge && typeof bridge.renderNetwork === 'function') {
              resolve(bridge);
              return;
            }
          } catch (_error) {}
          if (attempts >= 100) {
            reject(new Error('The co-expression graph renderer is unavailable.'));
            return;
          }
          window.setTimeout(check, 50);
        };
        check();
      }).catch(function (error) {
        bridgePromise = null;
        throw error;
      });
      return bridgePromise;
    }

    function pureNetwork(payload) {
      const normalized = contract.normalizeNetwork(payload);
      const edges = normalized.edges.filter(function (edge) {
        return edge.edgeLabel === 'Co-expression';
      });
      const retained = new Set();
      edges.forEach(function (edge) {
        retained.add(String(edge.source));
        retained.add(String(edge.target));
      });
      normalized.nodes.forEach(function (node) {
        if (node.isCenter) retained.add(String(node.id));
      });
      return {
        ...normalized,
        nodes: normalized.nodes.filter(function (node) { return retained.has(String(node.id)); }),
        edges,
      };
    }

    function expressionNames(currentNetwork) {
      return Array.from(new Set((currentNetwork.nodes || []).map(function (node) {
        return String(node.label || node.id || '').trim();
      }).filter(Boolean))).slice(0, 80);
    }

    function buildExpressionOverlay(currentNetwork, records) {
      const context = String(currentNetwork.selection && currentNetwork.selection.context || elements.context.value);
      const mapped = {};
      (records || []).forEach(function (record) {
        const key = String(record.requested_name || record.te_name || '').trim().toLowerCase();
        if (key) mapped[key] = record;
      });
      const values = expressionNames(currentNetwork).map(function (name) {
        const record = mapped[name.toLowerCase()];
        const value = Number(record && record[context] && record[context].median_value);
        return record && record.available === true && Number.isFinite(value) ? value : null;
      }).filter(function (value) { return value !== null; });
      return {
        enabled: expressionEnabled,
        context,
        context_label: context.replaceAll('_', ' '),
        records: mapped,
        min_value: values.length ? Math.min.apply(Math, values) : 0,
        max_value: values.length ? Math.max.apply(Math, values) : 0,
      };
    }

    async function expressionOverlay(currentNetwork, signal) {
      if (!expressionEnabled || !config.expressionApiUrl) return buildExpressionOverlay(currentNetwork, []);
      const payload = await fetchJson(config.expressionApiUrl, {
        method: 'POST',
        signal,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          te_names: expressionNames(currentNetwork),
          context: elements.context.value,
        }),
      }, 8000);
      return buildExpressionOverlay(currentNetwork, Array.isArray(payload.records) ? payload.records : []);
    }

    async function load() {
      const epoch = ++requestEpoch;
      if (requestController) requestController.abort();
      requestController = new AbortController();
      network = null;
      setState('', false);
      try {
        const payload = await fetchJson(networkUrl(elements.context.value), {
          signal: requestController.signal,
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          cache: 'no-store',
        }, 15000);
        if (epoch !== requestEpoch || destroyed) return;
        const nextNetwork = pureNetwork(payload);
        if (!nextNetwork.nodes.length || !nextNetwork.edges.length) {
          throw new Error('No co-expression relationships are available for this dataset.');
        }
        const bridge = await waitForBridge();
        if (epoch !== requestEpoch || destroyed) return;
        let overlay = buildExpressionOverlay(nextNetwork, []);
        try {
          overlay = await expressionOverlay(nextNetwork, requestController.signal);
        } catch (error) {
          if (error && error.name === 'AbortError') throw error;
        }
        if (epoch !== requestEpoch || destroyed) return;
        await bridge.setExpressionOverlay(overlay);
        const report = await bridge.renderNetwork(nextNetwork);
        if (epoch !== requestEpoch || destroyed) return;
        if (!report || report.nonblank !== true) throw new Error('The co-expression graph remained blank.');
        network = nextNetwork;
        setState('', false);
      } catch (error) {
        if (error && error.name === 'AbortError') return;
        if (epoch !== requestEpoch || destroyed) return;
        setState(error && error.message ? error.message : 'Unable to load the co-expression graph.', true);
      }
    }

    async function toggleActivity() {
      expressionEnabled = !expressionEnabled;
      elements.activity.classList.toggle('is-active', expressionEnabled);
      elements.activity.setAttribute('aria-pressed', expressionEnabled ? 'true' : 'false');
      elements.activity.textContent = 'Expression activity: ' + (expressionEnabled ? 'On' : 'Off');
      if (!network) return;
      const epoch = ++requestEpoch;
      if (requestController) requestController.abort();
      requestController = new AbortController();
      try {
        const overlay = await expressionOverlay(network, requestController.signal);
        if (epoch !== requestEpoch || destroyed) return;
        const bridge = await waitForBridge();
        await bridge.setExpressionOverlay(overlay);
      } catch (error) {
        if (error && error.name === 'AbortError') return;
        const bridge = await waitForBridge();
        await bridge.setExpressionOverlay(buildExpressionOverlay(network, []));
      }
    }

    function csvCell(value) {
      const text = value === null || value === undefined ? '' : String(value);
      return /[",\r\n]/.test(text) ? '"' + text.replaceAll('"', '""') + '"' : text;
    }

    function safeFilename(value) {
      return String(value || 'coexpression').trim().replace(/[^a-z0-9_.-]+/gi, '_').replace(/^_+|_+$/g, '') || 'coexpression';
    }

    function download(filename, content, mime) {
      const blob = content instanceof Blob ? content : new Blob([content], { type: mime });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    async function visibleGraph() {
      const bridge = await waitForBridge();
      if (!network || typeof bridge.getVisibleSubgraph !== 'function') throw new Error('The graph is not ready for export.');
      return bridge.getVisibleSubgraph();
    }

    async function exportCsv() {
      const graph = await visibleGraph();
      const rows = [['record_type', 'id', 'label', 'feature_type', 'source', 'target', 'correlation', 'fdr', 'pair_type']];
      (graph.nodes || []).forEach(function (node) {
        rows.push(['node', node.id, node.label, node.type, '', '', '', '', '']);
      });
      (graph.edges || []).forEach(function (edge) {
        rows.push(['edge', edge.id, '', '', edge.source, edge.target, edge.correlation, edge.fdr, edge.pair_type]);
      });
      const text = rows.map(function (row) { return row.map(csvCell).join(','); }).join('\r\n') + '\r\n';
      download('coexpression_' + safeFilename(config.te) + '_' + safeFilename(elements.context.value) + '.csv', '\uFEFF' + text, 'text/csv;charset=utf-8');
    }

    async function exportPng() {
      const bridge = await waitForBridge();
      const dataUrl = await bridge.exportPngDataUrl();
      if (!String(dataUrl || '').startsWith('data:image/png')) throw new Error('The PNG export is blank.');
      const response = await fetch(dataUrl);
      download('coexpression_' + safeFilename(config.te) + '_' + safeFilename(elements.context.value) + '.png', await response.blob(), 'image/png');
    }

    async function exportSvg() {
      const bridge = await waitForBridge();
      const svg = await bridge.exportSvgString();
      if (!String(svg || '').startsWith('<svg')) throw new Error('The SVG export is blank.');
      download('coexpression_' + safeFilename(config.te) + '_' + safeFilename(elements.context.value) + '.svg', svg, 'image/svg+xml;charset=utf-8');
    }

    function closeExportMenu() {
      elements.exportMenu.hidden = true;
      elements.exportToggle.setAttribute('aria-expanded', 'false');
    }

    function runExport(task) {
      closeExportMenu();
      task().catch(function (error) {
        setState(error && error.message ? error.message : 'Export failed.', true);
      });
    }

    function start() {
      if (started || destroyed) return;
      started = true;
      ensureFrame();
      load();
    }

    function setExpanded(nextExpanded) {
      expanded = Boolean(nextExpanded);
      root.classList.toggle('is-collapsed', !expanded);
      elements.toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      elements.toggle.setAttribute('aria-label', expanded ? 'Collapse co-expression graph' : 'Expand co-expression graph');
      elements.toggle.title = expanded ? 'Collapse co-expression graph' : 'Expand co-expression graph';
      elements.toggleIcon.innerHTML = expanded ? '&#9652;' : '&#9662;';
      if (expanded) start();
    }

    elements.toggle.addEventListener('click', function () { setExpanded(!expanded); });
    elements.context.addEventListener('change', load);
    elements.activity.addEventListener('click', toggleActivity);
    elements.retry.addEventListener('click', load);
    elements.exportToggle.addEventListener('click', function () {
      const open = elements.exportMenu.hidden;
      elements.exportMenu.hidden = !open;
      elements.exportToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    elements.exportCsv.addEventListener('click', function () { runExport(exportCsv); });
    elements.exportPng.addEventListener('click', function () { runExport(exportPng); });
    elements.exportSvg.addEventListener('click', function () { runExport(exportSvg); });
    const handlePointerDown = function (event) {
      if (!elements.exportWrap.contains(event.target)) closeExportMenu();
    };
    document.addEventListener('pointerdown', handlePointerDown);

    window.__TEKG_COEXPRESSION_GRAPH_HOST = {
      setStatus: function () {},
      setDetail: function () {},
      setDetailHtml: function () {},
      onSelection: function () {},
      onReady: function () {},
      onNonblank: function () {},
      onError: function (message) { setState(message || 'The graph renderer failed.', true); },
    };

    setExpanded(false);

    return {
      load,
      expand: function () { setExpanded(true); },
      destroy: function () {
        destroyed = true;
        requestEpoch += 1;
        if (requestController) requestController.abort();
        document.removeEventListener('pointerdown', handlePointerDown);
        const bridge = frame && frame.contentWindow && frame.contentWindow.__TEKG_COEXPRESSION_EMBED;
        if (bridge && typeof bridge.destroy === 'function') bridge.destroy();
        if (frame) frame.remove();
      },
      getState: function () {
        return { started, expanded, expressionEnabled, context: elements.context.value, network };
      },
    };
  }

  function init(root) {
    if (activeController) activeController.destroy();
    activeController = null;
    const panel = (root || document).querySelector('[data-expression-coexpression]');
    if (panel) activeController = createController(panel);
    return activeController;
  }

  window.__TEKG_EXPRESSION_COEXPRESSION = {
    init,
    expand: function () { if (activeController) activeController.expand(); },
  };
}());
