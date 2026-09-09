# Path Finder Graph Results View

## Goal

Add a Graph display mode for Path Finder search results while keeping the current table/cards mode. The graph must render only nodes and edges present in the returned paths, reuse the existing G6 renderer as much as possible, allow node and edge inspection, and disable graph expansion/jump behavior.

Also remove the visible `Relation type BIO_RELATION` row from Path Finder table evidence cards.

## Scope

Owned files:

- `path_finder.php`
- `assets/js/pages/path_finder.js`
- `assets/css/pages/path_finder.css`
- `assets/js/renderers/g6/index-g6-shared.js`
- `scripts/checks/check_path_finder.py`
- `scripts/checks/check_path_finder_graph_view.py`
- `docs/exec-plans/completed/path-finder-graph-results-view.md`

Forbidden files:

- `api/agent/**`
- `agent.php`
- `api/graph.php`
- `api/graph_service.php`
- Neo4j import/write scripts

## Implementation Notes

- Added Table/Graph result view controls to `path_finder.php`.
- Added direct G6 shared-runner rendering for Path Finder graph results.
- Path Finder graph elements are built in-browser from the current `payload.paths`; no additional API request is used for view switching.
- The graph elements include only nodes and edges returned in the current Path Finder result payload.
- Node `Jump` and `Expand` actions are disabled through a new optional `allowNodeActions` runner state in `index-g6-shared.js`; default TE-KG graph behavior remains unchanged.
- Removed the visible Path Finder `Relation type BIO_RELATION` evidence meta block from the table/cards view.
- Raw `BIO_RELATION` is not used as a Path Finder display fallback when `relation_label` is absent.
- Added a small Path Finder graph debug bridge for browser smoke checks to inspect the rendered subgraph and open a node card deterministically.
- Updated `check_path_finder.py` because its old direct-relation graph prohibition is now obsolete.
- Added `check_path_finder_graph_view.py` for browser smoke coverage of Table/Graph switching, G6 canvas rendering, no `graph.php` request, default Show toggles, Export readiness, and disabled node Jump/Expand actions.

## Review Notes

Reviewer found two issues:

- Raw `BIO_RELATION` could still be shown through fallback paths. Fixed by routing Path Finder relation display through `relationName()` and ignoring raw `BIO_RELATION` as a user-facing label.
- The first browser smoke was too narrow and used unstable canvas coordinates. Fixed by checking no `graph.php` requests, using the debug bridge to open a node card, and asserting `Jump`/`Expand` are absent while `Details` remains.

## Verification Results

- PASS: `php -l path_finder.php`
- PASS: `node --check assets/js/pages/path_finder.js`
- PASS: `node --check assets/js/renderers/g6/index-g6-shared.js`
- PASS: `python -m py_compile scripts/checks/check_path_finder.py scripts/checks/check_path_finder_graph_view.py`
- PASS: `python scripts/checks/check_path_finder.py`
- PASS: `python scripts/checks/check_path_finder_graph_view.py`
- PASS: `python scripts/checks/check_path_finder_connected_candidates.py`
- PASS: `python scripts/checks/check_te_autocomplete_smoke.py`
- PASS: `python scripts/checks/check_g6_static_contract.py`
- PASS: `python scripts/checks/check_g6_node_action_card_ux.py`
- PASS: `python scripts/checks/check_g6_browser_smoke.py`

Note: local PowerShell emits an execution-policy warning for `profile.ps1`, but the checks above exited with code 0.

## Notes

- No Neo4j writes.
- Graph API semantics were not changed.
- Active agent work under `api/agent/**` was not touched.
