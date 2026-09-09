# TE-KG Reliability

本文说明 TE-KG 当前可观测性和可靠性检查。目标是让 Codex 先拿证据，再判断问题在哪里。

## 核心检查

```powershell
python scripts/checks/check_neo4j_tekg3.py
python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_expand_mode_smoke.py
python scripts/checks/check_runtime_db_config.py
python scripts/checks/check_expression_paths.py
python scripts/checks/check_taxonomy_runtime_consistency.py
python scripts/checks/check_no_legacy_db_fallback.py
python scripts/checks/check_taxonomy_runtime_truth.py
python scripts/checks/check_docs_freshness.py
```

## 失败含义

- `check_neo4j_tekg3.py` 失败：优先检查 Neo4j 是否启动、`api/config.local.php` 是否指向 `tekg3`。
- `check_api_contracts.py` 失败：说明 PHP/WAMP/API payload 有问题，不要先修前端。
- `check_g6_browser_smoke.py` 失败：说明浏览器层或 G6 runtime 有证据异常；需要看 console、failed requests、loader 和 graph container。
- `check_g6_expand_mode_smoke.py` 失败：说明 `preview.php` Expand mode 在 parent/iframe bridge、局部追加、loader/canvas 状态或请求中止路径上可能回归；不要先改 API，先看 smoke 输出中的 canvas、nodes/edges、console 和 failed requests 证据。
- `check_runtime_db_config.py` 或 `check_no_legacy_db_fallback.py` 失败：说明旧 DB fallback 可能回流。
- `check_expression_paths.py` 失败：说明 expression runtime path 可能回到旧目录。
- `check_taxonomy_runtime_consistency.py` 或 `check_taxonomy_runtime_truth.py` 失败：说明 taxonomy canonical 规则可能被破坏。

## 当前已知可靠性风险

- Browser smoke 可能因外部 CDN 被阻断失败，尤其是 `@antv/g6` 和 `marked`。
- 这类失败不等于 Neo4j 或 API 失败；应优先按 `docs/exec-plans/active/g6-resource-localization.md` 处理。
- G6 resource loading blocker 已解除；`@antv/g6` 和 `marked` 现在由本地 vendor asset 提供，browser smoke 不应依赖外部 CDN。
- G6 legend loading blocker 已解除；`check_g6_browser_smoke.py` 会验证 legend 不停留在 `Loading legend...`。
- `L1HS` / `LINE-1` 初始进入图谱时 Preparing / Loading legend 卡住的问题已通过 render bridge timeout 缓解，避免大图 `graph.render()` 长时间不 resolve 时阻塞 parent loader。
- Expand mode 白屏 blocker 已解除，并由 `scripts/checks/check_g6_expand_mode_smoke.py` 覆盖保留中心图、非中心节点扩展、无 iframe blank / `ERR_ABORTED` 的基础回归路径。
- Expand mode 新增节点聚集问题已解除；`scripts/checks/check_g6_expand_layout_smoke.py` 覆盖增量追加后 `draw` / `layout` 路径和新增节点坐标分布。
- Expand mode same-label entity disambiguation 基础版已解决：Expand 请求会携带 `expand_node_id`、`expand_node_type`、`expand_query`，后端优先按 clicked node 精确扩展。`Disease:Aging` vs `Function:Aging` 由 `check_g6_expand_disambiguation_smoke.py` 和 `check_api_contracts.py` 覆盖。
- Same-label disambiguation 残留风险：当前 smoke 主要覆盖 Aging；更多同名跨类型实体样本仍需扩展。当前精确定位使用 Neo4j `elementId()`，适合本地单库 runtime，未来如需跨库稳定链接可能要引入稳定业务 id。
- Expanded node 的视觉 affordance / collapse、复杂多节点连续扩展、多类型展开质量仍需后续单独计划处理。
- G6 subgraph export v1 已完成：当前可见子图可导出 CSV，当前画布可导出 PNG，Export toolbar 已收敛为单按钮菜单，并由 `scripts/checks/check_g6_subgraph_export_smoke.py` 覆盖。SVG 仍是 disabled 的 `SVG Soon`，后续 v2 另行调研。
- Agent/DeepThink boundary and plugin envelope v1 已完成第一版：`task_complexity`、Agent research templates、`PluginResultEnvelope`、schema-style envelope checks 和 narration checks 已落地。当前仍未跑 live WAMP/Neo4j/LLM/browser，且 envelope 保留 legacy raw payload 兼容层。
- Agent Evidence Package v1 已完成第一版：Agent Integrating 生成并校验 `evidence_package.v1`；Answer Writer Node payload 不再暴露旧 `supported_claims` / `citation_bundle` 作为写作输入。
- Agent Evidence Walk v1 已完成第一版：Agent Writing 强制走 `evidence_package.v1 -> evidence_walk.v1 -> report_plan.v1 -> draft -> integrity gate -> polish -> integrity gate`，旧 direct evidence-package writer 已移除。

