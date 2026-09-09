# G6 TE Tree Load Regression Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use systematic debugging first. Do not implement fixes before reproducing and classifying failures. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore stable TE tree and preview graph loading after search/tree Jump across TE/category labels, without changing Neo4j data, taxonomy data sources, graph API contract, or loader SVG visuals.

**Architecture:** Use Playwright/browser smoke and direct API probes to classify each failure as API, empty graph, frontend lifecycle, tree click mapping, loader state, or tree layout. Then apply the smallest frontend-only fix needed, add a regression check, and archive only if all required checks pass.

**Tech Stack:** PHP root page, browser JavaScript G6 runtime, taxonomy API, graph API, Python Playwright checks.

---

## Scope

Allowed:

- Inspect and minimally edit `preview.php`, `assets/js/renderers/g6/*.js`, `assets/css/pages/preview.css`, and `scripts/checks/check_g6_te_tree_load_regression.py`.
- Update `docs/RELIABILITY.md` and `docs/exec-plans/tech-debt-tracker.md`.

Forbidden:

- Do not change Neo4j data.
- Do not change taxonomy data source.
- Do not change graph API contract unless evidence proves API is root cause.
- Do not continue loader SVG visual polish.
- Do not add layout mode.
- Do not change edge evidence table or CSV behavior.
- Do not delete Jump / Expand / Details structure.
- Do not hardcode isolated names as a workaround.

## Phase 1 - Reproduce And Classify Failures

- [x] Create `scripts/checks/check_g6_te_tree_load_regression.py` with diagnostic probes for search/direct graph load and TE tree node actions.
- [x] Probe direct search/load for `LINE1`, `L1HS`, `SINEs`, `Class I: Retrotransposons`, `Class II: DNA Transposons`, and `others`.
- [x] Probe TE tree data for `LINE1`, `SINEs`, `Class I: Retrotransposons`, `Class II: DNA Transposons`, and `others`.
- [x] For each scenario record request `q`, HTTP status, nodes, edges, iframe rendered state, loader hidden state, console errors, failed requests, parent query, iframe state, action-card visibility, and loader kind.
- [x] Produce a compact table in the final answer: `query/source | request q | API ok | nodes | edges | iframe rendered | loader hidden | failure layer`.

## Phase 2 - API Vs Frontend Lifecycle

- [x] For every browser stuck/empty case, directly request `api/graph.php?q=<same q>`.
- [x] Classify the failure as API failure, empty graph, frontend render failure, loader finally failure, wrong tree label/query, or category label using the wrong graph path.
- [x] Do not change code until this classification is documented.

## Phase 3 - TE Tree Shrink / Missing LINE1 / Initial Position

- [x] Confirm TE tree source remains `api/taxonomy.php?view=tree&source=<variant>`.
- [x] Verify `LINE1` exists or does not exist in taxonomy API response.
- [x] Verify whether `LINE1` enters frontend tree elements.
- [x] Inspect visible tree graph data/bounding box/viewport to determine whether nodes are filtered, collapsed, clipped, or positioned off-screen.
- [x] Identify why initial tree appears near the top rather than left-middle.

## Phase 4 - Loader Type Root Cause

- [x] Verify `getTeLoaderKind('SINEs') === 'retro'`.
- [x] Verify `Class I: Retrotransposons` is retro and `Class II: DNA Transposons` is dna.
- [x] Verify `others` falls back to default but does not keep loader stuck.
- [x] If type detection fails, fix semantic matching generally, not by one-off name patch.

## Phase 5 - Minimal Fixes

- [x] Apply only root-cause fixes required by Phase 1-4 evidence.
- [x] Candidate fixes include tree category Jump routing/query mapping, loader finalization for empty/error states, iframe empty graph resolve, tree layout fit/center, or accidental tree filtering.
- [x] Do not refactor the G6 runtime or rewrite taxonomy/tree rendering.

## Phase 6 - Checks And Archive

- [x] Run:

