#!/usr/bin/env python
"""Scan Louvain parameters for one co-expression context.

This script is intended for sensitivity analysis. It reads a raw feature-feature
edge table once, builds positive filtered graphs for a small threshold grid, and
then runs NetworkX Louvain at several resolution values.
"""

from __future__ import annotations

import argparse
import csv
import json
from collections import defaultdict
from pathlib import Path

import networkx as nx
import pandas as pd
from networkx.algorithms.community import louvain_communities


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_EDGE_PATH = PROJECT_ROOT / "data/coexpression/networks/v1/normal_cell_line_edges.tsv"
DEFAULT_OUTPUT_DIR = PROJECT_ROOT / "data/coexpression/modules/parameter_scan"

REQUIRED_COLUMNS = {
    "source",
    "target",
    "source_type",
    "target_type",
    "correlation",
    "abs_correlation",
    "fdr",
}


def parse_float_list(value: str) -> list[float]:
    return [float(item) for item in value.replace(",", " ").split() if item.strip()]


def threshold_label(abs_threshold: float, fdr_threshold: float) -> str:
    return f"abs{abs_threshold:g}_fdr{fdr_threshold:g}"


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Scan Louvain resolution and correlation thresholds for one co-expression network.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("--dataset", default="normal_cell_line", help="Dataset/context label.")
    parser.add_argument("--edge-path", type=Path, default=DEFAULT_EDGE_PATH, help="Raw feature-feature edge TSV.")
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR, help="Output directory.")
    parser.add_argument("--abs-thresholds", default="0.4 0.45 0.5", help="Whitespace/comma separated |r| thresholds.")
    parser.add_argument("--fdr-threshold", type=float, default=0.05, help="FDR threshold.")
    parser.add_argument("--resolutions", default="1.0 1.2 1.4 1.6 1.8 2.0", help="Whitespace/comma separated Louvain resolutions.")
    parser.add_argument("--seed", type=int, default=42, help="Random seed.")
    parser.add_argument("--chunk-size", type=int, default=250_000, help="Rows per TSV chunk.")
    parser.add_argument("--dry-run", action="store_true", help="Print settings without reading edges.")
    args = parser.parse_args(argv)
    args.abs_thresholds = parse_float_list(args.abs_thresholds)
    args.resolutions = parse_float_list(args.resolutions)
    if not args.abs_thresholds:
        parser.error("--abs-thresholds must contain at least one value")
    if not args.resolutions:
        parser.error("--resolutions must contain at least one value")
    if args.fdr_threshold <= 0 or args.fdr_threshold > 1:
        parser.error("--fdr-threshold must be in (0, 1]")
    if args.chunk_size < 1:
        parser.error("--chunk-size must be positive")
    return args


def normalize_feature_type(value: str) -> str:
    return "TE" if value.strip().lower() == "te" else "gene"


class ThresholdGraph:
    def __init__(self, abs_threshold: float, fdr_threshold: float) -> None:
        self.abs_threshold = abs_threshold
        self.fdr_threshold = fdr_threshold
        self.graph = nx.Graph()
        self.feature_types: dict[str, str] = {}
        self.positive_edge_count = 0

    def add_edge(self, row: pd.Series) -> None:
        source = str(row["source"])
        target = str(row["target"])
        if not source or not target or source == target:
            return
        correlation = float(row["correlation"])
        self._set_feature_type(source, normalize_feature_type(str(row["source_type"])))
        self._set_feature_type(target, normalize_feature_type(str(row["target_type"])))
        self.positive_edge_count += 1
        if self.graph.has_edge(source, target):
            if correlation > float(self.graph[source][target].get("weight", 0.0)):
                self.graph[source][target]["weight"] = correlation
        else:
            self.graph.add_edge(source, target, weight=correlation)

    def _set_feature_type(self, feature: str, feature_type: str) -> None:
        previous = self.feature_types.get(feature)
        if previous is None or feature_type == "TE":
            self.feature_types[feature] = feature_type


def build_threshold_graphs(edge_path: Path, abs_thresholds: list[float], fdr_threshold: float, chunk_size: int) -> dict[float, ThresholdGraph]:
    if not edge_path.exists():
        raise FileNotFoundError(f"Edge file not found: {edge_path}")
    graphs = {threshold: ThresholdGraph(threshold, fdr_threshold) for threshold in abs_thresholds}
    usecols = sorted(REQUIRED_COLUMNS)
    for chunk in pd.read_csv(edge_path, sep="\t", usecols=usecols, chunksize=chunk_size):
        missing = REQUIRED_COLUMNS - set(chunk.columns)
        if missing:
            raise ValueError(f"Edge file is missing required columns: {sorted(missing)}")
        for column in ["correlation", "abs_correlation", "fdr"]:
            chunk[column] = pd.to_numeric(chunk[column], errors="coerce")
        base = chunk.loc[(chunk["correlation"] > 0) & (chunk["fdr"] <= fdr_threshold)]
        if base.empty:
            continue
        for threshold, graph in graphs.items():
            passing = base.loc[base["abs_correlation"] >= threshold]
            for _, row in passing.iterrows():
                graph.add_edge(row)
    return graphs


