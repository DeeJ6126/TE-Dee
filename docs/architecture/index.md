# TE-KG Architecture Index

This page is the architecture documentation map. Use it to locate current facts,
historical handoffs, topic designs, and technical debt. Older documents may be
out of date; verify against live code and `current_system.md`.

## Current Entrypoints

- `../../AGENTS.md`: short AI entry and workflow index.
- `../../ARCHITECTURE.md`: root architecture entry.
- `../../AI_HANDOFF.md`: current AI handoff for the next session.
- `current_system.md`: current system overview. Prefer this over older
  handoffs.
- `database_overview.md`: detailed three-stage overview of data provenance,
  processing, integrated information, services, and interpretation boundaries.
- `graph_runtime.md`: G6 / TE-KG graph runtime structure.
- `data_sources.md`: current data sources, paths, and canonical rules.
- `database_contract.md`: Neo4j / API contract.
- `frontend_contract.md`: non-agent frontend constraints.
- `../../api/README.md`: current Agent/DeepThink and intelligent QA entry; use
  it for explicit intelligent QA tasks.
- `g6-development-rules.md`: G6 graph development constraints and lessons.
- `te_taxonomy_runtime_canonical_2026-05-16.md`: taxonomy runtime decision
  record.

## Topic Documents

- `../eqtl/README.md`: GTEx v11 strict TE-overlap scientific contract,
  versioned artifacts, MySQL runtime boundary, and recovery commands.
- `targets.md`: frontend and exploration capability targets.
- `network_explorer_next_tasks.md`: graph/network follow-up work.
- `kg_database_next_directions_2026-05-17.md`: database improvement direction.
- `project_core_risk_review_2026-05-16.md`: core risk review.
- `project_folder_simplification_2026-05-17.md`: folder simplification notes.
- `php_frontend_extraction_plan.md`: PHP/frontend extraction plan.
- `path_refactor_audit.md`: path abstraction and migration audit.
- `sequence_repbase_plan.md`, `repbase_sequence_structure_plan.md`: Repbase
  sequence plans.
- `jbrowse_recovery_plan.md`: JBrowse recovery plan.

## Structure and History

- `folder_structure_target.md`: target directory structure.
- `folder_cleanup_step1_inventory.md`: folder cleanup inventory.
- `project-structure-audit.md`: structure audit.
- `agent_differentiation_direction.md`: Agent vs non-Agent boundary direction.
- `tekg_agent_development_guide.md`: Agent development reference.

## Execution Plan System

- `../exec-plans/README.md`: execution-plan rules.
- `../exec-plans/active/`: active or prepared plans.
- `../exec-plans/completed/`: completed plans.
- `../exec-plans/tech-debt-tracker.md`: cross-task technical debt.
- `../QUALITY_SCORE.md`: module quality score.
- `../RELIABILITY.md`: reliability checks and failure diagnosis.
- `../design-docs/index.md`: design-doc entry.
- `../generated/README.md`: generated system snapshot entry.
- `../references/README.md`: reference index.

## Use Rules

- Read current entry documents first, then topic documents.
- Verify old documents against live code.
- Non-agent work should not modify the Agent subsystem by default.
- Architecture facts, forbidden paths, and long-term decisions must be written
  back to documentation, not left only in chat.
- New project Markdown should be English. Runtime Chinese prompt assets are
  functional assets, not ordinary documentation.
