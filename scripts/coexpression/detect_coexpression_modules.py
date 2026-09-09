#!/usr/bin/env python
"""Detect co-expression modules from filtered positive co-expression networks.

This stage consumes the already-filtered/summarized network TSVs from
``data/coexpression/analysis/v1/abs0.4_fdr0.05``. Community detection is run
only on positive correlation edges because negative co-expression is not a
similarity relationship for Louvain modularity.
"""

from __future__ import annotations

import argparse
import csv
import json
import math
import platform
from collections import defaultdict
from dataclasses import dataclass, field
from importlib import metadata
from pathlib import Path
from typing import Iterable

import networkx as nx
import pandas as pd
from networkx.algorithms.community import louvain_communities


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_INPUT_DIR = PROJECT_ROOT / "data/coexpression/analysis/v1/abs0.4_fdr0.05"
DEFAULT_OUTPUT_DIR = PROJECT_ROOT / "data/coexpression/modules/v1_abs0.4_fdr0.05"
DEFAULT_DATASETS = ["cancer_cell_line", "normal_cell_line", "normal_tissue"]

REQUIRED_EDGE_COLUMNS = {
    "source",
    "target",
    "source_type",
    "target_type",
    "context_type",
    "correlation",
}

FEATURE_MODULE_COLUMNS = [
    "context_type",
    "feature",
    "feature_type",
    "module_id",
    "module_size",
    "within_module_degree",
    "weighted_within_module_degree",
    "positive_degree",
    "weighted_positive_degree",
    "is_module_hub",
    "module_hub_rank",
]

MODULE_SUMMARY_COLUMNS = [
    "context_type",
    "module_id",
    "module_size",
    "TE_count",
    "gene_count",
    "TE_fraction",
    "internal_edge_count",
    "mean_internal_correlation",
    "hub_features",
    "hub_TEs",
    "hub_genes",
]

TE_MODULE_SUMMARY_COLUMNS = [
    *FEATURE_MODULE_COLUMNS,
    "TE_count",
    "gene_count",
    "TE_fraction",
    "internal_edge_count",
    "mean_internal_correlation",
    "hub_features",
    "hub_TEs",
    "hub_genes",
]

CROSS_CONTEXT_FIELDS = [
    "module_id",
    "module_size",
    "is_module_hub",
    "positive_degree",
    "weighted_positive_degree",
]


@dataclass
class PositiveGraphBuilder:
    context_type: str
    graph: nx.Graph = field(default_factory=nx.Graph)
    feature_types: dict[str, str] = field(default_factory=dict)
    positive_degree: defaultdict[str, int] = field(default_factory=lambda: defaultdict(int))
    weighted_positive_degree: defaultdict[str, float] = field(default_factory=lambda: defaultdict(float))
    input_edge_count: int = 0
    positive_edge_count: int = 0

    def consume_rows(self, rows: Iterable[dict]) -> None:
        for row in rows:
            self.consume_edge(row)

    def consume_edge(self, row: dict) -> None:
        self.input_edge_count += 1
        correlation = float(row["correlation"])
        if correlation <= 0:
            return

        source = str(row["source"])
        target = str(row["target"])
        if not source or not target or source == target:
            return

        source_type = normalize_feature_type(str(row["source_type"]))
        target_type = normalize_feature_type(str(row["target_type"]))
        self._set_feature_type(source, source_type)
        self._set_feature_type(target, target_type)

        self.positive_edge_count += 1
        self.positive_degree[source] += 1
        self.positive_degree[target] += 1
        self.weighted_positive_degree[source] += correlation
        self.weighted_positive_degree[target] += correlation

        if self.graph.has_edge(source, target):
            existing = float(self.graph[source][target].get("weight", 0.0))
            if correlation > existing:
                self.graph[source][target]["weight"] = correlation
        else:
            self.graph.add_edge(source, target, weight=correlation)

    def _set_feature_type(self, feature: str, feature_type: str) -> None:
        previous = self.feature_types.get(feature)
        if previous is None:
            self.feature_types[feature] = feature_type
        elif previous != feature_type and feature_type == "TE":
            self.feature_types[feature] = "TE"


