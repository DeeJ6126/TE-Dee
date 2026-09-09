# 整合人类转座元件知识与表达信息的数据资源

[AUTHOR_INPUT: 作者姓名、单位及通讯作者]

## 摘要（Abstract）

人类转座元件研究涉及文献、重复序列注释、表达谱和遗传关联数据，这些数据使用不同的标识符和观测单位。我们构建了 TE-KG，通过转座元件身份及可追溯的来源记录连接这些数据。该资源整合文献提取的实体关系、分类与代表性序列、参考基因组上的分布、表达谱、特定环境下的共表达网络，以及 GTEx v11 表达数量性状位点关联。文献部分在已记录的快照中包含 2,308 篇论文。eQTL 分析将 50 个 GTEx 组织类别的显著变异–基因关联与 596,140 个参考转座元件实例进行区间相交，获得 10,676,462 条实例级证据记录。基因标识符映射连接共表达与 eQTL 两层，同时保留各自的统计量和组织注释。网站支持实体检索、图谱与基因组浏览、表达查询及关联证据的自然语言问答。TE-KG 为研究人类转座元件和选择下游分析记录提供相互补充的证据。

## 背景与概述（Background & Summary）

转座元件（transposable elements，TEs）提供了参与人类基因组组织与调控的序列。研究者已从转录、细胞过程和疾病等方面考察其活动及基因组分布 [1]。RNA 测序为这些研究增加了表达维度，而 TE 序列的重复性使特征身份和定量层级成为重要考虑因素 [2]。因此，研究某一 TE 时，往往需要联系一个具名重复序列、它在基因组中的分布、特定生物学环境中的测量，以及报道其与其他实体关系的文献。整合这些观测既需要原始记录，也需要能够连接记录的标识符。

现有资源分别支持这一过程的不同环节。Repbase 提供代表性重复序列及其注释 [3]，Dfam 利用序列模型组织重复序列家族 [4]。HervD Atlas 等文献资源汇集人类内源性逆转录病毒与疾病之间的关联 [5]。这些资源为不同的科学问题提供参考信息。将此类信息与表达及变异关联结合，需要明确区分 TE 名称、参考基因组实例和测量特征。人类重复序列分类还包含进化与命名细节，在跨来源组织记录时需要保留这些信息 [6]。

整合不仅是名称匹配。文献关系连接具名的生物学实体，由具体论文支持；重复序列注释描述基因组区间；表达矩阵则将特征标签与样本测量相联系。eQTL 记录连接遗传变异与指定组织中的基因表达。这些记录具有不同的键和证据属性。整合资源应当允许用户在不同记录之间切换，同时保留使其能够被解释的论文、区间、样本环境和关联统计量。数据结构也应支持从单条记录到 TE 中心汇总结果的不同检索层级。

TE-KG 面向人类 TE 整合上述数据层。它将文献知识图谱与 TE 分类、代表性序列、基因组注释、三类表达环境、共表达网络及 TE 重叠的 GTEx eQTL 证据结合起来。TE 身份提供跨资源入口，各层记录则保留其自身的单位。文献关系携带论文标识符，表达与共表达记录保留数据集环境，eQTL 记录保留变异、基因和组织标识符。该资源面向需要查找支持文献和基因组背景的 TE 研究者，也面向检索特定表达或关联数据子集的计算研究者。网站通过搜索、网络探索、基因组浏览和自然语言证据检索，提供协调的数据访问方式。

## 方法（Methods）

### 数据来源与整合设计

我们从四类互补数据构建 TE-KG：生物医学文献、TE 参考注释、表达矩阵和 GTEx eQTL 关联文件。文献记录用于构建实体与关系数据；重复序列注释提供 TE 名称、分类、代表性序列和基因组分布。表达矩阵在各生物学环境内独立处理，生成表达汇总和共表达网络。GTEx 显著关联记录与已接受的 TE 基因组实例进行区间相交，再整理为组织内及跨组织数据表。

整合过程在各来源所表示的层级保留标识符。TE 目录名称将分类和序列记录与相关表达特征、基因组实例连接起来。每个基因组实例具有独立的坐标和链方向。共表达网络中的基因符号通过专门的映射步骤连接至 GTEx 基因标识符。文献谓词、相关系数和 eQTL 关联统计量仍作为对应记录的属性保留。表 1 列出数据来源及各来源保留的信息。

