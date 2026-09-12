(function () {
  const FORK_SOURCE = 'assets/js/renderers/g6/index-g6-shared.js';
  const G6Lib = window.G6;
  if (!G6Lib || !G6Lib.Graph) {
    window.__TEKG_COEXPRESSION_RENDERER_CORE = null;
    return;
  }

  const { Circle, ExtensionCategory, Graph, register } = G6Lib;

  async function fetchWithDeadline(url, options = {}, timeoutMs = 15000, label = 'Graph request') {
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
      if (timedOut) throw new Error(`${label} timed out. Please retry.`);
      throw error;
    } finally {
      window.clearTimeout(timeoutId);
      externalSignal?.removeEventListener?.('abort', abortFromCaller);
    }
  }

  const TYPE_META = window.__TEKG_G6_TYPE_META && typeof window.__TEKG_G6_TYPE_META === 'object'
    ? window.__TEKG_G6_TYPE_META
    : {};
  const TYPE_COLORS = TYPE_META.colors || {};
  const TYPE_STROKES = TYPE_META.strokes || {};

  const RELATION_LABELS_EN = {
    SUBFAMILY_OF: 'contains',
    EVIDENCE_RELATION: 'literature support',
    BIO_RELATION: 'related to',
    associated_with: 'associated with',
    related_to: 'related to',
    promotes: 'promotes',
    mediates: 'mediates',
    reports: 'reports',
    affects: 'affects',
    executes: 'executes',
    participates_in: 'participates in',
    regulates: 'regulates',
    leads_to: 'leads to',
    uses: 'uses',
    inhibits: 'inhibits',
    triggers: 'triggers',
    induces: 'induces',
    increases_risk_of: 'increases risk of',
    modulates: 'modulates',
    facilitates: 'facilitates',
    occurs_in: 'occurs in',
    activates: 'activates',
    disrupts: 'disrupts',
    produces: 'produces',
    acts_as: 'acts as',
    enables: 'enables',
    explains: 'explains',
    provides: 'provides',
    predisposes_to: 'predisposes to',
    is_regulated_by: 'is regulated by',
    alters: 'alters',
    lacks: 'lacks',
    manifests_as: 'manifests as',
    characterizes: 'characterizes',
    '相关': 'associated with',
    '促进': 'promotes',
    '介导': 'mediates',
    '报道': 'reports',
    '影响': 'affects',
    '执行': 'executes',
    '参与': 'participates in',
    '调控': 'regulates',
    '导致': 'leads to',
    '利用': 'uses',
    '抑制': 'inhibits',
    '触发': 'triggers',
    '诱导': 'induces',
    '增加风险': 'increases risk of',
    '调节': 'modulates',
    '促成': 'facilitates',
    '发生': 'occurs in',
    '激活': 'activates',
    '破坏': 'disrupts',
    '产生': 'produces',
    '充当': 'acts as',
    '使能': 'enables',
    '解释': 'explains',
    '提供': 'provides',
    '易感': 'predisposes to',
    '被调控': 'is regulated by',
    '改变': 'alters',
    '缺失': 'lacks',
    '表现为': 'manifests as',
    '表征': 'characterizes',
  };

  const TE_MIN_RADIUS = 12.5;
  const AGGREGATE_TE_CHILD_SIZE_RATIO = 1.5;
  let pathFinderRippleRegistered = false;

  function noop() {}

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function degreeToSize(degree) {
    const safeDegree = Math.max(1, Number(degree) || 1);
    return Math.max(10, Math.min(64, 10 + Math.log2(safeDegree + 1) * 7.5));
  }

  function canonicalTeLineageName(name) {
    let raw = String(name || '').trim();
    if (!raw) return raw;
    raw = raw.replace(/^(Class I|Class II|Subclass|Order|Superfamily|Family):\s*/i, '').trim();
    if (raw === 'LINE1' || raw === 'LINE-1' || raw === 'L1 (LINE-1)') return 'L1';
    return raw;
  }

  function getDisplayNameOverride(name) {
    const raw = String(name || '').trim();
    if (!raw) return raw;
    if (raw === 'L1' || raw === 'LINE-1') return 'LINE1';
    return raw;
  }

  function interpolateHexColor(startHex, endHex, t) {
    const start = hexToRgb(startHex);
    const end = hexToRgb(endHex);
    const clamped = Math.max(0, Math.min(1, Number(t) || 0));
    const mix = (from, to) => Math.round(from + (to - from) * clamped);
    return `rgb(${mix(start.r, end.r)}, ${mix(start.g, end.g)}, ${mix(start.b, end.b)})`;
  }

  function darkenHexColor(hex, amount) {
    return interpolateHexColor(hex, '#0f172a', amount);
  }

  function hexToRgb(hex) {
    const raw = String(hex || '').trim();
    const rgbMatch = raw.match(/^rgb\(\s*(\d+),\s*(\d+),\s*(\d+)\s*\)$/i);
    if (rgbMatch) {
      return { r: Number(rgbMatch[1]), g: Number(rgbMatch[2]), b: Number(rgbMatch[3]) };
    }
    const value = raw.replace('#', '');
    if (value.length !== 6) return { r: 148, g: 163, b: 184 };
    return {
      r: parseInt(value.slice(0, 2), 16),
      g: parseInt(value.slice(2, 4), 16),
      b: parseInt(value.slice(4, 6), 16),
    };
  }

  function mixEdgeColor(sourceType, targetType, alpha) {
    const source = hexToRgb(TYPE_COLORS[sourceType] || '#94a3b8');
    const target = hexToRgb(TYPE_COLORS[targetType] || '#94a3b8');
    const mixed = {
      r: Math.round((source.r + target.r) / 2),
      g: Math.round((source.g + target.g) / 2),
      b: Math.round((source.b + target.b) / 2),
    };
    return `rgba(${mixed.r}, ${mixed.g}, ${mixed.b}, ${alpha})`;
  }

  function containsChinese(text) {
    return /[\u4e00-\u9fff]/.test(String(text || ''));
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

  function normalizeTranslationMap(raw) {
    return raw && typeof raw === 'object' ? raw : {};
  }

  function buildTeam(node) {
    if (node.type === 'Disease' || node.type === 'DiseaseCategory' || node.type === 'DiseaseClass') {
      const diseaseClass = String(node.disease_class || '').trim() || 'Disease';
      return `Disease::${diseaseClass}`;
    }
    if (node.type === 'TE') return 'TE';
    if (node.type === 'Function') return 'Function';
    return `${node.type || 'Node'}::${node.id}`;
  }

  function diseaseCategoryFillColor(level, minLevel, maxLevel) {
    const safeMin = Number.isFinite(minLevel) ? minLevel : 1;
    const safeMax = Number.isFinite(maxLevel) ? maxLevel : safeMin;
    const safeLevel = Number.isFinite(level) ? level : safeMax;
    const span = Math.max(1, safeMax - safeMin);
    const normalized = Math.max(0, Math.min(1, (safeLevel - safeMin) / span));
    return interpolateHexColor('#cf2238', '#f6d4d9', normalized);
  }

  function fitLabelToCircle(text, diameter) {
    const raw = String(text || '').trim();
    if (!raw) return '';
    const maxChars = Math.max(2, Math.floor((Math.max(0, Number(diameter) || 0) - 14) / 8.5));
    if (raw.length <= maxChars) return raw;
    if (maxChars <= 3) return raw.slice(0, maxChars);
    return `${raw.slice(0, maxChars - 1)}...`;
  }

  function stripDiseaseCategoryDisplayPrefix(label) {
    return String(label || '')
      .trim()
      .replace(/^\s*(?:\d{1,2}|[IVXLCDM]{1,6})[\s.:-]+(?=[A-Za-z])/i, '')
      .trim();
  }

  function displayLabelForNode(translatedLabel, nodeType) {
    const translated = String(translatedLabel || '').trim();
    return nodeType === 'DiseaseCategory'
      ? stripDiseaseCategoryDisplayPrefix(translated)
      : translated;
  }

  function wrapFullLabel(text, diameter, maxLines = 3) {
    const raw = String(text || '').trim().replace(/\s+/g, ' ');
    if (!raw) return '';
    const safeDiameter = Math.max(24, Number(diameter) || 24);
    const safeMaxLines = Math.max(1, Number(maxLines) || 3);
    const charsPerLine = Math.max(5, Math.floor((safeDiameter - 10) / 7.2));
    if (raw.length <= charsPerLine) return raw;

    const hasSpaces = raw.includes(' ');
    if (hasSpaces) {
      const words = raw.split(' ').filter(Boolean);
      const stopWords = new Set(['a', 'an', 'and', 'as', 'at', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to', 'with']);
      const candidates = [];

      function collect(start, lines, targetLineCount) {
        if (lines.length === targetLineCount - 1) {
          candidates.push([...lines, words.slice(start).join(' ')]);
          return;
        }
        const remainingLines = targetLineCount - lines.length - 1;
        const maxEnd = words.length - remainingLines;
        for (let end = start + 1; end <= maxEnd; end += 1) {
          collect(end, [...lines, words.slice(start, end).join(' ')], targetLineCount);
        }
      }

      const maxCandidateLines = Math.min(safeMaxLines, words.length);
      for (let lineCount = 2; lineCount <= maxCandidateLines; lineCount += 1) {
        collect(0, [], lineCount);
      }
      let best = null;
      let bestScore = Number.POSITIVE_INFINITY;
      for (const lines of candidates) {
        const lengths = lines.map((line) => line.length);
        const longest = Math.max(...lengths);
        const overflow = lengths.reduce((sum, length) => sum + Math.max(0, length - charsPerLine) ** 2 * 3, 0);
        const ragged = lengths.reduce((sum, length) => sum + (longest - length) ** 2, 0);
        const orphanPenalty = lines.reduce((sum, line) => {
          const lower = line.trim().toLowerCase();
          if (stopWords.has(lower)) return sum + 1000;
          if (/^(?:of|and|or|the|a|an|to|in|for|with)\b/i.test(line) && line.length <= 16) return sum + 450;
          if (/\b(?:of|and|or|the|a|an|to|in|for|with)$/i.test(line) && line.length <= 12) return sum + 180;
          return sum;
        }, 0);
        const continuationPenalty = lines.reduce((sum, line, index) => {
          if (index >= lines.length - 1) return sum;
          const next = String(lines[index + 1] || '').trim();
          return /^(?:of|and|or|the|a|an|to|in|for|with)\b/i.test(next) ? sum + 800 : sum;
        }, 0);
        const lineCountPenalty = lines.length * 42;
        const score = overflow + ragged + orphanPenalty + continuationPenalty + lineCountPenalty;
        if (score < bestScore) {
          bestScore = score;
          best = lines;
        }
      }

      if (best) return best.join('\n');
    }

    const separator = '';
    const segments = raw.split(/(?=[/()_-])|(?<=[/()_-])/).filter(Boolean);
    const lines = [];
    let index = 0;

    while (index < segments.length && lines.length < safeMaxLines) {
      if (lines.length === safeMaxLines - 1) {
        lines.push(segments.slice(index).join(separator));
        break;
      }

      let current = '';
      while (index < segments.length) {
        const segment = segments[index];
        const next = current ? `${current}${separator}${segment}` : segment;
        if (next.length > charsPerLine && current) break;
        current = next;
        index += 1;
      }

      if (current) lines.push(current);
      if (!current && index < segments.length) {
        lines.push(segments[index]);
        index += 1;
      }
    }

    return lines.join('\n');
  }

  function resolveNode(edgeSide, nodes) {
    if (edgeSide && typeof edgeSide === 'object') return edgeSide;
    return nodes.find((node) => node.id === edgeSide) || null;
  }

  function createRunner(options) {
    const container = options?.container;
    if (!container) {
      throw new Error('G6 shared runner requires a container.');
    }

    const renderProfile = options?.renderProfile === 'integrated' ? 'integrated' : 'coexpression';
    let graph = null;
    let fixedView = !!options.initialFixedView;
    let currentKeyNodeLevel = Math.max(1, Math.min(10, Number(options.initialKeyNodeLevel) || 1));
    let currentQuery = String(options.initialQuery || '').trim();
    let currentQueryType = normalizeQueryType(options.initialQueryType);
    let currentClassQuery = String(options.initialClassQuery || '').trim();
    let currentLang = 'en';
    let currentShowEdgeLabels = false;
    let currentAllowInspectCard = true;
    let currentAllowNodeActions = options.initialAllowNodeActions !== false;
    let currentGraphData = null;
    let currentExpressionOverlay = { enabled: false, context: 'off', records: {}, min_value: 0, max_value: 0 };
    let currentLegendFocus = null;
    let hiddenElementIds = new Set();

    if (currentQueryType === 'disease_class') {
      if (!currentClassQuery) currentClassQuery = currentQuery;
      if (!currentQuery) currentQuery = currentClassQuery;
    } else {
      currentClassQuery = '';
    }

    let nameTranslations = {};
    let teDescriptions = { en: {} };
    let entityDescriptions = { en: { Disease: {}, Function: {} } };
    let teLineageDepths = new Map();
    let teLineageChildren = new Map();
    let teLineageDescendants = new Map();
    let teDatabaseDegrees = new Map();
    let teFixedRadii = new Map();
    let resourcesPromise = null;
    let inspectCard = null;
    let inspectCardState = null;
    let coexpressionTooltip = null;

    const hooks = {
      setStatus: typeof options.setStatus === 'function' ? options.setStatus : noop,
      setDetail: typeof options.setDetail === 'function' ? options.setDetail : noop,
      setDetailHtml: typeof options.setDetailHtml === 'function' ? options.setDetailHtml : noop,
      setMode: typeof options.setMode === 'function' ? options.setMode : noop,
      onSelection: typeof options.onSelection === 'function' ? options.onSelection : noop,
      onDiseaseClassClick: typeof options.onDiseaseClassClick === 'function' ? options.onDiseaseClassClick : noop,
      onNodeExpand: typeof options.onNodeExpand === 'function' ? options.onNodeExpand : noop,
      onNodeJump: typeof options.onNodeJump === 'function' ? options.onNodeJump : noop,
      onReady: typeof options.onReady === 'function' ? options.onReady : noop,
      setQueryUi: typeof options.setQueryUi === 'function' ? options.setQueryUi : noop,
      syncRouteState: typeof options.syncRouteState === 'function' ? options.syncRouteState : noop,
    };

    function buildCurrentRequest() {
      if (currentQueryType === 'disease_class') {
        const classQuery = String(currentClassQuery || currentQuery || '').trim();
        return {
          query: classQuery,
          queryType: 'disease_class',
          classQuery,
        };
      }
      return {
        query: String(currentQuery || '').trim(),
        queryType: currentQueryType || '',
        classQuery: '',
      };
    }

    function pubmedUrl(pmid) {
      return `https://pubmed.ncbi.nlm.nih.gov/${encodeURIComponent(String(pmid || '').trim())}/`;
    }

    function ensureInspectCardStyles() {
      if (document.getElementById('tekg-g6-inspect-card-styles')) return;
      const style = document.createElement('style');
      style.id = 'tekg-g6-inspect-card-styles';
      style.textContent = `
        .inspect-card {
          position: absolute;
          z-index: 20;
          width: 260px;
          max-width: min(640px, calc(100vw - 28px));
          border: 1px solid rgba(148, 163, 184, 0.35);
          border-radius: 10px;
          background: rgba(255, 255, 255, 0.96);
          box-shadow: 0 18px 44px rgba(15, 23, 42, 0.18);
          color: #0f172a;
          font-family: Arial, sans-serif;
          pointer-events: auto;
          overflow: hidden;
        }
        .inspect-card.is-expanded {
          width: 620px;
        }
        .inspect-card__body {
          padding: 12px 14px;
          max-height: min(560px, calc(100vh - 48px));
          overflow: auto;
        }
        .inspect-card__title {
          margin: 0;
          font-size: 14px;
          font-weight: 800;
          line-height: 1.3;
        }
        .inspect-card__meta {
          margin-top: 5px;
          color: #475569;
          font-size: 12px;
          font-weight: 700;
          line-height: 1.45;
        }
        .inspect-card__desc {
          margin-top: 9px;
          color: #334155;
          font-size: 12px;
          line-height: 1.55;
        }
        .inspect-card__section {
          margin-top: 10px;
          padding-top: 10px;
          border-top: 1px solid #e2e8f0;
        }
        .inspect-card__source-tabs {
          display: flex;
          gap: 6px;
          margin: 9px 0 2px;
        }
        .inspect-card__source-tab {
          border: 1px solid #cbd5e1;
          border-radius: 6px;
          background: #ffffff;
          color: #52677f;
          padding: 5px 8px;
          font-size: 11px;
          font-weight: 800;
          cursor: pointer;
        }
        .inspect-card__source-tab.is-active {
          border-color: #6688d8;
          background: #edf3ff;
          color: #244f9b;
        }
        .inspect-card__section-title {
          margin: 0 0 6px;
          color: #0f172a;
          font-size: 11px;
          font-weight: 900;
          letter-spacing: 0;
        }
        .inspect-card__section-head {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 10px;
          margin-bottom: 6px;
        }
        .inspect-card__section-head .inspect-card__section-title {
          margin-bottom: 0;
        }
        .inspect-card__kv {
          display: grid;
          grid-template-columns: 96px 1fr;
          gap: 5px 8px;
          font-size: 12px;
          line-height: 1.45;
        }
        .inspect-card__key {
          color: #64748b;
          font-weight: 800;
        }
        .inspect-card__value {
          color: #1e293b;
          min-width: 0;
          word-break: break-word;
        }
        .inspect-card__pmids {
          display: flex;
          flex-wrap: wrap;
          gap: 6px;
        }
        .inspect-card__pmid {
          color: #1d4ed8;
          font-size: 12px;
          font-weight: 800;
          text-decoration: none;
        }
        .inspect-card__pmid:hover {
          text-decoration: underline;
        }
        .inspect-card__actions {
          display: flex;
          justify-content: flex-end;
          flex-wrap: wrap;
          gap: 8px;
          margin-top: 10px;
        }
        .inspect-card__button {
          border: 1px solid #cbd5e1;
          border-radius: 7px;
          background: #ffffff;
          color: #1e293b;
          cursor: pointer;
          font-size: 12px;
          font-weight: 800;
          min-height: 28px;
          padding: 0 10px;
        }
        .inspect-card__button:hover {
          border-color: #2563eb;
          color: #1d4ed8;
        }
        .edge-evidence-table-wrap {
          max-width: 100%;
          overflow-x: hidden;
        }
        .edge-evidence-table {
          width: 100%;
          table-layout: fixed;
          border-collapse: collapse;
          font-size: 11px;
        }
        .edge-evidence-table th:nth-child(1),
        .edge-evidence-table td:nth-child(1) {
          width: 84px;
        }
        .edge-evidence-table th:nth-child(2),
        .edge-evidence-table td:nth-child(2) {
          width: 48px;
        }
        .edge-evidence-table th:nth-child(3),
        .edge-evidence-table td:nth-child(3) {
          width: 82px;
        }
        .edge-evidence-table th:nth-child(4),
        .edge-evidence-table td:nth-child(4),
        .edge-evidence-table th:nth-child(5),
        .edge-evidence-table td:nth-child(5) {
          width: 40px;
        }
        .edge-evidence-table th:nth-child(6),
        .edge-evidence-table td:nth-child(6) {
          width: 62px;
        }
        .edge-evidence-table th,
        .edge-evidence-table td {
          padding: 5px 5px;
          border: 1px solid #e2e8f0;
          text-align: left;
          vertical-align: top;
        }
        .edge-evidence-table th {
          background: #f8fafc;
          color: #475569;
          font-weight: 900;
          white-space: nowrap;
        }
        .edge-evidence-table a {
          color: #1d4ed8;
          font-weight: 800;
          text-decoration: none;
        }
        .edge-evidence-table a:hover {
          text-decoration: underline;
        }
        .edge-evidence-cell-compact {
          display: inline-block;
          max-width: 100%;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
          vertical-align: top;
        }
        .edge-evidence-download {
          flex: 0 0 auto;
          min-height: 26px;
          padding: 0 9px;
          border: 1px solid #cbd5e1;
          border-radius: 7px;
          background: #ffffff;
          color: #1d4ed8;
          cursor: pointer;
          font-size: 11px;
          font-weight: 900;
        }
        .coexpression-tooltip {
          position: absolute;
          z-index: 10;
          max-width: 260px;
          border: 1px solid rgba(148, 163, 184, 0.4);
          border-radius: 7px;
          background: rgba(15, 23, 42, 0.92);
          color: #ffffff;
          padding: 7px 9px;
          font: 700 11px/1.45 Arial, sans-serif;
          pointer-events: none;
          white-space: normal;
        }
      `;
      document.head.appendChild(style);
    }

    function ensureInspectCard() {
      ensureInspectCardStyles();
      if (inspectCard && inspectCard.isConnected) return inspectCard;
      if (window.getComputedStyle(container).position === 'static') {
        container.style.position = 'relative';
      }
      inspectCard = document.createElement('div');
      inspectCard.className = 'inspect-card';
      inspectCard.setAttribute('role', 'dialog');
      inspectCard.setAttribute('aria-label', 'Inspect card');
        inspectCard.addEventListener('click', (event) => {
          event.stopPropagation();
          const target = event.target;
        if (target instanceof HTMLElement) {
          if (target.classList.contains('edge-evidence-download')) {
            downloadTextFromIframe(
              `tekg_${safeFilename(currentQuery || 'graph')}_${safeFilename(target.dataset.edgeId || 'edge')}_edge_evidence.csv`,
              decodeURIComponent(String(target.getAttribute('data-evidence-csv') || '')),
              'text/csv;charset=utf-8'
            );
            return;
          }
          const nodeAction = target.dataset.nodeAction;
          if (nodeAction && inspectCardState?.kind === 'node' && inspectCardState.node) {
            void handleNodeAction(nodeAction, inspectCardState.node);
            return;
          }
          const sourceTab = target.dataset.sourceTab;
          if (sourceTab && inspectCardState?.kind === 'node') {
            inspectCardState = { ...inspectCardState, sourceTab: sourceTab };
            renderInspectCard();
            return;
          }
          if (target.dataset.inspectAction === 'toggle') {
            inspectCardState = {
              ...inspectCardState,
              expanded: !inspectCardState?.expanded,
            };
            renderInspectCard();
          }
        }
      });
      container.appendChild(inspectCard);
      return inspectCard;
    }

    function compactText(text, maxLength = 140) {
      const raw = String(text || '').replace(/\s+/g, ' ').trim();
      if (raw.length <= maxLength) return raw;
      return `${raw.slice(0, Math.max(0, maxLength - 3)).trim()}...`;
    }

    function naturalTaxonomySource(value) {
      const source = String(value || '').trim();
      if (source === 'tree_rmsk_repbase') return 'RMSK + Repbase taxonomy';
      if (source === 'tree_all') return 'Complete TE taxonomy';
      return source.replaceAll('_', ' ');
    }

    function naturalTaxonomyStatus(value) {
      const status = String(value || '').trim().toLowerCase();
      if (status === 'leaf') return 'Specific TE entry';
      if (status === 'non_leaf') return 'TE group';
      return String(value || '').replaceAll('_', ' ');
    }

    function stripModuleId(value, moduleId) {
      const text = String(value || '').trim();
      const id = String(moduleId || '').trim();
      if (!id) return text;
      return text
        .replace(new RegExp(`\\b${id.replace(/[.*+?^${}()|[\\]\\]/g, '\\$&')}\\b`, 'g'), 'the selected co-expression module')
        .replace(/\s+/g, ' ')
        .trim();
    }

    function naturalNetworkRole(node) {
      if (node.coexpressionIsCenter) return 'Selected entity';
      if (node.coexpressionIsModuleHub) return 'Highly connected member';
      return 'Network member';
    }

    function safeFilename(value) {
      return String(value || 'graph').trim().replace(/[^a-z0-9_.-]+/gi, '_').replace(/^_+|_+$/g, '') || 'graph';
    }

    function downloadTextFromIframe(filename, text, mime) {
      const blob = new Blob([text || ''], { type: mime || 'text/plain;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    function resolveInspectQuadrant(pointer, rect) {
      const x = Math.max(0, Number(pointer?.x) || 0);
      const y = Math.max(0, Number(pointer?.y) || 0);
      const midX = Math.max(1, rect.width / 2);
      const midY = Math.max(1, rect.height / 2);
      if (x >= midX && y < midY) return 'q1';
      if (x < midX && y < midY) return 'q2';
      if (x < midX && y >= midY) return 'q3';
      return 'q4';
    }

    function pointerFromEvent(event) {
      const nativeEvent = event?.nativeEvent || event?.originalEvent || event?.event || event;
      const rect = container.getBoundingClientRect();
      const clientX = Number(nativeEvent?.clientX);
      const clientY = Number(nativeEvent?.clientY);
      if (Number.isFinite(clientX) && Number.isFinite(clientY)) {
        return {
          x: clientX - rect.left,
          y: clientY - rect.top,
        };
      }
      const canvas = event?.canvas || event?.canvasPoint || event?.point;
      if (canvas && Number.isFinite(Number(canvas.x)) && Number.isFinite(Number(canvas.y))) {
        return {
          x: Number(canvas.x),
          y: Number(canvas.y),
        };
      }
      return {
        x: rect.width / 2,
        y: rect.height / 2,
      };
    }

    function ensureCoexpressionTooltip() {
      ensureInspectCardStyles();
      if (coexpressionTooltip && coexpressionTooltip.isConnected) return coexpressionTooltip;
      coexpressionTooltip = document.createElement('div');
      coexpressionTooltip.className = 'coexpression-tooltip';
      coexpressionTooltip.hidden = true;
      container.appendChild(coexpressionTooltip);
      return coexpressionTooltip;
    }

    function positionCoexpressionTooltip(tooltip, event) {
      const pointer = pointerFromEvent(event);
      const rect = container.getBoundingClientRect();
      const width = Math.max(1, tooltip.offsetWidth || 220);
      const height = Math.max(1, tooltip.offsetHeight || 56);
      tooltip.style.left = `${Math.max(8, Math.min(rect.width - width - 8, pointer.x + 12))}px`;
      tooltip.style.top = `${Math.max(8, Math.min(rect.height - height - 8, pointer.y + 12))}px`;
    }

    function showCoexpressionTooltipForNode(node, event) {
      if (!node?.coexpression) return;
      if (inspectCardState) {
        hideCoexpressionTooltip();
        return;
      }
      const tooltip = ensureCoexpressionTooltip();
      const label = String(node.displayLabel || node.rawLabel || node.id || '');
      const role = node.coexpressionIsCenter
        ? `Selected ${node.coexpressionFeatureType || node.nodeType || 'node'}`
        : (node.coexpressionIsModuleHub ? 'Module hub' : String(node.coexpressionFeatureType || node.nodeType || 'Node'));
      const activity = ['TE', 'Gene'].includes(node.nodeType) && currentExpressionOverlay.enabled
        ? ` · expression ${String(node.expressionActivity || 'Unavailable').toLowerCase()}`
        : '';
      tooltip.textContent = `${label} · ${role}${activity}`;
      tooltip.hidden = false;
      positionCoexpressionTooltip(tooltip, event);
    }

    function showCoexpressionTooltipForEdge(edge, nodes, event) {
      if (!edge?.coexpression) return;
      if (inspectCardState) {
        hideCoexpressionTooltip();
        return;
      }
      const source = resolveNode(edge.source, nodes);
      const target = resolveNode(edge.target, nodes);
      const sourceLabel = source?.displayLabel || source?.rawLabel || String(edge.source || '');
      const targetLabel = target?.displayLabel || target?.rawLabel || String(edge.target || '');
      const correlation = Number(edge.correlation);
      const fdr = Number(edge.fdr);
      const tooltip = ensureCoexpressionTooltip();
      tooltip.textContent = `${sourceLabel} – ${targetLabel} · Spearman r ${Number.isFinite(correlation) ? correlation.toFixed(3) : 'n/a'} · FDR ${Number.isFinite(fdr) ? fdr.toExponential(2) : 'n/a'}`;
      tooltip.hidden = false;
      positionCoexpressionTooltip(tooltip, event);
    }

    function hideCoexpressionTooltip() {
      if (coexpressionTooltip) coexpressionTooltip.hidden = true;
    }

    function positionInspectCard(card, state) {
      const pointer = state?.pointer || { x: 0, y: 0 };
      const rect = container.getBoundingClientRect();
      const quadrant = state?.quadrant || resolveInspectQuadrant(pointer, rect);
      const gap = 14;
      const width = Math.max(1, card.offsetWidth || (state?.expanded === true ? 380 : 260));
      const height = Math.max(1, card.offsetHeight || (state?.expanded === true ? 360 : 170));
      const maxX = Math.max(gap, rect.width - width - gap);
      const maxY = Math.max(gap, rect.height - height - gap);
      let left = pointer.x + gap;
      let top = pointer.y + gap;

      if (quadrant === 'q1') {
        left = pointer.x - width - gap;
        top = pointer.y + gap;
      } else if (quadrant === 'q2') {
        left = pointer.x + gap;
        top = pointer.y + gap;
      } else if (quadrant === 'q3') {
        left = pointer.x + gap;
        top = pointer.y - height - gap;
      } else {
        left = pointer.x - width - gap;
        top = pointer.y - height - gap;
      }

      card.style.left = `${Math.min(Math.max(gap, left), maxX)}px`;
      card.style.top = `${Math.min(Math.max(gap, top), maxY)}px`;
      card.style.transformOrigin = quadrant === 'q1'
        ? 'right top'
        : quadrant === 'q2'
          ? 'left top'
          : quadrant === 'q3'
            ? 'left bottom'
            : 'right bottom';
    }

    function kvRow(key, value) {
      const text = String(value || '').trim();
      if (!text) return '';
      return `<span class="inspect-card__key">${escapeHtml(key)}</span><span class="inspect-card__value">${escapeHtml(text)}</span>`;
    }

    function pmidLinks(pmids) {
      const values = Array.isArray(pmids)
        ? [...new Set(pmids.map((pmid) => String(pmid || '').trim()).filter(Boolean))]
        : [];
      if (!values.length) return '<span class="inspect-card__value">No PMID evidence attached.</span>';
      return [
        '<div class="inspect-card__pmids">',
        ...values.map((pmid) => `<a class="inspect-card__pmid" href="${pubmedUrl(pmid)}" target="_blank" rel="noopener noreferrer">${escapeHtml(pmid)} ↗</a>`),
        '</div>',
      ].join('');
    }

    function boundedEvidenceWidth(edge) {
      if (edge?.coexpression === true || edge?.relationType === 'COEXPRESSION_CORRELATION') {
        const correlation = Math.max(0.4, Math.min(1, Number(edge?.correlation) || 0.4));
        return 1.35 + ((correlation - 0.4) / 0.6) * 2.65;
      }
      if (edge?.synthetic) return 1.9;
      const count = Math.max(
        0,
        Number(edge?.support_pmid_count ?? (Array.isArray(edge?.pmids) ? edge.pmids.length : 0)) || 0
      );
      return Math.max(1.4, Math.min(6, 1.35 + Math.log2(count + 1) * 0.82));
    }

    function boundedEvidenceOpacity(edge) {
      if (edge?.coexpression === true || edge?.relationType === 'COEXPRESSION_CORRELATION') {
        const correlation = Math.max(0.4, Math.min(1, Number(edge?.correlation) || 0.4));
        return 0.34 + ((correlation - 0.4) / 0.6) * 0.5;
      }
      if (edge?.synthetic) return 0.62;
      const raw = Number(edge?.support_metric_coverage);
      const coverage = Number.isFinite(raw) ? Math.max(0, Math.min(1, raw)) : 0;
      return Math.max(0.28, Math.min(0.82, 0.28 + coverage * 0.54));
    }

    function evidenceValue(value) {
      if (value === null || value === undefined || value === '') return '—';
      return String(value);
    }

    function evidenceMetricValue(value) {
      if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '—';
      return Number(value).toFixed(1).replace(/\.0$/, '');
    }

    function evidenceRecordsForEdge(edge) {
      const records = Array.isArray(edge?.evidence_records) ? edge.evidence_records : [];
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
      return (Array.isArray(edge?.pmids) ? edge.pmids : [])
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

    function csvEscape(value) {
      const text = value === null || value === undefined ? '' : String(value);
      return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    }

    function buildEvidenceCsv(records) {
      const fields = [
        'pmid',
        'pubmed_url',
        'pubmed_publication_year',
        'pubmed_journal_title',
        'journal_metric_value',
        'journal_metric_source',
        'journal_metric_year',
        'journal_jcr_quartile',
        'journal_metric_match_method',
        'pubmed_title',
      ];
      const lines = [fields.join(',')];
      for (const record of records) {
        lines.push(fields.map((field) => csvEscape(record[field])).join(','));
      }
      return lines.join('\r\n') + '\r\n';
    }

    function buildEvidenceTableHtml(edge) {
      const records = evidenceRecordsForEdge(edge);
      if (!records.length) {
        return '<div class="edge-evidence-empty">No PMID evidence attached.</div>';
      }

      const rows = records.map((record) => {
        const title = String(record.pubmed_title || '').trim();
        const compactTitle = title ? compactText(title, 96) : '—';
        const url = record.pubmed_url || pubmedUrl(record.pmid);
        return [
          '<tr>',
          `<td><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(record.pmid)}</a></td>`,
          `<td>${escapeHtml(evidenceValue(record.pubmed_publication_year))}</td>`,
        `<td title="${escapeHtml(record.pubmed_journal_title || '')}"><span class="edge-evidence-cell-compact">${escapeHtml(evidenceValue(record.pubmed_journal_title))}</span></td>`,
          `<td>${escapeHtml(evidenceMetricValue(record.journal_metric_value))}</td>`,
          `<td>${escapeHtml(evidenceValue(record.journal_jcr_quartile))}</td>`,
          `<td>${escapeHtml(evidenceValue(record.journal_metric_match_method))}</td>`,
          `<td class="edge-evidence-title-cell" title="${escapeHtml(title)}"><span class="edge-evidence-cell-compact">${escapeHtml(compactTitle)}</span></td>`,
          '</tr>',
        ].join('');
      }).join('');

      return [
        '<div class="edge-evidence-block">',
        '<div class="edge-evidence-table-wrap">',
        '<table class="edge-evidence-table">',
        '<thead><tr><th>PMID</th><th>Year</th><th>Journal</th><th>IF</th><th>JCR</th><th>Match</th><th>Title</th></tr></thead>',
        `<tbody>${rows}</tbody>`,
        '</table>',
        '</div>',
        '</div>',
      ].join('');
    }

    function buildEvidenceCsvButtonHtml(edge) {
      const records = evidenceRecordsForEdge(edge);
      if (records.length <= 10) return '';
      return `<button class="edge-evidence-download" type="button" data-edge-id="${escapeHtml(edge?.id || 'edge')}" data-evidence-csv="${escapeHtml(encodeURIComponent(buildEvidenceCsv(records)))}">Download CSV</button>`;
    }

    function coexpressionMetric(value, digits = 3) {
      const number = Number(value);
      if (!Number.isFinite(number)) return '';
      if (number !== 0 && Math.abs(number) < 0.001) return number.toExponential(2);
      return number.toFixed(digits).replace(/0+$/, '').replace(/\.$/, '');
    }

    function renderCoexpressionNodeInspectCard(node) {
      const label = node.displayLabel || node.rawLabel || node.id || '';
      const type = node.coexpressionFeatureType || node.nodeType || 'Node';
      const module = node.coexpressionModule || {};
      const interpretation = node.coexpressionInterpretation || {};
      const expanded = inspectCardState?.expanded === true;
      const role = naturalNetworkRole(node);
      const centerLabel = String(node.coexpressionSelection?.feature || node.coexpressionSelection?.gene || node.coexpressionSelection?.te || 'selected entity').trim();
      const entitySummary = node.coexpressionIsCenter && Number(module.size) > 0
        ? `${module.size} entities: ${module.te_count || 0} TEs and ${module.gene_count || 0} genes`
        : '';
      const rows = [
        kvRow('Feature type', type),
        kvRow('Network role', role),
        node.correlationToCenter !== null ? kvRow(`Correlation with ${centerLabel}`, coexpressionMetric(node.correlationToCenter)) : '',
        kvRow('Composition', String(module.type || '').replaceAll('-', ' ')),
        entitySummary ? kvRow('Entities', entitySummary) : '',
      ].filter(Boolean).join('');
      const expressionHtml = node.expressionDisabled === true
        ? ''
        : (['TE', 'Gene'].includes(node.nodeType) ? renderExpressionEvidenceHtml(node, !expanded) : '');
      const enrichment = Array.isArray(module.top_enriched_terms)
        ? module.top_enriched_terms.slice(0, expanded ? 5 : 2).join('; ')
        : '';
      const statement = stripModuleId(interpretation.statement_en, module.id);

      return [
        '<div class="inspect-card__body">',
        `<h3 class="inspect-card__title">${escapeHtml(label)}</h3>`,
        `<div class="inspect-card__meta">${escapeHtml(type)} · ${escapeHtml(role)}</div>`,
        rows ? `<div class="inspect-card__section"><p class="inspect-card__section-title">Co-expression Node</p><div class="inspect-card__kv">${rows}</div></div>` : '',
        node.coexpressionIsCenter && module.candidate_label
          ? `<div class="inspect-card__section"><p class="inspect-card__section-title">Module Context</p><div class="inspect-card__desc">${escapeHtml(module.candidate_label)}</div>${enrichment ? `<div class="inspect-card__desc">${escapeHtml(enrichment)}</div>` : ''}</div>`
          : '',
        expanded && statement
          ? `<div class="inspect-card__section"><p class="inspect-card__section-title">Interpretation</p><div class="inspect-card__desc">${escapeHtml(statement)}</div></div>`
          : '',
        expressionHtml,
        '<div class="inspect-card__section"><div class="inspect-card__desc">Co-expression and expression activity describe statistical context only; they do not establish regulation or causation.</div></div>',
        '<div class="inspect-card__actions">',
        `<button class="inspect-card__button" type="button" data-inspect-action="toggle">${expanded ? 'Collapse' : 'Details'}</button>`,
        '</div>',
        '</div>',
      ].join('');
    }

    function renderNodeInspectCard(node) {
      if (node?.integrated && Array.isArray(node.integrated.sources) && node.integrated.sources.length > 1) {
        return renderIntegratedNodeInspectCard(node);
      }
      if (node?.coexpression) return renderCoexpressionNodeInspectCard(node);
      const label = node.displayLabel || node.rawLabel || node.id || '';
      const type = node.nodeType || 'Node';
      const desc = String(node.description || '').trim();
      const taxonomy = node.taxonomy && typeof node.taxonomy === 'object' ? node.taxonomy : null;
      const expanded = inspectCardState?.expanded === true;
      const briefPath = taxonomy?.display_path || (Array.isArray(taxonomy?.path_labels) ? taxonomy.path_labels.join(' > ') : '');
      const degree = Math.max(0, Number(node.databaseDegree) || 0);
      const summary = [
        `<h3 class="inspect-card__title">${escapeHtml(label)}</h3>`,
        `<div class="inspect-card__meta">${escapeHtml(type)} · degree ${escapeHtml(degree)}</div>`,
        desc ? `<div class="inspect-card__desc">${escapeHtml(compactText(desc, expanded ? 260 : 135))}</div>` : '',
      ];

      const sections = [];
      if (expanded) {
        const rows = [
        kvRow('Type', type),
        kvRow('Database connections', node.databaseDegree),
        kvRow('Disease class', node.diseaseClass),
        type === 'Paper' ? kvRow('PMID', node.pmid) : '',
        ].filter(Boolean).join('');
        if (rows) {
          sections.push(`<div class="inspect-card__section"><p class="inspect-card__section-title">Node</p><div class="inspect-card__kv">${rows}</div></div>`);
        }
        if (taxonomy) {
          const canonicalName = String(taxonomy.canonical_name || '').trim();
          const taxonomyRows = [
            canonicalName && canonicalName.toLowerCase() !== String(label).trim().toLowerCase()
              ? kvRow('Canonical name', canonicalName)
              : '',
            kvRow('Status', naturalTaxonomyStatus(taxonomy.status)),
            kvRow('Taxonomy source', naturalTaxonomySource(taxonomy.source)),
            kvRow('Path', briefPath),
          ].filter(Boolean).join('');
          if (taxonomyRows) {
            sections.push(`<div class="inspect-card__section"><p class="inspect-card__section-title">Taxonomy</p><div class="inspect-card__kv">${taxonomyRows}</div></div>`);
          }
        } else if (briefPath) {
          sections.push(`<div class="inspect-card__section"><p class="inspect-card__section-title">Taxonomy</p><div class="inspect-card__desc">${escapeHtml(briefPath)}</div></div>`);
        }
      } else if (briefPath) {
        sections.push(`<div class="inspect-card__desc">${escapeHtml(compactText(briefPath, 120))}</div>`);
      }
      const expressionHtml = renderExpressionEvidenceHtml(node, !expanded);
      if (expressionHtml) sections.push(expressionHtml);

      return [
        '<div class="inspect-card__body">',
        ...summary,
        ...sections,
        '<div class="inspect-card__actions">',
        currentAllowNodeActions ? '<button class="inspect-card__button" type="button" data-node-action="jump">Jump</button>' : '',
        currentAllowNodeActions ? '<button class="inspect-card__button" type="button" data-node-action="expand">Expand</button>' : '',
        `<button class="inspect-card__button" type="button" data-inspect-action="toggle">${expanded ? 'Collapse' : 'Details'}</button>`,
        '</div>',
        '</div>',
      ].join('');
    }

    function renderIntegratedNodeInspectCard(node) {
      const integrated = node.integrated || {};
      const hasKnowledge = !!integrated.knowledge;
      const hasTeGene = !!integrated.teGene;
      const requested = String(inspectCardState?.sourceTab || '');
      const active = requested === 'te_gene' && hasTeGene ? 'te_gene' : (hasKnowledge ? 'knowledge' : 'te_gene');
      const payload = active === 'knowledge' ? integrated.knowledge : integrated.teGene;
      const label = String(payload?.label || payload?.rawLabel || node.displayLabel || node.rawLabel || node.id || '');
      const type = String(payload?.type || node.nodeType || 'Node');
      const description = active === 'knowledge'
        ? String(payload?.description || node.description || '').trim()
        : (node.coexpressionIsCenter ? 'Selected entity in the TE-Gene network.' : (node.coexpressionIsModuleHub ? 'Highly connected member of the selected co-expression module.' : 'Member of the selected TE-Gene network.'));
      const tabs = hasKnowledge && hasTeGene ? [
        '<div class="inspect-card__source-tabs" role="tablist" aria-label="Evidence source">',
        '<button class="inspect-card__source-tab' + (active === 'knowledge' ? ' is-active' : '') + '" type="button" data-source-tab="knowledge">Knowledge graph</button>',
        '<button class="inspect-card__source-tab' + (active === 'te_gene' ? ' is-active' : '') + '" type="button" data-source-tab="te_gene">TE-Gene</button>',
        '</div>',
      ].join('') : '';
      const details = active === 'knowledge'
        ? [
          kvRow('Type', type),
          kvRow('Database connections', payload?.degree),
          kvRow('Disease class', payload?.disease_class),
          kvRow('Taxonomy source', payload?.taxonomy?.source),
        ].filter(Boolean).join('')
        : [
          kvRow('Role', naturalNetworkRole(node)),
          kvRow('Context', node.coexpressionSelection?.context),
          kvRow('Module', node.coexpressionModule?.label || node.coexpressionModule?.module_label),
          kvRow('Correlation to center', node.correlationToCenter == null ? '' : coexpressionMetric(node.correlationToCenter)),
        ].filter(Boolean).join('');
      return [
        '<div class="inspect-card__body">',
        '<h3 class="inspect-card__title">' + escapeHtml(label) + '</h3>',
        '<div class="inspect-card__meta">' + escapeHtml(type) + '</div>',
        tabs,
        description ? '<div class="inspect-card__desc">' + escapeHtml(compactText(description, inspectCardState?.expanded ? 260 : 150)) + '</div>' : '',
        inspectCardState?.expanded && details ? '<div class="inspect-card__section"><div class="inspect-card__kv">' + details + '</div></div>' : '',
        active === 'te_gene' ? renderExpressionEvidenceHtml(node, !inspectCardState?.expanded) : '',
        '<div class="inspect-card__actions">',
        active === 'knowledge' && currentAllowNodeActions ? '<button class="inspect-card__button" type="button" data-node-action="jump">Jump</button>' : '',
        active === 'knowledge' && currentAllowNodeActions ? '<button class="inspect-card__button" type="button" data-node-action="expand">Expand</button>' : '',
        '<button class="inspect-card__button" type="button" data-inspect-action="toggle">' + (inspectCardState?.expanded ? 'Collapse' : 'Details') + '</button>',
        '</div></div>',
      ].join('');
    }

    async function handleNodeAction(action, node) {
      const nextAction = String(action || '').trim().toLowerCase();
      if (!node) return false;
      if (nextAction === 'details') {
        inspectCardState = {
          ...inspectCardState,
          kind: 'node',
          node,
          expanded: !inspectCardState?.expanded,
        };
        renderInspectCard();
        return true;
      }
      if (!currentAllowNodeActions && (nextAction === 'expand' || nextAction === 'jump')) {
        return false;
      }
      if (nextAction === 'expand') {
        hideInspectCard();
        const expanded = await Promise.resolve(hooks.onNodeExpand(node));
        return expanded === true;
      }
      if (nextAction === 'jump') {
        const query = String(node.queryLabel || node.rawLabel || node.displayLabel || '').trim();
        if (!query) return false;
        hideInspectCard();
        const handled = await Promise.resolve(hooks.onNodeJump(node, {
          query: query,
          queryType: normalizeQueryType(node.nodeType || ''),
        }));
        if (handled === true) return true;
        if (node.nodeType === 'DiseaseClass' || node.nodeType === 'DiseaseCategory') {
          const classQuery = String(node.classQuery || node.diseaseClass || query).trim();
          if (classQuery) {
            const handled = await Promise.resolve(
              hooks.onDiseaseClassClick(node, {
                query: classQuery,
                queryType: 'disease_class',
                classQuery,
              })
            );
            if (handled) return true;
            await loadGraph({
              query: classQuery,
              queryType: 'disease_class',
              classQuery,
            });
            return true;
          }
        }
        await loadGraph(query);
        return true;
      }
      return false;
    }

    function renderEdgeInspectCard(edge, nodes) {
      const isTeGeneEdge = edge?.sourceGraph === 'te_gene' || (!edge?.sourceGraph && edge?.coexpression === true);
      if (isTeGeneEdge && edge?.relationType === 'Co-expression') return renderCoexpressionEdgeInspectCard(edge, nodes);
      const source = resolveNode(edge?.source, nodes);
      const target = resolveNode(edge?.target, nodes);
      const sourceLabel = source?.displayLabel || source?.rawLabel || String(edge?.source || '');
      const targetLabel = target?.displayLabel || target?.rawLabel || String(edge?.target || '');
      const relation = relationLabelForEdge(edge);
      const relationType = String(edge?.relationType || '').trim();
      const isEqtl = relationType === 'eQTL' || relation === 'eQTL';
      const isBoth = relationType === 'Both' || relation === 'Both';
      const isTeGeneEvidence = isTeGeneEdge && (isEqtl || isBoth);
      const eqtl = edge?.eqtlEvidence && typeof edge.eqtlEvidence === 'object' ? edge.eqtlEvidence : null;
      const pmids = Array.isArray(edge?.pmids) ? edge.pmids : [];
      const summary = isEqtl
        ? `GTEx eQTL overlap${eqtl?.supporting_variant_count != null ? ` · ${eqtl.supporting_variant_count} supporting variants` : ''}${eqtl?.tissue_count != null ? ` · ${eqtl.tissue_count} tissues` : ''}`
        : (isBoth ? `Combined co-expression + GTEx eQTL evidence${eqtl?.supporting_variant_count != null ? ` · ${eqtl.supporting_variant_count} supporting variants` : ''}` : (pmids.length === 0 ? 'No linked PMID records' : `${pmids.length} linked PMID ${pmids.length === 1 ? 'record' : 'records'}`));
      const evidence = String(edge?.evidence || '').trim();
      const expanded = inspectCardState?.expanded === true;
      const expressionNode = expressionNodeEndpoint(edge, nodes);
      const expressionHtml = expanded && expressionNode
        ? renderExpressionEvidenceHtml(expressionNode, false).replace('Expression Evidence', 'Expression Context')
        : '';
      const rows = [
        kvRow('Relation', relation),
        !isTeGeneEvidence ? kvRow('PMID count', edge?.support_pmid_count ?? pmids.length) : '',
        eqtl?.supporting_variant_count != null ? kvRow('Supporting variants', eqtl.supporting_variant_count) : '',
        eqtl?.tissue_count != null ? kvRow('Supporting tissues', eqtl.tissue_count) : '',
        eqtl?.minimum_pval_nominal != null ? kvRow('Minimum nominal P', eqtl.minimum_pval_nominal) : '',
        isBoth ? kvRow('Spearman r', coexpressionMetric(edge?.correlation)) : '',
        isBoth ? kvRow('Adjusted P value (FDR)', coexpressionMetric(edge?.fdr)) : '',
      ].filter(Boolean).join('');

      return [
        '<div class="inspect-card__body">',
        `<h3 class="inspect-card__title">${escapeHtml(sourceLabel)} → ${escapeHtml(relation)} → ${escapeHtml(targetLabel)}</h3>`,
        `<div class="inspect-card__meta">${escapeHtml(summary)}</div>`,
        expanded && rows ? `<div class="inspect-card__section"><p class="inspect-card__section-title">Relation</p><div class="inspect-card__kv">${rows}</div></div>` : '',
        !isTeGeneEvidence && expanded ? `<div class="inspect-card__section"><div class="inspect-card__section-head"><p class="inspect-card__section-title">PubMed</p>${buildEvidenceCsvButtonHtml(edge)}</div>${buildEvidenceTableHtml(edge)}</div>` : (!isTeGeneEvidence && pmids.length ? `<div class="inspect-card__desc">PMID: ${escapeHtml(pmids.slice(0, 4).join(', '))}${pmids.length > 4 ? '...' : ''}</div>` : ''),
        expanded && evidence ? `<div class="inspect-card__section"><p class="inspect-card__section-title">Evidence</p><div class="inspect-card__desc">${escapeHtml(evidence)}</div></div>` : '',
        expressionHtml,
        '<div class="inspect-card__actions">',
        `<button class="inspect-card__button" type="button" data-inspect-action="toggle">${expanded ? 'Collapse' : 'Expand'}</button>`,
        '</div>',
        '</div>',
      ].join('');
    }

    function renderCoexpressionEdgeInspectCard(edge, nodes) {
      const source = resolveNode(edge?.source, nodes);
      const target = resolveNode(edge?.target, nodes);
      const sourceLabel = source?.displayLabel || source?.rawLabel || String(edge?.source || '');
      const targetLabel = target?.displayLabel || target?.rawLabel || String(edge?.target || '');
      const expanded = inspectCardState?.expanded === true;
      const rows = [
        kvRow('Spearman r', coexpressionMetric(edge?.correlation)),
        kvRow('Adjusted P value (FDR)', coexpressionMetric(edge?.fdr)),
        kvRow('Pair type', String(edge?.pairType || '').replaceAll('_', ' / ')),
      ].filter(Boolean).join('');
      return [
        '<div class="inspect-card__body">',
        `<h3 class="inspect-card__title">${escapeHtml(sourceLabel)} ↔ ${escapeHtml(targetLabel)}</h3>`,
        '<div class="inspect-card__meta">Positive Spearman correlation</div>',
        `<div class="inspect-card__section"><p class="inspect-card__section-title">Association</p><div class="inspect-card__kv">${rows}</div></div>`,
        expanded ? '<div class="inspect-card__section"><div class="inspect-card__desc">This edge is a filtered statistical association. It does not establish direct interaction, regulation, mechanism, or causation.</div></div>' : '',
        '<div class="inspect-card__actions">',
        `<button class="inspect-card__button" type="button" data-inspect-action="toggle">${expanded ? 'Collapse' : 'Details'}</button>`,
        '</div>',
        '</div>',
      ].join('');
    }

    function renderInspectCard() {
      if (!inspectCardState) return;
      const card = ensureInspectCard();
      card.className = inspectCardState.expanded ? 'inspect-card is-expanded' : 'inspect-card';
      card.innerHTML = inspectCardState.kind === 'edge'
        ? renderEdgeInspectCard(inspectCardState.edge, inspectCardState.nodes || [])
        : renderNodeInspectCard(inspectCardState.node);
      positionInspectCard(card, inspectCardState);
    }

    function showInspectCard(kind, payload, event, graphData) {
      const pointer = pointerFromEvent(event);
      const rect = container.getBoundingClientRect();
      inspectCardState = {
        kind,
        node: kind === 'node' ? payload : null,
        edge: kind === 'edge' ? payload : null,
        nodes: graphData?.nodes || [],
        pointer,
        quadrant: resolveInspectQuadrant(pointer, rect),
        expanded: false,
      };
      renderInspectCard();
    }

    function hideInspectCard() {
      inspectCardState = null;
      if (inspectCard) {
        inspectCard.remove();
        inspectCard = null;
      }
    }

    function normalizeGraphRequest(requestLike) {
      if (requestLike && typeof requestLike === 'object' && !Array.isArray(requestLike)) {
        const queryType = normalizeQueryType(requestLike.type || requestLike.queryType);
        const classQuery = String(requestLike.classQuery || requestLike.class || '').trim();
        const query = String(requestLike.query || requestLike.q || classQuery || '').trim();
        const expandNodeId = String(requestLike.expandNodeId || requestLike.expand_node_id || '').trim();
        const expandNodeType = String(requestLike.expandNodeType || requestLike.expand_node_type || '').trim();
        const expandQuery = String(requestLike.expandQuery || requestLike.expand_query || query || '').trim();
        if (queryType === 'disease_class') {
          const normalizedClassQuery = classQuery || query;
          return {
            query: normalizedClassQuery,
            queryType,
            classQuery: normalizedClassQuery,
            expandNodeId: '',
            expandNodeType: '',
            expandQuery: '',
          };
        }
        return {
          query,
          queryType,
          classQuery: '',
          expandNodeId,
          expandNodeType,
          expandQuery,
        };
      }

      if (typeof requestLike === 'string') {
        return {
          query: String(requestLike || '').trim(),
          queryType: '',
          classQuery: '',
        };
      }

      const uiQuery = typeof options.getQuery === 'function' ? String(options.getQuery() || '').trim() : '';
      if (currentQueryType === 'disease_class') {
        const preserveClassGraph = !uiQuery || uiQuery === currentQuery || uiQuery === currentClassQuery;
        if (preserveClassGraph) return buildCurrentRequest();
      }

      const query = String(uiQuery || currentQuery || 'LINE1').trim() || 'LINE1';
      return {
        query,
        queryType: '',
        classQuery: '',
      };
    }

    function computeTeVisualMetrics() {
      teFixedRadii = new Map();
      if (!teLineageDepths.size) return;

      const lineageNames = new Set([...teLineageDepths.keys(), ...teDatabaseDegrees.keys()]);
      const baseScores = new Map();

      for (const name of lineageNames) {
        const descendantCount = teLineageDescendants.get(name) || 0;
        const directChildrenCount = (teLineageChildren.get(name) || []).length;
        const databaseDegree = Math.max(0, Number(teDatabaseDegrees.get(name)) || 0);
        const nonChildEdgeCount = Math.max(0, databaseDegree - directChildrenCount);
        const weightedSignal = Math.max(1, descendantCount * 0.35 + nonChildEdgeCount * 0.65);
        baseScores.set(name, Math.sqrt(weightedSignal));
      }

      const adjustedScores = new Map(
        [...lineageNames].map((name) => [name, Math.max(1, Number(baseScores.get(name)) || 1)]),
      );

      const namesByDepthDesc = [...lineageNames].sort(
        (left, right) => (teLineageDepths.get(right) || 0) - (teLineageDepths.get(left) || 0),
      );

      for (const name of namesByDepthDesc) {
        const children = teLineageChildren.get(name) || [];
        if (!children.length) continue;
        const maxChildScore = Math.max(...children.map((child) => Math.max(0, Number(adjustedScores.get(child)) || 0)));
        if (maxChildScore <= 0) continue;
        const currentScore = Math.max(1, Number(adjustedScores.get(name)) || 1);
        const descendantCount = teLineageDescendants.get(name) || 0;
        const structuralBonus = 2.6 + Math.min(7.0, children.length * 0.4 + Math.log2(descendantCount + 1) * 0.84);
        const inverseSizeBoost = Math.max(1.2, 5.6 / Math.max(1, currentScore));
        const additiveBonus = structuralBonus * inverseSizeBoost;
        adjustedScores.set(name, Math.max(currentScore, maxChildScore + additiveBonus));
      }

      const line1TargetRadius = degreeToSize(teDatabaseDegrees.get('L1') || teDatabaseDegrees.get('LINE1') || 1) / 2;
      const line1AdjustedScore = Math.max(1, Number(adjustedScores.get('L1')) || 1);
      const scaleCoefficient = line1TargetRadius / line1AdjustedScore;

      for (const name of lineageNames) {
        teFixedRadii.set(name, Math.max(TE_MIN_RADIUS, (adjustedScores.get(name) || 1) * scaleCoefficient));
      }

      capAggregateTeRadiiByChildren();
    }

    function capAggregateTeRadiiByChildren() {
      const namesByDepthDesc = [...teLineageDepths.keys()].sort(
        (left, right) => (teLineageDepths.get(right) || 0) - (teLineageDepths.get(left) || 0),
      );

      for (const name of namesByDepthDesc) {
        const children = getAggregateTeChildNames(name);
        if (!children.length) continue;

        const childRadii = children
          .map((child) => Number(teFixedRadii.get(child)) || 0)
          .filter((radius) => radius > 0);
        if (!childRadii.length) continue;

        const currentRadius = Number(teFixedRadii.get(name)) || 0;
        if (currentRadius <= 0) continue;

        const maxChildRadius = Math.max(...childRadii);
        const cappedRadius = Math.max(
          TE_MIN_RADIUS,
          Math.min(currentRadius, maxChildRadius * AGGREGATE_TE_CHILD_SIZE_RATIO),
        );
        teFixedRadii.set(name, cappedRadius);
      }
    }

    function getAggregateTeChildNames(name) {
      const canonicalName = canonicalTeLineageName(name);
      const taxonomyChildren = (teLineageChildren.get(canonicalName) || [])
        .map((child) => canonicalTeLineageName(child))
        .filter((child) => child && child !== canonicalName);
      if (taxonomyChildren.length) return taxonomyChildren;

      const knownNames = [...teFixedRadii.keys()].map((candidate) => canonicalTeLineageName(candidate));
      if (canonicalName === 'SINE1/7SL (Alu)') {
        return knownNames.filter((candidate) => /^Alu/i.test(candidate));
      }
      if (canonicalName === 'L1') {
        return knownNames.filter((candidate) => /^L1/i.test(candidate) && candidate !== 'L1');
      }
      return [];
    }

    function teFillColorForName(name) {
      const canonicalName = canonicalTeLineageName(name);
      const depth = teLineageDepths.get(canonicalName);
      const depths = [...teLineageDepths.values()].filter((value) => Number.isFinite(value));
      const minDepth = depths.length ? Math.min(...depths) : 0;
      const maxDepth = depths.length ? Math.max(...depths) : 1;
      const span = Math.max(1, maxDepth - minDepth);
      const normalizedDepth = Math.max(0, Math.min(1, (((Number.isFinite(depth) ? depth : maxDepth) - minDepth) / span)));
      return interpolateHexColor('#18357d', '#8fb0ff', normalizedDepth);
    }

    function teRadiusForName(name, fallbackDegree) {
      const canonicalName = canonicalTeLineageName(name);
      return teFixedRadii.get(canonicalName) || degreeToSize(fallbackDegree) / 2;
    }

    function isImportantLabelNode(node) {
      const nodeType = String(node?.nodeType || '');
      return nodeType === 'TE'
        || nodeType === 'Disease'
        || nodeType === 'DiseaseClass'
        || nodeType === 'DiseaseCategory';
    }

    function importantLabelText(node) {
      const raw = String(node?.displayLabel || node?.rawLabel || '').trim();
      if (!raw) return '';
      return wrapFullLabel(raw, Math.max(28, Number(node?.size) || 28), 4);
    }

    function importantLabelFontSize(node) {
      const raw = String(node?.displayLabel || node?.rawLabel || '').trim();
      const diameter = Math.max(24, Number(node?.size) || 24);
      if (!raw) return 10;
      const lines = importantLabelText(node).split('\n').filter(Boolean);
      const longest = lines.reduce((max, line) => Math.max(max, line.length), 0);
      const estimated = (diameter - 8) / Math.max(4, longest * 0.62);
      const upper = node?.nodeType === 'TE' ? 14 : 13;
      return Math.max(9, Math.min(upper, estimated));
    }

    function secondaryLabelText(node) {
      const raw = String(node?.displayLabel || node?.rawLabel || '').trim();
      if (!raw) return '';
      return fitLabelToCircle(raw, Math.max(16, Number(node?.size) || 16));
    }

    function secondaryLabelFontSize(node) {
      const text = secondaryLabelText(node);
      const diameter = Math.max(16, Number(node?.size) || 16);
      if (!text) return 10;
      const estimated = (diameter - 8) / Math.max(3, text.length * 0.7);
      return Math.max(7, Math.min(11, estimated));
    }

    async function loadEnglishResources() {
      const [nameRes, teDescRes, entityDescRes, teLineageRes, teMetricsRes] = await Promise.allSettled([
        fetchWithDeadline(window.__TEKG_PATHS.dataUrl('processed/entity_description_key_translation_cache.json'), { credentials: 'same-origin' }, 6000, 'Graph terminology data'),
        fetchWithDeadline(window.__TEKG_PATHS.dataUrl('processed/te_descriptions.json'), { credentials: 'same-origin' }, 6000, 'TE descriptions'),
        fetchWithDeadline(window.__TEKG_PATHS.dataUrl('processed/entity_descriptions.json'), { credentials: 'same-origin' }, 6000, 'Entity descriptions'),
        fetchWithDeadline(window.__TEKG_PATHS.apiUrl('taxonomy.php?view=tree'), { credentials: 'same-origin' }, 6000, 'Taxonomy support data'),
        fetchWithDeadline(window.__TEKG_PATHS.apiUrl('te_metrics.php'), { credentials: 'same-origin' }, 6000, 'TE metrics'),
      ]);

      if (nameRes.status === 'fulfilled' && nameRes.value.ok) {
        nameTranslations = normalizeTranslationMap(await nameRes.value.json());
      }

      if (teDescRes.status === 'fulfilled' && teDescRes.value.ok) {
        const payload = await teDescRes.value.json();
        teDescriptions = { en: payload?.en || {} };
      }

      if (entityDescRes.status === 'fulfilled' && entityDescRes.value.ok) {
        const payload = await entityDescRes.value.json();
        entityDescriptions = {
          en: {
            Disease: payload?.en?.Disease || {},
            Function: payload?.en?.Function || {},
          },
        };
      }

      if (teLineageRes.status === 'fulfilled' && teLineageRes.value.ok) {
        const payload = await teLineageRes.value.json();
        teLineageDepths = new Map();
        for (const node of payload?.nodes || []) {
          const name = canonicalTeLineageName(node?.name);
          if (!name) continue;
          const depth = Math.max(0, Number(node?.depth) || 0);
          if (!teLineageDepths.has(name) || depth < teLineageDepths.get(name)) {
            teLineageDepths.set(name, depth);
          }
        }

        teLineageChildren = new Map();
        for (const edge of payload?.edges || []) {
          const parent = canonicalTeLineageName(edge?.parent);
          const child = canonicalTeLineageName(edge?.child);
          if (!parent || !child || parent === child) continue;
          if (!teLineageChildren.has(parent)) teLineageChildren.set(parent, []);
          teLineageChildren.get(parent).push(child);
        }

        teLineageDescendants = new Map();
        const countDescendants = (name) => {
          if (teLineageDescendants.has(name)) return teLineageDescendants.get(name);
          const children = teLineageChildren.get(name) || [];
          let total = 0;
          for (const child of children) total += 1 + countDescendants(child);
          teLineageDescendants.set(name, total);
          return total;
        };

        for (const name of teLineageDepths.keys()) {
          countDescendants(name);
        }
      }

      if (teMetricsRes.status === 'fulfilled' && teMetricsRes.value.ok) {
        const payload = await teMetricsRes.value.json();
        teDatabaseDegrees = new Map(
          Object.entries(payload?.metrics || {}).map(([name, degree]) => [canonicalTeLineageName(name), Math.max(0, Number(degree) || 0)]),
        );
      }

      computeTeVisualMetrics();
    }

    function ensureResources() {
      if (!resourcesPromise) {
        resourcesPromise = loadEnglishResources().catch((error) => {
          console.warn('Failed to load English resources for G6 graph:', error);
        });
      }
      return resourcesPromise;
    }

    function translateName(rawLabel) {
      const raw = String(rawLabel || '').trim();
      if (!raw) return '';
      if (currentLang !== 'en') return raw;
      if (!containsChinese(raw)) return getDisplayNameOverride(raw);
      return nameTranslations[raw] || raw;
    }

    function translateDescription(nodeType, rawLabel, rawDescription) {
      const label = String(rawLabel || '').trim();
      const description = String(rawDescription || '').trim();
      if (currentLang !== 'en') {
        return description || '';
      }
      if (nodeType === 'DiseaseClass') return description || 'Disease class node in the current graph.';
      if (nodeType === 'DiseaseCategory') return description || 'Disease classification category in the current graph.';
      if (nodeType === 'TE') {
        const key = translateName(label) || label;
        const mapped = String(teDescriptions?.en?.[key] || '').trim();
        if (mapped) return mapped;
      }
      if (nodeType === 'Disease' || nodeType === 'Function') {
        const key = translateName(label) || label;
        const mapped = String(entityDescriptions?.en?.[nodeType]?.[key] || '').trim();
        if (mapped) return mapped;
      }
      return description || '';
    }

    function normalizeExpressionLookupKey(value) {
      return String(value || '')
        .replace(/^(Class I|Class II|Subclass|Order|Superfamily|Family):\s*/i, '')
        .trim()
        .toLowerCase();
    }

    function expressionContextName(context) {
      return {
        global: 'Global',
        normal_tissue: 'Normal Tissue',
        normal_cell_line: 'Normal Cell Line',
        cancer_cell_line: 'Cancer Cell Line',
      }[String(context || '')] || 'Expression';
    }

    function expressionRecordForNode(node) {
      const records = currentExpressionOverlay && typeof currentExpressionOverlay.records === 'object'
        ? currentExpressionOverlay.records
        : {};
      for (const value of [node?.rawLabel, node?.displayLabel, node?.id]) {
        const key = normalizeExpressionLookupKey(value);
        if (key && records[key]) return records[key];
      }
      return null;
    }

    function expressionBucket(record, context) {
      if (!record || record.available !== true) return null;
      if (context === 'global') return record.global || null;
      return record[context] || null;
    }

    function expressionValue(record, context) {
      const bucket = expressionBucket(record, context);
      const value = Number(bucket && bucket.median_value);
      return Number.isFinite(value) ? value : null;
    }

    function expressionStrength(value) {
      const minValue = Math.max(0, Number(currentExpressionOverlay?.min_value) || 0);
      const maxValue = Math.max(0, Number(currentExpressionOverlay?.max_value) || 0);
      if (!Number.isFinite(value) || value <= 0 || maxValue <= 0) return 0;
      const logMin = Math.log10(minValue + 1);
      const logMax = Math.log10(maxValue + 1);
      if (Math.abs(logMax - logMin) < 1e-9) return 0.55;
      return Math.max(0, Math.min(1, (Math.log10(value + 1) - logMin) / (logMax - logMin)));
    }

    function expressionActivityLevel(strength) {
      if (!Number.isFinite(strength)) return 'Unavailable';
      if (strength < 0.34) return 'Low';
      if (strength < 0.67) return 'Medium';
      return 'High';
    }

    function expressionDetailUrl(node) {
      const label = String(node?.rawLabel || node?.displayLabel || '').trim();
      if (!label) return '';
      const base = window.__TEKG_PATHS.appUrl('expression_detail.php');
      const url = new URL(base, window.location.origin);
      url.searchParams.set('te', label);
      url.searchParams.set('metric', 'median');
      url.searchParams.set('sort', 'high_to_low');
      return url.toString();
    }

    function renderExpressionEvidenceHtml(node, compact = false) {
      if (!currentExpressionOverlay?.enabled || !node || node.nodeType !== 'TE') return '';
      const context = String(currentExpressionOverlay.context || 'global');
      const record = node.expressionRecord || expressionRecordForNode(node);
      if (!record || record.available !== true) {
        const empty = 'No expression summary is available for this context class.';
        return compact
          ? `<div class="inspect-card__desc">${escapeHtml(empty)}</div>`
          : `<div class="inspect-card__section"><p class="inspect-card__section-title">Expression Activity</p><div class="inspect-card__desc">${escapeHtml(empty)}</div></div>`;
      }

      const bucket = expressionBucket(record, context) || {};
      const value = expressionValue(record, context);
      const label = String(bucket.label || bucket.short_label || 'No top context label');
      const contextLabel = expressionContextName(context);
      const rows = [
        kvRow('Layer', contextLabel),
        kvRow('Top context', label),
        value !== null ? kvRow('Median', value.toLocaleString(undefined, { maximumFractionDigits: 2 })) : '',
        value !== null ? kvRow('Relative activity', node.expressionActivity || expressionActivityLevel(expressionStrength(value))) : '',
        kvRow('Context count', bucket.context_count),
        kvRow('Breadth', bucket.breadth),
      ].filter(Boolean).join('');
      const link = expressionDetailUrl(node);
      const linkHtml = link ? `<a class="inspect-card__button" href="${escapeHtml(link)}" target="_top">Open Expression</a>` : '';
      const boundary = 'Expression values provide activity context only; halo strength is relative within this visible network and does not prove causal graph relations.';
      if (compact) {
        return `<div class="inspect-card__section"><p class="inspect-card__section-title">Expression Activity</p><div class="inspect-card__desc">${escapeHtml(contextLabel)}: ${escapeHtml(label)}${value !== null ? `; median ${escapeHtml(value.toLocaleString(undefined, { maximumFractionDigits: 2 }))}` : ''}</div></div>`;
      }
      return [
        '<div class="inspect-card__section">',
        '<p class="inspect-card__section-title">Expression Activity</p>',
        rows ? `<div class="inspect-card__kv">${rows}</div>` : '',
        `<div class="inspect-card__desc">${escapeHtml(boundary)}</div>`,
        linkHtml ? `<div class="inspect-card__actions">${linkHtml}</div>` : '',
        '</div>',
      ].join('');
    }

    function expressionNodeEndpoint(edge, nodes) {
      const source = resolveNode(edge?.source, nodes);
      const target = resolveNode(edge?.target, nodes);
      if (source?.nodeType === 'TE') return source;
      if (target?.nodeType === 'TE') return target;
      return null;
    }

    function applyExpressionOverlayToNodes(nodes) {
      const enabled = currentExpressionOverlay?.enabled === true;
      const context = String(currentExpressionOverlay?.context || 'off');
      for (const node of nodes) {
        if (!node) continue;
        if (!node.baseFillColor) node.baseFillColor = node.fillColor;
        if (!node.baseStrokeColor) node.baseStrokeColor = node.strokeColor;
        node.expressionRecord = null;
        node.expressionContext = enabled ? context : 'off';
        node.expressionValue = null;
        node.expressionStrength = 0;
        node.expressionActivity = 'Off';
        node.expressionAvailable = false;
        node.expressionHaloOpacity = 0;
        node.expressionHaloWidth = 0;
        node.expressionRingCount = 0;
        node.expressionRippleRadius = 0;
        node.graphRipple = false;
        node.expressionLineWidth = node.endpointHighlight ? 5 : 2;
        node.opacity = 1;
        node.fillColor = node.baseFillColor;
        node.strokeColor = node.baseStrokeColor;

        if (node.expressionDisabled === true) {
          node.expressionContext = 'off';
          node.expressionActivity = 'Not shown for eQTL-only evidence';
          node.expressionLineWidth = node.endpointHighlight ? 5 : 2;
          continue;
        }

        if (!enabled) continue;
        if (!['TE', 'Gene'].includes(node.nodeType)) continue;

        const record = expressionRecordForNode(node);
        node.expressionRecord = record;
        const value = expressionValue(record, context);
        node.expressionValue = value;
        if (!record || record.available !== true || value === null) {
          node.expressionActivity = 'Unavailable';
          continue;
        }

        const strength = expressionStrength(value);
        node.expressionStrength = strength;
        node.expressionActivity = expressionActivityLevel(strength);
        node.expressionAvailable = true;
        node.expressionHaloOpacity = 0.14 + strength * 0.34;
        node.expressionHaloWidth = 4 + Math.round(strength * 6);
        node.expressionRingCount = 1 + Math.round(strength * 2);
        node.expressionRippleRadius = 10 + Math.round(strength * 20);
        node.graphRipple = enabled && node.expressionAvailable === true;
      }
    }

    function normalizeLegendFocus(focus) {
      if (!focus || typeof focus !== 'object') return null;
      const kind = String(focus.kind || '').trim();
      const value = String(focus.value || '').trim();
      if (!['entity', 'relation', 'module-hub', 'relative-expression'].includes(kind) || !value) return null;
      return { kind, value };
    }

    function entityLegendMatchesNode(node, value) {
      const nodeType = String(node?.nodeType || '');
      if (value === 'Disease') {
        return nodeType === 'Disease' || nodeType === 'DiseaseClass' || nodeType === 'DiseaseCategory';
      }
      return nodeType === value;
    }

    function relationLegendMatchesEdge(edge, value) {
      return String(edge?.relationKey || edge?.relationType || edge?.relation || '').trim() === value;
    }

    function applyLegendFocusToGraphData() {
      if (!currentGraphData || !Array.isArray(currentGraphData.nodes) || !Array.isArray(currentGraphData.edges)) {
        return { nodes: [], edges: [] };
      }
      const focus = normalizeLegendFocus(currentLegendFocus);
      for (const node of currentGraphData.nodes) {
        node.legendOpacity = 1;
        node.legendFocused = false;
      }
      for (const edge of currentGraphData.edges) {
        edge.legendOpacity = 1;
        edge.legendFocused = false;
      }
      if (!focus) return { nodes: currentGraphData.nodes, edges: currentGraphData.edges };

      const highlightedNodeIds = new Set();
      if (focus.kind === 'entity' || focus.kind === 'module-hub' || focus.kind === 'relative-expression') {
        for (const node of currentGraphData.nodes) {
          const matches = focus.kind === 'entity'
            ? entityLegendMatchesNode(node, focus.value)
            : focus.kind === 'module-hub'
              ? node.coexpressionIsModuleHub === true
              : node.expressionAvailable === true;
          if (matches) highlightedNodeIds.add(String(node.id || ''));
        }
        for (const node of currentGraphData.nodes) {
          const focused = highlightedNodeIds.has(String(node.id || ''));
          node.legendOpacity = focused ? 1 : 0.18;
          node.legendFocused = focused;
        }
        for (const edge of currentGraphData.edges) {
          const source = String(edge.source || '');
          const target = String(edge.target || '');
          const focused = highlightedNodeIds.has(source) || highlightedNodeIds.has(target);
          edge.legendOpacity = focused ? 0.82 : 0.12;
          edge.legendFocused = focused;
        }
      } else {
        for (const edge of currentGraphData.edges) {
          if (!relationLegendMatchesEdge(edge, focus.value)) continue;
          highlightedNodeIds.add(String(edge.source || ''));
          highlightedNodeIds.add(String(edge.target || ''));
        }
        for (const node of currentGraphData.nodes) {
          const focused = highlightedNodeIds.has(String(node.id || ''));
          node.legendOpacity = focused ? 1 : 0.18;
          node.legendFocused = focused;
        }
        for (const edge of currentGraphData.edges) {
          const focused = relationLegendMatchesEdge(edge, focus.value);
          edge.legendOpacity = focused ? 1 : 0.12;
          edge.legendFocused = focused;
        }
      }
      return { nodes: currentGraphData.nodes, edges: currentGraphData.edges };
    }

    function pushLegendFocusVisualState() {
      const { nodes, edges } = applyLegendFocusToGraphData();
      if (!graph) return Promise.resolve(false);
      if (typeof graph.updateNodeData === 'function' && nodes.length) {
        graph.updateNodeData(nodes.map((node) => ({
          id: node.id,
          legendOpacity: node.legendOpacity,
          legendFocused: node.legendFocused,
        })));
      }
      if (typeof graph.updateEdgeData === 'function' && edges.length) {
        graph.updateEdgeData(edges.map((edge) => ({
          id: edge.id,
          legendOpacity: edge.legendOpacity,
          legendFocused: edge.legendFocused,
        })));
      }
      if (typeof graph.draw === 'function') return Promise.resolve(graph.draw()).then(() => true);
      return Promise.resolve(true);
    }

    function relationLabelForEdge(edge) {
      if (edge?.synthetic) return 'classified as';
      const rawRelation = String(edge?.relation || '').trim();
      const rawType = String(edge?.relationType || '').trim();
      const raw = rawRelation || rawType;
      if (!raw) return 'related to';
      if (currentLang === 'en') {
        return RELATION_LABELS_EN[raw] || raw;
      }
      return raw;
    }

    function relationLegendKeyForEdge(edge) {
      const relation = String(edge?.relation || '').trim();
      const relationType = String(edge?.relationType || '').trim();
      if (relation) return relation;
      if (relationType) return relationType;
      return 'RELATION';
    }

    function formatEvidence(edge) {
      const evidence = String(edge?.evidence || '').trim();
      const pmids = Array.isArray(edge?.pmids)
        ? edge.pmids.map((pmid) => String(pmid || '').trim()).filter(Boolean)
        : [];

      if (pmids.length) {
        return `PMID: ${pmids.join(', ')}`;
      }
      if (evidence) {
        return evidence;
      }
      return 'Not listed.';
    }

    function hashString(input) {
      let hash = 0;
      const text = String(input || '');
      for (let index = 0; index < text.length; index += 1) {
        hash = ((hash << 5) - hash + text.charCodeAt(index)) | 0;
      }
      return Math.abs(hash);
    }

    const RELATION_STYLE_COLORS = [
      '#2563eb',
      '#dc2626',
      '#059669',
      '#7c3aed',
      '#ea580c',
      '#0891b2',
      '#be123c',
      '#4f46e5',
      '#65a30d',
      '#9333ea',
    ];

    function relationStyleForType(relationType) {
      const type = String(relationType || 'RELATION').trim() || 'RELATION';
      const coexpressionStyles = {
        'Co-expression': { color: '#2563eb', dashed: false, lineDash: [] },
        eQTL: { color: '#d97706', dashed: false, lineDash: [] },
        Both: { color: '#0f766e', dashed: false, lineDash: [] },
      };
      if (coexpressionStyles[type]) return coexpressionStyles[type];
      const index = hashString(type) % RELATION_STYLE_COLORS.length;
      const dashed = /CLASSIFICATION|CATEGORY|TAXONOMY|SYNTHETIC/i.test(type);
      return {
        color: RELATION_STYLE_COLORS[index],
        dashed,
        lineDash: dashed ? [8, 6] : [],
      };
    }

    function isClassificationRelation(relationType) {
      return /CLASSIFIED_AS|HAS_SUBCATEGORY|TOP_CLASS_RELATION|DISEASE_CLASSIFICATION/i.test(String(relationType || ''));
    }

    function buildEdgeDetailHtml(edge, nodes) {
      if (edge?.coexpression && edge?.relationType === 'Co-expression') {
        const source = resolveNode(edge?.source, nodes);
        const target = resolveNode(edge?.target, nodes);
        const sourceLabel = source?.displayLabel || source?.rawLabel || String(edge?.source || '');
        const targetLabel = target?.displayLabel || target?.rawLabel || String(edge?.target || '');
        return [
          '<div class="edge-evidence-detail">',
          `<div class="edge-evidence-title"><strong>${escapeHtml(sourceLabel)}</strong>&nbsp;↔&nbsp;<strong>${escapeHtml(targetLabel)}</strong></div>`,
          `<div class="edge-evidence-summary">Spearman r: ${escapeHtml(coexpressionMetric(edge.correlation))} · Adjusted P value (FDR): ${escapeHtml(coexpressionMetric(edge.fdr))} · ${escapeHtml(String(edge.pairType || '').replaceAll('_', ' / '))}</div>`,
          '<div class="edge-evidence-summary">Statistical association only; this edge does not establish regulation or causation.</div>',
          '</div>',
        ].join('');
      }
      const source = resolveNode(edge?.source, nodes);
      const target = resolveNode(edge?.target, nodes);
      const sourceLabel = source?.displayLabel || source?.rawLabel || String(edge?.source || '');
      const targetLabel = target?.displayLabel || target?.rawLabel || String(edge?.target || '');
      const relation = relationLabelForEdge(edge);
      const eqtl = edge?.eqtlEvidence || null;
      const isEqtl = relation === 'eQTL';
      const pmidCount = Math.max(0, Number(edge?.support_pmid_count) || (Array.isArray(edge?.pmids) ? edge.pmids.length : 0));
      const expressionNode = expressionNodeEndpoint(edge, nodes);
      const expressionHtml = expressionNode
        ? renderExpressionEvidenceHtml(expressionNode, true)
        : '';

      return [
        '<div class="edge-evidence-detail">',
        '<div class="edge-evidence-title">',
        `<strong>${escapeHtml(sourceLabel)}</strong>`,
        `&nbsp;&rarr;&nbsp;${escapeHtml(relation)}&nbsp;&rarr;&nbsp;`,
        `<strong>${escapeHtml(targetLabel)}</strong>`,
        '</div>',
          `<div class="edge-evidence-summary">${escapeHtml(relation)} evidence. ${eqtl?.supporting_variant_count != null ? `Supporting variants: ${escapeHtml(eqtl.supporting_variant_count)}.` : ''}</div>`,
          !isEqtl && pmidCount > 0 ? `<div class="edge-evidence-summary">Linked PMID records: ${escapeHtml(pmidCount)}.</div>` : '',
        expressionHtml,
        '</div>',
      ].join('');
    }

    function buildGraphData(elements, options = {}) {
      const includePaperNodes = options.includePaperNodes === true;
      const restrictToAnchorComponent = options.restrictToAnchorComponent !== false;
      const forceAnchorLabel = options.forceAnchorLabel === true;
      const graphRippleAnchor = options.graphRippleAnchor !== false;
      const hasNodeSizeScale = Object.prototype.hasOwnProperty.call(options, 'nodeSizeScale');
      const hasNodeMinSize = Object.prototype.hasOwnProperty.call(options, 'nodeMinSize');
      const nodeSizeScale = Math.max(1, Number(hasNodeSizeScale ? options.nodeSizeScale : 1.42) || 1);
      const nodeMinSize = Math.max(0, Number(hasNodeMinSize ? options.nodeMinSize : 38) || 0);
      const endpointNodeMinSize = Math.max(nodeMinSize, Number(options.endpointNodeMinSize) || 0);
      const endpointHighlightIds = new Set(
        (Array.isArray(options.endpointHighlightIds) ? options.endpointHighlightIds : [])
          .map((id) => String(id || '').trim())
          .filter(Boolean),
      );
      const visibleTypes = options.visibleTypes && typeof options.visibleTypes === 'object'
        ? options.visibleTypes
        : null;
      const visibleRelations = options.visibleRelations && typeof options.visibleRelations === 'object'
        ? options.visibleRelations
        : null;
      const minRelationPmids = Math.max(0, Number(options.minRelationPmids) || 0);
      const nodes = [];
      const edges = [];
      const allowedNodeIds = new Set();
      let anchorNodeId = '';

      for (const item of elements || []) {
        const data = item && item.data ? item.data : null;
        if (!data) continue;
        if (data.source && data.target) continue;
        const nodeType = data.type || 'TE';
        if (!includePaperNodes && nodeType === 'Paper') continue;
        if (visibleTypes && visibleTypes[nodeType] === false) continue;
        if (!anchorNodeId) anchorNodeId = String(data.id || '');

        const node = {
          id: data.id,
          size: nodeType === 'Paper'
            ? Math.max(28, degreeToSize(data.degree))
            : degreeToSize(data.degree),
          baseSize: nodeType === 'Paper'
            ? Math.max(28, degreeToSize(data.degree))
            : degreeToSize(data.degree),
          nodeType,
          rawLabel: data.rawLabel || data.label || data.id,
          displayLabel: displayLabelForNode(translateName(data.label || data.rawLabel || data.id), nodeType),
          databaseDegree: Math.max(0, Number(data.degree) || 0),
          description: data.preferProvidedDescription === true
            ? String(data.description || '')
            : translateDescription(data.type || 'TE', data.label || data.rawLabel || data.id, data.description || ''),
          pmid: String(data.pmid || ''),
          diseaseClass: String(data.disease_class || ''),
          categoryLevel: Math.max(0, Number(data.category_level) || 0),
          taxonomy: data.taxonomy && typeof data.taxonomy === 'object' ? data.taxonomy : null,
          integrated: data.integrated && typeof data.integrated === 'object' ? clonePlain(data.integrated) : null,
          sourceGraph: String(data.sourceGraph || ''),
          coexpression: data.coexpression === true,
          coexpressionRole: String(data.coexpressionRole || ''),
          coexpressionSourceRole: String(data.coexpressionSourceRole || ''),
          coexpressionFeatureType: String(data.coexpressionFeatureType || nodeType),
          coexpressionIsCenter: data.coexpressionIsCenter === true,
          coexpressionIsModuleHub: data.coexpressionIsModuleHub === true,
          expressionDisabled: data.expressionDisabled === true,
          coexpressionModule: data.coexpressionModule && typeof data.coexpressionModule === 'object'
            ? { ...data.coexpressionModule }
            : {},
          coexpressionSelection: data.coexpressionSelection && typeof data.coexpressionSelection === 'object'
            ? { ...data.coexpressionSelection }
            : {},
          coexpressionInterpretation: data.coexpressionInterpretation && typeof data.coexpressionInterpretation === 'object'
            ? { ...data.coexpressionInterpretation }
            : {},
          correlationToCenter: Number.isFinite(Number(data.correlationToCenter))
            ? Number(data.correlationToCenter)
            : null,
          team: buildTeam(data),
          queryLabel: nodeType === 'DiseaseCategory' ? '' : String(data.rawLabel || data.label || data.id),
          queryType: nodeType === 'DiseaseClass' ? 'disease_class' : '',
          classQuery: nodeType === 'DiseaseClass' ? String(data.rawLabel || data.label || data.id) : '',
          fillColor: TYPE_COLORS[nodeType] || '#94a3b8',
          strokeColor: TYPE_STROKES[nodeType] || '#111111',
          endpointHighlight: endpointHighlightIds.has(String(data.id || '')),
          graphRipple: graphRippleAnchor && String(data.id || '') === anchorNodeId,
          alwaysShowLabel:
            (includePaperNodes && (data.type || 'TE') === 'Paper') ||
            (forceAnchorLabel && String(data.id || '') === anchorNodeId) ||
            endpointHighlightIds.has(String(data.id || '')),
        };

        nodes.push(node);
        allowedNodeIds.add(node.id);
      }

      const teNodes = nodes.filter((node) => node.nodeType === 'TE');
      for (const node of teNodes) {
        if (node.coexpression) {
          node.fillColor = node.coexpressionIsCenter ? '#3b66c4' : '#7896d8';
          node.strokeColor = node.coexpressionIsCenter ? '#0f172a' : '#4164a8';
          continue;
        }
        const canonicalName = canonicalTeLineageName(node.rawLabel || node.displayLabel);
        const fixedRadius = teRadiusForName(canonicalName, node.databaseDegree);
        node.size = fixedRadius * 2;
        node.baseSize = node.size;
        node.fillColor = teFillColorForName(canonicalName);
        node.strokeColor = darkenHexColor(node.fillColor, 0.28);
      }

      for (const node of nodes.filter((item) => item.coexpression && item.nodeType === 'Gene')) {
        node.fillColor = node.coexpressionIsCenter ? '#0f766e' : '#1aa486';
        node.strokeColor = node.coexpressionIsCenter || node.coexpressionIsModuleHub ? '#0f172a' : '#0f6b5d';
      }

      if (nodeSizeScale > 1 || nodeMinSize > 0 || endpointNodeMinSize > 0) {
        for (const node of nodes) {
          const baseSize = Math.max(10, Number(node.size) || 10);
          const scaledSize = Math.round(baseSize * nodeSizeScale);
          const minSize = node.endpointHighlight ? endpointNodeMinSize : nodeMinSize;
          node.size = Math.max(scaledSize, minSize || 0);
          node.baseSize = node.size;
        }
      }

      const diseaseCategoryNodes = nodes.filter((node) => node.nodeType === 'DiseaseCategory');
      if (diseaseCategoryNodes.length) {
        const levels = diseaseCategoryNodes
          .map((node) => Math.max(1, Number(node.categoryLevel) || 1))
          .filter((level) => Number.isFinite(level));
        const minLevel = levels.length ? Math.min(...levels) : 1;
        const maxLevel = levels.length ? Math.max(...levels) : minLevel;
        for (const node of diseaseCategoryNodes) {
          const fillColor = diseaseCategoryFillColor(Math.max(1, Number(node.categoryLevel) || 1), minLevel, maxLevel);
          node.fillColor = fillColor;
          node.strokeColor = darkenHexColor(fillColor, 0.24);
        }
      }

      for (const node of nodes) {
        node.baseFillColor = node.fillColor;
        node.baseStrokeColor = node.strokeColor;
      }
      applyExpressionOverlayToNodes(nodes);

      const baseEdges = [];
      for (const item of elements || []) {
        const data = item && item.data ? item.data : null;
        if (!data || !data.source || !data.target) continue;
        if (!allowedNodeIds.has(data.source) || !allowedNodeIds.has(data.target)) continue;
        const relationType = String(data.relationType || data.relation || 'RELATION').trim() || 'RELATION';
        const relationKey = relationLegendKeyForEdge(data);
        const pmids = Array.isArray(data.pmids) ? data.pmids : [];
        if (visibleRelations && visibleRelations[relationKey] === false) continue;
        if (!isClassificationRelation(relationType) && pmids.length < minRelationPmids) continue;
        baseEdges.push({
          id: String(data.id || `${data.source}__${relationType}__${data.target}`),
          source: data.source,
          target: data.target,
          relation: String(data.relation || '').trim(),
          relationType,
          relationKey,
          coexpression: data.coexpression === true,
          correlation: Number(data.correlation),
          fdr: Number(data.fdr),
          pairType: String(data.pairType || ''),
          coexpressionEdgeRole: String(data.coexpressionEdgeRole || data.role || ''),
          eqtlEvidence: data.eqtlEvidence && typeof data.eqtlEvidence === 'object' ? clonePlain(data.eqtlEvidence) : null,
          evidence: String(data.evidence || '').trim(),
          pmids,
          evidence_records: Array.isArray(data.evidence_records) ? data.evidence_records : [],
          support_pmid_count: Number(data.support_pmid_count ?? pmids.length) || 0,
          support_metric_paper_count: Number(data.support_metric_paper_count ?? 0) || 0,
          support_metric_coverage: Number(data.support_metric_coverage ?? 0) || 0,
          support_if_max: data.support_if_max ?? null,
          support_if_mean: data.support_if_mean ?? null,
          support_if_median: data.support_if_median ?? null,
          support_jcr_q1_count: Number(data.support_jcr_q1_count ?? 0) || 0,
          support_jcr_q2_count: Number(data.support_jcr_q2_count ?? 0) || 0,
          support_jcr_q3_count: Number(data.support_jcr_q3_count ?? 0) || 0,
          support_jcr_q4_count: Number(data.support_jcr_q4_count ?? 0) || 0,
          support_journal_count: Number(data.support_journal_count ?? 0) || 0,
          support_publication_year_min: data.support_publication_year_min ?? null,
          sourceGraph: String(data.sourceGraph || ''),
          originalId: String(data.originalId || ''),
          parallelIndex: Math.max(0, Number(data.parallelIndex) || 0),
          parallelCount: Math.max(1, Number(data.parallelCount) || 1),
          support_publication_year_max: data.support_publication_year_max ?? null,
        });
      }

      const connectedNodeIds = new Set();
      for (const edge of baseEdges) {
        connectedNodeIds.add(edge.source);
        connectedNodeIds.add(edge.target);
      }

      const nonIsolatedNodes = nodes.filter((node) => connectedNodeIds.has(node.id));
      const candidateNodes = [...nodes];
      const adjacency = new Map();
      for (const node of candidateNodes) adjacency.set(node.id, []);
      for (const edge of baseEdges) {
        if (!adjacency.has(edge.source) || !adjacency.has(edge.target)) continue;
        adjacency.get(edge.source).push(edge.target);
        adjacency.get(edge.target).push(edge.source);
      }

      const mainComponentNodeIds = new Set();
      const traversalStartId = adjacency.has(anchorNodeId) ? anchorNodeId : (candidateNodes[0]?.id || '');
      if (traversalStartId) {
        const queue = [traversalStartId];
        mainComponentNodeIds.add(traversalStartId);
        while (queue.length) {
          const currentId = queue.shift();
          for (const neighborId of adjacency.get(currentId) || []) {
            if (mainComponentNodeIds.has(neighborId)) continue;
            mainComponentNodeIds.add(neighborId);
            queue.push(neighborId);
          }
        }
      }

      const visibleNodes = (!restrictToAnchorComponent || baseEdges.length === 0 || nonIsolatedNodes.length === 0)
        ? [...candidateNodes]
        : candidateNodes.filter((node) => mainComponentNodeIds.has(node.id));
      const visibleNodeIds = new Set(visibleNodes.map((node) => node.id));

      for (const node of visibleNodes) {
        node.importantLabel = isImportantLabelNode(node);
      }

      for (const edge of baseEdges) {
        if (!visibleNodeIds.has(edge.source) || !visibleNodeIds.has(edge.target)) continue;
        const relationStyle = relationStyleForType(edge.relationKey);
        edges.push({
          id: edge.id,
          source: edge.source,
          target: edge.target,
          relation: edge.relation,
          relationType: edge.relationType,
          relationKey: edge.relationKey,
          coexpression: edge.coexpression,
          correlation: edge.correlation,
          fdr: edge.fdr,
          pairType: edge.pairType,
          coexpressionEdgeRole: edge.coexpressionEdgeRole,
          evidence: edge.evidence,
          pmids: edge.pmids,
          evidence_records: edge.evidence_records,
          support_pmid_count: edge.support_pmid_count,
          support_metric_paper_count: edge.support_metric_paper_count,
          support_metric_coverage: edge.support_metric_coverage,
          support_if_max: edge.support_if_max,
          support_if_mean: edge.support_if_mean,
          support_if_median: edge.support_if_median,
          support_jcr_q1_count: edge.support_jcr_q1_count,
          support_jcr_q2_count: edge.support_jcr_q2_count,
          support_jcr_q3_count: edge.support_jcr_q3_count,
          support_jcr_q4_count: edge.support_jcr_q4_count,
          support_journal_count: edge.support_journal_count,
          support_publication_year_min: edge.support_publication_year_min,
          support_publication_year_max: edge.support_publication_year_max,
          strokeColor: relationStyle.color,
          lineDash: relationStyle.lineDash,
        });
      }

      const relationLegendMeta = [...new Set(edges.map((edge) => edge.relationKey || edge.relationType).filter(Boolean))]
        .sort((left, right) => left.localeCompare(right))
        .map((relationKey) => ({
          relationType: relationKey,
          ...relationStyleForType(relationKey),
        }));

      return { nodes: visibleNodes, edges, relationLegendMeta };
    }

    function applyCurrentViewState() {
      if (!currentGraphData || !Array.isArray(currentGraphData.nodes) || !Array.isArray(currentGraphData.edges)) {
        return Promise.resolve(false);
      }

      const nextNodes = currentGraphData.nodes.map((node) => {
        const baseSize = Math.max(10, Number(node.baseSize || node.size) || 10);
        return {
          ...node,
          size: baseSize,
          importantLabel: isImportantLabelNode(node),
        };
      });
      currentGraphData.nodes = nextNodes;
      applyLegendFocusToGraphData();

      if (graph && typeof graph.updateNodeData === 'function' && typeof graph.updateEdgeData === 'function') {
        graph.updateNodeData(nextNodes.map((node) => ({
          id: node.id,
          size: node.size,
          importantLabel: node.importantLabel,
          legendOpacity: node.legendOpacity,
          legendFocused: node.legendFocused,
        })));
        graph.updateEdgeData(currentGraphData.edges.map((edge) => ({
          id: edge.id,
          legendOpacity: edge.legendOpacity,
          legendFocused: edge.legendFocused,
        })));
        if (typeof graph.draw === 'function') {
          return Promise.resolve(graph.draw()).then(() => true);
        }
      }
      return Promise.resolve(false);
    }

    function setExpressionOverlay(overlay) {
      currentExpressionOverlay = overlay && typeof overlay === 'object'
        ? {
            enabled: overlay.enabled === true,
            context: String(overlay.context || 'off'),
            context_label: String(overlay.context_label || ''),
            records: overlay.records && typeof overlay.records === 'object' ? overlay.records : {},
            min_value: Math.max(0, Number(overlay.min_value) || 0),
            max_value: Math.max(0, Number(overlay.max_value) || 0),
            evidence_boundary: String(overlay.evidence_boundary || ''),
          }
        : { enabled: false, context: 'off', records: {}, min_value: 0, max_value: 0 };

      if (!currentGraphData || !Array.isArray(currentGraphData.nodes)) {
        return Promise.resolve(false);
      }

      applyExpressionOverlayToNodes(currentGraphData.nodes);
      applyLegendFocusToGraphData();
      if (inspectCardState) renderInspectCard();
      if (graph && typeof graph.updateNodeData === 'function') {
        graph.updateNodeData(currentGraphData.nodes.map((node) => ({
          id: node.id,
          fillColor: node.fillColor,
          strokeColor: node.strokeColor,
          opacity: node.opacity,
          legendOpacity: node.legendOpacity,
          legendFocused: node.legendFocused,
          expressionLineWidth: node.expressionLineWidth,
          expressionRecord: node.expressionRecord,
          expressionContext: node.expressionContext,
          expressionValue: node.expressionValue,
          expressionStrength: node.expressionStrength,
          expressionActivity: node.expressionActivity,
          expressionAvailable: node.expressionAvailable,
          expressionHaloOpacity: node.expressionHaloOpacity,
          expressionHaloWidth: node.expressionHaloWidth,
          expressionRingCount: node.expressionRingCount,
          expressionRippleRadius: node.expressionRippleRadius,
          graphRipple: node.graphRipple,
        })));
        if (typeof graph.draw === 'function') {
          return Promise.resolve(graph.draw()).then(() => true);
        }
      }

      return Promise.resolve(false);
    }

    function mergeCurrentGraphData(nextData) {
      if (!currentGraphData) {
        currentGraphData = {
          nodes: [],
          edges: [],
          relationLegendMeta: [],
        };
      }
      const existingNodeIds = new Set((currentGraphData.nodes || []).map((node) => String(node.id || '')));
      const existingEdgeIds = new Set((currentGraphData.edges || []).map((edge) => String(edge.id || '')));
      const nextNodes = [];
      const nextEdges = [];

      for (const node of nextData.nodes || []) {
        const id = String(node.id || '');
        if (!id || existingNodeIds.has(id)) continue;
        existingNodeIds.add(id);
        nextNodes.push(node);
      }
      for (const edge of nextData.edges || []) {
        const id = String(edge.id || `${edge.source || ''}__${edge.relationType || edge.relation || 'RELATION'}__${edge.target || ''}`);
        if (!id || existingEdgeIds.has(id)) continue;
        if (!existingNodeIds.has(String(edge.source || '')) || !existingNodeIds.has(String(edge.target || ''))) continue;
        existingEdgeIds.add(id);
        nextEdges.push(edge);
      }

      currentGraphData.nodes = [...(currentGraphData.nodes || []), ...nextNodes];
      currentGraphData.edges = [...(currentGraphData.edges || []), ...nextEdges];
      currentGraphData.relationLegendMeta = [...new Set([
        ...(currentGraphData.relationLegendMeta || []).map((item) => item.relationType).filter(Boolean),
        ...(nextData.relationLegendMeta || []).map((item) => item.relationType).filter(Boolean),
      ])].sort((left, right) => left.localeCompare(right)).map((relationType) => ({
        relationType,
        ...relationStyleForType(relationType),
      }));

      return { nextNodes, nextEdges };
    }

    function getContainerMetrics() {
      const docEl = document.documentElement;
      return {
        width: Math.max(container.clientWidth || 0, docEl ? docEl.clientWidth || 0 : 0, window.innerWidth || 0),
        height: Math.max(container.clientHeight || 0, docEl ? docEl.clientHeight || 0 : 0, window.innerHeight || 0),
      };
    }

    function waitForContainerSize(maxAttempts = 60, delayMs = 50) {
      return new Promise((resolve, reject) => {
        let attempts = 0;
        const check = () => {
          attempts += 1;
          const { width, height } = getContainerMetrics();
          if (width > 24 && height > 24) {
            resolve({ width, height });
            return;
          }
          if (attempts >= maxAttempts) {
            reject(new Error('G6 container has no size yet.'));
            return;
          }
          window.setTimeout(check, delayMs);
        };
        check();
      });
    }

    async function renderElements(elements, requestLike, options = {}) {
      const request = normalizeGraphRequest(requestLike);
      currentLegendFocus = null;
      hiddenElementIds = new Set();
      const query = String(request.query || currentQuery || '').trim() || 'LINE1';
      const sourceLabel = options.sourceLabel === 'qa' ? 'qa' : 'query';
      currentQuery = query;
      currentQueryType = request.queryType || '';
      currentClassQuery = currentQueryType === 'disease_class' ? String(request.classQuery || query).trim() : '';
      if (sourceLabel !== 'qa') {
        hooks.setQueryUi(query);
        hooks.syncRouteState({
          query,
          queryType: currentQueryType,
          classQuery: currentClassQuery,
          keyNodeLevel: currentKeyNodeLevel,
          fixedView,
          lang: 'en',
        });
      }
      hooks.setMode('dynamic', {
        query,
        queryType: currentQueryType,
        classQuery: currentClassQuery,
        source: sourceLabel,
      });
      if (!options.skipInitialStatus) {
        hooks.setStatus(
          sourceLabel === 'qa'
            ? `Synchronizing answer graph for ${query} ...`
            : `Rendering graph for ${query} (key-node level ${currentKeyNodeLevel}) ...`,
        );
      }

      try {
        await ensureResources();
        const metrics = await waitForContainerSize();
        if ((container.clientWidth || 0) < 25 && metrics.width > 0) {
          container.style.width = `${metrics.width}px`;
        }
        if ((container.clientHeight || 0) < 25 && metrics.height > 0) {
          container.style.height = `${metrics.height}px`;
        }

        const payloadElements = Array.isArray(elements) ? elements : [];
        const graphDataOptions = { ...(options.graphDataOptions || {}) };
        if (Object.prototype.hasOwnProperty.call(graphDataOptions, 'showEdgeLabels')) {
          currentShowEdgeLabels = graphDataOptions.showEdgeLabels === true;
        }
        if (Object.prototype.hasOwnProperty.call(graphDataOptions, 'allowInspectCard')) {
          currentAllowInspectCard = graphDataOptions.allowInspectCard === true;
        }
        if (Object.prototype.hasOwnProperty.call(graphDataOptions, 'allowNodeActions')) {
          currentAllowNodeActions = graphDataOptions.allowNodeActions !== false;
        }
        const data = buildGraphData(payloadElements, graphDataOptions);
        currentGraphData = data;
        applyLegendFocusToGraphData();
        const hasRippleHighlights = data.nodes.some((node) => node.endpointHighlight === true || node.graphRipple === true);
        const rippleNodeAvailable = hasRippleHighlights && ensurePathFinderRippleNodeRegistered();
        const layoutDistanceScale = Math.max(1, Number(graphDataOptions.layoutDistanceScale) || 1.45);
        const collisionPaddingScale = Math.max(1, Number(graphDataOptions.collisionPaddingScale) || 2.6);
        const chargeScale = Math.max(1, Number(graphDataOptions.chargeScale) || 1.55);
        const edgeLabelFontSize = Math.max(9, Number(graphDataOptions.edgeLabelFontSize) || 9);

        if (!Array.isArray(data.nodes) || data.nodes.length === 0) {
          hideInspectCard();
          if (graph && typeof graph.destroy === 'function') {
            graph.destroy();
            graph = null;
          }
          container.innerHTML = '';
          hooks.onSelection?.(null);
          hooks.setDetail(
            'No visible graph nodes',
            'The current legend filters hide all nodes for this graph. Re-enable at least one node type to render the subgraph.',
          );
          hooks.setStatus(
            sourceLabel === 'qa'
              ? 'The current QA graph has no visible nodes under the active legend filters.'
              : `No visible nodes remain for ${query} under the active legend filters.`,
          );
          return { elements: payloadElements };
        }

        if (graph && typeof graph.destroy === 'function') {
          graph.destroy();
        }

        graph = new Graph({
          container,
          autoFit: false,
          transforms: renderProfile === 'integrated'
            ? [{ type: 'process-parallel-edges', mode: 'bundle', distance: 28 }]
            : [],
          data,
          node: {
            type: (d) => ((d.endpointHighlight || d.graphRipple) && rippleNodeAvailable ? 'path-finder-ripple-circle' : 'circle'),
            style: {
              size: (d) => d.size,
              fill: (d) => d.fillColor || TYPE_COLORS[d.nodeType] || '#94a3b8',
              stroke: (d) => d.strokeColor || TYPE_STROKES[d.nodeType] || '#111111',
              opacity: (d) => {
                const baseOpacity = typeof d.opacity === 'number' ? d.opacity : 1;
                const legendOpacity = typeof d.legendOpacity === 'number' ? d.legendOpacity : 1;
                return Math.min(baseOpacity, legendOpacity);
              },
              lineWidth: (d) => {
                const coexpressionWidth = d.coexpressionIsCenter ? 5 : (d.coexpressionIsModuleHub ? 4 : 2);
                const baseWidth = d.expressionLineWidth || ((d.endpointHighlight || d.graphRipple) ? 5 : coexpressionWidth);
                return d.legendFocused ? baseWidth + 2 : baseWidth;
              },
              shadowColor: (d) => {
                if (d.expressionAvailable) return `rgba(14, 165, 233, ${Math.max(0.18, d.expressionHaloOpacity || 0.18)})`;
                return (d.endpointHighlight || d.graphRipple) ? 'rgba(37, 99, 235, 0.42)' : 'rgba(15, 23, 42, 0.08)';
              },
              shadowBlur: (d) => {
                if (d.expressionAvailable) return 8 + (d.expressionStrength || 0) * 20;
                return (d.endpointHighlight || d.graphRipple) ? 26 : 0;
              },
              shadowOffsetX: 0,
              shadowOffsetY: 0,
              halo: (d) => d.endpointHighlight === true || d.expressionAvailable === true,
              haloStroke: (d) => d.expressionAvailable ? '#38bdf8' : (d.strokeColor || TYPE_STROKES[d.nodeType] || '#2563eb'),
              haloOpacity: (d) => d.expressionAvailable ? d.expressionHaloOpacity : 0.28,
              haloLineWidth: (d) => d.expressionAvailable ? d.expressionHaloWidth : 4,
              expressionStrength: (d) => d.expressionStrength || 0,
              expressionRingCount: (d) => d.expressionRingCount || 1,
              expressionRippleRadius: (d) => d.expressionRippleRadius || 10,
              labelText: (d) => {
                if (d.importantLabel || isImportantLabelNode(d)) return importantLabelText(d);
                return secondaryLabelText(d);
              },
              labelPlacement: 'center',
              labelFill: '#111111',
              labelFontSize: (d) => {
                if (d.importantLabel || isImportantLabelNode(d)) return importantLabelFontSize(d);
                return secondaryLabelFontSize(d);
              },
              labelFontWeight: (d) => ((d.importantLabel || isImportantLabelNode(d)) ? 700 : 600),
              labelStroke: '#ffffff',
              labelLineWidth: 3,
              labelLineJoin: 'round',
            },
          },
          edge: {
            type: (edge) => renderProfile === 'integrated' && edge.parallelCount > 1 ? 'quadratic' : 'line',
            style: {
              stroke: (edge) => {
                if (edge.strokeColor) return edge.strokeColor;
                const source = resolveNode(edge.source, data.nodes);
                const target = resolveNode(edge.target, data.nodes);
                const alpha = boundedEvidenceOpacity(edge);
                return mixEdgeColor(source?.nodeType, target?.nodeType, alpha);
              },
              lineWidth: (edge) => boundedEvidenceWidth(edge) + (edge.legendFocused ? 1.5 : 0),
              lineDash: (edge) => edge.lineDash || [],
              curveOffset: (edge) => renderProfile === 'integrated' && edge.parallelCount > 1
                ? (edge.parallelIndex - (edge.parallelCount - 1) / 2) * 28
                : 0,
              opacity: (edge) => (typeof edge.legendOpacity === 'number' ? edge.legendOpacity : 1),
              labelText: (edge) => {
                if (!currentShowEdgeLabels) return '';
                const relationType = String(edge.relationKey || edge.relation || edge.relationType || '').trim();
                const pmidCount = Array.isArray(edge.pmids) ? edge.pmids.length : 0;
                if (!relationType && pmidCount <= 0) return '';
                return pmidCount > 0 ? `${relationType} (${pmidCount})` : relationType;
              },
              labelFontSize: edgeLabelFontSize,
              labelFill: '#334155',
              labelBackground: true,
              labelBackgroundFill: 'rgba(255,255,255,0.82)',
              labelBackgroundRadius: 4,
            },
          },
          layout: {
            type: 'd3-force',
            link: {
              distance: (edge) => {
                const source = resolveNode(edge.source, data.nodes);
                const target = resolveNode(edge.target, data.nodes);
                if (!source || !target) return 80;
                if (source.nodeType === 'DiseaseClass' || target.nodeType === 'DiseaseClass') return 120 * layoutDistanceScale;
                if (source.team === target.team) return 96 * layoutDistanceScale;
                if (source.nodeType === 'Disease' || target.nodeType === 'Disease') return 300 * layoutDistanceScale;
                return 220 * layoutDistanceScale;
              },
              strength: (edge) => {
                const source = resolveNode(edge.source, data.nodes);
                const target = resolveNode(edge.target, data.nodes);
                if (!source || !target) return 0.1;
                if (source.nodeType === 'DiseaseClass' || target.nodeType === 'DiseaseClass') return 0.92;
                if (source.team === target.team) return 0.5;
                if (source.nodeType === 'Disease' || target.nodeType === 'Disease') return 0.06;
                return 0.12;
              },
            },
            manyBody: {
              strength: (node) => {
                const size = typeof node.size === 'number' ? node.size : 16;
                if (node.nodeType === 'DiseaseClass') return -(240 + size * 4.6) * chargeScale;
                return -(170 + size * 3.8) * chargeScale;
              },
            },
            collide: {
              radius: (node) => {
                const highlightPadding = (node.endpointHighlight || node.graphRipple) ? 36 : 0;
                if (node.nodeType === 'DiseaseClass') return node.size / 2 + (58 + highlightPadding) * collisionPaddingScale;
                if (node.nodeType === 'TE') return node.size / 2 + (52 + highlightPadding) * collisionPaddingScale;
                if (node.nodeType === 'Disease') return node.size / 2 + (48 + highlightPadding) * collisionPaddingScale;
                return node.size / 2 + (44 + highlightPadding) * collisionPaddingScale;
              },
              strength: 1,
              iterations: Math.max(24, Number(graphDataOptions.collisionIterations) || 28),
            },
          },
          behaviors: [
            {
              type: 'drag-element-force',
              trigger: [],
              enable: (event) => event.targetType === 'node',
            },
            'zoom-canvas',
            'drag-canvas',
          ],
        });

        const renderPromise = graph.render();
        if (renderPromise && typeof renderPromise.then === 'function') {
          let renderSettled = false;
          await Promise.race([
            renderPromise.then(
              () => { renderSettled = true; },
              (error) => {
                renderSettled = true;
                throw error;
              }
            ),
            new Promise((resolve) => { setTimeout(resolve, 2500); }),
          ]);
          if (!renderSettled) {
            renderPromise.catch((error) => {
              console.warn('G6 render finished after the parent bridge timeout.', error);
            });
          }
        }
        hideInspectCard();
        graph.off?.('node:click');
        graph.off?.('node:pointerenter');
        graph.off?.('node:pointerleave');
        graph.off?.('edge:click');
        graph.off?.('edge:pointerenter');
        graph.off?.('edge:pointerleave');
        graph.off?.('canvas:click');
        graph.on('node:click', (event) => {
          const nodeId = event?.target?.id;
          const node = data.nodes.find((item) => item.id === nodeId);
          if (!node) return;
          hideCoexpressionTooltip();
          showInspectCard('node', node, event, data);
          hooks.onSelection(node);
          hooks.setDetail(node.displayLabel || node.rawLabel, node.description);
        });
        graph.on('node:pointerenter', (event) => {
          const nodeId = event?.target?.id;
          const node = data.nodes.find((item) => item.id === nodeId);
          if (!node) return;
          showCoexpressionTooltipForNode(node, event);
        });
        graph.on('node:pointerleave', () => {
          hideCoexpressionTooltip();
        });
        graph.on('edge:click', (event) => {
          const edgeId = event?.target?.id;
          const edge = data.edges.find((item) => item.id === edgeId);
          if (!edge) return;
          hideCoexpressionTooltip();
          if (currentAllowInspectCard) showInspectCard('edge', edge, event, data);
          else hideInspectCard();
          hooks.onSelection(null);
          hooks.setDetailHtml(buildEdgeDetailHtml(edge, data.nodes));
        });
        graph.on('edge:pointerenter', (event) => {
          const edgeId = event?.target?.id;
          const edge = data.edges.find((item) => item.id === edgeId);
          if (edge) showCoexpressionTooltipForEdge(edge, data.nodes, event);
        });
        graph.on('edge:pointerleave', hideCoexpressionTooltip);
        graph.on('canvas:click', () => {
          hideInspectCard();
          hideCoexpressionTooltip();
          hooks.onSelection(null);
          hooks.setDetail('No node selected', 'Click a node or edge to inspect graph details.');
        });

        hooks.onSelection(null);
        hooks.setDetail('No node selected', 'Click a node or edge to inspect graph details.');
        hooks.setStatus(
          sourceLabel === 'qa'
            ? `Loaded ${data.nodes.length} nodes and ${data.edges.length} edges from the current QA answer.`
            : `Loaded ${data.nodes.length} nodes and ${data.edges.length} edges for ${query}.`,
        );
        return { elements: payloadElements, relationLegendMeta: data.relationLegendMeta };
      } catch (error) {
        hooks.setStatus(`Failed: ${error && error.message ? error.message : 'unknown error'}`);
        console.error('G6 graph failed:', error);
        throw error;
      }
    }

    async function loadGraph(requestLike, options = {}) {
      const request = normalizeGraphRequest(requestLike);
      currentLegendFocus = null;
      const query = String(request.query || '').trim() || 'LINE1';
      hooks.setStatus(`Loading graph for ${query} (key-node level ${currentKeyNodeLevel}) ...`);

      try {
        const endpoint = new URL(window.__TEKG_PATHS.apiUrl('graph.php'), window.location.origin);
        endpoint.searchParams.set('q', query);
        endpoint.searchParams.set('key_level', String(currentKeyNodeLevel));
        if (request.queryType) {
          endpoint.searchParams.set('type', request.queryType);
        }
        if (request.queryType === 'disease_class') {
          endpoint.searchParams.set('class', request.classQuery || query);
        }

        const response = await fetchWithDeadline(endpoint.toString(), {
          credentials: 'same-origin',
        }, 15000, 'Knowledge Graph request');
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        const graphDataOptions = { ...(options.graphDataOptions || {}) };
        if (!Object.prototype.hasOwnProperty.call(graphDataOptions, 'visibleTypes')) {
          const currentBridge = window.__TEKG_G6_BRIDGE;
          if (currentBridge && typeof currentBridge.getVisibleTypes === 'function') {
            graphDataOptions.visibleTypes = currentBridge.getVisibleTypes();
          } else if (window.parent && window.parent !== window) {
            const parentBridge = window.parent.__TEKG_G6_BRIDGE;
            if (parentBridge && typeof parentBridge.getVisibleTypes === 'function') {
              graphDataOptions.visibleTypes = parentBridge.getVisibleTypes();
            }
          }
        }
        if (!Object.prototype.hasOwnProperty.call(graphDataOptions, 'showEdgeLabels')) {
          const currentBridge = window.__TEKG_G6_BRIDGE;
          if (currentBridge && typeof currentBridge.getShowEdgeLabels === 'function') {
            graphDataOptions.showEdgeLabels = currentBridge.getShowEdgeLabels();
          } else if (window.parent && window.parent !== window) {
            const parentBridge = window.parent.__TEKG_G6_BRIDGE;
            if (parentBridge && typeof parentBridge.getShowEdgeLabels === 'function') {
              graphDataOptions.showEdgeLabels = parentBridge.getShowEdgeLabels();
            }
          }
        }
        const rendered = await renderElements(payload.elements || [], request, {
          sourceLabel: 'query',
          skipInitialStatus: true,
          graphDataOptions,
        });
        return { ...payload, elements: rendered.elements, relationLegendMeta: rendered.relationLegendMeta };
      } catch (error) {
        hooks.setStatus(`Failed: ${error && error.message ? error.message : 'unknown error'}`);
        console.error('G6 graph failed:', error);
        throw error;
      }
    }

    function ensurePathFinderRippleNodeRegistered() {
      if (pathFinderRippleRegistered) return true;
      if (!Circle || !register || !ExtensionCategory) return false;
      try {
        class PathFinderRippleCircle extends Circle {
          onCreate() {
            const keyShape = this.shapeMap?.key;
            const keyStyle = keyShape?.style || {};
            const fill = this.attributes?.fill || keyStyle.fill || '#2f63b9';
            const r = Number(keyStyle.r || this.attributes?.r || 24) || 24;
            const strength = Math.max(0, Math.min(1, Number(this.attributes?.expressionStrength) || 0));
            const ringCount = Math.max(1, Math.min(3, Number(this.attributes?.expressionRingCount) || 1));
            const rippleRadius = Math.max(10, Math.min(30, Number(this.attributes?.expressionRippleRadius) || 10));
            const startOpacity = 0.12 + strength * 0.2;
            const duration = 1800 + rippleRadius * 45;
            Array.from({ length: ringCount }).forEach((_, index) => {
              const ripple = this.upsert(
                `path-finder-ripple-${index}`,
                'circle',
                {
                  r,
                  fill,
                  fillOpacity: startOpacity,
                  stroke: fill,
                  strokeOpacity: startOpacity + 0.08,
                  lineWidth: 2,
                  pointerEvents: 'none',
                },
                this,
              );
              if (ripple && typeof ripple.animate === 'function') {
                ripple.animate(
                  [
                    { r, fillOpacity: startOpacity, strokeOpacity: startOpacity + 0.08 },
                    { r: r + rippleRadius, fillOpacity: 0, strokeOpacity: 0 },
                  ],
                  {
                    duration,
                    iterations: Infinity,
                    delay: (duration / ringCount) * index,
                    easing: 'ease-cubic',
                  },
                );
              }
            });
          }
        }
        register(ExtensionCategory.NODE, 'path-finder-ripple-circle', PathFinderRippleCircle);
        pathFinderRippleRegistered = true;
      } catch (error) {
        console.warn('Failed to register Path Finder ripple node; using halo fallback.', error);
        pathFinderRippleRegistered = false;
      }
      return pathFinderRippleRegistered;
    }

    async function expandGraph(requestLike, options = {}) {
      const request = normalizeGraphRequest(requestLike);
      const query = String(request.query || '').trim();
      if (!query) return { elements: [], relationLegendMeta: [], addedNodes: 0, addedEdges: 0 };

      const endpoint = new URL(window.__TEKG_PATHS.apiUrl('graph.php'), window.location.origin);
      endpoint.searchParams.set('q', query);
      endpoint.searchParams.set('key_level', String(currentKeyNodeLevel));
      if (request.expandNodeId) {
        endpoint.searchParams.set('expand_node_id', request.expandNodeId);
      }
      if (request.expandNodeType) {
        endpoint.searchParams.set('expand_node_type', request.expandNodeType);
      }
      if (request.expandQuery) {
        endpoint.searchParams.set('expand_query', request.expandQuery);
      }
      if (request.queryType) {
        endpoint.searchParams.set('type', request.queryType);
      }
      if (request.queryType === 'disease_class') {
        endpoint.searchParams.set('class', request.classQuery || query);
      }

      const response = await fetchWithDeadline(endpoint.toString(), {
        credentials: 'same-origin',
      }, 15000, 'Knowledge Graph expansion request');
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const payload = await response.json();
      const graphDataOptions = { ...(options.graphDataOptions || {}) };
      if (!Object.prototype.hasOwnProperty.call(graphDataOptions, 'showEdgeLabels')) {
        graphDataOptions.showEdgeLabels = currentShowEdgeLabels;
      }
      if (!Object.prototype.hasOwnProperty.call(graphDataOptions, 'allowInspectCard')) {
        graphDataOptions.allowInspectCard = currentAllowInspectCard;
      }

      const expandedData = buildGraphData(payload.elements || [], graphDataOptions);
      const { nextNodes, nextEdges } = mergeCurrentGraphData(expandedData);
      const expandNodeId = String(request.expandNodeId || '').trim();
      for (const node of nextNodes) {
        node.graphRipple = false;
        node.importantLabel = isImportantLabelNode(node);
      }
      const rippleUpdates = [];
      if (expandNodeId && currentGraphData && Array.isArray(currentGraphData.nodes)) {
        for (const node of currentGraphData.nodes) {
          const shouldRipple = node.graphRipple === true || String(node.id || '') === expandNodeId;
          if (node.graphRipple !== shouldRipple) {
            node.graphRipple = shouldRipple;
            rippleUpdates.push({
              id: node.id,
              graphRipple: shouldRipple,
            });
          }
        }
      }
      if (graph && (nextNodes.length || nextEdges.length || rippleUpdates.length)) {
        try {
          if (typeof graph.addNodeData === 'function' && nextNodes.length) {
            graph.addNodeData(nextNodes);
          }
          if (typeof graph.addEdgeData === 'function' && nextEdges.length) {
            graph.addEdgeData(nextEdges);
          }
          if (typeof graph.updateNodeData === 'function' && rippleUpdates.length) {
            graph.updateNodeData(rippleUpdates);
          }
          await pushLegendFocusVisualState();
          if (typeof graph.draw === 'function') {
            await graph.draw();
          }
          if (typeof graph.layout === 'function') {
            await graph.layout();
          }
        } catch (error) {
          console.warn('G6 expand incremental draw failed; keeping current graph surface.', error);
        }
      }

      return {
        ...payload,
        elements: payload.elements || [],
        relationLegendMeta: currentGraphData?.relationLegendMeta || expandedData.relationLegendMeta,
        addedNodes: nextNodes.length,
        addedEdges: nextEdges.length,
      };
    }

    function clonePlain(value) {
      return JSON.parse(JSON.stringify(value || null));
    }

    function getVisibleSubgraph() {
      const nodes = Array.isArray(currentGraphData?.nodes)
        ? currentGraphData.nodes
          .filter((node) => !hiddenElementIds.has(String(node.id || '')))
          .map((node) => ({
            id: String(node.id || ''),
            label: String(node.displayLabel || node.rawLabel || node.id || ''),
            rawLabel: String(node.rawLabel || node.displayLabel || node.id || ''),
            type: String(node.nodeType || node.type || 'TE'),
            nodeType: String(node.nodeType || node.type || 'TE'),
            size: Number(node.size || 0) || 0,
            endpointHighlight: node.endpointHighlight === true,
            description: String(node.description || ''),
            pmid: String(node.pmid || ''),
            degree: Number(node.databaseDegree || node.degree || 0) || 0,
            coexpression: node.coexpression === true,
            role: String(node.coexpressionSourceRole || node.coexpressionRole || ''),
            is_center: node.coexpressionIsCenter === true,
            is_module_hub: node.coexpressionIsModuleHub === true,
            correlation_to_center: node.correlationToCenter,
            expression_context: String(node.expressionContext || 'off'),
            expression_value: node.expressionValue !== null
              && node.expressionValue !== undefined
              && Number.isFinite(Number(node.expressionValue))
              ? Number(node.expressionValue)
              : null,
            expression_activity: String(node.expressionActivity || 'Off'),
            expression_strength: Number(node.expressionStrength || 0),
            expression_ripple: node.graphRipple === true,
            disease_class: String(node.diseaseClass || ''),
            category_level: Number(node.categoryLevel || 0) || 0,
            source_graph: String(node.sourceGraph || ''),
            source_graphs: Array.isArray(node.integrated?.sources) ? [...node.integrated.sources] : [],
            source_ids: node.integrated?.sourceIds ? clonePlain(node.integrated.sourceIds) : {},
          }))
        : [];
      const nodeById = new Map(nodes.map((node) => [String(node.id || ''), node]));
      const edges = Array.isArray(currentGraphData?.edges)
        ? currentGraphData.edges
          .filter((edge) => {
            const source = String(edge.source || '');
            const target = String(edge.target || '');
            return !hiddenElementIds.has(String(edge.id || ''))
              && !hiddenElementIds.has(source)
              && !hiddenElementIds.has(target);
          })
          .map((edge) => {
            const source = String(edge.source || '');
            const target = String(edge.target || '');
            return {
              id: String(edge.id || `${source}__${edge.relationType || edge.relation || 'RELATION'}__${target}`),
              source,
              target,
              source_label: nodeById.get(source)?.label || '',
              source_type: nodeById.get(source)?.type || '',
              target_label: nodeById.get(target)?.label || '',
              target_type: nodeById.get(target)?.type || '',
              relation: String(edge.relation || ''),
              relationType: String(edge.relationType || edge.relationKey || edge.relation || 'RELATION'),
              relationKey: String(edge.relationKey || edge.relationType || edge.relation || 'RELATION'),
              coexpression: edge.coexpression === true,
              correlation: Number.isFinite(Number(edge.correlation)) ? Number(edge.correlation) : null,
              fdr: Number.isFinite(Number(edge.fdr)) ? Number(edge.fdr) : null,
              pair_type: String(edge.pairType || ''),
              edge_role: String(edge.coexpressionEdgeRole || ''),
              eqtl_evidence: edge.eqtlEvidence && typeof edge.eqtlEvidence === 'object' ? clonePlain(edge.eqtlEvidence) : null,
              pmids: Array.isArray(edge.pmids) ? edge.pmids.map((pmid) => String(pmid || '')).filter(Boolean) : [],
              pmid_count: Array.isArray(edge.pmids) ? edge.pmids.length : 0,
              evidence_records: Array.isArray(edge.evidence_records) ? clonePlain(edge.evidence_records) : [],
              support_pmid_count: Number(edge.support_pmid_count ?? 0) || 0,
              support_metric_paper_count: Number(edge.support_metric_paper_count ?? 0) || 0,
              support_metric_coverage: Number(edge.support_metric_coverage ?? 0) || 0,
              support_if_max: edge.support_if_max ?? null,
              support_if_mean: edge.support_if_mean ?? null,
              support_if_median: edge.support_if_median ?? null,
              support_jcr_q1_count: Number(edge.support_jcr_q1_count ?? 0) || 0,
              support_jcr_q2_count: Number(edge.support_jcr_q2_count ?? 0) || 0,
              support_jcr_q3_count: Number(edge.support_jcr_q3_count ?? 0) || 0,
              support_jcr_q4_count: Number(edge.support_jcr_q4_count ?? 0) || 0,
              support_journal_count: Number(edge.support_journal_count ?? 0) || 0,
              support_publication_year_min: edge.support_publication_year_min ?? null,
              support_publication_year_max: edge.support_publication_year_max ?? null,
              evidence: String(edge.evidence || ''),
              source_graph: String(edge.sourceGraph || ''),
              original_id: String(edge.originalId || ''),
              parallel_index: Number(edge.parallelIndex || 0),
              parallel_count: Number(edge.parallelCount || 1),
            };
          })
        : [];

      return clonePlain({
        query: currentQuery,
        request: buildCurrentRequest(),
        nodes,
        edges,
        counts: { nodes: nodes.length, edges: edges.length },
        source: 'iframe-currentGraphData',
      });
    }

    function inspectEdge(edgeId) {
      const id = String(edgeId || '');
      const edge = Array.isArray(currentGraphData?.edges)
        ? currentGraphData.edges.find((item) => String(item.id || '') === id)
        : null;
      if (!edge) {
        return false;
      }
      const card = ensureInspectCard();
      const rect = container.getBoundingClientRect();
      inspectCardState = {
        kind: 'edge',
        node: null,
        edge,
        nodes: currentGraphData?.nodes || [],
        pointer: {
          x: Math.max(20, Math.min(rect.width - 20, rect.width / 2)),
          y: Math.max(20, Math.min(rect.height - 20, rect.height / 2)),
        },
        quadrant: 'q1',
        expanded: false,
      };
      renderInspectCard();
      positionInspectCard(card, inspectCardState);
      hooks.onSelection(null);
      hooks.setDetailHtml(buildEdgeDetailHtml(edge, currentGraphData?.nodes || []));
      return true;
    }

    function inspectNode(nodeId) {
      const id = String(nodeId || '');
      const node = Array.isArray(currentGraphData?.nodes)
        ? currentGraphData.nodes.find((item) => String(item.id || '') === id)
        : null;
      if (!node) {
        return false;
      }
      const card = ensureInspectCard();
      const rect = container.getBoundingClientRect();
      inspectCardState = {
        kind: 'node',
        node,
        edge: null,
        nodes: currentGraphData?.nodes || [],
        pointer: {
          x: Math.max(20, Math.min(rect.width - 20, rect.width / 2)),
          y: Math.max(20, Math.min(rect.height - 20, rect.height / 2)),
        },
        quadrant: 'q1',
        expanded: false,
      };
      renderInspectCard();
      positionInspectCard(card, inspectCardState);
      hooks.onSelection(node);
      hooks.setDetail(node.displayLabel || node.rawLabel, node.description);
      return true;
    }

    function triggerNodeAction(nodeId, action) {
      const id = String(nodeId || '');
      const node = Array.isArray(currentGraphData?.nodes)
        ? currentGraphData.nodes.find((item) => String(item.id || '') === id)
        : null;
      if (!node) {
        return Promise.resolve(false);
      }
      if (!inspectCardState || inspectCardState.kind !== 'node' || String(inspectCardState.node?.id || '') !== id) {
        inspectNode(id);
      }
      return Promise.resolve(handleNodeAction(action, node));
    }

    function getEdgeVisuals(edgeId) {
      const id = String(edgeId || '');
      const edge = Array.isArray(currentGraphData?.edges)
        ? currentGraphData.edges.find((item) => String(item.id || '') === id)
        : null;
      if (!edge) {
        return null;
      }
      return clonePlain({
        edgeId: id,
        support_pmid_count: Number(edge.support_pmid_count ?? 0) || 0,
        support_metric_coverage: Number(edge.support_metric_coverage ?? 0) || 0,
        width: boundedEvidenceWidth(edge),
        opacity: boundedEvidenceOpacity(edge),
      });
    }

    async function exportPngDataUrl() {
      if (graph && typeof graph.toDataURL === 'function') {
        try {
          const result = await graph.toDataURL({ type: 'image/png' });
          if (typeof result === 'string' && result.startsWith('data:image/png')) return result;
          if (result && typeof result.dataURL === 'string' && result.dataURL.startsWith('data:image/png')) return result.dataURL;
        } catch (error) {
          console.warn('G6 graph.toDataURL failed; falling back to canvas export.', error);
        }
      }

      const canvases = Array.from(container.querySelectorAll('canvas'))
        .filter((canvas) => canvas && canvas.width > 0 && canvas.height > 0)
        .sort((left, right) => (right.width * right.height) - (left.width * left.height));
      const canvas = canvases[0];
      if (!canvas || typeof canvas.toDataURL !== 'function') {
        throw new Error('No G6 canvas is available for PNG export.');
      }
      return canvas.toDataURL('image/png');
    }

    function exportSvgString() {
      if (!graph || !currentGraphData || typeof graph.getElementPosition !== 'function') {
        throw new Error('No positioned G6 graph is available for SVG export.');
      }
      const visible = getVisibleSubgraph();
      if (!Array.isArray(visible.nodes) || visible.nodes.length === 0) {
        throw new Error('The visible graph has no nodes to export.');
      }

      const sourceNodes = new Map((currentGraphData.nodes || []).map((node) => [String(node.id || ''), node]));
      const sourceEdges = new Map((currentGraphData.edges || []).map((edge) => [String(edge.id || ''), edge]));
      const nodes = visible.nodes.map((node) => {
        const position = graph.getElementPosition(node.id);
        const x = Number(position?.[0]);
        const y = Number(position?.[1]);
        if (!Number.isFinite(x) || !Number.isFinite(y)) {
          throw new Error(`Node ${node.id} has no finite G6 position for SVG export.`);
        }
        const source = sourceNodes.get(String(node.id || '')) || node;
        return { ...node, source, x, y };
      });
      const nodeById = new Map(nodes.map((node) => [String(node.id || ''), node]));
      const edges = (visible.edges || [])
        .filter((edge) => nodeById.has(String(edge.source || '')) && nodeById.has(String(edge.target || '')))
        .map((edge) => ({ ...edge, sourceData: sourceEdges.get(String(edge.id || '')) || edge }));

      const serializer = window.__TEKG_G6_SVG_EXPORT;
      if (!serializer || typeof serializer.serialize !== 'function') {
        throw new Error('The shared G6 SVG serializer is unavailable.');
      }
      const exportNodes = nodes.map((node) => {
        const model = node.source;
        const radius = Math.max(5, Number(node.size || model.size || 10) / 2);
        const opacity = Math.min(
          typeof model.opacity === 'number' ? model.opacity : 1,
          typeof model.legendOpacity === 'number' ? model.legendOpacity : 1,
        );
        const coexpressionWidth = model.coexpressionIsCenter ? 5 : (model.coexpressionIsModuleHub ? 4 : 2);
        const baseWidth = model.expressionLineWidth || ((model.endpointHighlight || model.graphRipple) ? 5 : coexpressionWidth);
        const lineWidth = model.legendFocused ? baseWidth + 2 : baseWidth;
        const important = model.importantLabel || isImportantLabelNode(model);
        const label = important ? importantLabelText(model) : secondaryLabelText(model);
        const rings = [];
        if (model.expressionAvailable === true) {
          const strength = Math.max(0, Math.min(1, Number(model.expressionStrength || 0)));
          const haloOpacity = Math.max(0.08, Math.min(0.7, Number(model.expressionHaloOpacity || 0.18)));
          const haloWidth = Math.max(2, Number(model.expressionHaloWidth || 4));
          const ringCount = Math.max(1, Math.min(3, Number(model.expressionRingCount || 1)));
          const rippleRadius = Math.max(6, Number(model.expressionRippleRadius || 10));
          rings.push({ radius: radius + haloWidth, stroke: '#38bdf8', strokeWidth: haloWidth, opacity: haloOpacity });
          for (let index = 1; index <= ringCount; index += 1) {
            const fraction = index / ringCount;
            rings.push({
              radius: radius + 4 + rippleRadius * fraction,
              stroke: '#60a5fa',
              strokeWidth: 1.25 + strength,
              opacity: Math.max(0.05, haloOpacity * (1 - fraction * 0.68)),
            });
          }
        }
        return {
          id: node.id,
          x: node.x,
          y: node.y,
          radius,
          fill: model.fillColor || TYPE_COLORS[model.nodeType] || '#94a3b8',
          stroke: model.strokeColor || TYPE_STROKES[model.nodeType] || '#111111',
          strokeWidth: lineWidth,
          opacity,
          rings,
          label: label ? {
            text: label,
            fontSize: important ? importantLabelFontSize(model) : secondaryLabelFontSize(model),
            fontWeight: important ? 700 : 600,
            opacity,
          } : null,
        };
      });
      const exportEdges = edges.map((edge) => {
        const source = nodeById.get(String(edge.source || ''));
        const target = nodeById.get(String(edge.target || ''));
        const model = edge.sourceData || edge;
        return {
          id: edge.id,
          source: edge.source,
          target: edge.target,
          stroke: model.strokeColor || mixEdgeColor(source?.type, target?.type, boundedEvidenceOpacity(model)),
          strokeWidth: boundedEvidenceWidth(model) + (model.legendFocused ? 1.5 : 0),
          opacity: typeof model.legendOpacity === 'number' ? model.legendOpacity : 1,
          dash: Array.isArray(model.lineDash) ? model.lineDash : [],
        };
      });
      return serializer.serialize({
        title: visible.query || currentQuery || 'TE-Dee co-expression network',
        description: 'Static vector export of the currently visible TE-Dee co-expression network.',
        metadata: {
          generator: 'TE-Dee shared G6 SVG exporter',
          graph_mode: 'coexpression',
          query: visible.query || currentQuery,
          selection: currentGraphData.nodes?.find((node) => node.coexpressionIsCenter === true)?.coexpressionSelection || {},
          node_count: exportNodes.length,
          edge_count: exportEdges.length,
          expression_context: currentExpressionOverlay.context || 'off',
          interpretation_limit: 'Correlation and expression activity do not imply causation.',
        },
        nodes: exportNodes,
        edges: exportEdges,
      });
    }

    function resize() {
      const metrics = getContainerMetrics();
      if ((container.clientWidth || 0) < 25 && metrics.width > 0) {
        container.style.width = `${metrics.width}px`;
      }
      if ((container.clientHeight || 0) < 25 && metrics.height > 0) {
        container.style.height = `${metrics.height}px`;
      }
      if (graph && typeof graph.resize === 'function') {
        graph.resize();
      }
    }

    function setFixedView(next) {
      fixedView = !!next;
      hooks.syncRouteState({
        query: currentQuery,
        queryType: currentQueryType,
        classQuery: currentClassQuery,
        keyNodeLevel: currentKeyNodeLevel,
        fixedView,
        lang: 'en',
      });
      return Promise.resolve(fixedView);
    }

    function setViewState(next = {}) {
      if (Object.prototype.hasOwnProperty.call(next, 'fixedView')) {
        fixedView = next.fixedView === true;
      }
      if (Object.prototype.hasOwnProperty.call(next, 'showEdgeLabels')) {
        currentShowEdgeLabels = next.showEdgeLabels === true;
      }
      if (Object.prototype.hasOwnProperty.call(next, 'allowInspectCard')) {
        currentAllowInspectCard = next.allowInspectCard === true;
      }
      if (Object.prototype.hasOwnProperty.call(next, 'allowNodeActions')) {
        currentAllowNodeActions = next.allowNodeActions !== false;
      }
      if (!currentAllowInspectCard) hideInspectCard();
      hooks.syncRouteState({
        query: currentQuery,
        queryType: currentQueryType,
        classQuery: currentClassQuery,
        keyNodeLevel: currentKeyNodeLevel,
        fixedView,
        lang: 'en',
      });
      return applyCurrentViewState();
    }

    function setKeyNodeLevel(level) {
      currentKeyNodeLevel = Math.max(1, Math.min(10, Number(level) || 1));
      hooks.syncRouteState({
        query: currentQuery,
        queryType: currentQueryType,
        classQuery: currentClassQuery,
        keyNodeLevel: currentKeyNodeLevel,
        fixedView,
        lang: currentLang,
      });
      if (!currentQuery) return Promise.resolve();
      return loadGraph(buildCurrentRequest());
    }

    function setLanguage(_lang) {
      currentLang = 'en';
      hooks.syncRouteState({
        query: currentQuery,
        queryType: currentQueryType,
        classQuery: currentClassQuery,
        keyNodeLevel: currentKeyNodeLevel,
        fixedView,
        lang: 'en',
      });
      if (!currentQuery) return Promise.resolve();
      return loadGraph(buildCurrentRequest());
    }

    function setLegendFocus(focus) {
      currentLegendFocus = normalizeLegendFocus(focus);
      return pushLegendFocusVisualState();
    }

    async function setElementVisibility(visible = {}) {
      if (!graph || !currentGraphData || typeof graph.setElementVisibility !== 'function') {
        throw new Error('G6 element visibility is unavailable.');
      }
      const nodeIds = new Set(Array.isArray(visible.nodeIds) ? visible.nodeIds.map(String) : []);
      const edgeIds = new Set(Array.isArray(visible.edgeIds) ? visible.edgeIds.map(String) : []);
      const visibility = {};
      for (const node of currentGraphData.nodes || []) {
        const id = String(node.id || '');
        visibility[id] = nodeIds.has(id) ? 'visible' : 'hidden';
      }
      for (const edge of currentGraphData.edges || []) {
        const id = String(edge.id || '');
        visibility[id] = edgeIds.has(id) ? 'visible' : 'hidden';
      }
      await graph.setElementVisibility(visibility, false);
      hiddenElementIds = new Set(
        Object.entries(visibility)
          .filter(([, state]) => state === 'hidden')
          .map(([id]) => id),
      );
      return getVisibleSubgraph();
    }

    function init() {
      window.addEventListener('resize', resize);
      return ensureResources()
        .catch((error) => {
          console.warn('Failed to load shared English resources for G6 graph:', error);
        })
        .finally(() => {
          hooks.onReady();
        });
    }

    function destroy() {
      window.removeEventListener('resize', resize);
      hideInspectCard();
      if (coexpressionTooltip) coexpressionTooltip.remove();
      coexpressionTooltip = null;
      if (graph && typeof graph.destroy === 'function') graph.destroy();
      graph = null;
      currentGraphData = null;
    }

    return {
      init,
      destroy,
      ensureResources,
      loadGraph,
      expandGraph,
      renderElements,
      resize,
      setFixedView,
      setViewState,
      setExpressionOverlay,
      setKeyNodeLevel,
      setLanguage,
      setLegendFocus,
      setElementVisibility,
      getGraph: () => graph,
      getCurrentQuery: () => currentQuery,
      getCurrentRequest: () => buildCurrentRequest(),
      getVisibleSubgraph,
      inspectNode,
      triggerNodeAction,
      inspectEdge,
      getEdgeVisuals,
      exportPngDataUrl,
      exportSvgString,
      getFixedView: () => fixedView,
      getKeyNodeLevel: () => currentKeyNodeLevel,
      escapeHtml,
    };
  }

  window.__TEKG_COEXPRESSION_RENDERER_CORE = {
    forkSource: FORK_SOURCE,
    createRunner,
    escapeHtml,
  };
}());
