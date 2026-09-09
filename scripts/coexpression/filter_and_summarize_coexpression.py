#!/usr/bin/env python
"""Filter raw co-expression networks and summarize thresholded networks.

The raw edge TSV files under data/coexpression/networks/v1 are large. This
postprocessor reads them in chunks, writes thresholded edge TSVs, and updates
summary statistics incrementally without loading all raw edges into memory.
"""

from __future__ import annotations

import argparse
import csv
import heapq
import math
from collections import Counter, defaultdict
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable

import pandas as pd


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_RAW_DIR = PROJECT_ROOT / "data/coexpression/networks/v1"
DEFAULT_OUTPUT_DIR = PROJECT_ROOT / "data/coexpression/analysis/v1"
DEFAULT_DATASETS = ["cancer_cell_line", "normal_cell_line", "normal_tissue"]
DEFAULT_THRESHOLDS = ["0.3:0.05", "0.4:0.05", "0.5:0.05"]

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

DATASET_SUMMARY_COLUMNS = [
    "dataset",
    "total_edges",
    "positive_edges",
    "negative_edges",
    "TE_TE_edges",
    "TE_gene_edges",
    "gene_gene_edges",
    "pair_type_counts",
    "source_raw_path",
    "filtered_edge_path",
]

FEATURE_SUMMARY_COLUMNS = [
    "dataset",
    "feature",
    "feature_type",
    "context_type",
    "degree_total",
    "positive_degree",
    "negative_degree",
    "TE_neighbor_count",
    "gene_neighbor_count",
    "TE_TE_edge_count",
    "TE_gene_edge_count",
    "gene_gene_edge_count",
    "max_abs_correlation",
    "mean_abs_correlation",
    "mean_positive_correlation",
    "mean_negative_correlation",
    "top_positive_partners",
    "top_negative_partners",
    "is_hub",
    "hub_rank",
]


@dataclass(frozen=True)
class Threshold:
    abs_correlation: float
    fdr: float
    label: str


@dataclass(order=True)
class PartnerEntry:
    score: tuple[float, float, str] = field(init=False, repr=False)
    partner: str
    correlation: float
    fdr: float

    def __post_init__(self) -> None:
        object.__setattr__(self, "score", (abs(self.correlation), -self.fdr, self.partner))

    def serialize(self) -> str:
        return f"{self.partner}|r={format_float(self.correlation)}|fdr={format_float(self.fdr)}"


@dataclass
class FeatureStats:
    feature: str
    feature_type: str
    context_type: str
    degree_total: int = 0
    positive_degree: int = 0
    negative_degree: int = 0
    te_neighbors: set[str] = field(default_factory=set)
    gene_neighbors: set[str] = field(default_factory=set)
    pair_type_counts: Counter = field(default_factory=Counter)
    abs_sum: float = 0.0
    abs_count: int = 0
    max_abs_correlation: float | None = None
    positive_sum: float = 0.0
    positive_count: int = 0
    negative_sum: float = 0.0
    negative_count: int = 0
    positive_partners: list[PartnerEntry] = field(default_factory=list)
    negative_partners: list[PartnerEntry] = field(default_factory=list)