## Agent / DeepThink Reliability Checks - 2026-05-25

第一阶段“巩固边界”和第二阶段“统一插件契约”的 Final Verifier PASS 命令：

```powershell
php -l agent.php
php -l api\agent\orchestrator\EntityNormalizer.php
php -l api\agent\orchestrator\traits\AcademicAgentNarrationTrait.php
php -l api\agent\orchestrator\traits\AcademicAgentPluginResultTrait.php
php -l api\agent\orchestrator\traits\DeepThinkRoutingTrait.php
php -l api\agent\contracts\PluginResultEnvelope.php
php -l api\agent\config\plugin_result_envelope_schema.php
php test\task_complexity_test.php
php test\plugin_result_envelope_test.php
php test\agent_narration_task_complexity_test.php
node --check assets\js\pages\agent.js
```

实际实现范围：

- `api/agent/orchestrator/EntityNormalizer.php`
- `api/agent/orchestrator/traits/AcademicAgentNarrationTrait.php`
- `api/agent/contracts/PluginResultEnvelope.php`
- `api/agent/config/plugin_result_envelope_schema.php`
- `api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php`
- `api/agent/orchestrator/traits/DeepThinkRoutingTrait.php`
- `agent.php`
- `assets/js/pages/agent.js`
- `assets/css/pages/agent.css`
- `test/task_complexity_test.php`
- `test/plugin_result_envelope_test.php`
- `test/agent_narration_task_complexity_test.php`

残留风险：

- 未跑 live WAMP、Neo4j、LLM、browser 路径；当前验证主要是 lint 和 fixture/contract tests。
- `PluginResultEnvelope` 仍兼容 legacy raw payload，旧消费者尚未全部迁移到 envelope-first。
- `task_complexity` 是启发式分类，适合作为产品边界提示和轻量路由信号，不应被当作严格语义分类器。

## Agent Evidence Package Reliability Checks - 2026-05-26

第三阶段 Evidence Package v1 的验证命令：

```powershell
php -l api\agent\contracts\EvidencePackage.php
php -l api\agent\config\evidence_package_schema.php
php -l api\agent\orchestrator\AcademicAgentService.php
php -l api\agent\orchestrator\LlmClient.php
php -l api\agent\orchestrator\traits\AcademicAgentEvidenceTrait.php
php -l api\agent\orchestrator\traits\AcademicAgentPluginResultTrait.php
php -l test\evidence_package_test.php
php -l test\agent_evidence_package_runtime_test.php
php test\evidence_package_test.php
php test\plugin_result_envelope_test.php
php test\agent_evidence_package_runtime_test.php
php test\agent_narration_task_complexity_test.php
```

残留风险：

- 未跑 live WAMP、Neo4j、LLM、browser 路径；当前验证证明静态 contract 和非 LLM runtime 约束成立。
- 旧 `evidence`、`citations`、`synthesized_evidence` 仍保留在 Agent response 中用于 UI/兼容展示，但 Agent Writing 不再依赖这些字段。
- Deep Think 暂未切换到 evidence package；当前 v1 只覆盖 Agent Writing。

## Agent Evidence Walk Reliability Checks - 2026-05-26

第四阶段 Evidence Walk v1 的验证命令：

