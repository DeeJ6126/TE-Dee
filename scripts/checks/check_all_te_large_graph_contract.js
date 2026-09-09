const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const validFocus = new Set(['layout', 'data-prep', 'interactions', 'lifecycle-legend', 'semantic-visuals', 'transient-motion', 'star-systems', 'family-expansion', 'all']);

class FakeGraph {
  constructor(options) {
    this.options = options;
    this.data = options.data;
    this.handlers = new Map();
    this.elementStates = new Map();
    this.stateBatches = [];
    this.deferStateWrites = false;
    this.pendingStateWrites = [];
    this.deferDraw = false;
    this.pendingDraws = [];
    this.counts = {
      render: 0, draw: 0, destroy: 0, setData: 0, layout: 0, setElementState: 0,
      setLayout: 0, stopLayout: 0,
    };
  }

  async render() {
    this.counts.render += 1;
    if (this.options.layout?.type === 'd3-force') this.counts.layout += 1;
  }
  async draw() {
    this.counts.draw += 1;
    if (this.deferDraw) await new Promise((resolve) => this.pendingDraws.push(resolve));
  }
  destroy() { this.counts.destroy += 1; }
  setLayout(nextLayout) { this.options.layout = nextLayout; this.counts.setLayout += 1; }
  async layout() {
    this.counts.layout += 1;
    if (typeof this.options.layout?.onTick === 'function') this.options.layout.onTick();
  }
  stopLayout() { this.counts.stopLayout += 1; }
  setData(data) {
    this.data = data;
    this.elementStates.clear();
    this.counts.setData += 1;
  }
  on(name, handler) { this.handlers.set(name, handler); }
  off(name) { this.handlers.delete(name); }
  getNodeData(id) {
    return (this.data?.nodes || this.options.data.nodes).find((node) => node.id === id);
  }
  getElementState(id) { return this.elementStates.get(id) || []; }
  async setElementState(idOrStates, states) {
    const updates = typeof idOrStates === 'string' ? { [idOrStates]: states } : idOrStates;
    this.counts.setElementState += 1;
    this.stateBatches.push(Object.fromEntries(Object.entries(updates).map(([id, value]) => [id, [...value]])));
    Object.entries(updates).forEach(([id, value]) => this.elementStates.set(id, [...value]));
    if (this.deferStateWrites) await new Promise((resolve) => this.pendingStateWrites.push(resolve));
  }
  resolveNextStateWrite() { this.pendingStateWrites.shift()?.(); }
  resolveNextDraw() { this.pendingDraws.shift()?.(); }
  resolveLastDraw() { this.pendingDraws.pop()?.(); }
}

function loadScript(relativePath, context) {
  vm.runInContext(fs.readFileSync(path.join(root, relativePath), 'utf8'), context, { filename: relativePath });
}

function createContext() {
  const NodeEvent = {
    DRAG_START: 'node:dragstart',
    DRAG_END: 'node:dragend',
    POINTER_ENTER: 'node:pointerenter',
    POINTER_LEAVE: 'node:pointerleave',
  };
  const window = { G6: { Graph: FakeGraph, NodeEvent } };
  window.window = window;
  const context = vm.createContext({ window, console, Map, Set, Promise, setTimeout, clearTimeout });
  [
    'large-force-graph-contract.js',
    'large-force-graph-layout.js',
    'large-force-graph-styles.js',
    'adapters/taxonomy-large-force-adapter.js',
    'large-force-graph-core.js',
  ].forEach((file) => loadScript(`assets/js/renderers/g6/large-force-graph/${file}`, context));
  return { context, NodeEvent };
}

function makeSource() {
  const rows = [
    ['TE', 0, true],
    ['Retrotransposon', 1, true],
    ['LINE', 2, true],
    ['L1', 3, true],
    ['L1PA', 4, false],
    ['L1HS', 5, false],
  ];
  const ids = rows.map(([label]) => `TREE_${label.toUpperCase()}`);
  return {
    rootId: ids[0],
    nodes: new Map(rows.map(([label, treeDepth, treeIsMeta], index) => [ids[index], {
      id: ids[index], label, queryLabel: treeIsMeta ? '' : label, treeDepth, treeIsMeta,
    }])),
    children: new Map(ids.slice(0, -1).map((id, index) => [id, [ids[index + 1]]])),
  };
}

function makeInteractionSource() {
  const source = makeSource();
  source.nodes.set('TREE_DNA', { id: 'TREE_DNA', label: 'DNA', queryLabel: '', treeDepth: 1, treeIsMeta: true });
  source.nodes.set('TREE_OTHER', { id: 'TREE_OTHER', label: 'Other', queryLabel: '', treeDepth: 1, treeIsMeta: true });
  source.children.set('TREE_TE', ['TREE_RETROTRANSPOSON', 'TREE_DNA', 'TREE_OTHER']);
  return source;
}

function makeStarSystemSource() {
  const rows = [
    ['ROOT', 'Human TE', 0, true],
    ['CLASS_A', 'Class A', 1, true],
    ['ORDER_A', 'Order A', 2, true],
    ['SF_A', 'Superfamily A', 3, true],
    ['FAMILY_A1', 'Family A1', 4, false],
    ['SUBFAMILY_A1', 'Subfamily A1', 5, false],
    ['FAMILY_A2', 'Family A2', 4, false],
    ['SF_B', 'Superfamily B', 3, true],
    ['FAMILY_B1', 'Family B1', 4, false],
    ['SUBFAMILY_B1', 'Subfamily B1', 5, false],
    ['FAMILY_B2', 'Family B2', 4, false],
  ];
  const nodes = new Map(rows.map(([suffix, label, treeDepth, treeIsMeta]) => [`TREE_${suffix}`, {
    id: `TREE_${suffix}`, label, queryLabel: treeIsMeta ? '' : label, treeDepth, treeIsMeta,
  }]));
  const children = new Map([
    ['TREE_ROOT', ['TREE_CLASS_A']],
    ['TREE_CLASS_A', ['TREE_ORDER_A']],
    ['TREE_ORDER_A', ['TREE_SF_A', 'TREE_SF_B']],
    ['TREE_SF_A', ['TREE_FAMILY_A1', 'TREE_FAMILY_A2']],
    ['TREE_FAMILY_A1', ['TREE_SUBFAMILY_A1']],
    ['TREE_SF_B', ['TREE_FAMILY_B1', 'TREE_FAMILY_B2']],
    ['TREE_FAMILY_B1', ['TREE_SUBFAMILY_B1']],
  ]);
  return { rootId: 'TREE_ROOT', nodes, children };
}