class DatasetAggregator:
    def __init__(self, dataset: str) -> None:
        self.dataset = dataset
        self.total_edges = 0
        self.positive_edges = 0
        self.negative_edges = 0
        self.pair_type_counts: Counter = Counter()
        self.features: dict[str, FeatureStats] = {}

    def add_feature(self, feature: str, feature_type: str, context_type: str | None = None) -> None:
        if feature not in self.features:
            self.features[feature] = FeatureStats(
                feature=feature,
                feature_type=normalize_feature_type(feature_type),
                context_type=context_type or self.dataset,
            )

    def consume_rows(self, rows: Iterable[dict]) -> None:
        for row in rows:
            self.consume_edge(row)

    def consume_edge(self, row: dict) -> None:
        source = str(row["source"])
        target = str(row["target"])
        source_type = normalize_feature_type(str(row["source_type"]))
        target_type = normalize_feature_type(str(row["target_type"]))
        context_type = str(row.get("context_type") or self.dataset)
        pair_type = str(row.get("pair_type") or pair_type_for(source_type, target_type))
        correlation = float(row["correlation"])
        abs_correlation = float(row.get("abs_correlation") or abs(correlation))
        fdr = float(row["fdr"])

        self.add_feature(source, source_type, context_type)
        self.add_feature(target, target_type, context_type)
        self.total_edges += 1
        if correlation > 0:
            self.positive_edges += 1
        elif correlation < 0:
            self.negative_edges += 1
        self.pair_type_counts[pair_type] += 1

        self._update_feature(source, target, target_type, pair_type, correlation, abs_correlation, fdr)
        self._update_feature(target, source, source_type, pair_type, correlation, abs_correlation, fdr)

    def _update_feature(
        self,
        feature: str,
        partner: str,
        partner_type: str,
        pair_type: str,
        correlation: float,
        abs_correlation: float,
        fdr: float,
    ) -> None:
        stats = self.features[feature]
        stats.degree_total += 1
        if correlation > 0:
            stats.positive_degree += 1
            stats.positive_sum += correlation
            stats.positive_count += 1
            push_top_partner(stats.positive_partners, PartnerEntry(partner, correlation, fdr))
        elif correlation < 0:
            stats.negative_degree += 1
            stats.negative_sum += correlation
            stats.negative_count += 1
            push_top_partner(stats.negative_partners, PartnerEntry(partner, correlation, fdr))

        if normalize_feature_type(partner_type) == "TE":
            stats.te_neighbors.add(partner)
        else:
            stats.gene_neighbors.add(partner)
        stats.pair_type_counts[pair_type] += 1
        stats.abs_sum += abs_correlation
        stats.abs_count += 1
        if stats.max_abs_correlation is None or abs_correlation > stats.max_abs_correlation:
            stats.max_abs_correlation = abs_correlation

    def dataset_summary_row(self, raw_path: str | Path, filtered_path: str | Path) -> dict:
        return {
            "dataset": self.dataset,
            "total_edges": self.total_edges,
            "positive_edges": self.positive_edges,
            "negative_edges": self.negative_edges,
            "TE_TE_edges": self.pair_type_counts.get("TE_TE", 0),
            "TE_gene_edges": self.pair_type_counts.get("TE_gene", 0),
            "gene_gene_edges": self.pair_type_counts.get("gene_gene", 0),
            "pair_type_counts": serialize_counter(self.pair_type_counts),
            "source_raw_path": str(raw_path),
            "filtered_edge_path": str(filtered_path),
        }

    def feature_summary_rows(self) -> list[dict]:
        nonzero = [stats for stats in self.features.values() if stats.degree_total > 0]
        ranked = sorted(nonzero, key=lambda s: (-s.degree_total, s.feature))
        if len(ranked) >= 10:
            hub_count = max(math.ceil(len(ranked) * 0.05), 10)
        else:
            hub_count = len(ranked)
        hub_ranks = {stats.feature: rank for rank, stats in enumerate(ranked[:hub_count], start=1)}

        zero_degree = sorted(
            (stats for stats in self.features.values() if stats.degree_total == 0),
            key=lambda s: (s.feature_type.lower(), s.feature),
        )
        rows = []
        for stats in [*ranked, *zero_degree]:
            hub_rank = hub_ranks.get(stats.feature, "")
            rows.append(
                {
                    "dataset": self.dataset,
                    "feature": stats.feature,
                    "feature_type": stats.feature_type,
                    "context_type": stats.context_type,
                    "degree_total": stats.degree_total,
                    "positive_degree": stats.positive_degree,
                    "negative_degree": stats.negative_degree,
                    "TE_neighbor_count": len(stats.te_neighbors),
                    "gene_neighbor_count": len(stats.gene_neighbors),
                    "TE_TE_edge_count": stats.pair_type_counts.get("TE_TE", 0),
                    "TE_gene_edge_count": stats.pair_type_counts.get("TE_gene", 0),
                    "gene_gene_edge_count": stats.pair_type_counts.get("gene_gene", 0),
                    "max_abs_correlation": format_optional(stats.max_abs_correlation),
                    "mean_abs_correlation": format_optional(mean(stats.abs_sum, stats.abs_count)),
                    "mean_positive_correlation": format_optional(mean(stats.positive_sum, stats.positive_count)),
                    "mean_negative_correlation": format_optional(mean(stats.negative_sum, stats.negative_count)),
                    "top_positive_partners": serialize_partners(stats.positive_partners),
                    "top_negative_partners": serialize_partners(stats.negative_partners),
                    "is_hub": "true" if hub_rank else "false",
                    "hub_rank": hub_rank,
                }
            )
        return rows


