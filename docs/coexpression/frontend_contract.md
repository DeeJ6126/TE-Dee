# Co-expression Frontend Contract

Last verified: 2026-09-02

## Runtime Shape

`preview.php` contains two independent graph workspaces:

- Knowledge Graph uses its existing G6 iframe, Neo4j API, legend, details, and
  history.
- The TE-Gene Graph uses `templates/preview/coexpression_workspace.php`, a
  dedicated iframe, a dedicated G6 instance, and `api/te_gene.php` for TE
  selections. The legacy `api/coexpression.php` remains available for
  compatibility and Gene-centered behavior.

The shared `Knowledge Graph | TE-Gene Graph` control moves between the active
toolbars. Switching workspaces hides rather than destroys either initialized
renderer. Co-expression stops its force layout while hidden and restores the
same iframe, coordinates, selection, filters, and zoom when resumed.

## Runtime Data

The Co-expression runtime is MySQL-only:

- `api/coexpression.php` exposes catalog and network actions.
- `api/coexpression_repository.php` reads the active
  `v1_abs0.4_fdr0.05_res1.8` rows.
- offline JSON, TSV, and manifests under `data/coexpression/` are provenance
  and importer inputs, not browser runtime sources.

One TE-Gene display unit is one selected TE in `All tissues` or one GTEx
tissue. Payloads are capped at 100 nodes and 150 edges by the existing G6
contract; the TE-Gene API uses a 49-edge display cap so the center TE fits in
the node limit. Complete aggregate counts remain in `counts` and truncation is
explicit in `metadata.display_truncated`.

TE-Gene edges are aggregate pair evidence labeled exactly `Co-expression`,
`eQTL`, or `Both`. `Both` means both evidence types exist for the same pair in
the selected evidence scope; supporting tissues are listed in the edge detail.

The searchable catalog contains approved TE centers and Genes present in the
stored display networks. A Gene search is case-insensitive but exact. When a
Gene occurs in multiple approved networks for one context, runtime selects the
network where that Gene has the greatest visible incident-edge count, uses
display tier and source TE only as deterministic tie-breakers, and re-roots the
payload so the searched Gene is the sole center. The source TE remains in
selection provenance as `source_center_te`.

Missing or unknown TE names never fall back to L1HS. `CR1` intentionally has no
`cancer_cell_line` display network and must show its two available alternatives.

## Visual Semantics

- selected center: uses the same degree-based size rule as other nodes and is
  distinguished by its fill, strong outline, and persistent label;
- partner TE: lighter blue circle than a selected TE center;
- Gene: green node, with a darker green fill when selected as the center;
- module hub: stronger outline, not a separate entity type;
- edge width and opacity: monotonic with positive correlation;
- no arrows, causal verbs, or default edge labels.

The legend uses an explicit Apply action. Turning off TE visibility hides
partner TE nodes but preserves the center TE. Turning off Gene visibility
removes Gene nodes and their incident edges. `Center edges` retains only edges
incident to the selected center; `All selected edges` also retains internal
edges whose endpoints pass the node filters.

## Expression Activity

Expression activity belongs to the Co-expression toolbar, not the Knowledge
Graph toolbar. It uses batched TE and Gene requests to
`api/graph_expression.php`; both entity types use measured values from the
shared MySQL expression summaries.

For the active context class:

- every TE or Gene with data receives a static activity halo;
- halo strength is log-normalized within the visible network;
- low, medium, and high activity increase halo opacity, ripple count, and
  maximum ripple radius monotonically;
- every TE or Gene with available expression data uses a stable ripple node
  while the layer is enabled; expression strength controls ripple opacity,
  ring count, and maximum radius;
- selection and hover may emphasize labels or outlines, but must not replace a
  node type or trigger a graph redraw during force dragging;
- animation speed is not used as a biological variable;
- missing expression remains explicit in details.

The value is a context-class summary. For example, a `normal_tissue` network
shows each TE or Gene's normal-tissue summary and its top tissue label. It does not
change edge weights, node position, force parameters, or layout cooling.
Expression activity and co-expression are statistical context, not regulation,
propagation, mechanism, or causality.

## Details and Tooltips

Center details include module ID/type/size, TE and Gene counts, confidence,
enriched context, interpretation text, and Expression activity.

Partner details include feature type, role, hub status, correlation to center
when present, and Expression activity for both TE and Gene nodes.

Edge details include source, target, Spearman correlation, FDR, pair type, edge
role, and the statistical-association boundary. Runtime text is escaped.

## URL, Cache, Retry, and Export

Supported direct URL:

```text
preview.php?mode=te_gene&te=L1HS&scope=all
```

Single-tissue direct URL:

```text
preview.php?mode=te_gene&te=L1HS&scope=tissue&tissue=Liver
```

Real user mode/selection changes push browser history. Back/Forward restores
mode, TE, and context without recreating the iframe. Mode restoration does not
infer a TE from a Knowledge Graph query.

Network and Expression caches are independent, version-bound, LRU-like, and
capped at six entries. Request epochs and abort signals prevent stale responses
from replacing newer selections.

Failures retain the selected feature/context and expose Retry. CSV export contains
visible nodes/edges, edge label, scope, supporting tissues, eQTL summary values,
correlation, FDR, Expression values, analysis version, method, thresholds, and
the interpretation limit. PNG export comes from the active TE-Gene Graph Canvas.
SVG export serializes the current filtered network
from final G6 positions as vector edges, static Expression rings, nodes, and
labels with an automatically padded `viewBox`. It uses the shared
`assets/js/renderers/g6/g6-svg-export.js` serializer while retaining a
Co-expression-specific snapshot adapter; Knowledge Graph and Co-expression do
not share one G6 instance. No export may contain absolute paths.

## Representative Acceptance Matrix

- `L1HS`: dense high-confidence, low-confidence, and not-interpretable contexts;
- `LTR5 / cancer_cell_line`: smaller gene-rich high-confidence graph and low
  activity;
- `MER11B / cancer_cell_line`: TE-only, zero-Gene, not-interpretable graph;
- `HERVH-int / cancer_cell_line`: dense gene-rich graph with high activity;
- `CR1 / normal_tissue`: small gene-rich high-confidence graph;
- `CR1 / cancer_cell_line`: unavailable context with recoverable alternatives.
- `C1orf116 / cancer_cell_line`: Gene-centered network with one Gene center and
  preserved source-TE provenance.

Desktop acceptance covers 1440x960, 1280x900, and 1024x768. Mobile layout and
mobile screenshots are out of scope.

## Primary Checks

- `scripts/checks/check_coexpression_task8_static.py`
- `scripts/checks/check_coexpression_task8_browser.py`
- `scripts/checks/check_coexpression_task9_static.py`
- `scripts/checks/check_coexpression_task9_browser.py`
- `scripts/checks/check_coexpression_graph_browser.py`
- `scripts/checks/measure_coexpression_graph_performance.py`
