# G6 Subgraph Export

## 背景

Expand mode 阶段已完成基础可靠性收口。下一阶段目标是把用户当前正在看的 TE-KG 子图导出，而不是导出完整数据库。第一版只覆盖当前可见 nodes / edges 的 CSV，以及当前画布 PNG。SVG 暂缓。

关键原则：导出必须尊重当前图上的可见状态，包括 legend node type filter、relation legend filter、min PMID filter，以及当前已经通过 Expand mode 局部追加到图上的内容。

## 目标

- 导出当前可见 nodes 为 CSV。
- 导出当前可见 edges 为 CSV。
- 导出当前 G6 canvas 为 PNG。
- 导出内容只来自当前图上用户看到的子图。
- 导出应覆盖初始图和 Expand mode 后的增量图。
- 不改 agent。
- 不改 graph API，除非前端证据证明无法拿到当前可见子图数据。

## 不做什么

- 不导出完整 Neo4j / MySQL 数据库。
- 不实现 SVG 导出。
- 不新增后端导出 endpoint，除非证据证明前端无法完成。
- 不改 `api/graph.php` / `api/graph_service.php` 的 graph query contract。
- 不改 `api/agent/`。
- 不改 taxonomy runtime truth source。
- 不继续做 Expand mode affordance / collapse / layout 美化。
- 不改变 Search / Browse / Expression 行为。

## 当前 runtime 证据

### Parent state

- `assets/js/renderers/g6/index-g6.bootstrap.js`
  - `currentQueryGraphElements` 保存当前 query 图的 raw elements。
  - `currentAnswerGraphElements` 保存 QA/answer graph elements。
  - `getCurrentGraphElements()` 根据 `currentGraphSource` 返回当前 raw elements。
  - `filterElementsForLegend(elements)` 会按：
    - `getVisibleTypePayload()`
    - `getVisibleRelationPayload()`
    - `relationMinPmids`
    - 节点是否仍可见
    过滤出当前应显示的 nodes / edges。
  - `buildCurrentGraphDataOptions()` 把 `visibleTypes`、`visibleRelations`、`minRelationPmids` 传给 iframe render。
  - `snapshotState()` 暴露 `currentElements`，但目前是 raw current elements，不一定是 filtered visible elements。
  - `window.__TEKG_G6_BRIDGE.getState()` 可供 checks 读取 parent state。

### Iframe state

- `assets/js/renderers/g6/index-g6-shared.js`
  - `buildGraphData(elements, options)` 负责把 raw elements 转成 G6 data。
  - `visibleTypes`、`visibleRelations`、`minRelationPmids` 在 `buildGraphData()` 中生效。
  - `currentGraphData` 保存当前 G6 runtime 的 nodes / edges / relationLegendMeta。
  - Expand mode 的 `mergeCurrentGraphData()` 会把新增 nodes / edges 合并进 `currentGraphData`。
  - G6 graph 实例变量 `graph` 目前只在 closure 内部，未通过 bridge 暴露。

### Existing bridge

- `assets/js/renderers/g6/index-g6-embed.js`
  - `window.__TEKG_G6_EMBED` 当前暴露：
    - `loadGraph`
    - `renderElements`
    - `expandGraph`
    - `setFixedView`
    - `setViewState`
    - `setKeyNodeLevel`
    - `setLanguage`
    - `resize`
    - `getCurrentQuery`
    - `getFixedView`
    - `getKeyNodeLevel`
  - 当前没有导出 visible nodes / edges 或 PNG 的 bridge 方法。

## 推荐设计

### Visible subgraph data source

第一版优先在 iframe runtime 暴露导出数据，因为 iframe 的 `currentGraphData` 已经是经过当前 render options 过滤后的 G6 data，并包含 Expand mode 增量合并结果。

新增 runner 方法：

- `getVisibleSubgraph()`
  - 返回：
    - `query`
    - `nodes`
    - `edges`
    - `counts`
  - 数据来自 `currentGraphData.nodes` / `currentGraphData.edges`。
  - 必须 clone plain JSON，避免外部改动 runtime state。

Parent fallback：

- 如果 iframe bridge 不可用，可用 `filterElementsForLegend(getCurrentGraphElements())` 生成 CSV 数据。
- 但 PNG 必须从 iframe/canvas 获取，因此最终 UI 应优先等待 iframe bridge。

### PNG export

优先使用 G6 graph 实例能力，如果当前 G6 版本提供：

- `graph.toDataURL(...)`
- 或 `graph.downloadFullImage(...)`
- 或 canvas DOM `toDataURL('image/png')`

计划执行时需要先定点确认 `@antv/g6` 当前版本实际可用 API。若 G6 graph API 不稳定，最小 fallback 是从 iframe `#container canvas` 取第一个 canvas 并调用 `toDataURL('image/png')`。

新增 runner 方法：

- `exportPngDataUrl()`
  - 返回 `data:image/png;base64,...`
  - 不自动下载，下载动作由 parent 统一处理。