```powershell
php -l api\agent\contracts\EvidenceWalk.php
php -l api\agent\contracts\ReportPlan.php
php -l api\agent\contracts\ReportIntegrityGate.php
php -l api\agent\orchestrator\LlmClient.php
php -l api\agent\orchestrator\AcademicAgentService.php
php -l api\agent\orchestrator\traits\AcademicAgentPluginResultTrait.php
php -l api\agent\bootstrap\evidence_support.php
php test\evidence_package_test.php
php test\evidence_walk_report_plan_test.php
php test\report_integrity_gate_test.php
php test\agent_research_report_prompt_test.php
php test\agent_evidence_walk_runtime_test.php
php test\agent_evidence_package_runtime_test.php
php test\plugin_result_envelope_test.php
php test\agent_narration_task_complexity_test.php
```

残留风险：

- 当前验证仍是 lint 和非 live fixture/contract tests；未跑 live WAMP、Neo4j、LLM、browser。
- `ReportIntegrityGate` 是确定性 guardrail，不等价于完整事实核验；它主要阻止新增 PMID、URL、citation/route marker 和明显强结论越界。缺失计划 section 只记录 warning，避免中文或自由标题报告被误拒。
- Polisher 未通过 integrity gate 时，runtime 会使用已通过 gate 的 draft 作为保守答案并记录 warning；后续可把该 warning 展示为更明确的写作质量状态。

## DT vs Agent Evaluation Harness Checks - 2026-05-26

Phase 5A 已建立 deterministic evaluation harness。当前阶段不跑真实 DeepSeek API，不依赖 live WAMP/Neo4j/browser/LLM，只验证评估契约、golden cases 和 Agent response 最小接入。

验证命令：

```powershell
php -l api\agent\contracts\ModeComparisonEvaluation.php
php -l api\agent\orchestrator\AcademicAgentService.php
php -l api\agent\bootstrap.php
php test\mode_comparison_evaluation_test.php
php test\dt_agent_golden_cases_test.php
php test\agent_evaluation_report_runtime_test.php
php test\agent_evidence_walk_runtime_test.php
php test\agent_narration_task_complexity_test.php
```

同款入口约束：

- Deep Think 真实评估必须调用 `api/deep_think_stream.php`，和 `agent.php` 的 `deepThinkStreamApiUrl` 一致。
- Agent 真实评估必须调用 `api/agent_runs.php` 创建 run，并轮询 `api/agent_run_status.php`，和网页 Agent 一致。
- 不用旧 `agent_stream.php` 做主评估入口，不直接调用 `agent_run_execute.php` 冒充用户入口。
- 任何声称评估 Agent/Deep Think 真实质量的结论都必须跑 live API；不能用“省成本”为理由跳过 API。
- 评估归档必须保存前端用户会看到的真实过程流和最终回答，包括 Agent thinking/阶段事件、工具结果摘要、错误、`done` payload、最终 answer。只看中间 artifact、只看静态测试、只看 deterministic proxy，都不能作为真实质量结论。
- Canary 可以先缩小样本数，但单个样本仍必须记录完整 raw events、过程文本和最终回答；如果没有这些证据，只能标为 static/unit verification，不能标为 live evaluation。

模型策略：

- Broad evaluation 默认使用 `deepseek-v4-flash`。
- `agent_polisher_model` 已进入 `tekg_agent_config()`，本地默认 `deepseek-v4-flash`。
- 不把 `agent_writing_model` 默认改成 `deepseek-v4-pro`；复杂/失败样本可在 Phase 5B/5C 通过 payload 覆盖 writer 为 pro 做对照。

残留风险：

- Phase 5A 只证明评估框架可运行，不证明 DT/Agent 真实回答质量。
- `ModeComparisonEvaluation` 是 deterministic proxy，不替代后续人工抽样和 LLM evaluator。
- 30 个 golden cases 是 seed set；真实评估后需要扩展到 80-120 个 cases 并校准指标权重。

## DT vs Agent Live Evaluation Results - 2026-05-26

Phase 5B 已完成第一轮真实 `deepseek-v4-flash` live eval。

结果目录：

- `docs/eval/runs/canary_phase5b/`
- `docs/eval/runs/phase5b_flash_full/`
- 详细分析：`docs/eval/runs/phase5b_flash_full/analysis.md`

环境前提已验证：

- `agent.php` HTTP 200。
- Neo4j `tekg3` read-only health check passed。
- LLM relay health passed by environment checker。

执行命令：

