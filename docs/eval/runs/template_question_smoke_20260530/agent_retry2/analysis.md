# Template Question Smoke - Agent Retry 2

Run time: 2026-05-30

## Template changes

Changed two Deep Think templates in `agent.php` to reduce poor first-click UX:

- `Sequence lookup` / `What is the sequence of L1HS?`
  - replaced with `Sequence summary` / `What is the consensus length and evidence source of L1HS?`
  - reason: direct sequence lookup tends to dump the full 6064 bp sequence instead of giving a usable summary.
- `Site navigation` / `Where can I view the Genome Annotation Distribution for L1HS?`
  - replaced with `Representative locus` / `What representative genome locus is available for L1HS?`
  - reason: the former template was a weak navigation-style prompt and did not reliably produce a useful DT answer.

Agent research templates were not changed in this pass. Prior failures were caused by the model relay/API returning errors, not by confirmed template-answer mismatch.

## Verification

Syntax check:

```powershell
php -l agent.php
```

Result: `No syntax errors detected in agent.php`.

## Agent retry

Command:

```powershell
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --cases docs\eval\runs\template_question_smoke_20260530\agent_template_cases.jsonl --agent-only --model deepseek-v4-pro --out-dir docs\eval\runs\template_question_smoke_20260530\agent_retry2 --timeout 1800 --poll-interval 2
```

Result summary:

- total cases: 5
- agent_ok: 0
- all 5 runs failed at `Understanding`
- common failure reason: `llm: stage=understanding provider=deepseek model=deepseek-v4-pro: LLM provider returned HTTP 500 relay=http://127.0.0.1:18087/chat`

Failure cases:

- `TPL_AGENT_001_MECHANISM`
- `TPL_AGENT_002_EVIDENCE_AUDIT`
- `TPL_AGENT_003_BATCH_COMPARISON`
- `TPL_AGENT_004_GRAPH_RANKING`
- `TPL_AGENT_005_RESEARCH_REPORT`

A single-case probe was then run:

```powershell
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --cases docs\eval\runs\template_question_smoke_20260530\agent_template_cases.jsonl --case-id TPL_AGENT_001_MECHANISM --agent-only --model deepseek-v4-pro --out-dir docs\eval\runs\template_question_smoke_20260530\agent_retry3_probe --timeout 1800 --poll-interval 2
```

Probe result: same failure at `Understanding`, same relay HTTP 500.

## Assessment

The Agent template smoke test is currently blocked by the `deepseek-v4-pro` relay returning HTTP 500 before the Agent reaches planning, plugins, integration, or writing. This run cannot evaluate Agent answer quality or template quality.

Next useful action is to restore relay/model health, then rerun the same Agent template cases. Repeated template changes before the relay is healthy would not be evidence-based.
