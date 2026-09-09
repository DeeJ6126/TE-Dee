from __future__ import annotations

import argparse
import csv
import json
import re
from collections import defaultdict
from pathlib import Path
from typing import Any

DEFAULT_INPUT = Path("data/processed/pubmed_metadata.jsonl")
DEFAULT_OUTPUT = Path("data/processed/pubmed_unique_journals.csv")


def normalize_text(value: Any) -> str:
    return str(value or "").strip()


def normalize_year(value: Any) -> int | None:
    if isinstance(value, int):
        return value
    text = normalize_text(value)
    return int(text) if re.fullmatch(r"\d{4}", text) else None


def iter_metadata(path: str | Path):
    with Path(path).open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, 1):
            line = line.strip()
            if not line:
                continue
            try:
                yield json.loads(line)
            except json.JSONDecodeError as exc:
                raise RuntimeError(f"Invalid JSON at {path}:{line_number}: {exc}") from exc


def build_unique_journal_rows(metadata_path: str | Path) -> list[dict[str, Any]]:
    groups: dict[tuple[str, str, str, str], dict[str, Any]] = {}
    pmids_by_group: dict[tuple[str, str, str, str], set[str]] = defaultdict(set)
    years_by_group: dict[tuple[str, str, str, str], list[int]] = defaultdict(list)

    for row in iter_metadata(metadata_path):
        journal = row.get("journal") or {}
        title = normalize_text(journal.get("title"))
        iso = normalize_text(journal.get("iso_abbreviation"))
        issn_print = normalize_text(journal.get("issn_print"))
        issn_electronic = normalize_text(journal.get("issn_electronic"))
        key = (title, iso, issn_print, issn_electronic)
        groups.setdefault(
            key,
            {
                "journal_title": title,
                "journal_iso_abbreviation": iso,
                "issn_print": issn_print,
                "issn_electronic": issn_electronic,
                "publication_year_min": "",
                "publication_year_max": "",
                "pmid_count": 0,
            },
        )
        pmid = normalize_text(row.get("pmid"))
        if pmid:
            pmids_by_group[key].add(pmid)
        year = normalize_year((row.get("publication") or {}).get("year"))
        if year is not None:
            years_by_group[key].append(year)

    rows = []
    for key, output in groups.items():
        years = years_by_group.get(key, [])
        if years:
            output["publication_year_min"] = min(years)
            output["publication_year_max"] = max(years)
        output["pmid_count"] = len(pmids_by_group.get(key, set()))
        rows.append(output)
    return sorted(rows, key=lambda item: (str(item["journal_title"]).lower(), str(item["issn_print"]), str(item["issn_electronic"])))


def write_csv(rows: list[dict[str, Any]], output_path: str | Path) -> None:
    path = Path(output_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    fieldnames = [
        "journal_title",
        "journal_iso_abbreviation",
        "issn_print",
        "issn_electronic",
        "publication_year_min",
        "publication_year_max",
        "pmid_count",
    ]
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build a unique PubMed journal/ISSN CSV for journal metric mapping.")
    parser.add_argument("--input", default=str(DEFAULT_INPUT), help="Input PubMed metadata JSONL.")
    parser.add_argument("--output", default=str(DEFAULT_OUTPUT), help="Output unique journals CSV.")
    args = parser.parse_args(argv)

    rows = build_unique_journal_rows(args.input)
    write_csv(rows, args.output)
    total_pmids = sum(int(row["pmid_count"]) for row in rows)
    print(f"[OK] wrote {len(rows)} unique journal rows covering {total_pmids} PMIDs to {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
