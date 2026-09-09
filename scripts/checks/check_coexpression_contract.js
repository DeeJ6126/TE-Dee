'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const contractPath = path.resolve(__dirname, '../../assets/js/renderers/g6/coexpression/coexpression-contract.js');
const Contract = require(contractPath);

function expectContractError(callback, code) {
  assert.throws(callback, (error) => {
    assert.strictEqual(error.name, 'CoexpressionContractError');
    assert.strictEqual(error.code, code);
    assert.ok(error.diagnostics && Array.isArray(error.diagnostics.rejected));
    return true;
  });
}

function catalogPayload() {
  return {
    ok: true,
    version: 'v1_abs0.4_fdr0.05_res1.8',
    method: 'spearman',
    default_selection: { te: 'L1HS', context: 'cancer_cell_line' },
    contexts: [
      { id: 'cancer_cell_line', label: 'Cancer cell line' },
      { id: 'normal_cell_line', label: 'Normal cell line' },
    ],
    thresholds: { min_abs_correlation: 0.4, max_fdr: 0.05 },
    interpretation_limit: 'Co-expression is correlation, not causation.',
    internal_path: 'D:/never/expose',
    items: [
      { te: 'L1HS', available_contexts: ['cancer_cell_line', 'normal_cell_line'], best_tier: 'core_case', recommended_default: true },
      { te: 'CR1', available_contexts: ['normal_cell_line'], best_tier: 'searchable_all', recommended_default: false },
    ],
    gene_items: [
      { gene: 'C1orf116', available_contexts: ['cancer_cell_line'], best_tier: 'high_confidence' },
      { gene: 'CLDN4', available_contexts: ['cancer_cell_line', 'normal_tissue'], best_tier: 'high_confidence' },
    ],
  };
}

function networkPayload() {
  return {
    ok: true,
    version: 'v1_abs0.4_fdr0.05_res1.8',
    selection: { te: 'L1HS', context: 'cancer_cell_line', available_contexts: ['cancer_cell_line'], display_tier: 'core_case', recommended_default: true, json_path: 'D:/never/expose' },
    module: { id: 'cancer_cell_line_M002', type: 'gene-rich', size: 428, te_count: 3, gene_count: 425, confidence: 'high', top_enriched_terms: ['term'] },
    interpretation: { statement_en: 'Association only.', statement_zh: '', limit: 'Correlation, not causation.' },
    nodes: [
      { id: 'L1HS', label: 'L1HS', feature_type: 'TE', role: 'center', is_center: true, is_module_hub: false, degree_hint: 2, source_path: 'D:/never/expose' },
      { id: 'GENE1', label: 'GENE1', feature_type: 'gene', role: 'partner', is_center: false, is_module_hub: true },
    ],
    edges: [
      { source: 'L1HS', target: 'GENE1', correlation: 0.82, abs_correlation: 0.82, fdr: 0.01, pair_type: 'TE-gene', role: 'center_connection', edge_source: 'private.tsv' },
    ],
  };
}

function run(name, callback) {
  callback();
  process.stdout.write(`PASS: ${name}\n`);
}

run('catalog normalization, case-insensitive selection, and strict TE matching', () => {
  const catalog = Contract.normalizeCatalog(catalogPayload());
  assert.strictEqual(catalog.items.length, 2);
  assert.strictEqual(catalog.geneItems.length, 2);
  assert.strictEqual(catalog.features.length, 4);
  assert.strictEqual(catalog.items[0].te, 'L1HS');
  assert.strictEqual(catalog.items[0].data.internal_path, undefined);
  assert.deepStrictEqual(Contract.resolveSelection(catalog, 'l1hs', 'normal_cell_line'), {
    te: 'L1HS', context: 'normal_cell_line', availableContexts: ['cancer_cell_line', 'normal_cell_line'], fallback: false,
  });
  assert.deepStrictEqual(Contract.resolveSelection(catalog, 'cr1', 'cancer_cell_line'), {
    te: 'CR1', context: 'normal_cell_line', availableContexts: ['normal_cell_line'], fallback: true,
  });
  assert.throws(
    () => Contract.resolveSelection(catalog, 'missing', 'normal_cell_line'),
    (error) => error && error.code === 'unknown_catalog_te',
  );
  assert.throws(
    () => Contract.resolveSelection(catalog, '', 'normal_cell_line'),
    (error) => error && error.code === 'missing_catalog_te',
  );
  assert.deepStrictEqual(Contract.resolveFeatureSelection(catalog, 'c1ORF116', 'Gene', 'cancer_cell_line'), {
    feature: 'C1orf116', featureType: 'Gene', context: 'cancer_cell_line', availableContexts: ['cancer_cell_line'], fallback: false,
  });
  assert.throws(
    () => Contract.resolveFeatureSelection(catalog, 'missing', 'Gene', 'normal_tissue'),
    (error) => error && error.code === 'unknown_catalog_gene',
  );
});

