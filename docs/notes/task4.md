学术智能体页面与别名驱动检索升级计划
Summary
这一轮保留前面的页面与交互目标，但把“重复问题优化”的核心改成你真正要的方向：

不是会话记忆优先
而是实体别名 / 查重识别 / 规范名回退优先
重点要解决的是：

用户问 LINE-1，本地图谱或插件如果查不到，智能体能自动尝试 LINE1、L1、其他规范别名
不再因为一个写法没命中，就立刻得出“0 条关系”并转向别的插件
Graph Plugin 的结果要能在右侧详情里通过“知识图谱”按钮打开一个可移动、可缩放的小窗图谱
页面继续收排版：输入框适度扩展、引用统一用 PMID、右侧详情独立滚动
Key Changes
1. 实体识别升级成“规范名 + 候选别名链”
EntityNormalizer 不再只输出单个 label，而是输出每个实体的：
canonical_label
display_label
aliases[]
normalized_tokens[]
别名来源统一合并三层：
当前 EntityNormalizer 里已有的硬编码高频别名
api/graph.php 里已经存在的 TE canonicalization / alias 规则
仓库中已有的标准化资产与树/数据库名字表
对 TE 类至少固定支持：
LINE-1 / LINE1 / L1
L1HS
SVA
Alu
对 disease 也沿用现有 alias 规则，但本轮重点先把 TE alias 链做稳
2. 所有插件改成“别名重试”而不是单次精确命中
Graph Plugin、Literature Plugin、Tree Plugin、Expression Plugin、Genome Plugin 都统一接收：
主实体 canonical label
候选 alias 列表
插件查询顺序固定为：
先查 canonical / preferred label
若结果为空或过弱，再按 alias 列表顺序补查
命中后把结果回映射回用户看到的主实体
这一步的目标是避免：
LINE-1 没命中就直接报 0
明明 LINE1 或 L1 能命中，却没有自动重试
Graph Plugin 的返回里要补一层：
matched_alias
matched_entity_label
retry_count
思考过程里不再写成：
查询到了 0 条关系
而是写成更自然的过程，例如：
我先尝试用 LINE-1 查询本地图谱，直接命中不足，接着改用同义别名 LINE1 / L1 补查。
如果最终命中，再写：
使用别名 LINE1 补查后，拿到了 12 条结构化关系。
3. Graph Plugin 详情区加入“知识图谱”按钮
Graph Plugin 不再只返回关系列表文本，还要返回：
graph_elements
nodes[]
edges[]
这份图谱数据固定是：
仅当前命中子图
不补一阶邻居
不拉完整图
右侧详情区中，Graph Plugin 的 Evidence 区顶部加入：
知识图谱 按钮
点击后打开一个可移动小窗：
浮在 agent 页面之上
支持拖动
支持缩放和平移
可关闭
图谱渲染优先复用现有 TE-KG 的 G6 渲染链，不新造渲染器
如果本轮 Graph Plugin 无命中：
不显示这个按钮
思考过程用自然语言解释“本地图谱当前没有形成足够直接的关系链，因此继续改用别名/文献补足”
4. 正文引用统一成 PMID 样式
正文里的 [^n] 不再作为最终展示。
前端把正文引用统一替换成：
PMID 38092519
或紧凑版 38092519
规则固定为：
只有带 pmid 的 citation 才进入正文引用
无 PMID citation 只在右侧详情区显示
hover 引用时：
必须显示完整标题
不能再出现 Open Citation
点击引用时：
必须跳到真实 PubMed 页面
不允许再跳到 agent.php#
后端需要确保 citation 尽量完整：
Graph Plugin 若只有 pmid，没有 title，就优先从本地图谱 Paper 节点或已有 evidence 中补标题
Literature Plugin 继续保证 title + pmid + url
5. 输入框改成“有限扩展”
输入框不是完全固定高度，也不是无限长大。
改成：
初始较矮
随输入内容向上扩展到一个明确上限
超过上限后内部滚动
这样既满足长问题编辑，又不会把底部布局撑坏。
用户消息气泡和正文继续统一字号、行高。
6. 思考过程继续往 DeepSeek 风格收，但突出“别名补查”
深度思考过程不再是调试日志式：
0 条关系 / 5 篇摘要
改成自然叙事：
我需要哪类知识
先查哪种来源
如果主名称没命中，如何改用别名补查
最终找到了什么
对像 LINE-1 如何导致癌症 这样的复杂问题：
编排器先识别需要多种关系类型
优先调用：
Graph Plugin（按 Function / Gene / Mutation / Protein / RNA / Disease 分组）
Literature Plugin（补机制文献）
只有确有必要时再调 Tree / Expression / Genome
最终回答继续维持自然写法，不强制 Conclusion / Evidence Summary / References / Limits
Test Plan
别名与查重
提问：
LINE-1 related diseases
LINE1 related diseases
L1 related diseases
要求：
三种写法都能落到同一实体主题
至少 Graph / Literature 的命中趋势一致
思考过程能体现“必要时做了别名补查”
Graph Plugin 小窗图谱
提一个能命中结构化关系的问题
打开 Graph Plugin 详情，点击 知识图谱
要求：
出现可移动小窗
图可缩放/平移
只显示当前命中子图
不拉长主页面
PMID 引用
回答正文中的引用应显示为 PMID 风格
hover 时显示完整标题
点击时跳到 PubMed
不再出现 Open Citation
不再跳到 agent.php#
输入框
输入短问题时保持较矮
输入很长问题时适度增高
超过上限后在框内滚动，不继续撑高
复杂机制题
提问：
LINE-1 是如何导致癌症的...
要求：
思考过程能体现需要哪些知识维度
若 LINE-1 直接命中弱，会自动补查 LINE1 / L1
最终回答能形成一条机制链，而不是只给固定模板总结
Assumptions
本轮不做“跨会话记忆”，重点是别名重试链，不是长期主题记忆。
Graph Plugin 小窗优先复用现有 G6 渲染链。
正文引用只接受有 PMID 的文献；无 PMID 的文献不进入正文引用。