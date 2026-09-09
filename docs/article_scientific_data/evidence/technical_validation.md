# Technical Validation Materials

Prepared 2026-09-02. Separate historical tests, inspected test code, fresh
manifest consistency checks and biological validation.

## Inherited Validation Evidence

The [historical evaluation register](snapshots/legacy_evaluation_register.md)
retains the detailed record:
- graph/API/taxonomy and browser contract tests check implementation, not truth
  of literature relations;
- matrix/metadata audit records dimensions and mismatches;
- feature annotation and parameter scans support conservative filtering, with
  uncertainty and final sensitivity reporting still open;
- offline-to-MySQL parity was recorded for 849 display networks;
- representative browser cases included L1HS, LTR5, MER11B, HERVH-int and CR1;
- 13-question baseline and routing comparison are small functional evaluations;
- the 36-question run (16 pass / 10 partial / 10 fail) is historical, not a
  current accuracy estimate. Later fixes invalidate reuse as current performance.

Local timing figures and successful endpoint completions are not evidence of
scientific reliability. The three reported 50-paper manual audit rates remain
unapproved until sample IDs, decisions and denominators can be recovered.

## eQTL Validation Assets

| Check source | What it can test | Evidence level in this migration |
| --- | --- | --- |
| `scripts/eqtl/test_gtex_overlap_core.py` | SNP/indel REF spans, boundaries, multiple instances, indexed parity | Test source inspected |
| `scripts/eqtl/test_build_gtex_all_tissues.py` | Small paired-tissue fixtures, duplicates, annotations and versioned output | Test source inspected |
| `scripts/eqtl/test_consolidate_gtex_mysql_artifacts.py` | Normalization/summaries on small generated inputs | Test source inspected |
| `scripts/checks/check_gtex_eqtl_all_tissues.py` | Full-artifact validation and optional MySQL checks | Existing reproduction entrypoint; not executed here |
| `scripts/checks/check_gtex_eqtl_mysql_contract.php` | MySQL active version and normalized contract | Existing reproduction entrypoint; not executed here |
| `scripts/eqtl/audit_gene_mapping.py` | Gene identity classes and possible Both pairs | Retained output captured; not rerun |
| JSON/table arithmetic checks | Tissue and import totals, file references, source preservation | Run in this migration; see verification record |

Production reports record completed processing/import. Copies preserve that
evidence but do not convert it into a fresh full-dataset validation.

## Release Validation Still Required

1. Freeze all inputs and code versions; verify each data partition hash and
   every foreign-key/natural-key invariant in the release copy.
2. Check gene-name ambiguity and case normalization across the exact runtime
   and offline audit sets, not only high-confidence flag presence.
3. Compare representative per-tissue and cross-tissue summaries to independently
   reconstructed joins; distinguish unique associations from repeated instances.
4. Audit null values, valid ranges, unsupported Variant formats, chromosomes,
   boundary-touch cases and multi-base alleles partially crossing a TE.
5. Release the full Gene mapping/status export and unmatched-TE reason table.
6. Verify that browser Both labels, tissue selectors and downloadable statistics
   agree with the frozen data; do not export renderer placeholder values.
7. Retain at least one non-L1HS reuse example and one missing-evidence example,
   but do not treat a handful of examples as a coverage benchmark.
8. Obtain independent literature-curation review evidence if extraction-quality
   claims are made.

A Data Descriptor's technical validation should support dataset quality, not
manufacture an independent biological discovery.
[Official referee criteria](https://www.nature.com/sdata/policies/for-referees)
(accessed 2026-09-02).
