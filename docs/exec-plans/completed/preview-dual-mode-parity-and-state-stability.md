# Preview Dual-Mode Parity and State Stability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:subagent-driven-development` or
> `superpowers:executing-plans` to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking. Runtime edits remain owned and
> accepted by the main AI.

**Goal:** Resolve the ten reported Preview-page state, interaction, Loader,
legend, filtering, and DeepThink-layout defects without merging the Knowledge
Graph and Co-expression G6 instances or restoring any LINE1-to-L1HS fallback.

**Architecture:** Keep the two graph workspaces and their G6 instances
independent. Make the parent workspace coordinator the sole owner of the active
route and exact TE handoff, extract the existing TE-aware Loader into one shared
renderer, and add narrow Co-expression bridge methods for focus and lightweight
visibility updates. All asynchronous UI actions use explicit epochs,
latest-request-wins behavior, and `try/finally` recovery.

**Tech Stack:** PHP templates, browser JavaScript, G6 v5 Canvas, MySQL-backed
Co-expression API, Neo4j-backed Knowledge Graph API, Playwright browser checks,
Python/static harness checks.

---

## 1. Scope and Constraints

### In Scope

- Exact TE transfer in both directions between Knowledge Graph and
  Co-expression.
- Canonical URL/history behavior for Knowledge dynamic graphs, taxonomy trees,
  Co-expression TE selection, and context selection.
- Co-expression context change and search transaction stability.
- Knowledge-style click-to-focus behavior in the Co-expression legend.
- Clear definitions for Module hub and Relative TE expression.
- One shared, TE-aware Loader implementation used by both workspaces.
- Lightweight, bounded Co-expression filtering with reliable recovery.
- Correct default DeepThink drawer bounds on direct Co-expression refresh.
- Browser contracts reproducing all ten reported phenomena.

### Out of Scope

- Sharing one G6 instance between the two workspaces.
- Changing Neo4j, MySQL, Co-expression scientific results, module detection, or
  Expression values.
- Adding a LINE1-to-L1HS alias or any other fuzzy/cross-level TE mapping.
- Redesigning the force layout, node palette, details card, Agent/DeepThink
  reasoning, or mobile layout.
- Repairing unrelated legacy G6 marker checks or the existing `index.php`
  taxonomy-helper failure.

### Permanent Invariants

1. Knowledge Graph and Co-expression retain separate iframes, bridges, G6
   instances, graph data, caches, legends, details, and exports.
2. Cross-mode TE transfer is exact and case-insensitive after canonical catalog
   lookup. It must never infer `L1HS` from `LINE1`.
3. Taxonomy tree state with no exact TE selection enters Co-expression in the
   awaiting-selection state.
4. Co-expression remains MySQL/API-backed; the browser must not read display
   JSON, TSV, or manifests.
5. Expression remains TE-only and statistical. It does not alter correlation
   edges or force parameters.
6. Filtering, legend focus, context changes, and mode switches must not create a
   second iframe or G6 instance.
7. No automatic commits. Inspect `git status` and relevant diffs before every
   implementation task.

## 2. Investigation Record

Investigation was read-only. It used current source, the completed dual-mode
plan, and headless Chrome against `http://127.0.0.1/TE-/preview.php`.

