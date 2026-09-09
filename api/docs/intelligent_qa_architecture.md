# Intelligent QA Architecture

This document explains the runtime architecture of TE-Dee intelligent QA.

## User-Facing Flow

The user interacts with `agent.php`. The page exposes two modes:

- DeepThink for direct, fast, evidence-bounded answers.
- Agent for heavier research-style answers.

The frontend implementation is primarily `assets/js/pages/agent.js`. It reads
API URLs emitted by `agent.php`, sends user questions, and renders thinking,
workflow, plugin, answer, error, and done states.

## DeepThink Data Flow

```text
agent.php
-> assets/js/pages/agent.js
-> api/deep_think_stream.php
-> TekgDeepThinkService
-> LlmClient node calls
-> plugin registry and selected plugins
-> Writing artifact
-> SSE events back to frontend
```

DeepThink stages:

1. Understanding: interprets language, intent, entities, evidence needs, and
   warnings.
2. Planning: selects a bounded plugin plan from available business plugins.
3. Executing: decides whether to stop or run the next remaining planned plugin.
4. Writing: produces the final answer from artifacts and plugin evidence.

DeepThink should expose stage failures clearly. Writing failure must not be
covered by deterministic answer assembly.

## Agent Data Flow

```text
agent.php
-> assets/js/pages/agent.js
-> api/agent_runs.php
-> run store
-> api/agent_run_execute.php / worker path
-> TekgAcademicAgentService
-> plugin registry and evidence contracts
-> api/agent_run_status.php polling
-> frontend workflow and answer rendering
```

Visible Agent stages:

1. Understanding
2. Planning
3. Collecting
4. Executing
5. Integrating
6. Writing

The six-stage UI is a runtime workflow contract. Do not replace it with the
larger workflow-lab graph unless there is a dedicated product decision.

## Backend Components

- `DeepThinkService.php`: four-stage DeepThink orchestration.
- `AcademicAgentService.php`: six-stage Agent orchestration.
- `LlmClient.php`: model calls for JSON node artifacts, narration, writing, and
  polishing.
- `EntityNormalizer.php`: deterministic entity and intent analysis used before
  evidence lookup.
- `CitationResolver.php`: citation normalization support.
- `AcademicAgentWorkflowTrait.php`: visible workflow state transitions.
- `AcademicAgentPluginResultTrait.php`: plugin result access and shaping.
- `DeepThinkRoutingTrait.php` and `DeepThinkEvidenceTrait.php`: DeepThink
  routing and evidence helpers.

## Evidence Structures

Agent uses structured contracts for evidence and writing:

- `PluginResultEnvelope`
- `EvidencePackage`
- `EvidenceWalk`
- `ReportPlan`
- `ReportIntegrityGate`
- `NodeLlmResult`

These structures exist to prevent unsupported writing. When changing the
pipeline, preserve the separation between retrieved evidence, interpreted
claims, and final prose.

## Frontend State Rules

- Do not show workflow progress before backend stage events arrive.
- Do not duplicate errors after an `error` event and final `done`.
- Keep status-bar labels stable; translate generated thinking content according
  to request language.
- Preserve plugin details so maintainers can inspect evidence payloads.
- Graph Plugin payloads may drive graph popups, but those UI actions must remain
  separate from scientific claim support.
