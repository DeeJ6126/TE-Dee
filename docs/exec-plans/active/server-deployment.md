# TE-KG Server Deployment

## Background

TE-KG is moving from the local Windows/WAMP environment to the assigned Linux
server. The application combines PHP, Apache, MySQL, Neo4j, and runtime data
that are intentionally not all stored in Git.

## Goals

- Deploy the reviewed `main` revision under `/app/tekg`.
- Store large static runtime assets under `/data/tekg`.
- Restore the active MySQL and Neo4j datasets without moving offline analysis
  inputs that are not used at runtime.
- Preserve checksums, database backups, and a reversible cutover path.
- Expose the application at `/TE-/` after staged verification.

## Non-Goals

- Re-run the GTEx or co-expression analysis pipelines.
- Copy local caches, logs, raw eQTL inputs, or historical Neo4j databases.
- Modify unrelated projects on the server.
- Expose MySQL, Neo4j, or internal model services publicly.

## Target Layout

```text
/app/tekg/app
/app/tekg/shared/config
/app/tekg/shared/cache
/app/tekg/shared/logs
/app/tekg/shared/neo4j-data
/app/tekg/services
/app/tekg/deploy

/data/tekg/runtime/JBrowse
/data/tekg/runtime/bulk_expression_web
/data/tekg/runtime/coexpression/feature_annotation
/data/tekg/staging/mysql
/data/tekg/staging/neo4j
/data/tekg/backups
/data/tekg/manifests
```

## Implementation Steps

1. Remove machine-local secrets from Git tracking, restore the RMSK Git LFS
   rule, and verify a clean reproducible checkout.
2. Initialize the application and data directory layout without touching other
   server projects.
3. Clone the pinned `main` revision and run static syntax checks.
4. Transfer versioned runtime data groups with SHA-256 manifests and connect
   them to the application through stable paths.
5. Export and restore `tekg_catalog` and `tekg_expression`, excluding the
   unused expression staging table, then validate active versions and counts.
6. Dump local `tekg3`, install the matching Neo4j release with Java 21, restore
   the dump, and validate graph counts.
7. Create the server-local runtime configuration and least-privilege database
   credentials.
8. Prepare the Apache `/TE-/` configuration and complete the administrator
   actions required for PHP 8.4, an in-container Apache reload, and persistent
   Neo4j management through the container's existing supervisord process.
9. Run API, static-file, representative-TE, and user-flow checks before public
   cutover.

## Plan Change Rule

If observed server state requires a material deviation, stop before applying
the deviation, document the reason and impact, and obtain user approval.

## Acceptance Criteria

- The deployed Git revision is recorded and the checkout is clean.
- Runtime assets match their recorded SHA-256 checksums.
- MySQL has one active Browse version, one active co-expression version, and
  one active validated eQTL version with the expected production counts.
- Neo4j serves `tekg3` and matches the recorded node and relationship counts.
- Apache serves `/TE-/` through PHP 8.4 without exposing database ports.
- Browse, Search, Graph, Path, Expression, Variant, and Agent smoke checks pass.
- A rollback path is retained for code, static data, MySQL, Neo4j, and Apache.

## Verification Commands

Commands and captured results will be appended during execution. Server-side
system changes that require administrator privileges are prepared in
`/app/tekg/deploy` and applied by the user or server administrator.

## Execution Log

- 2026-09-05: Server investigation completed. MySQL 8.0.42 and persistent
  storage were confirmed by the user. `/data/tekg` CRUD access is available.
  PHP 8.4 installation is pending. Formal migration execution started.
- 2026-09-05: The deployment target was confirmed to be a Docker container
  whose PID 1 is supervisord rather than systemd. With user approval, the
  service-management part of step 8 was changed from systemd to the container's
  existing supervisord/Apache controls; database and application goals are
  unchanged.
- 2026-09-07: MySQL `tekg_catalog` and `tekg_expression` were restored and
  validated, including 50 GTEx tissues, 664,555 variants, 10,670,298
  variant-gene-tissue associations, and 849 co-expression networks.
- 2026-09-07: Neo4j 2026.02.2 loaded `tekg3` with 11,415 nodes and 13,696
  relationships. Java 21, Neo4j, and the independent PHP 8.4.25 FPM service
  are managed by the container's existing supervisord process.
- 2026-09-07: Apache began serving `/TE-/` through PHP 8.4 FPM. Browse,
  Search, Graph, TE-Gene, co-expression, Path, Expression, Variant, About,
  JBrowse assets, MP4 assets, and a live DeepSeek Agent request passed smoke
  verification. Direct access to `api/config.local.php` returned HTTP 403.
- 2026-09-07: Missing taxonomy runtime text files were restored. The default
  tree returned 1,632 nodes and 1,631 edges; the full tree returned 1,666 nodes
  and 1,665 edges. A PHP 8.4 `fgetcsv()` deprecation was fixed without changing
  the co-expression response contract.

## Residual Risks

- The filesystem containing `/app` and `/var/lib/mysql` has about 6.7 GiB free;
  large static files, build trees, and transfer archives must remain under
  `/data/tekg`, and free space should be monitored.
- The reverse SSH proxy depends on the user's Windows SSH session and is not a
  durable production egress path for Agent/DeepThink.
- Secrets committed in earlier Git history require credential rotation even
  after the live configuration file is removed from current tracking.
