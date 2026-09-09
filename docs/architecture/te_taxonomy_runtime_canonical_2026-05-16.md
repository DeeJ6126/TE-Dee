# TE Taxonomy Runtime Canonical Source

日期：2026-05-16

## 结论

TE taxonomy 的运行时权威源是当前应用连接的 Neo4j `tekg3` 数据库。

运行时页面、API、G6 前端树、QA 和 agent 分类能力都应以 `TE` 节点上的 `taxonomy_*` 属性为准。旧的 lineage JSON/CSV 和 G6 demo data 不再作为运行时分类来源。

## 权威字段

Neo4j `tekg3` 中 `TE` 节点的核心分类字段是：

```text
taxonomy_group
taxonomy_status
taxonomy_source
taxonomy_canonical_name
taxonomy_class
taxonomy_subclass
taxonomy_order
taxonomy_superfamily
taxonomy_family
taxonomy_subclade
is_leaf_standard
homepage_chart_included
```

其中 `taxonomy_class` / `taxonomy_order` / `taxonomy_superfamily` / `taxonomy_family` / `taxonomy_subclade` 组成展示路径。

## 运行时入口

统一 taxonomy API：

```text
api/taxonomy.php
```

支持的运行时视图：

```text
api/taxonomy.php
api/taxonomy.php?names=L1HS,AluJb,SVA
api/taxonomy.php?view=tree
api/taxonomy.php?view=summary
```

共享 PHP helper：

```text
api/taxonomy_lib.php
```

Search、Browse、G6、QA、agent TreePlugin 和 EntityNormalizer 应复用该 helper/API，不再直接读取 taxonomy lineage 文件。

首页 ring chart：

```text
index.php -> tekg_taxonomy_fetch_items() -> tekg_taxonomy_homepage_payload()
```

该路径直接读取 Neo4j `tekg3` 的 `homepage_chart_included` 和 `taxonomy_*` 字段。

## 保留与删除

保留的 canonical input：

```text
data/taxonomy/transposon_tree/tree_rmsk_repbase.txt
data/taxonomy/transposon_tree/tree_all.txt
data/taxonomy/te_234/*
data/terminology/*
scripts/normalize/build_tekg3_from_tekg21.py
```

已移除的旧运行时真相源：

```text
data/taxonomy/lineage/tree_te_lineage.json
data/taxonomy/lineage/tree_te_lineage.csv
assets/data/graph_demo_data.js
```

`data/processed/tekg3_homepage_taxonomy.json` 暂时保留为首页 ring chart 的 fallback cache。首页运行时优先通过 PHP helper 实时查询 Neo4j `tekg3` 并生成 ring chart views；只有 Neo4j 查询失败时才回退到该 JSON 文件。

## 验证

运行以下检查确认运行时 taxonomy 没有分裂：

```powershell
python scripts/checks/check_taxonomy_runtime_consistency.py
```

该脚本会检查：

```text
1. 代表 TE 在 Neo4j tekg3 中有 taxonomy 字段。
2. api/taxonomy.php 与 Neo4j 字段一致。
3. api/graph.php 输出的 node taxonomy 与 Neo4j 字段一致。
4. index.php 优先从实时 Neo4j taxonomy 生成 homepage ring chart。
5. 运行时文件不再引用旧 lineage JSON 或 graph_demo_data.js。
```

当前代表 TE：

```text
L1HS
AluJb
MER131
SVA
ERVL
```

## 后续规则

新增页面或 agent 能力需要 TE 分类时，应先使用 `api/taxonomy.php` 或 `api/taxonomy_lib.php`。

不要重新引入以下文件作为运行时分类来源：

```text
tree_te_lineage.json
tree_te_lineage.csv
graph_demo_data.js
tekg2_0413_tree_*_lineage.json
```

如果未来需要静态缓存，应从 `tekg3` 导出，并且必须由一致性检查脚本覆盖。