def normalize_feature_type(value: str) -> str:
    return "TE" if value.strip().lower() == "te" else "gene"


def pair_type_for(source_type: str, target_type: str) -> str:
    normalized = {normalize_feature_type(source_type), normalize_feature_type(target_type)}
    if normalized == {"TE"}:
        return "TE_TE"
    if normalized == {"gene"}:
        return "gene_gene"
    return "TE_gene"


def format_float(value: float) -> str:
    return f"{value:.12g}"


def format_optional(value: float | None) -> str:
    return "" if value is None else format_float(value)


def mean(total: float, count: int) -> float | None:
    if count == 0:
        return None
    return total / count


def push_top_partner(heap: list[PartnerEntry], entry: PartnerEntry, limit: int = 10) -> None:
    if len(heap) < limit:
        heapq.heappush(heap, entry)
        return
    if entry.score > heap[0].score:
        heapq.heapreplace(heap, entry)


def serialize_partners(heap: list[PartnerEntry]) -> str:
    partners = sorted(heap, key=lambda item: (-abs(item.correlation), item.fdr, item.partner))
    return ";".join(entry.serialize() for entry in partners)


def serialize_counter(counter: Counter) -> str:
    return ";".join(f"{key}={counter[key]}" for key in sorted(counter))


def threshold_label(abs_correlation: float, fdr: float) -> str:
    return f"abs{format_float(abs_correlation)}_fdr{format_float(fdr)}"


def parse_thresholds(values: list[str]) -> list[Threshold]:
    thresholds = []
    seen = set()
    for value in values:
        if ":" not in value:
            raise argparse.ArgumentTypeError(f"Threshold must be ABS:FDR, got {value!r}")
        abs_text, fdr_text = value.split(":", 1)
        try:
            abs_correlation = float(abs_text)
            fdr = float(fdr_text)
        except ValueError as exc:
            raise argparse.ArgumentTypeError(f"Threshold must contain numeric ABS:FDR, got {value!r}") from exc
        if not 0 <= abs_correlation <= 1:
            raise argparse.ArgumentTypeError(f"abs_correlation must be between 0 and 1, got {abs_correlation}")
        if not 0 <= fdr <= 1:
            raise argparse.ArgumentTypeError(f"fdr must be between 0 and 1, got {fdr}")
        label = threshold_label(abs_correlation, fdr)
        if label in seen:
            continue
        seen.add(label)
        thresholds.append(Threshold(abs_correlation=abs_correlation, fdr=fdr, label=label))
    if not thresholds:
        raise argparse.ArgumentTypeError("At least one threshold is required.")
    return thresholds


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Filter and summarize raw TE/gene feature-feature co-expression networks.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument(
        "--thresholds",
        nargs="+",
        default=DEFAULT_THRESHOLDS,
        metavar="ABS:FDR",
        help="Threshold pairs such as 0.3:0.05 0.4:0.05.",
    )
    parser.add_argument(
        "--datasets",
        nargs="+",
        default=DEFAULT_DATASETS,
        choices=DEFAULT_DATASETS,
        help="Dataset names to process.",
    )
    parser.add_argument("--raw-dir", type=Path, default=DEFAULT_RAW_DIR, help="Raw network v1 directory.")
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR, help="Analysis output directory.")
    parser.add_argument("--chunk-size", type=int, default=250_000, help="Rows per edge TSV chunk.")
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Report expected inputs and output directories without reading edge data.",
    )
    args = parser.parse_args(argv)
    try:
        args.thresholds = parse_thresholds(args.thresholds)
    except argparse.ArgumentTypeError as exc:
        parser.error(str(exc))
    if args.chunk_size < 1:
        parser.error("--chunk-size must be at least 1")
    return args


def edge_path(raw_dir: Path, dataset: str) -> Path:
    return raw_dir / f"{dataset}_edges.tsv"


def node_path(raw_dir: Path, dataset: str) -> Path:
    return raw_dir / f"{dataset}_nodes.tsv"


