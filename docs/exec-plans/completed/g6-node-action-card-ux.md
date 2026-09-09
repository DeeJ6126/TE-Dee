# G6 Node Action Card UX

## Background

`g6-evidence-support-ux-v1` is completed and archived. The legacy detail area is hidden, and edge evidence tables now render inside the expanded edge card `PubMed` section.

Current G6 node interaction still relies on global `Fixed view` / `Expand mode` controls and implicit node-click behavior. This stage changes node click into an explicit action-card workflow.

## Goals

- Hide user-visible `Fixed view` and `Expand mode` controls.
- Keep the current graph fixed by default.
- Clicking a node opens a node action card and does not automatically jump or expand.
- Node action card exposes explicit `Jump`, `Expand`, and `Details` actions.
- Preserve edge evidence card / PubMed table / CSV behavior.

## Scope

- Only G6/preview front-end and checks.
- Do not modify Neo4j data.
- Do not change graph API contract unless a tiny compatibility fix is proven necessary.
- Do not modify taxonomy, agent, expression, evidence support API, metrics imports, or layout mode.
- Keep old internal state variables where needed for compatibility; prefer hiding UI over deleting state.

## Implementation Plan

1. Inspect current `Fixed view`, `Expand mode`, node click, inspect card, and detail card code paths.
2. Add/extend browser smoke for node action card:
   - global controls hidden;
   - node click opens action card;
   - node click alone does not jump or expand;
   - `Jump`, `Expand`, `Details` buttons exist and call real paths;
   - edge evidence table remains available.
3. Add explicit G6 runner handlers:
   - `jumpToNode(node)` reuses existing node-centered graph load path;
   - `expandNodeFromCard(node)` reuses existing `onNodeExpand` / same-label expand path;
   - `showNodeDetails(node)` expands the node card without changing graph query or elements.
4. Hide `Fixed view` / `Expand mode` toolbar controls through CSS/DOM state while leaving compatibility variables intact.
5. Update old expand-mode browser checks only where necessary so they validate the new card `Expand` behavior rather than the removed global mode UI.
6. Run required regression checks.

## Verification

Required:

```powershell
php -l preview.php
php -l api/graph.php
php -l api/graph_service.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_expand_mode_smoke.py --query LINE-1
python scripts/checks/check_g6_expand_layout_smoke.py --query LINE-1
python scripts/checks/check_g6_expand_disambiguation_smoke.py
python scripts/checks/check_g6_evidence_support_ux.py
python scripts/checks/check_g6_node_action_card_ux.py
python scripts/checks/check_api_contracts.py
```

## Archive Rule

If all required checks pass, move this file to `docs/exec-plans/completed/g6-node-action-card-ux.md` and update `docs/RELIABILITY.md` plus `docs/exec-plans/tech-debt-tracker.md`.

If any check fails, keep this plan active and record the failing command, cause, completed items, and next step.

## Execution Result - 2026-05-22

Status: completed.

Changed files:

- `assets/css/pages/preview.css`
- `assets/js/renderers/g6/index-g6.bootstrap.js`
- `assets/js/renderers/g6/index-g6-shared.js`
- `assets/js/renderers/g6/index-g6-embed.js`
- `scripts/checks/check_g6_browser_smoke.py`
- `scripts/checks/check_g6_expand_disambiguation_smoke.py`
- `scripts/checks/check_g6_expand_layout_smoke.py`
- `scripts/checks/check_g6_expand_mode_smoke.py`
- `scripts/checks/check_g6_node_action_card_ux.py`
- `docs/RELIABILITY.md`
- `docs/exec-plans/tech-debt-tracker.md`

Implementation notes:

- `Fixed view` and `Expand mode` controls remain in the compatibility DOM but are hidden from users.
- Node click now opens the inspect/action card only. It no longer auto-jumps or auto-expands.
- Node action card buttons are `Jump`, `Expand`, and `Details`.
- `Jump` reuses the existing node-centered graph load path.
- `Expand` reuses the existing `hooks.onNodeExpand(node)` path and preserves same-label disambiguation parameters: `expand_node_id`, `expand_node_type`, and `expand_query`.
- `Details` expands the node card without changing query or graph element count.
- Diagnostic bridge methods `inspectNode(nodeId)` and `triggerNodeAction(nodeId, action)` were added for smoke checks and reuse the real card/action paths.
- Edge evidence card, PubMed table, and CSV behavior remain unchanged.
- Force layout remains the default; no layout mode was added.

Verification passed:

```powershell
php -l preview.php
php -l api/graph.php
php -l api/graph_service.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_expand_mode_smoke.py --query LINE-1
python scripts/checks/check_g6_expand_layout_smoke.py --query LINE-1
python scripts/checks/check_g6_expand_disambiguation_smoke.py
python scripts/checks/check_g6_evidence_support_ux.py
python scripts/checks/check_g6_node_action_card_ux.py
python scripts/checks/check_api_contracts.py
```

Residual risks:

- `Fixed view` / `Expand mode` internals still exist for compatibility; future cleanup should be a separate plan after references are audited.
- Node action card smoke uses diagnostic bridge methods to make browser tests deterministic.
- Expand/collapse affordance and multi-step expand UX remain separate future work.

## Amendment - G6 UI Polish - 2026-05-22

Small follow-up UI polish completed without changing graph API, Neo4j, taxonomy, agent, expression, evidence records, metrics imports, or Jump/Expand/Details semantics.

Changes:

- Node Expand loading overlay now says `Expanding {node} ...` on the node-card expand path. Initial graph and disease classification loading text still uses existing `Preparing` copy.
- Node-card `Jump` closes the old inspect/action card before the new center graph loads.
- PubMed evidence table column allocation was tightened: Journal fixed width changed from `132px` to `82px`, while the Title column receives more remaining table width. Fixed utility columns were also reduced to keep the table inside the card without horizontal scrolling.
- Toolbar polish: user-visible `Reset` was removed, `Back to ...` keeps its behavior and now shares the Export main-button border style, off-state `Show names` and `Show relations` share the same style, and `Show labels` was renamed to `Show relations`.
- Force layout remains `d3-force`; only collision spacing was increased slightly. Collision radius offsets changed from DiseaseClass/TE/Disease/Other `46/38/34/30` to `50/42/38/34`.

Verification passed:

```powershell
php -l preview.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_expand_mode_smoke.py --query LINE-1
python scripts/checks/check_g6_expand_layout_smoke.py --query LINE-1
python scripts/checks/check_g6_expand_disambiguation_smoke.py
python scripts/checks/check_g6_evidence_support_ux.py
python scripts/checks/check_g6_node_action_card_ux.py
python scripts/checks/check_api_contracts.py
```

Residual risks:

- `Reset` internals remain harmlessly referenced because the DOM control is no longer rendered; remove only after a separate cleanup audit.
- Slightly larger collision radii improve crowding but do not introduce a full layout tuning pass.

## Amendment - TE Mechanism-Inspired Loading Animation - 2026-05-22

Small loader UI amendment completed without changing Neo4j, graph API, taxonomy, agent, expression, evidence records, Jump/Expand/Details semantics, edge evidence table, or CSV behavior.

Changes:

- The existing `#graph-preloader` show/hide logic remains the single loading gate. The amendment only swaps the visual component inside that overlay when the loading subject can be conservatively classified as TE-related.
- Retrotransposon-inspired loader is used for conservative retro keywords such as `LINE`, `L1`, `L1HS`, `SINE`, `Alu`, `SVA`, `ERV`, `HERV`, `LTR`, and `retrotransposon`. It shows source DNA with a TE segment, RNA/RT transfer toward target DNA, target opening, and a TE copy appearing at the target site.
- DNA transposon-inspired loader is used for conservative DNA transposon keywords such as `DNA transposon`, `Tc1`, `Mariner`, `hAT`, `piggyBac`, `PIF`, `Harbinger`, `Merlin`, and `Mutator`. It shows source DNA with a TE segment, end cuts, TE movement, target opening, and insertion.
- Unknown/non-TE/default cases keep the existing pulse loader and existing default loading wording.
- TE and RNA use the current G6 TE color from `window.__TEKG_G6_TYPE_META.colors.TE`, with fallback `#4e79ff`; non-TE genomic DNA uses gray; enzyme/cut/arrow elements use neutral dark gray.
- TE mechanism graph loading text uses `Loading {TE name} network`; Expand still uses `Expanding {node} ...`.
- Added `prefers-reduced-motion` handling so motion stops while static SVG and labels remain.
- Added diagnostic bridge methods `getTeLoaderKind` and `previewTeLoader` for smoke checks; they render the real loader component and do not alter graph data.