function sourceSnapshot(source) {
  return JSON.stringify({
    rootId: source.rootId,
    nodes: [...source.nodes.entries()],
    children: [...source.children.entries()],
  });
}

function adapt(adapter, source = makeSource()) {
  return adapter.fromTaxonomySource(source, {
    width: 960,
    height: 640,
    treeVariant: 'all',
    visibleTaxonomyLevels: Object.fromEntries([...Array(6)].map((_, depth) => [`level-${depth}`, true])),
  });
}

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function checkTransientMotion() {
  const { context, NodeEvent } = createContext();
  const layout = context.window.__TEKG_LARGE_FORCE_GRAPH_LAYOUT;
  assert.strictEqual(typeof layout.createTransientDragLayout, 'function',
    'layout module must expose an explicit transient drag layout factory');
  let ticks = 0;
  const transient = layout.createTransientDragLayout({
    nodeById: new Map(),
    onTick: () => { ticks += 1; },
  });
  assert.strictEqual(transient.type, 'd3-force');
  assert.strictEqual(transient.animation, true,
    'transient drag layout must explicitly paint intermediate animation frames');
  assert.strictEqual(transient.collide.iterations, 1, 'transient collision must remain one cheap iteration');
  transient.onTick();
  assert.strictEqual(ticks, 1, 'transient layout must forward tick diagnostics');
  const scoped = layout.createTransientDragLayout({
    nodeById: new Map(),
    activeSystemId: 'TREE_SF_A',
  });
  const activeMember = { id: 'TREE_FAMILY_A1', level: 4, data: { treeDepth: 4, superfamilyId: 'TREE_SF_A' }, style: { size: 12 } };
  const foreignMember = { id: 'TREE_FAMILY_B1', level: 4, data: { treeDepth: 4, superfamilyId: 'TREE_SF_B' }, style: { size: 12 } };
  assert(scoped.manyBody.strength(activeMember) < scoped.manyBody.strength(foreignMember),
    'active Superfamily members must receive more avoidance force than foreign members');
  assert(scoped.x.strength(foreignMember) > scoped.x.strength(activeMember)
    && scoped.y.strength(foreignMember) > scoped.y.strength(activeMember),
  'foreign systems must remain more strongly constrained to their accepted equilibria');

  const data = adapt(context.window.__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER, makeInteractionSource());
  const renderer = context.window.__TEKG_LARGE_FORCE_GRAPH_CORE.createRenderer({
    container: { clientWidth: 960, clientHeight: 640 },
    data,
    motionStopDelayMs: 15,
  });
  assert.strictEqual(await renderer.render(data), true);
  const graph = renderer.getGraph();
  const event = { target: { id: 'TREE_L1PA' }, targetType: 'node' };
  let diagnostics = renderer.getDiagnostics();
  assert.strictEqual(diagnostics.counters.motionStart, 0, 'bounded first paint must not count as interaction motion');
  assert.strictEqual(diagnostics.activeMotionCount, 0);

  await invoke(graph, NodeEvent.DRAG_START, event);
  diagnostics = renderer.getDiagnostics();
  assert.strictEqual(diagnostics.counters.motionStart, 1, 'drag start must start one transient motion');
  assert.strictEqual(diagnostics.counters.layoutStart, 2,
    'one bounded initial settle plus one transient drag layout must be recorded');
  assert.strictEqual(diagnostics.activeMotionCount, 1);
  assert.strictEqual(graph.counts.setLayout, 2,
    'initial settle handoff plus transient drag must each set layout ownership once');
  assert.strictEqual(graph.counts.layout, 2);
  assert.strictEqual(diagnostics.counters.motionTick, 1);

  await invoke(graph, NodeEvent.DRAG_START, event);
  assert.strictEqual(renderer.getDiagnostics().counters.motionStart, 1,
    'repeated drag start must reuse the one owned motion');
  await invoke(graph, NodeEvent.DRAG_END, event);
  await delay(30);
  diagnostics = renderer.getDiagnostics();
  assert.strictEqual(diagnostics.activeMotionCount, 0, 'released motion must hard-stop');
  assert.strictEqual(diagnostics.counters.motionStop, 1);
  assert(diagnostics.lastStopMs >= 0 && diagnostics.lastStopMs <= 30,
    `released motion exceeded the test cooling bound: ${diagnostics.lastStopMs}`);
  assert.strictEqual(graph.counts.stopLayout, 1);
  assert.strictEqual(graph.options.layout.type, 'preset', 'stopped motion must restore preset ownership');
  assert.strictEqual(renderer.getGraph(), graph, 'transient motion must retain graph identity');

  await invoke(graph, NodeEvent.DRAG_START, event);
  await invoke(graph, NodeEvent.DRAG_END, event);
  await delay(5);
  const ticksBeforeReheat = renderer.getDiagnostics().counters.motionTick;
  await invoke(graph, NodeEvent.DRAG_START, event);
  await delay(20);
  assert.strictEqual(renderer.getDiagnostics().activeMotionCount, 1,
    'a new drag must cancel the previous release timer instead of being stopped by it');
  assert(renderer.getDiagnostics().counters.motionTick > ticksBeforeReheat,
    'a new drag during cooling must explicitly reheat motion after the earlier layout settled');
  assert.strictEqual(graph.counts.stopLayout, 2, 'reheat must replace, not duplicate, the owned layout');
  await invoke(graph, NodeEvent.DRAG_END, event);
  await delay(30);
  assert.strictEqual(renderer.getDiagnostics().activeMotionCount, 0);
  assert.strictEqual(graph.counts.stopLayout, 3);
}

