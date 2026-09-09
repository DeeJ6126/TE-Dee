# Intelligent QA Maintenance Workflow

Use this workflow for Agent, DeepThink, plugin, LLM prompt, and intelligent QA
frontend changes.

## Default Process

1. Read `api/README.md` and `api/docs/intelligent_qa_handoff.md`.
2. Inspect live code before trusting older architecture notes.
3. Create or update an execution plan for complex behavior changes.
4. Use subagents when the task has separable investigation, implementation, or
   review slices.
5. Convert useful subagent output into durable docs, tests, scripts, or eval
   records.
6. Run static checks before claiming completion.
7. Run live checks only when WAMP, Neo4j, MySQL, and the LLM relay are available.

## Recommended Roles

- Explorer: read-only investigation of implementation, tests, docs, or eval
  evidence.
- Worker: bounded edits with explicit file ownership.
- Reviewer: bug, regression, and architecture-risk review.
- Verifier: command execution and evidence capture.

The main AI owns scope, decisions, integration, and final verification. Subagent
output is evidence, not a replacement for final review.

## When To Use Subagents

Use subagents for:

- Comparing implementation against documentation.
- Reviewing plugin evidence boundaries.
- Checking frontend event/state behavior.
- Running independent static or live verification.
- Updating separate docs with disjoint ownership.

Do not use subagents when:

- The task is a tiny one-file clarification.
- The next step requires the main AI's accumulated project context.
- Parallel edits would conflict in the same files.

## Documentation Rules

- New intelligent QA markdown lives under `api/docs/` unless it is a plugin
  document under `api/agent/plugins/`.
- `api/README.md` stays short and index-like.
- `api/docs/intelligent_qa_handoff.md` is the current subsystem handoff.
- Historical docs under root `docs/` are archive/background unless a current
  entry links them explicitly.
- New markdown should be English.

## Change Boundaries

Do not mix unrelated work:

- Agent/DeepThink behavior changes should not silently alter ordinary database
  pages.
- Plugin routing changes should not silently alter graph rendering.
- Frontend thinking/status changes should not alter backend evidence semantics.
- Documentation-only work should not change runtime PHP or JavaScript.