```powershell
python scripts/eval/run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --limit 1 --out-dir docs\eval\runs\canary_phase5b --timeout 240 --poll-interval 2
python scripts/eval/run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --out-dir docs\eval\runs\phase5b_flash_full --timeout 300 --poll-interval 2
python scripts/eval/run_dt_agent_live_eval.py --rescore-existing --out-dir docs\eval\runs\phase5b_flash_full
```

Aggregate result:

- DT success: 30/30。
- Agent success: 24/30。
- Agent overkill: 5/30。
- Expected Agent cases: 18 total, 16 completed.
- Expected Deep Think or boundary-DT cases: 12 total, 8 Agent completed and 5 showed overkill.

Main reliability findings:

- DT is currently more reliable and faster for the seed set.
- Agent produces research-style reports on many complex tasks but needs semantic evaluation before claiming superiority.
- Agent should hard-route simple lookup/navigation/single-hop cases back to DT.
- `ReportIntegrityGate` currently rejects some Site Navigator URLs emitted with Markdown punctuation; URL normalization should be fixed before the next live run.
- The live runner now rescales from `synthesizing` events because Agent `done` payload does not currently expose full evidence artifacts.
- Scope note: the runner is backend endpoint-equivalent to the webpage path, not a full browser UI smoke; it does not test DOM state or frontend stream rendering.
- Scoring note: the live run uses a Python proxy scorer aligned with Phase 5A concepts, not a direct call to PHP `ModeComparisonEvaluation`.

## Phase 5B.1 Live Canary Closeout - 2026-05-26

Phase 5B.1 的三个 follow-up live canary 已通过。当前结果证明边界修复在网页同款后端路径上可运行：

- Deep Think: `api/deep_think_stream.php`
- Agent: `api/agent_runs.php` + `api/agent_run_status.php`

执行命令：

```powershell
python scripts/eval/run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_005 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_005 --timeout 300 --poll-interval 2
python scripts/eval/run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_029 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_029 --timeout 300 --poll-interval 2
python scripts/eval/run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_001 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_001 --timeout 300 --poll-interval 2
```

Latest live records:

| Case | DT | Agent | Agent overkill | Errors |
|---|---|---|---|---|
| `P5A_B_005` | ok, 7435 ms | ok, 44609 ms | false | `dt_errors=[]`, `agent_errors=[]` |
| `P5A_B_029` | ok, 7041 ms | ok, 2230 ms | false | `dt_errors=[]`, `agent_errors=[]` |
| `P5A_B_001` | ok, 7431 ms | ok, 2159 ms | false | `dt_errors=[]`, `agent_errors=[]` |

Reliability facts established:

- Compact preflight accepts string confidence labels (`low`, `medium`, `high`) from `inferConfidence()`.
- Site Navigator route evidence accepts the same route without a `#fragment` when the evidence route contains a fragment.
- The Python live proxy scorer only counts Deep Think expected Agent runs as overkill when Agent has more than two plugins and `agent_artifact_score > 0.5`, matching the PHP contract more closely.

Verification commands used for this closeout:

```powershell
php test\report_integrity_gate_test.php
php test\agent_simple_preflight_gate_test.php
php test\agent_narration_task_complexity_test.php
php test\agent_evaluation_report_runtime_test.php
python test\dt_agent_live_eval_score_test.py
python -m py_compile scripts\eval\run_dt_agent_live_eval.py test\dt_agent_live_eval_score_test.py
php -l api\agent\contracts\ReportIntegrityGate.php
php -l api\agent\orchestrator\AcademicAgentService.php
php -l test\report_integrity_gate_test.php
php -l test\agent_simple_preflight_gate_test.php
```

Residual risk:

- These canaries prove the repaired boundary cases, not broad semantic answer quality.
- Phase 5C should add semantic evaluation over saved and future live runs before making claims about Agent research-answer superiority.

## Phase 5C Semantic Evaluation Checks - 2026-05-26

Phase 5C added a deterministic semantic proxy over saved DT vs Agent runs. It does not call live APIs and does not modify Deep Think or Agent runtime routing.

Primary files:

- `scripts/eval/semantic_eval.py`
- `scripts/eval/run_dt_agent_live_eval.py`
- `test/dt_agent_semantic_eval_test.py`
- `test/dt_agent_live_eval_score_test.py`