def normalize_feature_type(value: str) -> str:
    return "TE" if value.strip().lower() == "te" else "gene"


def format_float(value: float) -> str:
    return f"{value:.12g}"


def edge_path(input_dir: Path, dataset: str) -> Path:
    return input_dir / f"{dataset}_edges.tsv"


def feature_modules_path(output_dir: Path, dataset: str) -> Path:
    return output_dir / f"{dataset}_feature_modules.tsv"


def module_summary_path(output_dir: Path, dataset: str) -> Path:
    return output_dir / f"{dataset}_module_summary.tsv"


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Detect Louvain co-expression modules from filtered positive co-expression networks.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("--input-dir", type=Path, default=DEFAULT_INPUT_DIR, help="Filtered network directory.")
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR, help="Module output directory.")
    parser.add_argument(
        "--datasets",
        nargs="+",
        choices=DEFAULT_DATASETS,
        default=DEFAULT_DATASETS,
        help="Datasets to process.",
    )
    parser.add_argument("--min-module-size", type=int, default=3, help="Minimum module size eligible for hubs.")
    parser.add_argument("--seed", type=int, default=42, help="Random seed for NetworkX Louvain.")
    parser.add_argument(
        "--resolution",
        type=float,
        default=1.0,
        help="Louvain resolution parameter. Larger values generally produce smaller, more numerous modules.",
    )
    parser.add_argument("--chunk-size", type=int, default=250_000, help="Rows per edge TSV chunk.")
    parser.add_argument("--dry-run", action="store_true", help="Report paths and settings without processing edges.")
    args = parser.parse_args(argv)
    if args.min_module_size < 1:
        parser.error("--min-module-size must be at least 1")
    if args.resolution <= 0:
        parser.error("--resolution must be positive")
    if args.chunk_size < 1:
        parser.error("--chunk-size must be at least 1")
    return args


def validate_edge_columns(columns: Iterable[str], path: Path) -> None:
    missing = REQUIRED_EDGE_COLUMNS - set(columns)
    if missing:
        raise ValueError(f"Edge file {path} is missing required columns: {sorted(missing)}")


def load_positive_graph(edge_file: Path, dataset: str, chunk_size: int) -> PositiveGraphBuilder:
    if not edge_file.exists():
        raise FileNotFoundError(f"Filtered edge file not found: {edge_file}")
    builder = PositiveGraphBuilder(dataset)
    first_chunk = True
    usecols = sorted(REQUIRED_EDGE_COLUMNS)
    for chunk in pd.read_csv(edge_file, sep="\t", chunksize=chunk_size, usecols=usecols):
        if first_chunk:
            validate_edge_columns(chunk.columns, edge_file)
            first_chunk = False
        chunk["correlation"] = pd.to_numeric(chunk["correlation"], errors="coerce")
        positive = chunk.loc[chunk["correlation"] > 0]
        if positive.empty:
            builder.input_edge_count += len(chunk)
            continue
        builder.consume_rows(positive.to_dict("records"))
        builder.input_edge_count += len(chunk) - len(positive)
    return builder


def detect_communities(graph: nx.Graph, seed: int, resolution: float) -> list[set[str]]:
    if graph.number_of_nodes() == 0:
        return []
    communities = [
        set(c)
        for c in louvain_communities(
            graph,
            weight="weight",
            seed=seed,
            resolution=resolution,
        )
    ]
    return sort_communities(communities)


def sort_communities(communities: Iterable[set[str]]) -> list[set[str]]:
    return sorted((set(c) for c in communities), key=lambda c: (-len(c), sorted(c)[0] if c else ""))


def hub_count_for_module(module_size: int, min_module_size: int) -> int:
    if module_size < min_module_size:
        return 0
    return max(1, math.ceil(module_size * 0.05))


