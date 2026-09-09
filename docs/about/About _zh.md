# TE-Dee About 帮助文档

本文档是 About 公共页面的中文编辑稿，既记录面向用户的文字，也记录后续静态图和动态图的具体制作要求。图片说明只规定必须展示的内容，不限定截图或录制工具。由于网站界面为英文，图中标注建议继续使用下文给出的英文文字。

## 1. 关于 TE-Dee

TE-Dee 是一个面向人类转座元件的综合资源，将分类体系、文献提取的关系、代表性序列和基因组记录、表达与共表达环境、Variant 与 eQTL 证据、可下载数据，以及受证据约束的自然语言问答连接在一起。

### TE-Dee 是什么

TE-Dee 将人类转座元件的多种互补视图汇集到同一界面，而不是把每种数据当作孤立表格。该资源包括 TE 目录、实体详情页、基于文献的关系图、分类视图、表达与 TE-Gene 证据探索，以及可下载数据。TE-Dee 的核心目标是在便于探索 TE 信息的同时，让来源证据与解释边界保持可见。

### TE-Dee 的架构

TE-Dee 整合四类主要数据流。文献首先经过筛选，再进行 AI 辅助的实体和关系提取、标准化与人工整理。RMSK 基因组注释与 RepBase 分类和序列记录被统一到分类、序列和基因组注释层。表达数据集经过独立处理，用于构建特定环境下的 TE-Gene 共表达结果。GTEx v11 eQTL 数据则经 Variant 标准化、TE-Variant 严格坐标重叠和 Variant-Gene-Tissue 关联整理，形成可查询的 TE-Gene eQTL 证据。

其中，eQTL 证据的基本关系为：TE instance 与 Variant 的参考等位基因区间严格重叠，Variant 再在特定 tissue 中与 Gene 存在 GTEx eQTL 关联。知识图谱和分类运行于 Neo4j；目录、表达、共表达及 eQTL/Variant 运行数据保存在 MySQL，并通过统一 API 和证据服务支持 Browse、Path、Graph、Expression、Agent 和 DeepThink 工作流。

![alt text](<figs/TE-KG Data Architecture and Public Services.svg>)

### 数据访问入口

- 使用 Home 查看数据库当前的整体组成。
- 使用 Browse 浏览 TE 目录，再通过 Search 查看选中实体的详细记录、基因组位置和 Variant/eQTL 证据。
- 使用 Graph 探索知识关系、TE 分类和 TE-Gene Graph；使用 Path Finder 查看两个指定实体之间的连接。
- 使用 Expression 查看当前表达数据集中的丰度模式。
- 使用 Agent 或 DeepThink 提出有证据支撑的自然语言问题，并在当前对话中继续追问。
- 使用 Download 获取网站当前提供的文件。
- 使用 AI 窗口以在每一个页面使用 DeepThink 模式

![alt text](<figs/TE-KG Resource Overview.svg>)

## 2. Home 概览

Home 是 TE-Dee 的总览入口，用于介绍资源、概括当前数据库组成，并提供进入主要公共工作流的快捷路径。

- Overview 区域概述 TE-Dee 的目的和范围。
- Dataset Status 展示现有Neo4j的数据库规模，环形图分别展示实体组成、TE 分类和关系谓词组成。
  - Entity Composition 按存储节点统计主要知识图谱实体类别。
  - TE Classification 可以切换分类层级，因此图表能够从大类切换到更具体的分类层级。
  - Relation Composition 使用详细谓词统计，能够显示常见关系类型。
- Quick Links 可直接进入主要的检索、图谱、表达、下载和帮助工作流。

## 3. Browse

Browse 提供查找和查看 TE 记录的完整流程。内容包括 Summary、Local Graph、Sequence、Genome Annotation、Genome Browser 和 Variants。

![alt text](figs/Browse.png)

- Summary 用于确认当前记录，并展示现有的元数据。
- Local Graph 用于查看邻近实体和关系。
- Sequence 显示受支持的代表性或共识序列及现有注释，不代表每一个基因组 TE 拷贝。
- Genome Annotation Distribution 汇总当前 assembly 上支持的命中。
- Genome Browser 用于查看具体基因组位置，且可以通过点击 Genome Annotation Distribution 的命中来更新 Genomic hit 列表。
- Variants 默认以表格展示与当前 TE 严格重叠的 GTEx eQTL Variant。默认视图一行对应一个 Variant，并汇总关联 Gene、tissue 数量和最小 nominal p-value；也可以切换到 Evidence rows，以查看逐条 Variant-Gene-Tissue 证据及其 slope 和 p-value。无关联 Gene 的重叠 Variant 仍会保留显示。

<video src="figs/Browse.mp4" poster="figs/Browse.png" controls loop muted playsinline></video>

## 4. Path

Path 搜索两个指定实体之间已存储的连接，并以可核验形式展示每条关系的证据。它适合处理“两个实体如何连接”的问题，而不是单个实体的查询。

![alt text](figs/Path.png)

### 搜索结构

- 搜索框两侧都包含较窄的类别选择器和较宽的实体选择器。
- 若已确定一侧的实体，则另一侧下拉框里的候选结果必定会与所选实体有关联。
- 选择 MAX HOPS 以扩大多跳邻居实体的搜索范围

