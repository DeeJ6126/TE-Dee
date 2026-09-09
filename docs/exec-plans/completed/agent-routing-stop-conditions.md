# Agent Routing And Stop Conditions Plan

Date: 2026-07-28

## Goal

Enforce the existing Agent routing policy at every queue-expansion boundary and
stop after an existing hard-stop evidence contract passes, without reducing the
model's ability to request plugins for explicit user requirements.

## Constraints

- Do not change plugin registration, business plugin behavior, planning prompts,
  DeepThink, or `agent_routing_policy.json`.
- Preserve explicit requests for literature and Cypher exploration.
- Preserve fallback execution when the primary plugin is empty, failed, or does
  not meet the existing minimum evidence gate.
- Preserve the accepted uncommitted working-tree changes.

## Execution

- [x] Reproduce forbidden plugin re-entry through research requirements and LLM
  recommendations.
- [x] Add one shared Agent routing-policy gate with explicit-request overrides.
- [x] Apply the gate to the initial queue, research-required plugins, deterministic
  append rules, and sufficiency recommendations.
- [x] Verify successful graph analytics hard-stops while empty/incomplete results
  retain Cypher Explorer fallback.
- [x] Verify explicit literature and multi-dimensional research reports retain
  their required plugin access.
- [x] Run focused, contract, and Agent six-stage regression tests.
- [x] Live-test AQ08 and AQ13 and record plugin chains, answer coverage, and time.

## Implementation Record

- Added one routing-policy gate in `AcademicAgentEvidenceTrait.php`. A forbidden
  plugin is rejected unless the normalized question contains the corresponding
  explicit user request.
- Applied the gate to the initial queue, research-report requirements,
  deterministic append rules, and LLM sufficiency recommendations.
- Kept the existing graph-analytics hard stop and Cypher fallback contracts.
  No plugin implementation, planning prompt, routing policy, or DeepThink code
  was changed for this task.
- Added rejection diagnostics to distinguish policy filtering from plugin
  failure or ordinary evidence sufficiency.

## Verification Record

- `test/agent_routing_stop_conditions_test.php` covers forbidden re-entry,
  explicit literature override, research-report access, graph-analytics hard
  stop, and incomplete-result fallback.
- Agent six-stage, research-planning, executing-review resilience, plugin native
  contract, and payload-projection tests passed.
- AQ08 completed in 190,083 ms with `Entity Resolver -> Graph Analytics Plugin
  -> Cypher Explorer Plugin`. Graph Analytics returned no usable ranking in this
  run, so the existing fallback correctly ran; forbidden Graph and Literature
  plugins did not re-enter. The earlier baseline took 305,845 ms and called
  seven plugins.
- AQ13 completed in 311,565 ms with Entity Resolver, Literature, Graph, Tree,
  Expression, Genome, Sequence, and Citation Resolver. Its answer retained all
  requested report dimensions and explicit limitations. Literature Reading was
  queued but correctly skipped because Literature returned no stable citations
  to read. The earlier baseline took 494,040 ms.
