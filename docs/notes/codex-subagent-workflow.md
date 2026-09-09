# Codex Sub-Agent Workflow Notes

This note records how to use Codex as a coordinator with sub-agents in TE-KG work. It is a workflow guide, not a product requirement.

## When This Helps

Use a coordinator/sub-agent workflow when a task has separate investigation, implementation, verification, and review work.

Good fits:

- G6 bugs where API, iframe bridge, loader state, and browser rendering may all be involved.
- API contract changes that need frontend checks.
- Data import tasks that need dry-run, write, verification, and rollback.
- Large documentation or harness updates where multiple files must remain consistent.

Poor fits:

- Small wording changes.
- One-file CSS tweaks.
- A single failing check with an obvious cause.
- Highly coupled edits where multiple workers would need to edit the same files.

## Roles

### Coordinator

The coordinator owns the task boundary. It should read the harness, decide whether sub-agents are useful, assign work, integrate results, run or request verification, and decide whether to archive.

Coordinator responsibilities:

- Read `AGENTS.md`, architecture docs, active plans, and relevant completed plans.
- Create or update `docs/exec-plans/active/...` for complex tasks.
- Keep file ownership clear.
- Avoid letting multiple workers edit the same file.
- Review sub-agent results instead of blindly accepting them.
- Archive only when verification passes.

The coordinator may edit files, but for large tasks it should prefer planning, integration, review, and final checks over doing every implementation detail itself.

### Explorer

Explorers are read-only. Use them to answer specific factual questions about the repo.

Good explorer prompts:

- "Find the node click path and list the functions involved. Do not edit files."
- "Inspect graph API edge payload construction and list fields returned for `BIO_RELATION`."
- "Check whether `AGENTS.md`, `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/exec-plans/`, and `scripts/checks/` exist."

Avoid vague explorer prompts such as "find harness files." Name the expected files or concepts explicitly.

### Worker

Workers implement bounded changes. Give each worker a clear write scope.

Worker prompts should include:

- Owned files or modules.
- Exact goal.
- Non-goals.
- Required checks.
- Reminder not to revert user or other agent changes.

Avoid multiple workers touching files such as `assets/js/renderers/g6/index-g6.bootstrap.js` at the same time.

### Verifier

Verifiers run checks and report evidence. They should not fix code unless explicitly reassigned as workers.

Useful verifier tasks:

- Run browser smoke checks.
- Run API contract checks.
- Report console errors, failed requests, loader state, iframe canvas/children, and command results.

### Reviewer

Reviewers inspect diffs and risks. They should lead with findings, not summaries.

Ask reviewers to check:

- Files outside scope.
- API contract regressions.
- G6 loader/iframe/legend state risks.
- Missing checks.
- Hardcoded one-off fixes.
- Whether archive criteria are actually met.

## Default Delegation Pattern

For medium or large TE-KG tasks:

1. Coordinator reads harness and creates or updates the active plan.
2. Explorer A checks the frontend or G6 path.
3. Explorer B checks API/data/checks if relevant.
4. Coordinator integrates findings and narrows the implementation scope.
5. One worker implements the bounded change.
6. Verifier runs specified checks.
7. Reviewer inspects the diff and risk.
8. Coordinator updates docs and archives if verification passes.

For small tasks, skip sub-agents and execute directly.

## File Ownership Rules

- Do not let two workers edit the same file in parallel.
- Keep API, G6 frontend, docs, and checks as separate work scopes when possible.
- For data imports, separate scripts, checks, docs, and database write steps.
- Require dry-run and rollback for Neo4j writes.

## Verification Rules

For G6 work, typical checks include:

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

Add task-specific checks, for example:

- `check_g6_node_action_card_ux.py`
- `check_g6_evidence_support_ux.py`
- `check_g6_te_mechanism_loader.py`
- `check_g6_te_tree_load_regression.py`

For data or Neo4j work, prefer:

- preflight check
- dry-run
- explicit `--write`
- post-import verification
- rollback preview

## Archive Rules

If all planned verification passes:

- Move the active plan to `docs/exec-plans/completed/`.
- Record changed files, commands, results, residual risks, and next steps.
- Update `docs/RELIABILITY.md`.
- Update `docs/exec-plans/tech-debt-tracker.md` when residual risks remain.

If verification fails:

- Do not archive.
- Keep the plan active.
- Record the failing command, failure layer, completed work, and next recommended step.

## Recommended Mother-Agent Opening Prompt

```text
Use this conversation as the TE-KG coordinator. Read the harness first. Prefer explorers for read-only investigation, workers for bounded implementation, verifiers for checks, and reviewers for diff risk. Do not spawn agents unless the task benefits from parallel or independent work. Do not let multiple workers edit the same file. Archive only after verification passes.
```

