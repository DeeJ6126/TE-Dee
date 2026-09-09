# Agent Plugin Evidence And Contract Hardening Plan

> **Execution note:** This plan records the full design context agreed on 2026-07-28. The primary maintainer owns implementation and verification. Do not delegate code edits to subagents unless the user explicitly re-approves that workflow. Use `executing-plans` when implementation begins, and keep each task independently testable and revertible.

**Goal:** Preserve the existing twelve-plugin Agent/DeepThink system while making plugin results easier for the LLM to interpret, scientifically more honest, structurally enforceable, and materially smaller in runtime event payloads.

**Architecture:** Keep the current plugin interface, registry, public plugin names, orchestration stages, and deterministic data sources. Add a small native-result contract validator, separate scientific evidence strength from diagnostic/query confidence, make degraded fallbacks and mixed outcomes explicit, and derive bounded LLM/UI projections from one canonical plugin result.

**Tech stack:** PHP plugin runtime and contracts under `api/agent/`, existing PHP contract tests, Python evaluation helpers, browser/API smoke checks, and the existing local LLM relay at `127.0.0.1:18087`.

---

## Background

The current plugin set is usable and more coherent than a rewrite would justify. The registry and catalog agree on twelve public plugins:

1. Entity Resolver
2. Site Navigator Plugin
3. Graph Plugin
4. Graph Analytics Plugin
5. Cypher Explorer Plugin
6. Literature Plugin
7. Literature Reading Plugin
8. Sequence Plugin
9. Expression Plugin
10. Genome Plugin
11. Tree Plugin
12. Citation Resolver

Their native results generally follow the same twelve-field top-level convention:

```text
plugin_name
status
query_summary
results
display_label
display_summary
display_details
result_counts
evidence_items
citations
errors
latency_ms
```

That uniformity is currently a convention rather than an enforced contract. `TekgAgentPluginInterface` only requires `getName()` and `run()`. Compatibility code later normalizes results into `PluginResultEnvelope`, while orchestration and UI code produce several additional representations. This has kept the system flexible, but it also allows semantic drift and oversized event payloads.

The 2026-07-28 read-only audit found six concrete improvement areas:

1. `support_strength` sometimes represents alias-match confidence, query certainty, rank, or citation count instead of scientific support for a claim.
2. Literature Reading can fall back from failed LLM synthesis to title-based grouping while still presenting the operation as fully successful.
3. A successful PubMed search query is currently promoted to `medium` evidence even though retrieval success is not biological evidence.
4. Graph Plugin reports overall `error` when some graph queries succeed and others fail; the truthful state is `partial` when usable data remains.
5. The common result structure is not validated immediately after every plugin runs.
6. Similar content is repeated across native results, envelopes, LLM context, UI detail objects, and streamed `tool_result` events.

A historical successful L1HS Agent run produced roughly 328,917 characters across eight `tool_result` payloads. The largest contributors were approximately Graph 112 KB, Citation Resolver 68 KB, Literature 47 KB, Sequence 43 KB, and Literature Reading 38 KB. These are not merely storage concerns: oversized repeated context can make tool selection, evidence interpretation, latency, and final synthesis less reliable.

The relay was rechecked during planning. TCP connectivity to `127.0.0.1:18087` succeeded. An `OPTIONS /chat` request returned HTTP 501, which confirms a listener but is not a real LLM inference test. Live inference must therefore remain an explicit later verification step.

## Approved Design Decisions

- Keep the twelve existing plugins and their public names.
- Do not replace deterministic database, graph, navigation, sequence, or citation work with LLM calls.
- Define `support_strength` narrowly: it describes how strongly an evidence item supports the exact scientific claim represented by that item.
- Store alias confidence, match type, query certainty, result rank, citation count, and similar operational facts in dedicated metadata fields.
- Preserve useful fallbacks, but expose their mode and degraded state instead of disguising them as normal LLM synthesis.
- Use `partial` for mixed success when usable results and errors coexist.
- Validate each native plugin result before envelope conversion, evidence aggregation, LLM projection, or UI projection.
- Reduce repeated payloads without breaking the result inspector, graph details, citations, or full sequence access.
- Preserve the current Agent and DeepThink endpoints, stages, and front-end workflow.
- Create new dated evaluation records; do not overwrite historical runs.