async function checkStarSystems() {
  const { context } = createContext();
  const adapter = context.window.__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER;
  const source = makeStarSystemSource();
  const first = adapt(adapter, source);
  const second = adapt(adapter, source);
  const firstById = new Map(first.nodes.map((node) => [node.id, node]));
  const secondById = new Map(second.nodes.map((node) => [node.id, node]));
  const expected = {
    TREE_ROOT: ['root', '', '', ''],
    TREE_CLASS_A: ['class', 'TREE_CLASS_A', '', ''],
    TREE_ORDER_A: ['order', 'TREE_CLASS_A', 'TREE_ORDER_A', ''],
    TREE_SF_A: ['superfamily', 'TREE_CLASS_A', 'TREE_ORDER_A', 'TREE_SF_A'],
    TREE_FAMILY_A1: ['member', 'TREE_CLASS_A', 'TREE_ORDER_A', 'TREE_SF_A'],
    TREE_SUBFAMILY_A1: ['member', 'TREE_CLASS_A', 'TREE_ORDER_A', 'TREE_SF_A'],
    TREE_FAMILY_A2: ['member', 'TREE_CLASS_A', 'TREE_ORDER_A', 'TREE_SF_A'],
    TREE_SF_B: ['superfamily', 'TREE_CLASS_A', 'TREE_ORDER_A', 'TREE_SF_B'],
    TREE_FAMILY_B1: ['member', 'TREE_CLASS_A', 'TREE_ORDER_A', 'TREE_SF_B'],
    TREE_SUBFAMILY_B1: ['member', 'TREE_CLASS_A', 'TREE_ORDER_A', 'TREE_SF_B'],
    TREE_FAMILY_B2: ['member', 'TREE_CLASS_A', 'TREE_ORDER_A', 'TREE_SF_B'],
  };

  for (const [id, [starTier, classId, orderId, superfamilyId]] of Object.entries(expected)) {
    const node = firstById.get(id);
    const duplicate = secondById.get(id);
    assert(node && duplicate, `${id} must survive adaptation`);
    assert.strictEqual(node.starTier, starTier, `${id} starTier must derive from structural depth`);
    assert.strictEqual(node.classId || '', classId, `${id} classId must derive from ancestry`);
    assert.strictEqual(node.orderId || '', orderId, `${id} orderId must derive from ancestry`);
    assert.strictEqual(node.superfamilyId || '', superfamilyId, `${id} superfamilyId must derive from ancestry`);
    for (const key of ['starTier', 'classId', 'orderId', 'superfamilyId']) {
      assert.strictEqual(node.payload?.[key] ?? '', node[key] ?? '', `${id} payload must preserve ${key}`);
    }
    for (const key of ['x', 'y', 'clusterX', 'clusterY']) {
      assert(Number.isFinite(node[key]), `${id}.${key} must be finite`);
      assert.strictEqual(node[key], duplicate[key], `${id}.${key} must be deterministic`);
    }
  }

  const superfamilies = ['TREE_SF_A', 'TREE_SF_B'].map((id) => firstById.get(id));
  assert(Math.hypot(superfamilies[0].x - superfamilies[1].x, superfamilies[0].y - superfamilies[1].y) > 0,
    'distinct Superfamily anchors must not share a center');
  for (const anchor of superfamilies) {
    assert(Number.isFinite(anchor.systemRadius) && anchor.systemRadius > 0,
      `${anchor.id} must declare a finite positive systemRadius`);
  }
  const anchorDistance = Math.hypot(
    superfamilies[0].x - superfamilies[1].x,
    superfamilies[0].y - superfamilies[1].y,
  );
  assert(anchorDistance >= superfamilies[0].systemRadius + superfamilies[1].systemRadius,
    'declared Superfamily system discs must not overlap');

  for (const node of first.nodes.filter((item) => item.level >= 4)) {
    const own = firstById.get(node.superfamilyId);
    const foreign = superfamilies.find((item) => item.id !== node.superfamilyId);
    assert.strictEqual(node.clusterX, node.x, `${node.id} clusterX must retain its own deterministic orbital target`);
    assert.strictEqual(node.clusterY, node.y, `${node.id} clusterY must retain its own deterministic orbital target`);
    assert(Math.hypot(node.x - own.x, node.y - own.y) < Math.hypot(node.x - foreign.x, node.y - foreign.y),
      `${node.id} must be spatially closer to its own Superfamily anchor`);
  }

  const rootNode = firstById.get('TREE_ROOT');
  const classNode = firstById.get('TREE_CLASS_A');
  const orderNode = firstById.get('TREE_ORDER_A');
  const sfNode = firstById.get('TREE_SF_A');
  const memberNode = firstById.get('TREE_FAMILY_A1');
  assert(rootNode.size > classNode.size && classNode.size > orderNode.size
    && orderNode.size > sfNode.size && sfNode.size > memberNode.size,
  'root/class/order/superfamily/member tiers must have descending size hierarchy');
  assert(rootNode.strokeWidth > classNode.strokeWidth && classNode.strokeWidth > orderNode.strokeWidth
    && orderNode.strokeWidth > sfNode.strokeWidth && sfNode.strokeWidth > memberNode.strokeWidth,
  'root/class/order/superfamily/member tiers must have descending stroke hierarchy');

  const starLabels = superfamilies.filter((node) => node.starLabel === true);
  assert(starLabels.length > 0 && starLabels.length < superfamilies.length,
    'Superfamily star labels must be sparse rather than pinning every depth-3 label');
  assert(superfamilies.every((node) => node.pinnedLabel === false),
    'sparse star labels must not weaken the existing depth-3 pinnedLabel invariant');
  assert(first.nodes.filter((node) => node.level >= 4).every((node) => node.pinnedLabel === false),
    'ordinary deep members must remain unpinned');

  const renderer = context.window.__TEKG_LARGE_FORCE_GRAPH_CORE.createRenderer({
    container: { clientWidth: 960, clientHeight: 640 },
    data: first,
  });
  assert.strictEqual(await renderer.render(first), true);
  const graph = renderer.getGraph();
  const projected = new Map(graph.data.nodes.map((node) => [node.id, node]));
  const labelText = graph.options.node.style.labelText;
  const labeled = projected.get(starLabels[0].id);
  const ordinarySuperfamily = projected.get(superfamilies.find((node) => !node.starLabel).id);
  const deepMember = projected.get('TREE_FAMILY_A1');
  assert.strictEqual(labeled.starLabel, true,
    'sparse Superfamily starLabel must survive the production G6 projection');
  assert.strictEqual(labeled.data?.starLabel, true,
    'sparse Superfamily starLabel must remain available to O(1) style callbacks');
  assert(labelText(labeled).length > 0,
    'a sparse Superfamily starLabel must be displayed in the idle graph');
  assert.strictEqual(labelText(ordinarySuperfamily), '',
    'an ordinary depth-3 Superfamily label must remain hidden while idle');
  assert.strictEqual(labelText(deepMember), '',
    'ordinary depth-4+ member labels must remain hidden while idle');
  renderer.destroy();
}

