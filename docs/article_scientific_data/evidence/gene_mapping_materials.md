# Gene Mapping Materials

Source: [retained audit report](snapshots/gene_mapping_audit.md).
The source report does not state its run date. It was captured 2026-09-02;
capture date must not be substituted for audit execution date. Audit not rerun.

| Audit category | Gene symbols | TE_gene edge rows |
| --- | ---: | ---: |
| Unique high-confidence match | 3243 | 167997 |
| Unique name but not high-confidence Gene annotation | 0 | 0 |
| Low confidence | 0 | 0 |
| Ambiguous | 0 | 0 |
| Unmatched | 38 | 2602 |
| Total | 3281 | 170599 |

The audit reports 169,474 distinct pairs and 7,715 potential Both pairs.
Eligible edge rows partition into 7,763 with and 160,234 without a cross-tissue
eQTL counterpart: 7,763 + 160,234 = 167,997.
These counts concern the full audited offline edge set, not one screen.

Examples retained in the source:
- A2ML1 -> ENSG00000166535: eligible unique high-confidence identity.
- ABCA13 -> ENSG00000179869: eligible unique high-confidence identity.
- ADIRF, DDX3Y and UTY: no exact eQTL name match in this audit.

Unmatched does not mean unimportant, unstudied, absent from biology, or lacking
every eQTL study. A uniquely mapped Gene without a TE-eQTL pair is a different
outcome from a Gene that could not be mapped.

## Reproducibility Gap

The current audit generator writes aggregate counts and example rows; a complete
machine-readable Gene/status/base-ID and pair-membership export is still needed
for independent reuse. Freeze annotation hashes and per-context pair lists.

The inspected runtime append helper uses a case-folded high-confidence symbol
allowlist rather than consuming the audit's per-symbol uniqueness decisions.
The audit itself uses exact-case names and unique base IDs. Current zero ambiguous
audit categories do not prove that future releases enforce the same rule.
Do not claim a universally enforced unique-ID join without a release-specific
runtime parity check. No runtime fix is attempted in this documentation task.