| # | Classification | Confirmed root cause |
|---|---|---|
| 1 | Bug / over-correction | Clicking Co-expression calls `setMode()` without a TE. `activate()` therefore calls `awaitTeSelection()` even when the Knowledge Graph has an exact catalog TE such as L1HS. The earlier no-fallback rule removed all transfer instead of only removing unsafe inference. |
| 2 | Missing parity | Knowledge legend rows have click handlers and `setLegendFocus()`. Co-expression exposes only checkbox changes and Apply; its rows have no focus metadata/listener and its iframe bridge does not expose legend focus. |
| 3 | Communication gap | Both encodings are implemented, but the legend gives no definition. Module hubs are top-ranked features by weighted within-module degree; Relative TE expression is log-normalized within the visible TE set for the selected context. Neither means causality. |
| 4 | Missing shared component | Knowledge owns private `getTeLoaderKind()` / SVG rendering and has `#te-mechanism-loader-slot`. Co-expression has only the generic two-circle Loader and no mechanism slot. |
| 5 | Real stability bug | Apply calls `setViewOptions()`, which calls full `renderNetwork()` and restarts the complete G6 render. Apply stays enabled until the await finishes, the listener drops the returned promise, and there is no `try/catch/finally`. A rejection or overlapping render can leave state at `rendering`. A single LTR5 Apply took about 3.6 seconds in the investigation. |
| 6 | Real layout bug | `preview-shell.js#getAssistantBounds()` uses the first `.preview-g6-surface-stack`, even when it belongs to the hidden Knowledge workspace. A direct Co-expression URL produced a zero-size bound and clamped the drawer to 280x280 at the upper left. |
| 7 | Real event bug | The context `<select>` has no change listener. Its visible value changes, but `activate()`, the network, renderer count, selection, and URL do not. |
| 8 | Real handoff bug | Switching to Knowledge only deactivates Co-expression and resizes the already-existing Knowledge workspace. It never loads the active Co-expression TE, so a fresh page reveals the initial taxonomy tree. |
| 9 | Real URL ownership gap | In-page Knowledge searches call `loadDynamicGraph()` but never update the parent browser URL. The coordinator only edits mode/TE/context, so a successfully rendered L1HS graph can still have `preview.php` with no `q`. |
| 10 | Real transaction/UX bug | The Co-expression route is updated only after `await activate()`. During a measured L1HS-to-LTR5 change the URL remained L1HS for about six seconds. Rendering is serialized behind previous work with no bounded recovery, so a slow/stuck filter render can also delay the next TE indefinitely. |

## 3. Canonical Navigation Contract

The coordinator in `preview-workspace-mode.js` becomes the only browser-history
writer for Preview graph navigation.

### Canonical Routes

```text
Knowledge dynamic TE:
preview.php?q=L1HS&type=TE

Knowledge taxonomy:
preview.php?tree=rmsk_repbase

Co-expression:
preview.php?mode=coexpression&te=L1HS&context=cancer_cell_line
```

- Co-expression routes do not retain stale `q`, `type`, `class`, or `tree`
  parameters.
- Knowledge routes do not retain stale `mode`, `te`, or `context` parameters.
- A requested unavailable Co-expression context remains in the URL so refresh
  reproduces the explicit unavailable state.
- Each explicit user search, context change, taxonomy-source change, or
  workspace switch produces at most one `pushState`.
- Loader updates, legend focus, filter Apply, internal redraws, and diagnostics
  produce no history entry.
- `popstate` restores the route without writing new history.

### Exact TE Handoff

Knowledge to Co-expression:

1. Read `__TEKG_G6_BRIDGE.getState()`.
2. Require dynamic mode and TE query type.
3. Resolve the query case-insensitively against the Co-expression catalog.
4. Exact match: activate that canonical TE and the retained valid context or
   catalog default.
5. TE query without catalog match: preserve its text and show explicit
   unavailable state; never substitute another TE.
6. Taxonomy/non-TE/no-query state: show awaiting selection.

Co-expression to Knowledge:

1. Read the current stable or requested Co-expression selection.
2. If it has a TE, show Knowledge and call
   `__TEKG_G6_BRIDGE.loadGraph({query: te, queryType: 'TE'})`.
3. Keep the Knowledge Loader visible until the actual graph is ready.
4. If no TE is selected, restore the retained Knowledge state.

## 4. File Map

### Create

- `assets/js/pages/preview/te-loader.js`
  - Pure TE Loader classification and shared DOM rendering.
- `scripts/checks/check_preview_dual_mode_navigation_browser.py`
  - Exact handoff, route, context, Back/Forward, latest-request-wins checks.
- `scripts/checks/check_coexpression_legend_filter_loader_browser.py`
  - Legend focus, semantic help, shared Loader, filter latency/recovery checks.
- `scripts/checks/check_preview_assistant_workspace_bounds.py`
  - Direct-route and mode-roundtrip DeepThink drawer bounds.
- `scripts/checks/check_preview_dual_mode_parity_static.py`
  - Static ownership, event, shared Loader, and prohibited fallback contracts.

### Modify

- `preview.php`
  - Load the shared Loader before both graph controllers and include its mtime.
