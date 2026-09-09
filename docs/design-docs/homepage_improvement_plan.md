# TE-KG 首页改进计划

更新时间：2026-05-23

## 1. 参考对象与可借鉴点

本计划参考了几个知识图谱或生物医学数据库主页的常见结构：

- HALDxAI / HALD 系列：适合作为“实验室数据库首页”的参照，重点是把资源入口、搜索、数据说明和平台定位放在第一屏。
- Hetionet：第一屏直接说明它整合了多少数据库、多少节点、多少关系，并提供 About / Explore / Studies / Software 等清晰入口。
- ADiKA：首页用大数字展示实体数、关系数、实体类型、数据来源，并把“平台亮点、数据来源、研究产出、合作方”分区展示。
- Bio-Graph：强调“从碎片化数据到可解释证据”，把能力拆成 unified KG、evidence mining、prioritization、provenance 等模块。
- Open Targets / PrimeKG / KG-Hub 类页面：强调数据版本、来源、证据、可复现入口和文档链接。

对 TE-KG 来说，不建议把首页做成纯营销页。更合适的是做成“数据库门户首页”：第一屏就能搜索、进入图谱、进入 taxonomy、进入 expression，同时让用户一眼知道数据库规模、证据链路、数据来源和当前版本。

## 2. 首页改进目标

当前首页的问题是信息密度偏低、视觉上较空、缺少轻量动画，也没有充分展示 TE-KG 已经完成的图谱、PubMed evidence、journal metrics、taxonomy 和 expression 能力。

首页 v1 的目标：

- 第一屏明确告诉用户 TE-KG 是什么、能查什么、应该从哪里开始。
- 增加数据库规模指标，而不是只放介绍文字。
- 增加轻量但有主题感的动画，不加载完整 G6 图谱。
- 把 graph、taxonomy、expression、path finder、search 几个核心入口组织成可扫描的模块。
- 展示 evidence support，但明确它不是 confidence。
- 展示数据来源、版本和可靠性边界。
- 保持首页轻、快、稳定，不动 Neo4j 写入和 G6 runtime 语义。

## 3. 建议首页模块

### 3.1 第一屏：搜索 + 主题视觉

建议内容：

- 标题：TE-KG 或 Transposable Element Knowledge Graph。
- 一句话定位：面向转座元件、疾病、功能、表达和文献证据的本地知识图谱数据库。
- 主搜索框：支持输入 `L1HS`、`LINE1`、`AluJb`、`SVA`、`Aging` 等示例。
- 主按钮：`Explore Graph`、`Browse Taxonomy`。
- 轻量动画：用 SVG / CSS 表达 TE 片段、DNA、节点连线或证据流动，不在首页加载完整 G6。

给母智能体的简短提示词：

```text
请读取 AGENTS.md、ARCHITECTURE.md、docs/architecture/index.md 和 docs/architecture/current_system.md，创建首页 v1 active plan。先只改 index.php 及其相关 CSS/JS，把第一屏改成 TE-KG 数据库门户：包含主搜索框、示例 query、Explore Graph / Browse Taxonomy 入口，以及轻量 SVG/CSS 主题动画。不要加载完整 G6，不改 Neo4j/API/taxonomy/agent。
```

### 3.2 数据规模指标区

建议展示：

- TE entities
- disease / function / pathway 等实体数量
- BIO_RELATION 关系数量
- PubMed evidence 数量
- Paper 节点数量
- journal metric coverage
- taxonomy nodes / edges

这些数字不要手填死在代码里，优先从已有 API、check 输出、静态 metadata 或后端轻量 endpoint 中读取。如果 v1 先静态展示，也必须注明来源和更新日期。

给母智能体的简短提示词：

```text
请为首页增加 Data Snapshot 区。先派 Explorer 只读确认当前已有 check/API/文档里哪些统计数字可复用，再实现一组小型指标卡。数字必须有来源，不能让 LLM 猜；如果暂时静态写入，要在文档中记录来源日期和后续动态化计划。
```

### 3.3 核心能力入口区

建议做成 5 个入口卡片：

- Explore TE Graph：进入 `preview.php`
- Browse TE Taxonomy：进入 taxonomy/tree 相关入口
- Expression Atlas：进入 `expression.php`
- Path Finder：进入 `path_finder.php`
- Search Database：进入搜索页或当前搜索入口

每张卡片只写一句“用户能做什么”，不要写太长的项目背景。

给母智能体的简短提示词：

```text
请在首页增加 Core Tools 区，卡片入口包括 Explore TE Graph、Browse TE Taxonomy、Expression Atlas、Path Finder、Search Database。每个入口要链接到现有 runtime page，不新增页面，不重构 root runtime pages。完成后跑 PHP lint 和首页浏览器 smoke。
```

### 3.4 Evidence Support 展示区

TE-KG 已经完成 PubMed metadata、journal metrics、Paper enrichment、BIO_RELATION support 聚合和 G6 evidence table。首页应该把这个能力说清楚。

建议展示：

- 每条关系可以追溯到 PMID。
- 边支持度包含 PMID count、journal metric coverage、IF mean/max、JCR counts、publication year range。
- IF 是 Journal Impact Factor，不是 confidence。
- 缺失 IF 保持 null，不由 LLM 猜测。
- 点击图谱边后可查看 PubMed 表格和 CSV。

给母智能体的简短提示词：

