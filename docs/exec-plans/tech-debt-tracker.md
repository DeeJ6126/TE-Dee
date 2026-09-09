# TE-KG Tech Debt Tracker

本文记录跨任务技术债。它不替代具体执行计划；每个条目应在后续拆成 `active/` 下的独立计划。

## 高优先级

### G6 Expand mode same-label entity disambiguation

- 状态：基础版已解决，见 `completed/g6-expand-same-label-disambiguation.md`。
- 已完成：Expand 请求携带 `expand_node_id`、`expand_node_type`、`expand_query`；后端按 node id -> type+query -> old q fallback 解析。
- 已覆盖：`Disease:Aging` vs `Function:Aging`，包括 API direct contract 和 browser smoke。
- 残留风险：当前样本只覆盖 Aging；更多同名跨类型实体需要扩展 smoke。当前精确定位使用 Neo4j `elementId()`，未来跨库或重建数据库场景可能需要稳定业务 id。

### G6 Expand mode 后续交互质量

- 现状：白屏 blocker、loader blocker、legend loading、L1HS / LINE-1 初始加载卡住、增量新增节点聚集问题已解除。
- 风险：expanded node 仍缺少明确视觉 affordance / collapse；复杂多节点连续扩展、expand 后切换 legend/filter/view state 再继续 expand 的 smoke 覆盖不足。
- 下一步：拆分独立 active plan，先定义 expand/collapse 交互 contract 和多步浏览器 smoke，再做最小 runtime 改动。

### G6 Expand mode 白屏与加载态

- 状态：已完成阶段性修复。
- 现状：`preview.php` 中 Expand mode 白屏、loader 长期卡住、iframe request abort、增量新增节点无布局坐标等 blocker 已由 browser smoke 覆盖。
- 下一步：保留 smoke 作为回归门禁；后续只在新的 active plan 中处理同名实体歧义和交互质量。

### G6 Subgraph export SVG v2

- 状态：v1 已完成，见 `completed/g6-subgraph-export.md`。
- 已完成：当前可见 nodes / edges CSV 导出、当前画布 PNG 导出、单按钮 Export 菜单收敛。
- 已覆盖：`scripts/checks/check_g6_subgraph_export_smoke.py` 验证 Export 菜单、CSV/PNG 导出触发、SVG disabled 状态。
- 残留风险：SVG export 未实现，当前 UI 保持 disabled 的 `SVG Soon`。下一步先执行 `active/g6-svg-export.md` 调研当前 G6 canvas runtime 是否存在可靠官方 SVG export API，以及手写 SVG 是否值得做。

### G6 局部扩展接口不稳定

- 现象：当前局部扩展逻辑仍处在探索阶段，尚未形成可靠 contract。
- 风险：前端可能混用整图重载、iframe bridge 和局部增量合并，导致状态失真。
- 下一步：为 graph expand 行为写 API contract 和前端状态 contract。

### Browser smoke harness 基础版

- 现状：Phase 2 新增 `scripts/checks/check_g6_browser_smoke.py`。
- 风险：基础版只验证页面不白屏、无 `ReferenceError`、图容器存在；还不验证 Expand mode 的业务正确性。当前还可能因 CDN 被阻断失败。
- 下一步：执行 `active/g6-resource-localization.md`，再扩展 browser smoke 覆盖 legend apply、Show labels、节点点击和局部扩展结果。

### API contract harness 基础版

- 现状：Phase 2 新增 `scripts/checks/check_api_contracts.py`。
- 风险：当前覆盖 health、graph、taxonomy；expression JSON contract 尚未设计。
- 下一步：为 expression 建立明确 JSON endpoint 或单独 runtime data contract。

## 中优先级

### Taxonomy browser slug-ID collisions - 2026-07-15

