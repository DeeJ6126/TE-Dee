# All-TE Large-Static G6 Evaluation

## Purpose

This directory records the reproducible local baseline and later optimized
evidence for the production `preview.php?tree=all` taxonomy Graph. It belongs to
Tasks 1, 3, 5, 6, and 7 of `docs/exec-plans/active/all-te-g6-performance-stabilization.md`.

The baseline captures the original force-layout implementation without changing
runtime JavaScript or PHP. Optimized phases use the same scripts, viewport,
source, warmup count, and measured-run count so the comparison remains useful.

## Task 3 Optimized Layout

Task 3 made adapter coordinates authoritative by resolving `large-static` to
G6 `preset` and using `drag-element` instead of `drag-element-force`. The
comparison command was:

```powershell
python scripts/checks/measure_all_te_graph_performance.py --source all --warmups 2 --runs 5 --output docs/eval/runs/2026-07-15-all-te-large-static/optimized-layout.json
```

| Metric | Baseline | Task 3 |
| --- | ---: | ---: |
| Adapter median | 8.8 ms | 12.4 ms |
| G6 render median | 1,899.0 ms | 313.5 ms |
| Stable median | 2,030.2 ms | 481.8 ms |
| Page to interactive median | 2,224.7 ms | 756.8 ms |
| Longest main-thread task | 1,952.0 ms | 383.0 ms |

The render median improved by approximately 6.1 times. All five measured runs
used `preset`, recorded zero public layout calls and browser errors, and kept
932 visible nodes and 931 visible edges. `desktop-layout.png` was inspected and
is nonblank. Deterministic coordinates expose substantial branch and label
overlap; that is retained as evidence for the later overlap gate rather than
concealed by restoring force. Interaction probes still observe four global
draws, which remains assigned to Task 5.

## Task 5 Local Interaction State

Task 5 replaced global pointer, drag, and custom-click redraws with degree-
bounded G6 element-state batches and a reusable host-owned HTML tooltip. The
comparison command was:

```powershell
python scripts/checks/measure_all_te_graph_performance.py --source all --warmups 2 --runs 5 --output docs/eval/runs/2026-07-15-all-te-large-static/optimized-interactions.json
```

The five-run medians were render `265.5 ms`, stable `418.0 ms`, page to
interactive `620.7 ms`, and hover plus leave `47.7 ms`; the longest observed
task was `370.0 ms`. Every run retained 932 visible nodes and 931 visible edges,
used `preset`, recorded zero public layout calls and browser errors, and made
two directly observed global draws. Those two draws belong to the still-global
legend focus/clear probe; hover and leave themselves use local
`setElementState` updates and make zero `draw`, render, layout, destroy, or
`setData` calls. The dedicated browser contract also verifies one raw-label-
first tooltip, bounded placement, leave cleanup, repeated-enter no-op behavior,
stable graph identity/counts, and pending-hover invalidation across leave and
destroy.

## Task 6 Renderer Reuse and Lifecycle

Task 6 moved legend filtering from the adapter boundary into a pure master-
data view, proved the bundled G6 `setData()` plus one awaited draw path, and
kept the same renderer and graph instance for same-source visibility changes.
Legend focus and clear now use only targeted element states. Source and
Tree/Graph changes remain explicit destroy/create ownership boundaries.

The accepted five-run command was:

```powershell
python scripts/checks/measure_all_te_graph_performance.py --warmups 1 --runs 5 --output docs/eval/runs/2026-07-15-all-te-large-static/optimized-lifecycle.json --assert-budget
```

The medians were adapter `10.1 ms`, render `247.6 ms`, stable `385.9 ms`, page
to interactive `591.0 ms`, hover plus leave `42.0 ms`, legend focus `40.0 ms`,
and visibility Apply `107.1 ms`; the longest observed task was `281.0 ms`.
The full browser roundtrip proved zero live taxonomy renderers in Tree mode,
exactly one in Graph mode, destruction of every departed renderer, stable
identity for same-source hide/restore, valid visible endpoints, nonblank Canvas
pixels, and no console, page, or request errors.

The API reports `1666/1665`, while canonical renderer master diagnostics report
`1660/1659`. The six-row difference is pre-existing slug-ID canonicalization
for six label pairs that differ only by a trailing underscore. Task 6 does not
change that upstream ID policy and retains every canonical element it receives;
the collision is recorded in the tech-debt tracker.

## Task 7 Semantic Visual Encoding

