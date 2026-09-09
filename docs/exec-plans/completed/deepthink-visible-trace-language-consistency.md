# Deep Think visible trace language consistency

## 背景

Deep Think 的内部四阶段已经稳定为 `Understanding`、`Planning`、`Executing`、`Writing`，但用户可见 trace 仍混入 LLM 生成的漂移 reason、未本地化工具结果和错误文案。中文问题的 thinking 正文应使用中文，英文问题的 thinking 正文应使用英文；UI 外壳始终保持英文，同时科研原始数据不能被翻译或改写。

## 目标

- 保持内部 stable stage id 和展示 label 固定为英文。
- DT 标题固定为 `Deep thinking`，Agent 标题固定为 `Agent thinking`。
- `tool_selected` 保留原始 reason payload，但 UI 只显示双语确定性模板。
- `tool_result` 的用户可见 summary/message 使用双语 presentation 文案，raw payload 保持不变。
- 错误文案按问题语言展示。
- DT prompts 强制所有叙述字段和 `answer_markdown` 使用请求语言，并明确保护实体名、论文标题、URL、插件注册名、序列、关系类型和 raw data。
- 增加中文/英文聚焦 contract 测试。

## 不做什么

- 不删除本轮记录的 Agent direct site-navigation Writing 技术债。
- 不改插件业务查询、route map、URL integrity contract、taxonomy、图谱渲染或 Agent 六阶段栏。
- 不翻译 raw data。

## 涉及文件范围

- `docs/exec-plans/tech-debt-tracker.md`
- `api/agent/orchestrator/DeepThinkService.php`
- `api/agent/orchestrator/traits/DeepThinkRoutingTrait.php`
- `api/agent/orchestrator/AcademicAgentService.php`
- `api/agent/orchestrator/traits/AcademicAgentNarrationTrait.php`
- `api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php`
- `api/agent/bootstrap/run_store.php`
- `api/agent_run_execute.php`
- `api/agent/config/dt_node_prompts.php`
- `api/deep_think_stream.php`
- `assets/js/components/deepthink-client.js`
- `assets/js/pages/agent.js`
- `assets/js/components/side-deepthink.js`
- `assets/js/pages/preview/preview-deepthink.js`
- `scripts/checks/check_deepthink_frontend_state_contract.js`
- `test/deepthink_four_stage_contract_test.php`
- `test/deepthink_four_stage_runtime_test.php`
- `test/agent_narration_task_complexity_test.php`
- `test/agent_six_stage_runtime_test.php`

## 实施步骤

1. 扩展聚焦 contract 测试，覆盖 stage 展示 label、tool selected/result 双语 presentation、错误文案、prompt 语言锁和 raw payload 保留。
2. 运行聚焦测试并确认在当前实现上失败。
3. 在 DT routing trait 中生成稳定的双语 presentation 文案，将原始 reason 和科研详情留在 payload。
4. 在共享 DT client 中加入语言检测、固定英文 stage label 和错误展示 helper；让 agent、side、preview consumer 使用共享 helper。
5. 加强 DT node prompts 的请求语言锁和 raw data 保护约束。
6. 为 Agent deterministic narration、tool selected、reflection、synthesizing、node summary 和 Writing presentation error 增加双语正文模板。
7. 运行相关 PHP tests、Node contract、语法检查和 diff 检查。

## 验收标准

- 中文问题的 thinking 正文和错误提示为中文，英文问题的 thinking 正文和错误提示为英文。
- DT 与 Agent 标题、DT 四阶段栏和 Agent 六阶段栏始终为英文。
- stable stage id 保持 `Understanding`、`Planning`、`Executing`、`Writing`。
- LLM reason 仍保留在 payload，但不直接成为 UI narration。
- raw payload 中实体、论文标题、URL、插件注册名、序列和关系类型原样保留。
- 相关检查全部通过。

## 验证命令

```powershell
php test/deepthink_four_stage_contract_test.php
php test/deepthink_four_stage_runtime_test.php
node scripts/checks/check_deepthink_frontend_state_contract.js
node --check assets/js/components/deepthink-client.js
node --check assets/js/pages/agent.js
node --check assets/js/components/side-deepthink.js
node --check assets/js/pages/preview/preview-deepthink.js
php -l api/agent/orchestrator/traits/DeepThinkRoutingTrait.php
php -l api/agent/config/dt_node_prompts.php
git diff --check
```

## 执行记录

- 已在 `tech-debt-tracker.md` 记录 disabled direct site-navigation Writing branch 技术债。
- 后端 `stage_state` 新增固定英文 `display_label` 和请求 `language`，stable stage id 保持不变。
- `tool_selected` 可见叙述改为双语确定性模板，原始 LLM reason 保留在 `payload.selection_reason`。
- `tool_result` 可见 `summary/message` 改为双语 presentation 文案，科研详情继续保留在 payload。
- 服务层和 endpoint 层错误事件新增按请求语言生成的 presentation 文案，raw `failure_reason` 保留用于诊断。
- 共享 DT client 增加语言检测、stage label、状态文本和错误文案 helper，Agent、side、preview consumer 统一复用。
- DT node prompts 增加请求语言锁和 raw data 保护约束。
- Agent deterministic narration 和 Writing presentation error 增加双语正文模板，raw `failure_reason` 保留用于诊断。
- 聚焦测试先在旧实现上失败，再在实现后通过。

## 残留风险

- `AcademicAgentService::buildDirectSiteNavigationWritingResult()` 清理仍按 tracker 中独立技术债处理。
- 本轮未执行 live LLM/browser smoke；确定性 PHP/Node contract 已覆盖 presentation contract。

## 验证结果

- `php test/deepthink_four_stage_contract_test.php`：通过。
- `php test/deepthink_four_stage_runtime_test.php`：通过。
- `php test/deepthink_relationship_synthesis_test.php`：通过。
- `php test/deepthink_sequence_local_answer_test.php`：通过。
- `php test/agent_prompt_language_test.php`：通过。
- `php test/side_deepthink_shell_test.php`：通过。
- `node scripts/checks/check_deepthink_frontend_state_contract.js`：通过。
- `node scripts/checks/check_side_deepthink_contract.js`：通过。
- 四个相关 JS 文件 `node --check`：通过。
- 四个相关 PHP runtime 文件和两个测试文件 `php -l`：通过。
- `git diff --check`：通过，仅输出工作区 LF/CRLF 提示。
