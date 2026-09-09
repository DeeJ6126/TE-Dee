# Agent/DeepThink Boundary and Plugin Envelope Implementation Plan

> **For agentic workers:** REQUIRED COORDINATOR HARNESS: follow `docs/architecture/agent_deepthink_coordinator_harness_cn.md`. Use medium subagents only unless the user explicitly requests high. Worker silence must not exceed 12 minutes.

**Goal:** Make Deep Think the default full-site immediate QA path, reserve Agent for research tasks, and introduce a common `PluginResultEnvelope` so Agent/DeepThink can consume plugin evidence consistently.

**Architecture:** Add a lightweight task complexity classifier to the existing routing layer, surface research-task templates on the Agent page, and introduce a shared plugin result envelope adapter/schema without forcing a large rewrite of every plugin in one pass. Existing plugin payloads should continue to work through normalization adapters while tests enforce the new envelope shape.

**Tech Stack:** PHP orchestrator/services/plugins, browser JS/CSS for `agent.php`, JSON Schema-style PHP validation tests, existing TE-KG test scripts.

---

## Background

This plan implements the first two stages from `docs/architecture/tekg_agent_development_guide.md`:

- 9.1 Boundary hardening:
  - Deep Think remains the default immediate QA system.
  - Agent is reserved for research tasks.
  - Add `task_complexity`.
  - Simple tasks should recommend Deep Think.
  - Agent page should expose research templates: mechanism review, evidence audit, batch comparison, graph ranking, report generation.
- 9.2 Plugin contract unification:
  - Define `PluginResultEnvelope`.
  - Add `status`, `metrics`, `evidence_items`, and `errors`.
  - Add JSON Schema-style tests.

This is not the webpage Agent test harness. This is a Codex coordinator implementation plan for changing TE-KG runtime behavior safely.

## Non-Goals

- Do not rewrite Neo4j, graph APIs, taxonomy runtime, or expression runtime.
- Do not change database contents.
- Do not replace the current Agent async run pipeline.
- Do not remove Deep Think or Agent modes.
- Do not require every plugin to be internally rewritten in this pass; normalize outputs at the boundary first.
- Do not expose private local config values or secrets in docs, tests, or logs.

## File Scope

Confirmed files from medium Explorer reports:

- Modify: `api/agent/orchestrator/EntityNormalizer.php`
- Modify: `api/agent/orchestrator/DeepThinkService.php` only if service-level event/debug payloads need the new field.
- Modify: `api/agent/orchestrator/AcademicAgentService.php`
- Modify: `api/agent/orchestrator/traits/DeepThinkRoutingTrait.php`
- Modify: `api/agent/orchestrator/traits/DeepThinkEvidenceTrait.php` only for compatibility reads if needed.
- Modify: `api/agent/orchestrator/traits/AcademicAgentPlanningTrait.php`
- Modify: `api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php`
- Modify: `api/agent/orchestrator/traits/AcademicAgentEvidenceTrait.php` only if envelope accessors are needed around existing raw reads.
- Create: `api/agent/contracts/PluginResultEnvelope.php`
- Create: `api/agent/config/plugin_result_envelope_schema.php`
- Create: `test/task_complexity_test.php`
- Create: `test/plugin_result_envelope_test.php`
- Modify: `agent.php`
- Modify: `assets/js/pages/agent.js`
- Modify: `assets/css/pages/agent.css`
- Update docs after implementation:
  - `docs/RELIABILITY.md` if new checks become canonical.
  - `docs/exec-plans/tech-debt-tracker.md` if structural debt remains.

Do not modify:

- `api/graph.php`, Graph API service/runtime, G6 runtime, Neo4j configuration, taxonomy runtime, expression runtime.
- Plugin internals beyond trivial output compatibility if avoidable. First pass normalizes at the consumption boundary.

## Risks

