from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

from harness_lib import neo4j_config, neo4j_database_name, neo4j_query, ok, require, run_check


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_INPUT = ROOT / "data" / "processed" / "pubmed_metadata_with_metrics.jsonl"
EXPECTED_SOURCE = "impact_factor_package_2025"
EXPECTED_YEAR = 2025
EXPECTED_PAPER_COUNT = 2308


def normalize_pmid(value: Any) -> str:
    text = str(value or "").strip()
    return text if re.fullmatch(r"\d+", text) else ""


def expected_counts(path: Path) -> tuple[int, int, int]:
    total = matched = unmatched = 0
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            total += 1
            record = json.loads(line)
            metrics = record.get("journal_metrics") if isinstance(record.get("journal_metrics"), dict) else {}
            if metrics.get("metric_source") == EXPECTED_SOURCE and metrics.get("metric_value") is not None:
                matched += 1
            else:
                unmatched += 1
    return total, matched, unmatched


def main(argv: list[str] | None = None) -> None:
    parser = argparse.ArgumentParser(description="Verify journal metrics Paper enrichment in Neo4j.")
    parser.add_argument("--input", default=str(DEFAULT_INPUT))
    parser.add_argument("--import-tag", required=True)
    args = parser.parse_args(argv)

    expected_total, expected_matched, expected_unmatched = expected_counts(Path(args.input))
    config = neo4j_config()
    database = neo4j_database_name(config)
    require(database == "tekg3", f"Neo4j target must be tekg3, got {database or 'unknown'}")
    ok("Neo4j target is tekg3")

    paper_total = neo4j_query(config, "MATCH (p:Paper) RETURN count(p) AS count", timeout=120)
    require(int(paper_total[0].get("count") or 0) == EXPECTED_PAPER_COUNT, "unexpected Paper count")
    ok(f"Paper count remains {EXPECTED_PAPER_COUNT}")

    counts = neo4j_query(
        config,
        """
MATCH (p:Paper)
RETURN
  count(CASE WHEN p.journal_metrics_import_tag = $tag THEN p END) AS tagged,
  count(CASE WHEN p.journal_metric_source = $source THEN p END) AS source_count,
  count(CASE WHEN p.journal_metric_year = $year THEN p END) AS year_count,
  count(CASE WHEN p.journal_metrics_import_tag = $tag AND p.journal_metric_source IS NULL THEN p END) AS null_metric_count,
  count(CASE WHEN p.journal_metrics_import_tag = $tag AND p.journal_metric_value IS NOT NULL THEN p END) AS value_count
""",
        {"tag": args.import_tag, "source": EXPECTED_SOURCE, "year": EXPECTED_YEAR},
        timeout=120,
    )[0]
    tagged = int(counts.get("tagged") or 0)
    source_count = int(counts.get("source_count") or 0)
    year_count = int(counts.get("year_count") or 0)
    null_metric_count = int(counts.get("null_metric_count") or 0)
    value_count = int(counts.get("value_count") or 0)

    require(tagged == expected_total, f"tagged Paper count {tagged} != expected {expected_total}")
    require(source_count == expected_matched, f"metric source count {source_count} != expected {expected_matched}")
    require(year_count == expected_matched, f"metric year count {year_count} != expected {expected_matched}")
    require(value_count == expected_matched, f"metric value count {value_count} != expected {expected_matched}")
    require(null_metric_count == expected_unmatched, f"null metric count {null_metric_count} != expected {expected_unmatched}")
    ok(f"Paper enrichment counts: tagged={tagged}, with_metric={source_count}, without_metric={null_metric_count}")

    polluted = neo4j_query(
        config,
        """
MATCH (n)
WHERE NOT n:Paper AND (
  n.journal_metrics_import_tag IS NOT NULL OR
  n.journal_metric_source IS NOT NULL OR
  n.journal_metric_value IS NOT NULL
)
RETURN count(n) AS count
""",
        timeout=120,
    )
    require(int(polluted[0].get("count") or 0) == 0, "journal metric properties found on non-Paper nodes")
    ok("no journal metric properties on non-Paper nodes")

    sample = neo4j_query(
        config,
        """
MATCH (p:Paper)
WHERE p.journal_metrics_import_tag = $tag
RETURN p.pmid AS pmid,
       p.pubmed_title AS title,
       p.pubmed_journal_title AS journal,
       p.journal_metric_source AS metric_source,
       p.journal_metric_value AS metric_value
ORDER BY p.pmid
LIMIT 3
""",
        {"tag": args.import_tag},
        timeout=120,
    )
    require(len(sample) == 3, "expected sample Paper records")
    ok("sample Paper records readable: " + ", ".join(row["pmid"] for row in sample))


if __name__ == "__main__":
    run_check(lambda: main(sys.argv[1:]))
