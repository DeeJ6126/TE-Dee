#!/usr/bin/env python
"""Summarize all-TE display subgraph quality from offline JSON outputs."""

from __future__ import annotations

import argparse
import csv
import json
from collections import Counter
from pathlib import Path
from statistics import mean
from typing import Any


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_DISPLAY_ROOT = PROJECT_ROOT / "data/coexpression/display_subgraphs/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_ALL_TE_DIR = DEFAULT_DISPLAY_ROOT / "all_te"
DEFAULT_INTERPRETATION_DIR = PROJECT_ROOT / "data/coexpression/interpretation/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_MODULE_DIR = PROJECT_ROOT / "data/coexpression/modules/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_OUTPUT_TSV = DEFAULT_DISPLAY_ROOT / "all_te_quality_summary.tsv"
DEFAULT_OUTPUT_JSON = DEFAULT_DISPLAY_ROOT / "all_te_quality_summary.json"
DEFAULT_OUTPUT_MD = DEFAULT_DISPLAY_ROOT / "all_te_quality_summary.md"

QUALITY_COLUMNS = [
    "feature",
    "te_name",
    "context",
    "json_path",
    "has_subgraph",
    "node_count",
    "edge_count",
    "center_edge_count",
    "gene_neighbor_count",
    "te_neighbor_count",
    "other_neighbor_count",
    "positive_edge_count",
    "negative_edge_count",
    "mean_abs_center_correlation",
    "max_abs_center_correlation",
    "module_id",
    "module_size",
    "module_te_count",
    "module_gene_count",
    "module_classification",
    "functional_context_confidence",
    "enrichment_term_count",
    "has_enrichment",
    "quality_flag",
]


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--all-te-dir", type=Path, default=DEFAULT_ALL_TE_DIR)
    parser.add_argument("--interpretation-dir", type=Path, default=DEFAULT_INTERPRETATION_DIR)
    parser.add_argument("--module-dir", type=Path, default=DEFAULT_MODULE_DIR)
    parser.add_argument("--output-tsv", type=Path, default=DEFAULT_OUTPUT_TSV)
    parser.add_argument("--output-json", type=Path, default=DEFAULT_OUTPUT_JSON)
    parser.add_argument("--output-md", type=Path, default=DEFAULT_OUTPUT_MD)
    return parser.parse_args(argv)


def read_tsv(path: Path) -> list[dict[str, str]]:
    if not path.exists():
        return []
    with path.open("r", encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle, delimiter="\t"))


def load_rows_by_feature_context(path: Path) -> dict[tuple[str, str], dict[str, str]]:
    rows = read_tsv(path)
    indexed: dict[tuple[str, str], dict[str, str]] = {}
    for row in rows:
        feature = row.get("feature", "")
        context = row.get("context_type", "") or row.get("context", "")
        if feature and context:
            indexed[(feature, context)] = row
    return indexed


def load_manifest_files(all_te_dir: Path) -> list[Path]:
    manifest_path = all_te_dir / "manifest.json"
    if manifest_path.exists():
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        files = []
        for item in manifest.get("files", []):
            rel = Path(str(item))
            if rel.parts and rel.parts[0] == all_te_dir.name:
                rel = Path(*rel.parts[1:])
            files.append(all_te_dir / rel)
        return files
    return sorted(path for path in all_te_dir.glob("*/*.json") if path.name != "manifest.json")


def as_int(value: Any) -> int | str:
    if value in ("", None):
        return ""
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return ""


def as_float(value: Any) -> float | str:
    if value in ("", None):
        return ""
    try:
        return float(value)
    except (TypeError, ValueError):
        return ""


def bool_text(value: bool) -> str:
    return "true" if value else "false"


def feature_type_for(nodes_by_id: dict[str, dict[str, Any]], node_id: str) -> str:
    node = nodes_by_id.get(node_id, {})
    return str(node.get("feature_type", node.get("type", ""))).lower()


def is_center_edge(edge: dict[str, Any], center: str) -> bool:
    if edge.get("role") == "center_neighbor_edge":
        return True
    return edge.get("source") == center or edge.get("target") == center