## Evidence Semantics

The implementation must distinguish scientific evidence from diagnostic or routing metadata.

### Scientific evidence

Scientific evidence may support a claim about a TE, relationship, genomic record, expression observation, publication finding, or derived graph metric. Its strength depends on the relationship between the source and the exact claim, not simply on whether a lookup succeeded.

### Diagnostic metadata

The following are useful to the orchestrator and LLM but are not, by themselves, biological evidence:

- entity alias resolution;
- page or feature navigation;
- successful database/API query execution;
- PubMed search execution;
- record rank or citation count;
- plugin selection rationale;
- absence of an error.

The current `EvidencePackage` already recognizes a limited diagnostic convention based on `support_strength=none` plus non-empty `diagnostic` or `provenance` metadata. Extend and test that existing convention first. Add a new flag such as `is_scientific_evidence=false` only if the existing representation cannot express a required case without ambiguity. Diagnostic records may carry fields such as `match_confidence`, `match_type`, `query_status`, `rank`, `citation_count`, and `source_scope`.

### Plugin-specific expectations

- Entity Resolver: exact or strict alias matches may have high match confidence, but must not become high scientific support.
- Site Navigator: navigation results are diagnostic only.
- Citation Resolver: citation normalization is provenance infrastructure, not evidence for the cited claim.
- Literature Plugin: a successful PubMed query is diagnostic; individual records may provide literature evidence with strength determined by source and claim proximity.
- Literature Reading: citation count must not automatically create high evidence. Evidence should reflect what was actually extracted or synthesized from the available records.
- Graph Plugin: a returned relation can usually be `medium` evidence for the existence of that stored association, with a flag such as `association_not_causality` where causal interpretation would be unsafe.
- Graph Analytics Plugin: derived degree, centrality, or neighborhood metrics can be `medium` evidence for the metric itself, with derivation and graph scope recorded.
- Cypher Explorer Plugin: successful query execution is diagnostic; returned domain facts may become evidence only after typed conversion.
- Sequence Plugin: exact sequence/source records and keyword-derived structure hints must be separate items. Keyword hints should be `low` and marked `keyword_derived`.
- Expression, Genome, and Tree: retain their currently conservative boundaries unless contract tests reveal a mismatch.

## Status Semantics

All plugins should use the same status decision table:

| Status | Meaning |
| --- | --- |
| `ok` | Usable results exist and no operation-level error or degraded fallback occurred. |
| `partial` | Usable results exist, but one or more requested operations failed or a degraded fallback was required. |
| `empty` | No usable result was found and no execution failure occurred. |
| `error` | No usable result could be produced because execution failed. |

This table describes native plugin status. The compatibility `PluginResultEnvelope` currently maps native `error`/`failed` to envelope status `failed`. Preserve that compatibility unless every downstream consumer is audited and migrated deliberately.

A shared helper may encode this decision, for example:

```php
tekg_agent_plugin_status(bool $hasUsableData, array $errors): string
```

Fallback warnings may be stored separately from fatal errors, but any fallback that materially changes the interpretation of the result must be visible in status or explicit mode metadata.

## Native Result Contract

Add a small validator rather than replacing the plugin architecture with a large DTO hierarchy. The proposed file is:

```text
api/agent/contracts/PluginResultContract.php
```

It should validate or normalize, at minimum:

- the twelve required native fields: `plugin_name`, `status`, `query_summary`, `results`, `display_label`, `display_summary`, `display_details`, `result_counts`, `evidence_items`, `citations`, `errors`, and `latency_ms`;
- exact `plugin_name` agreement with the registered plugin;
- allowed status values;
- array/object types for results, evidence, citations, and errors;
- non-negative numeric `latency_ms`;
- normalized evidence fields and allowed strength values;
- stable citation identifiers and URLs when present;
- status/error consistency;
- diagnostic versus scientific evidence classification.

Validation must occur immediately after `plugin->run()` and before downstream consumers see the result. Contract failure must become a visible plugin failure with a useful diagnostic; it must not be silently corrected into a misleading success.

