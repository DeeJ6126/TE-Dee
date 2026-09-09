# G6 Evidence Support UX v1

## Background

Graph API evidence support contract and Neo4j relation aggregation were already completed before this stage. `BIO_RELATION` edge payloads exposed `support_*` aggregate fields, but G6 did not yet show these values visually and edge click details still showed only basic PMID evidence.

This stage makes evidence support visible and usable in `preview.php` without changing Neo4j data, taxonomy, agent, expression, or unrelated pages.

## Goals

- Encode edge support using existing `support_*` fields.
- Show an evidence table when a user clicks an edge.
- Link each PMID to PubMed.
- Let users download the selected edge evidence table as CSV when the table has more than 10 rows.
- Keep IF terminology explicit as IF / Journal Impact Factor, never confidence.

## Changed Files

- `api/graph_service.php`
- `assets/js/renderers/g6/index-g6-shared.js`
- `assets/js/renderers/g6/index-g6-embed.js`
- `assets/js/renderers/g6/index-g6.bootstrap.js`
- `assets/css/pages/preview.css`
- `scripts/checks/check_graph_api_edge_evidence_records.py`
- `scripts/checks/check_g6_evidence_support_ux.py`
- `docs/RELIABILITY.md`
- `docs/exec-plans/tech-debt-tracker.md`

## API Contract

Each graph edge keeps the previous fields, including `pmids` and `support_*`, and now includes eager `evidence_records`.

`evidence_records` is intentionally small and does not include abstract text:

- `pmid`
- `pubmed_url`
- `pubmed_title`
- `pubmed_journal_title`
- `pubmed_publication_year`
- `journal_metric_value`
- `journal_metric_source`
- `journal_metric_year`
- `journal_jcr_quartile`
- `journal_metric_match_method`

Implementation notes:

- `GraphService::buildElements()` collects all relation PMIDs for the current graph payload.
- `GraphService::loadEvidenceRecordsByPmids()` queries `Paper` metadata in batches.
- Missing `Paper` metadata still produces a minimal record with PMID and PubMed URL, while metadata fields remain null.
- No lazy endpoint was added.

## G6 Edge Visual Encoding

- Edge width uses `support_pmid_count` with a bounded log rule.
- Edge opacity uses `support_metric_coverage` with a visible lower bound, so coverage `0` edges remain visible.
- Synthetic / taxonomy-style edges keep lightweight styling.
- No field or UI label uses confidence wording.

## Edge Evidence Table

Clicking an edge renders an evidence table in the existing detail/evidence area.

Columns:

- PMID
- Year
- Journal
- IF
- JCR
- Match
- Title

PMID links use `https://pubmed.ncbi.nlm.nih.gov/{pmid}/`. Missing IF values render as `—`, not `0`. Long titles are visually truncated but keep the full text in the `title` attribute.

## CSV Download

When an edge has more than 10 evidence records, the detail area shows `Download CSV`.

CSV contains full values for:

- `pmid`
- `pubmed_url`
- `pubmed_publication_year`
- `pubmed_journal_title`
- `journal_metric_value`
- `journal_metric_source`
- `journal_metric_year`
- `journal_jcr_quartile`
- `journal_metric_match_method`
- `pubmed_title`

The download is handled by parent-page event delegation from the detail area. No third-party library was added.

## Verification

Passed:

```powershell
php -l api/graph.php
php -l api/graph_service.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_api_contracts.py
python scripts/checks/check_graph_api_evidence_support.py
python scripts/checks/check_graph_api_edge_evidence_records.py
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_evidence_support_ux.py
```

Key evidence:

- `check_graph_api_edge_evidence_records.py` selected a LINE1 edge with 65 PMIDs and verified `evidence_records`.
- `check_g6_evidence_support_ux.py` verified the evidence table, PMID link, bounded edge visuals, no confidence wording, and CSV content.
- Existing `check_g6_browser_smoke.py` still passed.

## Residual Risks

- `evidence_records` are eager in the graph payload. Current LINE1 payload is acceptable, but very large future subgraphs may need a lazy endpoint.
- Browser smoke uses the diagnostic `inspectEdge(edgeId)` bridge to trigger the same detail rendering path as real edge click. It is not a user-facing control.
- Visual encoding is v1 only; it does not add a legend explaining edge width/opacity yet.
- Evidence CSV covers the selected edge only, not all visible edges.

## Next Recommendations

- Add an evidence-support legend if users need an explicit explanation of width and opacity.
- Consider a lazy edge-evidence endpoint only if eager payload size causes measurable browser/API regression.
- Design downstream G6 visual polish separately; do not mix it with API contract changes.

## Amendment - Edge Card Evidence Placement - 2026-05-22

Follow-up UI adjustment completed after v1 archive:

- Evidence table is no longer rendered in the lower detail area.
- Edge click still opens/updates the edge inspect card.
- Expanding the edge inspect card renders the evidence table inside the card's `PubMed` section.
- `Download CSV` appears on the PubMed section header only when the selected edge has more than 10 evidence records.
- CSV field content and API contract remain unchanged.
- Edge width/opacity evidence visual encoding remains unchanged.
- Verification passed:

```powershell
php -l preview.php
php -l api/graph.php
php -l api/graph_service.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_graph_api_edge_evidence_records.py
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_evidence_support_ux.py
```

## Amendment - Hide Legacy Detail And Fit Evidence Table - 2026-05-22

Follow-up preview/G6 UI adjustment completed:

- The legacy lower detail area is visually hidden again and does not occupy obvious graph layout space.
- Edge/card information remains available through node/edge inspect cards.
- The edge evidence table remains in the expanded edge card `PubMed` section.
- The evidence table no longer relies on horizontal scrolling. It uses fixed table layout with narrow PMID, Year, IF, JCR, and Match columns, and bounded Journal/Title columns with tooltip-backed truncation.
- CSV download content remains unchanged and continues to include full title/journal values.
- Verification passed:

```powershell
php -l preview.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_evidence_support_ux.py
```
