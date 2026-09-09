(function () {
  if (window.__TEKG_RENDERER_MODE !== 'g6') return;

  const G6Lib = window.G6;
  if (!G6Lib) return;

  const {
    Graph,
    register,
    ExtensionCategory,
    Rect,
    CircleCombo,
    Badge,
  } = G6Lib;
  if (!Graph || !register || !ExtensionCategory || !Rect || !CircleCombo || !Badge) return;

  const TYPE_ORDER = ['TE', 'Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Pharmaceutical', 'Toxin', 'Lipid', 'Peptide', 'Carbohydrate'];
  const TYPE_LEGEND_ORDER = [...TYPE_ORDER, 'Paper'];
  const TYPE_LABEL = {
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
  const TYPE_COLORS = {
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
  const COMBO_BG = {
    TE: '#eef4ff',
    Disease: '#fff1f1',
    Function: '#eefaf5',
    Gene: '#f3efff',
    Protein: '#eefafa',
    RNA: '#eef6ff',
    Mutation: '#fff4e8',
    Pharmaceutical: '#f4f0ff',
    Toxin: '#fff1ec',
    Lipid: '#f3fae8',
    Peptide: '#eafaf7',
    Carbohydrate: '#fdf8e7',
    Paper: '#fff7ea',
  };
  const COMBO_STROKE = {
    TE: '#b9ccff',
    Disease: '#ffc8c8',
    Function: '#bfe9d4',
    Gene: '#e4dcff',
    Protein: '#d3f0ed',
    RNA: '#dcebff',
    Mutation: '#ffe1bf',
    Pharmaceutical: '#e4dbff',
    Toxin: '#f5d3ca',
    Lipid: '#e0efc8',
    Peptide: '#d0f1ec',
    Carbohydrate: '#efe0a8',
    Paper: '#ffd89b',
  };
  const NODE_STROKE = {
    TE: '#2f63d8',
    Disease: '#d65b6f',
    Function: '#2f9d6b',
    Gene: '#6656d8',
    Protein: '#2d8f87',
    RNA: '#3d88db',
    Mutation: '#db7c1f',
    Pharmaceutical: '#7a60d4',
    Toxin: '#b25d49',
    Lipid: '#6ea23b',
    Peptide: '#249f97',
    Carbohydrate: '#ab8b28',
    Paper: '#d08c23',
  };

  let g6DynamicGraph = null;
  let isBound = false;
  let registered = false;
  let lastPayload = null;

  const FOCUS_TUNING = {
    duration: 220,
    zoomDuration: 180,
    localZoom: 1.08,
  };
  const DISPLAY_TUNING = {
    viewportPadding: 72,
    maxCharsPerLine: 24,
    maxLabelLines: 2,
  };
  const FORCE_LAYOUT_TUNING = {
    iterations: 600,
    velocityDecay: 0.28,
    alphaDecay: 0.035,
    linkGapSameCombo: 28,
    linkGapCrossCombo: 72,
    collidePadding: 14,
    manyBodyBaseStrength: -220,
    manyBodyScale: 1.8,
  };
  const NODE_SIZE_TUNING = {
    minWidth: 260,
    minHeight: 148,
    maxWidth: 760,
    maxHeight: 280,
    widthPerChar: 20,
    widthPadding: 72,
    widthPerRelation: 18,
    heightPerRelation: 12,
  };

  function getEl(id) {
    return document.getElementById(id);
  }

  function getNameSafe(label, type, description, pmid) {
    if (typeof getName === 'function') return getName(label || '', type || 'Unknown', description || '', pmid || '');
    return String(label || '');
  }

  function getDescSafe(label, type, description, pmid) {
    if (typeof getDesc === 'function') return getDesc(label || '', type || 'Unknown', description || '', pmid || '');
    return String(description || '');
  }

  function getRelSafe(relation) {
    if (typeof getRel === 'function') return getRel(relation || '');
    return String(relation || '');
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

  function updateDetailFromDatum(datum) {
    const detailEl = getEl('node-details');
    if (!detailEl) return;
    if (!datum) {
      detailEl.textContent = 'G6 dynamic graph is active.';
      return;
    }

    if (datum.kind === 'combo') {
      detailEl.innerHTML = `<strong>${datum.label}</strong><br>${datum.description}`;
      return;
    }

    if (datum.kind === 'edge') {
      detailEl.innerHTML = `<strong>${datum.sourceLabel}</strong> -> ${datum.relation} -> <strong>${datum.targetLabel}</strong><br>${datum.evidence}`;
      return;
    }

    const label = getNameSafe(datum.rawLabel || datum.label, datum.type, datum.description, datum.pmid);
    const desc = getDescSafe(datum.rawLabel || datum.label, datum.type, datum.description, datum.pmid);
    const typeName = TYPE_LABEL[datum.type] || datum.type || 'Node';
    const pmidLine = datum.pmid ? ` | PMID: ${datum.pmid}` : '';
    detailEl.innerHTML = `<strong>${label}</strong> (${typeName})<br>${desc}<div class="meta">${datum.rawLabel || datum.label}${pmidLine}</div>`;
  }

  function buildComboDescription(type, nodeCount) {
    const map = {
      TE: `This group contains ${nodeCount} transposable element nodes.`,
      Disease: `This group contains ${nodeCount} disease nodes.`,
      Function: `This group contains ${nodeCount} function or mechanism nodes.`,
      Gene: `This group contains ${nodeCount} gene nodes.`,
      Protein: `This group contains ${nodeCount} protein nodes.`,
      RNA: `This group contains ${nodeCount} RNA nodes.`,
      Mutation: `This group contains ${nodeCount} mutation nodes.`,
      Pharmaceutical: `This group contains ${nodeCount} pharmaceutical nodes.`,
      Toxin: `This group contains ${nodeCount} toxin nodes.`,
      Lipid: `This group contains ${nodeCount} lipid nodes.`,
      Peptide: `This group contains ${nodeCount} peptide nodes.`,
      Carbohydrate: `This group contains ${nodeCount} carbohydrate nodes.`,
      Paper: `This group contains ${nodeCount} paper nodes.`,
    };
    return map[type] || `This group contains ${nodeCount} nodes.`;
  }
  function buildDiseaseComboLabel(diseaseClass) {
    return String(diseaseClass || 'Disease');
  }

  function buildComboKey(type, diseaseClass = '') {
    if (type === 'Disease') {
      const cls = String(diseaseClass || '').trim() || 'Disease';
      return `Disease::${cls}`;
    }
    return null;
  }

  function comboKeyToId(comboKey) {
    return `combo-${String(comboKey).replace(/[^a-zA-Z0-9_-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '')}`;
  }

  function wrapTextLines(text, maxCharsPerLine = 22) {
    const raw = String(text || '').trim();
    if (!raw) return [];
    const words = raw.split(/\s+/);
    const lines = [];
    let current = '';
    for (const word of words) {
      const candidate = current ? `${current} ${word}` : word;
      if (candidate.length <= maxCharsPerLine || !current) current = candidate;
      else {
        lines.push(current);
        current = word;
      }
    }
    if (current) lines.push(current);
    return lines;
  }

  function wrapNodeLabel(text, maxCharsPerLine = 22) {
    return wrapTextLines(text, maxCharsPerLine).join('\n');
  }

  function fitLabelLines(text, maxLines = 2) {
    const raw = String(text || '').trim();
    if (!raw) return { lines: [''], longestLineLength: 0 };

    let bestLines = wrapTextLines(raw, DISPLAY_TUNING.maxCharsPerLine);
    for (let charsPerLine = 14; charsPerLine <= 48; charsPerLine += 2) {
      const lines = wrapTextLines(raw, charsPerLine);
      bestLines = lines;
      if (lines.length <= maxLines) break;
    }

    const longestLineLength = bestLines.reduce((max, line) => Math.max(max, String(line).length), 0);
    return { lines: bestLines.slice(0, maxLines), longestLineLength };
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function getNodeSize(label, relationCount) {
    const safeLabel = String(label || '');
    const count = Math.max(0, Number(relationCount) || 0);
    const { lines, longestLineLength } = fitLabelLines(safeLabel, DISPLAY_TUNING.maxLabelLines);
    const baseWidth = Math.max(
      NODE_SIZE_TUNING.minWidth,
      longestLineLength * NODE_SIZE_TUNING.widthPerChar + NODE_SIZE_TUNING.widthPadding,
    );
    const width = clamp(
      Math.round(baseWidth + count * NODE_SIZE_TUNING.widthPerRelation),
      NODE_SIZE_TUNING.minWidth,
      NODE_SIZE_TUNING.maxWidth,
    );
    const height = clamp(
      Math.round(
        NODE_SIZE_TUNING.minHeight
        + Math.max(0, lines.length - 1) * 44
        + count * NODE_SIZE_TUNING.heightPerRelation,
      ),
      NODE_SIZE_TUNING.minHeight,
      NODE_SIZE_TUNING.maxHeight,
    );
    const diameter = clamp(
      Math.round(Math.max(width, height * 1.15)),
      NODE_SIZE_TUNING.minHeight,
      NODE_SIZE_TUNING.maxWidth,
    );
    return {
      size: diameter,
      displayLabel: lines.join('\n'),
    };
  }

  function getNodeDimensions(node) {
    const rawSize = node?.style?.size ?? node?.size ?? null;
    if (typeof rawSize === 'number') {
      return { width: rawSize, height: rawSize };
    }
    const [width = NODE_SIZE_TUNING.minWidth, height = NODE_SIZE_TUNING.minHeight] = Array.isArray(rawSize)
      ? rawSize
      : [NODE_SIZE_TUNING.minWidth, NODE_SIZE_TUNING.minHeight];
    return { width, height };
  }

  function getNodeCollisionRadius(node) {
    const { width, height } = getNodeDimensions(node);
    const diameter = Math.max(width, height);
    return diameter / 2 + FORCE_LAYOUT_TUNING.collidePadding;
  }

  function resolveLayoutNodeRef(ref, nodeMap) {
    if (!ref) return null;
    if (typeof ref === 'string' || typeof ref === 'number') return nodeMap.get(String(ref)) || nodeMap.get(ref) || null;
    if (typeof ref === 'object') {
      if (ref.id && nodeMap.has(ref.id)) return nodeMap.get(ref.id);
      return ref;
    }
    return null;
  }

  function buildGraphData(elements, options = {}) {
    const includePapers = !!options.includePapers;
    const hideNonKeyNodes = !!options.hideNonKeyNodes;
    const rawNodes = [];
    const rawEdges = [];

    for (const item of elements || []) {
      const data = item && item.data ? item.data : null;
      if (!data) continue;
      if (data.source && data.target) rawEdges.push(data);
      else rawNodes.push(data);
    }

    const hasKeyNodeMetadata = rawNodes.some((node) => typeof node.isKeyNode === 'boolean');
    const counts = { TE: 0, Disease: 0, Function: 0, Gene: 0, Protein: 0, RNA: 0, Mutation: 0, Pharmaceutical: 0, Toxin: 0, Lipid: 0, Peptide: 0, Carbohydrate: 0, Paper: 0 };
    const comboCounts = new Map();
    const filteredNodes = rawNodes.filter((node) => {
      const type = TYPE_LABEL[node.type] ? node.type : 'TE';
      const keepPaper = includePapers || type !== 'Paper';
      const keepKey = !hideNonKeyNodes || !hasKeyNodeMetadata || node.isKeyNode === true;
      return keepPaper && keepKey;
    });
    filteredNodes.forEach((node) => {
      const type = TYPE_LABEL[node.type] ? node.type : 'TE';
      counts[type] = (counts[type] || 0) + 1;
      const comboKey = buildComboKey(type, node.disease_class || node.diseaseClass || '');
      if (comboKey) {
        comboCounts.set(comboKey, (comboCounts.get(comboKey) || 0) + 1);
      }
    });
    const eligibleComboKeys = Array.from(comboCounts.keys()).filter((comboKey) => (comboCounts.get(comboKey) || 0) > 1);
    const useCombos = eligibleComboKeys.length > 0;
    const nodes = filteredNodes.map((node) => {
      const type = TYPE_LABEL[node.type] ? node.type : 'TE';
      const diseaseClass = String(node.disease_class || node.diseaseClass || '');
      const comboKey = buildComboKey(type, diseaseClass);
      const comboId = comboKey && useCombos && (comboCounts.get(comboKey) || 0) > 1 ? comboKeyToId(comboKey) : undefined;
      return {
        id: node.id,
        size: NODE_SIZE_TUNING.minHeight,
        ...(comboId ? { combo: comboId } : {}),
        data: {
          kind: 'node',
          rawLabel: node.label,
          label: getNameSafe(node.label, type, node.description, node.pmid),
          displayLabel: getNameSafe(node.label, type, node.description, node.pmid),
          type,
          diseaseClass,
          description: node.description || '',
          pmid: node.pmid || '',
          databaseDegree: Math.max(0, Number(node.degree) || 0),
          relationCount: 0,
        },
        style: {
          size: NODE_SIZE_TUNING.minHeight,
        },
      };
    });

    const nodeMap = new Map(nodes.map((node) => [node.id, node]));
    const combos = useCombos
      ? eligibleComboKeys.map((comboKey) => {
        const [type, diseaseClass = ''] = comboKey.split('::');
        const nodeCount = comboCounts.get(comboKey) || 0;
        const label = type === 'Disease' ? buildDiseaseComboLabel(diseaseClass) : TYPE_LABEL[type];
        return {
          id: comboKeyToId(comboKey),
          data: {
            kind: 'combo',
            type,
            diseaseClass,
            label,
            description: type === 'Disease'
              ? `This group contains ${nodeCount} disease nodes in ${label}.`
              : buildComboDescription(type, nodeCount),
          },
          style: {
            labelText: label,
            collapsed: false,
          },
        };
      })
      : [];

    const edges = rawEdges
      .filter((edge) => nodeMap.has(edge.source) && nodeMap.has(edge.target))
      .map((edge, index) => {
        const source = nodeMap.get(edge.source);
        const target = nodeMap.get(edge.target);
        source.data.relationCount += 1;
        target.data.relationCount += 1;
        return {
          id: edge.id || `edge-${index}`,
          source: edge.source,
          target: edge.target,
          data: {
            kind: 'edge',
            relation: getRelSafe(edge.relation || edge.relationType || ''),
            sourceLabel: source.data.label,
            targetLabel: target.data.label,
            evidence: edge.evidence || (Array.isArray(edge.pmids) && edge.pmids.length ? `PMID: ${edge.pmids.join(', ')}` : 'No evidence attached.'),
          },
        };
      });

    nodes.forEach((node) => {
      const effectiveDegree = Math.max(node.data.relationCount, node.data.databaseDegree || 0);
      const sized = getNodeSize(node.data.label, effectiveDegree);
      node.style.size = sized.size;
      node.size = sized.size;
      node.data.displayLabel = effectiveDegree <= 1 ? '' : sized.displayLabel;
    });

    return { nodes, edges, combos, useCombos };
  }

  class CircleComboWithExtraButton extends CircleCombo {
    render(attributes, container) {
      super.render(attributes, container);
      this.drawButton(attributes);
    }

    drawButton(attributes) {
      const { collapsed } = attributes;
      const [, height] = this.getKeySize(attributes);
      const y = height / 2 + 8;
      this.upsert(
        'hit-area',
        Badge,
        {
          text: collapsed ? '+' : '-',
          fill: '#3d81f7',
          fontSize: 10,
          textAlign: 'center',
          backgroundFill: '#ffffff',
          backgroundStroke: '#3d81f7',
          backgroundLineWidth: 1.2,
          backgroundWidth: 18,
          backgroundHeight: 18,
          x: 0,
          y,
          cursor: 'pointer',
        },
        this,
      );
    }

    onCreate() {
      this.shapeMap['hit-area'].addEventListener('click', (event) => {
        event.stopPropagation();
        const id = this.id;
        const collapsed = !this.attributes.collapsed;
        const { graph } = this.context;
        if (collapsed) graph.collapseElement(id);
        else graph.expandElement(id);
      });
    }
  }

  class TEKGDynamicNode extends Rect {
    render(attributes = this.parsedAttributes, container) {
      const withoutBuiltInLabel = { ...attributes, labelText: '' };
      super.render(withoutBuiltInLabel, container);
      this.drawOutlinedLabel(attributes, container);
    }

    drawOutlinedLabel(attributes, container) {
      const [width = 260, height = 148] = Array.isArray(attributes.size)
        ? attributes.size
        : [attributes.size || 260, attributes.size || 260];
      const sourceText = String(attributes.labelText || '');
      const text = sourceText.includes('\n')
        ? sourceText
        : wrapNodeLabel(sourceText, Math.max(16, Math.floor(width / 12)));
      const x = 0;
      const y = 0;
      const fontSize = attributes.labelFontSize || 54;
      const fontWeight = attributes.labelFontWeight || 700;

      this.upsert(
        'label-outline',
        'text',
        {
          x,
          y,
          text,
          fontSize,
          fontWeight,
          fill: '#111827',
          stroke: '#ffffff',
          lineWidth: 14,
          lineJoin: 'round',
          lineCap: 'round',
          textAlign: 'center',
          textBaseline: 'middle',
          wordWrap: true,
          wordWrapWidth: width - 32,
          pointerEvents: 'none',
        },
        container,
      );
    }
  }

  function ensureRegistered() {
    if (registered) return;
    register(ExtensionCategory.NODE, 'tekg-dynamic-node', TEKGDynamicNode);
    register(ExtensionCategory.COMBO, 'circle-combo-with-extra-button', CircleComboWithExtraButton);
    registered = true;
  }

  function destroyGraph() {
    if (g6DynamicGraph && typeof g6DynamicGraph.destroy === 'function') g6DynamicGraph.destroy();
    g6DynamicGraph = null;
  }

  async function focusTarget(id) {
    if (!g6DynamicGraph || !id || typeof g6DynamicGraph.focusElement !== 'function') return;
    const fixedModeEnabled = typeof fixedView !== 'undefined' && fixedView === true;
    if (fixedModeEnabled) return;
    try {
      await g6DynamicGraph.focusElement(id, {
        duration: FOCUS_TUNING.duration,
        easing: 'ease-in-out',
      });
      if (typeof focusLevel !== 'undefined' && focusLevel === 100 && typeof g6DynamicGraph.getZoom === 'function' && typeof g6DynamicGraph.zoomTo === 'function') {
        const currentZoom = g6DynamicGraph.getZoom();
        await g6DynamicGraph.zoomTo(Math.max(currentZoom, FOCUS_TUNING.localZoom), {
          duration: FOCUS_TUNING.zoomDuration,
          easing: 'ease-out',
        });
      }
    } catch (error) {
      console.warn('G6 dynamic focus failed:', error);
    }
  }

  async function renderDynamicGraph(elements, focusLabel = '', payload = null, options = {}) {
    const detailEl = getEl('node-details');
    try {
      ensureRegistered();
      setRendererVisibility();
      currentGraphKind = 'dynamic';
      lastPayload = { elements: JSON.parse(JSON.stringify(elements || [])), focusLabel, payload, options: { ...options } };
      if (window.__TEKG_G6_DEFAULT_TREE && typeof window.__TEKG_G6_DEFAULT_TREE.destroy === 'function') {
        window.__TEKG_G6_DEFAULT_TREE.destroy();
      }

      const host = getEl('g6-default-tree-surface');
      if (!host) return;

      await new Promise((resolve) => requestAnimationFrame(resolve));
      const width = host.clientWidth || host.offsetWidth;
      const height = host.clientHeight || host.offsetHeight;
      if (!width || !height) {
        if (detailEl) detailEl.textContent = 'G6 dynamic graph container has no size yet.';
        return;
      }

      const data = buildGraphData(elements || [], {
        includePapers: !!payload?.__fromQa,
        hideNonKeyNodes: !!options.hideNonKeyNodes,
      });
      destroyGraph();
      host.innerHTML = '';

      const focusNode = focusLabel
        ? data.nodes.find((node) => node.data?.rawLabel === focusLabel || node.data?.label === focusLabel || node.id === focusLabel)
        : null;

      const centerId = focusNode?.id || data.nodes[0]?.id || null;
      const layoutNodeMap = new Map(data.nodes.map((node) => [node.id, node]));

      const graph = new Graph({
        container: host,
        width,
        height,
        autoResize: true,
        autoFit: {
          type: 'view',
          options: {
            when: 'overflow',
          },
        },
        padding: DISPLAY_TUNING.viewportPadding,
        animation: false,
        data,
        layout: {
          type: 'd3-force',
          iterations: FORCE_LAYOUT_TUNING.iterations,
          alpha: 1,
          alphaMin: 0.001,
          alphaDecay: FORCE_LAYOUT_TUNING.alphaDecay,
          velocityDecay: FORCE_LAYOUT_TUNING.velocityDecay,
          nodeSize: (node) => {
            const resolved = resolveLayoutNodeRef(node, layoutNodeMap) || node;
            return getNodeCollisionRadius(resolved) * 2;
          },
          link: {
            distance: (edge) => {
              const source = resolveLayoutNodeRef(edge.source, layoutNodeMap);
              const target = resolveLayoutNodeRef(edge.target, layoutNodeMap);
              const sourceRadius = getNodeCollisionRadius(source);
              const targetRadius = getNodeCollisionRadius(target);
              const sameCombo = !!source?.combo && source.combo === target?.combo;
              const gap = sameCombo ? FORCE_LAYOUT_TUNING.linkGapSameCombo : FORCE_LAYOUT_TUNING.linkGapCrossCombo;
              return sourceRadius + targetRadius + gap;
            },
            strength: (edge) => {
              const source = resolveLayoutNodeRef(edge.source, layoutNodeMap);
              const target = resolveLayoutNodeRef(edge.target, layoutNodeMap);
              return source?.combo && source.combo === target?.combo ? 0.22 : 0.12;
            },
            iterations: 3,
          },
          manyBody: {
            strength: (node) => {
              const resolved = resolveLayoutNodeRef(node, layoutNodeMap) || node;
              const radius = getNodeCollisionRadius(resolved);
              return FORCE_LAYOUT_TUNING.manyBodyBaseStrength - radius * FORCE_LAYOUT_TUNING.manyBodyScale;
            },
            distanceMax: Math.max(width, height) * 1.4,
          },
          collide: {
            radius: (node) => {
              const resolved = resolveLayoutNodeRef(node, layoutNodeMap) || node;
              return getNodeCollisionRadius(resolved);
            },
            strength: 1,
            iterations: 8,
          },
        },
        combo: {
          type: 'circle-combo-with-extra-button',
          animation: {
            collapse: false,
            expand: false,
          },
          style: {
            lineWidth: 1.4,
            stroke: (datum) => COMBO_STROKE[datum.data?.type] || '#d8e4f0',
            fill: (datum) => COMBO_BG[datum.data?.type] || '#f8fbff',
            fillOpacity: 0.18,
            shadowColor: (datum) => COMBO_STROKE[datum.data?.type] || '#d8e4f0',
            shadowBlur: 22,
            shadowOffsetX: 0,
            shadowOffsetY: 18,
            shadowType: 'outer',
            increasedLineWidthForHitTesting: 28,
            collapsedSize: 460,
            collapsedLineWidth: 5.2,
            collapsedFill: (datum) => {
              const type = datum.data?.type;
              const map = {
                TE: '#3b6fdf',
                Disease: '#d86a7a',
                Function: '#3da978',
                Gene: '#6b4ee6',
                Protein: '#199393',
                RNA: '#458fe3',
                Mutation: '#eb8a20',
                Pharmaceutical: '#7c5ee0',
                Toxin: '#bf6246',
                Lipid: '#6ea930',
                Peptide: '#12a391',
                Carbohydrate: '#b79122',
                Paper: '#d39a34',
              };
              return map[type] || '#64748b';
            },
            collapsedFillOpacity: 1,
            collapsedStroke: (datum) => {
              const type = datum.data?.type;
              const map = {
                TE: '#1d4ed8',
                Disease: '#b84e60',
                Function: '#21885c',
                Gene: '#5437cf',
                Protein: '#126f6f',
                RNA: '#2a72c5',
                Mutation: '#c96d05',
                Pharmaceutical: '#6340bc',
                Toxin: '#9f4d34',
                Lipid: '#55861e',
                Peptide: '#0d8b7b',
                Carbohydrate: '#8b6d11',
                Paper: '#b57f1d',
              };
              return map[type] || '#334155';
            },
            collapsedStrokeOpacity: 1,
            collapsedShadowColor: (datum) => {
              const type = datum.data?.type;
              const map = {
                TE: 'rgba(29, 78, 216, 0.45)',
                Disease: 'rgba(184, 78, 96, 0.45)',
                Function: 'rgba(33, 136, 92, 0.45)',
                Gene: 'rgba(84, 55, 207, 0.45)',
                Protein: 'rgba(18, 111, 111, 0.45)',
                RNA: 'rgba(42, 114, 197, 0.45)',
                Mutation: 'rgba(201, 109, 5, 0.45)',
                Pharmaceutical: 'rgba(99, 64, 188, 0.45)',
                Toxin: 'rgba(159, 77, 52, 0.45)',
                Lipid: 'rgba(85, 134, 30, 0.45)',
                Peptide: 'rgba(13, 139, 123, 0.45)',
                Carbohydrate: 'rgba(139, 109, 17, 0.45)',
                Paper: 'rgba(181, 127, 29, 0.45)',
              };
              return map[type] || 'rgba(51, 65, 85, 0.45)';
            },
            collapsedShadowBlur: 36,
            collapsedShadowOffsetX: 0,
            collapsedShadowOffsetY: 28,
            collapsedShadowType: 'outer',
            collapsedIncreasedLineWidthForHitTesting: 56,
            collapsedMarker: false,
            labelText: (datum) => datum.data?.label || datum.id,
            labelFill: '#1f2b46',
            labelFontWeight: 700,
            labelFontSize: 54,
            collapsedLabelFill: '#ffffff',
            collapsedLabelFontWeight: 700,
            collapsedLabelFontSize: 62,
            labelPlacement: 'top',
            labelMaxWidth: 140,
            collapsed: false,
          },
          state: {
            selected: {
              stroke: (datum) => (datum.style?.collapsed
                ? (({
                  TE: '#1d4ed8',
                  Disease: '#b84e60',
                  Function: '#21885c',
                  Paper: '#b57f1d',
                })[datum.data?.type] || '#334155')
                : '#2563eb'),
              lineWidth: (datum) => (datum.style?.collapsed ? 5.2 : 2),
            },
            active: {
              stroke: (datum) => (datum.style?.collapsed
                ? (({
                  TE: '#1d4ed8',
                  Disease: '#b84e60',
                  Function: '#21885c',
                  Paper: '#b57f1d',
                })[datum.data?.type] || '#334155')
                : '#2563eb'),
              lineWidth: (datum) => (datum.style?.collapsed ? 5.2 : 2),
            },
          },
        },
        node: {
          type: 'circle',
          style: {
            size: (datum) => datum.style?.size || NODE_SIZE_TUNING.minHeight,
            fill: (datum) => TYPE_COLORS[datum.data?.type] || '#94a3b8',
            stroke: (datum) => NODE_STROKE[datum.data?.type] || TYPE_COLORS[datum.data?.type] || '#94a3b8',
            lineWidth: 1.2,
            shadowColor: 'rgba(15, 23, 42, 0.08)',
            shadowBlur: 10,
            labelText: (datum) => datum.data?.displayLabel || '',
            labelFill: '#1f2b46',
            labelPlacement: 'center',
            labelTextAlign: 'center',
            labelTextBaseline: 'middle',
            labelFontSize: 54,
            labelFontWeight: 700,
            labelBackground: (datum) => Boolean(datum.data?.displayLabel),
            labelBackgroundFill: 'rgba(255,255,255,0.92)',
            labelBackgroundStroke: '#ffffff',
            labelBackgroundLineWidth: 8,
            labelBackgroundRadius: 18,
            labelPadding: (datum) => (datum.data?.displayLabel ? [6, 14] : [0, 0]),
          },
          state: {
            selected: {
              halo: true,
              haloFill: 'rgba(37, 99, 235, 0.12)',
              lineWidth: 2.4,
              stroke: '#2563eb',
              labelFill: '#1f2b46',
              labelFontSize: 54,
            },
            active: {
              lineWidth: 2.2,
              stroke: '#2563eb',
              labelFill: '#1f2b46',
              labelFontSize: 54,
            },
          },
        },
        edge: {
          type: 'quadratic',
          style: {
            stroke: '#9fb2d8',
            lineWidth: 1.5,
            endArrow: false,
            labelText: (datum) => datum.data?.relation || '',
            labelFill: '#475569',
            labelFontSize: 24,
            labelFontWeight: 600,
            labelPlacement: 'center',
            labelBackground: true,
            labelBackgroundFill: 'rgba(255,255,255,0.92)',
            labelBackgroundRadius: 8,
            labelPadding: [4, 10],
          },
          state: {
            selected: {
              stroke: '#2563eb',
              lineWidth: 2.2,
            },
            active: {
              stroke: '#2563eb',
              lineWidth: 2,
            },
          },
        },
        behaviors: [
          'drag-canvas',
          {
            type: 'drag-element-force',
            trigger: [],
            enable: (event) => event.targetType === 'node',
          },
          { type: 'zoom-canvas', sensitivity: 1.14 },
          {
            type: 'click-select',
            degree: 1,
            state: 'selected',
            neighborState: 'active',
          },
          {
            type: 'collapse-expand',
            trigger: 'dblclick',
            enable: (event) => event.targetType === 'combo',
          },
        ],
      });

      g6DynamicGraph = graph;
      await graph.render();
      updateDetailFromDatum(null);

      graph.on('node:click', async (event) => {
        const id = event?.target?.id;
        if (!id || typeof graph.getNodeData !== 'function') return;
        const datum = graph.getNodeData(id)?.data || null;
        updateDetailFromDatum(datum);
        const fixedModeEnabled = typeof fixedView !== 'undefined' && fixedView === true;
        const query = datum?.pmid || datum?.rawLabel || datum?.label || id;
        const homePreviewMode = window.__TEKG_EMBED_MODE === 'home-preview';
        if (!fixedModeEnabled && !homePreviewMode && typeof window.__TEKG_LOAD_DYNAMIC_GRAPH === 'function') {
          try {
            await window.__TEKG_LOAD_DYNAMIC_GRAPH(query);
            return;
          } catch (_error) {
            await focusTarget(id);
            return;
          }
        }
        await focusTarget(id);
      });

      graph.on('edge:click', (event) => {
        const id = event?.target?.id;
        if (!id || typeof graph.getEdgeData !== 'function') return;
        const datum = graph.getEdgeData(id)?.data || null;
        updateDetailFromDatum(datum);
      });

      graph.on('combo:click', async (event) => {
        const id = event?.target?.id;
        if (!id || typeof graph.getComboData !== 'function') return;
        const datum = graph.getComboData(id)?.data || null;
        updateDetailFromDatum(datum);
        await focusTarget(id);
      });

      const targetNode = focusNode;
      if (targetNode) {
        updateDetailFromDatum(targetNode.data);
      }
    } catch (error) {
      if (detailEl) {
        detailEl.textContent = `G6 dynamic graph failed: ${error && error.message ? error.message : 'unknown error'}`;
      }
      console.error('G6 dynamic graph failed:', error);
    }
  }

  function rerenderLast(overrideOptions = {}) {
    if (!lastPayload) return;
    renderDynamicGraph(
      lastPayload.elements,
      lastPayload.focusLabel,
      lastPayload.payload,
      Object.assign({}, lastPayload.options || {}, overrideOptions || {}),
    );
  }

  function bindTriggers() {
    if (isBound) return;
    isBound = true;
  }
  bindTriggers();
  window.__TEKG_G6_TYPE_META = Object.freeze({
    order: Object.freeze([...TYPE_ORDER]),
    legendOrder: Object.freeze([...TYPE_LEGEND_ORDER]),
    labels: Object.freeze({ ...TYPE_LABEL }),
    colors: Object.freeze({ ...TYPE_COLORS }),
  });
  window.__TEKG_G6_DYNAMIC_GRAPH = {
    render: renderDynamicGraph,
    rerender: rerenderLast,
    destroy: destroyGraph,
  };
}());
