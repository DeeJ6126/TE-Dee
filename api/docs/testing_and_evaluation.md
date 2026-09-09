# Intelligent QA Testing And Evaluation

This document lists the checks most useful for Agent and DeepThink maintenance.

## Static PHP Checks

```powershell
php -l api/deep_think_stream.php
php -l api/agent_runs.php
php -l api/agent_run_status.php
php -l api/agent_run_execute.php
php -l api/agent/orchestrator/DeepThinkService.php
php -l api/agent/orchestrator/AcademicAgentService.php
```

## Runtime Contract Tests

```powershell
php test/agent_model_config_test.php
php test/agent_timeout_config_test.php
php test/agent_six_stage_runtime_test.php
php test/agent_six_stage_llm_contract_test.php
php test/agent_executing_review_resilience_test.php
php test/agent_simple_preflight_gate_test.php
php test/agent_routing_stop_conditions_test.php
php test/conversation_memory_test.php
php test/conversation_context_resolver_test.php
php test/agent_multiturn_context_runtime_test.php
php test/deepthink_multiturn_context_runtime_test.php
php test/deepthink_four_stage_runtime_test.php
php test/deepthink_four_stage_contract_test.php
php test/deepthink_relationship_synthesis_test.php
php test/deepthink_sequence_local_answer_test.php
php test/plugin_result_envelope_test.php
php test/report_integrity_gate_test.php
php test/plugin_native_result_contract_test.php
php test/plugin_evidence_semantics_test.php
php test/plugin_status_semantics_test.php
php test/literature_reading_fallback_contract_test.php
php test/literature_query_disambiguation_test.php
php test/plugin_payload_projection_test.php
```

## Frontend Contract Checks

```powershell
node scripts/checks/check_agent_llm_event_frontend_contract.js
node scripts/checks/check_agent_workflow_default_state_guard.js
node scripts/checks/check_deepthink_frontend_state_contract.js
node scripts/checks/check_agent_conversation_session_scope.js
```

## Repository-Level Checks

```powershell
python scripts/checks/check_docs_freshness.py
python scripts/checks/check_api_contracts.py
python scripts/checks/check_neo4j_tekg3.py
python scripts/checks/check_no_legacy_db_fallback.py
```

`check_api_contracts.py` and `check_neo4j_tekg3.py` require the local services
to be running. Do not interpret their failures as Agent bugs until WAMP and
Neo4j state is confirmed.

## Live Evaluation

Live Agent/DeepThink evaluation records live under `docs/eval/runs/`. Use a new
run directory for each live run.

Example:

```powershell
python scripts/eval/run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --cases docs/eval/dt_agent_golden_cases.jsonl --out-dir docs/eval/runs/<run-name> --timeout 2400 --poll-interval 2
```

Live evaluation needs:

- WAMP/PHP available at the chosen base URL.
- Neo4j running with the expected `tekg3` database.
- MySQL available when expression evidence is tested.
- LLM relay or DashScope configuration available.

The 2026-07-28 contract-hardening verification used three isolated live runs:

- DeepThink sequence: `2026-07-28-plugin-contract-dt-sequence`
- DeepThink literature: `2026-07-28-plugin-contract-dt-literature`
- Agent report: `2026-07-28-plugin-contract-agent-report`

The Agent report completed nine plugins and writing in 467,486 ms. Selective
ExecutingReview called the LLM for five scientifically interpretive results and
skipped four deterministic/post-processing results, while the final answer kept
association, representative-locus, sequence, and evidence-depth limitations.

The reviewer-facing English baseline is defined in
`docs/eval/agent_13_question_cases.jsonl`. Its consolidated report is
`docs/eval/runs/2026-07-28-agent-13-question-baseline/report.md`. The baseline
contains thirteen runs across DeepThink and Agent, records the complete returned
answers and exposed workflow artifacts, and observed all twelve plugins. Stage
timings in that report are measured LLM HTTP-call durations from diagnostics;
they are not inferred wall-clock durations or hidden model reasoning.

