# Network Explorer 后续任务清单

本文档记录 `TE-KG / preview.php` 在图例与关系筛选基本稳定之后，继续从“展示图”升级为“可控探索工具”还可以做的工作。范围只覆盖非智能问答的 Network / G6 图谱探索能力。

## 当前已完成的基础能力

- Entity / Relation 图例切换。
- Entity 图例只显示当前图中实际存在的节点类型。
- Relation 图例按边的语义关系显示，而不是只显示粗粒度 `BIO_RELATION`。
- 图例勾选后不会立即刷新，必须点击 `Apply`。
- `Min PMID` 证据数量筛选。
- `Show labels` 控制边标签，默认关闭。
- `Show names` 控制节点名称。
- Expand mode 可以在保留中心图谱的同时扩展被点击节点的邻域。
- 加载过程中隐藏图例，避免未完成加载时触发筛选状态错误。
- 移除了前端合成的大 `Disease` 节点，避免历史假节点污染图谱。

## 1. 固定右侧详情面板（√）

### 实现什么

- 点击节点时，在右侧显示固定详情面板，而不是只显示简短文本。
- 点击边时，显示关系类型、语义关系名、PMID 数量、证据摘要和 PubMed 链接。
- 面板应该可以在当前图中连续切换节点/边，不打断图谱布局。

### 为什么

图谱探索时，用户需要边看图边判断“这个节点或关系是否值得继续追”。如果详情只靠临时文本，用户很难比较多个节点或关系。

### 大致怎么做

- 复用当前 `node-details` 区域，但把内容结构化为：
  - 标题
  - 类型
  - 属性摘要
  - 关系证据
  - 跳转入口
- 边详情优先使用已有 `pmids` 和 `evidence` 字段。
- PMID 链接沿用 Path Finder / Deep Think 的 PubMed URL 格式。

## 2. 边证据展开（√）

### 实现什么

- 边被点击后，显示该边所有 PMID。
- 每个 PMID 可点击跳转 PubMed。
- 如果有 evidence text，按条展示。
- 没有 PMID 时明确显示“无 PMID 证据”，而不是空白。

### 为什么

知识图谱的核心不是“有一条边”，而是“这条边凭什么存在”。边证据展开可以让用户直接判断关系可信度。

### 大致怎么做

- 前端先使用 `api/graph.php` 已返回的 `pmids` / `evidence`。
- 如果当前边只返回了 PMID 列表，后续可新增 `api/edge_evidence.php` 返回更完整的 evidence package。
- 先不强制查 PubMed 元数据，避免引入外部网络依赖。

## 3. Pin / Hide 节点（x）

### 实现什么

- 点击节点后可以 `Pin`，使它在布局中保持稳定。
- 点击节点后可以 `Hide`，临时从当前子图隐藏。
- 提供 `Reset hidden` 或 `Reset view` 恢复当前图。

### 为什么

当图中节点变多时，用户需要保留关键节点、清理干扰节点，否则图会很快变成“能看但不能分析”。

### 大致怎么做

- 在前端维护本地状态：
  - `pinnedNodeIds`
  - `hiddenNodeIds`
  - `selectedNodeId`
- Hide 只影响当前视图，不写回数据库。
- Pin 状态只影响 G6 layout，不改变 API 数据。

## 4. 继续扩展当前节点（√）

### 实现什么

- 当前 Expand mode 已经能扩展节点，后续需要让扩展结果更可控：
  - 显示“已扩展”状态
  - 避免重复扩展同一节点
  - 支持撤销某次扩展
  - 支持只扩展某些关系类型

### 为什么

扩展能力一旦打开，图可能快速变大。用户需要知道哪些节点已经扩展过，也需要能撤回一次不满意的扩展。

### 大致怎么做

- 继续使用现有 `expandedNodeKeys`。
- 记录每次扩展新增的 node / edge key。
- 撤销扩展时只移除本次新增且没有被其他扩展依赖的元素。
- 第一版可以只支持 `Reset expanded`，不做复杂依赖回滚。

## 5. 子图导出（√）

### 实现什么

- 导出当前可见子图为 CSV。
- 导出当前画布为 PNG。
- 后续再考虑 SVG。

### 为什么

科研用户通常需要把探索结果放入笔记、报告或后续分析。CSV 适合数据复用，PNG 适合快速汇报。

### 大致怎么做

- CSV 分为两个文件或一个 zip：
  - `nodes.csv`
  - `edges.csv`
- 节点字段建议包括：
  - `id`
  - `label`
  - `type`
  - `degree`
  - `taxonomy`
- 边字段建议包括：
  - `source`
  - `target`
  - `relation`
  - `relationType`
  - `pmidCount`
  - `pmids`
