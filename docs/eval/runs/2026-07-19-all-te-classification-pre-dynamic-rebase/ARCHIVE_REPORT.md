# All-TE Classification Graph Experiment Archive Report

Archive date: 2026-07-19

## Final Decision

The experimental force-directed All-TE classification Graph is paused and
removed from the live taxonomy workflow. `preview.php` now exposes the existing
collapsible taxonomy tree only. The `Switch: Tree` / `Switch: Graph` control is
removed, and the taxonomy large-graph and dynamic-prototype scripts are no
longer loaded by either the main preview page or the iframe entrypoint.

The experiment is preserved rather than deleted. Its runtime source remains in
the repository as inactive code, and a frozen copy of the final experimental
state is stored under `final-experiment-state/`.

This is a product-direction decision, not a claim that the engineering work was
unsuccessful. The experiment produced useful performance infrastructure,
interaction patterns, renderer lifecycle rules, and evidence that can be reused
if a future graph has a display contract better suited to a large force layout.

## Original Problem

The All-TE taxonomy API returns an authoritative tree backed by Neo4j. During
the experiment, the complete All payload contained 1,666 nodes and 1,665 edges.
The first production attempt displayed 932 nodes and 931 edges, including deep
taxonomy levels, and took about 2.2 seconds to become interactive on the
reference machine. It also performed expensive full-graph work during ordinary
pointer interactions.

The desired experience was inspired by a separate large network example:
important hierarchy nodes should behave like separated stars, their descendants
should read as local planetary systems, dragging should reveal visible
force-directed motion, and the simulation should cool quickly instead of
running forever.

## Work Completed

### Stage 1: Reproducible Baseline

The first stage added a browser/performance harness before changing runtime
behavior. It reconciled API and Canvas counts, captured screenshots, measured
page startup and interaction timing, sampled Canvas pixels, and recorded
renderer diagnostics.

The accepted baseline was:

- authoritative API: 1,666 nodes and 1,665 edges;
- visible graph: 932 nodes and 931 edges;
- adapter preparation: 8.8 ms;
- render: 1,899.0 ms;
- stable: 2,030.2 ms;
- page-to-interactive: 2,224.7 ms;
- longest task: 1,952.0 ms.

Although this stage took substantial time, it established evidence that later
changes could be compared against rather than judged only by impression.

### Stage 2: Performance Contracts

The second stage added failing contracts for two known sources of cost:

- the nominal `large-static` profile still selected `d3-force`;
- hover, leave, drag, and click caused five whole-graph draws.

The checks separated layout, data-preparation, and interaction responsibilities.
This made later performance changes testable and prevented an apparently smooth
Canvas from hiding repeated global work.

### Stage 3: Static First Paint

The third stage changed `large-static` to use deterministic preset coordinates
for first paint. Explicit force mode remained available as a separate profile.

This reduced render time from about 1,899 ms to 313.5 ms and
page-to-interactive time from about 2,225 ms to 756.8 ms. It also made the graph
nonblank immediately and eliminated force work during startup. The cost was
that deterministic overlap became visually obvious rather than being concealed
by a long initial simulation.

### Stage 4: Linear Tree Preparation

The taxonomy source builder previously searched the full element array while
processing coordinates. The fourth stage created a position index once and used
constant-time lookups for edge preparation.

This change preserved taxonomy truth, root selection, parent relationships, and
payload order while removing a repeated coordinate scan. The optimization is
general and remains useful for future hierarchy renderers.

### Stage 5: Local Interaction Updates

The fifth stage built reusable indexes for nodes, neighbors, incident edges, and
legend groups. Hover and selection then updated only the affected local
neighborhood instead of redrawing the whole graph.

It also introduced generation guards for delayed state work and a single,
plain-text, host-bounded tooltip. Hover, leave, drag, and click no longer called
global render, layout, destroy, or data-replacement paths.

The resulting measurements included:

- render: 265.5 ms;
- stable: 418.0 ms;
- page-to-interactive: 620.7 ms;
- hover/leave: 47.7 ms;
- zero layout calls during ordinary pointer interaction.

### Stage 6: Renderer Reuse and Source Ownership

The sixth stage separated canonical master data from the currently visible
view. Legend visibility updates reused the existing renderer through one
endpoint-safe data replacement and one awaited draw.

Lifecycle diagnostics established explicit ownership:

- zero live taxonomy renderers while Tree mode was active;
- exactly one live renderer while taxonomy Graph mode was active;
- old renderers were destroyed during source or mode changes;
- stale callbacks and mixed-source elements were rejected.

The full All / RMSK+Repbase and Tree / Graph transition sequence passed at that
time. Page-to-interactive fell to 591.0 ms.

### Stage 7: Lightweight Semantic Styling

The seventh stage added bounded visual semantics without adding expensive
effects:

- deterministic branch and cluster identifiers;
- one branch color per top-level family;
- hierarchy-controlled node size, stroke, and opacity;
- a unique root treatment;
- labels pinned only for shallow structural levels;
- straight, unlabeled taxonomy edges;
- deeper names available through tooltip and detail views.

