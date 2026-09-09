# TE-KG AI Handoff

Last reviewed: 2026-07-27

This document gives the next AI maintainer a compact but actionable project
handoff. Use it together with `AGENTS.md`, `ARCHITECTURE.md`, and live code.

## Next Conversation Scope

The next maintainer should treat the following as the active product-editing
scope, in this order unless the user redirects it:

1. `preview.php` and the Graph / Co-expression workspace.
2. `agent.php`, Agent, DeepThink, and their evidence plugins.
3. `download.php` and its public dataset presentation.
4. `about.php` and its user-guide content and presentation.

The other main pages are currently accepted working surfaces. They remain
important sources for the paper and may need factual documentation updates, but
they should not be casually redesigned or refactored:

- Home has the dataset-status charts and the current database overview figure
  at `assets/img/home-database-overview-v1.png`.
- Browse uses the independent MySQL Browse catalog and matching autocomplete.
- Search remains the detail destination for Browse records.
- The visible `Path` page has Table and G6 views with CSV, PNG, and SVG export.
- Expression uses MySQL summaries and a query-aware autocomplete from the same
  TE-name field as its result table.

Always inspect the live dirty diff before editing. The latest UI cleanup is
uncommitted and is user work that must not be reverted.

## What This Project Is

TE-KG is a local web database for transposable element knowledge exploration.
It combines root-level PHP pages, browser JavaScript, Neo4j graph data, MySQL
expression summaries, local data files, and an Agent/DeepThink question-answering
subsystem.

The project is not a single-page application. Runtime pages still live in the
repository root. The main user-facing pages are:

- `index.php`: homepage and dataset overview.
- `browse.php`: TE browse and entity lookup.
- `preview.php`: graph workspace using G6 and iframe-based graph rendering.
- `path_finder.php`: the user-facing `Path` search and path graph display.
- `expression.php` / `expression_detail.php`: expression data views.
- `agent.php`: Agent and DeepThink entrypoint.
- `download.php`: download/catalog page.

## Runtime State

- Neo4j runtime target: `tekg3`.
- Runtime configuration: `api/runtime_config.php`, `api/config.local.php`.
- PHP path helper: `path_config.php`.
- Browser path helper: `assets/js/tekg_paths.php`.
- Python path helper: `scripts/path_helpers.py`.
- TE taxonomy runtime truth: Neo4j/API via `api/taxonomy.php`.
- Expression runtime root: `data/bulk_expression_web`.
- Expression Keyword autocomplete: query-aware MySQL catalog from
  `expression_browse_summary` via `api/expression_catalog.php`; it does not use
  the Neo4j taxonomy list.
- Browse catalog runtime: MySQL `tekg_catalog` via `api/browse.php`; the Browse
  table and its autocomplete share the same active 276-row catalog version.

Do not restore old runtime fallbacks or old expression roots without a dedicated
migration plan and verification.

## Major Subsystems

### Recently Stabilized Non-primary Pages

- Browse runtime data comes from MySQL `tekg_catalog`; its table, filters, and
  Keyword autocomplete share the same active 276-row catalog.
- Expression Keyword autocomplete comes from `api/expression_catalog.php` and
  is intentionally independent of the Neo4j taxonomy autocomplete.
- Path has compact Table / Graph controls, relation-label control, and CSV,
  PNG, and SVG graph exports. It defaults to three hops and supports up to ten.
  Direct and multi-hop results both show compact path strips; relationship
  evidence starts collapsed with an animated disclosure, and Graph edges reuse
  the shared floating evidence card. Returned routes cannot revisit an entity;
  higher-depth traversal is bounded by a 15-second request-wide query budget,
  and autocomplete labels its safety-oriented count as shortest paths. If the
  resolved shortest route is deeper than three hops, Path returns that route;
  otherwise supplemental alternatives are enumerated only through hop three.
  Truncated status copy reports the actual `searched_through_hop` boundary.
- Browse, Expression, Path, Download, and About no longer show the former
  introductory paragraph and breadcrumb block. Their page titles use a common
  64px gap before the main content.
- Browse-style command controls use a 6px corner radius. Download category
  buttons and the Expression detail search field now follow that treatment.

These pages can be consulted while writing the paper. Unless a concrete bug or
content correction is reported, keep future implementation focused on Graph,
Agent, Download, and About.

