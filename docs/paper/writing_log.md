# TE-KG 中文数据库论文写作记录

## 本轮定位

本文已从 HALD / Scientific Data 风格重排为更贴近 `reference/format/baaf070.pdf` 的 *Database* 期刊结构：

- 引言
- 材料与方法
- 结果
- 讨论
- 结论

根据后续修改要求，正文已从“作者贡献”起删除尾部声明性章节，仅保留参考文献。

## Nature skills 执行记录

- `nature-reader`
  - 读取 `reference/format/baaf070.pdf`，提取其结构为：Introduction、Materials and methods、Results、Discussion、Conclusions、Author contributions、Funding、Data availability。
  - 生成章节迁移逻辑：原“数据记录”和“使用说明”并入 Results，原“技术验证”拆入 Methods 和 Discussion。

- `nature-academic-search`
  - 补充数据库和知识图谱资源类参考文献，包括 InTxDB、KG-Hub、SPOKE、CROssBAR、Dfam 等。
  - 更新 `reference.bib`，只保留正文实际引用的条目。

- `nature-writing`
  - 按 *Database* 文章逻辑重写正文。
  - 每节尽量采用“主张 -> 数据/功能证据 -> 边界”的段落结构。
  - 队友的 `docs/methods/method_english.docx` 被吸收到文献检索、白名单、筛选、抽取、规范化和验证部分。

- `nature-figure`
  - 使用 Python 作为唯一绘图后端。
  - 已生成当前正文使用的三张图的 PNG、SVG、PDF 和 TIFF。
  - 图件源数据位于 `figure_source_data/`。
  - 图件说明位于 `docs/figure_contracts.md`。

- `nature-data`
  - 重写代码可用性和数据可用性部分。
  - 保留 FAIR 原则，明确正式公开前还需清理敏感配置、版本说明和下载入口。

- `nature-polishing`
  - 中文正文已做一轮结构性润色。
  - 禁止词和孤立英文仍需在编译后继续自动检查。

## 图件与源数据

- Figure 2: `figures/generated/figure2_data_composition.*`
  - 使用 `figure_source_data/entity_composition_no_paper.csv` 等源数据。
  - Entity Composition 明确排除 Paper / 文献节点。
- Figure 3: `figures/generated/figure3_te_classification_pies.*`
  - 使用 `figure_source_data/te_classification_by_level.csv`。
  - 展示 Class、Order、Superfamily 和 Family 四级分类组成。
- Figure 4: `figures/generated/figure4_l1hs_case.*`
  - L1HS 证据卡片图，不表示因果流程。

## 已吸收的关键数据

- 候选文献：34,788 篇。
- 白名单初筛文献：5,362 篇。
- 核心文献：2,308 篇。
- 待检查文献：1,803 篇。
- 运行图谱节点：11,415 个。
- 运行图谱有向关系：13,696 条。
- 文献节点：2,308 个，已从实体组成图中排除。
- RepeatMasker 注释记录：5,683,690 条。
- TE 分类记录：299 条。
- 表达浏览摘要：37,868 条。
- 表达上下文：143 个。
- 表达数据集摘要：113,604 条。

## 待复核事项

- 关系组成目前使用首页统计接口的主关系类型与 Other 分组，正式投稿前建议导出更完整的谓词统计表。
- 截图来自现有展示材料的截图目录，最终提交前建议重新截图以保证 UI 状态最新。
- L1HS 案例中的序列、代表性位置和疾病关系需要在最终版前再次用实时数据库核对。
- 若后续恢复正式投稿格式，作者贡献、经费支持、代码可用性和数据可用性需要按实际情况补全。
- 当前会话无法刷新 `build/main.pdf`，因为 XeLaTeX 无法写入 `main.log`，且提权编译请求被额度系统拒绝。已完成引用键、图件存在性、禁词和实体组成源数据的静态检查。