The 2026-07-28 Literature Plugin disambiguation verification reran AQ06 with an
entity-scoped LINE-1/cancer PubMed query. The plugin inspected eight top records,
retained four title-level LINE-1/cancer matches, filtered four scope mismatches,
and the DeepThink answer cited only the retained PMIDs. The raw verification run
is `2026-07-28-literature-disambiguation-aq06-v3` (local evaluation artifact).

The 2026-07-28 Agent routing-stop verification used AQ08 and AQ13. AQ08 took
190,083 ms and called Entity Resolver, Graph Analytics, and Cypher Explorer. The
analytics result was empty, so the existing fallback ran, while forbidden Graph
and Literature plugins did not re-enter. AQ13 took 311,565 ms and retained the
requested Sequence, Tree, Genome, Expression, Graph, and Literature coverage.
Literature Reading remained available in the queue but did not run because the
Literature result contained no stable citations to read. These runs are local
evaluation artifacts named `2026-07-28-agent-routing-stop-aq08` and
`2026-07-28-agent-routing-stop-aq13`.

The 2026-07-29 user-facing writing verification used the exact L1HS research
report question from the baseline. Prompt-only runs still exposed audit-style
language and encouraged unsupported background expansion. The final design
therefore projects internal evidence into `UserFacingWritingContext` before
Agent or DeepThink Writing, audits generated prose, and runs a repair pass only
when presentation leakage is detected. Regression coverage includes internal
IDs and flags, registered plugin names, evidence accounting, internal source
labels, malformed citation fragments, duplicate literature sections, and
unsupported sequence-structure expansion. Live iterations are stored under
`docs/eval/runs/2026-07-29-agent-user-facing-writing/`.

The 2026-07-30 multi-turn and broad quality evaluation is defined by
`docs/eval/agent_deepthink_36_question_cases.jsonl` and stored under
`docs/eval/runs/2026-07-30-agent-deepthink-36-question/`. It contains exactly
30 fixed cases plus six adaptive cases, with 34 English questions, both modes,
all twelve public plugin roles, and several typical TE families. The run records
responses, plugin chains, citations, context diagnostics, errors, and elapsed
time. `report_zh.md` is the maintainer-facing quality review and `summary.json`
is the machine-readable summary.

That run found no observed loss of single-turn quality or plugin capability
caused by the context layer. Follow-ups inherited SVA_F and AluY correctly,
ambiguous or fresh-session pronouns clarified without plugin calls, and browser
testing confirmed reload isolation. The broader answer-quality result was mixed
(16 pass, 10 partial, 10 fail), so it must not be presented as proof that all
Agent/DeepThink answers are production-perfect. The failures are recorded for
future scoped work rather than hidden by the context feature's success.

DeepThink frontend verification also covers terminal progress state: once a
successful answer and `done` state are received, Understanding, Planning,
Executing, and Writing must all render as complete with no active spinner.

The 2026-07-30 F13 follow-up fixed a deterministic URL-integrity false positive.
Markdown destinations are now extracted once and masked before the separate
bare-URL scan, so escaped paragraph breaks after a Markdown PubMed link cannot
be absorbed into a second malformed URL. `report_integrity_gate_test.php`
reproduces the original multi-link long-report shape. The live F13 rerun
completed Writing with no error and returned all requested evidence dimensions;
its record is under `2026-07-30-agent-f13-url-integrity-rerun/`.

The 2026-07-30 A34 follow-up added deterministic PMID-link alignment. Colon-form
markers such as `PMID:41681929` now participate in integrity validation. For an
official PubMed Markdown destination, URL normalization aligns the displayed
PMID with the identifier in the destination before Writing is checked, while a
raw mismatch still fails the integrity gate as a final defense. The regression
uses the exact A34 mismatch (`4181929` displayed for URL `41681929`) and also
confirms that an already matching link remains unchanged.

## Interpreting Results

- A green static check proves syntax or contract markers only; it does not prove
  scientific answer quality.
- Read every live answer as a user who does not know the plugin architecture.
  Internal vocabulary that a maintainer can mentally translate is still a
  user-facing writing failure.
- A successful plugin call proves retrieval succeeded, not that the final claim
  is biologically strong.
- Agent answer quality should be judged against evidence support, citation
  relevance, unsupported-claim avoidance, and language consistency.
- DeepThink should be judged for concise evidence-grounded answers and clear
  failure exposure.
