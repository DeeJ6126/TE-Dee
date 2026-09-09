# data/processed 说明

本目录存放由原始数据二次处理、标准化、结构化或补充生成得到的中间产物与前端资源。  
这些文件大多可以由 `scripts/` 下的脚本重新生成，但其中一部分已经被前端页面或搜索模块直接使用。

## 文件总览

### 图谱种子与标准化结果

- `normalized_output.jsonl`
  - 第一版 `output.jsonl` 的标准化结果。
  - 主要用于早期 Neo4j 图谱构建流程的清洗后输出。

- `neo4j_graph_seed.json`
  - 第一版图谱的节点、关系种子文件。
  - 适合旧版导入、回溯或对照，不建议作为当前主数据源。

- `te_kg2_normalized_output.jsonl`
  - 第二版 `te_kg2.jsonl` 的标准化结果。
  - 当前 Disease / Function / TE 图谱构建的主要清洗后文本数据。

- `te_kg2_graph_seed.json`
  - 由 `te_kg2_normalized_output.jsonl` 进一步整理得到的结构化图谱种子。
  - 包含节点、描述、关系等，是当前前端 description 与图谱关系的重要数据来源之一。

- `te_kg2_normalization_report.json`
  - 第二版 `te_kg2` 标准化过程的统计报告。
  - 当前记录：
    - `records = 1843`
    - `paper_nodes = 1831`
    - `te_nodes = 124`
    - `disease_nodes = 533`
    - `function_nodes = 3016`
    - `relation_count = 12145`
  - `top_relations` 反映标准化后的高频关系类型统计。

### TE 树与谱系文件

- `tree_te_lineage.json`
  - 由 `data/raw/tree.txt` 解析得到的 TE 家族树结构化文件。
  - 当前包含：
    - 根节点
    - 节点总数与边总数
    - 每个节点的层级深度、原始标签、来源行号等
  - 主要用于默认树展示与家族谱系构建。

- `tree_te_lineage.csv`
  - `tree_te_lineage.json` 的 CSV 版本。
  - 适合做导入、检查或人工筛查。

- `line1_subfamily_relations.csv`
  - LINE-1 相关子家族关系整理表。
  - 字段包括：
    - `source`
    - `relation`
    - `target`
    - `copies`
    - `description`
  - 用于补充 LINE-1 家族及其子家族的层级关系。

### 前端展示与说明资源

- `te_descriptions.json`
  - 当前数据库中 TE 节点的双语 description 资源。
  - 已与数据库现存 TE 节点一一对应，用于替代 `index_g6.html` 内联 TE 描述。

- `entity_descriptions.json`
  - Disease / Function 的双语 description 资源。
  - 供前端页面（尤其是 `index_g6.html`）读取，用于替代旧的内联 `descMap`。

- `ui_text.json`
  - `index_g6.html` 及相关页面使用的双语 UI 文案资源。
  - 包括按钮文字、标题、工具栏提示、局部图说明、模型标签等。

- `local_qa_templates.json`
  - 本地 fallback 智能问答模板。
  - 用于在后端不可用或需要本地模板回答时生成结构化说明文本。

### Repbase 结构化与对齐文件

- `te_repbase_structured.json`
  - 将 `data/raw/TE_Repbase.txt` 结构化后的完整 JSON。
  - 每个条目包含：
    - `id`
    - `accession`
    - `name`
    - `description`
    - `keywords`
    - `species`
    - `classification`
    - `references`
    - `sequence_summary`
    - `sequence`
  - 当前共有 `526` 个 Repbase 条目。

- `te_repbase_report.json`
  - Repbase 结构化处理报告。
  - 当前统计：
    - `entry_count = 526`
    - `strict_name_index_count = 540`
    - `canonical_index_count = 538`
    - `matched_count = 276`
    - `unmatched_db_te_count = 171`
    - `repbase_only_count = 250`

- `te_repbase_db_alignment.json`
  - 当前数据库 TE 节点与 Repbase 条目的对齐报告。
  - 用于判断：
    - 哪些数据库 TE 能映射到 Repbase
    - 哪些数据库 TE 暂时无法映射
    - 哪些 Repbase 条目当前数据库尚未使用

- `te_repbase_db_matched.json`
  - 只保留“当前数据库中已成功对齐到 Repbase 的那部分条目”的子集。
  - 搜索页的 Repbase 参考区块优先使用此文件，而不是直接扫描完整 `TE_Repbase.txt`。

### 重复检查与标准化报告