Verification passed:

```powershell
php -l preview.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_node_action_card_ux.py
python scripts/checks/check_g6_evidence_support_ux.py
python scripts/checks/check_g6_te_mechanism_loader.py
```

Residual risks:

- Loader kind detection is intentionally conservative keyword matching; ambiguous TE names fall back to the existing default loader.
- DNA transposon coverage currently relies on diagnostic/keyword validation when the runtime graph lacks a stable DNA transposon query fixture.

## Amendment - TE Mechanism Loader Visual Refinement - 2026-05-22

Small visual refinement completed without changing loading logic, graph API, Neo4j, graph data logic, Jump/Expand/Details semantics, evidence table, or CSV behavior. Jump remains fast and is not forced to show a loader or delayed artificially.

Changes:

- Mechanism loader SVG was enlarged from `280x150` to `420x220` with `viewBox="0 0 420 220"` and responsive `max-width: calc(100vw - 48px)`.
- Retro loader now uses a clearer left-top to right-bottom process: source DNA with TE, RNA/RT complex, target DNA slide-in, target opening, and inserted TE/cDNA copy. RNA path, RNA label, RT circle, and RT text now live in one `.te-loader-retro-complex` moving group.
- Retro RNA uses the TE hue with reduced opacity (`0.62`) rather than full-strength TE segment color.
- DNA transposon loader now uses source DNA, cut marks, a moving TE segment group containing rect and label, target DNA slide-in, target opening, and inserted segment label.
- Target copy labels now use the current TE short label instead of hard-coded `TE`.
- SVG text labels now use black fill with white stroke and `paint-order: stroke`, matching graph-label readability.
- Reduced-motion mode keeps static SVG elements readable by removing movement while forcing visible opacity/transform defaults.

Verification passed:

```powershell
php -l preview.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_node_action_card_ux.py
python scripts/checks/check_g6_evidence_support_ux.py
python scripts/checks/check_g6_te_mechanism_loader.py
```

Residual risks:

- The animation remains mechanism-inspired and simplified; it should not be treated as a strict mechanistic diagram.
- DNA transposon runtime validation still depends on diagnostic rendering unless a stable DNA transposon graph query is added later.

## Amendment - TE Mechanism Loader Trigger Bugfix and Scale Refinement - 2026-05-22

Bugfix and visual refinement completed without changing graph API, Neo4j, graph data logic, Jump/Expand/Details semantics, edge evidence table, or CSV behavior. Jump remains fast and is not forced to show a loader or delayed artificially.

Changes:

- Fixed `LINE1` search loader classification by adding explicit `LINE1` and `LINE-1` retro keywords. Previously `LINE-1` and `L1HS` matched retro, but `LINE1` did not because keyword matching used token boundaries and only had `LINE`/`L1`.
- Search / graph loading now shows the retro mechanism loader for `LINE1`, `LINE-1`, and `L1HS`; unknown/non-TE queries still fall back to the default pulse loader.
- Mechanism loader SVG was enlarged again from `420x220` to `560x300` with `viewBox="0 0 560 300"` and responsive `max-width: calc(100vw - 48px)`.
- Retro target DNA no longer uses the slide-in animation; the target DNA remains static while RNA/RT moves to the target site and the target opening/copy animations continue.
- DNA transposon loader no longer renders a separate inserted-copy group. The moving TE segment itself travels to the target site and remains there, avoiding the brief double-TE state.

