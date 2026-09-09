# About 页面中文教程草稿

本文是 `about.php` 英文页面的中文教程草稿，也是后续替换真实截图和 GIF 的制作说明。排版参考 CellAnalyst DEG 帮助文档：在每个有图的大节或关键流程中，先写图片路径，再写截图建议、标注建议，最后写编号说明。图片文件已经固定放在 `assets/img/about/`，后续只要用真实截图或 GIF 覆盖同名文件，About 页面会自动显示新素材。

## 页面导语

`About` 页面是 TE-KG 公共界面的详细使用指南。它不只是介绍项目背景，也帮助用户判断应该从哪个入口开始：Home 用于整体概览，Browse 用于实体查找，Path Finder 用于连接路径和证据，TE-KG 用于图谱探索，Expression 用于 TE 表达上下文，Download 用于公共文件获取，Agent 用于自然语言导航和解释辅助。

页面顶部文案强调：TE-KG 将 TE 知识作为相互连接的证据网络来探索，而不是作为彼此孤立的表格来阅读。用户阅读本页时，应特别注意两个原则：一是所有运行时图谱页面都应理解为当前 Neo4j `tekg3` 数据源上的视图；二是关系层级结论必须回到证据表和论文元数据中核验。

## 01 About TE-KG

TE-KG 是一个以转座元件为中心的知识图谱界面，用于在同一个本地数据库环境中探索 TE 实体、疾病、分子功能、文献证据、表达语境和公共数据导出。这个部分适合放一张资源总览图，帮助用户先建立“页面入口”和“证据网络”的整体印象。

图片：`assets/img/about/about-resource-overview.png`

截图建议：可以使用 Home 首屏、TE-KG 图谱页或一张人工整理的架构式截图。画面应突出 TE-KG 的核心对象：TE、Disease、Function、Paper、Expression、Download。建议采用 1440x900 或 1200x720 比例，保留足够边距，避免把所有页面截图硬塞到一张图里。重点是让用户看到 TE-KG 是一个围绕 TE 组织起来的连接型资源，而不是单页工具集合。

标注建议：

- `TE-centered knowledge graph resource`
- `Runtime graph source: Neo4j tekg3`
- `Connected entities, relations, and publication evidence`
- `Public workflows for lookup, graph review, expression, and download`

### 1. 理解 TE-KG 是什么

TE-KG 将转座元件知识组织为相互连接的实体，而不是彼此分散的表格。用户在页面中看到的 TE、疾病、功能、论文、表达数据和下载文件，都应理解为围绕同一套数据库和公共工作流组织起来的资源。

公共界面包含目录查找、图谱探索、路径搜索、表达查看和可下载数据集。不同页面回答的问题不同：Browse 适合确认实体是否存在；Path Finder 适合询问两个实体之间是否有连接；TE-KG 适合查看可视化网络；Expression 适合查看表达上下文；Download 适合获取公开文件。

运行时图谱目标是 Neo4j `tekg3`。当用户在 TE-KG、Path Finder 或其他图谱相关页面看到节点、边和证据时，应把这些内容理解为 `tekg3` 运行时数据源上的视图，而不是静态文档中的固定结论。

TE-KG 的目标是让 TE 相关关系变得可审阅，尤其是在关系依赖文献证据时。用户不应只看一条边或一个摘要句，而应进一步查看支持论文、PMID、期刊、年份和匹配信息。

### 2. 本指南覆盖什么

本指南解释每个公共页面适合回答什么问题，并说明主要控件的使用顺序。它区分实体查询、图谱浏览、路径证据、表达分析和公共文件下载等不同工作流，避免用户把所有入口当成同一种搜索框。

本指南也记录证据阅读注意事项。尤其是 IF 和 JCR 属于期刊指标，不应被称为置信度；缺失的 IF 或 JCR 不应被猜测补齐；当页面之间看起来不一致时，应先检查页面上下文、API payload 和数据来源，再判断是否存在生物学差异。

