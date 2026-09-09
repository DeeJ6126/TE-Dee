# Graph API Evidence Support Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose existing Neo4j `BIO_RELATION.support_*` evidence-support aggregate fields through `api/graph.php` / `api/graph_service.php` edge payloads so frontend code can read them later.

**Architecture:** Keep the existing graph API `elements` structure unchanged and add a flat, backwards-compatible set of optional edge `data.support_*` fields. Do not alter node payloads, G6 rendering, taxonomy, agent, expression, or Neo4j data. Do not call IF-derived fields confidence.

**Tech Stack:** PHP graph API service, Neo4j `BIO_RELATION` relationship properties, Python harness checks using `scripts/checks/harness_lib.py`.

---

## Current Contract Evidence

Current edge payload from `api/graph.php?q=LINE1` is built in `GraphService::buildElements()` from row fields such as:

- `relation_type`
- `relation_label`
- `relation_evidence`
- `relation_pmids`

Current edge `data` includes:

- `id`
- `source`
- `target`
- `relation`
- `relationType`
- `evidence`
- `pmids`

The current Cypher row loaders do not return relation `support_*` fields, so API consumers cannot yet read relation evidence-support metrics.

## Contract Addition

Add these fields to every API edge payload:

- `support_pmid_count`
- `support_metric_paper_count`
- `support_metric_coverage`
- `support_if_max`
- `support_if_mean`
- `support_if_median`
- `support_jcr_q1_count`
- `support_jcr_q2_count`
- `support_jcr_q3_count`
- `support_jcr_q4_count`
- `support_journal_count`
- `support_publication_year_min`
- `support_publication_year_max`

Defaulting rules:

- Count fields default to `0` when relationship property is missing.
- Coverage defaults to `0.0` when missing.
- IF fields default to `null` when missing.
- Year min/max default to `null` when missing.

This is backwards-compatible because existing fields remain unchanged.

## Execution Tasks

### Task 1: Add Failing Check

**Files:**

- Create: `scripts/checks/check_graph_api_evidence_support.py`

- [ ] Fetch `api/graph.php?q=LINE1`.
- [ ] Find at least one edge.
- [ ] Assert every support field exists on the edge.
- [ ] Assert count fields are ints, coverage is number in `[0, 1]`, IF fields are number or null, and years are int or null.
- [ ] Run check before PHP change and confirm it fails on missing support fields.

### Task 2: Extend GraphService Row Loaders

**Files:**

- Modify: `api/graph_service.php`

- [ ] Add relation support fields to the Cypher return list for direct `BIO_RELATION` loaders.
- [ ] Add helper methods to normalize support values.
- [ ] Add support fields to primary and expanded edge `data` in `buildElements()`.
- [ ] Preserve existing `id/source/target/relation/relationType/evidence/pmids` behavior.

### Task 3: Verify API Contract

Run:

```powershell
php -l api/graph.php
php -l api/graph_service.php
python scripts/checks/check_api_contracts.py
python scripts/checks/check_graph_api_evidence_support.py
```

Expected:

- Existing API checks still pass.
- New evidence-support check passes for at least one `LINE1` edge.

## Out Of Scope

- No G6 visual encoding.
- No graph API structural rewrite.
- No Neo4j writes.
- No taxonomy, agent, expression changes.
- No confidence naming.

## Execution Record - 2026-05-22

Files changed:

- `api/graph_service.php`
- `scripts/checks/check_graph_api_evidence_support.py`
- `docs/exec-plans/active/graph-api-evidence-support-contract.md`

RED check before implementation:

```powershell
python scripts/checks/check_graph_api_evidence_support.py
```

Result:

```text
[FAIL] no edge has support_* fields; sample edge keys=['evidence', 'id', 'pmids', 'relation', 'relationType', 'source', 'target']
```

Implementation:

- Added `BIO_RELATION.support_*` fields to graph row Cypher returns.
- Added normalized edge payload defaults:
  - counts default to `0`
  - coverage defaults to `0.0`
  - IF values default to `null`
  - year min/max default to `null`
- Preserved existing edge fields: `id`, `source`, `target`, `relation`, `relationType`, `evidence`, `pmids`.

Verification:

```powershell
php -l api/graph.php
php -l api/graph_service.php
python scripts/checks/check_api_contracts.py
python scripts/checks/check_graph_api_evidence_support.py
```

Result:

```text
No syntax errors detected in api\graph.php
No syntax errors detected in api\graph_service.php
[OK] api/graph.php?q=LINE1 contract passed
[OK] api/graph.php same-label expand disambiguation contract passed
[OK] graph API evidence support edge contract passed: support_pmid_count=2, coverage=1, if_mean=2.5
```

Sample edge payload:

```json
{
  "relation": "insert into",
  "pmids": ["17034961", "20169193"],
  "support_pmid_count": 2,
  "support_metric_paper_count": 2,
  "support_metric_coverage": 1,
  "support_if_max": 2.6,
  "support_if_mean": 2.5,
  "support_if_median": 2.5,
  "support_jcr_q1_count": 0,
  "support_journal_count": 2,
  "support_publication_year_min": 2007,
  "support_publication_year_max": 2010
}
```