Task 7 replaced the single blue depth ramp with deterministic top-level branch
colors while keeping taxonomy IDs, hierarchy, and API truth unchanged. Root,
Class, and Order nodes remain semantic anchors through size, stroke, opacity,
and pinned labels. Deeper labels stay off the Canvas and remain available in
the bounded HTML tooltip. The depth legend uses neutral swatches because node
hue now represents branch identity, not depth.

The accepted five-run command was:

```powershell
python scripts/checks/measure_all_te_graph_performance.py --source all --warmups 1 --runs 5 --output docs/eval/runs/2026-07-15-all-te-large-static/optimized-semantics.json --assert-budget
```

The medians were adapter `11.4 ms`, render `299.1 ms`, stable `453.8 ms`, page
to interactive `683.6 ms`, hover plus leave `46.4 ms`, legend focus `50.0 ms`,
and visibility Apply `133.0 ms`; the longest observed task was `342.0 ms`.
All timing budgets passed. The production browser check retained `932/931`
visible nodes/edges, one live preset renderer, valid endpoints, nonblank Canvas
pixels, a reachable bounded tooltip, and no browser/request errors.

`desktop.png` was captured at `1440x960` and inspected. The three top-level
branches are visually distinguishable and the root remains readable. Dense
Order labels and preset branch overlap remain visible; they are not concealed
by this color phase and are explicitly assigned to Task 8.

## Task 8 Preset Spacing and Bounded Drag Reheat

Task 8 retained the preset first paint and replaced compressed sibling rings
with deterministic golden-angle local packing plus wider top-level branch
separation. On the production `all` view, initial visible circle-overlap pairs
fell from the recorded pre-change `4,998` to `679` (`86.4%` reduction), while
total penetration fell from `30,130.61` to `3,502.27` (`88.4%` reduction).
Coincident coordinate groups fell from one to zero.

Node drag now starts one low-alpha local G6 `d3-force` layout through the public
`setLayout()` and `layout()` APIs. Release hard-stops that owned layout through
`stopLayout()` and restores preset ownership. Production browser runs stopped
in approximately `601-613 ms`, below the `800 ms` gate. After cooling, a further
`500 ms` idle window recorded no coordinate or tick change. The transient pass
reduced remaining overlap to `79` pairs and total penetration to `510.39`.
A real Playwright mouse drag moved the selected node approximately `89.6 px`
without replacing the Graph or renderer, changing `932/931` visible counts, or
issuing another taxonomy request.

The accepted five-run command was:

```powershell
python scripts/checks/measure_all_te_graph_performance.py --source all --warmups 1 --runs 5 --output docs/eval/runs/2026-07-15-all-te-large-static/optimized-phase8.json --assert-budget
```

The medians were adapter `11.2 ms`, render `276.7 ms`, stable `431.2 ms`, page
to interactive `658.0 ms`, hover plus leave `40.6 ms`, legend focus `41.2 ms`,
and visibility Apply `129.5 ms`; the longest observed task was `314.0 ms`.
All budgets passed. `desktop-phase8.png` is the inspected `1440x960` production
first-paint screenshot; it is nonblank and shows the less compressed preset
before any drag force runs.

## Baseline Commands

```powershell
python scripts/checks/measure_all_te_graph_performance.py --source all --warmups 2 --runs 5 --output docs/eval/runs/2026-07-15-all-te-large-static/baseline.json
python scripts/checks/check_all_te_large_graph_browser.py --focus initial --screenshot docs/eval/runs/2026-07-15-all-te-large-static/desktop-baseline.png
```

## Captured Baseline

Captured on 2026-07-15 from Git commit
`f8d4f568db6b83ef9431c503a98bbcc63455e4d8` with Chromium
`148.0.7778.96`, Python `3.13.5`, and Windows 11. Two warmups preceded
five measured runs.

| Metric | Median / maximum |
| --- | ---: |
| Taxonomy API request | 168.322 ms observed before browser runs |
| Adapter | 8.8 ms median |
| G6 render, including internal layout/draw | 1,899.0 ms median |
| Public G6 `layout()` | unavailable; included in render |
| Public G6 `draw()` during initial render | unavailable; included in render |
| Stable interaction | 2,030.2 ms median |
| Page to interactive | 2,224.7 ms median |
| Hover plus leave | 31.5 ms median |
| Legend focus | 33.7 ms median |
| Legend visibility Apply | 435.8 ms median |
| Longest observed main-thread task | 1,952.0 ms maximum |

All five runs reconciled the API at 1,666 nodes and 1,665 edges, rendered
932 visible nodes and 931 visible edges under the default level state, created
one measured renderer, and reported zero page errors, console errors, failed
requests, or invalid visible edge endpoints.

