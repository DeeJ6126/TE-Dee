# Home Dataset Status Donut Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Execute this plan task-by-task in the current worker session. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the homepage Dataset Status ring with live, read-only `tekg3` graph statistics rendered as SVG donuts.

**Architecture:** `api/home_stats.php` will be a small GET/OPTIONS JSON endpoint that loads `api/runtime_config.php`, rejects non-`tekg3` Neo4j targets, and issues read-only Cypher through the Neo4j HTTP transaction endpoint. `index.php` will keep the existing Dataset Status layout shell but remove server-side guessed/stat-derived chart payloads; `assets/js/pages/index.js` will fetch the new endpoint, render entity and relation donuts in SVG, and show a failure state without fake numbers.

**Tech Stack:** PHP 8.3, Neo4j HTTP Cypher endpoint, browser JavaScript, inline SVG, existing homepage CSS.

---

## Goal

Rework the homepage Dataset Status area while preserving the existing stats concept. The donut charts must be driven by live, read-only Neo4j `tekg3` statistics, not hardcoded numbers or inferred values.

## Scope

- Add a lightweight read-only endpoint: `api/home_stats.php`.
- Return `nodes_total`, `relationships_total`, `entity_composition`, `relation_composition`, and `generated_at`.
- Use Neo4j labels for Entity Composition.
- Use `BIO_RELATION.predicate` Top 5 plus `Other` for Relation Composition.
- Update homepage frontend to draw SVG donut charts without third-party libraries.
- Show legend, counts, percentages, loading animation, and failure fallback.

## Constraints

- Do not write to Neo4j.
- Do not change graph API semantics.
- Do not add a second taxonomy runtime truth source.
- Do not use guessed values.
- Keep Neo4j target on `tekg3`.

## Workflow

- [x] Read architecture entry docs and existing homepage/API patterns.
- [x] Confirm RED: `python scripts/checks/check_home_stats_api.py` fails with HTTP 404 for `api/home_stats.php`.
- [x] Add `api/home_stats.php` with read-only Neo4j queries, `tekg3` guard, JSON/CORS method handling, and normalized composition rows.
- [x] Update `index.php` Dataset Status markup to expose live chart containers and loading/failure copy without hardcoded chart numbers.
- [x] Update `assets/js/pages/index.js` to fetch `api/home_stats.php`, animate SVG donut segments, show legends/counts/percentages, and avoid fabricated fallback values.
- [x] Update `assets/css/pages/index.css` for responsive donut/legend layout and loading/error states.
- [x] Run static and read-only verification commands and record results here.

## Verification Plan

- `php -l api/home_stats.php`
- `php -l index.php`
- `node --check assets/js/pages/index.js`
- `python scripts/checks/check_home_stats_api.py`
- Relevant existing runtime checks if touched files require them.

## Verification Results - 2026-05-23

RED evidence:

```powershell
python scripts/checks/check_home_stats_api.py
```

Result:

```text
[FAIL] HTTP 404 for http://127.0.0.1/TE-/api/home_stats.php
```

Final verification:

```powershell
php -l api/home_stats.php
php -l index.php
node --check assets/js/pages/index.js
python -m py_compile scripts/checks/check_home_stats_api.py scripts/checks/check_homepage_dataset_status_smoke.py
python scripts/checks/check_home_stats_api.py
python scripts/checks/check_homepage_dataset_status_smoke.py
python scripts/checks/check_api_contracts.py
python scripts/checks/check_runtime_db_config.py
python scripts/checks/check_no_legacy_db_fallback.py
```

Results:

```text
No syntax errors detected in api/home_stats.php
No syntax errors detected in index.php
node --check assets/js/pages/index.js: exit 0
python -m py_compile ...: exit 0
[OK] home stats API contract passed: nodes=11415, relationships=13696, entities=15, relations=6
[OK] homepage Dataset Status smoke passed: entity_segments=15, relation_segments=6
[OK] api/health.php contract passed
[OK] api/graph.php?q=LINE1 contract passed
[OK] api/graph.php same-label expand disambiguation contract passed
[OK] api/taxonomy.php file tree contract passed
[OK] api/taxonomy.php items contract passed: AluJb, L1HS, SVA
PASS runtime DB config
[OK] No legacy tekg2/tekg21 runtime fallback found in active files.
```

Review:

- Reviewer found performance/check-strength/cache-control/process issues, no blocking correctness issue.
- Follow-up fixed API to use one Neo4j HTTP multi-statement request, added `Cache-Control: no-store`, and strengthened `check_home_stats_api.py` against direct Neo4j invariants.
- Second reviewer found no blocking issue and confirmed no `api/graph.php` / `api/graph_service.php` semantic changes.

## Completion Notes

- `api/home_stats.php` is a read-only `GET/OPTIONS` JSON endpoint and rejects non-`tekg3` runtime targets.
- Endpoint returns live `nodes_total`, `relationships_total`, `entity_composition`, `relation_composition`, and `generated_at`.
- Entity composition uses Neo4j labels, counting each node once via its first label.
- Relation composition uses `BIO_RELATION.predicate` Top 5 plus `Other`.
- Homepage Dataset Status fetches the endpoint, renders SVG donut charts without third-party libraries, shows legends/counts/percentages, animates loading, and falls back without fabricated numbers on failure.
- No Neo4j data writes were performed.
- Graph API semantics were not changed.
