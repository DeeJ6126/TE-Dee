# Agent P1 Context/Routing/Citation Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development or equivalent harness workflow. Workers for this plan use reasoning effort high as requested. Steps use checkbox (`- [ ]`) syntax for tracking. Do not run live API until static/unit checks pass.

**Goal:** Fix the P1 contract gaps and the live API routing regression exposed by `AGENT_P0_SITE_NAV`, then verify with real Agent API runs.

**Architecture:** Keep fixes narrow and contract-driven. First repair deterministic site-navigation detection so route questions call Site Navigator. Then add shared context/citation accessors and migrate the most fragile readers to those helpers. Finally run live API canaries and inspect thinking plus final answers.

**Tech Stack:** PHP Agent normalizer/planning/plugins/contracts, existing PHP tests, Python live eval runner against `api/agent_runs.php` and `api/agent_run_status.php`.

---

## Current Evidence

Live canary results:

- `docs/eval/runs/agent_p0_live_parallel_site_nav/raw_events/AGENT_P0_SITE_NAV.json`
- `docs/eval/runs/agent_p0_live_parallel_l1hs_report/raw_events/AGENT_P0_L1HS_REPORT.json`
- `docs/eval/runs/agent_p0_live_parallel_empty_diagnostic/raw_events/AGENT_P0_EMPTY_DIAGNOSTIC.json`

The Site Navigator live case completed but failed functionally:

- Question: `Where can I open the L1HS sequence panel in TE-KG?`
- Actual plugins: `Entity Resolver`, `Sequence Plugin`, `Citation Resolver`
- Expected plugin: `Site Navigator Plugin`
- Root evidence from raw event: `intent=sequence`, planning `required_plugins=["Sequence Plugin"]`.
- Likely deterministic cause: `EntityNormalizer::asksForSiteNavigation()` recognizes `where can i find`, but not `where can i open`.

## Task 1: Repair Site Navigation Detection and Planning

**Owner:** Worker P1-A

**Files:**

- Modify: `api/agent/orchestrator/EntityNormalizer.php`
- Modify: `test/task_complexity_test.php`
- Modify or add: `test/agent_research_report_planning_test.php`

- [ ] Add a failing test for English page/panel navigation:

```php
$analysis = $normalizer->analyze('Where can I open the L1HS sequence panel in TE-KG?', []);
assert_true($analysis['asks_for_site_navigation'], 'where can I open sequence panel is site navigation');
assert_same('sequence', $analysis['intent'], 'capability may remain sequence but navigation flag must be true');
```

- [ ] Add a planning assertion that the Agent tool plan includes `Site Navigator Plugin` before `Sequence Plugin` when `asks_for_site_navigation=true`.

- [ ] Implement the minimal normalizer update.

Required phrases:

- `where can i open`
- `where do i open`
- `where to open`
- `open the`
- `open ... panel` or equivalent safe token logic
- `panel`

Do not reintroduce the old broad bug where arbitrary `link` in `disease links` triggers navigation for research reports. Research synthesis questions must still keep `asks_for_site_navigation=false` unless they explicitly ask for page/panel/URL navigation.

- [ ] Run:

```powershell
php test/task_complexity_test.php
php test/agent_research_report_planning_test.php
php test/site_navigator_plugin_test.php
```

## Task 2: Add Plugin Context and Citation Accessors

**Owner:** Worker P1-B

**Files:**

- Modify: `api/agent/bootstrap/evidence_support.php`
- Add: `test/agent_plugin_context_accessor_test.php`

- [ ] Add failing tests for helper functions.

Required helper behavior:

```php
tekg_agent_context_analysis($context)
tekg_agent_context_plugin_results($context)
tekg_agent_context_plugin_result($context, 'Graph Plugin')
tekg_agent_context_resolved_entities($context, 'TE')
tekg_agent_context_alias_chains($context)
tekg_agent_plugin_result_citations($result)
tekg_agent_context_citations($context, ['Citation Resolver'])
```

Required entity priority:

1. `context.entity_resolution.resolved_entities`
2. `plugin_results['Entity Resolver'].results.resolved_entities`
3. `plugin_results['Entity Resolver'].results.alias_chains`
4. `analysis.normalized_entities`
5. `analysis.alias_chains`

Required citation sources:

- top-level `citations`
- `result_envelope.citations`
- `results.citations`
- `display_details.citations`
- `evidence_items[*].citations`

The accessor must deduplicate citations by PMID, DOI, URL, title, or JSON fallback.

- [ ] Implement helpers without changing plugin behavior yet.

- [ ] Run:

```powershell
php -l api/agent/bootstrap/evidence_support.php
php test/agent_plugin_context_accessor_test.php
php test/plugin_result_envelope_test.php
```

## Task 3: Entity Resolver Output Becomes Contract Input

**Owner:** Worker P1-C

**Files:**

- Modify: `api/agent/plugins/EntityResolverPlugin.php`
- Modify: `api/agent/orchestrator/AcademicAgentService.php`
- Modify or add: `test/agent_plugin_context_accessor_test.php`
- Modify or add narrow runtime/static test if needed.

- [ ] Make `EntityResolverPlugin` output both:

```php
'results' => [
  'alias_chains' => ...,
  'resolved_entities' => [
    [
      'label' => 'L1HS',
      'canonical_label' => 'L1HS',
      'type' => 'TE',
      'matched_alias' => 'L1HS',
      'aliases' => [...],
      'broad_aliases' => [...],
      'confidence' => 0.93,
    ],
  ],
]
```

