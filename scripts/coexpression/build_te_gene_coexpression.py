#!/usr/bin/env python
"""Build feature-feature co-expression networks from bulk expression matrices.

This script implements the conservative v1 feature-feature co-expression workflow:
high-confidence TE features are kept, high-confidence genes are filtered by
detectability and ranked by variability, then correlations are computed among
all selected features per dataset. Importing this module does not run the
analysis.
"""

from __future__ import annotations

import argparse
import json
import math
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

import numpy as np
import pandas as pd

try:
    from tqdm.auto import tqdm
except ImportError:  # pragma: no cover - optional progress display
    tqdm = None

try:
    from scipy import stats
except ImportError as exc:  # pragma: no cover - handled at runtime
    stats = None
    SCIPY_IMPORT_ERROR = exc
else:
    SCIPY_IMPORT_ERROR = None


PROJECT_ROOT = Path(__file__).resolve().parents[2]

DEFAULT_DATASETS = {
    "normal_tissue": PROJECT_ROOT
    / "data/bulk_expression_web/normal_tissue/Normal_tissue_TE_normalized_count.tsv",
    "normal_cell_line": PROJECT_ROOT
    / "data/bulk_expression_web/normal_cell_line/Normal_cell_line_TE_normalized_count.tsv",
    "cancer_cell_line": PROJECT_ROOT
    / "data/bulk_expression_web/cancer_cell_line/CCLE_TE_normalized_count.tsv",
}
DEFAULT_ANNOTATION = PROJECT_ROOT / "data/coexpression/feature_annotation/feature_annotation.tsv"
DEFAULT_OUTPUT_DIR = PROJECT_ROOT / "data/coexpression/networks/v1"
CORRELATION_CHUNK_SIZE = 32

REQUIRED_ANNOTATION_COLUMNS = {
    "feature",
    "feature_type",
    "confidence",
    "annotation_source",
}

EDGE_COLUMNS = [
    "source",
    "target",
    "source_type",
    "target_type",
    "pair_type",
    "edge_type",
    "context_type",
    "method",
    "correlation",
    "abs_correlation",
    "p_value",
    "fdr",
    "sample_count",
    "source_detection_rate",
    "target_detection_rate",
    "expression_filter",
    "variance_metric",
    "interpretation_level",
]

NODE_COLUMNS = [
    "id",
    "label",
    "feature_type",
    "confidence",
    "context_type",
    "detection_rate",
    "selected_for_network",
    "selection_reason",
    "variability_rank",
    "mad",
    "variance",
]

SELECTION_COLUMNS = [
    "feature",
    "feature_type",
    "confidence",
    "context_type",
    "selected_for_network",
    "selection_reason",
    "detection_rate",
    "mad",
    "variance",
    "variability_rank",
]


@dataclass(frozen=True)
class FeatureSets:
    te_features: list[str]
    gene_features: list[str]
    annotation: pd.DataFrame


