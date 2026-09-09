from __future__ import annotations

import argparse
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
CHECKS = ROOT / "scripts" / "checks"
if str(CHECKS) not in sys.path:
    sys.path.insert(0, str(CHECKS))

from harness_lib import fail, neo4j_config, neo4j_database_name, neo4j_query  # noqa: E402


DEFAULT_INPUT = ROOT / "data" / "processed" / "pubmed_metadata_with_metrics.jsonl"
EXPECTED_SOURCE = "impact_factor_package_2025"
METRIC_NAME = "Journal Impact Factor"
METRIC_YEAR = 2025
PAPER_PROPERTIES = [
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


def normalize_pmid(value: Any) -> str:
    text = str(value or "").strip()
    return text if re.fullmatch(r"\d+", text) else ""


def load_jsonl(path: Path) -> list[dict[str, Any]]:
    if not path.exists():
        fail(f"missing metadata JSONL: {path}")
    records: list[dict[str, Any]] = []
    seen: set[str] = set()
    with path.open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, 1):
            line = line.strip()
            if not line:
                continue
            record = json.loads(line)
            pmid = normalize_pmid(record.get("pmid"))
            if not pmid:
                fail(f"invalid PMID at line {line_number}")
            if pmid in seen:
                fail(f"duplicate PMID in input: {pmid}")
            seen.add(pmid)
            records.append(record)
    return records


def flatten_record(record: dict[str, Any], import_tag: str) -> dict[str, Any]:
    journal = record.get("journal") if isinstance(record.get("journal"), dict) else {}
    publication = record.get("publication") if isinstance(record.get("publication"), dict) else {}
    metrics = record.get("journal_metrics") if isinstance(record.get("journal_metrics"), dict) else {}
    metric_source = metrics.get("metric_source")
    metric_value = metrics.get("metric_value")
    matched = metric_source == EXPECTED_SOURCE and metric_value is not None
    return {
        "pmid": normalize_pmid(record.get("pmid")),
        "pubmed_title": record.get("title") or "",
        "pubmed_doi": record.get("doi") or "",
        "pubmed_journal_title": journal.get("title") or "",
        "pubmed_publication_year": publication.get("year"),
        "journal_metric_value": float(metric_value) if matched else None,
        "journal_metric_source": EXPECTED_SOURCE if matched else None,
        "journal_metric_name": METRIC_NAME if matched else None,
        "journal_metric_year": METRIC_YEAR if matched else None,
        "journal_jcr_quartile": metrics.get("jcr_quartile") if matched else None,
        "journal_cas_partition": metrics.get("cas_partition") if matched else None,
        "journal_metric_match_method": metrics.get("match_method") or "none",
        "journal_metrics_import_tag": import_tag,
    }


def chunks(rows: list[dict[str, Any]], batch_size: int) -> list[list[dict[str, Any]]]:
    return [rows[index:index + batch_size] for index in range(0, len(rows), batch_size)]


def get_paper_pmids(config: dict[str, str]) -> set[str]:
    rows = neo4j_query(
        config,
        "MATCH (p:Paper) WHERE coalesce(p.pmid, '') <> '' RETURN collect(p.pmid) AS pmids",
        timeout=120,
    )
    return {normalize_pmid(pmid) for pmid in (rows[0].get("pmids") or []) if normalize_pmid(pmid)}


def write_batch(config: dict[str, str], rows: list[dict[str, Any]]) -> int:
    result = neo4j_query(
        config,
        """
UNWIND $rows AS row
MATCH (p:Paper {pmid: row.pmid})
SET p.pubmed_title = row.pubmed_title,
    p.pubmed_doi = row.pubmed_doi,
    p.pubmed_journal_title = row.pubmed_journal_title,
    p.pubmed_publication_year = row.pubmed_publication_year,
    p.journal_metric_value = row.journal_metric_value,
    p.journal_metric_source = row.journal_metric_source,
    p.journal_metric_name = row.journal_metric_name,
    p.journal_metric_year = row.journal_metric_year,
    p.journal_jcr_quartile = row.journal_jcr_quartile,
    p.journal_cas_partition = row.journal_cas_partition,
    p.journal_metric_match_method = row.journal_metric_match_method,
    p.journal_metrics_import_tag = row.journal_metrics_import_tag
RETURN count(p) AS updated
""",
        {"rows": rows},
        timeout=120,
    )
    return int(result[0].get("updated") or 0)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Import PubMed journal metrics into existing Neo4j Paper nodes.")
    parser.add_argument("--input", default=str(DEFAULT_INPUT))
    parser.add_argument("--batch-size", type=int, default=500)
    parser.add_argument("--import-tag", default="")
    parser.add_argument("--write", action="store_true", help="Perform writes. Default is dry-run.")
    args = parser.parse_args(argv)

    import_tag = args.import_tag.strip() or "journal_metrics_v1_" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    records = load_jsonl(Path(args.input))
    rows = [flatten_record(record, import_tag) for record in records]
    config = neo4j_config()
    database = neo4j_database_name(config)
    if database != "tekg3":
        fail(f"Neo4j target must be tekg3, got {database or 'unknown'}")

    paper_pmids = get_paper_pmids(config)
    input_pmids = {row["pmid"] for row in rows}
    missing_papers = sorted(input_pmids - paper_pmids, key=int)
    rows_to_update = [row for row in rows if row["pmid"] in paper_pmids]
    metric_rows = [row for row in rows_to_update if row["journal_metric_source"] == EXPECTED_SOURCE]
    null_metric_rows = [row for row in rows_to_update if row["journal_metric_source"] is None]

    print(f"mode={'write' if args.write else 'dry-run'}")
    print(f"import_tag={import_tag}")
    print(f"input_records={len(records)}")
    print(f"paper_updates_planned={len(rows_to_update)}")
    print(f"paper_with_metric={len(metric_rows)}")
    print(f"paper_without_metric={len(null_metric_rows)}")
    print(f"missing_paper_pmids={len(missing_papers)}")
    if missing_papers:
        print("missing_paper_pmid_examples=" + ",".join(missing_papers[:20]))
    print("properties=" + ",".join(PAPER_PROPERTIES))

    if not args.write:
        return 0

    updated = 0
    for batch in chunks(rows_to_update, max(1, args.batch_size)):
        updated += write_batch(config, batch)
        print(f"updated_progress={updated}/{len(rows_to_update)}")
    print(f"[OK] Paper enrichment write complete: updated={updated}, import_tag={import_tag}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
