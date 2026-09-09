# TE-KG 项目文件夹精简建议

日期：2026-05-17

范围：本文记录当前项目文件夹中值得清理的实际目标。本文刻意不处理 download link 这类容易后补的小问题。

## 当前结构

顶层目录：

```text
.vscode
api
assets
data
docs
imports
reference
scripts
templates
test
```

当前 `rg --files` 能看到约 296 个文件。本地工作区还包含很多被 Git 忽略的大型数据资产，主要集中在 `data/` 下。

大型本地资产主要集中在：

- `data/JBrowse`
- `data/bulk_expression_web`
- `data/raw`
- `data/processed`
- `data/dfam`
- `reference/external_examples/G6/.git`
- `archive/processing_history` 已删除

## 1. 从版本控制里移除 Python bytecode

本项已处理。原先观察到被 Git 跟踪的 bytecode：

```text
scripts/__pycache__/path_helpers.cpython-313.pyc
scripts/checks/__pycache__/check_taxonomy_runtime_consistency.cpython-313.pyc
scripts/normalize/__pycache__/build_tekg3_from_tekg21.cpython-313.pyc
```

已执行处理：

- 已用 `git rm --cached` 从 Git 索引移除所有已跟踪 `.pyc`。
- 已在 `.gitignore` 增加 `__pycache__/` 和 `*.pyc`。
- 本地磁盘上的 `.pyc` 文件不作为运行时输入，也不应再次进入版本控制。

风险：

- Python 版本变化或脚本运行都会制造无意义 diff。
- code review 会被生成文件干扰。

已执行命令：

```powershell
git rm --cached scripts/__pycache__/path_helpers.cpython-313.pyc
git rm --cached scripts/checks/__pycache__/check_taxonomy_runtime_consistency.cpython-313.pyc
git rm --cached scripts/normalize/__pycache__/build_tekg3_from_tekg21.cpython-313.pyc
```

`.gitignore` 已加入：

```text
__pycache__/
*.pyc
```

## 2. 保持运行时配置集中

本轮已经改进：

- 新增 `api/runtime_config.php`。
- 把 `api/config.local.php.example` 更新到 `tekg3`。
- 从活跃 API 文件里移除了运行时 fallback 到 `tekg2` 和 `tekg21` 的逻辑。
- 新增 `scripts/checks/check_runtime_db_config.py`。

下一步清理：

- 非 agent 运行时文件应尽量通过 `api/runtime_config.php` 读取共享配置。
- MySQL expression 配置如果确实只属于 expression，可以继续保留在 expression helper 中。
- 不要再在页面或 API 文件里重新加入 DB 名默认值。

## 3. 删除旧构建脚本

本项已处理。以下旧链路脚本已从 `scripts/` 中删除：

```text
scripts/build/generate_tree_demo_data.py
scripts/build/generate_tree_te_lineage.py
scripts/build/generate_tree_te_lineage_0413.py
scripts/export/finalize_te_234_taxonomy.py
scripts/build/build_tekg2_seed_from_standardized_new.py
scripts/normalize/fix_tekg2_unresolved_0413.py
scripts/normalize/extract_tekg2_unresolved_relations.py
scripts/eval/analyze_tekg2_import_readiness.py
scripts/export/export_te_234_classification_csv.py
scripts/export/export_te_234_template_csv.py
scripts/import/generate_disease_classification_import_cypher_0413.py
scripts/import/generate_te_kg2_dedup_cypher.py
scripts/import/generate_tekg2_import_bundle.py
scripts/normalize/apply_tekg2_attention_db_import.py
scripts/normalize/apply_tekg2_entity_overrides.py
scripts/normalize/clean_tekg2_standardized_jsonl.py
scripts/normalize/normalize_te_kg2_graph.py
scripts/tekg2_entity_overrides.py
```

风险：

- 后续维护者可能运行旧 generator，重新生成过时文件。
- 旧的 `tekg2`、`4.18/4.27` taxonomy 假设可能重新混入运行时。

后续如果还要继续清理，可考虑的结构：

```text
scripts/
  active/
  legacy/
  checks/
  normalize/
  build/
```

保留原则：

- `scripts/normalize/build_tekg3_from_tekg21.py` 保留，因为它是当前 `tekg3` 构建和标准化入口。
- `scripts/checks/*` 保留，因为这些是运行时一致性检查。
- 历史说明文档里出现旧脚本名可以保留，但不能再把这些脚本当成当前可运行入口。

## 4. 拆大文件前先保证行为有覆盖

活跃 PHP 文件里较大的文件：

```text
api/qa.php                                      2293 lines
api/agent/orchestrator/AcademicAgentService.php 2241 lines
api/graph.php                                  1821 lines
api/agent/orchestrator/DeepThinkService.php    1439 lines
search.php                                     1115 lines
api/agent/bootstrap.php                         797 lines
api/expression_data.php                         541 lines
```

活跃 JS 文件里较大的文件：

```text
assets/js/renderers/g6/index-g6.bootstrap.js  1415 lines
assets/js/pages/agent.js                      1264 lines
assets/js/renderers/g6/index-g6-shared.js     1127 lines
assets/js/renderers/g6/dynamic-graph.js        883 lines
assets/js/renderers/g6/default-tree-mindmap.js 856 lines
assets/js/renderers/g6/default-tree.js         833 lines
```

