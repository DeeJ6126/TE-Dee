from __future__ import annotations

import argparse
import csv
import json
import re
from pathlib import Path

DEFAULT_METADATA = Path("data/processed/pubmed_metadata.jsonl")
DEFAULT_CSV = Path("data/processed/pubmed_unique_journals.csv")
ISSN_RE = re.compile(r"^\d{4}-\d{3}[\dXx]$")


def count_metadata_records(path: Path) -> int:
    count = 0
    with path.open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, 1):
            line = line.strip()
            if not line:
                continue
            json.loads(line)
            count += 1
    return count


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Check PubMed unique journals CSV.")
    parser.add_argument("--metadata", default=str(DEFAULT_METADATA))
    parser.add_argument("--csv", default=str(DEFAULT_CSV))
    args = parser.parse_args(argv)

    metadata_path = Path(args.metadata)
    csv_path = Path(args.csv)
    assert metadata_path.exists(), f"missing metadata JSONL: {metadata_path}"
    assert csv_path.exists(), f"missing unique journals CSV: {csv_path}"

    expected_records = count_metadata_records(metadata_path)
    pmid_total = 0
    row_count = 0
    with csv_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        required = {
            "journal_title",
            "journal_iso_abbreviation",
            "issn_print",
            "issn_electronic",
            "publication_year_min",
            "publication_year_max",
            "pmid_count",
        }
        assert set(reader.fieldnames or []) == required, f"unexpected CSV fields: {reader.fieldnames}"
        for row_number, row in enumerate(reader, 2):
            row_count += 1
            title = (row.get("journal_title") or "").strip()
            assert title, f"journal_title is empty at CSV row {row_number}"
            for field in ("issn_print", "issn_electronic"):
                value = (row.get(field) or "").strip()
                assert not value or ISSN_RE.fullmatch(value), f"invalid {field} at row {row_number}: {value}"
            pmid_count = int(row.get("pmid_count") or "0")
            assert pmid_count > 0, f"pmid_count must be positive at row {row_number}"
            pmid_total += pmid_count
    assert row_count > 0, "unique journals CSV has no rows"
    assert pmid_total == expected_records, (
        f"pmid_count sum {pmid_total} != metadata record count {expected_records}; "
        "difference would indicate dropped or double-counted metadata rows"
    )
    print(f"[OK] PubMed unique journals CSV passed: rows={row_count}, pmid_total={pmid_total}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