async function checkFamilyExpansion() {
  const { context } = createContext();
  const adapter = context.window.__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER;
  const data = adapt(adapter, makeStarSystemSource());
  const renderer = context.window.__TEKG_LARGE_FORCE_GRAPH_CORE.createRenderer({
    container: { clientWidth: 960, clientHeight: 640 },
    data,
  });
  assert.strictEqual(await renderer.render(data), true);
  const graph = renderer.getGraph();
  const initialIds = new Set(renderer.getData().nodes.map((node) => node.id));
  assert(!initialIds.has('TREE_SUBFAMILY_A1') && !initialIds.has('TREE_SUBFAMILY_B1'),
    'initial visibility must stop at Family even when the level-5 legend is enabled');

  const before = { ...graph.counts };
  await invoke(graph, 'node:click', { target: { id: 'TREE_FAMILY_A1' }, targetType: 'node' });
  graph.elementStates.set('TREE_FAMILY_A1', ['selected']);
  assert.strictEqual(await renderer.expandFamily('TREE_FAMILY_A1'), true);
  assert.strictEqual(renderer.getGraph(), graph, 'Family expansion must preserve Graph identity');
  assert(renderer.getData().nodes.some((node) => node.id === 'TREE_SUBFAMILY_A1'));
  assert.strictEqual(graph.counts.setData - before.setData, 1);
  assert.strictEqual(graph.counts.draw - before.draw, 1);
  assert(graph.getElementState('TREE_FAMILY_A1').includes('selected'),
    'Family expansion must restore the selected visual state after setData');
  assert.strictEqual(await renderer.expandFamily('TREE_FAMILY_A1'), false, 'repeat expansion must be idempotent');
  assert.strictEqual(graph.counts.setData - before.setData, 1);
  assert.strictEqual(graph.counts.draw - before.draw, 1);

  assert.strictEqual(await renderer.expandFamily('TREE_FAMILY_B1'), true);
  assert.deepStrictEqual(
    JSON.parse(JSON.stringify(renderer.getDiagnostics().expandedFamilyIds.sort())),
    ['TREE_FAMILY_A1', 'TREE_FAMILY_B1'],
    'expansion must be cumulative',
  );
  assert(renderer.getData().nodes.some((node) => node.id === 'TREE_SUBFAMILY_A1'));
  assert(renderer.getData().nodes.some((node) => node.id === 'TREE_SUBFAMILY_B1'));
  assert(renderer.getData().edges.every((edge) => (
    renderer.getData().nodes.some((node) => node.id === edge.source)
    && renderer.getData().nodes.some((node) => node.id === edge.target)
  )), 'expanded edges must retain valid endpoints');

  assert.strictEqual(await renderer.collapseFamily('TREE_FAMILY_A1'), true);
  assert(!renderer.getData().nodes.some((node) => node.id === 'TREE_SUBFAMILY_A1'));
  assert(renderer.getData().nodes.some((node) => node.id === 'TREE_SUBFAMILY_B1'));
  assert.strictEqual(await renderer.collapseFamily('TREE_FAMILY_A1'), false, 'repeat collapse must be idempotent');
  assert.strictEqual(graph.counts.render, before.render);
  assert.strictEqual(graph.counts.destroy, before.destroy);
  assert.strictEqual(graph.counts.layout, before.layout);

  const nextSourceData = adapter.fromTaxonomySource(makeSource(), {
    width: 960,
    height: 640,
    treeVariant: 'rmsk_repbase',
    visibleTaxonomyLevels: Object.fromEntries([...Array(6)].map((_, depth) => [`level-${depth}`, true])),
  });
  assert.strictEqual(await renderer.render(nextSourceData), true);
  assert.deepStrictEqual(
    JSON.parse(JSON.stringify(renderer.getDiagnostics().expandedFamilyIds)),
    [],
    'renderer reuse with a different taxonomy source must clear expanded Family ids',
  );
}

async function checkLayout() {
  const { context, NodeEvent } = createContext();
  const layout = context.window.__TEKG_LARGE_FORCE_GRAPH_LAYOUT;
  const preset = layout.createLayout('taxonomy-large', {
    performanceProfile: 'large-static',
    nodeById: new Map(),
  });
  assert.deepStrictEqual(JSON.parse(JSON.stringify(preset)), { type: 'preset' },
    'large-static taxonomy layout must resolve to preset');
  const force = layout.createLayout('taxonomy-force', {
    performanceProfile: 'bounded-force',
    nodeById: new Map(),
  });
  assert.strictEqual(force.type, 'd3-force', 'bounded-force must remain explicit');

  const data = adapt(context.window.__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER);
  const renderer = context.window.__TEKG_LARGE_FORCE_GRAPH_CORE.createRenderer({
    container: { clientWidth: 960, clientHeight: 640 },
    data,
  });
  assert.strictEqual(await renderer.render(data), true);
  const graph = renderer.getGraph();
  const diagnostics = renderer.getDiagnostics();
  assert.strictEqual(diagnostics.initialLayoutType, 'd3-force',
    'production taxonomy renderer must start from the bounded dynamic profile');
  assert.strictEqual(diagnostics.activeInitialSettleCount, 0,
    'initial bounded settle must be stopped before render publishes');
  assert(diagnostics.lastInitialSettleMs >= 0 && diagnostics.lastInitialSettleMs <= 800,
    'initial bounded settle must stop within 800 ms');
  assert(['completed', 'deadline'].includes(diagnostics.initialSettleStopReason));
  assert.strictEqual(graph.options.layout.type, 'preset',
    'completed initial settle must hand layout ownership back to preset');
  const behaviorTypes = graph.options.behaviors.map((behavior) => (
    typeof behavior === 'string' ? behavior : behavior.type
  ));
  assert(behaviorTypes.includes('drag-element-force'), 'bounded dynamic taxonomy must use drag-element-force');

  assert.strictEqual(graph.counts.layout, 1, 'bounded production first paint must own one finite settle');
}