### Graph Runtime

The main graph frontend is under `assets/js/renderers/g6/`. The current evidence
graph supports G6 rendering, relation legends, Expand/Jump workflows, evidence
inspection, export behavior, and Agent/DeepThink-driven graph actions.

The user considers the main Graph interaction work broadly complete. Future
Graph work should be incremental and evidence-driven rather than a renderer
rewrite. Preserve the accepted drag behavior, loader behavior, exact TE route
handoff, separate Knowledge / Co-expression G6 instances, legend interactions,
and shared SVG export contract.

Dynamic Knowledge Graph navigation is now parent-owned. Search and typed node
Jump requests enter `navigateGraph`; URL identity, search controls, parent
elements, relation legend metadata, iframe rendering, and page Back history are
committed only after the request succeeds. Superseded loads cannot attach old
handlers to a newer iframe graph, and failed loads restore the last stable
dynamic or taxonomy surface. Browser Back/Forward restores the routed entity
without retaining misleading page-level Back entries. Preserve this contract
when adding Agent-driven navigation or disease classification Graph mode. The
legend, relation, and Min PMID controls remain disabled during a semantic
navigation so a cache redraw cannot supersede the incoming entity request. The
  focused regressions are
  `scripts/checks/check_graph_canonical_navigation_contract.py` and
  `scripts/checks/check_graph_canonical_navigation_browser.js`.

Preview routes now preserve the accepted TE taxonomy and applied Graph view
state. `tree=all|rmsk_repbase` selects the taxonomy source;
`taxonomy=graph` selects Canvas Graph and is omitted for the default Tree.
Dynamic entity routes use `nodes`, `relations`, and `min_pmids` only for
user-applied nondefault filters. An applied empty node or relation selection is
encoded as `none`; all-applied and Min PMID `0` are omitted. The URL, not
session storage, is the durable truth for applied filters, while unapplied
checkbox/input drafts remain local and disappear on refresh. Restore filters
only after the active graph's node and relation catalogs exist. Preserve the
atomic taxonomy transition and filter rollback behavior covered by
`scripts/checks/check_graph_route_view_state_contract.py` and
`scripts/checks/check_graph_route_view_state_browser.js`.

Graph assistant answers no longer reverse-drive the visible graph from each
raw tool result. The experimental structured answer-subgraph implementation is
retained internally, but `ANSWER_GRAPH_ACTION_ENABLED` is false because entity
recognition is not yet precise enough for users. Do not expose `View answer
graph`, its counts, or any automatic `tool_result` graph loading until that
recognition has a separate reviewed fix. The Agent page Graph Plugin inspector
remains its separate, already user-initiated popup.
Regressions are `scripts/checks/check_graph_answer_subgraph_contract.py` and
`scripts/checks/check_graph_answer_subgraph_browser.js`.

Disease classification now has the same routed Tree/Graph display choice as TE
classification. Its stable route is
`q=<class>&type=disease_class&class=<class>` with `taxonomy=graph` only for the
Canvas view. It continues to consume the existing Graph API hierarchy payload;
`assets/js/renderers/canvas-force/disease-classification-adapter.js` adapts that
payload to the shared Canvas force core without introducing another truth
source. DiseaseClass and DiseaseCategory nodes do not navigate. Clicking a
concrete Disease uses canonical typed Jump, while dragging never activates it.
The disease Canvas export carries disease-specific node types and metadata.
Preserve this contract and the bounded nature of the current hierarchy payload.
Focused regressions are
`scripts/checks/check_disease_classification_canvas_contract.py` and
`scripts/checks/check_disease_classification_canvas_browser.js`.

Knowledge Graph and Co-expression SVG exports share
`assets/js/renderers/g6/g6-svg-export.js`, but each workspace supplies its own
filtered snapshot, final G6 positions, and domain styling. They still use
separate G6 instances and iframe bridges.

Important files include:

- `assets/js/renderers/g6/index-g6-shared.js`
- `assets/js/renderers/g6/index-g6-runtime.js`
- `assets/js/renderers/g6/index-g6-embed.js`
- `assets/js/renderers/g6/index-g6.bootstrap.js`
- `assets/js/renderers/g6/default-tree-mindmap.js`
- `api/graph.php`
- `api/graph_service.php`

