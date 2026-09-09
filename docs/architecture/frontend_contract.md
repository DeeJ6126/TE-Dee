# Frontend Contract

This document records current non-agent frontend constraints. The goal is to
keep page changes consistent and avoid creating new visual systems or runtime
forks without a plan.

## Page Structure

- Root runtime pages remain in the repository root.
- Typical pages include `index.php`, `browse.php`, `preview.php`,
  `expression.php`, and `path_finder.php`.
- New page work should prefer existing `head.php`, `proto-container`, panel,
  table, button, and filter structures.

## Graph Page

- `preview.php` is the current TE-KG / Network Explorer entrypoint.
- G6 legend filtering should apply through an explicit Apply action, not redraw
  immediately on every checkbox change.
- `Show labels` defaults to off.
- `Fixed view` and `Expand mode` are state controls. They must not reload the
  graph into a blank state.

## Homepage

- Homepage taxonomy and ring charts are data entrypoints, not decoration.
- Homepage statistics should prefer lightweight APIs or caches and avoid heavy
  queries during page load.
- Changing the TE classification level redraws only the center TE chart. The
  Entity and Relation charts retain their existing DOM and animation state.
- Dataset Status cards share the height of the longest current card so their
  lower borders align. Shorter legends are vertically centered within their
  remaining lower-card space.

## Browse / Expression

- Browse remains TE-oriented; do not expand it into a full all-entity search page
  without a dedicated plan.
- Expression runtime data root is `data/bulk_expression_web`.
- Expression and Graph integration can be added gradually through URL
  parameters and overlay APIs.

## Related Checks

- `scripts/checks/check_docs_freshness.py`
- `scripts/checks/check_g6_static_contract.py`
- `scripts/checks/check_path_finder.py`
