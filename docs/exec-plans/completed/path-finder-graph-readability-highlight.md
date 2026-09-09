# Path Finder Graph Readability And Endpoint Highlight

## Goal

Improve Path Finder Graph mode readability without changing TE-KG preview defaults:

- Increase node radius and collision spacing for Path Finder Graph mode.
- Keep node and edge labels visible by default where feasible.
- Highlight the searched source/target nodes with a persistent ripple-style effect inspired by `reference/external_examples/G6/packages/site/examples/animation/persistence/demo/ripple-circle.js`.
- Preserve the existing TE-KG page behavior.

## Scope

Owned files:

- `assets/js/renderers/g6/index-g6-shared.js`
- `assets/js/pages/path_finder.js`
- `assets/css/pages/path_finder.css`
- `scripts/checks/check_path_finder_graph_view.py`
- `docs/exec-plans/completed/path-finder-graph-readability-highlight.md`

Forbidden files:

- `api/graph.php`
- `api/graph_service.php`
- `api/path_finder.php`
- `api/path_finder_service.php`
- `api/agent/**`
- Neo4j data/import/write scripts

## Implementation Notes

- Added optional shared G6 graph data/layout options with defaults that preserve existing TE-KG behavior.
- Path Finder Graph now passes Path Finder-only options:
  - `preferFullLabels: true`
  - `endpointHighlightIds`
  - `nodeSizeScale: 2.4`
  - `nodeMinSize: 72`
  - `endpointNodeMinSize: 104`
  - `layoutDistanceScale: 1.55`
  - `collisionPaddingScale: 2.1`
  - `collisionIterations: 28`
  - `chargeScale: 1.6`
  - `edgeLabelFontSize: 11`
- Source and target nodes are marked via `endpointHighlightIds` from the resolved Path Finder source/target `element_id`.
- Added a Path Finder-only G6 `path-finder-ripple-circle` node type that creates animated ripple circles for highlighted endpoints. If registration is unavailable, the node falls back to halo/shadow styling.
- Increased Path Finder graph canvas height to give the enlarged force layout more room.
- Extended `check_path_finder_graph_view.py` to assert visible nodes/edges, endpoint highlighting, enlarged endpoint size, enlarged minimum node size, no `graph.php` request, and disabled Jump/Expand.

## Review Notes

- A Reviewer subagent was requested, but it could not read the diff due to sandbox startup failure and an approval-service 503.
- Main thread performed scoped diff review and found only formatting issues in `index-g6-shared.js`; these were fixed before final verification.

## Verification Results

- PASS: `node --check assets/js/renderers/g6/index-g6-shared.js`
- PASS: `node --check assets/js/pages/path_finder.js`
- PASS: `python -m py_compile scripts/checks/check_path_finder_graph_view.py`
- PASS: `python scripts/checks/check_path_finder_graph_view.py`
- PASS: `python scripts/checks/check_g6_static_contract.py`
- PASS: `python scripts/checks/check_g6_node_action_card_ux.py`
- PASS: `python scripts/checks/check_g6_browser_smoke.py`
- PASS: `python scripts/checks/check_path_finder.py`
- PASS: `python scripts/checks/check_path_finder_connected_candidates.py`
- PASS: `python scripts/checks/check_te_autocomplete_smoke.py`

Note: local PowerShell emits an execution-policy warning for `profile.ps1`, but the checks above exited with code 0.

## Notes

- No Neo4j writes.
- No graph API semantics were changed.
- TE-KG preview G6 smoke passed after the shared runner change.
