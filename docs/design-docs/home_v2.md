# TE-KG Home v2 改进计划

更新时间：2026-05-23

本文是在 `docs/design-docs/homepage_v1_integrated_plan.md` 基础上的收敛版。v2 不追求一次性展示所有能力，而是优先把首页改成更稳、更聚焦的数据库门户：

- 不接入首页搜索框。
- 第一屏右侧使用更精细的 SVG 展示 TE 知识图谱效果。
- 数据规模区保留，但重点改造现有饼图。
- 饼图和统计数字必须实时检测 Neo4j 数据量。
- Core Tools 保留。
- 暂不做 Evidence Support 和 Sources / Provenance 首页区块。

## 1. v2 范围

### 做

- 改造首页视觉结构。
- 增加更精细的 TE-KG SVG 主题图。
- 保留并强化数据规模展示。
- 改造饼图，让它表达实体类型、关系类型或证据覆盖等真实统计。
- 数据规模和饼图必须来自 Neo4j 实时统计，不能手写死数。
- 保留 Core Tools：Graph、Taxonomy、Expression、Path Finder、Search/Browse。
- 维持科研数据库门户风格。

### 不做

- 不在首页接入搜索框。
- 不在首页加载完整 G6。
- 不改 Neo4j 数据。
- 不改 graph API 业务语义。
- 不改 taxonomy runtime truth source。
- 不改 agent / expression 数据逻辑。
- 不做 Evidence Support 独立展示区。
- 不做 Sources and Provenance 独立展示区。

## 2. 第一屏方案

### 2.1 页面目标

第一屏要解决两个问题：

1. 让用户快速理解 TE-KG 是转座元件相关的知识图谱数据库。
2. 用一张有信息量的 SVG 视觉图，替代空白区域或过于简陋的装饰图。

v2 不放搜索框，因此第一屏的重点应是：

- 数据库名称。
- 简短定位语。
- 入口按钮。
- 右侧 TE-KG SVG 图。

建议第一屏文案：

```text
TE-KG
A transposable element-centered biomedical knowledge graph for exploring TE taxonomy, biological relations, expression context, and literature-supported network evidence.
```

建议按钮：

- Explore Graph
- Browse Taxonomy
- Expression Atlas
- Path Finder

## 3. 右侧 SVG 视觉方案

当前 mockup 里的 SVG 更像简单装饰，v2 建议改成“可解释的 TE 知识图谱示意图”，但仍然是静态 SVG / CSS 动画，不接真实数据。

### 3.1 SVG 总体构图

建议使用“中心 TE + 多层证据网络”的构图：

- 中央是一个主要 TE 节点，例如 `L1HS` 或 `LINE1`。
- 左侧是 taxonomy 层级：
  - `Class I`
  - `LINE`
  - `L1`
  - `L1HS`
- 右侧是 biomedical relation 层：
  - Disease
  - Function
  - Pathway
  - Gene / Protein
- 下方是 literature evidence 层：
  - PMID nodes
  - Paper / Journal metric hints
- 背景是一段灰色 DNA 轨道，TE 片段嵌在其中。

这样用户一眼能看懂：TE-KG 不是单纯 taxonomy，也不是单纯图谱，而是把 TE 分类、关系、表达/功能、文献证据放到同一个数据库里。

### 3.2 SVG 元素细节

建议至少包含这些元素：

- **中央 TE 节点**
  - 大节点，标签 `L1HS` 或 `LINE1`。
  - 使用当前图谱中 TE 类型对应的颜色。
  - 节点外圈可有轻微 pulse，表示当前探索焦点。

- **taxonomy 分支**
  - 从左上向中心收束。
  - 节点大小逐级变化，表示从 class 到 family 到 element。
  - 连线可以是灰蓝色，强调层级关系。

- **biological relation 分支**
  - 从中心向右侧发散。
  - Disease、Function、Pathway 使用不同颜色。
  - 连线宽度不同，暗示不同 support count，但不要写 confidence。

- **literature evidence 分支**
  - 从中心或边连接到下方 PMID / Paper 小节点。
  - 可以有小型 evidence dots 沿边移动。
  - 用金色或琥珀色表达文献证据，不要用过强警示色。