When changing G6 behavior, verify API payloads, iframe bridge behavior, loader
state, legend state, and browser rendering before changing visual logic.

### Taxonomy Graph Runtime And Archived Experiments

The current classification runtime defaults to the existing G6 Tree and offers
an optional native Canvas Graph through the adjacent `Tree | Graph` switch.
Search and Export remain visible in classification mode; Show relations is
hidden. The Canvas Graph always shows Class/Order labels and does not expose the
standalone demo's label checkbox, Restart, or Fit controls.

- `assets/js/renderers/g6/large-force-graph/`
  - Archived G6-based lightweight large-graph experiment.
  - It was connected to taxonomy Graph in an earlier attempt but is not loaded
    by the current runtime.
  - User feedback: it did not visually diverge enough from the old taxonomy G6
    graph and did not match the desired "space / star and planets" feel.

- `taxonomy_canvas_demo.php` and `assets/js/renderers/canvas-force/`
  - `taxonomy_canvas_demo.php` remains the standalone interaction reference.
  - `taxonomy-canvas-renderer.js` is the production renderer and fetches only
    `api/taxonomy.php?view=tree`.
  - The production layout groups by depth-1 Class. Bounded square-root
    descendant mass assigns about 57% / 35% / 8% to Retrotransposons / DNA
    Transposons / Others for `rmsk_repbase`, and about 56% / 34% / 10% for
    `all`. Depth-2 galaxies use bounded descendant scaling; singleton Others
    galaxies are compactly packed without hiding or aggregating nodes.
  - Dragging reheats the force layout and affects neighboring nodes. Pointer
    release clears persistent selection while hover focus remains available.

The pre-Canvas large-force history and resumption rules remain recorded in
`docs/eval/runs/2026-07-19-all-te-classification-pre-dynamic-rebase/ARCHIVE_REPORT.md`.

### Co-expression Runtime

Co-expression is now an integrated, independently rendered mode in
`preview.php`. It deliberately does not reuse the Knowledge Graph G6 instance.
The two workspaces share page-level placement and interaction conventions but
retain separate iframes, bridges, G6 state, Loader DOM state, details, legends,
filters, caches, and exports. The Loader renderer itself is shared through
`assets/js/pages/preview/te-loader.js` so the same TE uses the same animation in
both modes.
Loader mechanism selection is taxonomy-backed through
`api/taxonomy.php?view=loader_kinds&source=rmsk_repbase`: descendants of
`Class I: Retrotransposons` use the retrotransposition animation, descendants
of `Class II: DNA Transposons` use the DNA transposition animation, and
`Others` uses the default Loader. Name heuristics are only a compatibility
fallback when the existing taxonomy tree cannot resolve a TE; they must not
become a second taxonomy truth source. Both Knowledge Graph TE searches and
Co-expression loads must await this resolver before showing the mechanism
Loader; otherwise names such as AluJb incorrectly flash the default spinner.
Co-expression may expose different internal loading states for diagnostics, but
its public Loader copy is always `Loading <TE> co-expression network...`, and
the mechanism animation label must always use the TE name rather than stage
words such as Preparing or Rendering.

Graph request liveness is bounded in the browser: main graph/network requests
time out after 15 seconds, optional renderer support data after 6 seconds, and
Co-expression Expression activity after 8 seconds. Co-expression deactivation
also restores the last stable state synchronously so a superseded mode switch
cannot leave the hidden workspace in `loading-*` indefinitely.

As of 2026-07-26, `preview-workspace-mode.js` is the canonical owner of Preview
routes and exact TE handoff. Knowledge search, Co-expression search, context
changes, mode switches, refresh, and Back/Forward keep the URL and active graph
in sync. A live Knowledge iframe/G6 instance is reused when the TE changes;
changing a query must not reset its `src`. Co-expression filters use G6 element
visibility rather than rebuilding the graph, and legend rows support the same
click-to-focus interaction model as Knowledge. LINE1 remains an exact
unavailable Co-expression selection and is never aliased to L1HS.

