# Co-expression Gene to GTEx eQTL Mapping Audit

## Scope and rule

This is a read-only audit of the approved co-expression network at `data/coexpression/analysis/v1/abs0.4_fdr0.05` against the active GTEx version `gtex_v11_strict_te_overlap_v1`. Only `TE_gene` co-expression edges are counted. A Gene is eligible for evidence integration only when its co-expression feature is annotated as a high-confidence Gene, its symbol exactly matches eQTL `gene_name`, and that name resolves to one `gene_id_base`. Version suffixes such as `.16` are normalized by `gene_id_base`, while original IDs remain available in eQTL data.

## Gene-level results

- Co-expression Gene symbols in TE-Gene edges: **3281**
- Unique high-confidence matches: **3243**
- Exact name matches with non-high-confidence co-expression annotation: **0**
- Low-confidence matches: **0**
- Ambiguous matches: **0**
- Unmatched: **38**

## Edge-level results

- TE-Gene co-expression edge rows across contexts: **170599**
- Distinct TE-Gene pairs: **169474**
- Edges with eligible unique high-confidence Gene mapping: **167997**
- Eligible edges with any cross-tissue TE-overlap eQTL evidence: **7763**
- Eligible edges without cross-tissue TE-overlap eQTL evidence: **160234**
- Potential `Both` pairs (any tissue, before tissue-filter display): **7715**

## Interpretation

The strict integration set is the unique high-confidence category. Low-confidence, ambiguous, and unmatched names are retained as audit outcomes but do not participate in `Both`. A mapped Gene without an eQTL pair is different from an unmatched Gene: its identity is known, but this strict TE-overlap dataset provides no corresponding evidence.

## Status counts

| Mapping status | Gene symbols | TE-Gene edge rows |
|---|---:|---:|
| `unique_high_confidence_match` | 3243 | 167997 |
| `unique_name_match_not_high_coexpression` | 0 | 0 |
| `low_confidence_match` | 0 | 0 |
| `ambiguous_match` | 0 | 0 |
| `unmatched` | 38 | 2602 |

## Examples

| Symbol | Status | eQTL gene_id_base | Reason |
|---|---|---|---|
| `ADIRF` | `unmatched` | `-` | no exact eQTL gene_name match |
| `ANO1-AS1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `APOBEC3B-AS1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `BMF-AS1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `C19orf33` | `unmatched` | `-` | no exact eQTL gene_name match |
| `CDR1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `DCANP1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `DDX3Y` | `unmatched` | `-` | no exact eQTL gene_name match |
| `EIF1AY` | `unmatched` | `-` | no exact eQTL gene_name match |
| `ERVMER61-1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `FAM153B` | `unmatched` | `-` | no exact eQTL gene_name match |
| `FAM226B` | `unmatched` | `-` | no exact eQTL gene_name match |
| `IGHJ3P` | `unmatched` | `-` | no exact eQTL gene_name match |
| `IGHV1-24` | `unmatched` | `-` | no exact eQTL gene_name match |
| `IGHV1-69-2` | `unmatched` | `-` | no exact eQTL gene_name match |
| `IGLC1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `IGLJ1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `IQCJ-SCHIP1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `KCNQ5-IT1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `KDM5D` | `unmatched` | `-` | no exact eQTL gene_name match |
| `LINC01422` | `unmatched` | `-` | no exact eQTL gene_name match |
| `LINC02802` | `unmatched` | `-` | no exact eQTL gene_name match |
| `MTRNR2L12` | `unmatched` | `-` | no exact eQTL gene_name match |
| `NLGN4Y` | `unmatched` | `-` | no exact eQTL gene_name match |
| `PRKY` | `unmatched` | `-` | no exact eQTL gene_name match |
| `PTPRCAP` | `unmatched` | `-` | no exact eQTL gene_name match |
| `RASA2-IT1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `RC3H1-IT1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `RMRP` | `unmatched` | `-` | no exact eQTL gene_name match |
| `RPS4Y1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `SMAD1-AS1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `SPON1-AS1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `TGFB2-OT1` | `unmatched` | `-` | no exact eQTL gene_name match |
| `TMSB4Y` | `unmatched` | `-` | no exact eQTL gene_name match |
| `TRBJ2-2P` | `unmatched` | `-` | no exact eQTL gene_name match |
| `TTTY10` | `unmatched` | `-` | no exact eQTL gene_name match |
| `UBD` | `unmatched` | `-` | no exact eQTL gene_name match |
| `UTY` | `unmatched` | `-` | no exact eQTL gene_name match |
| `A2ML1` | `unique_high_confidence_match` | `ENSG00000166535` | high-confidence co-expression Gene; exact eQTL gene_name; one gene_id_base |
| `ABCA13` | `unique_high_confidence_match` | `ENSG00000179869` | high-confidence co-expression Gene; exact eQTL gene_name; one gene_id_base |

## Evidence boundary

`Both` means the same TE–Gene pair has an eligible co-expression mapping and at least one eQTL TE–Gene cross-tissue summary in any GTEx tissue. It remains statistical/positional evidence, not proof of TE-mediated causality.
