# All-TE G6 Performance and Visual Stabilization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `subagent-driven-development` (recommended) or `executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the production `All TE taxonomy` G6 view load and interact smoothly while preserving all taxonomy semantics, the existing Tree/Graph workflow, and the ordinary Evidence Graph.

**Architecture:** Keep the existing shared `large-force-graph` core and taxonomy adapter, but use the revised Task 9 bounded dynamic multi-star profile for the production taxonomy Graph. Deterministic adapter coordinates remain the nonblank warm start, while Class, Order, and Superfamily act as separated structural anchors and Family nodes retain their own orbital equilibria. The initial visible graph stops at Family; multiple Families may expose authoritative direct Subfamilies cumulatively through the existing renderer. Frequent interactions remain local, data replacement reuses one renderer, every simulation has a measured cooling deadline, and the verified `large-static -> preset` path remains an explicit fallback rather than the active dynamic profile. Taxonomy source and Tree/Graph mode changes remain explicit ownership boundaries so stale expansion state, data, and callbacks cannot cross sources. The design borrows only lightweight interaction ideas from `NetworkEnhancedForceGraph.jsx`; it does not introduce React or a second renderer.

**Tech Stack:** PHP page shell, browser JavaScript, local AntV G6 Canvas runtime, Python Playwright checks, Node/vm contract checks, Neo4j/API-backed taxonomy data.

---

## Status and Ownership

- Status: Tasks 1-8 complete. Task 9 was design-revised on 2026-07-18 and is approved for later implementation; this plan edit does not begin runtime work.
- Plan owner: main AI maintainer.
- Runtime target: production Graph page (`preview.php`), not a parallel demo.
- User authorization: throughout this plan, the main AI may use available medium-reasoning 5.6 sol subagents for Explorer, Worker, Reviewer, and Verifier roles.
- Supersedes implementation guidance in `docs/exec-plans/active/large-force-graph-phase1-design.md`. That file remains useful as the original architecture record, but it no longer describes the current code state because its proposed modules now exist.
- Repository durability: `.gitignore` contains narrow exceptions for this plan in its active/completed locations and for its dated evaluation directory, so handoff and verification evidence remain visible to Git without exposing the rest of the ignored `docs/` tree.

## Resume After Context Compaction

The next session must not reload the whole repository. Read in this order:

1. `AGENTS.md`
2. `AI_HANDOFF.md`
3. This plan
4. `docs/architecture/current_system.md`
5. `docs/architecture/graph_runtime.md`
6. Files listed under **Primary Runtime File Scope**
7. For the current task only: its listed checks, immediate callers, `assets/js/renderers/g6/index-g6.bootstrap.js`, and existing browser-harness helpers as read-only context

Do not broaden the read set beyond immediate callers and task-listed checks unless a concrete failure requires it. Read access does not expand edit ownership; any edit outside the stated task scope still requires a plan deviation and main-AI approval.

Before any edit:

```powershell
git status --short
git diff -- docs/exec-plans/active/all-te-g6-performance-stabilization.md assets/js/renderers/g6/large-force-graph assets/js/renderers/g6/default-tree-mindmap.js assets/js/renderers/g6/index-g6.bootstrap.js scripts/checks
```

Do not revert unrelated user work. If relevant files contain new user changes, integrate with them and record the deviation in **Execution Log**.

## Confirmed Baseline

Read-only investigation on 2026-07-14/15 established:

- `api/taxonomy.php?view=tree&source=all`: 1,666 nodes, 1,665 edges, 316,559 decoded bytes.
- Default visible taxonomy levels currently render approximately 932 nodes and 931 edges.
- Browser resource duration for the local `source=all` API was approximately 77 ms after page startup.
- Taxonomy adapter transformation was approximately 6.7 ms.
- Current G6 `d3-force` render was approximately 1,852 ms; total taxonomy Graph render was approximately 1,962 ms.
- The force render produced an approximately 1.94-second main-thread long task.
- A runtime-only experiment replacing the layout with `{ type: "preset" }` rendered the same graph in approximately 443 ms.
- Hover feedback remained approximately 33 ms after warm-up because pointer enter/leave invokes a whole-graph draw.
- `node scripts/checks/check_large_force_graph_contract.js` passed, but the check does not cover the real `all` payload, performance, lifecycle counts, or browser interaction latency.

These numbers are evidence, not permanent taxonomy constants. API semantic checks must reconcile declared counts, array lengths, root connectivity, IDs, and edge endpoints. Exact `1666/1665` may change only when the taxonomy input changes deliberately in the same reviewed work.

## Root-Cause Statement

The primary performance defect is not the API payload. The taxonomy adapter already computes finite positions, but `large-force-graph-core.js` always starts a full `d3-force` layout with link, many-body, x/y, and collision forces. The declared `performanceProfile: "large-static"` is not consumed. Frequent pointer and drag events call whole-graph `draw()`, and visibility/source changes can destroy and rebuild the graph. `buildStrictTreeSource()` also performs repeated linear `.find()` calls while building and sorting edges.

The plan must fix these causes in that order. It must not begin with styling changes, pagination, API migration, or a renderer rewrite.

## Decisions Locked by This Plan

1. Keep G6 and the current Graph page.
2. Do not add React or `react-force-graph-2d` to production.
3. Do not integrate `taxonomy_canvas_demo.php` into production in this plan.
4. Use the existing taxonomy adapter coordinates as the default layout truth for rendering only. Taxonomy semantic truth remains `api/taxonomy.php`.
5. `large-static` must not silently fall back to force.
6. `large-static` must still resolve to preset for initial render. Task 8 may introduce an explicit transient-force interaction policy that reheats only for node dragging, cools to a complete stop within a measured bound, and never activates for hover, selection, legend changes, source changes, or idle display. A continuously running production force layout remains prohibited.
7. No co-expression frontend, adapter, API, or data work is included.
8. Do not modify the ordinary dynamic Evidence Graph implementation unless a regression test proves a shared-shell fix is unavoidable. Any such change requires a plan update before editing.
9. No Neo4j, MySQL, taxonomy API, runtime database, expression, Agent, or DeepThink changes.
10. Performance gains never justify lost nodes, stale source data, broken legend semantics, blank Canvas output, or ordinary Graph regressions.

## Primary Runtime File Scope

Expected modifications:

- `assets/js/renderers/g6/large-force-graph/large-force-graph-contract.js`
  - Add pure view filtering/diagnostic helpers only when required by tests.
- `assets/js/renderers/g6/large-force-graph/large-force-graph-layout.js`
  - Resolve `large-static` to preset; keep force under an explicit non-default profile.
- `assets/js/renderers/g6/large-force-graph/large-force-graph-core.js`
  - Pass performance profile, maintain lifecycle/diagnostic counters, remove frequent global redraws, and reuse the graph for safe data updates.
- `assets/js/renderers/g6/large-force-graph/large-force-graph-styles.js`
  - Keep labels sparse and add deterministic branch-aware visual tokens after performance work passes.
- `assets/js/renderers/g6/large-force-graph/adapters/taxonomy-large-force-adapter.js`
  - Preserve authoritative positions, expose branch/anchor metadata, and keep complete legend counts.
- `assets/js/renderers/g6/default-tree-mindmap.js`
  - Replace repeated coordinate searches, host the taxonomy tooltip, and bridge legend/source updates without changing Tree mode.

Expected tests and evidence:

- Modify: `scripts/checks/check_large_force_graph_contract.js`
- Modify if it exists and currently requires force: `scripts/checks/check_g6_taxonomy_graph_mode.py`
- Create: `scripts/checks/check_all_te_large_graph_contract.js`
- Create: `scripts/checks/check_all_te_large_graph_browser.py`
- Create: `scripts/checks/measure_all_te_graph_performance.py`
- Create: `docs/eval/runs/2026-07-15-all-te-large-static/baseline.json`
- Create: `docs/eval/runs/2026-07-15-all-te-large-static/optimized.json`
- Create: `docs/eval/runs/2026-07-15-all-te-large-static/README.md`
- Create during verification: `docs/eval/runs/2026-07-15-all-te-large-static/desktop.png`

