# Intelligent QA Runtime Audit (2026-07-28)

This audit records the current Agent and DeepThink implementation after reading
the live PHP, browser JavaScript, plugin registry, evidence contracts, prompts,
tests, and selected historical evaluation records. No runtime code was changed
during the audit.

## Scope And Evidence

The audit covered:

- `agent.php` and `assets/js/pages/agent.js`
- `api/deep_think_stream.php`
- `api/agent_runs.php`, `api/agent_run_status.php`, and the Agent worker path
- `api/agent/orchestrator/DeepThinkService.php`
- `api/agent/orchestrator/AcademicAgentService.php` and its traits
- `api/agent/orchestrator/LlmClient.php`
- the plugin registry, all registered plugin implementations, and plugin docs
- evidence, report, integrity, and LLM node contracts
- local model and timeout configuration, without recording credentials
- current contract tests and selected records under `docs/eval/runs/`

Historical Markdown and old evaluation artifacts were treated as background.
Claims below are based on the current code unless explicitly labeled as a
historical observation.

## Verified User-Facing Runtime

`agent.php` currently opens in DeepThink mode and exposes a mode picker for
DeepThink and Agent. Each mode has five example prompts. The page also contains
a plugin inspector and a movable Knowledge Graph popup for Graph Plugin output.

The page rendered successfully at `http://127.0.0.1/TE-/agent.php` during this
audit. Both mode states rendered without browser console warnings or errors from
the application. The page loads Marked and G6 from public CDNs, so those two UI
features retain an external-network dependency.

The frontend locks the selected mode after the first submitted question. It
uses:

- direct SSE streaming for DeepThink;
- asynchronous run creation plus polling for Agent;
- backend events, rather than a synthetic timer, to advance visible stages;
- a shared event renderer for tool details, citations, errors, answers, and
  terminal state.

## DeepThink Runtime

DeepThink is a strict four-stage LLM workflow:

```text
Understanding -> Planning -> Executing -> Writing
```

Current verified behavior:

- The runtime model is hard-coded by `DeepThinkService::resolveModel()` to
  `deepseek-v4-flash`; the model value posted by the frontend does not override
  it.
- Understanding and Planning must produce valid schema-bound LLM artifacts.
- Entity Resolver always runs as bootstrap after planning.
- The planner may select only registered business plugins, without duplicates.
- Each planned business plugin can run at most once.
- Literature Plugin and Literature Reading Plugin require an explicit
  literature request.
- Literature Reading additionally requires usable Literature Plugin citations.
- Citation Resolver is optional post-processing and does not consume a business
  plugin slot.
- Writing `answer_markdown` is the only final answer source. Empty or invalid
  Writing output terminates the run as a visible failure.
- Plugin `empty` and `partial` states may still proceed; plugin `error` stops the
  DeepThink run.

DeepThink therefore favors a smaller state machine and clearer failure
semantics, but it still makes an LLM call for every stage and for every
Executing decision round.

## Agent Runtime

Agent uses the six-stage product workflow:

```text
Understanding -> Planning -> Collecting -> Executing -> Integrating -> Writing
```

The request path is:

```text
agent.php
-> POST api/agent_runs.php
-> filesystem run state and payload
-> loopback kickoff or background PHP worker
-> TekgAcademicAgentService
-> append-only event JSONL
-> GET api/agent_run_status.php polling
-> assets/js/pages/agent.js
```

Current verified behavior:

- Deterministic entity normalization and initial planning run before the six
  LLM stages.
- The compact preflight implementation still exists, but
  `shouldUseCompactPreflightGate()` currently always returns `false`. Simple
  questions therefore enter the full Agent workflow.
- Understanding and Planning have conservative schema-valid deterministic
  artifact fallbacks if their control-model calls fail.
- Collecting failure is terminal.
- Each business plugin runs at most once.
- An ExecutingReview LLM artifact is requested after every business plugin.
  Its failure can be nonfatal when the deterministic plugin result remains
  usable; the result is then marked with an explicit review caveat.
- Integration builds EvidencePackage, EvidenceWalk, ReportPlan, a claim/evidence
  map, and integrity material before final writing.
- Site-navigation questions have a deliberate direct-writing exception: the
  validated Site Navigator answer can be returned without model-generated
  prose. It remains navigation output, not scientific evidence.
- Other final answers require a validated model draft. A failed or empty draft
  is exposed as Writing failure rather than replaced with a rule-composed
  scientific answer.
- The optional polisher is disabled by default. When disabled, a validated draft
  becomes the final answer. If enabled and the polished answer fails integrity,
  the validated draft is retained.

## Effective Model And Timeout Configuration

The current local configuration resolves to:

| Role | Effective model |
|---|---|
| DeepThink all stages | `deepseek-v4-flash` |
| Agent control | `deepseek-v4-flash` default |
| Agent core | `deepseek-v4-pro` |
| Agent sufficiency/Collecting | `deepseek-v4-pro` |
| Agent expert/ExecutingReview | `deepseek-v4-pro` |
| Agent narrator | `deepseek-v4-pro` |
| Agent answer structure | `deepseek-v4-pro` |
| Agent writing | `deepseek-v4-pro` |
| Agent polisher | `deepseek-v4-pro`, disabled by default |

