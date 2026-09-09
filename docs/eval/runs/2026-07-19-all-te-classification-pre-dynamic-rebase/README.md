# All-TE Classification Graph Archive

Snapshot and retirement date: 2026-07-19

The experiment is now paused. Read `ARCHIVE_REPORT.md` for the detailed history,
results, final decision, reusable work, and resumption rules. The live taxonomy
workflow now exposes the existing collapsible classification tree only.

## Purpose

This directory preserves the current All-TE classification Graph implementation
immediately before the experimental switch to the existing ordinary dynamic
Graph interaction model. The snapshot is reference material only. Files under
this directory are not runtime entrypoints and must not be loaded by production
pages.

The copied files retain their repository-relative paths so the implementation,
checks, and active execution-plan context can be inspected together.

## Runtime State At The Snapshot

- Taxonomy truth comes from Neo4j through `api/taxonomy.php`.
- The authoritative All-TE payload contains 1,666 nodes and 1,665 edges.
- The default Graph shows structural depths 0-4 only:
  - Human TE: 1
  - Class: 3
  - Order: 49
  - Superfamily: 92
  - Family: 72
- The default visible graph therefore contains 217 nodes and 216 edges.
- Direct depth-5 Subfamilies can be expanded cumulatively from individual
  Family nodes.
- The renderer preserves one Graph instance for hover, selection, legend focus,
  Family Expand, and Family Collapse.
- A bounded initial force settle and bounded drag reheats are present.

## Why This Version Was Frozen

The implementation met important data, visibility, lifecycle, and lightweight
interaction contracts, but its visible result was not accepted:

- deterministic multi-level disc packing still dominated the composition;
- the graph appeared miniature when the Deep Think drawer was open;
- the interaction felt like a pre-arranged taxonomy layout with added motion,
  rather than the project's existing ordinary dynamic Graph;
- continued parameter tuning risked preserving those underlying constraints.

The next experiment therefore starts from the existing dynamic Graph behavior
and adapts taxonomy data into it. It may temporarily contain visual or
interaction defects. The immediate question is whether the ordinary dynamic
interaction model feels materially better with the 217-node Family-bounded
taxonomy dataset.

## Scope Boundary

Tasks 1-8 of the performance stabilization work remain intact. This experiment
must not rewrite their contracts or remove their checks. The snapshot does not
authorize a second taxonomy truth source, evidence-graph semantics on taxonomy
edges, or a fallback to a legacy Neo4j database.

## Contents

- `assets/js/renderers/g6/default-tree-mindmap.js`
- `assets/js/renderers/g6/large-force-graph/`
- `scripts/checks/check_all_te_large_graph_contract.js`
- `scripts/checks/check_all_te_large_graph_browser.py`
- `scripts/checks/check_g6_taxonomy_graph_mode.py`
- `docs/exec-plans/active/all-te-g6-performance-stabilization.md`

The runtime files outside this snapshot remain authoritative. The live preview
no longer loads the archived large-force or dynamic-prototype scripts.

## Dynamic Prototype Preserved After The Snapshot

The first comparison prototype is implemented separately in
`assets/js/renderers/g6/taxonomy-dynamic-prototype.js`. Taxonomy Graph mode
prefers that renderer when it is loaded and keeps the snapshotted renderer as a
fallback.

The prototype deliberately removes deterministic taxonomy positions from G6
input and uses the same basic interaction model as the ordinary dynamic Graph:
one `d3-force` layout plus native `drag-element-force`, canvas drag, zoom, and
click selection. It retains the Neo4j/API taxonomy adapter, the 217-node
Family-bounded default dataset, taxonomy colors, the Family detail card, and
single-Family expansion.

This prototype was a visual and interaction comparison, not completed Task 9
acceptance. In particular, expansion reheated the dynamic layout and the
existing Phase 9 motion/cooling checks were not expected to pass unchanged.

Its final runtime context and screenshot are preserved under
`final-experiment-state/`. They are not production entrypoints.
