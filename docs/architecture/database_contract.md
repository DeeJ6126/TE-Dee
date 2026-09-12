# TE-Dee Database Contract

Last reviewed: 2026-09-11

This contract lists runtime invariants. Exact scientific row counts belong in
version manifests and subsystem checks; architecture code should not duplicate
them unless the count is itself a product contract.

## Configuration Contract

- Runtime configuration is loaded through `api/runtime_config.php`.
- Secrets remain in ignored `api/config.local.php`.
- Missing or invalid configuration fails explicitly.
- No runtime fallback to historical Neo4j databases or obsolete expression
  roots is permitted.

## Neo4j Contract

- The configured production database is reachable and answers a trivial query.
- TE nodes and literature relationship edges are non-empty.
- Relationship payloads retain their detailed relation label and PMID/evidence
  metadata.
- Representative entities such as L1HS, AluJb, and SVA return valid partial or
  complete payloads.
- Taxonomy requests are served through `api/taxonomy.php`; Tree and Canvas
  Graph are views of the same API truth.
- Path queries are read-only, bounded, and do not return routes that revisit an
  entity.

## Browse Catalog Contract

- `tekg_catalog` has exactly one active validated catalog version.
- Active names are case-insensitively unique.
- Catalog entries retain Repbase/RMSK classification provenance.
- Browse table, filters, and autocomplete read the same active version.
- Home TE classification/composition uses the same catalog population.
- Catalog failure does not silently fall back to local JSON or the subset of
  Neo4j TEs with literature relations.
- Previous versions remain recoverable through the versioned import design.

The current deployed catalog contains 276 unique TE names, but import validation
and active-version metadata are the authority for future updates.

## Expression Contract

- Expression runtime reads MySQL summaries and the active
  `data/bulk_expression_web` asset root where file access is required.
- Expression autocomplete queries the expression feature catalog, not the
  Browse or Neo4j taxonomy catalog.
- Context values distinguish Normal tissue, Normal primary cell, and Cancer
  cell line while preserving compatibility with existing internal keys.
- Missing data and runtime errors are not presented as biological zeroes.
- Public exclusions intentionally omit `(CATTC)n` and `(GAATG)n`.

## Co-expression Contract

- The active analysis version and thresholds are explicit in metadata.
- Runtime networks are read from validated MySQL tables.
- Offline JSON/TSV/Parquet artifacts are not browser runtime truth.
- Gene-centered search is exact and deterministic.
- Unknown features or unavailable contexts never fall back to L1HS.
- Edges represent statistical correlation, not regulation or causality.
- Expression activity is a measured overlay and does not alter edge weights.

## eQTL Contract

- One validated eQTL version is active at a time.
- TE-instance/Variant overlap and Variant/Gene/Tissue association remain
  normalized, with tissue and cross-tissue summaries as indexed projections.
- The current production rule is strict GRCh38 reference-span intersection,
  without a flanking/proximity window.
- Runtime reads MySQL, not staging tar/Parquet/TSV/SQLite files.
- eQTL evidence is positional/statistical and does not establish TE-mediated
  regulation.

## TE-Gene And Integrated Graph Contract

- `api/te_gene.php` presents Co-expression, eQTL, or Both aggregate pair
  evidence without destroying underlying provenance.
- Knowledge Graph and TE-Gene Graph remain separate API/storage domains.
- Integrated Graph is a client-side combination, not a third database.
- Matching TE and Gene nodes may be merged visually; source-specific payloads
  remain inspectable.
- Knowledge and TE-Gene edges between the same endpoints remain distinct
  parallel edges.
- Classification edges are excluded from Integrated Graph.
- Detailed Knowledge relation labels are preserved.
- Filters, context, expression activity, export, and route state must not mutate
  stored scientific data.

## API Health Contract

Representative checks should verify:

- health/configuration reachability;
- `api/graph.php?q=LINE1` returns an anchor and non-empty elements;
- non-empty Graph and taxonomy payloads;
- active Browse catalog metadata and representative names;
- Expression catalog lookup and representative summaries;
- Co-expression/TE-Gene availability and explicit unavailable states;
- active eQTL version and row-count/hash manifest consistency;
- Integrated Graph static contract and nonblank browser render.

## Related Checks

- `scripts/checks/check_runtime_db_config.py`
- `scripts/checks/check_no_legacy_db_fallback.py`
- `scripts/checks/check_neo4j_tekg3.py`
- `scripts/checks/check_api_contracts.py`
- `scripts/checks/check_browse_catalog_mysql_contract.php`
- `scripts/checks/check_home_stats_api.py`
- `scripts/checks/check_expression_catalog_autocomplete.py`
- `scripts/checks/check_coexpression_repository.php`
- `scripts/checks/check_gtex_eqtl_mysql_contract.php`
- `scripts/checks/test_te_gene_repository_contract.php`
- `scripts/checks/check_preview_integrated_graph_static.py`
