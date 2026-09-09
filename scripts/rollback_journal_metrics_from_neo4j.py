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
    "pubmed_title",
    "pubmed_doi",
    "pubmed_journal_title",
    "pubmed_publication_year",
    "journal_metric_value",
    "journal_metric_source",
    "journal_metric_name",
    "journal_metric_year",
    "journal_jcr_quartile",
    "journal_cas_partition",
    "journal_metric_match_method",
    "journal_metrics_import_tag",
]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Rollback journal metrics Paper enrichment properties.")
    parser.add_argument("--import-tag", required=True)
    parser.add_argument("--write", action="store_true", help="Remove properties. Default is preview only.")
    args = parser.parse_args(argv)

    config = neo4j_config()
    database = neo4j_database_name(config)
    if database != "tekg3":
        fail(f"Neo4j target must be tekg3, got {database or 'unknown'}")

    count_rows = neo4j_query(
        config,
        "MATCH (p:Paper) WHERE p.journal_metrics_import_tag = $tag RETURN count(p) AS count",
        {"tag": args.import_tag},
        timeout=120,
    )
    affected = int(count_rows[0].get("count") or 0)
    print(f"mode={'write' if args.write else 'dry-run'}")
    print(f"import_tag={args.import_tag}")
    print(f"paper_nodes_to_clear={affected}")
    print("properties_to_remove=" + ",".join(PROPERTIES))
    if not args.write:
        return 0

    result = neo4j_query(
        config,
        """
MATCH (p:Paper)
WHERE p.journal_metrics_import_tag = $tag
REMOVE p.pubmed_title,
       p.pubmed_doi,
       p.pubmed_journal_title,
       p.pubmed_publication_year,
       p.journal_metric_value,
       p.journal_metric_source,
       p.journal_metric_name,
       p.journal_metric_year,
       p.journal_jcr_quartile,
       p.journal_cas_partition,
       p.journal_metric_match_method,
       p.journal_metrics_import_tag
RETURN count(p) AS cleared
""",
        {"tag": args.import_tag},
        timeout=120,
    )
    print(f"[OK] rollback complete: cleared={int(result[0].get('cleared') or 0)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