The current hover/leave plus focus/clear probes made four directly observed
whole-graph `draw()` calls in every measured run. The measured visibility Apply
plus restoration created and destroyed two additional taxonomy renderer
instances in every run. These are baseline lifecycle observations, not Task 1
regressions; later phases are responsible for removing them.

`desktop-baseline.png` was inspected after capture. The production graph is
nonblank and the taxonomy controls and legend are visible. The existing right
Deep Think panel occupies part of the viewport and bounds the visible graph;
this is recorded as baseline page composition, not treated as a Task 1 layout
change.

The performance script launches one Chromium process, performs one unmeasured
cold page, performs the requested warmups, and then measures fresh pages in the
same browser context. The fixed viewport is `1440x960` with device scale factor
`1`.

## Metric Boundaries

- API time and payload bytes describe `api/taxonomy.php`; API node and edge
  counts are validated independently from visible graph counts.
- Adapter time wraps `taxonomy-large-force-adapter.fromTaxonomySource()`.
- Render time wraps the shared large-force renderer's asynchronous `render()`
  and therefore includes G6's internal layout and initial draw.
- The harness also wraps public G6 `layout()` and `draw()` methods. The bundled
  runtime did not call either public method during initial `render()`, so those
  subphase values are explicitly `null`; they are not guessed or subtracted
  from aggregate render time.
- Stable time begins at the first observed adapter/render boundary and ends
  after the loader is hidden, taxonomy state and graph data exist, and two
  animation frames have elapsed.
- Page-to-interactive begins at the page performance origin and uses the same
  stable endpoint.
- Hover/leave measures programmatic G6 pointer enter and leave, including the
  current redraw promises and two final animation frames.
- Legend focus measures one focus update and two animation frames. Clearing the
  focus happens outside the measured interval.
- Legend visibility Apply measures one deliberate visibility change and its
  resulting stable draw. Restoration happens outside the measured interval.
- Long tasks are browser `PerformanceObserver` entries of type `longtask` when
  Chromium exposes that API.

## Interpretation Limits

- Absolute timing is local reference evidence, not a cross-machine correctness
  assertion. CPU scheduling, browser build, and other local work affect it.
- The unmeasured cold page is excluded from timing medians. Measured pages share
  warm browser and HTTP caches.
- The baseline force renderer can resolve its render promise before motion looks
  completely quiet. The deterministic stable boundary is loader/state/data plus
  two frames, not subjective visual stillness.
- The current runtime destroys and recreates the taxonomy renderer for each
  render. Exact layout-start and live-instance counters are not yet exposed;
  unavailable values must remain `null`, never inferred.
- The API contains all taxonomy levels, while the default legend hides deep
  levels. Therefore API counts and visible G6 counts are expected to differ.
- The Task 3 static contract requires `large-static -> preset`, `drag-element`,
  and no `drag-element-force`. The explicit `taxonomy-force` profile remains
  available only for bounded comparison work.

## Acceptance Use

Baseline generation records current behavior and does not apply future timing
budgets. Later optimized measurement uses `--assert-budget`, which requires this
directory's `baseline.json`, checks the absolute budgets in the active plan,
and requires at least a twofold render-time improvement over the baseline.

The browser check validates API count declarations, unique node IDs, root
connectivity, edge endpoints, loader state, host dimensions, visible G6
endpoint integrity, Canvas backing dimensions, and aggregate Canvas pixels.
Interaction focus modes are extended by later plan tasks. Until implemented,
requesting one of those focus modes runs the initial contract and then exits
nonzero with a pending-suite error, so automation cannot mistake the missing
interaction assertions for a pass.

## Correctness Check Record

Fresh Task 1 verification produced:

- `node scripts/checks/check_large_force_graph_contract.js`: PASS.
- `python scripts/checks/check_g6_browser_smoke.py`: PASS for the ordinary
  Evidence Graph at `preview.php?q=LINE1`.
- `python scripts/checks/check_all_te_large_graph_browser.py --focus initial`:
  PASS with API `1666/1665`, visible graph `932/931`, valid endpoints, and
  nonblank Canvas pixels.
- `python scripts/checks/check_taxonomy_runtime_truth.py`: pre-existing FAIL,
  reporting `index.php should build homepage taxonomy from the Neo4j-backed
  taxonomy helper.` Task 1 did not modify `index.php`, and `git status` showed
  no change to that file. `AI_HANDOFF.md` already identifies this exact
  homepage taxonomy-helper condition as a possible pre-existing failure to
  distinguish from graph work.

The taxonomy-truth failure is recorded rather than attributed to the new
harness. It does not alter the API semantic checks above, but it remains an
unresolved repository check outside Task 1's allowed edit scope.