- Status: discovered during All-TE G6 Task 6; not introduced or fixed by the renderer lifecycle work.
- Current boundary: `api/taxonomy.php?view=tree&source=all` returns `1666/1665`, while `taxonomyPayloadToElements()` and `buildStrictTreeSource()` provide the renderer `1660/1659` canonical elements.
- Cause: `treeNodeId()` strips trailing punctuation, collapsing six distinct pairs: `LTR12/LTR12_`, `MER34C/MER34C_`, `MER4A1/MER4A1_`, `LTR13/LTR13_`, `LTR3B/LTR3B_`, and `LTR33A/LTR33A_`.
- Risk: six API-backed taxonomy nodes are merged at the browser canonicalization boundary even though the large-graph adapter retains all elements it receives.
- Next step: create a separate taxonomy ID migration plan with stable collision-safe IDs and dedicated Tree/Graph, click/query-label, legend-count, and browser regression checks. Do not patch IDs ad hoc inside the renderer.

### Agent plugin envelope legacy consumers - 2026-05-25

- 状态：第一版 `PluginResultEnvelope` 已落地，见 `completed/agent-deepthink-boundary-plugin-envelope.md`。
- 已完成：Agent/DeepThink 插件结果在消费边界增加 `result_envelope`，并保留 legacy raw payload；`plugin_result_envelope_test.php` 覆盖成功、空结果、失败、route、literature/citation 和 legacy `status=error` 映射。
- 已完成：Agent Evidence Package v1 已于 2026-05-26 落地，Agent Writing 只读 `evidence_package.v1`，不再以 legacy raw plugin payload、散装 `supported_claims` 或 `citation_bundle` 作为写作证据主体。
- 已完成：Agent Evidence Walk v1 已于 2026-05-26 落地，Agent Writing 现在从 `evidence_package.v1` 派生 `evidence_walk.v1` 和 `report_plan.v1`，再走 draft / polish 双模型与确定性 integrity gate；旧 direct evidence-package writer 已移除。
- 债务：旧消费者仍有路径读取 legacy `results.*` 或 plugin-specific raw arrays，主要用于 sufficiency、UI 展示、node payload debug 和 session memory，尚未全部迁移到 envelope-first。
- 风险：非 Writing 的控制逻辑仍可能因插件 legacy 字段漂移而表现不稳定。
- 下一步：后续阶段逐步把 sufficiency、UI/debug payload、session memory 迁移到 envelope/evidence package 的稳定字段。

### Agent Evidence Walk integrity gate calibration - 2026-05-26

- 状态：第一版 `ReportIntegrityGate` 已落地，见 `completed/agent-evidence-walk-v1.md`。
- 已完成：Gate 检查 unsupported PMID、URL、citation/route marker、空证据强结论，并把缺失计划 section、未覆盖 evidence walk claim 记录为 warning。
- 风险：计划 section warning 依赖报告中出现 section title；它现在不阻断中文或自由标题报告，但 warning 可能偏多。
- 下一步：建立 Agent report golden set 后，按真实报告格式校准 section 检查，必要时增加 title alias 或 section-key 映射。

### DT vs Agent live evaluation - 2026-05-26

- 状态：Phase 5A deterministic harness 已落地，见 `completed/dt-agent-evaluation-harness-v1.md`。
- 已完成：30 个 DT/Agent 双轨 golden cases、`ModeComparisonEvaluation` deterministic contract、Agent response `evaluation_report`、同款网页入口约束和 polisher model 配置读取。
- 已完成：Phase 5B 第一轮 live eval 已运行，见 `completed/dt-agent-live-evaluation-v1.md` 和 `docs/eval/runs/phase5b_flash_full/analysis.md`。
- 结果：DT 30/30 成功，Agent 24/30 成功；Agent 在 5 个简单/边界 DT case 上 overkill。
- 债务：当前 deterministic proxy 只能评估 artifact presence，不能公平评估 claim-level semantic quality。
- 下一步：先修 Agent 边界路由和 Site Navigator URL gate，再做 Phase 5C 语义 evaluator；复杂/失败样本再用 pro 复跑。