def summarize_communities(
    context_type: str,
    builder: PositiveGraphBuilder,
    communities: Iterable[set[str]],
    min_module_size: int,
) -> tuple[list[dict], list[dict]]:
    feature_rows: list[dict] = []
    module_rows: list[dict] = []

    for module_index, community in enumerate(sort_communities(communities), start=1):
        module_id = f"{context_type}_M{module_index:03d}"
        subgraph = builder.graph.subgraph(community)
        module_size = len(community)
        internal_weights = [float(data.get("weight", 0.0)) for _, _, data in subgraph.edges(data=True)]
        hub_count = hub_count_for_module(module_size, min_module_size)
        weighted_within = dict(subgraph.degree(weight="weight"))
        within_degree = dict(subgraph.degree())
        ranked_features = sorted(
            community,
            key=lambda feature: (-float(weighted_within.get(feature, 0.0)), -int(within_degree.get(feature, 0)), feature),
        )
        hub_ranks = {feature: rank for rank, feature in enumerate(ranked_features[:hub_count], start=1)}

        te_features = sorted(f for f in community if builder.feature_types.get(f, "gene") == "TE")
        gene_features = sorted(f for f in community if builder.feature_types.get(f, "gene") != "TE")
        hub_features = sorted(hub_ranks, key=lambda f: hub_ranks[f])
        hub_tes = [f for f in hub_features if builder.feature_types.get(f, "gene") == "TE"]
        hub_genes = [f for f in hub_features if builder.feature_types.get(f, "gene") != "TE"]

        for feature in ranked_features:
            hub_rank = hub_ranks.get(feature, "")
            feature_rows.append(
                {
                    "context_type": context_type,
                    "feature": feature,
                    "feature_type": builder.feature_types.get(feature, "gene"),
                    "module_id": module_id,
                    "module_size": module_size,
                    "within_module_degree": int(within_degree.get(feature, 0)),
                    "weighted_within_module_degree": float(weighted_within.get(feature, 0.0)),
                    "positive_degree": int(builder.positive_degree.get(feature, 0)),
                    "weighted_positive_degree": float(builder.weighted_positive_degree.get(feature, 0.0)),
                    "is_module_hub": "true" if hub_rank else "false",
                    "module_hub_rank": hub_rank,
                }
            )

        module_rows.append(
            {
                "context_type": context_type,
                "module_id": module_id,
                "module_size": module_size,
                "TE_count": len(te_features),
                "gene_count": len(gene_features),
                "TE_fraction": len(te_features) / module_size if module_size else 0.0,
                "internal_edge_count": subgraph.number_of_edges(),
                "mean_internal_correlation": sum(internal_weights) / len(internal_weights) if internal_weights else 0.0,
                "hub_features": ";".join(hub_features),
                "hub_TEs": ";".join(hub_tes),
                "hub_genes": ";".join(hub_genes),
            }
        )

    return feature_rows, module_rows


def normalize_output_row(row: dict, columns: list[str]) -> dict:
    normalized = {}
    for column in columns:
        value = row.get(column, "")
        if isinstance(value, float):
            normalized[column] = format_float(value)
        else:
            normalized[column] = value
    return normalized


