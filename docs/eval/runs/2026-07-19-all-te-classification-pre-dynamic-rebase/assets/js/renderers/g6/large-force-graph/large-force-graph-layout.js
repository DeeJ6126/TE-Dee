(function () {
  const root = window.__TEKG_LARGE_FORCE_GRAPH_LAYOUT || {};

  function createTaxonomyLargeForceLayout(options = {}) {
    const nodeById = options.nodeById instanceof Map ? options.nodeById : new Map();
    const endpointId = window.__TEKG_LARGE_FORCE_GRAPH_CONTRACT?.endpointId || ((value) => String(value || ''));
    return {
      type: 'd3-force',
      animation: true,
      iterations: 24,
      alpha: 0.2,
      alphaMin: 0.04,
      alphaDecay: 0.18,
      link: {
        distance: (edge) => {
          const source = nodeById.get(endpointId(edge?.source));
          const target = nodeById.get(endpointId(edge?.target));
          const sourceDepth = Number(source?.level ?? source?.data?.treeDepth ?? 0);
          const targetDepth = Number(target?.level ?? target?.data?.treeDepth ?? 0);
          if (sourceDepth <= 0 || targetDepth <= 1) return 76;
          if (targetDepth >= 5) return 12;
          if (targetDepth >= 4) return 18;
          return 34;
        },
        strength: (edge) => {
          const target = nodeById.get(endpointId(edge?.target));
          const depth = Number(target?.level ?? target?.data?.treeDepth ?? 0);
          if (depth >= 5) return 0.88;
          if (depth >= 4) return 0.78;
          return 0.58;
        },
      },
      manyBody: {
        theta: 1.1,
        distanceMax: 360,
        strength: (node) => {
          const depth = Number(node?.data?.treeDepth ?? node?.level ?? 0);
          const size = Number(node?.style?.size || node?.size || 16);
          if (depth <= 0) return -72;
          if (depth <= 1) return -42;
          if (depth <= 2) return -18;
          return -(1.2 + size * 0.12);
        },
      },
      x: {
        x: (node) => Number(node?.style?.clusterX || node?.style?.x || node?.x || 0),
        strength: (node) => {
          const depth = Number(node?.data?.treeDepth ?? node?.level ?? 0);
          if (depth <= 1) return 0.24;
          if (depth >= 5) return 0.12;
          return 0.16;
        },
      },
      y: {
        y: (node) => Number(node?.style?.clusterY || node?.style?.y || node?.y || 0),
        strength: (node) => {
          const depth = Number(node?.data?.treeDepth ?? node?.level ?? 0);
          if (depth <= 1) return 0.24;
          if (depth >= 5) return 0.12;
          return 0.16;
        },
      },
      collide: {
        radius: (node) => {
          const depth = Number(node?.data?.treeDepth ?? node?.level ?? 0);
          const size = Number(node?.style?.size || node?.size || 16);
          return size / 2 + (depth >= 5 ? 1 : depth >= 4 ? 2 : 6);
        },
        strength: 0.72,
        iterations: 3,
      },
    };
  }

  function createLayout(profile, options = {}) {
    if (options.performanceProfile === 'large-static') return { type: 'preset' };
    if (profile === 'taxonomy-force') return createTaxonomyLargeForceLayout(options);
    return { type: 'preset' };
  }

  function createTransientDragLayout(options = {}) {
    const transient = createTaxonomyLargeForceLayout(options);
    const activeSystemId = String(options.activeSystemId || '');
    const nodeSystemId = (node) => {
      const depth = Number(node?.data?.treeDepth ?? node?.level ?? node?.payload?.treeDepth ?? 0);
      if (depth === 3) return String(node?.id || '');
      return String(node?.data?.superfamilyId || node?.superfamilyId || node?.payload?.superfamilyId || '');
    };
    const isActiveSystem = (node) => !activeSystemId || nodeSystemId(node) === activeSystemId;
    return {
      ...transient,
      animation: true,
      iterations: 3,
      alpha: 0.16,
      alphaMin: 0.025,
      alphaDecay: 0.5,
      alphaTarget: 0,
      manyBody: {
        ...transient.manyBody,
        theta: 1.1,
        distanceMax: 320,
        strength: (node) => {
          const depth = Number(node?.data?.treeDepth ?? node?.level ?? 0);
          const size = Number(node?.style?.size || node?.size || 16);
          const active = isActiveSystem(node);
          if (depth <= 0) return active ? -54 : -18;
          if (depth === 1) return active ? -42 : -14;
          if (depth === 2) return active ? -32 : -10;
          if (depth === 3) {
            const systemRadius = Math.max(0, Number(node?.data?.systemRadius) || 0);
            const force = 26 + Math.min(12, systemRadius * 0.06);
            return active ? -force : -Math.max(8, force * 0.35);
          }
          return active ? -(0.8 + size * 0.08) : -0.18;
        },
      },
      x: {
        ...transient.x,
        strength: (node) => {
          const depth = Number(node?.data?.treeDepth ?? node?.level ?? 0);
          if (activeSystemId && !isActiveSystem(node)) return 0.52;
          if (depth <= 1) return 0.36;
          if (depth === 2) return 0.3;
          if (depth === 3) return 0.26;
          return depth >= 5 ? 0.1 : 0.12;
        },
      },
      y: {
        ...transient.y,
        strength: (node) => {
          const depth = Number(node?.data?.treeDepth ?? node?.level ?? 0);
          if (activeSystemId && !isActiveSystem(node)) return 0.52;
          if (depth <= 1) return 0.36;
          if (depth === 2) return 0.3;
          if (depth === 3) return 0.26;
          return depth >= 5 ? 0.1 : 0.12;
        },
      },
      collide: {
        ...transient.collide,
        strength: 0.66,
        iterations: 1,
      },
      onTick: typeof options.onTick === 'function' ? options.onTick : undefined,
    };
  }

  root.createLayout = createLayout;
  root.createTransientDragLayout = createTransientDragLayout;
  root.createTaxonomyLargeForceLayout = createTaxonomyLargeForceLayout;
  root.createTaxonomyLargeLayout = createTaxonomyLargeForceLayout;
  window.__TEKG_LARGE_FORCE_GRAPH_LAYOUT = root;
}());