### 文献检索与关系整理

我们在 PubMed 检索截至 2026 年 4 月 13 日的人类 TE 相关文献。记录的检索式使用 OR 连接 MeSH 词 `DNA Transposable Elements` 及自由词 `retrotransposon`、`transposon`、`retrotransposons`、`transposons`、`Retrotransposition` 和 `transposition`。该词组再通过 AND 与人类限定词组组合，后者包括 MeSH 词 `humans`，以及 `human` 或 `homo sapiens`。首先依据人类 RepeatMasker 注释中的重复序列名称，以及 Repbase 记录中的标识符和关键词字段建立白名单，对题名和摘要进行初筛。

初筛后的题名和摘要由 DeepSeek-V3 处理，以受约束的 JSON 格式提取实体及关系。提取提示词指定物种、实体类别和允许使用的关系谓词，并要求每篇文章包含 PubMed 标识符（PMID）。实体描述要求来自所提供的摘要。除 TE 外，实体模式还包括疾病、生物学功能、突变、基因、RNA、蛋白质、糖类、脂质、肽、药物和毒素。关系词表包括分子相互作用、表达与编码关系、关联，以及文献报道的调控或疾病相关关系。论文记录作为证据来源单独保留。

第二轮白名单筛选剔除不包含人类 TE 实体的提取结果。对同类实体名称采用 Ratcliff/Obershelp 字符串相似度进行分组，阈值为 80%，随后人工整理同义词到规范名称的映射。映射用于统一图谱端点名称，同时保留与来源论文的联系。有向生物学关系保存其谓词及 PMID 来源信息。最终保留的文献集包含 2,308 篇论文。[AUTHOR_INPUT: 确认实际使用的提取模型版本、请求参数及已归档的人工整理决策文件。]

### TE 身份、分类与基因组注释

Repbase 衍生记录提供 TE 名称、别名、描述、分类和代表性序列；RepeatMasker 记录提供基因组重复序列注释及补充分类信息。有 Repbase 分类时优先采用，RepeatMasker 注释作为辅助分类参考。参考来源支持的 TE 分类与文献图谱中更广泛的 TE 名称集合分别组织。原始标签保留，使来源记录与整理后的名称能够通过明确映射连接。

序列记录描述 TE 条目对应的代表性或共识序列。基因组记录则通过染色体、起点、终点和链方向标识各个注释实例。这样，一个目录条目可以连接多个基因组实例，同时保留一个或多个来源序列记录。疾病记录采用 ICD-11 衍生的层级组织，对于所选分类分支未能充分表示的术语，保留项目中的专门分组。[AUTHOR_INPUT: 确认这些注释所用的 Repbase 导出版本、RepeatMasker 来源快照及 ICD-11 版本。]

eQTL 整合采用 `hg38.rmsk.repeats.bed` 中的 hg38 重复序列区间，以及 `te_repbase_db_matched.json` 中已接受的 Browse 名称映射。目录名称与参考基因组 TE 区间匹配，保留来源重复序列名称、class、family、染色体、坐标和链方向。没有对应已接受区间的目录条目记录在映射报告中。由此得到的区间集构成 eQTL 分析的基因组检索范围。

### 表达数据与特征注释

表达数据按正常组织、正常细胞和癌细胞系三类环境组织。正常组织部分来自 E-MTAB-1733 和 E-MTAB-2836，对应组织转录组研究 [7,8]；正常细胞部分来自 SRP013565；癌细胞系部分来自 PRJNA523380，对应 Cancer Cell Line Encyclopedia [9]。三个矩阵分别包含 205、307 和 646 个样本列，共享 37,868 个特征行。输入值以标准化计数提供。[AUTHOR_INPUT: 补充上游读段处理、重复序列定量和标准化流程，并确认 SRP013565 子集的实验级组成。]

样本标识符连接矩阵与环境元数据。在表达汇总中，相同样本标识符和环境的重复元数据记录被合并。已有正常组织处理记录显示，200 个矩阵列能够关联元数据并用于环境汇总，其余五列保留在输入矩阵中。正常细胞和癌细胞系汇总分别使用 307 和 646 个关联样本。环境表达汇总包括均值、中位数和最大值，数据集汇总支持比较各注释环境中的特征丰度。共表达计算采用其自身运行清单中指定的矩阵列。