新增 iframe bridge 方法：

- `getVisibleSubgraph()`
- `exportPngDataUrl()`

新增 parent bridge/helper：

- `getDynamicEmbedBridge()` 或复用现有 `dynamicBridgePromise`。
- `downloadBlob(filename, blob)`。
- `downloadText(filename, text, mime)`.

### CSV fields

Nodes CSV fields:

- `id`
- `label`
- `rawLabel`
- `type`
- `degree`
- `isKeyNode`
- `description`
- `pmid`
- `disease_class`
- `category_level`

Edges CSV fields:

- `id`
- `source`
- `source_label`
- `source_type`
- `target`
- `target_label`
- `target_type`
- `relation`
- `relationType`
- `pmids`
- `pmid_count`
- `evidence`

CSV rules:

- UTF-8 text.
- Quote fields using standard CSV escaping.
- Arrays such as `pmids` join with `;`.
- Filenames include current query and timestamp, for example:
  - `tekg_LINE-1_visible_nodes_2026-05-21.csv`
  - `tekg_LINE-1_visible_edges_2026-05-21.csv`
  - `tekg_LINE-1_canvas_2026-05-21.png`

## UI placement

Use existing tool-style controls in `preview.php` / G6 toolbar area. Do not create a landing-style section or large card.

Recommended first version:

- Add one compact `Export` button near existing graph controls.
- Button opens a small menu or dropdown with:
  - `Nodes CSV`
  - `Edges CSV`
  - `Canvas PNG`
- Keep labels short and utilitarian.
- Disable or no-op with clear detail/status if no current graph is available.

If current UI has no dropdown pattern, use three compact buttons grouped with existing controls rather than introducing a new visual system.

## 涉及文件范围

- Modify: `preview.php`
  - Add compact export control(s) in existing graph toolbar.
- Modify: `assets/js/renderers/g6/index-g6.bootstrap.js`
  - Parent export handlers.
  - CSV serialization.
  - Download helpers.
  - Bridge calls to iframe export methods.
- Modify: `assets/js/renderers/g6/index-g6-shared.js`
  - Runner methods to expose visible subgraph data and PNG data URL.
- Modify: `assets/js/renderers/g6/index-g6-embed.js`
  - Expose export methods through `window.__TEKG_G6_EMBED`.
- Add or Modify: `scripts/checks/check_g6_subgraph_export_smoke.py`
  - Browser smoke for visible CSV/PNG export behavior.
- Possibly Modify: `docs/RELIABILITY.md`
  - Add export smoke to reliability checks once implemented.

## 实施步骤

### 1. Confirm available PNG API

- Inspect local `assets/vendor/g6/g6.min.js` or runtime graph object in Playwright.
- Determine whether `graph.toDataURL`, `graph.downloadFullImage`, or canvas `toDataURL` is available.
- Record chosen path in execution record.

### 2. Add failing smoke

Create `scripts/checks/check_g6_subgraph_export_smoke.py`:

- Open `preview.php?q=LINE-1`.
- Wait for graph and legend to load.
- Capture baseline visible nodes / edges from the planned export bridge.
- Toggle a node type or relation legend filter.
- Verify exported visible nodes / edges reflect the filter.
- Trigger Nodes CSV and Edges CSV export without relying on OS downloads if possible:
  - Prefer calling bridge/helper directly in page context and inspect returned CSV text.
  - If UI-only, intercept browser download and read file content.
- Trigger PNG export:
  - Verify returned data URL or downloaded file starts with PNG signature.
  - Verify byte length is non-trivial.

Expected RED before implementation:

- Export bridge methods are missing.
- UI controls are missing.

### 3. Expose visible subgraph from iframe

In `assets/js/renderers/g6/index-g6-shared.js`:

- Add runner method `getVisibleSubgraph()`.
- Source from `currentGraphData.nodes` / `currentGraphData.edges`.
- Include node labels/types in edge rows by resolving source/target nodes.
- Return JSON-safe cloned objects.

In `assets/js/renderers/g6/index-g6-embed.js`:

- Add `getVisibleSubgraph()` bridge method.

### 4. Add PNG export bridge

In `assets/js/renderers/g6/index-g6-shared.js`:

- Add runner method `exportPngDataUrl()`.
- Prefer G6 official graph export API if confirmed.
- Fallback to iframe canvas `toDataURL('image/png')`.
- Throw a clear error if no canvas/data URL can be produced.

In `assets/js/renderers/g6/index-g6-embed.js`:

- Add `exportPngDataUrl()` bridge method.

### 5. Add parent export helpers

In `assets/js/renderers/g6/index-g6.bootstrap.js`:

- Add helper to resolve current iframe bridge.
- Add CSV serializer.
- Add filename sanitizer.
- Add export handlers:
  - `exportVisibleNodesCsv()`
  - `exportVisibleEdgesCsv()`
  - `exportCanvasPng()`