## Result Projections

The target data flow is:

```text
canonical native plugin result
    -> compatibility/result envelope
    -> bounded LLM view
    -> bounded UI/event view
    -> optional diagnostic reference
```

The canonical result remains complete enough for in-process evidence aggregation. Other representations should be derived deliberately:

- `llm_view`: compact facts, evidence summaries, citations, warnings, and carefully selected records needed for reasoning;
- `ui_view`: summary and expandable details required by the existing inspector;
- `diagnostic_ref`: identifiers or metadata for verbose debugging data when needed.

The first implementation should avoid adding a new public endpoint. It should remove duplicated branches and introduce named size/count limits while preserving all user-visible capabilities. Do not reduce the evidence available to final Writing until semantic regression tests demonstrate that the smaller representation is sufficient.

## Scope

Expected runtime files include:

- `api/agent/bootstrap/evidence_support.php`
- the plugin registry/runner used by Agent and DeepThink
- `api/agent/contracts/PluginResultEnvelope.php`
- `api/agent/contracts/EvidencePackage.php`
- Agent and DeepThink plugin-result/evidence traits
- Entity Resolver, Site Navigator, Graph, Graph Analytics, Cypher Explorer, Literature, Literature Reading, and Sequence plugins
- Expression, Genome, Tree, and Citation Resolver only where contract enforcement exposes a real mismatch

Expected documentation files include:

- `api/README.md`
- `api/docs/intelligent_qa_handoff.md`
- Agent architecture and testing documentation
- `api/agent/plugins/PLUGIN_CATALOG.md`
- this execution plan and, if a structural risk remains, `docs/exec-plans/tech-debt-tracker.md`

## Non-Goals

- No work on Home, Browse, Search, Path, Expression pages, Graph page rendering, Download, or About.
- No new plugin, data source, graph database, taxonomy truth source, or expression runtime root.
- No Agent/DeepThink UI redesign.
- No orchestration-stage redesign or model change before baseline evidence exists.
- No deletion or rewriting of historical evaluation data.
- No background job queue or broad asynchronous-runtime rewrite.
- No wholesale replacement of plugin arrays with a new object framework.
- No automatic cleanup of `.pytest_cache` or unrelated working-tree content.

## Preflight: Reconcile Existing Active Plans

Two earlier active plans overlap with the same contracts and evidence path:

- `docs/exec-plans/active/agent-p0-plugin-writing-live-api-fixes.md`
- `docs/exec-plans/active/agent-p1-context-routing-citation-fixes.md`

Before implementing this plan, compare their checkboxes with the current code and tests. Some described work is already present even though the plans remain under `active/`. Record what is complete, still relevant, superseded, or stale. Do not reimplement context accessors, citation binding, Site Navigator normalization, or writing-contract changes merely because an older checkbox is unchecked.

---

## Task 1: Freeze Current Names And Shapes

**Files:** Add `test/plugin_native_result_contract_test.php`; inspect registry, catalog, and all plugin classes.

- [x] Add characterization tests proving the twelve registered names exactly match the catalog and runtime result names.
- [x] Capture the twelve expected native top-level fields for every plugin, including both success and empty/error branches.
- [x] Add representative success, empty, partial, and error fixtures without changing runtime behavior.
- [x] Record any existing exception rather than normalizing it silently during this task.

**Gate:** No production semantic changes until the characterization suite passes on the current implementation.

## Task 2: Separate Scientific Evidence From Diagnostics

**Files:** Modify `api/agent/bootstrap/evidence_support.php`, `EvidencePackage.php`, and add `test/plugin_evidence_semantics_test.php`.

- [x] Define and document the meaning of `support_strength`.
- [x] Add a helper such as `tekg_agent_make_diagnostic_item()` using the same stable base shape where practical.
- [x] Ensure evidence aggregation can exclude diagnostics without discarding them from tool reasoning and inspection.
- [x] Keep backward-compatible reading for historical result fixtures.
- [x] Add tests showing that high alias confidence, query success, rank, and citation count do not become high scientific support.

**Gate:** Agent and DeepThink must agree on which items enter their evidence packages.

## Task 3: Correct Plugin Evidence Semantics

