# TE-KG 首页 v1 综合改进计划

更新时间：2026-05-23

本文整合 `docs/design-docs/v1.md` 和 `docs/design-docs/homepage_improvement_plan.md` 的内容：前者偏“参考调研与设计依据”，后者偏“可执行计划与母智能体提示词”。本文件作为后续首页 v1 refresh 的推荐入口。

本计划只讨论首页改进，不要求立即实现；真正实现时应另建 `docs/exec-plans/active/homepage-v1-refresh.md`。

## 1. 目标定位

TE-KG 首页不应只是一个空入口页，也不应做成纯营销落地页。更合适的定位是“科研数据库门户首页”：

- 第一屏让用户知道 TE-KG 是什么、能查什么、从哪里开始。
- 首页直接提供搜索、示例 query、图谱入口、taxonomy 入口和核心页面入口。
- 用真实数据规模和 evidence support 建立可信度。
- 用轻量 SVG/CSS 动画增强主题感，但不在首页加载完整 G6。
- 清楚展示数据来源、版本、可靠性边界和已知限制。

首页 v1 的核心目标：

- 提高信息密度，减少“空”的感觉。
- 增加轻量动画和主题视觉。
- 让首页像成熟知识图谱数据库，而不是临时 demo。
- 把 TE-KG 已经完成的 PubMed evidence、journal metrics、taxonomy、expression、G6 evidence UI 等能力展示出来。
- 保持稳：不改 Neo4j、graph API、taxonomy runtime、agent、expression 数据逻辑。

## 2. 参考网页与启发

### 2.1 HALD / HALDxAI 系列

参考链接：

- HALD: https://bis.zju.edu.cn/hald/
- HALDxAI: https://bis.zju.edu.cn/haldxai/home

可借鉴点：

- 首页直接提供搜索入口。
- 搜索支持实体类型或资源类型引导。
- 首页给出示例查询，例如 gene、disease、drug 或 phenotype。
- About 或 summary 区域用数据规模说明数据库可信度。
- 页面结构不复杂，但用户能快速知道“能搜什么、数据有多少、入口在哪里”。

对 TE-KG 的启发：

- 第一屏必须有主搜索框和示例 query，例如 `L1HS`、`LINE1`、`AluJb`、`SVA`、`Aging`。
- 首页应展示 TE、Disease、Function、Paper/Evidence 等规模，而不是只写介绍文字。
- 首页应把图谱、taxonomy、expression、path finder 等入口放到容易扫描的位置。

### 2.2 Hetionet

参考链接：

- https://het.io/

可借鉴点：

- 首页一句话说明它是整合多个数据库的 biomedical knowledge network。
- 明确展示节点数、节点类型、关系数、关系类型。
- 导航入口清楚，包括 About、Explore、Studies、Software 等。

对 TE-KG 的启发：

- 首页应有非常直接的定位句，例如：
  - `TE-KG is a transposable element-centered biomedical knowledge graph with literature evidence support.`
- 应展示核心统计：
  - TE entities
  - Disease entities
  - Function entities
  - BIO_RELATION relationships
  - PubMed evidence records
  - Paper nodes
  - journal metric coverage

### 2.3 ADiKA

参考链接：

- https://www.adika-ai.org/

可借鉴点：

- 首页顶部使用大数字卡片展示实体数、关系数、数据源数、实体类型。
- 有 Platform Highlights，说明知识整合、AI/reasoning、evaluation 等能力。
- 有 Interactive Knowledge Graph 区块，让用户预期可以探索图谱。
- 有 Publications / Research 区块，增强学术可信度。

对 TE-KG 的启发：

- 首页可分成“数据规模”“核心能力”“探索入口”“证据支持”“数据来源/版本”几个区块。
- 首页要像成熟数据库平台，而不是只放一个搜索框。

### 2.4 Bio-Graph

参考链接：

- https://bio-graph.io/

可借鉴点：

- 强调从 fragmented data 到 actionable graph。
- 用 Use Cases 解释平台能解决什么问题。
- 用 Architecture / Layers 展示平台组成，例如 knowledge graph、evidence mining、prioritization、provenance。
- 强调 provenance、explainability、evidence。

对 TE-KG 的启发：

- 首页应突出“证据可追溯”：
  - 每条边可查看 PMID evidence table。
  - 每条关系有 `support_pmid_count`、IF/JCR/year range 等 evidence support。
- 首页应说明 TE-KG 不只是可视化图谱，而是带文献证据和数据来源的探索工具。

### 2.5 Open Targets / PrimeKG / KG-Hub

参考链接：

- Open Targets Platform: https://platform.opentargets.org/
- Open Targets evidence docs: https://platform-docs.opentargets.org/evidence
- PrimeKG registry: https://kghub.org/kg-registry/resource/primekg/primekg.html