The styling made the three top-level branches distinguishable, but it did not
solve the deeper structural problem that many branches still occupied one dense
shared region.

### Stage 8: Bounded Drag Reheat and Collision Relief

The eighth stage widened deterministic top-level spacing, used golden-angle
local packing, and added a bounded force lifecycle only while a node was being
dragged.

Measured circle-overlap pairs fell from 4,998 to 679 before interaction and to
79 after one transient drag. Release stopped the owned force layout in roughly
601-613 ms, followed by a verified 500 ms idle window with no coordinate or tick
changes. Renderer identity and visible counts remained stable.

The final performance measurements for this stage included:

- render: 276.7 ms;
- stable: 431.2 ms;
- page-to-interactive: 658.0 ms;
- hover/leave: 40.6 ms;
- longest task: 314.0 ms.

This stage met its technical budgets, but the graph still did not provide the
clear, separated star-system reading expected by the user.

### Stage 9: Family-Bounded and Dynamic Rebase Attempts

The ninth-stage design was revised after visual review:

- default visibility would stop at Family;
- multiple Families could expand independently to direct Subfamilies;
- Class, Order, and Superfamily nodes would act as structural star anchors;
- meaningful relative movement was required instead of a graph that merely
  contracted toward the center.

The Family-bounded adapter produced a default view of 217 nodes and 216 edges:

- Human TE: 1;
- Class: 3;
- Order: 49;
- Superfamily: 92;
- Family: 72.

A detail card and Family Expand/Collapse interaction were prototyped. A second
comparison prototype then bypassed the deterministic taxonomy coordinates and
used an ordinary `d3-force` interaction model. It was visibly more organic, but
it still did not reach the desired clarity. Expanding a Family also reheated the
layout, which intentionally conflicted with the earlier no-layout-restart
acceptance contract.

The ninth stage was therefore not accepted as complete, and the tenth-stage
independent production acceptance was not started.

## Why The Experiment Was Paused

Performance became acceptable, but the product problem was not mainly
performance by the end. The visual model remained difficult to read:

- many structural anchors still competed inside one large composition;
- descendants were not consistently perceived as belonging to one obvious
  anchor;
- tuning the existing layout risked preserving the same underlying spatial
  assumptions;
- the force behavior could produce motion without producing clearer taxonomy;
- the existing collapsible tree already communicates strict parent-child
  classification more reliably.

For the current product, a taxonomy tree is a better fit than a large
force-directed network. The graph work may become relevant again only if a
future requirement benefits from neighborhood exploration more than strict
hierarchy reading.

## Reusable Results

The following work remains valuable:

- browser performance and Canvas nonblank checks;
- API-to-renderer count reconciliation;
- deterministic stable identifiers and taxonomy-level metadata;
- indexed neighbor, edge, and legend lookup;
- local hover and selection updates;
- renderer instance and callback lifecycle diagnostics;
- endpoint-safe visible views derived from canonical master data;
- bounded simulations with explicit cooling ownership;
- sparse labels and lightweight semantic node styling;
- cumulative expansion concepts and detail-card interaction;
- measured overlap and post-drag idle checks.

These techniques may help a future co-expression network, especially because
co-expression is a true network rather than a strict hierarchy. Reuse must be
selective: the co-expression frontend needs its own data contract, scientific
language, filtering rules, and acceptance plan. Correlation must not be
presented as causation or TE regulation.

## Runtime State After Archival

- `preview.php` renders the existing collapsible taxonomy tree by default.
- The taxonomy display-mode switch is absent.
- `renderCurrentTaxonomyView()` always delegates to the tree renderer.
- The taxonomy source switch remains available so the tree can still change
  between the supported taxonomy sources.
- Large-force and taxonomy-dynamic prototype scripts are not loaded.
- Ordinary evidence Graph behavior remains a separate runtime and is not
  intentionally changed by this retirement.
- Neo4j/API remains the only runtime taxonomy truth.

## Archive Contents

The top level of this archive contains the pre-dynamic-rebase snapshot, its
original README, this report, and the checks copied at the snapshot boundary.

`final-experiment-state/` contains the state immediately before the taxonomy
Graph was removed from the live runtime:

- `preview.php`;
- `assets/html/preview_graph.html`;
- `assets/js/renderers/g6/index-g6.bootstrap.js`;
- `assets/js/renderers/g6/default-tree-mindmap.js`;
- `assets/js/renderers/g6/taxonomy-dynamic-prototype.js`;
- `assets/js/renderers/g6/large-force-graph/`;
- All-TE contract, browser, lifecycle, and performance checks;
- the active execution plan;
- the final dynamic-prototype screenshot.

Files inside this archive are evidence and reference material only. They must
not be loaded as production runtime entrypoints.

## Resumption Rule

Do not silently restore the taxonomy Graph or its switch. A future resumption
requires:

1. a new active execution plan;
2. a clear product reason why a network is preferable to the tree;
3. an explicit visible-node and expansion contract;
4. fresh browser screenshots and interaction evidence;
5. verification that ordinary Evidence Graph remains unchanged;
6. user acceptance of the visual result, not only performance measurements.