**Files:** Modify Entity Resolver, Site Navigator, Graph, Graph Analytics, Cypher Explorer, Literature, Literature Reading, Sequence, and focused tests.

- [x] Move entity resolution confidence into match metadata.
- [x] Mark navigation and query-execution records diagnostic.
- [x] Preserve graph associations as evidence for associations, never as automatic causal evidence.
- [x] Attach graph scope and derivation metadata to analytics evidence.
- [x] Separate exact sequence/source records from keyword-derived structure hints.
- [x] Prevent citation count from elevating literature-reading evidence.
- [x] Verify Expression, Genome, Tree, and Citation behavior remains unchanged unless a failing contract test justifies a correction.

## Task 4: Make Literature Fallback Truthful

**Files:** Modify `LiteraturePlugin.php`, `LiteratureReadingPlugin.php`; add `test/literature_reading_fallback_contract_test.php`.

Required behavior:

| Condition | Required result |
| --- | --- |
| Valid LLM synthesis | `status=ok`, `generation_mode=llm` |
| LLM invalid/unavailable, usable citations remain | `status=partial`, `generation_mode=metadata_fallback`, visible warning |
| No usable citations and no synthesis | `status=empty` or `error` according to cause |

- [x] Keep deterministic grouping only as an explicitly labeled metadata summary.
- [x] Do not manufacture supported scientific claims from titles alone.
- [x] Preserve citations and enough source context for the final writer to state limitations accurately.
- [x] Test malformed JSON, relay failure, empty citations, and valid synthesis independently.

## Task 5: Unify Mixed-Success Status

**Files:** Add `test/plugin_status_semantics_test.php`; modify shared helper and Graph Plugin first.

- [x] Implement the shared decision table.
- [x] Change Graph mixed success from `error` to `partial` when usable rows exist.
- [x] Audit other plugins for the same pattern, but change only proven cases.
- [x] Confirm errors and warnings remain visible in UI details and LLM context.

## Task 6: Enforce The Native Contract

**Files:** Add `PluginResultContract.php`; modify both current execution sites, `AcademicAgentService.php` and `orchestrator/traits/DeepThinkRoutingTrait.php`, plus focused tests.

- [x] Put validation logic in one shared contract class and invoke it immediately after `run()` at both execution sites, before `augmentPluginResult()`.
- [x] Emit a stable, inspectable contract failure instead of allowing malformed data downstream.
- [x] Avoid plugin-specific validator branches unless the field is genuinely plugin-specific.
- [x] Test wrong names, illegal statuses, malformed evidence, invalid citations, negative latency, and inconsistent success/error combinations.
- [x] Confirm both Agent and DeepThink use the same validator.

## Task 7: Consolidate Shared Projections

**Files:** Modify `PluginResultEnvelope.php`, Agent/DeepThink result traits, and add `test/plugin_payload_projection_test.php`.

- [x] Inventory which fields are consumed by final Writing, ExecutingReview, UI inspection, graph details, citations, and debug logs.
- [x] Define named limits for row counts, text lengths, citation lists, and sequence previews.
- [x] Consolidate only genuinely identical Agent/DeepThink projection logic.
- [x] Preserve a complete canonical result for in-process evidence aggregation.
- [x] Preserve full sequence access, graph drill-down, citation links, and existing inspector behavior.
- [x] Add snapshot-like tests for compact and detailed views.

## Task 8: Reduce Streamed Payload Duplication

**Files:** Modify the `tool_result` event builder and related tests/evaluation helpers.

- [x] Measure the baseline by plugin and representation before changing serialization.
- [x] Stop sending equivalent full content simultaneously in `results`, `raw_result`, `compressed_result`, `result_envelope`, and `display_details` unless a consumer demonstrably requires it.
- [x] Keep stable summary, evidence, citation, status, warning, latency, and expansion metadata.
- [x] Verify the browser inspector against real Graph, Literature, Sequence, and Citation results.
- [x] Use 30 percent aggregate reduction as an initial engineering target for the recorded L1HS-style scenario, not as permission to discard required evidence or UI access. If a smaller safe reduction is achieved, record the limiting consumers instead of trimming blindly.