图片：`assets/img/about/about-resource-data-routes.png`

截图建议：建议制作一张流程图或路径图，而不是截取单个页面。图中可以把 Home 放在入口位置，向 Browse、Path Finder、TE-KG、Expression、Download 分流。每个分支旁边放一句短说明，例如 Browse 对应 entity lookup，Path Finder 对应 relation path，Download 对应 public files。

标注建议：

- `Start with Home for dataset scale and entry points`
- `Use Browse for catalog lookup`
- `Use Path Finder when the question is a connection`
- `Use TE-KG for visual graph exploration`
- `Use Expression for TE expression context`
- `Use Download for public files`

### 3. 选择数据访问路线

当用户需要快速了解当前数据库包含什么时，应先使用 Home。Home 提供项目级方向感，包括数据集状态、主要入口和快速链接。

当用户已经知道或大致知道实体名称时，应使用 Browse。Browse 的价值在于通过表格和数据库驱动的建议列表确认实体是否存在，并帮助用户避免自由输入造成的名称歧义。

当关系证据很重要时，应使用 Path Finder 或 TE-KG。Path Finder 更适合明确的两个端点问题，例如某个 TE 与某个疾病之间是否存在连接；TE-KG 更适合探索邻域网络、节点类型和关系类型。

当问题涉及 TE 表达上下文时，应使用 Expression。当用户需要支持可见工作流的公开文件时，应使用 Download。

图片：`assets/img/about/about-resource-evidence-table.png`

截图建议：建议截取 Path Finder 或 TE-KG 中的证据表局部。画面应保留表头和至少两行示例记录，重点展示 PMID、Title、Year、Journal、IF、JCR、Match type 等字段。不要把截图做成只有 PMID 列的清单，因为本指南要强调证据元数据的完整阅读方式。

标注建议：

- `PMID identifies the supporting publication`
- `Title and year help verify the source`
- `Journal, IF, and JCR are descriptive metadata`
- `Match type explains how journal metadata was linked`
- `IF is not confidence`
- `Missing metrics should remain missing`

### 4. 证据阅读原则

关系层级声明应在可用时核对支持论文。用户看到某条关系时，应查看它对应的 PMID、标题和证据表，而不是只依赖图上的边标签。

PMID、标题、年份、期刊、IF、JCR 和 match type 都是证据元数据字段。它们的作用不同，不能互相替代。IF 不应称为 confidence，JCR 也不表示某条关系一定更可靠。

缺失的 IF 或 JCR 不应被猜测。缺失值本身是一种元数据状态，应在界面和解释中保持明确。

当页面看起来不一致时，应先检查页面语境和数据来源。例如 Browse 是实体发现界面，Path Finder 是路径证据界面，TE-KG 是图谱可视化界面。不同页面的呈现重点不同，并不必然表示生物学结论冲突。

## 02 Home Overview

Home 是 TE-KG 的方向层。它介绍项目，显示 Neo4j 支持数据集的实时规模，并提供进入公共数据库工作流的紧凑路径。

图片：`assets/img/about/about-home-overview.png`

截图建议：建议截取 Home 页面首屏，保留项目简介、Dataset Status 区域和 Quick Links。右侧如果仍是架构图占位，也可以保留，因为它能说明 Home 是项目级入口，不是详细图谱运行时。截图不需要滚动到页面底部，重点是让用户知道从 Home 可以进入哪些核心页面。

标注建议：

- `Project-level overview`
- `Live dataset status from Neo4j tekg3`
- `Quick links to public workflows`
- `Use Home to decide where to go next`

### 1. 页面包含什么

Overview 区域概述 TE-KG 的目标，并为未来架构图保留右侧图像区域。这个区域用于建立项目级方向感，不承担详细检索或证据审阅功能。

Dataset Status 报告来自 Neo4j `tekg3` 的实时只读统计，而不是写死在页面源代码中的固定数字。用户如果看到数据规模变化，应优先理解为运行时数据更新或加载状态变化。

