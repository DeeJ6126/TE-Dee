# TE-KG Agent Handoff for a New Codex Session

## 1. Purpose

This document is a handoff package for a **new Codex conversation** focused on the TE-KG intelligent agent system.

It is intentionally scoped to:

- `Deep Think`
- `Agent`
- plugin execution
- LLM calling
- async run orchestration
- frontend agent experience

It intentionally avoids drifting into unrelated work such as:

- homepage visualization polishing
- BioRender / figure generation
- TE taxonomy UI cleanup
- general content page styling

Those areas exist in the repo, but they are not the primary target for the new conversation.

---

## 2. Project Snapshot

Project root:

- `D:\wamp64\www\TE-`

This is a PHP web application for **transposable element (TE)** exploration, search, graph-backed QA, and AI-assisted reasoning over a TE knowledge graph.

The system currently exposes two AI interaction modes on the same page:

- `Deep Think`
- `Agent`

High-level user-facing entry points:

- [agent.php](D:/wamp64/www/TE-/agent.php)
- [search.php](D:/wamp64/www/TE-/search.php)
- [genomic.php](D:/wamp64/www/TE-/genomic.php)
- [expression.php](D:/wamp64/www/TE-/expression.php)
- [browse.php](D:/wamp64/www/TE-/browse.php)
- [download.php](D:/wamp64/www/TE-/download.php)
- [agent_workflow_lab.php](D:/wamp64/www/TE-/agent_workflow_lab.php)

---

## 3. Current Agent / Deep Think Architecture

### Deep Think

Deep Think is the lighter mode.

Characteristics:

- single-model orchestration
- plugin-augmented reasoning
- shows `Deep thinking`
- does **not** show the six-node workflow
- uses streamed events
- already has deterministic shortcuts for some simple tasks such as:
  - full sequence output
  - full-list relationship output

Core backend files:

- [deep_think_stream.php](D:/wamp64/www/TE-/api/deep_think_stream.php)
- [DeepThinkService.php](D:/wamp64/www/TE-/api/agent/orchestrator/DeepThinkService.php)
- [LlmClient.php](D:/wamp64/www/TE-/api/agent/orchestrator/LlmClient.php)

Important historical note:

- `qa.php` is no longer the main homepage path for the lightweight mode.
- Deep Think replaced the old Quick QA idea.

### Agent

Agent is the heavier mode.

Characteristics:

- multi-stage orchestration
- visible six-node workflow
- structured evidence collection
- heavier synthesis / writing path

Six visible stages:

1. `Understanding`
2. `Planning`
3. `Collecting`
4. `Executing`
5. `Integrating`
6. `Writing`

Core backend files:

- [AcademicAgentService.php](D:/wamp64/www/TE-/api/agent/orchestrator/AcademicAgentService.php)
- [LlmClient.php](D:/wamp64/www/TE-/api/agent/orchestrator/LlmClient.php)
- [bootstrap.php](D:/wamp64/www/TE-/api/agent/bootstrap.php)

Current runtime model:

- Agent no longer depends on the old single long-lived SSE request.
- It now uses **async run + worker**.

Async run files:

- [agent_runs.php](D:/wamp64/www/TE-/api/agent_runs.php)
- [agent_run_status.php](D:/wamp64/www/TE-/api/agent_run_status.php)
- [agent_run_worker.php](D:/wamp64/www/TE-/api/agent_run_worker.php)
- [agent_run_execute.php](D:/wamp64/www/TE-/api/agent_run_execute.php)
- [agent_run_kickoff.php](D:/wamp64/www/TE-/api/agent_run_kickoff.php)

Frontend wiring:

- [agent.php](D:/wamp64/www/TE-/agent.php)
- [agent.js](D:/wamp64/www/TE-/assets/js/pages/agent.js)
- [agent.css](D:/wamp64/www/TE-/assets/css/pages/agent.css)

Important verified frontend config:

- `Agent` run create URL comes from `agent_runs.php`
- `Agent` run status URL comes from `agent_run_status.php`
- `Deep Think` stream URL comes from `deep_think_stream.php`

---

## 4. Current Runtime Model Configuration

Current effective runtime config is in:

- [config.local.php](D:/wamp64/www/TE-/api/config.local.php)

Current values:

- `dashscope_model = deepseek-v4-flash`
- `agent_writing_model = deepseek-v4-flash`
- `deepseek_model = deepseek-v4-flash`
- `deepseek_reasoner_model = deepseek-v4-flash`
- `llm_answer_chat_timeout = 40`

