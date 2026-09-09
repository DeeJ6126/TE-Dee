# G6 Expand Same-Label Entity Disambiguation

状态：completed

日期：2026-05-21

## 背景

Expand mode 目前基本可用，但在同名跨类型实体上仍有歧义。例如 `api/graph.php?q=aging` 当前 anchor 是 `Function: Aging`；`LINE-1` 图中存在 `Disease: Aging`，`L1HS` 图中存在 `Function: Aging`。如果用户在 `preview.php?q=LINE-1` 的 Expand mode 中点击 `Disease: Aging`，当前 label-only expand 可能被后端解析为 `Function: Aging`，从而把错误类型实体的邻居追加到当前子图。

本计划只处理 Expand mode 同名实体消歧。它不继续优化 Expand mode 布局、视觉 affordance、collapse 或多步交互体验。

## 当前证据

### Frontend expand request

- `assets/js/renderers/g6/index-g6.bootstrap.js`
  - `expandSelectedNode(node)` 当前使用：
    - `node?.queryLabel || node?.rawLabel || node?.displayLabel` 构造 `query`
    - `node?.id || query` 构造 `expandedNodeKeys` key
    - `bridge.expandGraph({ query }, { graphDataOptions: ... })` 发起 iframe expand
  - 结论：当前 parent 只把 `{ query }` 传给 iframe bridge，没有传 node id 或 node type。

- `assets/js/renderers/g6/index-g6-shared.js`
  - `buildGraphData()` 为节点构造：
    - `id`
    - `nodeType`
    - `rawLabel`
    - `displayLabel`
    - `queryLabel`
  - `expandGraph(requestLike)` 当前只读取 normalized request 的 `query` / `queryType` / `classQuery`：
    - `endpoint.searchParams.set('q', query)`
    - `endpoint.searchParams.set('key_level', String(currentKeyNodeLevel))`
    - disease class 时额外设置 `type=disease_class` 和 `class=...`
  - 结论：iframe 侧已经有能力从 clicked node 接收更多字段，但当前 endpoint 只发 label query。

### API contract

- `api/graph.php`
  - 当前读取：
    - `q`
    - `type`
    - `class`
    - `key_level`
  - 当前调用：
    - `$service->search($query, $keyLevel, $queryType, $classQuery)`
  - 结论：入口未读取 `expand_node_id` / `expand_node_type` 之类的精确扩展参数。

- `api/graph_service.php`
  - `GraphService::search(string $query, int $keyLevel = 1, string $queryType = '', string $classQuery = '')`
  - 普通搜索路径调用 `findAnchorNode($query, $normalized)`。
  - `findAnchorNode()` 先按 `n.name` exact / `pmid` 查找，再 fuzzy contains，并按 label 优先级排序。
  - 后续 context rows 已经基于 anchor element id 展开：
    - Disease: `buildDiseaseContextRows($anchor)`，内部使用 `elementId(d) = $anchorId`
    - Paper: `buildPaperContextRows($anchor)`
    - Generic: `loadDirectRowsForAnchor($anchor)` / `loadDirectRows($anchorId)`
  - 结论：服务层目前没有公开“按 elementId 精确选择 anchor”的参数，但一旦获得精确 anchor，后续展开路径已经主要围绕 anchor element id 工作。

### Known ambiguous sample

- `api/graph.php?q=aging`
  - anchor: `Function: Aging`
  - matches: 包含 `Function: Aging` 和 `Disease: Aging`
- `api/graph.php?q=LINE-1`
  - elements 中存在 `Disease: Aging`
- `api/graph.php?q=L1HS`
  - elements 中存在 `Function: Aging`

## 目标

- 在 Expand mode 中点击同名跨类型实体时，后端按 clicked node 的精确 identity 展开。
- 点击 `LINE-1` 图中的 `Disease: Aging` 时，不应解析成 `Function: Aging`。
- 保留现有普通 search / browse 行为：直接访问 `api/graph.php?q=aging` 的默认解析规则不作为本计划目标修改。
- 保留现有 Expand mode 架构：parent -> iframe bridge -> `api/graph.php` -> incremental merge/render。

