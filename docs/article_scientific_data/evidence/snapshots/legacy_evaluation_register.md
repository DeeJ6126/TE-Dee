# TE-KG Evaluation Register

Last reviewed: 2026-08-01

Evaluation records are grouped by what they actually test. Automated contracts,
browser acceptance, human answer assessment, and biological validation are not
interchangeable.

## Knowledge Graph and Taxonomy

| evaluation | scope | usable conclusion | limitation | source |
| --- | --- | --- | --- | --- |
| Neo4j runtime check | database target, reachability, representative TE names, non-empty graph | Current local runtime resolves to `tekg3` and contains TE and biological-relation data | Connectivity and counts do not validate relation correctness | `scripts/checks/check_neo4j_tekg3.py` plus 2026-07-31 read-only queries |
| Graph API/static/browser checks | payload, iframe bridge, loader, legends, expansion, export, rendering | Principal graph interactions have executable regression coverage | UI checks do not validate biological interpretation | `scripts/checks/check_g6_*`, graph contract checks |
| Taxonomy Canvas integration | Tree/Graph switching, legend filtering, force/drag behavior, toolbar contract | Canvas classification Graph is integrated and interaction-tested | Layout position/area has no quantitative taxonomic meaning | Canvas integration and browser checks; current handoff |

One legacy taxonomy-consistency script failed on 2026-07-31 because it expected
an older homepage implementation. This failure cannot be represented as current
taxonomy corruption without first updating or re-scoping that check.

## Expression and Co-expression

| evaluation | scope | usable conclusion | limitation | source |
| --- | --- | --- | --- | --- |
| Matrix/metadata audit | dimensions, missing/negative values, feature column, metadata matching | Matrix sizes and known metadata discrepancies are documented | Audit predates the manuscript snapshot and should be rerun | `docs/coexpression/data_audit.md` |
| Feature annotation audit | TE/Gene/uncertain assignment and conflicts | Conservative feature filtering is documented | 13,315 uncertain features and remaining alias conflicts limit coverage | `docs/coexpression/feature_annotation_status_zh.md` |
| Method and parameter scans | correlation thresholds and Louvain resolution | Active method and parameter rationale are recorded | Final sensitivity table and statistical audit are not yet assembled | `docs/coexpression/README.md`; parameter-scan outputs |
| 849-network parity check | offline-to-MySQL display products | Recorded acceptance covered all 849 approved TE-context networks | This verifies data transfer/contract consistency, not biological correctness | `docs/eval/runs/2026-07-25-coexpression-dual-mode/README.md` |
| Representative browser cases | L1HS, LTR5, MER11B, HERVH-int, CR1; drag and rendering | Representative positive, weak, and unavailable cases were exercised | A small case set is not a biological benchmark | same acceptance run and `verification.json` |

The acceptance record reported HTTP medians of 33.8 and 31.0 ms for measured
local requests and graph stabilization of approximately 1.2-2.8 s for three
tested network types. These values are environment-specific and are not planned
as central manuscript claims.

## Agent and DeepThink

### 13-question baseline

- 13 English questions: 9 DeepThink and 4 Agent.
- All 13 endpoint runs completed.
- The case set exercised all 12 registered plugin roles.
- Appropriate use: initial functional and plugin-coverage evidence.
- Inappropriate use: a current answer-accuracy estimate.
- Source: `docs/eval/runs/2026-07-28-agent-13-question-baseline/report.md`.

### Routing-stop comparison

- The fixed 13-question comparison observed no judged quality regression.
- Total plugin calls fell from 50 to 46; Agent-subset calls fell from 28 to 23.
- Appropriate use: evidence that the scoped routing change reduced unnecessary
  calls in this case set without an observed loss in judged coverage.
- Boundary: the comparison is small, model-dependent, and not a general
  efficiency or quality theorem.
- Source:
  `docs/eval/runs/2026-07-28-agent-routing-stop-remaining/comparison_report.md`.

### 36-question evaluation

| measure | result |
| --- | ---: |
| total cases | 36 |
| fixed/adaptive | 30 / 6 |
| English/Chinese | 34 / 2 |
| Agent/DeepThink | 20 / 16 |
| pass/partial/fail | 16 / 10 / 10 |
| recorded runtime-error cases | 3 |

The assessment used a user-facing standard: internal developer/model terms,
missing requested content, broken links, off-topic literature, and citation
mismatches were treated as quality defects even when the workflow completed.

This is a historical maintenance evaluation. F13 URL integrity and A34
PMID-link alignment were subsequently fixed, while the historical totals were
deliberately preserved. Therefore, the table must not be labelled current
system accuracy.

### Multi-turn context

The case set and browser verification support the following bounded statement:
English and Chinese follow-up references can inherit a validated recent TE;
multi-entity ambiguity prompts clarification without scientific plugin calls;
reload/new-tab sessions do not inherit earlier entities. No reduction in
single-turn plugin capability was observed in that test set.

### Known intelligent-QA limitations

- disease-qualified literature retrieval can miss relevant papers;
- literature reading is commonly limited to titles, metadata, and abstracts;
- Agent Writing is a latency/failure concentration point;
- concurrent sessions multiply upstream LLM calls and have produced relay
  timeouts;
- Sequence structure-hint parsing can misread `Non-LTR` as containing an LTR
  hint, although unrequested hints are suppressed in final writing;
- historical cases exposed overlong answers and internal vocabulary.

## Literature-Pipeline Manual Validation

The archived method document reports three samples of 50 papers with decision
rates of 100%, 94%, and 96%. These values remain `partial` evidence because:

1. the intermediate filtering counts are internally inconsistent;
2. sampled identifiers and decisions have not yet been recovered into this
   workspace;
3. the random seed and reproducible sampling command are not recorded;
4. the confidence intervals require independent recomputation.

Until those records are recovered, the manuscript may describe manual review as
part of the workflow but should not present the rates as a definitive validation
result.

## Evaluation Needed Before Submission

1. rerun the expression matrix/metadata audit on the submitted release;
2. assemble co-expression sensitivity and quality tables from retained outputs;
3. recover or repeat the literature-screening manual audit reproducibly;
4. select two or three manuscript use cases and preserve their exact inputs,
   outputs, citations, screenshots, and interpretation boundaries;
5. decide whether Agent/DeepThink receives only a qualitative case study or a
   new current benchmark; do not reuse the historical 36-case totals as current
   accuracy.