矩阵特征依据项目 TE 名称参考和 HUGO Gene Nomenclature Committee（HGNC）基因集分类 [10]。精确匹配已接受项目 TE 名称或已分类 TE 参考的特征，被赋予高可信 TE 状态；精确匹配 HGNC 已批准符号的特征，被赋予高可信基因状态。无歧义的历史符号和别名匹配以较低可信度保留，未解析的名称另行记录。当一个名称同时精确匹配 TE 参考和基因名称时，保留 TE 分类并记录冲突。特征原始标签与注释同时保存。共表达输入注释识别出 290 个高可信 TE 特征和 23,148 个高可信基因特征。

### 特定环境下的共表达网络

三个表达环境分别构建共表达网络。保留全部高可信 TE 特征；基因在对应环境中至少 10% 的样本里标准化计数大于 1 时，具备后续筛选资格。对符合条件的基因进行 `log2(normalized_count + 1)` 转换后，按中位数绝对偏差排序，依次用方差和特征名称处理并列情况。每个环境选择前 2,000 个基因。特征筛选表保存表达检出比例、变异指标、是否被选择及其原因。

在全部入选特征间计算 Spearman 相关，包括 TE–TE、TE–基因和基因–基因配对。在各特征内部对数值排序，并列值采用平均秩；相关系数由标准化秩向量计算。双侧 p 值使用基于相关系数的 t 近似计算，自由度为样本数减 2。恒定特征产生未定义的相关系数，其配对不进入导出的边集。分析前检查输入矩阵中的重复特征标签、缺失值、非数值及负值。

在各环境相关矩阵的上三角内，对排除自身配对后的有限 p 值采用 Benjamini–Hochberg 方法进行多重检验校正 [11]。2,290 个入选特征在每个环境中形成 2,620,905 个候选无序配对。以 Spearman 相关绝对值至少 0.4、校正后 p 值至多 0.05 筛选网络，并保留相关系数符号。随后在正相关子图中采用 Louvain 算法识别社区 [12]，边权重为相关系数，resolution 为 1.8，随机种子为 42。记录的实现使用 NetworkX 3.4.2。模块归属和 TE 中心显示子图与筛选后的网络边表分别保存。

### GTEx eQTL 输入与 TE 实例重叠

GTEx 项目刻画人类不同组织中的基因表达遗传关联 [13]。我们使用 GTEx 下载集合提供的成人 GTEx v11 单组织 cis-eQTL 归档 `GTEx_Analysis_v11_eQTL.tar` [14]。该版本采用 GENCODE 47 注释。每个归档组织类别的显著变异–基因关联 Parquet 文件与其配套 eGenes 注释文件配对处理，共处理全部 50 个配对组织类别。分析使用来源已筛选的显著关联，不额外施加 TE-KG p 值阈值。

GTEx 变异标识符被解析为 b38 组装上的染色体、以 1 为起点的位置、参考等位基因和替代等位基因。染色体标签统一为 hg38 TE 区间使用的形式。每个变异以 0 为起点的半开参考等位基因区间表示。对于位置 p 和参考等位基因 REF，起点为 p - 1，终点为 p - 1 + length(REF)。因此，单核苷酸变异占据一个参考碱基，多碱基参考等位基因则使用完整参考跨度。

当变异与 TE 实例位于同一染色体且区间相交时，保留该配对：变异起点须小于 TE 终点，变异终点须大于 TE 起点。仅在边界接触的区间被排除。如果多碱基参考等位基因与 TE 共享参考碱基，即使跨越 TE 边界，该规则也允许保留。不增加侧翼窗口。重叠计算使用已索引的 TE 区间集，匹配变异再与各组织的基因关联连接。

所得证据记录以组织、TE 实例、变异和完整基因标识符为键，在此层级去重。同一键出现冲突的关联数值或不一致基因注释时，作为完整性错误处理。基因名称、biotype、基因组坐标和链方向取自对应 eGenes 文件。保留原始含版本号的基因标识符，另去除末尾数字版本后缀，得到用于跨层匹配的标识符。来源 nominal p 值、slope、slope 标准误及附带关联字段与原记录一起保留。