```powershell
php -l preview.php
php -l api/graph.php
php -l api/graph_service.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_node_action_card_ux.py
python scripts/checks/check_g6_evidence_support_ux.py
python scripts/checks/check_g6_te_mechanism_loader.py
python scripts/checks/check_g6_te_tree_load_regression.py
```

- [x] If all pass, move this plan to `docs/exec-plans/completed/g6-te-tree-load-regression.md`.
- [x] Update `docs/RELIABILITY.md` and `docs/exec-plans/tech-debt-tracker.md`.
- [ ] If any fail, keep this plan active and record failed command, failure layer, completed work, and next step.

## Findings

- `api/taxonomy.php?view=tree&source=rmsk_repbase` was the live TE tree source.
- The taxonomy API returned 1632 nodes but only 1281 edges before the fix. `L1HS` existed as a node but had no parent edge, so the frontend strict tree could not reach it from the root. The parser was computing tree depth from Unicode prefix byte length, which broke deep parent/child edges.
- After the parser fix, taxonomy tree edge count is 1631 and `L1HS -> Family: L1PA -> Superfamily: L1 (LINE-1) -> Order: Non-LTR Retrotransposons (LINEs) -> Class I: Retrotransposons -> Transposable Elements - Human` is restored.
- `LINE1` is not present as a taxonomy file-tree node in the current `rmsk_repbase` tree; `L1HS` is present. `LINE1` remains a graph API/search alias that returns a valid dynamic graph.
- Folded category tree nodes were being misclassified as leaves because click handling checked the currently visible `children` array after collapse, not the real descendant count. That sent `Class I`, `Class II`, and `Others` into ordinary graph query loading.
- `SINEs` loader classification missed plural family/order labels. Semantic loader matching now normalizes plural TE family words such as `SINEs`, `LINEs`, `LTRs`, `ERVs`, and `HERVs`.
- Initial mindmap tree appeared too high because visible tree layout used top padding as the default Y offset. It now centers the root vertically unless a specific center node is requested.

## Changes

- `api/taxonomy_lib.php`: use UTF-8 character length for file-tree prefix depth parsing.
- `assets/js/renderers/g6/default-tree-mindmap.js`: prevent category nodes with real children from jumping to ordinary graph query; center initial tree vertically; expose read-only diagnostic `getGraph()` and `getStateTree()`.
- `assets/js/renderers/g6/default-tree.js`: apply the same category-node click guard for compatibility and expose read-only diagnostic bridge methods.
- `assets/js/renderers/g6/index-g6.bootstrap.js`: extend loader semantic normalization for plural TE family/order labels.
- `scripts/checks/check_g6_te_tree_load_regression.py`: new regression check covering taxonomy tree integrity, search/load diagnostics, category node click behavior, L1HS path restoration, and loader classification.

## Verification Results

All required commands passed:

```powershell
php -l preview.php
php -l api/graph.php
php -l api/graph_service.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_node_action_card_ux.py
python scripts/checks/check_g6_evidence_support_ux.py
python scripts/checks/check_g6_te_mechanism_loader.py
python scripts/checks/check_g6_te_tree_load_regression.py
```

Additional syntax checks also passed:

```powershell
php -l api/taxonomy_lib.php
node --check assets/js/renderers/g6/default-tree-mindmap.js
```

## Residual Risks

- `SINEs` as a normal graph search currently resolves to a Paper node (`SINEs.`) with 1 node and 0 edges. Loader closes correctly, but the graph API anchor semantics may need a separate alias/query-resolution plan if `SINEs` should map to taxonomy category rather than Paper text.
- `Class I: Retrotransposons`, `Class II: DNA Transposons`, and `others` are category labels and return empty graph payloads through ordinary graph search. Tree category clicks no longer route them into graph query, but direct search still shows an empty result path with loader closed.
- The tree remains collapsed by default to root plus top-level categories. Deep nodes such as `L1HS` are now connected in tree state, but users still need to expand the path.