Verification passed:

```powershell
php -l preview.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_node_action_card_ux.py
python scripts/checks/check_g6_evidence_support_ux.py
python scripts/checks/check_g6_te_mechanism_loader.py
```

Residual risks:

- `check_g6_te_mechanism_loader.py` filters Playwright `ERR_ABORTED` noise caused by its own diagnostic page reload/request interruption; regular browser smoke still covers runtime load stability.
- Loader kind detection remains intentionally conservative. Ambiguous TE names fall back to the default loader rather than guessing.

## Amendment - TE Mechanism Loader Biology/Visual Refinement - 2026-05-22

Biology/visual refinement completed without changing graph API, Neo4j, graph data logic, Jump/Expand/Details semantics, edge evidence table, CSV behavior, third-party dependencies, or default/unknown loader behavior.

Changes:

- Removed source-to-target arrow paths from both retrotransposon and DNA transposon loaders. Direction is now communicated by the actual RNA/RT or TE segment motion.
- Retro loader keeps source DNA intact for copy-and-paste inspired behavior. Target DNA is fixed in place and split into left/right gray segments that open a visible insertion gap. The TE/cDNA copy appears in that gap.
- DNA transposon loader no longer uses slash cut marks. Source donor DNA is split into left/right segments around the TE, and those segments separate as the TE leaves.
- DNA transposon target DNA is fixed in place and split into left/right gap segments. It no longer slides as a whole.
- DNA transposon moving TE keeps rect and label in one group and now follows two motion phases only: lift upward, then move right/down to the target gap and stay there. No inserted-copy duplicate is rendered.
- Reduced-motion mode remains supported with static readable states.

Verification passed:

```powershell
php -l preview.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_node_action_card_ux.py
python scripts/checks/check_g6_evidence_support_ux.py
python scripts/checks/check_g6_te_mechanism_loader.py
```

Residual risks:

- The loader remains a simplified mechanism-inspired animation, not a detailed biochemical mechanism.
- DNA transposon runtime coverage still uses diagnostic rendering unless a stable DNA transposon query fixture is added later.

## Amendment - TE Mechanism Loader Root-Cause Refinement - 2026-05-22

Root-cause refinement completed without changing graph API, Neo4j, graph data logic, Jump/Expand/Details semantics, edge evidence table, CSV behavior, third-party dependencies, or default/unknown loader behavior.

Changes:

- Fixed loader classification at the normalization layer instead of adding a one-off `Class I: Retrotransposons` patch. Loader text is now normalized for punctuation, plural `transposons`/`retrotransposons`, and category phrases before keyword matching.
- Category/taxonomy-style inputs now classify semantically: `Class I`, `Retrotransposons`, `LTR retrotransposon`, `LINE1`, `L1HS` -> retro; `Class II`, `DNA transposons`, `Tc1-Mariner` -> DNA transposon. Unknown/non-TE labels still fall back to the default loader.
- Removed the object-node early default path that discarded taxonomy/category labels before semantic classification. Non-matching objects still return default.
- Retro target DNA now uses left/right gap segments aligned to the inserted copy edges, with the inserted copy centered on the target DNA backbone and rendered above the target opening arc.
- DNA transposon source DNA starts visually connected to the TE segment using left/right donor segments that separate as the TE leaves.
- DNA transposon target DNA gap is aligned to the moving TE final coordinates. The target/opening group renders underneath the moving TE so the arc cannot cover the TE label.
- The DNA loader still uses one moving TE only; no inserted-copy duplicate and no source-to-target arrow paths.

Verification passed:

```powershell
php -l preview.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_node_action_card_ux.py
python scripts/checks/check_g6_evidence_support_ux.py
python scripts/checks/check_g6_te_mechanism_loader.py
```

Residual risks:

- The mechanism loaders remain simplified explanatory animation, not a complete biological mechanism diagram.
- DNA transposon runtime coverage still depends on diagnostic rendering unless a stable DNA transposon graph query fixture is added later.
