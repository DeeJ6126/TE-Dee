# G6 Graph Runtime

This document records the current TE-KG graph runtime structure. It is a compact
maintenance map, not a complete code explanation.

## Current Entrypoints

- Page entrypoint: `preview.php`
- Graph API: `api/graph.php`
- Graph service: `api/graph_service.php`
- Iframe page: `assets/html/preview_graph.html`
- G6 runtime: `assets/js/renderers/g6/`

## Current Interaction Model

- `preview.php` owns the page shell, query bar, legends, top controls, and
  parent-page state.
- The iframe G6 runner owns actual graph rendering.
- Parent page and iframe communicate through a bridge.
- Legends support entity and relation modes and apply filters through an Apply
  action.
- `Fixed view`, `Expand mode`, `Show names`, and `Show labels` should use
  lightweight state updates when possible. They should not trigger full graph
  reloads unless necessary.

## Dual Graph Workspaces

As of 2026-07-25, `preview.php` can host two independent graph workspaces:

- Knowledge Graph: Neo4j-backed evidence and taxonomy behavior in the existing
  G6 iframe.
- Co-expression: MySQL-backed TE/Gene correlation networks in a dedicated G6
  iframe.

`assets/js/pages/preview/preview-workspace-mode.js` coordinates visibility and
URL history. It does not replace one graph's data inside the other graph's G6
instance. Each workspace owns its renderer lifecycle, bridge, loading state,
details, legend, filters, cache, and export behavior. Switching modes preserves
the initialized workspace and stops hidden Co-expression force activity.

The shared placement and interaction style do not imply shared scientific
semantics. Knowledge Graph does not expose Expression activity. Co-expression
owns the TE-only Expression activity control and requests
`api/graph_expression.php`; Gene nodes never receive inferred Expression
values. Co-expression edges remain statistical associations and do not imply
regulation or causality.

The complete Co-expression contract is recorded in
`docs/coexpression/frontend_contract.md`.

## Unified SVG Export

Knowledge Graph and Co-expression keep separate G6 instances and mode-specific
export adapters, but both SVG paths use
`assets/js/renderers/g6/g6-svg-export.js`. Each adapter supplies the current
filtered nodes and edges, the final positions reported by its active G6
instance, and its domain-specific styles. The shared serializer emits a padded
vector `viewBox`, edges, optional edge labels, static activity or highlight
rings, nodes, labels, and metadata.

SVG is a static scientific snapshot rather than a recording of the Canvas
runtime. Knowledge Graph relation labels follow their current visibility state;
Co-expression Expression ripples are represented as static rings. Hover,
dragging, force motion, tooltips, and other transient interactions are not
serialized. PNG continues to capture the active Canvas directly.

`preview.php` includes the serializer and both child iframe documents in the
preview asset version. The Knowledge Graph and Co-expression iframe URLs carry
that version, and each iframe propagates it to its internal renderer scripts, so
browsers cannot combine a cached pre-SVG bridge with a new SVG-aware renderer.

## Loader Liveness

Graph loaders must always settle to a ready, empty, or error state. Browser-side
Knowledge Graph and Co-expression data requests have a 15-second deadline;
optional renderer support data has a 6-second deadline, and Co-expression
Expression activity has an 8-second deadline. Support-data failure degrades the
extra labels or metrics rather than blocking the graph indefinitely. Main data
failure exposes the existing error/Retry path.

Leaving Co-expression while an activation is in progress immediately restores
the last stable Co-expression selection and hides its Loader. Superseded async
activations must not restore a captured `loading-*` state. Rejected catalog and
iframe-bridge promises are cleared so Retry performs a fresh attempt rather than
reusing a permanently rejected promise.

Both graph modes use the shared TE loader's staged progress contract. The bar
advances within bounded request, data-preparation, and render/layout ranges; it
may creep within the active range but never claims completion before the graph
operation settles. The primary loading label remains above the bar and the
smaller current-phase label remains below it.
`preview.php` computes the shared preview asset version before rendering
`head.php` and applies it to the page stylesheets as well as the scripts. This
prevents new loader markup from being paired with cached pre-progress CSS.

## Current Risks

- G6 browser smoke tests can expose blank canvases and stuck loader states.
- Local smoke environments may block external CDN resources such as `@antv/g6`
  and `marked`; prefer local assets where possible.
- Expand mode business correctness remains an independent technical debt item.
  Do not repair it blindly without browser evidence.
- The native Canvas taxonomy Graph is integrated but remains independently
  rendered from ordinary evidence graphs. Keep renderer state and failure
  handling isolated when tuning its layout.

## Taxonomy Runtime Decision

As of 2026-07-28, classification still defaults to the accepted collapsible G6
Tree, with an optional native Canvas `Graph` beside it. The Canvas renderer uses
only `api/taxonomy.php?view=tree`, reuses the Knowledge Graph taxonomy legend,
and does not load the archived G6 large-force implementation.

The Canvas macro layout groups nodes by their depth-1 Class. Class sector shares
use bounded square-root descendant mass (8%-60%), while depth-2 galaxies use a
bounded fourth-root scale. Current measured shares are approximately 57% / 35%
/ 8% for Retrotransposons / DNA Transposons / Others in `rmsk_repbase`, and 56%
/ 34% / 10% in `all`. Small singleton galaxies remain present but are compactly
packed rather than receiving equal global ring slots.

Disease classification reuses the same Canvas interaction core through
`disease-classification-adapter.js`, but it does not reuse the TE taxonomy API
or TE taxonomy labels. Its runtime truth remains the existing
`api/graph.php?type=disease_class` element payload. Disease Class and Disease
Category nodes are inspect-only; a click on a concrete Disease enters the
canonical typed Knowledge Graph route, while a drag never navigates. The
classification route keeps `q`, `type=disease_class`, and `class`; the optional
Canvas display adds `taxonomy=graph`, and Tree omits it. The TE-only taxonomy
source selector is hidden for disease classification. Page Back, browser
Back/Forward, refresh, and export preserve the disease class and display mode.
The current hierarchy endpoint returns a bounded set of paths and must not be
described as a complete ICD-11 export.

## Related Checks

- `scripts/checks/check_g6_browser_smoke.py`
- `scripts/checks/check_g6_static_contract.py`
- `scripts/checks/check_g6_no_legacy_disease_node.py`
- `scripts/checks/check_g6_relation_legend_expand_mode.py`
- `scripts/checks/check_g6_legend_expand_tree_fixes.py`
- `scripts/checks/check_taxonomy_canvas_integration.py`
- `scripts/checks/check_taxonomy_canvas_layout_browser.py`
- `scripts/checks/check_disease_classification_canvas_contract.py`
- `scripts/checks/check_disease_classification_canvas_browser.js`
- `scripts/checks/check_g6_subgraph_export_smoke.py`
- `scripts/checks/check_coexpression_task8_browser.py`
- `scripts/checks/check_coexpression_task9_browser.py`