Documentation touched only at completion:

- This plan: execution and verification results.
- `AI_HANDOFF.md`: only if the accepted runtime behavior changes the next-session facts.
- `docs/architecture/graph_runtime.md`: record the accepted `large-static -> preset` contract.
- `docs/exec-plans/tech-debt-tracker.md`: only for unresolved structural risks.

Explicitly out of scope:

- `api/taxonomy.php`
- `api/graph.php`
- `api/graph_service.php`
- `assets/js/renderers/g6/index-g6-shared.js`
- `assets/js/renderers/g6/index-g6-runtime.js`
- `assets/js/renderers/g6/index-g6-embed.js`
- `assets/js/renderers/canvas-force/`
- `taxonomy_canvas_demo.php`
- `data/coexpression/`
- `scripts/coexpression/`
- `api/agent/`

If implementation appears to require an out-of-scope file, stop that Worker, document the reason, and obtain main-AI approval before expanding scope.

## Harness Roles and File Ownership

Each implementation phase uses the following sequence:

1. Explorer: read-only confirmation of the exact call path and current diff.
2. Worker: edits only the files assigned in that task.
3. Reviewer: independent findings-first review with file/line references; no implementation edits.
4. Verifier: fresh commands and browser evidence on the integrated worktree; no implementation edits.
5. Main AI: reviews diffs, resolves findings, updates this plan, and decides whether the phase passes.

Subagents may not declare the phase complete. The main AI must inspect their evidence and run or witness the final verification.

## Performance Gates

Deterministic gates are mandatory on every machine:

- `performanceProfile: "large-static"` resolves to `preset`.
- A production `all` render starts zero force layouts.
- Hover, pointer leave, and selection start zero layouts and create/destroy zero graph instances. Before Task 8, drag start/end also start zero layouts; after Task 8, only the accepted bounded drag lifecycle may start transient motion.
- The production startup profile uses ordinary `drag-element`, not `drag-element-force`, and never warms force before first paint. Task 8 may replace only the drag interaction with its explicitly bounded transient-force policy.
- Exactly one taxonomy large-graph instance is live while Graph mode is active.
- Tree mode and ordinary dynamic Graph retain their own expected lifecycle.
- Frequent hover logic is proportional to the touched node's degree and does not scan every node or edge.
- Every visible edge has two visible endpoints.
- API source and taxonomy truth remain unchanged.

Reference-machine timing gates use two warmups and five measured runs in the same Chromium process:

- Adapter median: at most 50 ms.
- G6 render median for default-filtered `all`: at most 800 ms.
- Warm-cache taxonomy Graph stable-interaction median: at most 1,000 ms.
- Warm-cache page-to-interactive median: at most 1,500 ms.
- Hover/leave to tooltip-state median: at most 50 ms.
- Legend focus median: at most 50 ms.
- Optimized G6 render median: at least 2x faster than the same-machine force baseline.

Absolute timing gates may be rerun once after eliminating concurrent machine load. Deterministic gates remain mandatory even if the timing environment is unstable.

## Task 1: Capture a Reproducible Baseline Before Runtime Edits

**Files:**

- Create: `scripts/checks/measure_all_te_graph_performance.py`
- Create: `scripts/checks/check_all_te_large_graph_browser.py`
- Create: `docs/eval/runs/2026-07-15-all-te-large-static/baseline.json`
- Create: `docs/eval/runs/2026-07-15-all-te-large-static/README.md`
- Modify: this plan, **Execution Log** only

- [x] **Step 1: Create the measurement script before changing the renderer**

The script must accept:

```text
--source all|rmsk_repbase
--warmups <int>
--runs <int>
--output <path>
--assert-budget
```

It must use the project Playwright environment, set the taxonomy source explicitly, and distinguish full warm-cache page startup, adapter work, `renderGraph()`, stable interaction, and targeted interaction probes. Each render/stable measurement waits two animation frames. Do not collapse page startup and renderer time into one number.

```python
result = {
    "source": source,
    "api": {
        "node_count": api_payload["node_count"],
        "edge_count": api_payload["edge_count"],
        "decoded_bytes": decoded_bytes,
    },
    "environment": {
        "viewport": {"width": 1440, "height": 960},
        "device_scale_factor": 1,
        "browser_version": browser.version,
        "git_commit": git_commit,
        "dirty_files": dirty_files,
    },
    "runs": measured_runs,
    "summary": {
        "adapter_median_ms": median(adapter_values),
        "render_median_ms": median(render_values),
        "stable_median_ms": median(stable_values),
        "page_to_interactive_median_ms": median(page_to_interactive_values),
        "hover_leave_median_ms": median(hover_leave_values),
        "legend_focus_median_ms": median(legend_focus_values),
        "legend_visibility_apply_median_ms": median(legend_apply_values),
        "longest_task_max_ms": max(long_tasks),
    },
}
```

Do not include the first PHP/browser cold start in any timing median. `--assert-budget` owns and checks every timing gate in **Performance Gates**: adapter, render, stable, page-to-interactive, hover/leave, legend focus, and relative render improvement. Legend visibility Apply is recorded separately because it may perform a deliberate data update/draw and is judged against the stable-interaction budget, not the 50 ms focus budget. Capture page errors, console errors, failed requests, graph node/edge counts, and Canvas dimensions in every measured run.

- [x] **Step 2: Create the browser harness skeleton before any task invokes it**

The browser check must accept:

```text
--focus initial|hover-drag|legend-source-roundtrip|all
--screenshot <path>
```

Default `--focus all` runs every implemented section. The Task 1 skeleton must already implement page startup, API/count reconciliation, error capture, Canvas dimensions, aggregate pixel sampling, and diagnostic capture. Later tasks extend the same file with their new interaction assertions; they must not create parallel browser harnesses.

- [x] **Step 3: Run the baseline measurement**

```powershell
python scripts/checks/measure_all_te_graph_performance.py --source all --warmups 2 --runs 5 --output docs/eval/runs/2026-07-15-all-te-large-static/baseline.json
```

Expected before optimization: functional capture succeeds; render median is near the existing force baseline and may exceed the future 800 ms budget.

- [x] **Step 4: Record baseline interpretation**

`README.md` must state that the run is local reference evidence, list the exact command/environment, distinguish API/adapter/layout/draw time, and explicitly say that exact timing is not a cross-machine correctness assertion.

- [x] **Step 5: Run the current correctness checks**

```powershell
node scripts/checks/check_large_force_graph_contract.js
python scripts/checks/check_taxonomy_runtime_truth.py
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_all_te_large_graph_browser.py --focus initial
```

Expected: all existing checks pass. Record any pre-existing failure before continuing; do not attribute it to later work.

- [x] **Step 6: Main-AI checkpoint**

The main AI reviews the baseline JSON and confirms that the captured Graph contains at least 900 nodes and 900 edges under the current default level state. Stop if the Canvas is blank, API counts do not reconcile, or ordinary Graph smoke is already failing for an unexplained reason.

## Task 2: Add Failing Contracts for Static Layout and Full-Graph Redraw Prohibition

**Files:**

- Create: `scripts/checks/check_all_te_large_graph_contract.js`
- Modify: `scripts/checks/check_large_force_graph_contract.js`
- Test only; no runtime modification in this task

- [x] **Step 1: Load the contract, layout, styles, adapter, and core in a Node vm harness**

The contract check must accept `--focus layout|data-prep|interactions|all`; default `all` runs every group. The `data-prep` group owns adapter semantics and the Task 4 coordinate-index/source-complexity assertions. This keeps red/green checkpoints unambiguous while the tasks are implemented sequentially.

Use a fake G6 Graph that records calls:

```js
class FakeGraph {
  constructor(options) {
    this.options = options;
    this.handlers = new Map();
    this.counts = { render: 0, draw: 0, destroy: 0, setData: 0, layout: 0 };
  }
  async render() { this.counts.render += 1; }
  draw() { this.counts.draw += 1; }
  destroy() { this.counts.destroy += 1; }
  setData(data) { this.data = data; this.counts.setData += 1; }
  on(name, handler) { this.handlers.set(name, handler); }
  off() {}
  getNodeData(id) { return (this.data?.nodes || this.options.data.nodes).find((node) => node.id === id); }
}
```

- [x] **Step 2: Assert the intended layout contract**

```js
const preset = layout.createLayout('taxonomy-large', {
  performanceProfile: 'large-static',
  nodeById: new Map(),
});
assert.deepStrictEqual(preset, { type: 'preset' });

const force = layout.createLayout('taxonomy-force', {
  performanceProfile: 'bounded-force',
  nodeById: new Map(),
});
assert.strictEqual(force.type, 'd3-force');
```

- [x] **Step 3: Assert finite adapter positions and taxonomy semantics**

For a representative multi-level source, assert every node has finite `x`, `y`, `clusterX`, and `clusterY`; `meta.source === "taxonomy"`; `meta.truthSource === "api/taxonomy.php"`; deep labels are not pinned; edge endpoints exist; and the input maps were not mutated.

- [x] **Step 4: Assert frequent interactions do not globally draw**

After `renderer.render(data)`, capture counters, invoke registered pointer enter, pointer leave, drag start, drag end, and click handlers, then assert:

```js
assert.strictEqual(fake.counts.draw, before.draw, 'frequent interactions must not call graph.draw()');
assert.strictEqual(fake.counts.destroy, before.destroy, 'frequent interactions must not recreate the graph');
assert.strictEqual(fake.counts.render, before.render, 'frequent interactions must not rerender the graph');
```

- [x] **Step 5: Run the new contract and prove it fails for the current implementation**

```powershell
node scripts/checks/check_all_te_large_graph_contract.js --focus all
```

Expected: FAIL because `large-static` currently resolves to `d3-force` and pointer/drag handlers call `draw()`.

- [x] **Step 6: Commit boundary**

Do not commit automatically. Present the test-only diff to the main AI. The main AI records the expected failure in the execution log before assigning Task 3.

## Task 3: Make `large-static` Use Authoritative Preset Positions

**Files:**

- Modify: `assets/js/renderers/g6/large-force-graph/large-force-graph-layout.js`
- Modify: `assets/js/renderers/g6/large-force-graph/large-force-graph-core.js`
- Test: `scripts/checks/check_all_te_large_graph_contract.js`

- [x] **Step 1: Pass the performance profile into layout resolution**

The core call must become equivalent to:

```js
layout: layout.createLayout(data.options?.layoutProfile || 'taxonomy-large', {
  nodeById,
  performanceProfile: data.options?.performanceProfile || '',
}),
```

- [x] **Step 2: Resolve the static profile without force**

Implement the layout boundary as:

```js
function createLayout(profile, options = {}) {
  if (options.performanceProfile === 'large-static') return { type: 'preset' };
  if (profile === 'taxonomy-force') return createTaxonomyLargeForceLayout(options);
  return { type: 'preset' };
}
```

Rename the existing force helper to make its non-default nature explicit. Do not delete it; keeping an explicit bounded experiment path makes rollback and comparison possible.

- [x] **Step 3: Decouple static dragging from force behavior**

For the static profile, resolve behaviors separately from layout. Replace force-coupled dragging with ordinary element dragging:

```js
const dragBehavior = performanceProfile === 'large-static'
  ? 'drag-element'
  : 'drag-element-force';
```

The exact local G6 behavior configuration must be confirmed against the bundled runtime before editing. The contract check must prove that production `large-static` contains no `drag-element-force` behavior and that drag start/end do not start or reheat a layout.

- [x] **Step 4: Run syntax and contract checks**

```powershell
node --check assets/js/renderers/g6/large-force-graph/large-force-graph-layout.js
node --check assets/js/renderers/g6/large-force-graph/large-force-graph-core.js
node scripts/checks/check_all_te_large_graph_contract.js --focus layout
node scripts/checks/check_large_force_graph_contract.js
```

Expected: all layout assertions pass. Interaction assertions remain intentionally out of this focused run until Task 5.

- [x] **Step 5: Run the performance measurement immediately**

```powershell
python scripts/checks/measure_all_te_graph_performance.py --source all --warmups 2 --runs 5 --output docs/eval/runs/2026-07-15-all-te-large-static/optimized-layout.json
```

Expected: render median is at least 2x faster than baseline and no force layout starts are recorded.

- [x] **Step 6: Browser visual checkpoint**

Open `preview.php`, select `All TE taxonomy`, switch to Graph, and capture a screenshot. Verify the Canvas is nonblank and the precomputed coordinates remain finite. Visual overlap is recorded for Task 7; do not re-enable force to conceal it.

## Task 4: Remove the Superlinear Tree-Source Coordinate Lookup

**Files:**

- Modify: `assets/js/renderers/g6/default-tree-mindmap.js`
- Modify: `scripts/checks/check_all_te_large_graph_contract.js` or create a focused static assertion in the same check

- [x] **Step 1: Add a failing source assertion**

The check must reject the repeated lookup pattern:

```js
assert(!mindmapSource.includes('getCurrentTreeElements().find('));
```

It must also require a single coordinate index such as `positionYById`.

- [x] **Step 2: Build the coordinate index in the first node pass**

Replace `getY().find()` with one map:

```js
const positionYById = new Map();
for (const item of getCurrentTreeElements()) {
  const data = item?.data;
  if (!data || data.source) continue;
  positionYById.set(data.id, Number(item?.position?.y) || 0);
  // existing nodes.set(...) logic remains here
}
const getY = (id) => positionYById.get(id) || 0;
```

Do not change parent selection, edge ordering, root detection, or taxonomy payload fields.

- [x] **Step 3: Run checks**

```powershell
node --check assets/js/renderers/g6/default-tree-mindmap.js
node scripts/checks/check_all_te_large_graph_contract.js --focus data-prep
python scripts/checks/check_taxonomy_runtime_truth.py
python scripts/checks/check_g6_te_tree_load_regression.py
```

Expected: the all-TE hierarchy, root, and lineage counts remain unchanged.

## Task 5: Eliminate Whole-Graph Draws from Frequent Pointer and Drag Events

**Files:**

- Modify: `assets/js/renderers/g6/large-force-graph/large-force-graph-core.js`
- Modify: `assets/js/renderers/g6/default-tree-mindmap.js`
- Modify: `assets/js/renderers/g6/large-force-graph/large-force-graph-styles.js`
- Test: `scripts/checks/check_all_te_large_graph_contract.js`

- [x] **Step 1: Preserve callbacks while removing `redraw()` from frequent handlers**

Pointer and drag handlers must update state and call callbacks only:

```js
graph.on(NodeEvent.POINTER_ENTER, (event) => {
  const nodeId = resolveEventNodeId(event);
  if (!nodeId || state.hoverNodeId === nodeId) return;
  state.hoverNodeId = nodeId;
  callbacks.onNodeHover?.(getNodeData(nodeId), { nodeId, client: event?.client || null });
});

graph.on(NodeEvent.POINTER_LEAVE, (event) => {
  const nodeId = resolveEventNodeId(event);
  if (!nodeId || state.hoverNodeId !== nodeId) return;
  state.hoverNodeId = null;
  callbacks.onNodeHover?.(null, { nodeId, client: event?.client || null });
});
```

Drag start/end may update `state.dragging`, but they must not call `draw()`, `render()`, layout, or destroy.

- [x] **Step 2: Build reusable indexes before binding interactions**

Build these maps once per accepted master/visible-data update, never inside a pointer handler:

```js
const nodeById = new Map();
const neighborsByNodeId = new Map();
const incidentEdgeIdsByNodeId = new Map();
const nodeIdsByLegendKey = new Map();
```

