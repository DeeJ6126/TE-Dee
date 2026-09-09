# Claim Evidence Register

Prepared 2026-09-02. Legacy IDs are retained for traceability, not approval to
paste old prose into a new manuscript. Old figure numbers and manuscript-section
assignments have been removed. Evidence paths are repository-root-relative.
All legacy verified rows below mean **historically verified in the old register**;
none is a fresh database recount. Supersession notes take priority.

| ID | Material or claim | Evidence | Boundary | Preparation status |
| --- | --- | --- | --- | --- |
| C001 | Resource scope now also includes Variant/eQTL evidence. | `docs/architecture/database_overview.md`; runtime entrypoints; architecture review | The layers remain distinct evidence types. | Superseded scope; see research facts and E001-E008. |
| C002 | The current knowledge graph contains 2,308 paper nodes. | Neo4j `tekg3`; live read-only label-count query, 2026-07-31 | Runtime snapshot; record date and release. | Historical verified |
| C003 | The current graph contains 225 TE nodes and 12,444 directed `BIO_RELATION` relationships. | Neo4j `tekg3`; live read-only label/relation-count queries, 2026-07-31 | Relationship direction/count semantics must be stated. | Historical verified |
| C004 | The graph connects TEs to 11 supported biomedical entity categories. | `docs/methods/method_english.docx`; runtime label counts; schema and runtime audit | Paper and taxonomy category nodes are not part of the 11 biomedical categories. | Historical verified |
| C005 | Every current biological relation retains PMID provenance and a predicate. | Neo4j relationship properties; live property-completeness query, 2026-07-31 | Presence of a PMID does not prove that every phrasing is causal. | Historical verified |
| C006 | The final retained literature corpus contains 2,308 papers. | `docs/methods/method_english.docx`; Neo4j Paper count; document/runtime agreement | Intermediate filtering counts are excluded. | Historical verified |
| C007 | PubMed records were screened with TE whitelists and processed by constrained DeepSeek-V3 extraction followed by normalization and manual curation. | `docs/methods/method_english.docx`; `docs/architecture/database_overview.md`; method record | Exact model version and reproducible environment remain missing. | partial |
| C008 | The reported three 50-record manual audits support the filtering workflow. | `docs/methods/method_english.docx`; archived method report | Sample lists, seed, denominators, and inconsistent stage counts must be recovered. | partial |
| C009 | RMSK-derived records provide genomic positions and RepBase-derived records provide identity, classification, and consensus/reference sequences. | `data/rmsk.txt`; `data/raw/TE_Repbase.txt`; architecture overview; source-file and schema review | Upstream versions, dates, and licences are missing. | partial |
| C010 | The live taxonomy summary contains 225 TEs, including 192 with a taxonomy class. | `api/taxonomy.php?view=summary`; live HTTP response, 2026-07-31 | Runtime snapshot only. | Historical verified |
| C011 | Browse exposes a versioned 276-entry TE catalog. | `api/browse.php`; live HTTP response, 2026-07-31 | Browse catalog count is not identical to the Neo4j TE-node count. | Historical verified |
| C012 | Expression data cover normal tissue, normal primary cell, and cancer cell line contexts with 205, 307, and 646 samples. | active matrices; `docs/coexpression/data_audit.md`; matrix audit | Existing runtime name `normal_cell_line` means normal primary cell. | Historical verified |
| C013 | Expression matrices contain both TE/repeat and gene features. | active matrices; data audit; feature-name audit | Do not describe them as gene-only matrices. | Historical verified |
| C014 | Five normal-tissue matrix samples lack matching metadata and duplicate run identifiers are present. | `docs/coexpression/data_audit.md`; metadata audit | Must be disclosed or resolved before downstream comparisons are generalized. | Historical verified |
| C015 | Context-specific co-expression uses Spearman correlation with `abs(r) >= 0.4`, `FDR <= 0.05`, and Louvain resolution 1.8. | `docs/coexpression/README.md`; runtime catalog; code/output/runtime agreement | Parameter-selection evidence still needs a manuscript-facing sensitivity summary. | Historical verified |
| C016 | Production co-expression serves approved MySQL-backed display networks separately from full offline networks. | repository/API/frontend; runtime architecture review | Display catalogs are not full analysis networks. | Historical verified |
| C017 | The live co-expression catalog contains 285 TE and 499 Gene entries across three contexts. | `api/coexpression.php?action=catalog`; live HTTP response, 2026-07-31 | Counts describe approved searchable display entries. | Historical verified |
| C018 | Co-expression edges indicate statistical association and do not establish regulation or causality. | method design and runtime contract; method/contract review | Required wording boundary. | Historical verified |
| C019 | Interfaces expose evidence layers; implementation is not demonstrated scientific utility. | root pages, APIs, browser checks; runtime/source review | Scientific utility must be shown with worked use cases, not asserted from feature presence. | Historical capability; see current reuse notes. |
| C020 | Agent/DeepThink evidence-access roles and session behavior. | intelligent QA handoff, plugin catalog, tests; contract/test review | The interface is not an autonomous reasoning or validation system. | Historical implementation only; plugin count and behavior need release audit. |
| C021 | The historical 36-question evaluation demonstrates acceptable current answer quality. | 2026-07-30 evaluation records; historical run | Later fixes changed some failures; the complete suite was not rerun as one current benchmark. | rejected |
| C022 | Novelty or comprehensiveness claim. | literature comparison not yet complete; none | Requires systematic comparison against existing resources. | Excluded unless independently supported; not a preparation requirement. |
| C023 | Improved discovery or efficiency claim. | worked use cases not yet locked; none | Must be demonstrated with bounded examples or user evaluation. | Not demonstrated; do not turn reuse examples into impact claims. |
| C024 | The data and code are openly and durably available. | repository and licence plan missing; none | Local runtime and Download page do not establish archival availability. | missing |
| C025 | The manual literature audit achieved the reported confidence intervals. | `docs/methods/method_english.docx`; source audit pending | Recompute after recovering the sampled decisions and exact denominators. | partial |

