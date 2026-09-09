from __future__ import annotations

import argparse
import statistics
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
CHECKS = ROOT / "scripts" / "checks"
if str(CHECKS) not in sys.path:
    sys.path.insert(0, str(CHECKS))

from harness_lib import fail, neo4j_config, neo4j_database_name, neo4j_query  # noqa: E402


AGGREGATE_PROPERTIES = [
    "support_pmid_count",
    "support_metric_paper_count",
    "support_metric_coverage",
    "support_if_max",
    "support_if_mean",
    "support_if_median",
    "support_jcr_q1_count",
    "support_jcr_q2_count",
    "support_jcr_q3_count",
    "support_jcr_q4_count",
    "support_journal_count",
    "support_publication_year_min",
    "support_publication_year_max",
    "relation_metrics_import_tag",
]


def normalize_pmid(value: Any) -> str:
    text = str(value or "").strip()
    return text if text.isdigit() else ""


def unique_pmids(values: list[Any]) -> list[str]:
    seen: set[str] = set()
    result: list[str] = []
    for value in values or []:
        pmid = normalize_pmid(value)
        if pmid and pmid not in seen:
            seen.add(pmid)
            result.append(pmid)
    return result


def bucket_coverage(value: float | None) -> str:
    if value is None:
        return "null"
    if value == 0:
        return "0"
    if value <= 0.25:
        return "(0,0.25]"
    if value <= 0.5:
        return "(0.25,0.5]"
    if value <= 0.75:
        return "(0.5,0.75]"
    if value < 1:
        return "(0.75,1)"
    return "1"


def bucket_count(value: int) -> str:
    if value == 0:
        return "0"
    if value == 1:
        return "1"
    if value == 2:
        return "2"
    if value <= 5:
        return "3-5"
    if value <= 10:
        return "6-10"
    return ">10"


def median(values: list[float]) -> float | None:
    if not values:
        return None
    return float(statistics.median(values))


def fetch_relation_rows(config: dict[str, str]) -> list[dict[str, Any]]:
    return neo4j_query(
        config,
        """
MATCH ()-[r:BIO_RELATION]->()
RETURN elementId(r) AS rel_id, coalesce(r.pmids, []) AS pmids
ORDER BY rel_id
""",
        timeout=180,
    )


def fetch_paper_rows(config: dict[str, str]) -> dict[str, dict[str, Any]]:
    rows = neo4j_query(
        config,
        """
MATCH (p:Paper)
WHERE coalesce(p.pmid, '') <> ''
RETURN p.pmid AS pmid,
       p.journal_metric_value AS metric_value,
       p.journal_jcr_quartile AS jcr_quartile,
       p.pubmed_journal_title AS journal_title,
       p.pubmed_publication_year AS publication_year
""",
        timeout=180,
    )
    return {normalize_pmid(row.get("pmid")): row for row in rows if normalize_pmid(row.get("pmid"))}


def build_aggregate(row: dict[str, Any], papers: dict[str, dict[str, Any]], import_tag: str) -> dict[str, Any] | None:
    pmids = unique_pmids(row.get("pmids") or [])
    if not pmids:
        return None
    joined = [papers[pmid] for pmid in pmids if pmid in papers]
    metric_values = [
        float(paper["metric_value"])
        for paper in joined
        if paper.get("metric_value") is not None
    ]
    quartiles = Counter(str(paper.get("jcr_quartile") or "").upper() for paper in joined)
    journals = {
        str(paper.get("journal_title") or "").strip()
        for paper in joined
        if str(paper.get("journal_title") or "").strip()
    }
    years = [
        int(paper["publication_year"])
        for paper in joined
        if paper.get("publication_year") is not None
    ]
    metric_count = len(metric_values)
    pmid_count = len(pmids)
    return {
        "rel_id": row["rel_id"],
        "support_pmid_count": pmid_count,
        "support_metric_paper_count": metric_count,
        "support_metric_coverage": metric_count / pmid_count if pmid_count else 0.0,
        "support_if_max": max(metric_values) if metric_values else None,
        "support_if_mean": sum(metric_values) / metric_count if metric_values else None,
        "support_if_median": median(metric_values),
        "support_jcr_q1_count": quartiles["Q1"],
        "support_jcr_q2_count": quartiles["Q2"],
        "support_jcr_q3_count": quartiles["Q3"],
        "support_jcr_q4_count": quartiles["Q4"],
        "support_journal_count": len(journals),
        "support_publication_year_min": min(years) if years else None,
        "support_publication_year_max": max(years) if years else None,
        "relation_metrics_import_tag": import_tag,
        "_joined_pmid_count": len(joined),
    }


