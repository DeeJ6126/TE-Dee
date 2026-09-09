from __future__ import annotations

import argparse
import csv
from pathlib import Path


DEFAULT_PROBE = Path("data/processed/impact_factor_probe_top10.csv")
EXPECTED_FIELDS = [
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


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Validate impact_factor top10 probe CSV.")
    parser.add_argument("--csv", default=str(DEFAULT_PROBE))
    args = parser.parse_args(argv)

    path = Path(args.csv)
    assert path.exists(), f"missing probe CSV: {path}"
    with path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        assert reader.fieldnames == EXPECTED_FIELDS, f"unexpected fields: {reader.fieldnames}"
        rows = list(reader)

    assert len(rows) == 10, f"expected 10 rows, got {len(rows)}"
    matched = 0
    for index, row in enumerate(rows, 2):
        metric_source = (row.get("metric_source") or "").strip()
        metric_name = (row.get("metric_name") or "").strip()
        metric_value = (row.get("metric_value") or "").strip()
        matched_value = (row.get("matched") or "").strip().lower()

        assert metric_source, f"metric_source is empty at row {index}"
        assert metric_source != "official_jcr", f"probe must not claim official JCR at row {index}"
        assert metric_source == "impact_factor_package_exploratory", (
            f"unexpected metric_source at row {index}: {metric_source}"
        )
        assert metric_name, f"metric_name is empty at row {index}"
        assert matched_value in {"true", "false"}, f"matched must be true/false at row {index}"
        if metric_value:
            float(metric_value)
        if matched_value == "true":
            matched += 1
            assert metric_value, f"matched row has no metric_value at row {index}"
        else:
            assert not metric_value, f"unmatched row should not have metric_value at row {index}"

    print(f"[OK] impact_factor probe passed: rows={len(rows)}, matched={matched}, unmatched={len(rows) - matched}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
