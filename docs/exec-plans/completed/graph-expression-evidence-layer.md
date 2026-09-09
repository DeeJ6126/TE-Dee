# Graph Expression Evidence Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development or executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an Expression Evidence Layer to the Graph page so TE nodes can be colored, inspected, and explained using existing expression summary data.

**Architecture:** Keep Graph as the main analysis surface. Add a small Graph-specific expression summary API backed by the existing expression repository, then let the G6 parent page fetch expression records for visible TE nodes and push a compact overlay map into the iframe runner. The iframe runner applies node visual changes and returns expression-aware node/edge details without changing graph topology.

**Tech Stack:** PHP, MySQL expression repository, browser JavaScript, G6 renderer, existing `preview.php` Graph shell.

---

## Scope

- Build scheme B only: **Expression Evidence Layer**.
- Do not modify Expression pages as peer integration pages.
- Do not create co-expression networks or machine-learning regulatory networks.
- Do not change Neo4j runtime target or taxonomy truth source.
- Do not alter Agent/DeepThink behavior.

## Files

- Create `api/graph_expression.php`: Graph-focused JSON API for batch TE expression summaries.
- Modify `preview.php`: add toolbar control and cache-bust the new CSS/JS changes.
- Modify `assets/css/pages/preview.css`: style expression layer controls and evidence panels.
- Modify `assets/js/renderers/g6/index-g6.bootstrap.js`: fetch expression summaries, manage layer state, update details.
- Modify `assets/js/renderers/g6/index-g6-embed.js`: expose a bridge method to update expression overlay state.
- Modify `assets/js/renderers/g6/index-g6-shared.js`: apply expression overlay styles and render expression-aware inspect/detail content.

## Tasks

### Task 1: Graph Expression API

- [x] Create `api/graph_expression.php`.
- [x] Accept `POST` JSON `{ "te_names": ["L1HS"], "context": "cancer_cell_line" }`.
- [x] Reuse `tekg_expression_fetch_detail_bundle()`.
- [x] Return one compact record per resolved TE:
  - `te_name`
  - `available`
  - `global`
  - `normal_tissue`
  - `normal_cell_line`
  - `cancer_cell_line`
  - `contexts_available`
- [x] Never report a failed MySQL lookup as biological absence; return `ok:false` only for API/runtime failure and per-TE `available:false` for missing expression rows.

### Task 2: Graph Toolbar Control

- [x] Add a toolbar `select` in `preview.php`:
  - Off
  - Global
  - Normal Tissue
  - Normal Cell Line
  - Cancer Cell Line
- [x] Keep the layer off by default.
- [x] Add a small status pill showing how many visible TE nodes have expression data.

### Task 3: Parent Graph State

- [x] In `index-g6.bootstrap.js`, collect visible TE names after graph load, answer graph render, legend filtering, and expansion.
- [x] Fetch expression summaries only when layer is not Off.
- [x] Cache by TE names and context to avoid repeated requests.
- [x] Push overlay state into iframe through `bridge.setExpressionOverlay(...)`.
- [x] Update the detail panel with expression evidence when a TE node is selected.

### Task 4: G6 Overlay Rendering

- [x] In `index-g6-shared.js`, store `currentExpressionOverlay`.
- [x] Add runner method `setExpressionOverlay(overlay)`.
- [x] For TE nodes with expression data:
  - recolor fill from low to high using a blue expression gradient;
  - strengthen stroke for the active context;
  - keep node size/layout stable.
- [x] For TE nodes without expression data:
  - keep topology visible but reduce opacity.
- [x] Keep non-TE node type colors intact, only slightly dim them while the expression layer is on.

### Task 5: Evidence Details

- [x] Node detail card: add `Expression Evidence` section for TE nodes.
- [x] Edge detail card: if one endpoint is a TE with expression data, add `Expression context for this relation`.
- [x] Explicitly state that expression is activity context, not causal proof.
- [x] Link to `expression_detail.php?te=<TE name>`.

### Task 6: Verification

- [x] Run syntax checks:
  - `php -l api/graph_expression.php`
  - `php -l preview.php`
  - `node --check assets/js/renderers/g6/index-g6.bootstrap.js`
  - `node --check assets/js/renderers/g6/index-g6-embed.js`
  - `node --check assets/js/renderers/g6/index-g6-shared.js`
- [x] If local WAMP/MySQL is available, call `api/graph_expression.php` for `L1HS`.
- [x] Browser smoke manually or with existing G6 check if runtime is available:
  - open Graph for `L1HS`;
  - turn on Cancer Cell Line layer;
  - confirm TE node color changes;
  - click L1HS and inspect expression detail;
  - click `L1HS associate with Cancer` edge and inspect expression context.

## Acceptance Criteria

- Expression data is visibly part of Graph, not a separate peer module.
- Graph topology does not change when the layer toggles.
- No expression statement is phrased as causality.
- Layer defaults to Off and does not slow initial graph load.
- Missing expression data is visually distinct from low expression.
- Existing Graph controls, legend filtering, Expand mode, and Deep Think back behavior remain intact.

## Verification Results

- `php -l api/graph_expression.php`: pass.
- `php -l preview.php`: pass.
- `node --check assets/js/renderers/g6/index-g6.bootstrap.js`: pass.
- `node --check assets/js/renderers/g6/index-g6-embed.js`: pass.
- `node --check assets/js/renderers/g6/index-g6-shared.js`: pass.
- `python scripts/checks/check_g6_static_contract.py`: pass.
- `python scripts/checks/check_no_legacy_db_fallback.py`: pass.
- `python scripts/checks/check_g6_browser_smoke.py`: pass for `http://127.0.0.1/TE-/preview.php?q=LINE1`.
- Live API check: `POST http://127.0.0.1/TE-/api/graph_expression.php` with `L1HS` returned expression summaries for normal tissue, normal cell line, and cancer cell line.
- Targeted browser check: `preview.php?q=L1HS`, switch Expression layer to `Cancer Cell Line`, status became `Cancer Cell Line - 1/2 TE`, graph bridge remained available with 54 nodes and 58 edges.
- Targeted inspect-card check: L1HS node card included cancer-cell-line expression context with median `16,897.33`.
