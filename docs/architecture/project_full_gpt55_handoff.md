# TE-KG Full Project Handoff for a New Codex Session

## 1. Purpose

This document is a **full-project handoff package** for a new Codex conversation.

It is broader than:

- [agent_gpt55_handoff.md](/D:/wamp64/www/TE-/docs/architecture/agent_gpt55_handoff.md)

That agent handoff is intentionally focused on:

- `Deep Think`
- `Agent`
- plugin execution
- async orchestration
- LLM invocation

This new handoff is for the **entire project**.

It should let a new Codex session:

1. read this file
2. scan the codebase on its own
3. verify the current structure and runtime state
4. develop a sufficiently deep understanding of the project before making changes

Important scope note:

- This document still mentions the intelligent-agent subsystem because it is part of the project.
- But it is **not primarily an agent-focused handoff**.
- Agent-specific deep work should continue to rely on:
  - [agent_gpt55_handoff.md](/D:/wamp64/www/TE-/docs/architecture/agent_gpt55_handoff.md)

## 2. Project Root and Environment

Project root:

- `D:\wamp64\www\TE-`

Environment:

- Windows
- WAMP-style local PHP app
- local Neo4j
- local MySQL for expression-related data
- browser frontend with PHP-rendered pages and static JS/CSS assets

This is not a single-purpose frontend app.

It is a mixed system combining:

- PHP website pages
- backend APIs
- graph-backed search and visualization
- local data assets
- local build/normalization/import scripts
- TE taxonomy assets
- AI-assisted question answering

That mixed nature is the main reason the repo can feel structurally dense.

## 3. What the Project Is

TE-KG is a local web application for:

- exploring transposable-element knowledge
- browsing graph-backed TE entities and relations
- viewing genomic, expression, and epigenetics information
- downloading data
- running AI-assisted reasoning over the TE graph

User-facing entry points currently live at project root:

- [index.php](/D:/wamp64/www/TE-/index.php)
- [search.php](/D:/wamp64/www/TE-/search.php)
- [browse.php](/D:/wamp64/www/TE-/browse.php)
- [preview.php](/D:/wamp64/www/TE-/preview.php)
- [genomic.php](/D:/wamp64/www/TE-/genomic.php)
- [expression.php](/D:/wamp64/www/TE-/expression.php)
- [expression_detail.php](/D:/wamp64/www/TE-/expression_detail.php)
- [epigenetics.php](/D:/wamp64/www/TE-/epigenetics.php)
- [download.php](/D:/wamp64/www/TE-/download.php)
- [about.php](/D:/wamp64/www/TE-/about.php)
- [agent.php](/D:/wamp64/www/TE-/agent.php)
- [jbrowse.php](/D:/wamp64/www/TE-/jbrowse.php)
- [repbase_structure_svg.php](/D:/wamp64/www/TE-/repbase_structure_svg.php)

Global bootstrap/layout helpers at root:

- [path_config.php](/D:/wamp64/www/TE-/path_config.php)
- [site_i18n.php](/D:/wamp64/www/TE-/site_i18n.php)
- [head.php](/D:/wamp64/www/TE-/head.php)
- [foot.php](/D:/wamp64/www/TE-/foot.php)

## 4. Current Top-Level Structure

Current top-level layout is already partially reorganized.

Long-lived top-level areas:

- `api/`
- `assets/`
- `data/`
- `docs/`
- `imports/`
- `lab/`
- `reference/`
- `scripts/`
- `templates/`
- `archive/`
- `test/`
- `.vscode/`

This is materially different from the older layout.

Recent structure cleanup already moved:

- experimental pages into `lab/`
- taxonomy assets under `data/taxonomy/`
- terminology under `data/terminology/`
- many scripts into grouped subfolders under `scripts/`
- import artifacts into grouped subfolders under `imports/`

Do not assume old path references in historical notes are still current.

## 5. Runtime Entry Pages

The project root intentionally still contains runtime entry pages.

That is a deliberate current choice, not an accident.

The user-facing PHP entry layer remains at root in this pass.

