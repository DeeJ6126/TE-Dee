# TE-Dee Architecture Index

Last reviewed: 2026-09-11

This directory now separates current architecture from the small number of
retained decision records. Superseded handoffs, next-task lists, cleanup plans,
and target-state documents were removed rather than kept beside current facts.

## Current Architecture

- `../../ARCHITECTURE.md`: compact root entry and store map.
- `current_system.md`: deployed application shape and page/API ownership.
- `database_overview.md`: scientific data flow, physical stores, integration
  boundaries, and public services.
- `data_sources.md`: canonical runtime source for each feature.
- `database_contract.md`: invariants and checks for Neo4j, MySQL, and APIs.
- `graph_runtime.md`: Knowledge, TE-Gene, and Integrated Graph architecture.
- `frontend_contract.md`: shared frontend constraints.
- `../coexpression/README.md`: Co-expression method and runtime boundary.
- `../coexpression/frontend_contract.md`: TE-Gene and Integrated display
  contract.
- `../eqtl/README.md`: GTEx v11 strict-overlap data contract.
- `../../api/README.md`: Agent/DeepThink entry.
- `../../api/agent/plugins/PLUGIN_CATALOG.md`: live planner-facing plugin
  catalog.

## Runtime Ownership

| Surface | Primary runtime owner |
| --- | --- |
| Home classification/composition | `api/home_stats.php`, active MySQL Browse catalog |
| Browse | `api/browse.php`, `api/browse_repository.php` |
| Knowledge Graph | `api/graph.php`, `api/graph_service.php`, Neo4j |
| TE taxonomy | `api/taxonomy.php`, Neo4j |
| TE-Gene Graph | `api/te_gene.php`, `api/te_gene_repository.php`, MySQL |
| Integrated Graph | parent orchestration and integrated G6 adapter |
| Expression | `api/expression_*.php`, MySQL expression summaries |
| Path | `api/path_finder*.php`, Neo4j |
| Variant/eQTL | `api/variants.php`, `api/variant_repository.php`, MySQL |
| Agent/DeepThink | `api/agent/` and the Agent/DeepThink endpoints |

## Retained Decision And Engineering Records

- `te_taxonomy_runtime_canonical_2026-05-16.md`: why taxonomy has one runtime
  truth source.
- `g6-development-rules.md`: still-applicable renderer engineering rules.

These records explain constraints but do not override current code or the
current architecture files above.

## Documentation Rules

- Update current architecture when a runtime source, schema, API contract, or
  page ownership boundary changes.
- Keep scientific provenance and interpretation limits beside the affected data
  layer.
- Delete a superseded plan or handoff once its useful decisions have been
  absorbed into current documentation.
- Do not duplicate secrets, credentials, private absolute paths, or generated
  data into documentation.
- Internal implementation names may appear in technical documents when
  necessary, but should not leak into public UI or manuscript prose.