## Added Evidence

| ID | Material | Evidence | Status and limit |
| --- | --- | --- | --- |
| E001 | 50 GTEx source categories; 104,901,807 source associations | `data/eQTL/derived/gtex_v11_strict_te_overlap_v1/all_tissue_manifest.json` | Artifact totals checked; signif_pairs input, not all tests |
| E002 | 202 matched and 74 unmatched Browse names; 596,140 approved intervals | same manifest and `missing_browse_te.tsv` | Mapping scope, not all human TE instances |
| E003 | 10,676,462 instance-level evidence rows | same manifest; per-tissue counts | Not unique Variant-Gene-Tissue count |
| E004 | Eight normalized import tables; 130 partitions; 16,510,562 rows | `mysql/manifest.json` under the same version | Manifest arithmetic, not fresh MySQL COUNT(*) |
| E005 | Strict same-chromosome REF-span intersection | `scripts/eqtl/gtex_overlap_core.py` | Source inspected; no flanking/full-containment claim |
| E006 | 3,243 high-confidence unique Gene matches; 38 unmatched | `docs/eqtl/gene_mapping_audit.md` | Retained audit, not rerun; technical identity not Gene importance |
| E007 | 7,715 potential Both pairs | same audit | Whole audited offline TE_gene set before display filtering; no tissue-matched replication claim |
| E008 | Browse Variant and Evidence rows; GTEx only visible source | `api/variants.php`, `api/variant_repository.php`, `search.php` | Source inspected; ClinVar is not locally tabulated |
| E009 | eQTL appended to original co-expression graph | `api/coexpression_repository.php` | Source inspected; runtime limits and mapping enforcement caveats in reuse notes |
| E010 | Tissue query support in separate TE-Gene API | `api/te_gene.php`, `api/te_gene_repository.php` | Source inspected; browser wiring needs verification before screenshot/claim |
| E011 | Public eQTL/data release, approved licences and DOI | Not yet supplied | Open; local normalized output is not a public deposit |

Legacy status for C015 does not close the missing FDR-family/sample-manifest
documentation. C017's display catalog is not the 3,281 Gene-symbol audit set.
C021 remains rejected as a current accuracy claim.