Important examples:

### Homepage

- [index.php](/D:/wamp64/www/TE-/index.php)

Current role:

- homepage overview
- quick links
- dataset status ring chart
- embedded graph preview via iframe

Important current homepage behavior:

- homepage taxonomy/ring data comes from:
  - [tekg3_homepage_taxonomy.json](/D:/wamp64/www/TE-/data/processed/tekg3_homepage_taxonomy.json)
- the embedded graph preview uses:
  - [preview_graph.html](/D:/wamp64/www/TE-/assets/html/preview_graph.html)

### Graph / TE-KG Preview

- [preview.php](/D:/wamp64/www/TE-/preview.php)

This is a core graph-view entry point and should be treated as product runtime, not experimental code.

### Search / Browse / Domain Views

- [search.php](/D:/wamp64/www/TE-/search.php)
- [browse.php](/D:/wamp64/www/TE-/browse.php)
- [genomic.php](/D:/wamp64/www/TE-/genomic.php)
- [expression.php](/D:/wamp64/www/TE-/expression.php)
- [expression_detail.php](/D:/wamp64/www/TE-/expression_detail.php)
- [epigenetics.php](/D:/wamp64/www/TE-/epigenetics.php)
- [download.php](/D:/wamp64/www/TE-/download.php)

### AI Entry

- [agent.php](/D:/wamp64/www/TE-/agent.php)

The new session should know it exists, but should use the dedicated agent handoff for deep agent work.

## 6. API Layer

Main API directory:

- [api](/D:/wamp64/www/TE-/api)

Top-level API files:

- [agent.php](/D:/wamp64/www/TE-/api/agent.php)
- [agent_runs.php](/D:/wamp64/www/TE-/api/agent_runs.php)
- [agent_run_execute.php](/D:/wamp64/www/TE-/api/agent_run_execute.php)
- [agent_run_kickoff.php](/D:/wamp64/www/TE-/api/agent_run_kickoff.php)
- [agent_run_status.php](/D:/wamp64/www/TE-/api/agent_run_status.php)
- [agent_run_worker.php](/D:/wamp64/www/TE-/api/agent_run_worker.php)
- [agent_stream.php](/D:/wamp64/www/TE-/api/agent_stream.php)
- [agent_workflow_lab.php](/D:/wamp64/www/TE-/api/agent_workflow_lab.php)
- [config.local.php](/D:/wamp64/www/TE-/api/config.local.php)
- [deep_think_stream.php](/D:/wamp64/www/TE-/api/deep_think_stream.php)
- [expression_data.php](/D:/wamp64/www/TE-/api/expression_data.php)
- [graph.php](/D:/wamp64/www/TE-/api/graph.php)
- [health.php](/D:/wamp64/www/TE-/api/health.php)
- [qa.php](/D:/wamp64/www/TE-/api/qa.php)
- [te_metrics.php](/D:/wamp64/www/TE-/api/te_metrics.php)

Important subdirectories:

- [api/agent](/D:/wamp64/www/TE-/api/agent)
- [api/prompts](/D:/wamp64/www/TE-/api/prompts)

`api/agent/` contains:

- orchestration core
- plugin implementations
- routing/config JSON
- evaluation assets

Important agent subfiles:

- [bootstrap.php](/D:/wamp64/www/TE-/api/agent/bootstrap.php)
- [agent.md](/D:/wamp64/www/TE-/api/agent/agent.md)
- [agent_routing_policy.json](/D:/wamp64/www/TE-/api/agent/config/agent_routing_policy.json)
- [agent_workflow_lab.json](/D:/wamp64/www/TE-/api/agent/config/agent_workflow_lab.json)
- [entity_alias_map.php](/D:/wamp64/www/TE-/api/agent/config/entity_alias_map.php)

Important orchestrator classes:

- [AcademicAgentService.php](/D:/wamp64/www/TE-/api/agent/orchestrator/AcademicAgentService.php)
- [DeepThinkService.php](/D:/wamp64/www/TE-/api/agent/orchestrator/DeepThinkService.php)
- [LlmClient.php](/D:/wamp64/www/TE-/api/agent/orchestrator/LlmClient.php)
- [Neo4jClient.php](/D:/wamp64/www/TE-/api/agent/orchestrator/Neo4jClient.php)
- [EntityNormalizer.php](/D:/wamp64/www/TE-/api/agent/orchestrator/EntityNormalizer.php)
- [CitationResolver.php](/D:/wamp64/www/TE-/api/agent/orchestrator/CitationResolver.php)

Plugin directory:

- [api/agent/plugins](/D:/wamp64/www/TE-/api/agent/plugins)

## 7. Frontend Asset Layer

Main asset directory:

- [assets](/D:/wamp64/www/TE-/assets)

Subdirectories:

- [assets/css](/D:/wamp64/www/TE-/assets/css)
- [assets/js](/D:/wamp64/www/TE-/assets/js)
- [assets/img](/D:/wamp64/www/TE-/assets/img)
- [assets/data](/D:/wamp64/www/TE-/assets/data)
- [assets/vendor](/D:/wamp64/www/TE-/assets/vendor)

Important frontend path abstraction file:

- [tekg_paths.php](/D:/wamp64/www/TE-/assets/js/tekg_paths.php)

This file is part of the path cleanup work. It exposes browser/runtime path config to JS.

Important frontend page JS worth scanning early:

- [assets/js/pages/index.js](/D:/wamp64/www/TE-/assets/js/pages/index.js)
- [assets/js/pages/agent.js](/D:/wamp64/www/TE-/assets/js/pages/agent.js)
- [assets/js/pages/agent_workflow_lab.js](/D:/wamp64/www/TE-/assets/js/pages/agent_workflow_lab.js)
- [assets/js/tekg_runtime_data.js](/D:/wamp64/www/TE-/assets/js/tekg_runtime_data.js)

Important G6-related frontend code:

- [assets/js/renderers/g6](/D:/wamp64/www/TE-/assets/js/renderers/g6)

New sessions should not assume G6 code is disposable.

The G6 layer is a real runtime dependency for homepage embedding and graph experiences.

## 8. Template Layer

Template directory:

- [templates](/D:/wamp64/www/TE-/templates)

Current visible subdirectory:

- [templates/components](/D:/wamp64/www/TE-/templates/components)

The template layer is lighter than the API/data layers, but still relevant for shared UI structure.

Do not assume the template layer is fully extracted yet. Some shared layout still lives in root PHP files such as:

- [head.php](/D:/wamp64/www/TE-/head.php)
- [foot.php](/D:/wamp64/www/TE-/foot.php)

## 9. Data Layer

Main data directory:

- [data](/D:/wamp64/www/TE-/data)

Current subdirectories:

- [data/archive](/D:/wamp64/www/TE-/data/archive)
- [data/bulk_expression_web](/D:/wamp64/www/TE-/data/bulk_expression_web)
- [data/cache](/D:/wamp64/www/TE-/data/cache)
- [data/dfam](/D:/wamp64/www/TE-/data/dfam)
- [data/JBrowse](/D:/wamp64/www/TE-/data/JBrowse)
- [data/logs](/D:/wamp64/www/TE-/data/logs)
- [data/processed](/D:/wamp64/www/TE-/data/processed)
- [data/raw](/D:/wamp64/www/TE-/data/raw)
- [data/statistics](/D:/wamp64/www/TE-/data/statistics)
- [data/taxonomy](/D:/wamp64/www/TE-/data/taxonomy)
- [data/terminology](/D:/wamp64/www/TE-/data/terminology)

The project now treats taxonomy and terminology as data assets, not root-level pseudo-modules.

That was a deliberate structural decision.

## 10. Taxonomy Layer

Current taxonomy root:

- [data/taxonomy](/D:/wamp64/www/TE-/data/taxonomy)

Subdirectories:

- [data/taxonomy/transposon_tree](/D:/wamp64/www/TE-/data/taxonomy/transposon_tree)
- [data/taxonomy/te_234](/D:/wamp64/www/TE-/data/taxonomy/te_234)
- [data/taxonomy/lineage](/D:/wamp64/www/TE-/data/taxonomy/lineage)