def other_endpoint(edge: dict[str, Any], center: str) -> str | None:
    source = str(edge.get("source", ""))
    target = str(edge.get("target", ""))
    if source == center and target:
        return target
    if target == center and source:
        return source
    return None


def edge_sign_counts(edges: list[dict[str, Any]]) -> tuple[int, int]:
    positive = 0
    negative = 0
    for edge in edges:
        correlation = as_float(edge.get("correlation", ""))
        if correlation == "":
            continue
        if correlation > 0:
            positive += 1
        elif correlation < 0:
            negative += 1
    return positive, negative


def split_terms(value: Any) -> list[str]:
    if value in ("", None):
        return []
    return [term.strip() for term in str(value).split(";") if term.strip()]


def enrichment_count(graph: dict[str, Any], interpretation_row: dict[str, str]) -> int:
    terms = split_terms(graph.get("top_enriched_terms", "")) or split_terms(interpretation_row.get("top_enriched_terms", ""))
    return len(terms)


def first_nonblank(*values: Any) -> Any:
    for value in values:
        if value not in ("", None):
            return value
    return ""


def quality_flag(
    *,
    center_edge_count: int,
    node_count: int,
    gene_neighbor_count: int,
    has_enrichment: bool,
    confidence: str,
) -> str:
    confidence_norm = str(confidence).strip().lower()
    if center_edge_count <= 0:
        return "empty"
    if confidence_norm in {"high", "confident"}:
        return "high"
    if center_edge_count >= 10 and gene_neighbor_count >= 5 and has_enrichment:
        return "high"
    if center_edge_count >= 5 and node_count >= 6:
        return "medium"
    return "low"


def summarize_graph(
    graph_path: Path,
    graph: dict[str, Any],
    interpretation_rows: dict[tuple[str, str], dict[str, str]],
    module_rows: dict[tuple[str, str], dict[str, str]],
) -> dict[str, Any]:
    feature = str(graph.get("center") or graph_path.parent.name)
    context = str(graph.get("context_type") or graph_path.stem)
    interp_row = interpretation_rows.get((feature, context), {})
    module_row = module_rows.get((feature, context), {})
    nodes = graph.get("nodes") if isinstance(graph.get("nodes"), list) else []
    edges = graph.get("edges") if isinstance(graph.get("edges"), list) else []
    nodes_by_id = {str(node.get("id", "")): node for node in nodes if isinstance(node, dict)}
    center_edges = [edge for edge in edges if isinstance(edge, dict) and is_center_edge(edge, feature)]

    neighbor_counts = Counter()
    center_abs_correlations = []
    for edge in center_edges:
        endpoint = other_endpoint(edge, feature)
        if not endpoint:
            continue
        feature_type = feature_type_for(nodes_by_id, endpoint)
        if feature_type == "gene":
            neighbor_counts["gene"] += 1
        elif feature_type == "te":
            neighbor_counts["te"] += 1
        else:
            neighbor_counts["other"] += 1
        abs_correlation = as_float(edge.get("abs_correlation", ""))
        if abs_correlation == "":
            correlation = as_float(edge.get("correlation", ""))
            abs_correlation = abs(correlation) if correlation != "" else ""
        if abs_correlation != "":
            center_abs_correlations.append(float(abs_correlation))

    positive_edges, negative_edges = edge_sign_counts(edges)
    enrich_count = enrichment_count(graph, interp_row)
    confidence = str(first_nonblank(graph.get("functional_context_confidence"), interp_row.get("functional_context_confidence")))
    node_count = len(nodes)
    center_edge_count = len(center_edges)
    has_enrichment = enrich_count > 0

    return {
        "feature": feature,
        "te_name": feature,
        "context": context,
        "json_path": str(graph_path),
        "has_subgraph": bool_text(node_count > 0 and len(edges) > 0),
        "node_count": node_count,
        "edge_count": len(edges),
        "center_edge_count": center_edge_count,
        "gene_neighbor_count": neighbor_counts["gene"],
        "te_neighbor_count": neighbor_counts["te"],
        "other_neighbor_count": neighbor_counts["other"],
        "positive_edge_count": positive_edges,
        "negative_edge_count": negative_edges,
        "mean_abs_center_correlation": mean(center_abs_correlations) if center_abs_correlations else "",
        "max_abs_center_correlation": max(center_abs_correlations) if center_abs_correlations else "",
        "module_id": first_nonblank(graph.get("module_id"), interp_row.get("module_id"), module_row.get("module_id")),
        "module_size": as_int(first_nonblank(graph.get("module_size"), interp_row.get("module_size"), module_row.get("module_size"))),
        "module_te_count": as_int(first_nonblank(graph.get("TE_count"), interp_row.get("TE_count"), module_row.get("TE_count"))),
        "module_gene_count": as_int(first_nonblank(graph.get("gene_count"), interp_row.get("gene_count"), module_row.get("gene_count"))),
        "module_classification": first_nonblank(graph.get("module_type"), interp_row.get("module_type")),
        "functional_context_confidence": confidence,
        "enrichment_term_count": enrich_count,
        "has_enrichment": bool_text(has_enrichment),
        "quality_flag": quality_flag(
            center_edge_count=center_edge_count,
            node_count=node_count,
            gene_neighbor_count=neighbor_counts["gene"],
            has_enrichment=has_enrichment,
            confidence=confidence,
        ),
    }


