# Agent Model Timeout Smoke Verification - 2026-05-28

Status: completed verifier/docs pass.

Scope:

- Verified the two Worker outputs without running live API calls or starting long-running services.
- Recorded that Agent no longer compact-gates simple Deep Think-recommended tasks away from the full Agent workflow.
- Recorded the current model split: Agent nodes default to `deepseek-v4-pro`; Deep Think/default DeepSeek paths stay on `deepseek-v4-flash`.
- Recorded the new timeout thresholds: Agent execution `900s`, six-stage node `90s`, answer chat `120s`, answer reasoner `180s`.
- Recorded the dry-run-first relay smoke script at `scripts/eval/relay_deepseek_smoke.py`.

Runtime notes:

- `test/agent_simple_preflight_gate_test.php` asserts simple Agent prompts are not compact-gated to Deep Think.
- `test/agent_model_config_test.php` asserts Agent pro defaults and Deep Think flash defaults.
- `test/agent_model_config_test.php` also covers the real webpage payload case where Agent still sends generic `model=deepseek-v4-flash`; `AcademicAgentService::resolveCoreModel()` ignores that generic field for Agent core routing and keeps `deepseek-v4-pro`. Explicit Agent overrides must use `core_model` or `agent_core_model`.
- `test/agent_timeout_config_test.php` asserts the new timeout thresholds and stale-run timeout rule.
- `test/relay_deepseek_smoke_test.py` and the direct smoke command verify dry-run request planning only.
- No live relay/API request was run; `scripts/eval/relay_deepseek_smoke.py` was executed without `--run-live`, so `mode` was `dry_run`, `live` was `false`, and `results` was empty.

Verification summary:

```powershell
php test\agent_simple_preflight_gate_test.php
# Agent simple preflight gate tests passed.

php test\agent_model_config_test.php
# Agent model config tests passed.
# Includes generic webpage model=flash no longer overriding Agent core pro.

php test\agent_evaluation_report_runtime_test.php
# Agent evaluation report runtime tests passed.

php test\agent_timeout_config_test.php
# Agent timeout config tests passed.

python test\relay_deepseek_smoke_test.py
# Ran 1 test ... OK

python scripts\eval\relay_deepseek_smoke.py --concurrency 3 --model deepseek-v4-flash --prompt ping --timeout 7
# ok=true, mode=dry_run, live=false, model=deepseek-v4-flash, concurrency=3, timeout=7, results=[]

php test\agent_six_stage_runtime_test.php
# Six-stage runtime tests passed.

node --check assets\js\pages\agent.js
# passed with no output
```

PHP lint passed for the related modified/new PHP files:

- `api/agent/bootstrap.php`
- `api/agent/bootstrap/run_store.php`
- `api/agent/orchestrator/AcademicAgentService.php`
- `api/agent/orchestrator/EntityNormalizer.php`
- `api/agent/orchestrator/LlmClient.php`
- `api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php`
- `api/config.local.php`
- `api/config.local.php.example`
- `api/agent/config/agent_node_prompts.php`
- `api/agent/config/agent_node_schemas.php`
- `api/agent/contracts/NodeLlmResult.php`
- `test/agent_simple_preflight_gate_test.php`
- `test/agent_model_config_test.php`
- `test/agent_evaluation_report_runtime_test.php`
- `test/agent_timeout_config_test.php`
- `test/agent_six_stage_runtime_test.php`

Residual risk:

- Live DeepSeek/relay/API behavior remains unverified by request. The dry-run smoke confirms request planning and config discovery only.
