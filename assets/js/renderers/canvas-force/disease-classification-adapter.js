(function () {
  'use strict';

  const COLORS = ['#164e63', '#0e7490', '#0284c7', '#38bdf8', '#7dd3fc', '#bae6fd'];

  function build(elements, classQuery, onNodeActivate) {
    const sourceNodes = [];
    const sourceEdges = [];
    for (const item of Array.isArray(elements) ? elements : []) {
      const data = item && item.data ? item.data : null;
      if (!data) continue;
      if (data.source && data.target) sourceEdges.push(data);
      else if (data.id) sourceNodes.push(data);
    }

    const nodesById = new Map(sourceNodes.map((node) => [String(node.id), node]));
    const children = new Map();
    const indegree = new Map(sourceNodes.map((node) => [String(node.id), 0]));
    sourceEdges.forEach((edge) => {
      const source = String(edge.source || '');
      const target = String(edge.target || '');
      if (!nodesById.has(source) || !nodesById.has(target)) return;
      if (!children.has(source)) children.set(source, []);
      children.get(source).push(target);
      indegree.set(target, (indegree.get(target) || 0) + 1);
    });

    const classNeedle = String(classQuery || '').trim().toLowerCase();
    const roots = sourceNodes.filter((node) => {
      const type = String(node.type || node.nodeType || '');
      const label = String(node.rawLabel || node.label || node.id || '').toLowerCase();
      return type === 'DiseaseClass' && (!classNeedle || label.includes(classNeedle));
    });
    const root = roots[0] || sourceNodes.find((node) => String(node.type || node.nodeType || '') === 'DiseaseClass')
      || sourceNodes.find((node) => (indegree.get(String(node.id)) || 0) === 0)
      || sourceNodes[0];
    const rootId = String(root?.id || '');

    const depth = new Map();
    if (rootId) {
      depth.set(rootId, 0);
      const queue = [rootId];
      while (queue.length) {
        const parent = queue.shift();
        const nextDepth = (depth.get(parent) || 0) + 1;
        for (const child of children.get(parent) || []) {
          if (depth.has(child) && depth.get(child) <= nextDepth) continue;
          depth.set(child, nextDepth);
          queue.push(child);
        }
      }
    }
    sourceNodes.forEach((node) => {
      const id = String(node.id);
      if (!depth.has(id)) depth.set(id, String(node.type || node.nodeType || '') === 'Disease' ? 2 : 1);
    });

    const levels = new Map();
    sourceNodes.forEach((node) => {
      const level = depth.get(String(node.id)) || 0;
      if (!levels.has(level)) levels.set(level, new Set());
      levels.get(level).add(String(node.type || node.nodeType || 'Disease'));
    });
    const maxDepth = Math.max(0, ...depth.values());
    const levelLabels = Array.from({ length: maxDepth + 1 }, (_, level) => {
      const types = levels.get(level) || new Set();
      if (types.size === 1 && types.has('DiseaseClass')) return 'Disease Class';
      if (types.size === 1 && types.has('DiseaseCategory')) return 'Disease Category';
      if (types.size === 1 && types.has('Disease')) return 'Disease';
      return level === 0 ? 'Disease Class' : 'Category / Disease';
    });

    return {
      root: rootId,
      source: `disease_class:${classQuery}`,
      mode: 'disease_class_graph',
      title: `TE-Dee disease classification: ${classQuery}`,
      description: 'Static vector export of the visible disease classification graph.',
      levelLabels,
      colors: COLORS,
      onNodeActivate,
      nodes: sourceNodes.map((node) => ({
        id: String(node.id),
        name: String(node.id),
        label: String(node.rawLabel || node.label || node.id),
        depth: depth.get(String(node.id)) || 0,
        nodeType: String(node.type || node.nodeType || 'Disease'),
        queryLabel: String(node.queryLabel || node.rawLabel || node.label || node.id),
        description: String(node.description || ''),
      })),
      edges: sourceEdges
        .filter((edge) => nodesById.has(String(edge.source)) && nodesById.has(String(edge.target)))
        .map((edge) => ({ parent: String(edge.source), child: String(edge.target) })),
    };
  }

  window.__TEKG_DISEASE_CLASSIFICATION_ADAPTER = { build };
}());
