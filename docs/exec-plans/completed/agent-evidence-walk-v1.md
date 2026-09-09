# Agent Evidence Walk v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development. Coordinator dispatches medium subagents only. Do not modify Neo4j, graph runtime, taxonomy, or expression runtime.

**Goal:** Implement fourth-stage Agent differentiation with a deterministic TE-KG Evidence Walk, a two-model Writer/Polisher chain using evidence-grounded drafting and evidence-preserving polishing, and a deterministic integrity gate.

**Architecture:** EvidencePackage remains the mandatory evidence source. New deterministic builders derive `evidence_walk.v1` and `report_plan.v1` from the package. Agent Writing uses two LLM calls: first draft from the writing model/policy, then polish from a second model/policy. A deterministic integrity gate validates that the polished report does not add unsupported citations, URLs, or claims beyond package/walk evidence.

**Tech Stack:** PHP orchestrator, existing LLM client, fixture PHP tests, no live service dependency.

---

## Scope

### In scope
- Add `EvidenceWalk` contract/builder.
- Add `ReportPlan` contract/builder.
- Add two LLM client methods for evidence-first drafting and evidence-preserving polishing.
- Add deterministic `ReportIntegrityGate`.
- Wire Agent Writing to draft -> polish -> integrity gate.
- Add non-LLM tests for builders, prompt constraints, and integrity gate.
- Update long-term docs and completion plan.

### Out of scope
- Do not change Deep Think behavior.
- Do not modify plugin implementations.
- Do not modify Neo4j, graph API, taxonomy, expression runtime, or frontend UI unless a runtime contract requires a small payload field.
- Do not run live LLM tests in this session.

## Requirements

1. `evidence_walk.v1` is derived only from `evidence_package.v1`.
2. `report_plan.v1` is deterministic and selected by intent/task type: mechanism review, evidence audit, batch comparison, graph ranking, or research report.
3. Writer model call uses evidence-first drafting policy: evidence first, section plan, claim-evidence map, bounded claims.
4. Polisher model call uses evidence-preserving polishing policy: polish without adding claims/citations/URLs, downgrade unsupported claims, preserve citation and route integrity.
5. Integrity gate checks the polished report against package/walk references and blocks unsupported new PMID/URL/citation IDs or suspicious strong claims.
6. Final Agent response should prefer polished report if integrity passes; otherwise fail clearly or return draft with integrity failure metadata, but must not silently accept invalid polish.
7. Keep Agent response fields compatible by adding `evidence_walk`, `report_plan`, `draft_report`, `polished_report`, `integrity_report` without removing existing fields.

## Tasks

### Task 1: EvidenceWalk and ReportPlan contracts

Files:
- Create `api/agent/contracts/EvidenceWalk.php`
- Create `api/agent/contracts/ReportPlan.php`
- Create `api/agent/config/evidence_walk_schema.php`
- Create `api/agent/config/report_plan_schema.php`
- Create `test/evidence_walk_report_plan_test.php`

Test-first requirements:
- Construct a package with literature claim, citation, and route.
- Assert deterministic `walk_id`, `path_id`, `claim_id`, `evidence_ids`, `citation_ids`, `route_ids`.
- Assert mechanism intent produces mechanism review sections.
- Assert graph_analytics intent produces ranking report sections.
- Assert invalid walk/plan fails validation.

### Task 2: ReportIntegrityGate

Files:
- Create `api/agent/contracts/ReportIntegrityGate.php`
- Create `test/report_integrity_gate_test.php`

Test-first requirements:
- Valid report with known PMID and URL passes.
- Report with new PMID not in package fails.
- Report with new http URL not in package route_map/citation_map fails.
- Report with strong unsupported phrases and no package claims warns or fails according to severity.

### Task 3: Two-model LLM client methods

Files:
- Modify `api/agent/orchestrator/LlmClient.php`
- Create/modify `test/agent_research_report_prompt_test.php`

Test-first requirements:
- Prompt for draft contains evidence-first drafting policy terms and includes only `evidence_package`, `evidence_walk`, `report_plan` as evidence body.
- Prompt for polish contains evidence-preserving polishing constraints and explicitly forbids new claims, PMID, URLs, and citations.
- Prompt does not include raw plugin payload keys.