环形图分别展示实体组成、TE 分类和关系谓词组成。Quick Links 提供进入 Browse、Path Finder、TE-KG、Expression、Download 和 About 指南的直接入口。

图片：`assets/img/about/about-home-dataset-status.png`

截图建议：建议截取 Dataset Status 完整区域，包含三个环形图和 TE classification 层级切换控件。如果页面有加载失败或 fallback 状态，也可以另外准备版本作为内部参考，但正式教程图应优先展示正常加载后的状态。

标注建议：

- `Entity Composition counts major node classes`
- `TE Classification can switch taxonomy levels`
- `Relation Composition summarizes BIO_RELATION predicates`
- `Fallback appears when live statistics cannot load`

### 2. 阅读 Dataset Status

Entity Composition 按 Neo4j 节点逐一统计主要节点类别。它回答的是“当前图谱中有哪些类型的实体以及大致规模如何”。

TE Classification 可以切换分类层级，因此图表可以从宽泛类别切换到更具体的 taxonomy 层级。用户应注意当前选中的层级，不要把不同层级的数字直接混为一谈。

Relation Composition 使用 BIO_RELATION 谓词级统计，使常见关系类型可见，而不是把所有关系折叠成模糊标签。

如果实时统计无法加载，页面应显示 fallback，而不是编造或猜测数值。用户遇到 fallback 时应检查运行时服务和数据源状态。

### 3. 推荐使用流程

当用户需要快速了解当前数据库包含什么内容时，从 Home 开始。Home 的作用是帮助用户决定下一步，而不是完成所有分析。

如果用户知道实体名称，下一步进入 Browse；如果用户想查两个实体之间的连接，进入 Path Finder；如果用户想查看可视化网络，进入 TE-KG；如果用户想看表达数据，进入 Expression；如果用户需要文件，进入 Download。

## 03 Browse

Browse 是表格优先的查询界面。它适合用户在打开图谱、路径搜索或表达工作流前，直接扫描 TE-KG 实体。

图片：`assets/img/about/about-browse-main.png`

截图建议：建议截取 Browse 页面主体，包括实体类别选择、名称输入或选择区域、结果表格和关键链接控件。截图应显示这是 catalog-style lookup，而不是图谱画布。表格中至少保留几行示例，便于用户理解 Browse 适合比较多条记录。

标注建议：

- `Catalog-first entity lookup`
- `Choose entity category before selecting a name`
- `Use database-backed suggestions`
- `Open graph, detail, or evidence workflows after lookup`

### 1. 页面用途

当用户想要目录式视图，而不是图谱优先探索时，应使用 Browse。Browse 适合检查某个 TE、疾病、功能或其他支持实体是否存在。

下拉建议由数据库驱动，因此用户不需要凭记忆猜测精确名称。选择建议项比直接输入自由文本更可靠，尤其是在实体名称存在大小写、同义词或分类歧义时。

Browse 也适合在选择详细审阅对象前比较多条记录。用户可以先通过表格查看候选实体，再决定是否进入图谱、路径或表达页面。

图片：`assets/img/about/about-browse-selector.gif`

截图建议：GIF 建议展示完整交互：先选择实体类别，再输入名称前缀，然后从建议列表中选择实体，最后看到表格或详情区域更新。动图不需要过长，2-5 秒即可。鼠标移动和点击位置要清楚，建议保持浏览器缩放比例一致。

标注建议：

- `Select an entity category first`
- `Type a prefix to narrow database-backed suggestions`
- `Pick a suggested entity instead of unchecked free text`
- `Review the updated table before moving to another workflow`

### 2. 使用选择器

当页面提供类别选择器时，先选择实体类别。类别会约束后续建议列表，减少 TE、Disease、Function 等不同类型记录混在一起的风险。

输入名称开头可以在所选类别内按字母过滤建议。用户应从下拉建议中选择实体，而不是依赖未检查的自由文本。