## 推荐 Contract

新增 expand 专用 query params，命名保持明确、低侵入：

- `expand_node_id`
  - Neo4j `elementId(...)`，来自 clicked node 的 `id`。
  - 优先级最高。
- `expand_node_type`
  - clicked node 的 `nodeType` / API element `data.type`，例如 `Disease`、`Function`、`TE`。
  - 用于校验 `expand_node_id` 命中的节点类型，也用于 id 缺失时的 name/type fallback。
- `expand_query`
  - clicked node 的 raw/query label，来自 `queryLabel || rawLabel || displayLabel`。
  - 作为 fallback 和诊断字段。

兼容策略：

- Expand mode 请求同时保留 `q=<expand_query>`，避免破坏现有 API shape、日志和旧检查。
- `api/graph.php` 读取新增参数后传给 service。
- service 优先按 `expand_node_id` 找 anchor；找不到或 type 不匹配时，再按 `expand_query + expand_node_type` 找 anchor；最后才 fallback 到现有 `q` 解析。

建议响应中增加诊断字段，便于 smoke 精确断言：

- `expanded_source`
  - `id`
  - `name`
  - `type`
  - `resolution`
    - `id`
    - `name_type`
    - `query_fallback`

如果为了最小改动暂不新增 `expanded_source`，smoke 至少应断言 `anchor.name` / `anchor.type` 和 returned elements 中 anchor id/type；但建议新增，因为它能把 Expand mode contract 和普通 search contract 分开。

## 不做什么

- 不重构整个 `api/graph.php`。
- 不重构 `GraphService` 的整体搜索架构。
- 不改 taxonomy runtime。
- 不改 expression runtime。
- 不改 `api/agent/`。
- 不改变普通 Search / Browse / 直接 `q=` 访问行为。
- 不继续做 Expand mode 视觉 affordance、collapse、布局美化。
- 不复制 G6 mindmap 架构。

## 涉及文件范围

- Modify: `assets/js/renderers/g6/index-g6.bootstrap.js`
  - 在 `expandSelectedNode(node)` 中构造 expand request payload，携带 node id/type/query。
- Modify: `assets/js/renderers/g6/index-g6-shared.js`
  - 在 `expandGraph(requestLike)` 中把 expand identity 映射为 API query params。
- Modify: `api/graph.php`
  - 读取 `expand_node_id`、`expand_node_type`、`expand_query` 并传给 service。
- Modify: `api/graph_service.php`
  - 增加精确 expand anchor 解析路径。
- Modify or Add: `scripts/checks/check_g6_expand_mode_smoke.py` 或新增 `scripts/checks/check_g6_expand_disambiguation_smoke.py`
  - 覆盖 `LINE-1` 中 `Disease:Aging` 的精确 expand。
- Modify: `scripts/checks/check_api_contracts.py`
  - 增加 API-level exact expand contract，先证明同名实体能被精确解析。

## 实施步骤

### 1. 写 API-level RED check

- 新增或扩展 `scripts/checks/check_api_contracts.py`。
- 请求样例：
  - 先从 `api/graph.php?q=LINE-1` 找到 `data.label/rawLabel == Aging` 且 `data.type == Disease` 的 node id。
  - 再请求：
    - `api/graph.php?q=Aging&expand_query=Aging&expand_node_type=Disease&expand_node_id=<Disease Aging id>&key_level=1`
- 预期 RED：
  - 当前 API 不读取 expand params，返回 anchor 仍可能是 `Function: Aging`。
- 修复后的预期：
  - `anchor.name == Aging`
  - `anchor.type == Disease`
  - 如果新增 `expanded_source`，则 `expanded_source.id == <Disease Aging id>` 且 `expanded_source.resolution == id`

### 2. 写 browser-level RED check

- 新增 `scripts/checks/check_g6_expand_disambiguation_smoke.py`，或把同名实体专项场景加入 `check_g6_expand_mode_smoke.py --query LINE-1 --target-label Aging --target-type Disease`。
- 浏览器步骤：
  - 打开 `preview.php?q=LINE-1`。
  - 等待图谱和 legend 完成。
  - 开启 Expand mode。
  - 在 current graph data 中定位 clicked node：
    - `rawLabel/displayLabel/queryLabel == Aging`
    - `nodeType == Disease`
  - 通过 G6 node position 点击该节点。
  - 捕获点击后的 `api/graph.php` request URL。