def write_rows(path: Path, rows: list[dict], columns: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, delimiter="\t", extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow(normalize_output_row(row, columns))


def write_te_module_summary(output_dir: Path, dataset_feature_rows: dict[str, list[dict]], dataset_module_rows: dict[str, list[dict]]) -> list[dict]:
    rows: list[dict] = []
    for dataset, feature_rows in dataset_feature_rows.items():
        modules_by_id = {row["module_id"]: row for row in dataset_module_rows[dataset]}
        for feature_row in feature_rows:
            if feature_row["feature_type"] != "TE":
                continue
            module_row = modules_by_id[feature_row["module_id"]]
            rows.append({**feature_row, **{key: module_row[key] for key in MODULE_SUMMARY_COLUMNS if key not in {"context_type", "module_id", "module_size"}}})
    write_rows(output_dir / "te_module_summary.tsv", rows, TE_MODULE_SUMMARY_COLUMNS)
    return rows


def write_cross_context_te_summary(output_dir: Path, te_rows: list[dict], datasets: list[str]) -> list[dict]:
    by_feature: dict[str, dict[str, dict]] = defaultdict(dict)
    for row in te_rows:
        by_feature[row["feature"]][row["context_type"]] = row

    columns = ["feature"]
    for dataset in datasets:
        columns.extend(f"{dataset}_{field}" for field in CROSS_CONTEXT_FIELDS)

    output_rows = []
    for feature in sorted(by_feature):
        out = {"feature": feature}
        for dataset in datasets:
            row = by_feature[feature].get(dataset, {})
            for field in CROSS_CONTEXT_FIELDS:
                out[f"{dataset}_{field}"] = row.get(field, "")
        output_rows.append(out)
    write_rows(output_dir / "cross_context_te_module_summary.tsv", output_rows, columns)
    return output_rows


def package_version(package: str) -> str:
    try:
        return metadata.version(package)
    except metadata.PackageNotFoundError:
        return "not_installed"


def write_manifest(
    args: argparse.Namespace,
    dataset_stats: list[dict],
    te_row_count: int,
    cross_context_te_count: int,
) -> Path:
    manifest = {
        "pipeline": "co-expression module detection stage 3",
        "algorithm": "networkx_louvain",
        "seed": args.seed,
        "resolution": args.resolution,
        "script": str(Path(__file__).resolve()),
        "input_dir": str(args.input_dir),
        "output_dir": str(args.output_dir),
        "datasets": args.datasets,
        "parameters": {
            "min_module_size": args.min_module_size,
            "chunk_size": args.chunk_size,
            "resolution": args.resolution,
            "edge_weight": "correlation",
            "positive_edge_policy": "Only edges with correlation > 0 are included in community detection.",
            "negative_edge_policy": "Negative and zero correlation edges are excluded from module detection.",
        },
        "versions": {
            "python": platform.python_version(),
            "platform": platform.platform(),
            "networkx": nx.__version__,
            "pandas": pd.__version__,
            "igraph": package_version("igraph"),
            "leidenalg": package_version("leidenalg"),
        },
        "outputs": {
            "te_module_summary": str(args.output_dir / "te_module_summary.tsv"),
            "cross_context_te_module_summary": str(args.output_dir / "cross_context_te_module_summary.tsv"),
            "te_module_row_count": te_row_count,
            "cross_context_te_count": cross_context_te_count,
            "datasets": dataset_stats,
        },
    }
    path = args.output_dir / "module_detection_manifest.json"
    path.write_text(json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8")
    return path


def write_readme(output_dir: Path) -> None:
    output_dir.mkdir(parents=True, exist_ok=True)
    text = """# Co-expression modules v1 abs0.4 fdr0.05

This directory contains stage-3 module detection outputs generated from the filtered co-expression networks in `data/coexpression/analysis/v1/abs0.4_fdr0.05/`.

## Method

Modules were detected separately for `cancer_cell_line`, `normal_cell_line`, and `normal_tissue` using NetworkX Louvain community detection (`networkx.algorithms.community.louvain_communities`) on undirected weighted graphs. Edge weights are the positive Spearman correlations retained by the upstream filter.

The Louvain `resolution` parameter is recorded in `module_detection_manifest.json`. Larger values generally split dense graphs into smaller and more numerous modules.

## Positive-edge-only rule

Only edges with `correlation > 0` are included in community detection. Negative and zero-correlation edges are excluded because Louvain modularity expects similarity-like positive edge weights. The output degree fields therefore describe the positive filtered network, not the full signed co-expression network.

## Hub rule

Within each module, features are ranked by weighted within-module degree. Modules with size at least the configured minimum module size mark the top 5% as hubs, with at least one hub per eligible module.

## Output files

- `{dataset}_feature_modules.tsv`: feature-level module assignments, within-module degree, positive degree, and hub flags.
- `{dataset}_module_summary.tsv`: module-level size, TE/gene composition, internal edge count, mean internal correlation, and hub lists.
- `te_module_summary.tsv`: TE-only feature rows joined with module summary fields.
- `cross_context_te_module_summary.tsv`: one row per TE with module fields across all three contexts.
- `module_detection_manifest.json`: algorithm, parameters, package versions, inputs, outputs, and positive-edge policy.

## Interpretation limits

Modules are correlation communities, not causal pathways or regulatory units. They depend on the upstream feature selection, correlation threshold, FDR threshold, sample composition, and positive-edge-only policy. Use module membership and hubs for prioritization and exploratory interpretation, not as standalone mechanistic evidence.
"""
    (output_dir / "README.md").write_text(text, encoding="utf-8")


def dry_run(args: argparse.Namespace) -> int:
    print("Dry run: no edge files will be processed and no outputs will be written.")
    print(f"Input directory: {args.input_dir}")
    print(f"Output directory: {args.output_dir}")
    print(f"Algorithm: networkx_louvain, seed={args.seed}, resolution={args.resolution}")
    print("Positive-edge policy: retain correlation > 0 only")
    for dataset in args.datasets:
        print(f"{dataset}: {edge_path(args.input_dir, dataset)}")
    return 0


def run(args: argparse.Namespace) -> dict:
    args.output_dir.mkdir(parents=True, exist_ok=True)
    write_readme(args.output_dir)

    dataset_feature_rows: dict[str, list[dict]] = {}
    dataset_module_rows: dict[str, list[dict]] = {}
    dataset_stats: list[dict] = []

    for dataset in args.datasets:
        input_path = edge_path(args.input_dir, dataset)
        print(f"[{dataset}] loading positive edges from {input_path}")
        builder = load_positive_graph(input_path, dataset, args.chunk_size)
        print(
            f"[{dataset}] positive graph: {builder.graph.number_of_nodes()} nodes, "
            f"{builder.graph.number_of_edges()} edges"
        )
        communities = detect_communities(builder.graph, args.seed, args.resolution)
        feature_rows, module_rows = summarize_communities(dataset, builder, communities, args.min_module_size)
        write_rows(feature_modules_path(args.output_dir, dataset), feature_rows, FEATURE_MODULE_COLUMNS)
        write_rows(module_summary_path(args.output_dir, dataset), module_rows, MODULE_SUMMARY_COLUMNS)
        dataset_feature_rows[dataset] = feature_rows
        dataset_module_rows[dataset] = module_rows
        dataset_stats.append(
            {
                "context_type": dataset,
                "input_edge_path": str(input_path),
                "feature_module_path": str(feature_modules_path(args.output_dir, dataset)),
                "module_summary_path": str(module_summary_path(args.output_dir, dataset)),
                "input_edge_count": builder.input_edge_count,
                "positive_edge_count": builder.positive_edge_count,
                "graph_node_count": builder.graph.number_of_nodes(),
                "graph_edge_count": builder.graph.number_of_edges(),
                "module_count": len(module_rows),
                "eligible_hub_count": sum(1 for row in feature_rows if row["is_module_hub"] == "true"),
                "TE_feature_count": sum(1 for row in feature_rows if row["feature_type"] == "TE"),
                "gene_feature_count": sum(1 for row in feature_rows if row["feature_type"] != "TE"),
            }
        )

    te_rows = write_te_module_summary(args.output_dir, dataset_feature_rows, dataset_module_rows)
    cross_rows = write_cross_context_te_summary(args.output_dir, te_rows, args.datasets)
    manifest_path = write_manifest(args, dataset_stats, len(te_rows), len(cross_rows))
    return {"manifest_path": manifest_path, "dataset_stats": dataset_stats}


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    if args.dry_run:
        return dry_run(args)
    result = run(args)
    print(f"Wrote module detection manifest: {result['manifest_path']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
