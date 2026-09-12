# TE/Gene Co-expression Data And Runtime

Last reviewed: 2026-09-11

This directory documents Co-expression audits, method choices, interpretation
boundaries, offline artifacts, and the browser runtime.

## Analysis Standard

The accepted main analysis is:

```text
Input network: data/coexpression/analysis/v1/abs0.4_fdr0.05/
Module result: data/coexpression/modules/v1_abs0.4_fdr0.05_res1.8/
Correlation: Spearman
Edge filter: |correlation| >= 0.4 and FDR <= 0.05
Module input: positive edges only
Module algorithm: NetworkX Louvain
Random seed: 42
Resolution: 1.8
```

The chosen thresholds balance retained TE-Gene signal and module granularity.
Alternative folders are sensitivity analyses, not competing production
versions.

## Offline Data Layers

- `data/coexpression/networks/v1/`: computed feature-feature networks.
- `data/coexpression/analysis/v1/`: filtered networks and summaries.
- `data/coexpression/modules/v1_abs0.4_fdr0.05_res1.8/`: accepted modules.
- `data/coexpression/modules/parameter_scan/`: resolution scans.
- `data/coexpression/display_subgraphs/`: versioned display/import artifacts.

These files preserve provenance and support imports. Production browser requests
read validated MySQL tables rather than treating these files as an ad hoc
runtime database.

## Runtime Interfaces

- `api/coexpression.php`, `api/coexpression_repository.php`: legacy-compatible
  catalog/network interface and Gene-centered behavior.
- `api/te_gene.php`, `api/te_gene_repository.php`: current TE-Gene payload
  combining Co-expression and active eQTL evidence.
- `api/graph_expression.php`: measured expression overlay.
- `assets/js/pages/preview/coexpression-mode.js`: parent controller.
- `assets/js/renderers/g6/coexpression/`: dedicated G6 renderer.
- `assets/js/pages/preview/integrated-mode.js`: reuse of TE-Gene evidence in
  Integrated Graph without changing its scientific meaning.

The TE-Gene Graph and Integrated Graph have independent renderer instances.
Integrated Graph visually merges matching TE/Gene nodes with Knowledge Graph
nodes but retains source-specific edges and evidence.

## Network Selection

A runtime unit is a selected TE or Gene in an available expression/eQTL context.
Approved display networks are bounded for browser rendering. Gene selection is
case-insensitive but exact and deterministically chooses the approved network
where the Gene has the strongest visible incident-edge representation.
Selection provenance retains the source center TE.

Unknown features and unavailable contexts remain explicit. They never fall back
to L1HS.

## Expression Activity

Expression activity is a measured visual overlay for TE and Gene nodes. It is
loaded from MySQL summaries and does not change edge weights, graph topology,
force parameters, or the statistical definition of Co-expression.

## Interpretation Boundary

- Co-expression is statistical association, not regulation or causality.
- Module membership is a community in correlation space, not a validated
  pathway.
- Expression level and network centrality are different quantities.
- A safe enrichment statement is that a TE belongs to a module whose Gene
  members are enriched for a term; this alone does not assign that function to
  the TE.
- A TE-Gene edge labeled Both retains separate Co-expression and eQTL evidence;
  it is not a stronger causal claim by definition.

## Representative Runtime Cases

- L1HS: dense baseline across available context classes.
- LTR5 / cancer cell line: smaller Gene-rich graph.
- MER11B / cancer cell line: TE-only display.
- HERVH-int / cancer cell line: dense Gene-rich display.
- CR1 / normal tissue: small Gene-rich display.
- CR1 / cancer cell line: intentionally unavailable, with alternatives.
- C1orf116 / cancer cell line: Gene-centered rerooted display.

## Related Documents

- `frontend_contract.md`: browser behavior and acceptance contract.
- `data_audit.md`: expression matrix and metadata audit.
- `method_design.md`: method design history.
- `display_tier_recommendations.md`: offline display-tier decisions.
- `../eqtl/README.md`: eQTL scientific and runtime contract.
