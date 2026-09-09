# Agent Routing Stop Quality Comparison

Date: 2026-07-28

## Scope

This comparison reran all thirteen reviewer-facing cases after the Agent routing
and stop-condition change. AQ08 and AQ13 came from the focused verification;
the other eleven cases were rerun afterward. Each case used the same mode as the
original baseline: nine DeepThink cases and four Agent cases.

The assessment compares answer coverage, factual alignment with returned
evidence, failure behavior, and plugin chains. Latency is not used as a quality
criterion.

## Result

No answer-quality regression was found in the thirteen-case comparison.

- All thirteen cases produced acceptable final answers. AQ11 had one transient
  Writing failure and passed on an immediate retry with the expected empty-result
  answer. This is a reliability observation, not a routing-policy regression.
- All requested answer dimensions remained covered.
- Shorter answers in AQ03, AQ04, and AQ05 removed unrequested elaboration while
  retaining the requested coordinates, expression contexts, and disease list.
- AQ06 improved materially: literature retrieval returned four directly relevant
  LINE-1/cancer papers and Literature Reading ran, instead of presenting unrelated
  search results as secondary context.
- AQ09 still states that no complete two-hop path can be supported because the
  required gene-to-disease edge is absent. AQ10 still includes main findings,
  conflicts, evidence gaps, and deduplicated references.
- AQ13 retained sequence, classification, genomic location, expression, disease,
  literature, and limitations coverage. Literature Reading was queued but skipped
  because Literature returned no stable citations for a reading pass.

## Plugin Comparison

| Case | Mode | Baseline | Current | Change | Quality verdict |
|---|---|---:|---:|---:|---|
| AQ01 | DeepThink | 3 | 3 | 0 | Maintained; citation detail improved |
| AQ02 | DeepThink | 2 | 2 | 0 | Same answer and coverage |
| AQ03 | DeepThink | 2 | 2 | 0 | Maintained; still distinguishes representative from complete loci |
| AQ04 | DeepThink | 2 | 2 | 0 | Maintained; all three requested contexts present |
| AQ05 | DeepThink | 3 | 3 | 0 | Maintained; complete disease list and association caveat present |
| AQ06 | DeepThink | 3 | 4 | +1 | Improved literature relevance; reading pass added |
| AQ07 | DeepThink | 2 | 2 | 0 | Same navigation coverage |
| AQ08 | Agent | 7 | 3 | -4 | Maintained; irrelevant Graph/Literature calls removed, Cypher fallback retained |
| AQ09 | Agent | 7 | 7 | 0 | Maintained; unsupported two-hop path is not invented |
| AQ10 | Agent | 5 | 5 | 0 | Maintained; requested synthesis structure and references retained |
| AQ11 | DeepThink | 2 | 2 | 0 | Maintained after one transient Writing retry |
| AQ12 | DeepThink | 3 | 3 | 0 | Improved; gives a bounded introduction and explicit entity scope |
| AQ13 | Agent | 9 | 8 | -1 | Maintained; reading pass skipped only after empty literature retrieval |

Across all cases, plugin calls changed from 50 to 46. Within Agent cases, calls
changed from 28 to 23. Five Agent calls were removed (four in AQ08 and one in
AQ13); DeepThink AQ06 added one useful Literature Reading call. The net reduction
is therefore four calls, with no observed loss of requested answer coverage.

## Conclusion

The small routing change did not broadly weaken plugin use. It reduced plugins
only where the route was irrelevant or an upstream evidence prerequisite was
not met, while leaving AQ09 and AQ10's complex multi-plugin chains unchanged.
The evidence from this fixed case set supports retaining the change.
