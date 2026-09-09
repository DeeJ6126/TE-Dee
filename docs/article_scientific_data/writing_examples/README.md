# Scientific Data 论文写法导读

查询日期：2026-09-03。只收集已发表正文，不含补充材料，不是 TE-KG 初稿。

## 先读哪几篇

| 论文 | 年份 | 引用量 | 阅读目的 | 正文 |
| --- | --- | ---: | --- | --- |
| [PrimeKG: Building a knowledge graph to enable precision medicine](https://www.nature.com/articles/s41597-023-01960-3) | 2023 | 585 | 首选：背景怎样收束到数据需求；多来源 Methods 怎样保持一致 | [PDF](papers/primekg/PDFs/Building_a_knowledge_graph_to_enable_precision_medicine.pdf) |
| [Building a PubMed knowledge graph](https://www.nature.com/articles/s41597-020-0543-2) | 2020 | 211 | 实体识别、归一化、消歧及其验证怎样分开写 | [PDF](papers/pkg/PDFs/Building_a_PubMed_knowledge_graph.pdf) |
| [SciSciNet: A large-scale open data lake for the science of science research](https://www.nature.com/articles/s41597-023-02198-9) | 2023 | 158 | 数据整理决策、表间联系、验证和限制怎样交代 | [PDF](papers/sciscinet/PDFs/SciSciNet_A_large-scale_open_data_lake_for_the_science_of_science_research.pdf) |
| [MatKG: An autonomously generated knowledge graph in Material Science](https://www.nature.com/articles/s41597-024-03039-z) | 2024 | 89 | 补充：抽取质量怎样报告，关系语义怎样限定 | [PDF](papers/matkg/PDFs/MatKG_An_autonomously_generated_knowledge_graph_in_Material_Science.pdf) |
| [The heterogeneous pharmacological medical biochemical network PharMeBINet](https://www.nature.com/articles/s41597-022-01510-3) | 2022 | 26 | 补充：最贴近跨库映射、Gene/Variant 和 Neo4j 的方法叙述 | [PDF](papers/pharmebinet/PDFs/The_heterogeneous_pharmacological_medical_biochemical_network_PharMeBINet.pdf) |

前 3 篇是引用较高的主读；后 2 篇按写作相关性补充，尤其 PharMeBINet **不是高引用代表**。SciSciNet 是数据湖论文，不将它冒充为生物医学知识图谱论文。

引用量统一取出版社文章页的 **Citations**，不是访问量，也不是 Web of Science / ESI 的“高被引论文”认证。此处没有进行全刊穷尽排名。引用总量受发表年限和主题影响，不能据此证明英语最好，更不能推断写法导致接收。其他平台的计数可能不同。

## 怎样读，而不是读什么创新

以下是针对已阅读正文段落的写法分析，不是逐句精读全篇。页码指下载 PDF 从第 1 页起的页序；引号内均为原文短片段，其余是阅读建议。

### 1. PrimeKG：先学整篇的叙述顺序

先读第 1–2 页的摘要和背景，再看第 3 页的数据来源段落、第 7 页的标准化与合并。

注意背景不是从网站功能开始，而是由研究需求转到数据分散，再具体解释整合障碍，最后引出资源。Methods 的来源段落重复采用“来源是什么 → 获取版本/时间 → 处理规则 → 输出”的顺序；这种重复让读者容易核查，不需要刻意换花样。

短措辞：第 3 页的 **“We retrieved”**、**“Processing involved”**、**“After processing”**。这些普通动词把获取、处理和产出分开，比连续使用“构建了强大平台”更明确。不要照搬文中的强宣传性形容词。

### 2. PubMed KG：把“为什么处理”放在“如何处理”前面

重点看第 2–4 页的归一化与作者消歧，第 7 页起的 Technical Validation。

它先说明名字为什么不能直接当唯一身份，再解释对应的处理方法。验证部分另起小节，交代比较基线和评价指标，不把“做了清洗”当成“证明准确”。你可以观察每段是否完整回答：问题是什么、采取了什么操作、用什么检查。

短措辞：摘要中的 **“To address this issue”**；第 7 页的 **“we calculated”**。前者承接一个已经说清的问题，后者引出实际测量，不是无证据地宣布数据可靠。

### 3. SciSciNet：方法不是步骤流水账

重点看第 5 页的去重及重新计数，第 15 页的记录与验证，第 18 页的使用限制。

作者不仅写做了什么，还写为什么合并、未进入主表的记录放在哪里，以及合并后哪些派生量必须重新计算。Data Records 解释表间如何连接；验证段落先确定检查对象，再给比较方式。限制则落到具体来源覆盖和数据快照，而不是泛泛说“未来仍需改进”。

短措辞：第 5 页 **“To avoid duplication”**；第 15 页 **“To test the reliability”**。留意目的短语后面必须接具体操作，不能只接“进行了全面分析”。

### 4. MatKG：学习怎样写清边的含义和限制

只需先看第 6 页 Technical Validation、第 6–7 页 Limitations。

验证写出抽样对象、标注者、抽样构成与类别间差异；限制则指出同一种关系标签下可能存在不同语义，并用例子解释。这里值得学的是“限制究竟限制了哪一种解释”。不要把它采用的共现权重当作普遍适用的真实性证明。

短措辞：第 7 页 **“it does not establish causation”**。这是明确划定证据边界的表达。对 TE-KG，措辞同样需要区分共表达、TE 内变异的 eQTL 关联和因果调控；不能仅换上更强的动词就提高证据等级。

### 5. PharMeBINet：学习整合规则的交代方式

优先看第 11 页的 Gene/Variant 映射与 Data Records、第 11–12 页 Technical Validation。

这部分把不同实体使用什么标识符、匹配不上如何处理、哪些证据被纳入写得很具体。验证再区分来源筛选、映射检查和结构检查。它适合帮助你组织 Gene 映射审计的说明，但其实际映射标准不能直接替代 TE-KG 的标准。

短措辞：第 11 页 **“only include those with experimental evidence”**。值得学的是明确说出纳入条件，而不是笼统称“高质量”。这不是要求 TE-KG 的所有边都改成实验验证边，也不是推荐照搬该文的英语细节。

## 共同值得学的语言习惯

1. **一段只承担一个任务。** 背景解释需求，Methods 解释生成过程，Data Records 解释交付文件，Technical Validation 提供质量证据。不要每段都重新介绍平台价值。
2. **用操作和限定条件代替赞美。** 写清获取、筛选、匹配、保留和检查；准确率、覆盖率等判断必须有对应证据。
3. **时态服从含义。** 已完成的数据处理通常用过去时，当前数据内容和字段定义通常用现在时；不必强行全篇一种时态，也不必全部被动语态。
4. **先写对象，再写动作与条件。** 尤其讲映射时，读者应随时知道主语是原始名称、稳定标识符、TE instance、Gene，还是汇总后的边。
5. **学习段落功能，不做同义词改写。** 读完标出每句的任务，然后关掉原文，依据自己的记录重新组织。原文中的表达失误和过强论断也不必模仿。

## 旧论文不能当现行模板

按 [Scientific Data 当前投稿指南](https://www.nature.com/sdata/submission-guidelines)（2026-09-03 核对）：标题不允许冒号或括号，也不应放数据集品牌名、自造缩写；Usage Notes 不应写完整案例、结论或卖点。Data Records 负责文件和字段，概要统计如确有必要放短小的 Data Overview，而非照搬旧论文的大量分析。

因此，**学这些论文怎样说明问题、交代过程和限定结论；章节边界与标题则服从当前指南。** 数据质量所必需的 Technical Validation 仍应保留，不等于完全不能做验证分析。

今晚建议只读 PrimeKG 的背景与两个来源段落，再读 PubMed KG 的一个归一化段落和一个验证段落。每段只记“这段回答什么问题、证据在哪、用了哪些动作词”。不必一次读完五篇。

## 文件与核验

五篇均从出版社公开 PDF 链接下载，无需校园网。均核对 PDF 文件头、首页 DOI、页数、下载大小与 SHA256；没有改写原 PDF。总计约 13.9 MB，未下载 SI，也未生成论文初稿。

[来源与校验记录](collection_manifest.json)包含文章日期、引用量原始 HTML 标记、查询时间、正文来源和哈希；[选文清单](selection.json)保留选文层级。每篇目录中的 `manifest.json` 是下载工具的原始记录。

本地复核：`python -X utf8 docs/article_scientific_data/writing_examples/verify_collection.py`。只校验现有文件；加 `--capture-metrics` 才重新访问出版社并更新查询快照，更新后须同步本页引用量。