### Agent Site Navigator URL integrity false positives - 2026-05-26

- 现象：Phase 5B 中 P5A_B_005、P5A_B_006、P5A_B_029 失败于 Writing，原因是 `ReportIntegrityGate` 把带 Markdown 片段/尾随符号的 Site Navigator URL 判为 unsupported。
- 风险：站内导航类问题本应由 DT 快速处理；若用户强制 Agent，也不应因可点击链接格式轻微变化而失败。
- 下一步：为 `ReportIntegrityGate::cleanUrl()` 增加 Markdown URL normalization，允许 route_map 中的 Site Navigator URL 作为 route evidence，并新增针对 `url](url)`、尾随 backtick、粗体结束符的测试。

### Agent simple-task overkill - 2026-05-26

- 现象：Phase 5B 中序列查询、单跳关系、paper list、简单定义等 5 个 case 被判为 Agent overkill。
- 风险：Agent 会变成慢版 DT，延迟和成本增加，同时稀释研究工作流定位。
- 下一步：在 Agent preflight 中，如果 `analysis.recommended_mode=deep_think` 且无报告/审计/排名/批量需求，直接返回建议使用 DT 或 compact answer，不进入完整 Evidence Walk Writing。

### Agent disabled direct site-navigation Writing branch - 2026-06-01

- 现状：`AcademicAgentService::buildDirectSiteNavigationWritingResult()` 已禁用，但 helper 和不可达分支仍残留；生产路径中 `$directSiteNavigationWriting` 固定为 `null`。
- 风险：旧分支和过时测试会误导后续维护者，使 Agent Writing 路径看起来仍存在双轨行为。
- 下一步：在独立清理任务中删除 helper、不可达分支和过时测试；保留 `route_map` 与 URL integrity contract。

### Agent task complexity classifier calibration - 2026-05-25

- 状态：第一版 `task_complexity` 已落地在 `EntityNormalizer`，并由 `task_complexity_test.php` 和 `agent_narration_task_complexity_test.php` 覆盖。
- 风险：当前分类是启发式，适合产品边界提示和 lightweight routing，不是经过 benchmark 校准的严格语义分类。
- 下一步：建立 DT/Agent golden set 后，用真实问题集校准 `simple_lookup`、`single_hop`、`research_synthesis`、`mechanism_chain` 和 `ambiguous` 的边界。

### 旧 DB fallback 回归风险

- 现象：仓库历史中仍有 `tekg2`、`tekg21` 相关文件。
- 风险：未来修改可能重新引入旧 runtime fallback。
- 下一步：继续维护 runtime DB 配置检查，明确旧 DB 只能出现在 legacy docs/imports 中。

### 旧 expression path 回归风险

- 现象：历史路径 `data/raw/new_data/bulk_expression_web` 曾经作为 fallback 出现。
- 风险：重建 expression assets 或 fallback 逻辑时可能再次指向不存在路径。
- 下一步：新增或维护 expression path 检查，确保 runtime root 是 `data/bulk_expression_web`。

### Taxonomy 多真相源回归风险

- 现象：历史上 taxonomy 曾由多个文件和 runtime 路径同时提供。
- 风险：页面、API、G6、agent 可能显示不一致分类。
- 现状：Phase 3 新增 `scripts/checks/check_taxonomy_runtime_truth.py`。
- 下一步：持续维护 Neo4j/API canonical 检查，避免旧文件重新成为 runtime truth source。

### Phase 3 结构约束检查

- 现状：新增 `check_no_legacy_db_fallback.py`、`check_taxonomy_runtime_truth.py`、`check_g6_static_contract.py`、`check_g6_no_legacy_disease_node.py`、`check_docs_freshness.py`、`check_repository_entropy.py`。
- 风险：前五个是 hard fail；`check_repository_entropy.py` 初版是 warning-only，避免把历史大文件和参考 checkout 立刻变成阻塞项。
- 下一步：等清理策略明确后，再逐步把部分 entropy warning 升级为 hard fail。

