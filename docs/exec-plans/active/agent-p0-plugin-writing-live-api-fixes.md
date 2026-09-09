# Agent P0 Plugin/Writing/Live-API Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development or equivalent harness workflow. Steps use checkbox (`- [ ]`) syntax for tracking. Do not run live API until the static/unit repair tasks pass.

**Goal:** Fix the current P0 Agent defects that can mislead plugin metrics, disconnect writing decisions from final answers, or misrepresent failed Agent workflows.

**Architecture:** Keep the repair narrow. Normalize Site Navigator output at the plugin boundary, pass `claim_evidence_map` and `writing_decision` into final draft/polish prompts, and make Agent workflow failure states visible instead of being overwritten by done handling. Verification must include unit/static tests and targeted live API runs that inspect both frontend-visible thinking process and final answer.

**Tech Stack:** PHP Agent orchestrator/plugins/contracts, browser JS Agent UI, existing PHP tests, existing Python live eval runner against `api/agent_runs.php` and `api/agent_run_status.php`.

---

## Context

Explorer findings:

- `SiteNavigatorPlugin` currently stores `primary_confidence_percent` inside `result_counts`; `PluginResultEnvelope::inferResultCount()` chooses the maximum numeric result count and can report confidence percent as result count.
- `claim_evidence_map.v1` and `writing_decision.v1` are generated and recorded, but final `writeEvidenceWalkDraft()` / `polishEvidenceWalkAnswer()` prompts do not receive them directly.
- Failure handling can report backend `failed` while frontend workflow state handling expects `error`/done flows and may show failed workflows as pending or completed.

Existing dirty worktree changes from the previous task must be preserved:

- `api/agent/bootstrap/evidence_support.php`
- `api/agent/contracts/PluginResultEnvelope.php`
- `api/agent/plugins/CitationResolverPlugin.php`
- `api/agent/plugins/ExpressionPlugin.php`
- `api/agent/plugins/GenomePlugin.php`
- `api/agent/plugins/TreePlugin.php`
- `api/agent/plugins/README.md`
- `test/plugin_result_envelope_test.php`

## Task 1: Site Navigator Output Contract

**Owner:** Worker A

**Files:**

- Modify: `api/agent/plugins/SiteNavigatorPlugin.php`
- Modify: `test/site_navigator_plugin_test.php`
- Modify only if needed: `test/plugin_result_envelope_test.php`

- [ ] Add a failing test using real `TekgAgentSiteNavigatorPlugin::run()` output, then wrap it with `PluginResultEnvelope::fromPluginResult()`.

Expected assertions:

```php
assert_same(count($result['results']['candidate_routes']), $envelope['metrics']['result_count'], 'site navigator result_count must count routes');
assert_true(!array_key_exists('primary_confidence_percent', $result['result_counts']), 'confidence percent must not be a result count');
assert_true(is_float($result['confidence']) || is_int($result['confidence']), 'top-level confidence is exposed');
assert_same((float)$result['confidence'], $envelope['metrics']['confidence'], 'envelope confidence comes from top-level confidence');
```

- [ ] Add an empty-result contract test.

Expected assertions:

```php
assert_same('empty', $result['status'], 'empty site navigation status');
assert_same(0, $result['result_counts']['routes'], 'empty site navigation route count');
assert_same([], $result['results']['candidate_routes'], 'empty site navigation candidates');
assert_true(is_array($result['display_details']['preview_items']), 'empty display preview_items array');
assert_true(is_array($result['evidence_items']), 'empty evidence_items array');
assert_true(is_array($result['citations']), 'empty citations array');
assert_true(is_array($result['errors']), 'empty errors array');
```

- [ ] Implement the minimal plugin fix.

Required behavior:

- `result_counts` contains counts only, such as `routes`.
- `confidence` is top-level and numeric.
- Success and empty return branches both include `query_summary`, `results`, `display_label`, `display_summary`, `display_details`, `result_counts`, `evidence_items`, `citations`, `errors`, and `latency_ms`.
- Empty branch must not fabricate biological evidence.

