# TE-Gene And Integrated Graph Frontend Contract

Last reviewed: 2026-09-11

## Runtime Shape

`preview.php` contains three selectable workspaces:

- Knowledge Graph: Neo4j evidence and taxonomy.
- TE-Gene Graph: MySQL Co-expression/eQTL evidence.
- Integrated Graph: browser merge of Knowledge and TE-Gene payloads.

The stable mode control is a dropdown to the right of the search controls.
Expression context uses a visually matching dropdown without a visible Context
label. Switching modes hides rather than destroys initialized renderers and
must not move the mode selector.

Each mode owns a separate iframe, G6 instance, bridge, loader, cache, filters,
details, and export adapter. Shared utilities do not imply shared mutable state.

## Runtime Data

The TE-Gene runtime is MySQL-only:

- `api/coexpression.php`, `api/coexpression_repository.php`
- `api/te_gene.php`, `api/te_gene_repository.php`
- `api/graph_expression.php`

Offline files under `data/coexpression/` and `data/eQTL/` are provenance and
import inputs, not browser runtime sources.

Pair evidence is labeled:

- `Co-expression`
- `eQTL`
- `Both`

Both preserves the presence of two evidence types. It does not erase their
separate fields or imply causality.

## Search And Context

- TE and Gene catalogs offer candidates before typing.
- Search is exact after selection/resolution; one-letter prefixes still return
  valid matching candidates.
- Gene-centered networks are deterministically rerooted on the selected Gene.
- Unknown features and unavailable contexts do not fall back to L1HS.
- Normal tissue is the default context when available.
- Direct routes and browser Back/Forward preserve mode, feature type, feature,
  and context without recreating the active iframe.

## TE-Gene Visual Semantics

- selected center: strong outline/fill and persistent complete label;
- TE nodes: blue circular nodes;
- Gene nodes: green nodes with complete Gene names;
- module hub: outline treatment, not a new entity type;
- Co-expression edge strength: monotonic with positive correlation;
- default graph: no causal arrows or causal wording.

Visibility controls use the original graph legend interaction style. Expression
activity is a measured halo/ripple overlay and never changes edge topology.

## Integrated Merge

Only canonical matching TE and Gene nodes are merged across sources. Merged
nodes retain source-specific payloads for inspection. Knowledge-only biomedical
entity types remain distinct.

Edges are not collapsed because endpoints match. Knowledge and TE-Gene edges
retain distinct IDs, sources, detail cards, and parallel-edge metadata.

Integrated mode intentionally omits Knowledge-only Expand and Fixed view
controls.

## Integrated Legend

The legend reuses established Graph styles:

- two tabs only: Entity and Relation;
- no duplicate Entity Legend or Relation Legend heading;
- Entity is the union of Knowledge entity types and TE-Gene concepts;
- original colored markers and green visibility checkboxes;
- no per-row counts;
- detailed Knowledge relation labels from edge payloads;
- no classification edges;
- a horizontal divider before TE-Gene relations;
- Co-expression/eQTL/Both rows when present;
- original click-to-focus/filter behavior.

## Details And Evidence

TE-Gene details expose center/module provenance, context, feature roles,
correlation/FDR fields, eQTL summaries, supporting tissues, and expression
activity where available. Runtime text is escaped.

Knowledge detail cards retain their relation/PMID evidence. Integrated inspect
cards must not flatten these fields into a generic relation.

## Export

CSV exports visible structured data and provenance fields. PNG captures the
active Canvas. SVG uses `assets/js/renderers/g6/g6-svg-export.js` with final
positions and mode-specific styles. Exports must not contain credentials or
private absolute paths.

## Interpretation

Co-expression is correlation. eQTL overlap is positional/statistical evidence.
Expression activity is a measured context summary. None alone proves regulation,
mechanism, or causality.

## Primary Checks

- `check_te_gene_graph_static.py`
- `check_coexpression_renderer_contract.js`
- `check_coexpression_graph_browser.py`
- `check_integrated_graph_contract.js`
- `check_preview_integrated_graph_static.py`
- `check_preview_integrated_graph_browser.py`
- `check_preview_dual_mode_parity_static.py`
- `check_preview_dual_mode_navigation_browser.py`
