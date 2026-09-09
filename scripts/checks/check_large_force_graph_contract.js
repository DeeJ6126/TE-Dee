const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');

function loadScript(relativePath, context) {
  const absolutePath = path.join(root, relativePath);
  const source = fs.readFileSync(absolutePath, 'utf8');
  vm.runInContext(source, context, { filename: relativePath });
}

const context = vm.createContext({
  window: {},
  console,
});
context.window.window = context.window;

loadScript('assets/js/renderers/g6/large-force-graph/large-force-graph-contract.js', context);
loadScript('assets/js/renderers/g6/large-force-graph/adapters/taxonomy-large-force-adapter.js', context);

const contract = context.window.__TEKG_LARGE_FORCE_GRAPH_CONTRACT;
const adapter = context.window.__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER;

assert(contract, 'contract namespace should be exposed');
assert(adapter, 'taxonomy adapter namespace should be exposed');

const source = {
  rootId: 'TREE_TE',
  nodes: new Map([
    ['TREE_TE', { id: 'TREE_TE', label: 'TE', queryLabel: '', description: 'root', treeDepth: 0, treeIsMeta: true }],
    ['TREE_LINE', { id: 'TREE_LINE', label: 'LINE', queryLabel: 'LINE', description: 'order', treeDepth: 2, treeIsMeta: true }],
    ['TREE_L1HS', { id: 'TREE_L1HS', label: 'L1HS', queryLabel: 'L1HS', description: 'family', treeDepth: 5, treeIsMeta: false }],
  ]),
  children: new Map([
    ['TREE_TE', ['TREE_LINE']],
    ['TREE_LINE', ['TREE_L1HS']],
  ]),
};
const sourceSnapshot = JSON.stringify({
  rootId: source.rootId,
  nodes: [...source.nodes.entries()],
  children: [...source.children.entries()],
});

const graphData = adapter.fromTaxonomySource(source, {
  width: 800,
  height: 520,
  treeVariant: 'rmsk_repbase',
  visibleTaxonomyLevels: { 'level-5': false },
});

assert.strictEqual(graphData.meta.source, 'taxonomy');
assert.strictEqual(graphData.meta.truthSource, 'api/taxonomy.php');
assert.strictEqual(graphData.options.performanceProfile, 'large-static');
assert.strictEqual(graphData.nodes.length, 3, 'adapter should retain hidden-level nodes in master data');
assert.strictEqual(graphData.edges.length, 2, 'adapter should retain hidden-level edges in master data');
assert(graphData.legend.items.some((item) => item.key === 'level-5' && item.count === 1), 'legend should keep counts for hidden levels');
const visibleGraphData = contract.filterByLegend(graphData, graphData.legend.state);
assert.strictEqual(visibleGraphData.nodes.length, 2, 'legend filtering should prune hidden-level nodes');
assert.strictEqual(visibleGraphData.edges.length, 1, 'legend filtering should prune edges with hidden endpoints');

const rootNode = graphData.nodes.find((node) => node.id === 'TREE_TE');
const lineNode = graphData.nodes.find((node) => node.id === 'TREE_LINE');
assert(rootNode.pinnedLabel, 'root label should be pinned');
assert.strictEqual(lineNode.pinnedLabel, true, 'top taxonomy levels should keep labels');
assert.strictEqual(JSON.stringify({
  rootId: source.rootId,
  nodes: [...source.nodes.entries()],
  children: [...source.children.entries()],
}), sourceSnapshot, 'taxonomy adapter must not mutate its source maps');

const normalized = contract.normalizeGraphData({
  nodes: [{ id: 'a' }, { id: '' }, { id: 'b' }],
  edges: [{ id: 'ab', source: 'a', target: 'b' }, { id: 'missing', source: 'a', target: 'z' }],
});
assert.strictEqual(normalized.nodes.length, 2);
assert.strictEqual(normalized.edges.length, 1);
assert.strictEqual(normalized.report.droppedNodes, 1);
assert.strictEqual(normalized.report.droppedEdges, 1);
assert.strictEqual(normalized.nodes.find((node) => node.id === 'a').degree, 1);

console.log('[OK] large-force-graph contract check passed');