- **DNA / TE 主题轨道**
  - 背景放两条灰色 DNA 线。
  - 中间一段彩色 TE segment，标注 `TE locus` 或当前 TE 名。
  - 这部分是主题背景，不承担真实机制解释。

- **简短标签**
  - `Taxonomy`
  - `Relations`
  - `Evidence`
  - `Expression`
  - 标签文字小而清楚，避免堆满。

### 3.3 SVG 动画建议

动画应轻量，避免像加载动画那样抢注意力。

建议动效：

- 中央 TE 节点外圈慢速呼吸。
- 2-3 个 evidence dots 沿着 PMID 到 relation 的边缓慢移动。
- taxonomy 分支不移动，保持稳定。
- biological relation 边可以有非常轻的 opacity 变化。
- DNA 背景不移动，避免喧宾夺主。
- 支持 `prefers-reduced-motion`，关闭移动 dot，只保留静态图。

### 3.4 SVG 风格约束

- 不使用大面积紫色渐变。
- 不做太空风、赛博风或营销页风格。
- 背景应接近科研工具：浅色、清晰、信息密度适中。
- SVG 不应只是装饰，要让用户能看出 TE、taxonomy、relation、evidence 之间的关系。
- 不引入第三方图形库。

给母智能体的提示词：

```text
请改造首页第一屏，但不要接入搜索框。右侧用静态 SVG + CSS 轻量动画展示 TE-KG 知识图谱效果，不加载 G6，不接真实数据。SVG 要比当前 mockup 更精细：中央 TE 节点，左侧 taxonomy 层级，右侧 disease/function/pathway relation 分支，下方 PMID/evidence 小节点，背景有灰色 DNA 和 TE segment。动画只保留中央 TE 轻微 pulse 和少量 evidence dots 沿边移动，并支持 prefers-reduced-motion。整体风格保持科研数据库门户，不要太空风或纯营销页。
```

## 4. 数据规模与饼图改造

你提到数据规模其实已经显示好了，因此 v2 不需要重做整个 stats 区。重点是改造饼图，让它更有解释力。

### 4.1 核心原则

饼图和数据规模必须实时检测 Neo4j 数据量：

- 不能手写死数。
- 不能依赖聊天记录。
- 不能让 LLM 猜。
- 不能用过期静态 JSON 冒充实时数据。
- 如果出于性能原因做缓存，必须明确缓存时间和刷新条件。

### 4.2 推荐饼图方案

建议不要只做一个饼图，而是做一个“数据构成面板”，里面包含一个主饼图和一组小指标。

推荐主饼图：**Entity Composition**

展示 Neo4j 当前节点 label 的构成，例如：

- TE / Transposable Element
- Disease
- Function / Biological Process
- Gene / Protein
- Paper
- Other

优点：

- 最直观。
- 能说明 TE-KG 不是只有 TE，而是多实体知识图谱。
- 适合首页展示。

需要注意：

- Neo4j 节点可能有多 label。
- 需要先定义 label 归类规则。
- 同一个节点多 label 时要有优先级，避免重复计数。

推荐归类规则：

1. 如果含 Paper / Publication 相关 label，归为 Paper。
2. 如果含 TE / Repeat / TransposableElement 相关 label，归为 TE。
3. 如果含 Disease 相关 label，归为 Disease。
4. 如果含 Gene / Protein 相关 label，归为 Gene / Protein。
5. 如果含 Function / Pathway / GO / BiologicalProcess 相关 label，归为 Function / Pathway。
6. 其他归为 Other。

### 4.3 可选第二饼图

如果首页空间允许，可加入第二个小型 donut chart：**Relation Composition**

展示 `BIO_RELATION.predicate` 或 relation type 的 Top categories：

- participate in
- associate with
- regulate
- insert into
- affect
- Other

如果 predicate 太分散：

- 只展示 Top 5。
- 剩余合并为 Other。

### 4.4 不推荐的饼图

暂不推荐首页 v2 做这些饼图：

- Journal quartile composition：容易让用户误以为首页重点是 IF。
- Evidence coverage pie：有解释价值，但容易和 confidence 混淆。
- Taxonomy class pie：适合 taxonomy 页面，不一定适合首页。

### 4.5 实时 Neo4j 统计实现建议

建议新增一个轻量只读 API，例如：

```text
api/home_stats.php
```

