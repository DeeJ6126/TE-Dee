from __future__ import annotations

import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import csv
import json
import math
from collections import defaultdict
from dataclasses import dataclass
from statistics import median
from typing import Any


from path_helpers import expression_bulk_path

BULK_ROOT = expression_bulk_path()
OUT_ROOT = BULK_ROOT / "processed"


@dataclass(frozen=True)
class DatasetConfig:
    key: str
    label: str
    matrix_path: Path
    meta_path: Path
    run_field: str
    context_field: str
    full_name_field: str | None = None
    matrix_has_te_column: bool = True
    dataset_order: int = 0


DATASETS = [
    DatasetConfig(
        key="normal_tissue",
        label="Normal Tissue",
        matrix_path=BULK_ROOT / "normal_tissue" / "Normal_tissue_TE_normalized_count.tsv",
        meta_path=BULK_ROOT / "normal_tissue" / "Normal_tissue_meta.csv",
        run_field="Run",
        context_field="Organ",
        dataset_order=1,
    ),
    DatasetConfig(
        key="normal_cell_line",
        label="Normal Cell Line",
        matrix_path=BULK_ROOT / "normal_cell_line" / "Normal_cell_line_TE_normalized_count.tsv",
        meta_path=BULK_ROOT / "normal_cell_line" / "Normal_cell_line_meta.csv",
        run_field="Run",
        context_field="Celltype",
        dataset_order=2,
    ),
    DatasetConfig(
        key="cancer_cell_line",
        label="Cancer Cell Line",
        matrix_path=BULK_ROOT / "cancer_cell_line" / "CCLE_TE_normalized_count.tsv",
        meta_path=BULK_ROOT / "cancer_cell_line" / "CCLE_meta.csv",
        run_field="Run",
        context_field="Cancer",
        full_name_field="Full_name",
        matrix_has_te_column=False,
        dataset_order=3,
    ),
]


def ensure_output_dirs() -> None:
    OUT_ROOT.mkdir(parents=True, exist_ok=True)


def safe_float(value: str) -> float:
    if value == "" or value is None:
        return 0.0
    return float(value)


def round_or_none(value: float | None, digits: int = 6) -> float | None:
    if value is None:
        return None
    return round(value, digits)




def detect_text_encoding(path: Path, candidates: tuple[str, ...] = ("utf-8", "utf-8-sig", "gb18030", "gbk", "latin-1")) -> str:
    raw = path.read_bytes()
    for encoding in candidates:
        try:
            raw.decode(encoding)
            return encoding
        except UnicodeDecodeError:
            continue
    return "latin-1"

def build_te_order(source_path: Path) -> list[str]:
    te_names: list[str] = []
    with source_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.reader(handle, delimiter="\t")
        next(reader)
        for row in reader:
            if row:
                te_names.append(row[0])
    return te_names


