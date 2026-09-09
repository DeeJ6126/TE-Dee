# Agent Evidence Package v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development. This plan is for the TE-KG Codex coordinator harness; all subagents default to medium reasoning. Do not modify Neo4j, taxonomy, graph runtime, or expression runtime.

**Goal:** Make Agent Writing consume a mandatory structured `evidence_package` generated during Integrating, with no legacy raw-payload fallback.

**Architecture:** Agent plugin calls already produce `result_envelope`. v1 introduces a focused evidence package builder that converts envelopes into auditable claims, evidence items, citation mappings, route mappings, metrics, and package limits. Integrating persists/emits the package; Writing receives only the package plus the user question/analysis metadata and fails clearly if the package is missing or invalid.

**Tech Stack:** PHP 8 style code, existing TE-KG orchestrator traits, local PHP tests, no database writes.

---

## Scope

### In scope
- Add a reusable Agent evidence package contract/builder under `api/agent/contracts/` or `api/agent/orchestrator/`.
- Integrate package generation into `AcademicAgentService` Integrating/Writing path.
- Remove old Agent Writing fallback to raw plugin payloads.
- Add tests for package schema, claim-level citation mapping, and Writing input enforcement.
- Update long-term docs after verification.

### Out of scope
- Do not change Deep Think runtime behavior in v1.
- Do not modify taxonomy, expression, graph API, Neo4j, MySQL, or external LLM provider config.
- Do not redesign frontend workflow UI beyond consuming existing events if necessary.

## Required behavior

1. `Integrating` creates `evidence_package` from plugin `result_envelope` values.
2. `evidence_package` contains at least:
   - `schema_version`
   - `question`
   - `generated_at`
   - `claims[]`
   - `evidence_items[]`
   - `citation_map[]`
   - `route_map[]`
   - `metrics`
   - `limits`
   - `errors[]`
3. Every claim has stable `claim_id`, `text`, `supporting_evidence_ids[]`, `citation_ids[]`, `source_plugins[]`, `confidence`.
4. Every evidence item has stable `evidence_id`, `plugin`, `kind`, `summary`, optional `entity`, optional `url`, optional `citation_id`, optional `raw_ref`, and `confidence`.
5. Writing must receive `evidence_package` as its evidence input. It must not receive the previous complete plugin raw payload bundle.
6. If the package cannot be generated or is invalid, Agent run fails clearly before Writing rather than silently falling back.
7. Existing plugin envelope compatibility remains, but Agent Writing no longer parses arbitrary plugin arrays.

## Work breakdown

### Task 1: Explore and pin exact integration points

**Owner:** Explorer, read-only.

- Confirm current Integrating/Writing function names and payload variables.
- Confirm current event names consumed by frontend.
- Confirm tests that can be extended without live LLM/Neo4j.

### Task 2: Add EvidencePackage contract and tests

**Files:**
- Create: `api/agent/contracts/EvidencePackage.php`
- Create or modify: `api/agent/config/evidence_package_schema.php`
- Create: `test/evidence_package_test.php`

**Implementation notes:**
- Builder accepts `question`, `analysis`, and an ordered list of plugin results or envelopes.
- Prefer `result_envelope`; if absent, wrap via `PluginResultEnvelope::fromPluginResult()` at package build boundary only.
- Limit summaries to a safe size and record truncation in `limits`.
- Produce deterministic IDs (`claim_1`, `evidence_1`, `citation_1`, etc.) for testability.

### Task 3: Integrate into Agent Integrating/Writing

**Files:**
- Modify only necessary files under `api/agent/orchestrator/AcademicAgentService.php` and `api/agent/orchestrator/traits/`.

**Implementation notes:**
- Generate `evidence_package` in Integrating before Writing.
- Store it in the run/session result structure already passed between stages.
- Writing prompt builder should consume `evidence_package`, not raw plugin results.
- Remove old fallback that reconstructs answer content from raw plugin dumps when structure/package is missing.
- Add clear error if `evidence_package` missing or invalid.

### Task 4: Update tests and static checks

**Commands:**
- `php -l api\agent\contracts\EvidencePackage.php`
- `php -l api\agent\config\evidence_package_schema.php`
- `php -l api\agent\orchestrator\AcademicAgentService.php`
- `php test\evidence_package_test.php`
- Existing relevant tests: `php test\plugin_result_envelope_test.php`, `php test\agent_narration_task_complexity_test.php`

