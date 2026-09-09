# TE- Folder Structure Target

## Goal

This document records the intended long-term folder structure for the `TE-` project.

The goal is not to make the tree look elegant in isolation. The goal is to make the project:

- easier to navigate
- safer to refactor
- clearer about what is runtime code vs data vs pipeline vs experiments
- closer to the way the project actually works

This target structure is based on the current real contents of the repo, not on a generic frontend template.

---

## Final Target Structure

```text
TE-/
├── index.php
├── search.php
├── browse.php
├── download.php
├── agent.php
├── expression.php
├── expression_detail.php
├── about.php
├── preview.php
├── jbrowse.php
├── genomic.php
├── epigenetics.php
│
├── head.php
├── foot.php
├── path_config.php
├── site_i18n.php
│
├── api/
│   ├── agent/
│   ├── prompts/
│   ├── graph.php
│   ├── qa.php
│   ├── deep_think_stream.php
│   ├── agent_runs.php
│   ├── agent_run_status.php
│   ├── agent_run_worker.php
│   ├── agent_run_execute.php
│   └── ...
│
├── assets/
│   ├── css/
│   ├── js/
│   ├── img/
│   ├── vendor/
│   └── data/
│
├── templates/
│   ├── components/
│   └── layout/
│
├── data/
│   ├── raw/
│   ├── processed/
│   ├── statistics/
│   ├── cache/
│   ├── logs/
│   ├── dfam/
│   ├── JBrowse/
│   ├── bulk_expression_web/
│   ├── terminology/
│   ├── taxonomy/
│   │   ├── transposon_tree/
│   │   ├── te_234/
│   │   └── lineage/
│   └── archive/
│
├── scripts/
│   ├── build/
│   ├── normalize/
│   ├── export/
│   ├── import/
│   ├── eval/
│   └── plot/
│
├── imports/
│   ├── neo4j/
│   ├── disease/
│   ├── te_lineage/
│   └── merge/
│
├── docs/
│   ├── setup/
│   ├── demo/
│   ├── notes/
│   ├── latex/
│   ├── architecture/
│   └── course/
│
├── lab/
│   ├── agent_workflow_lab.php
│   ├── index_g6.html
│   ├── index_g6_embed.html
│   └── tmp_quick_done_test.php
│
├── reference/
│   ├── screenshots/
│   ├── papers/
│   ├── external_examples/
│   └── G6/
│
├── test/
├── archive/
└── .vscode/
```

---

## Structural Principles

### 1. Keep the repo root thin

The root should only contain:

- real user-facing entry pages
- a very small number of global bootstrap files

The root should not remain the default place for:

- experiments
- one-off tests
- migration helpers
- alternative renderer demos
- reference material

### 2. Separate runtime from production pipeline

This project is not just a website. It is:

- a website
- a TE knowledge graph product
- a data processing pipeline
- an agent / plugin system

Because of that, the structure must distinguish:

- runtime web code
- runtime data
- data generation / import pipeline

That is why:

- `api/`, `assets/`, `templates/` are runtime
- `data/` is runtime and product data
- `scripts/` and `imports/` are production pipeline

### 3. Treat TE taxonomy as data, not as a top-level code module

The current `transposon_tree/` directory is not a standalone application module.

It is mainly:

- TE taxonomy trees
- lookup workbooks
- comparison outputs
- taxonomy-oriented helper assets

So it belongs under `data/`, specifically under:

```text
data/taxonomy/transposon_tree/
```

Related 234-TE classification outputs should live nearby:

```text
data/taxonomy/te_234/
```

### 4. Keep experiments explicit

Anything like:

- workflow playgrounds
- G6 demo pages
- temporary manual verification pages

should move under `lab/`.

That avoids confusing:

- production pages
- experiments
- internal tools

### 5. Treat docs as a first-class area

The project already has real documentation tracks:

- setup notes
- demos
- architecture thinking
- LaTeX outputs
- course material

So `docs/latex/` should remain visible and official, not hidden inside a generic docs bucket.

---

## Where Current Major Areas Should End Up

### Runtime web layer

- root PHP pages remain at root
- [api](D:/wamp64/www/TE-/api)
- [assets](D:/wamp64/www/TE-/assets)
- [templates](D:/wamp64/www/TE-/templates)

### Product data layer

- [data/raw](D:/wamp64/www/TE-/data/raw)
- [data/processed](D:/wamp64/www/TE-/data/processed)
- [data/statistics](D:/wamp64/www/TE-/data/statistics)
- [data/cache](D:/wamp64/www/TE-/data/cache)
- [data/logs](D:/wamp64/www/TE-/data/logs)
- [data/dfam](D:/wamp64/www/TE-/data/dfam)
- [data/JBrowse](D:/wamp64/www/TE-/data/JBrowse)
- [data/bulk_expression_web](D:/wamp64/www/TE-/data/bulk_expression_web)

### Data assets that should move into `data/`

Current directory:

- [transposon_tree](D:/wamp64/www/TE-/transposon_tree)

Target:

```text
data/taxonomy/transposon_tree/
```

Current directory:

- [terminology](D:/wamp64/www/TE-/terminology)

Target:

```text
data/terminology/
```

Rationale:

- both are data assets
- neither is a runtime code subsystem

### Pipeline layer

Current directory:

- [scripts](D:/wamp64/www/TE-/scripts)

Keep the directory, but organize it internally by responsibility:

```text
scripts/
├── build/
├── normalize/
├── export/
├── import/
├── eval/
└── plot/
```

Current directory:

- [imports](D:/wamp64/www/TE-/imports)

This should become more obviously import-oriented:

```text
imports/
├── neo4j/
├── disease/
├── te_lineage/
└── merge/
```

### Internal tools / experiments

Current files:

- [agent_workflow_lab.php](D:/wamp64/www/TE-/agent_workflow_lab.php)
- [index_g6.html](D:/wamp64/www/TE-/index_g6.html)
- [index_g6_embed.html](D:/wamp64/www/TE-/index_g6_embed.html)
- [tmp_quick_done_test.php](D:/wamp64/www/TE-/tmp_quick_done_test.php)

Target:

```text
lab/
```

### Reference and archive

Current directories:

- [reference](D:/wamp64/www/TE-/reference)
- [archive](D:/wamp64/www/TE-/archive)

These should remain separate:

- `reference/` = useful external or comparative material
- `archive/` = historical or retired material

---

## Path Management Strategy

## Short answer

Yes, path centralization should happen before large-scale directory movement.

But it should **not** be a single global variable name for everything.

That would only replace scattered string literals with one oversized config blob.

The right approach is:

1. inventory path usage
2. classify paths by kind
3. centralize path builders and named roots
4. migrate code to use those helpers
5. move directories after path usage is no longer hard-coded everywhere

---

## What should be centralized

There are three different kinds of paths in this project:

### 1. Filesystem roots

Examples:

- project root
- data root
- JBrowse data root
- taxonomy data root
- terminology data root
- cache root

These should live in a central config file.

There is already a good start in:

- [path_config.php](D:/wamp64/www/TE-/path_config.php)

This file should be expanded instead of inventing a second parallel mechanism.

### 2. URL roots

Examples:

- `/TE-/data`
- `/TE-/assets/...`
- `/TE-/api/...`
- JBrowse asset URLs

These should also be centralized, but separately from filesystem paths.

### 3. Project-relative helper functions

Instead of manual string concatenation everywhere, code should use helpers like:

- `tekg_fs_from_project_relative(...)`
- `tekg_url_from_project_relative(...)`
- `tekg_jbrowse_fs_path(...)`
- `tekg_jbrowse_url(...)`

That pattern already exists in [path_config.php](D:/wamp64/www/TE-/path_config.php). It should be extended.

---

## What should not be centralized into one variable

Do **not** create one giant variable like:

- `$PATHS = [...]`

and then encourage every file to manually dig values out of it in ad hoc ways.

That tends to produce:

- weak naming discipline
- no distinction between URLs and filesystem paths
- more string concatenation, not less

Prefer:

- small named constants for roots
- small helper functions for path derivation

---

## Recommended path config expansion

Keep [path_config.php](D:/wamp64/www/TE-/path_config.php) as the single PHP source of truth and grow it carefully.

Examples of future additions:

- `TEKG_ASSETS_FS_DIR`
- `TEKG_ASSETS_URL_BASE`
- `TEKG_API_URL_BASE`
- `TEKG_TAXONOMY_FS_DIR`
- `TEKG_TAXONOMY_URL_BASE` if needed
- `TEKG_TERMINOLOGY_FS_DIR`
- `TEKG_CACHE_FS_DIR`
- `TEKG_LOGS_FS_DIR`
- `TEKG_LAB_FS_DIR` only if runtime code truly needs it

And helper functions such as:

- `tekg_assets_url(...)`
- `tekg_data_fs_path(...)`
- `tekg_taxonomy_fs_path(...)`
- `tekg_terminology_fs_path(...)`

---

## Migration Method

### Phase 1: Record and classify existing path usage

Before moving directories:

- search for hard-coded references to:
  - `data/`
  - `transposon_tree/`
  - `terminology/`
  - `imports/`
  - `assets/`
  - `/TE-/...`

Then classify each usage as:

- filesystem path
- URL path
- relative include
- script-only path

This is the real prerequisite for safe folder cleanup.

### Phase 2: Normalize PHP path usage

Expand [path_config.php](D:/wamp64/www/TE-/path_config.php), then gradually replace direct literals in PHP with helper calls.

This should happen before moving important directories like:

- `transposon_tree/`
- `terminology/`
- `imports/`

### Phase 3: Normalize JS-facing path usage

For browser code, avoid hard-coded URLs scattered through page scripts.

Prefer one of:

- page-level JSON config
- `data-*` config on root containers
- one small JS bootstrap config object

The point is:

- PHP computes URLs
- JS consumes them
- JS does not guess folder layout

### Phase 4: Move low-risk directories first

Move things with the least blast radius first:

- experimental pages into `lab/`
- reference assets into clearer `reference/` subfolders
- internal docs into clearer `docs/` subfolders

### Phase 5: Move taxonomy and terminology under `data/`

After path helpers are in place:

- `transposon_tree/` -> `data/taxonomy/transposon_tree/`
- `terminology/` -> `data/terminology/`

This is one of the highest-value structural changes.

### Phase 6: Reorganize `scripts/` and `imports/`

Only after path usage is stabilized.

---

## Recommended Execution Order

1. Finalize target structure as written in this document.
2. Inventory all hard-coded paths.
3. Expand `path_config.php` into the authoritative PHP path layer.
4. Convert high-value runtime code to path helpers.
5. Move `lab/` candidates out of the root.
6. Move `transposon_tree/` and `terminology/` under `data/`.
7. Reorganize `scripts/` and `imports/` internally.
8. Do a final pass on docs and reference structure.

---

## Practical Conclusion

The folder cleanup should not start with directory dragging.

It should start with **path discipline**.

The correct sequence is:

- define the target structure
- centralize path handling
- replace hard-coded path usage
- then move the directories

If this order is reversed, refactoring cost and breakage risk rise sharply.