Populate adjacency in one pass over visible edges and legend membership in one pass over visible nodes. Store sets internally where duplicate prevention matters. Rebuild the indexes only when master data or legend visibility changes. Do not use `links.some(...)`, `edges.filter(...)`, `nodes.find(...)`, or equivalent full-collection scans inside hover, leave, click, or drag handlers.

- [x] **Step 3: Update only the locally affected element states**

Use the locally established G6 `setElementState` API, or a narrower verified `updateNodeData`/`updateEdgeData` path if the bundled version requires it. Hover enter/leave may touch only:

- the current and previously hovered node
- their direct neighbors
- their incident edges

Do not dim the entire graph to create focus. Track the previously touched ID sets so leave clears only those elements. The contract harness must use high-degree and low-degree fixtures and assert state-update call counts are bounded by `O(degree(current) + degree(previous))`, not total graph size.

- [x] **Step 4: Keep click selection lightweight**

The existing G6 `click-select` behavior remains responsible for visible selection. The custom click handler updates detail callbacks and state but does not globally draw. Selection must not trigger navigation automatically.

- [x] **Step 5: Add one reusable HTML tooltip owned by the taxonomy host**

`default-tree-mindmap.js` must create at most one tooltip under `#g6-default-tree-surface`, reuse it, clamp it to the host bounds, and remove/hide it during destroy/source/mode changes. Its content is plain text derived from `rawLabel/displayLabel` and taxonomy level; it must not use unsanitized `innerHTML`.

Required callback shape:

```js
onNodeHover(nodeData, context) {
  if (!nodeData) {
    hideTaxonomyTooltip();
    return;
  }
  showTaxonomyTooltip({
    label: nodeData.data?.rawLabel || nodeData.data?.displayLabel || nodeData.id,
    level: nodeData.data?.taxonomyLevelLabel || '',
    client: context?.client || null,
  });
}
```

- [x] **Step 6: Remove hover-dependent Canvas label work**

Deep labels remain hidden on Canvas by default. Full hover text lives in the HTML tooltip. Root/high-level/pinned labels and selected-node detail behavior remain unchanged.

- [x] **Step 7: Run contract and browser checks**

```powershell
node scripts/checks/check_all_te_large_graph_contract.js --focus interactions
python scripts/checks/check_all_te_large_graph_browser.py --focus hover-drag
```

Expected: hover and drag increment no full draw/layout/render/destroy counters; tooltip contains the full label and hides on leave; node/edge counts and instance identity remain unchanged.

## Task 6: Reuse the Renderer for Legend Visibility and Keep Source Boundaries Explicit

**Files:**

- Modify: `assets/js/renderers/g6/large-force-graph/large-force-graph-contract.js`
- Modify: `assets/js/renderers/g6/large-force-graph/large-force-graph-core.js`
- Modify: `assets/js/renderers/g6/large-force-graph/adapters/taxonomy-large-force-adapter.js`
- Modify: `assets/js/renderers/g6/default-tree-mindmap.js`
- Test: `scripts/checks/check_all_te_large_graph_contract.js`
- Test: `scripts/checks/check_all_te_large_graph_browser.py`

- [x] **Step 1: Write a failing pure filtering contract**

Add a helper that returns a new visible view without mutating master data:

```js
const view = contract.filterByLegend(masterData, {
  'level-0': true,
  'level-1': true,
  'level-5': false,
});
assert(view.nodes.every((node) => node.legendKeys.every((key) => key !== 'level-5')));
const ids = new Set(view.nodes.map((node) => node.id));
assert(view.edges.every((edge) => ids.has(edge.source) && ids.has(edge.target)));
```

- [x] **Step 2: Keep full adapter data and filter in the core**

The adapter must return complete normalized nodes/edges and full legend counts. The core derives `visibleData` from master data and legend state before converting to G6 data. This allows hidden levels to be restored without refetching or rebuilding taxonomy semantics.

- [x] **Step 3: Add stable diagnostics**

Expose a read-only diagnostic object from the renderer:

```js
getDiagnostics() {
  return {
    graphId: data.graphId,
    source: data.meta?.treeVariant || '',
    masterNodeCount: data.nodes.length,
    masterEdgeCount: data.edges.length,
    visibleNodeCount: visibleData.nodes.length,
    visibleEdgeCount: visibleData.edges.length,
    instanceId,
    counters: { ...counters },
    lastTimings: { ...lastTimings },
  };
}
```

Counters must include create, destroy, render, setData, draw, layoutStart, hover, click, dragStart, and dragEnd. Diagnostics are observational and must not change runtime behavior.

- [x] **Step 4: Prove local G6 `setData()` behavior before relying on it**

The browser check must update one legend level through the renderer, verify the Canvas remains nonblank, counts reconcile, and instance identity remains unchanged. If the local G6 runtime cannot preserve correct output through `setData()` plus one explicit draw, stop Task 6 and record the incompatibility in this plan and the tech-debt tracker. Do not create a second production renderer or patch ordinary Evidence Graph as a workaround.

- [x] **Step 5: Implement one-instance explicit updates**

For a supported local G6 API:

```js
async function applyVisibleData(nextVisibleData) {
  visibleData = nextVisibleData;
  graph.setData(toG6Data(visibleData));
  counters.setData += 1;
  await graph.draw();
  counters.draw += 1;
}
```

Legend visibility Apply may perform this single explicit data update/draw because it changes the visible graph. It is judged against the stable-interaction budget and must not start layout or create/destroy the graph.

Legend focus is different: use `nodeIdsByLegendKey` and targeted element-state updates only. It must not call `setData()`, full `draw()`, render, layout, create, or destroy, and its median must remain at most 50 ms. Clearing focus touches only the IDs recorded by the preceding focus operation.

Taxonomy source switching must destroy the old taxonomy renderer and create exactly one renderer for the new source. A genuine Tree/Graph mode change must likewise destroy the renderer owned by the mode being left. These are infrequent ownership changes, not optimization targets. The browser check must prove the old instance is destroyed, the new instance ID differs, only one instance is live, and no stale events or mixed-source data remain.

- [x] **Step 6: Exercise the complete roundtrip**

```powershell
python scripts/checks/check_all_te_large_graph_browser.py --focus legend-source-roundtrip
```

Required sequence: all Graph -> all Tree -> all Graph -> RMSK+Repbase Graph -> Tree -> Graph -> all Graph. Verify source, counts, loader, legend, instance identity/lifecycle, callback count, and nonblank Canvas after every transition.

## Task 7: Apply Lightweight Semantic Visual Encoding

**Files:**

- Modify: `assets/js/renderers/g6/large-force-graph/adapters/taxonomy-large-force-adapter.js`
- Modify: `assets/js/renderers/g6/large-force-graph/large-force-graph-styles.js`
- Modify only to consume verified visual metadata: `assets/js/renderers/g6/large-force-graph/large-force-graph-core.js`
- Modify only if necessary for tooltip styling: `assets/css/pages/preview.css`
- Test: `scripts/checks/check_all_te_large_graph_contract.js`
- Evidence: `docs/eval/runs/2026-07-15-all-te-large-static/desktop.png`

- [x] **Step 1: Derive deterministic branch identity in the adapter**

Each node must inherit the ID of its first top-level taxonomy branch. Add `clusterId`/`branchId` as display metadata only; do not change taxonomy IDs or hierarchy.

- [x] **Step 2: Replace the single-hue depth interpolation with a bounded branch palette**

Use a fixed accessible palette selected deterministically by branch index/hash. Depth changes size and opacity, while branch identity determines hue. Root remains visually unique. Do not add gradients, bokeh, animated particles, or a dark galaxy background to the Graph page.

- [x] **Step 3: Treat high taxonomy levels as semantic anchors**

Use the existing circle node type. Root/Class/Order anchors receive larger size, stronger stroke, and pinned labels. Family/Subfamily nodes remain small circles with no permanent label. Do not register a custom star node shape unless the measured render median remains within budget and a separate plan amendment is approved.

- [x] **Step 4: Keep links and labels cheap**

