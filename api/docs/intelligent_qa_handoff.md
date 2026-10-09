# TE-Dee Intelligent QA Handoff

Last reviewed: 2026-10-09

Closeout update: complete cross-turn dialogue now reaches the research and final
writing model. `ConversationMemory::historyView` retains up to 12 whole successful
turns and 1 MiB of original text (512 KiB per pair). The prior three-turn summary
only supports entity resolution. Full dialogue lives in private `.conversations`
files, not the old summary files. Both page modes and the floating window show
context eviction/omission notices and keep their session ID in page memory.
`ConversationMaterials` permits explicit supplied-text tasks while preserving
fresh-source requirements for new scientific queries. Source identifiers retained
for editing are context references, not verified scientific findings.

`QaCacheRetention` performs daily request-triggered cleanup of seven-day-old
native records, completed async results, sessions, private conversations and
dated diagnostics. Active native records and pending/running jobs are preserved;
PubMed source cache, evaluation output and maintenance snapshots are excluded.
See `scripts/checks/prune_qa_cache.php` for a safe preview/apply CLI.

Runtime audit note: 2026-07-28. The live frontend, endpoints, orchestrators,
model configuration, prompts, plugin registry, evidence contracts, tests, and
selected historical evaluations were re-audited. The follow-up plugin contract
hardening pass is now implemented and verified with the configured local LLM
relay. See `api/docs/intelligent_qa_runtime_audit_2026-07-28.md` for the starting
snapshot and `api/docs/intelligent_qa_plugin_contract_hardening_2026-07-28.md`
for the implemented behavior and current verification evidence.

Current plugin maintenance state: both modes use the shared native tool loop and
validated plugin contract. Scientific evidence is separated from diagnostics;
the model can read original material and revisit scopes within its budget.
The old separate ExecutingReview/Literature Reading model chain is retired from
the active runtime. Future plugin work should preserve the shared contracts.

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
  `deepseek-flash`.
- Planning uses native function schemas with explicit arguments.
- Executing can revisit a tool with a different validated scope, focus or page; exact queries are deduplicated.
- Citation merging remains post-processing, without a separate model call.
- The ongoing model reads original abstracts through read_evidence; a separate Literature Reading model is not used by the native runtime.
- Writing is the final answer source. If Writing fails, the failure must be
  exposed instead of hidden behind a rule-composed answer.

## Agent

Agent is a six-stage research assistant:

```text
Understanding -> Planning -> Collecting -> Executing -> Integrating -> Writing
```

Current important behavior:

- Agent and DeepThink share `QaResearchLoop`, `QaToolRegistry`, and `QaEvidenceStore`.
- The model continues one messages history through native tool calls. Reasoning
  protocol content is retained privately, never displayed or treated as evidence.
- Tools have explicit validated parameters and can be called again to inspect
  different scopes, query focus or pages. Repeated identical successful queries
  reuse their original evidence, and retrieval failures may be retried within bounds.
- The four/six stage names describe real normalization, decision, execution,
  evidence integration and final writing operations. They do not each require
  separate LLM JSON nodes or fabricated progress.
- Final writing uses the same task history. Citation/presentation checks are
  preserved, one real model repair is allowed, and requested FASTA is appended
  from verified original records. No deterministic scientific answer replaces
  a failed writer.
- Native private transcripts/original records live under denied `.native_runs`;
  evidence IDs can only read records from the current run instance.
- Agent defaults to 12 actions and 480 seconds; DeepThink to 6 and 240. Research
  model and HTTP operations have a cutoff that reserves final-writing time.
- Existing async Agent endpoints and DeepThink SSE, open-page conversation scope,
  and bounded antecedent resolution remain. A new question refetches scientific
  evidence; old answer text is not evidence.

## Models And Config

Do not rely on old handoff text for model truth. Inspect the live local config
before debugging model behavior:

- `api/config.local.php`
- `api/agent/bootstrap.php`
- `api/agent/orchestrator/DeepThinkService.php`
- `api/agent/orchestrator/AcademicAgentService.php`

Current model policy (2026-10-08): `api/agent/contracts/AgentModelPolicy.php`
provides one `agent_model` for every QA stage and model-assisted plugin. Its
default is `deepseek-flash`; old Flash aliases normalize to the current API
name. Legacy per-stage config and incoming model parameters cannot switch one
stage to Pro. Existing server credentials remain private and are unchanged.
The native tool loop now drives both modes. The four/six stage UI is retained,
while the old independent control/review/writing node chain is retired.

PubMed EFetch now overrides the shared JSON parameter with XML, retains complete
abstract text, and versions its cache to exclude old empty-abstract entries.
Multi-TE queries cover each TE/disease pair with bounded retrieval. Duplicate
citations preserve complete material through collection, normalization,
evidence packaging, and writing. Reading derives its supported claims from
citation-validated clusters and does not treat titles as biological evidence.

Taxonomy results carry explicit ranks and missing-rank fields. L1HS has recorded
family L1PA; no subfamily field is stored. Global disease rankings use distinct
linked TE counts and state that connectivity is not biological association
strength. Undefined graph scoring fields cannot become a synthetic score.

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
- Sequence metadata hints remain keyword-derived. The Non-LTR substring parser issue
  was fixed in the native-loop pass; hints are not verified structural annotation.

## Recommended Next Maintenance Tasks

1. Keep `api/README.md` and `api/docs/` current after every Agent/DeepThink
   change.
2. Keep keyword metadata distinct from verified sequence annotation and biological taxonomy.
3. Use the 36-question evaluation findings to address scoped answer-quality
   failures, especially missing requested evidence, long path-query failures,
   literature precision, and remaining internal writing vocabulary.
4. Keep plugin catalog wording accurate enough for LLM planning.
5. Treat old `docs/architecture/*agent*` markdown as historical background, not
   runtime truth.