Saved-run outputs:

- `docs/eval/runs/phase5b_flash_full/semantic_case_results.jsonl`
- `docs/eval/runs/phase5b_flash_full/semantic_summary.json`
- `docs/eval/runs/phase5b_flash_full/semantic_summary.md`
- `docs/eval/runs/phase5b_flash_full/semantic_analysis.md`

Verification commands:

```powershell
python test\dt_agent_live_eval_score_test.py
python test\dt_agent_semantic_eval_test.py
python -m py_compile scripts\eval\run_dt_agent_live_eval.py scripts\eval\semantic_eval.py test\dt_agent_live_eval_score_test.py test\dt_agent_semantic_eval_test.py
python scripts\eval\semantic_eval.py --run-dir docs\eval\runs\phase5b_flash_full
python scripts\eval\run_dt_agent_live_eval.py --rescore-existing --semantic-proxy --out-dir docs\eval\runs\phase5b_flash_full
python scripts/checks/check_docs_freshness.py
python scripts/checks/check_api_contracts.py
```

Phase 5B saved-run semantic proxy result:

- Agent wins: 13/30.
- Deep Think wins: 11/30.
- Ties: 6/30.
- Average claim support score: 0.726.
- Average citation relevance score: 0.562.
- Average missing-evidence handling score: 0.672.
- Average research usefulness score: 0.679.

Reliability interpretation:

- The semantic proxy gives stronger evidence than artifact counts alone, but it is still deterministic triage.
- It cannot verify biomedical truth or claim-level citation support.
- Deep Think remains the default immediate QA path.
- Agent remains appropriate for research-style synthesis candidates, especially when semantic proxy and manual review show added value.
- Test coverage includes no-network validation of the live runner main loop with `--semantic-proxy` and saved-run fallback behavior when `raw_events` files are absent.

## Agent Bilingual Prompt Library Checks - 2026-05-27

Agent prompt text is now centralized in `api/agent/config/agent_prompts.php`.

Runtime changes:

- `TekgAgentLlmClient` delegates prompt text to `TekgAgentPromptLibrary`.
- Agent prompts support Chinese and English branches for system, narrator, generic user, structured answer, evidence-walk draft, evidence-walk polish, direct answer, evidence summary, JSON system, sufficiency, answer structure, DeepThink routing, Cypher Explorer, and Literature Reading instructions.
- `tekg_agent_detect_language()` supports explicit Chinese aliases including `zh`, `zh-cn`, `zh_cn`, `中文`, `汉语`, and `漢語`.
- Payload-array language detection falls back to the `question` field when explicit language fields are absent.
- Agent and Deep Think process narration no longer force English; narration follows resolved answer/process language.

Verification commands:

```powershell
php test\agent_prompt_language_test.php
php test\agent_evaluation_report_runtime_test.php
php test\agent_simple_preflight_gate_test.php
php test\agent_research_report_prompt_test.php
php test\agent_evidence_package_runtime_test.php
php -l api\agent\bootstrap.php
php -l api\agent\orchestrator\LlmClient.php
php -l api\agent\config\agent_prompts.php
php -l api\agent\orchestrator\AcademicAgentService.php
php -l api\agent\orchestrator\DeepThinkService.php
php -l api\agent\orchestrator\traits\DeepThinkRoutingTrait.php
php -l api\agent\plugins\CypherExplorerPlugin.php
php -l api\agent\plugins\LiteratureReadingPlugin.php
php -l test\agent_prompt_language_test.php
```

Residual risk:

- No live LLM relay/API call was run for this prompt refactor.
- Tests verify prompt construction and language routing, not actual model output quality.

## 运行前提

- WAMP 由用户手动启动。
- Neo4j 由用户手动启动。
- 默认 base URL 是 `http://127.0.0.1/TE-`，可用 `TEKG_BASE_URL` 覆盖。

## G6 Export Reliability Notes - 2026-05-21

- G6 subgraph export v1 supports CSV export for the current visible nodes/edges and PNG export for the current canvas.
- SVG export was researched and archived in `docs/exec-plans/completed/g6-svg-export.md`.
- Current TE-KG browser G6 runtime is canvas-based. `Graph.prototype` exposes raster `toDataURL()` but does not expose reliable browser `toSVG`, `export`, or `exportToFile` APIs.
- SVG remains disabled in the Export menu as `SVG Soon`. Future options are a simplified SVG renderer or a separate `@antv/g6-ssr` export path, both outside v1 scope.