- Straight taxonomy edges only.
- No edge labels.
- No per-leaf shadow, blur, glow, arrow, or ripple.
- Halo only for selection/focus and only when it does not increase hover latency beyond 50 ms.
- Full deep-node labels remain in the HTML tooltip/detail panel.

- [x] **Step 5: Verify color and hierarchy semantics**

The contract check must assert same-branch nodes receive the same base hue token, different top-level branches can differ, root/high-level labels remain pinned, and deep labels remain hidden.

- [x] **Step 6: Capture screenshots and inspect them**

Capture `1440x960` and inspect for blank output, incoherent overlap, clipped controls, unreadable anchors, and tooltip overflow. The committed desktop screenshot must show the actual `All TE taxonomy` Graph, not a demo or mock payload.

## Task 8: Add Bounded Drag-Reheat Force and Remediate Preset Overlap

**Execution gate:** This task is required because the accepted preset screenshot and user review show unusable node and label overlap. Preserve the fast preset first paint, then add only the minimum bounded force lifecycle needed for natural drag response and collision relief.

**Files:**

- Modify: `assets/js/renderers/g6/large-force-graph/adapters/taxonomy-large-force-adapter.js`
- Modify: `assets/js/renderers/g6/large-force-graph/large-force-graph-layout.js`
- Modify: `assets/js/renderers/g6/large-force-graph/large-force-graph-core.js`
- Modify if needed for motion-only label suppression: `assets/js/renderers/g6/large-force-graph/large-force-graph-styles.js`
- Test: `scripts/checks/check_all_te_large_graph_contract.js`
- Test: `scripts/checks/check_all_te_large_graph_browser.py`
- Evidence: `docs/eval/runs/2026-07-15-all-te-large-static/README.md`

- [x] **Step 1: Quantify overlap and motion cost before changing code**

Record anchor-label collisions, coincident node centers, branch bounding-box intersections, drag frame timing, and post-drag CPU/layout activity from the deterministic preset output. Keep the current Task 5 performance evidence as the no-force reference.

- [x] **Step 2: Preserve deterministic preset startup and tune its anchors**

Adjust only bounded constants such as ring radius, branch angular span, sibling spacing, or depth separation so the initial frame is usable before force starts. Preserve finite positions, deterministic output, stable IDs, taxonomy hierarchy, and `large-static -> preset`. Re-run the performance harness after every accepted tuning batch.

- [x] **Step 3: Implement an explicit drag-only transient-force lifecycle**

Initial render remains preset with no force warmup. Node drag may reheat one owned simulation at a low bounded alpha; release must lower the target immediately and stop the simulation after a hard tick/time/alpha threshold. Hover, selection, legend visibility/focus, source changes, mode changes, and idle display must not reheat it. Reuse the existing renderer and graph instance; never create a parallel renderer or simulation per event.

- [x] **Step 4: Keep moving frames cheap**

During active motion, suppress nonessential deep labels and expensive visual effects while retaining root and semantic-anchor identity. Restore the accepted idle label policy after cooling. Coordinate updates must be batched and must not rebuild indexes, refetch taxonomy data, or recreate the renderer.

- [x] **Step 5: Prove cooling, overlap, and regression budgets**

On the production `all` payload, verify that drag visibly affects connected/global positions without freezing the page, release reaches a complete stop within `800 ms`, and no simulation/tick activity remains after the stop threshold. Require a material reduction in measured overlap from the Task 8 baseline, stable IDs/counts/instance identity, nonblank Canvas, no stale tooltip or selection, and no errors. Initial page-to-interactive must remain at most `800 ms`; if the local G6 force lifecycle cannot meet these gates, retain the verified preset runtime and record Task 8 as stopped rather than shipping continuous force.

## Task 9: Rebase All-TE Taxonomy on a Bounded Dynamic Multi-Star Runtime

### Task 9 Design Revision - 2026-07-18

This revision supersedes the previous Task 9 design only. Tasks 1-8 and their recorded evidence remain valid and must not be reverted. The retained assets are the normalized graph contract, deterministic IDs and positions, indexed interaction state, one-renderer lifecycle, explicit source boundaries, lightweight semantic styling, browser/performance harnesses, and bounded cooling ownership.

The previous Task 9 assumed that the default Graph should continue showing Subfamily nodes and that visible motion alone was sufficient proof of a useful transient force. Production inspection contradicted both assumptions. The accepted product direction now stops the initial graph at Family, allows multiple Families to expand cumulatively, and treats the taxonomy view as a dynamic graph with many separated structural star anchors. The current force behavior that pulls members toward shared Superfamily centers while preserving nearly all relative angles is a known failed state, not an acceptable intermediate result. Coordinate or Canvas changes without meaningful relative movement do not satisfy this revised task.

Do not create a second active plan for this revision. This section is the authoritative Task 9 implementation and acceptance contract.

**Files:**

- Modify: `assets/js/renderers/g6/default-tree-mindmap.js`
- Modify: `assets/js/renderers/g6/large-force-graph/adapters/taxonomy-large-force-adapter.js`
- Modify: `assets/js/renderers/g6/large-force-graph/large-force-graph-layout.js`
- Modify: `assets/js/renderers/g6/large-force-graph/large-force-graph-core.js`
- Modify if required for shared inspect-card presentation or sparse anchor identity: `assets/js/renderers/g6/large-force-graph/large-force-graph-styles.js`
- Modify: `scripts/checks/check_all_te_large_graph_contract.js`
- Modify: `scripts/checks/check_all_te_large_graph_browser.py`
- Modify: `scripts/checks/check_g6_taxonomy_graph_mode.py` if its old profile assertions conflict
- Test without unrelated edits: real `preview.php`
- Evidence: `docs/eval/runs/2026-07-15-all-te-large-static/README.md`
- Evidence: final Task 9 idle, expanded, and drag screenshots in `docs/eval/runs/2026-07-15-all-te-large-static/`

- [ ] **Step 1: Freeze the revised data, visibility, and lifecycle contract with failing tests**

Treat structural depth, never label text, as authoritative: depth 1 is `Class`, depth 2 is `Order`, depth 3 is `Superfamily`, depth 4 is `Family`, and depth 5 is `Subfamily`. The normalized master graph must retain every valid API node and edge, but the initial visible graph must contain only depths 0-4. Derive expected initial node and edge counts from the production API payload by depth instead of hard-coding a count that may vary by taxonomy source. Initial visibility of any depth-5-or-deeper node is a failure.

Maintain a renderer-owned set of expanded depth-4 Family IDs. Expansion is cumulative: expanding a second Family must preserve the first Family and its visible direct Subfamilies. An Expand operation may reveal only authoritative direct depth-5 children of that Family and their endpoint-valid parent edges. It must not reveal depth 6+, globally enable the Subfamily legend level, infer children from names, mutate API ancestry, refetch taxonomy data, start a second renderer, or introduce a second taxonomy truth source. Repeating the same Expand action must be idempotent. A Collapse action may remove one Family from the set without affecting other expanded Families.

Write RED Node and browser checks for the initial depth boundary, cumulative expansion, idempotency, endpoint integrity, renderer identity, source scoping, and the exact absence of global `render`, destroy, refetch, or unrelated layout work.

- [ ] **Step 2: Rebase the taxonomy runtime on the dynamic graph model without coupling it to Evidence Graph semantics**

Keep the taxonomy adapter and renderer boundary separate from the ordinary Evidence Graph renderer. Reuse the proven interaction model, inspect-card visual language, G6 force primitives, and bounded lifecycle concepts; do not route taxonomy edges through literature-evidence code, ordinary graph expansion APIs, or disease/relation semantics.

Use deterministic taxonomy coordinates as a nonblank warm start rather than as a permanently frozen final layout. The active taxonomy profile may run one owned, bounded dynamic settle and bounded interaction reheats; the existing `large-static -> preset` behavior remains an explicit fallback contract and must not silently become continuous force. No simulation may remain active after its accepted cooling deadline. The initial page must remain interactive within the existing `800 ms` budget on the reference machine.

