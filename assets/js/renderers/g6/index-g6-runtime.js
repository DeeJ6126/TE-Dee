(function () {
  const G6Lib = window.G6;
  if (!G6Lib || !G6Lib.Graph) {
    window.__TEKG_G6_RUNTIME = null;
    return;
  }

  const { Graph } = G6Lib;
  const runtime = {};
  let container = null;
  let queryInput = null;
  let levelMinusBtn = null;
  let levelDisplayBtn = null;
  let levelPlusBtn = null;
  let fixedBtn = null;
  let status = null;
  let detail = null;

  const TYPE_META = window.__TEKG_G6_TYPE_META && typeof window.__TEKG_G6_TYPE_META === 'object'
    ? window.__TEKG_G6_TYPE_META
    : {};
  const TYPE_COLORS = TYPE_META.colors || {};
  const TYPE_STROKES = TYPE_META.strokes || {};

  let graph = null;
  let fixedView = false;
  let currentKeyNodeLevel = 1;
  let nameTranslations = {};
  let teDescriptions = { en: {} };
  let entityDescriptions = { en: { Disease: {}, Function: {} } };
  let teLineageDepths = new Map();
  let teLineageChildren = new Map();
  let teLineageDescendants = new Map();
  let teDatabaseDegrees = new Map();
  let teFixedRadii = new Map();
  let currentQuery = 'LINE1';
  let resourcesPromise = null;
  const TE_MIN_RADIUS = 12.5;
  const AGGREGATE_TE_CHILD_SIZE_RATIO = 1.5;

  function setStatus(text) {
    if (status) status.textContent = text;
  }

  function updateFixedButton() {
    if (fixedBtn) fixedBtn.textContent = `Fixed view: ${fixedView ? 'On' : 'Off'}`;
  }

  function updateLevelControls() {
    if (levelDisplayBtn) {
      levelDisplayBtn.textContent = `Key-node level: ${currentKeyNodeLevel}`;
    }
    if (levelMinusBtn) {
      levelMinusBtn.disabled = currentKeyNodeLevel <= 1;
    }
    if (levelPlusBtn) {
      levelPlusBtn.disabled = currentKeyNodeLevel >= 10;
    }
  }

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function setDetail(title, description) {
    if (!detail) return;
    detail.innerHTML = `<strong>${escapeHtml(title)}</strong>${escapeHtml(description || 'No description.')}`;
  }

  function getQuery() {
    return (queryInput?.value || currentQuery || 'LINE1').trim() || 'LINE1';
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
      [...lineageNames].map((name) => [name, Math.max(1, Number(baseScores.get(name)) || 1)])
    );

    const namesByDepthDesc = [...lineageNames].sort(
      (left, right) => (teLineageDepths.get(right) || 0) - (teLineageDepths.get(left) || 0)
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
      (left, right) => (teLineageDepths.get(right) || 0) - (teLineageDepths.get(left) || 0)
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
        Math.min(currentRadius, maxChildRadius * AGGREGATE_TE_CHILD_SIZE_RATIO)
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

  function teShouldShowLabel(node) {
    const canonicalName = canonicalTeLineageName(node?.rawLabel || node?.displayLabel);
    const depth = teLineageDepths.get(canonicalName);
    return (Number.isFinite(depth) && depth <= 2) || (Math.max(0, Number(node?.databaseDegree) || 0) > 10);
  }

  function teLabelFontSize(node) {
    const text = String(node?.displayLabel || node?.rawLabel || '').trim();
    const diameter = Math.max(16, Number(node?.size) || 16);
    if (!text) return 12;
    const estimated = (diameter - 10) / Math.max(3, text.length * 0.62);
    return Math.max(8, Math.min(16, estimated));
  }

  function secondaryShouldShowLabel(node) {
    const nodeType = String(node?.nodeType || '');
    if (nodeType === 'DiseaseClass') return true;
    if (nodeType === 'DiseaseCategory') return true;

    const labelThresholds = {
      Disease: 34,
      Function: 34,
      Gene: 30,
      Protein: 30,
      RNA: 30,
      Mutation: 30,
      Pharmaceutical: 32,
      Toxin: 30,
      Lipid: 30,
      Peptide: 30,
      Carbohydrate: 30,
    };

    return Object.prototype.hasOwnProperty.call(labelThresholds, nodeType)
      && Math.max(0, Number(node?.size) || 0) >= labelThresholds[nodeType];
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
    return Math.max(7, Math.min(12, estimated));
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

  function displayLabelForNode(rawLabel, nodeType) {
    const translated = translateName(rawLabel, nodeType);
    return nodeType === 'DiseaseCategory'
      ? stripDiseaseCategoryDisplayPrefix(translated)
      : translated;
  }

  function hexToRgb(hex) {
    const raw = String(hex || '').trim();
    const rgbMatch = raw.match(/^rgb\(\s*(\d+),\s*(\d+),\s*(\d+)\s*\)$/i);
    if (rgbMatch) {
      return {
        r: Number(rgbMatch[1]),
        g: Number(rgbMatch[2]),
        b: Number(rgbMatch[3]),
      };
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

  function normalizeTranslationMap(raw) {
    return raw && typeof raw === 'object' ? raw : {};
  }

  async function loadEnglishResources() {
    const [nameRes, teDescRes, entityDescRes, teLineageRes, teMetricsRes] = await Promise.allSettled([
      fetch(window.__TEKG_PATHS.dataUrl('processed/entity_description_key_translation_cache.json'), { credentials: 'same-origin' }),
      fetch(window.__TEKG_PATHS.dataUrl('processed/te_descriptions.json'), { credentials: 'same-origin' }),
      fetch(window.__TEKG_PATHS.dataUrl('processed/entity_descriptions.json'), { credentials: 'same-origin' }),
      fetch(window.__TEKG_PATHS.apiUrl('taxonomy.php?view=tree'), { credentials: 'same-origin' }),
      fetch(window.__TEKG_PATHS.apiUrl('te_metrics.php'), { credentials: 'same-origin' }),
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
        Object.entries(payload?.metrics || {}).map(([name, degree]) => [canonicalTeLineageName(name), Math.max(0, Number(degree) || 0)])
      );
    }

    computeTeVisualMetrics();
  }

  function ensureResources() {
    if (!resourcesPromise) {
      resourcesPromise = loadEnglishResources().catch((error) => {
        console.warn('Failed to load English resources for G6 runtime:', error);
      });
    }
    return resourcesPromise;
  }

  function translateName(rawLabel, type) {
    const raw = String(rawLabel || '').trim();
    if (!raw) return '';
    if (!containsChinese(raw)) return getDisplayNameOverride(raw);
    const mapped = nameTranslations[raw];
    if (mapped) return mapped;
    return raw;
  }

  function translateDescription(nodeType, rawLabel, rawDescription) {
    const label = String(rawLabel || '').trim();
    const description = String(rawDescription || '').trim();
    if (nodeType === 'DiseaseClass') return description || 'Disease class node in the current graph.';
    if (nodeType === 'DiseaseCategory') return description || 'Disease classification category in the current graph.';
    if (nodeType === 'TE') {
      const key = translateName(label, nodeType) || label;
      const mapped = String(teDescriptions?.en?.[key] || '').trim();
      if (mapped) return mapped;
    }
    if (nodeType === 'Disease' || nodeType === 'Function') {
      const key = translateName(label, nodeType) || label;
      const mapped = String(entityDescriptions?.en?.[nodeType]?.[key] || '').trim();
      if (mapped) return mapped;
    }
    if (description) return description;
    return '';
  }

  function buildGraphData(elements) {
    const nodes = [];
    const edges = [];
    const allowedNodeIds = new Set();
    let anchorNodeId = '';

    for (const item of elements || []) {
      const data = item && item.data ? item.data : null;
      if (!data) continue;
      if (data.source && data.target) continue;
      if ((data.type || 'TE') === 'Paper') continue;
      if (!anchorNodeId) anchorNodeId = String(data.id || '');

      const node = {
        id: data.id,
        size: degreeToSize(data.degree),
        nodeType: data.type || 'TE',
        rawLabel: data.rawLabel || data.label || data.id,
        displayLabel: displayLabelForNode(data.label || data.rawLabel || data.id, data.type || 'TE'),
        databaseDegree: Math.max(0, Number(data.degree) || 0),
        description: translateDescription(data.type || 'TE', data.label || data.rawLabel || data.id, data.description || ''),
        diseaseClass: String(data.disease_class || ''),
        categoryLevel: Math.max(0, Number(data.category_level) || 0),
        team: buildTeam(data),
        queryLabel: (data.type || 'TE') === 'DiseaseCategory' ? '' : String(data.rawLabel || data.label || data.id),
        fillColor: TYPE_COLORS[data.type || 'TE'] || '#94a3b8',
        strokeColor: TYPE_STROKES[data.type || 'TE'] || '#111111',
      };

      nodes.push(node);
      allowedNodeIds.add(node.id);
    }

    const teNodes = nodes.filter((node) => node.nodeType === 'TE');
    for (const node of teNodes) {
      const canonicalName = canonicalTeLineageName(node.rawLabel || node.displayLabel);
      const fixedRadius = teRadiusForName(canonicalName, node.databaseDegree);
      node.size = fixedRadius * 2;
      node.fillColor = teFillColorForName(canonicalName);
      node.strokeColor = darkenHexColor(node.fillColor, 0.28);
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

    const baseEdges = [];
    for (const item of elements || []) {
      const data = item && item.data ? item.data : null;
      if (!data || !data.source || !data.target) continue;
      if (!allowedNodeIds.has(data.source) || !allowedNodeIds.has(data.target)) continue;
      baseEdges.push({
        source: data.source,
        target: data.target,
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
    for (const node of candidateNodes) {
      adjacency.set(node.id, []);
    }
    for (const edge of baseEdges) {
      if (!adjacency.has(edge.source) || !adjacency.has(edge.target)) continue;
      adjacency.get(edge.source).push(edge.target);
      adjacency.get(edge.target).push(edge.source);
    }

    const mainComponentNodeIds = new Set();
    const traversalStartId = adjacency.has(anchorNodeId)
      ? anchorNodeId
      : (candidateNodes[0]?.id || '');
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

    const visibleNodes = baseEdges.length === 0 || nonIsolatedNodes.length === 0
      ? [...candidateNodes]
      : candidateNodes.filter((node) => mainComponentNodeIds.has(node.id));
    const visibleNodeIds = new Set(visibleNodes.map((node) => node.id));

    for (const edge of baseEdges) {
      if (!visibleNodeIds.has(edge.source) || !visibleNodeIds.has(edge.target)) continue;
      edges.push({
        source: edge.source,
        target: edge.target,
      });
    }

    return { nodes: visibleNodes, edges };
  }

  function resolveNode(edgeSide, nodes) {
    if (edgeSide && typeof edgeSide === 'object') return edgeSide;
    return nodes.find((node) => node.id === edgeSide) || null;
  }

  async function loadGraph(forcedQuery) {
    const query = (forcedQuery || getQuery()).trim() || 'LINE1';
    currentQuery = query;
    if (queryInput) queryInput.value = query;
    setStatus(`Loading one-hop graph for ${query} (key-node level ${currentKeyNodeLevel}) ...`);

    try {
      const endpoint = new URL(window.__TEKG_PATHS.apiUrl('graph.php'), window.location.origin);
      endpoint.searchParams.set('q', query);
      endpoint.searchParams.set('key_level', String(currentKeyNodeLevel));
      const response = await fetch(endpoint.toString(), {
        credentials: 'same-origin',
      });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const payload = await response.json();
      const data = buildGraphData(payload.elements || []);

      if (graph && typeof graph.destroy === 'function') {
        graph.destroy();
      }

      graph = new Graph({
        container,
        autoFit: 'center',
        data,
        node: {
          style: {
            size: (d) => d.size,
            fill: (d) => d.fillColor || TYPE_COLORS[d.nodeType] || '#94a3b8',
            stroke: (d) => d.strokeColor || TYPE_STROKES[d.nodeType] || '#111111',
            lineWidth: 2,
            labelText: (d) => {
              if (d.nodeType === 'TE' && teShouldShowLabel(d)) {
                return d.displayLabel || d.rawLabel || '';
              }
              if (secondaryShouldShowLabel(d)) {
                return secondaryLabelText(d);
              }
              return '';
            },
            labelPlacement: 'center',
            labelFill: '#111111',
            labelFontSize: (d) => {
              if (d.nodeType === 'TE' && teShouldShowLabel(d)) {
                return teLabelFontSize(d);
              }
              if (secondaryShouldShowLabel(d)) {
                return secondaryLabelFontSize(d);
              }
              return 10;
            },
            labelFontWeight: 700,
            labelStroke: '#ffffff',
            labelLineWidth: 3,
            labelLineJoin: 'round',
          },
        },
        edge: {
          style: {
            stroke: (edge) => {
              const source = resolveNode(edge.source, data.nodes);
              const target = resolveNode(edge.target, data.nodes);
              const alpha = edge.synthetic ? 0.62 : 0.5;
              return mixEdgeColor(source?.nodeType, target?.nodeType, alpha);
            },
            lineWidth: (edge) => (edge.synthetic ? 1.9 : 1.5),
          },
        },
        layout: {
          type: 'd3-force',
          link: {
            distance: (edge) => {
              const source = resolveNode(edge.source, data.nodes);
              const target = resolveNode(edge.target, data.nodes);
              if (!source || !target) return 80;
              if (source.nodeType === 'DiseaseClass' || target.nodeType === 'DiseaseClass') return 56;
              if (source.team === target.team) return 48;
              if (source.nodeType === 'Disease' || target.nodeType === 'Disease') return 170;
              return 110;
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
              if (node.nodeType === 'DiseaseClass') return -(90 + size * 2.4);
              return -(55 + size * 1.8);
            },
          },
          collide: {
            radius: (node) => node.size / 2 + 10,
            strength: 1,
            iterations: 6,
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

      await graph.render();
      graph.off?.('node:click');
      graph.on('node:click', (event) => {
        const nodeId = event?.target?.id;
        const node = data.nodes.find((item) => item.id === nodeId);
        if (!node) return;
        setDetail(node.displayLabel || node.rawLabel, node.description);
        if (!fixedView && node.nodeType !== 'DiseaseClass' && node.queryLabel) {
          loadGraph(node.queryLabel);
        }
      });
      setDetail('No node selected', 'Click a node to inspect its name and description.');
      setStatus(`Loaded ${data.nodes.length} nodes and ${data.edges.length} edges for ${query} at key-node level ${currentKeyNodeLevel}.`);
    } catch (error) {
      setStatus(`Failed: ${error && error.message ? error.message : 'unknown error'}`);
      console.error('G6 test graph failed:', error);
    }
  }

  runtime.init = async function init(options = {}) {
    container = document.getElementById(options.containerId || 'container');
    queryInput = document.getElementById(options.queryInputId || 'query-input');
    levelMinusBtn = document.getElementById(options.levelMinusId || 'level-minus');
    levelDisplayBtn = document.getElementById(options.levelDisplayId || 'level-display');
    levelPlusBtn = document.getElementById(options.levelPlusId || 'level-plus');
    fixedBtn = document.getElementById(options.fixedBtnId || 'fixed-btn');
    status = document.getElementById(options.statusId || 'status');
    detail = document.getElementById(options.detailId || 'detail');
    fixedView = !!options.fixedView;
    currentKeyNodeLevel = Math.max(1, Math.min(10, Number(options.keyNodeLevel) || 1));
    currentQuery = String(options.initialQuery || currentQuery || 'LINE1').trim() || 'LINE1';
    if (queryInput && !queryInput.value) queryInput.value = currentQuery;
    updateFixedButton();
    updateLevelControls();
    setDetail('No node selected', 'Click a node to inspect its name and description.');
    return ensureResources();
  };

  runtime.loadGraph = async function loadGraphPublic(query) {
    await ensureResources();
    return loadGraph(query);
  };

  runtime.destroy = function destroy() {
    if (graph && typeof graph.destroy === 'function') {
      graph.destroy();
    }
    graph = null;
  };

  runtime.setFixedView = function setFixedView(next) {
    fixedView = !!next;
    updateFixedButton();
  };

  runtime.getFixedView = function getFixedView() {
    return fixedView;
  };

  runtime.setKeyNodeLevel = function setKeyNodeLevel(level) {
    currentKeyNodeLevel = Math.max(1, Math.min(10, Number(level) || 1));
    updateLevelControls();
  };

  runtime.getKeyNodeLevel = function getKeyNodeLevel() {
    return currentKeyNodeLevel;
  };

  runtime.getCurrentQuery = function getCurrentQuery() {
    return currentQuery;
  };

  runtime.setStatus = setStatus;
  runtime.setDetail = setDetail;

  window.__TEKG_G6_RUNTIME = runtime;
}());
