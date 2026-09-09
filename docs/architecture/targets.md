# TE-KG 前端与交互优化目标

本文档用于记录 TE-KG 后续前端和交互能力的改进方向。重点不是单纯美化页面，而是让用户更清楚地完成三件事：

1. 找到正确实体。
2. 理解实体之间的关系和证据。
3. 从 taxonomy、graph、expression、genome、literature 等数据之间顺畅跳转。

## 1. Search：改造成真正的实体入口（x）

### 实现什么

- 增加输入自动补全，支持 TE、disease、gene、function、sequence accession 等实体。
- 搜索结果按实体类型分组展示。
- 每个结果显示核心摘要：实体类型、别名、taxonomy path、相关关系数量、是否有 expression / genome / sequence 数据。
- 点击结果前即可看到该实体大概有什么数据。

### 为什么

当前 Search 更像详情页跳转入口，用户需要先点进去才知道结果是否有价值。知识图谱数据库的搜索入口应该先帮用户判断“这个实体是不是我要找的对象”，并降低同名、别名、类型混淆带来的误点。

### 大致怎么做

- 后端新增或扩展 suggest API，统一返回实体名、类型、别名、命中字段和简短统计。
- 前端搜索框改为异步补全。
- 搜索结果页增加类型分组和结果摘要卡片。
- 优先接入现有 Neo4j 图谱实体，后续再补 MySQL expression、sequence、JBrowse 覆盖标记。

## 2. Detail：改成证据优先的实体详情页（x）

### 实现什么

- 在 TE / disease / gene / function 等详情页增加 Evidence 区域。
- 展示该实体相关的疾病数、功能数、基因数、文献数、表达数据覆盖数。
- 每条关系可展开 PMID、证据句、来源、关系类型、更新时间。
- 明确区分 curated、literature-mined、computed、inferred 等证据来源。

### 为什么

用户看知识图谱时最关心的是“这条关系凭什么成立”。如果只显示节点和边，图会很直观，但可信度不清楚。TE-KG 需要让用户从详情页直接看到证据链，而不是只看到一个结论。

### 大致怎么做

- 梳理 Neo4j 里关系、Paper、PMID、evidence 字段的现有结构。
- 新增 evidence summary API，按实体聚合关系和文献证据。
- 在 detail 区域加入 Evidence Summary、Evidence Table、Relation Details 三块。
- 图中的边点击后也跳转或展开到同一套 evidence 数据。

## 3. Path Finder：新增实体路径查找页面（已实现）

### 实现什么

- 新增 Path Finder 页面，用于查询两个实体之间的路径。
- 支持输入 source entity 和 target entity。
- 支持最大路径长度，例如 2、3、4。
- 支持过滤关系类型，例如 TE-disease、TE-function、TE-gene、literature、expression。
- 路径结果以“路径卡片 + 小图 + 证据展开”的方式展示。

### 为什么

很多科研问题不是“某个节点有哪些邻居”，而是“两个对象之间有没有可解释链路”。例如 `L1HS -> breast cancer`、`AluJb -> TP53`、`ERVL -> immune response -> disease`。Path Finder 能把知识图谱从展示工具变成推理辅助工具。

### 大致怎么做

- 后端新增 path API，基于 Neo4j 查询限定长度的路径。
- 对路径做去重、排序和限制，避免返回过大的结果。
- 前端提供两个实体输入框、长度选择、关系过滤器。
- 每条路径显示节点类型、边类型、证据数量和可展开文献。

## 4. Network：从展示图变成可控探索工具

### 实现什么

- 增加节点类型、边类型、证据来源、关系强度等过滤器。
- 节点点击后在右侧显示固定详情面板。
- 支持 pin 节点、隐藏节点、只看一跳或二跳。
- 支持从当前节点继续扩展。
- 支持导出当前子图为 PNG / SVG / CSV。
- 边上显示 relation type 和 evidence count。

### 为什么

当前图谱可视化可以展示关系，但用户对图的控制能力还不够。图谱一旦节点变多，如果不能过滤、固定、扩展、查看证据，就会很快变成“看起来复杂但难以分析”的图。

### 大致怎么做

- 在现有 G6 runtime 上增加左侧过滤面板和右侧 detail panel。
- 复用现有 graph API，逐步增加可选参数：node type、edge type、evidence source、limit。
- 对当前子图维护本地状态：pinned、hidden、expanded、selected。
- 导出功能先做 CSV 和 PNG，SVG 可后续补。

## 5. Home：展示数据库能力和可执行入口

### 实现什么

- 首页增加数据库统计：TE 节点数、疾病节点数、关系数、文献证据数、taxonomy 覆盖、expression 覆盖、genome 覆盖。
- 展示当前主要数据源和更新时间。
- 提供 3 到 5 个示例任务入口，例如 `L1HS`、`AluJb`、`SVA`、`ERVL`、`breast cancer`。
- 保留当前 taxonomy / ring chart 展示，但让它服务于数据入口，而不是只做视觉展示。

### 为什么

用户进入首页时需要快速判断 TE-KG 的数据范围和能做什么。一个知识图谱数据库的首页应该说明数据库规模、数据类型、典型用法和入口，而不只是展示视觉效果。

### 大致怎么做

