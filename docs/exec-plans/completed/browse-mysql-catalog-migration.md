# Browse MySQL Catalog Migration

## Background

Browse currently embeds 276 RepBase-aligned rows from a processed JSON file,
while its autocomplete still reads the Neo4j taxonomy catalog. This migration
makes a dedicated MySQL catalog the single runtime source for both surfaces
without changing Neo4j or the other application modes.

## Goals

- Create the independent `tekg_catalog` MySQL database.
- Import a versioned, reversible 276-row Browse snapshot.
- Serve the Browse table, filters, and autocomplete from one MySQL API payload.
- Preserve every Browse row link to the existing Search detail page.
- Fail explicitly when the catalog is unavailable; never fall back at runtime.

## Non-Goals

- Changing Neo4j `tekg3`, taxonomy, Graph, Path, Expression, Co-expression, or
  Agent data sources.
- Migrating Search detail sequence, genome, or local graph data.
- Using `data/TE_names.txt` as a Browse runtime source.

## Implementation Steps

1. Add versioned InnoDB schema and a validated transactional importer.
2. Import and activate the initial catalog, recording hashes and provenance.
3. Add an isolated Browse repository and read-only API.
4. Replace PHP-embedded rows with one frontend API request shared by table and
   autocomplete.
5. Add database, API, static, and browser contract checks.
6. Record verification evidence and move this plan to completed.

## Acceptance Criteria

- Exactly one active catalog version contains 276 case-insensitively unique
  names derived from `db_to_repbase` keys.
- API, table, filters, and autocomplete expose that same set.
- `AluYb10` is searchable in Browse and all rows retain Search links.
- Browse production runtime contains no JSON or Neo4j fallback.
- Existing unrelated runtime sources and user edits remain unchanged.

## Execution Log

- 2026-07-27: Plan approved. Existing dirty worktree recorded and preserved.
- 2026-07-27: Created MySQL `tekg_catalog` and activated version
  `browse-20260727T104133Z-7dc80d8143dd-35ff7f` with 276 rows and 75 Neo4j
  taxonomy matches. The Neo4j database was not modified.
- 2026-07-27: Migrated the Browse table, filters, and autocomplete to the
  MySQL-only API; retained all Search detail links and added explicit failure
  states with no runtime fallback.
- 2026-07-27: PHP/JavaScript syntax, MySQL catalog, repository, live HTTP API,
  static runtime, legacy fallback, and browser acceptance checks passed. The
  broader taxonomy truth check still reports its pre-existing `index.php`
  homepage-helper expectation and was not changed by this migration.

## Residual Risks

- Browse lineage fields are a provenance-tagged display snapshot, not a second
  taxonomy runtime truth.