def sorted_communities(graph: nx.Graph, resolution: float, seed: int) -> list[set[str]]:
    communities = louvain_communities(graph, weight="weight", seed=seed, resolution=resolution)
    return sorted((set(c) for c in communities), key=lambda c: (-len(c), sorted(c)[0] if c else ""))


def summarize_modules(dataset: str, graph_data: ThresholdGraph, resolution: float, seed: int) -> dict:
    graph = graph_data.graph
    if graph.number_of_nodes() == 0:
        return {
            "dataset": dataset,
            "abs_threshold": graph_data.abs_threshold,
            "fdr_threshold": graph_data.fdr_threshold,
            "resolution": resolution,
            "positive_edges": 0,
            "nodes": 0,
            "module_count": 0,
            "largest_module_size": 0,
            "largest_module_fraction": 0,
            "small_module_count": 0,
            "te_rich_module_count": 0,
            "max_te_fraction": 0,
            "l1hs_module_size": "",
            "l1hs_module_te_fraction": "",
            "l1hs_module_rank_by_size": "",
        }

    communities = sorted_communities(graph, resolution, seed)
    module_sizes = [len(c) for c in communities]
    te_fractions = []
    l1hs_module_size = ""
    l1hs_module_te_fraction = ""
    l1hs_module_rank = ""
    for idx, community in enumerate(communities, start=1):
        te_count = sum(1 for feature in community if graph_data.feature_types.get(feature, "gene") == "TE")
        te_fraction = te_count / len(community) if community else 0.0
        te_fractions.append(te_fraction)
        if "L1HS" in community:
            l1hs_module_size = len(community)
            l1hs_module_te_fraction = te_fraction
            l1hs_module_rank = idx

    return {
        "dataset": dataset,
        "abs_threshold": graph_data.abs_threshold,
        "fdr_threshold": graph_data.fdr_threshold,
        "resolution": resolution,
        "positive_edges": graph_data.positive_edge_count,
        "nodes": graph.number_of_nodes(),
        "module_count": len(communities),
        "largest_module_size": max(module_sizes),
        "largest_module_fraction": max(module_sizes) / graph.number_of_nodes(),
        "small_module_count": sum(1 for size in module_sizes if size < 10),
        "te_rich_module_count": sum(1 for frac in te_fractions if frac >= 0.5),
        "max_te_fraction": max(te_fractions) if te_fractions else 0,
        "l1hs_module_size": l1hs_module_size,
        "l1hs_module_te_fraction": l1hs_module_te_fraction,
        "l1hs_module_rank_by_size": l1hs_module_rank,
    }


def write_rows(path: Path, rows: list[dict]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    columns = [
        "dataset",
        "abs_threshold",
        "fdr_threshold",
        "resolution",
        "positive_edges",
        "nodes",
        "module_count",
        "largest_module_size",
        "largest_module_fraction",
        "small_module_count",
        "te_rich_module_count",
        "max_te_fraction",
        "l1hs_module_size",
        "l1hs_module_te_fraction",
        "l1hs_module_rank_by_size",
    ]
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, delimiter="\t")
        writer.writeheader()
        writer.writerows(rows)


def write_manifest(args: argparse.Namespace, output_path: Path) -> None:
    manifest = {
        "script": str(Path(__file__).resolve()),
        "dataset": args.dataset,
        "edge_path": str(args.edge_path),
        "output_path": str(output_path),
        "abs_thresholds": args.abs_thresholds,
        "fdr_threshold": args.fdr_threshold,
        "resolutions": args.resolutions,
        "seed": args.seed,
        "positive_edge_policy": "correlation > 0 only",
    }
    (args.output_dir / "normal_cell_line_louvain_scan_manifest.json").write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False),
        encoding="utf-8",
    )


def write_readme(output_dir: Path) -> None:
    text = """# Normal cell line Louvain parameter scan

This directory contains a sensitivity scan for normal-cell-line co-expression module detection.

The scan varies positive-edge correlation thresholds and Louvain `resolution`. It is intended to find a parameter region that avoids both overly coarse module partitions and excessive fragmentation.

Columns include module count, largest module size/fraction, TE-rich module count, and L1HS module size/TE fraction.
"""
    (output_dir / "README.md").write_text(text, encoding="utf-8")


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    args.output_dir.mkdir(parents=True, exist_ok=True)
    output_path = args.output_dir / f"{args.dataset}_louvain_parameter_scan.tsv"
    if args.dry_run:
        print(f"Dataset: {args.dataset}")
        print(f"Edge path: {args.edge_path}")
        print(f"Output: {output_path}")
        print(f"|r| thresholds: {args.abs_thresholds}")
        print(f"FDR threshold: {args.fdr_threshold}")
        print(f"Resolutions: {args.resolutions}")
        return 0

    graphs = build_threshold_graphs(args.edge_path, args.abs_thresholds, args.fdr_threshold, args.chunk_size)
    rows = []
    for threshold in args.abs_thresholds:
        graph_data = graphs[threshold]
        for resolution in args.resolutions:
            rows.append(summarize_modules(args.dataset, graph_data, resolution, args.seed))

    write_rows(output_path, rows)
    write_manifest(args, output_path)
    write_readme(args.output_dir)
    print(f"Wrote parameter scan: {output_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
