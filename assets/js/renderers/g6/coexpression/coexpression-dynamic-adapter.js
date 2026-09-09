(function (root, factory) {
  const api = factory();
  if (root) root.__TEKG_COEXPRESSION_DYNAMIC_ADAPTER = api;
  if (typeof module === 'object' && module.exports) module.exports = api;
}(typeof globalThis === 'object' ? globalThis : null, function () {
  'use strict';

  const INTERPRETATION_LIMIT = 'Correlation does not imply causation.';

  function formatMetric(value) {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric.toPrecision(3) : 'n/a';
  }

  function toGraphElements(network) {
    const nodes = Array.isArray(network && network.nodes) ? network.nodes : [];
    const edges = Array.isArray(network && network.edges) ? network.edges : [];
    const degrees = new Map(nodes.map((node) => [String(node.id), 0]));
    const center = nodes.find((node) => node.isCenter === true) || nodes[0] || null;
    const centerId = String(center?.id || '');
    const correlationToCenter = new Map();

    edges.forEach((edge) => {
      const source = String(edge.source || '');
      const target = String(edge.target || '');
      if (degrees.has(source)) degrees.set(source, degrees.get(source) + 1);
      if (degrees.has(target)) degrees.set(target, degrees.get(target) + 1);
      if (source === centerId && target) correlationToCenter.set(target, Number(edge.correlation));
      if (target === centerId && source) correlationToCenter.set(source, Number(edge.correlation));
    });

    const coexpressionModule = network && network.module && typeof network.module === 'object'
      ? { ...network.module }
      : {};
    const coexpressionSelection = network && network.selection && typeof network.selection === 'object'
      ? { ...network.selection }
      : {};
    const coexpressionInterpretation = network && network.interpretation && typeof network.interpretation === 'object'
      ? { ...network.interpretation }
      : {};

    const orderedNodes = nodes
      .map((node, index) => ({ node, index }))
      .sort((left, right) => Number(right.node.isCenter === true) - Number(left.node.isCenter === true) || left.index - right.index)
      .map(({ node }) => ({
        data: {
          ...(node.data && typeof node.data === 'object' ? node.data : {}),
          id: String(node.id),
          label: String(node.label || node.id),
          rawLabel: String(node.label || node.id),
          type: node.kind === 'gene' ? 'Gene' : 'TE',
          degree: degrees.get(String(node.id)) || 0,
          isKeyNode: node.isCenter === true || node.isModuleHub === true,
          preferProvidedDescription: true,
          description: node.isCenter === true
            ? `Selected ${node.kind === 'gene' ? 'Gene' : 'TE'} in this co-expression network.`
            : (node.isModuleHub === true ? 'Module hub in this co-expression network.' : ''),
          coexpression: true,
          coexpressionRole: node.isCenter === true ? (node.kind === 'gene' ? 'selected_gene' : 'selected_te') : (node.isModuleHub === true ? 'module_hub' : 'network_member'),
          coexpressionSourceRole: String(node.data?.role || ''),
          coexpressionFeatureType: node.kind === 'gene' ? 'Gene' : 'TE',
          coexpressionIsCenter: node.isCenter === true,
          coexpressionIsModuleHub: node.isModuleHub === true,
          expressionDisabled: node.data?.expression_disabled === true || node.data?.expressionDisabled === true,
          coexpressionModule,
          coexpressionSelection,
          coexpressionInterpretation,
          correlationToCenter: node.isCenter === true ? null : (correlationToCenter.get(String(node.id)) ?? null),
        },
      }));

    const graphEdges = edges.map((edge, index) => {
      const edgeLabel = String(edge.edgeLabel || edge.role || 'Co-expression');
      const eqtlEvidence = edge.data?.eqtl_evidence || edge.data?.eqtlEvidence || null;
      return {
      data: {
        id: String(edge.id || `${edge.source}::${edge.target}::${index}`),
        source: String(edge.source),
        target: String(edge.target),
        relation: edgeLabel,
        relationType: edgeLabel,
        relationKey: edgeLabel,
        coexpression: edgeLabel === 'Co-expression' || edgeLabel === 'Both',
        correlation: Number(edge.correlation),
        abs_correlation: Number(edge.absCorrelation ?? edge.abs_correlation ?? edge.correlation),
        fdr: Number(edge.fdr),
        pairType: String(edge.pairType || edge.pair_type || ''),
        coexpressionEdgeRole: String(edge.role || ''),
        role: String(edge.role || ''),
        eqtlEvidence,
        evidence: edgeLabel === 'eQTL' || edgeLabel === 'Both'
          ? `GTEx eQTL overlap evidence${eqtlEvidence?.supporting_variant_count != null ? `; ${eqtlEvidence.supporting_variant_count} supporting variants` : ''}. ${INTERPRETATION_LIMIT}`
          : `Spearman r = ${formatMetric(edge.correlation)}; FDR = ${formatMetric(edge.fdr)}. ${INTERPRETATION_LIMIT}`,
        pmids: [],
      },
      };
    });

    return [...orderedNodes, ...graphEdges];
  }

  return { toGraphElements, INTERPRETATION_LIMIT };
}));