### Task 4: Agent runtime integration

Files:
- Modify `api/agent/orchestrator/AcademicAgentService.php`
- Modify `api/agent/orchestrator/traits/AcademicAgentEvidenceTrait.php` if helper placement is needed
- Modify `api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php` for node payloads
- Create/modify `test/agent_evidence_walk_runtime_test.php`

Test-first requirements:
- Static runtime test verifies `AcademicAgentService` calls draft, polish, and integrity gate path.
- Answer Writer Node input includes `evidence_package`, `evidence_walk`, `report_plan`.
- Legacy `writeStructuredAnswer()` remains unused by Agent runtime.

### Task 5: Docs and completion

Files:
- Move this plan to `docs/exec-plans/completed/agent-evidence-walk-v1.md` after implementation.
- Update `docs/architecture/tekg_agent_development_guide.md` fourth-stage status.
- Update `docs/RELIABILITY.md` with checks and residual risks.
- Update `docs/exec-plans/tech-debt-tracker.md` if residual non-writing legacy consumers remain.

## Verification

Run:

```powershell
php -l api\agent\contracts\EvidenceWalk.php
php -l api\agent\contracts\ReportPlan.php
php -l api\agent\contracts\ReportIntegrityGate.php
php -l api\agent\orchestrator\LlmClient.php
php -l api\agent\orchestrator\AcademicAgentService.php
php test\evidence_package_test.php
php test\evidence_walk_report_plan_test.php
php test\report_integrity_gate_test.php
php test\agent_research_report_prompt_test.php
php test\agent_evidence_walk_runtime_test.php
php test\agent_evidence_package_runtime_test.php
```

## Stop conditions

- Stop if implementation requires changing plugin output formats.
- Stop if it requires live LLM or Neo4j to verify basic behavior.
- Stop if Agent cannot preserve current response structure while adding report artifacts.

---

## Completion Record - 2026-05-26

Status: completed.

Implemented files:

- `api/agent/contracts/EvidenceWalk.php`
- `api/agent/contracts/ReportPlan.php`
- `api/agent/contracts/ReportIntegrityGate.php`
- `api/agent/config/evidence_walk_schema.php`
- `api/agent/config/report_plan_schema.php`
- `api/agent/orchestrator/AcademicAgentService.php`
- `api/agent/orchestrator/LlmClient.php`
- `api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php`
- `api/agent/bootstrap/evidence_support.php`
- `test/evidence_walk_report_plan_test.php`
- `test/report_integrity_gate_test.php`
- `test/agent_research_report_prompt_test.php`
- `test/agent_evidence_walk_runtime_test.php`
- `test/agent_evidence_package_runtime_test.php`

Runtime result:

- Agent Writing now derives `evidence_walk.v1` and `report_plan.v1` from the validated `evidence_package.v1`.
- Writing uses `TekgAgentLlmClient::writeEvidenceWalkDraft()` followed by deterministic `ReportIntegrityGate::check()`.
- Writing then uses `TekgAgentLlmClient::polishEvidenceWalkAnswer()` followed by deterministic `ReportIntegrityGate::check()`.
- `writeEvidencePackageAnswer()` and `buildEvidencePackageAnswerPrompt()` were removed to avoid accidental reuse of the old direct package-writing path.
- `writeStructuredAnswer()` remains in `LlmClient` for older non-Agent callers, but Agent runtime static tests assert it is not called.

Verifier result:

- A Verifier subagent reported all planned PHP lint/tests passed before the final removal of the old direct evidence-package writer.
- After the final removal, run the verification commands in `docs/RELIABILITY.md` section `Agent Evidence Walk Reliability Checks - 2026-05-26` as the current gate.

Residual risks:

- No live WAMP, Neo4j, browser, or LLM verification was performed in this plan.
- `ReportIntegrityGate` is a deterministic guardrail, not a complete factual evaluator.
- Section-title coverage is warning-only to avoid blocking Chinese or freer report styles; calibration is tracked in `docs/exec-plans/tech-debt-tracker.md`.
