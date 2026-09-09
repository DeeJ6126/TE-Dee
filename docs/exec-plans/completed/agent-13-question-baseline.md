# Agent 13-Question Baseline Evaluation Plan

Date: 2026-07-28

## Goal

Run a compact but complete user-scenario baseline across all twelve intelligent
QA plugins, both answer workflows, and two edge cases. Produce one human-readable
Markdown report containing every answer, visible reasoning events, plugin chain,
total duration, and available stage timing.

## Scope

- Use the current working-tree implementation without changing answer behavior.
- Run nine common or edge questions through DeepThink.
- Run four interpretive or report questions through Agent.
- Preserve raw endpoint events in the ignored run directory for auditability.
- Track only the final report and reusable case definition.
- Record visible workflow reasoning and structured stage artifacts only; do not
  claim access to hidden model chain-of-thought.

## Case Coverage

| Case | Mode | Primary coverage |
|---|---|---|
| AQ01 | DeepThink | Entity Resolver, Sequence |
| AQ02 | DeepThink | Entity Resolver, Tree |
| AQ03 | DeepThink | Entity Resolver, Genome |
| AQ04 | DeepThink | Entity Resolver, Expression |
| AQ05 | DeepThink | Entity Resolver, Graph |
| AQ06 | DeepThink | Entity Resolver, Literature |
| AQ07 | DeepThink | Entity Resolver, Site Navigator |
| AQ08 | Agent | Graph Analytics |
| AQ09 | Agent | Cypher Explorer |
| AQ10 | Agent | Literature Reading, Citation Resolver |
| AQ11 | DeepThink | Unknown-entity failure behavior |
| AQ12 | DeepThink | Ambiguous/broad entity handling |
| AQ13 | Agent | Full multi-plugin six-stage report |

## Execution

- [x] Validate case JSONL and local API availability.
- [x] Run AQ01-AQ07 and AQ11-AQ12 with `--dt-only`.
- [x] Run AQ08-AQ10 and AQ13 with `--agent-only`.
- [x] Rescore accumulated raw events to rebuild the run summary.
- [x] Generate `report.md` with complete answers, visible reasoning events,
  plugin statuses, total time, and stage timing when present.
- [x] Verify all thirteen cases appear exactly once and all twelve plugins are
  either observed or explicitly reported as missed coverage.
- [x] Record failures without rerunning until they pass; this is a baseline, not
  a result-cleaning exercise.

## Acceptance Criteria

- Thirteen endpoint runs are recorded under unique case IDs.
- The report contains the exact question, assigned mode, completion state,
  plugin chain, visible reasoning trace, timing, and complete returned answer.
- Failures and missing answers remain visible.
- Plugin coverage is calculated from actual events rather than expected routing.
- No runtime implementation file is changed during baseline collection.

## Execution Log

- 2026-07-28: Plan created after preserving the existing plugin-hardening working
  tree. Existing evaluation runner selected to avoid introducing another runtime
  path.
- 2026-07-28: Eleven Chinese-prompt runs were preserved under
  `2026-07-28-agent-13-question-zh-pilot`. After the user requested English-first
  evaluation for an English-speaking reviewer, all thirteen formal prompts were
  translated to English and the final baseline was restarted. This restart is a
  language/protocol correction, not an attempt to hide failed cases.
- 2026-07-28: The English baseline completed 13/13 endpoint runs. Actual runtime
  events covered all twelve plugins in the matrix. The complete report records
  full answers, exposed workflow traces, end-to-end durations, measured LLM call
  timings, and plugin outcomes at
  `docs/eval/runs/2026-07-28-agent-13-question-baseline/report.md`.
