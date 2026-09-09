# Agent User-Facing Writing Layer Implementation Plan

> **For agentic workers:** Execute inline in the primary session. Preserve all
> accepted uncommitted work and verify every generated answer as user-facing
> prose rather than as an internal evidence audit.

**Goal:** Prevent Agent and DeepThink final answers from exposing internal
workflow vocabulary, identifiers, flags, plugin names, and evidence-accounting
language while preserving supported scientific content and real citations.

**Architecture:** Keep internal evidence contracts unchanged. Strengthen every
final-writing boundary with one shared presentation policy, make WritingDecision
produce user-facing instructions, and keep report planning aligned with the
user's requested deliverable rather than the detected evidence source. Do not
change plugin routing or scientific source data in this task.

**Tech Stack:** PHP 8.3, prompt templates, deterministic report planning, local
PHP contract tests, live Agent evaluation through the existing run API.

---

## Root Cause Record

- The current WritingDecision prompt asks for evidence strength, limitations,
  citations, and sections but does not distinguish internal evidence metadata
  from user-facing prose.
- The evidence-walk draft prompt explicitly asks the model to make the
  claim-evidence map visible in the report.
- `ReportPlan` selects `evidence_audit` whenever the normalized intent is
  literature, even when the user explicitly asks for a research report.
- Internal flags such as `keyword_derived` and `association_not_causality`, IDs
  such as `citation_24`, plugin names, claim counts, and audit sections therefore
  become visible prose.
- The original quality review checked coverage and plugin chains but did not
  test whether a user without system knowledge could understand the answer.

## Tasks

- [x] Add a failing prompt-contract test requiring plain-language output policy
  at Agent WritingDecision, Agent draft/polish/direct answer, and DeepThink
  Writing boundaries.
- [x] Add a failing report-plan test proving that an explicit research-report
  request is not converted into an evidence-audit template solely because it
  requests literature evidence.
- [x] Add one bilingual user-facing writing policy that prohibits literal
  plugin names, schema names, internal IDs, raw quality flags, support-level
  accounting, claim counts, and repeated caveats.
- [x] Require internal metadata to affect selection and caution only; translate
  necessary limitations into ordinary language and omit irrelevant weak hints.
- [x] Require real user-facing references such as PMID, DOI, paper title, or a
  normal numbered reference; never expose `citation_*` identifiers.
- [x] Treat report plans and claim-evidence maps as internal organization tools,
  not mandatory visible sections or prose.
- [x] Make explicit research-report requests select the research-report plan.
- [x] Run prompt, report-plan, integrity, six-stage, and DeepThink regressions.
- [x] Rerun the exact L1HS report question and inspect every paragraph for
  internal leakage, clarity, requested coverage, citation usability, and
  repeated caveats.
- [x] Record the live result and remaining data-layer issue separately; do not
  claim the upstream `Non-LTR` substring bug is fixed by this writing task.

## Verification Record

- Contract, prompt, report-plan, Agent six-stage, DeepThink four-stage,
  relationship, sequence, language, and model-configuration tests passed on
  2026-07-29.
- The final live iteration is
  `docs/eval/runs/2026-07-29-agent-user-facing-writing/run7`. It contains no
  plugin names, raw quality flags, internal IDs, evidence-accounting language,
  or duplicate literature-summary section. Exact sequence and genome values
  are preserved and citations use normal PMID or reference forms.
- The report was still longer than necessary, so the shared policy now carries
  a gentle 600-900 word and 6-10 representative-reference target. This is a
  prompt-level preference, not deterministic truncation.