可借鉴点：

- 强调 evidence source、数据版本、文档、可下载资源。
- Open Targets 按数据源组织 target-disease evidence。
- PrimeKG 强调跨多个生物尺度整合资源。

对 TE-KG 的启发：

- 首页应引导用户理解 evidence support，而不是把 IF 误称为 confidence。
- 首页可以提供“数据来源 / 证据模型 / 文档 / 下载或导出能力”的入口。

## 3. 首页建议结构

### 3.1 第一屏：定位 + 搜索 + 主题视觉

目标：

- 用户一进入首页就知道 TE-KG 是什么。
- 用户可以立刻搜索 TE、Disease、Function 或 evidence 相关对象。
- 页面视觉不再空，但仍保持科研数据库风格。

建议内容：

- 标题：`TE-KG`
- 副标题：`A transposable element-centered biomedical knowledge graph with literature evidence support.`
- 主搜索框：
  - placeholder: `Search L1HS, LINE1, Alu, SVA, disease, function...`
- 示例 query：
  - `L1HS`
  - `LINE1`
  - `AluJb`
  - `SVA`
  - `Aging`
- 主入口按钮：
  - `Explore Graph`
  - `Browse Taxonomy`
- 背景或右侧视觉：
  - 轻量 SVG/CSS 动画。
  - 元素可以包括灰色 DNA、TE 彩色片段、少量知识图谱节点、evidence pulse。
  - 不加载完整 G6。
  - 支持 `prefers-reduced-motion`。

给母智能体的简短提示词：

```text
请读取 AGENTS.md、ARCHITECTURE.md、docs/architecture/index.md 和 docs/architecture/current_system.md，创建首页 v1 active plan。先只改 index.php 及其相关 CSS/JS，把第一屏改成 TE-KG 数据库门户：包含主搜索框、示例 query、Explore Graph / Browse Taxonomy 入口，以及轻量 SVG/CSS 主题动画。不要加载完整 G6，不改 Neo4j/API/taxonomy/agent/expression。
```

### 3.2 Data Snapshot：数据规模指标

目标：

- 像 HALD、Hetionet、ADiKA 一样，用真实数字建立可信度。
- 让用户快速知道 TE-KG 的覆盖范围。

建议展示：

- TE entities
- Disease entities
- Function / pathway entities
- BIO_RELATION relationships
- PubMed evidence records
- Paper nodes
- Journal metric coverage
- Taxonomy nodes / edges

实现原则：

- 数字必须有来源，不能让 LLM 猜。
- 优先复用已有 API、check 输出、generated docs、processed metadata 或已归档 completed plans。
- 如果 v1 暂时静态写入，也要在文档中记录来源日期和后续动态化计划。
- 不要在首页直接运行重查询。

给母智能体的简短提示词：

```text
请为首页增加 Data Snapshot 区。先派 Explorer 只读确认当前已有 check/API/文档里哪些统计数字可复用，再实现一组小型指标卡。数字必须有来源，不能让 LLM 猜；如果暂时静态写入，要在文档中记录来源日期和后续动态化计划。不要在首页引入重查询。
```

### 3.3 Core Tools：核心能力入口

目标：

- 让首页说明 TE-KG 能做什么，而不是让用户点进去后再猜。
- 把现有 runtime pages 组织成清楚的工具入口。

建议入口：

- `Explore TE Graph`
  - 进入 `preview.php`
  - 说明支持 Jump / Expand / edge evidence table。
- `Browse TE Taxonomy`
  - 进入 taxonomy/tree 相关入口。
- `Expression Atlas`
  - 进入 `expression.php`。
- `Path Finder`
  - 进入 `path_finder.php`。
- `Search Database`
  - 进入搜索页或当前全站搜索入口。

实现原则：

- 每张卡片只写一句“用户能做什么”。
- 链接到现有页面，不新增页面。
- 不重构 root runtime pages。

给母智能体的简短提示词：

```text
请在首页增加 Core Tools 区，卡片入口包括 Explore TE Graph、Browse TE Taxonomy、Expression Atlas、Path Finder、Search Database。每个入口要链接到现有 runtime page，不新增页面，不重构 root runtime pages。完成后跑 PHP lint 和首页浏览器 smoke。
```

### 3.4 Evidence Support：证据支持展示

目标：

- 把已经完成的 PubMed metadata、journal metrics、Paper enrichment、BIO_RELATION support aggregation、G6 evidence table 展示出来。
- 让用户理解图谱边不是“空关系”，而是有文献证据支持。

建议展示：

- 每条关系可追溯到 PMID。
- 边支持度包含：
  - PMID count
  - journal metric coverage
  - IF mean / max
  - JCR Q counts
  - publication year range
