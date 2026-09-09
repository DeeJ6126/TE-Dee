#!/usr/bin/env python
"""Build selected TE co-expression case-study tables."""

from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path

import pandas as pd


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_FILTERED_DIR = PROJECT_ROOT / "data/coexpression/analysis/v1/abs0.4_fdr0.05"
DEFAULT_INTERPRETATION_DIR = PROJECT_ROOT / "data/coexpression/interpretation/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_OUTPUT_DIR = PROJECT_ROOT / "data/coexpression/case_studies/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_DATASETS = ["cancer_cell_line", "normal_cell_line", "normal_tissue"]
DEFAULT_FEATURES = ["L1HS", "LTR5", "HERVH-int"]


CASE_COLUMNS = [
    "feature",
    "context_type",
    "module_id",
    "module_type",
    "module_size",
    "TE_count",
    "gene_count",
    "TE_fraction",
    "positive_degree",
    "weighted_positive_degree",
    "is_module_hub",
    "functional_context_confidence",
    "candidate_label",
    "top_enriched_terms",
    "top_gene_partners",
    "top_te_partners",
    "case_interpretation_zh",
    "case_interpretation_en",
]


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build case-study summaries for selected TE features.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("--filtered-dir", type=Path, default=DEFAULT_FILTERED_DIR)
    parser.add_argument("--interpretation-dir", type=Path, default=DEFAULT_INTERPRETATION_DIR)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR)
    parser.add_argument("--features", nargs="+", default=DEFAULT_FEATURES)
    parser.add_argument("--datasets", nargs="+", choices=DEFAULT_DATASETS, default=DEFAULT_DATASETS)
    parser.add_argument("--top-n", type=int, default=10)
    return parser.parse_args(argv)


def edge_path(filtered_dir: Path, dataset: str) -> Path:
    return filtered_dir / f"{dataset}_edges.tsv"


def format_partner(row: pd.Series, partner: str) -> str:
    return f"{partner}|r={float(row['correlation']):.3f}|fdr={float(row['fdr']):.2g}"


def top_partners(filtered_dir: Path, dataset: str, feature: str, top_n: int) -> tuple[str, str]:
    path = edge_path(filtered_dir, dataset)
    if not path.exists():
        raise FileNotFoundError(f"Filtered edge file not found: {path}")
    usecols = ["source", "target", "source_type", "target_type", "correlation", "abs_correlation", "fdr"]
    matches = []
    for chunk in pd.read_csv(path, sep="\t", usecols=usecols, chunksize=250_000):
        hit = chunk.loc[(chunk["source"].eq(feature)) | (chunk["target"].eq(feature))].copy()
        if not hit.empty:
            matches.append(hit)
    if not matches:
        return "", ""
    edges = pd.concat(matches, ignore_index=True)
    edges = edges.loc[pd.to_numeric(edges["correlation"], errors="coerce") > 0].copy()
    if edges.empty:
        return "", ""
    edges["abs_correlation"] = pd.to_numeric(edges["abs_correlation"], errors="coerce")
    edges = edges.sort_values(["abs_correlation", "fdr"], ascending=[False, True])

    gene_partners = []
    te_partners = []
    for _, row in edges.iterrows():
        if row["source"] == feature:
            partner = str(row["target"])
            partner_type = str(row["target_type"])
        else:
            partner = str(row["source"])
            partner_type = str(row["source_type"])
        entry = format_partner(row, partner)
        if partner_type.lower() == "te":
            if len(te_partners) < top_n:
                te_partners.append(entry)
        else:
            if len(gene_partners) < top_n:
                gene_partners.append(entry)
        if len(gene_partners) >= top_n and len(te_partners) >= top_n:
            break
    return ";".join(gene_partners), ";".join(te_partners)


def zh_statement(row: dict) -> str:
    feature = row["feature"]
    context = row["context_type"]
    if row["functional_context_confidence"] == "high":
        return (
            f"{feature} 在 {context} 中位于 gene-rich 共表达模块 {row['module_id']}，"
            f"该模块的 gene 富集结果指向 {row['candidate_label']}。该结果说明表达背景相关，不能解释为直接调控。"
        )
    if row["functional_context_confidence"] == "low":
        return (
            f"{feature} 在 {context} 中位于 TE-rich 共表达模块 {row['module_id']}。"
            "该模块以 TE 共变为主，gene 富集只能作为弱线索。"
        )
    return (
        f"{feature} 在 {context} 中位于 {row['module_id']}，"
        "但该模块缺少足够可解释的 gene 富集结果，因此不做功能背景解释。"
    )


def en_statement(row: dict) -> str:
    feature = row["feature"]
    context = row["context_type"]
    if row["functional_context_confidence"] == "high":
        return (
            f"{feature} is assigned to the gene-rich co-expression module {row['module_id']} in {context}; "
            f"module genes are enriched for {row['candidate_label']}. This is expression-context evidence, not direct regulation."
        )
    if row["functional_context_confidence"] == "low":
        return (
            f"{feature} is assigned to the TE-rich co-expression module {row['module_id']} in {context}; "
            "gene enrichment should be treated as weak contextual evidence."
        )
    return (
        f"{feature} is assigned to {row['module_id']} in {context}, but the module lacks sufficient interpretable gene-enrichment evidence."
    )


def write_tsv(path: Path, rows: list[dict], columns: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, delimiter="\t", extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    summary_path = args.interpretation_dir / "te_functional_context_summary.tsv"
    if not summary_path.exists():
        raise FileNotFoundError(f"TE functional context summary not found: {summary_path}")
    summary = pd.read_csv(summary_path, sep="\t").fillna("")

    rows = []
    for feature in args.features:
        for dataset in args.datasets:
            match = summary.loc[summary["feature"].eq(feature) & summary["context_type"].eq(dataset)]
            if match.empty:
                continue
            row = match.iloc[0].to_dict()
            gene_partners, te_partners = top_partners(args.filtered_dir, dataset, feature, args.top_n)
            out = {
                key: row.get(key, "")
                for key in [
                    "feature",
                    "context_type",
                    "module_id",
                    "module_type",
                    "module_size",
                    "TE_count",
                    "gene_count",
                    "TE_fraction",
                    "positive_degree",
                    "weighted_positive_degree",
                    "is_module_hub",
                    "functional_context_confidence",
                    "candidate_label",
                    "top_enriched_terms",
                ]
            }
            out["top_gene_partners"] = gene_partners
            out["top_te_partners"] = te_partners
            out["case_interpretation_zh"] = zh_statement(out)
            out["case_interpretation_en"] = en_statement(out)
            rows.append(out)

    args.output_dir.mkdir(parents=True, exist_ok=True)
    write_tsv(args.output_dir / "selected_te_case_comparison.tsv", rows, CASE_COLUMNS)
    for feature in args.features:
        feature_rows = [row for row in rows if row["feature"] == feature]
        write_tsv(args.output_dir / f"{feature}_case_summary.tsv", feature_rows, CASE_COLUMNS)
    manifest = {
        "script": str(Path(__file__).resolve()),
        "filtered_dir": str(args.filtered_dir),
        "interpretation_dir": str(args.interpretation_dir),
        "output_dir": str(args.output_dir),
        "features": args.features,
        "datasets": args.datasets,
        "top_n": args.top_n,
        "interpretation_limit": "co-expression context, not causal regulation",
    }
    (args.output_dir / "case_study_manifest.json").write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False),
        encoding="utf-8",
    )
    print(f"Wrote case studies: {args.output_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
