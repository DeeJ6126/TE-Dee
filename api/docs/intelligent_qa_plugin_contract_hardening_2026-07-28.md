# Intelligent QA Plugin Contract Hardening

Date: 2026-07-28

This record captures the completed plugin-layer hardening pass that followed
the read-only runtime audit. It is the durable implementation and verification
summary; the raw live event records remain under `docs/eval/runs/`.

## Implemented Behavior

- All twelve registry names remain unchanged.
- `PluginResultContract` enforces one twelve-field native result shape in Agent
  and DeepThink before augmentation or evidence aggregation.
- Native status semantics are shared: `ok`, `partial`, `empty`, and `error`.
- Diagnostic evidence uses `support_strength=none` and remains inspectable while
  being excluded from scientific aggregation.
- Entity resolution confidence, navigation matches, PubMed query execution,
  citation normalization, rank, and metric execution are not treated as
  scientific support.
- Graph relations are association evidence rather than automatic causal
  evidence; graph metrics identify their derivation.
- Sequence records and keyword-derived structure hints are separate evidence
  types with different strengths.
- Literature Reading accepts only valid structured LLM synthesis. Invalid or
  unavailable synthesis preserves citation metadata as an explicit
  `metadata_fallback` without generating supported claims.
- `PluginResultProjection` supplies shared bounded LLM and UI views. Browser
  events carry a single canonical `raw_result` instead of repeated full copies.
- Agent skips ExecutingReview for deterministic, empty/error, exact-retrieval,
  and citation-normalization results. Skips are explicit and create no fake LLM
  artifact.

## Payload Evidence

Using the historical L1HS event representation as a before/after fixture, total
tool-result JSON size fell from 328,917 characters to an estimated 104,788
characters, a 68.1 percent reduction. The fresh Agent L1HS report emitted these
projected payload sizes:

| Plugin | JSON characters |
|---|---:|
| Entity Resolver | 1,555 |
| Literature Plugin | 12,513 |
| Literature Reading Plugin | 13,824 |
| Graph Plugin | 38,981 |
| Tree Plugin | 1,761 |
| Expression Plugin | 3,182 |
| Genome Plugin | 2,479 |
| Sequence Plugin | 10,459 |
| Citation Resolver | 17,425 |

No fresh tool event contained `compressed_result`, `display_details`, or
`raw_preview`. Graph drill-down, citation lists, complete sequence access, and
Literature Reading `generation_mode` remained available.

## Live Verification

### DeepThink sequence

Run: `docs/eval/runs/2026-07-28-plugin-contract-dt-sequence`

- Completed in 42,032 ms.
- Selected Entity Resolver, Sequence Plugin, and Citation Resolver.
- Returned the 6,064 bp L1HS consensus record and two citations.
- Full sequence data remained available once under the UI projection.

### DeepThink literature

Run: `docs/eval/runs/2026-07-28-plugin-contract-dt-literature`

- Completed in 46,168 ms.
- Selected Entity Resolver, Literature Plugin, Literature Reading Plugin, and
  Citation Resolver.
- Entity resolution and query execution remained diagnostic.
- Literature records and synthesis were medium-strength and explicitly bounded
  to metadata or abstract-level material.

### Agent research report

Run: `docs/eval/runs/2026-07-28-plugin-contract-agent-report`

- Completed successfully in 467,486 ms with writing intact.
- Selected nine plugins: Entity Resolver, Literature, Literature Reading,
  Graph, Tree, Expression, Genome, Sequence, and Citation Resolver.
- Five scientifically interpretive results received ExecutingReview. Four
  deterministic or post-processing results were marked `not_required`, meeting
  the target of at least three fewer LLM review calls.
- The final Chinese report explicitly distinguished disease association from
  causality and stated representative-locus, keyword-derived structure, source
  coverage, and evidence-depth limitations.

## Automated Verification

- All PHP files under `api/agent/` passed syntax checks.
- The existing 23 PHP Agent/DeepThink tests and five new focused contract tests
  passed.
- Three frontend event/state contract checks passed.
- Eight Python semantic/evaluation tests passed; only the pre-existing
  inaccessible `.pytest_cache` warning remained.
- API contract, Neo4j `tekg3`, no-legacy-fallback, and documentation checks
  passed. The broader taxonomy truth check still reports the pre-existing
  homepage-helper mismatch in `index.php`; this Agent-focused pass did not
  modify or suppress that unrelated failure.

## Browser Observation

Both DeepThink and Agent mode selectors, templates, input states, and the plugin
details panel rendered without console errors. A live empty Sequence result
showed summary, counts, evidence, citations, canonical returned data, and errors
in the inspector. Graph, literature, sequence, and citation payload structures
were additionally verified from the successful live event records and frontend
contract checks.

One separate browser DeepThink attempt ended at Writing after the model failed
to resolve the entity in that run. The failure was visible in the UI and did not
indicate a plugin contract or inspector exception; the isolated DeepThink live
sequence run above completed successfully.