- [ ] Run:

```powershell
php -l api/agent/plugins/SiteNavigatorPlugin.php
php test/site_navigator_plugin_test.php
php test/plugin_result_envelope_test.php
```

## Task 2: Final Writer Uses Claim Map and Writing Decision

**Owner:** Worker B

**Files:**

- Modify: `api/agent/orchestrator/AcademicAgentService.php`
- Modify: `api/agent/orchestrator/LlmClient.php`
- Modify if prompt text is centralized there: `api/agent/config/agent_prompts.php`
- Modify: `test/agent_research_report_prompt_test.php`
- Modify: `test/agent_six_stage_runtime_test.php`
- Modify if needed: `test/agent_evidence_walk_runtime_test.php`

- [ ] Add failing prompt tests that reflection-build the draft and polish prompt payloads with sentinel values.

Required sentinel data:

```php
$claimEvidenceMap = [
    'schema_version' => 'claim_evidence_map.v1',
    'unsupported_claims' => ['Do not claim causality without evidence'],
    'limitations' => ['Expression evidence is missing'],
    'evidence_links' => [['claim_id' => 'claim_1', 'evidence_ids' => ['evidence_1']]],
];
$writingDecision = [
    'schema_version' => 'writing_decision.v1',
    'forbidden_claims' => ['Do not claim causality without evidence'],
    'citation_requirements' => ['Every major claim needs a linked evidence item'],
    'final_checks' => ['Apply forbidden_claims before final answer'],
];
```

Expected assertions:

```php
assert_true(str_contains($prompt, '"claim_evidence_map"'), 'draft/polish prompt includes claim_evidence_map');
assert_true(str_contains($prompt, '"writing_decision"'), 'draft/polish prompt includes writing_decision');
assert_true(str_contains($prompt, 'Do not claim causality without evidence'), 'forbidden claim sentinel is visible');
assert_true(str_contains($prompt, 'citation_requirements'), 'citation requirements are visible');
assert_true(str_contains($prompt, 'final_checks'), 'final checks are visible');
```

- [ ] Add runtime/static tests confirming service calls pass `$claimEvidenceMap` and `$writingDecision` into both writer calls.

Expected source-level assertions can check for:

```php
'$claimEvidenceMap = (array)$integratingNode->parsed_json'
'$writingDecision = (array)$writingDecisionNode->parsed_json'
'->writeEvidenceWalkDraft(' with `$claimEvidenceMap` and `$writingDecision`
'->polishEvidenceWalkAnswer(' with `$claimEvidenceMap` and `$writingDecision`
```

- [ ] Implement the minimum signature and payload changes.

Required behavior:

- `AcademicAgentService` stores:
  - `$claimEvidenceMap = (array)$integratingNode->parsed_json;`
  - `$writingDecision = (array)$writingDecisionNode->parsed_json;`
- `writeEvidenceWalkDraft()` and `polishEvidenceWalkAnswer()` accept and include both arrays.
- Draft prompt explicitly states that `writing_decision.forbidden_claims`, `citation_requirements`, and `final_checks` are mandatory constraints.
- Polish prompt explicitly states that it must enforce `writing_decision.final_checks` and must not add new unsupported evidence.
- Debug/node payload for the answer writer exposes `claim_evidence_map` and `writing_decision` if the current code has a dedicated payload builder.

- [ ] Run:

```powershell
php -l api/agent/orchestrator/AcademicAgentService.php
php -l api/agent/orchestrator/LlmClient.php
php test/agent_research_report_prompt_test.php
php test/agent_six_stage_runtime_test.php
php test/agent_evidence_walk_runtime_test.php
php test/agent_evaluation_report_runtime_test.php
```

## Task 3: Workflow Failure State Visibility

**Owner:** Worker C

**Files:**

