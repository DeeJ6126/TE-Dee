from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path


DEFAULT_MAPPING = Path("data/reference/journal_metrics.csv")
DEFAULT_METADATA = Path("data/processed/pubmed_metadata_with_metrics.jsonl")
DEFAULT_REPORT = Path("data/processed/journal_metrics_mapping_report.json")

ALLOWED_MATCH_METHODS = {"eissn", "issn", "title_exact", "none"}
EXPECTED_SOURCE = "impact_factor_package_2025"


def load_jsonl(path: Path) -> list[dict]:
    records: list[dict] = []
    with path.open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, 1):
            line = line.strip()
            if not line:
                continue
            try:
                records.append(json.loads(line))
            except json.JSONDecodeError as exc:
                raise AssertionError(f"invalid JSONL at {path}:{line_number}: {exc}") from exc
    return records


def assert_metric_value(value: str | int | float | None, context: str) -> None:
    if value in ("", None):
        return
    try:
        float(value)
    except (TypeError, ValueError) as exc:
        raise AssertionError(f"metric_value must be numeric or null at {context}: {value!r}") from exc


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Validate full journal metrics mapping outputs.")
    parser.add_argument("--mapping", default=str(DEFAULT_MAPPING))
    parser.add_argument("--metadata", default=str(DEFAULT_METADATA))
    parser.add_argument("--report", default=str(DEFAULT_REPORT))
    args = parser.parse_args(argv)

    mapping_path = Path(args.mapping)
    metadata_path = Path(args.metadata)
    report_path = Path(args.report)
    assert mapping_path.exists(), f"missing mapping CSV: {mapping_path}"
    assert metadata_path.exists(), f"missing metadata JSONL: {metadata_path}"
    assert report_path.exists(), f"missing report JSON: {report_path}"

    with mapping_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        expected_fields = [
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
        assert reader.fieldnames == expected_fields, f"unexpected mapping fields: {reader.fieldnames}"
        mapping_rows = list(reader)

    assert mapping_rows, "mapping CSV has no rows"
    for row_number, row in enumerate(mapping_rows, 2):
        match_method = (row.get("match_method") or "").strip()
        metric_source = (row.get("metric_source") or "").strip()
        metric_name = (row.get("metric_name") or "").strip()
        assert match_method in ALLOWED_MATCH_METHODS, f"invalid match_method at row {row_number}: {match_method}"
        assert_metric_value(row.get("metric_value"), f"mapping row {row_number}")
        if match_method == "none":
            assert not metric_source, f"unmatched mapping row has metric_source at row {row_number}"
        else:
            assert metric_source == EXPECTED_SOURCE, f"invalid metric_source at row {row_number}: {metric_source}"
            assert metric_name == "Journal Impact Factor", f"invalid metric_name at row {row_number}: {metric_name}"

    metadata_records = load_jsonl(metadata_path)
    assert metadata_records, "metadata_with_metrics JSONL has no records"
    pmid_matched = 0
    for index, record in enumerate(metadata_records, 1):
        journal_metrics = record.get("journal_metrics")
        assert isinstance(journal_metrics, dict), f"journal_metrics missing or not object at metadata row {index}"
        match_method = journal_metrics.get("match_method")
        assert match_method in ALLOWED_MATCH_METHODS, f"invalid metadata match_method at row {index}: {match_method}"
        assert_metric_value(journal_metrics.get("metric_value"), f"metadata row {index}")
        if match_method == "none":
            assert journal_metrics.get("metric_source") is None, f"unmatched metadata has metric_source at row {index}"
            assert journal_metrics.get("metric_name") is None, f"unmatched metadata has metric_name at row {index}"
            assert journal_metrics.get("metric_year") is None, f"unmatched metadata has metric_year at row {index}"
        else:
            pmid_matched += 1
            assert journal_metrics.get("metric_source") == EXPECTED_SOURCE, (
                f"invalid metadata metric_source at row {index}: {journal_metrics.get('metric_source')}"
            )
            assert journal_metrics.get("metric_name") == "Journal Impact Factor", (
                f"invalid metadata metric_name at row {index}: {journal_metrics.get('metric_name')}"
            )
            assert journal_metrics.get("metric_year") == 2025, (
                f"invalid metadata metric_year at row {index}: {journal_metrics.get('metric_year')}"
            )

    report = json.loads(report_path.read_text(encoding="utf-8"))
    unique_total = report["unique_journal_total"]
    matched_total = report["matched_journal_count"]
    unmatched_total = report["unmatched_journal_count"]
    assert matched_total + unmatched_total == unique_total, "journal matched + unmatched != total"
    assert unique_total == len(mapping_rows), "report unique total != mapping rows"
    assert report["pmid_total"] == len(metadata_records), "report PMID total != metadata rows"
    assert report["pmid_matched_count"] == pmid_matched, "report PMID matched count mismatch"
    assert report["pmid_matched_count"] + report["pmid_unmatched_count"] == report["pmid_total"], (
        "PMID matched + unmatched != total"
    )

    print(
        "[OK] journal metrics full mapping passed: "
        f"journals={unique_total}, matched={matched_total}, "
        f"pmids={report['pmid_total']}, pmid_matched={report['pmid_matched_count']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
