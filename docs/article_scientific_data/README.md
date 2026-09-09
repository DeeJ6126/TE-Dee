# TE-KG Scientific Data Preparation Materials

Prepared: 2026-09-02. Updated: 2026-09-04. Status: preparation materials plus an author-requested bilingual first draft, not a completed submission package.

## Purpose and Reading Order

This workspace replaces the legacy journal-specific writing workflow, not the
original evidence. The initial migration covered materials and TE-Gene/Variant
updates only; no manuscript draft or finished figure was created. The existing
About text and artwork were unchanged by that migration.

Official submission resources were subsequently collected at the user's
request: [Scientific Data guidance](journal_guidance/README.md). This separate
reference collection includes an unfilled official checklist, not a manuscript
template or an AI-written paper.

**Authoring authorization (2026-09-03, superseding the earlier no-drafting
instruction for this task):** The user explicitly approved an English first
draft and matching Chinese translation using existing evidence and five
Scientific Data writing examples. See the [English draft](drafts/manuscript_en.md),
[Chinese draft](drafts/manuscript_zh.md) and [editorial notes](drafts/editorial_notes.md).
The draft requires author review and the marked missing inputs. Do not treat
this authorization as permission for unrequested future rewrites or submission.

1. [Preparation scope](00_preparation_scope.md): what is being prepared.
2. [Research facts and boundaries](01_research_facts.md).
3. [Claim evidence register](02_evidence_table.md): inherited claims and new evidence.
4. [Source register](evidence/data_source_register.md) and
   [Methods materials](evidence/methods_materials.md).
5. [Data records dictionary](evidence/data_records_dictionary.md) and
   [count inventory](evidence/content_inventory.md).
6. [Technical validation](evidence/technical_validation.md) and
   [reuse routes](evidence/runtime_and_reuse.md).
7. [Open decisions and gaps](preparation_gaps.md).
8. [Figure and table materials](figures_tables/materials_plan.md).
9. [Literature map](evidence/literature_map.md) and [bibliography](references.bib).
10. [Migration record](migration_report.md), [file decisions](migration_manifest.csv),
    and [verification](verification.md).

## Evidence Levels

- **Historical record:** evidence reported in July/August 2026. Not a fresh
  runtime query; counts and validations retain their original dates.
- **Artifact verified:** checked against retained machine-readable production
  manifests during this migration. Not a complete reread of all Parquet/TSV rows.
- **Source inspected:** implementation read in September 2026; not a browser test.
- **Open:** missing, unresolved, conflicting, or not yet checked for publication.

Paths in backticks are repository-root-relative unless a different base is
explicitly stated. Clickable links point to the actual material.

## Material, Not a Data Deposit

Small provenance snapshots are retained under `evidence/snapshots/`.
Large tar/Parquet/TSV/SQLite products remain at their existing data paths.
This folder is not the full dataset, an archival deposit, or evidence that
redistribution licences and public access have been approved.

The new source of truth for drafting preparation is this folder. The 118 files
in `docs/article_database/` remain an unchanged historical workspace.
