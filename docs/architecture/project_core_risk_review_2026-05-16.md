# TE-KG 核心隐患审查与待修复清单

日期：2026-05-16

范围：在 Neo4j 与 WAMPServer 已启动的状态下，对 TE-KG 全项目做结构性风险审查。本文档刻意不把 `download.php` 里的下载链接这类容易修复的浅层问题列为核心隐患。

主要交接依据：`docs/architecture/project_full_gpt55_handoff.md`。

辅助阅读：`docs/architecture/agent_gpt55_handoff.md`。该文档主要面向负责 agent 子系统的 AI；本次审查仍以全项目、尤其是非 agent 的运行结构、数据链路和维护风险为主。

## 已验证的运行状态

- WAMP/PHP 运行中。
- `api/config.local.php` 当前把 Neo4j 指向：
  - `http://127.0.0.1:7474/db/tekg3/tx/commit`
- `api/health.php` 在 Neo4j 预热后返回：
  - `using_local_config = true`
  - `dashscope_model = deepseek-v4-flash`
  - `neo4j_url = .../db/tekg3/tx/commit`
  - `neo4j_reachable = true`
- `api/graph.php?q=L1HS` 可以从 `tekg3` 返回有效图数据。
- `api/te_metrics.php` 返回 `225` 个 TE metric 条目。
- 直接查询 `tekg3` 得到：
  - `TE` 节点数：`225`
  - `homepage_chart_included = true`：`154`
  - `is_leaf_standard = true`：`188`
  - `taxonomy_group = standard`：`138`
  - `taxonomy_group = A`：`36`
  - `taxonomy_group = B`：`35`
  - `taxonomy_group = C`：`16`
- `L1HS` 在 Neo4j 中已有当前 taxonomy 属性：
  - `taxonomy_group = standard`
  - `taxonomy_status = leaf`
  - `taxonomy_source = tree_rmsk_repbase`
  - `is_leaf_standard = true`
  - `homepage_chart_included = true`
  - `taxonomy_class = Retrotransposons`
  - `taxonomy_order = Non-LTR Retrotransposons (LINEs)`
  - `taxonomy_superfamily = L1 (LINE-1)`

## P0：必须优先处理

### 1. 本地敏感配置被 Git 跟踪

证据：

- `git ls-files api/config.local.php` 显示 `api/config.local.php` 已被 Git 跟踪。
- `api/config.local.php` 内含真实本地 Neo4j 密码。
- `.gitignore` 没有忽略 `api/config.local.php`。

风险：

- 本地密码可能被提交、推送、复制到交接材料或出现在 diff 中。
- 后续维护者可能误以为 `config.local.php` 是项目默认配置，而不是机器本地私有覆盖。
- `api/config.local.php.example` 目前仍指向旧的 `tekg2` 默认值，和真实运行目标 `tekg3` 不一致。

建议任务：

1. 如果仓库曾被分享或推送，先轮换 Neo4j 密码。
2. 在 `.gitignore` 中加入 `api/config.local.php`。
3. 用 `git rm --cached api/config.local.php` 从 Git 索引移除本地配置。
4. 保留并更新 `api/config.local.php.example`，使其反映当前 `tekg3`、模型名和配置键。
5. 在 setup 文档中说明哪些配置是本地私有项。

验收标准：

- `git ls-files api/config.local.php` 无输出。
- 正常开发时 `git status --short` 不再反复显示本地配置变化。
- `api/config.local.php.example` 不包含真实密码或真实 API key。

### 2. 运行必需数据被整体忽略，项目不可从 Git 干净复现

证据：

- `.gitignore` 忽略整个 `data/` 目录。
- `git ls-files` 没有跟踪当前关键运行输入，例如：
  - `data/processed/tekg3_homepage_taxonomy.json`
  - `data/processed/tekg3_taxonomy_standardization_report.json`
  - `data/taxonomy/transposon_tree/tree_rmsk_repbase.txt`
  - `data/taxonomy/transposon_tree/tree_all.txt`
- 但运行页和前端代码直接依赖 `data/` 下的文件。

风险：

- 新机器 clean clone 后无法复现当前站点。
- 当前最重要的 taxonomy 状态在 Git 外，容易在交接、迁移、重建时丢失。
- 后续修复可能依赖本机已有文件，但仓库本身没有说明文件来源、版本、hash 或生成方法。

建议任务：

选择一个明确的数据管理策略。

方案 A：跟踪小型 canonical runtime inputs。

- 继续忽略大型 raw/genome/expression 文件。
- 对小型、运行必需、可审查的 JSON/TXT/CSV 使用 `.gitignore` negation rules 单独纳入版本控制。

