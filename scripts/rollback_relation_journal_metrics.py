from __future__ import annotations

import argparse
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CHECKS = ROOT / "scripts" / "checks"
if str(CHECKS) not in sys.path:
    sys.path.insert(0, str(CHECKS))

from harness_lib import fail, neo4j_config, neo4j_database_name, neo4j_query  # noqa: E402


PROPERTIES = [
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


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Rollback BIO_RELATION journal metrics aggregation properties.")
    parser.add_argument("--import-tag", required=True)
    parser.add_argument("--write", action="store_true")
    args = parser.parse_args(argv)

    config = neo4j_config()
    database = neo4j_database_name(config)
    if database != "tekg3":
        fail(f"Neo4j target must be tekg3, got {database or 'unknown'}")

    rows = neo4j_query(
        config,
        "MATCH ()-[r:BIO_RELATION]->() WHERE r.relation_metrics_import_tag = $tag RETURN count(r) AS count",
        {"tag": args.import_tag},
        timeout=180,
    )
    affected = int(rows[0].get("count") or 0)
    print(f"mode={'write' if args.write else 'dry-run'}")
    print(f"import_tag={args.import_tag}")
    print(f"bio_relation_to_clear={affected}")
    print("properties_to_remove=" + ",".join(PROPERTIES))
    if not args.write:
        return 0

    result = neo4j_query(
        config,
        """
MATCH ()-[r:BIO_RELATION]->()
WHERE r.relation_metrics_import_tag = $tag
REMOVE r.support_pmid_count,
       r.support_metric_paper_count,
       r.support_metric_coverage,
       r.support_if_max,
       r.support_if_mean,
       r.support_if_median,
       r.support_jcr_q1_count,
       r.support_jcr_q2_count,
       r.support_jcr_q3_count,
       r.support_jcr_q4_count,
       r.support_journal_count,
       r.support_publication_year_min,
       r.support_publication_year_max,
       r.relation_metrics_import_tag
RETURN count(r) AS cleared
""",
        {"tag": args.import_tag},
        timeout=180,
    )
    print(f"[OK] relation metrics rollback complete: cleared={int(result[0].get('cleared') or 0)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
