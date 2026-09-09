# Runtime Access and Reuse Materials

Source review: 2026-09-02. No fresh browser acceptance test or usability study
was performed as part of the migration.

## Access Routes

| Route | Reusable material or function | Scientific boundary |
| --- | --- | --- |
| Browse / Search | TE catalog, detail sections, representative sequence/genome records | Source-specific identity and coverage |
| Variants after Genome Browser | GTEx Variant summary and Variant-Gene-Tissue Evidence rows | Positional/statistical association, not pathogenicity |
| Knowledge Graph / Path | Stored relation/path records and PMID provenance | A path is not a validated mechanism |
| TE-Gene Graph | Existing context-specific co-expression network plus eQTL Genes/edges | Display subset, not the full normalized tables |
| Expression | Abundance summaries and context-specific profiles | Not matched normal/cancer experiments |
| Agent / DeepThink | Optional evidence-access interface | Generated answers are not dataset ground truth |
| Download | Existing configured files | Does not yet establish release of every new eQTL table |

## TE-Gene Semantics and Display

`preview.php` labels the workspace TE-Gene Graph.
`api/coexpression_repository.php` appends eQTL to the original context graph,
reuses existing Gene nodes first and labels matching edges Both. New edges use
eQTL; co-expression evidence remains a separate evidence type. The append route
budgets 100 nodes and 150 edges. No layout/ripple change is made here.

Code includes render-compatibility numeric placeholders on eQTL-only edges
(e.g. zero correlation and FDR=1 in the append route). These are not measured
co-expression statistics and must never become scientific data exports.

The separate `api/te_gene.php` accepts all/tissue scopes and its repository
includes tissue labels/statistics. The co-expression append helper, however,
queries cross-tissue summaries and emits scope=all. The presence of a separate
tissue endpoint is not evidence that the accepted frontend's selector is wired
to it. A release-time browser/API check is required before claiming fully
verified All tissues/single-tissue switching in manuscript material.

The screenshot count of Both depends on context, identity matching, tissue
scope, and display truncation. The audit's 7,715 possible pairs is not a
browser acceptance result. Co-expression Context and GTEx tissue are distinct
selection dimensions; Both does not imply matched samples.

## Browse Variants

`api/variants.php` defaults to page size 10 and GTEx source `eqtl`.
`api/variant_repository.php` defines:
- variant view: one distinct Variant, associated Gene/tissue counts and minimum
  nominal p-value;
- evidence view: Variant-Gene-Tissue associations with p-value, slope,
  slope SE, allele frequency and minor-allele count when available;
- scope: direct TE name/instances, then family resolution, with no broad
  superfamily-only fallback.

The summary view uses left joins, so a missing association need not remove an
overlap record. That design is not evidence that this significant-pair-derived
dataset actually contains non-eQTL variants or unmapped genes.

ClinVar variants and CNV remain Genome Browser BigBed tracks; they are not
a local tabular source in this release. Legacy panel notes still describe
source tabs, but the user removed those frontend tabs. Do not reproduce that
outdated UI description or infer that ClinVar cannot be tabulated in principle.

## Reuse Cases to Freeze Later

- L1HS: retain the earlier case as a candidate, not a completed validation.
- AluYa5: an independently named Browse candidate present in the mapping report.
- L1PA4 or L3: candidates with documented eQTL summary coverage.
- Include an unmatched Browse-name case and a boundary-overlap fixture.

For each selected case retain exact TE identity, API/query parameters,
data version, Variant/reference coordinates, Gene ID, tissue, statistics,
mapping status and evidence rows. If Both is shown, retain the actual
co-expression context and correlation/FDR. No biological narrative or claim
of representativeness is invented from these candidate names.
