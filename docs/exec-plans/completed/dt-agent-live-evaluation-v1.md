# DT vs Agent Live Evaluation v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development. Coordinator dispatches medium subagents only. Do not modify Neo4j, graph runtime, taxonomy, or expression runtime. This phase may call the same local DT/Agent endpoints used by the webpage and may consume DeepSeek/DashScope tokens.

**Goal:** Run the Phase 5A golden cases through the real webpage backend endpoint-equivalent Deep Think and Agent runtimes, collect comparable outputs, and identify where Agent adds research value beyond DT.

**Architecture:** The live evaluator reads `docs/eval/dt_agent_golden_cases.jsonl`, calls DT through `api/deep_think_stream.php`, calls Agent through `api/agent_runs.php` plus `api/agent_run_status.php`, then scores outputs with a Python live proxy scorer aligned with the Phase 5A `ModeComparisonEvaluation` concepts. Results are written under `docs/eval/runs/<run_id>/` so the evaluation is versionable and not hidden by the ignored `data/` directory.

**Tech Stack:** PHP/Python CLI runner, local WAMP/PHP endpoints, current TE-KG Agent/Deep Think APIs, DeepSeek/DashScope via existing project config.

---

## Scope

### In scope

- Add a live evaluation runner for the 30 Phase 5A golden cases.
- Use same webpage endpoints:
  - DT: `POST api/deep_think_stream.php`
  - Agent: `POST api/agent_runs.php`, then poll `api/agent_run_status.php`
- Run a canary subset first, then the 30-case set if environment is healthy.
- Persist raw DT/Agent outputs, normalized evaluation reports, and summary Markdown.
- Prefer `deepseek-v4-flash`; do not set `deepseek-v4-pro` by default. Later pro reruns are Phase 5C.

### Out of scope

- Do not change Deep Think behavior.
- Do not change Agent routing, plugins, graph/taxonomy/expression/Neo4j data.
- Do not build browser UI.
- Do not run pro reruns in this phase unless explicitly requested later.

## Runtime assumptions

- WAMP or equivalent local PHP server is running at `http://127.0.0.1/TE-` or `TEKG_BASE_URL`.
- Neo4j `tekg3` is running.
- LLM relay / provider config in `api/config.local.php` is available.
- Network/API spend is acceptable for 30 DT + 30 Agent live runs using flash defaults.

## Tasks

### Task 1: Environment and canary checks

Files:

- No production code changes expected.

Steps:

- Verify `docs/eval/dt_agent_golden_cases.jsonl` has 30 valid rows.
- Verify local API base URL is reachable.
- Verify Neo4j `tekg3` health using existing read-only checks.
- Run 1 DT canary and 1 Agent canary before the full set.

### Task 2: Live eval runner

Files:

- Create `scripts/eval/run_dt_agent_live_eval.py`
- Create `test/dt_agent_live_eval_runner_static_test.php` or equivalent static contract test.

Requirements:

- CLI flags:
  - `--base-url`
  - `--cases`
  - `--out-dir`
  - `--limit`
  - `--case-id`
  - `--dt-only`
  - `--agent-only`
  - `--timeout`
  - `--poll-interval`
- Preserve raw SSE/status payloads.
- Use no concurrency by default.
- Stop cleanly on per-case timeout and record failure attribution.

### Task 3: Run live evaluation

Files:

- Output under `docs/eval/runs/<timestamp>/`

Steps:

- Run canary subset first.
- If canary passes, run all 30 cases.
- Generate:
  - `raw_events/*.json`
  - `case_results.jsonl`
  - `summary.json`
  - `summary.md`

### Task 4: Analysis and docs

Files:

- Update `docs/architecture/tekg_agent_development_guide.md`
- Update `docs/RELIABILITY.md`
- Update `docs/exec-plans/tech-debt-tracker.md`
- Move this plan to `docs/exec-plans/completed/dt-agent-live-evaluation-v1.md`

Requirements:

- Summarize best-mode match rate, Agent value-added distribution, failure stages, latency, and clear optimization directions.
- Call out whether Agent is genuinely deeper than DT on research tasks.
- Separate framework limitations from product bugs.

## Verification

Run:

```powershell
php test\dt_agent_golden_cases_test.php
php test\mode_comparison_evaluation_test.php
python scripts/checks/check_neo4j_tekg3.py
python scripts/eval/run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --limit 1 --out-dir docs/eval/runs/canary
```

Full run, only after canary:

```powershell
python scripts/eval/run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --out-dir docs/eval/runs/<timestamp>
```

## Stop conditions

- Stop if local API base URL is unreachable.
- Stop if Neo4j `tekg3` is unavailable.
- Stop if LLM relay/provider calls fail on canary.
- Stop if canary Agent run cannot complete or fail cleanly within timeout.
- Stop if runner would need to bypass `agent_runs.php` / `agent_run_status.php` and call worker internals as the primary user path.

---

## Completion Record - 2026-05-26

Status: completed.

Implemented files:

- `scripts/eval/run_dt_agent_live_eval.py`
- `docs/eval/runs/canary_phase5b/`
- `docs/eval/runs/phase5b_flash_full/`
- `docs/eval/runs/phase5b_flash_full/analysis.md`
- `docs/architecture/tekg_agent_development_guide.md`
- `docs/RELIABILITY.md`
- `docs/exec-plans/tech-debt-tracker.md`

Live run:

- Canary: 1 case, DT ok, Agent ok.
- Full run: 30 cases using current default `deepseek-v4-flash` runtime.
- DT success: 30/30.
- Agent success: 24/30.
- Agent overkill: 5/30.

Key findings:

- DT is currently more reliable and faster across the seed set.
- Agent completes most research tasks and often produces longer, structured evidence-walk style answers.
- Current deterministic proxy reports low Agent value-added because it measures artifact presence, not semantic claim quality.
- Agent should not run full Evidence Walk Writing for simple lookup/navigation/single-hop tasks.
- Site Navigator URL formatting can trigger false-positive integrity failures.

Verification:

```powershell
php test\dt_agent_golden_cases_test.php
php test\mode_comparison_evaluation_test.php
python scripts\checks\check_neo4j_tekg3.py
python -m py_compile scripts\eval\run_dt_agent_live_eval.py
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --limit 1 --out-dir docs\eval\runs\canary_phase5b --timeout 240 --poll-interval 2
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --out-dir docs\eval\runs\phase5b_flash_full --timeout 300 --poll-interval 2
python scripts\eval\run_dt_agent_live_eval.py --rescore-existing --out-dir docs\eval\runs\phase5b_flash_full
```

Residual risks:

- The runner recorded two Agent transport/JSON failures that need individual replay before product diagnosis.
- The scorer is still deterministic and coarse; Phase 5C should add semantic evaluator and human sampling.
- This phase did not run `deepseek-v4-pro`; pro comparison should be limited to complex/failed samples after routing/gate fixes.