## 低优先级

### 历史文档新鲜度

- 现象：`docs/architecture/` 中部分文档是历史 handoff 或专题记录。
- 风险：Codex 可能误把旧状态当当前事实。
- 现状：Phase 3 新增 `scripts/checks/check_docs_freshness.py`。
- 下一步：继续在索引中标注 canonical / reference / legacy。

### G6 SVG export v2 research result - 2026-05-21

- Status: research archived in `completed/g6-svg-export.md`; no runtime implementation in this phase.
- Current supported export surface: visible subgraph CSV and current canvas PNG from G6 subgraph export v1.
- Decision: keep SVG disabled as `SVG Soon` because current browser G6 runtime is canvas-based and does not expose reliable browser `toSVG`, `export`, or `exportToFile` APIs.
- Residual options: later build a simplified visible-subgraph SVG renderer, or evaluate a dedicated `@antv/g6-ssr` export path. Both require a separate plan and should not change CSV/PNG semantics.

### PubMed metadata enrichment and evidence support inputs - 2026-05-21

- Status: full PubMed metadata fetch completed for current Neo4j `tekg3` PMID inventory; visual edge encoding is not changed.
- Output: `data/processed/pubmed_metadata.jsonl` contains 2308 PubMed metadata records and can later be joined to graph relations by PMID.
- Supported fields: DOI, title, abstract, journal title/ISO abbreviation/ISSN, publication year/date/model/types, MeSH terms, keywords, authors, affiliations, grants, chemicals, references, source provider, fetched timestamp.
- Impact Factor is not a PubMed field. Future relation strength or evidence support work needs an external journal metric mapping keyed by ISSN/year; do not hardcode IF in prompts or ask an LLM to infer it.
- Debt: Impact Factor requires a future local mapping file such as `data/reference/journal_metrics.csv` with `issn,year,impact_factor,metric_source,metric_name`.

### Journal metric mapping v1 - 2026-05-22

- Status: v1 mapping completed and accepted for internal TE-KG use; no Neo4j import, G6 change, graph API change, taxonomy change, or agent change has been made.
- Source: local `reference/external_examples/impact_factor` package data, treated as 2025 data with `metric_source=impact_factor_package_2025` and `metric_name=Journal Impact Factor`.
- Provenance constraint: this is not a direct official JCR export, but the project has approved it as a trusted internal journal metric source.
- Outputs: `data/reference/journal_metrics.csv`, `data/processed/journal_metrics_mapping_report.json`, and `data/processed/pubmed_metadata_with_metrics.jsonl`.
- Coverage: journal match rate 86.59%; PMID match rate 88.26%; unmatched PMID count 271.
- Unmatched journals/PMIDs keep null metrics. Do not guess IF, do not use LLM-generated metrics, and do not force-match different ISSNs.
- Residual risk: `title_exact` fallback produced 22 matches. This is accepted for internal v1, but should be manually reviewed before publication or external-facing visual encoding.

### Neo4j journal metrics relation aggregation v1 - 2026-05-22

- Status: Paper enrichment and BIO_RELATION aggregation completed in Neo4j `tekg3`; graph API and G6 visual encoding are still unchanged.
- Paper enrichment: 2308 existing `Paper` nodes tagged `journal_metrics_v1_2026-05-22`; 2037 with metrics, 271 with null metrics.
- Relation aggregation: 12444 existing `BIO_RELATION` relationships tagged `relation_metrics_v1_2026-05-22`.
- Aggregation evidence: 14770 raw relation PMID references, 2270 unique relation PMIDs, 2270 joinable PMIDs, 2003 with metrics, 267 without metrics.
- Written relation fields include `support_pmid_count`, `support_metric_paper_count`, `support_metric_coverage`, `support_if_max`, `support_if_mean`, `support_if_median`, JCR quartile counts, journal count, publication year min/max, and `relation_metrics_import_tag`.
- Rollback is property-only via `scripts/rollback_relation_journal_metrics.py --import-tag relation_metrics_v1_2026-05-22 --write`; it must not delete nodes or relationships.
- Next debt: design graph API evidence-support read path and G6 visual encoding in a separate active plan. Do not call IF-derived fields confidence.