- `task_complexity` may duplicate existing intent logic if inserted at the wrong layer.
- Agent may still execute simple tasks if frontend templates are added but backend routing is not enforced.
- A strict envelope could break existing plugins if applied directly rather than through a compatibility adapter.
- Deep Think and Agent may need different envelope fields; overgeneralizing too early would create another brittle abstraction.
- Tests could become schema-shape tests only and miss behavioral regressions.
- Agent page template UI could drift from existing design if styling is not kept minimal.
- Existing plugin status values include `error`; first-pass envelope must preserve compatibility and may expose normalized `status=failed` while retaining `legacy_status=error`.
- DeepThink deterministic answers and Agent sufficiency still read deep `results.*` payloads; removing raw payloads is explicitly out of scope.

## Stop Conditions

- Current runtime already has `complexity`; implementation must extend it into product-facing `task_complexity` instead of creating a disconnected classifier.
- Plugin outputs are too heterogeneous for a direct adapter without first mapping per-plugin semantics.
- Any required change would touch graph/taxonomy/expression runtime internals without explicit user approval.
- Tests reveal current plugin calls require live Neo4j/LLM for basic validation; then first pass should use fixture-only tests.

## Implementation Tasks

### Task 1: Read Explorer Reports and Finalize File Ownership

- [x] Wait for Explorer A, B, and C.
- [x] Update this plan with exact file targets and excluded areas.
- [x] Confirm whether implementation can proceed under this plan.

Explorer evidence:

- `EntityNormalizer::analyze()` currently creates `intent` and `complexity`.
- `DeepThinkRoutingTrait` performs Deep Think routing and hard-stop shortcuts.
- `AcademicAgentPlanningTrait` uses `agent_routing_policy.json` for Agent plugin queue planning.
- `agent.php`/`agent.js` currently lack clickable research-task templates.
- Plugin interface is weakly typed: `TekgAgentPluginInterface::getName()` and `run(array $context): array`.
- Plugin consumers parse heterogeneous arrays in `AcademicAgentService`, `DeepThinkRoutingTrait`, `AcademicAgentPluginResultTrait`, `DeepThinkEvidenceTrait`, and `AcademicAgentEvidenceTrait`.
- No completed Agent/DeepThink implementation plan exists yet; current guide is design direction.

### Task 2: Add Task Complexity Classification Test

**Intent:** Lock down the boundary before implementation.

Test cases:

- `L1HS的序列是什么` -> `simple_lookup`, recommend Deep Think.
- `L1HS位于哪里` -> `simple_lookup`, recommend Deep Think.
- `L1HS在哪些组织表达` -> `simple_lookup`, recommend Deep Think.
- `LINE-1是如何导致癌症的？` -> `research`, allow Agent.
- `What papers support LINE-1 and Alzheimer's disease?` -> `research`, allow Agent.
- `比较 L1HS、AluY、HERVK 与癌症的证据强度` -> `research`, allow Agent.
- `现在知识图谱里面，哪一个疾病与转座子关联度最大？` -> `research`, allow Agent.
- `为 L1HS 生成一份研究报告` -> `research`, allow Agent.

Expected implementation target:

- A shared classifier or existing normalizer method returns:

```php
[
    'task_complexity' => 'simple_lookup|single_hop|research_synthesis|mechanism_chain|ambiguous',
    'recommended_mode' => 'deepthink|agent',
    'reason' => '...',
]
```

### Task 3: Implement `task_complexity`

**Intent:** Keep DT as the default for immediate QA and make Agent self-aware about simple tasks.

Expected behavior:

- Deep Think can include `task_complexity` in analysis/debug payloads without changing its public API contract.
- Agent planning/understanding can detect simple lookup tasks and emit a clear recommendation to use Deep Think, without silently switching mode behind the user's back.
- Research intents remain Agent-eligible:
  - mechanism
  - literature/evidence support
  - evidence comparison
  - graph analytics/ranking/topology
  - batch comparison
  - report generation

Suggested rules:

- Simple lookup:
  - sequence
  - genome/location
  - expression
  - classification
  - site navigation
  - one-hop relationship list