### 阅读路径结果

结果一共分为两种模式：Table与Graph

- Table
  - 记录每一条路径的详细信息，包含实体名、类别与实体间关系
  - 点击关系以展开/收回支撑的文献表格
  - 表格里包含文献的PMID, Year, Journal, IF, JCR, Match, Title
- Graph
  - 在一张Graph中记录所有路径
  - 可以点击节点和边以了解详细信息，如边的文献表格
  - 可以点击 "Show relations" 来显示边名，点击 "Export" 可以导出图像信息

<video src="figs/Path.mp4" poster="figs/Path.png" controls loop muted playsinline></video>

## 5. Graph 工作区

Graph 提供三种互补的可视化工作流：基于文献的知识关系、Tree 或 Graph 形式的 TE 分类，以及整合共表达和 eQTL 证据的 TE-Gene Graph。

### 分类 Tree 与 Graph

- 显示 TE 分类时，Tree 提供稳定的层级视图；Graph 提供可交互重排的力导向视图。
- All 模式下收纳了RMSK和Repbase未收纳的，以及一些不规范的TE名称。

### 图谱操作

- 在搜索框中选择实体类别并输入实体名称，以快速跳转到特定实体图谱
- 点击 Show relations 可以显示边名，点击 Back to entity 可以返回上一张Graph，点击Export可以导出Graph
- 若搜索实体为 TE，则可通过 Knowledge Graph / TE-Gene Graph 切换按钮查看该 TE 的不同信息。
- 可以点击图例以在Graph中暂时强调该内容，也可筛选图例类别等并Apply以聚焦到特定实体或关系上

### Knowledge Graph 工作区

- 点击节点可以弹出信息卡，了解节点的类别、连接数与简介，并有以下功能
  - Jump: 跳转到以该节点为中心的知识图谱
  - Expand: 以该节点为中心，扩展现有的知识图谱
  - Detail：显示详细信息或TE分类信息
- 搜索以及扩展的节点会有波纹提示
- 可以点击 "Show relations" 来显示边名，点击 "Export" 可以导出图像信息
- 实体图例用于区分 TE、疾病等节点类型，关系图例显示active、affect等谓词类别，并支持筛选特定图例

![alt text](figs/Graph.png)

### TE-Gene Graph 工作区

- 图例中的边分为 Co-expression、eQTL 和 Both。Both 表示同一 TE-Gene 对同时具有共表达和 eQTL 两类证据。
- 共表达部分保留原有特定 Context 的网络结构，使用图例显示或隐藏 TE 或 Gene 节点、识别 module hub，并选择当前显示的边范围。
- 开启 Expression activity 后，具有表达强度意义的共表达节点根据表达强度展示不同的波纹；仅由 eQTL 引入的非中心 Gene 不显示该波纹。
- 可以选择 All tissues 汇总 eQTL 证据，也可以在 tissue 下拉框中查看单个 GTEx tissue 的证据。
- 构建共表达网络时用的筛选指标为：Spearman r >= 0.4，FDR <= 0.05，而 eQTL 当前不额外设置 p-value 阈值。

## 6. Agent 与 DeepThink

Agent 是自然语言研究界面。Agent 模式通过结构化多阶段流程收集证据；DeepThink 使用更短的、受证据约束的推理流程回答较直接的问题。

### 适用模式

- Agent: 综合序列、基因组位置、Variant/eQTL、表达、疾病关系和文献等多个数据库区域，由多个大模型完成，需经历六阶段
  - Understanding
  - Planning
  - Collecting
  - Executing
  - Integrating
  - Writing
- DeepThink: 问题较直接、较短的推理与写作流程已经足够时，由单个大模型完成，需经历四阶段
  - Understanding
  - Planning
  - Executing
  - Writing

### 提问

- 尽量使用清晰的 TE、疾病、基因名称或 PMID；缩写或实体名称有歧义时应先要求澄清。
- 可以询问 TE 分类、序列、基因组记录、Variant 重叠、GTEx eQTL、表达、共表达、图关系、疾病、基因或文献证据。
- 文献证据重要时，点击答案中的 PMID 链接查看对应 PubMed 记录。
- 数据库缺少相应证据时，回答可能合理地保持有限；检索结果中缺失不能证明生物学上不存在。

## 7. Expression

Expression 是 TE 丰度查询界面，支持目录级筛选，以及 normal tissue、normal cell line 和 cancer cell line 数据集中的 TE 详情视图。

### 查找 TE

- 使用 Keyword 搜索 TE，也可以组合 dataset source、top-context 文字和 minimum global median 筛选表格。可以使用 Sort 按现有汇总指标排序，再点击 TE 行进入详情页。
- 在对应数据存在时，浏览表会汇总最高的 normal tissue、normal cell line 和 cancer cell line 环境以及变异系数
- 可以在详情页面的Display Controls选择不同的Chart Type, Metric和Order

## 8. Contact

如有建议或问题，请通过以下方式联系我们：

| Jiang XianDi | Chen Ming |
| --- | --- |
| 3240105782@zju.edu.cn | mchen@zju.edu.cn<br>+86 (0) 571-88206612 |