### Task 5: Review, docs, completion record

**Files:**
- Move this file from `docs/exec-plans/active/` to `docs/exec-plans/completed/` after success.
- Update `docs/architecture/tekg_agent_development_guide.md` with the new v1 evidence package fact.
- Update `docs/RELIABILITY.md` if new failure behavior is important.
- Update `docs/exec-plans/tech-debt-tracker.md` only if residual tech debt is found.

## Risks

- If Writing currently depends on raw plugin-specific fields, removing fallback can break answers until package coverage is sufficient.
- Live LLM behavior cannot be fully verified by static tests.
- Some plugins may produce sparse envelopes; v1 must degrade into explicit `empty`/`partial` claims or fail clearly rather than hallucinate.

## Stop conditions

- Stop and report if changing Writing requires modifying unrelated graph/taxonomy/expression logic.
- Stop and report if no deterministic non-LLM test can validate package construction.
- Stop and report if existing run state format cannot carry package without API response change.

---

## Completion Record - 2026-05-26

### Actual Changes

- Created `api/agent/contracts/EvidencePackage.php` for `evidence_package.v1` construction and validation.
- Created `api/agent/config/evidence_package_schema.php` as the schema-style contract.
- Added `test/evidence_package_test.php` for package construction, nested validation, claim IDs, citation mapping, route mapping, empty/error plugin handling, and truncation.
- Added `test/agent_evidence_package_runtime_test.php` for Agent writer prompt and Answer Writer Node constraints.
- Updated `api/agent/orchestrator/AcademicAgentService.php` so Agent Integrating builds a validated `evidence_package` and Agent Writing calls `writeEvidencePackageAnswer()`.
- Updated `api/agent/orchestrator/LlmClient.php` with `writeEvidencePackageAnswer()` and a prompt that treats `evidence_package` as the only evidence body.
- Updated `api/agent/orchestrator/traits/AcademicAgentEvidenceTrait.php` with validated package construction and package-derived synthesis/citation helpers.
- Updated `api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php` so `Answer Writer Node` input contains `evidence_package` instead of legacy `supported_claims` / `citation_bundle` writer inputs.
- Updated long-term docs: `docs/architecture/tekg_agent_development_guide.md`, `docs/RELIABILITY.md`, and `docs/exec-plans/tech-debt-tracker.md`.

### Key Decisions

- Agent Writing no longer uses the old scattered writer evidence inputs. It reads `evidence_package.v1` only.
- Package validation failure is explicit and throws before Writing; there is no silent fallback to raw plugin payloads.
- Deep Think was intentionally not migrated in v1.
- Legacy `evidence`, `citations`, and `synthesized_evidence` remain in response/UI compatibility fields, but they are not the Agent writer evidence path.

### Verification

```powershell
php -l api\agent\contracts\EvidencePackage.php
php -l api\agent\config\evidence_package_schema.php
php -l api\agent\orchestrator\AcademicAgentService.php
php -l api\agent\orchestrator\LlmClient.php
php -l api\agent\orchestrator\traits\AcademicAgentEvidenceTrait.php
php -l api\agent\orchestrator\traits\AcademicAgentPluginResultTrait.php
php -l test\evidence_package_test.php
php -l test\agent_evidence_package_runtime_test.php
php test\evidence_package_test.php
php test\plugin_result_envelope_test.php
php test\agent_evidence_package_runtime_test.php
php test\agent_narration_task_complexity_test.php
rg -n -- "->writeStructuredAnswer\(|->writeEvidencePackageAnswer\(|evidence_package" api\agent\orchestrator test\agent_evidence_package_runtime_test.php docs\RELIABILITY.md docs\architecture\tekg_agent_development_guide.md docs\exec-plans\tech-debt-tracker.md
```

All syntax checks and PHP tests passed. The final `rg` check confirmed Agent runtime calls `writeEvidencePackageAnswer()` and does not call `writeStructuredAnswer()`.

### Residual Risks

- Live WAMP/Neo4j/LLM/browser paths were not run in this session.
- Some non-writing consumers still read legacy plugin fields for sufficiency, UI/debug payloads, or session memory.
- The current package text truncation is byte-based via PHP `strlen/substr`; this is acceptable for v1 but can be improved for Chinese multibyte text.