Important files:

### Tree sources

- [tree_all.txt](/D:/wamp64/www/TE-/data/taxonomy/transposon_tree/tree_all.txt)
- [tree_rmsk_repbase.txt](/D:/wamp64/www/TE-/data/taxonomy/transposon_tree/tree_rmsk_repbase.txt)

### 234/226 TE classification assets

- [te_234_template.csv](/D:/wamp64/www/TE-/data/taxonomy/te_234/te_234_template.csv)
- [te_234_template2.xlsx](/D:/wamp64/www/TE-/data/taxonomy/te_234/te_234_template2.xlsx)
- [te_234_classification.csv](/D:/wamp64/www/TE-/data/taxonomy/te_234/te_234_classification.csv)
- [mismatch_case_output.xlsx](/D:/wamp64/www/TE-/data/taxonomy/te_234/mismatch_case_output.xlsx)
- [missing_TE_simple.xlsx](/D:/wamp64/www/TE-/data/taxonomy/te_234/missing_TE_simple.xlsx)
- [te_234_cleanup_summary.json](/D:/wamp64/www/TE-/data/taxonomy/te_234/te_234_cleanup_summary.json)

### Lineage outputs

- [tree_te_lineage.csv](/D:/wamp64/www/TE-/data/taxonomy/lineage/tree_te_lineage.csv)
- [tree_te_lineage.json](/D:/wamp64/www/TE-/data/taxonomy/lineage/tree_te_lineage.json)

Important current reality:

- taxonomy cleanup has already changed the project meaningfully
- naming and classification are no longer purely historical
- new sessions should treat taxonomy as live application context, not just an offline side topic

## 11. Current TE / Neo4j Standardization State

This is one of the most important recent project changes.

### Current default Neo4j target

Configured in:

- [api/config.local.php](/D:/wamp64/www/TE-/api/config.local.php)

Current value:

- `neo4j_url = http://127.0.0.1:7474/db/tekg3/tx/commit`

This means application-side Neo4j calls are currently pointed at:

- `tekg3`

not:

- `tekg21`

### Current TE database strategy

`tekg3` was created as a cleaned target DB based on `tekg21`.

Rules applied:

- `C` class naming issues standardized against:
  - [tree_rmsk_repbase.txt](/D:/wamp64/www/TE-/data/taxonomy/transposon_tree/tree_rmsk_repbase.txt)
- `A` items preserved but aligned to:
  - [tree_all.txt](/D:/wamp64/www/TE-/data/taxonomy/transposon_tree/tree_all.txt)
- `B` items preserved in the graph but marked as non-leaf and excluded from homepage chart counting

Main script:

- [build_tekg3_from_tekg21.py](/D:/wamp64/www/TE-/scripts/normalize/build_tekg3_from_tekg21.py)

Generated outputs:

- [tekg3_taxonomy_standardization_report.json](/D:/wamp64/www/TE-/data/processed/tekg3_taxonomy_standardization_report.json)
- [tekg3_homepage_taxonomy.json](/D:/wamp64/www/TE-/data/processed/tekg3_homepage_taxonomy.json)

New sessions should inspect those outputs directly, not rely only on conversation history.

### Important current counts

These should be verified by the new session, not blindly trusted forever, but they reflect the latest known state:

- total `TE` nodes in `tekg3`: around `225`
- homepage chart uses only classified leaf-standard items
- `B` items remain in graph data but are excluded from homepage pie/ring statistics

### Important practical implication

The homepage chart is no longer just a frontend cosmetic issue.

It is coupled to:

- taxonomy rules
- `tekg3`
- generated processed JSON
- classification semantics

## 12. Homepage Ring Chart State

Homepage page:

- [index.php](/D:/wamp64/www/TE-/index.php)

Homepage JS:

- [assets/js/pages/index.js](/D:/wamp64/www/TE-/assets/js/pages/index.js)

Homepage current ring chart source:

- [tekg3_homepage_taxonomy.json](/D:/wamp64/www/TE-/data/processed/tekg3_homepage_taxonomy.json)

Important current behavior:

- old hardcoded `1140 / 440` style counts are no longer authoritative
- homepage ring chart is data-driven from generated JSON
- ring chart supports drilldown
- example-expansion behavior for side circles was removed

Recent homepage chart debugging involved:

- verifying that `SINEs` should not be collapsed into `Unclassified`
- tracing specific cases such as `MER131`
- correcting TE taxonomy-driven chart output

This area is active and nontrivial.

A new session should verify actual runtime behavior rather than assuming the latest generated JSON and frontend state are perfectly aligned.

## 13. Terminology Layer

Terminology is now located at:

- [data/terminology](/D:/wamp64/www/TE-/data/terminology)

This was part of the folder reorganization.

Any old references to root-level `terminology/` should be treated as historical unless current code confirms otherwise.

Terminology matters to:

- graph UI
- G6 runtime
- search/QA surface naming

## 14. Scripts Layer

Main scripts directory:

- [scripts](/D:/wamp64/www/TE-/scripts)

Current grouped subdirectories:

- [scripts/build](/D:/wamp64/www/TE-/scripts/build)
- [scripts/normalize](/D:/wamp64/www/TE-/scripts/normalize)
- [scripts/export](/D:/wamp64/www/TE-/scripts/export)
- [scripts/import](/D:/wamp64/www/TE-/scripts/import)
- [scripts/eval](/D:/wamp64/www/TE-/scripts/eval)
- [scripts/plot](/D:/wamp64/www/TE-/scripts/plot)

Root-level support modules and operational files:

- [path_helpers.py](/D:/wamp64/www/TE-/scripts/path_helpers.py)
- [semantic_aliases.py](/D:/wamp64/www/TE-/scripts/semantic_aliases.py)
- [disease_top_class.py](/D:/wamp64/www/TE-/scripts/disease_top_class.py)
- [tekg2_entity_overrides.py](/D:/wamp64/www/TE-/scripts/tekg2_entity_overrides.py)
- [llm_relay.py](/D:/wamp64/www/TE-/scripts/llm_relay.py)
- [start_llm_relay.bat](/D:/wamp64/www/TE-/scripts/start_llm_relay.bat)
- [start_neo4j_console.bat](/D:/wamp64/www/TE-/scripts/start_neo4j_console.bat)
- [README.md](/D:/wamp64/www/TE-/scripts/README.md)

Important practical note:

- not every script is equally canonical
- some scripts are generators
- some are one-off normalizers
- some represent the newer `te_kg2` pipeline and some are retained for legacy comparison

The new session should inspect script groupings and actual current references before deciding what is “dead”.

## 15. Imports Layer

Main import directory:

- [imports](/D:/wamp64/www/TE-/imports)

Grouped subdirectories:

- [imports/neo4j](/D:/wamp64/www/TE-/imports/neo4j)
- [imports/disease](/D:/wamp64/www/TE-/imports/disease)
- [imports/te_lineage](/D:/wamp64/www/TE-/imports/te_lineage)
- [imports/merge](/D:/wamp64/www/TE-/imports/merge)

Important structural rule:

- generated import artifacts are outputs, not the canonical place to encode new logic
- if imports need changes, the new session should usually prefer updating generator scripts first

## 16. Lab / Experimental Area

Current lab directory:

- `lab/` has been removed from the formal preview runtime.

Replacement files:

- [preview_graph.html](/D:/wamp64/www/TE-/assets/html/preview_graph.html)
- [preview_g6_embed.html](/D:/wamp64/www/TE-/assets/html/preview_g6_embed.html)

Important caution:

`lab/` is not just trash.

Some lab files still participate in real runtime experiences, especially:

- homepage graph preview iframe
- graph testing and embed behavior

So “lab” means experimental origin, not necessarily “safe to delete”.

## 17. Reference and Documentation Areas

Reference directory:

- [reference](/D:/wamp64/www/TE-/reference)