## PubMed Metadata Enrichment Notes - 2026-05-21

- PubMed metadata enrichment v1 writes fixture-verified metadata records to `data/processed/pubmed_metadata.jsonl`.
- The parser is standalone in `scripts/pubmed_metadata.py` and is not coupled to DeepSeek IE extraction or prompts.
- Parsed fields include PMID, DOI, title, abstract availability, journal metadata, publication dates/types, MeSH terms, authors, affiliations, source provider, and fetched timestamp.
- Impact Factor is not available from PubMed XML. `journal_metrics` is reserved for future external ISSN/year journal metric mapping; LLMs must not guess IF.
- `scripts/data_resource_update.py` currently has hardcoded local paths and a hardcoded DeepSeek API key risk. Treat it as non-canonical until paths are repo-relative and secrets are moved to environment variables.

## PubMed Metadata Full Fetch Notes - 2026-05-22

- Full read-only Neo4j PMID inventory for `tekg3` found 2308 unique PMIDs and wrote `data/processed/pubmed_pmids_inventory.txt`.
- PubMed canary fetch wrote 3/3 records to `data/processed/pubmed_metadata_canary.jsonl` with 0 failures.
- Full PubMed metadata fetch wrote 2308/2308 records to `data/processed/pubmed_metadata.jsonl`; `data/processed/pubmed_metadata_failures.jsonl` contains 0 failures.
- Full-output sanity check passed: DOI 2244, journal 2308, year 2308, authors 2307, MeSH 2087, keywords 827, grants 1064, chemicals 1888, references 1607.
- PubMed XML does not provide Impact Factor. Future IF support requires external `data/reference/journal_metrics.csv` keyed by `issn,year`.

## Journal Metric Mapping v1 Notes - 2026-05-22

- Journal metric mapping v1 is accepted for internal TE-KG use with `metric_source=impact_factor_package_2025` and `metric_name=Journal Impact Factor`.
- Source note: the mapping is generated from the local `reference/external_examples/impact_factor` package data, treated as 2025 data. It is not a direct official JCR export, but this project has approved it as a trusted internal journal metric source.
- Outputs: `data/reference/journal_metrics.csv`, `data/processed/journal_metrics_mapping_report.json`, and `data/processed/pubmed_metadata_with_metrics.jsonl`.
- Coverage: journal match rate 86.59% (594/686 unique journal rows); PMID match rate 88.26% (2037/2308 PubMed metadata records); unmatched PMID count 271.
- Unmatched records remain explicit null metrics in `pubmed_metadata_with_metrics.jsonl`; do not guess IF values or fill missing metrics with LLM output.
- Matching policy: eISSN exact -> print ISSN exact -> normalized title exact. `title_exact` fallback matched 22 journals and should be manually reviewed before publication or external presentation.
- No Neo4j import has been performed for journal metrics v1; graph/API/G6 behavior is unchanged.

## Neo4j Journal Metric Import Notes - 2026-05-22

- Paper enrichment v1 imported PubMed/journal metric fields onto 2308 existing `Paper` nodes with `journal_metrics_import_tag=journal_metrics_v1_2026-05-22`.
- Paper metric coverage in Neo4j: 2037 `Paper` nodes with `journal_metric_source=impact_factor_package_2025`; 271 `Paper` nodes keep null metric values.
- Relation aggregation v1 wrote derived support properties to 12444 existing `BIO_RELATION` relationships with `relation_metrics_import_tag=relation_metrics_v1_2026-05-22`.
- Relation aggregation dry-run/write evidence: 14770 raw PMID references, 2270 unique relation PMIDs, 2270 joinable PMIDs, 2003 PMIDs with metrics, 267 PMIDs without metrics.
- Relation support distributions: `support_pmid_count` buckets `{'1': 11705, '2': 434, '3-5': 219, '6-10': 53, '>10': 33}`; `support_metric_coverage` buckets `{'0': 1354, '(0.25,0.5]': 103, '(0.5,0.75]': 53, '(0.75,1)': 62, '1': 10872}`.
- IF aggregate range on relations: `support_if_mean` 0.3 to 90.2; `support_if_max` 0.3 to 90.2.
- No graph API or G6 visual encoding consumes these fields yet. Add API/G6 usage only in a separate active plan.