- Modify: `assets/js/pages/agent.js`
- Modify only if needed: `api/agent/orchestrator/AcademicAgentService.php`
- Modify/add frontend static check: `scripts/checks/check_agent_llm_event_frontend_contract.js` or existing Agent workflow test

- [ ] Add a failing static check for failed workflow handling.

Required assertions:

```js
// Frontend accepts both "error" and "failed" as failed stage states.
// done handling must not call completeWorkflowForDone(turn) when the run payload indicates writing_failed or status failed.
```

- [ ] Implement the minimum frontend/backend compatibility change.

Required behavior:

- Frontend stage status normalization treats `failed` as `error`.
- Polling/result done handling does not force all workflow stages to done when run status is `failed` or payload indicates `writing_failed`.
- If backend code already emits `failed`, prefer frontend compatibility over changing backend storage semantics.

- [ ] Run:

```powershell
node --check assets/js/pages/agent.js
node scripts/checks/check_agent_llm_event_frontend_contract.js
```

## Task 4: Reviewer Pass

**Owner:** Reviewer

**Files:** read all files changed by Worker A/B/C.

- [ ] Review for scope control: no Neo4j/runtime/taxonomy/expression runtime changes.
- [ ] Review for regression risk in existing dirty plugin evidence changes.
- [ ] Review whether tests actually cover the three P0 findings.
- [ ] Produce a finding-first review. If issues exist, assign a Worker fix loop.

## Task 5: Static/Unit Verification

**Owner:** Verifier

Run after Reviewer approves:

```powershell
php test/plugin_result_envelope_test.php
php test/site_navigator_plugin_test.php
php test/agent_research_report_prompt_test.php
php test/agent_six_stage_runtime_test.php
php test/agent_evidence_walk_runtime_test.php
php test/agent_evaluation_report_runtime_test.php
node --check assets/js/pages/agent.js
node scripts/checks/check_agent_llm_event_frontend_contract.js
```

## Task 6: Live API Verification

**Owner:** Verifier

Live API verification is mandatory for this and future Agent/DT quality validations.

Use the current webpage backend path:

- Agent: `api/agent_runs.php` + `api/agent_run_status.php`
- DT only when comparing: `api/deep_think_stream.php`

Targeted Agent questions:

1. Site navigation metric/count:
   - `Where can I open the L1HS sequence panel in TE-KG?`
   - Inspect thinking/tool output for Site Navigator route count and absence of inflated confidence-as-count behavior.

2. Research report writing constraints:
   - `Generate a research report for L1HS including sequence, genomic location, expression, disease links, and literature evidence.`
   - Inspect thinking for claim-evidence map and writing decision stages.
   - Inspect final answer for explicit gaps, citation discipline, and no unsupported upgrade of missing expression data.

3. Empty/diagnostic evidence handling:
   - `Generate a research report for a nonexistent TE named FAKE_TE_DOES_NOT_EXIST_12345 including sequence, expression, disease links, and literature.`
   - Inspect thinking and final answer to ensure empty/system diagnostics are presented as limitations, not biological claims.

Suggested command pattern:

```powershell
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_001 --out-dir docs\eval\runs\agent_p0_live_P5A_B_001 --timeout 1800 --poll-interval 2
```

If the golden runner does not support the exact targeted free-form prompts, create a small one-off script under `scripts/eval/` or use the existing Agent run endpoints directly. Archive raw status events, frontend-visible thinking stages, plugin/tool outputs, final answer, duration, and pass/fail notes under `docs/eval/runs/agent_p0_*`.

## Done Criteria

- Site Navigator no longer reports confidence percent as result count.
- Final draft/polish prompts include `claim_evidence_map` and `writing_decision`.
- Workflow failed state is preserved in UI state logic.
- Reviewer approves or all findings are fixed.
- Unit/static verification passes.
- Live API verification archives thinking process and final answer for targeted questions.
- Any unresolved risk is recorded in the final report and, if structural, in `docs/exec-plans/tech-debt-tracker.md`.
