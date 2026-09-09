# TE-KG Proposal Work Plan and Fact Sheet

> Scope guard for this proposal work: only files under `docs/proposal/` may be written. This first round creates only this plan file and does not modify `main.tex`, `reference.bib`, `zjureport.sty`, figures, runtime code, data, scripts, APIs, assets, or other docs.

## 1. Rediscovered Nature Skills

Read in this session from the current local skill files:

| Skill | Use in this proposal workflow |
|---|---|
| `nature-writing` | Primary writing scaffold for the proposal argument: scientific problem, gap, central hypothesis, aims, innovation, technical route, boundaries, and claim-evidence mapping. |
| `nature-academic-search` | Later, after plan confirmation, search background literature, gaps, and reference candidates through structured scholarly sources. Not yet used for live literature search in this first local-only investigation round. |
| `nature-citation` | Later, add citations to specific scientific claims and grade support conservatively. Not yet used to insert or export citations. |
| `nature-data` | Used at planning level to flag Data Availability, FAIR, repository, provenance, and third-party-data issues. |
| `nature-figure` | Use only if the template/proposal needs a figure. If an actual figure must be generated, ask the user first: Python or R? |
| `nature-polishing` | Final-stage Nature-leaning English/academic polishing after factual drafting and citation mapping. Not used for main-file polishing yet. |
| `nature-reader` | Available only as a paper-reading aid if full papers need source-grounded reading; not a proposal entry point. |
| `nature-response` | Not used by default; for reviewer-response workflows only. |
| `nature-paper2ppt` | Not used by default; for PPTX generation only. |

## 2. Proposal Main File

- Main file: `docs/proposal/main.tex`.
- Supporting files: `docs/proposal/reference.bib`, `docs/proposal/zjureport.sty`, `docs/proposal/figures/`.
- This round did not edit the main file.

## 3. Template Structure

The current template is a LaTeX report template:

- `main.tex` uses `ctexart` with `\usepackage{zjureport}` and is intended for `xelatex -> bibtex -> xelatex*2`.
- Structure in `main.tex`: cover via `\cover`, optional abstract, table of contents, then normal `\section` / `\subsection` body.
- Existing template body is still generic template documentation, not TE-KG proposal content.
- Figures are currently local ZJU logo assets under `figures/`.
- `zjureport.sty` defines cover metadata, page style, `\reference`, theorem environments, and a `\tbox{}` helper.
- Citation style needs care in the formal rewrite: `zjureport.sty` uses BibTeX-style `\bibliographystyle{unsrt}` and `\bibliography{reference}`, while `reference.bib` currently contains a LaTeX `\usepackage[style=alphabetic,...]{biblatex}` line. Do not change this until formal rewrite is approved.

## 4. TE-KG Implemented Facts Found

Repository and architecture facts:

- TE-KG is a local PHP + browser JavaScript + Neo4j + MySQL project.
- Current Neo4j runtime target is `tekg3`.
- Runtime root pages remain at project root, including `index.php`, `browse.php`, `preview.php`, `expression.php`, `expression_detail.php`, and `path_finder.php`.
- Main graph API entry points are `api/graph.php` and `api/graph_service.php`.
- Main graph frontend is the G6 runtime under `assets/js/renderers/g6/`, with `preview.php` as the current graph workspace.
- Taxonomy API is `api/taxonomy.php`.
- Expression runtime uses `api/expression_data.php` and `api/expression_repository.php`.
- Expression asset root is `data/bulk_expression_web`; the old `data/raw/new_data/bulk_expression_web` path must not be restored as runtime root.
- Path abstractions exist for PHP, browser JS, and Python: `path_config.php`, `assets/js/tekg_paths.php`, and `scripts/path_helpers.py`.

Evidence-support implementation facts:

- PubMed metadata parser/fetcher was implemented as a standalone path independent of DeepSeek IE extraction.
- Full PubMed metadata fetch recorded 2,308 unique PMIDs and 2,308 metadata records with 0 failures.
- PubMed XML does not provide Impact Factor; journal metrics require external mapping.
- Journal metric mapping v1 uses internal `impact_factor_package_2025` provenance, not an official direct JCR export.
- Neo4j Paper enrichment imported PubMed/journal metric fields onto 2,308 existing `Paper` nodes: 2,037 with metrics and 271 with null metrics.
- Relation aggregation wrote derived `support_*` properties to existing `BIO_RELATION` relationships in the archived 2026-05-22 import plan. These are evidence-support fields, not confidence fields.
- Graph API edge payloads expose `support_*` fields and eager `evidence_records`.
- G6 evidence UX maps edge width to `support_pmid_count` and opacity to `support_metric_coverage`; IF is shown explicitly as IF/Journal Impact Factor, not confidence.
- Edge click can show a PubMed evidence table and CSV download for selected-edge evidence tables above 10 rows.
- Current visible G6 subgraph export supports CSV and PNG. SVG remains disabled as `SVG Soon`.

