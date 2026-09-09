# Agent Literature Disambiguation Implementation Plan

> **For agentic workers:** Implement this plan task-by-task with test-first
> verification. The primary AI owns integration and review.

**Goal:** Prevent ambiguous TE abbreviations and weakly related PubMed records
from entering Agent and DeepThink evidence packages.

**Architecture:** Keep entity resolution as the source of canonical TE names and
strict aliases. Build PubMed queries from those resolved entities rather than
the planner's earlier entity snapshot, expand only unsafe generic abbreviations
into domain-specific phrases, and apply a deterministic title/abstract relevance
gate before merging PubMed records with trusted graph-linked citations.

**Tech Stack:** PHP 8, existing plugin contracts, PubMed E-utilities, repository
PHP assertion tests.

---

### Task 1: Reproduce the ambiguity and filtering gap

**Files:**
- Create: `test/literature_query_disambiguation_test.php`

- [x] Assert that a resolved generic `TE` entity produces a domain-qualified
  PubMed query containing `transposable element` and never a bare `"TE"` query.
- [x] Assert that strict LINE-1 aliases remain available in the query.
- [x] Assert that thermoelectric and unrelated cancer records are rejected while
  LINE-1/transposable-element cancer records are retained.
- [x] Run the test and confirm it fails because the current query builder uses
  planner entities and has no deterministic relevance gate.

### Task 2: Implement resolved-entity query construction

**Files:**
- Modify: `api/agent/plugins/LiteraturePlugin.php`

- [x] Pass `tekg_agent_context_resolved_entities()` into PubMed term building.
- [x] Replace unsafe generic aliases such as `TE` with explicit domain phrases.
- [x] Preserve specific strict aliases such as `LINE-1`, `LINE1`, and `L1HS`.
- [x] Keep comparison and disease-specific query behavior bounded to at most two
  query terms.
- [x] Run the focused test and confirm the query assertions pass.

### Task 3: Filter external results before evidence synthesis

**Files:**
- Modify: `api/agent/plugins/LiteraturePlugin.php`
- Test: `test/literature_query_disambiguation_test.php`

- [x] Score PubMed title plus abstract against the resolved TE alias group and,
  when present, the resolved disease/topic group.
- [x] Reject records that do not match the TE domain group; require the disease
  group when the question resolves a disease such as cancer.
- [x] Leave graph-linked local citations intact because their provenance is an
  explicit graph relationship rather than a keyword search.
- [x] Report candidate, retained, and filtered counts without padding an answer
  with rejected records.
- [x] Run focused and existing plugin contract tests.

### Task 4: Document and verify

**Files:**
- Modify: `api/agent/plugins/PLUGIN_CATALOG.md`
- Modify: `api/docs/plugin_system.md`
- Modify: `api/docs/testing_and_evaluation.md`

- [x] Document that Literature Plugin queries must use resolved canonical entity
  scope and that external records pass a deterministic relevance gate.
- [x] Run PHP syntax checks and the plugin/DeepThink regression suite.
- [x] Re-run the affected English literature question if the local relay and
  PubMed are available; otherwise record that live verification remains pending.

## Verification Record

- The focused test was observed failing first on the bare `TE` query, then on
  the missing relevance gate, and finally on unsafe short-alias substring
  matching before each production change was applied.
- Static and regression checks passed for Literature Plugin syntax, native
  result/evidence/projection/status contracts, Literature Reading fallback,
  context accessors, and DeepThink four-stage contract/runtime behavior.
- Live AQ06 verification used the resolved query
  `("LINE1" OR "LINE-1" OR "LINE 1" OR "long interspersed nuclear element 1") AND "cancer"`
  with PubMed Title/Abstract fields. Of eight returned top records, four relevant
  LINE-1/cancer records were retained and four scope mismatches were filtered.
  The final answer listed only the four retained PMIDs.