def summarize(relation_rows: list[dict[str, Any]], aggregates: list[dict[str, Any]], papers: dict[str, dict[str, Any]]) -> dict[str, Any]:
    total = len(relation_rows)
    with_pmids = sum(1 for row in relation_rows if unique_pmids(row.get("pmids") or []))
    raw_refs = sum(len(row.get("pmids") or []) for row in relation_rows)
    all_unique_pmids = sorted({pmid for row in relation_rows for pmid in unique_pmids(row.get("pmids") or [])}, key=int)
    joinable_pmids = [pmid for pmid in all_unique_pmids if pmid in papers]
    metric_pmids = [pmid for pmid in joinable_pmids if papers[pmid].get("metric_value") is not None]
    if_means = [row["support_if_mean"] for row in aggregates if row["support_if_mean"] is not None]
    if_maxes = [row["support_if_max"] for row in aggregates if row["support_if_max"] is not None]
    return {
        "bio_relation_total": total,
        "bio_relation_with_pmids": with_pmids,
        "bio_relation_without_pmids": total - with_pmids,
        "raw_pmid_reference_count": raw_refs,
        "unique_pmid_count": len(all_unique_pmids),
        "joinable_pmid_count": len(joinable_pmids),
        "pmid_with_metric_count": len(metric_pmids),
        "pmid_without_metric_count": len(joinable_pmids) - len(metric_pmids),
        "aggregatable_relation_count": len(aggregates),
        "support_pmid_count_distribution": dict(sorted(Counter(bucket_count(row["support_pmid_count"]) for row in aggregates).items())),
        "support_metric_coverage_distribution": dict(sorted(Counter(bucket_coverage(row["support_metric_coverage"]) for row in aggregates).items())),
        "support_if_mean_min": min(if_means) if if_means else None,
        "support_if_mean_max": max(if_means) if if_means else None,
        "support_if_max_min": min(if_maxes) if if_maxes else None,
        "support_if_max_max": max(if_maxes) if if_maxes else None,
    }


def chunks(rows: list[dict[str, Any]], size: int) -> list[list[dict[str, Any]]]:
    return [rows[index:index + size] for index in range(0, len(rows), size)]


def write_aggregates(config: dict[str, str], aggregates: list[dict[str, Any]], batch_size: int) -> int:
    updated = 0
    for batch in chunks(aggregates, max(1, batch_size)):
        rows = neo4j_query(
            config,
            """
UNWIND $rows AS row
MATCH ()-[r:BIO_RELATION]->()
WHERE elementId(r) = row.rel_id
SET r.support_pmid_count = row.support_pmid_count,
    r.support_metric_paper_count = row.support_metric_paper_count,
    r.support_metric_coverage = row.support_metric_coverage,
    r.support_if_max = row.support_if_max,
    r.support_if_mean = row.support_if_mean,
    r.support_if_median = row.support_if_median,
    r.support_jcr_q1_count = row.support_jcr_q1_count,
    r.support_jcr_q2_count = row.support_jcr_q2_count,
    r.support_jcr_q3_count = row.support_jcr_q3_count,
    r.support_jcr_q4_count = row.support_jcr_q4_count,
    r.support_journal_count = row.support_journal_count,
    r.support_publication_year_min = row.support_publication_year_min,
    r.support_publication_year_max = row.support_publication_year_max,
    r.relation_metrics_import_tag = row.relation_metrics_import_tag
RETURN count(r) AS updated
""",
            {"rows": batch},
            timeout=180,
        )
        updated += int(rows[0].get("updated") or 0)
        print(f"updated_progress={updated}/{len(aggregates)}")
    return updated


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Aggregate Paper journal metrics onto BIO_RELATION relationships.")
    parser.add_argument("--import-tag", default="")
    parser.add_argument("--batch-size", type=int, default=500)
    parser.add_argument("--write", action="store_true")
    args = parser.parse_args(argv)

    import_tag = args.import_tag.strip() or "relation_metrics_v1_" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    config = neo4j_config()
    database = neo4j_database_name(config)
    if database != "tekg3":
        fail(f"Neo4j target must be tekg3, got {database or 'unknown'}")

    relation_rows = fetch_relation_rows(config)
    papers = fetch_paper_rows(config)
    aggregates = [agg for row in relation_rows if (agg := build_aggregate(row, papers, import_tag)) is not None]
    summary = summarize(relation_rows, aggregates, papers)

    print(f"mode={'write' if args.write else 'dry-run'}")
    print(f"import_tag={import_tag}")
    for key, value in summary.items():
        print(f"{key}={value}")
    print("properties=" + ",".join(AGGREGATE_PROPERTIES))

    if not args.write:
        return 0
    updated = write_aggregates(config, aggregates, args.batch_size)
    print(f"[OK] relation aggregation write complete: updated={updated}, import_tag={import_tag}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