选中实体后，可以使用表格或链接控件进入详情、图谱或证据工作流。Browse 的定位是发现和查找；如果关系支持很重要，应继续到 TE-KG 或 Path Finder 中审阅证据。

### 3. 数据解释

实体标签和名称应被视为来自 `tekg3` 的运行时数据库值。当同一名称出现在多个语境中时，应结合类别和关联元数据区分 TE、疾病和功能记录。

Browse 优化的是发现和查询，不是关系证据评估。用户不应只凭 Browse 表格就得出关系层级结论。

## 04 Path Finder

Path Finder 搜索两个已选实体之间的路径，并以可审阅格式展示关系层级支持。它适合回答“连接”问题，而不是简单查询问题。

图片：`assets/img/about/about-pathfinder-search.gif`

截图建议：GIF 建议截取 Path Finder 搜索区域，展示左右两侧的类别选择器和实体选择器如何配合。动图应包含选择 source category、选择 source entity、选择 target category、选择 target entity、点击搜索这几个关键动作。

标注建议：

- `Choose the source entity category`
- `Select a source entity from constrained suggestions`
- `Choose the target entity category`
- `Select the target entity before running path search`
- `Keep endpoints explicit to avoid mixed-category queries`

### 1. 搜索结构

搜索两侧都在同一搜索框中包含较窄的类别选择器和较宽的实体选择器。实体下拉受所选类别约束，因此选择 TE 会得到 TE 名称，选择 Disease 会得到疾病名称。

默认状态保持字段干净，用户可以从一对新的实体开始。这种结构避免把不同类别混入一个不受控制的 autocomplete 列表。

图片：`assets/img/about/about-pathfinder-results.png`

截图建议：建议截取路径结果区域，显示实体-关系-实体的序列。画面应包含关系标签，例如 activate、affect 或其他谓词，使用户看清路径不是单纯节点列表，而是由关系连接起来的证据结构。

标注建议：

- `Read the path as entity-relation-entity steps`
- `Relation labels should be interpreted at predicate level`
- `Each relation can have its own evidence`
- `Do not treat the whole path as one uniform claim`

### 2. 阅读路径结果

路径本身显示连接所选端点的实体和关系列表。关系标签应按详细谓词级别理解，例如 activate 或 affect，而不是被笼统理解为“有关联”。

每条关系下方的证据以表格显示，而不是松散 PMID 列表。证据行可以包含 PMID、年份、期刊、IF、JCR、match type 和标题。

图片：`assets/img/about/about-pathfinder-evidence.png`

截图建议：建议截取某条关系展开后的证据表。画面要显示表头和一到三条证据记录，尤其要保留 title 或 PMID，避免用户误以为证据只是期刊指标。

标注建议：

- `Use PMID and title to identify the source paper`
- `Journal metrics describe the journal, not relation confidence`
- `Match type explains metric linkage`
- `Review evidence separately for each relation`

### 3. 证据检查

使用 PMID 和标题识别支持出版物。期刊、IF 和 JCR 是描述性元数据，不是阅读来源的替代品。

使用 match type 理解论文如何关联到期刊指标。当一条路径有多个关系时，应分别审阅每个关系，而不是假设整条路径具有一致支持。

## 05 TE-KG Graph

TE-KG 是交互式 G6 图谱运行时。它通过 G6 视觉界面展示 Neo4j `tekg3` 实体和 BIO_RELATION 边，并包含图例、过滤器、节点操作和证据支持。

图片：`assets/img/about/about-tekg-graph.gif`

截图建议：GIF 建议展示图谱搜索或加载、平移缩放、点击节点、打开节点操作卡或关系证据控件。动图应保持画布主体清晰，避免只展示鼠标在空白处移动。若图谱较密，可选择一个较小邻域作为教程素材。

标注建议：

- `Search or load a graph neighborhood`
- `Pan and zoom without losing layout context`
- `Click a node to inspect actions and metadata`
- `Open relation evidence for visible edges`

### 1. 图谱交互

使用搜索或内置加载控件打开图谱邻域。用户可以平移和缩放来检查网络，同时保留高层布局感。

