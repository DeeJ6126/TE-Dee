# Graph Classification Export and Inspect Card Cleanup

## Goal

Complete the approved Graph-page usability changes without changing Agent/DeepThink evidence routing or the underlying graph/co-expression data.

## Scope

1. Enable CSV, PNG, and SVG export in both taxonomy Tree and taxonomy Graph modes.
2. Remove taxonomy Tree leaf-node navigation into the dynamic knowledge graph. Taxonomy node inspection and Tree collapse/expand remain available.
3. Reposition the AI floating button beside the drawer whenever the drawer opens.
4. Allow question and answer text in the Graph AI drawer to be selected and copied.
5. Add distinct-entity Back history to Co-expression, including history inherited from Knowledge Graph navigation.
6. Simplify node and edge inspect cards:
   - remove `Key node`, `Category level`, raw `Module`, `Confidence`, `Edge role`, `Metric coverage`, and empty knowledge-edge `Evidence` sections;
   - naturalize taxonomy source names;
   - rename graph degree to database connections;
   - show canonical names only when they differ from the displayed node name;
   - restrict node PMID display to Paper nodes;
   - naturalize co-expression role, composition, center-correlation, and FDR labels;
   - remove raw module identifiers from user-facing co-expression interpretation text;
   - make node Details/Collapse reversible.

## Out Of Scope

- Do not modify Graph AI reverse-driving behavior in this execution.
- Do not redesign the graph or co-expression datasets.
- Do not restore the correlation notice removed from `templates/preview/coexpression_workspace.php`.

## Implementation Boundaries

- `assets/js/renderers/g6/index-g6.bootstrap.js`: taxonomy export routing and shared entity history exposure.
- `assets/js/renderers/g6/default-tree.js`: non-navigating taxonomy Tree and G6 export snapshot.
- `assets/js/renderers/canvas-force/taxonomy-canvas-renderer.js`: Canvas taxonomy export snapshot/PNG/SVG.
- `assets/js/renderers/g6/index-g6-shared.js` and co-expression renderer counterpart: inspect-card presentation.
- `assets/js/pages/preview/preview-shell.js` and `assets/css/pages/preview.css`: AI drawer/FAB and text selection.
- `assets/js/pages/preview/preview-workspace-mode.js`, `assets/js/pages/preview/coexpression-mode.js`, and `templates/preview/coexpression_workspace.php`: co-expression Back control and distinct-entity history.
- `scripts/checks/`: static contract checks written before implementation; existing browser checks extended where practical.

## Verification

- Run all new static checks once before implementation and confirm they fail for the missing behavior.
- Run `node --check` for every modified JavaScript file and `php -l` for modified PHP templates.
- Run the relevant G6 taxonomy, export, and co-expression checks.
- Verify Tree/Graph export, no taxonomy jump, AI selection/FAB placement, card contents, and cross-mode Back behavior in the browser.

## Completion Record

Completed on 2026-08-06.

- Taxonomy Tree and Canvas Graph export CSV, PNG, and SVG through the existing Export menu.
- Taxonomy Tree leaf clicks no longer navigate into the dynamic knowledge graph.
- The AI FAB is repositioned beside the drawer on open, and message text is selectable.
- Co-expression uses a distinct-entity Back history shared with Knowledge Graph navigation.
- Knowledge and co-expression inspect cards no longer expose the approved internal fields or empty Evidence copy.
- The existing user change in `templates/preview/coexpression_workspace.php` that removes the correlation notice was preserved.

Verification:

- `python scripts/checks/check_graph_ui_cleanup_contract.py`
- `node scripts/checks/check_graph_ui_cleanup_browser.js`
- `node --check` for all modified JavaScript runtime files
- `php -l preview.php`
- `php -l templates/preview/coexpression_workspace.php`
- `python scripts/checks/check_taxonomy_canvas_integration.py`
- `python scripts/checks/check_coexpression_frontend_static_contract.py`
- `python scripts/checks/check_coexpression_task6_static.py`
- `python scripts/checks/check_g6_static_contract.py`
