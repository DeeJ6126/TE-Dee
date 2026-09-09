# TE-KG 知识图谱数据库后续发展方向

日期：2026-05-17

范围：本文只讨论 TE-KG 的数据库层和知识图谱层，不规划智能问答或 agent 子系统。

## 当前基础状态

- 运行时 Neo4j 目标库：`tekg3`。
- 运行时配置入口：`api/runtime_config.php`。
- TE taxonomy 权威来源：Neo4j `tekg3` 中 `TE` 节点上的属性。
- 当前 Neo4j 节点规模：
  - `TE`：225
  - `Function`：3683
  - `Paper`：2308
  - `Gene`：1280
  - `Protein`：1089
  - `DiseaseCategory`：767
  - `Disease`：676
  - `RNA`：588
  - `Mutation`：377
  - `Pharmaceutical`：293
- 当前关系规模：
  - `BIO_RELATION`：12444
  - `HAS_SUBCATEGORY`：744
  - `CLASSIFIED_AS`：436
  - `SUBFAMILY_OF`：72
- 当前 TE taxonomy 状态：
  - 225 个 `TE` 节点都有 `taxonomy_group`
  - 192 个 `TE` 节点有 `taxonomy_class`
  - 154 个 `TE` 节点进入首页 taxonomy 环图
  - taxonomy 分组：`standard=138`、`A=36`、`B=35`、`C=16`

## 1. 把证据来源提升为一等数据

图谱里已经有 `Paper` 节点，`BIO_RELATION` 关系上也有 `pmids`，但如果要作为严肃的知识图谱数据库，关系级证据仍然太粗。

建议方向：

- 为每条抽取出来的 claim 建立明确的证据节点，或建立关系证据记录。
- 保存原文句子、抽取方法、模型或脚本版本、PMID、可用时的文章章节、置信度。
- 把人工整理关系和自动抽取关系区分开。
- 增加审核状态，例如 `raw`、`reviewed`、`rejected`、`curated`。

这样做的意义：

- 用户可以区分强证据支持的生物关系和单次抽取出来的弱关系。
- 后续清洗数据时，可以针对弱证据关系处理，而不是粗暴删除整个实体节点。
- 图谱搜索排序可以解释为什么某条关系更可靠。

## 2. 规范关系语义

当前多数生物关系统一存为 `BIO_RELATION`，具体语义放在 `predicate` 属性里。这种方式灵活，但对图算法、质量检查和长期维护都偏弱。

建议方向：

- 定义受控的 `predicate` 词表。
- 把 `predicate` 分成几类，例如关联、表达、调控、插入、分类、证据报道、药物或治疗交互。
- 增加 source label 和 target label 的合法组合规则。
- 如果为了导入灵活性继续保留 `BIO_RELATION`，也应验证 `predicate` 和两端节点类型。

近期可做的检查：

- 统计不同 `predicate` 的数量。
- 找出只差大小写、拼写或词形的重复 `predicate`。
- 找出可疑的关系形态，例如 drug-to-drug、mutation-to-toxin，再判断是真实知识还是抽取噪声。

## 3. 把 TE taxonomy 升级为可管理的子图

当前 taxonomy 主要存为 `TE` 节点属性，并用 `SUBFAMILY_OF` 表示 TE 到 TE 的层级关系。这个模型已经足够支撑运行时页面，但还不够支撑更深入的 taxonomy 管理。

建议方向：

- 可选地增加 taxonomy 概念节点，例如 `TaxonomyClass`、`TaxonomyOrder`、`TaxonomySuperfamily`、`TaxonomyFamily`。
- 继续保留 `TE` 节点上的 `taxonomy_*` 冗余属性，方便前端快速展示。
- 用 taxonomy 概念节点做验证、浏览和未来跨物种扩展。
- 把 taxonomy 来源、merge 决策和 rename 决策记录为图谱数据，而不只放在 JSON report 里。

优先级：

- 先保持当前 Neo4j 属性模型稳定。
- 再用增量方式添加 taxonomy 概念节点。

## 4. 把 Expression 数据摘要接回图谱

Expression 数据现在主要放在 MySQL `tekg_expression` 和 `data/bulk_expression_web` 文件中。这样适合页面查询，但知识图谱本身无法直接理解表达上下文。

建议方向：