- 点击图谱边后可查看 PubMed evidence table。
- evidence 超过 10 行时可导出 CSV。

必须避免：

- 不把 IF、Journal Impact Factor 或 `support_*` 称为 confidence。
- 不让 LLM 猜缺失 IF。
- 缺失 IF 应显示为 null / unknown / `—`。

给母智能体的简短提示词：

```text
请在首页增加 Evidence Support 区，解释 TE-KG 的边证据来自 PubMed 和 Paper enrichment，并展示 PMID count、IF/JCR/year range 等字段示意。严格避免把 IF 或 support_* 称为 confidence；缺失 IF 必须描述为 null/unknown，不允许推断。优先做静态示意，不要在首页加载完整 G6 图谱。
```

### 3.5 Data Sources & Provenance：数据来源与可追溯性

目标：

- 说明 TE-KG 的数据从哪里来、如何追溯、哪些地方存在可靠性边界。

建议展示：

- Neo4j runtime target：`tekg3`
- PubMed metadata：2308 records
- Paper enrichment import tag：`journal_metrics_v1_2026-05-22`
- relation support aggregation import tag：`relation_metrics_v1_2026-05-22`
- journal metrics source：`impact_factor_package_2025`
- taxonomy：rmsk_repbase / Neo4j/API runtime truth
- evidence_records 不含 abstract
- 缺失 IF 保持 null，不猜测

可靠性边界：

- `impact_factor_package_2025` 是内部 v1 可信来源，不是官方 JCR direct export。
- `title_exact` fallback 匹配的 journal 在对外展示或发表前应人工复核。
- unmatched journal metrics 保持 null 是预期行为，不是需要 LLM 补全的缺口。

给母智能体的简短提示词：

```text
请在首页增加 Data Sources & Provenance 区，读取 docs/RELIABILITY.md 和 completed plans 中的已归档事实，只展示可验证事实。必须说明 tekg3、PubMed metadata、journal metrics v1、relation support aggregation、taxonomy runtime truth 和可靠性边界。不要新增数据库写入。
```

### 3.6 Quick Start：示例工作流

目标：

- 降低新用户进入门槛。
- 告诉用户从首页开始应该点什么。

建议 workflow：

1. Search `L1HS` 或 `LINE1`。
2. Open TE graph。
3. Click an edge。
4. Inspect PubMed evidence。
5. Export edge evidence CSV。

也可以增加：

- Browse taxonomy → expand to L1HS
- Search disease/function → inspect related TE
- Open expression page → select TE expression profile

给母智能体的简短提示词：

```text
请在首页增加 Quick Start 区，用 3-5 个短步骤说明如何从 L1HS 或 LINE1 示例开始探索图谱、查看边证据、导出 PubMed evidence CSV。只写用户操作，不写开发过程，不引入新功能。
```

### 3.7 Data Version / Release Notes：版本与更新状态

目标：

- 让用户知道数据库不是静态黑箱。
- 让维护者能在首页暴露当前运行版本和关键数据更新。

建议展示：

- 当前 Neo4j target：`tekg3`
- PubMed metadata records：2308
- Journal metrics v1
- Paper enrichment status
- Relation aggregation status
- G6 evidence UX status
- Taxonomy parser fix status

实现原则：

- 数据来自 `docs/RELIABILITY.md`、completed plans 或 generated summary。
- 首页不要运行重查询。
- 如果是静态版本信息，要记录更新时间。

给母智能体的简短提示词：

```text
请为首页增加 Data Version / Release Notes 区，展示当前 tekg3、PubMed metadata 2308 records、journal metrics v1、relation support aggregation、G6 evidence UX、taxonomy parser fix 等状态。数据来自 docs/RELIABILITY.md 或 completed plans，不要在首页跑重查询。
```

### 3.8 轻量动态背景

目标：

- 解决首页视觉上太空的问题。
- 增加 TE-KG 的主题感，但不牺牲性能。

建议实现：

- 使用 HTML/CSS/SVG。
- 不引入第三方动画库。
- 不加载 G6。
- 可以表现：
  - 灰色 DNA 线段
  - TE 彩色片段
  - 少量知识图谱节点
  - evidence pulse 沿边移动
  - 文献证据点流向关系边
- 支持 `prefers-reduced-motion`。
- 移动端降低动画复杂度，不能遮挡搜索框。

给母智能体的简短提示词：

```text
请为首页第一屏增加轻量 TE-KG 主题动画，使用 SVG/CSS 实现，不引入第三方库，不加载 G6。动画元素包括灰色 DNA、TE 彩色片段、少量知识图谱节点和 evidence pulse。必须支持 prefers-reduced-motion，并在移动端保持不遮挡搜索框。
```

