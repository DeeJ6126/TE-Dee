#!/usr/bin/env python
"""Run functional enrichment for gene-rich co-expression modules via g:Profiler."""

from __future__ import annotations

import argparse
import csv
import json
import time
from pathlib import Path

import pandas as pd
import requests


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_MODULE_DIR = PROJECT_ROOT / "data/coexpression/modules/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_OUTPUT_DIR = PROJECT_ROOT / "data/coexpression/interpretation/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_DATASETS = ["cancer_cell_line", "normal_cell_line", "normal_tissue"]
GPROFILER_URL = "https://biit.cs.ut.ee/gprofiler/api/gost/profile/"
SOURCES = ["GO:BP", "KEGG", "REAC"]


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run GO/KEGG/Reactome enrichment for gene-rich co-expression modules.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("--module-dir", type=Path, default=DEFAULT_MODULE_DIR, help="Module result directory.")
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR, help="Output directory.")
    parser.add_argument("--datasets", nargs="+", default=DEFAULT_DATASETS, choices=DEFAULT_DATASETS)
    parser.add_argument("--min-genes", type=int, default=20, help="Minimum module gene count for enrichment.")
    parser.add_argument("--top-terms", type=int, default=5, help="Number of top terms used for candidate labels.")
    parser.add_argument("--sleep-seconds", type=float, default=0.5, help="Delay between g:Profiler requests.")
    parser.add_argument("--dry-run", action="store_true", help="Write gene sets only; do not call g:Profiler.")
    args = parser.parse_args(argv)
    if args.min_genes < 1:
        parser.error("--min-genes must be positive")
    if args.top_terms < 1:
        parser.error("--top-terms must be positive")
    return args


def load_dataset_modules(module_dir: Path, dataset: str) -> tuple[pd.DataFrame, pd.DataFrame]:
    feature_path = module_dir / f"{dataset}_feature_modules.tsv"
    module_path = module_dir / f"{dataset}_module_summary.tsv"
    if not feature_path.exists():
        raise FileNotFoundError(f"Feature module table not found: {feature_path}")
    if not module_path.exists():
        raise FileNotFoundError(f"Module summary table not found: {module_path}")
    return pd.read_csv(feature_path, sep="\t"), pd.read_csv(module_path, sep="\t")


def module_gene_rows(module_dir: Path, datasets: list[str], min_genes: int) -> tuple[list[dict], dict[str, list[str]], dict[str, pd.DataFrame]]:
    rows: list[dict] = []
    backgrounds: dict[str, list[str]] = {}
    module_tables: dict[str, pd.DataFrame] = {}
    for dataset in datasets:
        feature_df, module_df = load_dataset_modules(module_dir, dataset)
        module_tables[dataset] = module_df
        genes = sorted(feature_df.loc[feature_df["feature_type"].str.lower().eq("gene"), "feature"].astype(str).unique())
        backgrounds[dataset] = genes
        genes_by_module = (
            feature_df.loc[feature_df["feature_type"].str.lower().eq("gene")]
            .groupby("module_id")["feature"]
            .apply(lambda s: sorted(s.astype(str).unique()))
            .to_dict()
        )
        for _, module in module_df.iterrows():
            module_id = str(module["module_id"])
            module_genes = genes_by_module.get(module_id, [])
            rows.append(
                {
                    "context_type": dataset,
                    "module_id": module_id,
                    "module_size": int(module["module_size"]),
                    "TE_count": int(module["TE_count"]),
                    "gene_count": int(module["gene_count"]),
                    "TE_fraction": float(module["TE_fraction"]),
                    "enrichment_status": "eligible" if len(module_genes) >= min_genes else "skipped_too_few_genes",
                    "gene_count_for_enrichment": len(module_genes),
                    "background_gene_count": len(genes),
                    "genes": ";".join(module_genes),
                }
            )
    return rows, backgrounds, module_tables


