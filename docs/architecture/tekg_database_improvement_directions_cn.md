# TE-KG 数据库改进方向

本文只讨论 TE-KG 数据库和数据模型的改进方向，不讨论 `preview.php` / G6 页面本身。

当前运行数据库：Neo4j `tekg3`。

## 当前数据库状态

当前图数据库已经包含：

- TE、疾病、功能和相关生物实体。
- `BIO_RELATION` 关系，包含 `predicate`、`description`、`source_group`、`pmids` 等属性。
- 以 `pmid` 为键的 `Paper` 节点。
- 已导入到 `Paper` 节点的 PubMed metadata。
- 使用内部认可的 `impact_factor_package_2025` 映射到 `Paper` 节点的 journal metric。
- 已聚合到 `BIO_RELATION` 上的 evidence support 字段，包括 PMID 数量、IF 汇总、JCR 计数、journal 数量和发表年份范围。

当前硬约束：

- 不要回退到 `tekg2` 或 `tekg21`。
- 不要新增第二套 taxonomy runtime truth source。
- 缺失 Impact Factor 时不能让 LLM 猜。
- Impact Factor 只能作为 journal metric 组件，不能称为关系 “confidence”。

## 1. Publication / Evidence 模型加固

当前 v1 做法是 enrich 现有 `Paper {pmid}` 节点。这个方案务实，而且已经跑通。

后续可考虑：

- 如果产品语义需要，可以给 `Paper` 节点增加 `:Publication` label。
- 继续使用 `pmid` 作为唯一键。
- 如果还没有约束/索引，考虑增加：
  - `Paper(pmid)`
  - 未来可选的 `Publication(pmid)`

实施前需要确认：

- 所有 `Paper` 节点是否都是 PubMed publication。
- 是否会引入非 PubMed evidence source。
- preprint、book series、protocol 是否仍放在 `Paper` 下，还是需要来源特异 label。

## 2. Evidence Source 规范化

当前 `BIO_RELATION.pmids` 是 relation 到文献的 join key。

潜在改进：

- 将关系证据规范化为显式 evidence 结构。
- Neo4j relationship 不能直接连到 `Paper`，如果要表达“关系被文献支持”，可能需要 reified relation node，例如 `(:EvidenceRelation)`。
- 保留当前 `BIO_RELATION.pmids` 以兼容现有 API/G6。

不要急着做这件事。关系 reification 是较大的迁移，会影响 API contract。

## 3. Category-Centered Graph Contract

当前普通 `api/graph.php?q=...` 处理的是 entity-centered graph query。  
`Class I: Retrotransposons`、`Class II: DNA Transposons`、`others` 这类 taxonomy category label 不是普通实体 anchor，因此可能返回空图。

潜在改进：

- 设计 category graph query contract。
- 示例参数：
  - `query_type=taxonomy_category`
  - `taxonomy_source=rmsk_repbase`
  - `category_id` 或 normalized category path
- 返回 category 节点、后代节点和受限的 relation summary。

收益：

- Tree category 的 Jump 可以有明确语义。
- 用户可以围绕 “Class I” 或 “SINEs” 查看分类中心图。

风险：

- Category graph 的语义容易变得昂贵或模糊。
- 必须有严格的深度、数量和关系类型限制。

## 4. 稳定实体 ID

部分现有流程使用 Neo4j `elementId()` 做精确消歧。当前 runtime 内可以接受，但数据库重建后不稳定。

潜在改进：

- 为生物实体增加稳定业务 ID。
- 可由 source、type、normalized name、taxonomy path 等字段确定性生成。
- `elementId()` 作为 runtime fallback，而不是长期身份标识。

适用场景：

- Expand same-label disambiguation。
- 保存图谱状态。
- URL 分享。
- 可复现导出。

## 5. 同名跨类型实体审计

当前 expand contract 支持精确节点身份：

- `expand_node_id`
- `expand_node_type`
- `expand_query`

潜在数据库改进：

- 审计所有跨类型同名实体。
- 生成共享 label 的节点报告。
- 增加稳定 ID 或 alias，减少 label-only ambiguity。

推荐输出：

- `data/processed/same_label_entity_report.csv`
- 防止 exact expansion 回归的检查脚本。

## 6. Evidence Support Score

当前 `BIO_RELATION` 已有描述性 evidence 字段：

- `support_pmid_count`
- `support_metric_paper_count`
- `support_metric_coverage`
- `support_if_max`
- `support_if_mean`
- `support_if_median`
- `support_jcr_q*_count`
- `support_journal_count`
- publication year range

潜在改进：

- 在公式透明后再增加 `support_score_v1`。
- 保留所有 component 字段，让 score 可解释。
- 除非有真正的置信度模型，否则不要命名为 `confidence`。

可考虑的 score 组件：

- log-scaled PMID count
- metric coverage
- recent publication count
- Q1/Q2 support count
- 未来导入 article type 后的文献类型权重

## 7. Journal Metric 来源管理

当前来源是 `impact_factor_package_2025`，作为内部 v1 可信来源。

潜在改进：

- 如果未来拿到官方 JCR export，可用其替换或覆盖当前 mapping。
- 每个 metric 必须保留 source 和 year。
- 未匹配的 metric 保持 null。
- 对已知错配使用 manual override 文件。

相关文件：

- `data/reference/journal_metrics.csv`
- `data/reference/journal_metrics_manual_overrides.csv`
- `data/processed/journal_metrics_mapping_report.json`

## 8. Taxonomy 数据完整性

最近已修复 `api/taxonomy_lib.php` 中 Unicode 树形前缀解析问题，恢复了 `L1HS` 等深层路径。

潜在改进：

- 增加 taxonomy parser fixture，覆盖 Unicode tree prefix。
- 检查已知 taxonomy source 的 parent-child edge count。
- 验证关键路径：
  - `Class I: Retrotransposons`
  - `Order: Non-LTR Retrotransposons (LINEs)`
  - `Superfamily: L1 (LINE-1)`
  - `Family: L1PA`
  - `L1HS`

不要因此新增第二套 taxonomy runtime truth source。

## 9. 数据质量 Dashboard

项目可以增加自动生成的数据质量报告。

建议报告：

- 按类型统计 node count。
- 按 predicate 统计 relationship count。
- 按关系类型统计 PMID coverage。
- Paper metadata completeness。
- Journal metric coverage by journal/year。
- Same-label entity collisions。
- Taxonomy orphan nodes。
- Empty category graph candidates。

这些报告可以生成在 `data/processed/` 或 `docs/generated/`，并配套可重复运行的 checks。

## 10. 导入安全与回滚

所有数据库写入都应沿用 journal metrics import 已经跑通的模式：

1. preflight check
2. dry-run
3. 显式 `--write`
4. import tag
5. post-import verification
6. rollback preview
7. rollback `--write`

没有 import tag 或用户明确批准时，不要做破坏性数据库清理。

## 推荐的下一批数据库工作

优先级较高的数据库层任务：

1. **Category-centered graph contract**  
   让 taxonomy category label 可以被查询，而不是伪装成普通 entity anchor。

2. **稳定实体 ID**  
   减少长期依赖 Neo4j `elementId()`。

3. **同名实体审计**  
   找出跨类型共享 label 的实体，降低扩展和跳转歧义。

4. **Taxonomy parser fixtures and checks**  
   防止 Unicode tree parsing 再次回归。

5. **Evidence support score 设计**  
   在 component 字段稳定后，设计透明可解释的 evidence support score。

