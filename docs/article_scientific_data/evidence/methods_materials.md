# Methods Materials

Prepared 2026-09-02. Source and parameter notes only; not a drafted Methods section.

## Literature and Identity (Inherited)

From the old source register/canon: PubMed TE-focused MeSH/free-text search with
human restriction; reported coverage through 2026-04-13; screening, constrained
DeepSeek-V3 extraction, whitelist filtering, normalization and manual curation.
The approved historical final corpus is 2,308 papers. Intermediate counts do
not reconcile, and the sampled decisions for three 50-record manual audits are
not retained in this material package. Do not report their percentages or
confidence intervals as reproducible validation.

Recover full query, screening ledger, retained PMIDs, prompts, model endpoint/
version, extraction schema, normalization rules, curator decisions, software
environment and extraction-to-graph mapping. Older references to
`docs/methods/method_english.docx` are provenance pointers, not an inspected primary
document in this migration.

RepeatMasker genomic occurrences and RepBase identity/consensus records must
remain distinct. Preserve TE names, aliases, class/family, source IDs, assembly,
coordinate system and exact cross-layer mapping rules.

## Expression and Co-expression (Inherited)

- Sources: E-MTAB-1733, E-MTAB-2836, SRP013565 and PRJNA523380.
- Contexts: normal tissue, normal primary cell (`normal_cell_line` runtime
  name), and cancer cell line. They are independently sourced, not matched
  case/control samples.
- Recorded method: log2(normalized_count + 1), Spearman association,
  abs(r) >= 0.4, BH-FDR <= 0.05, positive retained edges for Louvain, seed 42,
  resolution 1.8; version `v1_abs0.4_fdr0.05_res1.8`.
- Preserve full offline networks separately from approved display networks.
- Recover exact pair-testing universe, constant/missing feature handling,
  correction family per context, source preprocessing, package versions,
  sample inclusions/exclusions and parameter-sensitivity source tables.
- Historical annotation counts (290 TE, 24,261 Gene, 2 simple-repeat and 13,315
  uncertain features) are reported in the old inventory, not re-audited here.
  Analysis selection of 290 TE and 23,148 Gene features must be independently
  reconciled before final reporting.

## GTEx v11 Input and Selection

Use only the supplied archive's paired
`<tissue>.v11.eQTLs.signif_pairs.parquet` and
`<tissue>.v11.eGenes.txt.gz` members. The builder discovers 50 complete pairs.
These are upstream-selected associations; TE-KG adds no further p-value cutoff.
It does not recompute genotype-expression QTLs, perform fine-mapping, or process
all nonsignificant tests.

Inputs:
- `data/eQTL/GTEx_Analysis_v11_eQTL.tar`
- `data/JBrowse/repeats/hg38.rmsk.repeats.bed`
- `data/processed/te_repbase_db_matched.json`

Input hashes, coordinates and counts are in the
[retained manifest](snapshots/eqtl_all_tissue_manifest.json).

## Overlap, Annotation and Normalization

1. Load approved Browse names and matching hg38 BED intervals; retain a full
   mapped/unmapped name report. The file called `missing_browse_te.tsv`
   actually contains all 276 names, not only the 74 unmapped ones.
2. Parse b38 Variant IDs into chromosome, 1-based position, REF and ALT.
   Normalize chromosome spelling; reject unsupported identifiers rather than
   assigning them by proximity. This parsing is not a claimed VCF left-alignment
   or equivalent-indel canonicalization procedure.
3. Use [position - 1, position - 1 + length(REF)) and same-chromosome interval
   intersection with [te_start, te_end). SNPs span one reference base; insertions
   retain the anchor REF span and deletions may span multiple reference bases.
   Boundary-only touching does not count.
4. Join tissue associations to intersecting instances. Preserve full Gene ID
   and derive `gene_id_base` by removing a trailing numeric version suffix.
   Companion eGenes records supply Gene names/biotypes/coordinates.
   Gene starts are converted to 0-based; end coordinates produce half-open spans.
5. Deduplicate at tissue/TE-instance/Variant/full-Gene-ID grain. Conflicting
   repeated associations or inconsistent annotations are integrity errors,
   not observations to average away.
6. Consolidate dimension tables, instance-Variant overlaps and normalized
   Variant-Gene-Tissue associations. Retain tissue-specific statistics.
7. Derive per-TE-name/Gene/tissue and cross-tissue summaries. Count distinct
   supporting Variants and instances explicitly; evidence-row counts count
   joins and can repeat one association across multiple TE instances.
   Minimum p-value and maximum absolute slope are descriptive extrema, not
   pooled significance or an average effect.
8. Export gzip TSV partitions with schema/row counts/hashes; import ledger and
   version validation govern resumable MySQL import. Archive identity is not
   permission to redistribute the upstream files.

Source implementation:
`scripts/eqtl/gtex_overlap_core.py`,
`scripts/eqtl/build_gtex_all_tissues.py`,
`scripts/eqtl/consolidate_gtex_mysql_artifacts.py`,
`scripts/eqtl/import_gtex_eqtl_mysql.php`.

## Gene-Mapping Audit

`scripts/eqtl/audit_gene_mapping.py` tests offline `TE_gene` edges against
`data/coexpression/feature_annotation/feature_annotation.tsv` and GTEx Gene
dimensions. Eligibility requires high-confidence Gene annotation, an exact
symbol match, and exactly one base Gene ID. Full versioned Gene IDs are not
discarded from underlying records.

The audit records five output categories, not nine populated categories:
unique high-confidence; unique-name/non-high-Gene annotation; low confidence;
ambiguous; unmatched. Three categories have zero counts in this snapshot.
See [mapping results](gene_mapping_materials.md).

## Small Illustrative Coordinate Cases

These are synthetic rule examples, not new biological observations.
For TE interval chr1:[100,200), Variant intervals [99,100) and [200,201)
do not overlap; [100,101) does; deletion REF span [99,102) also does despite
not being fully contained. A SNP at 1-based position 101 becomes [100,101).
An otherwise identical interval on chr2 does not match.