### TE–基因证据的整理与整合

各组织输出整理为独立的组织、TE 实例、变异和基因表，以及实例–变异重叠表和变异–基因–组织关联表。这种组织方式将基因组重叠与重复使用该重叠的关联记录分开，也保留一个变异与多个 TE 注释实例相交的情况。组织内 TE–基因汇总按 TE 名称、完整基因标识符和组织分组；跨组织汇总按 TE 名称和完整基因标识符分组，并记录贡献组织数量。

各汇总记录报告不同支持变异和实例的数量、参与汇总的实例级证据记录数，以及这些记录中的最小 nominal p 值和最大 slope 绝对值。这些字段提供访问底层关联表的入口，用户仍可检查具体变异及组织注释。处理与导入产物使用版本 `gtex_v11_strict_te_overlap_v1`；清单记录输入身份、输出分区、字段顺序、行数和 SHA256 校验和。

为评估 eQTL 与共表达证据的连接，我们将共表达基因符号与 GTEx 基因维度表中的名称进行映射审计。该审计的接受条件为高可信基因注释、名称精确匹配，并且只对应一个不含版本后缀的基因标识符；歧义、较低可信度和未匹配结果分别计数。在整合视图中，`Both` 表示一个 TE–基因对在选定表达环境中具有保留的共表达证据，同时在选定 GTEx 组织范围中具有 TE 重叠 eQTL 证据。共表达环境和 GTEx 组织分别记录。仅有单层支持的配对保留 `Co-expression` 或 `eQTL` 标签。

### 数据服务与访问

文献图谱和 TE 分类存储于 Neo4j，目录、表达、共表达和 eQTL 表由 MySQL 提供服务。PHP 应用及浏览器 JavaScript 视图通过共享 API 访问数据。图查询检索生物学实体、关系及论文证据；表格查询检索表达汇总和变异关联。基因组坐标将 TE 实例记录连接至嵌入的 JBrowse 2 视图 [15]。数据库分工对应数据结构：文献关系采用图遍历，表达和关联记录采用有索引的表格查询。

TE-Gene Graph 在已有特定环境共表达网络上增加 eQTL 支持的基因节点及证据标签，保留原共表达邻域和模块信息。选择一个或全部 GTEx 组织可改变 eQTL 证据范围。Browse 提供变异汇总视图及更细致的变异–基因–组织视图。Agent 和 DeepThink 的自然语言访问通过资源检索服务收集相关记录，并返回关联证据的回答。这些服务是同一数据层的不同入口，而非独立的科学数据集。

### 稿件准备

OpenAI Codex 辅助组织已有项目材料、核对参考文献元数据、起草和编辑英文稿件，并准备中文译稿。[AUTHOR_INPUT: 完成稿件的作者核验，并在投稿前确定最终 AI 使用披露。]

### 伦理声明

[AUTHOR_INPUT: 提供适用于公开文献、注释、表达数据及 GTEx 汇总关联结果二次使用的声明。]

**表 1. TE-KG 的数据来源及用途。**

| 组成 | 来源或登录号 | 保留信息及用途 |
| --- | --- | --- |
| 文献 | 截至 2026 年 4 月 13 日的 PubMed 检索 | 论文标识符、实体描述和有向关系 |
| TE 参考 | Repbase 和 RepeatMasker 记录 | 名称、别名、分类、代表性序列和基因组分布 |
| 疾病组织 | ICD-11 衍生层级 | 疾病实体分类 |
| 正常组织表达 | E-MTAB-1733；E-MTAB-2836 | 特征×样本标准化计数矩阵及样本元数据 |
| 正常细胞表达 | SRP013565 | 特征×样本标准化计数矩阵及样本元数据 |
| 癌细胞系表达 | PRJNA523380 | 特征×样本标准化计数矩阵及样本元数据 |
| 基因命名 | HGNC 完整基因集 | 已批准基因符号及备选名称 |
| eQTL 关联 | GTEx v11 显著 cis-eQTL 配对和 eGenes 注释 | 变异–基因–组织记录、统计量和基因注释 |
| 重叠参考 | hg38 RepeatMasker BED 及已接受的 Browse 映射 | 用于连接关联记录的参考基因组 TE 实例区间 |