```text
请在首页增加 Evidence Support 区，解释 TE-KG 的边证据来自 PubMed 和 Paper enrichment，并展示 PMID count、IF/JCR/year range 等字段示意。严格避免把 IF 或 support_* 称为 confidence；缺失 IF 必须描述为 null/unknown，不允许推断。
```

### 3.5 数据来源与版本区

建议展示：

- Neo4j runtime target：`tekg3`
- PubMed metadata：2308 records
- journal metrics v1：`impact_factor_package_2025`
- relation support aggregation：`relation_metrics_v1_2026-05-22`
- taxonomy：rmsk_repbase / Neo4j/API runtime truth
- 当前版本的限制：不是官方 JCR direct export，title_exact fallback 需外部展示前复核。

给母智能体的简短提示词：

```text
请在首页增加 Data Sources & Version 区，读取 docs/RELIABILITY.md 和 completed plans 中的已归档事实，只展示可验证事实。必须说明 tekg3、PubMed metadata、journal metrics v1、relation support aggregation 和 taxonomy runtime truth。不要新增数据库写入。
```

### 3.6 Quick Start 区

建议用 3-4 步告诉用户如何使用：

1. 搜索 `L1HS` 或 `LINE1`。
2. 打开图谱。
3. 点击一条边查看 PubMed evidence table。
4. 如果 evidence 超过 10 条，下载 CSV。

给母智能体的简短提示词：

```text
请在首页增加 Quick Start 区，用 3-4 步说明如何从搜索进入图谱、点击边查看 PubMed evidence table、下载 CSV。只写用户操作，不写开发过程，不引入新功能。
```

### 3.7 轻量动态背景

建议实现方式：

- 使用 HTML/CSS/SVG，不引入第三方动画库。
- 可以表现 DNA 灰色线段、TE 彩色片段、节点连线、证据点沿边移动。
- 动画只做氛围和主题提示，不承担数据真实渲染。
- 支持 `prefers-reduced-motion`。
- 移动端降低复杂度。

给母智能体的简短提示词：

```text
请为首页第一屏增加轻量 TE-KG 主题动画，使用 SVG/CSS 实现，不引入第三方库，不加载 G6。动画元素包括灰色 DNA、TE 彩色片段、少量知识图谱节点和 evidence pulse。必须支持 prefers-reduced-motion，并在移动端保持不遮挡搜索框。
```

## 4. 推荐实施顺序

如果想稳妥推进，建议分 3 个小阶段：

1. 首页信息架构 v1：第一屏、搜索入口、核心工具入口、Quick Start。
2. 数据可信展示 v1：Data Snapshot、Evidence Support、Data Sources & Version。
3. 视觉增强 v1：轻量动画、响应式细节、浏览器截图验证。

如果额度充足，也可以让母智能体一次性完成，但要让它派 Explorer 先读入口文档和首页现状，再由 Worker 实现，最后由 Verifier 独立跑检查。

## 5. 一次性执行提示词

如果希望一次性让母智能体完成首页 v1，可以直接发：

```text
请按母智能体流程完成 TE-KG 首页 v1 refresh。

先读取 AGENTS.md、ARCHITECTURE.md、docs/architecture/index.md、docs/architecture/current_system.md、docs/RELIABILITY.md、docs/exec-plans/tech-debt-tracker.md，并派 Explorer 只读检查 index.php、相关 CSS/JS、首页入口、已归档 evidence/journal metrics/taxonomy 事实。

目标：把首页改成更完整的数据库门户，而不是纯营销页。范围只允许改首页相关文件、必要的 CSS/JS、执行计划和文档；不要改 Neo4j、graph API、taxonomy runtime、agent、expression 数据逻辑，也不要加载完整 G6。

必须实现：
1. 第一屏：TE-KG 定位、主搜索框、示例 query、Explore Graph / Browse Taxonomy 入口、轻量 TE-KG 主题动画。
2. Data Snapshot：展示可验证的数据规模指标，数字必须来自已有文档/check/API，不能猜。
3. Core Tools：Graph、Taxonomy、Expression、Path Finder、Search Database 五个入口。
4. Evidence Support：展示 PubMed/IF/JCR/year range 等证据能力，但不得把 IF/support_* 称为 confidence。
5. Data Sources & Version：展示 tekg3、PubMed metadata、journal metrics v1、relation aggregation、taxonomy runtime truth 和可靠性边界。
6. Quick Start：用 3-4 步说明如何搜索、进图谱、点边看 evidence table、下载 CSV。
7. 响应式和 reduced-motion 支持。

请先创建 docs/exec-plans/active/homepage-v1-refresh.md。实现后至少运行 php -l index.php、相关 JS/CSS 静态检查、首页 browser smoke；如全部通过，归档到 docs/exec-plans/completed/homepage-v1-refresh.md，并更新 docs/RELIABILITY.md 和 tech-debt-tracker.md。全程用中文和我汇报。
```

## 6. 验收标准

- 首页第一屏不再空。
- 用户不用阅读长文就能知道 TE-KG 能查什么。
- 搜索入口和核心页面入口清楚。
- 至少有一个轻量主题动画。
- 数据规模、证据支持、来源版本都有展示。
- 不误导：IF 不是 confidence，缺失指标不是待 LLM 补全。
- 不破坏现有 `preview.php`、G6、taxonomy、expression 和 API contract。
- 首页在桌面和移动端都不出现文字重叠。