- `templates/preview/knowledge_graph_workspace.php`
  - Keep the existing Loader root/slot contract.
- `templates/preview/coexpression_workspace.php`
  - Add the same mechanism Loader slot and legend focus/help semantics.
- `assets/js/pages/preview/te-loader.js`
  - Shared classification/SVG implementation.
- `assets/js/renderers/g6/index-g6.bootstrap.js`
  - Consume the shared Loader and emit explicit navigation events.
- `assets/js/pages/preview/preview-workspace-mode.js`
  - Own canonical routes, exact TE handoff, history, and transition epochs.
- `assets/js/pages/preview/coexpression-mode.js`
  - Context/search transaction flow, optimistic selection, filter recovery,
    focus wiring, and diagnostics.
- `assets/js/renderers/g6/coexpression/coexpression-embed.js`
  - Lightweight visibility and legend-focus bridge methods.
- `assets/js/renderers/g6/coexpression/coexpression-renderer.js`
  - Co-expression focus predicates and batched element visibility support.
- `assets/js/pages/preview/preview-shell.js`
  - Measure the visible workspace and reclamp on workspace changes.
- `assets/css/pages/preview.css`
  - Shared Loader slot state, legend focus/help states, and stable busy controls.
- Existing Task 7/8/9 browser and static checks
  - Preserve earlier isolation, Expression, export, cache, retry, and viewport
    acceptance.
- `docs/coexpression/frontend_contract.md`
- `docs/architecture/graph_runtime.md`
- `AI_HANDOFF.md`
- This plan's execution log.

## 5. Implementation Tasks

### Task 1: Lock the Ten Regressions Before Runtime Changes

**Files:**

- Create: `scripts/checks/check_preview_dual_mode_navigation_browser.py`
- Create: `scripts/checks/check_coexpression_legend_filter_loader_browser.py`
- Create: `scripts/checks/check_preview_assistant_workspace_bounds.py`
- Create: `scripts/checks/check_preview_dual_mode_parity_static.py`

- [ ] Add a navigation scenario that loads L1HS in Knowledge, clicks
      Co-expression, and expects the Co-expression input/selection to remain
      L1HS without a second search.
- [ ] Add a negative exact-match scenario: LINE1 must never become L1HS.
- [ ] Add a taxonomy scenario that expects awaiting selection rather than an
      implicit TE.
- [ ] Add a Co-expression LTR5-to-Knowledge scenario that expects a dynamic
      LTR5 evidence graph, not the taxonomy tree.
- [ ] Add a Knowledge in-page L1HS search assertion for `q=L1HS&type=TE`.
- [ ] Add a context-change scenario requiring URL, selected context, API
      request, Expression context, and renderer data to change.
- [ ] Add an L1HS-to-LTR5 search scenario requiring URL/input/loading state to
      show LTR5 within 100 ms and the latest selection to win.
- [ ] Add Back/Forward across Knowledge L1HS, Co-expression L1HS, context
      change, Co-expression LTR5, and Knowledge LTR5.
- [ ] Add direct Co-expression refresh drawer bounds at 1440x960 and 1024x768.
- [ ] Add legend row focus and filter Apply scenarios.
- [ ] Add injected filter rejection and rapid double-Apply scenarios; neither
      may leave the Loader visible or state at `rendering`.
- [ ] Run the new checks and record the expected failures against current code.

**Acceptance:**

- Every reported phenomenon has one deterministic red assertion.
- Tests use conditions/diagnostics rather than arbitrary long sleeps.
- Test failures identify navigation, Loader, legend, filter, or assistant bounds
  separately.

**Time box:** 35-50 minutes.

### Task 2: Establish One Canonical Route and Exact TE Handoff

**Files:**

- Modify: `assets/js/pages/preview/preview-workspace-mode.js`
- Modify: `assets/js/renderers/g6/index-g6.bootstrap.js`
- Modify: `assets/js/pages/preview/coexpression-mode.js`
- Test: `scripts/checks/check_preview_dual_mode_navigation_browser.py`
- Test: existing Task 7/9 browser checks

- [ ] Add a stable `tekg:g6-navigation` event with normalized Knowledge mode,
      query, query type, class query, tree variant, and history action.