The configured Agent execution timeout is 7200 seconds. JSON control calls use
120 seconds, while six-stage nodes and answer calls can use up to 1200 seconds.
These values explain why a failed or slow Agent run can remain active for a long
time.

The `defaultModel` emitted by `agent.php` is posted by the frontend as `model`,
but the current Agent role resolvers read role-specific payload keys and local
configuration. It is not the effective selector for Agent stages. DeepThink
also ignores it because its model is fixed in the service.

## Plugin Data Sources And Boundaries

| Plugin | Current source | Important boundary |
|---|---|---|
| Entity Resolver | deterministic normalization and alias chains | routing metadata, not scientific evidence |
| Site Navigator | `site_navigation_map.php` and path helpers | navigation only |
| Graph | Neo4j `tekg3` | local association and relation semantics, not automatic causality |
| Graph Analytics | fixed Neo4j aggregation templates | graph-content metrics, not biological importance |
| Cypher Explorer | LLM/heuristic Cypher plus read-only validation | generated-query scope and schema assumptions |
| Literature | graph citations plus NCBI E-utilities and cache | candidate citation metadata/abstract material |
| Literature Reading | Literature Plugin citations plus LLM synthesis | limited to supplied citation material |
| Tree | canonical taxonomy library/API plus disease class map | classification only |
| Expression | shared expression detail runtime and MySQL | runtime failure is not biological absence |
| Genome | local JBrowse TE-hit JSON bundles | representative/sample loci are not exhaustive |
| Sequence | `data/processed/te_repbase_db_matched.json` | record facts and keyword-derived hints only |
| Citation Resolver | upstream plugin citations | normalization, not claim-support verification |

All default plugins return the common envelope shape and feed structured
evidence into later stages. Evidence items distinguish `high`, `medium`, `low`,
and `none` support and may carry diagnostic or quality flags. The writing layer
must continue to keep retrieval results, interpreted claims, and final prose as
separate layers.

## Run Storage And Operational State

Agent run payloads, state, and events are stored under
`data/cache/agent/runs/<run_id>/` as JSON and append-only JSONL. Polling uses an
`after` sequence number to fetch only new events.

Stale-state detection is lazy: it runs when a run is polled. No automatic run
retention or cache cleanup path was found in the current run-store code. At the
time of this audit, the cache contained 114 completed runs, 71 failed runs, two
old pending runs, one old running run, and seven legacy entries without a
current status value. The pending/running entries date from April-May 2026 and
are historical residue, not evidence of active workers.

The page and API endpoints were reachable through local WAMP. Neo4j `tekg3`
was reachable with 225 TE nodes and 24,748 `BIO_RELATION` relationships. The
configured relay at `127.0.0.1:18087` was not listening during this audit, so a
new live LLM-backed Agent or DeepThink answer was intentionally not started.

## Verification Baseline

Passed during this audit:

- PHP syntax for all 47 files under `api/agent/`
- 23 Agent/DeepThink PHP contract and runtime tests
- 3 frontend event/state contract checks
- 8 Python semantic/evaluation tests
- API contract checks for health, graph, taxonomy, and co-expression
- Neo4j `tekg3` connectivity and representative entity checks
- intelligent QA documentation freshness check
- local browser rendering for both initial mode states

`test/agent_infrastructure_cleanup_test.php` did not execute to completion
because its repository-wide iterator encountered the existing inaccessible
`.pytest_cache/` directory. This is an environment/fixture traversal problem,
not an Agent assertion failure. The directory was not changed or removed.

## Historical Evaluation Evidence

The latest focused Agent/DeepThink quality records are from May 2026 and should
not be treated as a fresh live benchmark.

- A successful Chinese L1HS Agent report used eight plugins and took 932,901 ms
  (about 15.5 minutes).
- Another nearby Agent report attempt failed after 372,543 ms.
- A focused DeepThink L1HS synthesis completed in 14,852 ms.
- Historical DeepThink template records ranged from 311 ms to about 42 seconds.

These records support the handoff warning that Agent latency and failure risk
are concentrated in repeated LLM nodes and final Writing. They do not prove the
current relay/model combination has the same performance.

## Current Risks And Recommended Order

1. **Operational readiness:** restore or verify the local LLM relay before
   judging answer behavior. The current UI and deterministic contracts are
   healthy, but live LLM requests cannot be evaluated while the relay is down.
2. **Agent latency:** capture per-node and per-plugin timings on a small live
   question set before changing prompts or models. The historical 6-15 minute
   Agent duration is the largest product risk.
3. **Simple-question cost:** the compact preflight gate is intentionally
   disabled, so Agent handles simple lookups through the full workflow. Decide
   this as a product behavior, not as an incidental optimization.
4. **Semantic evaluation:** add fresh checks for citation relevance,
   claim/evidence alignment, association-versus-causality language, expression
   failure wording, and representative-locus wording.
5. **Run retention:** define a bounded cleanup policy for old payload, state,
   event, session, diagnostic, and PubMed cache files before the cache becomes a
   maintenance burden.
6. **Frontend dependencies:** decide whether Marked and G6 should remain CDN
   dependencies or join the project's local vendor assets.

The best next step is a small live baseline after the relay is available: one
simple DeepThink lookup, one DeepThink relation/literature question, and one
multi-dimensional Agent report. Record stage timings, selected plugins,
evidence quality, final answer support, and visible frontend state before
choosing an implementation target.