@dataclass(frozen=True)
class DatasetResult:
    context_type: str
    matrix_path: Path
    sample_count: int
    te_count: int
    selected_gene_count: int
    tested_pair_count: int
    edge_count: int
    methods: list[str]
    edge_path: Path
    node_path: Path
    feature_selection_path: Path


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build conservative feature-feature co-expression networks from bulk expression matrices.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument(
        "--datasets",
        nargs="+",
        choices=sorted(DEFAULT_DATASETS),
        default=sorted(DEFAULT_DATASETS),
        help="Datasets to process.",
    )
    parser.add_argument(
        "--annotation",
        type=Path,
        default=DEFAULT_ANNOTATION,
        help="Feature annotation TSV.",
    )
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=DEFAULT_OUTPUT_DIR,
        help="Output directory for network files.",
    )
    parser.add_argument(
        "--method",
        choices=["spearman", "pearson", "both"],
        default="spearman",
        help="Correlation method.",
    )
    parser.add_argument(
        "--top-genes",
        type=int,
        default=2000,
        help="Number of high-variability genes to select per dataset.",
    )
    parser.add_argument(
        "--gene-min-expression",
        type=float,
        default=1.0,
        help="Raw normalized-count threshold for gene detectability.",
    )
    parser.add_argument(
        "--gene-min-sample-fraction",
        type=float,
        default=0.10,
        help="Minimum sample fraction above expression threshold for genes.",
    )
    parser.add_argument(
        "--min-abs-correlation",
        type=float,
        default=0.0,
        help="Minimum absolute correlation retained in edge output.",
    )
    parser.add_argument(
        "--max-fdr",
        type=float,
        default=1.0,
        help="Maximum Benjamini-Hochberg FDR retained in edge output.",
    )
    parser.add_argument(
        "--matrix-normal-tissue",
        type=Path,
        default=DEFAULT_DATASETS["normal_tissue"],
        help="Normal tissue expression matrix.",
    )
    parser.add_argument(
        "--matrix-normal-cell-line",
        type=Path,
        default=DEFAULT_DATASETS["normal_cell_line"],
        help="Normal cell line expression matrix.",
    )
    parser.add_argument(
        "--matrix-cancer-cell-line",
        type=Path,
        default=DEFAULT_DATASETS["cancer_cell_line"],
        help="Cancer cell line expression matrix.",
    )
    parser.add_argument(
        "--validate-inputs-only",
        action="store_true",
        help="Validate inputs and selected feature availability without computing correlations.",
    )
    return parser.parse_args(argv)


def dataset_paths(args: argparse.Namespace) -> dict[str, Path]:
    return {
        "normal_tissue": args.matrix_normal_tissue,
        "normal_cell_line": args.matrix_normal_cell_line,
        "cancer_cell_line": args.matrix_cancer_cell_line,
    }


def ensure_scipy_available() -> None:
    if stats is None:
        raise RuntimeError(
            "scipy is required for rank transformation and p-value calculation. "
            f"Original import error: {SCIPY_IMPORT_ERROR}"
        )


def load_feature_sets(annotation_path: Path) -> FeatureSets:
    if not annotation_path.exists():
        raise FileNotFoundError(f"Feature annotation file not found: {annotation_path}")
    annotation = pd.read_csv(annotation_path, sep="\t", dtype=str).fillna("")
    missing = REQUIRED_ANNOTATION_COLUMNS - set(annotation.columns)
    if missing:
        raise ValueError(f"Feature annotation is missing required columns: {sorted(missing)}")
    if annotation["feature"].duplicated().any():
        dupes = annotation.loc[annotation["feature"].duplicated(), "feature"].head(10).tolist()
        raise ValueError(f"Feature annotation contains duplicated features, examples: {dupes}")

    high = annotation["confidence"].str.lower().eq("high")
    te_mask = annotation["feature_type"].str.upper().eq("TE") & high
    gene_mask = annotation["feature_type"].str.lower().eq("gene") & high
    te_features = annotation.loc[te_mask, "feature"].tolist()
    gene_features = annotation.loc[gene_mask, "feature"].tolist()
    if not te_features:
        raise ValueError("No high-confidence TE features found in annotation.")
    if not gene_features:
        raise ValueError("No high-confidence gene features found in annotation.")
    return FeatureSets(te_features=te_features, gene_features=gene_features, annotation=annotation)


def read_matrix_feature_column(matrix_path: Path) -> tuple[str, list[str], int]:
    if not matrix_path.exists():
        raise FileNotFoundError(f"Expression matrix not found: {matrix_path}")
    features = pd.read_csv(matrix_path, sep="\t", usecols=[0], dtype=str)
    first_col = str(features.columns[0])
    feature_values = features.iloc[:, 0].astype(str).tolist()
    header = pd.read_csv(matrix_path, sep="\t", nrows=0)
    sample_count = len(header.columns) - 1
    return first_col, feature_values, sample_count