- `tekg_exact_duplicate_report.json`
  - Disease / Function 的“精确重复桶”检查结果。
  - 当前内容为 `[]`，表示按当前规则未检出仍待处理的精确重复桶。

- `tekg_semantic_standardization_report.json`
  - Disease / Function 的语义标准化合并检查结果。
  - 当前内容为 `[]`，表示当前这轮语义标准化脚本没有再生成待执行的额外合并项。

### 辅助检查文件

- `_db_te_names.txt`
  - 数据库 TE 名称列表的文本版快照。
  - 适合快速肉眼查重、对齐或比对。

- `_db_te_names_current.txt`
  - 当前数据库 TE 名称列表文本快照。
  - 与 `_db_te_names.txt` 类似，但用于记录“当前”状态。

- `_db_te_names_current.json`
  - 当前数据库 TE 名称列表的 JSON 版。
  - 便于脚本处理与对齐检查。

- `_disease_function_descriptions_raw.json`
  - Disease / Function 原始 description 抽取缓存。
  - 属于构建 `entity_descriptions.json` 之前的中间原料，不建议直接用于前端展示。

## 疾病实体去重与标准化

当前 Disease 实体的标准化规则主要来源于 `scripts/semantic_aliases.py`。  
下面这些名称已经被明确合并到统一规范名：

- `AIDS`
  - 统一为：`Acquired immunodeficiency syndrome (AIDS)`

- `Acquired immunodeficiency syndrome`
  - 统一为：`Acquired immunodeficiency syndrome (AIDS)`

- `ALS`
  - 统一为：`Amyotrophic lateral sclerosis (ALS)`

- `Amyotrophic lateral sclerosis`
  - 统一为：`Amyotrophic lateral sclerosis (ALS)`

- `FTD`
  - 统一为：`Frontotemporal dementia`

- `HNSCC`
  - 统一为：`Head and neck squamous cell carcinoma`

- `LSCC`
  - 统一为：`lung squamous cell carcinoma`

- `TLE`
  - 统一为：`Temporal lobe epilepsy (TLE)`

- `Temporal lobe epilepsy`
  - 统一为：`Temporal lobe epilepsy (TLE)`

- `UCEC`
  - 统一为：`Uterine corpus endometrial carcinoma (UCEC)`

- `Uterine corpus endometrial carcinoma`
  - 统一为：`Uterine corpus endometrial carcinoma (UCEC)`

- `Mendelian diseases`
  - 统一为：`Mendelian disease`

- `Mendelian disorders`
  - 统一为：`Mendelian disease`

- `Genetic disorders`
  - 统一为：`genetic disease`

- `human genetic disorders`
  - 统一为：`human genetic disease`

- `neurogenetic disorders`
  - 统一为：`neurogenetic disease`

说明：

- 上述是当前显式写入标准化别名表的 Disease 规范项。
- `tekg_exact_duplicate_report.json` 与 `tekg_semantic_standardization_report.json` 当前均为空列表，说明现有脚本在当前数据上没有再检测出待处理的重复桶。
- 这不代表 Disease 语义层已经永远“完全无重复”，而是表示当前规则下已没有新增的自动合并建议。

## 使用建议

- 如果要做前端展示：
  - 优先使用 `te_descriptions.json`、`entity_descriptions.json`、`ui_text.json`

- 如果要做搜索页 TE 详情：
  - 优先使用 `te_repbase_db_matched.json`
  - 如需完整 Repbase 数据，再看 `te_repbase_structured.json`

- 如果要回溯图谱构建过程：
  - 优先查看 `te_kg2_normalized_output.jsonl`、`te_kg2_graph_seed.json`、`te_kg2_normalization_report.json`

- 如果要检查 TE 树：
  - 查看 `tree_te_lineage.json` 与 `tree_te_lineage.csv`

## Legacy / primary pipeline note

- `normalized_output.jsonl` should now be treated as a legacy processed artifact derived from `output.jsonl`.
- `te_kg2_normalized_output.jsonl` is the primary processed JSONL for the current active pipeline.
- New development should prefer the `te_kg2` branch first and only touch the older `output.jsonl` branch when maintaining backward compatibility.

---

## Current archive note (2026-03-31)

The following legacy LINE1-focused artifacts have been moved out of the active `data/processed/` folder and archived under `data/archive/legacy/processed/`:

- `normalized_output.jsonl`
- `neo4j_graph_seed.json`
- `line1_subfamily_relations.csv`

The active default pipeline now uses:

- `te_kg2_normalized_output.jsonl`
- `te_kg2_graph_seed.json`
