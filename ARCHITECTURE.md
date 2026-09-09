# TE-KG Architecture Entry

This is the root architecture entry. It keeps only the current map and hard
facts. For details, continue into `docs/architecture/`.

## Current Facts

- TE-KG is a local PHP + browser JavaScript + Neo4j + MySQL project.
- The current Neo4j runtime database target is `tekg3`.
- Runtime pages still live in the repository root, such as `index.php`,
  `browse.php`, `preview.php`, `expression.php`, and `path_finder.php`.
- TE taxonomy runtime truth is Neo4j / `api/taxonomy.php`.
- Expression runtime data root is `data/bulk_expression_web`.
- Non-agent tasks should not primarily modify `api/agent/` unless explicitly
  requested.

## Read First

1. `AGENTS.md`
2. `AI_HANDOFF.md`
3. `docs/architecture/index.md`
4. `docs/architecture/current_system.md`
5. `docs/architecture/graph_runtime.md`
6. `docs/architecture/data_sources.md`
7. `docs/architecture/database_contract.md`
8. `docs/architecture/frontend_contract.md`

## Harness Entrypoints

- Execution plans: `docs/exec-plans/`
- Quality score: `docs/QUALITY_SCORE.md`
- Reliability notes: `docs/RELIABILITY.md`
- Runnable checks: `scripts/checks/`

Historical handoffs and topic notes can explain why the project moved in a
particular direction, but current facts must be verified against this entry,
`current_system.md`, live code, and runnable checks.