def write_tsv(path: Path, rows: list[dict], columns: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, delimiter="\t", extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def gprofiler_request(module_genes: list[str], background_genes: list[str]) -> list[dict]:
    payload = {
        "organism": "hsapiens",
        "query": module_genes,
        "domain_scope": "custom_annotated",
        "background": background_genes,
        "sources": SOURCES,
        "user_threshold": 0.05,
        "significance_threshold_method": "fdr",
        "no_iea": False,
    }
    response = requests.post(GPROFILER_URL, json=payload, timeout=60)
    response.raise_for_status()
    return response.json().get("result", [])


def flatten_intersections(value) -> list[str]:
    if value is None:
        return []
    if isinstance(value, str):
        return [value]
    flattened = []
    if isinstance(value, list):
        for item in value:
            flattened.extend(flatten_intersections(item))
    return flattened


def normalize_enrichment_row(context_type: str, module_id: str, item: dict) -> dict:
    intersections = flatten_intersections(item.get("intersections"))
    return {
        "context_type": context_type,
        "module_id": module_id,
        "source": item.get("source", ""),
        "term_id": item.get("native", ""),
        "term_name": item.get("name", ""),
        "p_value": item.get("p_value", ""),
        "negative_log10_p_value": item.get("negative_log10_of_adjusted_p_value", ""),
        "term_size": item.get("term_size", ""),
        "query_size": item.get("query_size", ""),
        "intersection_size": item.get("intersection_size", ""),
        "precision": item.get("precision", ""),
        "recall": item.get("recall", ""),
        "intersection_annotations": ";".join(intersections),
        "description": item.get("description", ""),
    }


def run_enrichment(gene_set_rows: list[dict], backgrounds: dict[str, list[str]], sleep_seconds: float) -> list[dict]:
    results: list[dict] = []
    for row in gene_set_rows:
        if row["enrichment_status"] != "eligible":
            continue
        context_type = row["context_type"]
        module_id = row["module_id"]
        module_genes = [g for g in row["genes"].split(";") if g]
        terms = gprofiler_request(module_genes, backgrounds[context_type])
        for item in terms:
            results.append(normalize_enrichment_row(context_type, module_id, item))
        time.sleep(sleep_seconds)
    return results


def first_nonempty_terms(enrichment_df: pd.DataFrame, module_id: str, top_terms: int) -> str:
    if enrichment_df.empty:
        return ""
    subset = enrichment_df.loc[enrichment_df["module_id"].eq(module_id)].copy()
    if subset.empty:
        return ""
    subset["p_value_numeric"] = pd.to_numeric(subset["p_value"], errors="coerce")
    subset = subset.sort_values(["p_value_numeric", "source", "term_name"]).head(top_terms)
    return "; ".join(f"{row.source}:{row.term_name}" for row in subset.itertuples())


def make_candidate_label(top_terms: str, te_fraction: float, gene_count: int) -> str:
    if gene_count < 20:
        return "too_few_genes_for_functional_enrichment"
    if te_fraction >= 0.5:
        return "TE-rich module; interpret gene enrichment cautiously"
    if not top_terms:
        return "no_significant_GO_KEGG_Reactome_terms"
    names = []
    for term in top_terms.split("; "):
        if ":" in term:
            names.append(term.split(":", 1)[1])
    return " / ".join(names[:3])


def build_interpretation_rows(gene_set_rows: list[dict], enrichment_rows: list[dict], top_terms: int) -> list[dict]:
    enrichment_df = pd.DataFrame(enrichment_rows)
    rows = []
    for row in gene_set_rows:
        top = first_nonempty_terms(enrichment_df, row["module_id"], top_terms)
        rows.append(
            {
                "context_type": row["context_type"],
                "module_id": row["module_id"],
                "module_size": row["module_size"],
                "TE_count": row["TE_count"],
                "gene_count": row["gene_count"],
                "TE_fraction": row["TE_fraction"],
                "gene_count_for_enrichment": row["gene_count_for_enrichment"],
                "enrichment_status": row["enrichment_status"],
                "top_enriched_terms": top,
                "candidate_label": make_candidate_label(top, float(row["TE_fraction"]), int(row["gene_count"])),
                "interpretation_note": "correlation_module_not_causal_regulatory_unit",
            }
        )
    return rows


def write_readme(output_dir: Path, args: argparse.Namespace) -> None:
    text = f"""# Co-expression module enrichment

This directory contains GO Biological Process, KEGG, and Reactome enrichment for the main co-expression module result.

Input module directory:

```text
{args.module_dir}
```

Only modules with at least {args.min_genes} genes are submitted to g:Profiler. The background for each context is the set of gene features present in that context's module table, not all human genes.

Interpretation limit: enrichment terms describe over-represented annotations among module genes. They do not prove TE regulation, causality, or pathway activity.
"""
    (output_dir / "README.md").write_text(text, encoding="utf-8")


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    args.output_dir.mkdir(parents=True, exist_ok=True)
    gene_rows, backgrounds, _ = module_gene_rows(args.module_dir, args.datasets, args.min_genes)
    write_tsv(
        args.output_dir / "module_gene_sets.tsv",
        gene_rows,
        [
            "context_type",
            "module_id",
            "module_size",
            "TE_count",
            "gene_count",
            "TE_fraction",
            "enrichment_status",
            "gene_count_for_enrichment",
            "background_gene_count",
            "genes",
        ],
    )
    enrichment_rows: list[dict] = []
    if not args.dry_run:
        enrichment_rows = run_enrichment(gene_rows, backgrounds, args.sleep_seconds)
    write_tsv(
        args.output_dir / "module_enrichment_results.tsv",
        enrichment_rows,
        [
            "context_type",
            "module_id",
            "source",
            "term_id",
            "term_name",
            "p_value",
            "negative_log10_p_value",
            "term_size",
            "query_size",
            "intersection_size",
            "precision",
            "recall",
            "intersection_annotations",
            "description",
        ],
    )
    interpretation_rows = build_interpretation_rows(gene_rows, enrichment_rows, args.top_terms)
    write_tsv(
        args.output_dir / "module_interpretation_candidates.tsv",
        interpretation_rows,
        [
            "context_type",
            "module_id",
            "module_size",
            "TE_count",
            "gene_count",
            "TE_fraction",
            "gene_count_for_enrichment",
            "enrichment_status",
            "top_enriched_terms",
            "candidate_label",
            "interpretation_note",
        ],
    )
    manifest = {
        "script": str(Path(__file__).resolve()),
        "module_dir": str(args.module_dir),
        "output_dir": str(args.output_dir),
        "datasets": args.datasets,
        "sources": SOURCES,
        "min_genes": args.min_genes,
        "background_policy": "context-specific genes present in feature module table",
        "dry_run": args.dry_run,
    }
    (args.output_dir / "module_enrichment_manifest.json").write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False),
        encoding="utf-8",
    )
    write_readme(args.output_dir, args)
    print(f"Wrote enrichment outputs: {args.output_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
