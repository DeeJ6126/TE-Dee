# TE-KG Quality Score

本文是项目质量看板。评分不是荣誉榜，而是让下一个 Codex 快速知道哪里最值得修。

| 模块 | 评分 | 当前问题 | 风险 | 下一步 | 对应检查 |
| --- | --- | --- | --- | --- | --- |
| Home | B | 首页已有 taxonomy / ring chart，但数据库能力入口仍可增强。 | 用户不易快速理解数据库范围。 | 增加数据库概览和示例任务入口。 | `check_api_contracts.py` |
| Browse | B- | Browse 当前仍偏数据目录，分析表格能力不足。 | TE 筛选、批量分析、导出能力有限。 | 引入后端分页、筛选、选中 TE 操作。 | `check_refactor_boundaries.py` |
| TE-KG/G6 | C | 图谱功能丰富但状态复杂，browser smoke 已发现 CDN 依赖风险。 | 白屏、loader 卡住、状态回归难排查。 | 先本地化 G6 依赖，再稳定 Expand mode。 | `check_g6_browser_smoke.py`、`check_g6_static_contract.py` |
| Expression | B | 数据根目录已统一到 `data/bulk_expression_web`，但 contract 仍不够系统。 | fallback 或重建脚本可能回旧路径。 | 建 expression API/data contract。 | `check_expression_paths.py` |
| Taxonomy | B+ | 已转向 Neo4j/API canonical，但历史文件仍多。 | 旧 truth source 可能回流。 | 持续运行 taxonomy truth 检查。 | `check_taxonomy_runtime_truth.py` |
| Path Finder | B | v1 已有页面/API/PMID 跳转。 | 复杂路径解释和候选 disambiguation 仍简单。 | 增加路径 ranking 和 evidence 摘要质量检查。 | `check_path_finder.py` |
| Data pipeline | C+ | tekg3 标准化流程存在，但历史脚本和导入路径仍需治理。 | 新数据重建时容易误用旧链路。 | 增加 pipeline manifest 和 build smoke。 | `check_repository_entropy.py` |
| Harness | B | 已有 AGENTS、exec plans、Neo4j/API/G6 smoke、Phase 3 checks。 | 仍缺完整 reviewer 自动化和 generated snapshots。 | 扩充 generated docs 和 role-based review。 | `check_docs_freshness.py` |
| Agent boundary | Not owned | Agent 子系统有单独 handoff。 | 非 agent 任务误改 agent 代码。 | 保持边界说明；涉及 agent 时单独计划。 | `AGENTS.md` |

## 使用规则

- 大改前先看本文件，确认目标模块风险。
- 大改后如果质量状态变化，应更新对应模块评分和下一步。
- 评分必须对应至少一个可运行检查或明确的 active plan。