def load_nodes(path: Path, dataset: str) -> list[dict[str, str]]:
    if not path.exists():
        raise FileNotFoundError(f"Raw node file not found: {path}")
    nodes = pd.read_csv(path, sep="\t", dtype=str).fillna("")
    required = {"id", "feature_type", "context_type"}
    missing = required - set(nodes.columns)
    if missing:
        raise ValueError(f"Node file {path} is missing required columns: {sorted(missing)}")
    result = []
    for row in nodes.itertuples(index=False):
        row_dict = row._asdict()
        result.append(
            {
                "feature": str(row_dict["id"]),
                "feature_type": str(row_dict["feature_type"]),
                "context_type": str(row_dict.get("context_type") or dataset),
            }
        )
    return result


def initialize_aggregators(dataset: str, thresholds: list[Threshold], nodes: list[dict[str, str]]) -> dict[str, DatasetAggregator]:
    aggregators = {threshold.label: DatasetAggregator(dataset) for threshold in thresholds}
    for aggregator in aggregators.values():
        for node in nodes:
            aggregator.add_feature(node["feature"], node["feature_type"], node["context_type"])
    return aggregators


def validate_edge_columns(columns: list[str], path: Path) -> None:
    missing = [column for column in EDGE_COLUMNS if column not in columns]
    if missing:
        raise ValueError(f"Edge file {path} is missing required columns: {missing}")


