# Agent P0/P1 Plugin Live API Fixes - 2026-05-30

## Goal

Finish the Agent P0/P1 plugin reliability work with harness-style exploration, worker fixes, reviewer checks, static/unit gates, and real API verification. The validation target was the frontend-equivalent Agent backend path:

- `api/agent_runs.php`
- `api/agent_run_status.php`

## Scope

This completion covers the plugin contract, routing, citation/evidence binding, writing, and frontend-visible workflow issues found during targeted Agent plugin canaries. It does not change Neo4j runtime, graph runtime, taxonomy runtime, or expression runtime roots.

## Key Fixes

- Unified plugin context accessors and high-risk plugin readers so downstream plugins can read entity resolution, prior plugin results, alias chains, and citations consistently.
- Bound evidence items to their own citations before falling back to plugin-level citations.
- Filtered diagnostic/system/empty evidence out of final supported biological claims.
- Fixed run store polling JSON pollution from `fgets()` warnings.
- Expanded routing for graph analytics and read-only Cypher exploration.
- Normalized Site Navigator URLs in `ReportIntegrityGate`:
  - Unicode dash/hyphen variants are compared as ASCII `-`.
  - trailing Markdown and Unicode quote punctuation is stripped for URL allowlist checks.
  - frontend-visible URL text is normalized inside URLs only.
- Added a deterministic direct-writing path for Site Navigator answers:
  - uses `Site Navigator Plugin` `results.answer_markdown` directly.
  - records a `writing_decision.v1` artifact with `writing_strategy=direct_site_navigation`.
  - bypasses draft/polish LLM URL rewriting for route-only answers.
  - still validates through `EvidencePackage` route_map and `ReportIntegrityGate`.
- Fixed Writing workflow state so failed writing leaves `Writing=failed` instead of always marking it `done`.

## Verification

Static/unit gate:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File temp\agent_p0p1_gate.ps1
```

Result: passed.

Targeted live API verification:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File temp\run_agent_plugin_live_parallel.ps1
python scripts\eval\check_agent_plugin_live_results.py --cases docs\eval\agent_plugin_live_cases.jsonl --run-dir docs\eval\runs\agent_plugin_live_targeted
```

Final checker result: all 11 targeted plugin cases passed.

The final Site Navigator retry used:

```powershell
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --cases docs\eval\agent_plugin_live_cases.jsonl --case-id AGENT_TARGET_SITE_NAV --agent-only --model deepseek-v4-pro --out-dir docs\eval\runs\agent_plugin_live_retry_site_nav_directwrite_statefix --timeout 1800 --poll-interval 2
```

Result: `agent_ok=True`.

Frontend-visible final answer preserved the exact route:

```text
http://127.0.0.1/TE-/search.php?q=L1HS&type=TE#search-sequence-panel
```

Workflow state in the final `done` event:

```json
{
  "Understanding": "done",
  "Planning": "done",
  "Collecting": "done",
  "Executing": "done",
  "Integrating": "done",
  "Writing": "done"
}
```

`writing_failed=false`.

## Live Targeted Cases

All passed after merging the latest Site Navigator raw event into:

- `docs/eval/runs/agent_plugin_live_targeted/raw_events/`

Cases:

- `AGENT_TARGET_SITE_NAV`
- `AGENT_TARGET_ENTITY_RESOLVER`
- `AGENT_TARGET_SEQUENCE`
- `AGENT_TARGET_GENOME`
- `AGENT_TARGET_GRAPH`
- `AGENT_TARGET_GRAPH_ANALYTICS`
- `AGENT_TARGET_LITERATURE_SEARCH`
- `AGENT_TARGET_LITERATURE_READING`
- `AGENT_TARGET_EXPRESSION`
- `AGENT_TARGET_TREE`
- `AGENT_TARGET_CYPHER_EXPLORER`

## Reviewer Notes

Reviewer found no P0/P1 blocker after direct Site Navigator Writing. A P2 workflow-state issue was fixed immediately with a red/green regression assertion in `test/agent_evidence_walk_runtime_test.php`.

Residual low-risk note: there is still no full fixture-level stream test that proves draft/polish LLM were not called for direct site navigation; current coverage includes helper-level behavior, source-level runtime assertions, static/unit gates, and real API verification.