Agent/DeepThink facts:

- Agent/DeepThink boundary work introduced `task_complexity`, Agent research templates, and `PluginResultEnvelope`, while preserving legacy raw payload compatibility.
- Agent Writing was migrated to `evidence_package.v1 -> evidence_walk.v1 -> report_plan.v1 -> draft/polish -> integrity gate`.
- Deterministic and live evaluation harnesses exist for DT vs Agent.
- Phase 5B live eval recorded DT success 30/30 and Agent success 24/30 on 30 seed cases; Phase 5C semantic proxy reported Agent wins 13/30, DT wins 11/30, ties 6/30.
- Latest targeted Agent plugin live API verification reported all 11 targeted plugin cases passed.
- These facts support a cautious "prototype/pilot evidence-grounded assistant workflow" description only. They do not support claiming a mature autonomous scientific agent.

## 5. Preliminary Work Candidates

Potential proposal phrasing should present the current system as an implemented foundation / pilot resource / preliminary platform, not as future-only work:

- A local TE knowledge graph runtime backed by Neo4j `tekg3`, with PHP APIs and browser G6 visualization.
- A TE-oriented graph exploration workspace with node action cards, same-label-safe expansion, relation legend filters, and browser smoke coverage.
- PubMed metadata enrichment and journal metric mapping as a pilot literature-evidence layer.
- Relation-level support aggregation using PMID counts, metric coverage, IF aggregate values, JCR quartile counts, and year ranges.
- Graph API and G6 evidence support UX showing edge-level PubMed evidence.
- Export of current visible graph data as CSV and canvas PNG.
- Homepage statistics endpoint using live Neo4j counts, with SVG donut charts.
- Expression runtime path consistency using `data/bulk_expression_web`.
- Agent/DeepThink evidence-package and evidence-walk prototypes as an experimental assistant layer, with deterministic guardrails and live targeted plugin checks.

## 6. Database Scale and Verified Functions

Current read-only checks run in this session:

- `check_neo4j_tekg3.py`: PASS. Runtime resolves to `tekg3`; `RETURN 1` works; representative TE names resolved: `AluJb`, `L1HS`, `SVA`; reported 225 `TE` nodes and 24,748 `BIO_RELATION` matches using that check's undirected relationship pattern.
- `check_runtime_db_config.py`: PASS.
- `check_expression_paths.py`: PASS. Canonical root: `D:\wamp64\www\TE-\data\bulk_expression_web`.
- `check_api_contracts.py`: PASS for health, `api/graph.php?q=LINE1`, same-label expand disambiguation, taxonomy tree, and taxonomy items.
- `check_home_stats_api.py`: PASS. Current home stats API reported `nodes=11415`, `relationships=13696`, `entities=15`, `relations=6`.
- `check_graph_api_evidence_support.py`: PASS. Sample support fields were present, with `support_pmid_count=2`, `coverage=1`, `if_mean=2.5`.
- `check_g6_browser_smoke.py`: PASS for `preview.php?q=LINE1`.
- `check_g6_evidence_support_ux.py`: PASS.
- `check_g6_subgraph_export_smoke.py`: PASS.
- `check_g6_te_tree_load_regression.py`: PASS, including `LINE1` graph load with 95 nodes / 103 edges, `L1HS` graph load with 54 nodes / 58 edges, taxonomy tree `nodeCount=1632`, `edgeCount=1631`, and restored `L1HS` path.

Current checks with risk:

- `check_taxonomy_runtime_truth.py`: FAIL. It reports that `index.php` should build homepage taxonomy from the Neo4j-backed taxonomy helper.
- `check_taxonomy_runtime_consistency.py`: FAIL. It reports that the homepage ring chart does not load `api/taxonomy_lib.php` and does not build views from realtime Neo4j taxonomy.

Scale caution:

- Do not mix the `24,748 BIO_RELATION` value from `check_neo4j_tekg3.py` with the `13,696 relationships` value from `check_home_stats_api.py` as if they were the same counting口径.
- Formal proposal text should either use the currently verified home stats total (`11,415 nodes`, `13,696 relationships`) or explicitly state the metric-specific source and query口径.
- Archived relation aggregation numbers from 2026-05-22 are valid as import evidence, but should not be presented as the current total graph scale unless revalidated with the exact intended directed query.

## 7. Scientific Claims Needing Citation Support

Later citation work should support, at minimum:

- Transposable elements are major components of eukaryotic genomes and can shape genome evolution, regulation, and genome instability.
- Human TE families such as LINE-1, Alu/SINE, SVA, and HERV/LTR are biologically relevant to gene regulation and disease contexts.
- TE dysregulation or TE-derived sequences have been implicated in cancer, aging, neurodegeneration, immunity, or other disease mechanisms, depending on the final proposal emphasis.
- Knowledge graphs can integrate heterogeneous biomedical entities, literature evidence, and relationships for exploration and hypothesis generation.
- PubMed/MeSH metadata can support literature-evidence organization, but it does not contain Impact Factor.
- Journal-level metrics, when used, are external metadata and should be described as journal metadata rather than article-level evidence quality.
- FAIR/data availability claims require citation to FAIR principles, repository/data-sharing guidance, or target-journal policy.

Do not add citations before `nature-academic-search` and `nature-citation` have verified candidate references.

## 8. Data Availability / FAIR Points

Issues to handle with `nature-data` during formal rewrite:

- Define which TE-KG outputs are shareable: source code, processed metadata, graph export, Neo4j dump, MySQL-derived expression summaries, generated CSV/JSONL, and documentation.
- Separate project-generated data from third-party or licensed inputs, especially PubMed metadata, Repbase/RMSK-derived taxonomy inputs, and `impact_factor_package_2025`.
- Record provenance and versioning for processed outputs, import tags, and runtime target `tekg3`.
- Do not promise public release of any licensed or restricted data without human confirmation.
- Avoid vague "available upon request" language unless there is a real restriction and access process.
- If the proposal needs a Data Availability section, use explicit dataset-to-location mapping and mark unknown repository/DOI/license fields as `TBD` rather than inventing them.

## 9. Claims That Must Stay Cautious

- Do not call Impact Factor, `support_if_*`, or `support_metric_coverage` "confidence".
- Missing IF or unmatched records must remain `null`, `unknown`, or `unmatched`; do not infer values.
- `impact_factor_package_2025` is an internally approved metric source, not an official direct JCR export.
- Agent/DeepThink should be described as an experimental assistant workflow with evidence packaging, guardrails, and targeted live checks, not as a mature autonomous scientific agent.
- The Phase 5C semantic proxy does not verify biomedical truth or claim-level citation support.
- SVG export is not implemented; only CSV and PNG are currently supported.
- Category-centered graph search is not fully implemented; TE tree category clicks are guarded, but direct category graph searches can be empty.
- Homepage taxonomy truth-source checks currently fail, so homepage taxonomy/ring-chart claims require caution until fixed or explicitly excluded.
- The G6 TE mechanism loaders are simplified UI animations, not strict biological mechanism diagrams.
- Relation aggregation and PubMed/journal metrics are preliminary evidence metadata layers, not validated measures of causal strength.

## 10. Formal Main-File Rewrite Steps After User Confirmation

1. Confirm proposal language/target and whether to keep the current Chinese LaTeX template body language.
2. Use `nature-writing` to create a proposal argument map: problem, gap, central hypothesis, aims, innovation, technical route, preliminary foundation, risk/limits.
3. Build a claim-evidence table from the facts above and decide which claims are repository-supported versus literature-supported.
4. Use `nature-academic-search` to collect background and gap references from structured sources.
5. Use `nature-citation` to attach verified citations to specific scientific claims and update `reference.bib` only with checked records.
6. Use `nature-data` to draft Data Availability / FAIR wording if the final template needs it.
7. If a figure is needed, first ask the user "Python or R?" before using `nature-figure` to generate any actual figure.
8. Rewrite only `docs/proposal/main.tex` and, if needed, `docs/proposal/reference.bib` and figure assets under `docs/proposal/`.
9. Preserve LaTeX commands, sectioning, labels, bibliography style, and compile route unless a minimal internal template fix is approved.
10. Optionally compile LaTeX with build output only under `docs/proposal/build/`.
11. Run a final proposal QA: unsupported-claim scan, TODO citation scan, Data Availability risk scan, and check that no file outside `docs/proposal/` was modified.
