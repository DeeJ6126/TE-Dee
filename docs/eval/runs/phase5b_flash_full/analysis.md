# Phase 5B DT vs Agent Live Evaluation Analysis

Run directory: `docs/eval/runs/phase5b_flash_full`

Model strategy: default `deepseek-v4-flash` through the current local TE-KG runtime.

Runtime path:

- Deep Think: `api/deep_think_stream.php`
- Agent: `api/agent_runs.php` + `api/agent_run_status.php`
- Neo4j target: `tekg3`

Scope note: this is backend endpoint-equivalent to the webpage path, not a full browser UI run. It does not exercise DOM state, browser AbortController behavior, or the exact frontend stream renderer.

Scoring note: `case_results.jsonl` uses the Python live proxy scorer in `scripts/eval/run_dt_agent_live_eval.py`, aligned with Phase 5A metrics but not a direct invocation of the PHP `ModeComparisonEvaluation` contract.

## Executive Summary

The 30-case live run completed and produced usable raw evidence for DT vs Agent comparison.

Main result: Deep Think is currently more reliable and much faster across the seed set. Agent completes most research cases and often produces longer, more structured evidence-walk style answers, but the current deterministic scorer only measures artifact presence and cannot yet judge semantic depth. Agent also still over-executes several simple tasks and fails several site-navigation/simple-boundary cases in Writing.

This means Agent has a visible research-report direction, but Phase 5B does not yet prove strong quality superiority over DT. It identifies the next optimization targets.

## Aggregate Results

| Expected best mode | Cases | DT success | Agent success | Agent overkill | Avg DT ms | Avg Agent ms | Agent value added |
|---|---:|---:|---:|---:|---:|---:|---|
| Deep Think | 10 | 10 | 6 | 4 | 4,968 | 46,422 | low=9, none=1 |
| Boundary Deep Think | 2 | 2 | 2 | 1 | 14,113 | 57,889 | low=2 |
| Agent | 18 | 18 | 16 | 0 | 38,565 | 75,758 | low=16, none=2 |

Overall:

- DT success: 30/30
- Agent success: 24/30
- Agent overkill: 5/30
- Agent value added by deterministic proxy: low=27, none=3

Interpretation:

- DT is robust for immediate QA and even answers many research prompts with shorter summaries.
- Agent is slower but generates research-style structure on many complex prompts.
- The deterministic proxy underestimates semantic value because it does not evaluate claim quality, mechanism coherence, or citation relevance.
- The current Agent needs stronger pre-routing so simple/navigation tasks do not enter heavy Writing.

## Agent Failure Cases

| Case | Expected | Failure type | Interpretation |
|---|---|---|---|
| P5A_B_005 | Deep Think | Writing gate rejected Markdown URL variants | Site Navigator routes are evidence, but URL normalization in `ReportIntegrityGate` is too strict for model-emitted Markdown fragments. |
| P5A_B_006 | Deep Think | Writing gate rejected malformed Markdown URLs | Same Site Navigator / URL normalization issue. |
| P5A_B_014 | Agent | HTTP 404 during Agent create/poll path | Runner or transient backend path issue; needs replay with raw HTTP capture before product diagnosis. |
| P5A_B_016 | Agent | JSON parse error from empty/non-JSON response | Runner should record raw body/status for non-JSON failures; needs replay. |
| P5A_B_026 | Deep Think | Writing timeout at 40s | Simple explanation should not run heavy Agent Writing; route to DT. |
| P5A_B_029 | Deep Think | Writing gate rejected Site Navigator URLs | Same Site Navigator / URL normalization issue. |

## Agent Overkill Cases

Agent over-executed these simple or boundary-DT prompts:

- P5A_B_001: `L1HS 的序列是什么？`
- P5A_B_007: `L1HS 和哪些疾病有直接关系？`
- P5A_B_008: `Alzheimer's disease 相关的 TE 有哪些？`
- P5A_B_009: `What papers support LINE-1 and Alzheimer's disease?`
- P5A_B_026: `L1HS 是什么？`

Optimization direction:

- Agent should hard-suggest Deep Think for simple sequence, single-hop relation, direct paper-list, and definition prompts.
- If user forces Agent, it should use a compact answer path and avoid full evidence-walk Writing.

## What Agent Already Does Better

In complex prompts such as mechanism review, graph ranking, and research report generation, Agent frequently produced longer, sectioned, evidence-walk style answers. Examples include:

- P5A_B_011: LINE-1 cancer mechanism chain.
- P5A_B_017: TE-disease graph ranking.
- P5A_B_023: L1HS research dossier.

However, the current live proxy only scores artifact presence. A fair comparison requires claim-level semantic evaluation:

- Are Agent claims better grounded than DT claims?
- Are citations directly relevant?
- Does Agent expose missing evidence more clearly?
- Does Agent correctly distinguish supported claim vs hypothesis?
- Are graph paths and route links correct?

## Next Optimization Directions

Priority 1: Boundary routing

- Keep Deep Think as default for sequence, location, definition, site navigation, and single-hop relation queries.
- In Agent, add a stronger preflight gate: if `analysis.recommended_mode` is `deep_think` and no research-report requirement is present, return a compact redirect/suggestion instead of running full Writing.

Priority 2: Site Navigator + integrity gate

- Normalize Markdown-wrapped URLs before `ReportIntegrityGate` checks them.
- Treat Site Navigator `route_map` URLs as allowed route evidence even if the writer emits minor Markdown punctuation.
- Add tests for malformed URL fragments like `url](url)` and trailing backticks.

Priority 3: Live evaluator robustness

- Keep extracting Agent artifacts from `synthesizing` events unless done payload is expanded.
- Record raw HTTP status/body for Agent create/poll non-JSON failures.
- Add a replay command for individual failed cases without rerunning all 30.

Priority 4: Semantic evaluator

- Add a Phase 5C evaluator that samples DT/Agent answers and scores claim faithfulness, citation relevance, missing-evidence reporting, and report usefulness.
- Use `deepseek-v4-flash` for broad evaluation and reserve `deepseek-v4-pro` for complex/failed samples.

## Bottom Line

Phase 5B validates the evaluation harness and gives the first real DT/Agent comparison. Current evidence supports the product boundary:

- DT should remain the default all-site immediate QA.
- Agent should not handle simple lookup/navigation tasks through full Writing.
- Agent's future advantage is research-report quality, evidence audit, graph ranking, and claim-level verification, but this requires stronger routing and a semantic evaluation layer before claiming superiority.
