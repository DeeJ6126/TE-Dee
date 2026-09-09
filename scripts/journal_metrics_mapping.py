from __future__ import annotations

import argparse
import csv
import json
import re
import sqlite3
import sys
from collections import Counter
from copy import deepcopy
from pathlib import Path
from typing import Any


DEFAULT_UNIQUE_JOURNALS = Path("data/processed/pubmed_unique_journals.csv")
DEFAULT_METADATA = Path("data/processed/pubmed_metadata.jsonl")
DEFAULT_MAPPING = Path("data/reference/journal_metrics.csv")
DEFAULT_REPORT = Path("data/processed/journal_metrics_mapping_report.json")
DEFAULT_METADATA_WITH_METRICS = Path("data/processed/pubmed_metadata_with_metrics.jsonl")
DEFAULT_REFERENCE = Path("reference/external_examples/impact_factor")
DEFAULT_SCRATCH_DEPS = Path.home() / ".codex" / "memories" / "impact_factor_probe_pkg"

METRIC_SOURCE = "impact_factor_package_2025"
METRIC_NAME = "Journal Impact Factor"
METRIC_YEAR = 2025

MAPPING_FIELDS = [
    "issn",
    "year",
    "metric_value",
    "metric_source",
    "metric_name",
    "journal_title",
    "journal_title_matched",
    "matched_issn",
    "matched_eissn",
    "jcr_quartile",
    "cas_partition",
    "match_method",
]


def normalize_text(value: str | None) -> str:
    text = (value or "").strip().lower()
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def normalize_issn(value: str | None) -> str:
    return (value or "").strip()


def add_import_paths(reference_dir: Path, deps_dir: Path) -> None:
    for path in (deps_dir, reference_dir):
        if path.exists():
            sys.path.insert(0, str(path.resolve()))


def load_factor(reference_dir: Path, deps_dir: Path) -> Any:
    add_import_paths(reference_dir, deps_dir)
    try:
        from impact_factor.core import Factor  # type: ignore

        return Factor()
    except Exception:
        return LocalImpactFactorSearch(reference_dir / "impact_factor" / "data" / "impact_factor.sqlite3")


class LocalImpactFactorSearch:
    def __init__(self, db_path: Path):
        self.db_path = db_path

    def search(self, value: str, key: str | None = None) -> list[dict[str, Any]]:
        if not value:
            return []
        default_keys = ["issn", "eissn", "nlm_id", "journal", "journal_abbr"]
        keys = [key] if key else default_keys
        with sqlite3.connect(self.db_path) as connection:
            connection.row_factory = sqlite3.Row
            for field in keys:
                if "%" in value:
                    cursor = connection.execute(f"SELECT * FROM factor WHERE {field} LIKE ?", (value,))
                else:
                    cursor = connection.execute(f"SELECT * FROM factor WHERE lower({field}) = lower(?)", (value,))
                rows = [dict(row) for row in cursor.fetchall()]
                if rows:
                    return rows
        return []


def load_csv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def load_jsonl(path: Path) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if line:
                records.append(json.loads(line))
    return records


def first_result(factor: Any, value: str, key: str) -> dict[str, Any] | None:
    if not value:
        return None
    results = factor.search(value, key=key)
    return results[0] if results else None


def find_match(factor: Any, row: dict[str, str]) -> tuple[dict[str, Any] | None, str]:
    eissn = normalize_issn(row.get("issn_electronic"))
    match = first_result(factor, eissn, "eissn")
    if match:
        return match, "eissn"

    issn = normalize_issn(row.get("issn_print"))
    match = first_result(factor, issn, "issn")
    if match:
        return match, "issn"

    title = (row.get("journal_title") or "").strip()
    if title:
        candidates = factor.search(title, key="journal")
        source_title = normalize_text(title)
        exact = [candidate for candidate in candidates if normalize_text(candidate.get("journal")) == source_title]
        if len(exact) == 1:
            return exact[0], "title_exact"

    return None, "none"


