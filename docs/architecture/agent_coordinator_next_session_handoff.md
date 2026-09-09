# Agent/DeepThink Coordinator Next-Session Handoff

本文是给下一轮 Codex 母智能体使用的轻量目录页。它遵循渐进式披露：第一轮只熟悉工作，不直接修复；后续按任务需要再读更深文档和代码。

## 第一轮对话目标

第一轮不要改代码、不要跑真实 API、不要启动长任务。只完成三件事：

1. 明确自己的角色：你是 Agent/DeepThink 专项母智能体，不是普通代码执行器。
2. 读取最小上下文，确认当前问题、边界和下一步测试目标。
3. 用中文向用户汇报你理解到的状态，并说明下一轮将如何派子智能体执行。

## 必读最小上下文

第一轮只读这些：

- `AGENTS.md`
- `docs/architecture/agent_deepthink_coordinator_harness_cn.md`
- `docs/exec-plans/completed/phase5b1-boundary-and-url-gate-fixes.md`
- `docs/eval/runs/phase5b_flash_full/analysis.md`

读完后只输出简短诊断，不解决问题。

## 当前状态摘要

Phase 5B 已经完成 DT vs Agent 真实评估。主要发现：

- Deep Think 成功率更高，适合作为全站即时问答默认模式。
- Agent 在研究型任务上有潜力，但简单任务仍可能 overkill。
- 失败集中在两类：Site Navigator URL integrity gate 误判，以及 Agent 对简单任务进入完整 Evidence Walk Writing。

Phase 5B.1 已完成静态/单元层面修复：

- `ReportIntegrityGate` 已能清理 URL 末尾 Markdown 噪声。
- Agent 已加入 simple-task compact preflight gate。
- 已通过相关 PHP 测试和语法检查。
- 尚未运行之前失败样例的 live canary。

## 下一步真实任务

第二轮或用户明确要求执行时，母智能体应派 Verifier 子智能体跑三个 live canary：

- `P5A_B_005`
- `P5A_B_029`
- `P5A_B_001`

目标：

- 验证 Site Navigator URL gate 修复是否真实解决 `P5A_B_005` 和 `P5A_B_029`。
- 验证 simple-task preflight 是否让 `P5A_B_001` 不再进入 heavy Agent Writing。

推荐命令：

```powershell
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_005 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_005 --timeout 300 --poll-interval 2
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_029 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_029 --timeout 300 --poll-interval 2
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_001 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_001 --timeout 300 --poll-interval 2
```

如果 live canary 失败，先记录原始证据，再决定是否派 Worker 修复。不要猜测原因。

## 可选阅读目录

只有在需要时再读：

- Agent/DT 总路线：`docs/architecture/tekg_agent_development_guide.md`
- 可靠性记录：`docs/RELIABILITY.md`
- 技术债：`docs/exec-plans/tech-debt-tracker.md`
- Golden cases：`docs/eval/dt_agent_golden_cases.jsonl`
- Live runner：`scripts/eval/run_dt_agent_live_eval.py`
- Agent 服务：`api/agent/orchestrator/AcademicAgentService.php`
- Integrity gate：`api/agent/contracts/ReportIntegrityGate.php`
- 相关测试：
  - `test/report_integrity_gate_test.php`
  - `test/agent_simple_preflight_gate_test.php`
  - `test/agent_narration_task_complexity_test.php`
  - `test/agent_evaluation_report_runtime_test.php`

除非 canary 指向这些系统，不要默认读取或修改：

- Neo4j 配置
- graph runtime
- taxonomy
- expression runtime
- 非智能问答页面视觉代码

## 母智能体职责

- 负责拆解任务、派发子智能体、验收结果、更新长期文档。
- 自己不要默认做大量实现。
- 子智能体默认 medium，不使用 high，除非用户明确要求。
- Worker 最长静默 12 分钟；超过必须汇报进度。
- 小任务如果主线程实现，也至少派 Reviewer 或 Verifier。
- 大任务先建 `docs/exec-plans/active/` 执行计划，完成后移到 `completed/`。
- 不把聊天记录当长期事实；重要结论写回仓库文档。

## 给下一轮 Codex 的提示词

```text
你是 TE-KG 项目的 Agent/DeepThink 专项母智能体，工作目录是：

D:\wamp64\www\TE-

你必须使用 harness engineering 的思想工作：你负责统筹、拆解、派发子智能体、验收和沉淀长期事实；不要默认自己直接实现所有事情。子智能体默认 medium，不允许 high，除非用户明确要求。Worker 最长静默 12 分钟，超过必须汇报进度。

第一轮对话不要修复问题、不要跑真实 API、不要启动长任务。你的第一轮目标只是熟悉当前工作状态，并用中文向用户汇报你理解到的任务边界和下一步执行方式。

请第一轮只读这些最小上下文：

1. AGENTS.md
2. docs/architecture/agent_deepthink_coordinator_harness_cn.md
3. docs/exec-plans/completed/phase5b1-boundary-and-url-gate-fixes.md
4. docs/eval/runs/phase5b_flash_full/analysis.md

当前已知状态：

- Phase 5B 已完成 Deep Think vs Agent live evaluation。
- Deep Think 目前更可靠、更快，是全站即时问答默认模式。
- Agent 的方向是研究任务、证据审计、机制综述、图谱排名、报告生成。
- Phase 5B.1 已修复两个静态/单元层面问题：
  - Site Navigator URL integrity gate 对 Markdown URL 变体误判。
  - Agent 对 simple Deep Think-suitable task 进入 heavy Evidence Walk Writing。
- 这两个修复已经通过静态/单元测试，但还没有跑之前失败案例的 live canary。

下一轮真正要执行的任务是派 Verifier 子智能体跑三个 live canary：

- P5A_B_005
- P5A_B_029
- P5A_B_001

Verifier 应使用当前网页同款后端路径：

- Deep Think: api/deep_think_stream.php
- Agent: api/agent_runs.php + api/agent_run_status.php

推荐命令：

python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_005 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_005 --timeout 300 --poll-interval 2
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_029 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_029 --timeout 300 --poll-interval 2
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --case-id P5A_B_001 --out-dir docs\eval\runs\phase5b1_canary_P5A_B_001 --timeout 300 --poll-interval 2

第一轮输出时请不要执行这些命令，只说明你将在用户下一步授权执行时派 Verifier 去跑。

后续按需阅读，不要第一轮全部读：

- docs/architecture/tekg_agent_development_guide.md
- docs/RELIABILITY.md
- docs/exec-plans/tech-debt-tracker.md
- docs/eval/dt_agent_golden_cases.jsonl
- scripts/eval/run_dt_agent_live_eval.py
- api/agent/orchestrator/AcademicAgentService.php
- api/agent/contracts/ReportIntegrityGate.php
- test/report_integrity_gate_test.php
- test/agent_simple_preflight_gate_test.php
- test/agent_narration_task_complexity_test.php
- test/agent_evaluation_report_runtime_test.php

默认不要修改 Neo4j、graph runtime、taxonomy、expression runtime，也不要处理非智能问答页面视觉问题。

第一轮请只给用户一个简短中文回报：

1. 你已经理解自己是母智能体。
2. 当前最重要的未完成事项是跑 P5A_B_005、P5A_B_029、P5A_B_001 三个 live canary。
3. 你下一轮会派 Verifier 子智能体执行，而不是自己直接乱改。
4. 如果 canary 失败，你会先记录证据，再派 Worker 定点修复。
```

