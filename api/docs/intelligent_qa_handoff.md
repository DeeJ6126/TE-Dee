# TE-Dee Intelligent QA Handoff

Last reviewed: 2026-07-30

Runtime audit note: 2026-07-28. The live frontend, endpoints, orchestrators,
model configuration, prompts, plugin registry, evidence contracts, tests, and
selected historical evaluations were re-audited. The follow-up plugin contract
hardening pass is now implemented and verified with the configured local LLM
relay. See `api/docs/intelligent_qa_runtime_audit_2026-07-28.md` for the starting
snapshot and `api/docs/intelligent_qa_plugin_contract_hardening_2026-07-28.md`
for the implemented behavior and current verification evidence.

Current plugin maintenance state: the shared native plugin contract remains unchanged;
both orchestrators enforce one native result contract; scientific evidence is
separated from diagnostics; Literature Reading fallback is explicit; Agent and
DeepThink share bounded UI/LLM projections; and Agent uses selective execution
review. Future plugin work should preserve these contracts rather than adding a
second result shape.

Multi-turn context note: 2026-07-30. Agent and DeepThink now share a bounded
context resolver before entity normalization and routing. It retains at most
three successful turns, validates retained and explicit TE entities, and gives
the existing workflow a standalone effective question while the response and
browser preserve the user's original wording. Ambiguous or context-free
references return clarification with zero scientific plugin calls. The browser
stores the session ID only in the live page's JavaScript memory; reloads and new
tabs do not restore an earlier conversation. Existing backend session-cache
retention is unchanged.

This document is the current handoff for the TE-Dee intelligent QA subsystem. It
covers DeepThink, Agent, plugin routing, LLM nodes, frontend event handling, and
maintenance risks.

## What This Subsystem Does

The intelligent QA subsystem answers TE-Dee questions through two user-facing
modes on `agent.php`:

- DeepThink: a lighter assistant for direct, plugin-grounded answers.
- Agent: a heavier research assistant for multi-step evidence collection,
  integration, and report-style answers.

Both modes use local TE-Dee evidence tools. Neither mode should invent graph
facts, PMIDs, URLs, expression results, loci, or sequence details.

## Current Runtime Shape

- DeepThink endpoint: `api/deep_think_stream.php`.
- Agent async run create: `api/agent_runs.php`.
- Agent async run status: `api/agent_run_status.php`.
- Agent execution path: `api/agent_run_execute.php` and worker/kickoff helpers.
- Main backend orchestrators:
  - `api/agent/orchestrator/DeepThinkService.php`
  - `api/agent/orchestrator/AcademicAgentService.php`
  - `api/agent/orchestrator/LlmClient.php`
  - `api/agent/orchestrator/EntityNormalizer.php`
- Main frontend: `assets/js/pages/agent.js`.

The old direct `api/agent_stream.php` still exists, but the current frontend path
uses async run creation and polling for Agent.

## DeepThink

DeepThink is a four-stage assistant:

```text
Understanding -> Planning -> Executing -> Writing
```

Current important behavior:

- The model for DeepThink stages is resolved in the service and currently uses
  `deepseek-v4-flash`.
- Planning selects only validated business plugins.
- Executing may call each planned business plugin at most once.
- Citation Resolver is post-processing and may run outside the business-plugin
  set.
- Literature Reading requires usable Literature Plugin results.
- Writing is the final answer source. If Writing fails, the failure must be
  exposed instead of hidden behind a rule-composed answer.

## Agent

Agent is a six-stage research assistant:

```text
Understanding -> Planning -> Collecting -> Executing -> Integrating -> Writing
```

Current important behavior:

- Agent uses deterministic normalization and planning helpers plus LLM node
  artifacts.
- Collecting decides whether more evidence is needed.
- Executing runs plugins and can review plugin output with an ExecutingReview
  LLM artifact.
- Integrating builds structured evidence materials such as evidence package,
  evidence walk, report plan, answer structure, and claim mapping.
- Writing runs a writing decision, draft generation, optional polishing, and
  integrity checks.

Agent is more expressive than DeepThink but slower and more failure-prone,
especially near Writing.

## Models And Config

