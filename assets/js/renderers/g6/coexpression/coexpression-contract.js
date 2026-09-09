(function (root, factory) {
  const api = factory();
  if (root) root.__TEKG_COEXPRESSION_CONTRACT = api;
  if (typeof module === 'object' && module.exports) module.exports = api;
}(typeof globalThis === 'object' ? globalThis : null, function () {
  'use strict';
  const MAX_NODES = 100;
  const MAX_EDGES = 150;
  const INTERNAL_KEY = /(?:path|file|directory|root|internal|edge_source)/i;
  class CoexpressionContractError extends Error {
    constructor(code, message, diagnostics) { super(message); this.name = 'CoexpressionContractError'; this.code = code; this.diagnostics = diagnostics; }
  }
  const text = (value) => typeof value === 'string' ? value.trim() : '';
  const record = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
  const numeric = (value) => typeof value === 'number' && Number.isFinite(value);
  function cleanData(value) {
    const result = {};
    if (!record(value)) return result;
    Object.keys(value).forEach((key) => { if (!INTERNAL_KEY.test(key)) result[key] = value[key]; });
    return result;
  }
  function report(nodes, edges) { return { input: { nodes, edges }, output: { nodes: 0, edges: 0 }, rejected: [] }; }
  function fail(code, message, diagnostics, rejected) {
    if (rejected) diagnostics.rejected.push(rejected);
    throw new CoexpressionContractError(code, message, diagnostics);
  }
  function success(payload, diagnostics) { if (!record(payload) || payload.ok !== true) fail('invalid_api_envelope', 'The co-expression response is not a successful API payload.', diagnostics); }
  function normalizeCatalog(payload) {
    const diagnostics = report(0, 0); success(payload, diagnostics);
    if (!Array.isArray(payload.items) || !Array.isArray(payload.contexts)) fail('invalid_catalog_structure', 'The co-expression catalog has an invalid structure.', diagnostics);
    const ids = new Set();
    const contexts = payload.contexts.map((item, index) => { const id = text(item && item.id); if (!id || ids.has(id)) fail('invalid_catalog_context', 'The co-expression catalog contains an invalid context.', diagnostics, { type: 'context', index }); ids.add(id); return { id, label: text(item.label) || id }; });
    const seen = new Set();
    const items = payload.items.map((item, index) => {
      const source = record(item) ? item : {};
      const te = text(source.te); const available = Array.isArray(source.available_contexts) ? source.available_contexts.map(text).filter((id) => ids.has(id)) : [];
      if (!te || seen.has(te.toLowerCase()) || !available.length) fail('invalid_catalog_item', 'The co-expression catalog contains an invalid TE item.', diagnostics, { type: 'item', index });
      seen.add(te.toLowerCase()); return { te, availableContexts: [...new Set(available)], bestTier: text(source.best_tier), recommendedDefault: source.recommended_default === true, data: cleanData(source) };
    });
    const geneSeen = new Set();
    const geneItems = (Array.isArray(payload.gene_items) ? payload.gene_items : []).map((item, index) => {
      const source = record(item) ? item : {};
      const gene = text(source.gene); const available = Array.isArray(source.available_contexts) ? source.available_contexts.map(text).filter((id) => ids.has(id)) : [];
      if (!gene || geneSeen.has(gene.toLowerCase()) || !available.length) fail('invalid_catalog_gene', 'The co-expression catalog contains an invalid Gene item.', diagnostics, { type: 'gene', index });
      geneSeen.add(gene.toLowerCase()); return { gene, availableContexts: [...new Set(available)], bestTier: text(source.best_tier), data: cleanData(source) };
    });
    const selection = record(payload.default_selection) ? payload.default_selection : {};
    const defaultTe = text(selection.te) || text(selection.feature); const defaultContext = text(selection.context);
    if (!items.some((item) => item.te.toLowerCase() === defaultTe.toLowerCase()) || !ids.has(defaultContext)) fail('invalid_catalog_default', 'The co-expression catalog default selection is invalid.', diagnostics);
    const features = [
      ...items.map((item) => ({ feature: item.te, featureType: 'TE', availableContexts: item.availableContexts.slice(), data: item.data })),
      ...geneItems.map((item) => ({ feature: item.gene, featureType: 'Gene', availableContexts: item.availableContexts.slice(), data: item.data })),
    ];
    diagnostics.output.nodes = features.length;
    return { version: text(payload.version), method: text(payload.method), contexts, items, geneItems, features, defaultSelection: { feature: defaultTe, featureType: 'TE', te: defaultTe, context: defaultContext }, thresholds: cleanData(payload.thresholds), interpretationLimit: text(payload.interpretation_limit), diagnostics };
  }
  function resolveFeatureSelection(catalog, requestedFeature, requestedType, requestedContext) {
    const featureType = text(requestedType).toLowerCase() === 'gene' ? 'Gene' : 'TE';
    const wanted = text(requestedFeature).toLowerCase();
    const source = featureType === 'Gene' ? catalog && catalog.geneItems : catalog && catalog.items;
    const items = Array.isArray(source) ? source : [];
    const labelKey = featureType === 'Gene' ? 'gene' : 'te';
    const typeKey = featureType.toLowerCase();
    if (!items.length) throw new CoexpressionContractError(`empty_${typeKey}_catalog`, `The co-expression catalog has no selectable ${featureType}.`, report(0, 0));
    if (!wanted) throw new CoexpressionContractError(`missing_catalog_${typeKey}`, `Select a ${featureType} before resolving a co-expression network.`, report(0, 0));
    const item = items.find((candidate) => candidate[labelKey].toLowerCase() === wanted);
    if (!item) throw new CoexpressionContractError(`unknown_catalog_${typeKey}`, `The selected ${featureType} is not available in the co-expression catalog.`, report(0, 0));
    const requested = text(requestedContext);
    const defaultContext = text(catalog && catalog.defaultSelection && catalog.defaultSelection.context);
    const context = item.availableContexts.includes(requested) ? requested : (item.availableContexts.includes(defaultContext) ? defaultContext : item.availableContexts[0]);
    return { feature: item[labelKey], featureType, context, availableContexts: item.availableContexts.slice(), fallback: context !== requested };
  }
  function resolveSelection(catalog, requestedTe, requestedContext) {
    try {
      const selection = resolveFeatureSelection(catalog, requestedTe, 'TE', requestedContext);
      return { te: selection.feature, context: selection.context, availableContexts: selection.availableContexts, fallback: selection.fallback };
    } catch (error) {
      if (error && error.code === 'empty_te_catalog') error.code = 'empty_catalog';
      throw error;
    }
  }
  function normalizeNetwork(payload) {
    const diagnostics = report(Array.isArray(payload && payload.nodes) ? payload.nodes.length : 0, Array.isArray(payload && payload.edges) ? payload.edges.length : 0); success(payload, diagnostics);
    if (!Array.isArray(payload.nodes) || !Array.isArray(payload.edges)) fail('invalid_network_structure', 'The co-expression network nodes or edges are not lists.', diagnostics);
    if (payload.nodes.length > MAX_NODES || payload.edges.length > MAX_EDGES) fail('graph_too_large', 'The co-expression network exceeds the display limit.', diagnostics);
    const ids = new Set(); const nodes = payload.nodes.map((item, index) => {
      const id = text(item && item.id); if (!id) fail('invalid_node_id', 'The co-expression network contains an empty node ID.', diagnostics, { type: 'node', index }); if (ids.has(id)) fail('duplicate_node_id', 'The co-expression network contains a duplicate node ID.', diagnostics, { type: 'node', index, id });
      const type = text(item.feature_type); if (type !== 'TE' && type !== 'gene') fail('invalid_node_type', 'The co-expression network contains an unsupported node type.', diagnostics, { type: 'node', index, id }); ids.add(id);
      return { id, label: text(item.label) || id, kind: type === 'TE' ? 'te' : 'gene', isCenter: item.is_center === true, isModuleHub: item.is_module_hub === true, data: cleanData(item) };
    });
    const edges = payload.edges.map((item, index) => {
      const source = text(item && item.source); const target = text(item && item.target); if (!source || !target || !ids.has(source) || !ids.has(target)) fail('invalid_edge_endpoint', 'The co-expression network contains an unresolved edge endpoint.', diagnostics, { type: 'edge', index, source, target });
      const correlation = item.correlation; const absCorrelation = item.abs_correlation; const fdr = item.fdr;
      const role = text(item.edge_label) || 'Co-expression';
      const nonCorrelation = role === 'eQTL';
      if (!numeric(correlation) || !numeric(absCorrelation) || !numeric(fdr) || (!nonCorrelation && (correlation <= 0 || correlation > 1)) || (nonCorrelation && (correlation < 0 || correlation > 1)) || absCorrelation < 0 || absCorrelation > 1 || fdr < 0 || fdr > 1) fail('invalid_edge_values', 'The co-expression network contains invalid correlation or FDR values.', diagnostics, { type: 'edge', index, source, target });
      return { id: `${source}::${target}::${index}`, source, target, correlation, absCorrelation, fdr, pairType: text(item.pair_type), role: text(item.role), edgeLabel: role, data: cleanData(item) };
    });
    diagnostics.output.nodes = nodes.length; diagnostics.output.edges = edges.length;
    return { version: text(payload.version), selection: cleanData(payload.selection), module: cleanData(payload.module), interpretation: cleanData(payload.interpretation), nodes, edges, diagnostics };
  }
  return { CoexpressionContractError, normalizeCatalog, normalizeNetwork, resolveSelection, resolveFeatureSelection, MAX_NODES, MAX_EDGES };
}));