def metric_value(match: dict[str, Any] | None) -> float | None:
    if not match or match.get("factor") in ("", None):
        return None
    return float(match["factor"])


def make_mapping_row(source: dict[str, str], match: dict[str, Any] | None, method: str) -> dict[str, Any]:
    primary_issn = normalize_issn(source.get("issn_electronic")) or normalize_issn(source.get("issn_print"))
    if not match:
        return {
            "issn": primary_issn,
            "year": "",
            "metric_value": "",
            "metric_source": "",
            "metric_name": "",
            "journal_title": source.get("journal_title") or "",
            "journal_title_matched": "",
            "matched_issn": "",
            "matched_eissn": "",
            "jcr_quartile": "",
            "cas_partition": "",
            "match_method": "none",
        }

    value = metric_value(match)
    return {
        "issn": primary_issn,
        "year": str(METRIC_YEAR),
        "metric_value": "" if value is None else str(value),
        "metric_source": METRIC_SOURCE,
        "metric_name": METRIC_NAME,
        "journal_title": source.get("journal_title") or "",
        "journal_title_matched": match.get("journal") or "",
        "matched_issn": match.get("issn") or "",
        "matched_eissn": match.get("eissn") or "",
        "jcr_quartile": match.get("jcr") or "",
        "cas_partition": match.get("zky") or "",
        "match_method": method,
    }


def journal_key_from_unique(row: dict[str, str]) -> str:
    return "\t".join(
        [
            normalize_text(row.get("journal_title")),
            normalize_issn(row.get("issn_print")),
            normalize_issn(row.get("issn_electronic")),
        ]
    )


def journal_key_from_metadata(record: dict[str, Any]) -> str:
    journal = record.get("journal") if isinstance(record.get("journal"), dict) else {}
    return "\t".join(
        [
            normalize_text(journal.get("title")),
            normalize_issn(journal.get("issn_print")),
            normalize_issn(journal.get("issn_electronic")),
        ]
    )


def metric_payload(row: dict[str, Any]) -> dict[str, Any]:
    if row["match_method"] == "none":
        return {
            "metric_value": None,
            "metric_source": None,
            "metric_name": None,
            "metric_year": None,
            "jcr_quartile": None,
            "cas_partition": None,
            "match_method": "none",
        }
    return {
        "metric_value": float(row["metric_value"]) if row["metric_value"] != "" else None,
        "metric_source": METRIC_SOURCE,
        "metric_name": METRIC_NAME,
        "metric_year": METRIC_YEAR,
        "jcr_quartile": row["jcr_quartile"] or None,
        "cas_partition": row["cas_partition"] or None,
        "match_method": row["match_method"],
    }