**Rollback boundary:** Payload changes must be independently revertible from evidence and status corrections.

## Task 9: Consider Selective ExecutingReview

This is optional and must not begin until Tasks 1-8 pass. The current per-plugin review may add cost without equal value for deterministic outputs.

Proposed default:

- Skip review: Entity Resolver, Site Navigator, Tree, Genome, Citation Resolver.
- Review when scientifically material: Graph, Graph Analytics, Cypher Explorer, Literature, Literature Reading, Expression.
- Review Sequence only when interpretive structure hints are present; exact sequence retrieval alone does not need LLM review.
- Record `review_status=not_required` when skipped; do not fabricate an LLM review event.

Accept this optimization only if live comparison shows at least three fewer LLM review calls in a representative multi-plugin run without worse evidence integrity, citation mapping, error wording, or final answer quality.

## Task 10: Verify, Document, And Close

- [x] Run all focused PHP tests added by this plan.
- [x] Run the existing Agent/DeepThink PHP contract tests and three front-end contract checks recorded in the 2026-07-28 audit.
- [x] Run the eight existing Python semantic/evaluation tests.
- [x] Run API contract, Neo4j `tekg3`, no-legacy-fallback, and documentation freshness checks.
- [x] Perform live Agent and DeepThink runs only after static/unit checks pass.
- [x] Inspect the browser UI for both modes and record structured observations.
- [x] Update the plugin catalog, API README, intelligent QA handoff, architecture/testing notes, and this execution log.
- [x] Move this plan to `docs/exec-plans/completed/` only after every acceptance criterion has evidence.

---

## Live Evaluation Matrix

Use new dated run directories. Do not overwrite prior raw events.

1. DeepThink: L1HS sequence and source question.
2. DeepThink: LINE-1 disease-association question explicitly requesting papers.
3. Agent: multi-dimensional L1HS research report spanning graph, literature, sequence, and citations.

For each run record:

- selected plugins and why they were selected;
- native plugin statuses and fallback modes;
- plugin latency and node latency;
- number of LLM review calls;
- raw and projected payload sizes per plugin;
- scientific versus diagnostic item counts;
- citation relevance and claim-to-citation mapping;
- whether association language is incorrectly made causal;
- whether expression/data-source failures are described accurately;
- whether representative-locus or representative-sequence limits are explicit;
- final language consistency;
- final UI state and inspector availability.

## Acceptance Criteria

- All twelve public plugins remain registered under the same names.
- Every plugin result passes the same native contract or becomes a visible contract error.
- Alias confidence, navigation, query success, rank, and citation count are not mislabeled as scientific support.
- Literature Reading fallback is explicitly degraded and does not pretend title grouping is LLM synthesis.
- Graph mixed success reports `partial` and retains successful data plus errors.
- Agent and DeepThink aggregate the same scientific evidence semantics.
- Existing deterministic data-source boundaries and `tekg3` runtime constraints remain intact.
- Aggregate streamed tool payload size is measurably reduced without breaking the UI or final evidence. The initial target is 30 percent; a smaller result is acceptable only when the remaining duplication is tied to documented consumers and further reduction would create a regression risk.
- No regression appears in sequence access, graph inspection, citations, stage streaming, cancellation, or final writing.
- Live evaluation artifacts and durable documentation describe the final behavior.

## Reviewer Checklist

- Look for diagnostics leaking back into `EvidencePackage` as high support.
- Look for fallback paths that still return `ok` without an explicit mode.
- Look for a validator that silently invents missing scientific content.
- Look for plugin-specific branching that recreates the current inconsistency inside the contract layer.
- Look for payload trimming that removes data before evidence aggregation.
- Look for Agent/DeepThink divergence after shared helper changes.
- Look for broken graph/citation/sequence inspector behavior.
- Confirm no unrelated stable page or database configuration was touched.

## Verifier Checklist

The verifier must report commands, exit codes, test counts, and any skipped checks. A passing unit suite alone is insufficient; at least one live Agent run and one live DeepThink run must complete through the configured relay before completion is claimed.

Suggested command groups include:

```powershell
php -l api/agent/contracts/PluginResultContract.php
php test/plugin_native_result_contract_test.php
php test/plugin_evidence_semantics_test.php
php test/plugin_status_semantics_test.php
php test/literature_reading_fallback_contract_test.php
php test/plugin_payload_projection_test.php
python scripts/checks/check_api_contracts.py
python scripts/checks/check_neo4j_tekg3.py
python scripts/checks/check_no_legacy_db_fallback.py
python scripts/checks/check_docs_freshness.py
```

The exact existing Agent/DeepThink suite command should be copied from the current testing documentation at execution time rather than reconstructed from memory.

## Rollback Boundaries

- Evidence-semantic changes must be independently revertible from serialization changes.
- Literature fallback/status changes must be independently revertible from selective review.
- Payload projection changes must be independently revertible if the browser inspector loses required detail.
- Selective ExecutingReview must remain a separate final optimization.
- Never roll back by restoring deterministic title-based output as authoritative scientific synthesis.

## Residual Risks

- Some downstream prompts may implicitly depend on current diagnostic items being present in the evidence list; tests must distinguish useful context from scientific support.
- Compact projections can reduce reasoning quality if trimming occurs before claim extraction.
- Historical fixtures may encode old semantics and require compatibility readers rather than destructive rewrites.
- Live relay/model behavior is nondeterministic; compare multiple runs or use stable semantic assertions rather than exact prose.
- The inaccessible `.pytest_cache` observed during the audit is an environment/permission issue. Do not delete it or treat it as a plugin assertion failure without separate authorization.

## Execution Log

- 2026-07-28: Read-only plugin audit completed. Twelve registered/catalog plugins were found structurally usable; six improvement areas above were agreed with the user.
- 2026-07-28: Static verification baseline recorded: 47 PHP files under `api/agent` linted successfully; 23 PHP Agent/DeepThink contract tests, three front-end contract checks, and eight Python semantic/evaluation tests passed. API contracts, Neo4j `tekg3`, and documentation freshness checks passed.
- 2026-07-28: `test/agent_infrastructure_cleanup_test.php` could not complete because an existing `.pytest_cache` directory was inaccessible. No assertion failure was observed.
- 2026-07-28: Relay listener recheck succeeded on `127.0.0.1:18087`; no real model inference was sent during planning.
- 2026-07-28: This plan was prepared. Implementation has not started, and no runtime code was modified by this planning step.
- 2026-07-28: Post-compression plan audit corrected the native twelve-field shape, documented the native `error` versus envelope `failed` distinction, identified the two real plugin execution sites, required reconciliation with older active plans, aligned diagnostics with the existing `EvidencePackage` convention, and changed the 30 percent payload reduction from an unsafe absolute gate to a measured target.
- 2026-07-28: Implemented shared native-result validation, diagnostic evidence semantics, truthful Literature Reading fallback, shared status handling, bounded Agent/DeepThink projections, and selective ExecutingReview. All twelve public plugin names were preserved.
- 2026-07-28: Historical L1HS tool-event representation measured 328,917 characters before projection and 104,788 characters after equivalent projection, a 68.1 percent reduction. Fresh tool events contained one canonical `raw_result` and no duplicate `compressed_result`, `display_details`, or `raw_preview` fields.
- 2026-07-28: Live DeepThink sequence and literature runs completed in 42,032 ms and 46,168 ms. The live Agent L1HS report completed nine plugins and writing in 467,486 ms; five interpretive outputs received review and four deterministic/post-processing outputs skipped review, exceeding the three-call reduction target.
- 2026-07-28: Final verification passed 49 Agent PHP lint checks, 34 PHP Agent/DeepThink tests, three frontend checks, eight Python semantic/evaluation tests, API contracts, Neo4j `tekg3`, the no-legacy-fallback check, documentation freshness, and `git diff --check`. `agent_infrastructure_cleanup_test.php` remains blocked by the pre-existing inaccessible `.pytest_cache`; the unrelated taxonomy truth check reports the existing `index.php` homepage-helper mismatch.
- 2026-07-28: Browser verification confirmed both mode selectors and templates, the canonical Sequence inspector sections, visible failure handling, and no console errors. Successful live event records plus frontend contracts verified Graph, Literature, Sequence, and Citation payload compatibility.