- [ ] **Step 3: Define separated Class, Order, and Superfamily star anchors**

Derive star roles structurally: Class is the strongest regional anchor, Order is the next tier, and Superfamily is the local center for its Family system. Preserve the existing taxonomy branch palette; a literal yellow fill is neither required nor desired. Express anchor hierarchy with bounded size, stroke or halo weight, label priority, collision radius, and repulsion.

Give every star anchor its own finite deterministic equilibrium target. Foreign Superfamily anchors and their declared system bounds must not share coincident centers or collapse into one dense disc. Family nodes must retain their own deterministic orbital equilibrium positions around their Superfamily rather than using the Superfamily center as their X/Y target. At least `85%` of default-visible Family nodes must be spatially closest to their own Superfamily anchor. Record numerator, denominator, ratio, worst systems, cross-Superfamily overlap pairs, and penetration.

Use a bounded spatial index or capped anchor-level collision pass. Do not introduce an all-node pairwise loop. The final fitted graph must remain readable in the actually visible graph surface, including asymmetric clearance for an open Deep Think drawer. Automated overlap metrics do not override a visibly miniature, excessively sparse, clipped, or panel-obscured screenshot.

- [ ] **Step 4: Reuse the dynamic inspect-card language and add cumulative Family Expand**

Clicking a visible Family must select it and show a taxonomy inspect card using the ordinary dynamic graph's established visual language without importing its evidence semantics. The compact card must show:

- the Family display name
- `TE · degree N`, where degree is derived from normalized authoritative taxonomy edges
- the API description when present
- the complete structural ancestry from Human TE through the selected Family
- an `Expand` action when authoritative direct Subfamily children exist

Expand must update the existing Graph through one explicit data replacement and awaited draw path; it must not recreate the renderer, call global `render`, refetch taxonomy data, or start a permanent layout. Newly visible Subfamilies must begin at finite deterministic positions around their own Family. Multiple expanded Families must coexist, remain independently traceable, and survive unrelated hover, selection, and legend-focus actions. Expansion state is scoped to the current taxonomy source and Graph-mode session and must be cleared on taxonomy-source or Tree/Graph mode changes so stale IDs cannot cross source boundaries.

- [ ] **Step 5: Replace center contraction with meaningful bounded dynamic motion**

First preserve a reproducible browser case for the known failure: dragging one node causes non-dragged nodes to move toward shared centers while their pairwise structure changes negligibly. Then replace the force model at its source:

- star anchors retain separate equilibrium targets and stronger system-level repulsion
- a Family is attracted toward its own orbital equilibrium and taxonomy link, not directly to the Superfamily center
- an expanded Subfamily is attracted toward its own Family-local orbit
- dragging a member primarily reheats its owning Superfamily system
- dragging a star anchor may move its local system, while foreign systems remain softly constrained near their accepted equilibria
- collision and repulsion create visible local avoidance instead of uniform radial contraction

One drag owns at most one transient simulation. Every accepted tick must reach the visible G6 scene through the runtime's animation-frame update path. Release must begin cooling immediately, stop all layout activity within `800 ms`, restore the idle label policy, and produce zero coordinate or tick changes during a subsequent `500 ms` idle window. Do not call global `render`, recreate the Graph, rebuild indexes, refetch data, or create parallel simulations.

- [ ] **Step 6: Strengthen motion acceptance beyond frame counters**

A real mouse drag must produce at least three distinct non-target coordinate snapshots across separate animation frames and at least two consecutive intervals with both coordinate and identified Canvas-region pixel changes. In addition, require all of the following:

- at least two non-dragged nodes in the affected system change their pairwise distance or parent-relative radius by a visible, printed threshold
- the affected system records non-rigid relative movement rather than a uniform translation or scale
- the final median radial distance of non-dragged default-visible nodes remains at least `90%` of its pre-drag value, preventing whole-graph center collapse
- foreign star anchors remain within a printed bounded displacement budget
- own-Superfamily proximity and cross-system overlap gates remain valid after cooling

The harness must print the thresholds, measured values, affected system ID, sampled node IDs, frame count, Canvas-difference count, stop time, and idle delta. Counter-only motion, a single final jump, uniform contraction, or a visually unchanged relative layout is a failure.

- [ ] **Step 7: Keep frequent interactions and cumulative expansion lightweight**

Hover, leave, click selection, tooltip movement, inspect-card rendering, and legend focus must not call global draw, render, layout, destroy, or data refetch. One Family Expand or Collapse may perform exactly one owned `setData` plus one awaited draw on the existing Graph instance. It must rebuild only the indexes required by the new visible data, preserve unaffected selection/expansion state, and leave no stale callbacks or timers.

Exercise rapid re-drag, repeated Expand, two-Family cumulative Expand, collapse of one expanded Family, source change after expansion, and Tree/Graph roundtrip. Verify one live renderer, one callback per click, valid visible edge endpoints, exact restored counts, and zero mixed-source nodes.

- [ ] **Step 8: Complete visual, browser, and performance acceptance**

Run the focused RED/GREEN checks first:

```powershell
node scripts/checks/check_all_te_large_graph_contract.js --focus star-systems
node scripts/checks/check_all_te_large_graph_contract.js --focus transient-motion
python scripts/checks/check_all_te_large_graph_browser.py --focus initial
python scripts/checks/check_all_te_large_graph_browser.py --focus structural-star
python scripts/checks/check_all_te_large_graph_browser.py --focus family-expand
python scripts/checks/check_all_te_large_graph_browser.py --focus overlap-motion
```

Then run the complete Task 9 acceptance:

```powershell
python scripts/checks/check_all_te_large_graph_browser.py
python scripts/checks/measure_all_te_graph_performance.py --source all --warmups 1 --runs 5 --output docs/eval/runs/2026-07-15-all-te-large-static/optimized-phase9.json --assert-budget
python scripts/checks/check_g6_browser_smoke.py
```

Expected: PASS with API/master/default-visible/expanded counts, cumulative Family IDs, valid edge endpoints, one renderer instance, star-separation metrics, meaningful relative-motion metrics, Canvas frame differences, force stop time, idle stability, interaction counters, key timings, and evidence paths. Capture and inspect at least one default Family-only screenshot, one screenshot with two or more Families expanded, and one drag-motion frame sequence. Task 9 remains incomplete if the checks pass while the screenshot is still a dense shared disc, a miniature over-separated map, obscured by the open panel, or visibly contracting toward the center.

## Task 10: Independent Review and Full Verification

**Files:**

- No implementation edits by Reviewer or Verifier
- Modify after evidence is accepted: this plan, architecture/handoff docs listed above

- [ ] **Step 1: Reviewer audit**

Reviewer reports findings first and checks:

- `large-static` cannot fall back to force.
- The active taxonomy dynamic profile remains bounded and cannot leave background layout activity; `large-static` remains the explicit preset fallback.
- Adapter positions remain authoritative and finite.
- Class/Order/Superfamily star anchors remain structurally derived, deterministic, and separated into traceable descendant systems.
- Initial visibility stops at Family, while cumulative Family expansion exposes only authoritative direct Subfamilies and preserves one renderer.
- Real drag motion paints meaningful non-rigid intermediate frames rather than exposing only the final coordinates, rigid translation, or center contraction.
- Frequent interactions do not call global draw/render/layout/destroy.
- Legend/source reuse cannot retain stale data or duplicate events.
- Visible-edge endpoint integrity is preserved.
- No second taxonomy truth source was introduced.
- Ordinary Graph, iframe bridge, loader, export, relation legend, inspect, and expand code are unchanged or demonstrably unaffected.
- No co-expression work entered scope.
- Tests assert behavior rather than only source markers.

- [ ] **Step 2: Resolve all high- and medium-severity findings**

Worker fixes are assigned narrowly, then the Reviewer rechecks only the affected area plus its callers. Do not bundle visual polish into defect fixes.

- [ ] **Step 3: Verifier runs syntax and contract checks fresh**