def write_tsv(path: Path, rows: list[dict[str, Any]], columns: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, delimiter="\t", extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def summarize_rows(rows: list[dict[str, Any]], expected_json_count: int) -> dict[str, Any]:
    context_counts = Counter(str(row["context"]) for row in rows)
    quality_counts = Counter(str(row["quality_flag"]) for row in rows)
    return {
        "expected_json_count": expected_json_count,
        "row_count": len(rows),
        "per_context_counts": dict(sorted(context_counts.items())),
        "quality_flag_distribution": dict(sorted(quality_counts.items())),
        "heuristic": {
            "high": "center_edge_count >= 10 and gene_neighbor_count >= 5 and has_enrichment true, or functional_context_confidence is high/confident",
            "medium": "center_edge_count >= 5 and node_count >= 6",
            "low": "has center edges but weak interpretability",
            "empty": "no valid subgraph or zero center edges",
        },
    }


def write_summary_json(path: Path, summary: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(summary, indent=2, ensure_ascii=False), encoding="utf-8")


def write_summary_md(path: Path, summary: dict[str, Any]) -> None:
    lines = [
        "# All-TE Display Subgraph Quality Summary",
        "",
        "This is a backend/offline QA sidecar for `all_te_quality_summary.tsv`.",
        "",
        "## Heuristic",
        "",
        "- high: center_edge_count >= 10 and gene_neighbor_count >= 5 and has_enrichment true, or functional_context_confidence is high/confident.",
        "- medium: center_edge_count >= 5 and node_count >= 6.",
        "- low: has center edges but weak interpretability.",
        "- empty: no valid subgraph or zero center edges.",
        "",
        "## Counts",
        "",
        f"- expected_json_count: {summary['expected_json_count']}",
        f"- row_count: {summary['row_count']}",
        "",
        "## Per Context",
        "",
    ]
    lines.extend(f"- {key}: {value}" for key, value in summary["per_context_counts"].items())
    lines.extend(["", "## Quality Flags", ""])
    lines.extend(f"- {key}: {value}" for key, value in summary["quality_flag_distribution"].items())
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    interpretation_rows = load_rows_by_feature_context(args.interpretation_dir / "te_functional_context_summary.tsv")
    module_rows = load_rows_by_feature_context(args.module_dir / "te_module_summary.tsv")
    graph_paths = load_manifest_files(args.all_te_dir)

    rows = []
    for graph_path in graph_paths:
        graph = json.loads(graph_path.read_text(encoding="utf-8"))
        rows.append(summarize_graph(graph_path, graph, interpretation_rows, module_rows))

    rows.sort(key=lambda row: (str(row["feature"]), str(row["context"])))
    write_tsv(args.output_tsv, rows, QUALITY_COLUMNS)
    summary = summarize_rows(rows, len(graph_paths))
    write_summary_json(args.output_json, summary)
    write_summary_md(args.output_md, summary)
    print(json.dumps(summary, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