- [ ] Emit that event only for actual graph navigation, not legend/filter/view
      redraws.
- [ ] Replace scattered route writes with coordinator helpers:
      `routeForKnowledge()`, `routeForCoexpression()`, and
      `writeRoute(action, state)`.
- [ ] Add `coexpressionMode.resolveExactTe(name)` backed by the already-loaded
      catalog; return a canonical match or `null`, never a fallback.
- [ ] Add Knowledge-to-Co-expression handoff using the rules in Section 3.
- [ ] Add Co-expression-to-Knowledge handoff through
      `__TEKG_G6_BRIDGE.loadGraph({query: te, queryType: 'TE'})`.
- [ ] Make route writes optimistic: write the requested TE/context when the user
      action is accepted, before network/expression/render awaits.
- [ ] Guard all mode transitions with the existing transition epoch so late
      completions cannot overwrite the newer route or active workspace.
- [ ] Restore canonical routes on `popstate` without adding history.
- [ ] Preserve exactly one iframe and G6 instance per initialized workspace.

**Acceptance:**

- Issues 1, 8, and 9 are fixed.
- The route visibly follows issue 10's new TE immediately.
- LINE1 never resolves to L1HS.
- Knowledge and Co-expression graph data remain isolated.

**Time box:** 45-65 minutes.

### Task 3: Make Context and TE Search One Recoverable Transaction

**Files:**

- Modify: `assets/js/pages/preview/coexpression-mode.js`
- Modify: `assets/js/pages/preview/preview-workspace-mode.js`
- Modify: `assets/js/renderers/g6/coexpression/coexpression-embed.js`
- Test: `scripts/checks/check_preview_dual_mode_navigation_browser.py`

- [ ] Add a context `change` listener that submits the current canonical TE and
      selected context through the coordinator.
- [ ] Route Search click and Enter through the same
      `requestCoexpressionSelection({te, context, history})` path.
- [ ] Update input, `currentSelection`, context options, URL, and Loader label
      synchronously before awaiting API/render work.
- [ ] Separate request stages in diagnostics:
      `loading-network`, `loading-expression`, `loading-iframe`, `rendering`.
- [ ] Coalesce queued renders so only the latest pending selection runs after an
      active non-cancellable G6 render.
- [ ] Pass a request generation into the iframe and ignore stale reports.
- [ ] Bound an individual render wait to 10 seconds; on timeout stop its layout,
      expose Retry, clear the Loader, and retain the requested TE/context.
- [ ] Use `try/catch/finally` in DOM handlers so rejected promises never become
      unhandled and busy controls always recover.
- [ ] Keep cached network/expression responses version-bound and separate.

**Acceptance:**

- Issues 7 and 10 are fixed.
- Context changes alter the network and Expression context.
- A newer TE/context always wins over a slower older request.
- URL/input state never falsely continues to display the prior TE during load.
- Failure leaves a usable Retry path rather than a permanent Loader.

**Time box:** 40-60 minutes.

### Task 4: Extract and Reuse the Exact Knowledge Loader

**Files:**

- Create: `assets/js/pages/preview/te-loader.js`
- Modify: `preview.php`
- Modify: `templates/preview/coexpression_workspace.php`
- Modify: `assets/js/renderers/g6/index-g6.bootstrap.js`
- Modify: `assets/js/pages/preview/coexpression-mode.js`
- Modify: `assets/css/pages/preview.css`
- Test: `scripts/checks/check_coexpression_legend_filter_loader_browser.py`
- Test: `scripts/checks/check_preview_dual_mode_parity_static.py`

- [ ] Move the current normalization, semantic classification, truncation, SVG
      construction, and DOM class logic from Knowledge bootstrap into
      `window.__TEKG_TE_LOADER`.
- [ ] Expose:

```javascript
classify(nodeOrQuery)
show({ overlay, slot, label, nodeOrQuery })
hide({ overlay, slot })
```

- [ ] Keep one source for retro/DNA/default classification and one source for
      the mechanism SVG.
- [ ] Add `#coexpression-mechanism-loader-slot` with the same CSS dimensions and
      animation classes as the Knowledge slot.
