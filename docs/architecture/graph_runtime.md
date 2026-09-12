# TE-Dee Graph Runtime

Last reviewed: 2026-09-11

`preview.php` is one page with three graph workspaces. The workspaces share
placement, routing, export conventions, and selected utilities, but they do not
share one mutable G6 instance or one scientific interpretation.

## Mode Map

| Mode | Scientific source | Parent controller | Renderer |
| --- | --- | --- | --- |
| Knowledge Graph | Neo4j Graph/Taxonomy APIs | existing Preview/G6 controllers | `assets/js/renderers/g6/index-g6-*.js` and Canvas taxonomy adapters |
| TE-Gene Graph | MySQL TE-Gene/Co-expression/eQTL APIs | `coexpression-mode.js` | `assets/js/renderers/g6/coexpression/` |
| Integrated Graph | Knowledge payload + TE-Gene payload | `integrated-mode.js` | `assets/js/renderers/g6/integrated/` |

The stable mode dropdown is rendered by `preview.php`. Parent-owned routing and
mode transitions live in
`assets/js/pages/preview/preview-workspace-mode.js`.

## Workspace Composition

- `templates/preview/knowledge_graph_workspace.php`
- `templates/preview/coexpression_workspace.php`
- `templates/preview/integrated_graph_workspace.php`
- `assets/html/preview_g6_embed.html`
- `assets/html/preview_coexpression_embed.html`
- `assets/html/preview_integrated_embed.html`

Each iframe has its own bridge and renderer lifecycle. Hiding a mode preserves
its stable state and stops unnecessary force activity. Request epochs and abort
handling prevent stale responses from replacing a newer selection.

## Knowledge Graph

Knowledge Graph owns literature-derived entities/relations, PubMed evidence,
dynamic Jump/Expand behavior, taxonomy/disease classification, node and edge
inspection, and relation/entity filtering.

TE taxonomy supports a collapsible G6 Tree and a native Canvas Graph using the
same taxonomy API. Disease classification adapts a Graph API hierarchy payload
to the Canvas interaction core. These are production views, not isolated demos.

Detailed `data.relation` values are scientifically meaningful and must not be
replaced with a generic edge family in legends or inspection.

## TE-Gene Graph

TE-Gene Graph uses MySQL-backed aggregate pair evidence:

- `Co-expression`
- `eQTL`
- `Both`

It supports TE and Gene centers, expression context, Expression activity, node
and edge inspection, visibility filters, and CSV/PNG/SVG export. Normal tissue
is the default context when available. Co-expression is association, and eQTL
overlap is positional/statistical evidence; neither is automatic causality.

## Integrated Graph

Integrated Graph fetches both source payloads for the same selected TE or Gene
and merges them in the browser.

`integrated-contract.js` canonicalizes matching TE and Gene nodes. Merged nodes
retain Knowledge and TE-Gene payloads under source-specific metadata. Other
Knowledge entity types remain distinct.

Edges are never collapsed solely because endpoints match. Knowledge and
TE-Gene relations keep distinct IDs, sources, evidence, and parallel-edge
metadata.

The accepted Integrated legend:

- has only Entity and Relation tabs;
- reuses original graph legend row/checkbox styling;
- groups disease classification node subtypes for display;
- shows detailed Knowledge relation labels;
- excludes classification edges;
- separates TE-Gene relation rows with a horizontal divider;
- shows Co-expression/eQTL/Both when present;
- does not show redundant legend headings or per-row counts.

Integrated mode intentionally omits Knowledge-only Expand mode and Fixed view
controls. Gene labels must remain complete.

## Route State

The URL is the durable owner of mode and applied state. Knowledge is the default;
TE-Gene and Integrated use explicit mode values. Routes may preserve query,
entity type, context, node/relation filters, PMID threshold, TE-Gene edge scope,
and expression activity.

Browser Back/Forward, refresh, and mode changes must restore the matching
workspace without moving the mode selector or leaving a stale loader.

## Shared Utilities

- TE mechanism loader: `assets/js/pages/preview/te-loader.js`
- SVG serialization: `assets/js/renderers/g6/g6-svg-export.js`
- type metadata: `assets/js/renderers/g6/index-g6-type-meta.js`
- shared runtime helpers: `assets/js/renderers/g6/index-g6-shared.js`

Shared utilities do not imply shared mutable graph state.

## Debugging Order

When a graph is blank, stale, or inconsistent, inspect:

1. API request, resolved entity, context, and payload.
2. Parent mode and route state.
3. Host-to-iframe bridge readiness.
4. Loader/preloader settlement.
5. Renderer master elements and visibility filters.
6. Legend catalogs and applied/focus state.
7. Nonblank canvas, positions, labels, and inspect cards.

Do not begin by rewriting the renderer.

## Focused Checks

- `check_g6_static_contract.py`
- `check_g6_browser_smoke.py`
- `check_graph_canonical_navigation_contract.py`
- `check_graph_route_view_state_contract.py`
- `check_te_gene_graph_static.py`
- `check_coexpression_renderer_contract.js`
- `check_integrated_graph_contract.js`
- `check_preview_integrated_graph_static.py`
- `check_preview_integrated_graph_browser.py`
- `check_preview_dual_mode_parity_static.py`
- `check_preview_dual_mode_navigation_browser.py`

Run checks proportional to the changed layer. Browser evidence is required for
visual/interaction claims; static checks alone do not prove a nonblank graph.
