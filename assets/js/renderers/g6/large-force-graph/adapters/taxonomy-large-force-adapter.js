(function () {
  const contract = window.__TEKG_LARGE_FORCE_GRAPH_CONTRACT;
  if (!contract) return;

  function seededUnit(seed) {
    let hash = 2166136261;
    const text = String(seed || '');
    for (let index = 0; index < text.length; index += 1) {
      hash ^= text.charCodeAt(index);
      hash = Math.imul(hash, 16777619);
    }
    return ((hash >>> 0) % 10000) / 10000;
  }

  const BRANCH_PALETTE = [
    '#2563eb', '#dc2626', '#059669', '#7c3aed', '#d97706', '#0891b2',
    '#be185d', '#4d7c0f', '#9333ea', '#0f766e', '#c2410c', '#0369a1',
  ];
  const ROOT_BRANCH_COLOR = '#172554';
  const DEPTH_SWATCHES = ['#334155', '#475569', '#64748b', '#94a3b8', '#a8b1bf', '#c0c7d1'];

  function stableHash(value) {
    let hash = 2166136261;
    const text = String(value || '');
    for (let index = 0; index < text.length; index += 1) {
      hash ^= text.charCodeAt(index);
      hash = Math.imul(hash, 16777619);
    }
    return hash >>> 0;
  }

  function branchVisual(branchId, isRoot = false, paletteIndex = null) {
    if (isRoot) return { color: ROOT_BRANCH_COLOR, hueToken: 'taxonomy-root' };
    const resolvedIndex = Number.isInteger(paletteIndex)
      ? paletteIndex % BRANCH_PALETTE.length
      : stableHash(branchId) % BRANCH_PALETTE.length;
    return {
      color: BRANCH_PALETTE[resolvedIndex],
      hueToken: `taxonomy-branch-${resolvedIndex}`,
    };
  }

  function levelKey(depth) {
    return `level-${Math.max(0, Number(depth) || 0)}`;
  }

  function levelLabel(depth) {
    const safeDepth = Math.max(0, Number(depth) || 0);
    const labels = ['Human TE', 'Class', 'Order', 'Superfamily', 'Family', 'Subfamily'];
    return labels[safeDepth] || `Level ${safeDepth}`;
  }

  function nodeColor(depth, maxDepth) {
    const safeDepth = Math.max(0, Number(depth) || 0);
    return DEPTH_SWATCHES[Math.min(safeDepth, DEPTH_SWATCHES.length - 1)];
  }

  function nodeSize(depth, siblingCount = 0, childCount = 0) {
    const safeDepth = Math.max(0, Number(depth) || 0);
    const base = [68, 46, 34, 24, 15, 9][safeDepth] || 7;
    const siblingPenalty = siblingCount > 160 ? 4 : siblingCount > 90 ? 3 : siblingCount > 48 ? 2 : 0;
    const childBonus = childCount > 20 ? 3 : childCount > 8 ? 1 : 0;
    return Math.max(6, base - siblingPenalty + childBonus);
  }

  function isMapLike(value) {
    return !!value
      && typeof value.get === 'function'
      && typeof value.set === 'function'
      && typeof value.values === 'function'
      && typeof value.entries === 'function';
  }

  function buildTree(source) {
    const nodes = isMapLike(source?.nodes) ? source.nodes : new Map();
    const children = isMapLike(source?.children) ? source.children : new Map();
    const rootId = String(source?.rootId || [...nodes.values()].find((node) => Number(node.treeDepth || 0) === 0)?.id || '');
    function makeNode(id, parent = null) {
      const node = nodes.get(id);
      if (!node) return null;
      const childIds = children.get(id) || [];
      const treeNode = {
        ...node,
        id,
        parent,
        children: [],
      };
      treeNode.children = childIds.map((childId) => makeNode(childId, treeNode)).filter(Boolean);
      return treeNode;
    }
    return rootId ? makeNode(rootId) : null;
  }

  function walk(node, visitor) {
    if (!node || typeof visitor !== 'function') return;
    visitor(node);
    for (const child of Array.isArray(node.children) ? node.children : []) walk(child, visitor);
  }

  const GOLDEN_ANGLE = Math.PI * (3 - Math.sqrt(5));

  function starTier(depth) {
    return ['root', 'class', 'order', 'superfamily'][Math.max(0, Number(depth) || 0)] || 'member';
  }

  function packDiscs(discs, centerClearance = 0, gap = 18) {
    const ordered = discs.slice().sort((a, b) => b.radius - a.radius || String(a.id).localeCompare(String(b.id)));
    if (!ordered.length) return { items: [], radius: centerClearance + gap };
    const maxRadius = ordered[0].radius;
    const cellSize = Math.max(24, maxRadius * 2 + gap);
    const buckets = new Map();
    const placed = [];
    let enclosingRadius = centerClearance;
    const bucketKey = (x, y) => `${Math.floor(x / cellSize)},${Math.floor(y / cellSize)}`;
    const addToBucket = (item) => {
      const key = bucketKey(item.x, item.y);
      if (!buckets.has(key)) buckets.set(key, []);
      buckets.get(key).push(item);
    };
    const isClear = (x, y, disc) => {
      if (Math.hypot(x, y) < centerClearance + disc.radius + gap) return false;
      const cellX = Math.floor(x / cellSize);
      const cellY = Math.floor(y / cellSize);
      for (let dx = -1; dx <= 1; dx += 1) {
        for (let dy = -1; dy <= 1; dy += 1) {
          for (const other of buckets.get(`${cellX + dx},${cellY + dy}`) || []) {
            if (Math.hypot(x - other.x, y - other.y) < disc.radius + other.radius + gap - 0.001) return false;
          }
        }
      }
      return true;
    };
    for (const disc of ordered) {
      const phase = seededUnit(`${disc.id}:pack`) * Math.PI * 2;
      let best = null;
      let bestExtent = Infinity;
      const candidateLimit = 768;
      for (let attempt = 0; attempt < candidateLimit; attempt += 1) {
        const base = placed.length ? placed[attempt % placed.length] : null;
        const ray = placed.length ? Math.floor(attempt / placed.length) : attempt;
        const angle = phase + GOLDEN_ANGLE * ray;
        const distance = base
          ? base.radius + disc.radius + gap
          : centerClearance + disc.radius + gap;
        const x = (base?.x || 0) + Math.cos(angle) * distance;
        const y = (base?.y || 0) + Math.sin(angle) * distance;
        if (!isClear(x, y, disc)) continue;
        const extent = Math.hypot(x, y) + disc.radius;
        if (extent < bestExtent) {
          best = { x, y };
          bestExtent = extent;
        }
      }
      // This radial fallback is collision-valid because enclosingRadius bounds
      // every previously placed disc, including its own radius.
      if (!best) best = { x: enclosingRadius + disc.radius + gap, y: 0 };
      const item = { ...disc, x: best.x, y: best.y };
      placed.push(item);
      addToBucket(item);
      enclosingRadius = Math.max(enclosingRadius, Math.hypot(item.x, item.y) + item.radius);
    }
    return {
      items: placed,
      radius: Math.max(
        centerClearance,
        placed.reduce((max, item) => Math.max(max, Math.hypot(item.x, item.y) + item.radius), 0),
      ) + gap,
    };
  }

  function memberOffsets(members, visibleTaxonomyLevels = {}) {
    const memberById = new Map(members.map((member) => [member.id, member]));
    const isVisible = (depth) => {
      const configured = visibleTaxonomyLevels[levelKey(depth)];
      // Phase 9 first paint stops at Family. Subfamily and deeper members keep
      // deterministic coordinates for on-demand expansion, but never enlarge
      // the initial Superfamily system or its ancestor packing regions.
      return depth === 4 && configured !== false;
    };
    const visible = members.filter((member) => isVisible(member.depth))
      .sort((a, b) => a.depth - b.depth || String(a.id).localeCompare(String(b.id)));
    const hidden = members.filter((member) => !isVisible(member.depth))
      .sort((a, b) => a.depth - b.depth || String(a.id).localeCompare(String(b.id)));
    const offsets = new Map();
    let extent = 0;
    visible.forEach((member, index) => {
      const phase = seededUnit(`${member.id}:member`) * 0.12;
      const radius = 18 + 5.8 * Math.sqrt(index + 1);
      const angle = GOLDEN_ANGLE * index + phase;
      const x = Math.cos(angle) * radius;
      const y = Math.sin(angle) * radius;
      offsets.set(member.id, { x, y });
      extent = Math.max(extent, radius + member.size / 2);
    });
    const systemRadius = Math.max(30, extent + 9);
    const hiddenLimit = Math.max(16, systemRadius - 10);
    const hiddenChildrenByParent = new Map();
    for (const member of hidden) {
      if (!hiddenChildrenByParent.has(member.parentId)) hiddenChildrenByParent.set(member.parentId, []);
      hiddenChildrenByParent.get(member.parentId).push(member);
    }
    for (const siblings of hiddenChildrenByParent.values()) {
      siblings.sort((a, b) => String(a.id).localeCompare(String(b.id)));
    }
    hidden.forEach((member, index) => {
      const parentOffset = offsets.get(member.parentId);
      if (parentOffset) {
        const siblings = hiddenChildrenByParent.get(member.parentId) || [member];
        const siblingIndex = Math.max(0, siblings.findIndex((item) => item.id === member.id));
        const parentSize = memberById.get(member.parentId)?.size || member.size;
        const localRadius = parentSize / 2 + member.size / 2 + 7 + 5 * Math.sqrt(siblingIndex + 1);
        const angle = GOLDEN_ANGLE * siblingIndex + seededUnit(`${member.id}:child`) * 0.12;
        offsets.set(member.id, {
          x: parentOffset.x + Math.cos(angle) * localRadius,
          y: parentOffset.y + Math.sin(angle) * localRadius,
        });
        return;
      }
      const fraction = Math.sqrt((index + 1) / Math.max(1, hidden.length));
      const radius = 8 + (hiddenLimit - 8) * fraction;
      const angle = GOLDEN_ANGLE * index + seededUnit(`${member.id}:hidden`) * 0.12;
      offsets.set(member.id, { x: Math.cos(angle) * radius, y: Math.sin(angle) * radius });
    });
    return { offsets, radius: systemRadius };
  }

  function fromTaxonomySource(source, options = {}) {
    const rootTree = buildTree(source);
    if (!rootTree) {
      return contract.normalizeGraphData({
        graphId: `taxonomy:${options.treeVariant || 'unknown'}`,
        meta: { source: 'taxonomy', truthSource: 'api/taxonomy.php' },
        nodes: [],
        edges: [],
      });
    }

    const width = Number(options.width) || 800;
    const height = Number(options.height) || 520;
    const treeVariant = String(options.treeVariant || 'rmsk_repbase');
    const nodeEntries = [];
    const nodeEntryById = new Map();
    const parentById = new Map();
    const taxonomyById = new Map();
    const descendantCountById = new Map();
    function indexTree(node, depth = 0, ancestry = {}) {
      const classId = depth === 1 ? node.id : String(ancestry.classId || '');
      const orderId = depth === 2 ? node.id : String(ancestry.orderId || '');
      const superfamilyId = depth === 3 ? node.id : String(ancestry.superfamilyId || '');
      const taxonomy = { depth, classId, orderId, superfamilyId, starTier: starTier(depth) };
      nodeEntries.push(node);
      nodeEntryById.set(node.id, node);
      taxonomyById.set(node.id, taxonomy);
      for (const child of Array.isArray(node.children) ? node.children : []) {
        parentById.set(child.id, node.id);
        indexTree(child, depth + 1, taxonomy);
      }
    }
    indexTree(rootTree);
    const maxDepth = nodeEntries.reduce((max, node) => Math.max(max, taxonomyById.get(node.id)?.depth || 0), 0);
    const centerX = Math.max(260, width * 0.42);
    const centerY = Math.max(160, height / 2);
    const firstLevelChildren = Array.isArray(rootTree.children) ? rootTree.children : [];
    const branchPaletteIndexById = new Map(
      firstLevelChildren.map((child) => String(child.id)).sort().map((id, index) => [id, index]),
    );
    const branchById = new Map([[rootTree.id, rootTree.id]]);

    function countDescendants(node) {
      const children = Array.isArray(node.children) ? node.children : [];
      const count = children.reduce((sum, child) => sum + 1 + countDescendants(child), 0);
      descendantCountById.set(node.id, count);
      return count;
    }
    countDescendants(rootTree);

    function assignBranch(node, branchId) {
      const depth = taxonomyById.get(node.id)?.depth || 0;
      const nextBranch = depth === 1 ? node.id : branchId;
      branchById.set(node.id, nextBranch || rootTree.id);
      for (const child of Array.isArray(node.children) ? node.children : []) assignBranch(child, nextBranch);
    }
    assignBranch(rootTree, rootTree.id);

    const sizingById = new Map();
    for (const node of nodeEntries) {
      const depth = taxonomyById.get(node.id)?.depth || 0;
      const childCount = Array.isArray(node.children) ? node.children.length : 0;
      const parentId = parentById.get(node.id) || '';
      const siblingIds = parentId && isMapLike(source.children) ? source.children.get(parentId) : null;
      const siblingCount = Array.isArray(siblingIds) ? siblingIds.length : firstLevelChildren.length || 1;
      sizingById.set(node.id, {
        childCount,
        siblingCount,
        size: nodeSize(depth, siblingCount, childCount),
      });
    }

    const membersBySuperfamily = new Map();
    for (const node of nodeEntries) {
      const taxonomy = taxonomyById.get(node.id);
      if (taxonomy.depth < 4 || !taxonomy.superfamilyId) continue;
      if (!membersBySuperfamily.has(taxonomy.superfamilyId)) membersBySuperfamily.set(taxonomy.superfamilyId, []);
      membersBySuperfamily.get(taxonomy.superfamilyId).push({
        id: node.id,
        parentId: parentById.get(node.id) || '',
        depth: taxonomy.depth,
        size: sizingById.get(node.id).size,
      });
    }

    const superfamilySystems = new Map();
    for (const node of nodeEntries) {
      const taxonomy = taxonomyById.get(node.id);
      if (taxonomy.starTier !== 'superfamily') continue;
      const memberLayout = memberOffsets(membersBySuperfamily.get(node.id) || [], options.visibleTaxonomyLevels || {});
      superfamilySystems.set(node.id, {
        id: node.id,
        orderId: taxonomy.orderId,
        radius: memberLayout.radius,
        memberOffsets: memberLayout.offsets,
      });
    }

    const orderRegions = new Map();
    for (const node of nodeEntries) {
      const taxonomy = taxonomyById.get(node.id);
      if (taxonomy.starTier !== 'order') continue;
      const systems = [...superfamilySystems.values()].filter((system) => system.orderId === node.id);
      const packed = packDiscs(systems, sizingById.get(node.id).size / 2 + 22, 16);
      orderRegions.set(node.id, {
        id: node.id,
        classId: taxonomy.classId,
        radius: Math.max(48, packed.radius + 10),
        systems: new Map(packed.items.map((item) => [item.id, item])),
      });
    }

    const classRegions = new Map();
    for (const node of nodeEntries) {
      const taxonomy = taxonomyById.get(node.id);
      if (taxonomy.starTier !== 'class') continue;
      const orders = [...orderRegions.values()].filter((region) => region.classId === node.id);
      const packed = packDiscs(orders, sizingById.get(node.id).size / 2 + 28, 24);
      classRegions.set(node.id, {
        id: node.id,
        radius: Math.max(72, packed.radius + 14),
        orders: new Map(packed.items.map((item) => [item.id, item])),
      });
    }

    const classPacking = packDiscs([...classRegions.values()], sizingById.get(rootTree.id).size / 2 + 52, 36);
    const packedClassById = new Map(classPacking.items.map((item) => [item.id, item]));
    const positionById = new Map([[rootTree.id, { x: centerX, y: centerY }]]);
    for (const classRegion of classRegions.values()) {
      const packedClass = packedClassById.get(classRegion.id);
      const classPosition = { x: centerX + packedClass.x, y: centerY + packedClass.y };
      positionById.set(classRegion.id, classPosition);
      for (const orderPlacement of classRegion.orders.values()) {
        const orderPosition = { x: classPosition.x + orderPlacement.x, y: classPosition.y + orderPlacement.y };
        positionById.set(orderPlacement.id, orderPosition);
        const orderRegion = orderRegions.get(orderPlacement.id);
        for (const systemPlacement of orderRegion.systems.values()) {
          const systemPosition = { x: orderPosition.x + systemPlacement.x, y: orderPosition.y + systemPlacement.y };
          positionById.set(systemPlacement.id, systemPosition);
          const system = superfamilySystems.get(systemPlacement.id);
          for (const [memberId, offset] of system.memberOffsets.entries()) {
            positionById.set(memberId, { x: systemPosition.x + offset.x, y: systemPosition.y + offset.y });
          }
        }
      }
    }

    const labeledSuperfamilies = new Set();
    for (const orderRegion of orderRegions.values()) {
      const candidates = [...orderRegion.systems.values()].sort((a, b) => b.radius - a.radius || String(a.id).localeCompare(String(b.id)));
      if (candidates[0]) labeledSuperfamilies.add(candidates[0].id);
      for (const candidate of candidates) {
        if (candidate.radius >= 105) labeledSuperfamilies.add(candidate.id);
      }
    }

    const nodes = [];
    walk(rootTree, (node) => {
      const taxonomy = taxonomyById.get(node.id);
      const depth = taxonomy.depth;
      const { childCount, siblingCount } = sizingById.get(node.id);
      const parentId = parentById.get(node.id) || '';
      const fallback = positionById.get(parentId) || { x: centerX, y: centerY };
      const position = positionById.get(node.id) || fallback;
      const x = position.x;
      const y = position.y;

      const rawLabel = String(node.label || node.id || '').trim();
      const displayLabel = depth === 0 ? 'Human TE' : String(options.getDisplayLabel ? options.getDisplayLabel(rawLabel, node.description, depth) : rawLabel);
      const treeIsMeta = node.treeIsMeta === true;
      const jumpable = !!String(node.queryLabel || '').trim() && !treeIsMeta && depth > 0;
      const key = levelKey(depth);
      const isRoot = depth === 0;
      const visualBranchId = branchById.get(node.id) || rootTree.id;
      const visual = branchVisual(visualBranchId, isRoot, branchPaletteIndexById.get(visualBranchId));
      const strokeWidth = [4, 3.2, 2.6, 2, 1.5, 1.15][depth] || 1;
      const systemRadius = taxonomy.starTier === 'superfamily' ? superfamilySystems.get(node.id)?.radius : undefined;
      const clusterPosition = taxonomy.starTier === 'member'
        ? position
        : position;
      const ancestryIds = [rootTree.id, taxonomy.classId, taxonomy.orderId, taxonomy.superfamilyId, depth >= 4 ? node.id : '']
        .filter(Boolean);
      const ancestryLabels = ancestryIds.map((id) => {
        const ancestor = nodeEntryById.get(id);
        return id === rootTree.id ? 'Human TE' : String(ancestor?.label || id);
      });
      const directChildIds = (Array.isArray(node.children) ? node.children : [])
        .filter((child) => (taxonomyById.get(child.id)?.depth || 0) === depth + 1)
        .map((child) => child.id);
      const degree = childCount + (parentId ? 1 : 0);
      nodes.push({
        id: node.id,
        label: displayLabel,
        rawLabel,
        displayLabel,
        description: String(node.description || ''),
        type: 'TE',
        level: depth,
        x,
        y,
        clusterX: clusterPosition.x,
        clusterY: clusterPosition.y,
        size: nodeSize(depth, siblingCount, childCount),
        color: visual.color,
        branchColor: visual.color,
        branchHueToken: visual.hueToken,
        branchId: visualBranchId,
        clusterId: visualBranchId,
        starTier: taxonomy.starTier,
        classId: taxonomy.classId,
        orderId: taxonomy.orderId,
        superfamilyId: taxonomy.superfamilyId,
        ...(Number.isFinite(systemRadius) ? { systemRadius } : {}),
        stroke: depth <= 2 ? '#1e293b' : visual.color,
        strokeWidth,
        lineDash: depth === 0 || jumpable ? [] : [5, 4],
        legendKeys: [key],
        pinnedLabel: depth <= 2,
        starLabel: depth === 3 && labeledSuperfamilies.has(node.id),
        parentId,
        ancestryIds,
        ancestryLabels,
        directChildIds,
        degree,
        payload: {
          treeDepth: depth,
          queryLabel: node.queryLabel || rawLabel,
          taxonomyOnly: !jumpable,
          treeVariant,
          hasGraphEntity: jumpable,
          isRoot: depth === 0,
          taxonomyLevelKey: key,
          taxonomyLevelLabel: levelLabel(depth),
          directChildCount: childCount,
          descendantCount: descendantCountById.get(node.id) || 0,
          siblingCount,
          parentId,
          ancestryIds,
          ancestryLabels,
          directChildIds,
          degree,
          starLabel: depth === 3 && labeledSuperfamilies.has(node.id),
          description: String(node.description || ''),
          starTier: taxonomy.starTier,
          classId: taxonomy.classId,
          orderId: taxonomy.orderId,
          superfamilyId: taxonomy.superfamilyId,
          ...(Number.isFinite(systemRadius) ? { systemRadius } : {}),
          branchId: visualBranchId,
          clusterId: visualBranchId,
          branchColor: visual.color,
          branchHueToken: visual.hueToken,
          strokeWidth,
        },
      });
    });

    const edges = [];
    if (isMapLike(source.children)) {
      for (const [parentId, childIds] of source.children.entries()) {
        for (const childId of childIds) {
          edges.push({
            id: `${parentId}__taxonomy__${childId}`,
            source: parentId,
            target: childId,
            kind: 'taxonomy-parent',
            direction: 'directed',
            label: '',
            curve: 'line',
            payload: { relation: 'taxonomy parent' },
          });
        }
      }
    }

    const counts = new Map();
    for (const node of nodes) counts.set(node.legendKeys[0], (counts.get(node.legendKeys[0]) || 0) + 1);
    const legendItems = Array.from({ length: maxDepth + 1 }, (_, depth) => {
      const key = levelKey(depth);
      return {
        key,
        depth,
        kind: 'taxonomy-level',
        label: levelLabel(depth),
        count: counts.get(key) || 0,
        color: nodeColor(depth, maxDepth),
        swatchRole: 'taxonomy-depth',
        visible: typeof options.visibleTaxonomyLevels?.[key] === 'boolean' ? options.visibleTaxonomyLevels[key] : depth < 5,
        focusable: true,
      };
    }).filter((item) => item.count > 0 && item.depth <= 4);
    const legendState = contract.defaultLegendState(legendItems, options.visibleTaxonomyLevels || {});
    const normalized = contract.normalizeGraphData({
      graphId: `taxonomy:${treeVariant}`,
      version: 1,
      meta: {
        source: 'taxonomy',
        truthSource: 'api/taxonomy.php',
        label: 'TE taxonomy graph',
        treeVariant,
        evidenceBoundary: '',
      },
      rootId: rootTree.id,
      maxDepth,
      nodes,
      edges,
      legend: { items: legendItems, state: legendState },
      options: {
        layoutProfile: 'taxonomy-force',
        performanceProfile: 'bounded-dynamic',
      },
    });
    normalized.originalNodeCount = nodes.length;
    normalized.originalEdgeCount = edges.length;
    return normalized;
  }

  window.__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER = {
    fromTaxonomySource,
    levelKey,
    levelLabel,
    nodeColor,
  };
}());