Important nuance:

- [bootstrap.php](D:/wamp64/www/TE-/api/agent/bootstrap.php) still contains fallback defaults such as `qwen3.5-35b-a3b`, `deepseek-chat`, and `deepseek-reasoner`.
- Those are fallback values only.
- The current runtime should be reading the explicit values from `config.local.php`.

The new session should still verify the actual runtime path instead of assuming this is fully clean.

---

## 5. Current Known State of the Agent System

### What has already been improved

- The old problem where async Agent runs failed before startup was addressed by:
  - moving startup acknowledgement earlier
  - adding a local kickoff path
  - reducing reliance on a fragile startup chain

- The old Quick QA path was replaced by Deep Think.

- Deep Think was simplified for simple tasks:
  - deterministic full sequence rendering
  - deterministic full-list relationship rendering
  - shorter tool chains for simple intents

### What remains the main risk

The main remaining Agent risk is still the **Writing** stage.

Historically observed behavior:

- plugin stages can finish
- the run reaches `Writing`
- final answer generation is the slowest node
- increasing `llm_answer_chat_timeout` from the old lower value to `40` helped some failures

Prior experiment:

- Agent `Writing` was temporarily switched to `qwen3.6-flash-2026-04-16`
- that did **not** solve the core timeout problem
- model config was later unified back to `deepseek-v4-flash`

Working hypothesis:

- the main bottleneck is not just model choice
- it is likely a combination of:
  - Writing payload size
  - prompt structure
  - answer generation path complexity
  - heavy evidence bundle passed into final writing

### Important caution on plugin diagnosis

There was at least one prior round of plugin diagnosis where Neo4j was not running.

That means:

- any old conclusion that `GraphPlugin`, `GraphAnalyticsPlugin`, or `CypherExplorerPlugin` failed may be contaminated by missing infrastructure
- the new session must confirm Neo4j is running before drawing conclusions

---

## 6. Plugin Map

Current plugin files:

- [CitationResolverPlugin.php](D:/wamp64/www/TE-/api/agent/plugins/CitationResolverPlugin.php)
- [CypherExplorerPlugin.php](D:/wamp64/www/TE-/api/agent/plugins/CypherExplorerPlugin.php)
- [EntityResolverPlugin.php](D:/wamp64/www/TE-/api/agent/plugins/EntityResolverPlugin.php)
- [ExpressionPlugin.php](D:/wamp64/www/TE-/api/agent/plugins/ExpressionPlugin.php)
- [GenomePlugin.php](D:/wamp64/www/TE-/api/agent/plugins/GenomePlugin.php)
- [GraphAnalyticsPlugin.php](D:/wamp64/www/TE-/api/agent/plugins/GraphAnalyticsPlugin.php)
- [GraphPlugin.php](D:/wamp64/www/TE-/api/agent/plugins/GraphPlugin.php)
- [LiteraturePlugin.php](D:/wamp64/www/TE-/api/agent/plugins/LiteraturePlugin.php)
- [LiteratureReadingPlugin.php](D:/wamp64/www/TE-/api/agent/plugins/LiteratureReadingPlugin.php)
- [SequencePlugin.php](D:/wamp64/www/TE-/api/agent/plugins/SequencePlugin.php)
- [TreePlugin.php](D:/wamp64/www/TE-/api/agent/plugins/TreePlugin.php)

Practical grouping:

- entry / normalization:
  - `EntityResolverPlugin`
- local graph / topology:
  - `GraphPlugin`
  - `GraphAnalyticsPlugin`
  - `CypherExplorerPlugin`
- domain lookup:
  - `SequencePlugin`
  - `GenomePlugin`
  - `ExpressionPlugin`
  - `TreePlugin`
- evidence:
  - `LiteraturePlugin`
  - `LiteratureReadingPlugin`
  - `CitationResolverPlugin`

---

## 7. Important Product Intent

The intended difference between the two modes is:

### Deep Think

- faster
- lighter
- tool-augmented
- suitable for:
  - sequence lookup
  - ordinary relationship lookup
  - classification
  - expression lookup
  - simple genome questions

### Agent

- heavier
- more structured
- should be reserved for:
  - mechanism questions
  - literature support / evidence comparison
  - graph analytics / ranking / topology
  - multi-step evidence collection and synthesis

