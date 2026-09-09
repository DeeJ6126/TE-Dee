# Content Inventory and Counting Units

Prepared 2026-09-02. Source dates and evidence levels are part of every count.

## Historical Foundation

[Legacy count table](snapshots/legacy_content_snapshot_20260731.csv):
225 Neo4j TE nodes, 2,308 Paper nodes, 12,444 directed biological relations,
276 Browse entries, 192 classified taxonomy TEs, 1,158 expression samples,
784 searchable co-expression entries (285 TE + 499 Gene), and 10 configured
Download files. These are 2026-07-31 records, not current live counts.

The legacy report also records 849 TE-context display products: 17 core_case,
287 high_confidence, 542 searchable_all and 3 not_recommended_default;
281 cancer-cell-line, 285 primary-cell and 283 normal-tissue products.
These are selection categories, not statistical significance levels.

Do not reuse the old universal 50-node display limit: the inspected append
implementation now budgets 100 nodes and 150 edges. Separate API paths can
have different limits. None describes the full dataset.

## eQTL Version

`gtex_v11_strict_te_overlap_v1`. Totals below were checked against retained
JSON manifests, not recomputed by scanning all biological rows or querying
MySQL today.

- 50 named archive tissue categories.
- 104,901,807 input significant-pair associations.
- 276 approved Browse names: 202 matched, 74 without hg38 matches.
- 596,140 approved TE intervals. This is the mapped input universe, not a
  count of instances guaranteed to have eQTL evidence.
- 10,676,462 instance-level TE-Variant-Gene-Tissue evidence rows.
- The normalized import table counts are in [eqtl_table_counts.csv](../tables/eqtl_table_counts.csv).
- 130 gzip TSV partitions and 16,510,562 total rows across eight tables.
  Summing heterogeneous dimension and fact rows is useful for import accounting,
  not a count of independent associations.

664,555 distinct Variant records differ from 664,902 instance-Variant overlap
pairs: a Variant can overlap more than one TE instance. The 10,670,298 normalized
Variant-Gene-Tissue rows differ from the instance-expanded evidence total.
Likewise 540,906 cross-tissue TE-name/Gene rows are not instance-level links,
nor the number of visible eQTL edges.

[Per-tissue source/evidence totals](../tables/eqtl_tissue_counts.csv) are
generated from the all-tissue manifest. They can support reproducibility checks;
no enrichment, causal discovery or cross-tissue statistical comparison was run.

## Gene Audit Universe

The mapping report uses 3,281 distinct Gene symbols across 170,599 offline
TE_gene edge rows and 169,474 distinct TE-Gene pairs.
It is not the historical searchable display catalog's 499 Genes.
Counts of 7,763 eligible edge rows with eQTL and 7,715 distinct potential Both
pairs differ because contexts can repeat pairs. See
[Gene mapping materials](gene_mapping_materials.md).

## Release Freeze Still Needed

Record one code commit, database release/version, source identities, count
queries and outputs, and a public manifest. Do not silently combine historical
graph counts and later eQTL counts under a single claimed release date.
