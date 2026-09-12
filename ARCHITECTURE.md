# TE-Dee Architecture Entry

Last reviewed: 2026-09-11

TE-Dee (Transposable Element Data Exploration Engine) is a multi-store
scientific web database built from root-level PHP pages, browser JavaScript,
MySQL, Neo4j, and file-backed scientific artifacts.

## Current Runtime Shape

- Public URL base: `/tedee/`.
- Public pages remain in the repository root.
- Neo4j database `tekg3` supplies literature-derived knowledge relations,
  paths, disease hierarchy payloads, and the canonical taxonomy API through
  `api/taxonomy.php`.
- MySQL `tekg_catalog` supplies the active Repbase + RMSK Browse catalog and
  Home classification statistics.
- MySQL `tekg_expression` supplies expression summaries, Co-expression
  networks, GTEx eQTL overlap summaries, and TE-Gene graph payloads.
- `preview.php` exposes Knowledge Graph, TE-Gene Graph, and Integrated Graph.
- Runtime secrets are local to `api/config.local.php`; public code reads them
  through `api/runtime_config.php`.
- Active expression assets are rooted at `data/bulk_expression_web`.
- Large source, derived, and runtime datasets live under `data/` and are not
  ordinary Git payloads.

Internal identifiers such as database names and `/app/tekg` remain stable
infrastructure names. They are not user-facing branding.

## Read By Task

- System map: `docs/architecture/current_system.md`
- Database/data flow: `docs/architecture/database_overview.md`
- Runtime sources: `docs/architecture/data_sources.md`
- Verifiable contracts: `docs/architecture/database_contract.md`
- Graph runtime: `docs/architecture/graph_runtime.md`
- Frontend boundaries: `docs/architecture/frontend_contract.md`
- Co-expression: `docs/coexpression/README.md` and
  `docs/coexpression/frontend_contract.md`
- GTEx eQTL: `docs/eqtl/README.md`
- Agent/DeepThink: `api/README.md` and `api/docs/`

Historical handoffs and dated plans explain earlier decisions but are not
current runtime truth. Prefer the files above, live code, active database
metadata, and focused checks under `scripts/checks/`.
