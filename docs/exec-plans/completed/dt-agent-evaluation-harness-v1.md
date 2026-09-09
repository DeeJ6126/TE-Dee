# DT vs Agent Evaluation Harness v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development. Coordinator dispatches medium subagents only. Do not modify Neo4j, graph runtime, taxonomy, or expression runtime. Do not run live DeepSeek API in this phase.

**Goal:** Build the first deterministic DT-vs-Agent evaluation harness so TE-KG can measure when Agent adds research value beyond Deep Think.

**Architecture:** Golden cases define paired DT and Agent expectations. A deterministic `ModeComparisonEvaluation` contract scores artifacts from the same web runtime outputs used by `agent.php`: Deep Think through `api/deep_think_stream.php`, Agent through async `api/agent_runs.php` / `api/agent_run_status.php` / `api/agent_run_execute.php`. Phase 5A does not run live API batches; it establishes stable cases, metrics, contracts, tests, and documentation.

**Tech Stack:** PHP contracts and fixture tests, JSONL golden cases, current TE-KG Agent/Deep Think services.

---

## Scope

### In scope

- Create `docs/eval/dt_agent_golden_cases.jsonl` with 30 paired cases. The original `data/eval` target is not used because the repository ignores `data/`.
- Create `api/agent/contracts/ModeComparisonEvaluation.php`.
- Add deterministic fixture tests for DT report, Agent report, and comparison scoring.
- Add `agent_eval_model_strategy` config documentation and runtime config keys for writer/polisher recommendations:
  - writer / evidence-grounded drafting: prefer `deepseek-v4-pro` for complex report generation.
  - polisher / evidence-preserving polishing: prefer `deepseek-v4-flash`.
  - default evaluator/bulk smoke: prefer `deepseek-v4-flash`; reserve `deepseek-v4-pro` for complex cases.
- Add minimal Agent response field `evaluation_report` generated from existing runtime artifacts.
- Update long-term docs with Phase 5A design, same-runtime requirement, and next optimization directions.

### Out of scope

- Do not run live DeepSeek API batches.
- Do not build browser UI for evaluation.
- Do not change Deep Think behavior.
- Do not modify plugin implementations.
- Do not modify Neo4j, graph API/runtime, taxonomy, or expression runtime.

## Same Runtime Rule

Evaluation must use the same runtime as the webpage:

- Deep Think: `agent.php` config key `deepThinkStreamApiUrl`, backend `api/deep_think_stream.php`, service `TekgDeepThinkService`.
- Agent: `agent.php` config keys `agentRunCreateUrl` and `agentRunStatusUrl`, backend async run pipeline `api/agent_runs.php` -> `api/agent_run_execute.php` -> `TekgAcademicAgentService`.
- Evaluation fixtures may call contracts directly, but any future live harness must call those endpoints rather than a separate test-only Agent.

## Tasks

### Task 1: Golden cases

Files:

- Create `docs/eval/dt_agent_golden_cases.jsonl`

Requirements:

- Exactly 30 JSONL rows.
- Each row has `case_id`, `question`, `category`, `expected_best_mode`, `dt_expectation`, `agent_expectation`, `comparison_metrics`.
- Cover simple lookup, site navigation, mechanism review, evidence audit, graph ranking, batch comparison, report generation, and boundary routing.

### Task 2: Deterministic comparison contract

Files:

- Create `api/agent/contracts/ModeComparisonEvaluation.php`
- Create `test/mode_comparison_evaluation_test.php`

Requirements:

- Evaluate DT report fields: answer presence, plugin names, citations, routes, latency, simple-task fit.
- Evaluate Agent report fields: evidence_package, evidence_walk, report_plan, integrity_report, citations, routes, writing failure, latency, model strategy.
- Compare value added: `none`, `low`, `medium`, `high`.
- Detect overkill: simple cases where Agent is expected not to add value.
- Avoid LLM calls.

### Task 3: Minimal Agent response integration

Files:

- Modify `api/agent/orchestrator/AcademicAgentService.php`
- Modify `api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php` if node payloads need the report.
- Create or update `test/agent_evaluation_report_runtime_test.php`

Requirements:

- Add `evaluation_report` to Agent response using only existing runtime artifacts.
- Do not change API request structure.
- Do not alter Deep Think response shape.

### Task 4: Model strategy keys

Files:

- Modify `api/agent/bootstrap.php`
- Modify `api/config.local.php`
- Update tests if config contracts exist.

Requirements:

- Add `agent_polisher_model` config resolution.
- Default local recommendation: keep `agent_writing_model = deepseek-v4-flash`, set `agent_polisher_model = deepseek-v4-flash`, and reserve `deepseek-v4-pro` for later live-eval payload overrides on complex or failed cases.
- Keep `deepseek_model` and bulk/default paths on `deepseek-v4-flash`.

### Task 5: Documentation and completion

Files:

- Update `docs/architecture/tekg_agent_development_guide.md`.
- Update `docs/RELIABILITY.md`.
- Update `docs/exec-plans/tech-debt-tracker.md` if residual live-eval work remains.
- Move this plan to `docs/exec-plans/completed/dt-agent-evaluation-harness-v1.md`.

Requirements:

- Document that Phase 5A is deterministic harness only.
- Document next phase: live DeepSeek evaluation over the 30 cases, then expand to 80-120 cases.
- Document cost-aware model strategy: flash for broad evaluation, pro for complex research cases and evidence-first drafting.

## Verification

Run:

```powershell
php -l api\agent\contracts\ModeComparisonEvaluation.php
php -l api\agent\orchestrator\AcademicAgentService.php
php -l api\agent\bootstrap.php
php test\mode_comparison_evaluation_test.php
php test\agent_evaluation_report_runtime_test.php
php test\agent_evidence_walk_runtime_test.php
php test\agent_narration_task_complexity_test.php
```

Also verify JSONL:

```powershell
php -r "$f='docs/eval/dt_agent_golden_cases.jsonl'; $n=0; foreach(file($f) as $line){$n++; json_decode($line,true); if(json_last_error()){fwrite(STDERR,\"bad json line $n\n\"); exit(1);}} if($n!==30){fwrite(STDERR,\"expected 30 lines, got $n\n\"); exit(1);} echo \"golden cases ok\n\";"
```

## Stop Conditions

- Stop if implementation would require live LLM/Neo4j/browser verification.
- Stop if Agent and DT cannot be evaluated through their webpage runtime endpoints.
- Stop if adding `evaluation_report` would break existing Agent response compatibility.

---

## Completion Record - 2026-05-26

Status: completed.

Implemented files:

- `docs/eval/dt_agent_golden_cases.jsonl`
- `api/agent/contracts/ModeComparisonEvaluation.php`
- `api/agent/orchestrator/AcademicAgentService.php`
- `api/agent/bootstrap.php`
- `api/config.local.php`
- `test/mode_comparison_evaluation_test.php`
- `test/dt_agent_golden_cases_test.php`
- `test/agent_evaluation_report_runtime_test.php`
- `docs/architecture/tekg_agent_development_guide.md`
- `docs/RELIABILITY.md`
- `docs/exec-plans/tech-debt-tracker.md`

Runtime result:

- Agent response now includes `evaluation_report`, derived only from existing Agent runtime artifacts.
- `agent_polisher_model` is resolved by `tekg_agent_config()` and local default is `deepseek-v4-flash`.
- `agent_writing_model` remains `deepseek-v4-flash`; Phase 5A does not make `deepseek-v4-pro` the default writer.
- DT and Agent same-runtime constraints are documented: DT uses `api/deep_think_stream.php`, Agent uses `api/agent_runs.php` plus `api/agent_run_status.php`.

Verification:

```powershell
php -l api\agent\contracts\ModeComparisonEvaluation.php
php -l api\agent\orchestrator\AcademicAgentService.php
php -l api\agent\bootstrap.php
php test\mode_comparison_evaluation_test.php
php test\dt_agent_golden_cases_test.php
php test\agent_evaluation_report_runtime_test.php
php test\agent_evidence_walk_runtime_test.php
php test\agent_narration_task_complexity_test.php
```

All commands passed with exit code 0. PowerShell still prints the local profile execution-policy warning, but it does not affect PHP command exit status.

Residual risks:

- Phase 5A does not run live DeepSeek/DashScope API, WAMP, Neo4j, or browser tests.
- `ModeComparisonEvaluation` is a deterministic proxy for evaluation readiness, not a semantic judge of real answer quality.
- The 30 golden cases are seed cases; Phase 5B should run them live with `deepseek-v4-flash`, then expand to 80-120 cases.
