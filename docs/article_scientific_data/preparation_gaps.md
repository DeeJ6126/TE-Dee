# Open Decisions and Preparation Gaps

Prepared 2026-09-02. Adapted from legacy source, statistical, availability,
reference and mock-review audits, including the bilingual gap list.
Journal-specific OUP packaging and Database commitments are not carried over.

## Author Decisions

| Decision | Why it matters | Current status |
| --- | --- | --- |
| Article type and scientific object | Data Descriptor lens is provisional; define the reusable data product rather than a website paper | Author/supervisor confirmation |
| Release scope | Whole TE-KG evidence resource or a justified subset; clarify whether restricted sequence/source fields are included | Not selected |
| Dataset/code repositories and licences | Persistent release and rights must be real before availability claims | Not supplied |
| Authorship, affiliations, funding, conflicts and ethics applicability | Cannot be inferred from commits or data access | Author confirmation |
| Gene/UI contract discrepancies | Decide a scoped implementation audit before release claims; this migration does not change runtime | Review before final data freeze |
| Figure/reuse emphasis | Select evidence-backed datasets and reuse examples; no fixed four-figure or L1HS-only requirement | Deferred until release scope |

## Retained Scientific Gaps

- PubMed full query, intermediate screening ledger, model/prompt version and
  reproducible manual-audit decisions.
- RMSK source release, acquisition date and terms; distinguish old source tables
  from the specific hg38 BED used by the eQTL pipeline.
- RepBase snapshot/export procedure and redistribution permissions: the legacy
  letter=A URL alone does not explain all 526 local records.
- ICD-11 version/reuse terms.
- Expression preprocessing, units, reference annotation/software and complete
  source-accession-to-local-sample manifest; five unmatched normal-tissue runs,
  duplicate IDs, and SRP013565 subset classification.
- Co-expression feature reference, tested pair universe, FDR correction family,
  constant/missing handling, environment and sensitivity outputs.
- Current release census with reproducible outputs; old snapshot counts are
  useful historical evidence but not a current released dataset.
- Bibliography field-level review and source-specific citations; older
  verification does not guarantee current website/resource comparisons.
- Dataset/code archives and file-level metadata, licences, checksums and
  reader/reviewer access. Avoid distributing restricted text or raw subject data.
- Scientific validation of extracted relations is different from UI acceptance.

## New eQTL/Variant Gaps

- Complete v11 source metadata, citation, annotation release and field definitions.
- Permitted redistribution boundary for GTEx-derived data and reused TE sources.
- Full machine-readable Gene mapping and rejected/ambiguous outcomes with
  source hashes; match audit exact-case/unique-ID logic to runtime allowlisting.
- Freeze per-tissue counts, table keys and reproducible summary joins; audit
  null-slope direction classes before using them as biological summaries.
- Verify tissue-selector wiring against the accepted appended co-expression
  graph. A separate tissue endpoint and a stated UI plan are not acceptance evidence.
- Separate display placeholder correlation/FDR fields from scientific exports.
- Keep ClinVar BigBed tracks outside the claimed MySQL Variant dataset unless
  a separately documented adapter/release is actually produced.
- Broad TE-family queries and missing mappings require explicit resolved scope
  and denominators; no superfamily-wide evidence claim.
- Existing significant-pair filtering must be disclosed even though TE-KG
  applies no extra p-value threshold.
- Both is no proof of causality, same-tissue concordance, or independent
  replication; matched tissues/cohorts would require a separate analysis.

## Suggested Next Work, Not Performed Here

First agree on the release object, article type and permissions with the
supervisor. Then close provenance/sample gaps and freeze reproducible release
tables plus validation. Select a small data-dictionary/reuse figure set after
that. Manuscript drafting comes later; no draft was prepared in this task.
