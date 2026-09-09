# G6 Resource Localization

## 背景

Phase 2 browser smoke 已能打开 `preview.php?q=LINE1` 并收集页面证据，但当前失败证据显示浏览器无法加载外部 CDN 资源，例如 `@antv/g6` 和 `marked`。这会导致 G6 iframe 有尺寸但无 canvas/svg/children，看起来像白屏。

## 目标

- 让 G6 runtime 关键前端依赖可在本地 WAMP 环境稳定加载。
- Browser smoke 不再因为 CDN 被阻断而失败。
- 保留现有页面和 API 行为。

## 不做什么

- 本计划不修 Expand mode 的业务正确性。
- 本计划不重写 G6 runtime。
- 本计划不改变 Neo4j 或 MySQL 数据。

## 涉及文件范围

- `preview.php`
- `assets/html/preview_graph.html`
- `assets/html/preview_g6_embed.html`
- `assets/vendor/g6/`
- `assets/vendor/marked/`

## 验收标准

- `preview.php?q=LINE1` 在 browser smoke 中能加载 G6 依赖。
- Smoke 输出不再包含 `ERR_NETWORK_ACCESS_DENIED` 的 G6 / marked CDN 错误。
- 图容器至少出现 canvas/svg/children，且 loader 不长期卡住。

## 验证命令

```powershell
php -l preview.php
python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_browser_smoke.py
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_g6_static_contract.py
python scripts/checks/check_g6_relation_legend_expand_mode.py
python scripts/checks/check_g6_legend_expand_tree_fixes.py
python scripts/checks/check_no_legacy_db_fallback.py
```

## 执行记录

- Added local WAMP-served vendor assets for `@antv/g6@5.1.1` and `marked@18.0.4` under `assets/vendor/`, including license files.
- Updated `preview.php` to load `marked` and G6 from local vendor assets instead of jsDelivr.
- Updated `assets/html/preview_graph.html` and `assets/html/preview_g6_embed.html` to load G6 from local vendor assets.
- Did not change Expand mode business logic, G6 runtime implementation, graph API, Neo4j/MySQL data, or agent subsystem.

Verification results:

- `php -l preview.php`: pass.
- `python scripts/checks/check_api_contracts.py`: pass for health, graph `q=LINE1`, and taxonomy contracts.
- `python scripts/checks/check_g6_browser_smoke.py`: pass for `http://127.0.0.1/TE-/preview.php?q=LINE1`.
- `node --check assets/js/renderers/g6/index-g6.bootstrap.js`: pass.
- `node --check assets/js/renderers/g6/index-g6-shared.js`: pass.
- `node --check assets/js/renderers/g6/index-g6-embed.js`: pass.
- `python scripts/checks/check_g6_static_contract.py`: pass.
- `python scripts/checks/check_g6_relation_legend_expand_mode.py`: pass.
- `python scripts/checks/check_g6_legend_expand_tree_fixes.py`: pass.
- `python scripts/checks/check_no_legacy_db_fallback.py`: pass.

## 残留风险

Expand mode 的业务正确性仍需要单独定位和修复。本计划只解除 CDN/resource loading blocker。
