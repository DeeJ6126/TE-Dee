# Scientific Data Materials Migration

## Goal

Prepare evidence and reusable writing materials in `docs/article_scientific_data/`
without producing a manuscript or modifying the legacy workspace.

## Scope

Read `docs/article_database/`, current eQTL artifacts and implementation, and
official Scientific Data guidance. Write only the new materials workspace and
this execution record. Preserve all existing About and other user changes.
No database writes, pipeline reruns, publication, or Git commits.

## Steps

- [x] Inventory and read reusable source, method, count, reference, QA, and
  figure/table materials. Separate journal-neutral evidence from OUP packaging.
- [x] Retain selected reference/query/snapshot files with hashes and provenance;
  synthesize current fact, method, validation, reuse, and gap registers.
- [x] Add versioned TE-Variant/eQTL records, Gene mapping, TE-Gene Graph and
  Browse evidence boundaries from retained reports and current code.
- [x] Verify copied-file hashes, local links, artifact counts and arithmetic,
  exclusions, source preservation, and absence of manuscript drafts.
- [x] Record outcomes and move this plan to `completed/`.

## Acceptance

The new README identifies reading order, source dates, historical versus
current evidence, excluded files, and author decisions. Local import success
must not become a public-release claim. eQTL overlap must mean reference-span
intersection, not full containment, causal mediation, or experimentally proven
regulation. Gene-mapping exclusions must not imply biological unimportance.

## Verification

Compare SHA-256 before/after for all legacy files; verify each unchanged copy
against its migration manifest. Check Markdown local links and count tables
with standard parsers. Run `git diff --check` on new tracked edits and inventory
all destination files. Do not assert new biological validation or current live
database counts without executing those checks.

## Execution Log

- 2026-09-02: new destination initially empty; legacy documents dated mainly
  2026-07-31 through 2026-08-03. Official guidance checked for Data Descriptor
  orientation only; no template or journal-format conversion planned.
- All 118 legacy files are in the disposition/hash manifest: 5 copied,
  6 adapted, 14 selectively extracted and 93 excluded without deletion.
- Five additional eQTL reports/manifests/mapping files copied; large products
  remain in data/. New materials include table dictionaries, per-tissue count
  CSVs and evidence/validation/reuse/gap registers. No draft produced.
- Narrow .gitignore exceptions were necessary because the new directory and
  completed execution record were otherwise ignored by blanket docs rules.
- `powershell -NoProfile -ExecutionPolicy Bypass -File
  docs/article_scientific_data/scripts/check_materials.ps1` passed: 118 source
  hashes unchanged, 10 copies equal, 50 tissues, eight normalized tables,
  130 partitions, 16,510,562 import rows, generated CSV parity, 32 local links,
  22 unique bibliography entries, and no drafts/templates/bulk payloads.

## Residual Risks

Historical graph and evaluation counts were not rerun. Full partition hashes
and biological rows were not revalidated. Gene audit uniqueness/case handling
differs from runtime allowlisting; separate tissue API support is not proof
of accepted browser selector wiring. These are recorded as release checks,
not silently fixed or treated as completed validation. Source licences,
sample provenance, exact v11 citation and public archive decisions remain open.