def build_outputs(
    unique_journals_path: Path,
    metadata_path: Path,
    mapping_path: Path,
    report_path: Path,
    metadata_with_metrics_path: Path,
    reference_dir: Path,
    deps_dir: Path,
) -> dict[str, Any]:
    factor = load_factor(reference_dir, deps_dir)
    unique_rows = load_csv(unique_journals_path)
    metadata_records = load_jsonl(metadata_path)

    mapping_rows: list[dict[str, Any]] = []
    metrics_by_journal_key: dict[str, dict[str, Any]] = {}
    unmatched_journals: list[dict[str, Any]] = []
    title_fallbacks: list[dict[str, Any]] = []

    for row in unique_rows:
        match, method = find_match(factor, row)
        mapping_row = make_mapping_row(row, match, method)
        mapping_rows.append(mapping_row)
        metrics_by_journal_key[journal_key_from_unique(row)] = mapping_row
        if method == "none":
            unmatched_journals.append(
                {
                    "journal_title": row.get("journal_title") or "",
                    "journal_iso_abbreviation": row.get("journal_iso_abbreviation") or "",
                    "issn_print": row.get("issn_print") or "",
                    "issn_electronic": row.get("issn_electronic") or "",
                    "pmid_count": int(row.get("pmid_count") or 0),
                }
            )
        elif method == "title_exact":
            title_fallbacks.append(
                {
                    "journal_title": row.get("journal_title") or "",
                    "journal_title_matched": mapping_row["journal_title_matched"],
                    "issn_print": row.get("issn_print") or "",
                    "issn_electronic": row.get("issn_electronic") or "",
                    "matched_issn": mapping_row["matched_issn"],
                    "matched_eissn": mapping_row["matched_eissn"],
                    "metric_value": mapping_row["metric_value"],
                }
            )

    mapping_path.parent.mkdir(parents=True, exist_ok=True)
    with mapping_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=MAPPING_FIELDS)
        writer.writeheader()
        writer.writerows(mapping_rows)

    pmid_matched = 0
    pmid_unmatched = 0
    metadata_with_metrics_path.parent.mkdir(parents=True, exist_ok=True)
    with metadata_with_metrics_path.open("w", encoding="utf-8") as handle:
        for record in metadata_records:
            out = deepcopy(record)
            mapping_row = metrics_by_journal_key.get(journal_key_from_metadata(record))
            if mapping_row is None:
                mapping_row = {"match_method": "none"}
            payload = metric_payload(mapping_row)
            out["journal_metrics"] = payload
            if payload["match_method"] == "none":
                pmid_unmatched += 1
            else:
                pmid_matched += 1
            handle.write(json.dumps(out, ensure_ascii=False, sort_keys=True) + "\n")

    method_counts = Counter(row["match_method"] for row in mapping_rows)
    factor_count = sum(1 for row in mapping_rows if row["metric_value"] != "")
    jcr_count = sum(1 for row in mapping_rows if row["jcr_quartile"] not in ("", "."))
    cas_count = sum(1 for row in mapping_rows if row["cas_partition"] not in ("", "."))
    report = {
        "unique_journal_total": len(mapping_rows),
        "matched_journal_count": sum(1 for row in mapping_rows if row["match_method"] != "none"),
        "unmatched_journal_count": sum(1 for row in mapping_rows if row["match_method"] == "none"),
        "pmid_total": len(metadata_records),
        "pmid_matched_count": pmid_matched,
        "pmid_unmatched_count": pmid_unmatched,
        "eissn_match_count": method_counts["eissn"],
        "issn_match_count": method_counts["issn"],
        "title_exact_match_count": method_counts["title_exact"],
        "factor_coverage_count": factor_count,
        "jcr_quartile_coverage_count": jcr_count,
        "cas_partition_coverage_count": cas_count,
        "unmatched_journals_top50": sorted(
            unmatched_journals,
            key=lambda item: (-item["pmid_count"], item["journal_title"].lower()),
        )[:50],
        "title_exact_fallback_matches": title_fallbacks,
    }
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return report


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build journal metric mapping from local impact_factor data.")
    parser.add_argument("--unique-journals", default=str(DEFAULT_UNIQUE_JOURNALS))
    parser.add_argument("--metadata", default=str(DEFAULT_METADATA))
    parser.add_argument("--mapping", default=str(DEFAULT_MAPPING))
    parser.add_argument("--report", default=str(DEFAULT_REPORT))
    parser.add_argument("--metadata-with-metrics", default=str(DEFAULT_METADATA_WITH_METRICS))
    parser.add_argument("--reference", default=str(DEFAULT_REFERENCE))
    parser.add_argument("--deps", default=str(DEFAULT_SCRATCH_DEPS))
    args = parser.parse_args(argv)

    report = build_outputs(
        unique_journals_path=Path(args.unique_journals),
        metadata_path=Path(args.metadata),
        mapping_path=Path(args.mapping),
        report_path=Path(args.report),
        metadata_with_metrics_path=Path(args.metadata_with_metrics),
        reference_dir=Path(args.reference),
        deps_dir=Path(args.deps),
    )
    print(
        "[OK] journal metrics mapping wrote outputs: "
        f"journals={report['unique_journal_total']}, matched={report['matched_journal_count']}, "
        f"pmids={report['pmid_total']}, pmid_matched={report['pmid_matched_count']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