## 数据记录（Data Records）

TE-KG 分为文献、TE 参考、表达、共表达和 eQTL 数据层。文献记录包含论文元数据、带类别的实体和有向关系。关系记录通过谓词及支持 PMID 连接来源和目标实体。TE 参考记录将目录名称连接至分类与序列信息，基因组实例记录标识对应区间。这些标识符使 TE 查询能够定位不同类型的证据，同时保留整合时使用的来源记录。

表达矩阵为制表符分隔文件，每行对应一个特征，各列以样本标识符命名。配套元数据将样本归入记录的生物学环境。处理后的环境表和数据集表提供表达汇总，特征注释表记录每个矩阵行的身份和可信度。共表达数据也以制表符分隔的节点、边和筛选表组织。边记录包括端点标识符及类型、环境、相关系数、校正后 p 值和样本数。模块归属描述正相关社区，TE 中心子图作为独立产物用于交互查看。

eQTL 数据的每个组织目录包含 `te_variant_gene_overlaps.parquet`、`te_gene_summary.parquet` 和处理清单。规范化表示包含八张表（表 2），导出为压缩的制表符分隔分区。基因表保留完整含版本号的标识符、符号和坐标；变异表同时保留 GTEx 原始标识符、解析后的等位基因和坐标。通过变异键连接重叠表与关联表，可恢复实例级证据。TE 实例键标识一个注释实例，TE 名称将其连接到目录级汇总。

在同一分析版本内，表键决定记录的组合方式。实例–变异重叠由实例键和变异键共同唯一确定；关联记录由组织、变异和完整基因标识符共同唯一确定。TE–基因汇总保留这些关联使用的完整基因标识符，去除后缀的标识符用于另行审计的共表达映射。这使组织级检索能够保留来源基因注释，而不必将变异记录替换为仅含基因符号的边。清单记录各文件的分区顺序、字段顺序、字节大小和校验和。

[AUTHOR_INPUT: 补入 TE-KG 持久数据集登录号、最终文件清单和逐文件复用条款。此处描述的是已有项目产物，投稿前须确认归档存储。]

**表 2. 规范化 eQTL 记录及其标识字段。**

| 数据表 | 表示的记录 | 键及主要内容 |
| --- | --- | --- |
| `eqtl_tissues` | 一个归档组织类别 | `tissue_key`；显示名称及来源归档成员 |
| `eqtl_te_instances` | 一个参考 TE 实例 | `te_instance_key`；TE 名称、class、family、染色体、区间和链方向 |
| `eqtl_variants` | 一个解析后的 GTEx 变异 | `variant_key`；原始 ID、染色体、REF、ALT 和参考区间 |
| `eqtl_genes` | 一个含版本号的基因注释 | `gene_id`；base ID、名称、biotype、区间和链方向 |
| `eqtl_te_variant_overlaps` | 一个实例–变异交集 | `te_instance_key`、`variant_key` |
| `eqtl_variant_gene_tissue_associations` | 一条来源关联 | `tissue_key`、`variant_key`、`gene_id`；nominal p 值、slope、标准误及来源字段 |
| `eqtl_te_gene_tissue_summary` | 一个组织内的 TE–基因对 | 组织、TE 名称和基因 ID；支持数量及统计量极值 |
| `eqtl_te_gene_cross_tissue_summary` | 一个跨组织 TE–基因对 | TE 名称和基因 ID；组织与支持数量及统计量极值 |

## 数据概览（Data Overview）

2026 年 7 月 31 日记录的文献图谱快照包含 2,308 个 Paper 节点、225 个 TE 节点及 12,444 条有向生物学关系。Browse 目录包含 276 个条目，其中 202 个名称匹配 eQTL 整合使用的 596,140 个已接受 hg38 TE 实例。完成的 eQTL 分析处理了 50 个组织类别的 104,901,807 条来源关联行，生成 10,676,462 条实例级证据记录。规范化整理得到 664,555 个不同变异、664,902 个实例–变异重叠，以及 10,670,298 条变异–基因–组织关联。TE–基因汇总表分别包含 3,320,749 条组织内记录和 540,906 条跨组织记录。这些数量分别描述已记录的图谱快照和独立版本化的 eQTL 产物。