点击节点可以查看实体相关选项和元数据。当需要查看可见边的出版物支持时，应打开关系证据控件，而不是只根据边的视觉位置判断。

图片：`assets/img/about/about-tekg-legend.png`

截图建议：建议截取图谱图例区域，包含实体图例、关系图例、展开模式和过滤状态。截图可以只截取右侧或底部图例面板，但应保留一部分画布背景，帮助用户理解这是图谱视图的一部分。

标注建议：

- `Entity legend separates node types`
- `Relation legend exposes predicate categories`
- `Expanded legend mode supports dense graph review`
- `Filters change the view, not the database`

### 2. 图例和过滤器

实体图例帮助区分 TE、疾病、功能、论文和其他节点类型。关系图例暴露谓词类别，使用户更容易聚焦某一部分关系类型。

展开图例模式适合在图谱密集时进行详细审阅。过滤变化应理解为视图变化，而不是数据库写入。

### 3. 运行时边界

图谱页面读取当前运行时目标 `tekg3`。它不应被视为另一套 taxonomy truth source。

iframe bridge 状态、loader 状态和 legend 状态都是图谱体验的一部分，会影响用户看到的内容。如果可视图谱内容看起来不完整，应先比较 API payload 和浏览器渲染，再得出生物学结论。

## 06 Agent

Agent 是自然语言助手界面。它与核心表格和图谱页面分离，用于帮助用户组织问题、导航工作流，并理解下一步应检查什么。

图片：`assets/img/about/about-agent-main.png`

截图建议：建议截取 Agent 页面主界面，包括问题输入框、回答区域和可能的导航建议。截图应体现 Agent 是辅助层，而不是证据最终来源。若回答中包含页面链接或建议，应保留这些内容。

标注建议：

- `Ask clear entity, disease, function, paper, or PMID questions`
- `Use Agent to choose the next TE-KG workflow`
- `Verify relation claims in Path Finder or TE-KG evidence tables`
- `Do not replace direct evidence review with a text answer`

### 1. 可以问什么

用户可以询问想探索的 TE 实体、疾病、功能、论文或证据模式。当需要在 Browse、Path Finder、TE-KG、Expression 和 Download 之间选择时，可以使用 Agent。

尽量提供清晰实体名称或 PMID，使回答能够落在数据库语境中。对于歧义名称，可以先要求助手澄清候选实体，再继续。

### 2. 如何使用回答

应将 Agent 视为引导式导航和解释层。对于关系证据，应在 Path Finder 或 TE-KG 证据表中核验重要声明；对于数据集内容，应在 Download 中核验公共文件；对于表达问题，应在助手识别相关 TE 名称或语境后使用 Expression。

### 3. 重要限制

当声明依赖特定论文时，Agent 不应替代直接证据审阅。Agent 面向页面也不是普通 UI 或数据库修改的默认位置。

如果回答引用 IF 或 JCR，应记住它们是期刊指标，不应称为置信度。缺失的期刊指标应保持缺失，不应被猜测。

## 07 Expression

Expression 是 TE 表达查询界面。它帮助用户从数据库驱动选择器中选择有效 TE 名称后，查看受支持的 bulk expression 语境。

图片：`assets/img/about/about-expression-main.png`

截图建议：建议截取 Expression 页面主工作区，包含 TE 选择器、当前数据集或语境摘要、表达图表或详情入口。如果页面支持多个 cohort 或样本组，截图中应展示可比较的表达视图，而不是只截取空白选择框。

标注建议：

- `Select a TE from the database-backed list`
- `Confirm the displayed dataset or context`
- `Compare expression patterns across supported groups`
- `Keep expression evidence separate from graph relation evidence`

### 1. 选择 TE

使用 TE 下拉框从当前数据库支持列表中选择名称。输入前缀可以缩小建议范围，而不是手动输入自由标签。

该选择器模式与其他 TE 查询界面共享，以保持跨页面名称一致。如果某个 TE 没有出现在建议中，应先验证它当前是否可用，再继续。

