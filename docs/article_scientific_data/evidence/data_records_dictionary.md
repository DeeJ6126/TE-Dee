# Data Records Dictionary

Version: `gtex_v11_strict_te_overlap_v1`.
Generated table/column lists below come from the retained MySQL import manifest.
This dictionary describes existing local products; it does not state that they
are deposited in a public repository.

## Storage and File Units

Tissue folders contain `te_variant_gene_overlaps.parquet`,
`te_gene_summary.parquet`, `manifest.json` and `report.md`.
Normalized gzip TSV partitions live under
`data/eQTL/derived/gtex_v11_strict_te_overlap_v1/mysql/`.
The manifest provides ordered columns, partition paths, rows, bytes and SHA-256.
The TSV writer uses tab delimiters, CSV quoting and a header; the release must
confirm importer null conventions and provide a portable read example.

MySQL adds release scoping through `version_id`; it is not a column in these
portable partition column lists. Join within one version. Import bookkeeping
and the active-version pointer are operational metadata, not biological evidence.
A local version ID integer alone is not a persistent cross-release identifier.

## eqtl_tissues

- Grain/key: One named GTEx archive tissue category; tissue_key.
- Manifest rows: 50; partitions: 1.
- Ordered fields: `tissue_key`, `display_name`, `source_member`, `source_row_count`, `evidence_row_count`.

## eqtl_te_instances

- Grain/key: One approved reference TE occurrence; te_instance_key (te_instance_id retained).
- Manifest rows: 596,140; partitions: 3.
- Ordered fields: `te_instance_key`, `te_instance_id`, `te_name`, `te_class`, `te_family`, `chrom`, `te_start`, `te_end`, `te_strand`.

## eqtl_variants

- Grain/key: One parsed b38 Variant identity; variant_key (original variant_id retained).
- Manifest rows: 664,555; partitions: 3.
- Ordered fields: `variant_key`, `variant_id`, `chrom`, `variant_start`, `variant_end`, `ref`, `alt`.

## eqtl_genes

- Grain/key: One full versioned Gene ID; gene_id (gene_id_base is not this primary key).
- Manifest rows: 52,962; partitions: 1.
- Ordered fields: `gene_id`, `gene_id_base`, `gene_name`, `biotype`, `chrom`, `gene_start`, `gene_end`, `strand`.

## eqtl_te_variant_overlaps

- Grain/key: One distinct te_instance_key + variant_key intersection.
- Manifest rows: 664,902; partitions: 3.
- Ordered fields: `te_instance_key`, `variant_key`.

## eqtl_variant_gene_tissue_associations

- Grain/key: One tissue_key + variant_key + full gene_id association.
- Manifest rows: 10,670,298; partitions: 66.
- Ordered fields: `tissue_key`, `variant_key`, `gene_id`, `start_distance`, `af`, `ma_samples`, `ma_count`, `pval_nominal`, `slope`, `slope_se`, `pval_nominal_threshold`, `min_pval_nominal`, `pval_beta`.

## eqtl_te_gene_tissue_summary

- Grain/key: One tissue_key + te_name + full gene_id summary.
- Manifest rows: 3,320,749; partitions: 50.
- Ordered fields: `tissue_key`, `te_name`, `gene_id`, `supporting_variant_count`, `supporting_instance_count`, `evidence_row_count`, `minimum_pval_nominal`, `maximum_abs_slope`, `positive_slope_count`, `negative_slope_count`, `direction_class`.

## eqtl_te_gene_cross_tissue_summary

- Grain/key: One te_name + full gene_id cross-tissue summary.
- Manifest rows: 540,906; partitions: 3.
- Ordered fields: `te_name`, `gene_id`, `tissue_count`, `supporting_variant_count`, `supporting_instance_count`, `evidence_row_count`, `positive_tissue_count`, `negative_tissue_count`, `mixed_tissue_count`, `zero_tissue_count`, `minimum_pval_nominal`, `maximum_abs_slope`.

## Field Semantics and Cautions

| Field group | Definition or handling |
| --- | --- |
| te_name / te_family / te_class | Repeat/TE labels attached to the matched BED occurrence; do not confuse classification levels with instance IDs |
| te_start/end, variant_start/end | 0-based half-open coordinates on the recorded chromosome |
| gene_start/end | eGenes annotation converted by the builder to 0-based half-open coordinates |
| ref / alt | Parsed reference and alternate alleles; REF defines the overlap span |
| gene_id / gene_id_base / gene_name | Full versioned identifier, suffix-stripped base identifier, and source name; symbols need mapping audit |
| pval_nominal / slope / slope_se | Source per-association p-value, effect slope and its standard error; not newly estimated TE effects |
| af / ma_samples / ma_count | Source allele-frequency and minor-allele support fields; preserve values; verify exact v11 denominator and allele conventions before publication |
| start_distance | Source signed distance field; preserve it and verify its exact v11 reference-point definition before writing a directional claim |
| pval_nominal_threshold / min_pval_nominal / pval_beta | Retained GTEx fields; not TE-KG's chosen cutoff; release-specific definitions/citations still needed |
| supporting_variant_count | Distinct Variant keys within the stated TE-Gene scope |
| supporting_instance_count | Distinct TE-instance keys supporting the stated scope |
| evidence_row_count in TE-Gene summaries | Rows of the instance-overlap/association join; one association can contribute via multiple instances |
| direction_class | positive_only, negative_only, mixed or zero_only from contributing slopes; current ELSE branch also warrants a null-slope audit |
| positive/negative/mixed/zero_tissue_count | Counts of per-tissue direction classes, not pooled effect estimates or replication counts |
| minimum_pval_nominal / maximum_abs_slope | Minimum nominal p and maximum absolute slope among contributing rows; no meta-analysis |

Source review found `direction_class` uses an ELSE `zero_only` branch when
no positive/negative slopes are counted. Freeze the source null policy and
verify null-slope incidence before interpreting every zero_only group as
biologically zero. No row-level null scan was run during this migration.

The Gene dimension includes annotations loaded from companion eGenes files;
52,962 dimension rows must not be asserted to be 52,962 distinct Genes with
strict TE-overlap support. Quantify linked Gene IDs separately if needed.

## Other Layers Needing Release Dictionaries

| Dataset | Minimum record semantics to freeze |
| --- | --- |
| Literature graph | Stable node IDs, directed predicates, endpoint IDs, PMID lists, support counts, curation provenance |
| TE crosswalk | Source name/ID, aliases, rank, mapping method/status, assembly and release |
| Sequence | Source accession, consensus/reference versus locus-derived, sequence alphabet, release and reuse permissions |
| Expression | Feature ID/type, sample column IDs, numerical unit and normalization, metadata inclusion/exclusion |
| Co-expression | Context, endpoint IDs/types, signed correlation, abs(r), FDR family, module assignment, full-network/display-subset version |
| Gene mapping | Feature symbol/type/confidence, exact matched IDs, category, rejection reason and linked source versions |

Existing evidence permits preparing these dictionaries, not filling unknown
software versions, biological units or licences by inference.
