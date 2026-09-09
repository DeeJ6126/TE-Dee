from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

from harness_lib import fail, neo4j_config, neo4j_database_name, neo4j_query, ok, require, run_check


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_INPUT = ROOT / "data" / "processed" / "pubmed_metadata_with_metrics.jsonl"
EXPECTED_PAPER_COUNT = 2308
EXPECTED_SOURCE = "impact_factor_package_2025"


def normalize_pmid(value: Any) -> str:
    text = str(value or "").strip()
    return text if re.fullmatch(r"\d+", text) else ""


def load_metadata(path: Path) -> list[dict[str, Any]]:
    require(path.exists(), f"missing metadata JSONL: {path}")
    records: list[dict[str, Any]] = []
    seen: set[str] = set()
    with path.open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, 1):
            line = line.strip()
            if not line:
                continue
            try:
                record = json.loads(line)
            except json.JSONDecodeError as exc:
                fail(f"invalid JSONL at line {line_number}: {exc}")
            pmid = normalize_pmid(record.get("pmid"))
            require(pmid, f"invalid or missing PMID at metadata line {line_number}")
            require(pmid not in seen, f"duplicate PMID in metadata JSONL: {pmid}")
            seen.add(pmid)
            records.append(record)
    require(records, "metadata JSONL has no records")
    return records


def metric_counts(records: list[dict[str, Any]]) -> tuple[int, int]:
    matched = 0
    unmatched = 0
    for record in records:
        metrics = record.get("journal_metrics") if isinstance(record.get("journal_metrics"), dict) else {}
        if metrics.get("metric_source") == EXPECTED_SOURCE:
            matched += 1
        else:
            unmatched += 1
    return matched, unmatched


def main(argv: list[str] | None = None) -> None:
    parser = argparse.ArgumentParser(description="Read-only preflight for journal metrics Neo4j import.")
    parser.add_argument("--input", default=str(DEFAULT_INPUT))
    args = parser.parse_args(argv)

    config = neo4j_config()
    database = neo4j_database_name(config)
    require(database == "tekg3", f"Neo4j target must be tekg3, got {database or 'unknown'}")
    ok("Neo4j target is tekg3")

    ping = neo4j_query(config, "RETURN 1 AS ok")
    require(int(ping[0].get("ok") or 0) == 1, "Neo4j RETURN 1 failed")
    ok("Neo4j connection works")

    records = load_metadata(Path(args.input))
    matched, unmatched = metric_counts(records)
    ok(f"metadata_with_metrics JSONL records: {len(records)}")
    ok(f"metadata metrics: matched={matched}, unmatched={unmatched}")

    paper_rows = neo4j_query(
        config,
        """
MATCH (p:Paper)
WHERE coalesce(p.pmid, '') <> ''
RETURN count(p) AS paper_count, count(DISTINCT p.pmid) AS distinct_pmids, collect(p.pmid) AS pmids
""",
        timeout=120,
    )
    paper_row = paper_rows[0]
    paper_count = int(paper_row.get("paper_count") or 0)
    distinct_pmids = int(paper_row.get("distinct_pmids") or 0)
    paper_pmids = {normalize_pmid(pmid) for pmid in (paper_row.get("pmids") or []) if normalize_pmid(pmid)}
    metadata_pmids = {normalize_pmid(record.get("pmid")) for record in records}
    require(paper_count == EXPECTED_PAPER_COUNT, f"expected {EXPECTED_PAPER_COUNT} Paper nodes, got {paper_count}")
    require(distinct_pmids == EXPECTED_PAPER_COUNT, f"expected {EXPECTED_PAPER_COUNT} distinct Paper PMIDs, got {distinct_pmids}")
    ok(f"Paper.pmid count: {paper_count}, distinct={distinct_pmids}")

    matched_pmids = metadata_pmids & paper_pmids
    missing_papers = sorted(metadata_pmids - paper_pmids, key=int)
    extra_papers = sorted(paper_pmids - metadata_pmids, key=int)
    require(not missing_papers, f"metadata PMIDs missing Paper nodes: {missing_papers[:20]}")
    require(not extra_papers, f"Paper PMIDs missing metadata records: {extra_papers[:20]}")
    ok(f"metadata PMID to Paper.pmid match: {len(matched_pmids)}/{len(metadata_pmids)}")

    existing = neo4j_query(
        config,
        """
MATCH (p:Paper)
WHERE p.journal_metrics_import_tag IS NOT NULL
   OR p.journal_metric_source IS NOT NULL
   OR p.journal_metric_value IS NOT NULL
RETURN count(p) AS count
""",
        timeout=120,
    )
    existing_count = int(existing[0].get("count") or 0)
    require(existing_count == 0, f"existing Paper journal metric properties found on {existing_count} nodes")
    ok("no existing Paper journal metric import properties")

    bad_rel_pmids = neo4j_query(
        config,
        """
MATCH ()-[r:BIO_RELATION]->()
UNWIND coalesce(r.pmids, []) AS pmid
WITH trim(toString(pmid)) AS value
WHERE value <> '' AND NOT value =~ '\\d+'
RETURN collect(value)[0..20] AS bad_values, count(value) AS count
""",
        timeout=120,
    )
    bad_count = int(bad_rel_pmids[0].get("count") or 0)
    require(bad_count == 0, f"BIO_RELATION.pmids contains non-numeric values: {bad_rel_pmids[0].get('bad_values')}")
    ok("BIO_RELATION.pmids values are numeric strings")


if __name__ == "__main__":
    run_check(lambda: main(sys.argv[1:]))
