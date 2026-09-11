# TE-Dee Runtime Data Sources

Last reviewed: 2026-09-11

This file answers one question: which source is authoritative for each runtime
feature?

## Source Matrix

| Domain | Canonical runtime source | Main consumers |
| --- | --- | --- |
| Literature knowledge graph | Neo4j | `api/graph.php`, `api/graph_service.php` |
| Path search and relation evidence | Neo4j | `api/path_finder*.php` |
| TE taxonomy Tree/Graph | Neo4j through `api/taxonomy.php` | Graph classification views |
| Disease hierarchy | Neo4j Graph API payload | disease Tree/Canvas views |
| Browse TE catalog | active MySQL `tekg_catalog` version | Browse and autocomplete |
| Home TE classification/composition | active Repbase + RMSK Browse catalog plus graph entity statistics where explicitly labeled | `api/home_stats.php` |
| Expression catalog and summaries | MySQL `tekg_expression` | Expression pages and activity overlay |
| Co-expression networks | MySQL `coexpression_*` tables | Co-expression and TE-Gene APIs |
| GTEx eQTL overlap | active version in MySQL `tekg_expression` | Variant and TE-Gene APIs |
| Integrated Graph | merge of Knowledge API and TE-Gene API payloads | Integrated parent/iframe renderer |
| Genome browser | files under `data/JBrowse/` | `jbrowse.php`, Genome plugin |
| Repbase sequence/detail | processed Repbase-backed assets | Search/detail and Sequence plugin |

## Neo4j

The active Neo4j database is `tekg3`. Runtime configuration is read from
`api/config.local.php` through
`api/runtime_config.php`. Missing configuration must fail explicitly; it must
not silently select an older database.

Neo4j taxonomy is the sole runtime taxonomy truth. Repbase/RMSK files and
processed taxonomy reports may be import inputs or audit artifacts, but browser
views must not independently interpret them as a second taxonomy database.

## Browse And Home

The active MySQL `tekg_catalog` version is imported from the processed
Repbase + RMSK matching pipeline. Browse table rows, filters, autocomplete, Home
TE Classification, and the TE population used by Home composition must remain
aligned to that active catalog.

This is intentionally different from asking which TE nodes have
literature-derived relationships in Neo4j. A TE can be catalogued and classified
without appearing in the literature graph.

## Expression

- Active file root: `data/bulk_expression_web`.
- Runtime summaries and feature lookup: MySQL `tekg_expression`.
- Expression autocomplete searches the same expression feature namespace as the
  result table.
- Expression and Browse autocompletes are separate because their runtime
  populations are different.

The historical `data/raw/new_data/bulk_expression_web` path must not return as
an active root.

## Co-expression

The approved network analysis is versioned and imported into MySQL. Files under
`data/coexpression/` are provenance, analysis, validation, and importer
artifacts. The production browser does not use them as an ad hoc JSON database.

See `docs/coexpression/README.md`.

## GTEx eQTL

- Source: GTEx v11 eQTL archive.
- Genome assembly: GRCh38/b38.
- TE intervals: hg38 RepeatMasker BED used by the documented pipeline.
- Active runtime version: the sole validated active version in MySQL.
- Runtime must not read source tar, Parquet, gzip TSV, or SQLite staging files.

The association model keeps TE-instance/Variant overlap separate from
Variant/Gene/Tissue association. TE-Gene evidence is their join. See
`docs/eqtl/README.md`.

## Integrated Graph

Integrated Graph has no third scientific database. It combines a Knowledge
Graph payload and a TE-Gene payload in the browser, canonicalizes matching TE
and Gene nodes, and preserves independent edges and evidence details. It must
not write a merged interpretation back into Neo4j or MySQL.

## Configuration And Secrets

- Local secrets: `api/config.local.php`.
- Shared reader: `api/runtime_config.php`.
- Public example: `api/config.local.php.example`.

Never commit the local file, embed credentials in URLs, or copy secret values
into documentation or logs.

## Verification

Relevant checks include:

- `check_runtime_db_config.py`
- `check_no_legacy_db_fallback.py`
- `check_taxonomy_runtime_truth.py`
- `check_browse_catalog_mysql_contract.php`
- `check_home_stats_api.py`
- `check_expression_catalog_autocomplete.py`
- `check_coexpression_mysql_contract.php`
- `check_gtex_eqtl_mysql_contract.php`
- `check_te_gene_api_static.py`
- `check_preview_integrated_graph_static.py`