function checkDataPreparation() {
  const treeRendererSource = fs.readFileSync(
    path.join(root, 'assets/js/renderers/g6/default-tree-mindmap.js'),
    'utf8',
  );
  assert(!treeRendererSource.includes('getCurrentTreeElements().find('),
    'strict tree preparation must not rescan all elements for each edge coordinate');
  assert.strictEqual((treeRendererSource.match(/const positionYById = new Map\(\);/g) || []).length, 1,
    'strict tree preparation must create one positionYById coordinate index');
  assert(treeRendererSource.includes('positionYById.set(data.id,'),
    'strict tree preparation must populate positionYById during the node pass');
  assert(treeRendererSource.includes('positionYById.get(id) || 0'),
    'strict tree preparation must read edge coordinates from positionYById');
  const adapterSource = fs.readFileSync(
    path.join(root, 'assets/js/renderers/g6/large-force-graph/adapters/taxonomy-large-force-adapter.js'),
    'utf8',
  );
  assert(!adapterSource.includes('nodeEntries.find('),
    'taxonomy ancestry projection must use an index rather than rescan every node');

  const { context } = createContext();
  const adapter = context.window.__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER;
  const source = makeSource();
  const before = sourceSnapshot(source);
  const data = adapt(adapter, source);

  assert.strictEqual(data.meta.source, 'taxonomy');
  assert.strictEqual(data.meta.truthSource, 'api/taxonomy.php');
  assert.strictEqual(data.options.performanceProfile, 'bounded-dynamic');
  assert.strictEqual(data.nodes.length, source.nodes.size);
  assert.strictEqual(data.edges.length, source.nodes.size - 1);
  assert.strictEqual(sourceSnapshot(source), before, 'adapter must not mutate source maps');

  const nodeIds = new Set(data.nodes.map((node) => node.id));
  for (const node of data.nodes) {
    ['x', 'y', 'clusterX', 'clusterY'].forEach((key) => {
      assert(Number.isFinite(node[key]), `${node.id}.${key} must be finite`);
    });
    if (node.level >= 3) assert.strictEqual(node.pinnedLabel, false, `${node.id} must not pin a deep label`);
  }
  for (const edge of data.edges) {
    assert(nodeIds.has(edge.source), `${edge.id} source must exist`);
    assert(nodeIds.has(edge.target), `${edge.id} target must exist`);
  }
}

async function checkSemanticVisuals() {
  const { context } = createContext();
  const adapter = context.window.__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER;
  const style = context.window.__TEKG_LARGE_FORCE_GRAPH_STYLES;
  const data = adapt(adapter, makeInteractionSource());
  const nodes = new Map(data.nodes.map((node) => [node.id, node]));
  const rootNode = nodes.get('TREE_TE');
  const retro = nodes.get('TREE_RETROTRANSPOSON');
  const line = nodes.get('TREE_LINE');
  const l1pa = nodes.get('TREE_L1PA');
  const dna = nodes.get('TREE_DNA');
  const other = nodes.get('TREE_OTHER');

  for (const node of data.nodes) {
    assert(node.branchId, `${node.id} must expose branchId display metadata`);
    assert.strictEqual(node.clusterId, node.branchId, `${node.id} clusterId must follow its stable branch`);
    assert.strictEqual(node.payload.branchId, node.branchId, `${node.id} payload must preserve branchId`);
    assert.strictEqual(node.payload.clusterId, node.clusterId, `${node.id} payload must preserve clusterId`);
    assert.strictEqual(node.payload.branchColor, node.branchColor, `${node.id} payload must preserve branchColor`);
    assert.strictEqual(node.payload.branchHueToken, node.branchHueToken, `${node.id} payload must preserve hue token`);
    assert(!('shadowBlur' in node) && !('gradient' in node) && !('particles' in node),
      `${node.id} must not add leaf-heavy visual effects`);
  }
  assert.strictEqual(rootNode.branchHueToken, 'taxonomy-root', 'root must have an independent visual token');
  assert.notStrictEqual(rootNode.branchColor, retro.branchColor, 'root must not borrow a first-level branch color');
  assert.strictEqual(retro.branchId, line.branchId);
  assert.strictEqual(line.branchId, l1pa.branchId);
  assert.strictEqual(retro.branchColor, line.branchColor, 'same branch must keep one base color across depths');
  assert.strictEqual(line.branchColor, l1pa.branchColor, 'deep descendants must keep their branch base color');
  assert.strictEqual(retro.branchHueToken, l1pa.branchHueToken, 'same branch must keep one base hue token');
  assert.strictEqual(new Set([retro.branchColor, dna.branchColor, other.branchColor]).size, 3,
    'fixture first-level branches must use distinguishable stable palette colors');

  assert(data.nodes.filter((node) => node.level <= 2).every((node) => node.pinnedLabel === true),
    'levels 0-2 must pin labels');
  assert(data.nodes.filter((node) => node.level >= 3).every((node) => node.pinnedLabel === false),
    'levels 3+ must hide labels by default');
  assert(retro.strokeWidth > line.strokeWidth && line.strokeWidth > l1pa.strokeWidth,
    'high taxonomy levels must have stronger strokes than deep levels');
  assert(style.nodeOpacity({ level: 1, legendKeys: ['level-1'] }, { legendState: {} })
    > style.nodeOpacity({ level: 5, legendKeys: ['level-5'] }, { legendState: {} }),
  'depth may reduce node opacity without changing branch hue');
  assert.strictEqual(style.nodeOpacity({ level: 5, legendKeys: ['level-5'] }, { legendState: {}, legendFocus: 'level-1' }),
    style.nodeOpacity({ level: 5, legendKeys: ['level-5'] }, { legendState: {}, legendFocus: null }),
    'semantic styling must not restore global focus dimming');

  assert(data.edges.every((edge) => edge.label === '' && edge.curve === 'line'),
    'taxonomy edges must remain straight and unlabeled');
  const branchColors = new Set(data.nodes.map((node) => node.branchColor));
  assert(data.legend.items.every((item) => item.swatchRole === 'taxonomy-depth' && !branchColors.has(item.color)),
    'level legend must use neutral depth swatches rather than branch colors');

  const renderer = context.window.__TEKG_LARGE_FORCE_GRAPH_CORE.createRenderer({
    container: { clientWidth: 960, clientHeight: 640 },
    data,
  });
  assert.strictEqual(await renderer.render(data), true);
  const graph = renderer.getGraph();
  const g6Nodes = new Map(graph.data.nodes.map((node) => [node.id, node]));
  const lineWidth = graph.options.node.style.lineWidth;
  assert(lineWidth(g6Nodes.get('TREE_RETROTRANSPOSON')) > lineWidth(g6Nodes.get('TREE_LINE')));
  assert(lineWidth(g6Nodes.get('TREE_LINE')) > lineWidth(g6Nodes.get('TREE_L1PA')),
    'production G6 node style must consume semantic stroke widths');
  assert.strictEqual(graph.options.edge.type, 'line', 'production G6 edges must explicitly remain straight');
  renderer.destroy();

  const styleSource = fs.readFileSync(
    path.join(root, 'assets/js/renderers/g6/large-force-graph/large-force-graph-styles.js'),
    'utf8',
  );
  assert(!styleSource.includes('.find(') && !styleSource.includes('.filter('),
    'high-frequency style callbacks must remain O(1)');
}