def validate_inputs(args: argparse.Namespace, paths: dict[str, Path], feature_sets: FeatureSets) -> dict:
    report = {
        "annotation": {
            "path": str(args.annotation),
            "high_confidence_te": len(feature_sets.te_features),
            "high_confidence_gene": len(feature_sets.gene_features),
        },
        "datasets": {},
    }
    for dataset in args.datasets:
        first_col, features, sample_count = read_matrix_feature_column(paths[dataset])
        feature_set = set(features)
        missing_te = sorted(set(feature_sets.te_features) - feature_set)
        missing_gene = sorted(set(feature_sets.gene_features) - feature_set)
        duplicate_count = len(features) - len(feature_set)
        report["datasets"][dataset] = {
            "path": str(paths[dataset]),
            "first_column": first_col,
            "feature_rows": len(features),
            "sample_count": sample_count,
            "duplicated_features": duplicate_count,
            "missing_high_confidence_te": len(missing_te),
            "missing_high_confidence_gene": len(missing_gene),
            "missing_te_examples": missing_te[:10],
            "missing_gene_examples": missing_gene[:10],
        }
        if sample_count < 3:
            raise ValueError(f"{dataset} has fewer than 3 sample columns; correlation is not valid.")
        if duplicate_count:
            raise ValueError(f"{dataset} matrix has {duplicate_count} duplicated feature rows.")
        if missing_te:
            raise ValueError(f"{dataset} is missing high-confidence TE features, examples: {missing_te[:10]}")
        if missing_gene:
            raise ValueError(f"{dataset} is missing high-confidence gene features, examples: {missing_gene[:10]}")
    return report


def load_expression_matrix(matrix_path: Path) -> pd.DataFrame:
    df = pd.read_csv(matrix_path, sep="\t")
    if df.shape[1] < 4:
        raise ValueError(f"Expression matrix has too few columns for correlation: {matrix_path}")
    feature_col = df.columns[0]
    if df[feature_col].duplicated().any():
        dupes = df.loc[df[feature_col].duplicated(), feature_col].head(10).tolist()
        raise ValueError(f"Expression matrix contains duplicated features, examples: {dupes}")
    df = df.set_index(feature_col)
    df = df.apply(pd.to_numeric, errors="coerce")
    if df.isna().any().any():
        raise ValueError(f"Expression matrix contains missing or non-numeric values: {matrix_path}")
    if (df < 0).any().any():
        raise ValueError(f"Expression matrix contains negative values: {matrix_path}")
    return df


def median_absolute_deviation(values: np.ndarray) -> np.ndarray:
    med = np.median(values, axis=1)
    return np.median(np.abs(values - med[:, None]), axis=1)


