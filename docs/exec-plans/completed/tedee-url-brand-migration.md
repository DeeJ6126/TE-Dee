# TE-Dee URL and Public Brand Migration

## Background

The public product name is now **TE-Dee** (Transposable Element Data Exploration Engine), with the canonical URL base `/tedee`. The deployed application currently uses `/TE-`, while internal infrastructure deliberately retains the `tekg` engineering name.

## Goals

- Serve `/TE-/` and `/tedee/` concurrently during migration.
- Make `/tedee` redirect to `/tedee/`.
- Establish `TEKG_APP_URL_BASE` as the PHP URL source of truth and expose it to browser code through `assets/js/tekg_paths.php`.
- Remove runtime fallbacks that hard-code `/TE-/`.
- Change public-facing product text to TE-Dee and its full name without renaming internal identifiers or infrastructure.
- Verify the complete application at `http://127.0.0.1/tedee/` before retiring the legacy Alias.

## Non-Goals

- Do not rename `/app/tekg`, `/data/tekg`, MySQL databases, Supervisor programs, PHP constants/functions, JavaScript namespaces, or Neo4j databases.
- Do not configure the host gateway, public HTTPS, DNS, or certificates.
- Do not remove the legacy `/TE-/` route during the validation phase.

## File Scope

- Apache deployment configuration under `deploy/apache/` and `/etc/apache2/conf-enabled/tekg.conf`.
- Shared URL helpers in `path_config.php` and `assets/js/tekg_paths.php`.
- Runtime files containing hard-coded `/TE-/` fallbacks.
- User-visible page, graph export, Agent, About, and maintainer README branding where the text names the public product.
- Focused checks under `scripts/checks/` when needed to prevent URL regressions.

## Implementation Steps

1. Install a dual-Alias Apache configuration, run `apache2ctl configtest`, and gracefully reload only after `Syntax OK`.
2. Change `TEKG_APP_URL_BASE` to `/tedee` and replace hard-coded runtime paths with PHP or browser path helpers.
3. Update public-facing TE-KG branding to TE-Dee while preserving internal `TEKG` identifiers.
4. Run PHP, JavaScript, static-contract, and old-path scans locally.
5. Commit and push the migration, update the server checkout, and validate `/tedee/` pages, APIs, media, JBrowse, and Agent/DeepThink routing.
6. After successful validation, replace the legacy Alias with a 301 redirect to `/tedee/`, then repeat Apache and HTTP verification.

## Acceptance Criteria

- `/tedee` redirects to `/tedee/` and `/tedee/` serves through PHP 8.4 FPM.
- The main pages, APIs, assets, Variant, Graph, Expression, Agent, media, and JBrowse resolve beneath `/tedee/`.
- Browser-visible requests no longer depend on `/TE-/`.
- No internal service, storage path, database, or namespace is renamed.
- The old route remains available until validation succeeds, then redirects permanently to the equivalent `/tedee/` location.

## Verification Commands

- `php -l` on changed PHP files.
- `node --check` on changed JavaScript files.
- Existing runtime, API, G6, and path checks relevant to changed files.
- `rg` scan for runtime `/TE-` literals and public `TE-KG` strings.
- `apache2ctl configtest` before each graceful reload.
- Local HTTP checks against `http://127.0.0.1/tedee/`.

## Execution Log

- 2026-09-07: Plan created; internal engineering identifiers explicitly frozen.
- 2026-09-07: Installed and verified the dual `/TE-/` and `/tedee/` Apache Alias configuration. Both routes returned HTTP 200, `/tedee` redirected to `/tedee/`, and local secrets and dot paths returned HTTP 403.
- 2026-09-07: Changed the canonical application URL base to `/tedee`; removed active runtime `/TE-/` fallbacks in PHP and JavaScript in favor of shared path helpers.
- 2026-09-07: Updated public text to TE-Dee and preserved all `TEKG` engineering namespaces and infrastructure identifiers. About media files were intentionally left unchanged for author-managed updates.
- 2026-09-07: PHP and JavaScript syntax checks passed. Focused Site Navigator, G6 static-contract, and expression path tests passed. Three unrelated full-suite tests remain environment-dependent locally: one requires the expression database, and two cannot write the protected Agent session cache.
- 2026-09-07: Server HTML inspection found a legacy URL embedded in the historical karyotype index. The data artifact was preserved; its stored path is now normalized at the read boundary and rebuilt through the canonical URL helper.
- 2026-09-07: Live validation passed for Home, Browse, Graph, Path, Expression, Search/Variant, About, Agent, and DeepThink; their APIs returned valid data beneath `/tedee/`. CSS, JavaScript, graph assets, MP4 media, JBrowse data, autocomplete paths, and pagination links resolved successfully.
- 2026-09-07: MySQL, Neo4j, and PHP 8.4 FPM were running under Supervisor. Apache access controls returned HTTP 403 for local configuration and dot paths. The legacy `/TE-/` Alias was then replaced with a path-preserving HTTP 301 redirect to `/tedee/`.

## Residual Risks

- Public HTTPS and host-level routing remain dependent on the external gateway.
- Agent/DeepThink external model access continues to depend on the reverse SSH proxy tunnel.
- The filesystem containing `/`, `/app`, and `/var/lib/mysql` has approximately 6.7 GiB free and requires operational monitoring; bulk data and future build artifacts should remain under `/data/tekg`.
