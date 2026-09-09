'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '../..');
const rendererPath = path.join(ROOT, 'assets/js/renderers/g6/coexpression/coexpression-renderer.js');
const dynamicGraphPath = path.join(ROOT, 'assets/js/renderers/g6/index-g6-shared.js');
const harnessPath = path.join(ROOT, 'test/coexpression_renderer_harness.html');
const adapterPath = path.join(ROOT, 'assets/js/renderers/g6/coexpression/coexpression-dynamic-adapter.js');
const rendererSource = fs.readFileSync(rendererPath, 'utf8');
const dynamicGraphSource = fs.readFileSync(dynamicGraphPath, 'utf8');
const harnessSource = fs.readFileSync(harnessPath, 'utf8');

assert.ok(
  rendererSource.includes("const FORK_SOURCE = 'assets/js/renderers/g6/index-g6-shared.js';"),
  'the Co-expression renderer must identify the production Dynamic Graph runner as its literal fork source',
);

for (const inheritedFragment of [
  'function createRunner(options)',
  'async function renderElements(elements, requestLike, options = {})',
  'const layoutDistanceScale = Math.max(1, Number(graphDataOptions.layoutDistanceScale) || 1.45);',
  "type: 'drag-element-force'",
  "'zoom-canvas'",
  "'drag-canvas'",
  'collisionPaddingScale',
  'chargeScale',
]) {
  assert.ok(dynamicGraphSource.includes(inheritedFragment), `Dynamic Graph source lost expected fragment: ${inheritedFragment}`);
  assert.ok(rendererSource.includes(inheritedFragment), `Co-expression fork did not retain Dynamic Graph fragment: ${inheritedFragment}`);
}

for (const rejectedRewriteFragment of ["graph.setLayout({ type: 'preset' })", 'scheduleCooling()', 'coolingTimer']) {
  assert.ok(
    !rendererSource.includes(rejectedRewriteFragment),
    `the fork must not retain the rejected hand-written force lifecycle: ${rejectedRewriteFragment}`,
  );
}

for (const inheritedHarnessFragment of [
  '../assets/css/tekg_runtime.css',
  '../assets/css/pages/preview.css',
  'preview-graph-embed-page',
  'preview-graph-workspace',
  'preview-graph-panel',
  'preview-g6-surface-stack',
  'graph-preloader',
  'g6-default-tree-surface',
  'node-details',
]) {
  assert.ok(
    harnessSource.includes(inheritedHarnessFragment),
    `the Task 5 harness must retain the Dynamic Graph page skeleton: ${inheritedHarnessFragment}`,
  );
}

assert.ok(
  rendererSource.includes('window.__TEKG_COEXPRESSION_RENDERER_CORE = {'),
  'the copied runner must expose a Co-expression-owned global instead of replacing the Knowledge Graph global',
);
assert.ok(
  rendererSource.includes('getGraph: () => graph'),
  'the isolated harness must be able to inspect the copied runner without changing its layout behavior',
);
assert.ok(
  rendererSource.includes('node.graphRipple = enabled && node.expressionAvailable === true;'),
  'every TE or Gene with Expression data must keep a stable ripple identity while Expression is enabled',
);
assert.ok(
  !rendererSource.includes("graph.on('node:pointerleave', () => {\n          expressionHoveredNodeId = '';\n          void syncExpressionPulseState();"),
  'leaving a TE must not redraw or replace its node while a force drag is active',
);
assert.ok(
  rendererSource.includes("node.fillColor = node.coexpressionIsCenter ? '#3b66c4' : '#7896d8';")
    && rendererSource.includes("node.fillColor = node.coexpressionIsCenter ? '#0f766e' : '#1aa486';"),
  'Co-expression partner TEs must be lighter and a Gene center must be darker without changing global entity colors',
);
assert.ok(
  !rendererSource.includes('const largestPartner = Math.max(')
    && !rendererSource.includes('coexpressionCenter.size = Math.max(68, largestPartner + 12);'),
  'The selected Co-expression center must use the same degree-based size rule as every other node',
);
assert.ok(
  rendererSource.includes('function exportSvgString()')
    && rendererSource.includes("graph.getElementPosition(node.id)")
    && rendererSource.includes('window.__TEKG_G6_SVG_EXPORT')
    && rendererSource.includes('.serialize({'),
  'SVG export must normalize final G6 positions through the shared vector serializer',
);

const adapter = require(adapterPath);
const network = {
  selection: { te: 'L1HS', context: 'cancer_cell_line' },
  nodes: [
    {
      id: 'L1HS',
      label: 'L1HS',
      kind: 'te',
      isCenter: true,
      isModuleHub: false,
      data: { type: 'Disease', description: 'raw API description', preferProvidedDescription: false },
    },
    { id: 'LTR5', label: 'LTR5', kind: 'te', isCenter: false, isModuleHub: false },
    { id: 'GENE1', label: 'GENE1', kind: 'gene', isCenter: false, isModuleHub: true },
  ],
  edges: [
    { id: 'e1', source: 'L1HS', target: 'GENE1', correlation: 0.9, fdr: 0.001 },
    { id: 'e2', source: 'LTR5', target: 'GENE1', correlation: 0.5, fdr: 0.02 },
  ],
};
const elements = adapter.toGraphElements(network);
assert.strictEqual(elements.length, 5);
assert.strictEqual(elements[0].data.type, 'TE');
assert.strictEqual(elements[2].data.type, 'Gene');
assert.strictEqual(elements[0].data.degree, 1);
assert.strictEqual(elements[2].data.degree, 2);
assert.strictEqual(elements[0].data.preferProvidedDescription, true);
assert.match(elements[0].data.description, /Selected TE/);
assert.strictEqual(elements[3].data.relation, 'positive correlation');
assert.strictEqual(elements[3].data.correlation, 0.9);
assert.match(elements[3].data.evidence, /Correlation does not imply causation/);
assert.ok(
  rendererSource.includes('data.preferProvidedDescription === true'),
  'the fork must allow the Co-expression adapter to keep domain-specific card descriptions',
);

process.stdout.write('PASS: Co-expression renderer is a literal production Dynamic Graph runner fork with a domain-only adapter\n');