- 预期 RED：
  - 当前 request 只含 `q=Aging`，不含 `expand_node_id` / `expand_node_type`。
  - 当前 response anchor 可能是 `Function: Aging`。
- 修复后的预期：
  - request URL 包含 `expand_node_id=<Disease Aging id>`。
  - request URL 包含 `expand_node_type=Disease`。
  - response anchor / `expanded_source` 是 `Disease: Aging`。
  - 不新增 `Function: Aging` 作为本次被扩展中心。
  - parent state query 仍是 `LINE-1`。
  - loader 不长期 visible，canvas 仍存在，无 page error / failed request。

### 3. 前端 parent payload 最小改动

- 在 `assets/js/renderers/g6/index-g6.bootstrap.js` 的 `expandSelectedNode(node)` 中保留现有 query fallback，同时构造：
  - `expandNodeId: String(node?.id || '').trim()`
  - `expandNodeType: String(node?.nodeType || node?.type || '').trim()`
  - `expandQuery: query`
- 调用 iframe bridge 时改为传：
  - `{ query, expandNodeId, expandNodeType, expandQuery }`
- 保持 `expandedNodeKeys` 使用 node id 优先。
- 不改 `ensureDynamicFrame()` / iframe src / full rerender 路径。

### 4. iframe API params 最小改动

- 在 `assets/js/renderers/g6/index-g6-shared.js` 的 `expandGraph(requestLike)` 中：
  - 继续 `endpoint.searchParams.set('q', query)`。
  - 如果 `request.expandNodeId` 非空，设置 `expand_node_id`。
  - 如果 `request.expandNodeType` 非空，设置 `expand_node_type`。
  - 如果 `request.expandQuery` 非空，设置 `expand_query`。
- 不改 incremental `addNodeData()` / `addEdgeData()` / `draw()` / `layout()` 路径。

### 5. API entry 最小改动

- 在 `api/graph.php` 中读取：
  - `$expandNodeId = trim((string)($_GET['expand_node_id'] ?? ''));`
  - `$expandNodeType = trim((string)($_GET['expand_node_type'] ?? ''));`
  - `$expandQuery = trim((string)($_GET['expand_query'] ?? ''));`
- 把这些字段传给 service。推荐保持签名向后兼容：
  - `$service->search($query, $keyLevel, $queryType, $classQuery, ['expand_node_id' => ..., 'expand_node_type' => ..., 'expand_query' => ...])`

### 6. Service exact anchor resolution

- 在 `api/graph_service.php` 中让 `search()` 接受可选 `$options = []`。
- 当不是 `DiseaseClass` 且存在 expand options 时，优先调用新的 resolver：
  - `resolveExpandAnchor($query, $normalized, $options)`
- resolver 优先级：
  1. `expand_node_id`
     - Cypher: `MATCH (n) WHERE elementId(n) = $nodeId RETURN ... LIMIT 1`
     - 如果 `expand_node_type` 非空，校验 `normalizeType(labels(n)) === expand_node_type`。
     - 命中时 matches 至少包含该节点。
  2. `expand_query + expand_node_type`
     - Cypher: `MATCH (n) WHERE toLower(coalesce(n.name, '')) = toLower($name) AND $type IN labels(n) RETURN ... LIMIT 1`
     - 用于 id 不可用或 id 失效时 fallback。
  3. 现有 `findAnchorNode($query, $normalized)`
- 保持普通 `q=` search 不传 expand options 时完全走旧逻辑。

### 7. 响应诊断字段

- 在 expand exact resolution 命中时，在 payload 中加入：
  - `expanded_source.id`
  - `expanded_source.name`
  - `expanded_source.type`
  - `expanded_source.resolution`
- 普通 search 可不返回 `expanded_source`，或返回 `null`。
- 如果新增字段，更新 API contract smoke。

### 8. Verification

必须运行：

