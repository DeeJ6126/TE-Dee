# Phase 5C Semantic Evaluation

> Completed coordinator harness task. Medium worker subagents were used for implementation slices and the coordinator independently verified outputs. Neo4j, graph runtime, taxonomy, expression runtime, Deep Think runtime routing, and Agent runtime routing were not modified.

**Goal:** Add a semantic evaluation layer for Deep Think vs Agent that measures answer quality beyond deterministic artifact presence.

## Completed Changes

### Task A: Deterministic Semantic Proxy

**Files changed:**
- `scripts/eval/semantic_eval.py`
- `test/dt_agent_semantic_eval_test.py`

**Result:**
- Added `score_semantic_proxy(case, dt_result, agent_result)`.
- The semantic proxy emits:
  - `claim_support_score`
  - `citation_relevance_score`
  - `missing_evidence_score`
  - `research_usefulness_score`
  - `semantic_winner`
  - `semantic_notes`
- The v1 scorer is deterministic and does not call external LLMs.
- Tests cover Agent-win, weak-long-Agent-answer, and Deep Think/compact-boundary scenarios.

### Task B: Saved-Run Post-Processor

**Files changed:**
- `scripts/eval/semantic_eval.py`

**Result:**
- Added CLI support for saved run directories:

```powershell
python scripts\eval\semantic_eval.py --run-dir docs\eval\runs\phase5b_flash_full
```

- Generated:
  - `docs/eval/runs/phase5b_flash_full/semantic_case_results.jsonl`
  - `docs/eval/runs/phase5b_flash_full/semantic_summary.json`
  - `docs/eval/runs/phase5b_flash_full/semantic_summary.md`

### Task C: Optional Live Runner Integration

**Files changed:**
- `scripts/eval/run_dt_agent_live_eval.py`
- `test/dt_agent_live_eval_score_test.py`

**Result:**
- Added optional `--semantic-proxy`.
- Default runner output remains compatible when the flag is absent.
- When enabled, semantic results are written into `raw_events`, `case_results.jsonl`, `summary.json`, and `summary.md`.
- `--rescore-existing --semantic-proxy` recomputes semantic results from saved raw events without rerunning live APIs.
- Regression coverage includes both `rescore_existing()` and a no-network main-loop test that stubs `run_dt()` / `run_agent()` and verifies `--semantic-proxy` writes semantic fields.

### Task D: Phase 5B Semantic Analysis

**Files changed:**
- `docs/eval/runs/phase5b_flash_full/semantic_analysis.md`

**Result:**
- Phase 5B saved run now has a semantic proxy analysis.
- Aggregate result:
  - Agent wins: 13/30
  - Deep Think wins: 11/30
  - Ties: 6/30
- This supports the existing boundary: Deep Think remains default immediate QA; Agent has measurable research-task promise but still needs claim-level review before broad quality claims.

### Task E: Reviewer Follow-Up Coverage

**Files changed:**
- `test/dt_agent_live_eval_score_test.py`
- `test/dt_agent_semantic_eval_test.py`

**Result:**
- Added no-network main-loop coverage for `--semantic-proxy`.
- Added CLI fallback coverage for summary-only saved runs where `raw_events/<case>.json` is missing.
- The fallback test verifies that semantic notes include the missing raw-events limitation and that the summary limitation count is populated.

## Verification

Passed:

```powershell
python test\dt_agent_live_eval_score_test.py
python test\dt_agent_semantic_eval_test.py
python -m py_compile scripts\eval\run_dt_agent_live_eval.py scripts\eval\semantic_eval.py test\dt_agent_semantic_eval_test.py
python scripts\eval\semantic_eval.py --run-dir docs\eval\runs\phase5b_flash_full
python scripts\eval\run_dt_agent_live_eval.py --rescore-existing --semantic-proxy --out-dir docs\eval\runs\phase5b_flash_full
python scripts/checks/check_docs_freshness.py
python scripts/checks/check_api_contracts.py
```

No live API rerun was required for Phase 5C implementation. The work operates over saved Phase 5B artifacts.

## Key Decision

Phase 5C intentionally remains a deterministic semantic proxy, not a final truth judge. It improves triage and product-boundary evidence without pretending to verify biomedical facts or claim-level citation support.

## Residual Risk

- The v1 proxy cannot determine whether a biological claim is true.
- The v1 proxy cannot prove whether a specific citation directly supports a specific claim.
- Some Phase 5B saved records lack complete answer/evidence artifacts; these cases are explicitly marked with semantic limitations.
- A future Phase 5D or manual audit should sample Agent-win and tie cases for claim-level review.
- `--rescore-existing` rewrites saved run outputs by design; use it intentionally on historical runs.
