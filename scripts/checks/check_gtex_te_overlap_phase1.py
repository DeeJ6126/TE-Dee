#!/usr/bin/env python
"""Verify the generated GTEx v11 Liver strict TE-overlap phase-1 artifacts."""

from __future__ import annotations

import json
import sys
from pathlib import Path

import pandas as pd


PROJECT_ROOT = Path(__file__).resolve().parents[2]
OUTPUT_DIR = PROJECT_ROOT / "data/eQTL/derived/gtex_v11_te_overlap/Liver"
CATALOG_PATH = PROJECT_ROOT / "data/processed/te_repbase_db_matched.json"

sys.path.insert(0, str(PROJECT_ROOT))
from scripts.eqtl.build_gtex_te_overlap import EVIDENCE_COLUMNS, parse_variant_id  # noqa: E402


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> int:
    evidence_path = OUTPUT_DIR / "te_variant_gene_overlaps.parquet"
    summary_path = OUTPUT_DIR / "te_gene_summary.parquet"
    manifest_path = OUTPUT_DIR / "run_manifest.json"
    report_path = OUTPUT_DIR / "liver_overlap_report.md"
    for path in (evidence_path, summary_path, manifest_path, report_path):
        require(path.is_file() and path.stat().st_size > 0, f"Missing or empty output: {path}")

    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    report = report_path.read_text(encoding="utf-8")
    evidence = pd.read_parquet(evidence_path)
    summary = pd.read_parquet(summary_path)
    counts = manifest["counts"]

    require(list(evidence.columns) == EVIDENCE_COLUMNS, "Evidence schema does not match the phase-1 contract.")
    require(len(evidence) == counts["overlap_evidence_row_count"], "Evidence row count differs from manifest.")
    require(len(summary) == counts["te_gene_pair_count"], "Summary row count differs from manifest.")
    require(set(evidence["mapping_type"]) == {"strict_te_overlap"}, "Unexpected evidence mapping type.")
    require(set(summary["mapping_type"]) == {"strict_te_overlap"}, "Unexpected summary mapping type.")
    require(
        bool(((evidence["variant_start"] < evidence["te_end"]) & (evidence["variant_end"] > evidence["te_start"])).all()),
        "At least one evidence row does not satisfy the strict interval-overlap predicate.",
    )
    require(
        bool(((evidence["variant_end"] - evidence["variant_start"]) == evidence["ref"].str.len()).all()),
        "At least one stored variant span differs from its REF allele length.",
    )
    require(
        not evidence.duplicated(["tissue", "te_instance_id", "variant_id", "gene_id"]).any(),
        "Evidence primary key contains duplicates.",
    )

    unique_variants = evidence[["variant_id", "chrom", "variant_start", "variant_end", "ref", "alt"]].drop_duplicates()
    require(not unique_variants["variant_id"].duplicated().any(), "One variant ID maps to conflicting stored coordinates.")
    for row in unique_variants.itertuples(index=False):
        parsed = parse_variant_id(row.variant_id)
        require(
            (parsed.chrom, parsed.start, parsed.end, parsed.ref, parsed.alt)
            == (row.chrom, row.variant_start, row.variant_end, row.ref, row.alt),
            f"Stored coordinates disagree with variant ID: {row.variant_id}",
        )

    catalog = json.loads(CATALOG_PATH.read_text(encoding="utf-8"))
    approved_names = set(catalog["db_to_repbase"])
    require(set(evidence["te_name"]).issubset(approved_names), "Evidence contains a TE outside the Browse catalog.")
    require(evidence["variant_id"].nunique() == counts["overlapping_unique_variant_count"], "Variant count differs from manifest.")
    require(evidence["gene_id"].nunique() == counts["overlapping_unique_gene_count"], "Gene count differs from manifest.")
    require(evidence["te_instance_id"].nunique() == counts["overlapping_unique_te_instance_count"], "TE instance count differs from manifest.")
    require(evidence["te_name"].nunique() == counts["overlapping_unique_te_name_count"], "TE name count differs from manifest.")

    grouped = evidence.groupby(["tissue", "te_name", "gene_id", "gene_id_base"], sort=True)
    rebuilt = grouped.agg(
        supporting_variant_count=("variant_id", "nunique"),
        supporting_instance_count=("te_instance_id", "nunique"),
        evidence_row_count=("variant_id", "size"),
        minimum_pval_nominal=("pval_nominal", "min"),
        maximum_abs_slope=("slope", lambda values: float(values.abs().max())),
        positive_slope_count=("slope", lambda values: int((values > 0).sum())),
        negative_slope_count=("slope", lambda values: int((values < 0).sum())),
    ).reset_index()
    rebuilt["mapping_type"] = "strict_te_overlap"
    sort_columns = ["tissue", "te_name", "gene_id", "gene_id_base"]
    pd.testing.assert_frame_equal(
        summary.sort_values(sort_columns).reset_index(drop=True),
        rebuilt.sort_values(sort_columns).reset_index(drop=True),
        check_dtype=False,
        rtol=1e-6,
        atol=1e-12,
    )

    for key in (
        "eqtl_row_count",
        "overlap_evidence_row_count",
        "overlapping_unique_variant_count",
        "overlapping_unique_gene_count",
        "overlapping_unique_te_instance_count",
        "overlapping_unique_te_name_count",
        "te_gene_pair_count",
    ):
        require(f"`{key}`" in report, f"Report is missing manifest metric: {key}")
    require("not prove" in report, "Report is missing the non-causality interpretation boundary.")

    print(
        "GTEx phase-1 artifacts verified: "
        f"{len(evidence):,} evidence rows, {len(summary):,} TE-Gene pairs, "
        f"{len(unique_variants):,} unique overlapping variants."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