def load_meta(config: DatasetConfig) -> tuple[dict[str, str], dict[str, str], dict[str, int]]:
    run_to_context: dict[str, str] = {}
    context_to_full_name: dict[str, str] = {}
    stats = {
        "meta_rows": 0,
        "duplicate_runs_same_context": 0,
        "duplicate_runs_conflicting_context": 0,
    }
    with config.meta_path.open("r", encoding=detect_text_encoding(config.meta_path), newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            stats["meta_rows"] += 1
            run = (row.get(config.run_field) or "").strip()
            context = (row.get(config.context_field) or "").strip()
            full_name = (
                (row.get(config.full_name_field) or "").strip()
                if config.full_name_field
                else context
            )
            if not run or not context:
                continue
            if run in run_to_context:
                if run_to_context[run] == context:
                    stats["duplicate_runs_same_context"] += 1
                else:
                    stats["duplicate_runs_conflicting_context"] += 1
                continue
            run_to_context[run] = context
            context_to_full_name.setdefault(context, full_name or context)
    return run_to_context, context_to_full_name, stats


def quartiles(sorted_values: list[float]) -> tuple[float | None, float | None]:
    count = len(sorted_values)
    if count == 0:
        return None, None
    if count == 1:
        return sorted_values[0], sorted_values[0]
    midpoint = count // 2
    if count % 2 == 0:
        lower_half = sorted_values[:midpoint]
        upper_half = sorted_values[midpoint:]
    else:
        lower_half = sorted_values[:midpoint]
        upper_half = sorted_values[midpoint + 1 :]
    if not lower_half:
        lower_half = sorted_values
    if not upper_half:
        upper_half = sorted_values
    return median(lower_half), median(upper_half)


def summarize_metric(values: list[float]) -> dict[str, float | int | None]:
    if not values:
        return {
            "sample_count": 0,
            "min_value": None,
            "q1_value": None,
            "max_value": None,
            "median_value": None,
            "q3_value": None,
            "mean_value": None,
            "std_value": None,
            "cv_value": None,
        }
    sorted_values = sorted(values)
    count = len(sorted_values)
    min_value = sorted_values[0]
    max_value = sorted_values[-1]
    q1_value, q3_value = quartiles(sorted_values)
    median_value = median(sorted_values)
    mean_value = sum(sorted_values) / count
    if count > 1:
        variance = sum((item - mean_value) ** 2 for item in sorted_values) / count
        std_value = math.sqrt(variance)
    else:
        std_value = 0.0
    cv_value = (std_value / mean_value) if mean_value not in (0.0, -0.0) else None
    return {
        "sample_count": count,
        "min_value": min_value,
        "q1_value": q1_value,
        "max_value": max_value,
        "median_value": median_value,
        "q3_value": q3_value,
        "mean_value": mean_value,
        "std_value": std_value,
        "cv_value": cv_value,
    }


def aggregate_context_metric(values: list[float]) -> dict[str, float | int | None]:
    if not values:
        return {
            "max": None,
            "min": None,
            "median": None,
            "mean": None,
            "cv": None,
            "breadth": 0,
        }
    sorted_values = sorted(values)
    count = len(sorted_values)
    mean_value = sum(sorted_values) / count
    if count > 1:
        variance = sum((item - mean_value) ** 2 for item in sorted_values) / count
        std_value = math.sqrt(variance)
    else:
        std_value = 0.0
    cv_value = (std_value / mean_value) if mean_value not in (0.0, -0.0) else None
    return {
        "max": max(sorted_values),
        "min": min(sorted_values),
        "median": median(sorted_values),
        "mean": mean_value,
        "cv": cv_value,
        "breadth": sum(1 for item in values if item > 0),
    }


def top_context(
    context_rows: list[dict[str, Any]],
    metric_key: str,
) -> tuple[str | None, str | None, float | None]:
    ranked: list[tuple[float, int, str, str]] = []
    for row in context_rows:
        value = row.get(metric_key)
        if value is None:
            continue
        ranked.append(
            (
                float(value),
                int(row["context_order"]),
                str(row["context_label"]),
                str(row["context_full_name"]),
            )
        )
    if not ranked:
        return None, None, None
    ranked.sort(key=lambda item: (-item[0], item[1], item[2]))
    winner = ranked[0]
    return winner[2], winner[3], winner[0]


def write_tsv(path: Path, rows: list[dict[str, Any]], fieldnames: list[str]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, delimiter="\t", fieldnames=fieldnames)
        writer.writeheader()
        for row in rows:
            writer.writerow(row)


def prepare_dataset(
    config: DatasetConfig,
    te_order_reference: list[str],
    context_writer: csv.DictWriter,
    dataset_writer: csv.DictWriter,
) -> dict[str, Any]:
    run_to_context, context_to_full_name, meta_stats = load_meta(config)

    dataset_summary_rows: dict[str, dict[str, Any]] = {}
    context_catalog_rows: list[dict[str, Any]] = []

    with config.matrix_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.reader(handle, delimiter="\t")
        header = next(reader)
        run_headers = header[1:] if config.matrix_has_te_column else header

        context_positions: dict[str, list[int]] = defaultdict(list)
        context_order: dict[str, int] = {}
        missing_runs = 0
        for idx, run in enumerate(run_headers):
            context = run_to_context.get(run)
            if not context:
                missing_runs += 1
                continue
            context_positions[context].append(idx)
            if context not in context_order:
                context_order[context] = len(context_order) + 1

        for context, order_value in context_order.items():
            context_catalog_rows.append(
                {
                    "dataset_key": config.key,
                    "dataset_label": config.label,
                    "dataset_order": config.dataset_order,
                    "context_label": context,
                    "context_full_name": context_to_full_name.get(context, context),
                    "context_order": order_value,
                    "run_count": len(context_positions[context]),
                }
            )

        row_count = 0
        for row_idx, row in enumerate(reader):
            if config.matrix_has_te_column:
                te_name = row[0]
                values = row[1:]
            else:
                te_name = te_order_reference[row_idx]
                values = row

            row_count += 1
            context_rows: list[dict[str, Any]] = []
            for context, order_value in sorted(context_order.items(), key=lambda item: item[1]):
                context_values = [safe_float(values[position]) for position in context_positions[context]]
                metric_stats = summarize_metric(context_values)
                context_row = {
                    "te_name": te_name,
                    "dataset_key": config.key,
                    "dataset_label": config.label,
                    "dataset_order": config.dataset_order,
                    "context_label": context,
                    "context_full_name": context_to_full_name.get(context, context),
                    "context_order": order_value,
                    "sample_count": metric_stats["sample_count"],
                    "min_value": round_or_none(metric_stats["min_value"]),
                    "q1_value": round_or_none(metric_stats["q1_value"]),
                    "median_value": round_or_none(metric_stats["median_value"]),
                    "q3_value": round_or_none(metric_stats["q3_value"]),
                    "mean_value": round_or_none(metric_stats["mean_value"]),
                    "max_value": round_or_none(metric_stats["max_value"]),
                    "std_value": round_or_none(metric_stats["std_value"]),
                    "cv_value": round_or_none(metric_stats["cv_value"]),
                }
                context_writer.writerow(context_row)
                context_rows.append(context_row)

            max_values = [float(row_item["max_value"]) for row_item in context_rows if row_item["max_value"] is not None]
            mean_values = [float(row_item["mean_value"]) for row_item in context_rows if row_item["mean_value"] is not None]
            median_values = [float(row_item["median_value"]) for row_item in context_rows if row_item["median_value"] is not None]

            max_summary = aggregate_context_metric(max_values)
            mean_summary = aggregate_context_metric(mean_values)
            median_summary = aggregate_context_metric(median_values)

            top_max_label, top_max_full, top_max_value = top_context(context_rows, "max_value")
            top_mean_label, top_mean_full, top_mean_value = top_context(context_rows, "mean_value")
            top_median_label, top_median_full, top_median_value = top_context(context_rows, "median_value")

            dataset_summary_row = {
                "te_name": te_name,
                "dataset_key": config.key,
                "dataset_label": config.label,
                "dataset_order": config.dataset_order,
                "context_count": len(context_rows),
                "run_count_total": sum(int(row_item["sample_count"]) for row_item in context_rows),
                "top_context_max": top_max_label,
                "top_context_max_full_name": top_max_full,
                "top_context_max_value": round_or_none(top_max_value),
                "top_context_mean": top_mean_label,
                "top_context_mean_full_name": top_mean_full,
                "top_context_mean_value": round_or_none(top_mean_value),
                "top_context_median": top_median_label,
                "top_context_median_full_name": top_median_full,
                "top_context_median_value": round_or_none(top_median_value),
                "max_of_max": round_or_none(max_summary["max"]),
                "min_of_max": round_or_none(max_summary["min"]),
                "median_of_max": round_or_none(max_summary["median"]),
                "mean_of_max": round_or_none(max_summary["mean"]),
                "cv_of_max": round_or_none(max_summary["cv"]),
                "breadth_of_max": max_summary["breadth"],
                "max_of_mean": round_or_none(mean_summary["max"]),
                "min_of_mean": round_or_none(mean_summary["min"]),
                "median_of_mean": round_or_none(mean_summary["median"]),
                "mean_of_mean": round_or_none(mean_summary["mean"]),
                "cv_of_mean": round_or_none(mean_summary["cv"]),
                "breadth_of_mean": mean_summary["breadth"],
                "max_of_median": round_or_none(median_summary["max"]),
                "min_of_median": round_or_none(median_summary["min"]),
                "median_of_median": round_or_none(median_summary["median"]),
                "mean_of_median": round_or_none(median_summary["mean"]),
                "cv_of_median": round_or_none(median_summary["cv"]),
                "breadth_of_median": median_summary["breadth"],
            }
            dataset_writer.writerow(dataset_summary_row)
            dataset_summary_rows[te_name] = dataset_summary_row

    return {
        "config": config,
        "meta_stats": meta_stats,
        "missing_matrix_runs": missing_runs,
        "unique_runs_used": len(run_headers) - missing_runs,
        "context_catalog_rows": context_catalog_rows,
        "dataset_summary_rows": dataset_summary_rows,
        "row_count": row_count,
        "matrix_columns": len(run_headers),
    }


def build_browse_rows(
    te_order: list[str],
    dataset_results: dict[str, dict[str, Any]],
) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    dataset_prefixes = {
        "normal_tissue": "normal_tissue",
        "normal_cell_line": "normal_cell_line",
        "cancer_cell_line": "cancer_cell_line",
    }
    for te_name in te_order:
        row: dict[str, Any] = {
            "te_name": te_name,
            "datasets_available": 0,
            "datasets_available_labels": "",
            "global_top_context_median_dataset": None,
            "global_top_context_median": None,
            "global_top_context_median_full_name": None,
            "global_top_context_median_value": None,
            "global_max_of_median": None,
        }
        available_labels: list[str] = []
        global_candidates: list[tuple[float, int, str, str, str]] = []
        for dataset_idx, dataset_key in enumerate(
            ["normal_tissue", "normal_cell_line", "cancer_cell_line"],
            start=1,
        ):
            summary_row = dataset_results[dataset_key]["dataset_summary_rows"].get(te_name)
            prefix = dataset_prefixes[dataset_key]
            if summary_row:
                available_labels.append(dataset_results[dataset_key]["config"].label)
                row[f"{prefix}_available"] = 1
                for key, value in summary_row.items():
                    if key in {"te_name", "dataset_key", "dataset_label", "dataset_order"}:
                        continue
                    row[f"{prefix}_{key}"] = value
                metric_value = summary_row.get("top_context_median_value")
                if metric_value is not None:
                    global_candidates.append(
                        (
                            float(metric_value),
                            dataset_idx,
                            dataset_results[dataset_key]["config"].label,
                            str(summary_row.get("top_context_median") or ""),
                            str(summary_row.get("top_context_median_full_name") or ""),
                        )
                    )
            else:
                row[f"{prefix}_available"] = 0
        row["datasets_available"] = len(available_labels)
        row["datasets_available_labels"] = " | ".join(available_labels)
        if global_candidates:
            global_candidates.sort(key=lambda item: (-item[0], item[1], item[2], item[3]))
            top_value, _, dataset_label, context_label, context_full_name = global_candidates[0]
            row["global_top_context_median_dataset"] = dataset_label
            row["global_top_context_median"] = context_label
            row["global_top_context_median_full_name"] = context_full_name
            row["global_top_context_median_value"] = round_or_none(top_value)
            row["global_max_of_median"] = round_or_none(top_value)
        rows.append(row)
    return rows


def _mysql_type_for_column(column_name: str) -> str:
    lowered = column_name.lower()
    if lowered == "te_name":
        return "VARCHAR(64) NOT NULL"
    if lowered == "datasets_available_labels":
        return "VARCHAR(64) NULL"
    if lowered.endswith("_available") or lowered.endswith("_count") or lowered.endswith("_count_total") or lowered.endswith("run_count") or "breadth" in lowered or lowered == "datasets_available":
        return "INT NULL"
    if lowered.endswith("_value") or "_max_of_" in lowered or "_min_of_" in lowered or "_median_of_" in lowered or "_mean_of_" in lowered or "_cv_of_" in lowered or lowered in {"min_value", "median_value", "mean_value", "max_value", "std_value", "cv_value", "global_max_of_median"}:
        return "DOUBLE NULL"
    return "VARCHAR(64) NULL"


def write_mysql_schema(browse_fieldnames: list[str]) -> None:
    browse_columns = []
    for column_name in browse_fieldnames:
        browse_columns.append(f"  `{column_name}` {_mysql_type_for_column(column_name)}")
    browse_columns_sql = ",\n".join(browse_columns)
    schema = f"""-- MySQL-ready schema for TE bulk expression assets
CREATE TABLE IF NOT EXISTS expression_context_catalog (
  dataset_key VARCHAR(32) NOT NULL,
  dataset_label VARCHAR(32) NOT NULL,
  dataset_order INT NOT NULL,
  context_label VARCHAR(64) NOT NULL,
  context_full_name VARCHAR(64) NOT NULL,
  context_order INT NOT NULL,
  run_count INT NOT NULL,
  PRIMARY KEY (dataset_key, context_label)
);

CREATE TABLE IF NOT EXISTS expression_dataset_summary (
  te_name VARCHAR(64) NOT NULL,
  dataset_key VARCHAR(32) NOT NULL,
  dataset_label VARCHAR(32) NOT NULL,
  dataset_order INT NOT NULL,
  context_count INT NOT NULL,
  run_count_total INT NOT NULL,
  top_context_max VARCHAR(64) NULL,
  top_context_max_full_name VARCHAR(64) NULL,
  top_context_max_value DOUBLE NULL,
  top_context_mean VARCHAR(64) NULL,
  top_context_mean_full_name VARCHAR(64) NULL,
  top_context_mean_value DOUBLE NULL,
  top_context_median VARCHAR(64) NULL,
  top_context_median_full_name VARCHAR(64) NULL,
  top_context_median_value DOUBLE NULL,
  max_of_max DOUBLE NULL,
  min_of_max DOUBLE NULL,
  median_of_max DOUBLE NULL,
  mean_of_max DOUBLE NULL,
  cv_of_max DOUBLE NULL,
  breadth_of_max INT NOT NULL,
  max_of_mean DOUBLE NULL,
  min_of_mean DOUBLE NULL,
  median_of_mean DOUBLE NULL,
  mean_of_mean DOUBLE NULL,
  cv_of_mean DOUBLE NULL,
  breadth_of_mean INT NOT NULL,
  max_of_median DOUBLE NULL,
  min_of_median DOUBLE NULL,
  median_of_median DOUBLE NULL,
  mean_of_median DOUBLE NULL,
  cv_of_median DOUBLE NULL,
  breadth_of_median INT NOT NULL,
  PRIMARY KEY (te_name, dataset_key),
  KEY idx_dataset_key (dataset_key),
  KEY idx_top_context_median (top_context_median)
);

CREATE TABLE IF NOT EXISTS expression_context_stats (
  te_name VARCHAR(64) NOT NULL,
  dataset_key VARCHAR(32) NOT NULL,
  dataset_label VARCHAR(32) NOT NULL,
  dataset_order INT NOT NULL,
  context_label VARCHAR(64) NOT NULL,
  context_full_name VARCHAR(64) NOT NULL,
  context_order INT NOT NULL,
  sample_count INT NOT NULL,
  min_value DOUBLE NULL,
  q1_value DOUBLE NULL,
  median_value DOUBLE NULL,
  q3_value DOUBLE NULL,
  mean_value DOUBLE NULL,
  max_value DOUBLE NULL,
  std_value DOUBLE NULL,
  cv_value DOUBLE NULL,
  PRIMARY KEY (te_name, dataset_key, context_label),
  KEY idx_dataset_context (dataset_key, context_label),
  KEY idx_te_dataset (te_name, dataset_key)
);

CREATE TABLE IF NOT EXISTS expression_browse_summary (
{browse_columns_sql},
  PRIMARY KEY (te_name)
);
"""
    (OUT_ROOT / "expression_mysql_schema.sql").write_text(schema, encoding="utf-8")

def main() -> None:
    ensure_output_dirs()

    te_order = build_te_order(DATASETS[0].matrix_path)
    dataset_results: dict[str, dict[str, Any]] = {}
    context_catalog_rows: list[dict[str, Any]] = []

    context_stats_path = OUT_ROOT / "te_expression_context_stats.tsv"
    dataset_summary_path = OUT_ROOT / "te_expression_dataset_summary.tsv"
    browse_summary_path = OUT_ROOT / "te_expression_browse_summary.tsv"
    context_catalog_path = OUT_ROOT / "te_expression_context_catalog.tsv"

    context_fieldnames = [
        "te_name",
        "dataset_key",
        "dataset_label",
        "dataset_order",
        "context_label",
        "context_full_name",
        "context_order",
        "sample_count",
        "min_value",
        "q1_value",
        "median_value",
        "q3_value",
        "mean_value",
        "max_value",
        "std_value",
        "cv_value",
    ]
    dataset_fieldnames = [
        "te_name",
        "dataset_key",
        "dataset_label",
        "dataset_order",
        "context_count",
        "run_count_total",
        "top_context_max",
        "top_context_max_full_name",
        "top_context_max_value",
        "top_context_mean",
        "top_context_mean_full_name",
        "top_context_mean_value",
        "top_context_median",
        "top_context_median_full_name",
        "top_context_median_value",
        "max_of_max",
        "min_of_max",
        "median_of_max",
        "mean_of_max",
        "cv_of_max",
        "breadth_of_max",
        "max_of_mean",
        "min_of_mean",
        "median_of_mean",
        "mean_of_mean",
        "cv_of_mean",
        "breadth_of_mean",
        "max_of_median",
        "min_of_median",
        "median_of_median",
        "mean_of_median",
        "cv_of_median",
        "breadth_of_median",
    ]

    with context_stats_path.open("w", encoding="utf-8", newline="") as context_handle, dataset_summary_path.open(
        "w", encoding="utf-8", newline=""
    ) as dataset_handle:
        context_writer = csv.DictWriter(context_handle, delimiter="\t", fieldnames=context_fieldnames)
        dataset_writer = csv.DictWriter(dataset_handle, delimiter="\t", fieldnames=dataset_fieldnames)
        context_writer.writeheader()
        dataset_writer.writeheader()

        for config in DATASETS:
            result = prepare_dataset(config, te_order, context_writer, dataset_writer)
            dataset_results[config.key] = result
            context_catalog_rows.extend(result["context_catalog_rows"])

    browse_rows = build_browse_rows(te_order, dataset_results)
    browse_fieldnames = list(browse_rows[0].keys()) if browse_rows else ["te_name"]
    write_tsv(browse_summary_path, browse_rows, browse_fieldnames)

    context_catalog_fieldnames = [
        "dataset_key",
        "dataset_label",
        "dataset_order",
        "context_label",
        "context_full_name",
        "context_order",
        "run_count",
    ]
    write_tsv(context_catalog_path, context_catalog_rows, context_catalog_fieldnames)

    write_mysql_schema(browse_fieldnames)

    manifest = {
        "source_files": {
            config.key: {
                "matrix_path": str(config.matrix_path),
                "meta_path": str(config.meta_path),
                "matrix_has_te_column": config.matrix_has_te_column,
            }
            for config in DATASETS
        },
        "assumptions": {
            "te_order_reference": str(DATASETS[0].matrix_path),
            "ccle_te_order_shared_with_other_matrices": True,
            "mean_and_average_are_same_metric": True,
            "expression_breadth_is_reported_separately_for_max_mean_median": True,
            "no_tpm_label_is_applied_because_input_uses_normalized_count": True,
        },
        "output_files": {
            "context_catalog_tsv": str(context_catalog_path),
            "context_stats_tsv": str(context_stats_path),
            "dataset_summary_tsv": str(dataset_summary_path),
            "browse_summary_tsv": str(browse_summary_path),
            "mysql_schema_sql": str(OUT_ROOT / "expression_mysql_schema.sql"),
        },
        "dataset_results": {
            key: {
                "row_count": result["row_count"],
                "matrix_columns": result["matrix_columns"],
                "missing_matrix_runs": result["missing_matrix_runs"],
                "unique_runs_used": result["unique_runs_used"],
                "meta_stats": result["meta_stats"],
                "context_count": len(result["context_catalog_rows"]),
            }
            for key, result in dataset_results.items()
        },
        "te_count": len(te_order),
    }
    (OUT_ROOT / "expression_processing_manifest.json").write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    print(json.dumps(manifest, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