async function checkLifecycleLegend() {
  const { context } = createContext();
  const contract = context.window.__TEKG_LARGE_FORCE_GRAPH_CONTRACT;
  const style = context.window.__TEKG_LARGE_FORCE_GRAPH_STYLES;
  const core = context.window.__TEKG_LARGE_FORCE_GRAPH_CORE;
  const adapter = context.window.__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER;
  const source = makeSource();
  const data = adapter.fromTaxonomySource(source, {
    width: 960,
    height: 640,
    treeVariant: 'all',
    visibleTaxonomyLevels: { 'level-4': false, 'level-5': false },
  });

  assert.strictEqual(data.nodes.length, source.nodes.size,
    'adapter must retain all normalized nodes regardless of initial legend visibility');
  assert.strictEqual(data.edges.length, source.nodes.size - 1,
    'adapter must retain all normalized edges regardless of initial legend visibility');
  assert.strictEqual(data.originalNodeCount, source.nodes.size, 'adapter must preserve original node count semantics');
  assert.strictEqual(data.originalEdgeCount, source.nodes.size - 1, 'adapter must preserve original edge count semantics');
  assert.strictEqual(data.meta.truthSource, 'api/taxonomy.php');
  assert.strictEqual(data.legend.state['level-4'], false);

  const before = JSON.stringify(data);
  const filtered = contract.filterByLegend(data, { 'level-4': false, 'level-5': true });
  assert.strictEqual(JSON.stringify(data), before, 'filterByLegend must not mutate master data');
  assert(!filtered.nodes.some((node) => node.id === 'TREE_L1PA'), 'a node with an explicitly false key must be hidden');
  assert(filtered.nodes.some((node) => node.id === 'TREE_L1HS'), 'a node with no false key must remain visible');
  assert(!filtered.edges.some((edge) => edge.target === 'TREE_L1PA' || edge.source === 'TREE_L1PA'),
    'edges with a hidden endpoint must be hidden');

  const callbacks = [];
  const renderer = core.createRenderer({
    container: { clientWidth: 960, clientHeight: 640 },
    data,
    callbacks: { onRenderStats: (stats) => callbacks.push(stats) },
  });
  assert.strictEqual(await renderer.render(data), true);
  const graph = renderer.getGraph();
  const initialDiagnostics = renderer.getDiagnostics();
  assert(initialDiagnostics.instanceId, 'renderer diagnostics must expose a stable instance id');
  assert.strictEqual(initialDiagnostics.graphId, data.graphId);
  assert.strictEqual(initialDiagnostics.source, 'all');
  assert.strictEqual(initialDiagnostics.sourceKind, 'taxonomy');
  assert.strictEqual(initialDiagnostics.master.nodes, source.nodes.size);
  assert.strictEqual(initialDiagnostics.visible.nodes, source.nodes.size - 2);
  assert.deepStrictEqual(JSON.parse(JSON.stringify(initialDiagnostics.counters)), {
    create: 1, destroy: 0, render: 1, setData: 0, draw: 0, layoutStart: 1,
    hover: 0, click: 0, dragStart: 0, dragEnd: 0,
    motionStart: 0, motionTick: 0, motionStop: 0, forcedStop: 0,
  });
  const initialLifecycle = core.getLifecycleDiagnostics();
  assert.deepStrictEqual(JSON.parse(JSON.stringify(initialLifecycle.liveInstanceIds)), [initialDiagnostics.instanceId]);
  assert(initialLifecycle.createdInstanceIds.includes(initialDiagnostics.instanceId));
  assert.strictEqual(initialLifecycle.liveInstanceCount, 1);

  const deepDatum = { id: 'deep', level: 5, legendKeys: ['level-5'], label: 'Deep label' };
  assert.strictEqual(style.nodeOpacity(deepDatum, { legendState: {}, legendFocus: 'level-4' }),
    style.nodeOpacity(deepDatum, { legendState: {}, legendFocus: null }),
    'base node opacity must not depend on legend focus');
  assert.strictEqual(style.displayLabel(deepDatum, { legendState: {}, legendFocus: 'level-5' }), '',
    'legend focus must not promote deep labels through base style callbacks');

  const handlersBefore = graph.handlers.size;
  const graphCountsBefore = { ...graph.counts };
  await renderer.setLegendState({ 'level-4': true });
  const afterApply = renderer.getDiagnostics();
  assert.strictEqual(renderer.getGraph(), graph, 'same-source legend Apply must preserve Graph identity');
  assert.strictEqual(renderer.getDiagnostics().instanceId, initialDiagnostics.instanceId,
    'same-source legend Apply must preserve renderer identity');
  assert.strictEqual(afterApply.visible.nodes, source.nodes.size - 1);
  assert.strictEqual(afterApply.visible.edges, source.nodes.size - 2,
    'visible edges must be reconciled to visible endpoints');
  assert.strictEqual(graph.counts.setData - graphCountsBefore.setData, 1, 'Apply must call setData exactly once');
  assert.strictEqual(graph.counts.draw - graphCountsBefore.draw, 1, 'Apply must call and await draw exactly once');
  assert.strictEqual(graph.counts.render - graphCountsBefore.render, 0, 'Apply must not render');
  assert.strictEqual(graph.counts.layout - graphCountsBefore.layout, 0, 'Apply must not start layout');
  assert.strictEqual(graph.counts.destroy - graphCountsBefore.destroy, 0, 'Apply must not destroy');
  assert.strictEqual(graph.handlers.size, handlersBefore, 'Apply must not register handlers');

  graph.elementStates.set('TREE_LINE', ['selected', 'hover']);
  const focusCounts = { ...graph.counts };
  await renderer.setLegendFocus('level-4');
  const focusBatch = graph.stateBatches.at(-1);
  assert.deepStrictEqual(Object.keys(focusBatch).sort(), [
    'TREE_L1PA', 'TREE_L1__taxonomy__TREE_L1PA',
  ].sort(), 'focus must touch only matching nodes and their incident edges');
  assert(graph.getElementState('TREE_L1PA').includes('legend-focus'));
  assert.deepStrictEqual(graph.getElementState('TREE_LINE'), ['selected', 'hover'],
    'focus must preserve unrelated selected and hover states');
  await renderer.setLegendFocus(null);
  assert.deepStrictEqual(Object.keys(graph.stateBatches.at(-1)).sort(), Object.keys(focusBatch).sort(),
    'clearing focus must touch only ids recorded by the previous focus');
  assert(!graph.getElementState('TREE_L1PA').includes('legend-focus'));
  for (const name of ['setData', 'draw', 'render', 'layout', 'destroy']) {
    assert.strictEqual(graph.counts[name], focusCounts[name], `focus must not call graph.${name}()`);
  }

  await renderer.setLegendFocus('level-4');
  const focusApplyBatchCount = graph.stateBatches.length;
  await renderer.setLegendState({ 'level-5': true });
  assert.strictEqual(graph.stateBatches.length, focusApplyBatchCount + 1,
    'visibility Apply must reapply the current focus to the new visible data');
  const reappliedFocusIds = Object.keys(graph.stateBatches.at(-1)).sort();
  assert(reappliedFocusIds.length > 0 && reappliedFocusIds.includes('TREE_L1PA'));
  await renderer.setLegendFocus(null);
  assert.deepStrictEqual(Object.keys(graph.stateBatches.at(-1)).sort(), reappliedFocusIds,
    'clear after visibility Apply must remove exactly the reapplied focus IDs');
  assert(reappliedFocusIds.every((id) => !graph.getElementState(id).includes('legend-focus')),
    'clear after visibility Apply must remove every local focus state');

  const callbacksBeforeRace = callbacks.length;
  graph.deferDraw = true;
  const staleApply = renderer.setLegendState({ 'level-4': false });
  await Promise.resolve();
  const latestApply = renderer.setLegendState({ 'level-4': true });
  await Promise.resolve();
  graph.resolveLastDraw();
  assert.strictEqual(await latestApply, true, 'latest rapid Apply must publish');
  graph.resolveNextDraw();
  assert.strictEqual(await staleApply, false, 'stale rapid Apply must not publish after a newer Apply');
  assert.strictEqual(callbacks.length, callbacksBeforeRace + 1, 'only the latest rapid Apply may publish stats');
  assert.strictEqual(renderer.getDiagnostics().visible.nodes, source.nodes.size - 1,
    'rapid Apply completion order must retain the latest Family-bounded visible state');

  const callbacksBeforeDestroy = callbacks.length;
  const destroyedApply = renderer.setLegendState({ 'level-4': false });
  await Promise.resolve();
  renderer.destroy();
  graph.resolveNextDraw();
  assert.strictEqual(await destroyedApply, false, 'destroyed renderer must suppress pending Apply completion');
  assert.strictEqual(callbacks.length, callbacksBeforeDestroy, 'destroyed Apply must not publish stats');

  renderer.destroy();
  const destroyed = renderer.getDiagnostics();
  assert.strictEqual(destroyed.counters.destroy, 1, 'destroy must be idempotent');
  assert.strictEqual(renderer.getGraph(), null);
  const destroyedLifecycle = core.getLifecycleDiagnostics();
  assert.strictEqual(destroyedLifecycle.liveInstanceCount, 0);
  assert(destroyedLifecycle.destroyedInstanceIds.includes(initialDiagnostics.instanceId));
}

