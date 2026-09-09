# Template Question Smoke Test

Date: 2026-05-30

## Scope

This run tested the current `agent.php` template questions without changing code or templates.

Deep Think templates:

1. `What is the sequence of L1HS?`
2. `Where is L1HS located in the genome?`
3. `In which tissues is L1HS expressed?`
4. `Which subfamily does L1HS belong to?`
5. `Where can I view the Genome Annotation Distribution for L1HS?`

Agent templates:

1. `How does LINE-1 contribute to cancer?`
2. `What papers support the relationship between LINE-1 and Alzheimer's disease?`
3. `Compare the evidence strength linking L1HS, AluY, and HERVK to cancer.`
4. `Which disease has the strongest association with transposable elements in the knowledge graph?`
5. `Generate a research report for L1HS including sequence, genomic location, expression, disease links, and literature evidence.`

## Deep Think Result

Endpoint path: `api/deep_think_stream.php`

Run directory: `dt/`

- 5/5 completed.
- 3 templates passed the UX check.
- 1 template is usable but heavy.
- 1 template should be replaced or moved away from DT.

| Case | Result | Notes |
|---|---|---|
| Sequence lookup | Borderline | Correctly returns L1HS sequence evidence, but the final answer includes the full 6064 bp sequence and feels too heavy for a quick template. |
| Genome location | Pass | Directly returns representative hg38 locus and total hits. |
| Expression lookup | Pass | Directly returns expression contexts. |
| Classification lookup | Pass | Directly returns L1PA classification/path. |
| Site navigation | Fail | It does not reliably answer where to view Genome Annotation Distribution; it returns an evidence-gap answer instead. |

## Agent Result

Endpoint path: `api/agent_runs.php` + `api/agent_run_status.php`

Run directories:

- First full attempt: `agent/`
- Retry attempt: `agent_retry1/`
- Single health probe: `agent_health_probe/`

All Agent attempts failed before answer generation due to local LLM relay/provider errors:

- `llm: stage=understanding provider=deepseek model=deepseek-v4-pro: LLM provider returned HTTP 500 relay=http://127.0.0.1:18087/chat`

The first full attempt had one case progress farther, then fail at `ExecutingReview`; the retry and health probe failed at `Understanding`. Because no Agent template produced a final answer in the retry/health probe, this run cannot honestly judge Agent answer quality.

## Recommendation

Deep Think templates should be adjusted for a more stable first impression:

- Replace `Where can I view the Genome Annotation Distribution for L1HS?` with a data question that DT already answers reliably, or move site-navigation examples to a dedicated navigation-capable path.
- Consider replacing `What is the sequence of L1HS?` with a lighter prompt such as asking for consensus length, source, and a short sequence preview instead of the full sequence.

Agent template quality is not yet assessable from this run because the Agent backend was unavailable through `deepseek-v4-pro` relay calls. Before changing Agent templates, rerun the same five questions after the relay/API is healthy.
