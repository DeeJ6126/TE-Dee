# TE Taxonomy Runtime Canonical Source

日期：2026-05-16

## 结论

TE taxonomy 的运行时权威源是当前应用连接的 Neo4j `tekg3` 数据库。

运行时分类树/图从独立的 `:TaxonomyNode` 及 `TAXONOMY_PARENT_OF` 关系读取；文献实体 `:TE` 只保存扁平化分类注释。两类节点没有关系，分类节点不会进入知识图谱。两份 TXT 是可编辑的导入输入，不由 PHP 在请求期间解析。

## 权威字段

Neo4j `tekg3` 中 `TE` 节点的核心分类字段是：

```text
taxonomy_status
taxonomy_source
taxonomy_class
taxonomy_subclass
taxonomy_order
taxonomy_superfamily
taxonomy_family
taxonomy_subclade
```

`taxonomy_group`、`taxonomy_canonical_name`、`is_leaf_standard`、`homepage_chart_included` 不再存于 Neo4j。API 需要兼容旧响应时，从 `TE.name` 生成 canonical name，并以 `taxonomy_source == tree_rmsk_repbase && taxonomy_status == leaf` 推导权威叶节点。匹配方式与名称冲突只在导入审计报告中记录。

每个 `TaxonomyNode` 有 `node_key`（来源与完整原始标签祖先路径的 SHA-256）、`source`、`name`、`raw_label`、`rank`、`depth`、`is_leaf` 和 `path_key`。两棵树以 `source` 隔离；边方向为父节点到子节点。导入清单记录两个 TXT 的 SHA-256，不能用同名分类节点合并不同层级或来源。

## 运行时入口

统一 taxonomy API：

```text
api/taxonomy.php
```

支持的运行时视图：

```text
api/taxonomy.php
api/taxonomy.php?names=L1HS,AluJb,SVA
api/taxonomy.php?view=tree&source=rmsk_repbase
api/taxonomy.php?view=tree&source=all
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

该路径从 `:TE` 的 `taxonomy_source`、`taxonomy_status` 和等级属性推导权威叶节点。Home 的完整分类统计从 `:TaxonomyNode` 权威树叶节点读取，不依赖只有 225 个文献实体的 `:TE` 集合。Home 的实体组成将分类节点排除在非 TE 计数之外，避免与 1,501 个权威叶节点重复；关系总数仍统计 Neo4j 的全部关系（包括分类边），关系饼图只统计 `BIO_RELATION`。

## 保留与删除

保留的 canonical input：

```text
data/taxonomy/transposon_tree/tree_rmsk_repbase.txt
data/taxonomy/transposon_tree/tree_all.txt
data/taxonomy/te_234/*
data/terminology/*
scripts/normalize/build_tekg3_from_tekg21.py
scripts/taxonomy/taxonomy_model.py
scripts/taxonomy/rebuild_neo4j_taxonomy.py
```

已移除的旧运行时真相源：

```text
data/taxonomy/lineage/tree_te_lineage.json
data/taxonomy/lineage/tree_te_lineage.csv
assets/data/graph_demo_data.js
```

`data/processed/tekg3_homepage_taxonomy.json` 暂时保留为首页 ring chart 的 fallback cache。首页运行时优先通过 PHP helper 实时查询 Neo4j `tekg3` 并生成 ring chart views；只有 Neo4j 查询失败时才回退到该 JSON 文件。

新导入清单位于 `data/processed/neo4j_taxonomy_import.json`。运行 `scripts/taxonomy/rebuild_neo4j_taxonomy.py --dry-run` 生成清单后，用 `--apply-active` 直接更新 `tekg3` 中的分类子图和文献 `:TE` 分类属性；脚本核对 TXT 与 `:TE` 名称是否仍对应清单，不创建临时数据库，也不重建整个知识图谱。

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