async function invoke(graph, name, event) {
  const handler = graph.handlers.get(name);
  assert.strictEqual(typeof handler, 'function', `${name} handler must be registered`);
  await handler(event);
}

async function checkInteractions() {
  const { context, NodeEvent } = createContext();
  const data = adapt(context.window.__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER, makeInteractionSource());
  const hoverCalls = [];
  const clickCalls = [];
  const selectCalls = [];
  const renderer = context.window.__TEKG_LARGE_FORCE_GRAPH_CORE.createRenderer({
    container: { clientWidth: 960, clientHeight: 640 },
    data,
    motionStopDelayMs: 15,
    callbacks: {
      onNodeHover: (node, detail) => hoverCalls.push({ id: node?.id || null, detail }),
      onNodeClick: async (node, detail) => clickCalls.push({ id: node?.id || null, detail }),
      onNodeSelect: (node, detail) => selectCalls.push({ id: node?.id || null, detail }),
    },
  });
  assert.strictEqual(await renderer.render(data), true);
  const graph = renderer.getGraph();
  const before = { ...graph.counts };
  const highDegreeEvent = { target: { id: 'TREE_TE' }, targetType: 'node', client: { x: 321, y: 123 } };
  const lowDegreeEvent = { target: { id: 'TREE_L1PA' }, targetType: 'node', clientX: 222, clientY: 111 };
  graph.elementStates.set('TREE_RETROTRANSPOSON', ['selected']);

  await invoke(graph, NodeEvent.POINTER_ENTER, highDegreeEvent);
  const touchedOnEnter = new Set(Object.keys(graph.stateBatches.at(-1)));
  assert.deepStrictEqual([...touchedOnEnter].sort(), [
    'TREE_TE', 'TREE_RETROTRANSPOSON', 'TREE_DNA', 'TREE_OTHER',
    'TREE_TE__taxonomy__TREE_RETROTRANSPOSON', 'TREE_TE__taxonomy__TREE_DNA', 'TREE_TE__taxonomy__TREE_OTHER',
  ].sort(), 'high-degree hover must touch only the node, direct neighbors, and incident edges');
  assert.deepStrictEqual(graph.getElementState('TREE_RETROTRANSPOSON').sort(), ['neighbor', 'selected'],
    'hover must add its local state without replacing selected on a touched neighbor');
  await invoke(graph, NodeEvent.POINTER_LEAVE, highDegreeEvent);
  assert.deepStrictEqual(graph.getElementState('TREE_RETROTRANSPOSON'), ['selected'],
    'leave must remove only the Task 5 state from a touched selected neighbor');

  await invoke(graph, NodeEvent.POINTER_ENTER, lowDegreeEvent);
  assert.deepStrictEqual(Object.keys(graph.stateBatches.at(-1)).sort(), [
    'TREE_L1PA', 'TREE_L1', 'TREE_L1__taxonomy__TREE_L1PA',
  ].sort(), 'low-degree hover must remain bounded to one neighbor and edge');
  const repeatedEnterCount = graph.counts.setElementState;
  await invoke(graph, NodeEvent.POINTER_ENTER, lowDegreeEvent);
  assert.strictEqual(graph.counts.setElementState, repeatedEnterCount, 'same-node enter must not write states again');
  await invoke(graph, NodeEvent.POINTER_LEAVE, lowDegreeEvent);
  await invoke(graph, NodeEvent.DRAG_START, lowDegreeEvent);
  await invoke(graph, NodeEvent.DRAG_END, lowDegreeEvent);
  await delay(30);
  await invoke(graph, 'node:click', lowDegreeEvent);

  assert.deepStrictEqual(graph.getElementState('TREE_RETROTRANSPOSON'), ['selected'], 'later hover must preserve selected state');
  assert.strictEqual(hoverCalls.length, 4, 'same-node repeated enter must not duplicate the hover callback');
  assert.deepStrictEqual(hoverCalls.map((entry) => entry.id), ['TREE_TE', null, 'TREE_L1PA', null]);
  assert.deepStrictEqual(JSON.parse(JSON.stringify(hoverCalls[0].detail.client)), { x: 321, y: 123 });
  assert.strictEqual(clickCalls.length, 1, 'click callback must run exactly once');
  assert.strictEqual(selectCalls.length, 1, 'select callback must run exactly once');
  assert.strictEqual(graph.counts.draw, before.draw, 'frequent interactions must not call graph.draw()');
  assert.strictEqual(graph.counts.destroy, before.destroy, 'frequent interactions must not recreate the graph');
  assert.strictEqual(graph.counts.render, before.render, 'frequent interactions must not rerender the graph');
  assert.strictEqual(graph.counts.layout, before.layout + 1, 'one drag must run one transient layout');
  assert.strictEqual(renderer.getDiagnostics().activeMotionCount, 0, 'drag motion must stop after release');
  assert.strictEqual(graph.counts.setData, before.setData, 'frequent interactions must not replace graph data');
  assert.strictEqual(graph.getNodeData ? graph.data.nodes.length : 0, renderer.getData().nodes.length,
    'visible node count must remain stable');
  assert.strictEqual(graph.data.edges.length, renderer.getData().edges.length, 'visible edge count must remain stable');
  assert.strictEqual(renderer.getGraph(), graph, 'frequent interactions must preserve graph identity');

  const raceHoverCalls = [];
  const raceRenderer = context.window.__TEKG_LARGE_FORCE_GRAPH_CORE.createRenderer({
    container: { clientWidth: 960, clientHeight: 640 },
    data,
    callbacks: { onNodeHover: (node) => raceHoverCalls.push(node?.id || null) },
  });
  assert.strictEqual(await raceRenderer.render(data), true);
  const raceGraph = raceRenderer.getGraph();
  raceGraph.deferStateWrites = true;
  const raceEnter = invoke(raceGraph, NodeEvent.POINTER_ENTER, lowDegreeEvent);
  await Promise.resolve();
  const raceLeave = invoke(raceGraph, NodeEvent.POINTER_LEAVE, lowDegreeEvent);
  await Promise.resolve();
  assert.deepStrictEqual(raceHoverCalls, [null], 'leave must hide hover promptly while enter state work is pending');
  raceGraph.resolveNextStateWrite();
  await Promise.resolve();
  raceGraph.resolveNextStateWrite();
  await Promise.all([raceEnter, raceLeave]);
  assert.deepStrictEqual(raceHoverCalls, [null], 'stale enter continuation must not recreate hover after leave');
  assert.strictEqual(raceRenderer.getState().hoverNodeId, null, 'async enter/leave race must finish without stale hover identity');
  assert(!raceGraph.getElementState('TREE_L1PA').includes('hover'), 'async enter/leave race must clear local hover state');

  const destroyEnter = invoke(raceGraph, NodeEvent.POINTER_ENTER, lowDegreeEvent);
  await Promise.resolve();
  raceRenderer.destroy();
  raceGraph.resolveNextStateWrite();
  await destroyEnter;
  assert.deepStrictEqual(raceHoverCalls, [null], 'destroyed renderer must suppress pending enter callback');
  assert.strictEqual(raceRenderer.getGraph(), null, 'destroy during pending enter must keep renderer destroyed');
}

async function main() {
  const argv = process.argv.slice(2);
  const focusIndex = argv.indexOf('--focus');
  const focus = focusIndex < 0 ? 'all' : argv[focusIndex + 1];
  if (!validFocus.has(focus)) throw new Error(`--focus must be one of: ${[...validFocus].join(', ')}`);
  const groups = {
    layout: checkLayout,
    'data-prep': checkDataPreparation,
    interactions: checkInteractions,
    'lifecycle-legend': checkLifecycleLegend,
    'semantic-visuals': checkSemanticVisuals,
    'transient-motion': checkTransientMotion,
    'star-systems': checkStarSystems,
    'family-expansion': checkFamilyExpansion,
  };
  const selected = focus === 'all' ? Object.entries(groups) : [[focus, groups[focus]]];
  const failures = [];

  for (const [name, check] of selected) {
    try {
      await check();
      console.log(`[OK] ${name}`);
    } catch (error) {
      failures.push(name);
      console.error(`[FAIL] ${name}: ${error.message}`);
    }
  }
  if (failures.length) process.exitCode = 1;
  else console.log('[OK] all-TE large graph contract check passed');
}

main().catch((error) => {
  console.error(`[ERROR] ${error.stack || error.message}`);
  process.exitCode = 1;
});