## 技术验证（Technical Validation）

### 标识符与记录一致性

特征注释和基因映射按实际用于整合的标识符进行检查。在记录的共表达到 GTEx 审计中，TE–基因边出现 3,281 个不同基因符号。其中 3,243 个满足高可信、名称精确匹配和唯一 base identifier 条件，38 个没有 GTEx 名称精确匹配。本次审计没有出现歧义或较低可信度的精确匹配。应用可接受映射后，在组织选择及显示筛选之前，共识别出 7,715 个具有两类证据的不同 TE–基因对。映射报告保存类别计数及代表性的符号–标识符匹配。

表达输入验证记录了三个矩阵一致的特征维度、无重复特征标签，以及所有选定高可信 TE 和基因特征的存在。元数据处理另行识别了用于环境汇总的样本记录，从而区分特征矩阵完整性与样本环境归属。文献整理流程保留来源 PMID 和规范实体映射，支持逐条检查。[AUTHOR_INPUT: 提供可恢复的人工审核样本、决策与分母，以定量评价文献筛选和关系提取。]

### 重叠与规范化整理检查

eQTL 处理代码采用匹配结果已知的合成变异和 TE 区间测试。测试覆盖单核苷酸和多碱基参考等位基因解析、染色体标签、仅共享边界而不相交的区间，以及一个变异对应多个 TE。比较预建区间索引查询与使用同一区间数据框输入的查询，检查结果是否一致。其他测试数据覆盖组织文件配对发现、注释连接、重复处理及冲突记录拒绝。规范化测试检查表格导出的确定性，以及实例级证据与规范化汇总的一致性。在项目 eQTL 环境中，所选三个 eQTL 测试模块的全部 10 项测试通过。

我们还检查了保留的正式处理清单及汇总表。各组织计数之和为 104,901,807 条来源行和 10,676,462 条实例级证据行。规范化导出包含八张表的 130 个分区，总计 16,510,562 行。各表计数和生成的组织汇总与保留清单一致。Browse 映射覆盖全部 276 个目录条目，区分 202 个已匹配名称和 74 个没有已接受 hg38 实例的名称。这些检查支持已记录输入、处理输出和表格表示之间的一致性。

### 共表达实现检查

相关性流程包含特征选择、配对构建和多重检验校正测试，配套测试覆盖网络筛选和社区检测。所选三个共表达测试模块的全部七项测试通过。记录的处理清单指定转换方式、基因筛选参数及配对范围；模块清单记录正相关边策略、软件版本、resolution 和随机种子。这些记录与保留的节点和边表一起，使各环境网络的构建能够独立于交互显示进行检查。

## 使用说明（Usage Notes）

TE-KG 可通过 TE 名称、生物学实体或来源论文进入。Browse 汇集参考注释、序列和基因组实例记录；Graph 和 Path 展示存储的文献关系及支持论文。Expression 视图检索所选特征的测量和环境汇总。TE-Gene Graph 将特定环境共表达邻域与可按组织选择的 eQTL 证据结合，并保留各边类型对应统计量的访问方式。

变异汇总视图适合识别选定 TE 条目关联的不同变异，证据行视图进一步将其展开为变异–基因–组织记录。下载 eQTL 表后，可以按变异键连接实例–变异重叠和关联记录，再通过组织键和基因键检索注释。一个关联可能与多个 TE 实例相交，因此所选数据表应对应预期的分析单位，即关联或实例级重叠。复现这些连接时应保留完整基因标识符。

表达环境和 GTEx 组织范围独立选择。因此，针对特定组织的分析可以检索其 eQTL 记录，同时保留所选共表达网络的来源。程序化分析可使用完整筛选网络和组织级数据表，网页则提供较小子集便于检查。Agent 和 DeepThink 支持这些记录的自然语言检索，并链接回答使用的证据。[AUTHOR_INPUT: 提供经过验证的公开服务网址。]

## 数据可用性（Data Availability）

[AUTHOR_INPUT: 补入 Data Records 所述 TE-KG 记录的持久数据集登录号、版本和访问条件。] 来源表达数据登录号为 E-MTAB-1733、E-MTAB-2836、SRP013565 和 PRJNA523380。GTEx v11 关联文件可通过 GTEx 下载集合获取 [14]。

