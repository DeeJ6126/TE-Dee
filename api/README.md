# TE-Dee Intelligent QA Entry

This is the short entry page for maintainers working on the TE-Dee intelligent
question-answering system. It is intentionally index-like. Read the linked
documents before changing Agent, DeepThink, plugin routing, LLM prompts, or the
frontend thinking/status stream.

## Project Position

- The user-facing intelligent QA page is `agent.php`.
- DeepThink and Agent backend code lives mostly under `api/agent/`.
- DeepThink streams directly through `api/deep_think_stream.php`.
- Agent uses an async run model through `api/agent_runs.php`,
  `api/agent_run_status.php`, and `api/agent_run_execute.php`.
- Agent and DeepThink are part of the next AI maintainer's scope. They are not
  excluded from maintenance, but they should not be changed casually during
  unrelated database-page work.

## Read First

1. `api/docs/intelligent_qa_handoff.md`
2. `api/docs/intelligent_qa_runtime_audit_2026-07-28.md`
3. `api/docs/intelligent_qa_plugin_contract_hardening_2026-07-28.md`
4. `api/docs/intelligent_qa_architecture.md`
5. `api/docs/plugin_system.md`
6. `api/docs/testing_and_evaluation.md`
7. `api/docs/maintenance_workflow.md`
8. `api/agent/plugins/PLUGIN_CATALOG.md`
9. `api/agent/plugins/README.md`

## Directory Map

- `agent/`: orchestrators, contracts, plugins, prompts, runtime bootstrap, and
  evaluation fixtures for Agent and DeepThink.
- `agent/plugins/`: deterministic and LLM-assisted evidence plugins.
- `agent/config/`: node prompts, schemas, routing policy, site navigation map,
  and agent workflow lab configuration.
- `agent/contracts/`: evidence package, evidence walk, report plan, plugin
  envelope, integrity gate, and LLM node result structures.
- `agent/orchestrator/`: DeepThink, Agent, LLM client, entity normalization,
  citation resolution, and orchestration traits.
- `docs/`: the isolated intelligent QA maintenance documents.

## Runtime Entrypoints

- Page: `agent.php`
- DeepThink SSE: `api/deep_think_stream.php`
- Agent run create: `api/agent_runs.php`
- Agent run status: `api/agent_run_status.php`
- Agent run worker/execute: `api/agent_run_worker.php`,
  `api/agent_run_execute.php`, `api/agent_run_kickoff.php`
- Legacy direct Agent SSE: `api/agent_stream.php`; the current frontend uses
  async polling instead.
- Frontend: `assets/js/pages/agent.js`, `assets/css/pages/agent.css`

## Current Behavior

- DeepThink is the lighter four-stage assistant:
  `Understanding -> Planning -> Executing -> Writing`.
- Agent is the heavier six-stage assistant:
  `Understanding -> Planning -> Collecting -> Executing -> Integrating -> Writing`.
- Both modes resolve bounded follow-up context before entity normalization and
  routing. The resolver retains at most three successful turns, keeps the
  original question for the UI, and supplies a validated standalone effective
  question to the existing workflow.
- Ambiguous or context-free references return a natural clarification without
  running a scientific plugin.
- Conversation continuity is limited to the currently open page. The frontend
  keeps the session ID only in JavaScript memory and never restores it from
  `localStorage`, so reloads and new tabs start without prior page context.
- The plugin directory is read from `api/agent/plugins/PLUGIN_CATALOG.md` and
  supplied to LLM planning/collection prompts.
- Plugin results are evidence inputs, not final answers. Writing stages must
  respect evidence boundaries and should expose unsupported or missing evidence.
- The multi-turn layer does not change the twelve public plugins, routing
  policy, Agent stage count, or DeepThink stage count.

## Hard Constraints

- Do not invent deterministic fallback answers when a model writing stage is
  required to produce the answer.
- Do not treat Site Navigator results as scientific evidence.
- Do not call Literature Reading without usable Literature Plugin results.
- Do not interpret graph association, citation metadata, expression presence, or
  representative loci as stronger evidence than the plugin output supports.
- Do not rely on historical markdown as runtime truth. Verify the current code
  and local config before changing behavior.

## Common Checks

```powershell
php -l api/deep_think_stream.php
php -l api/agent_runs.php
php -l api/agent_run_status.php
php -l api/agent_run_execute.php
php -l api/agent/orchestrator/DeepThinkService.php
php -l api/agent/orchestrator/AcademicAgentService.php
php test/agent_model_config_test.php
php test/agent_six_stage_runtime_test.php
php test/agent_six_stage_llm_contract_test.php
php test/conversation_memory_test.php
php test/conversation_context_resolver_test.php
php test/agent_multiturn_context_runtime_test.php
php test/deepthink_multiturn_context_runtime_test.php
php test/deepthink_four_stage_runtime_test.php
php test/deepthink_four_stage_contract_test.php
php test/plugin_result_envelope_test.php
node scripts/checks/check_agent_conversation_session_scope.js
node scripts/checks/check_agent_llm_event_frontend_contract.js
node scripts/checks/check_agent_workflow_default_state_guard.js
node scripts/checks/check_deepthink_frontend_state_contract.js
```

## Next Recommended Work

1. Keep this entry and `api/docs/` aligned with the live code after every
   Agent/DeepThink change.
2. Review Agent writing latency and payload size before changing models again.
3. Keep DeepThink lightweight and evidence-bounded.
4. Keep plugin descriptions accurate enough for LLM planning, not just human
   maintainers.
