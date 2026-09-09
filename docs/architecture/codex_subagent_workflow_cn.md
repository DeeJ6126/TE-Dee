# Codex 母智能体与子智能体使用指南

本文记录在 TE-KG 项目中如何使用 Codex 的母智能体 / 子智能体工作流。它是工作方法说明，不是产品需求。

## 什么时候适合使用

当一个任务可以拆成“调查、实现、验证、审查”几个相对独立的部分时，适合使用母智能体协调子智能体。

适合：

- G6 问题可能同时涉及 API、iframe bridge、loader state、legend state、浏览器渲染。
- API contract 改动后还需要前端 smoke 验证。
- 数据导入任务需要 preflight、dry-run、write、verification、rollback。
- 大型文档或 harness 更新，需要多份文档保持一致。

不适合：

- 小文案修改。
- 单文件 CSS 微调。
- 一个很明确的 check 失败。
- 多个 worker 必须同时改同一批高耦合文件的任务。

## 角色分工

### Coordinator：母智能体

母智能体负责控制任务边界。它应该读取 harness、判断是否需要子智能体、分派任务、整合结果、运行或安排验证，并决定是否归档。

母智能体职责：

- 先读 `AGENTS.md`、架构文档、active plan、相关 completed plan。
- 复杂任务先创建或更新 `docs/exec-plans/active/...`。
- 明确每个子智能体的文件边界。
- 避免多个 worker 同时修改同一个文件。
- 审查子智能体结果，而不是直接照单全收。
- 只有验证通过后才归档。

母智能体可以改文件，但大型任务中更适合做计划、整合、审查和最终验证，不应该把所有实现细节都塞进自己的上下文。

### Explorer：只读调查员

Explorer 只读，不改文件。适合用来回答具体事实问题。

好的 Explorer 任务：

- “找出节点点击后 action card、Jump、Expand、Details 分别在哪些函数中处理。不要改文件。”
- “检查 graph API 的 edge payload 在哪里构造，列出 `BIO_RELATION` 返回字段。”
- “确认 `AGENTS.md`、`ARCHITECTURE.md`、`docs/RELIABILITY.md`、`docs/exec-plans/`、`scripts/checks/` 是否存在。”

避免模糊任务，例如“找 harness 文件”。应该明确写出要找的文件或概念。

### Worker：实现者

Worker 负责有明确边界的实现。给 Worker 的任务必须有清楚的写入范围。

Worker 提示词应包含：

- 负责的文件或模块。
- 明确目标。
- 明确非目标。
- 必须运行的检查。
- 不要回滚用户或其他 agent 改动的提醒。

不要让多个 Worker 同时改 `assets/js/renderers/g6/index-g6.bootstrap.js` 这类高耦合文件。

### Verifier：验证员

Verifier 只跑检查并报告证据。除非明确重新授权为 Worker，否则不应该顺手修代码。

适合的 Verifier 任务：

- 跑 browser smoke。
- 跑 API contract。
- 报告 console errors、failed requests、loader state、iframe canvas/children、命令结果。

### Reviewer：审查员

Reviewer 检查 diff 和风险。它的输出应该先列问题，而不是先写总结。

Reviewer 应重点检查：

- 是否改了范围外文件。
- 是否破坏 API contract。
- 是否影响 G6 loader / iframe / legend state。
- 是否缺少检查。
- 是否用了硬编码个例补丁。
- 是否真的满足归档条件。

## 默认协作流程

中大型 TE-KG 任务建议按这个顺序：

1. 母智能体读取 harness，创建或更新 active plan。
2. Explorer A 调查前端或 G6 路径。
3. Explorer B 调查 API / 数据 / checks，如果任务涉及这些部分。
4. 母智能体整合调查结果，收窄实现范围。
5. 一个 Worker 做有边界的实现。
6. Verifier 运行指定检查。
7. Reviewer 检查 diff 和风险。
8. 母智能体更新文档；如果验证通过则归档。

小任务可以跳过子智能体，直接由母智能体完成。

## 文件边界规则

- 不要让两个 Worker 并行修改同一个文件。
- API、G6 前端、docs、checks 尽量拆成独立工作范围。
- 数据导入任务要拆分为脚本、检查、文档、数据库写入步骤。
- Neo4j 写入必须有 dry-run 和 rollback。

## 验证规则

G6 工作常用检查：

```powershell
php -l preview.php
php -l api/graph.php
php -l api/graph_service.php
node --check assets/js/renderers/g6/index-g6.bootstrap.js
node --check assets/js/renderers/g6/index-g6-shared.js
node --check assets/js/renderers/g6/index-g6-embed.js
python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_browser_smoke.py
```

根据任务追加专项检查，例如：

- `check_g6_node_action_card_ux.py`
- `check_g6_evidence_support_ux.py`
- `check_g6_te_mechanism_loader.py`
- `check_g6_te_tree_load_regression.py`

数据或 Neo4j 工作建议采用：

- preflight check
- dry-run
- 显式 `--write`
- post-import verification
- rollback preview

## 归档规则

如果所有计划内验证通过：

- 将 active plan 移到 `docs/exec-plans/completed/`。
- 记录改动文件、命令、结果、残留风险和下一步。
- 更新 `docs/RELIABILITY.md`。
- 如果有残留风险，更新 `docs/exec-plans/tech-debt-tracker.md`。

如果验证失败：

- 不归档。
- 保留 active plan。
- 记录失败命令、失败层、已完成项和下一步建议。

## 推荐的母智能体开场提示词

```text
请把这个对话作为 TE-KG coordinator / mother agent 使用。先读取 harness。需要时使用 explorer 做只读调查，worker 做有边界实现，verifier 跑检查，reviewer 查风险。除非任务确实适合并行，否则不要滥用子智能体。不要让多个 worker 改同一文件。只有验证通过后才归档。
```