## G6 Evidence Support UX v1 Notes - 2026-05-22

- G6 evidence support UX v1 is completed and archived in `docs/exec-plans/completed/g6-evidence-support-ux-v1.md`.
- Graph API edge payloads now include eager `evidence_records` alongside existing `pmids` and `support_*` fields.
- `evidence_records` intentionally excludes abstracts and only carries PMID, PubMed URL, title, journal, publication year, journal metric value/source/year, JCR quartile, and metric match method.
- G6 edges use `support_pmid_count` for bounded edge width and `support_metric_coverage` for bounded opacity. Coverage `0` edges remain visible.
- Clicking an edge renders an evidence table in the existing detail area with PMID links, Year, Journal, IF, JCR, Match, and Title.
- Edges with more than 10 evidence records expose `Download CSV` for the selected edge evidence table.
- Verification: `check_graph_api_edge_evidence_records.py`, `check_g6_evidence_support_ux.py`, `check_graph_api_evidence_support.py`, `check_api_contracts.py`, and `check_g6_browser_smoke.py` passed.
- Residual risk: eager evidence payload is acceptable for current LINE1-sized graphs; future very large subgraphs may require a separate lazy endpoint if smoke/browser performance regresses.
- Follow-up placement update: evidence tables now render inside the expanded edge inspect card's `PubMed` section instead of the lower detail area. The lower detail area only keeps the edge summary/prompt, and `check_g6_evidence_support_ux.py` verifies no duplicate lower detail evidence table.
- Follow-up layout update: the legacy lower detail area is visually hidden again, and the card evidence table uses fixed column widths without horizontal scrolling. Journal and Title values may truncate in-cell but keep full `title` tooltip values and full CSV export values.

## G6 Node Action Card UX Notes - 2026-05-22

- G6 node action card UX is completed and archived in `docs/exec-plans/completed/g6-node-action-card-ux.md`.
- User-visible `Fixed view` and `Expand mode` controls are hidden while their internal compatibility state remains available.
- Clicking a node now opens the node action card only. It does not automatically jump to a new center graph and does not automatically expand.
- Node cards expose explicit `Jump`, `Expand`, and `Details` actions.
- `Jump` reuses the node-centered graph load path; `Expand` reuses the same-label-safe expand path with `expand_node_id`, `expand_node_type`, and `expand_query`; `Details` expands the node card without changing query or element count.
- Existing edge evidence card, PubMed evidence table, and selected-edge CSV download behavior remain unchanged.
- Verification: `check_g6_node_action_card_ux.py`, `check_g6_browser_smoke.py`, expand mode/layout/disambiguation smokes, evidence support UX smoke, and API contracts passed.
- Residual risk: legacy fixed/expand internal variables are intentionally retained for compatibility and should only be removed in a separate audited cleanup.
- Follow-up UI polish: node-card Expand overlay says `Expanding`, Jump closes the old action card, `Reset` is no longer user-visible, `Show labels` is now `Show relations`, off-state toolbar controls align with the Export button style, evidence table Journal/Title widths were adjusted, and collision radius offsets increased slightly from `46/38/34/30` to `50/42/38/34`.
- Follow-up loader amendment: retrotransposon and DNA transposon queries/nodes can show conservative mechanism-inspired inline SVG/CSS loaders using the current TE color, while unknown/non-TE cases keep the existing default loader. Expand copy remains `Expanding`; `check_g6_te_mechanism_loader.py` covers retro/default runtime behavior and DNA transposon diagnostic rendering.
- Follow-up loader visual refinement: mechanism loaders now render at `420x220`, use clearer source-to-target process animation, move RNA/label/RT as one retro complex, use semi-transparent TE-colored RNA, label target copies with the current TE short label, and draw SVG labels with black fill plus white stroke.
- Follow-up loader trigger/scale refinement: `LINE1` now explicitly classifies as retro for Search loading, mechanism loaders render at `560x300`, retro target DNA is static, and DNA transposon uses one moving TE segment instead of a duplicate inserted-copy group.
- Follow-up loader biology/visual refinement: source-to-target arrows were removed; target DNA is fixed and split into left/right gap segments; retro source DNA remains intact; DNA transposon donor DNA is split around the moving TE without slash cut marks; DNA transposon TE movement is lift then right/down transfer with no duplicate inserted-copy TE.
- Follow-up loader root-cause refinement: loader type detection now normalizes taxonomy/category phrases and plural forms, so classification-level labels such as `Class I: Retrotransposons` and `Class II: DNA transposons` use the mechanism loader when appropriate while unknown/non-TE labels keep the default loader. Retro inserted copy and DNA moving TE now align directly into the target DNA gap, and target opening arcs render behind TE labels.

