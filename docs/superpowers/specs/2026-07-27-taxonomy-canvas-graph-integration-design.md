# Taxonomy Canvas Graph Integration Design

## Goal

Add the existing Canvas taxonomy visualization as an optional classification
Graph without replacing the accepted G6 classification Tree or affecting the
ordinary Knowledge Graph and Co-expression workspaces.

## User Experience

- Classification mode defaults to `Tree` and keeps the current G6 tree.
- A `Tree | Graph` switch appears immediately to the left of the existing
  `All | RMSK + RepBase` source switch.
- `Graph` renders the Canvas taxonomy visualization inside the existing
  classification surface. It does not navigate to `taxonomy_canvas_demo.php`.
- In Tree or Graph classification mode, entity search, the two classification
  switches, and Export remain in their established positions. `Show relations`
  and other dynamic-only commands are hidden until an ordinary Knowledge Graph
  is open. Export may remain disabled when the active classification renderer
  does not support it.
- Canvas class labels are always enabled. The demo's `Show class labels`,
  `Restart`, and `Fit` controls are not integrated.
- Node dragging preserves the standalone demo's force response: moving a node
  reheats its local layout and affects connected nodes. Releasing the pointer
  does not leave a persistent node selection; hover focus clears on pointer exit.

## Architecture

- Keep `api/taxonomy.php?view=tree` as the only taxonomy runtime source.
- Extract the reusable Canvas renderer behavior from the isolated demo into a
  production-owned renderer under `assets/js/renderers/canvas-force/`.
- Give the Canvas renderer its own surface inside the Knowledge Graph iframe.
- Let `index-g6.bootstrap.js` remain the owner of classification mode, source
  selection, toolbar visibility, history, and transitions into ordinary graph
  queries.
- Do not reactivate the archived G6 large-force taxonomy renderer.

## Legend Contract

- Reuse the existing right-side Knowledge Graph legend container and visual
  language.
- Legend items represent taxonomy levels: Human TE, Class, Order,
  Superfamily, Family, Subfamily, and deeper levels when present.
- Clicking a legend row temporarily emphasizes nodes and edges for that level
  while dimming unrelated elements. Clicking the same row again clears focus.
- Checkboxes change pending level visibility; `Apply` commits visibility to the
  Canvas renderer without rebuilding data or restarting layout.
- An edge is visible only when both endpoint nodes are visible.
- Switching Tree/Graph or taxonomy source refreshes legend metadata and clears
  transient focus while preserving the selected source.

## Weighted Taxonomy Layout

- The Canvas layout groups nodes by their depth-1 Class before positioning the
  depth-2 Order galaxies. It must not give every depth-2 node an equal share of
  the global ring, because this lets many singleton `Others` nodes dominate the
  view while populous retrotransposon Orders overlap.
- For each Class, compute its mass from every node whose depth-1 ancestor is
  that Class. Convert mass to a target visual share with `sqrt(mass)`, clamp
  the share to 8%-60%, then renormalize all Class shares. With current data,
  this yields approximately 57% / 35% / 8% for Retrotransposons, DNA
  Transposons, and Others in `rmsk_repbase`, and 56% / 34% / 10% in `all`.
- Assign each Class a stable angular sector around the root using those shares.
  Depth-2 anchors remain in their Class sector. Their local galaxy scale uses
  descendant mass with a bounded fourth-root transform, which spreads LTR,
  LINE, and SINE without allowing LTR to consume the entire canvas.
- Singleton and very small depth-2 galaxies under `Others` use compact packing
  inside the Others sector. All nodes remain visible; this is spatial
  compression, not filtering or aggregation.
- The weighted anchors guide initialization and the running force simulation.
  Node dragging must still reheat the layout and influence connected nodes.
- The calculation is data-driven for both taxonomy sources and contains no
  hard-coded Class names or fixed source-specific percentages.

## State And Failure Behavior

- Default state is `Tree` plus the current default taxonomy source.
- Canvas data requests use the existing bounded taxonomy request behavior.
- Loading, empty, and error states settle through the existing graph loader and
  details surfaces; the standalone demo status panel is not integrated.
- Switching away from Canvas stops its animation loop. Returning reuses the
  current model and positions when the taxonomy source has not changed.

## Verification

- Static checks cover the new surface, switch, asset loading, toolbar-mode
  rules, legend bridge, and absence of archived large-force runtime loading.
- Browser checks cover Tree default, Tree/Graph switching, both taxonomy
  sources, always-on class labels, legend focus, Apply visibility, mode changes,
  and return to an ordinary Knowledge Graph.
- Existing Graph, loader, legend, navigation, Co-expression, taxonomy truth,
  and legacy-fallback checks must continue to pass.

## Non-Goals

- Replacing the current G6 classification Tree.
- Reusing one renderer instance for Canvas taxonomy and G6 evidence graphs.
- Reintroducing the archived G6 large-force taxonomy implementation.
- Defining the final click/double-click behavior for opening a TE dynamic graph;
  that interaction will remain disabled or unchanged until separately agreed.