### G6 evidence support UX v1 - 2026-05-22

- Status: completed and archived in `completed/g6-evidence-support-ux-v1.md`.
- Graph API now exposes per-edge eager `evidence_records` without abstracts; fields are limited to PMID, PubMed URL, title, journal, year, journal metric value/source/year, JCR quartile, and match method.
- G6 edge width uses `support_pmid_count`; edge opacity uses `support_metric_coverage` with a visible lower bound, so zero-coverage edges remain visible.
- Edge click now renders an evidence table with PubMed links and supports selected-edge evidence CSV download for tables above 10 rows.
- Follow-up UI placement: evidence table now lives inside the expanded edge inspect card's `PubMed` section, with the CSV button on that section header; the lower detail area no longer duplicates the table.
- Follow-up layout: legacy lower detail is visually hidden again, and the PubMed evidence table uses fixed in-card column widths without horizontal scrolling; Journal/Title truncation keeps tooltip and CSV full values.
- Checks: `scripts/checks/check_graph_api_edge_evidence_records.py` and `scripts/checks/check_g6_evidence_support_ux.py`.
- Residual risk: eager payload size may need a lazy endpoint if future large graph queries regress browser/API performance; v1 does not add an explanatory legend for edge width/opacity.

### G6 node action card UX - 2026-05-22

- Status: completed and archived in `completed/g6-node-action-card-ux.md`.
- User-visible `Fixed view` and `Expand mode` controls are hidden; compatibility DOM/state is retained.
- Node click now opens a node action card only. It does not auto-jump and does not auto-expand.
- Node action buttons are explicit: `Jump`, `Expand`, and `Details`.
- `Jump` reuses existing node-centered graph loading; `Expand` reuses the same-label-safe expand path and still sends `expand_node_id`, `expand_node_type`, and `expand_query`; `Details` expands the node card without graph mutation.
- Follow-up UI polish completed: node Expand loader copy changed to `Expanding`, Jump closes the old card, Reset is no longer user-visible, `Show labels` was renamed to `Show relations`, toolbar off-state styling aligns with Export, evidence table Journal/Title widths were adjusted, and collision radius offsets increased slightly from `46/38/34/30` to `50/42/38/34`.
- Follow-up loader amendment completed: TE graph loading can render conservative retrotransposon- or DNA-transposon-inspired inline SVG/CSS loaders using the current TE color. Unknown/non-TE loading keeps the existing default animation. The loader is illustrative only and must not be treated as a strict mechanism diagram.
- Follow-up loader refinement completed: mechanism loaders are now larger (`420x220`), labels use black fill with white stroke, retro RNA/label/RT move as one group, RNA is semi-transparent TE color, target DNA slides in, and target copy labels use the current TE short label.
- Follow-up loader bugfix/refinement completed: `LINE1` now triggers retro loader during Search/graph loading, mechanism SVGs are enlarged to `560x300`, retro target DNA no longer slides, and DNA transposon no longer renders a duplicate inserted-copy TE.
- Follow-up loader biology/visual refinement completed: arrows were removed, target DNA now opens as left/right fixed gap segments, retro source DNA remains intact, DNA transposon donor DNA splits around the excised TE without slash cut marks, and DNA transposon TE movement is lift plus right/down transfer only.
- Follow-up loader root-cause refinement completed: mechanism loader type detection now handles taxonomy/category-level phrases and plural normalization (`Class I: Retrotransposons`, `Retrotransposons`, `Class II: DNA transposons`, `Tc1-Mariner`) without one-off hardcoding. Retro copy and DNA moving TE now align into target DNA gaps, DNA source donor segments initially connect to the TE and separate during movement, and target opening arcs no longer cover TE labels.
- Checks: `scripts/checks/check_g6_node_action_card_ux.py`, updated `check_g6_browser_smoke.py`, and updated expand smokes.
- Residual debt: fixed/expand internal variables are retained for compatibility and should only be removed after a separate reference audit. Expand/collapse affordance and multi-step expanded-node UX remain separate future work.