def select_features_for_dataset(
    expr: pd.DataFrame,
    feature_sets: FeatureSets,
    context_type: str,
    top_genes: int,
    gene_min_expression: float,
    gene_min_sample_fraction: float,
) -> tuple[pd.DataFrame, pd.DataFrame, list[str], list[str], pd.DataFrame]:
    te_features = feature_sets.te_features
    gene_features = feature_sets.gene_features

    te_raw = expr.loc[te_features]
    gene_raw = expr.loc[gene_features]
    sample_count = expr.shape[1]
    min_samples = max(1, math.ceil(sample_count * gene_min_sample_fraction))

    te_detection = (te_raw.to_numpy(dtype=float) > gene_min_expression).mean(axis=1)
    gene_detection = (gene_raw.to_numpy(dtype=float) > gene_min_expression).mean(axis=1)
    gene_detectable = gene_detection >= gene_min_sample_fraction

    gene_log = np.log2(gene_raw.to_numpy(dtype=float) + 1.0)
    gene_mad = median_absolute_deviation(gene_log)
    gene_var = np.var(gene_log, axis=1, ddof=1)

    selection = pd.DataFrame(
        {
            "feature": gene_raw.index.to_numpy(),
            "feature_type": "gene",
            "confidence": "high",
            "context_type": context_type,
            "selected_for_network": False,
            "selection_reason": "gene_failed_low_expression_filter",
            "detection_rate": gene_detection,
            "mad": gene_mad,
            "variance": gene_var,
            "variability_rank": "",
        }
    )
    passing = selection.loc[gene_detectable].copy()
    passing = passing.sort_values(["mad", "variance", "feature"], ascending=[False, False, True])
    passing["variability_rank"] = np.arange(1, len(passing) + 1)
    selected_gene_features = passing.head(top_genes)["feature"].tolist()
    selected_gene_set = set(selected_gene_features)

    selection.loc[selection["feature"].isin(selected_gene_set), "selected_for_network"] = True
    selection.loc[selection["feature"].isin(selected_gene_set), "selection_reason"] = (
        "top_mad_after_low_expression_filter"
    )
    rank_map = passing.set_index("feature")["variability_rank"].to_dict()
    selection["variability_rank"] = selection["feature"].map(rank_map).fillna("")

    te_selection = pd.DataFrame(
        {
            "feature": te_raw.index.to_numpy(),
            "feature_type": "TE",
            "confidence": "high",
            "context_type": context_type,
            "selected_for_network": True,
            "selection_reason": "all_high_confidence_te_retained",
            "detection_rate": te_detection,
            "mad": median_absolute_deviation(np.log2(te_raw.to_numpy(dtype=float) + 1.0)),
            "variance": np.var(np.log2(te_raw.to_numpy(dtype=float) + 1.0), axis=1, ddof=1),
            "variability_rank": "",
        }
    )

    expression_filter = (
        f"gene expression > {gene_min_expression:g} in >= "
        f"{gene_min_sample_fraction:g} samples ({min_samples}/{sample_count}); "
        "all high-confidence TE retained"
    )
    selection = pd.concat([te_selection, selection], ignore_index=True)
    selection = selection[SELECTION_COLUMNS]
    selection.attrs["expression_filter"] = expression_filter
    return te_raw, gene_raw.loc[selected_gene_features], te_features, selected_gene_features, selection


