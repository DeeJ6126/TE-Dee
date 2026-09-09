# Template Quality Check After Relay Proxy

Run date: 2026-05-30

## Scope

Checked the two replacement Deep Think templates and the five current Agent templates via the live API path.

Deep Think replacement cases:

- `TPL_DT_NEW_001_SEQUENCE_SUMMARY`: `What is the consensus length and evidence source of L1HS?`
- `TPL_DT_NEW_002_REPRESENTATIVE_LOCUS`: `What representative genome locus is available for L1HS?`

Agent template cases:

- `TPL_AGENT_001_MECHANISM`: `How does LINE-1 contribute to cancer?`
- `TPL_AGENT_002_EVIDENCE_AUDIT`: `What papers support the relationship between LINE-1 and Alzheimer's disease?`
- `TPL_AGENT_003_BATCH_COMPARISON`: `Compare the evidence strength linking L1HS, AluY, and HERVK to cancer.`
- `TPL_AGENT_004_GRAPH_RANKING`: `Which disease has the strongest association with transposable elements in the knowledge graph?`
- `TPL_AGENT_005_RESEARCH_REPORT`: `Generate a research report for L1HS including sequence, genomic location, expression, disease links, and literature evidence.`

## Commands

```powershell
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --cases docs\eval\runs\template_question_smoke_20260530\dt_replacement_cases.jsonl --dt-only --out-dir docs\eval\runs\template_question_smoke_20260530\dt_replacements_after_proxy --timeout 600 --poll-interval 2

python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --cases docs\eval\runs\template_question_smoke_20260530\agent_template_cases.jsonl --agent-only --model deepseek-v4-pro --out-dir docs\eval\runs\template_question_smoke_20260530\agent_templates_after_proxy --timeout 2400 --poll-interval 2
```

## Result

No case produced an answer, so answer quality could not be evaluated.

Deep Think:

- 2/2 failed in `Writing`.
- Failure: `LLM provider returned HTTP 500: error_type=URLError error=<urlopen error [SSL: UNEXPECTED_EOF_WHILE_READING] EOF occurred in violation of protocol (_ssl.c:1028)>`

Agent:

- 5/5 failed in `Understanding`.
- Every Agent run had only two events: `stage_state` then `node_llm_error`.
- No plugins ran.
- No answer was generated.
- Failure: `llm: stage=understanding provider=deepseek model=deepseek-v4-pro: LLM provider returned HTTP 500: error_type=URLError error=<urlopen error [SSL: UNEXPECTED_EOF_WHILE_READING] EOF occurred in violation of protocol (_ssl.c:1028)> relay=http://127.0.0.1:18087/chat`

## Assessment

This run does not provide evidence for replacing or keeping the templates. It only proves the relay now exposes the real upstream networking failure instead of hiding it behind a generic HTTP 500.

The current blocker is the proxy/TLS path from `llm_relay.py` to DeepSeek. The proxy is being used, but the HTTPS connection is closed during TLS negotiation or CONNECT forwarding. This is likely a proxy mode/port mismatch, a proxy rule issue, or an upstream block on the chosen proxy route.

## Recommendation

Do not change the templates based on this run. First fix the relay-to-DeepSeek network path, then rerun the same seven cases.