## G6 TE Tree Load Regression Notes - 2026-05-22

- G6 TE tree load regression is completed and archived in `docs/exec-plans/completed/g6-te-tree-load-regression.md`.
- Taxonomy tree parsing now counts Unicode file-tree prefixes by UTF-8 character length. This restores missing deep parent edges in `api/taxonomy.php?view=tree&source=rmsk_repbase`; `L1HS` is connected under `Class I: Retrotransposons` again.
- Folded TE tree category nodes no longer jump into ordinary dynamic graph queries when clicked. Category nodes with real descendants stay in tree mode, avoiding stuck loaders for labels such as `Class I: Retrotransposons`, `Class II: DNA Transposons`, and `Others`.
- Mindmap initial positioning centers the root vertically by default instead of placing the tree near the top edge.
- Loader semantic normalization now handles plural TE family/order labels such as `SINEs`, `LINEs`, `LTRs`, `ERVs`, and `HERVs`.
- New regression coverage: `scripts/checks/check_g6_te_tree_load_regression.py` verifies taxonomy tree integrity, loader classification, direct graph loads for LINE1/L1HS/SINEs/category labels, category click behavior, restored L1HS path, and loader shutdown.
- Residual risk: direct graph search for category labels can still return empty graph payloads, and `SINEs` currently resolves to a single Paper node. Tree category clicks are guarded, but alias/category search semantics should be handled in a separate graph-query plan if needed.

## G6 / TE-KG Page Closeout Notes - 2026-05-22

- Current entry points remain `preview.php`, `api/graph.php`, `api/graph_service.php`, and `assets/js/renderers/g6/`.
- G6 resource loading is local-vendor based; browser smoke should not depend on external CDN availability for the current G6 runtime.
- The old global `Fixed view` / `Expand mode` controls are hidden. Node click now opens an action card, and the explicit card actions are `Jump`, `Expand`, and `Details`.
- `Expand` from the node action card keeps the current graph and uses the same-label-safe expand contract (`expand_node_id`, `expand_node_type`, `expand_query`). Internal `fixedView` / `expandModeEnabled` compatibility state is still retained.
- Edge evidence support is user-visible: edge width maps to `support_pmid_count`, edge opacity maps to `support_metric_coverage`, and zero-coverage edges remain visible.
- Edge click shows the evidence table inside the card `PubMed` section with PMID links and selected-edge CSV download when evidence rows exceed 10.
- TE mechanism loaders are mechanism-inspired UI only, not strict biological mechanism diagrams. Unknown/non-TE labels keep the default loader. Expand copy remains `Expanding`.
- TE tree load regression has been fixed: deep taxonomy edges are restored, folded category nodes stay in tree mode, and `SINEs` / Class I / Class II loader classification is covered.
- Data chain now in runtime use: PubMed metadata -> enriched `Paper` nodes -> `BIO_RELATION.support_*` aggregates -> graph API edge payload -> G6 evidence support UI.
- Core closeout verification on 2026-05-22 passed: PHP lint for `preview.php`, `api/graph.php`, `api/graph_service.php`; JS `node --check` for G6 bootstrap/shared/embed; API contracts; G6 browser smoke; node action card UX; evidence support UX; TE mechanism loader; TE tree load regression.
- Current residual risks: category-centered graph contract is not implemented; TE tree search/focus is not implemented; manual browser path loading regressions should be diagnosed with request/loader/iframe evidence before fixes; large future graph payloads may need an `evidence_records` lazy endpoint; journal metric `title_exact` matches and internal IF source should be reviewed/replaced before external publication claims.