方案 B：继续忽略全部 `data/`，但提供确定性 manifest/bootstrap。

- 新增 `data_manifest.json`，记录路径、大小、hash、来源和生成脚本。
- 新增 `scripts/check_runtime_data.py` 检查缺失文件。
- 新增 `scripts/bootstrap_runtime_data.py` 或清晰的人工恢复步骤。

至少需要明确策略的文件：

- `data/taxonomy/transposon_tree/tree_rmsk_repbase.txt`
- `data/taxonomy/transposon_tree/tree_all.txt`
- `data/taxonomy/lineage/tree_te_lineage.json`
- `data/processed/tekg3_homepage_taxonomy.json`
- `data/processed/tekg3_taxonomy_standardization_report.json`
- `data/processed/te_repbase_db_matched.json`
- `data/processed/te_descriptions.json`
- `data/processed/entity_descriptions.json`
- `data/processed/ui_text.json`
- `data/terminology/te_terminology.json`
- `data/terminology/te_terminology_overrides.json`

验收标准：

- clean checkout 后，项目能明确告诉开发者缺哪些 runtime data。
- 首页、Browse、Search、Preview、Expression 页面要么能运行，要么以清晰诊断失败。
- 每个生成文件都能被归类为：canonical runtime input、derived output、large local asset 或 disposable artifact。

## P1：高优先级结构问题

### 3. TE taxonomy 同时存在多个运行时真相源

证据：

- 首页 ring chart 使用 `data/processed/tekg3_homepage_taxonomy.json`。
- Neo4j `tekg3` 的 `TE` 节点上已有 `taxonomy_*`、`is_leaf_standard`、`homepage_chart_included` 属性。
- `browse.php` 使用：
  - `data/processed/te_repbase_db_matched.json`
  - `data/taxonomy/lineage/tree_te_lineage.json`
- `search.php` 使用 Repbase/Dfam/local lineage helper，而不是直接使用 `tekg3` 的 taxonomy 属性。
- G6 tree 使用 `assets/data/graph_demo_data.js`，其中仍有 `4.18` tree ID 和描述痕迹。
- `api/graph.php` 当前没有把 `taxonomy_*` 属性输出到节点 payload。

风险：

- 同一个 TE 在首页、Browse、Search、G6 和 Agent 中可能显示不同分类。
- `tekg3` 修复后的 taxonomy 不一定传导到所有前端入口。
- 用户看到的分类不一致时，很难判断是 DB 错、生成文件旧，还是某个页面仍在读旧数据。

建议任务：

1. 明确声明当前 runtime taxonomy authority。
2. 优先使用 `tekg3` 节点属性，或使用由 `scripts/normalize/build_tekg3_from_tekg21.py` 生成的文件。
3. 更新 `api/graph.php`，在 node payload 中输出 `taxonomy_class`、`taxonomy_order`、`taxonomy_superfamily` 等字段。
4. 决定 `data/taxonomy/lineage/tree_te_lineage.json` 是继续作为 runtime canonical，还是降级为 legacy/reference。
5. 用当前 `tree_rmsk_repbase.txt` / `tree_all.txt` 重建或替换 `assets/data/graph_demo_data.js`。
6. 为代表性 TE 加跨页面一致性检查，例如 `L1HS`、`AluJb`、`MER131`、`SVA`、`ERVL`。

验收标准：

- 首页、Browse、Search、Preview/G6、Agent TreePlugin 对同一 TE 的主分类一致。
- `api/graph.php?q=L1HS` 输出 `taxonomy_class`、`taxonomy_order`、`taxonomy_superfamily`。
- 活跃 runtime 不再依赖未标注的 `4.18` tree 数据。

### 4. 多个生成脚本指向已经不存在的 taxonomy 源文件

证据：

- `scripts/build/generate_tree_demo_data.py` 引用：
  - `tree_rmsk_repbase_4.18.txt`
  - `tree_all_4.18_2.txt`
- `scripts/build/generate_tree_te_lineage.py` 引用：
  - `tree.txt`
- `scripts/build/generate_tree_te_lineage_0413.py` 引用：
  - `tree_rmsk_repbase_4.18.txt`
  - `tree_all_4.18_2.txt`
- `scripts/export/finalize_te_234_taxonomy.py` 引用：
  - `tree_all_4.27.txt`
  - `tree_rmsk_repbase_4.27_2.txt`
- 当前实际存在的主 taxonomy 文件只有：
  - `data/taxonomy/transposon_tree/tree_rmsk_repbase.txt`
  - `data/taxonomy/transposon_tree/tree_all.txt`

