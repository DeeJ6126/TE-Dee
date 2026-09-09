# Path Refactor Audit

## Goal

Prepare the codebase for future directory reorganization by reducing hard-coded paths before any folder move happens.

This phase does **not** move folders. It does four things:

1. audit hard-coded path usage
2. centralize high-value runtime paths in `path_config.php`
3. convert runtime PHP pages to the new helpers
4. finish runtime JS and offline script path normalization before any folder move

## Path Types

### 1. Filesystem paths

These are server-side reads and writes such as:

- `__DIR__ . '/data/...'`
- `__DIR__ . '/scripts/...'`
- direct project-root references to `transposon_tree/`, `terminology/`, `imports/`

Primary risk:
- future folder moves break runtime file loading

### 2. URL paths

These are browser-facing paths such as:

- `/TE-/assets/...`
- `/TE-/api/...`
- `/TE-/data/...`
- hard-coded page links like `/TE-/browse.php`

Primary risk:
- URL base and folder names are duplicated across many pages and scripts

### 3. `include` / `require` paths

These are PHP include paths such as:

- `require __DIR__ . '/head.php'`
- `require __DIR__ . '/templates/components/...php'`

Primary risk:
- low for same-directory includes
- medium for cross-area includes if folders move later

### 4. Offline-only script paths

These are paths used only by data-building scripts, typically in Python or CLI PHP:

- `Path("data/...")`
- `Path("imports/...")`
- `Path("transposon_tree/...")`
- `Path("terminology/...")`

Primary risk:
- scripts silently depend on current working directory or repo layout

## Current Hard-Coded Path Surface

### Runtime PHP: URL path hotspots

Representative files:

- [head.php](/D:/wamp64/www/TE-/head.php)
- [agent.php](/D:/wamp64/www/TE-/agent.php)
- [search.php](/D:/wamp64/www/TE-/search.php)
- [jbrowse.php](/D:/wamp64/www/TE-/jbrowse.php)
- [index.php](/D:/wamp64/www/TE-/index.php)
- [browse.php](/D:/wamp64/www/TE-/browse.php)
- [download.php](/D:/wamp64/www/TE-/download.php)
- [expression.php](/D:/wamp64/www/TE-/expression.php)
- [expression_detail.php](/D:/wamp64/www/TE-/expression_detail.php)
- [preview.php](/D:/wamp64/www/TE-/preview.php)
- [genomic.php](/D:/wamp64/www/TE-/genomic.php)
- [epigenetics.php](/D:/wamp64/www/TE-/epigenetics.php)

Common literals:

- `/TE-/assets/...`
- `/TE-/api/...`
- `/TE-/index.php`
- `/TE-/browse.php`
- `/TE-/jbrowse.php`
- `/TE-/index_g6.html`

### Runtime PHP: filesystem path hotspots

Representative files:

- [search.php](/D:/wamp64/www/TE-/search.php)
- [jbrowse.php](/D:/wamp64/www/TE-/jbrowse.php)
- [repbase_structure_svg.php](/D:/wamp64/www/TE-/repbase_structure_svg.php)
- [api/agent/bootstrap.php](/D:/wamp64/www/TE-/api/agent/bootstrap.php)

Common literals:

- `__DIR__ . '/data/processed/...`
- `__DIR__ . '/data/rmsk.txt'`
- `__DIR__ . '/scripts/plot/...`
- `__DIR__ . '/api/config.local.php'`

### Runtime JS: URL path hotspots

Representative files:

- [assets/js/pages/agent.js](/D:/wamp64/www/TE-/assets/js/pages/agent.js)
- [assets/js/pages/agent_workflow_lab.js](/D:/wamp64/www/TE-/assets/js/pages/agent_workflow_lab.js)
- [assets/js/tekg_runtime_data.js](/D:/wamp64/www/TE-/assets/js/tekg_runtime_data.js)
- G6 renderers under [assets/js/renderers/g6](/D:/wamp64/www/TE-/assets/js/renderers/g6)

Common literals:

- `/TE-/api/...`
- `/TE-/data/...`
- `/TE-/assets/...`

### Offline scripts: filesystem path hotspots

Representative areas:

- [scripts](/D:/wamp64/www/TE-/scripts)
- [imports](/D:/wamp64/www/TE-/imports)

Common target roots:

- `data/`
- `imports/`
- `transposon_tree/`
- `terminology/`

## First-Wave Refactor Completed

### Centralized path layer expanded

Updated file:

- [path_config.php](/D:/wamp64/www/TE-/path_config.php)

Added centralized roots and helpers for:

- app URL base
- assets FS and URL
- API FS and URL
- data FS and URL
- templates FS
- scripts FS
- imports FS
- taxonomy FS
- terminology FS
- cache FS
- logs FS
- JBrowse FS and URL

Representative helpers:

- `tekg_app_url(...)`
- `tekg_assets_url(...)`
- `tekg_api_url(...)`
- `tekg_data_fs_path(...)`
- `tekg_data_url(...)`
- `tekg_scripts_fs_path(...)`
- `tekg_taxonomy_fs_path(...)`
- `tekg_terminology_fs_path(...)`

### Runtime PHP converted in wave 1

Updated files:

- [head.php](/D:/wamp64/www/TE-/head.php)
- [agent.php](/D:/wamp64/www/TE-/agent.php)
- [search.php](/D:/wamp64/www/TE-/search.php)
- [jbrowse.php](/D:/wamp64/www/TE-/jbrowse.php)

What changed:

- shared layout CSS and logo URLs now come from helpers
- agent API URLs now come from helpers
- search page data and script paths now use data/script helpers
- JBrowse page URLs and local asset URLs now use helpers

## Second-Wave Refactor Completed

### Runtime root PHP pages converted in wave 2

Updated files:

- [index.php](/D:/wamp64/www/TE-/index.php)
- [browse.php](/D:/wamp64/www/TE-/browse.php)
- [download.php](/D:/wamp64/www/TE-/download.php)
- [expression.php](/D:/wamp64/www/TE-/expression.php)
- [expression_detail.php](/D:/wamp64/www/TE-/expression_detail.php)
- [preview.php](/D:/wamp64/www/TE-/preview.php)
- [genomic.php](/D:/wamp64/www/TE-/genomic.php)
- [epigenetics.php](/D:/wamp64/www/TE-/epigenetics.php)
- [repbase_structure_svg.php](/D:/wamp64/www/TE-/repbase_structure_svg.php)

What changed:

- root page entry URLs now use `tekg_app_url(...)`
- page-local CSS and JS URLs now use `tekg_assets_url(...)`
- cross-page links built through `site_url_with_state(...)` now use helper-backed URLs
- remaining runtime file reads in this wave now use `tekg_data_fs_path(...)`
- preview runtime filemtime checks now use helper-backed filesystem paths

At this point, the remaining PHP runtime path cleanup is mostly limited to same-directory includes, acceptable local literals, and lower-priority files outside the wave-1 and wave-2 target set.

## Third-Wave Refactor Completed

### Shared frontend path config added

Added file:

- [assets/js/tekg_paths.php](/D:/wamp64/www/TE-/assets/js/tekg_paths.php)

What it provides:

- `window.__TEKG_PATHS.appUrl(...)`
- `window.__TEKG_PATHS.apiUrl(...)`
- `window.__TEKG_PATHS.assetsUrl(...)`
- `window.__TEKG_PATHS.dataUrl(...)`
- `window.__TEKG_PATHS.terminologyUrl(...)`

What changed:

- browser code no longer needs raw `/TE-/api/...`, `/TE-/data/...`, or `/TE-/assets/...` fallbacks
- static G6 HTML pages now load the same shared runtime path layer before their page scripts

### Runtime JS converted in wave 3

Updated files:

- [assets/js/tekg_runtime_data.js](/D:/wamp64/www/TE-/assets/js/tekg_runtime_data.js)
- [assets/js/pages/agent.js](/D:/wamp64/www/TE-/assets/js/pages/agent.js)
- [assets/js/pages/agent_workflow_lab.js](/D:/wamp64/www/TE-/assets/js/pages/agent_workflow_lab.js)
- [assets/js/renderers/g6/index-g6-runtime.js](/D:/wamp64/www/TE-/assets/js/renderers/g6/index-g6-runtime.js)
- [assets/js/renderers/g6/index-g6-shared.js](/D:/wamp64/www/TE-/assets/js/renderers/g6/index-g6-shared.js)
- [assets/js/renderers/g6/index-g6.bootstrap.js](/D:/wamp64/www/TE-/assets/js/renderers/g6/index-g6.bootstrap.js)
- [assets/js/pages/preview/preview-deepthink.js](/D:/wamp64/www/TE-/assets/js/pages/preview/preview-deepthink.js)
- [index_g6.html](/D:/wamp64/www/TE-/index_g6.html)
- [index_g6_embed.html](/D:/wamp64/www/TE-/index_g6_embed.html)
- [agent_workflow_lab.php](/D:/wamp64/www/TE-/agent_workflow_lab.php)

What changed:

- runtime fetches for API, processed data, and terminology now resolve through `window.__TEKG_PATHS`
- G6 graph endpoints no longer construct raw `api/graph.php` URLs directly
- agent workflow lab assets now use the PHP helper-backed asset URLs

### Offline Python and CLI PHP normalized in wave 3

Added file:

- [scripts/path_helpers.py](/D:/wamp64/www/TE-/scripts/path_helpers.py)

Representative updated scripts:

- [scripts/parse_dfam_embl.py](/D:/wamp64/www/TE-/scripts/parse_dfam_embl.py)
- [scripts/parse_te_repbase.py](/D:/wamp64/www/TE-/scripts/parse_te_repbase.py)
- [scripts/prepare_expression_assets.py](/D:/wamp64/www/TE-/scripts/prepare_expression_assets.py)
- [scripts/normalize_te_kg2_graph.py](/D:/wamp64/www/TE-/scripts/normalize_te_kg2_graph.py)
- [scripts/generate_core_relation_import_cypher.py](/D:/wamp64/www/TE-/scripts/generate_core_relation_import_cypher.py)
- [scripts/generate_node_import_cypher.py](/D:/wamp64/www/TE-/scripts/generate_node_import_cypher.py)
- [scripts/generate_node_names_import_cypher.py](/D:/wamp64/www/TE-/scripts/generate_node_names_import_cypher.py)
- [scripts/generate_paper_relation_import_cypher.py](/D:/wamp64/www/TE-/scripts/generate_paper_relation_import_cypher.py)
- [scripts/generate_exact_duplicate_merge.py](/D:/wamp64/www/TE-/scripts/generate_exact_duplicate_merge.py)
- [scripts/generate_semantic_standardization_merge.py](/D:/wamp64/www/TE-/scripts/generate_semantic_standardization_merge.py)
- [scripts/generate_te_kg2_dedup_cypher.py](/D:/wamp64/www/TE-/scripts/generate_te_kg2_dedup_cypher.py)
- [scripts/generate_tree_te_lineage.py](/D:/wamp64/www/TE-/scripts/generate_tree_te_lineage.py)
- [scripts/apply_te_standardization.py](/D:/wamp64/www/TE-/scripts/apply_te_standardization.py)
- [scripts/apply_semantic_standardization.py](/D:/wamp64/www/TE-/scripts/apply_semantic_standardization.py)
- [scripts/plot/render_dfam_structure_svg.py](/D:/wamp64/www/TE-/scripts/plot/render_dfam_structure_svg.py)
- [scripts/agent_baseline_eval.php](/D:/wamp64/www/TE-/scripts/agent_baseline_eval.php)

What changed:

- live scripts no longer depend on machine-specific repo roots like `D:\\wamp64\\www\\TE-`
- raw `Path("data/...")`, `Path("imports/...")`, and `Path("api/...")` usage in live scripts was centralized through helper functions
- root-level Python scripts now share the same repo-root helper layer
- CLI PHP cross-area file access now uses `path_config.php` helpers

### Generated artifacts remain outputs, not canonical path sources

No repo-wide cleanup was forced into generated Cypher files under [imports](/D:/wamp64/www/TE-/imports).

Rule:

- normalize generator scripts
- let regenerated outputs inherit centralized paths
- avoid hand-editing generated artifacts unless they contain real runtime-sensitive semantics

## Remaining Work

### Intentional leftovers only

The remaining direct path literals should now fall into one of these categories:

- same-directory PHP includes that are intentionally local
- generated artifacts under [imports](/D:/wamp64/www/TE-/imports)
- docs, reference material, or historical archive content
- the helper source itself, such as [scripts/path_helpers.py](/D:/wamp64/www/TE-/scripts/path_helpers.py)

### What is now ready

At this point:

- wave 1 is complete
- wave 2 is complete
- wave 3 is complete
- future moves like `transposon_tree -> data/taxonomy/transposon_tree` and `terminology -> data/terminology` are now primarily a helper/config update rather than a repo-wide string hunt

## Move Readiness Gate

Do **not** move folders yet.

The next folder move phase should only begin after:

1. runtime PHP has near-zero direct `/TE-/...` and cross-area `__DIR__ . '/data/...` usage
2. runtime JS consumes page-config URLs instead of hard-coded API/data URLs where possible
3. offline scripts have explicit repo-root resolution
4. remaining direct references to `transposon_tree/` and `terminology/` are fully enumerated

## Intended Future Moves Supported by This Work

- `transposon_tree/ -> data/taxonomy/transposon_tree/`
- `terminology/ -> data/terminology/`
- experimental root pages -> `lab/`
- later re-grouping of `scripts/` and `imports/`
