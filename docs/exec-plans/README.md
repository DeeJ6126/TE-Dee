# TE-KG Execution Plans

This directory stores execution plans for Codex/human collaboration. It is part
of the project harness: complex work should be turned from temporary chat into
reviewable, handoff-ready, verifiable repository assets.

## Folders

- `active/`: prepared or in-progress plans.
- `completed/`: finished plans with execution and verification notes.
- `tech-debt-tracker.md`: cross-task technical debt and follow-up governance.

## Plan Template

```markdown
# <Task Name>

## Background

Why this task exists and what problem it addresses.

## Goals

The results that must be true when the task is complete.

## Non-Goals

Explicitly excluded scope.

## File Scope

Expected files or modules to touch.

## Implementation Steps

Small, verifiable steps in execution order.

## Acceptance Criteria

How to decide the task is complete.

## Verification Commands

Commands that must be run and expected outcomes.

## Execution Log

What actually happened, including deviations and reasons.

## Residual Risks

Known issues left for future work.
```

## Use Rules

- Large features, architecture changes, G6 interaction fixes, and data-source
  migrations should start with an active plan.
- Move finished plans to `completed/` and record verification results.
- If a task exposes a structural issue, update `tech-debt-tracker.md`.
- A plan does not replace code review; it allows the next Codex session to
  continue safely.
- Project documentation should be English. Historical Chinese plans may remain
  temporarily, but important ongoing content should be translated or summarized
  into English before handoff.

## Three-Role Self-Review

Before complex work is called complete, review it from three perspectives:

- `Implementer`: confirms implementation covers the plan and did not expand
  scope.
- `Reviewer`: looks for bugs, architecture risks, old-path regressions, old DB
  fallback, multiple truth sources, and missing tests.
- `Verifier`: runs verification commands and records evidence. No fresh
  verification means no completion claim.

When subagents are available, these roles can be split across agents. The main
AI remains responsible for scope, integration, and final judgment.

## Completion Conditions

- Every active-plan acceptance criterion has a recorded result.
- Relevant checks have been run; failures are recorded in the execution log or
  technical-debt tracker.
- If quality status changed, update `../QUALITY_SCORE.md`.
- If reliability entrypoints changed, update `../RELIABILITY.md`.