建议顺序：

1. `api/qa.php` 和 agent 相关文件交给 agent 负责人处理。
2. `api/graph.php` 要等代表性 API smoke test 稳定后再拆。
3. `search.php` 可以按数据源 helper 拆分：
   - Repbase helper
   - Dfam helper
   - JBrowse helper
   - taxonomy helper
   - 页面组装和渲染
4. G6 文件可以按运行时契约、布局、交互、数据加载拆分。

不要把这一步做成视觉重设计。第一轮重构应该保持行为不变。

## 5. 把 `archive` 和 `reference` 明确为非运行时区域

本项已处理：

- 顶层 `archive/` 已删除。
- `reference/archive`、`reference/README.md`、`reference/deepseek.html` 已删除。
- `reference/` 目前只保留 `reference/external_examples`。
- 按用户要求，`reference/external_examples` 未删除。

原始风险：

- 搜索结果噪声很大。
- 大型历史文件容易被误认为活跃输入。
- 嵌套 `.git` 目录会干扰项目扫描、备份和迁移。

后续维护规则：

- 不要让运行时代码依赖 `reference/external_examples`。
- 如果未来新增归档材料，优先放到 Git 外或写入明确历史说明。
- 当前项目根目录下不再保留 `archive/` 作为活跃目录。

## 6. 增加运行时数据 manifest

当前重要运行时数据是本地的，而且体积很大。有些生成文件是 canonical runtime input，有些是 derived output，有些是 fallback cache。

建议 manifest 字段：

```json
{
  "path": "data/bulk_expression_web/processed/te_expression_context_stats.tsv",
  "role": "canonical runtime input",
  "owner": "expression",
  "generated_by": "scripts/build/prepare_expression_assets.py",
  "required_for": ["expression_detail.php"],
  "expected_size_bytes": 818036710
}
```

高优先级记录对象：

- `data/taxonomy/transposon_tree/tree_rmsk_repbase.txt`
- `data/taxonomy/transposon_tree/tree_all.txt`
- `data/processed/tekg3_taxonomy_standardization_report.json`
- `data/processed/tekg3_homepage_taxonomy.json`
- `data/bulk_expression_web/processed/*`
- `data/JBrowse/*`
- `data/processed/dfam/dfam_curated_catalog.json`

## 7. 删除或归档重复、可疑的大文件

一个明显候选：

```text
data/bulk_expression_web/cancer_cell_line/CCLE_TE_normalized_count copy.tsv
```

它体积很大，而且看起来像手动复制文件。不要盲删；先比较大小、checksum，并确认是否有脚本引用它。

建议检查：

```powershell
rg -n "CCLE_TE_normalized_count copy.tsv|CCLE_TE_normalized_count.tsv" .
```

如果只有非 copy 文件被使用，可以在备份后把 copy 文件移入 archive 或删除。

## 8. 让 docs 更容易导航

当前文档有价值，但分布较散：

```text
docs/architecture
docs/course
docs/demo
docs/latex
docs/notes
docs/setup
```

建议约定：

- `docs/architecture`：当前架构、活跃风险、任务清单。
- `docs/setup`：本地配置、运行时数据恢复、环境启动。
- `docs/latex`：论文或报告的 LaTeX 源文件。
- `docs/notes`：临时笔记，定期归档或合并。
- `docs/demo`：demo 专用脚本和页面说明。

另外建议在 `docs/README.md` 增加一个短表，说明哪些文档是当前有效版本。

## 9. 保持 agent 和非 agent 的职责边界

项目 handoff 已经说明：主 AI 主要负责非 agent 工作；`agent_gpt55_handoff.md` 面向另一个负责 agent 开发的 AI 或维护者。

建议边界：

- 非 agent 负责人：根目录运行时页面、数据路径、taxonomy API、graph API、expression 页面、JBrowse、docs。
- agent 负责人：`agent.php`、`assets/js/pages/agent.js`、`api/agent/*`、`api/qa.php` 行为。
- 共享契约：`api/runtime_config.php`、`api/taxonomy.php`、`api/graph.php`、expression helper。

这样可以避免无关 agent 重构阻塞数据库和前端稳定工作。

## 10. 建议清理顺序

1. 移除被跟踪的 `.pyc` 文件并更新 `.gitignore`。
2. 增加 runtime data manifest。
3. 把过时 generator 脚本标记为 legacy，或更新它们的输入路径。
4. 判断 `data/processed/tekg2/*` 是活跃参考、迁移输入，还是 archive。
5. 检查重复大文件，例如 `CCLE_TE_normalized_count copy.tsv`。
6. smoke test 稳定后，拆分 `search.php` 的数据 helper。
7. 把 `api/graph.php` 拆成查询、标准化、payload、HTTP 处理几个模块。
8. agent 重构和非 agent 数据库清理分开推进。

## 建议保留的验证命令

```powershell
python scripts/checks/check_runtime_db_config.py
python scripts/checks/check_taxonomy_runtime_consistency.py
python scripts/checks/check_expression_paths.py
php -l api/runtime_config.php
php -l api/graph.php
php -l api/health.php
php -l api/taxonomy_lib.php
php -l api/te_metrics.php
```
