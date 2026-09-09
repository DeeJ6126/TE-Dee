# TE/Gene Co-expression Workspace

This directory documents TE/gene co-expression data audits, method design,
parameter choices, interpretation boundaries, and display-planning notes. The
corresponding data outputs live under:

```text
data/coexpression/
```

## Current Main Analysis Standard

As of 2026-06-10, the main module-analysis result is fixed as:

```text
Input network: data/coexpression/analysis/v1/abs0.4_fdr0.05/
Module result: data/coexpression/modules/v1_abs0.4_fdr0.05_res1.8/
Correlation method: Spearman correlation
Edge filter: |correlation| >= 0.4 and FDR <= 0.05
Module input edges: positive edges only, correlation > 0
Module algorithm: NetworkX Louvain
Random seed: 42
Resolution: 1.8
```

`resolution = 1.8` was selected because normal cell line modules were too coarse
at `resolution = 1.0`, while higher settings risked over-splitting into many
small modules. Parameter scans indicated that `|r| >= 0.4`, `FDR <= 0.05`, and
`resolution = 1.8` balance TE-gene signal retention and module granularity.

## Main Data Layers

```text
data/coexpression/networks/v1/
```

Full feature-feature co-expression networks. Each dataset contains high-confidence
TE features and selected high-variance genes. This layer is the raw computed
network and is not recommended for direct frontend display.

```text
data/coexpression/analysis/v1/
```

Filtered networks and network summaries. Current sensitivity folders include
multiple absolute-correlation thresholds.

```text
data/coexpression/modules/
```

Module detection outputs. The main result is
`v1_abs0.4_fdr0.05_res1.8/`; other folders are sensitivity checks.

```text
data/coexpression/modules/parameter_scan/
```

Normal cell line parameter scans used to choose Louvain resolution.

```text
data/coexpression/display_subgraphs/
```

Versioned display subgraphs and quality/tier summary tables. These files are
scientific provenance and importer inputs. The browser and HTTP APIs must not
read them at runtime.

## Current Frontend Runtime

As of 2026-07-25, `preview.php` includes a separate Co-expression workspace next
to the Knowledge Graph workspace. The two modes share page-level placement and
interaction conventions, but they retain separate iframes, G6 instances,
bridges, APIs, state, details, legends, and exports.

Production Co-expression data is read from isolated MySQL
`coexpression_*` tables through:

- `api/coexpression.php`
- `api/coexpression_repository.php`
- `assets/js/pages/preview/coexpression-mode.js`
- `assets/js/renderers/g6/coexpression/`

The active analysis version is `v1_abs0.4_fdr0.05_res1.8`. A runtime network is
one selected TE or Gene in one context class, capped at 100 nodes and 150 edges.
TE searches use their approved stored display network. Gene searches
deterministically choose the approved stored network in which the Gene has the
highest visible incident-edge count and re-root that payload on the Gene.
Unknown features and unavailable contexts remain explicit; they never fall
back to `L1HS`.

Expression activity is a TE and Gene visual layer in the Co-expression toolbar.
It uses measured MySQL summaries through `api/graph_expression.php` and does
not alter co-expression edges or force-layout parameters. CSV, Canvas PNG, and
final-position vector SVG exports are available from the same toolbar. See
`frontend_contract.md` for the complete display and acceptance contract.

## Interpretation Boundary

Co-expression edges represent statistical association. They must not be written
as regulation, activation, inhibition, mechanism, or causality without additional
evidence.

Modules represent network communities in expression correlation space. They are
not equivalent to true pathways, regulatory networks, or experimentally
validated functional units. In papers or frontend text, describe them as
"co-expression modules" or "expression-associated modules."

## Representative Cases

- `L1HS`: dense baseline across all three context classes.
- `LTR5 / cancer_cell_line`: smaller gene-rich high-confidence graph with low
  expression activity.
- `MER11B / cancer_cell_line`: TE-only, zero-Gene, not-interpretable graph.
- `HERVH-int / cancer_cell_line`: dense gene-rich graph with high activity.
- `CR1 / normal_tissue`: small gene-rich high-confidence graph.
- `CR1 / cancer_cell_line`: deliberately unavailable context with recoverable
  alternatives.
- `C1orf116 / cancer_cell_line`: Gene-centered search, sourced from the
  `HERVH-int` approved display network with the Gene as the sole visual center.
- Avoid over-claiming TE function from module enrichment. The safe claim is:
  "this TE lies in a module whose gene members are enriched for X."

## Related Documents

- `data_audit.md`: expression matrix and metadata audit.
- `method_design.md`: early method design notes.
- `feature_annotation_status_zh.md`: legacy Chinese feature annotation status.
- `annotation_optimization_plan.md`: feature annotation improvement plan.
- `display_tier_recommendations.md`: display tier recommendations for offline
  subgraphs.
- `frontend_contract.md`: accepted browser runtime, visual semantics, and
  representative acceptance matrix.
