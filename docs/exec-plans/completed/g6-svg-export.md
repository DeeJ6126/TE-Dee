# G6 SVG Export Research Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to execute this research plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Decide whether TE-KG should implement SVG export for the current visible G6 subgraph, and define the minimal safe contract if it is worth implementing.

**Architecture:** This plan is research-only. It must not change runtime files, graph API files, agent code, or the existing CSV/PNG export logic. The output should be a decision record with evidence from the current browser runtime, local G6 bundle/reference, and existing TE-KG graph data shape.

**Tech Stack:** TE-KG `preview.php`, browser JS G6 runtime in `assets/js/renderers/g6/`, local `@antv/g6` vendor asset, Playwright smoke harness.

---

## Current Evidence

- Current visible graph export v1 is complete in `docs/exec-plans/completed/g6-subgraph-export.md`.
- Current PNG export uses iframe G6 runtime and returns a PNG data URL from `graph.toDataURL({ type: 'image/png' })` when available, otherwise from the iframe canvas `toDataURL('image/png')`.
- Current browser smoke evidence for G6 dynamic graph reports multiple `canvas` elements and zero `svg` elements inside the iframe container.
- Current UI exposes SVG only as disabled `SVG Soon`; this must remain disabled until a separate implementation plan is approved.

## Research Questions

### 1. 当前 G6 渲染是 canvas 还是 SVG？

Initial answer: current TE-KG dynamic G6 graph is canvas-based. Existing browser smoke reports `canvases > 0` and `svgs = 0` in the iframe graph container. Confirm this again with Playwright before any SVG implementation decision.

### 2. G6 官方是否提供可靠 SVG export API？

Initial answer: not confirmed. The local TE-KG runtime currently relies on PNG-oriented export behavior, and no TE-KG bridge exposes an SVG export method. Research must check the local `assets/vendor/g6/g6.min.js`, `reference/external_examples/G6`, and runtime graph object for explicit SVG export support. If the only confirmed API is raster image export, do not treat SVG as officially supported.

### 3. 如果没有官方 API，是否值得手写 SVG？

Initial answer: probably not for v1. Handwritten SVG would need to reproduce node shapes, colors, sizes, labels, edge paths, edge labels, current transform, filters, and expanded graph state. That risks creating a second renderer and diverging from G6. Only consider hand-written SVG if requirements accept a simplified data-diagram SVG rather than a faithful visual export.

### 4. SVG 应导出当前视口还是完整图？

Recommended contract: first SVG version should export the current visible subgraph data, but the visual bounds decision must be explicit:

- Current viewport SVG: matches what the user sees, easier to align with PNG semantics.
- Full graph SVG: better for publication/editing, but requires computing full graph bounds and may expose nodes outside current viewport.

Recommendation for first implementation, if approved: current viewport first, full graph as a later option.

### 5. SVG 是否需要包含 labels / edge labels / colors / sizes？

Recommended minimum, if approved:

- Include visible nodes and visible edges only.
- Preserve node colors and approximate node sizes.
- Include node labels according to current `Show names` state.
- Include edge colors/relation styling when available.
- Include edge labels only if current `Show labels` state is enabled.

Do not silently export extra hidden labels or filtered-out graph data.

### 6. 第一版 SVG 的验收标准是什么？

Minimum acceptance, if implementation is approved later:

- `Export > SVG` becomes enabled only when a dynamic graph is loaded.
- SVG export uses the same current visible subgraph source as CSV.
- SVG output is non-empty XML with `<svg>`, node shapes, edge paths/lines, and expected labels according to current UI state.
- Export respects legend entity filters, relation filters, and min PMID filters.
- SVG export does not change graph state, loader state, iframe URL, or Expand mode state.
- Existing checks remain green:
  - `node --check assets/js/renderers/g6/index-g6.bootstrap.js`
  - `node --check assets/js/renderers/g6/index-g6-shared.js`
  - `node --check assets/js/renderers/g6/index-g6-embed.js`
  - `python scripts/checks/check_g6_browser_smoke.py`
  - `python scripts/checks/check_g6_subgraph_export_smoke.py`
- Add or extend an SVG-specific smoke to verify disabled -> enabled behavior only after implementation is approved.

### 7. 如果实现成本过高，是否继续保持 SVG disabled？

Yes. If there is no reliable official SVG export API and faithful hand-written SVG would duplicate the renderer, keep `SVG Soon` disabled. The current v1 already provides CSV for data exchange and PNG for visual export.

## Tasks

### Task 1: Runtime Renderer Evidence

**Files:**
- Read: `assets/html/preview_g6_embed.html`
- Read: `assets/js/renderers/g6/index-g6-shared.js`
- Read: `assets/js/renderers/g6/index-g6-embed.js`
- No product file changes.