- [ ] After Entity Resolver runs, add an `entity_resolution` context for subsequent plugin calls, or ensure `tekg_agent_context_resolved_entities()` can read `plugin_results['Entity Resolver']` during sequential execution.

- [ ] Run:

```powershell
php -l api/agent/plugins/EntityResolverPlugin.php
php -l api/agent/orchestrator/AcademicAgentService.php
php test/agent_plugin_context_accessor_test.php
php test/agent_six_stage_runtime_test.php
```

## Task 4: Migrate High-Risk Plugin Readers to Accessors

**Owner:** Worker P1-D

**Files:**

- Modify: `api/agent/plugins/SequencePlugin.php`
- Modify: `api/agent/plugins/GenomePlugin.php`
- Modify: `api/agent/plugins/ExpressionPlugin.php`
- Modify: `api/agent/plugins/TreePlugin.php`
- Modify: `api/agent/plugins/SiteNavigatorPlugin.php`
- Modify: `api/agent/plugins/LiteraturePlugin.php`
- Modify if low-risk: `api/agent/plugins/GraphPlugin.php`, `api/agent/plugins/GraphAnalyticsPlugin.php`, `api/agent/plugins/CypherExplorerPlugin.php`
- Add or modify focused plugin tests where existing tests allow it.

- [ ] Replace direct `analysis.normalized_entities` / `analysis.alias_chains` reads with helper accessors where possible.

- [ ] Keep query semantics unchanged.

- [ ] Preserve Site Navigator page/panel route behavior.

- [ ] Run:

```powershell
php test/site_navigator_plugin_test.php
php test/agent_research_report_planning_test.php
php test/plugin_result_envelope_test.php
```

## Task 5: Citation Accessor Integration

**Owner:** Worker P1-E

**Files:**

- Modify: `api/agent/plugins/CitationResolverPlugin.php`
- Modify: `api/agent/plugins/LiteratureReadingPlugin.php`
- Modify: `api/agent/orchestrator/traits/AcademicAgentEvidenceTrait.php`
- Modify: `api/agent/contracts/EvidencePackage.php`
- Modify/add tests: `test/agent_plugin_context_accessor_test.php`, `test/evidence_package_test.php`, `test/agent_evidence_package_runtime_test.php`

- [ ] Use `tekg_agent_plugin_result_citations()` / `tekg_agent_context_citations()` when aggregating citations.

- [ ] In `EvidencePackage`, prefer evidence-item-level `citations` for a claim. Only fall back to plugin-level citations when the evidence item has no citations.

- [ ] Preserve citation IDs and deterministic ordering.

- [ ] Run:

```powershell
php test/agent_plugin_context_accessor_test.php
php test/evidence_package_test.php
php test/agent_evidence_package_runtime_test.php
php test/plugin_result_envelope_test.php
```

## Task 6: Reviewer

**Owner:** Reviewer P1

- [ ] Review findings first.
- [ ] Confirm Site Navigator live failure root cause is addressed by deterministic tests.
- [ ] Confirm helper/accessor changes do not alter Neo4j, graph runtime, taxonomy runtime, or expression runtime.
- [ ] Confirm citation binding no longer over-associates plugin-level citations when evidence item citations exist.
- [ ] Confirm tests are not only brittle string checks.

## Task 7: Static and Unit Verification

Run:

```powershell
php test/task_complexity_test.php
php test/agent_research_report_planning_test.php
php test/site_navigator_plugin_test.php
php test/agent_plugin_context_accessor_test.php
php test/evidence_package_test.php
php test/agent_evidence_package_runtime_test.php
php test/plugin_result_envelope_test.php
php test/agent_research_report_prompt_test.php
php test/agent_six_stage_runtime_test.php
php test/agent_evidence_walk_runtime_test.php
node --check assets/js/pages/agent.js
node scripts/checks/check_agent_llm_event_frontend_contract.js
node scripts/checks/check_agent_workflow_default_state_guard.js
```

## Task 8: Live API Verification

Live API is mandatory.

Run at minimum:

```powershell
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --cases docs\eval\agent_p0_live_cases.jsonl --case-id AGENT_P0_SITE_NAV --agent-only --model deepseek-v4-pro --out-dir docs\eval\runs\agent_p1_live_site_nav --timeout 1800 --poll-interval 2
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --cases docs\eval\agent_p0_live_cases.jsonl --case-id AGENT_P0_L1HS_REPORT --agent-only --model deepseek-v4-pro --out-dir docs\eval\runs\agent_p1_live_l1hs_report --timeout 2400 --poll-interval 2
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --cases docs\eval\agent_p0_live_cases.jsonl --case-id AGENT_P0_EMPTY_DIAGNOSTIC --agent-only --model deepseek-v4-pro --out-dir docs\eval\runs\agent_p1_live_empty_diagnostic --timeout 2400 --poll-interval 2
```

Pass conditions:

- `AGENT_P0_SITE_NAV` must call `Site Navigator Plugin`, include `search-sequence-panel`, and final answer must provide the route rather than claiming no UI evidence.
- `AGENT_P0_L1HS_REPORT` must still call report-relevant plugins and preserve missing/weak evidence as limitations.
- `AGENT_P0_EMPTY_DIAGNOSTIC` must not convert generic PubMed hits into evidence about the fake TE.
- Raw events must be inspected, not only `agent_ok=True`.