风险：

- 重要的前端和 taxonomy 输出无法从当前文件结构重新生成。
- 旧 generated asset 会因为生成脚本失效而长期滞留。
- 开发者可能绕过脚本直接手改输出文件，进一步破坏可复现性。

建议任务：

1. 把活跃生成脚本改为读取当前 canonical 文件名。
2. 确实属于历史流程的脚本移入 `archive/`，或加 `legacy_` 前缀。
3. 新增 `scripts/check_generators.py`，检查每个脚本声明的输入文件是否存在。
4. 写清当前生成顺序：
   - normalize TE graph
   - build `tekg3`
   - write homepage taxonomy JSON
   - rebuild lineage/G6 assets, if still required

验收标准：

- `python scripts/build/generate_tree_demo_data.py` 能成功运行，或明确退出并提示它是 legacy。
- 活跃脚本不再引用缺失的 `4.18`、`4.27`、`tree.txt`。
- `assets/data/graph_demo_data.js` 可以从当前源文件再生成。

### 5. `tekg3` 报告计数与 live DB 计数不完全一致

证据：

- `data/processed/tekg3_taxonomy_standardization_report.json` 中有 `228` 个 final/item 记录。
- live `tekg3` 中实际 `TE` 节点数是 `225`。
- report 分组计数与 live DB 属性略有差异：
  - report：`standard=139`、`A=37`、`B=36`、`C=16`
  - live DB：`standard=138`、`A=36`、`B=35`、`C=16`
- 首页计数与 live DB 一致：
  - `homepage_chart_included = true` 为 `154`

可能原因：

- 报告包含 merge/rename 前后的分类记录，而 live DB 反映合并后的节点状态。

风险：

- 后续排查时可能误把 report 当成 live DB snapshot。
- 小的计数漂移可能掩盖真实 taxonomy regression。

建议任务：

1. 把 report 拆清楚：
   - source classification counts
   - operation counts
   - final live DB validation counts
2. `write_taxonomy_properties` 后立即查询目标 DB，把验证结果写回报告。
3. 如果 homepage JSON 计数和 live DB `homepage_chart_included` 计数不一致，报告应显式报错或警告。

验收标准：

- report 明确区分 `input_records`、`post_merge_te_nodes`、`homepage_chart_nodes`。
- `tekg3_homepage_taxonomy.json.views.root.count` 与 live DB `homepage_chart_included = true` 一致。

### 6. Runtime DB fallback 默认值不一致

证据：

- `api/config.local.php` 指向 `tekg3`。
- `api/config.local.php.example` 仍指向 `tekg2`。
- `api/graph.php`、`api/health.php`、`api/te_metrics.php` fallback 仍包含 `tekg2`。
- agent bootstrap fallback 仍包含 `tekg21`。
- 当前能正常运行，是因为 `config.local.php` 覆盖了这些默认值。

风险：

- 如果缺失 `config.local.php`，不同子系统可能静默指向不同 DB。
- 新环境 setup 可能把旧 DB 名重新带回项目。
- 测试可能在错误 DB 上通过或失败，导致诊断混乱。

建议任务：

1. 更新 example config 和 runtime fallback 到当前目标，或取消 DB-name fallback，要求显式配置。
2. 新增共享 config loader，避免 `graph.php`、`health.php`、`te_metrics.php`、`qa.php`、agent bootstrap 各自维护默认值。
3. `api/health.php` 应单独输出 resolved DB name，而不是只输出 URL。

验收标准：

- 搜索 `/db/tekg2/tx/commit` 和 `/db/tekg21/tx/commit` 时，只出现在 legacy docs/scripts 中。
- 缺少本地配置时，系统给出明确 setup error，而不是静默落到旧 DB。

### 7. Expression 数据路径模型分裂（已处理）

证据：

- 当前实际 expression 文件在 `data/bulk_expression_web` 下。
- `api/expression_data.php` 的 quartile TSV 兜底读取已改为：
  - `data/bulk_expression_web/processed/te_expression_context_stats.tsv`
- `scripts/build/prepare_expression_assets.py` 已改为从以下位置读取/写入：
  - `data/bulk_expression_web`
- 路径统一通过 helper：
  - PHP：`tekg_expression_bulk_fs_path()`
  - Python：`expression_bulk_path()`

风险：

- 已降低。当前 Expression 页面运行和构建脚本使用同一个 canonical expression asset root。
- 如果 MySQL 缺 `q1_value` 或 `q3_value`，boxplot 兜底会去当前目录找 TSV。

已完成任务：