Docs directory:

- [docs](/D:/wamp64/www/TE-/docs)

Docs subareas:

- [docs/architecture](/D:/wamp64/www/TE-/docs/architecture)
- [docs/course](/D:/wamp64/www/TE-/docs/course)
- [docs/demo](/D:/wamp64/www/TE-/docs/demo)
- [docs/latex](/D:/wamp64/www/TE-/docs/latex)
- [docs/notes](/D:/wamp64/www/TE-/docs/notes)
- [docs/setup](/D:/wamp64/www/TE-/docs/setup)

Important architecture docs worth scanning:

- [agent_gpt55_handoff.md](/D:/wamp64/www/TE-/docs/architecture/agent_gpt55_handoff.md)
- [folder_structure_target.md](/D:/wamp64/www/TE-/docs/architecture/folder_structure_target.md)
- [path_refactor_audit.md](/D:/wamp64/www/TE-/docs/architecture/path_refactor_audit.md)
- [project-structure-audit.md](/D:/wamp64/www/TE-/docs/architecture/project-structure-audit.md)
- [php_root_reorganization_plan.md](/D:/wamp64/www/TE-/docs/architecture/php_root_reorganization_plan.md)
- [repbase_sequence_structure_plan.md](/D:/wamp64/www/TE-/docs/architecture/repbase_sequence_structure_plan.md)
- [jbrowse_recovery_plan.md](/D:/wamp64/www/TE-/docs/architecture/jbrowse_recovery_plan.md)

Important caution:

Some older architecture docs show encoding damage or stale paths.

They can still be useful for intent and history, but they are **not authoritative by themselves**.

The new session must verify current truth in live files.

## 18. Path Abstraction State

Path abstraction has already been implemented in three layers.

### PHP layer

- [path_config.php](/D:/wamp64/www/TE-/path_config.php)

This is now the main PHP path authority.

It defines helpers for:

- app URLs
- asset paths
- API paths
- data paths
- imports paths
- taxonomy paths
- terminology paths
- JBrowse paths
- cache/logs

### Browser/JS layer

- [assets/js/tekg_paths.php](/D:/wamp64/www/TE-/assets/js/tekg_paths.php)

This serves runtime path config to browser code.

### Python/offline layer

- [scripts/path_helpers.py](/D:/wamp64/www/TE-/scripts/path_helpers.py)

This is the shared path entrypoint for Python-side scripts.

Important implication:

- future folder moves should prefer updating these path layers first
- direct string replacement across the repo should no longer be the default first move

## 19. Neo4j Runtime Status and Startup

Neo4j Desktop is not the reliable control plane right now.

Practical working approach:

- start Neo4j directly through:
  - [start_neo4j_console.bat](/D:/wamp64/www/TE-/scripts/start_neo4j_console.bat)

That batch file exists because the DBMS can run correctly even when the Desktop shell is unstable.

Important operational note:

- if the Neo4j console window is closed, the DB stops
- many repo operations implicitly assume the DB is up

New sessions that need graph work should first confirm:

- the Neo4j process is actually running
- `tekg3` is reachable

## 20. What a New Session Should Not Assume

The new session should **not** assume:

1. every doc is current
2. every directory name reflects current reality
3. every `lab/` file is disposable
4. every taxonomy CSV is authoritative
5. homepage chart bugs are purely frontend bugs
6. old agent/plugin conclusions still hold if they depended on a dead Neo4j instance

It should verify.

## 21. Recommended Scan Order for a New Session

The new session should scan in this order.

### Step 1: global structure

Read:

- project root listing
- `docs/architecture/`
- [path_config.php](/D:/wamp64/www/TE-/path_config.php)

Goal:

- understand current structure
- understand path abstraction
- understand which structural cleanup has already happened

### Step 2: runtime entry layer

Read:

- [index.php](/D:/wamp64/www/TE-/index.php)
- [preview.php](/D:/wamp64/www/TE-/preview.php)
- [search.php](/D:/wamp64/www/TE-/search.php)
- [browse.php](/D:/wamp64/www/TE-/browse.php)
- [genomic.php](/D:/wamp64/www/TE-/genomic.php)
- [expression.php](/D:/wamp64/www/TE-/expression.php)
- [expression_detail.php](/D:/wamp64/www/TE-/expression_detail.php)
- [epigenetics.php](/D:/wamp64/www/TE-/epigenetics.php)
- [download.php](/D:/wamp64/www/TE-/download.php)
- [agent.php](/D:/wamp64/www/TE-/agent.php)

Goal:

- understand what the product currently exposes
- understand which pages are truly runtime-critical

### Step 3: asset/runtime JS layer

Read:

- [assets/js/pages/index.js](/D:/wamp64/www/TE-/assets/js/pages/index.js)
- [assets/js/pages/agent.js](/D:/wamp64/www/TE-/assets/js/pages/agent.js)
- [assets/js/tekg_runtime_data.js](/D:/wamp64/www/TE-/assets/js/tekg_runtime_data.js)
- [assets/js/tekg_paths.php](/D:/wamp64/www/TE-/assets/js/tekg_paths.php)
- G6 files under [assets/js/renderers/g6](/D:/wamp64/www/TE-/assets/js/renderers/g6)

Goal:

- understand frontend runtime paths
- understand graph embedding and runtime data loading

### Step 4: API layer

Read:

- [api/config.local.php](/D:/wamp64/www/TE-/api/config.local.php)
- [api/graph.php](/D:/wamp64/www/TE-/api/graph.php)
- [api/expression_data.php](/D:/wamp64/www/TE-/api/expression_data.php)
- [api/te_metrics.php](/D:/wamp64/www/TE-/api/te_metrics.php)
- [api/health.php](/D:/wamp64/www/TE-/api/health.php)

Goal:

- understand backend runtime dependencies
- confirm DB targets and service assumptions

### Step 5: data and taxonomy

Read:

- [data/taxonomy/transposon_tree/tree_rmsk_repbase.txt](/D:/wamp64/www/TE-/data/taxonomy/transposon_tree/tree_rmsk_repbase.txt)
- [data/taxonomy/transposon_tree/tree_all.txt](/D:/wamp64/www/TE-/data/taxonomy/transposon_tree/tree_all.txt)
- [data/processed/tekg3_homepage_taxonomy.json](/D:/wamp64/www/TE-/data/processed/tekg3_homepage_taxonomy.json)
- [data/processed/tekg3_taxonomy_standardization_report.json](/D:/wamp64/www/TE-/data/processed/tekg3_taxonomy_standardization_report.json)
- [data/taxonomy/te_234/te_234_template.csv](/D:/wamp64/www/TE-/data/taxonomy/te_234/te_234_template.csv)

Goal:

- understand current TE naming, chart, and taxonomy semantics

### Step 6: scripts/imports

Read:

- [scripts/README.md](/D:/wamp64/www/TE-/scripts/README.md)
- [scripts/path_helpers.py](/D:/wamp64/www/TE-/scripts/path_helpers.py)
- [scripts/normalize/build_tekg3_from_tekg21.py](/D:/wamp64/www/TE-/scripts/normalize/build_tekg3_from_tekg21.py)
- representative generator scripts in `scripts/build/`, `scripts/import/`, `scripts/export/`
- directory structure under `imports/`

Goal:

- understand how data is produced, normalized, and imported

### Step 7: agent context only as support

Read:

- [docs/architecture/agent_gpt55_handoff.md](/D:/wamp64/www/TE-/docs/architecture/agent_gpt55_handoff.md)

Use it as supporting context, not as the main scope.

## 22. Recommended First Questions for the New Session to Answer

Before changing code, the new session should answer:

1. Which pages are truly runtime-critical today?
2. Which data files are canonical vs. derived?
3. Which taxonomy outputs are authoritative for frontend use?
4. Is `tekg3` already the intended production-like DB target for all runtime features?
5. Which `lab/` files are still runtime-coupled?
6. Which older architecture docs are historical only?
7. Are there any remaining places where old root paths are still assumed?