- [ ] **Step 1: Run a browser probe for `preview.php?q=LINE1`.**

Run:

```powershell
python scripts/checks/check_g6_browser_smoke.py
```

Expected: PASS and evidence continues to show canvas-backed iframe rendering.

- [ ] **Step 2: Inspect iframe DOM manually or with Playwright.**

Record:

```text
container canvas count
container svg count
renderer-related graph object methods
```

Expected: dynamic graph uses canvas, not SVG DOM.

### Task 2: Official/Local G6 API Evidence

**Files:**
- Read: `assets/vendor/g6/g6.min.js`
- Read: `reference/external_examples/G6`
- No product file changes.

- [ ] **Step 1: Search local vendor/reference for export APIs.**

Run:

```powershell
rg -n "toDataURL|downloadImage|downloadFullImage|svg|SVG|renderer" assets/vendor/g6 reference/external_examples/G6
```

Expected: collect exact hits and classify whether they support SVG export, raster export, renderer selection, or only internal SVG primitives.

- [ ] **Step 2: Probe runtime graph methods.**

Use Playwright page evaluation to list method names containing:

```text
svg
image
download
dataURL
toData
```

Expected: identify whether the loaded graph instance has a usable official SVG export method.

### Task 3: Contract Decision

**Files:**
- Update only this plan or create a completed research note if asked.
- No runtime changes.

- [ ] **Step 1: Choose one of three outcomes.**

Allowed outcomes:

```text
Outcome A: Official SVG export exists and is usable. Plan minimal bridge implementation.
Outcome B: No official SVG export, but simplified data-diagram SVG is acceptable. Plan a separate renderer with strict limited scope.
Outcome C: No reliable SVG path. Keep SVG Soon disabled and do not implement v2 now.
```

- [ ] **Step 2: Record the recommended SVG scope.**

If outcome A or B, record:

```text
current viewport or full graph
labels behavior
edge labels behavior
colors/sizes behavior
filter behavior
smoke checks
```

If outcome C, record why CSV/PNG v1 remains the supported export surface.

## Non-Goals

- Do not implement SVG in this research plan.
- Do not modify `api/graph.php` or `api/graph_service.php`.
- Do not modify `api/agent/`.
- Do not rewrite G6 runtime.
- Do not change CSV / PNG export data source or format.
- Do not change Expand mode logic.

## Verification for Research Closeout

Run after research-only edits:

```powershell
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_subgraph_export_smoke.py
```

Expected:

- Existing G6 graph still loads.
- Existing Export menu still shows SVG as disabled.
- CSV/PNG export smoke remains green.

---

## Execution Record - 2026-05-21

### Runtime Evidence

- Current loaded G6 version observed in browser probe: `5.1.1`.
- TE-KG iframe graph container renders with canvas elements and no SVG elements.
- Current iframe bridge exposes `exportPngDataUrl()` and `getVisibleSubgraph()`, but no SVG export method.
- Runtime `Graph.prototype` exposes `toDataURL`, `getData`, `getNodeData`, `getEdgeData`, `getCanvas`, `draw`, `render`, and `layout`.
- Runtime `Graph.prototype` does not expose `toSVG`, `downloadImage`, `downloadFullImage`, `export`, or `exportToFile`.

### Local G6 Reference Evidence

- `reference/external_examples/G6/packages/site/docs/api/export-image.en.md` documents `Graph.toDataURL(options)`.
- The documented browser export types are `image/png`, `image/jpeg`, and `image/webp`.
- The same document states that G6 5.0 only provides an API to export canvas as a Base64 image.
- `reference/external_examples/G6/packages/g6/src/runtime/graph.ts` implements browser `toDataURL()` by delegating to `this.context.canvas!.toDataURL(options)`.
- `reference/external_examples/G6/packages/g6-ssr/README.md` documents SVG export through the separate `@antv/g6-ssr` path with `outputType: 'svg'` and `exportToFile()`.
- The current TE-KG browser runtime does not load `@antv/g6-ssr`.

### Decision

Outcome C: no reliable official browser SVG export API exists in the current TE-KG G6 runtime.

SVG export should remain disabled as `SVG Soon` until a separate implementation plan is approved. CSV remains the supported data export surface, and PNG remains the supported visual export surface.

### Recommended Next Choice

Recommended: keep SVG disabled for now.

Alternative options for a later phase:

- Build a simplified data-diagram SVG from the current visible nodes and edges, with explicit acceptance that it is not a faithful G6 visual export.
- Investigate a dedicated export path such as `@antv/g6-ssr`, which would require dependency and architecture review.