- Use iframe `getVisibleSubgraph()` for CSV.
- Use iframe `exportPngDataUrl()` for PNG.
- Keep parent raw `filterElementsForLegend()` as fallback only if bridge data is unavailable for CSV.

### 6. Add UI controls

In `preview.php`:

- Add compact export controls beside existing G6 graph tools.
- Wire buttons through existing JS bootstrap selectors.
- Keep controls disabled or inert until current graph is dynamic and iframe bridge is ready.

### 7. Verify

Required commands:

```powershell
php -l preview.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_expand_mode_smoke.py --query LINE-1
python scripts/checks/check_g6_expand_layout_smoke.py --query LINE-1
python scripts/checks/check_g6_expand_disambiguation_smoke.py
python scripts/checks/check_g6_subgraph_export_smoke.py
python scripts/checks/check_api_contracts.py
```

Expected:

- Export smoke passes for nodes CSV, edges CSV, and PNG.
- Existing G6 browser / expand / layout / same-label checks still pass.
- API contracts unchanged.

## 验收标准

- User can export current visible nodes CSV.
- User can export current visible edges CSV.
- User can export current canvas PNG.
- CSV respects legend/filter/min PMID visible subgraph.
- Export after Expand mode includes newly appended visible nodes / edges.
- Export does not require new backend endpoint.
- No agent, taxonomy, Search/Browse/Expression changes.

## 执行记录

- 计划创建日期：2026-05-21。
- 2026-05-21：第一版已实施。
  - Visible subgraph 数据源：iframe G6 runner 的 `currentGraphData.nodes` / `currentGraphData.edges`，通过 `window.__TEKG_G6_EMBED.getVisibleSubgraph()` 暴露；parent 仅在 iframe bridge 不可用时 fallback 到 `filterElementsForLegend(getCurrentGraphElements())`。
  - CSV：`Export CSV` 一次导出两个文件，分别为 current visible nodes 和 current visible edges。nodes 字段为 `id,label,rawLabel,type,description,pmid`；edges 字段为 `id,source,target,relation,relationType,pmids,evidence`。
  - PNG：优先尝试 G6 `graph.toDataURL({ type: 'image/png' })`，失败或不可用时 fallback 到 iframe 内最大 canvas 的 `toDataURL('image/png')`。
  - UI：在 `preview.php` 现有 G6 toolbar 中新增 `Export CSV` / `Export PNG` 两个工具型按钮；仅 dynamic graph 可用且非 loading 时启用。
  - Smoke：新增 `scripts/checks/check_g6_subgraph_export_smoke.py`，覆盖按钮存在、parent export bridge、CSV 行数与 visible subgraph counts 一致、PNG data URL 非空、legend filter 后导出数量缩小。
  - 验证通过：
    - `php -l preview.php`
    - `node --check assets/js/renderers/g6/index-g6.bootstrap.js`
    - `node --check assets/js/renderers/g6/index-g6-shared.js`
    - `node --check assets/js/renderers/g6/index-g6-embed.js`
    - `python scripts/checks/check_g6_browser_smoke.py`
    - `python scripts/checks/check_g6_expand_mode_smoke.py --query LINE-1`
    - `python scripts/checks/check_g6_expand_layout_smoke.py --query LINE-1`
    - `python scripts/checks/check_g6_expand_disambiguation_smoke.py`
    - `python scripts/checks/check_g6_subgraph_export_smoke.py`
    - `python scripts/checks/check_api_contracts.py`
- 2026-05-21：Export UI 收敛完成。
  - UI 从两个独立按钮 `Export CSV` / `Export PNG` 收敛为一个 `Export` 主按钮。
  - `Export` hover / focus / click 后显示小菜单，菜单项为 `CSV`、`PNG`、`SVG Soon`。
  - `CSV` 继续复用 `exportVisibleCsv()`；`PNG` 继续复用 `exportCanvasPng()`。
  - CSV / PNG 数据来源和文件格式未改变。
  - SVG 仅显示为 disabled 的 `SVG Soon`，未实现 SVG 导出。
  - `scripts/checks/check_g6_subgraph_export_smoke.py` 已更新为验证单按钮菜单语义、CSV/PNG 菜单项触发下载、SVG disabled 状态。
  - 验证通过：
    - `php -l preview.php`
    - `node --check assets/js/renderers/g6/index-g6.bootstrap.js`
    - `node --check assets/js/renderers/g6/index-g6-shared.js`
    - `node --check assets/js/renderers/g6/index-g6-embed.js`
    - `python scripts/checks/check_g6_browser_smoke.py`
    - `python scripts/checks/check_g6_subgraph_export_smoke.py`

## 残留风险

- PNG export API may vary by G6 version; implementation must verify local runtime capability before choosing method.
- Hidden/filtered graph state currently exists in both parent raw elements and iframe `currentGraphData`; implementation should avoid creating a third truth source.
- SVG export is intentionally deferred.
