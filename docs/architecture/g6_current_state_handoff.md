# G6 Current State Handoff

Last updated: 2026-05-22

This handoff summarizes the current `preview.php` / G6 graph page state for the next Codex session. It is not a replacement for `AGENTS.md`, `ARCHITECTURE.md`, or the completed execution plans.

## Current Entrypoints

- Page: `preview.php`
- Graph API: `api/graph.php`, `api/graph_service.php`
- Taxonomy API: `api/taxonomy.php`
- G6 runtime: `assets/js/renderers/g6/`
- Reliability notes: `docs/RELIABILITY.md`
- Debt tracker: `docs/exec-plans/tech-debt-tracker.md`

## User-Visible G6 Behavior

- The default graph layout remains Force. No layout mode switch is currently implemented.
- Global user-visible `Fixed view` and `Expand mode` controls are hidden. Their internal compatibility state is intentionally retained.
- Clicking a node opens a node action card only. It does not automatically jump or expand.
- Node action card buttons:
  - `Jump`: loads a node-centered graph.
  - `Expand`: expands the clicked node neighborhood in the current graph and preserves same-label disambiguation using `expand_node_id`, `expand_node_type`, and `expand_query`.
  - `Details`: expands/shows node details without graph mutation.
- Clicking an edge opens an edge card. Its `PubMed` section contains the evidence table.
- Edge evidence table columns are `PMID`, `Year`, `Journal`, `IF`, `JCR`, `Match`, and `Title`. PMID values link to PubMed.
- Edge evidence CSV download is shown for selected edges with more than 10 evidence rows. CSV uses full evidence values, not truncated UI text.
- Edges use evidence support visual encoding:
  - width maps to `support_pmid_count`
  - opacity maps to `support_metric_coverage`
  - coverage `0` edges remain visible
  - IF fields must not be called confidence
- TE mechanism loaders exist for clear retrotransposon and DNA transposon labels. They are mechanism-inspired loaders, not strict biological diagrams. Unknown/non-TE labels use the default loader.
- TE tree mode remains available as the default preview landing view. Deep taxonomy edges are restored; folded category nodes stay in tree mode instead of being routed to empty graph queries.

## Data Chain

Current evidence-support data path:

```text
PubMed metadata JSONL
-> enriched Neo4j Paper nodes
-> BIO_RELATION support_* aggregate properties
-> api/graph.php edge payload
-> G6 edge visual encoding and evidence table
```

Important constraints:

- `evidence_records` excludes abstracts.
- Journal metric values are internal v1 metrics from `impact_factor_package_2025`, not an official JCR export.
- Do not infer or guess missing IF values.
- Do not rename IF-derived fields to confidence.

## Key Checks

Run these before claiming the G6 page is healthy:

```powershell
php -l preview.php
php -l api/graph.php
php -l api/graph_service.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_node_action_card_ux.py
python scripts/checks/check_g6_evidence_support_ux.py
python scripts/checks/check_g6_te_mechanism_loader.py
python scripts/checks/check_g6_te_tree_load_regression.py
```

If a manual browser path gets stuck loading, do not guess. Capture:

- graph request URL and query parameters
- HTTP status
- API node/edge counts
- parent loader state
- iframe canvas/render state
- console errors and failed requests
- parent `currentGraphQuery`
- iframe graph state

## Completed Plans To Read First

- `docs/exec-plans/completed/g6-evidence-support-ux-v1.md`
- `docs/exec-plans/completed/g6-node-action-card-ux.md`
- `docs/exec-plans/completed/g6-te-tree-load-regression.md`
- `docs/exec-plans/completed/graph-api-evidence-support-contract.md`
- `docs/exec-plans/completed/journal-metrics-neo4j-import-plan.md`
- `docs/exec-plans/completed/journal-metrics-relation-aggregation.md`
- `docs/exec-plans/completed/g6-subgraph-export.md`

## Do Not Disturb Without A New Plan

- Do not change Neo4j data from a G6/UI task.
- Do not change taxonomy runtime truth source.
- Do not change expression runtime paths.
- Do not replace Force layout or add layout modes casually.
- Do not rewrite the G6 runtime to fix a narrow regression.
- Do not continue loader visual polish when the issue is graph loading lifecycle.
- Do not add a lazy evidence endpoint unless large graph checks show eager `evidence_records` is a real performance problem.

## Recommended Next Plans

- Category-centered graph contract for taxonomy/category labels.
- TE tree search/focus for deep taxonomy nodes.
- Node action card expanded-state and collapse/reset affordance.
- Loader mechanism scientific polish only after loader lifecycle stays green.
- Large graph `evidence_records` lazy endpoint only if payload performance regresses.
- Journal metric manual review and official JCR replacement path before external publication claims.
