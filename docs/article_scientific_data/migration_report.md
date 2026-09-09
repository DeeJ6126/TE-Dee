# Migration Report

Prepared 2026-09-02.

## Disposition

Reviewed the legacy workspace and recorded a decision and original SHA-256 for
all 118 files in [migration_manifest.csv](migration_manifest.csv).
Actions: 5 unchanged copies, 6 adapted materials,
14 selective extractions and 93 files not carried forward.
Excluded means left in the old folder, not deleted.

The unchanged-copy ledger also includes five newer eQTL provenance files:
[copy_provenance.json](copy_provenance.json). In total, ten files were copied
byte-for-byte. No multi-GB data products were duplicated.

## What Was Carried Forward

- Scientific scope, facts, interpretation boundaries and claim IDs C001-C025.
- Source identity/processing/rights gaps, including the bilingual source register.
- Dated graph/catalog/expression/co-expression counts and reproduction queries.
- Historical evaluation evidence, explicitly separated from current validation.
- All 22 BibTeX entries; six former writing exemplars are optional scientific
  background, not new-journal stylistic requirements. References were not
  reverified in full and an exact GTEx v11 reference remains open.
- Figure/table source requirements and candidate use-case evidence, not old
  manuscript paragraphs, layout instructions or mandatory figure numbering.
- Statistics, provenance, reference and availability gaps from legacy QA.

## What Was Excluded

All old manuscripts and translations, compiled PDFs/DOCX/LaTeX products,
OUP templates and style files, Word generators, submission checklist/cover
letter, formatting QA/render pages, target-journal examples/workflow state
and required section ordering remain only in the legacy directory.

No manuscript Abstract, Introduction, Methods prose, Results or Discussion
was drafted. The new Methods file is a record of inputs, algorithms, fields
and unresolved evidence.

## New Material Added

- A data-centered preparation scope, with Data Descriptor marked provisional.
- GTEx v11 significant-pair input, reference-span overlap contract and hashes.
- Eight normalized table grains/column lists, 50-tissue counts and record-unit
  distinctions.
- Gene mapping counts, unmapped-name audit and Both interpretation limits.
- Current Browse/Graph source observations and explicit release-verification
  gaps where the API/audit/frontend claims differ.
- Updated source/rights, validation, release and author-decision registers.

## Important Corrections

The old 50-node display cap is historical. The appended graph implementation
uses a 100-node budget; another API path remains differently bounded.
Gene identity exclusions do not establish biological insignificance.
TE-Gene summary extrema are not combined statistics.
ClinVar BigBed tracks are not claimed as a new MySQL tabular release.
GTEx signif_pairs selection still applies despite no extra TE-KG p cutoff.

The source method document moved: legacy `docs/method_english.docx` references
now resolve to `docs/methods/method_english.docx`. Adapted files use that path;
byte-preserved historical snapshots retain their original references.

## Verification Scope

See [verification.md](verification.md) and
[scripts/check_materials.ps1](scripts/check_materials.ps1). The checks address
migration integrity, metadata arithmetic and local links, not new biological
validation, full data partition scans or a live MySQL recount.

The destination was ignored by the existing blanket docs rule. A narrowly
scoped .gitignore exception makes this material package and the completed plan
visible to Git; it does not add datasets or old compiled manuscripts.