只做统计读取，不做写入。

返回示例：

```json
{
  "neo4j_target": "tekg3",
  "generated_at": "2026-05-23T12:00:00+08:00",
  "nodes_total": 12345,
  "relationships_total": 67890,
  "entity_composition": [
    {"key": "TE", "count": 1200},
    {"key": "Disease", "count": 800},
    {"key": "Function / Pathway", "count": 2400},
    {"key": "Paper", "count": 2308},
    {"key": "Other", "count": 5637}
  ],
  "relation_composition": [
    {"key": "participate in", "count": 3200},
    {"key": "associate with", "count": 2100},
    {"key": "regulate", "count": 1800},
    {"key": "Other", "count": 900}
  ]
}
```

### 4.6 性能要求

实时统计不能拖慢首页。

建议策略：

- API 只跑轻量聚合查询。
- 如果全量 label / predicate 统计太慢，则使用短缓存。
- 缓存文件可以放在 `data/generated/` 或已有项目认可的位置。
- 缓存 TTL 可以先设为 5-30 分钟。
- API 返回 `generated_at` 和 `cache_status`。
- 首页加载失败时显示 graceful fallback，不影响其他页面。

### 4.7 前端展示方式

饼图建议使用 SVG 自绘，不引入第三方库。

推荐视觉：

- donut chart，而不是实心 pie。
- 中间显示总节点数或总关系数。
- 右侧/下方显示 legend。
- legend 包含颜色、名称、数量、百分比。
- hover 时高亮扇区和 legend。
- 移动端改为上下布局。

给母智能体的提示词：

```text
请改造首页数据规模区，保留现有 stats 思路，但重点重做饼图。饼图必须来自 Neo4j tekg3 的实时只读统计，不能写死数字，不能让 LLM 猜。建议新增轻量只读 api/home_stats.php，返回 nodes_total、relationships_total、entity_composition、relation_composition、generated_at。Entity Composition 使用 Neo4j label 归类，Relation Composition 使用 BIO_RELATION.predicate Top 5 + Other。前端用 SVG donut chart 自绘，不引入第三方库；显示 legend、数量、百分比，并支持加载失败 fallback。不要改 Neo4j 数据，不要改 graph API 语义。
```

## 5. Core Tools 区

Core Tools 保留，但 v2 应尽量做得更像“数据库工具入口”，不要像普通营销卡片。

建议保留 5 个入口：

- Explore TE Graph
- Browse TE Taxonomy
- Expression Atlas
- Path Finder
- Search / Browse Database

每个卡片包含：

- 小图标。
- 工具名称。
- 一句话说明。
- 入口按钮或点击区域。

文案建议：

```text
Explore TE Graph
Inspect TE-centered relations, expand nodes, and review relation evidence in graph cards.

Browse TE Taxonomy
Navigate TE classes, families, and elements from the curated taxonomy tree.

Expression Atlas
Explore TE expression context across available biological datasets.

Path Finder
Trace paths between TE, disease, function, and biological relation nodes.

Search / Browse Database
Browse database records and jump into graph or taxonomy workflows.
```

给母智能体的提示词：

```text
请保留并优化首页 Core Tools 区，入口包括 Explore TE Graph、Browse TE Taxonomy、Expression Atlas、Path Finder、Search / Browse Database。卡片要像科研数据库工具入口，不要像营销 feature card。每个入口链接到现有 runtime page，不新增页面，不重构 root runtime pages。
```

## 6. v2 不做 Evidence / Provenance 的原因

Evidence Support 和 Sources / Provenance 是 TE-KG 的重要能力，但 v2 先不放首页独立区块，原因是：

- 首页第一版先解决“太空”和“入口不清楚”的问题。
- Evidence 内容如果写多，首页会变重。
- Provenance 更适合后续做到 About / Docs / Data 页面，或首页底部的简短版本。
- 当前优先级更高的是首页第一屏、SVG 图谱视觉、实时 Neo4j 数据规模、Core Tools。

注意：不做独立区块不代表删除相关事实。实现时仍要遵守：

- 不把 IF / support_* 称为 confidence。
- 不让 LLM 猜缺失 IF。
- 不改已有 evidence_records / G6 evidence table。

## 7. 推荐实施顺序

