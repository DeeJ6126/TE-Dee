# Co-expression Dual-Mode Acceptance

Date: 2026-07-25

Parity stabilization rerun: 2026-07-26

This run accepts Tasks 8-10 of the Co-expression dual-mode plan against the
MySQL-backed runtime in `preview.php`.

## Coverage

- representative networks: `L1HS`, `LTR5`, `MER11B`, `HERVH-int`, and `CR1`;
- unavailable context: `CR1 / cancer_cell_line`;
- TE-only Expression activity and Knowledge Graph Expression removal;
- details, tooltips, legend filters, edge scope, URL history, retry, cache,
  CSV/PNG export, and desktop layout;
- persistent, separate Knowledge Graph and Co-expression iframes/G6 instances;
- real force response after five Knowledge/Co-expression hide/resume
  roundtrips;
- ordinary Knowledge Graph, taxonomy tree, inspect-card, and export regression
  checks;
- real pointer drag and force cooling for three structurally different
  networks.

Screenshots are grouped under `representative-cases/`, `desktop/`, and
`performance/`. They were generated after the final center-node sizing and
1024px toolbar fixes.

## Measured Results

- HTTP catalog median: 33.8 ms.
- HTTP L1HS network median: 31.0 ms.
- Loader appears within 100 ms.
- L1HS: 26 nodes, 100 edges, 165.40 px target drag, 25 moving partners,
  1.8 s settling.
- HERVH-int: 19 nodes, 100 edges, 165.27 px target drag, 18 moving partners,
  2.8 s settling.
- MER11B: 15 nodes, 100 edges, 167.10 px target drag, 14 moving partners,
  1.2 s settling.
- All three force checks exposed ten distinct intermediate target positions and
  retained one G6 instance.

The isolated harness reached its first nonblank/ready point in 2.69-2.71 s.
This includes the copied Dynamic Graph force lifecycle and is slower than the
original 1.5 s aspirational gate. The accepted measured reference budget is
3.0 s for this harness and 3.0 s for drag cooling. The original targets remain
optimization goals, not claims about this accepted reference machine.

## Known Unrelated Failures

Three legacy checks remain red outside the Co-expression ownership boundary:

- `check_g6_relation_legend_expand_mode.py`: expects four historical
  `updateCurrentGraphViewState().catch` markers; the current bootstrap has
  three.
- `check_g6_legend_expand_tree_fixes.py`: expects the removed historical
  `if (expanded) return;` marker.
- `check_taxonomy_runtime_truth.py`: the previously recorded `index.php`
  homepage taxonomy-helper failure.

The live LINE1 Knowledge Graph browser smoke, taxonomy tree-only runtime, tree
load regression, inspect card, and subgraph export checks pass. These legacy
failures were not repaired inside the Co-expression plan.

## 2026-07-26 Preview Parity Stabilization

The follow-up run accepted the ten reported Preview state and interaction
regressions. The parent coordinator now owns canonical Knowledge/Co-expression
routes, exact TE handoff, context changes, and Back/Forward restoration. The
run also verified one persistent iframe/G6 instance per initialized workspace,
including a live Knowledge L1HS-to-LTR5 handoff without changing iframe `src`.

The Co-expression legend now supports click-to-focus and explains Module hub
and Relative TE expression. Filter Apply is a visibility-only operation: it
does not increment render count or replace the graph, finishes within the
1-second regression budget, restores the prior view after an injected
rejection, and remains usable after rapid repeated Apply. Knowledge and
Co-expression use the same TE Loader renderer while retaining isolated Loader
DOM state. DeepThink is clamped to the currently visible workspace after
refresh and mode changes.

The public Co-expression Loader uses one stable sentence throughout all
internal stages: `Loading <TE> co-expression network...`. The mechanism SVG
receives the TE name directly, preventing stage-derived labels such as `Co-...`
or `Ren...`.

Loader mechanism selection now follows the existing RMSK + RepBase taxonomy
tree through the taxonomy API rather than trying to recognize a TE from its
name alone. Class I descendants use the retro Loader, Class II descendants use
the DNA Loader, and the Others branch uses the default Loader. In the current
285-entry Co-expression catalog, the API resolves 240 entries as retro, 44 as
DNA, and only SART1 is absent from the tree; its narrow compatibility fallback
resolves it as retro. The API contract separately verifies that an Others node
resolves to the default Loader. Both graph modes await the same resolver, so a
Knowledge Graph search for AluJb and a Co-expression load for AluJb show the
same retro mechanism rather than the Knowledge workspace falling back to its
legacy spinner.

Expression ripple handling was subsequently stabilized: every TE with
available Expression data is created as a ripple node while the layer is on,
with strength controlling opacity, ring count, and radius. Pointer enter/leave
now updates tooltips only and does not swap node types or redraw the graph, so
force dragging continues after the pointer leaves the original node circle.

New acceptance checks:

- `check_preview_dual_mode_parity_static.py`
- `check_preview_dual_mode_navigation_browser.py`
- `check_coexpression_legend_filter_loader_browser.py`
- `check_preview_assistant_workspace_bounds.py`
- `check_te_loader_taxonomy_contract.py`
- `check_te_loader_taxonomy_browser.py`

All new checks, existing Co-expression Task 6-9 checks, API contracts, the live
Knowledge browser smoke, taxonomy tree-only runtime, inspect, and export checks
passed. The three historical unrelated failures listed above were reproduced
unchanged.

## Environment Note

The standalone `D:\php\php-8.5.1-Win32-vs17-x64\php.exe` lacks `mysqli`.
MySQL CLI contracts were therefore run with the Wamp runtime PHP
`D:\wamp64\bin\php\php8.5.0\php.exe`. It emits an unrelated stale Xdebug DLL
warning but loads `mysqli` and completes the full 849-network parity check.