### 2. 阅读表达视图

使用摘要区域确认当前显示的是哪个数据集或语境。使用图表比较受支持 cohort 或样本组之间的表达模式。当摘要不足以解释时，打开详情级视图。

表达证据和图谱关系证据应分开理解。它们回答的问题不同：表达视图关注数据集中的表达模式，图谱证据关注实体关系和文献支持。

### 3. 数据说明

当前运行时表达根目录是 `data/bulk_expression_web`。Expression 文件支持网站视图；公开暴露时也可以从 Download 页面下载。

表达值应结合显示的数据集和预处理路径解释。用户不应把一个表达上下文中的模式直接外推到所有数据集。

## 08 Download

Download 暴露支持可见 TE-KG 工作流的公共文件。页面使用传统表格，方便用户快速比较数据集名称、文件、用途和格式。

图片：`assets/img/about/about-download-main.png`

截图建议：建议截取 Download 表格主体，保留 Dataset、File、Used in、Format 等列。截图应显示多行不同类别文件，使用户知道这里是公共 catalog，而不是单个文件链接列表。

标注建议：

- `Dataset name describes the public export`
- `File points to the downloadable path`
- `Used in explains the visible workflow dependency`
- `Format identifies TSV, CSV, JSON, JSONL, TXT, or other types`

### 1. 表格布局

Dataset name 以人类可读级别描述公共数据导出。File 链接指向可下载文件路径。Used in 说明当前哪个页面或 pipeline 依赖该文件。Format 说明文件是 TSV、CSV、JSON、JSONL、TXT 还是其他暴露类型。

图片：`assets/img/about/about-download-filter.gif`

截图建议：GIF 建议展示类别过滤按钮、搜索框、展开数据集行和清空过滤。动图应从 All 状态开始，然后选择一个类别，例如 Expression 或 Graph，再输入搜索词，最后清空条件回到完整目录。

标注建议：

- `Filter by public data category`
- `Search dataset names, filenames, usage, formats, and descriptions`
- `Expand a row before downloading`
- `Clear filters to return to the full catalog`

### 2. 过滤下载项

使用 Expression、Graph 或 Taxonomy 等类别过滤按钮缩小表格范围。使用 Search 匹配数据集名称、文件名、用途描述、格式和行描述。

下载前需要简短说明时，可以展开数据集行。当想查看完整公共目录时，清空搜索文本或返回 All。

### 3. 目录范围

这里列出的文件应对应可见公共 TE-KG 页面或可审阅运行时数据。内部中间输出和归档文件默认不作为公共目录项。

文件出现在 Download 中表示它暴露给用户访问；这不意味着页面会写入 Neo4j。当路径变化时，应更新 Download，避免公共链接过期。

## 09 About

About 是 TE-KG 公共界面的详细指南。它解释每个页面做什么、如何使用，以及各页面如何相互关联。

### 1. 本指南如何组织

使用左侧导航在页面专属指南之间切换。每个章节描述目的、控件、数据解释和重要边界。指南面向需要决定从哪里开始以及如何验证发现结果的用户。

文本聚焦公共界面行为，而不是内部实现细节。内部架构、导入脚本和数据库维护不应混入用户教程主体，除非它们直接影响用户如何解释页面结果。

### 2. 选择正确工作流

使用 Browse 进行目录查询。使用 Path Finder 回答实体到实体的连接问题。使用 TE-KG 进行可视图谱探索和关系证据检查。使用 Expression 查看 TE 表达模式。使用 Download 获取公共数据导出。使用 Agent 获得自然语言引导，然后在相关页面核验重要声明。

### 3. 证据优先阅读

当做关系层级声明时，优先使用显示来源记录的页面。不要把期刊 IF 解释为置信度。不要假设缺失的 IF 或 JCR；缺失值应保持明确。

当页面间结果不同，应检查运行时来源、API payload 和页面语境，再判断是否存在生物学分歧。