建议分三步做：

1. 首页 v2 信息架构
   - 去掉首页搜索框方案。
   - 第一屏改成定位文案 + 入口按钮 + SVG 图谱视觉。
   - 保留 Core Tools。

2. 实时 Neo4j stats API
   - 新增或复用只读 stats endpoint。
   - 确认 Neo4j target 是 `tekg3`。
   - 输出 entity composition 和 relation composition。
   - 增加 check，验证数据来自当前 Neo4j。

3. 饼图和视觉细化
   - 用 SVG donut chart 渲染实时数据。
   - 加 legend、百分比、加载失败 fallback。
   - 优化响应式和 reduced-motion。

## 8. 一次性执行提示词

如果希望让母智能体一次性做首页 v2，可以发送：

```text
请按母智能体流程完成 TE-KG home v2 refresh，全程用中文和我沟通。

先读取 AGENTS.md、ARCHITECTURE.md、docs/architecture/index.md、docs/architecture/current_system.md、docs/RELIABILITY.md、docs/exec-plans/tech-debt-tracker.md，并派 Explorer 只读检查 index.php、首页相关 CSS/JS、当前数据规模展示、当前饼图实现、Neo4j tekg3 配置和已有 stats/check 入口。

目标：改造首页，但不要接入搜索框；首页仍是科研数据库门户，不是营销页。

范围：
1. 可以改首页相关文件、必要 CSS/JS、新增只读 stats API、执行计划和文档。
2. 不要改 Neo4j 数据。
3. 不要改 graph API 业务语义。
4. 不要改 taxonomy runtime truth source。
5. 不要改 agent / expression 数据逻辑。
6. 不要加载完整 G6。
7. 暂不做 Evidence Support 和 Sources / Provenance 独立区块。

必须实现：
1. 第一屏不放搜索框，改为 TE-KG 定位文案、核心入口按钮和右侧 TE-KG SVG 知识图谱示意图。
2. SVG 要比现有 mockup 更精细：中央 TE 节点，左侧 taxonomy 层级，右侧 disease/function/pathway relation 分支，下方 PMID/evidence 小节点，背景有灰色 DNA 和 TE segment；只做轻量 CSS 动画，支持 prefers-reduced-motion。
3. 保留数据规模展示，但改造饼图。
4. 饼图必须实时读取 Neo4j tekg3 的只读统计，不能写死数字，不能让 LLM 猜。
5. 推荐新增 api/home_stats.php，返回 nodes_total、relationships_total、entity_composition、relation_composition、generated_at/cache_status。
6. Entity Composition 用 Neo4j label 归类；Relation Composition 用 BIO_RELATION.predicate Top 5 + Other。
7. 前端用 SVG donut chart 自绘，不引入第三方库，显示 legend、数量、百分比和加载失败 fallback。
8. 保留并优化 Core Tools：Explore TE Graph、Browse TE Taxonomy、Expression Atlas、Path Finder、Search / Browse Database。

请先创建 docs/exec-plans/active/home-v2-refresh.md。实现后至少运行：
php -l index.php
php -l api/home_stats.php
相关 JS 静态检查
python scripts/checks/check_runtime_db_config.py
python scripts/checks/check_neo4j_tekg3.py
新增 home_stats API contract check
首页 browser smoke

如果全部通过，归档到 docs/exec-plans/completed/home-v2-refresh.md，并更新 docs/RELIABILITY.md 和 docs/exec-plans/tech-debt-tracker.md。验证失败则保留 active plan，记录失败命令、失败原因和下一步，不要归档。
```

## 9. 验收标准

- 首页第一屏不再包含搜索框。
- 第一屏右侧 SVG 明显比当前 mockup 更精细，能表达 TE-KG 的 taxonomy、relation、evidence 结构。
- 首页不加载完整 G6。
- 数据规模区保留。
- 饼图来自 Neo4j `tekg3` 实时只读统计，而不是硬编码。
- 饼图有 legend、数量、百分比和失败 fallback。
- Core Tools 可见且链接到现有页面。
- 不出现 Evidence Support 和 Sources / Provenance 独立区块。
- 不改 Neo4j 数据，不改 graph API 业务语义。
- 桌面和移动端不出现文字重叠。
- 支持 `prefers-reduced-motion`。