### G6 TE tree load regression - 2026-05-22

- Status: completed and archived in `completed/g6-te-tree-load-regression.md`.
- Resolved: taxonomy file-tree depth parsing now uses UTF-8 character length, restoring deep edges in the `rmsk_repbase` tree and reconnecting `L1HS` under the Class I/LINE hierarchy.
- Resolved: folded category nodes with real descendants stay in tree mode instead of being routed through ordinary graph search, preventing loader hangs for `Class I: Retrotransposons`, `Class II: DNA Transposons`, and `Others` clicks.
- Resolved: mindmap initial Y positioning centers the root vertically, and plural loader terms such as `SINEs` classify through the retro loader semantics.
- Check: `scripts/checks/check_g6_te_tree_load_regression.py` covers taxonomy integrity, direct search/load diagnostics, category click behavior, L1HS path restoration, and loader classification.
- Residual debt: ordinary dynamic graph search for taxonomy category labels still returns empty payloads, and `SINEs` currently resolves as a single Paper node. If these labels should open category-centered graphs, create a separate query/alias contract plan rather than hardcoding UI exceptions.

### Recommended next G6 / TE-KG plans - 2026-05-22

- Category-centered graph contract: define whether taxonomy categories such as `Class I: Retrotransposons`, `Class II: DNA Transposons`, `Order: SINEs`, and `Others` should open category-centered graphs, empty states, or tree focus. Do this as an API/query contract plan, not as isolated UI hardcoding.
- TE tree search/focus: add a tree search/focus interaction for deep taxonomy nodes such as `L1HS`, with clear expansion and viewport behavior.
- Node action card multi-step expand/collapse affordance: show expanded state, avoid duplicate expands, and design a reset/collapse path for added neighborhoods.
- Loader mechanism scientific polish: keep it separate from loader lifecycle. The current loaders are mechanism-inspired and should only be refined after graph loading stability remains green.
- Large graph `evidence_records` lazy endpoint: only pursue if future large graph checks show payload or browser performance regressions from eager per-edge evidence records.
- Journal metric manual review / official JCR replacement: before external-facing claims, review `title_exact` journal matches and consider replacing `impact_factor_package_2025` with an official licensed source.

### Disease classification initial-load failure fallback - 2026-08-11

- Status: non-blocking residual risk after the routed disease Tree/Graph implementation.
- Normal navigation, refresh, sharing, page Back, browser Back/Forward, drag/click behavior, and exports are covered by browser regression tests.
- If the first direct disease-class URL fails before any disease surface has committed, the renderer can show the default taxonomy fallback while the address still identifies the requested disease class. A future error-state pass should render an explicit routed classification failure surface rather than borrowing the taxonomy surface.

### Graph answer-subgraph entity recognition - 2026-08-12

- Status: experimental implementation retained but user-facing action disabled through `ANSWER_GRAPH_ACTION_ENABLED = false`.
- Reason: matching entity labels against generated answer prose can still select an imprecise node set. Until a reviewed resolver contract exists, Preview must not show `View answer graph`, display its counts, or mutate Graph from assistant answers.
- Re-enable only after broader TE/disease/gene ambiguity regressions demonstrate that the proposed subgraph contains exactly the intended answer entities.
