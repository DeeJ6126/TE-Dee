(function () {
  const form = document.getElementById('pathFinderForm');
  const sourceTypeSelect = document.getElementById('pathSourceType');
  const sourceInput = document.getElementById('pathSource');
  const targetTypeSelect = document.getElementById('pathTargetType');
  const targetInput = document.getElementById('pathTarget');
  const maxDepthSelect = document.getElementById('pathMaxDepth');
  const submitButton = document.getElementById('pathSubmit');
  const statusEl = document.getElementById('pathStatus');
  const resultsEl = document.getElementById('pathResults');
  const viewToggleEl = document.getElementById('pathViewToggle');
  const tableViewButton = document.getElementById('pathTableView');
  const graphViewButton = document.getElementById('pathGraphView');
  const graphPanelEl = document.getElementById('pathGraphPanel');
  const graphSurfaceEl = document.getElementById('pathGraphSurface');
  const graphShowRelationsButton = document.getElementById('pathGraphShowRelations');
  const graphExportButton = document.getElementById('pathGraphExport');
  const graphExportMenu = document.getElementById('pathGraphExportMenu');
  const graphExportCsvButton = document.getElementById('pathGraphExportCsv');
  const graphExportPngButton = document.getElementById('pathGraphExportPng');
  const graphExportSvgButton = document.getElementById('pathGraphExportSvg');

  if (!form || !sourceTypeSelect || !sourceInput || !targetTypeSelect || !targetInput || !maxDepthSelect || !submitButton || !statusEl || !resultsEl) {
    return;
  }

  let currentPayload = null;
  let currentView = 'table';
  let graphRunner = null;
  let graphInitPromise = null;
  let graphShowRelations = true;

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

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function pubmedUrl(pmid) {
    return `https://pubmed.ncbi.nlm.nih.gov/${encodeURIComponent(String(pmid || '').trim())}/`;
  }

  function evidenceValue(value) {
    if (value === null || value === undefined || String(value).trim() === '') {
      return '—';
    }
    return String(value).trim();
  }

  function evidenceMetricValue(value) {
    if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) {
      return '—';
    }
    return Number(value).toFixed(1).replace(/\.0$/, '');
  }

  function compactText(value, maxLength) {
    const text = String(value || '').trim();
    if (text.length <= maxLength) {
      return text;
    }
    return `${text.slice(0, Math.max(0, maxLength - 1)).trim()}…`;
  }

  function uniqueStringArray(values) {
    const seen = new Set();
    const out = [];
    (Array.isArray(values) ? values : []).forEach((value) => {
      const text = String(value || '').trim();
      if (!text || seen.has(text)) {
        return;
      }
      seen.add(text);
      out.push(text);
    });
    return out;
  }

  function uniqueEvidenceRecords(records) {
    const seen = new Set();
    const out = [];
    (Array.isArray(records) ? records : []).forEach((record) => {
      if (!record || typeof record !== 'object') {
        return;
      }
      const pmid = String(record.pmid || '').trim();
      const key = pmid || JSON.stringify(record);
      if (!key || seen.has(key)) {
        return;
      }
      seen.add(key);
      out.push({ ...record, pmid });
    });
    return out;
  }

  function graphNodeId(node) {
    return String(node && (node.element_id || node.id || node.name) ? (node.element_id || node.id || node.name) : '').trim();
  }

  function graphNodeLabel(node) {
    return String(node && (node.name || node.label || node.rawLabel || node.id) ? (node.name || node.label || node.rawLabel || node.id) : '').trim();
  }

  function relationName(edge) {
    const label = String(edge && (edge.relation_label || edge.relation || edge.predicate) ? (edge.relation_label || edge.relation || edge.predicate) : '').trim();
    if (label && label !== 'BIO_RELATION') {
      return label;
    }
    return 'related to';
  }

  function mergeEvidenceText(existing, next) {
    const values = uniqueStringArray([existing, next].flatMap((value) => String(value || '').split(/\n+/)));
    return values.join('\n');
  }

  function supportStats(pmids, records) {
    const safeRecords = Array.isArray(records) ? records : [];
    const metricValues = [];
    const years = [];
    const quartiles = { Q1: 0, Q2: 0, Q3: 0, Q4: 0 };
    uniqueEvidenceRecords(safeRecords).forEach((record) => {
      const metric = Number(record.journal_metric_value);
      if (Number.isFinite(metric)) {
        metricValues.push(metric);
      }
      const year = Number(record.pubmed_publication_year);
      if (Number.isFinite(year)) {
        years.push(year);
      }
      const quartile = String(record.journal_jcr_quartile || '').trim().toUpperCase();
      if (Object.prototype.hasOwnProperty.call(quartiles, quartile)) {
        quartiles[quartile] += 1;
      }
    });
    metricValues.sort((left, right) => left - right);
    const metricSum = metricValues.reduce((sum, value) => sum + value, 0);
    const median = metricValues.length
      ? metricValues[Math.floor((metricValues.length - 1) / 2)]
      : null;
    return {
      support_pmid_count: pmids.length,
      support_metric_paper_count: metricValues.length,
      support_metric_coverage: pmids.length ? metricValues.length / pmids.length : 0,
      support_if_max: metricValues.length ? Math.max(...metricValues) : null,
      support_if_mean: metricValues.length ? metricSum / metricValues.length : null,
      support_if_median: median,
      support_jcr_q1_count: quartiles.Q1,
      support_jcr_q2_count: quartiles.Q2,
      support_jcr_q3_count: quartiles.Q3,
      support_jcr_q4_count: quartiles.Q4,
      support_journal_count: uniqueStringArray(safeRecords.map((record) => record && record.pubmed_journal_title)).length,
      support_publication_year_min: years.length ? Math.min(...years) : null,
      support_publication_year_max: years.length ? Math.max(...years) : null,
    };
  }

  function buildGraphElements(payload) {
    const paths = Array.isArray(payload && payload.paths) ? payload.paths : [];
    const nodeMap = new Map();
    const edgeMap = new Map();
    const degreeCounts = new Map();

    paths.forEach((path) => {
      (Array.isArray(path.nodes) ? path.nodes : []).forEach((node) => {
        const id = graphNodeId(node);
        if (!id || nodeMap.has(id)) {
          return;
        }
        nodeMap.set(id, {
          id,
          label: graphNodeLabel(node) || id,
          rawLabel: graphNodeLabel(node) || id,
          type: nodeType(node),
          description: String(node && node.description ? node.description : ''),
          pmid: String(node && node.pmid ? node.pmid : ''),
          disease_class: String(node && node.disease_class ? node.disease_class : ''),
        });
      });

      (Array.isArray(path.edges) ? path.edges : []).forEach((edge) => {
        const source = String(edge && edge.source ? edge.source : '').trim();
        const target = String(edge && edge.target ? edge.target : '').trim();
        if (!source || !target) {
          return;
        }
        const relation = relationName(edge);
        const key = [source, target, relation].join('::');
        if (!edgeMap.has(key)) {
          edgeMap.set(key, {
            id: `path_edge_${edgeMap.size + 1}`,
            source,
            target,
            relation,
            relationType: relation,
            raw_relation_type: String(edge && edge.relation_type ? edge.relation_type : ''),
            evidence: '',
            pmids: [],
            evidence_records: [],
          });
        }
        const entry = edgeMap.get(key);
        entry.evidence = mergeEvidenceText(entry.evidence, edge && edge.evidence);
        entry.pmids = uniqueStringArray([...entry.pmids, ...(Array.isArray(edge && edge.pmids) ? edge.pmids : [])]);
        entry.evidence_records = uniqueEvidenceRecords([
          ...entry.evidence_records,
          ...(Array.isArray(edge && edge.evidence_records) ? edge.evidence_records : []),
        ]);
      });
    });

    edgeMap.forEach((edge) => {
      degreeCounts.set(edge.source, (degreeCounts.get(edge.source) || 0) + 1);
      degreeCounts.set(edge.target, (degreeCounts.get(edge.target) || 0) + 1);
    });

    const nodeElements = Array.from(nodeMap.values()).map((node) => ({
      data: {
        ...node,
        degree: Math.max(1, degreeCounts.get(node.id) || 1),
      },
    }));

    const edgeElements = Array.from(edgeMap.values()).map((edge) => {
      const stats = supportStats(edge.pmids, edge.evidence_records);
      return {
        data: {
          ...edge,
          ...stats,
        },
      };
    });

    return [...nodeElements, ...edgeElements];
  }

  function setExportMenuOpen(isOpen) {
    if (!graphExportMenu || !graphExportButton || graphExportButton.disabled) {
      return;
    }
    graphExportMenu.hidden = !isOpen;
    graphExportButton.setAttribute('aria-expanded', String(isOpen));
  }

  function closeExportMenu() {
    if (graphExportMenu) {
      graphExportMenu.hidden = true;
    }
    if (graphExportButton) {
      graphExportButton.setAttribute('aria-expanded', 'false');
    }
  }

  function setExportEnabled(isEnabled) {
    if (graphExportButton) {
      graphExportButton.disabled = !isEnabled;
    }
    [graphExportCsvButton, graphExportPngButton, graphExportSvgButton].forEach((button) => {
      if (button) {
        button.disabled = !isEnabled;
      }
    });
    if (!isEnabled) {
      closeExportMenu();
    }
  }

  function csvEscape(value) {
    const text = Array.isArray(value) ? value.join('|') : String(value ?? '');
    return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
  }

  function buildCsv(rows, fields) {
    const header = fields.map((field) => csvEscape(field.key)).join(',');
    const body = rows.map((row) => fields.map((field) => csvEscape(field.get(row))).join(','));
    return [header, ...body].join('\r\n') + '\r\n';
  }

  function safeExportName(value) {
    return (String(value || 'path-finder').trim() || 'path-finder')
      .replace(/[^a-z0-9_.-]+/gi, '_')
      .replace(/^_+|_+$/g, '') || 'path-finder';
  }

  function exportDateStamp() {
    return new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
  }

  function downloadText(filename, content, mimeType) {
    const url = URL.createObjectURL(new Blob([content], { type: mimeType }));
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

  function ensureGraphRunner() {
    if (graphRunner) {
      return graphInitPromise || Promise.resolve();
    }
    if (!graphSurfaceEl || !window.__TEKG_G6_SHARED || typeof window.__TEKG_G6_SHARED.createRunner !== 'function') {
      return Promise.reject(new Error('G6 graph renderer is unavailable on this page.'));
    }
    graphRunner = window.__TEKG_G6_SHARED.createRunner({
      container: graphSurfaceEl,
      initialFixedView: true,
      initialShowAllLabels: true,
      initialKeyNodeLevel: 1,
      initialLang: 'en',
      initialAllowNodeActions: false,
      syncRouteState: () => {},
      setStatus: () => {},
      setDetail: () => {},
      setDetailHtml: () => {},
      setMode: () => {},
      setQueryUi: () => {},
      onSelection: () => {},
      onDiseaseClassClick: () => false,
      onNodeExpand: () => false,
      onReady: () => {},
    });
    graphInitPromise = Promise.resolve(graphRunner.init());
    return graphInitPromise;
  }

  function updateGraphDebugBridge() {
    window.__TEKG_PATH_FINDER_GRAPH_DEBUG = {
      getVisibleSubgraph() {
        if (!graphRunner || typeof graphRunner.getVisibleSubgraph !== 'function') {
          return null;
        }
        return graphRunner.getVisibleSubgraph();
      },
      inspectEdge(edgeId) {
        if (!graphRunner || typeof graphRunner.inspectEdge !== 'function') {
          return false;
        }
        return graphRunner.inspectEdge(edgeId);
      },
    };
  }

  async function renderGraphFromCurrentPayload() {
    if (!currentPayload || !Array.isArray(currentPayload.paths) || !currentPayload.paths.length) {
      return;
    }
    if (!graphPanelEl || !graphSurfaceEl) {
      return;
    }
    setExportEnabled(false);
    const elements = buildGraphElements(currentPayload);
    const source = nodeLabel(currentPayload.source || {});
    const target = nodeLabel(currentPayload.target || {});
    const endpointHighlightIds = uniqueStringArray([
      graphNodeId(currentPayload.source || {}),
      graphNodeId(currentPayload.target || {}),
    ]);
    await ensureGraphRunner();
    await graphRunner.renderElements(elements, { query: `${source} to ${target}` }, {
      skipInitialStatus: true,
      graphDataOptions: {
        showAllLabels: true, // Path results always keep node names visible.
        showEdgeLabels: graphShowRelations, // 含义：是否显示边上的关系名称。TE-Dee default: controlled by Show relations, initially false.
        allowInspectCard: true, // Reuse the Knowledge Graph relation card for edge evidence.
        allowNodeActions: false, // 含义：是否允许节点卡片里的 Jump/Expand 按钮。TE-Dee default: true; Path Finder disables Jump/Expand.
        restrictToAnchorComponent: false, // 含义：过滤后是否只保留与 anchor 连通的组件。TE-Dee default: true.
        forceAnchorLabel: true, // 含义：是否强制显示第一个/anchor 节点标签。TE-Dee default: false.
        preferFullLabels: true, // 含义：是否优先显示完整节点名，而不是为了塞进圆里而截断。TE-Dee default: false.
        endpointHighlightIds, // 含义：需要 source/target ripple 高光的节点 id。TE-Dee default: [].
        nodeSizeScale: 1.5, // 含义：所有节点直径的整体倍率。TE-Dee default: 1.
        nodeMinSize: 50, // 含义：普通节点最小直径。TE-Dee default: 0.
        endpointNodeMinSize: 0, // 含义：搜索起点/终点节点的最小直径。TE-Dee default: 0.
        layoutDistanceScale: 3, // 含义：边/连接的理想长度倍率；越大节点越分散。TE-Dee default: 1.
        collisionPaddingScale: 8, // 含义：节点碰撞安全距离倍率；越大越不容易重叠。TE-Dee default: 1.
        collisionIterations: 52, // 含义：碰撞计算迭代次数；越大越努力分开节点，但更耗时。TE-Dee default: 16.
        chargeScale: 4, // 含义：节点之间排斥力倍率；越大整体越向外散开。TE-Dee default: 1.
        edgeLabelFontSize: 15, // 含义：边上关系名称的字体大小。TE-Dee default: 9.
      },
    });
    updateGraphDebugBridge();
    setExportEnabled(true);
  }

  function updateViewButtons() {
    if (!tableViewButton || !graphViewButton) {
      return;
    }
    const isGraph = currentView === 'graph';
    tableViewButton.classList.toggle('is-active', !isGraph);
    graphViewButton.classList.toggle('is-active', isGraph);
    tableViewButton.setAttribute('aria-pressed', String(!isGraph));
    graphViewButton.setAttribute('aria-pressed', String(isGraph));
  }

  function updateGraphToggleButtons() {
    if (graphShowRelationsButton) {
      graphShowRelationsButton.classList.toggle('is-on', graphShowRelations);
      graphShowRelationsButton.setAttribute('aria-pressed', String(graphShowRelations));
      graphShowRelationsButton.textContent = `Show relations: ${graphShowRelations ? 'On' : 'Off'}`;
    }
  }

  function setResultView(view) {
    currentView = view === 'graph' ? 'graph' : 'table';
    updateViewButtons();
    resultsEl.hidden = currentView === 'graph';
    if (graphPanelEl) {
      graphPanelEl.hidden = currentView !== 'graph';
    }
    if (currentView === 'graph') {
      renderGraphFromCurrentPayload().catch((error) => {
        statusEl.textContent = `${error && error.message ? error.message : 'Graph rendering failed.'}`;
        setExportEnabled(false);
      });
    }
  }

  function clearGraphResult() {
    if (graphPanelEl) {
      graphPanelEl.hidden = true;
    }
    if (graphSurfaceEl) {
      graphSurfaceEl.innerHTML = '';
    }
    setExportEnabled(false);
    if (window.__TEKG_PATH_FINDER_GRAPH_DEBUG) {
      delete window.__TEKG_PATH_FINDER_GRAPH_DEBUG;
    }
  }

  function setLoading(isLoading) {
    submitButton.disabled = !!isLoading;
    sourceTypeSelect.disabled = !!isLoading;
    sourceInput.disabled = !!isLoading;
    targetTypeSelect.disabled = !!isLoading;
    targetInput.disabled = !!isLoading;
    maxDepthSelect.disabled = !!isLoading;
  }

  function updateEntityPlaceholder(select, input) {
    const type = String(select.value || 'entity').trim();
    const example = entityExamples[type] || type;
    input.placeholder = `Select a ${type} entity such as ${example}`;
  }

  function nodeLabel(node) {
    const name = String(node && node.name ? node.name : '').trim();
    return name || 'Unnamed entity';
  }

  function nodeType(node) {
    return String(node && node.type ? node.type : 'Node').trim() || 'Node';
  }

  function evidencePanelId(path, edgeIndex) {
    const pathId = String(path.id || 'path').replace(/[^a-z0-9_-]+/gi, '-');
    return `${pathId}-evidence-${edgeIndex + 1}`;
  }

  function renderCompactPath(path) {
    const nodes = Array.isArray(path.nodes) ? path.nodes : [];
    const parts = [];
    nodes.forEach((node, index) => {
      if (index > 0) {
        const edge = Array.isArray(path.edges) ? path.edges[index - 1] : null;
        const edgeIndex = index - 1;
        const relation = relationName(edge);
        const panelId = evidencePanelId(path, edgeIndex);
        parts.push(`
          <button class="compact-path-edge" type="button" data-path-edge-index="${edgeIndex}" aria-expanded="false" aria-controls="${escapeHtml(panelId)}" aria-label="Show literature supporting ${escapeHtml(relation)}">
            <span class="compact-path-edge-label">${escapeHtml(relation)}</span>
            <span class="compact-path-edge-arrow" aria-hidden="true"></span>
          </button>
        `);
      }
      parts.push(`<span class="compact-path-node"><strong>${escapeHtml(nodeLabel(node))}</strong><small>${escapeHtml(nodeType(node))}</small></span>`);
    });
    return `<div class="compact-path-strip" aria-label="Path relationships">${parts.join('')}</div>`;
  }

  function evidenceRecordsForEdge(edge) {
    const records = Array.isArray(edge && edge.evidence_records) ? edge.evidence_records : [];
    if (records.length) {
      return records
        .filter((record) => record && typeof record === 'object')
        .map((record) => ({
          pmid: String(record.pmid || '').trim(),
          pubmed_url: String(record.pubmed_url || '').trim(),
          pubmed_title: record.pubmed_title ?? null,
          pubmed_journal_title: record.pubmed_journal_title ?? null,
          pubmed_publication_year: record.pubmed_publication_year ?? null,
          journal_metric_value: record.journal_metric_value ?? null,
          journal_metric_source: record.journal_metric_source ?? null,
          journal_metric_year: record.journal_metric_year ?? null,
          journal_jcr_quartile: record.journal_jcr_quartile ?? null,
          journal_metric_match_method: record.journal_metric_match_method ?? null,
        }))
        .filter((record) => record.pmid);
    }

    return (Array.isArray(edge && edge.pmids) ? edge.pmids : [])
      .map((pmid) => String(pmid || '').trim())
      .filter(Boolean)
      .map((pmid) => ({
        pmid,
        pubmed_url: pubmedUrl(pmid),
        pubmed_title: null,
        pubmed_journal_title: null,
        pubmed_publication_year: null,
        journal_metric_value: null,
        journal_metric_source: null,
        journal_metric_year: null,
        journal_jcr_quartile: null,
        journal_metric_match_method: null,
      }));
  }

  function renderEvidenceTable(edge) {
    const records = evidenceRecordsForEdge(edge);
    if (!records.length) {
      return '<div class="path-no-pmid">No PMID evidence attached to this edge.</div>';
    }

    const rows = records.map((record) => {
      const title = String(record.pubmed_title || '').trim();
      const journal = String(record.pubmed_journal_title || '').trim();
      const compactTitle = title ? compactText(title, 96) : '—';
      const url = record.pubmed_url || pubmedUrl(record.pmid);
      return [
        '<tr>',
        `<td><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(record.pmid)}</a></td>`,
        `<td>${escapeHtml(evidenceValue(record.pubmed_publication_year))}</td>`,
        `<td title="${escapeHtml(journal)}"><span class="edge-evidence-cell-compact">${escapeHtml(evidenceValue(record.pubmed_journal_title))}</span></td>`,
        `<td>${escapeHtml(evidenceMetricValue(record.journal_metric_value))}</td>`,
        `<td>${escapeHtml(evidenceValue(record.journal_jcr_quartile))}</td>`,
        `<td>${escapeHtml(evidenceValue(record.journal_metric_match_method))}</td>`,
        `<td class="edge-evidence-title-cell" title="${escapeHtml(title)}"><span class="edge-evidence-cell-compact">${escapeHtml(compactTitle)}</span></td>`,
        '</tr>',
      ].join('');
    }).join('');

    return [
      '<div class="edge-evidence-table-wrap">',
      '<table class="edge-evidence-table">',
      '<thead><tr><th>PMID</th><th>Year</th><th>Journal</th><th>IF</th><th>JCR</th><th>Match</th><th>Title</th></tr></thead>',
      `<tbody>${rows}</tbody>`,
      '</table>',
      '</div>',
    ].join('');
  }

  function renderEvidence(path) {
    const edges = Array.isArray(path.edges) ? path.edges : [];
    return edges.map((edge, index) => {
      const evidence = String(edge.evidence || '').trim();
      const panelId = evidencePanelId(path, index);
      const pmidCount = Number(edge.pmid_count || 0);
      return `
        <section class="path-evidence-detail" id="${escapeHtml(panelId)}" data-path-edge-index="${index}" hidden>
          <header class="path-evidence-head">
            <strong>${escapeHtml(relationName(edge) || 'Relationship')}</strong>
            <span>${pmidCount} PMID${pmidCount === 1 ? '' : 's'}</span>
          </header>
          <div class="path-evidence-body">
            ${renderEvidenceTable(edge)}
            ${evidence ? `<p>${escapeHtml(evidence)}</p>` : ''}
          </div>
        </section>
      `;
    }).join('');
  }

  function renderPath(path, index) {
    const direct = Number(path.hop_count || 0) <= 1;
    return `
      <article class="path-card ${direct ? 'is-direct' : 'is-multihop'}">
        <header class="path-card-header">
          <div>
            <span class="path-rank">Path ${index + 1}</span>
          </div>
          <div class="path-metrics">
            <span class="path-command-control">${Number(path.hop_count || 0)} hop${Number(path.hop_count || 0) === 1 ? '' : 's'}</span>
            <span class="path-command-control">${Number(path.pmid_count || 0)} PMID${Number(path.pmid_count || 0) === 1 ? '' : 's'}</span>
          </div>
        </header>
        ${renderCompactPath(path)}
        <div class="path-evidence-details" aria-live="polite">${renderEvidence(path)}</div>
      </article>
    `;
  }

  function renderResults(payload) {
    currentPayload = payload;
    const paths = Array.isArray(payload.paths) ? payload.paths : [];
    const searchedThroughHop = Math.max(0, Number(payload.searched_through_hop || 0));
    const safetyLimitText = searchedThroughHop > 0
      ? `Search reached hop ${searchedThroughHop} before the safety limit.`
      : 'Search stopped at the safety limit.';
    if (!paths.length) {
      statusEl.textContent = payload.search_truncated === true
        ? `No path was returned within ${Number(payload.max_depth || 3)} hops. ${safetyLimitText}`
        : `No path was found within ${Number(payload.max_depth || 3)} hops.`;
      resultsEl.innerHTML = '';
      if (viewToggleEl) {
        viewToggleEl.hidden = true;
      }
      resultsEl.hidden = false;
      clearGraphResult();
      return;
    }
    statusEl.textContent = payload.search_truncated === true
      ? `Showing ${paths.length} path${paths.length === 1 ? '' : 's'} within ${Number(payload.max_depth || 3)} hops. ${safetyLimitText}`
      : `${paths.length} path${paths.length === 1 ? '' : 's'} found within ${Number(payload.max_depth || 3)} hops.`;
    resultsEl.innerHTML = paths.map(renderPath).join('');
    if (viewToggleEl) {
      viewToggleEl.hidden = false;
    }
    setResultView(currentView);
  }

  resultsEl.addEventListener('click', (event) => {
    const edgeButton = event.target instanceof Element
      ? event.target.closest('.compact-path-edge')
      : null;
    if (!edgeButton || !resultsEl.contains(edgeButton)) {
      return;
    }
    const pathCard = edgeButton.closest('.path-card');
    if (!pathCard) {
      return;
    }
    const edgeIndex = String(edgeButton.dataset.pathEdgeIndex || '');
    const expanded = edgeButton.getAttribute('aria-expanded') !== 'true';
    pathCard.querySelectorAll('.compact-path-edge').forEach((button) => {
      button.setAttribute('aria-expanded', 'false');
      button.classList.remove('is-active');
    });
    pathCard.querySelectorAll('.path-evidence-detail').forEach((panel) => {
      panel.hidden = true;
    });
    if (!expanded) return;

    const evidencePanel = pathCard.querySelector(`.path-evidence-detail[data-path-edge-index="${edgeIndex}"]`);
    edgeButton.setAttribute('aria-expanded', 'true');
    edgeButton.classList.add('is-active');
    if (evidencePanel) {
      evidencePanel.hidden = false;
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const source = sourceInput.value.trim();
    const target = targetInput.value.trim();
    const sourceType = sourceTypeSelect.value || 'TE';
    const targetType = targetTypeSelect.value || 'Disease';
    const maxDepth = maxDepthSelect.value || '3';

    resultsEl.innerHTML = '';
    resultsEl.hidden = false;
    currentPayload = null;
    if (viewToggleEl) {
      viewToggleEl.hidden = true;
    }
    clearGraphResult();

    if (!source || !target) {
      statusEl.textContent = 'Both source and target are required.';
      return;
    }

    setLoading(true);
    statusEl.textContent = 'Searching paths...';
    try {
      const params = new URLSearchParams({ source, target, source_type: sourceType, target_type: targetType, max_depth: maxDepth });
      const response = await fetch(window.__TEKG_PATHS.apiUrl(`path_finder.php?${params.toString()}`), { credentials: 'same-origin' });
      const payload = await response.json();
      if (!response.ok || payload.ok !== true) {
        throw new Error(payload.error || `Path Finder API HTTP ${response.status}`);
      }
      renderResults(payload);
    } catch (error) {
      statusEl.textContent = `${error && error.message ? error.message : 'Path Finder failed.'} Check spelling or search the entity first.`;
    } finally {
      setLoading(false);
    }
  });

  if (tableViewButton) {
    tableViewButton.addEventListener('click', () => setResultView('table'));
  }
  if (graphViewButton) {
    graphViewButton.addEventListener('click', () => setResultView('graph'));
  }
  if (graphShowRelationsButton) {
    graphShowRelationsButton.addEventListener('click', () => {
      graphShowRelations = !graphShowRelations;
      updateGraphToggleButtons();
      if (currentView === 'graph') {
        renderGraphFromCurrentPayload().catch((error) => {
          statusEl.textContent = error && error.message ? error.message : 'Graph rendering failed.';
        });
      }
    });
  }
  if (graphExportButton) {
    graphExportButton.addEventListener('click', (event) => {
      event.stopPropagation();
      setExportMenuOpen(!!(graphExportMenu && graphExportMenu.hidden));
    });
  }

  async function runGraphExport(kind) {
    if (!graphRunner) {
      throw new Error('Graph export is not available yet.');
    }
    const query = `${nodeLabel(currentPayload?.source || {})}_to_${nodeLabel(currentPayload?.target || {})}`;
    const baseName = `tekg_path_${safeExportName(query)}_${exportDateStamp()}`;
    if (kind === 'png') {
      if (typeof graphRunner.exportPngDataUrl !== 'function') {
        throw new Error('PNG export is unavailable.');
      }
      downloadDataUrl(`${baseName}.png`, await graphRunner.exportPngDataUrl());
      return;
    }
    if (kind === 'svg') {
      if (typeof graphRunner.exportSvgString !== 'function') {
        throw new Error('SVG export is unavailable.');
      }
      downloadText(`${baseName}.svg`, graphRunner.exportSvgString(), 'image/svg+xml;charset=utf-8');
      return;
    }
    if (typeof graphRunner.getVisibleSubgraph !== 'function') {
      throw new Error('CSV export is unavailable.');
    }
    const graph = graphRunner.getVisibleSubgraph() || {};
    const nodes = Array.isArray(graph.nodes) ? graph.nodes : [];
    const edges = Array.isArray(graph.edges) ? graph.edges : [];
    const nodeFields = [
      { key: 'id', get: (row) => row.id },
      { key: 'label', get: (row) => row.label || row.rawLabel || row.id },
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
    downloadText(`${baseName}_nodes.csv`, buildCsv(nodes, nodeFields), 'text/csv;charset=utf-8');
    downloadText(`${baseName}_edges.csv`, buildCsv(edges, edgeFields), 'text/csv;charset=utf-8');
  }

  function bindExportAction(button, kind) {
    if (!button) {
      return;
    }
    button.addEventListener('click', async () => {
      closeExportMenu();
      setExportEnabled(false);
      try {
        await runGraphExport(kind);
      } catch (error) {
        statusEl.textContent = error && error.message ? error.message : 'Graph export failed.';
      } finally {
        setExportEnabled(true);
      }
    });
  }

  bindExportAction(graphExportCsvButton, 'csv');
  bindExportAction(graphExportPngButton, 'png');
  bindExportAction(graphExportSvgButton, 'svg');
  document.addEventListener('click', (event) => {
    if (!event.target.closest('#pathGraphExportWrap')) {
      closeExportMenu();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeExportMenu();
      if (graphExportButton) {
        graphExportButton.focus();
      }
    }
  });

  updateGraphToggleButtons();
  updateViewButtons();
  updateEntityPlaceholder(sourceTypeSelect, sourceInput);
  updateEntityPlaceholder(targetTypeSelect, targetInput);
  sourceTypeSelect.addEventListener('change', () => updateEntityPlaceholder(sourceTypeSelect, sourceInput));
  targetTypeSelect.addEventListener('change', () => updateEntityPlaceholder(targetTypeSelect, targetInput));
}());
