请先读取并遵守 TE-KG 仓库文档：
  AGENTS.md
  ARCHITECTURE.md
  docs/architecture/index.md
  docs/architecture/current_system.md
  docs/architecture/graph_runtime.md
  docs/architecture/g6_current_state_handoff.md
  docs/RELIABILITY.md
  docs/exec-plans/tech-debt-tracker.md

  再读取最近 completed plans：
  docs/exec-plans/completed/g6-evidence-support-ux-v1.md
  docs/exec-plans/completed/g6-node-action-card-ux.md
  docs/exec-plans/completed/g6-te-tree-load-regression.md
  docs/exec-plans/completed/graph-api-evidence-support-contract.md
  docs/exec-plans/completed/journal-metrics-neo4j-import-plan.md
  docs/exec-plans/completed/journal-metrics-relation-aggregation.md

  当前重点：不要重做已稳定的 G6 evidence/node action/tree regression 功能。新任务先做状态盘点和最小复现，再决定是否创建 active plan。不要改 Neo4j、
  taxonomy、expression、agent，除非任务明确要求并有验证计划。