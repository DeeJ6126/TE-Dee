# First-Draft Editorial Record

Prepared 3-4 September 2026. This accompanies the [English draft](manuscript_en.md)
and [section-aligned Chinese translation](manuscript_zh.md); it is not manuscript
text. The English draft contains 4,147 words before the references by the
bundled checker's token rule, including tables and author placeholders; the
abstract contains 150 words. No figures are included.

## Writing Decisions

The resource, not the website or eQTL analysis alone, is the subject. Methods
describe record generation; Data Records describe files and keys; Technical
Validation reports specific checks and their available outcomes. The short
Data Overview separates the dated literature snapshot from the eQTL version.

| Writing model | Adopted organization, not borrowed sentences |
| --- | --- |
| PrimeKG | Research need, fragmented records, integration problem, resource scope; source-processing-output order in Methods |
| PubMed KG | Distinguish name normalization, identity mapping and evaluation |
| PharMeBINet | State the identifiers and acceptance conditions used in mapping |
| SciSciNet | Explain record units, joins, deduplication and count reconciliation |
| MatKG | Connect extraction-quality claims to a recoverable review sample |

The five original PDFs remain in [writing_examples](../writing_examples/README.md).
They inform exposition, not the scientific evidence cited for TE-KG. The
[current Scientific Data instructions](https://www.nature.com/sdata/submission-guidelines)
were checked on 3 September 2026: the title avoids the dataset brand, colon and
parentheses; the abstract is below 170 words; no Results, Discussion or
Conclusion section or worked Usage Notes case is added. Older examples do not
override these current instructions.

## Essential Author Inputs

The in-text `AUTHOR_INPUT` markers identify the relevant locations. They are
not claims that the missing work has been completed.

1. Confirm the extraction model release/settings and recover the canonical-name
   curation decisions and manual-review records. Earlier three 50-paper audit
   percentages are omitted because sample IDs, decisions and denominators were
   not available to substantiate them.
2. Supply the upstream expression read-processing, TE quantification and
   normalization method. Confirm the experiment-level SRP013565 subset before
   calling it exclusively primary cells; the draft uses normal-cell context.
3. Freeze the Repbase, RepeatMasker, HGNC and ICD-11 source releases and their
   reuse permissions. Representative sequences and third-party data should not
   be assigned a blanket redistribution license without checking their terms.
4. Establish the archival accession, final file inventory, public service URL,
   code archive and licenses. The draft describes existing local products,
   not a completed public deposit.
5. Preserve a complete symbol/status/base-ID mapping export for release. The
   retained Gene audit currently contains aggregate counts and examples, not
   every row. Its exact-case uniqueness rule and the runtime case-folded
   allowlist need a release-specific parity check. The draft reports the audit
   rule as an audit rule, not a universally enforced runtime guarantee.
6. Confirm authors, contributions, ethics applicability, funding and competing
   interests. Finalize the AI-use disclosure after author review.

These are manuscript/release inputs, not a request to repeat the full eQTL
analysis. Before submission, validate the frozen release files themselves;
this drafting task did not re-hash every production partition or requery all
database records.

## Fact-to-Source Map

| Draft content | Inspected evidence and interpretation |
| --- | --- |
| Literature query, 13 April 2026 cutoff, DeepSeek-V3, 80% similarity, 2,308 retained papers | [Original methods document](../../methods/method_english.docx); unsupported intermediate screening totals were not combined |
| 2,308 Paper nodes, 225 TE nodes, 12,444 relationships | [31 July 2026 snapshot](../evidence/snapshots/legacy_content_snapshot_20260731.csv); localhost statistics endpoint was unavailable during this task, so these are not fresh live counts |
| Source accessions and matrix/metadata accounting | [Expression processing manifest](../../../data/bulk_expression_web/processed/expression_processing_manifest.json) and [processing code](../../../scripts/build/prepare_expression_assets.py): 205/307/646 matrix columns versus 200/307/646 metadata-linked summary columns |
| Feature confidence and selection | [Annotation provenance](../../../data/coexpression/feature_annotation/feature_annotation_sources.md), [network manifest](../../../data/coexpression/networks/v1/coexpression_run_manifest.json) and [construction code](../../../scripts/coexpression/build_te_gene_coexpression.py): 290 TE features; 23,148 high-confidence genes; top 2,000 genes/context |
| Correlations and modules | Same construction code; [module manifest](../../../data/coexpression/modules/v1_abs0.4_fdr0.05_res1.8/module_detection_manifest.json): absolute r >= 0.4, BH-adjusted p <= 0.05, positive edges only for Louvain, resolution 1.8, seed 42 |
| Strict reference-allele overlap and deduplication | [Overlap core](../../../scripts/eqtl/gtex_overlap_core.py), [all-tissue builder](../../../scripts/eqtl/build_gtex_all_tissues.py), [consolidator](../../../scripts/eqtl/consolidate_gtex_mysql_artifacts.py): half-open REF span, same chromosome, strict intersection, no flanking window |
| 50 tissues; 104,901,807 inputs; 10,676,462 instance evidence rows | [Completed all-tissue manifest](../evidence/snapshots/eqtl_all_tissue_manifest.json), version `gtex_v11_strict_te_overlap_v1` |
| Eight table counts; 130 partitions; 16,510,562 total exported rows | [MySQL export manifest](../evidence/snapshots/eqtl_mysql_manifest.json) and [table counts](../tables/eqtl_table_counts.csv); export total includes dimensions and summaries |
| 276 catalog names, 202 mapped, 74 unmapped, 596,140 instances | [Browse mapping](../evidence/snapshots/browse_te_mapping.tsv); instances define the search space, not all eQTL-positive instances |
| 3,281 symbols; 3,243 eligible; 38 unmatched; 7,715 potential Both pairs | [Retained audit](../evidence/snapshots/gene_mapping_audit.md), captured 2 September 2026; the audit execution date was not recorded |
| Record fields and service organization | [Data dictionary](../evidence/data_records_dictionary.md), [runtime/reuse material](../evidence/runtime_and_reuse.md) and the corresponding processing/API source |

Terminology is consistent across languages: a **TE name** is not a genomic
**TE instance**; normalized counts are not labeled TPM; **co-expression
context** and **GTEx tissue** are separate axes; **Both** denotes two retained
evidence types for the same TE-gene identity. Full versioned gene IDs identify
association records; suffix-stripped IDs support the separate mapping audit.

## References and AI-Assisted Review

[References](references.md) contains 14 journal articles and one official GTEx
v11 data-source entry. [BibTeX](references.bib) retains full available author
lists. [Metadata](reference_metadata.json) preserves the retrieved records and
timestamps. All 14 DOI identifiers resolve to matching Crossref records; 12
also have exact-DOI Europe PMC records. The Benjamini-Hochberg paper was checked
against its [publisher record](https://academic.oup.com/jrsssb/article/57/1/289/7035855),
and Blondel et al. against the [original preprint](https://arxiv.org/abs/0803.0476).
Dfam's online publication date is in 2012, but volume 41 belongs to 2013; the
reference uses the issue year. The 2020 GTEx paper supplies project context,
not v11-specific processing claims. The latter use the
[official download collection](https://gtexportal.org/home/downloads/adult-gtex/overview)
and the local archive/manifest. References are linked to their specific source
or method roles; the five writing examples are not inserted as decorative citations.

The requested workflow used nature-academic-search with local evidence checks,
nature-writing adapted to Data Descriptors, nature-ref-verifier, and an internal
nature-reviewer/nature-polishing pass. The CNS-only journal filter in
nature-citation was not applied to exclude relevant primary data sources.
This was one AI-assisted review, not three independent reviewers or completed
human verification. Review corrected the correlation sign rule, sample-count
denominators, Dfam year, mapping-report granularity and test-description scope.
OpenAI Codex assisted with research organization, drafting, editing and
translation; this is disclosed in Methods pending author confirmation under
the [Nature Portfolio AI policy](https://www.nature.com/nature-portfolio/editorial-policies/ai).

## Verification Scope

- Ten existing eQTL unit tests passed using `data/eQTL/.venv/Scripts/python.exe`.
  The default Anaconda environment first rejected the Parquet fixtures because
  of the existing PyArrow 19.0.0 compatibility guard; using the project
  environment resolved this without installing or changing packages.
- Seven existing co-expression tests passed. These are synthetic/implementation
  checks, not extraction accuracy or biological validation experiments.
- The eQTL indexed-query fixture compares two input routes to the same matcher,
  not an independent overlap algorithm. The draft now describes that precisely.
- [Material checker](../scripts/check_materials.ps1) checks retained-source
  hashes and manifest arithmetic. [Draft checker](../scripts/check_first_draft.py)
  checks bilingual sections, citations, numeric literals, terminology tokens,
  tables and placeholders. Its output is [verification.json](verification.json).
  Both passed on 4 September 2026: 118 legacy sources, ten retained copies,
  nine official downloads and five paper PDFs were unchanged; 165 local links
  resolved. All 13 bilingual sections passed the mechanical consistency checks.
- No bulk eQTL computation, database modification, website/SVG edit, Git commit
  or push was performed for this draft.
