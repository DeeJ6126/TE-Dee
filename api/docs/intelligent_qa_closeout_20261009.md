# Intelligent QA closeout — 2026-10-09

## Accepted runtime

Agent and DeepThink use the shared native `QaResearchLoop` and one
`deepseek-flash` model policy. Validated tools retrieve records, expose original
material through run-owned IDs, and support refined queries. Citation and
presentation checks remain before final delivery. Requested complete sequences
are appended from validated stored bases. The server retains its configured API
key; the browser-key prototype remains in the separate original working tree.

The continuous model conversation follows the session-history approach examined
in [DeepSeek Harness's agent loop](https://github.com/deepseek-ai/deepseek-harness/blob/master/packages/core/agent-loop/src/agent.ts).
This implementation uses the existing PHP/plugin runtime and does not install
the Harness runtime or copy its session/event subsystem.

## Cross-turn context

- Complete successful user/assistant turns reach research and final writing.
- Retention is bounded to 12 whole turns / 1 MiB of original text and 512 KiB per
  user/assistant pair; oversized pairs are explicitly omitted, never truncated.
- Three bounded summary turns continue to support validated TE antecedents.
- A bare request for a full sequence or FASTA now triggers antecedent resolution.
  Rewriting the effective question cannot drop the original full-sequence request.
- Text work on provided material can finish without unrelated lookup calls.
  Mixed editing + new scientific queries still require new source retrieval.
- Prior assistant wording and source identifiers are conversation context; they
  do not establish new scientific claims. Unknown citation IDs remain rejected.
- Failed writers do not append a successful turn. Legacy summaries cannot
  restore missing original material.
- Main page and floating window keep session IDs in page memory. Reload/new tab
  starts fresh; switching modes on the main page preserves that page's session.
- Whole-turn eviction/omission is visible through `context_notice`, without
  advancing scientific stages.

## Runtime-record retention

`QaCacheRetention` checks once per day when QA configuration loads. It removes
recognized seven-day-old original native records, completed/failed async runs,
session summaries, private conversation files and dated diagnostics. Native
execution holds a shared active lock; cleanup acquires an exclusive lock and
rechecks age. Pending/running async jobs are preserved. Symlinks, unknown files,
PubMed source caches, evaluation output and rollback snapshots are excluded.

Manual CLI defaults to preview:

```text
php scripts/checks/prune_qa_cache.php --dry-run
php scripts/checks/prune_qa_cache.php --apply
```

No scheduler or server-process configuration was added. After idle periods,
cleanup runs on the next QA config load. Terminal results and private history
are retained by last filesystem update time, not by scientific relevance.

## Verification

- Prior native-loop release: all 15 live cases completed, including 10 templates,
  complete sequences, eQTL and query refinement; 65 successful native model calls
  used the shared model.
- New live material/context suite: 9/9 requests completed; each mode supplied a
  long input, the other mode extracted its final four fields, and a third turn
  rewrote the previous answer. Isolated sessions asked for missing material.
  A fresh eQTL request actually retrieved 68 matching LTR7A associations and
  presented representative ZNF780A/FCGBP tissue, nominal p and slope fields.
- Primary same-mode sequence test: both modes answered the L1HS length/source
  query, then the bare Chinese request for complete FASTA. Each follow-up returned
  6064 bp, SHA-256
  `f33c2494629ad3c6875f2929dfa19a3d94c5ab560d9fd8cd78d81aebd6b2e352`,
  matching the stored record; neither asked the user to repeat a TE name.
- New history service test covers long user/assistant tails, both model phases,
  source retrieval, mixed requests, unknown references, session isolation,
  exact sequence follow-up, failure exclusion and private/public storage separation.
- Retention test covers expired vs recent records, active locks, busy cleanup,
  unrecognized content and symlinks (including on the server's Linux runtime).
- Focused native/protocol, evidence, sequence, memory, model-policy, citation,
  deadline and frontend contracts passed. Server PHP 8.4 ran the new tests.
- Existing actual `.conversations` and `.native_runs` records returned HTTP 403.
  Public summary files contain no complete dialogue or long material tail.
- Cleanup preview and explicit application had zero errors; current records were
  newer than seven days, so no existing record was eligible for deletion.

The sequence follow-up initially exposed an entity-scope mismatch: the model
understood L1HS, but validated plugin scope had not inherited the entity. The
antecedent fix and exact-output regression were completed before release.

Visual acceptance was performed by the user. Interface completion tests support
the tested workflows; they are not a scientific accuracy percentage or a
long-term availability measurement. Server external access remains dependent on
its existing network exit, which was not changed in this closeout.