## 4. 推荐实施顺序

如果想稳妥推进，建议分 3 个阶段：

1. 首页信息架构 v1
   - 第一屏
   - 搜索入口
   - 示例 query
   - 核心工具入口
   - Quick Start

2. 数据可信展示 v1
   - Data Snapshot
   - Evidence Support
   - Data Sources & Provenance
   - Data Version / Release Notes

3. 视觉增强 v1
   - 轻量 SVG/CSS 动画
   - 响应式细节
   - reduced-motion
   - 浏览器截图验证

如果额度充足，也可以让母智能体一次性完成。但仍建议让它按角色分工：

- Explorer：只读调查首页现状、入口文件、已有统计来源、已归档 evidence/journal metrics/taxonomy 事实。
- Worker：实现首页相关文件和 CSS/JS。
- Verifier：独立跑 lint、静态检查、browser smoke。
- Reviewer：检查是否误称 IF 为 confidence、是否破坏已有入口、是否出现移动端重叠。

## 5. 一次性执行提示词

如果希望直接让母智能体完成首页 v1，可以发送：

```text
请按母智能体流程完成 TE-KG 首页 v1 refresh，全程用中文和我沟通。

先读取 AGENTS.md、ARCHITECTURE.md、docs/architecture/index.md、docs/architecture/current_system.md、docs/RELIABILITY.md、docs/exec-plans/tech-debt-tracker.md，并派 Explorer 只读检查 index.php、相关 CSS/JS、首页入口、已归档 evidence/journal metrics/taxonomy 事实。

目标：把首页改成更完整的科研数据库门户，而不是纯营销页。范围只允许改首页相关文件、必要的 CSS/JS、执行计划和文档；不要改 Neo4j、graph API、taxonomy runtime、agent、expression 数据逻辑，也不要加载完整 G6。

必须实现：
1. 第一屏：TE-KG 定位、主搜索框、示例 query、Explore Graph / Browse Taxonomy 入口、轻量 TE-KG 主题动画。
2. Data Snapshot：展示可验证的数据规模指标，数字必须来自已有文档/check/API，不能猜，也不要在首页跑重查询。
3. Core Tools：Graph、Taxonomy、Expression、Path Finder、Search Database 五个入口。
4. Evidence Support：展示 PubMed/IF/JCR/year range 等证据能力，但不得把 IF/support_* 称为 confidence。
5. Data Sources & Provenance：展示 tekg3、PubMed metadata、journal metrics v1、relation aggregation、taxonomy runtime truth 和可靠性边界。
6. Quick Start：用 3-5 步说明如何搜索、进图谱、点边看 evidence table、下载 CSV。
7. Data Version / Release Notes：展示当前数据和功能版本状态。
8. 响应式和 prefers-reduced-motion 支持。

请先创建 docs/exec-plans/active/homepage-v1-refresh.md。实现后至少运行 php -l index.php、相关 JS 静态检查、首页 browser smoke；如全部通过，归档到 docs/exec-plans/completed/homepage-v1-refresh.md，并更新 docs/RELIABILITY.md 和 docs/exec-plans/tech-debt-tracker.md。验证失败则保留 active plan，记录失败命令、失败原因和下一步，不要归档。
```

## 6. 首页设计原则

- 首页应像科研数据库门户，不像商业 SaaS 落地页。
- 使用真实数据规模和真实入口，不写空泛宣传语。
- 不在首页加载完整 G6，避免性能风险。
- 动画应轻量，作为背景或微交互，不抢搜索入口。
- 文案要短，用户能快速知道：
  - TE-KG 是什么；
  - 能搜什么；
  - 数据有多少；
  - 如何进入图谱；
  - 证据如何追溯；
  - 数据来源和限制是什么。
- 不把 IF、Journal Impact Factor 或 `support_*` 称为 confidence。
- 缺失指标保持 null / unknown / `—`，不由 LLM 补全。
- 不新增第二套 taxonomy truth source。
- 不回退到 `tekg2` / `tekg21`。

## 7. 验收标准

- 首页第一屏不再空。
- 用户不用阅读长文就能知道 TE-KG 能查什么。
- 搜索入口和核心页面入口清楚。
- 至少有一个轻量主题动画。
- 数据规模、证据支持、来源版本都有展示。
- 数据数字有来源，不能是猜测值。
- 不误导：IF 不是 confidence，缺失指标不是待 LLM 补全。
- 不破坏现有 `preview.php`、G6、taxonomy、expression 和 API contract。
- 首页在桌面和移动端都不出现文字重叠。
- 支持 `prefers-reduced-motion`。
- 验证通过后有 completed plan 和文档记录。

