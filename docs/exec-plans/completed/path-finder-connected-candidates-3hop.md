# Path Finder Connected Candidates 3-hop

## Goal

Add connected candidate dropdowns to `path_finder.php` so that after a user selects one endpoint, the opposite endpoint selector prefers entities connected to that endpoint within the current Path Finder supported depth of 1-3 hops.

## Scope

- Add or update a lightweight read-only API for Path Finder candidate suggestions.
- Preserve existing Path Finder search semantics.
- Preserve Neo4j data; no writes.
- Display candidates grouped by path distance:
  - Direct connection
  - 2-hop path
  - 3-hop path
- Keep an all-entities fallback for the initial state or when connected candidates are unavailable.

## Constraints

- Neo4j runtime target remains `tekg3`.
- No Neo4j writes.
- Do not alter graph API semantics.
- Do not touch `api/agent` or agent pages.
- Do not allow an LLM to guess missing evidence or journal metrics.
- Path Finder browser smoke must cover dropdown filtering and a no-result-avoidance case where possible.

## Work Plan

1. Explore existing Path Finder frontend selector implementation.
2. Explore existing API/data helpers for entity suggestions and path search.
3. Explore current checks and completed plans related to Path Finder/entity dropdowns.
4. Implement a read-only candidate API or extend an existing Path Finder API if the local pattern clearly prefers extension.
5. Update Path Finder frontend so the opposite dropdown queries connected candidates when one endpoint is selected.
6. Group suggestions by hop distance through 3 hops, with clear labels.
7. Add focused checks for PHP syntax, JS syntax, API contract, and browser behavior.

## Validation Plan

- `php -l path_finder.php`
- `php -l <new-or-updated-api-file>`
- JS syntax check for changed Path Finder frontend files.
- Existing Path Finder checks if present.
- New or updated browser smoke for 3-hop connected candidate grouping.

## Status Log

- 2026-05-30: Created active plan before implementation.
- 2026-05-30: Added `scripts/checks/check_path_finder_connected_candidates.py` before implementation. Initial run failed as expected because `view=connected_candidates` returned HTTP 400.
- 2026-05-30: Added read-only `connected_candidates` view in `api/path_finder.php` and `PathFinderService::suggestConnectedCandidates()` using `BIO_RELATION*1..3`, excluding `Paper` nodes and returning `min_hop`, `path_count`, and `best_path_pmid_count`.
- 2026-05-30: Wired `path_finder.php` autocomplete roots so each side uses the opposite side as the connected source when present, while retaining `view=entities` when the opposite input is empty.
- 2026-05-30: Updated `assets/js/components/te-autocomplete.js` to preserve connected-candidate ordering, render `Direct connection`, `2-hop path`, and `3-hop path` groups, and keep existing selection behavior.
- 2026-05-30: Added lightweight group/meta styles in `assets/css/components/te-autocomplete.css`.
- 2026-05-30: Validation observed so far: `php -l api/path_finder.php`, `php -l api/path_finder_service.php`, `php -l path_finder.php`, `node --check assets/js/components/te-autocomplete.js`, and `python scripts/checks/check_path_finder_connected_candidates.py` passed. The API smoke found `TE:AluJb -> Disease` with 20 connected candidates.
- 2026-05-30: Also ran `node --check assets/js/pages/path_finder.js` and `python scripts/checks/check_path_finder.py`; both passed. No existing Path Finder browser smoke was present under `scripts/checks`, so browser dropdown grouping was not separately automated in this pass.
- 2026-05-30: Reviewer flagged missing fallback when connected candidates are empty/unavailable. Fixed `assets/js/components/te-autocomplete.js` so connected mode falls back to existing `view=entities` results on empty result or request failure.
- 2026-05-30: Replaced the focused check with API + Playwright coverage. `scripts/checks/check_path_finder_connected_candidates.py` now verifies `connected_candidates` contract, browser dropdown hop grouping, and invalid-source fallback to all target entities.
- 2026-05-30: Final validation passed:
  - `php -l api/path_finder.php`
  - `php -l api/path_finder_service.php`
  - `php -l path_finder.php`
  - `node --check assets/js/components/te-autocomplete.js`
  - `node --check assets/js/pages/path_finder.js`
  - `python -m py_compile scripts/checks/check_path_finder_connected_candidates.py`
  - `python scripts/checks/check_path_finder.py`
  - `python scripts/checks/check_path_finder_connected_candidates.py`
  - `python scripts/checks/check_te_autocomplete_smoke.py`
  - `python scripts/checks/check_neo4j_tekg3.py`
  - `python scripts/checks/check_api_contracts.py`
  - `git diff --check -- api/path_finder.php api/path_finder_service.php path_finder.php assets/js/components/te-autocomplete.js assets/css/components/te-autocomplete.css scripts/checks/check_path_finder_connected_candidates.py docs/exec-plans/active/path-finder-connected-candidates-3hop.md`
- 2026-05-30: Residual risk: `BIO_RELATION*1..3` connected candidate traversal can be expensive for high-degree source nodes because `LIMIT` applies after path aggregation. The first version constrains depth to `1..3`, clamps result limit, preserves PHP cURL timeout, and falls back to all-entity suggestions if the connected query fails. Future optimization can precompute candidate neighborhoods or add a more selective Neo4j query strategy if latency becomes visible.
- 2026-05-30: Follow-up UI refinement: removed separate hop group headers from the dropdown. Each connected candidate now shows hop distance, total path count, and total unique PMID count in the metadata line under the candidate name. The API now returns `pmid_count` from all unique PMIDs across all candidate paths instead of a best-path PMID count.