As of 2026-07-26, every Co-expression TE or Gene with available Expression data
uses a stable ripple node while the Expression layer is enabled. Expression
strength controls ring opacity, count, and radius. Hover and selection must not
switch between circle and ripple node types or call `graph.draw()` merely
because the pointer left a node; this keeps native `drag-element-force` active
until mouse release and matches Knowledge Graph drag behavior. Nodes without
Expression data remain non-ripple nodes and report the missing value explicitly.

Runtime data is MySQL-only:

- `api/coexpression.php`
- `api/coexpression_repository.php`
- `templates/preview/coexpression_workspace.php`
- `assets/js/pages/preview/coexpression-mode.js`
- `assets/js/pages/preview/preview-workspace-mode.js`
- `assets/js/renderers/g6/coexpression/`

Offline JSON/TSV/manifests under `data/coexpression/` remain scientific
provenance and importer inputs. Browser/API runtime must not read them.

The current main analysis standard is:

- Network filter: `abs0.4_fdr0.05`
- Module result: `v1_abs0.4_fdr0.05_res1.8`
- Runtime display unit: one selected TE or Gene and one context class, capped at
  50 nodes and 150 edges
- Summary tables: `all_te_quality_summary.tsv`,
  `display_tier_recommendations.tsv`

Communication boundary:

- Co-expression is correlation only.
- Module enrichment explains the gene context of a module; it does not prove TE
  regulation or causality.
- Unknown and unavailable TE/context selections never fall back to `L1HS`.
- Expression activity belongs only to Co-expression. It is requested in a
  batched TE-and-Gene layer from `api/graph_expression.php`, uses measured MySQL
  summaries for both entity types, and never changes correlation edges or force
  parameters.
- Representative acceptance cases are `L1HS`, `LTR5`, `MER11B`, `HERVH-int`,
  and `CR1`, including the unavailable `CR1 / cancer_cell_line` case.

The durable browser contract and test matrix are in
`docs/coexpression/frontend_contract.md`.

### Agent and DeepThink

The Agent/DeepThink subsystem lives under `api/agent/` and `agent.php`.
It is part of the next AI maintainer's scope. Non-agent tasks should not modify
it by default, but Agent/DeepThink tasks should be handled by the next AI after
reading the dedicated handoff and plugin catalog.

If a task explicitly involves Agent or DeepThink, read:

- `api/README.md`
- `api/docs/intelligent_qa_handoff.md`
- `api/docs/intelligent_qa_architecture.md`
- `api/agent/plugins/PLUGIN_CATALOG.md`
- `api/agent/plugins/README.md`
- `docs/eval/runs/`

Current broad direction:

- Agent and DeepThink expose visible thinking/status streams.
- Plugin descriptions are important and should remain visible to planning
  prompts.
- Writing must be model-generated where specified; deterministic fallback
  answers must not mask model-stage failures.

No Agent/DeepThink runtime code was changed during the latest Browse,
Expression, Path, Download, and About UI pass. The dedicated intelligent QA
handoff was last deeply audited before this transition, so verify live model
configuration, endpoints, prompts, and tests before making behavioral claims.

### Download and About

These are active next-session presentation surfaces:

- `download.php` currently exposes category filters and the public download
  catalog. Its old introductory paragraph and breadcrumb block were removed;
  category controls use the shared 6px corner treatment.
- `about.php` now starts with the `About` title and then the guide layout. The
  previous breadcrumb and large introductory marketing block were removed.
- Future edits should improve real public content and information hierarchy,
  not restore placeholder copy or oversized rounded controls.
- Keep dataset descriptions and About claims aligned with actual runtime data,
  source provenance, and paper-ready terminology.

## Documentation Policy

- New project Markdown should be English.
- `AGENTS.md` should stay short and index-like.
- Detailed handoff material should be in this file or linked architecture docs.
- Execution plans belong in `docs/exec-plans/`.
- Evaluation evidence belongs in `docs/eval/runs/`.
- Runtime Chinese prompt assets under `api/prompts/zh*` and
  `api/prompts/fallback_zh*` are functional assets and should not be translated
  as ordinary documentation.
- External reference corpora under `reference/` should not be translated or
  cleaned as project documentation.
- Historical Chinese or mixed-language Markdown should be treated as
  low-priority archive/background material. It is not a blocker for the current
  handoff cleanup unless a future task depends on a specific file.

## Cleanup State

Completed in the 2026-07-14 cleanup pass:

- Rewrote `AGENTS.md` in English as a short AI entry and index.
- Rewrote `ARCHITECTURE.md` in English as the root architecture entry.
- Added this handoff document.
- Rewrote key current architecture docs in English:
  - `docs/architecture/current_system.md`
  - `docs/architecture/index.md`
  - `docs/architecture/data_sources.md`
  - `docs/architecture/database_contract.md`
  - `docs/architecture/frontend_contract.md`
  - `docs/architecture/graph_runtime.md`
- Rewrote `docs/exec-plans/README.md`, `scripts/README.md`,
  `docs/coexpression/README.md`, and `api/agent/plugins/README.md` in English.
- Added the isolated intelligent QA documentation set under `api/README.md` and
  `api/docs/`.
- Removed the zero-byte `api/agent/agent.md`.
- Removed temporary `tmp/` screenshot artifacts.

Known remaining work:

- Historical Chinese or mixed-language Markdown files still exist in
  `docs/architecture/`, `docs/design-docs/`, `docs/exec-plans/`,
  `docs/coexpression/`, and course/paper/proposal folders.
- Ignore these files for routine handoff cleanup. Do not bulk-delete them.
  Translate or summarize durable decisions into English only when a future task
  needs that specific history.

## Current Dirty Worktree Warning

At the 2026-07-27 transition, the working tree contains active user-approved
changes. Important groups include:

- Expression MySQL autocomplete work: `api/expression_catalog.php`, repository
  and frontend integration, plus static/browser checks.
- Page-header cleanup and shared spacing in Browse, Expression, Path, Download,
  and About.
- Download category-button and Expression-detail search corner adjustments.
- Documentation updates in `AGENTS.md`, this handoff, and current architecture.

This list is a scope guide, not permission to restore files wholesale. Read
`git status` and the relevant diffs, preserve unrelated user changes, and undo
only a precisely identified change when explicitly requested.

## Harness Engineering Rules

Use harness engineering for complex tasks:

- Main AI: coordinates, scopes, plans, delegates, integrates, and verifies.
- Explorer: read-only repository investigation.
- Worker: bounded implementation with explicit file ownership.
- Reviewer: bug, architecture, and regression-risk review.
- Verifier: command execution and evidence capture.

Subagent output should not remain only in chat. Convert useful results into
plans, documentation, tests, scripts, or durable handoff notes.

## Recommended Next Tasks

1. Ask the user which of Graph, Agent, Download, or About is the first target;
   read only that subsystem's current docs and live code.
2. For Graph, preserve the accepted dual-mode runtime and diagnose through API,
   bridge, loader, legend, and browser state before renderer edits.
3. For Agent/DeepThink, read `api/README.md`, the intelligent QA handoff, and
   the plugin catalog, then re-audit live model configuration before planning.
4. For Download and About, improve public content and hierarchy while keeping
   the current compact header and 6px control language.
5. Treat Home, Browse, Search, Path, and Expression as stable reference pages
   unless the user reports a specific issue. Use them when preparing the paper,
   but avoid unrelated implementation churn.

## Minimum Verification Set

Use the relevant subset for the task:

```powershell
php -l preview.php
php -l api/graph.php
php -l api/graph_service.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_static_contract.py
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_coexpression_task8_static.py
python scripts/checks/check_coexpression_task8_browser.py
python scripts/checks/check_coexpression_task9_static.py
python scripts/checks/check_coexpression_task9_browser.py
python scripts/checks/check_preview_dual_mode_parity_static.py
python scripts/checks/check_preview_dual_mode_navigation_browser.py
python scripts/checks/check_coexpression_legend_filter_loader_browser.py
python scripts/checks/check_preview_assistant_workspace_bounds.py
python scripts/checks/check_te_loader_taxonomy_contract.py
python scripts/checks/check_te_loader_taxonomy_browser.py
python scripts/checks/check_taxonomy_runtime_truth.py
python scripts/checks/check_no_legacy_db_fallback.py
```

If `check_taxonomy_runtime_truth.py` fails, inspect whether the failure is a
pre-existing `index.php` taxonomy-helper issue or caused by current changes.
The latest full Co-expression acceptance evidence is under
`docs/eval/runs/2026-07-25-coexpression-dual-mode/`.
