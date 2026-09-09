from __future__ import annotations

import argparse
import csv
import json
import sqlite3
import sys
from pathlib import Path
from typing import Any


DEFAULT_INPUT = Path("data/processed/jcr_lookup_top100.csv")
DEFAULT_OUTPUT = Path("data/processed/impact_factor_probe_top10.csv")
DEFAULT_REFERENCE = Path("reference/external_examples/impact_factor")
DEFAULT_SCRATCH_DEPS = Path.home() / ".codex" / "memories" / "impact_factor_probe_pkg"

OUTPUT_FIELDS = [
    "journal_title",
    "journal_iso_abbreviation",
    "issn_print",
    "issn_electronic",
    "lookup_key",
    "matched",
    "matched_journal_title",
    "matched_issn",
    "matched_eissn",
    "metric_value",
    "metric_source",
    "metric_name",
    "metric_year",
    "jcr_quartile",
    "cas_partition",
    "match_method",
    "raw_result_summary",
]


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


def normalize(value: str | None) -> str:
    return (value or "").strip()


def summarize_result(record: dict[str, Any]) -> str:
    summary = {
        "journal": record.get("journal"),
        "journal_abbr": record.get("journal_abbr"),
        "issn": record.get("issn"),
        "eissn": record.get("eissn"),
        "factor": record.get("factor"),
        "jcr": record.get("jcr"),
        "zky": record.get("zky"),
        "nlm_id": record.get("nlm_id"),
    }
    return json.dumps(summary, ensure_ascii=False, sort_keys=True)


def choose_match(factor: Any, source_row: dict[str, str]) -> tuple[dict[str, Any] | None, str]:
    candidates = [
        ("eissn", normalize(source_row.get("issn_electronic"))),
        ("issn", normalize(source_row.get("issn_print"))),
        ("lookup_key", normalize(source_row.get("lookup_key_preferred"))),
        ("journal", normalize(source_row.get("journal_title"))),
    ]
    for method, value in candidates:
        if not value:
            continue
        key = None if method == "lookup_key" else method
        results = factor.search(value, key=key)
        if results:
            return results[0], method
    return None, ""


def build_probe(input_path: Path, output_path: Path, reference_dir: Path, deps_dir: Path, limit: int) -> None:
    factor = load_factor(reference_dir, deps_dir)
    with input_path.open("r", encoding="utf-8", newline="") as handle:
        rows = list(csv.DictReader(handle))[:limit]

    output_rows: list[dict[str, str]] = []
    for row in rows:
        match, method = choose_match(factor, row)
        base = {
            "journal_title": normalize(row.get("journal_title")),
            "journal_iso_abbreviation": normalize(row.get("journal_iso_abbreviation")),
            "issn_print": normalize(row.get("issn_print")),
            "issn_electronic": normalize(row.get("issn_electronic")),
            "lookup_key": normalize(row.get("lookup_key_preferred")),
            "metric_source": "impact_factor_package_exploratory",
            "metric_name": "Journal Impact Factor",
            "metric_year": "",
        }
        if match:
            output_rows.append(
                {
                    **base,
                    "matched": "true",
                    "matched_journal_title": normalize(match.get("journal")),
                    "matched_issn": normalize(match.get("issn")),
                    "matched_eissn": normalize(match.get("eissn")),
                    "metric_value": "" if match.get("factor") is None else str(match.get("factor")),
                    "jcr_quartile": normalize(match.get("jcr")),
                    "cas_partition": normalize(match.get("zky")),
                    "match_method": method,
                    "raw_result_summary": summarize_result(match),
                }
            )
        else:
            output_rows.append(
                {
                    **base,
                    "matched": "false",
                    "matched_journal_title": "",
                    "matched_issn": "",
                    "matched_eissn": "",
                    "metric_value": "",
                    "jcr_quartile": "",
                    "cas_partition": "",
                    "match_method": "none",
                    "raw_result_summary": "",
                }
            )

    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=OUTPUT_FIELDS)
        writer.writeheader()
        writer.writerows(output_rows)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Probe local impact_factor package with top journal lookup keys.")
    parser.add_argument("--input", default=str(DEFAULT_INPUT))
    parser.add_argument("--output", default=str(DEFAULT_OUTPUT))
    parser.add_argument("--reference", default=str(DEFAULT_REFERENCE))
    parser.add_argument("--deps", default=str(DEFAULT_SCRATCH_DEPS))
    parser.add_argument("--limit", type=int, default=10)
    args = parser.parse_args(argv)

    build_probe(
        input_path=Path(args.input),
        output_path=Path(args.output),
        reference_dir=Path(args.reference),
        deps_dir=Path(args.deps),
        limit=args.limit,
    )
    print(f"[OK] impact_factor probe wrote {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