- 继续让 MySQL 负责大规模 expression 矩阵和 summary 查询。
- 在 Neo4j 中只加入重要的摘要节点，例如 `ExpressionContext` 或 `Tissue`。
- 从 `TE` 连到表达上下文摘要节点，表示最高表达、最低表达、广泛表达、癌细胞系富集等摘要事实。
- 不要把 5415124 行的 context stats 全量复制进 Neo4j，只放图谱层需要的摘要事实。

第一批适合进入图谱的事实：

- `TE` -> `ExpressionContext`，表示每个 dataset 中 median 最高的 context。
- `TE` -> `ExpressionDataset`，表示该 TE 在哪些 dataset 中可用。
- 高层标记，例如广泛表达、组织特异、癌细胞系富集。

## 5. 增加数据库质量门禁

项目现在已经有 taxonomy 和运行时 DB 配置检查。下一步应该把这些检查变成导入或替换 `tekg3` 前的标准门禁。

建议检查：

- 节点数和关系数是否异常漂移。
- 各 label 的属性覆盖率。
- 唯一约束以外的重复名称。
- 缺失或格式异常的 `pmids`。
- 没有 `predicate` 的 `BIO_RELATION`。
- 缺少必要 taxonomy 字段的 `TE` 节点。
- 首页 taxonomy 数量是否等于 live Neo4j 中 `homepage_chart_included = true` 的数量。
- MySQL expression 表是否有预期行数和必要 quartile 字段。

建议脚本方向：

- 新增 `scripts/checks/check_graph_database_integrity.py`。
- 保留 `scripts/checks/check_taxonomy_runtime_consistency.py`。
- 保留 `scripts/checks/check_runtime_db_config.py`。
- 增加 expression 表检查。由于当前 CLI PHP 缺 `mysqli`，这个检查可以走 MySQL CLI 或一个 PHP web 检查入口。

## 6. 用图谱信号改进搜索排序

当前图谱结构已经足够支持比简单名称匹配更好的排序。

建议方向：

- 把 degree、实体类型、关系 `predicate`、证据数量作为排序特征。
- 对连接过多的泛化 `Function` 降权，除非用户明确在做广泛查询。
- 对有直接 TE 关系、有 curated taxonomy、有 expression 摘要的实体加权。
- 为重复使用的运行时查询预计算轻量 graph metrics。

有用的图谱指标：

- TE 按终点 label 统计的 degree。
- 每个 TE 的 `predicate` 多样性。
- 每条 TE 关系的证据数量。
- Disease 和 Function 邻域摘要。

## 7. 区分运行时图谱和构建图谱

`tekg3` 是当前运行时目标库。构建历史仍会引用 `tekg2` 和 `tekg21`，这对迁移脚本是正常的，但边界必须清楚。

建议方向：

- 把 `tekg3` 认定为唯一运行时数据库。
- 旧 DB 名只允许出现在迁移、构建、归档脚本中。
- 每次构建后生成 database manifest，记录：
  - source DB
  - target DB
  - 导入时间
  - 节点 label 计数
  - 关系 type 计数
  - taxonomy 计数
  - 首页 chart 计数
  - 可用时记录脚本版本或 commit/hash

## 8. 加强 schema 约束

当前约束已经覆盖了多数实体 label 的 name 字段，以及 `Paper` 的 PMID。这是一个好的基础。

建议补充：

- 在 Neo4j 版本支持时，考虑给核心 label 的 `name` 增加存在性约束。
- 为 `DiseaseCategory.category_node_id` 保持或加强检查。
- 对关系属性做检查，例如 `predicate` 和 `pmids`。
- 约束应由脚本生成和维护，不要只在 Neo4j Browser 中手工创建。

## 9. 把大型资产当成数据产品管理

`data/JBrowse`、`data/bulk_expression_web`、`data/dfam`、`data/raw` 下的大文件很重要，但不能靠口头记忆维护。

建议方向：

- 新建 `data_manifest.json` 或 `docs/architecture/runtime_data_manifest.md`。
- 对每个大型资产记录用途、来源、预期路径、大小、checksum、生成脚本。
- 小型 canonical metadata 可以在合适时进入 Git。
- 大型 raw/generated assets 可以继续留在 Git 外，但必须明确是否为运行时必需。

## 10. 建立公开的数据库契约

在继续增加功能前，应该定义 TE-KG 作为数据库承诺提供什么。

建议契约内容：

- 支持的实体 label。
- 支持的关系类别。
- 每类 label 的必要属性。
- taxonomy 权威来源规则。
- expression 摘要规则。
- 导入和构建顺序。
- 数据库被认为可用前必须通过的验证脚本。

这会降低未来 UI、API 和数据导入工作的脆弱性。