- Research:
  - mechanism words: `机制`, `如何导致`, `why`, `how`, `mechanism`
  - literature words: `paper`, `papers`, `文献`, `证据`, `support`
  - comparison words: `比较`, `compare`, `对比`
  - analytics words: `最大`, `排名`, `top`, `rank`, `centrality`, `关联度`
  - report words: `报告`, `综述`, `review`, `dossier`

Compatibility:

- Keep existing `complexity` output for current code.
- Add `task_complexity`, `recommended_mode`, and `task_complexity_reason`.
- Do not add a second source of truth outside `EntityNormalizer`.

### Task 4: Add Agent Research Templates

**Intent:** Make the product boundary visible to users.

Templates:

- Mechanism review: `LINE-1是如何导致癌症的？`
- Evidence audit: `What papers support LINE-1 and Alzheimer's disease?`
- Batch comparison: `比较 L1HS、AluY、HERVK 与癌症的证据强度`
- Graph ranking: `现在知识图谱里面，哪一个疾病与转座子关联度最大？`
- Research report: `为 L1HS 生成一份包含序列、位置、表达、疾病和文献证据的研究报告`

Expected UI behavior:

- Templates fill the Agent input.
- Templates are visible as Agent research templates, not as another global mode selector.
- They do not add `brief/shallow/deep/research` style mode buttons.
- They do not appear as ordinary Deep Think quick prompts unless the existing UI already shares prompt chips.
- Styling follows existing `agent.css` patterns; no broad redesign.

### Task 5: Define `PluginResultEnvelope`

**Intent:** Add a stable contract without breaking legacy plugins.

Expected class/API:

```php
PluginResultEnvelope::fromPluginResult(
    string $pluginName,
    mixed $rawResult,
    array $context = []
): array
```

Minimum envelope:

```php
[
    'plugin' => 'Graph Plugin',
    'status' => 'ok|partial|empty|failed',
    'legacy_status' => 'ok|partial|empty|error|null',
    'intent' => 'relationship|mechanism|navigation|sequence|expression|genome|analytics|unknown',
    'summary' => '',
    'raw' => [],
    'evidence_items' => [],
    'citations' => [],
    'routes' => [],
    'metrics' => [
        'duration_ms' => null,
        'result_count' => 0,
        'confidence' => null,
    ],
    'errors' => [],
]
```

Compatibility rule:

- Keep `raw` initially so old synthesis code can still access legacy payloads.
- New Agent integration should prefer `evidence_items`, `citations`, `routes`, and `metrics`.
- Map legacy `error` to normalized `failed`, but preserve `legacy_status`.
- Use `tekg_agent_normalize_evidence_item()` for evidence item compatibility where available.

### Task 6: Add Schema-Style Validation Tests

**Intent:** Enforce common envelope fields across representative plugins.

Test coverage:

- Envelope from a successful graph-like result.
- Envelope from an empty result.
- Envelope from a failed result with error message.
- Envelope from Site Navigator route result.
- Envelope from literature/citation-like result.
- Legacy `status=error` maps to `status=failed` and preserves `legacy_status=error`.

Validation must check:

- Required keys exist.
- `status` is one of allowed values.
- `metrics.result_count` is numeric.
- `evidence_items`, `citations`, `routes`, `errors` are arrays.
- Existing raw payload is preserved.
- `legacy_status` is preserved when present.

### Task 7: Normalize Plugin Outputs at Agent/DT Consumption Boundary

**Intent:** Stop direct arbitrary array parsing from spreading further.

Expected approach:

- Wrap plugin results immediately after execution at the Agent/DT consumption boundary.
- Continue passing legacy raw data where old synthesis still needs it.
- Add helper accessors where the code currently repeatedly checks plugin-specific keys.

Do not:

- Rewrite all plugins internally unless a plugin has a trivial local return wrapper.
- Change database queries.
- Change graph/taxonomy/expression runtime code.

### Task 8: Run Verification

Minimum commands:

```powershell
php -l api\agent\contracts\PluginResultEnvelope.php
php -l api\agent\orchestrator\EntityNormalizer.php
php -l api\agent\orchestrator\DeepThinkService.php
php -l api\agent\orchestrator\AcademicAgentService.php
php test\task_complexity_test.php
php test\plugin_result_envelope_test.php
node --check assets\js\pages\agent.js
```