```powershell
php -l preview.php
php -l api/taxonomy.php
node --check assets/js/renderers/g6/large-force-graph/large-force-graph-contract.js
node --check assets/js/renderers/g6/large-force-graph/large-force-graph-layout.js
node --check assets/js/renderers/g6/large-force-graph/large-force-graph-styles.js
node --check assets/js/renderers/g6/large-force-graph/large-force-graph-core.js
node --check assets/js/renderers/g6/large-force-graph/adapters/taxonomy-large-force-adapter.js
node --check assets/js/renderers/g6/default-tree-mindmap.js
node scripts/checks/check_large_force_graph_contract.js
node scripts/checks/check_all_te_large_graph_contract.js
```

- [ ] **Step 4: Verifier runs API and static regression checks**

```powershell
python scripts/checks/check_api_contracts.py
python scripts/checks/check_taxonomy_runtime_truth.py
python scripts/checks/check_no_legacy_db_fallback.py
python scripts/checks/check_g6_static_contract.py
python scripts/checks/check_g6_taxonomy_graph_mode.py
python scripts/checks/check_g6_relation_legend_expand_mode.py
python scripts/checks/check_g6_legend_expand_tree_fixes.py
python scripts/checks/check_g6_te_tree_load_regression.py
```

- [ ] **Step 5: Verifier runs all-TE browser and performance checks**

```powershell
python scripts/checks/check_all_te_large_graph_browser.py
python scripts/checks/measure_all_te_graph_performance.py --source all --warmups 2 --runs 5 --output docs/eval/runs/2026-07-15-all-te-large-static/optimized.json --assert-budget
```

- [ ] **Step 6: Verifier runs ordinary Evidence Graph regression checks**

```powershell
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_expand_disambiguation_smoke.py
python scripts/checks/check_g6_expand_mode_smoke.py
python scripts/checks/check_g6_inspect_card.py
```

If a listed script is absent, the Verifier records the exact missing path and the main AI corrects the command list before completion. A skipped browser or performance check prevents completion.

- [ ] **Step 7: Record final evidence**

Update `optimized.json`, the eval README, screenshots, and this plan's execution log with command results and deviations. Do not claim completion based on a previous Worker run.

## Acceptance Criteria

All criteria are mandatory:

1. Production `All TE taxonomy` uses the G6 bounded dynamic multi-star profile; deterministic coordinates provide its warm start and `large-static -> preset` remains the explicit fallback contract.
2. All expected taxonomy data is preserved; API counts, root, connectivity, IDs, and edge endpoints reconcile.
3. The Graph Canvas is nonblank by pixel sampling and screenshot inspection.
4. The default all-TE graph meets the deterministic and timing gates.
5. Hover, leave, and selection do not recreate, rerender, relayout, or globally redraw the graph. Drag may start only one accepted bounded transient-force lifecycle, must paint meaningful non-rigid relative movement without whole-graph center contraction, and must not recreate the renderer or leave background activity after cooling.
6. Legend focus/visibility keeps endpoint integrity and does not relayout/recreate.
7. Source and Tree/Graph roundtrips return exact semantic counts with no stale source or duplicate callbacks.
8. Exactly one live taxonomy renderer exists in Graph mode.
9. Root/Class/Order/Superfamily anchors remain readable, visually tiered, and spatially separated; at least `85%` of default-visible Family nodes are closest to their own Superfamily anchor, cross-Superfamily overlap and penetration satisfy the revised Task 9 gates, and deep labels remain sparse and accessible through tooltip/detail.
10. The initial graph contains no Subfamily-or-deeper node. Multiple Families can be expanded cumulatively through the taxonomy inspect card, revealing only their direct authoritative Subfamilies with valid endpoints and stable renderer identity.
11. Ordinary Evidence Graph browser, inspect, expand, legend, loader, bridge, export, and Back behavior pass their existing checks.
12. Taxonomy truth remains API-backed; no legacy DB or expression path regression is introduced.
13. Reviewer has no unresolved high- or medium-severity findings.
14. Verification evidence is recorded from the final integrated worktree.

## Stop and Rollback Conditions

Stop the current phase and return to the last verified phase if any occurs:

- blank or partially blank Canvas
- unexplained node/edge loss
- visible edges with hidden/missing endpoints
- mixed or stale source data
- more than one live taxonomy renderer
- duplicate event callbacks
- broken Tree/Graph roundtrip
- broken hover, selection, drag, legend, loader, node detail, or jump behavior
- any ordinary Evidence Graph/iframe/inspect/expand/export/Back regression
- new console errors, page errors, or unhandled rejections
- `large-static` force fallback
- optimized median slower than baseline by more than 10%
- taxonomy truth moved away from the approved API

Rollback must be scoped to the responsible phase. Never use `git reset --hard`, never revert unrelated user changes, and never discard later user work in shared files.

## Commit Strategy

The main AI may commit only when explicitly requested or when the active collaboration policy authorizes it. Recommended commit boundaries are:

1. `test: add all-TE G6 performance harness`
2. `perf: use preset layout for static taxonomy graph`
3. `perf: remove repeated taxonomy coordinate scans`
4. `perf: localize taxonomy graph interactions`
5. `perf: reuse taxonomy graph for legend updates`
6. `style: clarify all-TE taxonomy branches`
7. `test: cover all-TE graph browser performance`
8. `docs: record all-TE G6 verification`

Do not combine phases before their checkpoint passes.

## Execution Log

