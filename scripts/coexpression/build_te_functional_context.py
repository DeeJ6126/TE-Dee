#!/usr/bin/env python
"""Build TE-level functional context summaries from module enrichment results."""

from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path

import pandas as pd


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_MODULE_DIR = PROJECT_ROOT / "data/coexpression/modules/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_INTERPRETATION_DIR = PROJECT_ROOT / "data/coexpression/interpretation/v1_abs0.4_fdr0.05_res1.8"


OUTPUT_COLUMNS = [
    "feature",
    "context_type",
    "module_id",
    "module_size",
    "TE_count",
    "gene_count",
    "TE_fraction",
    "module_type",
    "positive_degree",
    "weighted_positive_degree",
    "within_module_degree",
    "weighted_within_module_degree",
    "is_module_hub",
    "module_hub_rank",
    "enrichment_status",
    "candidate_label",
    "top_enriched_terms",
    "functional_context_confidence",
    "interpretation_statement_zh",
    "interpretation_statement_en",
]


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Join TE module assignments with module enrichment interpretation.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("--module-dir", type=Path, default=DEFAULT_MODULE_DIR)
    parser.add_argument("--interpretation-dir", type=Path, default=DEFAULT_INTERPRETATION_DIR)
    parser.add_argument("--output", type=Path, default=DEFAULT_INTERPRETATION_DIR / "te_functional_context_summary.tsv")
    parser.add_argument("--l1hs-output", type=Path, default=DEFAULT_INTERPRETATION_DIR / "l1hs_functional_context_summary.tsv")
    return parser.parse_args(argv)


def module_type(te_fraction: float, gene_count: int) -> str:
    if te_fraction >= 0.5:
        return "TE-rich"
    if te_fraction < 0.1 and gene_count >= 50:
        return "gene-rich"
    return "mixed"


def confidence(module_kind: str, gene_count: int, enrichment_status: str, candidate_label: str) -> str:
    has_terms = bool(candidate_label) and candidate_label not in {
        "no_significant_GO_KEGG_Reactome_terms",
        "too_few_genes_for_functional_enrichment",
    }
    if gene_count < 20 or enrichment_status != "eligible":
        return "not_interpretable"
    if module_kind == "gene-rich" and gene_count >= 50 and has_terms:
        return "high"
    if module_kind == "mixed" and has_terms:
        return "medium"
    if module_kind == "TE-rich":
        return "low"
    return "low" if has_terms else "not_interpretable"


def statement_zh(row: dict) -> str:
    feature = row["feature"]
    context = row["context_type"]
    module_kind = row["module_type"]
    label = row["candidate_label"]
    module_id = row["module_id"]
    if row["functional_context_confidence"] == "not_interpretable":
        return f"{feature} 在 {context} 中位于 {module_id}，但该模块缺少足够可解释的 gene 富集结果，因此不做功能背景解释。"
    if module_kind == "TE-rich":
        return f"{feature} 在 {context} 中位于 TE-rich 共表达模块 {module_id}；该模块以 TE 为主，gene 富集结果只能作为弱线索，不能解释为 TE 的直接功能。"
    return f"{feature} 在 {context} 中位于 {module_kind} 共表达模块 {module_id}，该模块的 gene 富集结果指向 {label}；这表示表达相关背景，不代表因果调控。"


def statement_en(row: dict) -> str:
    feature = row["feature"]
    context = row["context_type"]
    module_kind = row["module_type"]
    label = row["candidate_label"]
    module_id = row["module_id"]
    if row["functional_context_confidence"] == "not_interpretable":
        return f"{feature} is assigned to {module_id} in {context}, but the module lacks sufficient interpretable gene-enrichment evidence."
    if module_kind == "TE-rich":
        return f"{feature} is assigned to the TE-rich co-expression module {module_id} in {context}; gene enrichment in this module should be treated as weak contextual evidence, not direct TE function."
    return f"{feature} is assigned to the {module_kind} co-expression module {module_id} in {context}; module genes are enriched for {label}, indicating expression context rather than causal regulation."


def write_tsv(path: Path, rows: list[dict], columns: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, delimiter="\t", extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    te_path = args.module_dir / "te_module_summary.tsv"
    interp_path = args.interpretation_dir / "module_interpretation_candidates.tsv"
    if not te_path.exists():
        raise FileNotFoundError(f"TE module summary not found: {te_path}")
    if not interp_path.exists():
        raise FileNotFoundError(f"Module interpretation table not found: {interp_path}")

    te = pd.read_csv(te_path, sep="\t")
    interp = pd.read_csv(interp_path, sep="\t")
    merged = te.merge(
        interp[
            [
                "context_type",
                "module_id",
                "enrichment_status",
                "top_enriched_terms",
                "candidate_label",
            ]
        ],
        on=["context_type", "module_id"],
        how="left",
    ).fillna("")

    rows = []
    for record in merged.to_dict("records"):
        kind = module_type(float(record["TE_fraction"]), int(record["gene_count"]))
        conf = confidence(kind, int(record["gene_count"]), str(record["enrichment_status"]), str(record["candidate_label"]))
        row = {
            **record,
            "module_type": kind,
            "functional_context_confidence": conf,
        }
        row["interpretation_statement_zh"] = statement_zh(row)
        row["interpretation_statement_en"] = statement_en(row)
        rows.append(row)

    rows = sorted(rows, key=lambda r: (r["feature"], r["context_type"]))
    write_tsv(args.output, rows, OUTPUT_COLUMNS)
    l1hs_rows = [row for row in rows if row["feature"] == "L1HS"]
    write_tsv(args.l1hs_output, l1hs_rows, OUTPUT_COLUMNS)

    manifest = {
        "script": str(Path(__file__).resolve()),
        "module_dir": str(args.module_dir),
        "interpretation_dir": str(args.interpretation_dir),
        "output": str(args.output),
        "l1hs_output": str(args.l1hs_output),
        "row_count": len(rows),
        "l1hs_row_count": len(l1hs_rows),
        "confidence_rule": {
            "high": "gene-rich, gene_count >= 50, eligible enrichment with significant terms",
            "medium": "mixed module with significant terms",
            "low": "TE-rich module or weaker significant context",
            "not_interpretable": "too few genes or no interpretable enrichment",
        },
    }
    (args.interpretation_dir / "te_functional_context_manifest.json").write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False),
        encoding="utf-8",
    )
    print(f"Wrote TE functional context: {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