This distinction matters because earlier failures often came from sending simple tasks through overly heavy paths.

---

## 8. Broader Project Context Worth Understanding

The new session should not stay trapped inside only the agent files. Some broader project context materially helps.

### Core product pages worth scanning

- [search.php](D:/wamp64/www/TE-/search.php)
- [genomic.php](D:/wamp64/www/TE-/genomic.php)
- [expression.php](D:/wamp64/www/TE-/expression.php)
- [browse.php](D:/wamp64/www/TE-/browse.php)
- [download.php](D:/wamp64/www/TE-/download.php)
- [index.php](D:/wamp64/www/TE-/index.php)

Reason:

- they show how TE entities are surfaced elsewhere in the product
- they help anchor what users actually expect from entity names, search behavior, and answer types

### Prompt and configuration files worth scanning

- [api/prompts](D:/wamp64/www/TE-/api/prompts)
- [agent_workflow_lab.php](D:/wamp64/www/TE-/agent_workflow_lab.php)
- [api/agent/config](D:/wamp64/www/TE-/api/agent/config)

Reason:

- these reveal existing prompting style and workflow assumptions

### Data and domain files worth scanning lightly

- [tekg2_seed.json](D:/wamp64/www/TE-/data/processed/tekg2/tekg2_seed.json)
- [data/statistics](D:/wamp64/www/TE-/data/statistics)
- [transposon_tree](D:/wamp64/www/TE-/transposon_tree)

Reason:

- recent TE name normalization and classification cleanup happened there
- those changes can affect entity resolution, plugin lookup, and output interpretation

The new session does **not** need to fix taxonomy work first, but should know it exists.

---

## 9. Recent Data Cleanup That May Affect Agent Behavior

This is not the main focus of the new conversation, but it is relevant context.

Recent work modified TE naming and TE classification sources so that:

- tree names were pulled closer to database entity names
- some TE aliases were merged
- some problematic TE-like entries were reclassified or excluded

Examples of data-side changes already applied:

- `L1` merged into `LINE-1`
- `HERV-L` and `HERVL` merged into `ERVL`
- `retroelement` merged into `retroposon`
- `HSATII` moved out of TE handling
- `L1Md-A5` moved out of human TE handling

This matters for the new session because:

- entity resolution and plugin lookups may be affected by renamed or merged TE entities
- not every surprising answer is an agent bug; some are data model side-effects

---

## 10. Recommended Scan Order for the New Session

The new session should do this in order:

1. Read the main page and frontend wiring:
   - [agent.php](D:/wamp64/www/TE-/agent.php)
   - [agent.js](D:/wamp64/www/TE-/assets/js/pages/agent.js)
   - [agent.css](D:/wamp64/www/TE-/assets/css/pages/agent.css)

2. Read current runtime config:
   - [config.local.php](D:/wamp64/www/TE-/api/config.local.php)
   - [bootstrap.php](D:/wamp64/www/TE-/api/agent/bootstrap.php)

3. Read the two orchestration services:
   - [AcademicAgentService.php](D:/wamp64/www/TE-/api/agent/orchestrator/AcademicAgentService.php)
   - [DeepThinkService.php](D:/wamp64/www/TE-/api/agent/orchestrator/DeepThinkService.php)
   - [LlmClient.php](D:/wamp64/www/TE-/api/agent/orchestrator/LlmClient.php)

4. Read async run plumbing:
   - [agent_runs.php](D:/wamp64/www/TE-/api/agent_runs.php)
   - [agent_run_status.php](D:/wamp64/www/TE-/api/agent_run_status.php)
   - [agent_run_worker.php](D:/wamp64/www/TE-/api/agent_run_worker.php)
   - [agent_run_execute.php](D:/wamp64/www/TE-/api/agent_run_execute.php)
   - [agent_run_kickoff.php](D:/wamp64/www/TE-/api/agent_run_kickoff.php)

5. Read plugin implementations:
   - every file in [api/agent/plugins](D:/wamp64/www/TE-/api/agent/plugins)

6. Scan broader product pages and supporting data to understand domain expectations.

Only after that should the new session propose changes.

---

## 11. Recommended First Diagnostics

The new session should first verify the current system state, not assume prior conclusions are still accurate.

Suggested test prompts:

- `L1HS的序列是什么`
- `L1HS和哪些疾病相关`
- `L1HS属于哪个亚家族`
- `L1HS在哪些组织表达`
- `L1HS位于哪里`
- `现在知识图谱里面，哪一个疾病与转座子关联度最大？`
- `What papers support LINE-1 and Alzheimer's disease?`
- `LINE-1是如何导致癌症的？`

For each test, record:

- `intent`
- plugin sequence
- current stage
- whether Neo4j is running
- whether plugin output was valid
- whether final answer failed
- whether failure happened in `Writing`
- answer length if successful

---

## 12. English Prompt for the New Codex Session

Use the following prompt in the new conversation.

```text
You are a new Codex session working on the TE-KG project at:

D:\\wamp64\\www\\TE-

Your task is to focus on the intelligent agent system only:

- Deep Think
- Agent
- plugin execution
- LLM invocation
- async run orchestration
- frontend agent experience

Important communication rule:

- Use Chinese when talking to the user.
- Keep code, identifiers, comments, and implementation text as non-Chinese as reasonably possible unless Chinese is already clearly required by the existing file.

Before proposing changes, scan the project in this order:

1. Frontend entry:
   - agent.php
   - assets/js/pages/agent.js
   - assets/css/pages/agent.css

2. Runtime config:
   - api/config.local.php
   - api/agent/bootstrap.php

3. Orchestration core:
   - api/agent/orchestrator/AcademicAgentService.php
   - api/agent/orchestrator/DeepThinkService.php
   - api/agent/orchestrator/LlmClient.php

4. Async Agent run pipeline:
   - api/agent_runs.php
   - api/agent_run_status.php
   - api/agent_run_worker.php
   - api/agent_run_execute.php
   - api/agent_run_kickoff.php

5. Plugins:
   - every file under api/agent/plugins

6. Broader project context that helps interpret agent behavior:
   - search.php
   - genomic.php
   - expression.php
   - browse.php
   - download.php
   - index.php
   - agent_workflow_lab.php
   - api/prompts/*
   - data/processed/tekg2/tekg2_seed.json
   - data/statistics/*
   - transposon_tree/*

Do not immediately modify code. First produce a concise diagnosis report based on the current codebase and a few real tests.

Current known context:

- The project has two modes: Deep Think and Agent.
- Deep Think is a lighter single-model tool-augmented mode with Deep thinking but no six-stage workflow.
- Agent is a heavier multi-stage mode with six visible stages:
  Understanding, Planning, Collecting, Executing, Integrating, Writing.
- Agent now uses async run + worker instead of the old direct long-lived stream.
- The main remaining suspected bottleneck is the Writing stage.
- A previous experiment switching Agent Writing to qwen did not solve the core timeout issue.
- Runtime config was later unified back to deepseek-v4-flash.
- Current config.local.php indicates:
  - dashscope_model = deepseek-v4-flash
  - agent_writing_model = deepseek-v4-flash
  - deepseek_model = deepseek-v4-flash
  - deepseek_reasoner_model = deepseek-v4-flash
  - llm_answer_chat_timeout = 40
- Old plugin failure conclusions may be contaminated by one earlier session where Neo4j was not running.

Your first job:

1. Verify the actual runtime model resolution path.
2. Verify whether Agent Writing still uses answer_structure and how heavy its final payload is.
3. Verify whether simple tasks are still being routed through unnecessarily heavy paths.
4. Verify whether Deep Think and Agent are aligned with their intended product roles.
5. Confirm Neo4j is running before diagnosing Graph / GraphAnalytics / CypherExplorer behavior.

Then run targeted tests such as:

- L1HS的序列是什么
- L1HS和哪些疾病相关
- L1HS属于哪个亚家族
- L1HS在哪些组织表达
- L1HS位于哪里
- 现在知识图谱里面，哪一个疾病与转座子关联度最大？
- What papers support LINE-1 and Alzheimer's disease?
- LINE-1是如何导致癌症的？

For each test, record:

- intent
- used plugins
- stage progression
- whether plugin output was valid
- whether the answer failed
- whether failure happened in Writing
- answer length if successful

Only after that, propose the smallest high-value fixes first.

Avoid spending time on unrelated homepage polish, BioRender, taxonomy UI cleanup, or non-agent content pages unless they directly affect the agent diagnosis.
```

---

## 13. Practical Recommendation

The best use of the new session is:

- fresh scan of the current codebase
- fresh runtime verification
- real API tests
- focused diagnosis before any new refactor

That is more valuable than carrying over every old assumption.
