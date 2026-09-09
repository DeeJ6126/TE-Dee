# Data Sources

This document records current runtime data sources. Its main purpose is to
prevent old paths, old databases, and old taxonomy truth sources from returning
to active runtime.

## Neo4j

- Active runtime database: `tekg3`
- Local configuration entry: `api/config.local.php`
- Shared runtime reader: `api/runtime_config.php`
- Main consumers: `api/graph.php`, `api/taxonomy.php`, `api/health.php`,
  `api/te_metrics.php`

## TE Taxonomy

- Canonical runtime source: Neo4j `TE` node taxonomy properties exposed through
  `api/taxonomy.php`.
- Homepage taxonomy and ring chart data should prefer PHP-side Neo4j/API access.
- Historical taxonomy files may be references or build inputs, but they must not
  become a second runtime truth source.

Important files:

- `data/taxonomy/transposon_tree/tree_rmsk_repbase.txt`
- `data/taxonomy/transposon_tree/tree_all.txt`
- `data/processed/tekg3_taxonomy_standardization_report.json`
- `data/processed/tekg3_homepage_taxonomy.json`

## Expression

- Active runtime root: `data/bulk_expression_web`
- Do not restore old root: `data/raw/new_data/bulk_expression_web`
- MySQL summary tables are the primary runtime source for expression pages.
  TSV fallback paths must also point to the active root.

## Browse Catalog

- Active runtime database: MySQL `tekg_catalog`.
- Active tables: `browse_catalog_versions`, `browse_catalog_entries`.
- Runtime API: `api/browse.php?view=items`.
- Import source: `data/processed/te_repbase_db_matched.json`.
- Import entrypoint: `scripts/import/import_browse_catalog_mysql.php`.
- The table, filters, and Browse-only autocomplete consume one API payload.
- Runtime must not fall back to the processed JSON or Neo4j when the catalog is
  unavailable. Neo4j remains the canonical TE taxonomy truth; Browse lineage
  columns are a provenance-tagged display snapshot only.

## Genome / JBrowse

- JBrowse runtime data lives under `data/JBrowse/`.
- `jbrowse.php` is the active entrypoint.

## GTEx eQTL

- Active runtime database: MySQL `tekg_expression`.
- Active version: `gtex_v11_strict_te_overlap_v1`.
- Source archive: `data/eQTL/GTEx_Analysis_v11_eQTL.tar`.
- TE interval input: `data/JBrowse/repeats/hg38.rmsk.repeats.bed`.
- Browse-name input: `data/processed/te_repbase_db_matched.json`.
- Versioned provenance/import root:
  `data/eQTL/derived/gtex_v11_strict_te_overlap_v1/`.
- Runtime must not read the tar, Parquet, gzip TSV, or SQLite staging database.
  It must select the sole validated active MySQL version.
- The approved mapping rule is GRCh38 reference-span intersection with no
  flanking window. See `docs/eqtl/README.md` for the coordinate contract and
  interpretation boundary.

## Related Checks

- `scripts/checks/check_no_legacy_db_fallback.py`
- `scripts/checks/check_taxonomy_runtime_truth.py`
- `scripts/checks/check_expression_paths.py`
- `scripts/checks/check_taxonomy_runtime_consistency.py`
- `scripts/checks/check_browse_catalog_mysql_contract.php`
- `scripts/checks/check_browse_mysql_api.py`
- `scripts/checks/check_gtex_eqtl_all_tissues.py`
- `scripts/checks/check_gtex_eqtl_mysql_static.py`
- `scripts/checks/check_gtex_eqtl_mysql_contract.php`