- 复用或扩展 `api/health.php`、`api/te_metrics.php`、taxonomy API，生成首页统计数据。
- 给首页增加“数据库概览”和“示例任务”区块。
- 示例任务直接跳转到 Search、Graph、Expression 或 JBrowse。
- 统计数据优先实时查询，成本较高的数据可使用生成缓存。

## 6. Expression 与 Graph 联动

### 实现什么

- 在图谱中按 expression median、max、tissue specificity 等指标给 TE 节点上色。
- 在 Expression 页面点击 tissue 或 condition 后，可以跳转到 Graph 并高亮相关 TE。
- 在 Graph 中选择一组 TE 后，可以跳转到 Expression 查看表达对比。
- 后续可支持上传 TE list 或 expression table，用于高亮图谱。

### 为什么

TE-KG 的价值不只是“图谱关系”，还在于能把表达、基因组位置、疾病、功能和文献证据放在一起解释。Expression 与 Graph 联动后，用户可以判断一个关系是否有表达层面的支持。

### 大致怎么做

- 定义 graph overlay 参数，例如 `overlay=expression&tissue=...`。
- 后端提供按 TE 名称批量返回 expression summary 的 API。
- G6 节点样式根据 expression 值动态映射颜色和大小。
- Expression 页面增加“View in Graph”入口。

## 7. Browse：改造成可分析的数据表

### 实现什么

- 增加多列排序、高级筛选、列显隐、保存筛选条件。
- 支持批量选择 TE。
- 支持批量导出。
- 支持一键进入 batch graph、batch expression、taxonomy branch 分析。

### 为什么

Browse 如果只是列表，就更像数据目录。对数据库用户来说，Browse 应该支持筛选、比较、批量选择和下游分析，尤其适合从 taxonomy branch 或 TE family 出发探索数据。

### 大致怎么做

- 先梳理 Browse 当前数据来源和字段。
- 抽象统一的 table state：filters、sort、selected rows、visible columns。
- 后端 API 支持分页、排序、筛选，避免一次性加载过多数据。
- 批量操作先支持导出和跳转 Graph，后续再扩展 enrichment。

## 8. Instruction：改成任务型工作流教程

### 实现什么

- Instruction 不再只写说明文字，而是按用户任务组织。
- 示例任务包括：
  - 查一个 TE：Search -> Detail -> Evidence -> JBrowse。
  - 查 TE 和疾病关系：Search -> Graph -> Evidence。
  - 比较多个 TE：Browse -> batch select -> export / graph。
  - 查看表达：Expression -> Detail -> Graph overlay。
  - 复现数据：API、Download、schema、citation。

### 为什么

数据库用户通常不是为了读说明而来，而是想完成一个具体任务。任务型教程能降低首次使用成本，也能减少用户不知道该从哪个页面开始的问题。

### 大致怎么做

- 重写 Instruction 文案结构。
- 每个任务配一个简短流程图或步骤卡片。
- 每一步附带真实示例实体。
- 所有示例链接应能直接跳转到对应页面和查询状态。

## 9. 关系可信度评分

### 实现什么

- 为 TE-KG 关系增加可解释的 evidence score。
- 初期评分可以基于：
  - 文献数量。
  - 是否有 curated source。
  - 是否有 expression 支持。
  - 是否有 genome annotation 支持。
  - 是否被多个来源重复支持。
- 每个分数都必须能展开解释。

### 为什么

图谱越大，用户越需要知道哪些边值得优先相信。没有可信度提示时，强证据关系和弱关联关系在图上看起来可能没有区别，这会影响科研判断。

### 大致怎么做

- 先定义简单透明的 scoring rule，不急于做复杂模型。
- 在关系导入或查询阶段计算 score。
- Graph、Detail、Path Finder 都显示 evidence score。
- 分数旁边提供解释面板，避免黑箱化。

## 10. 研究问题模板

### 实现什么

提供一组可点击的研究问题模板，例如：

- 某 TE 关联哪些疾病？
- 某疾病关联哪些 TE？
- 某 TE 的表达是否支持其疾病关联？
- 某 TE 和某 gene / disease 之间有哪些路径？
- 某 TE family 下哪些成员证据最强？
- 某 taxonomy branch 是否富集某类疾病？

### 为什么

很多用户并不熟悉图数据库查询方式。研究问题模板可以把复杂功能包装成可理解的入口，让用户从问题出发，而不是从页面结构出发。

### 大致怎么做

- 在 Home、Search、Graph、Instruction 中加入问题模板入口。
- 每个模板对应固定参数结构和目标页面。
- 后端可以逐步沉淀为 query preset API。
- 后续可与智能问答联动，但模板本身应独立可用。

## 建议实施顺序

1. Search 自动补全和分类型结果。
2. Detail 增加 evidence-first 结构。
3. Network 增加过滤器、右侧详情和边证据展开。
4. 新增 Path Finder。
5. Home 增加数据库统计和示例任务入口。
6. Expression 与 Graph 联动。
7. Browse 批量筛选和批量分析。
8. Instruction 改成任务型教程。
9. 关系可信度评分。
10. 研究问题模板。

## 当前核心短板总结

- 搜索入口还不够实体化。
- 证据链没有足够显性地展示。
- 图谱探索缺少路径查找、过滤和证据解释。
- expression、genome、taxonomy、literature 之间的联动还不够强。
- 用户难以判断哪些关系更可信。
- 批量分析能力不足。