1. 明确 canonical expression asset root 是 `data/bulk_expression_web`。
2. 更新 `scripts/build/prepare_expression_assets.py` 和 `api/expression_data.php`，统一使用同一个 path helper。
3. 新增 `scripts/checks/check_expression_paths.py`。

验收标准：

- Expression asset preparation 和 runtime fallback 使用同一个目录策略。
- 当 MySQL quartile columns 不存在时，boxplot fallback 能找到 `te_expression_context_stats.tsv`。

## P2：中优先级维护风险

### 8. Health check 在 Neo4j 冷启动时可能误导

证据：

- Neo4j 刚启动后的第一次 `api/health.php` 曾在 10 秒内超时。
- 随后真实图查询成功，后续 health check 返回 `neo4j_reachable = true`。

风险：

- 开发者可能误判 Neo4j 未启动，而实际只是第一次事务或 DB warm-up 慢。

建议任务：

1. 提高或配置 health-check timeout。
2. 在 health response 中输出 elapsed milliseconds。
3. 可选：在返回 unreachable 前做一次轻量 retry。

### 9. 大型 runtime 页面包含可抽取子系统

证据：

- `search.php` 同时包含 Repbase、Dfam、karyotype、JBrowse、classification、页面渲染准备等逻辑。
- 多个 helper 名称仍带 `_proto`，说明 prototype code 已进入 runtime。

风险：

- 回归难定位。
- 路径和数据策略修复会被单页大文件放大复杂度。

建议任务：

1. 等 config/data/taxonomy 策略稳定后，再做抽取。
2. 先抽 JBrowse path/session helper，因为它和路径抽象重复最明显。
3. 抽取时保持行为不变，不做视觉或交互重写。

### 10. Python bytecode 被 Git 跟踪

证据：

- Git 跟踪了多个 `__pycache__/*.pyc`，包括：
  - `scripts/__pycache__/path_helpers.cpython-313.pyc`
  - `scripts/normalize/__pycache__/build_tekg3_from_tekg21.cpython-313.pyc`

风险：

- Python 版本变化会制造无意义 diff。
- 生成字节码不应参与源码审查或合并。

建议任务：

1. 在 `.gitignore` 加入 `__pycache__/` 和 `*.pyc`。
2. 用 `git rm --cached` 移除已跟踪 bytecode。

## 建议修复顺序

1. 先处理配置和 Git hygiene：
   - untrack `api/config.local.php`
   - ignore local config 和 Python bytecode
   - 更新 `api/config.local.php.example`
2. 定义 runtime data policy：
   - 决定小型关键数据是否进入 Git
   - 或建立 manifest/bootstrap
   - 明确 canonical runtime inputs
3. 修复生成脚本源路径：
   - 当前 taxonomy 文件名
   - 当前 expression asset root
   - legacy 脚本明确归档或标注
4. 统一 TE taxonomy 消费者：
   - `api/graph.php` payload
   - Browse/Search classification
   - G6 static tree generation
   - Agent TreePlugin / EntityNormalizer fallback
5. 增加验证脚本：
   - `scripts/check_runtime_data.py`
   - `scripts/check_taxonomy_consistency.py`
   - health/API smoke checks
6. 最后再整理大页面结构：
   - 抽取 `search.php` helper
   - 减少重复 path logic

## 建议保留的 smoke checks

语法检查：

```powershell
php -l path_config.php
php -l index.php
php -l search.php
php -l browse.php
php -l expression.php
php -l expression_detail.php
php -l preview.php
php -l api/graph.php
php -l api/te_metrics.php
php -l api/health.php
```

运行检查：

```text
GET /TE-/api/health.php
GET /TE-/api/graph.php?q=L1HS
GET /TE-/api/te_metrics.php
GET /TE-/index.php
GET /TE-/search.php?q=L1HS
GET /TE-/browse.php
GET /TE-/expression.php
GET /TE-/expression_detail.php?te=L1HS
GET /TE-/preview.php
```

数据一致性检查：

```cypher
MATCH (t:TE) RETURN count(t) AS te_count;

MATCH (t:TE)
RETURN
  sum(CASE WHEN t.homepage_chart_included = true THEN 1 ELSE 0 END) AS homepage_count,
  sum(CASE WHEN t.is_leaf_standard = true THEN 1 ELSE 0 END) AS leaf_standard_count;

MATCH (t:TE)
RETURN t.taxonomy_group AS taxonomy_group, count(*) AS count
ORDER BY taxonomy_group;
```

## 本清单明确不处理

- `download.php` 的 stale download links。
- 视觉 polish。
- Agent Writing 阶段性能优化。
- 在 config、data reproducibility、taxonomy-source consistency 解决前的大规模重构。
