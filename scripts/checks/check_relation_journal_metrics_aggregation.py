from __future__ import annotations

import argparse
import sys

from harness_lib import neo4j_config, neo4j_database_name, neo4j_query, ok, require, run_check


def scalar(config: dict[str, str], query: str, params: dict | None = None, column: str = "count") -> int:
    rows = neo4j_query(config, query, params or {}, timeout=180)
    return int(rows[0].get(column) or 0)


def main(argv: list[str] | None = None) -> None:
    parser = argparse.ArgumentParser(description="Verify BIO_RELATION journal metrics aggregation.")
    parser.add_argument("--import-tag", required=True)
    args = parser.parse_args(argv)

    config = neo4j_config()
    database = neo4j_database_name(config)
    require(database == "tekg3", f"Neo4j target must be tekg3, got {database or 'unknown'}")
    ok("Neo4j target is tekg3")

    total = scalar(config, "MATCH ()-[r:BIO_RELATION]->() RETURN count(r) AS count")
    with_pmids = scalar(config, "MATCH ()-[r:BIO_RELATION]->() WHERE size(coalesce(r.pmids, [])) > 0 RETURN count(r) AS count")
    tagged = scalar(
        config,
        "MATCH ()-[r:BIO_RELATION]->() WHERE r.relation_metrics_import_tag = $tag RETURN count(r) AS count",
        {"tag": args.import_tag},
    )
    require(total == 12444, f"unexpected BIO_RELATION total: {total}")
    require(tagged == with_pmids, f"tagged relationships {tagged} != relationships with pmids {with_pmids}")
    ok(f"BIO_RELATION tagged aggregation count: {tagged}/{total}")

    no_pmid_tagged = scalar(
        config,
        """
MATCH ()-[r:BIO_RELATION]->()
WHERE r.relation_metrics_import_tag = $tag AND size(coalesce(r.pmids, [])) = 0
RETURN count(r) AS count
""",
        {"tag": args.import_tag},
    )
    require(no_pmid_tagged == 0, "relationships without pmids were tagged")

    invalid = scalar(
        config,
        """
MATCH ()-[r:BIO_RELATION]->()
WHERE r.relation_metrics_import_tag = $tag
  AND (
    r.support_pmid_count <= 0 OR
    r.support_metric_paper_count > r.support_pmid_count OR
    r.support_metric_coverage < 0 OR
    r.support_metric_coverage > 1 OR
    (r.support_if_mean IS NOT NULL AND r.support_if_max IS NOT NULL AND r.support_if_mean > r.support_if_max) OR
    (coalesce(r.support_jcr_q1_count, 0) + coalesce(r.support_jcr_q2_count, 0) + coalesce(r.support_jcr_q3_count, 0) + coalesce(r.support_jcr_q4_count, 0)) > r.support_metric_paper_count
  )
RETURN count(r) AS count
""",
        {"tag": args.import_tag},
    )
    require(invalid == 0, f"invalid aggregate relationships found: {invalid}")
    ok("aggregate value invariants passed")

    polluted = scalar(
        config,
        """
MATCH ()-[r]->()
WHERE type(r) <> 'BIO_RELATION' AND r.relation_metrics_import_tag IS NOT NULL
RETURN count(r) AS count
""",
    )
    require(polluted == 0, "non-BIO_RELATION relationships have relation_metrics_import_tag")
    ok("no non-BIO_RELATION relationship pollution")


if __name__ == "__main__":
    run_check(lambda: main(sys.argv[1:]))
