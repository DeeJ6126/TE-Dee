# Database Contract

This document records the current TE-KG database contract. Use it to verify
database facts before modifying business logic.

## Neo4j Contract

- The active database name must resolve to `tekg3`.
- `RETURN 1` must execute successfully.
- `TE` node count must be greater than zero.
- `BIO_RELATION` relationship count must be greater than zero.
- Representative TEs must return at least partial results: `L1HS`, `AluJb`,
  and `SVA`.

## API Contract

- `api/health.php` should return `ok=true`, `neo4j_database=tekg3`, and
  `neo4j_reachable=true`.
- `api/graph.php?q=LINE1` should return `ok=true`, an `anchor`, and non-empty
  `elements`.
- `api/taxonomy.php?view=tree&source=rmsk_repbase` should return tree/root data.
- `api/taxonomy.php?names=L1HS,AluJb,SVA` should return `source=tekg3` and
  parseable items.
- `api/browse.php?view=items` should return `source=mysql`, exactly 276 unique
  items from the active `tekg_catalog` version, and include `AluYb10`.

## Browse MySQL Contract

- `tekg_catalog` must contain exactly one active catalog version.
- The active version must contain 276 case-insensitively unique names derived
  from the processed `db_to_repbase` mapping keys.
- Browse runtime must not fall back to local JSON or Neo4j taxonomy items.
- Previous catalog versions remain available for explicit reactivation.

## Forbidden Regressions

- Active runtime must not fall back to `tekg2` or `tekg21`.
- Missing local configuration should produce an explicit setup/config error
  instead of silently switching to an old database.

## Related Checks

- `scripts/checks/check_neo4j_tekg3.py`
- `scripts/checks/check_api_contracts.py`
- `scripts/checks/check_runtime_db_config.py`
- `scripts/checks/check_no_legacy_db_fallback.py`
- `scripts/checks/check_browse_catalog_mysql_contract.php`
- `scripts/checks/check_browse_mysql_repository.php`
- `scripts/checks/check_browse_mysql_api.py`