run('network normalization creates stable graph records and strips internal fields', () => {
  const graph = Contract.normalizeNetwork(networkPayload());
  assert.strictEqual(graph.nodes.length, 2);
  assert.strictEqual(graph.edges.length, 1);
  assert.strictEqual(graph.edges[0].id, 'L1HS::GENE1::0');
  assert.strictEqual(graph.nodes[0].id, 'L1HS');
  assert.strictEqual(graph.nodes[0].data.source_path, undefined);
  assert.strictEqual(graph.edges[0].data.edge_source, undefined);
  assert.deepStrictEqual(graph.diagnostics.input, { nodes: 2, edges: 1 });
  assert.deepStrictEqual(graph.diagnostics.output, { nodes: 2, edges: 1 });
  assert.deepStrictEqual(graph.diagnostics.rejected, []);
});

run('network normalization preserves a Gene-centered selection and center node', () => {
  const payload = networkPayload();
  payload.selection = {
    feature: 'C1orf116',
    feature_type: 'Gene',
    gene: 'C1orf116',
    context: 'cancer_cell_line',
    source_center_te: 'HERVH-int',
  };
  payload.nodes[0].is_center = false;
  payload.nodes[1] = {
    ...payload.nodes[1],
    id: 'C1orf116',
    label: 'C1orf116',
    is_center: true,
    role: 'selected_gene',
  };
  payload.edges[0].target = 'C1orf116';
  const graph = Contract.normalizeNetwork(payload);
  assert.strictEqual(graph.selection.feature, 'C1orf116');
  assert.strictEqual(graph.selection.feature_type, 'Gene');
  assert.strictEqual(graph.nodes.find((node) => node.isCenter).kind, 'gene');
});

run('reference L1HS raw data remains 26 nodes and 100 edges', () => {
  const raw = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../data/coexpression/display_subgraphs/v1_abs0.4_fdr0.05_res1.8/all_te/L1HS/cancer_cell_line.json'), 'utf8'));
  const graph = Contract.normalizeNetwork({
    ok: true,
    version: 'v1_abs0.4_fdr0.05_res1.8',
    selection: { te: raw.center, context: raw.context_type, available_contexts: ['cancer_cell_line', 'normal_cell_line', 'normal_tissue'] },
    module: { id: raw.module_id, type: raw.module_type, size: raw.module_size, te_count: raw.TE_count, gene_count: raw.gene_count, confidence: raw.functional_context_confidence, candidate_label: raw.candidate_label, top_enriched_terms: raw.top_enriched_terms },
    interpretation: { statement_en: raw.interpretation_statement_en, statement_zh: raw.interpretation_statement_zh, limit: 'Co-expression is correlation, not causation or direct regulatory evidence.' },
    nodes: raw.nodes,
    edges: raw.edges,
  });
  assert.strictEqual(graph.nodes.length, 26);
  assert.strictEqual(graph.edges.length, 100);
});

run('invalid structural and scientific network records fail closed', () => {
  const duplicateNodes = networkPayload();
  duplicateNodes.nodes.push({ ...duplicateNodes.nodes[0] });
  expectContractError(() => Contract.normalizeNetwork(duplicateNodes), 'duplicate_node_id');

  const emptyId = networkPayload();
  emptyId.nodes[0].id = '  ';
  expectContractError(() => Contract.normalizeNetwork(emptyId), 'invalid_node_id');

  const missingEndpoint = networkPayload();
  missingEndpoint.edges[0].target = 'UNKNOWN';
  expectContractError(() => Contract.normalizeNetwork(missingEndpoint), 'invalid_edge_endpoint');

  const invalidNumbers = networkPayload();
  invalidNumbers.edges[0].correlation = '0.82';
  expectContractError(() => Contract.normalizeNetwork(invalidNumbers), 'invalid_edge_values');

  const invalidFdr = networkPayload();
  invalidFdr.edges[0].fdr = 1.1;
  expectContractError(() => Contract.normalizeNetwork(invalidFdr), 'invalid_edge_values');

  const unsafeCounts = networkPayload();
  unsafeCounts.nodes = Array.from({ length: 51 }, (_, index) => ({ id: `N${index}`, label: `N${index}`, feature_type: 'gene' }));
  expectContractError(() => Contract.normalizeNetwork(unsafeCounts), 'graph_too_large');

  const badLists = networkPayload();
  badLists.nodes = {};
  expectContractError(() => Contract.normalizeNetwork(badLists), 'invalid_network_structure');
});

process.stdout.write('PASS: co-expression browser contract\n');