If any command depends on unavailable local services, document that clearly and use fixture-based tests instead.

### Task 9: Documentation and Plan Completion

- [x] Update `docs/RELIABILITY.md` if new task complexity/envelope tests become canonical reliability checks.
- [x] Update `docs/exec-plans/tech-debt-tracker.md` if old raw plugin parsing remains as known debt.
- [x] Update `docs/architecture/tekg_agent_development_guide.md` and/or `docs/architecture/current_system.md` if implementation makes the boundary/envelope live rather than aspirational.
- [x] Move this plan to `docs/exec-plans/completed/` after implementation.
- [x] Add actual modified files, verification results, residual risks, and follow-up recommendations.

## User Confirmation Required

This plan changes Agent/DeepThink runtime behavior and plugin result contracts. Per `docs/architecture/agent_deepthink_coordinator_harness_cn.md`, implementation should begin only after:

1. Explorer reports have been reviewed.
2. Exact file scope has been corrected.
3. User confirms entry into implementation.

---

## Completion Closeout - 2026-05-25

Status: completed first implementation pass for Stage 1 boundary hardening and Stage 2 plugin contract unification.

### Actual changed implementation files

- `api/agent/orchestrator/EntityNormalizer.php`: added product-facing `task_complexity`, `recommended_mode`, and `task_complexity_reason` on top of existing analysis output.
- `api/agent/orchestrator/traits/AcademicAgentNarrationTrait.php`: added user-facing Agent recommendation narration for simple tasks without exposing internal field names.
- `api/agent/contracts/PluginResultEnvelope.php`: added the shared compatibility envelope adapter for heterogeneous plugin results.
- `api/agent/config/plugin_result_envelope_schema.php`: added schema-style envelope contract definition.
- `api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php`: wraps Agent plugin results with `result_envelope` while retaining legacy raw payloads.
- `api/agent/orchestrator/traits/DeepThinkRoutingTrait.php`: wraps Deep Think plugin shortcut/routing results with `result_envelope` while retaining legacy raw payloads.
- `agent.php`: added Agent research template surface.
- `assets/js/pages/agent.js`: added template click/fill behavior.
- `assets/css/pages/agent.css`: added minimal styling for the research templates.
- `test/task_complexity_test.php`: added task complexity classifier coverage.
- `test/plugin_result_envelope_test.php`: added envelope schema/normalization coverage.
- `test/agent_narration_task_complexity_test.php`: added narration coverage for simple-task Agent recommendation.

### Final Verifier PASS commands

```powershell
php -l agent.php
php -l api\agent\orchestrator\EntityNormalizer.php
php -l api\agent\orchestrator\traits\AcademicAgentNarrationTrait.php
php -l api\agent\orchestrator\traits\AcademicAgentPluginResultTrait.php
php -l api\agent\orchestrator\traits\DeepThinkRoutingTrait.php
php -l api\agent\contracts\PluginResultEnvelope.php
php -l api\agent\config\plugin_result_envelope_schema.php
php test\task_complexity_test.php
php test\plugin_result_envelope_test.php
php test\agent_narration_task_complexity_test.php
node --check assets\js\pages\agent.js
```

### Residual risks

- Live WAMP, Neo4j, LLM, and browser runtime were not exercised in this pass.
- `PluginResultEnvelope` intentionally remains legacy-compatible; raw plugin payloads are still preserved.
- Not every consumer has migrated to envelope-first reads. Some synthesis and sufficiency paths still inspect legacy `results.*` payloads.
- `task_complexity` is an intentionally lightweight heuristic classifier, not a learned or benchmark-calibrated router.

### Follow-up technical debt

- Old consumers that still read legacy `results.*` should be migrated gradually during the future evidence package phase.
- Evidence package work should move Writing and sufficiency checks toward envelope/evidence-item inputs rather than raw plugin dumps.
