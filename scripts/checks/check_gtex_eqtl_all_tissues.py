#!/usr/bin/env python
"""Verify all GTEx tissue outputs and optional consolidated MySQL artifacts."""

from __future__ import annotations

import argparse
import csv
import gzip
import json
import sys
from pathlib import Path

import pandas as pd
import pyarrow.parquet as pq
from tqdm.auto import tqdm


PROJECT_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(PROJECT_ROOT))

from scripts.eqtl import build_gtex_all_tissues as builder  # noqa: E402
from scripts.eqtl import consolidate_gtex_mysql_artifacts as consolidator  # noqa: E402
from scripts.eqtl import gtex_overlap_core as core  # noqa: E402


DEFAULT_ROOT = PROJECT_ROOT / "data/eQTL/derived/gtex_v11_strict_te_overlap_v1"
SUMMARY_COLUMNS = [
    "tissue", "te_name", "gene_id", "gene_id_base", "supporting_variant_count",
    "supporting_instance_count", "evidence_row_count", "minimum_pval_nominal",
    "maximum_abs_slope", "positive_slope_count", "negative_slope_count", "mapping_type",
]


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def rebuilt_summary(evidence: pd.DataFrame) -> pd.DataFrame:
    if evidence.empty:
        return pd.DataFrame(columns=SUMMARY_COLUMNS)
    grouped = evidence.groupby(
        ["tissue", "te_name", "gene_id", "gene_id_base"], sort=True
    )
    result = grouped.agg(
        supporting_variant_count=("variant_id", "nunique"),
        supporting_instance_count=("te_instance_id", "nunique"),
        evidence_row_count=("variant_id", "size"),
        minimum_pval_nominal=("pval_nominal", "min"),
        maximum_abs_slope=("slope", lambda values: float(values.abs().max())),
        positive_slope_count=("slope", lambda values: int((values > 0).sum())),
        negative_slope_count=("slope", lambda values: int((values < 0).sum())),
    ).reset_index()
    result["mapping_type"] = "strict_te_overlap"
    return result[SUMMARY_COLUMNS]


def verify_mysql_artifacts(root: Path) -> dict[str, int]:
    mysql_root = root / "mysql"
    manifest = json.loads((mysql_root / "manifest.json").read_text(encoding="utf-8"))
    require(manifest["status"] == "complete", "MySQL artifact manifest is not complete.")
    counts = {}
    total_parts = sum(
        len(manifest["tables"][table]["files"])
        for table in manifest["import_order"]
    )
    with tqdm(total=total_parts, desc="MySQL parts", unit="part", dynamic_ncols=True) as progress:
        for table in manifest["import_order"]:
            entry = manifest["tables"][table]
            require(entry["columns"] == consolidator.TABLE_COLUMNS[table], f"MySQL columns drifted: {table}")
            table_rows = 0
            for file_entry in entry["files"]:
                path = mysql_root / file_entry["path"]
                require(core.sha256_file(path) == file_entry["sha256"], f"MySQL part hash mismatch: {path}")
                with gzip.open(path, "rt", encoding="utf-8", newline="") as handle:
                    reader = csv.reader(handle, delimiter="\t", quotechar='"')
                    require(next(reader, None) == entry["columns"], f"MySQL part header mismatch: {path}")
                    rows = sum(1 for _ in reader)
                require(rows == int(file_entry["rows"]), f"MySQL part row count mismatch: {path}")
                require(rows <= int(manifest["part_row_limit"]), f"MySQL part exceeds limit: {path}")
                table_rows += rows
                progress.set_postfix_str(table, refresh=False)
                progress.update()
            require(table_rows == int(entry["rows"]), f"MySQL table row count mismatch: {table}")
            counts[table] = table_rows
    return counts


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--artifact-root", type=Path, default=DEFAULT_ROOT)
    parser.add_argument("--require-mysql", action="store_true")
    args = parser.parse_args()
    root = args.artifact_root
    manifest = json.loads((root / "all_tissue_manifest.json").read_text(encoding="utf-8"))
    require(manifest["status"] == "complete", "All-tissue manifest is incomplete.")
    require(int(manifest["counts"]["tissue_count"]) == 50, "Expected exactly 50 tissues.")
    require(len(manifest["tissues"]) == 50, "All-tissue manifest does not list 50 tissues.")
    input_hashes = manifest["input_hashes"]

    source_total = evidence_total = summary_total = 0
    for tissue in tqdm(
        sorted(manifest["tissues"]), desc="Tissue verification",
        unit="tissue", dynamic_ncols=True,
    ):
        directory = root / tissue
        require(builder._valid_completed_output(directory, input_hashes), f"Invalid tissue output: {tissue}")
        tissue_manifest = json.loads((directory / "manifest.json").read_text(encoding="utf-8"))
        require(core.sha256_file(directory / "manifest.json") == manifest["tissues"][tissue]["manifest_sha256"], f"Tissue manifest hash mismatch: {tissue}")
        evidence = pq.read_table(directory / "te_variant_gene_overlaps.parquet").to_pandas()
        summary = pq.read_table(directory / "te_gene_summary.parquet").to_pandas()
        require(list(evidence.columns) == core.EVIDENCE_COLUMNS, f"Evidence schema drift: {tissue}")
        require(list(summary.columns) == SUMMARY_COLUMNS, f"Summary schema drift: {tissue}")
        require(
            bool(((evidence["variant_start"] < evidence["te_end"]) & (evidence["variant_end"] > evidence["te_start"])).all()),
            f"Strict-overlap predicate failed: {tissue}",
        )
        require(
            bool(((evidence["variant_end"] - evidence["variant_start"]) == evidence["ref"].str.len()).all()),
            f"REF-span contract failed: {tissue}",
        )
        require(
            not evidence.duplicated(["tissue", "te_instance_id", "variant_id", "gene_id"]).any(),
            f"Duplicate evidence primary key: {tissue}",
        )
        rebuilt = rebuilt_summary(evidence)
        pd.testing.assert_frame_equal(
            summary.reset_index(drop=True),
            rebuilt.reset_index(drop=True),
            check_dtype=False,
            rtol=1e-6,
            atol=1e-12,
        )
        counts = tissue_manifest["counts"]
        require(len(evidence) == int(counts["overlap_evidence_row_count"]), f"Evidence count drift: {tissue}")
        require(len(summary) == int(counts["te_gene_pair_count"]), f"Summary count drift: {tissue}")
        source_total += int(counts["eqtl_row_count"])
        evidence_total += len(evidence)
        summary_total += len(summary)

    require(source_total == int(manifest["counts"]["source_association_count"]), "All-tissue source count drift.")
    require(evidence_total == int(manifest["counts"]["overlap_evidence_row_count"]), "All-tissue evidence count drift.")
    require(summary_total == int(manifest["counts"]["te_gene_tissue_summary_count"]), "All-tissue summary count drift.")
    audit = pd.read_csv(root / "missing_browse_te.tsv", sep="\t")
    require(len(audit) == 276 and audit["te_name"].nunique() == 276, "Browse TE mapping audit must contain 276 unique names.")
    require(int(audit["has_hg38_instance"].sum()) == 202, "Browse TE mapping audit should contain 202 mapped names.")
    require(not any(root.glob(".*.tmp-*")), "Temporary output directories remain after completion.")

    mysql_counts = verify_mysql_artifacts(root) if args.require_mysql else {}
    print(
        json.dumps(
            {
                "tissues": 50,
                "source_associations": source_total,
                "evidence_rows": evidence_total,
                "tissue_summaries": summary_total,
                "mysql_tables": mysql_counts,
            },
            indent=2,
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