def write_rows(path: Path, rows: list[dict], columns: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, delimiter="\t", extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def process_dataset(dataset: str, args: argparse.Namespace, output_handles: dict[str, object]) -> dict[str, DatasetAggregator]:
    raw_edge_path = edge_path(args.raw_dir, dataset)
    raw_node_path = node_path(args.raw_dir, dataset)
    if not raw_edge_path.exists():
        raise FileNotFoundError(f"Raw edge file not found: {raw_edge_path}")
    nodes = load_nodes(raw_node_path, dataset)
    aggregators = initialize_aggregators(dataset, args.thresholds, nodes)

    first_chunk = True
    for chunk in pd.read_csv(raw_edge_path, sep="\t", chunksize=args.chunk_size):
        if first_chunk:
            validate_edge_columns(list(chunk.columns), raw_edge_path)
            first_chunk = False
        abs_corr = pd.to_numeric(chunk["abs_correlation"], errors="coerce")
        fdr = pd.to_numeric(chunk["fdr"], errors="coerce")
        for threshold in args.thresholds:
            keep = (abs_corr >= threshold.abs_correlation) & (fdr <= threshold.fdr)
            filtered = chunk.loc[keep, EDGE_COLUMNS]
            if filtered.empty:
                continue
            filtered.to_csv(output_handles[threshold.label], sep="\t", index=False, header=False)
            aggregators[threshold.label].consume_rows(filtered.to_dict("records"))
    return aggregators


def prepare_filtered_edge_outputs(args: argparse.Namespace) -> dict[str, dict[str, Path]]:
    outputs: dict[str, dict[str, Path]] = defaultdict(dict)
    for threshold in args.thresholds:
        threshold_dir = args.output_dir / threshold.label
        threshold_dir.mkdir(parents=True, exist_ok=True)
        for dataset in args.datasets:
            outputs[threshold.label][dataset] = threshold_dir / f"{dataset}_edges.tsv"
    return outputs


def write_empty_edge_headers(outputs: dict[str, dict[str, Path]]) -> dict[str, object]:
    handles: dict[str, object] = {}
    for label, dataset_paths in outputs.items():
        for dataset, path in dataset_paths.items():
            path.parent.mkdir(parents=True, exist_ok=True)
            handle = path.open("w", encoding="utf-8", newline="")
            handle.write("\t".join(EDGE_COLUMNS) + "\n")
            handles[f"{label}:{dataset}"] = handle
    return handles


def close_handles(handles: dict[str, object]) -> None:
    for handle in handles.values():
        handle.close()


def write_threshold_summaries(
    threshold: Threshold,
    args: argparse.Namespace,
    aggregators: dict[str, DatasetAggregator],
    filtered_paths: dict[str, Path],
) -> None:
    threshold_dir = args.output_dir / threshold.label
    dataset_rows = []
    feature_rows = []
    te_rows = []
    for dataset in args.datasets:
        aggregator = aggregators[dataset]
        dataset_rows.append(aggregator.dataset_summary_row(edge_path(args.raw_dir, dataset), filtered_paths[dataset]))
        rows = aggregator.feature_summary_rows()
        feature_rows.extend(rows)
        te_rows.extend(row for row in rows if row["feature_type"] == "TE")

    write_rows(threshold_dir / "dataset_network_summary.tsv", dataset_rows, DATASET_SUMMARY_COLUMNS)
    write_rows(threshold_dir / "feature_network_summary.tsv", feature_rows, FEATURE_SUMMARY_COLUMNS)
    write_rows(threshold_dir / "te_network_summary.tsv", te_rows, FEATURE_SUMMARY_COLUMNS)


def write_readme(output_dir: Path) -> None:
    output_dir.mkdir(parents=True, exist_ok=True)
    readme = """# Co-expression analysis v1

This directory contains postprocessed feature-feature co-expression networks derived from raw networks in `data/coexpression/networks/v1/`.

Raw inputs are not modified. Each threshold subdirectory contains filtered edge TSVs plus network summaries.

## Default thresholds

- `abs0.3_fdr0.05`: `abs_correlation >= 0.3` and `fdr <= 0.05`
- `abs0.4_fdr0.05`: `abs_correlation >= 0.4` and `fdr <= 0.05`
- `abs0.5_fdr0.05`: `abs_correlation >= 0.5` and `fdr <= 0.05`

## Outputs per threshold

- `{dataset}_edges.tsv`: filtered edges for one dataset, preserving the raw edge columns.
- `dataset_network_summary.tsv`: one row per dataset with edge totals, sign counts, pair-type counts, and input/output paths.
- `feature_network_summary.tsv`: one row per dataset-feature, including zero-degree selected network nodes.
- `te_network_summary.tsv`: TE-only subset of `feature_network_summary.tsv`.

## Hub rule

Features are ranked by `degree_total` descending within each dataset, with feature name used only as a deterministic tie-breaker. Among nonzero-degree features, `is_hub` is `true` for the top 5% by degree. If there are at least 10 nonzero-degree features, at least the top 10 are marked as hubs; if there are fewer than 10 nonzero-degree features, all nonzero-degree features are marked as hubs. `hub_rank` is blank for non-hubs.

## Top partners

`top_positive_partners` and `top_negative_partners` keep up to 10 partners per feature, sorted by descending `abs_correlation`, then ascending FDR, then partner name. Entries are serialized as `partner|r=0.812|fdr=1e-05`.

## Interpretation limits

These networks summarize correlation only. Edges do not imply causality, regulation, direct physical interaction, or TE activity. Thresholded summaries are intended for prioritization and exploratory interpretation, not as standalone mechanistic evidence.
"""
    (output_dir / "README.md").write_text(readme, encoding="utf-8")


def dry_run(args: argparse.Namespace) -> int:
    print("Dry run: no edge files will be processed and no filtered outputs will be written.")
    print(f"Raw directory: {args.raw_dir}")
    print(f"Output directory: {args.output_dir}")
    for threshold in args.thresholds:
        print(f"Threshold {threshold.label}: abs_correlation >= {threshold.abs_correlation}, fdr <= {threshold.fdr}")
        print(f"  Output directory: {args.output_dir / threshold.label}")
        for dataset in args.datasets:
            print(f"  Dataset {dataset}")
            print(f"    Raw edges: {edge_path(args.raw_dir, dataset)}")
            print(f"    Raw nodes: {node_path(args.raw_dir, dataset)}")
            print(f"    Filtered edges: {args.output_dir / threshold.label / f'{dataset}_edges.tsv'}")
    return 0


def run(args: argparse.Namespace) -> None:
    write_readme(args.output_dir)
    outputs = prepare_filtered_edge_outputs(args)
    handles = write_empty_edge_headers(outputs)
    all_aggregators = {threshold.label: {} for threshold in args.thresholds}
    try:
        for dataset in args.datasets:
            dataset_handles = {
                threshold.label: handles[f"{threshold.label}:{dataset}"] for threshold in args.thresholds
            }
            dataset_aggregators = process_dataset(dataset, args, dataset_handles)
            for threshold in args.thresholds:
                all_aggregators[threshold.label][dataset] = dataset_aggregators[threshold.label]
    finally:
        close_handles(handles)

    for threshold in args.thresholds:
        write_threshold_summaries(threshold, args, all_aggregators[threshold.label], outputs[threshold.label])


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    if args.dry_run:
        return dry_run(args)
    run(args)
    print(f"Wrote filtered co-expression analysis outputs to: {args.output_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