Those answers should come from file inspection, not only from this document.

## 23. English Prompt for the New Codex Session

Use the following prompt in the new conversation.

```text
You are onboarding to the TE-KG project at:

D:\\wamp64\\www\\TE-

Use Chinese when talking to the user.
Keep code, identifiers, comments, and implementation text as non-Chinese as reasonably possible unless Chinese is already clearly required by an existing file.

Your goal is to understand the whole project, not just the agent subsystem.
You should treat the agent system as one important part of the application, but not the only part.

Clarify that as an AI assistant, you primarily read docs/architecture/project_full_gpt55_handoff.md, while docs/architecture/agent_gpt55_handoff.md is intended for another AI specifically responsible for the agent subsystem. Your main responsibility covers most of the non-agent work in the project, and you are largely not responsible for agent development.

Start by reading:

1. docs/architecture/project_full_gpt55_handoff.md
2. docs/architecture/agent_gpt55_handoff.md

Then independently inspect the live codebase to confirm or correct the handoff.
Do not assume the handoff is perfectly current. Verify everything important in files.

Recommended scan order:

1. Project root structure and docs/architecture
2. path_config.php
3. Root runtime pages:
   - index.php
   - preview.php
   - search.php
   - browse.php
   - genomic.php
   - expression.php
   - expression_detail.php
   - epigenetics.php
   - download.php
   - agent.php
4. Frontend runtime files:
   - assets/js/pages/index.js
   - assets/js/pages/agent.js
   - assets/js/tekg_runtime_data.js
   - assets/js/tekg_paths.php
   - assets/js/renderers/g6/*
5. API/config:
   - api/config.local.php
   - api/graph.php
   - api/expression_data.php
   - api/te_metrics.php
   - api/health.php
   - api/agent/*
6. Data/taxonomy:
   - data/taxonomy/transposon_tree/tree_rmsk_repbase.txt
   - data/taxonomy/transposon_tree/tree_all.txt
   - data/processed/tekg3_homepage_taxonomy.json
   - data/processed/tekg3_taxonomy_standardization_report.json
   - data/taxonomy/te_234/*
7. Scripts/imports:
   - scripts/README.md
   - scripts/path_helpers.py
   - scripts/normalize/build_tekg3_from_tekg21.py
   - representative build/import/export scripts
   - imports/*
8. Lab/reference areas:
   - lab/*
   - reference/*

Important current context:

- This is a local PHP + JS + Neo4j + MySQL project.
- Runtime pages still live at project root by design in the current phase.
- Path abstraction has already been implemented:
  - PHP: path_config.php
  - browser JS: assets/js/tekg_paths.php
  - Python: scripts/path_helpers.py
- Taxonomy and terminology were already moved under data/:
  - data/taxonomy/*
  - data/terminology/*
- Neo4j Desktop is not the reliable control plane right now.
- The working Neo4j target for the application is currently tekg3, configured in api/config.local.php.
- Homepage taxonomy/ring-chart data comes from data/processed/tekg3_homepage_taxonomy.json.
- The TE standardization workflow is encoded in scripts/normalize/build_tekg3_from_tekg21.py.

What you should do first:

1. Confirm the current structure from the filesystem.
2. Confirm the current runtime path model.
3. Confirm whether tekg3 is really the active DB target everywhere important.
4. Confirm which generated files are canonical runtime inputs versus derived outputs.
5. Produce a concise but concrete project understanding report before making changes.
6. Provide some modification suggestions, such as code that can be simplified, potential bugs, etc.



Do not immediately refactor code.
Do not assume any older doc is fully current.
Verify the live state from files and, when appropriate, from runtime behavior.
```

## 24. Final Guidance

This project is best understood as:

- a PHP site
- plus a graph/data platform
- plus a taxonomy-cleanup pipeline
- plus a local AI layer

New sessions usually go wrong when they assume only one of those layers matters.

The right approach is:

1. read this handoff
2. verify the codebase live
3. separate runtime truth from historical notes
4. then change only what is justified by the current files