Do not rely on old handoff text for model truth. Inspect the live local config
before debugging model behavior:

- `api/config.local.php`
- `api/agent/bootstrap.php`
- `api/agent/orchestrator/DeepThinkService.php`
- `api/agent/orchestrator/AcademicAgentService.php`

At the latest review, local config set Agent core/expert/narrator/writing style
models to `deepseek-v4-pro`, while DeepThink still used
`deepseek-v4-flash`. Timeouts were also much larger than older documents stated.

## Plugin System

Plugins are registered in `api/agent/plugin_registry.php`. The planner-facing
directory is `api/agent/plugins/PLUGIN_CATALOG.md`; maintainer notes are in
`api/agent/plugins/README.md`.

Registered plugins include:

- Entity Resolver
- Site Navigator Plugin
- Graph Plugin
- Graph Analytics Plugin
- Cypher Explorer Plugin
- Literature Plugin
- Literature Reading Plugin
- Tree Plugin
- Expression Plugin
- eQTL Plugin
- Genome Plugin
- Sequence Plugin
- Citation Resolver

Plugin results are bounded evidence. The writing layers must not turn retrieval
metadata into stronger claims than the evidence supports.

## Frontend Event Flow

The frontend receives streamed or polled events and renders thinking/status
state. Important event families include:

- `stage_state`
- `node_llm_result`
- `node_llm_error`
- `tool_selected`
- `tool_result`
- `reflection`
- `synthesizing`
- `answer`
- `error`
- `done`

The workflow UI should be driven by backend events, not by fake frontend
progress. Status labels such as `Understanding`, `Planning`, and `Writing`
remain English UI labels; generated thinking content follows the request
language.

The page does not read or write the conversation session ID through
`localStorage`. It reuses the server-returned ID only while the current page
instance remains open. DeepThink's shared progress renderer treats a successful
terminal state as authoritative and marks all four stages complete, preventing
the Writing indicator from continuing to spin after an answer is shown.

## Conversation Context

- `ConversationMemory` bounds history to the last three successful turns and
  limits stored text and entity fields.
- `ConversationContextResolver` runs once before normal normalization and
  routing in both orchestrators.
- Plugins, routing policy, evidence contracts, four-stage DeepThink, and
  six-stage Agent remain unchanged by the context feature.
- Failed runs and clarification-only responses are not appended as successful
  turns.
- Previous answer text is untrusted conversational context, not scientific
  evidence. The resolver may use it only to disambiguate the current request.
- A reload or new tab cannot recover the prior page's session ID, but this does
  not imply immediate deletion of server-side cache files.

## Known Risks

- Agent Writing is the main latency and failure risk because it consumes large
  evidence structures and may run draft, polish, and integrity checks.
- ExecutingReview improves traceability but adds LLM failure points.
- Agent may still over-handle simple lookup or navigation tasks if compact
  preflight is disabled.
- Literature retrieval can produce weak or irrelevant matches.
- Graph relationships describe local associations and relation labels, not
  automatic biological causality.
- Expression, genome, and sequence plugins have strict evidence boundaries that
  must be preserved in final answers.
- Agent and DeepThink final Writing now receive a user-facing evidence
  projection rather than raw plugin/evidence structures. Do not remove this
  boundary or expose raw flags, IDs, plugin names, or evidence-accounting terms
  in final prose.
- `SequencePlugin::structureHints()` still has a separate upstream data issue:
  substring matching can read `Non-LTR` as an `LTR` hint. The writing projection
  suppresses unrequested structure hints, but the plugin parser itself remains
  a future scoped fix.

## Recommended Next Maintenance Tasks

1. Keep `api/README.md` and `api/docs/` current after every Agent/DeepThink
   change.
2. Fix and test the `Non-LTR` structure-hint substring match in SequencePlugin.
3. Use the 36-question evaluation findings to address scoped answer-quality
   failures, especially missing requested evidence, long path-query failures,
   literature precision, and remaining internal writing vocabulary.
4. Keep plugin catalog wording accurate enough for LLM planning.
5. Treat old `docs/architecture/*agent*` markdown as historical background, not
   runtime truth.