- 2026-07-15: Detailed execution plan created after read-only code investigation, local API measurement, Headless Chromium profiling, and comparison with `reference/external_examples/graph/NetworkEnhancedForceGraph.jsx`.
- 2026-07-15: Independent plan review resolved durability, task-ordering, legend focus, measurement-schema, Canvas-layer, and post-compaction read-scope findings before implementation.
- 2026-07-15: Task 1 completed without runtime edits. Added the performance harness, initial browser contract, baseline JSON, evaluation README, and inspected desktop screenshot. The accepted five-run baseline is API `1666/1665`, visible G6 `932/931`, adapter `8.8 ms`, render `1899.0 ms`, stable `2030.2 ms`, page-to-interactive `2224.7 ms`, hover/leave `31.5 ms`, legend focus `33.7 ms`, legend visibility Apply `435.8 ms`, and longest task `1952.0 ms`.
- 2026-07-15: Task 1 checks passed for Python compilation, large-force contract, initial All-TE browser contract, ordinary Evidence Graph browser smoke, Canvas pixels, API connectivity, and endpoint integrity. `check_taxonomy_runtime_truth.py` retained its pre-existing `index.php` homepage-helper failure; Task 1 did not edit `index.php` or any runtime file.
- 2026-07-15: Independent spec and code-quality reviews found no remaining high/medium issue after corrections. The final Verifier was stopped before starting commands at user request; main-AI fresh command evidence is recorded in the evaluation README and baseline JSON.
- 2026-07-15: Task 2 completed as a test-only red-contract phase. Added `check_all_te_large_graph_contract.js` with `layout`, `data-prep`, `interactions`, and aggregate focus modes; strengthened the existing contract check for `large-static` metadata and source-map immutability. Syntax, the legacy contract, and data preparation pass. Layout fails because `large-static` still returns `d3-force`; interactions fail because pointer enter/leave, drag start/end, and click produce five full-graph draws. These failures are the intended inputs to Task 3, not runtime regressions introduced by Task 2. The bounded Reviewer reported no findings and judged Task 2 unblocked.
- 2026-07-15: Task 3 accepted. The core now passes `performanceProfile`, `large-static` resolves to authoritative `preset`, explicit `taxonomy-force` retains the force helper, and static graphs use `drag-element`. The focused layout, legacy contract, data-prep, syntax, browser, and Canvas checks pass; the Task 5 interaction contract remains intentionally red at five draws. The five-run optimized evidence reports render `313.5 ms`, stable `481.8 ms`, page-to-interactive `756.8 ms`, longest task `383.0 ms`, and zero public layout calls, versus the `1899.0 ms` baseline render. The inspected screenshot is nonblank and records deterministic overlap for the later overlap gate. Explorer and final Reviewer found no blocking runtime issue; the compatibility force-helper alias remains a low-severity cleanup note.
- 2026-07-15: Task 4 accepted. A red/green `data-prep` source contract now rejects the repeated `getCurrentTreeElements().find()` coordinate scan and requires one `positionYById` index. `buildStrictTreeSource()` populates that map during its existing node pass and uses constant-time edge-coordinate lookup without changing root, parent, edge-order, or payload logic. Syntax, data-prep, layout, legacy contract, and the production All-TE browser checkpoint pass. `check_taxonomy_runtime_truth.py` retains the recorded `index.php` helper failure. `check_g6_te_tree_load_regression.py` consistently reaches a fresh page with loader hidden and Canvas present but times out at its later stale `mode === 'tree'` expectation because the current default is `taxonomy_graph`; Task 4 does not change mode selection. The Reviewer found no high/medium issue and judged Task 4 unblocked; the exact-source-string complexity guard remains a low-severity test limitation.
- 2026-07-15: Task 5 accepted after Explorer, Worker, main-AI correction, and Reviewer re-review. The renderer builds reusable node, neighbor, incident-edge, and legend indexes; hover updates only the current node, direct neighbors, and incident edges through merged `setElementState` batches. Pointer, drag, and custom click handlers make no global draw, render, layout, destroy, or data replacement calls. A generation guard and deferred-state contract prevent stale tooltip/state callbacks across rapid leave, replacement hover, render, and destroy. Deep Canvas hover labels were removed in favor of one raw-label-first, plain-text, host-bounded tooltip. JS interaction and production `hover-drag` browser contracts pass with stable `932/931` graph identity/counts and no errors. The five-run evidence reports render `265.5 ms`, stable `418.0 ms`, page-to-interactive `620.7 ms`, hover/leave `47.7 ms`, longest task `370.0 ms`, zero layout calls, and two remaining global draws from legend focus/clear only. Reviewer medium findings on async hover races and raw-label priority were fixed and re-reviewed as resolved.
- 2026-07-15: Task 6 accepted after two read-only Explorers, one Worker, main-AI review, independent verification, and a blocking Reviewer re-review. The adapter now retains its complete canonical input as master data, the core derives endpoint-safe visible views, and same-source visibility Apply performs one `setData()` plus one awaited draw without render/layout/create/destroy. Legend focus/clear use only recorded local element states, including focus -> Apply -> clear. Global lifecycle diagnostics prove zero live taxonomy renderers in Tree mode, exactly one in Graph mode, and destruction of every departed source/mode instance. The full all Graph -> Tree -> Graph -> RMSK+Repbase Graph -> Tree -> Graph -> all Graph browser sequence passes with correct source, counts, Canvas pixels, loader, callback count, and no errors. Five-run evidence reports adapter `10.1 ms`, render `247.6 ms`, stable `385.9 ms`, page-to-interactive `591.0 ms`, hover/leave `42.0 ms`, legend focus `40.0 ms`, visibility Apply `107.1 ms`, and longest task `281.0 ms`.
- 2026-07-15: Task 7 accepted after Explorer, Worker TDD, main-AI production wiring, screenshot inspection, and independent Reviewer re-review. Nodes now inherit deterministic top-level `branchId`/`clusterId` display metadata and one bounded branch color; the root is unique, hierarchy controls size/stroke/opacity, levels 0-2 retain pinned labels, and deeper labels remain tooltip-only. Neutral legend swatches continue to encode depth rather than falsely representing branch hue. Production G6 explicitly consumes semantic stroke widths and straight unlabeled edges. The required `large-force-graph-core.js` edit was added after review proved adapter-only width/curve fields were not reaching Canvas. Five-run evidence reports adapter `11.4 ms`, render `299.1 ms`, stable `453.8 ms`, page-to-interactive `683.6 ms`, hover/leave `46.4 ms`, legend focus `50.0 ms`, visibility Apply `133.0 ms`, and longest task `342.0 ms`; all budgets passed. The inspected `desktop.png` is nonblank and shows three distinguishable top-level branches, while the known preset overlap remains assigned to Task 8.
- 2026-07-16: Task 8 accepted after two read-only Explorers, a bounded Worker attempt, main-AI integration, real-browser mouse verification, and two Reviewer correction rounds. The deterministic preset now uses wider top-level separation and golden-angle local packing. Initial visible circle-overlap pairs fell from `4998` to `679` and total penetration from `30130.61` to `3502.27`; one transient drag reduced them further to `79` and `510.39`. Production keeps `large-static -> preset` at first paint, then uses one public G6 `d3-force` layout only during node drag. Release calls the owned public `stopLayout()` and restores preset in approximately `601-613 ms`; a following 500 ms idle window has zero coordinate/tick change. A real mouse drag moved the target approximately `89.6 px` without changing graph/renderer identity or `932/931` visible counts. Rapid re-drag cancels stale cooling and explicitly reheats one owned layout; epoch and graph guards prevent stale Promise/timer interference. Five-run evidence reports adapter `11.2 ms`, render `276.7 ms`, stable `431.2 ms`, page-to-interactive `658.0 ms`, hover/leave `40.6 ms`, legend focus `41.2 ms`, visibility Apply `129.5 ms`, and longest task `314.0 ms`; all budgets passed.
- 2026-07-18: Task 9 design revised without runtime implementation. The former default-Subfamily/static-star proposal is superseded by one bounded dynamic multi-star taxonomy profile. The revised contract stops initial visibility at Family, supports cumulative per-Family direct-Subfamily expansion through a taxonomy inspect card, preserves one renderer and API truth, separates Class/Order/Superfamily anchors, and rejects frame-only motion that merely contracts the graph toward shared centers. Tasks 1-8 and their evidence remain accepted inputs.
- Runtime implementation: Tasks 3-8 complete; revised Task 9 is next and has not started under this design revision.
- Baseline evidence files: created under `docs/eval/runs/2026-07-15-all-te-large-static/`.
- Deviations: public G6 `layout()`/initial `draw()` subphase timings remain `null` because the bundled runtime does not call those public methods during `render()`; aggregate render timing includes both. Task 6 now exposes exact wrapper layout-start and live-instance lifecycle counters.

## Residual Risks

- Local G6 `setData()` plus one awaited draw is proven for same-source large preset legend updates; this does not authorize changing ordinary dynamic Evidence Graph data paths.
- `taxonomyPayloadToElements()` currently canonicalizes six distinct `all` API labels into colliding slug IDs (`LTR12/LTR12_`, `MER34C/MER34C_`, `MER4A1/MER4A1_`, `LTR13/LTR13_`, `LTR3B/LTR3B_`, and `LTR33A/LTR33A_`). Therefore the API reports `1666/1665` while renderer master diagnostics report `1660/1659`. This predates Task 6 and is tracked separately; Task 6 retains all canonical elements it receives.
- Preset positions expose substantial visual overlap. Task 8 must preserve preset first paint while measuring deterministic spacing and a drag-only transient-force lifecycle; continuous or unmeasured force remains prohibited.
- Four Canvas layers are normal for the bundled G6 runtime; Canvas count alone is not a leak signal. Instance and callback counters are the lifecycle authority.
- Timing budgets are reference-machine budgets. Deterministic preset startup, no force outside the accepted drag lifecycle, complete post-drag cooling, and no non-drag global redraw are the portable correctness gates.
- Co-expression may reuse the renderer later, but its data contract and correlation-only presentation require a separate plan after all-TE completion.

## Completion Procedure

When every acceptance criterion has fresh evidence:

1. Record final Reviewer and Verifier results in this file.
2. Update `docs/architecture/graph_runtime.md` and `AI_HANDOFF.md` with accepted facts.
3. Update `docs/exec-plans/tech-debt-tracker.md` for unresolved risks only.
4. Move this file to `docs/exec-plans/completed/`.
5. Run `python scripts/checks/check_docs_freshness.py`.
6. Report any remaining risk without describing it as completed work.
