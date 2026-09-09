# Journal Metrics Relation Aggregation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggregate existing `Paper` PubMed/journal metrics onto `BIO_RELATION` relationship properties via `BIO_RELATION.pmids -> Paper.pmid`, without changing graph API, G6, taxonomy, agent, Paper enrichment fields, nodes, or relationship topology.

**Architecture:** Keep `Paper` enrichment as the source of per-publication evidence metadata. Relation aggregation writes only derived support properties to `BIO_RELATION`, tagged with `relation_metrics_import_tag`, and can be rolled back by removing those properties only. The phase is dry-run first, then explicit `--write`.

**Tech Stack:** Python scripts using `scripts/checks/harness_lib.py`, Neo4j transactional HTTP API targeting `tekg3`, existing `Paper` fields from `journal_metrics_v1_2026-05-22`.

---

## Scope

- Only write derived aggregate properties to existing `BIO_RELATION` relationships.
- Do not modify `Paper` enrichment fields.
- Do not modify G6, graph API, taxonomy, or agent code.
- Do not delete nodes or relationships.
- All writes must include `relation_metrics_import_tag`.
- Do not call these fields confidence. They are evidence support metrics.

## Current Evidence

Read-only checks before implementation:

```text
BIO_RELATION total: 12444
BIO_RELATION with pmids: 12444
BIO_RELATION pmids raw references: 14770
Paper total: 2308
Paper tagged by journal_metrics_v1_2026-05-22: 2308
Paper with journal_metric_value: 2037
BIO_RELATION support/journal import properties currently present: 0
```

## Aggregation Fields

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
- `relation_metrics_import_tag`

## Calculation Rules

1. Deduplicate each relationship's `pmids` array before counting or joining.
2. `support_pmid_count` is the relationship-level distinct PMID count.
3. Join only via `Paper.pmid`.
4. IF aggregates use only joined `Paper` nodes where `journal_metric_value IS NOT NULL`.
5. Papers without `journal_metric_value` count toward `support_pmid_count` but not IF max/mean/median.
6. `support_metric_coverage = support_metric_paper_count / support_pmid_count`.
7. `support_journal_count` counts distinct non-empty `pubmed_journal_title` among joined Paper nodes.
8. Publication year min/max use non-null `pubmed_publication_year`.
9. Relationships without pmids should not receive aggregation fields. Current evidence says there are zero such `BIO_RELATION`, but the script must still handle them.

## Scripts

### `scripts/aggregate_relation_journal_metrics.py`

Modes:

- Default dry-run: read-only; compute and print planned stats.
- `--write`: write aggregate properties.
- `--import-tag`: required for stable rollback/audit; default can be generated if omitted.
- `--batch-size`: default 500.

Dry-run output must include:

- BIO_RELATION total.
- BIO_RELATION with pmids.
- BIO_RELATION without pmids.
- raw PMID reference count.
- unique PMID count across all relations.
- joinable PMID count.
- PMID with metric count.
- PMID without metric count.
- per-relation `support_pmid_count` distribution.
- per-relation metric coverage distribution.
- IF mean/max basic ranges.

Implementation note:

- Prefer computing aggregates in Python from read-only Cypher rows, then writing rows by `elementId(r)` in batches. This avoids complex Cypher median logic and makes dry-run/write use identical calculations.

### `scripts/checks/check_relation_journal_metrics_aggregation.py`

Validate after write:

- Neo4j target is `tekg3`.
- `BIO_RELATION` total remains unchanged.
- Relationships with non-empty `pmids` and `relation_metrics_import_tag=$tag` count equals expected aggregatable relation count.
- No relationship without pmids has `relation_metrics_import_tag=$tag`.
- All tagged relationships have `support_pmid_count > 0`.
- `support_metric_coverage` is between 0 and 1.
- `support_metric_paper_count <= support_pmid_count`.
- `support_if_mean <= support_if_max` when both are non-null.
- JCR quartile counts sum is <= `support_metric_paper_count`.
- No non-`BIO_RELATION` relationship has `relation_metrics_import_tag`.

### `scripts/rollback_relation_journal_metrics.py`

Rollback preview/write:

- Default dry-run counts affected relationships by `relation_metrics_import_tag`.
- `--write` removes only the aggregation properties listed above.
- Does not delete nodes or relationships.
- Does not remove Paper enrichment fields.

## Execution Record

Scope executed:

- Wrote only derived aggregate properties to existing `BIO_RELATION` relationships.
- Did not modify `Paper` enrichment fields.
- Did not modify G6, graph API, taxonomy, or agent code.
- Did not delete nodes or relationships.
- Did not rename IF fields to confidence.

Import tag:

```text
relation_metrics_v1_2026-05-22
```

Dry-run:

```powershell
python scripts/aggregate_relation_journal_metrics.py --import-tag relation_metrics_v1_2026-05-22
```

Result:

```text
bio_relation_total=12444
bio_relation_with_pmids=12444
bio_relation_without_pmids=0
raw_pmid_reference_count=14770
unique_pmid_count=2270
joinable_pmid_count=2270
pmid_with_metric_count=2003
pmid_without_metric_count=267
aggregatable_relation_count=12444
support_pmid_count_distribution={'1': 11705, '2': 434, '3-5': 219, '6-10': 53, '>10': 33}
support_metric_coverage_distribution={'(0.25,0.5]': 103, '(0.5,0.75]': 53, '(0.75,1)': 62, '0': 1354, '1': 10872}
support_if_mean_min=0.3
support_if_mean_max=90.2
support_if_max_min=0.3
support_if_max_max=90.2
```

Write:

```powershell
python scripts/aggregate_relation_journal_metrics.py --import-tag relation_metrics_v1_2026-05-22 --write
```

Result:

```text
[OK] relation aggregation write complete: updated=12444, import_tag=relation_metrics_v1_2026-05-22
```

Verification:

```powershell
python scripts/checks/check_relation_journal_metrics_aggregation.py --import-tag relation_metrics_v1_2026-05-22
python scripts/checks/check_journal_metrics_neo4j_import.py --import-tag journal_metrics_v1_2026-05-22
```

Result:

```text
[OK] BIO_RELATION tagged aggregation count: 12444/12444
[OK] aggregate value invariants passed
[OK] no non-BIO_RELATION relationship pollution
[OK] Paper enrichment counts: tagged=2308, with_metric=2037, without_metric=271
```

Rollback preview:

```powershell
python scripts/rollback_relation_journal_metrics.py --import-tag relation_metrics_v1_2026-05-22
```

Result:

```text
mode=dry-run
bio_relation_to_clear=12444
```
