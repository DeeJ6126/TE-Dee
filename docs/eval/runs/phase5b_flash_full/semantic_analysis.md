# Phase 5C Semantic Proxy Analysis

Run directory: `docs/eval/runs/phase5b_flash_full`

Semantic scorer: `scripts/eval/semantic_eval.py`

Schema: `dt_agent_semantic_proxy.v1`

Scope note: this is a deterministic semantic proxy over saved Phase 5B artifacts. It does not call an external LLM and does not verify biomedical truth. It measures saved answer/artifact signals that are relevant to semantic quality: evidence support, citation presence/relevance proxy, missing-evidence handling, and research usefulness.

## Aggregate Result

| Metric | Value |
|---|---:|
| Cases | 30 |
| Semantic winner: Agent | 13 |
| Semantic winner: Deep Think | 11 |
| Semantic winner: Tie | 6 |
| Average claim support score | 0.726 |
| Average citation relevance score | 0.562 |
| Average missing-evidence handling score | 0.672 |
| Average research usefulness score | 0.679 |
| Cases with explicit limitations | 14 |

## Interpretation

Phase 5C changes the evidence level from "Agent produced more artifacts" to "Agent shows deterministic signs of research-answer usefulness on a subset of cases." Agent wins 13/30 saved cases by the semantic proxy, while Deep Think remains stronger or sufficient in 17/30 cases when ties are counted as non-Agent wins.

This supports the current product boundary:

- Deep Think remains the default immediate QA path.
- Agent is promising for research synthesis, evidence audit, mechanism review, graph ranking, and report-like tasks.
- Agent should not be promoted broadly until claim-level semantic judging is added or manually sampled.

## Agent-Preferred Cases

The proxy marks these cases as Agent wins:

- `P5A_B_008`
- `P5A_B_011`
- `P5A_B_012`
- `P5A_B_013`
- `P5A_B_018`
- `P5A_B_021`
- `P5A_B_022`
- `P5A_B_023`
- `P5A_B_024`
- `P5A_B_025`
- `P5A_B_027`
- `P5A_B_028`
- `P5A_B_030`

Common signal pattern:

- Agent has answer text plus research artifacts.
- Citation/evidence proxy signals are present.
- Missing-evidence or limitation wording is detected more often than in simple Deep Think answers.
- Research usefulness score is typically high, especially for report-oriented or synthesis-oriented cases.

## Deep Think-Preferred Cases

The proxy marks these cases as Deep Think wins:

- `P5A_B_002`
- `P5A_B_003`
- `P5A_B_004`
- `P5A_B_005`
- `P5A_B_006`
- `P5A_B_007`
- `P5A_B_010`
- `P5A_B_014`
- `P5A_B_016`
- `P5A_B_026`
- `P5A_B_029`

Common signal pattern:

- The case is simple lookup, navigation, or boundary-Deep Think shaped.
- Agent saved artifacts are missing, failed, or provide no semantic proxy advantage.
- Some records are limited by missing saved answer/evidence fields from earlier Phase 5B failures.

## Tie Cases

The proxy marks these cases as ties:

- `P5A_B_001`
- `P5A_B_009`
- `P5A_B_015`
- `P5A_B_017`
- `P5A_B_019`
- `P5A_B_020`

Interpretation:

- The deterministic proxy sees useful signals but not enough separation to claim a winner.
- Several tie cases should be candidates for human or LLM-judge review because artifact presence alone may understate or overstate actual scientific usefulness.

## Known Limitations

- The proxy cannot determine whether a biological claim is true.
- The proxy cannot judge whether a cited PMID directly supports a specific claim.
- The proxy is sensitive to saved artifact completeness. Phase 5B records with missing Agent answer/evidence are penalized and explicitly noted.
- The proxy is deterministic and useful for triage, not final scientific evaluation.

## Recommendation

Use Phase 5C output as a triage layer:

- Keep Deep Think as default for immediate QA.
- Route complex research-style prompts to Agent only when the task needs synthesis, evidence audit, ranking, or report structure.
- For future Phase 5D or manual audit, sample Agent-win and tie cases for claim-level review: claim, supporting evidence, citation relevance, and missing-evidence statement quality.
