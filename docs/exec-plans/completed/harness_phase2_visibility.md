# Harness Phase 2 Visibility Checks

## 背景

TE-KG 需要更接近官方 harness 的可见性层：Codex 在修 API、Neo4j、G6 页面前，应先能自己获得运行证据，而不是只依赖人工描述。

## 目标

- 新增 Neo4j `tekg3` 检查。
- 新增核心 API contract 检查。
- 新增 G6 browser smoke 检查。
- 明确 Playwright 安装和运行方式。

## 不做什么

- 不修复 G6 Expand mode。
- 不新增 expression JSON endpoint。
- 不负责启动 WAMP 或 Neo4j。

## 涉及文件范围

- `requirements-dev.txt`
- `scripts/checks/harness_lib.py`
- `scripts/checks/check_neo4j_tekg3.py`
- `scripts/checks/check_api_contracts.py`
- `scripts/checks/check_g6_browser_smoke.py`
- `AGENTS.md`
- `docs/exec-plans/tech-debt-tracker.md`

## 验证命令

```powershell
pip install -r requirements-dev.txt
python -m playwright install chromium
python -m py_compile scripts/checks/harness_lib.py
python -m py_compile scripts/checks/check_neo4j_tekg3.py
python -m py_compile scripts/checks/check_api_contracts.py
python -m py_compile scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_neo4j_tekg3.py
python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_browser_smoke.py
```

## 执行记录

- `pip install -r requirements-dev.txt` 已安装 `playwright`。
- `python -m playwright install chromium` 已安装 Chromium；下载中途发生一次连接中断，自动重试后成功。
- Python 语法检查通过。
- `check_neo4j_tekg3.py` 通过：确认 runtime DB 为 `tekg3`，有 225 个 TE 节点和 24748 条 `BIO_RELATION` 关系，代表性 TE 可解析。
- `check_api_contracts.py` 通过：`health`、`graph.php?q=LINE1`、taxonomy tree、taxonomy items contract 均通过。
- `check_g6_browser_smoke.py` 当前失败，但失败证据有效：G6 iframe 有尺寸但内部无 canvas/svg/children，console 显示 CDN 资源被 `ERR_NETWORK_ACCESS_DENIED` 拦截。

## 残留风险

- Browser smoke v1 只确认页面不白屏、不出现 `ReferenceError`、图容器存在；它还不验证 Expand mode 的业务正确性。
- `api/expression_data.php` 当前是 include 入口，不作为 JSON contract 检查对象。
- 如果本地 URL 不是 `http://127.0.0.1/TE-`，需要设置 `TEKG_BASE_URL`。
- G6 运行仍依赖 CDN 资源；在网络受限或浏览器策略阻断时，`preview.php` 会出现 iframe 空白。后续应把 G6 / marked 等核心 runtime 资源本地化，或提供明确 fallback。