- [ ] Make both modes pass the same TE name to the shared helper.
- [ ] Clear Loader DOM/classes in `finally` on success, cancellation, error, and
      mode switch.
- [ ] Include the new file in `$previewVersion`.

**Acceptance:**

- Issue 4 is fixed.
- The same TE produces the same Loader kind, SVG structure, label, timing, and
  colors in both workspaces.
- No duplicate TE-classification rule remains in Co-expression.
- Existing Knowledge Loader checks still pass.

**Time box:** 35-50 minutes.

### Task 5: Add Click-to-Focus and Explain the Scientific Encodings

**Files:**

- Modify: `templates/preview/coexpression_workspace.php`
- Modify: `assets/js/pages/preview/coexpression-mode.js`
- Modify: `assets/js/renderers/g6/coexpression/coexpression-embed.js`
- Modify: `assets/js/renderers/g6/coexpression/coexpression-renderer.js`
- Modify: `assets/css/pages/preview.css`
- Test: `scripts/checks/check_coexpression_legend_filter_loader_browser.py`

- [ ] Add highlight metadata and pressed state to TE, Gene, Module hub, and
      Relative TE expression legend rows.
- [ ] Keep checkbox clicks dedicated to pending visibility. Clicking the
      remainder of a row toggles focus/dimming immediately without Apply.
- [ ] Expose `setLegendFocus()` through the Co-expression iframe bridge.
- [ ] Extend renderer focus predicates:
      - TE/Gene: node feature type;
      - Module hub: `coexpressionIsModuleHub === true`;
      - Relative TE expression: TE with `expressionAvailable === true`.
- [ ] Clicking the active focus again or clicking Canvas clears focus.
- [ ] Add concise accessible hover/focus help:

```text
Module hub
One of the top 5% of features by weighted within-module degree
(at least one hub in an eligible module of size 3 or more).

Relative TE expression
Median TE expression for the selected context, log-normalized against
the other visible TEs. It does not encode correlation or causality.
```

- [ ] Repeat the same boundary in node details where applicable.

**Acceptance:**

- Issues 2 and 3 are fixed.
- Legend focus matches Knowledge behavior without filtering or layout restart.
- Help text accurately describes the current offline method and Expression
  rendering.
- Gene nodes never appear as Relative TE expression.

**Time box:** 35-50 minutes.

### Task 6: Replace Full Filter Renders with Lightweight Visibility

**Files:**

- Modify: `assets/js/pages/preview/coexpression-mode.js`
- Modify: `assets/js/renderers/g6/coexpression/coexpression-embed.js`
- Modify: `assets/js/renderers/g6/coexpression/coexpression-renderer.js`
- Modify: `assets/css/pages/preview.css`
- Test: `scripts/checks/check_coexpression_legend_filter_loader_browser.py`
- Test: existing Task 8/9 browser checks

- [ ] Add a runner method that applies G6
      `setElementVisibility(id, visible)` to the current nodes and edges.
- [ ] Compute visible node IDs and endpoint-safe edge IDs from the unchanged
      normalized network.
- [ ] Always retain the center TE, even when partner TE visibility is off.
- [ ] Apply Center edges without rebuilding graph data or rerunning force.
- [ ] Keep hidden nodes/edges out of visible export and diagnostics.
- [ ] Disable Apply and set `aria-busy=true` before the first await.
- [ ] Use one filter epoch and `try/catch/finally`; repeated Apply while busy
      becomes a no-op rather than another render.
- [ ] On failure, restore the previous visibility state, clear busy state, keep
      the graph usable, and show a nonblocking error in details.
- [ ] Do not show the full graph Loader for a visibility-only operation.

**Acceptance:**

- Issue 5 is fixed.
- Apply completes within 250 ms for L1HS and LTR5 on the reference machine.
- `renderCount`, graph identity, coordinates, and force state do not change.
- Rapid double Apply and injected rejection cannot leave a stuck state.
- No visible edge has a hidden/missing endpoint.

**Time box:** 40-55 minutes.

### Task 7: Anchor DeepThink to the Visible Workspace

**Files:**

- Modify: `assets/js/pages/preview/preview-shell.js`
- Test: `scripts/checks/check_preview_assistant_workspace_bounds.py`