## 代码可用性（Code Availability）

[AUTHOR_INPUT: 补入公开代码仓库、归档版本标识符及软件许可证。] 项目代码包括文献规范化、特征注释、共表达构建、TE–变异重叠、组织数据整理及数据访问服务。分析清单记录对应产物的处理脚本及参数。

## 作者贡献（Author Contributions）

[AUTHOR_INPUT: 经作者确认的贡献说明。]

## 利益冲突（Competing Interests）

[AUTHOR_INPUT: 经作者确认的利益冲突声明。]

## 经费支持（Funding）

[AUTHOR_INPUT: 经费来源和项目编号，或适用的无经费支持声明。]

## 参考文献（References）

1. Chuong, Edward B.; Elde, Nels C.; Feschotte, Cédric. Regulatory activities of transposable elements: from conflicts to benefits. *Nature Reviews Genetics* **18**, 71-86 (2017). https://doi.org/10.1038/nrg.2016.139

2. Lanciano, Sophie; Cristofari, Gael. Measuring and interpreting transposable element expression. *Nature Reviews Genetics* **21**, 721-736 (2020). https://doi.org/10.1038/s41576-020-0251-y

3. Bao, Weidong; Kojima, Kenji K.; Kohany, Oleksiy. Repbase Update, a database of repetitive elements in eukaryotic genomes. *Mobile DNA* **6**, 11 (2015). https://doi.org/10.1186/s13100-015-0041-9

4. Wheeler, Travis J.; et al. Dfam: a database of repetitive DNA based on profile hidden Markov models. *Nucleic Acids Research* **41**, D70-D82 (2013). https://doi.org/10.1093/nar/gks1265

5. Li, Cuidan; et al. HervD Atlas: a curated knowledgebase of associations between human endogenous retroviruses and diseases. *Nucleic Acids Research* **52**, D1315-D1326 (2024). https://doi.org/10.1093/nar/gkad904

6. Kojima, Kenji K. Human transposable elements in Repbase: genomic footprints from fish to humans. *Mobile DNA* **9**, 2 (2018). https://doi.org/10.1186/s13100-017-0107-y

7. Edqvist, Per-Henrik D.; et al. Expression of Human Skin-Specific Genes Defined by Transcriptomics and Antibody-Based Profiling. *Journal of Histochemistry & Cytochemistry* **63**, 129-141 (2015). https://doi.org/10.1369/0022155414562646

8. Uhlén, Mathias; et al. Tissue-based map of the human proteome. *Science* **347**, 1260419 (2015). https://doi.org/10.1126/science.1260419

9. Ghandi, Mahmoud; et al. Next-generation characterization of the Cancer Cell Line Encyclopedia. *Nature* **569**, 503-508 (2019). https://doi.org/10.1038/s41586-019-1186-3

10. Tweedie, Susan; et al. Genenames.org: the HGNC and VGNC resources in 2021. *Nucleic Acids Research* **49**, D939-D946 (2021). https://doi.org/10.1093/nar/gkaa980

11. Benjamini, Yoav; Hochberg, Yosef. Controlling the False Discovery Rate: A Practical and Powerful Approach to Multiple Testing. *Journal of the Royal Statistical Society Series B: Statistical Methodology* **57**, 289-300 (1995). https://doi.org/10.1111/j.2517-6161.1995.tb02031.x

12. Blondel, Vincent D; Guillaume, Jean-Loup; Lambiotte, Renaud; Lefebvre, Etienne. Fast unfolding of communities in large networks. *Journal of Statistical Mechanics: Theory and Experiment* **2008**, P10008 (2008). https://doi.org/10.1088/1742-5468/2008/10/P10008

13. The GTEx Consortium; et al. The GTEx Consortium atlas of genetic regulatory effects across human tissues. *Science* **369**, 1318-1330 (2020). https://doi.org/10.1126/science.aaz1776

14. GTEx Consortium. GTEx Analysis v11 single-tissue cis-eQTL data. https://gtexportal.org/home/downloads/adult-gtex/overview (accessed 3 September 2026).

15. Diesh, Colin; et al. JBrowse 2: a modular genome browser with views of synteny and structural variation. *Genome Biology* **24**, 74 (2023). https://doi.org/10.1186/s13059-023-02914-z
