(function (root, factory) {
  const api = factory();
  if (root) root.__TEKG_INTEGRATED_CONTRACT = api;
  if (typeof module === 'object' && module.exports) module.exports = api;
}(typeof globalThis === 'object' ? globalThis : null, function () {
  'use strict';
  function text(value) { return String(value == null ? '' : value); }
  function canonicalText(value) { return text(value).normalize('NFKC').trim().toLocaleLowerCase('en-US'); }
  function canonicalEntityKey(input) {
    input = input || {};
    const type = canonicalText(input.type);
    if (type !== 'te' && type !== 'gene') return '';
    const taxonomy = input.taxonomy && typeof input.taxonomy === 'object' ? input.taxonomy : {};
    const label = type === 'te' && taxonomy.canonical_name ? taxonomy.canonical_name : (input.rawLabel || input.label);
    const canonical = canonicalText(label);
    return canonical ? (type === 'te' ? 'TE:' : 'Gene:') + canonical : '';
  }
  function encoded(value) { return encodeURIComponent(text(value)); }
  function integratedNodeId(source, data) {
    const key = canonicalEntityKey(data);
    if (key) {
      const separator = key.indexOf(':');
      return 'integrated:' + key.slice(0, separator) + ':' + encoded(key.slice(separator + 1));
    }
    return source === 'knowledge' ? 'kg:' + encoded(data.id) : 'te-gene:' + encoded(data.id);
  }
  function cloneData(data) { return data && typeof data === 'object' ? { ...data } : {}; }
  function isEdge(data) { return !!text(data && data.source) && !!text(data && data.target); }
  function mergeSourceElements(input) {
    input = input || {};
    const knowledgeElements = Array.isArray(input.knowledgeElements) ? input.knowledgeElements : [];
    const teGeneElements = Array.isArray(input.teGeneElements) ? input.teGeneElements : [];
    const nodes = new Map();
    const sourceMaps = { knowledge: new Map(), te_gene: new Map() };
    const edges = [];
    function addNode(source, rawElement) {
      const data = cloneData(rawElement && rawElement.data);
      if (!text(data.id) || isEdge(data)) return;
      const id = integratedNodeId(source, data);
      const sourceKey = source === 'knowledge' ? 'knowledge' : 'teGene';
      sourceMaps[source].set(text(data.id), id);
      const existing = nodes.get(id);
      if (existing) {
        if (!existing.data.integrated.sources.includes(source)) existing.data.integrated.sources.push(source);
        existing.data.integrated.sourceIds[sourceKey] = text(data.id);
        existing.data.integrated[sourceKey] = data;
        if (source === 'te_gene') Object.entries(data).forEach(function (entry) {
          if (!['id', 'label', 'rawLabel', 'type'].includes(entry[0])) existing.data[entry[0]] = entry[1];
        });
        return;
      }
      nodes.set(id, { data: { ...data, id: id, originalId: text(data.id), sourceGraph: source, integrated: {
        sources: [source], sourceIds: { [sourceKey]: text(data.id) }, [sourceKey]: data,
      } } });
    }
    function addEdge(source, rawElement) {
      const data = cloneData(rawElement && rawElement.data);
      if (!isEdge(data)) return;
      const sourceId = sourceMaps[source].get(text(data.source));
      const targetId = sourceMaps[source].get(text(data.target));
      if (!sourceId || !targetId) return;
      const prefix = source === 'knowledge' ? 'kg-edge:' : 'te-gene-edge:';
      const originalId = text(data.id || (data.source + '::' + data.target + '::' + edges.length));
      edges.push({ data: { ...data, id: prefix + encoded(originalId), originalId: originalId,
        source: sourceId, target: targetId, sourceGraph: source,
      } });
    }
    knowledgeElements.forEach(function (element) { addNode('knowledge', element); });
    teGeneElements.forEach(function (element) { addNode('te_gene', element); });
    knowledgeElements.forEach(function (element) { addEdge('knowledge', element); });
    teGeneElements.forEach(function (element) { addEdge('te_gene', element); });
    return assignParallelEdgeMetadata(Array.from(nodes.values()).concat(edges));
  }
  function assignParallelEdgeMetadata(elements) {
    const output = (Array.isArray(elements) ? elements : []).map(function (element) {
      return { ...element, data: cloneData(element && element.data) };
    });
    const groups = new Map();
    output.forEach(function (element) {
      const data = element.data;
      if (!isEdge(data)) return;
      const source = text(data.source); const target = text(data.target);
      const key = source === target ? 'loop:' + source + ':' + text(data.id) : [source, target].sort().join('\u0000');
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(element);
    });
    groups.forEach(function (group) {
      group.forEach(function (element, index) {
        element.data.parallelIndex = index; element.data.parallelCount = group.length;
      });
    });
    return output;
  }
  return { canonicalEntityKey, integratedNodeId, mergeSourceElements, assignParallelEdgeMetadata };
}));
