# Harness Phase 3 Governance

## 背景

Phase 1 建立了短入口和计划目录，Phase 2 建立了 Neo4j/API/G6 browser smoke 的可见性检查。本阶段补上官方式 harness 的治理层：架构规则、计划工作流、质量看板和可靠性说明。

## 目标

- 补齐短 `ARCHITECTURE.md` 和架构专题文档。
- 把旧 DB、旧 taxonomy truth source、G6 contract、legacy Disease aggregate、docs freshness 写成可运行检查。
- 建立质量评分和可靠性文档。
- 固化 `Implementer` / `Reviewer` / `Verifier` 三角色自查协议。

## 不做什么

- 不修 G6 白屏。
- 不改 PHP/JS runtime 业务逻辑。
- 不移动大型数据或参考目录。
- 不把 `reference/` 移入 `docs/`。

## 涉及文件范围

- `AGENTS.md`
- `ARCHITECTURE.md`
- `docs/architecture/*`
- `docs/exec-plans/*`
- `docs/QUALITY_SCORE.md`
- `docs/RELIABILITY.md`
- `scripts/checks/*`

## 验收标准

- 新增文档结构存在，并且 `AGENTS.md` 仍是短入口。
- 新增检查脚本可编译、可运行。
- Neo4j/API/expression/taxonomy 回归检查仍通过。
- repository entropy 仅 warning，不作为 Phase 3 hard fail。

## 验证命令

```powershell
python -m py_compile scripts/checks/check_no_legacy_db_fallback.py scripts/checks/check_taxonomy_runtime_truth.py scripts/checks/check_g6_static_contract.py scripts/checks/check_g6_no_legacy_disease_node.py scripts/checks/check_docs_freshness.py scripts/checks/check_repository_entropy.py
python scripts/checks/check_no_legacy_db_fallback.py
python scripts/checks/check_taxonomy_runtime_truth.py
python scripts/checks/check_g6_static_contract.py
python scripts/checks/check_g6_no_legacy_disease_node.py
python scripts/checks/check_docs_freshness.py
python scripts/checks/check_repository_entropy.py
python scripts/checks/check_neo4j_tekg3.py
python scripts/checks/check_api_contracts.py
python scripts/checks/check_runtime_db_config.py
python scripts/checks/check_expression_paths.py
python scripts/checks/check_taxonomy_runtime_consistency.py
```

## 执行记录

- 新增 root `ARCHITECTURE.md`。
- 新增 `graph_runtime.md`、`data_sources.md`、`database_contract.md`、`frontend_contract.md`。
- 新增 `docs/design-docs/`、`docs/generated/`、`docs/references/` 入口。
- 新增 `docs/QUALITY_SCORE.md` 和 `docs/RELIABILITY.md`。
- 新增 Phase 3 architecture checks。
- 更新 `AGENTS.md`、`docs/architecture/index.md`、`docs/exec-plans/README.md`、`tech-debt-tracker.md`。
- 新增 `active/g6-resource-localization.md`，记录 Phase 2 暴露的 CDN 阻断风险。

## 验证结果

- Python compile：通过。
- `check_no_legacy_db_fallback.py`：通过。
- `check_taxonomy_runtime_truth.py`：通过。
- `check_g6_static_contract.py`：通过。
- `check_g6_no_legacy_disease_node.py`：通过。
- `check_docs_freshness.py`：通过。
- `check_neo4j_tekg3.py`：通过，`tekg3`、225 个 TE、24748 条 `BIO_RELATION`。
- `check_api_contracts.py`：通过。
- `check_runtime_db_config.py`：通过。
- `check_expression_paths.py`：通过。
- `check_taxonomy_runtime_consistency.py`：通过。
- `check_repository_entropy.py`：warning-only，发现 Python cache、`reference/external_examples/G6/.git` 和多个大型数据文件。

## 残留风险

- G6 browser smoke 仍可能因 CDN 被阻断失败，需要执行 `active/g6-resource-localization.md`。
- `check_repository_entropy.py` 还不是 hard fail，需要后续明确哪些历史残留必须清理。
- `docs/generated/` 当前只有入口说明，还没有真正生成 schema/API snapshot。