```powershell
python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_expand_mode_smoke.py --query LINE-1
python scripts/checks/check_g6_expand_disambiguation_smoke.py
python scripts/checks/check_g6_expand_layout_smoke.py --query LINE-1
python scripts/checks/check_g6_browser_smoke.py
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
php -l api/graph.php
php -l api/graph_service.php
```

预期：

- API exact expand contract 通过，`Disease:Aging` 不再解析到 `Function:Aging`。
- Browser disambiguation smoke 通过，request 带 `expand_node_id` / `expand_node_type`。
- 现有 Expand mode、layout、browser smoke 不回归。
- 普通 `api/graph.php?q=aging` 默认行为不作为本计划变更目标；如果仍 anchor 到 `Function:Aging`，这是允许的。

## 验收标准

- `LINE-1` Expand mode 点击 `Disease:Aging` 时，后端 anchor / expanded source 是 `Disease:Aging`。
- Expand request 携带 clicked node identity，而不是只携带 label query。
- 当前中心图谱仍保留，parent `state.query` 仍是 `LINE-1`。
- 不新增 `Function:Aging` 作为本次被扩展中心。
- loader 不长期卡住，legend 不回到 loading，iframe canvas 不白屏。
- 普通 Search / Browse / taxonomy / agent 行为未被修改。

## 执行记录

- 计划创建日期：2026-05-21。
- 实施日期：2026-05-21。
- RED 证据：
  - `python scripts/checks/check_api_contracts.py` 在旧实现下失败：`api/graph.php?q=Aging&expand_query=Aging&expand_node_type=Disease&expand_node_id=<Disease Aging id>` 仍返回 `Function:Aging` anchor。
  - `python scripts/checks/check_g6_expand_disambiguation_smoke.py` 在旧实现下失败：点击 `LINE-1` 图中的 `Disease:Aging` 时，请求只有 `q=Aging&key_level=1`，没有 `expand_node_id` / `expand_node_type` / `expand_query`。
- 实施内容：
  - `expandSelectedNode(node)` 现在向 iframe bridge 传递 `query`、`expandNodeId`、`expandNodeType`、`expandQuery`。
  - iframe `expandGraph()` 现在把这些字段映射为 `api/graph.php` query params：`expand_node_id`、`expand_node_type`、`expand_query`。
  - `api/graph.php` 读取 expand identity 参数，并通过 `GraphService::search(..., $options)` 传入 service。
  - `GraphService` 新增 exact expand anchor resolution：优先 `elementId`，再 `name + type`，最后 fallback 到旧 `q` 解析。
  - expand exact 命中时响应新增 `expanded_source` 诊断字段。
- GREEN 证据：
  - API same-label contract 通过，`Disease:Aging` expand anchor 保持为 `Disease`。
  - Browser same-label smoke 通过，Expand 请求携带 `expand_node_id=<Disease Aging id>`、`expand_node_type=Disease`、`expand_query=Aging`，响应 `expanded_source.resolution=id`。
  - 普通 `api/graph.php?q=aging` 仍返回 `Function:Aging`，普通 search 行为未改变。
- API 直接证据：
  - `api/graph.php?q=Aging` 返回 `Function:Aging`，且没有 `expanded_source`。
  - `api/graph.php?q=Aging&expand_node_type=Disease&expand_query=Aging` 返回 `Disease:Aging`，`expanded_source.resolution=name_type`。
  - 从 `api/graph.php?q=LINE-1` 提取 `Disease:Aging` id 后请求 `api/graph.php?q=Aging&expand_node_type=Disease&expand_query=Aging&expand_node_id=<id>` 返回 `Disease:Aging`，`expanded_source.resolution=id`。

## 残留风险

- Neo4j `elementId()` 是数据库内部 id，适合当前单库运行时精确定位，但如果未来需要跨数据库持久链接，可能要引入稳定业务 id。
- 当前 graph payload 的 `data.id` 已使用 `elementId()`；本计划沿用现有 runtime contract，不新增跨库稳定 id 设计。
- 同名实体 smoke 先覆盖 `Disease:Aging` vs `Function:Aging`，其他同名跨类型实体仍需后续扩展样本。