def standardize_rows(matrix: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    centered = matrix - matrix.mean(axis=1, keepdims=True)
    ss = np.sqrt(np.sum(centered * centered, axis=1))
    safe = ss > 0
    standardized = np.zeros_like(centered, dtype=float)
    standardized[safe] = centered[safe] / ss[safe, None]
    return standardized, safe


def progress(iterable: Iterable, **kwargs) -> Iterable:
    if tqdm is None:
        return iterable
    return tqdm(iterable, **kwargs)


def correlation_matrix(
    x: np.ndarray,
    y: np.ndarray,
    method: str,
    progress_desc: str | None = None,
    chunk_size: int = CORRELATION_CHUNK_SIZE,
) -> tuple[np.ndarray, np.ndarray]:
    ensure_scipy_available()
    if method == "spearman":
        x = stats.rankdata(x, axis=1)
        y = stats.rankdata(y, axis=1)
    x_std, x_safe = standardize_rows(x.astype(float, copy=False))
    y_std, y_safe = standardize_rows(y.astype(float, copy=False))
    corr = np.empty((x_std.shape[0], y_std.shape[0]), dtype=float)
    chunk_starts = range(0, x_std.shape[0], max(1, chunk_size))
    for start in progress(
        chunk_starts,
        desc=progress_desc,
        unit="TE-chunk",
        leave=False,
    ):
        end = min(start + chunk_size, x_std.shape[0])
        corr[start:end, :] = x_std[start:end] @ y_std.T
    corr = np.clip(corr, -1.0, 1.0)
    corr[~x_safe, :] = np.nan
    corr[:, ~y_safe] = np.nan

    sample_count = x.shape[1]
    df = sample_count - 2
    p_values = np.full(corr.shape, np.nan, dtype=float)
    valid = np.isfinite(corr) & (np.abs(corr) < 1.0) & (df > 0)
    t_stat = np.empty_like(corr, dtype=float)
    t_stat.fill(np.nan)
    t_stat[valid] = corr[valid] * np.sqrt(df / np.maximum(1e-300, 1.0 - corr[valid] ** 2))
    p_values[valid] = 2.0 * stats.t.sf(np.abs(t_stat[valid]), df=df)
    perfect = np.isfinite(corr) & (np.abs(corr) >= 1.0) & (df > 0)
    p_values[perfect] = 0.0
    return corr, p_values


def benjamini_hochberg(p_values: np.ndarray) -> np.ndarray:
    flat = p_values.ravel()
    fdr = np.full(flat.shape, np.nan, dtype=float)
    valid = np.isfinite(flat)
    valid_p = flat[valid]
    if len(valid_p) == 0:
        return fdr.reshape(p_values.shape)
    order = np.argsort(valid_p)
    ranked = valid_p[order]
    n = len(ranked)
    adjusted = ranked * n / np.arange(1, n + 1)
    adjusted = np.minimum.accumulate(adjusted[::-1])[::-1]
    adjusted = np.clip(adjusted, 0.0, 1.0)
    valid_indices = np.where(valid)[0]
    fdr[valid_indices[order]] = adjusted
    return fdr.reshape(p_values.shape)


def benjamini_hochberg_upper_triangle(p_values: np.ndarray) -> np.ndarray:
    masked = np.full(p_values.shape, np.nan, dtype=float)
    upper = np.triu(np.ones(p_values.shape, dtype=bool), k=1)
    masked[upper] = p_values[upper]
    return benjamini_hochberg(masked)


def feature_pair_type(source_type: str, target_type: str) -> str:
    normalized = {source_type.lower(), target_type.lower()}
    if normalized == {"te"}:
        return "TE_TE"
    if normalized == {"gene"}:
        return "gene_gene"
    return "TE_gene"


def build_feature_feature_edges(
    features: list[str],
    feature_types: pd.Series,
    corr: np.ndarray,
    p_values: np.ndarray,
    fdr: np.ndarray,
    context_type: str,
    method: str,
    sample_count: int,
    detection: pd.Series,
    expression_filter: str,
    min_abs_correlation: float,
    max_fdr: float,
) -> pd.DataFrame:
    abs_corr = np.abs(corr)
    upper = np.triu(np.ones(corr.shape, dtype=bool), k=1)
    keep = upper & np.isfinite(corr) & np.isfinite(fdr)
    keep &= abs_corr >= min_abs_correlation
    keep &= fdr <= max_fdr
    source_idx, target_idx = np.where(keep)
    if len(source_idx) == 0:
        return pd.DataFrame(columns=EDGE_COLUMNS)

    feature_array = np.asarray(features, dtype=object)
    sources = feature_array[source_idx]
    targets = feature_array[target_idx]
    source_types = [feature_types.loc[x] for x in sources]
    target_types = [feature_types.loc[x] for x in targets]
    pair_types = [feature_pair_type(s, t) for s, t in zip(source_types, target_types)]

    edges = pd.DataFrame(
        {
            "source": sources,
            "target": targets,
            "source_type": source_types,
            "target_type": target_types,
            "pair_type": pair_types,
            "edge_type": "co_expression",
            "context_type": context_type,
            "method": method,
            "correlation": corr[source_idx, target_idx],
            "abs_correlation": abs_corr[source_idx, target_idx],
            "p_value": p_values[source_idx, target_idx],
            "fdr": fdr[source_idx, target_idx],
            "sample_count": sample_count,
            "source_detection_rate": [detection.loc[x] for x in sources],
            "target_detection_rate": [detection.loc[x] for x in targets],
            "expression_filter": expression_filter,
            "variance_metric": "MAD_primary_variance_secondary",
            "interpretation_level": "correlation_only",
        }
    )
    return edges[EDGE_COLUMNS].sort_values(
        ["abs_correlation", "fdr", "source", "target"], ascending=[False, True, True, True]
    )


def build_edges(
    te_features: list[str],
    gene_features: list[str],
    corr: np.ndarray,
    p_values: np.ndarray,
    fdr: np.ndarray,
    context_type: str,
    method: str,
    sample_count: int,
    te_detection: pd.Series,
    gene_detection: pd.Series,
    expression_filter: str,
    min_abs_correlation: float,
    max_fdr: float,
) -> pd.DataFrame:
    abs_corr = np.abs(corr)
    keep = np.isfinite(corr) & np.isfinite(fdr)
    keep &= abs_corr >= min_abs_correlation
    keep &= fdr <= max_fdr
    te_idx, gene_idx = np.where(keep)
    if len(te_idx) == 0:
        return pd.DataFrame(columns=EDGE_COLUMNS)
    sources = np.asarray(te_features, dtype=object)[te_idx]
    targets = np.asarray(gene_features, dtype=object)[gene_idx]
    edges = pd.DataFrame(
        {
            "source": sources,
            "target": targets,
            "source_type": "TE",
            "target_type": "gene",
            "pair_type": "TE_gene",
            "edge_type": "co_expression",
            "context_type": context_type,
            "method": method,
            "correlation": corr[te_idx, gene_idx],
            "abs_correlation": abs_corr[te_idx, gene_idx],
            "p_value": p_values[te_idx, gene_idx],
            "fdr": fdr[te_idx, gene_idx],
            "sample_count": sample_count,
            "source_detection_rate": [te_detection.loc[x] for x in sources],
            "target_detection_rate": [gene_detection.loc[x] for x in targets],
            "expression_filter": expression_filter,
            "variance_metric": "MAD_primary_variance_secondary",
            "interpretation_level": "correlation_only",
        }
    )
    return edges[EDGE_COLUMNS].sort_values(
        ["abs_correlation", "fdr", "source", "target"], ascending=[False, True, True, True]
    )


def build_nodes(selection: pd.DataFrame, context_type: str) -> pd.DataFrame:
    selected = selection.loc[selection["selected_for_network"].astype(bool)].copy()
    nodes = selected.rename(columns={"feature": "id"})
    nodes["label"] = nodes["id"]
    nodes["context_type"] = context_type
    nodes = nodes.rename(columns={"feature_type": "feature_type"})
    return nodes[NODE_COLUMNS].sort_values(["feature_type", "id"])


def write_dataset_outputs(
    output_dir: Path,
    context_type: str,
    edges: list[pd.DataFrame],
    nodes: pd.DataFrame,
    selection: pd.DataFrame,
) -> tuple[Path, Path, Path, int]:
    output_dir.mkdir(parents=True, exist_ok=True)
    edge_path = output_dir / f"{context_type}_edges.tsv"
    node_path = output_dir / f"{context_type}_nodes.tsv"
    selection_path = output_dir / f"{context_type}_feature_selection.tsv"
    edge_df = pd.concat(edges, ignore_index=True) if edges else pd.DataFrame(columns=EDGE_COLUMNS)
    edge_df.to_csv(edge_path, sep="\t", index=False)
    nodes.to_csv(node_path, sep="\t", index=False)
    selection.to_csv(selection_path, sep="\t", index=False)
    return edge_path, node_path, selection_path, len(edge_df)


def run_dataset(
    dataset: str,
    matrix_path: Path,
    feature_sets: FeatureSets,
    args: argparse.Namespace,
    methods: list[str],
) -> DatasetResult:
    expr = load_expression_matrix(matrix_path)
    te_raw, gene_raw, te_features, selected_genes, selection = select_features_for_dataset(
        expr=expr,
        feature_sets=feature_sets,
        context_type=dataset,
        top_genes=args.top_genes,
        gene_min_expression=args.gene_min_expression,
        gene_min_sample_fraction=args.gene_min_sample_fraction,
    )
    feature_raw = pd.concat([te_raw, gene_raw], axis=0)
    selected_feature_table = selection.loc[selection["selected_for_network"].astype(bool)].set_index("feature")
    selected_features = feature_raw.index.astype(str).tolist()
    feature_log = np.log2(feature_raw.to_numpy(dtype=float) + 1.0)
    feature_types = selected_feature_table.loc[selected_features, "feature_type"]
    detection = selected_feature_table.loc[selected_features, "detection_rate"]
    expression_filter = selection.attrs.get("expression_filter", "")

    edge_outputs = []
    for method in progress(methods, desc=f"{dataset}: methods", unit="method", leave=False):
        corr, p_values = correlation_matrix(
            feature_log,
            feature_log,
            method,
            progress_desc=f"{dataset}: {method} feature correlation",
        )
        fdr = benjamini_hochberg_upper_triangle(p_values)
        edge_outputs.append(
            build_feature_feature_edges(
                features=selected_features,
                feature_types=feature_types,
                corr=corr,
                p_values=p_values,
                fdr=fdr,
                context_type=dataset,
                method=method,
                sample_count=expr.shape[1],
                detection=detection,
                expression_filter=expression_filter,
                min_abs_correlation=args.min_abs_correlation,
                max_fdr=args.max_fdr,
            )
        )

    nodes = build_nodes(selection, dataset)
    edge_path, node_path, selection_path, edge_count = write_dataset_outputs(
        args.output_dir, dataset, edge_outputs, nodes, selection
    )
    return DatasetResult(
        context_type=dataset,
        matrix_path=matrix_path,
        sample_count=expr.shape[1],
        te_count=len(te_features),
        selected_gene_count=len(selected_genes),
        tested_pair_count=(len(selected_features) * (len(selected_features) - 1) // 2) * len(methods),
        edge_count=edge_count,
        methods=methods,
        edge_path=edge_path,
        node_path=node_path,
        feature_selection_path=selection_path,
    )


def write_manifest(
    args: argparse.Namespace,
    validation_report: dict,
    results: Iterable[DatasetResult],
    methods: list[str],
) -> Path:
    args.output_dir.mkdir(parents=True, exist_ok=True)
    manifest_path = args.output_dir / "coexpression_run_manifest.json"
    manifest = {
        "pipeline": "feature-feature co-expression v1",
        "script": str(Path(__file__).resolve()),
        "annotation": str(args.annotation),
        "datasets": args.datasets,
        "methods": methods,
        "parameters": {
            "top_genes": args.top_genes,
            "gene_min_expression": args.gene_min_expression,
            "gene_min_sample_fraction": args.gene_min_sample_fraction,
            "min_abs_correlation": args.min_abs_correlation,
            "max_fdr": args.max_fdr,
            "transformation": "log2(normalized_count + 1)",
            "gene_variability_metric": "MAD primary; variance secondary",
            "edge_type": "co_expression",
            "network_scope": "all selected features; upper triangle without self edges",
            "interpretation_level": "correlation_only",
        },
        "input_validation": validation_report,
        "outputs": [
            {
                "context_type": r.context_type,
                "matrix_path": str(r.matrix_path),
                "sample_count": r.sample_count,
                "te_count": r.te_count,
                "selected_gene_count": r.selected_gene_count,
                "tested_pair_count": r.tested_pair_count,
                "edge_count": r.edge_count,
                "methods": r.methods,
                "edge_path": str(r.edge_path),
                "node_path": str(r.node_path),
                "feature_selection_path": str(r.feature_selection_path),
            }
            for r in results
        ],
    }
    manifest_path.write_text(json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8")
    return manifest_path


def selected_methods(method: str) -> list[str]:
    if method == "both":
        return ["spearman", "pearson"]
    return [method]


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    paths = dataset_paths(args)
    feature_sets = load_feature_sets(args.annotation)
    validation_report = validate_inputs(args, paths, feature_sets)

    if args.validate_inputs_only:
        print(json.dumps(validation_report, indent=2, ensure_ascii=False))
        print("Input validation completed. Full co-expression analysis was not run.")
        return 0

    ensure_scipy_available()
    methods = selected_methods(args.method)
    results = []
    for dataset in progress(args.datasets, desc="Datasets", unit="dataset"):
        print(f"[{dataset}] building feature-feature co-expression network with {', '.join(methods)}")
        results.append(run_dataset(dataset, paths[dataset], feature_sets, args, methods))
    manifest_path = write_manifest(args, validation_report, results, methods)
    print(f"Wrote manifest: {manifest_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