- [ ] Replace the first-match surface query with the visible workspace's
      `.preview-g6-surface-stack`.
- [ ] Fall back to stage bounds only when neither workspace has a measurable
      surface.
- [ ] On `tekg:preview-workspace-mode-change`, recompute drawer and FAB bounds in
      the next animation frame.
- [ ] Preserve user drag/resize dimensions when still valid; clamp only values
      outside the new visible surface.
- [ ] Verify direct Knowledge, direct Co-expression, refresh, mode roundtrip,
      and fullscreen exit.

**Acceptance:**

- Issue 6 is fixed.
- At 1440x960 the default drawer is approximately 440 px wide and anchored to
  the right of the visible graph, never 280x280 at the upper left.
- At 1024x768 it remains within the visible graph without overlapping toolbar
  controls.
- Agent/DeepThink request and answer behavior is unchanged.

**Time box:** 20-30 minutes.

### Task 8: Integrated Verification and Durable Handoff

**Files:**

- Modify: `docs/coexpression/frontend_contract.md`
- Modify: `docs/architecture/graph_runtime.md`
- Modify: `AI_HANDOFF.md`
- Modify: this plan
- Create: dated evidence under
  `docs/eval/runs/<date>-preview-dual-mode-stability/`

- [ ] Run every new static/browser check.
- [ ] Run existing Co-expression Task 6-9 checks.
- [ ] Run ordinary Knowledge API, tree, browser, legend, inspect, export, and
      Loader checks.
- [ ] Inspect 1440x960, 1280x900, and 1024x768 screenshots.
- [ ] Capture route/state timelines for L1HS-to-LTR5 and context changes.
- [ ] Capture filter latency, render count, graph identity, Loader state, and
      endpoint integrity.
- [ ] Verify exact LINE1 negative behavior.
- [ ] Run one independent read-only review if explicitly authorized; the main AI
      verifies and resolves findings.
- [ ] Record unrelated legacy failures separately.
- [ ] Move this plan to `docs/exec-plans/completed/` only after acceptance.

**Acceptance:**

- All ten reported phenomena have passing regression evidence.
- No unresolved Critical, High, or Medium finding.
- No stuck Loader, blank Canvas, duplicate iframe, duplicate G6 instance,
  implicit TE alias, stale route, or hidden-workspace assistant bounds.
- Durable docs describe the actual route and handoff contract.

**Time box:** 35-50 minutes after implementation.

## 6. Verification Commands

```powershell
php -l preview.php
php -l templates/preview/knowledge_graph_workspace.php
php -l templates/preview/coexpression_workspace.php

node --check assets/js/pages/preview/te-loader.js
node --check assets/js/pages/preview/preview-workspace-mode.js
node --check assets/js/pages/preview/coexpression-mode.js
node --check assets/js/pages/preview/preview-shell.js
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/coexpression/coexpression-renderer.js
node --check assets/js/renderers/g6/coexpression/coexpression-embed.js

python scripts/checks/check_preview_dual_mode_parity_static.py
python scripts/checks/check_preview_dual_mode_navigation_browser.py
python scripts/checks/check_coexpression_legend_filter_loader_browser.py
python scripts/checks/check_preview_assistant_workspace_bounds.py

python scripts/checks/check_coexpression_task6_static.py
python scripts/checks/check_coexpression_task7_static.py
python scripts/checks/check_coexpression_task7_browser.py
python scripts/checks/check_coexpression_task8_static.py
python scripts/checks/check_coexpression_task8_browser.py
python scripts/checks/check_coexpression_task9_static.py
python scripts/checks/check_coexpression_task9_browser.py
python scripts/checks/check_coexpression_graph_browser.py

python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_te_tree_load_regression.py
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_inspect_card.py
python scripts/checks/check_g6_subgraph_export_smoke.py
python scripts/checks/check_taxonomy_tree_only_runtime.py
python scripts/checks/check_no_legacy_db_fallback.py
python scripts/checks/check_docs_freshness.py

git diff --check
```

Known unrelated checks must still be run and recorded but are not repaired
inside this plan:

```powershell
python scripts/checks/check_g6_relation_legend_expand_mode.py
python scripts/checks/check_g6_legend_expand_tree_fixes.py
python scripts/checks/check_taxonomy_runtime_truth.py
```

## 7. Stop Conditions

Stop the active task and return to the last accepted checkpoint if:

- LINE1 or another nonmatching query becomes L1HS;
- a mode switch creates/replaces a G6 instance unexpectedly;
- Knowledge graph data appears in Co-expression or vice versa;
- Back/Forward writes a new history entry or enters a loop;
- a filter operation restarts force or changes coordinates;
- the center TE becomes hidden;
- a late request replaces a newer TE/context/route;
- either Loader remains visible after success, error, cancellation, or timeout;
- DeepThink functionality changes beyond window bounds;
- a runtime browser/API request reads Co-expression display JSON/TSV/manifests;
- console errors, unhandled rejections, blank Canvas, or missing edge endpoints
  occur.

## 8. Execution Governance

- Execute one task at a time with a user-visible checkpoint.
- The main AI owns runtime edits and final acceptance.
- Default to no Worker. A read-only Explorer or Reviewer requires explicit user
  approval for that task.
- Start every task with `git status --short` and relevant diffs.
- Write failing regression evidence before runtime changes.
- Do not weaken an assertion merely to make a failing check green.
- Record measured deviations and durable decisions in the execution log.
- Do not move this plan to completed until all ten regressions pass.

## 9. Execution Log

- 2026-07-25: The user reported ten Preview-page phenomena after Tasks 8-10.
  The main AI performed read-only source tracing and headless-Chrome
  reproduction. No runtime file was modified during the investigation.
- 2026-07-25: Confirmed measurements included: exact L1HS was cleared on
  Knowledge-to-Co-expression switch; context selection changed only the DOM;
  Co-expression LTR5 returned to an empty taxonomy tree; an in-page Knowledge
  L1HS search left the URL at `preview.php`; direct Co-expression refresh
  clamped DeepThink to 280x280 at the upper left; L1HS-to-LTR5 left the URL on
  L1HS for roughly six seconds; one LTR5 filter Apply took roughly 3.6 seconds.
- 2026-07-25: Architecture decision recorded: retain separate G6 instances,
  centralize canonical route/exact TE handoff in the parent coordinator, reuse
  one shared Loader implementation, and make filter/focus operations
  lightweight rather than full graph renders.
- 2026-07-26: Runtime implementation completed. Canonical route ownership,
  exact bidirectional TE handoff, optimistic TE/context URLs, Back/Forward,
  latest-request-wins behavior, the shared TE Loader, Co-expression legend
  focus/help, visibility-only filters, and visible-workspace DeepThink bounds
  are active. A late integration failure exposed that Knowledge query changes
  were resetting the live iframe `src`; `ensureDynamicFrame()` now reuses the
  live bridge/G6 instance and sends the new request through `loadGraph()`.
- 2026-07-26: New regression checks passed, including in-page Knowledge search,
  LINE1 negative matching, LTR5 bidirectional handoff, context routing,
  Back/Forward, iframe identity, injected filter rejection, rapid repeated
  Apply, shared Loader behavior, and DeepThink bounds. Existing Co-expression
  Task 6-9, API, Knowledge browser, taxonomy-tree, inspect, and export checks
  also passed. Desktop browser checks covered 1440x960, 1280x900, and 1024x768.
- 2026-07-26: Three plan-declared unrelated legacy checks remain red and were
  not modified: `check_g6_relation_legend_expand_mode.py`,
  `check_g6_legend_expand_tree_fixes.py`, and
  `check_taxonomy_runtime_truth.py`. Their failures match the existing records
  in the acceptance README. No independent subagent review was run because it
  was not explicitly authorized; the main AI performed the final diff review.

## 10. Completion Status

**Status:** Completed and accepted on 2026-07-26.

All ten user-reported behaviors have executable regression coverage. The two
G6 instances remain separate, MySQL remains the only Co-expression runtime
source, and no fuzzy TE alias was introduced. A request epoch in the parent
controller provides the planned stale-result protection; this is behaviorally
equivalent to passing a separate generation value into the iframe. Generated
browser logs/screenshots were restored after verification to avoid unrelated
artifact churn.
