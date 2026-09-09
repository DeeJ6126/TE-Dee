# Phase 5B.1 Boundary and URL Gate Fixes

> Completed coordinator harness task. Medium worker subagents were used for the two independent implementation slices. Neo4j, graph runtime, taxonomy, and expression runtime were not modified.

**Goal:** Fix the two live-evaluation regressions found in Phase 5B: Site Navigator URL integrity false positives and Agent over-execution on simple DT-suitable tasks.

## Completed Changes

### Task A: Site Navigator URL Integrity Normalization

**Files changed:**
- `api/agent/contracts/ReportIntegrityGate.php`
- `test/report_integrity_gate_test.php`

**Result:**
- `ReportIntegrityGate::cleanUrl()` now normalizes benign Markdown URL noise before whitelist matching.
- Route-map URLs with trailing Markdown backticks or bold markers are accepted after normalization.
- Malformed Markdown fragments such as `url](url)` are normalized to the intended URL.
- Unknown URLs remain rejected after normalization, so the whitelist was not broadly relaxed.
- Route-map URLs with a fragment now also authorize the same route without the fragment. This fixes live Writing drafts that cite `search.php?q=L1HS&type=TE` when the evidence route is `search.php?q=L1HS&type=TE#search-karyotype-panel`.

### Task B: Agent Simple-Task Preflight Gate

**Files changed:**
- `api/agent/orchestrator/AcademicAgentService.php`
- `test/agent_simple_preflight_gate_test.php`

**Result:**
- Agent now applies a compact preflight gate when analysis recommends Deep Think and the task is a simple lookup/single-hop/ambiguous task with no research signal.
- Compact responses skip full Evidence Walk Writing while preserving the Agent API response shape.
- Mechanism, literature, comparison, ranking, audit, batch, and report-style prompts remain eligible for the full Agent workflow.
- Compact response language handling includes `chinese`, `zh`, `zh-cn`, and `zh_cn`.

### Task C: Compact Preflight Confidence Type

**Files changed:**
- `api/agent/orchestrator/AcademicAgentService.php`
- `test/agent_simple_preflight_gate_test.php`

**Result:**
- `buildCompactPreflightResponse()` now accepts string confidence labels from `inferConfidence()` (`low`, `medium`, `high`).
- The compact preflight regression test now exercises the same string confidence shape seen in live Agent runs.

### Task D: Live Eval Overkill Proxy Alignment

**Files changed:**
- `scripts/eval/run_dt_agent_live_eval.py`
- `test/dt_agent_live_eval_score_test.py`

**Result:**
- The Python live proxy scorer now matches the PHP `ModeComparisonEvaluation` overkill rule more closely.
- Deep Think expected cases only count as Agent overkill when Agent uses more than two plugins and has `agent_artifact_score > 0.5`.
- Compact Agent responses with multiple lightweight plugins and no full Evidence Walk/report artifacts are no longer counted as overkill.

## Verification

Passed:
- `php test\report_integrity_gate_test.php`
- `php test\agent_simple_preflight_gate_test.php`
- `php test\agent_narration_task_complexity_test.php`
- `php test\agent_evaluation_report_runtime_test.php`
- `python test\dt_agent_live_eval_score_test.py`
- `php -l api\agent\contracts\ReportIntegrityGate.php`
- `php -l api\agent\orchestrator\AcademicAgentService.php`
- `php -l test\report_integrity_gate_test.php`
- `php -l test\agent_simple_preflight_gate_test.php`
- `python -m py_compile scripts\eval\run_dt_agent_live_eval.py test\dt_agent_live_eval_score_test.py`

Live canaries passed after the follow-up fixes:

```powershell
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_005 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_005 --timeout 300 --poll-interval 2
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_029 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_029 --timeout 300 --poll-interval 2
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_001 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_001 --timeout 300 --poll-interval 2
```

Latest live results:

| Case | DT | Agent | Agent overkill | Errors |
|---|---|---|---|---|
| `P5A_B_005` | ok, 7435 ms | ok, 44609 ms | false | `dt_errors=[]`, `agent_errors=[]` |
| `P5A_B_029` | ok, 7041 ms | ok, 2230 ms | false | `dt_errors=[]`, `agent_errors=[]` |
| `P5A_B_001` | ok, 7431 ms | ok, 2159 ms | false | `dt_errors=[]`, `agent_errors=[]` |

## Residual Risk

- The simple-task preflight gate still uses keyword signals for research-task exclusion. Phase 5C semantic evaluation should tune this with more examples.
- The live eval proxy scorer is still deterministic and artifact-based. It does not judge semantic correctness, citation relevance, missing-evidence reporting, or claim faithfulness.
- Phase 5C should add semantic evaluation before claiming Agent quality superiority on research tasks.