- PNG 使用 G6 当前画布导出能力。

## 6. 当前视图状态保存与分享（x）

### 实现什么

- 把当前中心节点、图例筛选、Min PMID、Show labels、Show names、Expand mode 等状态编码进 URL。
- 用户复制 URL 后，可以恢复同一个探索视图。

### 为什么

如果图谱探索结果不能分享或恢复，用户每次都要重新筛选和扩展，影响实际使用。

### 大致怎么做

- URL 参数先覆盖轻量状态：
  - `q`
  - `entity_types`
  - `relation_types`
  - `min_pmids`
  - `show_labels`
  - `show_names`
- 扩展节点列表可后续加入，例如 `expanded=L1,SVA`。
- 不建议第一版把完整节点坐标写入 URL，容易过长。

## 7. API 侧支持更细的过滤参数（x）

### 实现什么

- `api/graph.php` 支持可选过滤参数：
  - 节点类型
  - 关系语义名
  - 最小 PMID 数
  - 返回数量限制
  - 扩展节点

### 为什么

当前主要是在前端拿到子图后再过滤。小图可以接受，但图变大后应尽量在 API 层减少返回量。

### 大致怎么做

- 保持旧参数兼容。
- 新增参数只作为可选项。
- 先让前端继续能本地过滤，再逐步把重过滤迁移到 API。
- 所有过滤参数都应有上限，避免 Neo4j 查询失控。

## 8. 图谱布局模式（x）

### 实现什么

- 提供少量明确布局模式：
  - Force
  - Radial
  - Taxonomy-aware
  - Evidence-weighted

### 为什么

不同分析任务适合不同布局。关系探索适合 Force，中心节点邻域适合 Radial，TE taxonomy 或疾病分类适合层级布局。

### 大致怎么做

- 第一版只增加 `Force / Radial` 切换。
- 保持默认布局不变，避免影响现有使用。
- 布局切换只改变前端布局参数，不重新请求 API。

## 9. 关系强度与可信度视觉编码(√)

### 实现什么

- 边粗细或透明度根据 evidence count / PMID count 映射。
- 高证据边更突出，低证据边更弱。
- 分类边不参与 PMID 强度比较，避免 taxonomy 边被误判为低证据。

### 为什么

同样一条边，如果一个关系有 20 篇文献支持，另一个只有 1 篇，用户应该能直观看出差异。

### 大致怎么做

- 先使用当前 `pmids.length`。
- 映射规则保持简单：
  - `0`: 细线
  - `1-2`: 默认线
  - `3-5`: 加粗
  - `>5`: 更粗或更深
- 与 Relation 图例颜色共存，避免只靠颜色表达强度。

## 10. 推荐实施顺序

1. 右侧详情面板结构化。
2. 边证据展开和 PMID 链接。
3. Pin / Hide 节点。
4. 导出当前子图 CSV。
5. URL 保存当前视图状态。
6. API 增加可选过滤参数。
7. PNG 导出。
8. 布局模式切换。
9. 关系强度视觉编码。
10. 更复杂的扩展撤销与批量分析。

## 验收标准

- 用户可以从一个中心节点出发，筛选、扩展、查看证据、隐藏干扰节点，并导出当前子图。
- 图谱不会因为节点变多而只能“看起来复杂”，而是能被逐步分析。
- 所有新增能力都不依赖智能问答系统。
- 旧入口 `/preview.php` 和现有 G6 runtime 继续可用。
- 新增功能优先复用当前 `preview.php`、G6 bridge、`api/graph.php` 和现有样式，不另建一套图谱系统。

## Current Completion Snapshot - 2026-05-22

- Subgraph export v1 is complete: current visible graph CSV and canvas PNG are available; SVG remains out of scope.
- Evidence support visual encoding is complete: edge width uses `support_pmid_count`, edge opacity uses `support_metric_coverage`, and zero-coverage edges remain visible.
- Edge evidence table is complete: the selected edge card shows PubMed evidence rows, PMID links, and CSV download for larger evidence tables.
- Node action card UX is complete: node click opens a card with explicit `Jump`, `Expand`, and `Details`; global Fixed view / Expand mode controls are hidden.
- Layout mode switching is intentionally not implemented. The runtime continues to use Force layout by default.
- TE mechanism loaders are implemented as mechanism-inspired loading UI only; they are not strict scientific diagrams.
- TE tree load regression is fixed: deep taxonomy edges are restored, category clicks no longer route into ordinary empty graph queries, and initial tree positioning is corrected.
- Recommended next work should be split into separate active plans: category-centered graph contract, TE tree search/focus, expanded-node collapse affordance, and large-graph evidence payload performance only if checks show a problem.
