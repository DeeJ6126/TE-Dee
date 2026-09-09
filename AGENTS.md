# TE-KG AI Entry

This is the short entry page for AI maintainers. It is an index, not a full
project encyclopedia. Read the linked documents before making broad changes.

## Project Overview

- TE-KG is a local PHP + browser JavaScript + Neo4j + MySQL project.
- Runtime pages still live in the repository root, including `index.php`,
  `browse.php`, `preview.php`, `expression.php`, and `path_finder.php`.
- The current Neo4j runtime target is `tekg3`; local runtime configuration
  comes from `api/config.local.php` and `api/runtime_config.php`.
- Agent and DeepThink are also maintained by the next AI. For ordinary database
  tasks, avoid touching them by default; for Agent/DeepThink tasks, read the
  dedicated handoff and plugin catalog first.

## Current Directory Map

- `api/`: PHP APIs, graph services, taxonomy/expression endpoints, and the
  Agent/DeepThink subsystem under `api/agent/`.
- `assets/`: browser JavaScript, CSS, HTML iframes, local vendors, and graph
  renderers.
- `data/`: runtime data, processed caches, bulk expression data, taxonomy
  assets, logs, and co-expression outputs.
- `docs/`: architecture notes, execution plans, evaluation records, paper
  materials, co-expression notes, and handoff documents.
- `imports/`: Neo4j import history and data import materials.
- `reference/`: external examples, papers, format references, and source
  material used for design comparison.
- `scripts/`: checks, data processing scripts, import helpers, and offline
  analysis tools.
- `templates/`: shared PHP templates.
- `test/`: local test and smoke-test helpers.

## Read First

1. `AI_HANDOFF.md`
2. `docs/architecture/index.md`
3. `docs/architecture/current_system.md`
4. `ARCHITECTURE.md`
5. `docs/exec-plans/README.md`
6. If the task explicitly involves Agent or DeepThink, then also read
   `api/README.md`, `api/docs/intelligent_qa_handoff.md`, and
   `api/agent/plugins/PLUGIN_CATALOG.md`.

## Core Runtime Entrypoints

- Pages: `index.php`, `browse.php`, `preview.php`, `expression.php`,
  `expression_detail.php`, `path_finder.php`, `agent.php`, `download.php`.
- Graph API: `api/graph.php`, `api/graph_service.php`.
- Graph frontend: `assets/js/renderers/g6/`.
- Taxonomy API: `api/taxonomy.php`.
- Expression API: `api/expression_data.php`, `api/expression_repository.php`,
  `api/expression_catalog.php`.
- Browse catalog API: `api/browse.php`, backed by MySQL `tekg_catalog`.
- Path helpers: `path_config.php`, `assets/js/tekg_paths.php`,
  `scripts/path_helpers.py`.

## Hard Constraints

- Do not restore runtime fallback to `tekg2` or `tekg21`.
- Do not add a second TE taxonomy runtime truth source. Runtime taxonomy truth
  must remain Neo4j/API-backed.
- Do not restore `data/raw/new_data/bulk_expression_web` as the expression
  runtime root. The active root is `data/bulk_expression_web`.
- Do not restructure root runtime pages without dedicated verification.
- When fixing G6, inspect API payloads, iframe bridge behavior, loader state,
  legend state, and browser rendering before changing renderer logic.
- Preserve user work. The current working tree may contain in-progress
  experiments and course/paper artifacts.

## Current Work State

- A co-expression backend pipeline exists under `scripts/coexpression/` and
  `data/coexpression/`. Co-expression is also integrated into `preview.php` as
  an independent MySQL-backed G6 workspace beside Knowledge Graph mode; the
  offline files remain provenance and importer inputs.
- A G6-based `large-force-graph` experiment and a separate Canvas taxonomy demo
  are currently present as in-progress graph-rendering experiments.
- The Canvas taxonomy demo is experimental and isolated; it should not be
  treated as production runtime until explicitly integrated.
- Some historical Markdown files are still Chinese or mixed-language. Treat them
  as low-priority archive/background material rather than cleanup blockers. New
  project documentation should be written in English. Runtime Chinese prompt
  assets under `api/prompts/zh*` and `api/prompts/fallback_zh*` are functional
  assets and should not be translated.

## Harness Engineering Workflow

- For complex work, create or update an execution plan in
  `docs/exec-plans/active/` before implementation.
- Use subagents for complex tasks when available:
  - Explorer: read-only investigation.
  - Worker: bounded implementation with explicit file ownership.
  - Reviewer: bug and architecture-risk review.
  - Verifier: command execution and evidence capture.
- The main AI coordinates: it defines scope, assigns tasks, reviews outputs,
  integrates results, and records durable decisions.
- Subagent outputs should become repository assets when useful: execution plans,
  handoff notes, tests, scripts, or updated documentation.
- Move completed plans to `docs/exec-plans/completed/` and record verification.
- Record structural risks in `docs/exec-plans/tech-debt-tracker.md`.

## Common Checks

```powershell
php -l preview.php
php -l api/graph.php
php -l api/graph_service.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_runtime_db_config.py
python scripts/checks/check_neo4j_tekg3.py
python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_no_legacy_db_fallback.py
python scripts/checks/check_taxonomy_runtime_truth.py
python scripts/checks/check_g6_static_contract.py
python scripts/checks/check_g6_no_legacy_disease_node.py
python scripts/checks/check_docs_freshness.py
python scripts/checks/check_g6_relation_legend_expand_mode.py
python scripts/checks/check_g6_legend_expand_tree_fixes.py
```

## Documentation Index

- Architecture index: `docs/architecture/index.md`
- Next-session handoff: `AI_HANDOFF.md`
- Intelligent QA entry: `api/README.md`
- Execution plans: `docs/exec-plans/`
- Co-expression notes: `docs/coexpression/`
- Evaluation records: `docs/eval/runs/`
- Paper/course artifacts: `docs/paper/`, `docs/ppt/`, `docs/proposal/`

## Next Recommended Work

1. The next active surfaces are Graph / Co-expression, Agent / DeepThink,
   Download, and About. Ask the user which one to address first.
2. Treat Home, Browse, Search, Path, and Expression as stable reference pages
   unless the user reports a scoped bug; they remain useful for paper writing.
3. Before Agent/DeepThink changes, read `api/README.md`,
   `api/docs/intelligent_qa_handoff.md`, and the plugin catalog.
