#!/usr/bin/env python
"""Build compact display subgraphs for TE co-expression contexts."""

from __future__ import annotations

import argparse
import csv
import json
from collections import defaultdict
from pathlib import Path
from typing import Any

import pandas as pd


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_FILTERED_DIR = PROJECT_ROOT / "data/coexpression/analysis/v1/abs0.4_fdr0.05"
DEFAULT_MODULE_DIR = PROJECT_ROOT / "data/coexpression/modules/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_INTERPRETATION_DIR = PROJECT_ROOT / "data/coexpression/interpretation/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_OUTPUT_DIR = PROJECT_ROOT / "data/coexpression/display_subgraphs/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_DATASETS = ["cancer_cell_line", "normal_cell_line", "normal_tissue"]
DEFAULT_SELECTED_CASES = ["L1HS", "LTR5", "HERVH-int", "CR1", "MER11B"]
CHUNKSIZE = 250_000
POSITIVE_EDGE_CACHE: dict[Path, pd.DataFrame] = {}
POSITIVE_EDGE_NODE_INDEX_CACHE: dict[Path, dict[str, set[int]]] = {}


CONTEXT_COLUMNS = [
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

MODULE_COLUMNS = [
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
    "TE_count",
    "gene_count",
    "TE_fraction",
    "hub_features",
    "hub_TEs",
    "hub_genes",
]

EDGE_COLUMNS = [
    "source",
    "target",
    "source_type",
    "target_type",
    "pair_type",
    "correlation",
    "abs_correlation",
    "fdr",
]

SUMMARY_COLUMNS = [
    "feature",
    "context_type",
    "node_count",
    "edge_count",
    "gene_nodes",
    "TE_nodes",
    "module_type",
    "confidence",
    "module_id",
]


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build offline JSON display subgraphs for TE co-expression results.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("--filtered-dir", type=Path, default=DEFAULT_FILTERED_DIR)
    parser.add_argument("--module-dir", type=Path, default=DEFAULT_MODULE_DIR)
    parser.add_argument("--interpretation-dir", type=Path, default=DEFAULT_INTERPRETATION_DIR)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR)
    parser.add_argument("--feature-set", choices=["selected_cases", "high_confidence", "all_te", "all"], default="selected_cases")
    parser.add_argument("--features", nargs="+", help="Explicit TE features to include for the requested feature set.")
    parser.add_argument("--datasets", nargs="+", default=DEFAULT_DATASETS)
    parser.add_argument("--top-n-partners", type=int, default=10)
    parser.add_argument("--top-n-hubs", type=int, default=5)
    parser.add_argument("--max-internal-edges", type=int, default=100)
    parser.add_argument("--dry-run", action="store_true")
    return parser.parse_args(argv)


def read_table(path: Path) -> pd.DataFrame:
    if not path.exists():
        raise FileNotFoundError(f"Required table not found: {path}")
    return pd.read_csv(path, sep="\t").fillna("")


def edge_path(filtered_dir: Path, dataset: str) -> Path:
    return filtered_dir / f"{dataset}_edges.tsv"


def normalize_bool(value: Any) -> bool:
    return str(value).strip().lower() in {"true", "1", "yes"}


def number_or_blank(value: Any) -> int | float | str:
    if value == "" or pd.isna(value):
        return ""
    try:
        as_float = float(value)
    except (TypeError, ValueError):
        return str(value)
    if as_float.is_integer():
        return int(as_float)
    return as_float


def split_semicolon(value: Any) -> list[str]:
    if value == "" or pd.isna(value):
        return []
    return [item.strip() for item in str(value).split(";") if item.strip()]


def safe_feature_dir_name(feature: str) -> str:
    return feature.replace("/", "_").replace("\\", "_").replace(":", "_")


def features_for_set(feature_set: str, explicit_features: list[str] | None, summary: pd.DataFrame) -> list[str]:
    if explicit_features:
        return list(dict.fromkeys(explicit_features))
    if feature_set == "selected_cases":
        return DEFAULT_SELECTED_CASES.copy()
    if feature_set == "high_confidence":
        high = summary.loc[summary["functional_context_confidence"].eq("high"), "feature"]
        return sorted(set(str(feature) for feature in high if str(feature)))
    if feature_set in {"all_te", "all"}:
        return sorted(set(str(feature) for feature in summary["feature"] if str(feature)))
    raise ValueError(f"Unsupported feature set: {feature_set}")


def load_context_rows(interpretation_dir: Path) -> dict[tuple[str, str], dict[str, Any]]:
    summary = read_table(interpretation_dir / "te_functional_context_summary.tsv")
    return {
        (str(row["feature"]), str(row["context_type"])): row
        for row in summary.to_dict("records")
    }


def load_module_rows(module_dir: Path) -> dict[tuple[str, str], dict[str, Any]]:
    module = read_table(module_dir / "te_module_summary.tsv")
    return {
        (str(row["feature"]), str(row["context_type"])): row
        for row in module.to_dict("records")
    }


def positive_edge_chunks(filtered_dir: Path, dataset: str):
    path = edge_path(filtered_dir, dataset)
    if not path.exists():
        raise FileNotFoundError(f"Filtered edge file not found: {path}")
    for chunk in pd.read_csv(path, sep="\t", usecols=EDGE_COLUMNS, chunksize=CHUNKSIZE):
        chunk["correlation"] = pd.to_numeric(chunk["correlation"], errors="coerce")
        chunk["abs_correlation"] = pd.to_numeric(chunk["abs_correlation"], errors="coerce")
        chunk["fdr"] = pd.to_numeric(chunk["fdr"], errors="coerce")
        yield chunk.loc[chunk["correlation"] > 0].copy()


def positive_edges_for_dataset(filtered_dir: Path, dataset: str) -> pd.DataFrame:
    path = edge_path(filtered_dir, dataset).resolve()
    cached = POSITIVE_EDGE_CACHE.get(path)
    if cached is not None:
        return cached
    chunks = list(positive_edge_chunks(filtered_dir, dataset))
    if chunks:
        edges = pd.concat(chunks, ignore_index=True)
    else:
        edges = pd.DataFrame(columns=EDGE_COLUMNS)
    POSITIVE_EDGE_CACHE[path] = edges
    return edges


def positive_edge_node_index(filtered_dir: Path, dataset: str) -> dict[str, set[int]]:
    path = edge_path(filtered_dir, dataset).resolve()
    cached = POSITIVE_EDGE_NODE_INDEX_CACHE.get(path)
    if cached is not None:
        return cached
    edges = positive_edges_for_dataset(filtered_dir, dataset)
    node_index: dict[str, set[int]] = defaultdict(set)
    for idx, source, target in zip(edges.index, edges["source"], edges["target"]):
        node_index[str(source)].add(int(idx))
        node_index[str(target)].add(int(idx))
    POSITIVE_EDGE_NODE_INDEX_CACHE[path] = node_index
    return node_index


def collect_center_partners(filtered_dir: Path, dataset: str, center: str) -> pd.DataFrame:
    edges = positive_edges_for_dataset(filtered_dir, dataset)
    node_index = positive_edge_node_index(filtered_dir, dataset)
    indices = sorted(node_index.get(center, set()))
    if not indices:
        return pd.DataFrame(columns=EDGE_COLUMNS)
    center_edges = edges.loc[indices].copy()
    return center_edges.sort_values(["abs_correlation", "fdr"], ascending=[False, True])


def partner_from_edge(row: pd.Series, center: str) -> tuple[str, str]:
    if row["source"] == center:
        return str(row["target"]), str(row["target_type"])
    return str(row["source"]), str(row["source_type"])


def select_top_partners(center_edges: pd.DataFrame, center: str, top_n: int) -> tuple[list[str], list[str]]:
    genes: list[str] = []
    tes: list[str] = []
    seen_genes: set[str] = set()
    seen_tes: set[str] = set()
    for _, row in center_edges.iterrows():
        partner, partner_type = partner_from_edge(row, center)
        if partner_type.lower() == "te":
            if partner not in seen_tes and len(tes) < top_n:
                tes.append(partner)
                seen_tes.add(partner)
        else:
            if partner not in seen_genes and len(genes) < top_n:
                genes.append(partner)
                seen_genes.add(partner)
        if len(genes) >= top_n and len(tes) >= top_n:
            break
    return genes, tes


def collect_internal_edges(filtered_dir: Path, dataset: str, selected_nodes: set[str], max_internal_edges: int) -> list[dict[str, Any]]:
    edge_by_pair: dict[tuple[str, str], dict[str, Any]] = {}
    edges = positive_edges_for_dataset(filtered_dir, dataset)
    node_index = positive_edge_node_index(filtered_dir, dataset)
    candidate_indices: set[int] = set()
    for node in selected_nodes:
        candidate_indices.update(node_index.get(node, set()))
    if not candidate_indices:
        return []
    candidates = edges.loc[sorted(candidate_indices)].copy()
    hit = candidates.loc[candidates["source"].isin(selected_nodes) & candidates["target"].isin(selected_nodes)].copy()
    if hit.empty:
        return []
    hit = hit.sort_values(["abs_correlation", "fdr"], ascending=[False, True])
    for _, row in hit.iterrows():
        source = str(row["source"])
        target = str(row["target"])
        pair = tuple(sorted((source, target)))
        edge = edge_to_json(row)
        current = edge_by_pair.get(pair)
        if current is None or edge["abs_correlation"] > current["abs_correlation"]:
            edge_by_pair[pair] = edge
    edges = sorted(edge_by_pair.values(), key=lambda item: (-float(item["abs_correlation"]), float(item["fdr"])))
    return edges[:max_internal_edges]


def collect_center_edges(center_edges: pd.DataFrame, center: str, selected_nodes: set[str]) -> list[dict[str, Any]]:
    edges: list[dict[str, Any]] = []
    seen_pairs: set[tuple[str, str]] = set()
    for _, row in center_edges.iterrows():
        source = str(row["source"])
        target = str(row["target"])
        if source not in selected_nodes or target not in selected_nodes:
            continue
        if center not in {source, target}:
            continue
        pair = tuple(sorted((source, target)))
        if pair in seen_pairs:
            continue
        edge = edge_to_json(row)
        edge["role"] = "center_neighbor_edge"
        edges.append(edge)
        seen_pairs.add(pair)
    return edges


def collect_noncenter_internal_edges(
    filtered_dir: Path,
    dataset: str,
    selected_nodes: set[str],
    center: str,
    max_internal_edges: int,
) -> list[dict[str, Any]]:
    edges = []
    for edge in collect_internal_edges(filtered_dir, dataset, selected_nodes, max_internal_edges + len(selected_nodes)):
        if center in {edge["source"], edge["target"]}:
            continue
        edge["role"] = "selected_internal_edge"
        edges.append(edge)
        if len(edges) >= max_internal_edges:
            break
    return edges


def edge_to_json(row: pd.Series) -> dict[str, Any]:
    return {
        "source": str(row["source"]),
        "target": str(row["target"]),
        "correlation": float(row["correlation"]),
        "abs_correlation": float(row["abs_correlation"]),
        "fdr": float(row["fdr"]),
        "pair_type": str(row["pair_type"]),
        "role": "positive_display_edge",
    }


def add_node(
    nodes: dict[str, dict[str, Any]],
    node_id: str,
    feature_type: str,
    role: str,
    module_row: dict[str, Any],
    degree_hints: dict[str, float],
    center: str,
) -> None:
    existing = nodes.get(node_id)
    roles = set(split_semicolon(existing.get("role", ""))) if existing else set()
    roles.add(role)
    nodes[node_id] = {
        "id": node_id,
        "label": node_id,
        "feature_type": feature_type,
        "role": ";".join(sorted(roles)),
        "module_id": str(module_row.get("module_id", "")),
        "is_center": node_id == center,
        "is_module_hub": role in {"hub_gene", "hub_TE"} or (node_id == center and normalize_bool(module_row.get("is_module_hub", ""))),
    }
    hint = degree_hints.get(node_id)
    if hint is not None:
        nodes[node_id]["degree_hint"] = hint


def build_degree_hints(center: str, module_row: dict[str, Any]) -> dict[str, float]:
    hints = {}
    value = module_row.get("positive_degree", "")
    if value != "":
        try:
            hints[center] = float(value)
        except (TypeError, ValueError):
            pass
    return hints


def node_type_from_edges(node_id: str, center_edges: pd.DataFrame, hub_tes: set[str]) -> str:
    if node_id in hub_tes or node_id == "":
        return "TE"
    for _, row in center_edges.iterrows():
        if row["source"] == node_id:
            return str(row["source_type"])
        if row["target"] == node_id:
            return str(row["target_type"])
    return "gene"


def build_subgraph(
    center: str,
    dataset: str,
    context_row: dict[str, Any],
    module_row: dict[str, Any],
    filtered_dir: Path,
    top_n_partners: int,
    top_n_hubs: int,
    max_internal_edges: int,
    feature_set: str,
    selection_rule: str,
) -> dict[str, Any]:
    center_edges = collect_center_partners(filtered_dir, dataset, center)
    gene_partners, te_partners = select_top_partners(center_edges, center, top_n_partners)
    hub_genes = split_semicolon(module_row.get("hub_genes", ""))[:top_n_hubs]
    hub_tes = split_semicolon(module_row.get("hub_TEs", ""))[:top_n_hubs]

    nodes: dict[str, dict[str, Any]] = {}
    degree_hints = build_degree_hints(center, module_row)
    add_node(nodes, center, "TE", "center", module_row, degree_hints, center)
    for gene in gene_partners:
        add_node(nodes, gene, "gene", "top_positive_gene_partner", module_row, degree_hints, center)
    for te in te_partners:
        add_node(nodes, te, "TE", "top_positive_TE_partner", module_row, degree_hints, center)
    for gene in hub_genes:
        add_node(nodes, gene, "gene", "hub_gene", module_row, degree_hints, center)
    for te in hub_tes:
        add_node(nodes, te, "TE", "hub_TE", module_row, degree_hints, center)

    selected = set(nodes)
    center_display_edges = collect_center_edges(center_edges, center, selected)
    remaining_internal_budget = max(0, max_internal_edges - len(center_display_edges))
    internal_edges = collect_noncenter_internal_edges(
        filtered_dir,
        dataset,
        selected,
        center,
        remaining_internal_budget,
    )
    edges = center_display_edges + internal_edges

    return {
        "center": center,
        "context_type": dataset,
        "module_id": str(context_row.get("module_id", "")),
        "module_type": str(context_row.get("module_type", "")),
        "module_size": number_or_blank(context_row.get("module_size", "")),
        "TE_count": number_or_blank(context_row.get("TE_count", "")),
        "gene_count": number_or_blank(context_row.get("gene_count", "")),
        "TE_fraction": number_or_blank(context_row.get("TE_fraction", "")),
        "functional_context_confidence": str(context_row.get("functional_context_confidence", "")),
        "candidate_label": str(context_row.get("candidate_label", "")),
        "top_enriched_terms": str(context_row.get("top_enriched_terms", "")),
        "interpretation_statement_zh": str(context_row.get("interpretation_statement_zh", "")),
        "interpretation_statement_en": str(context_row.get("interpretation_statement_en", "")),
        "nodes": sorted(nodes.values(), key=lambda item: (not item["is_center"], item["feature_type"], item["id"])),
        "edges": edges,
        "selection": {
            "feature_set": feature_set,
            "rule": selection_rule,
            "top_n_positive_gene_partners": top_n_partners,
            "top_n_positive_TE_partners": top_n_partners,
            "top_n_module_hub_genes": top_n_hubs,
            "top_n_module_hub_TEs": top_n_hubs,
            "max_internal_edges": max_internal_edges,
            "positive_edges_only": True,
            "edge_source": str(edge_path(filtered_dir, dataset)),
        },
    }


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")


def write_tsv(path: Path, rows: list[dict[str, Any]], columns: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, delimiter="\t", extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def write_readme(output_dir: Path) -> None:
    text = """# Co-expression Display Subgraphs

This directory contains compact offline JSON subgraphs derived from the filtered co-expression results for `abs0.4_fdr0.05_res1.8`.

`selected_cases` contains the hand-picked TE examples used for display-oriented inspection. `high_confidence` contains TE/context rows whose `functional_context_confidence` is `high` in `te_functional_context_summary.tsv`.

These files are display subgraphs. They retain positive co-expression edges among selected nodes and summarize module interpretation context, but they are not causal graphs, regulatory graphs, or evidence of direct TE regulation.
"""
    output_dir.mkdir(parents=True, exist_ok=True)
    (output_dir / "README.md").write_text(text, encoding="utf-8")


def summary_row(feature: str, graph: dict[str, Any]) -> dict[str, Any]:
    gene_nodes = sum(1 for node in graph["nodes"] if str(node["feature_type"]).lower() != "te")
    te_nodes = sum(1 for node in graph["nodes"] if str(node["feature_type"]).lower() == "te")
    return {
        "feature": feature,
        "context_type": graph["context_type"],
        "node_count": len(graph["nodes"]),
        "edge_count": len(graph["edges"]),
        "gene_nodes": gene_nodes,
        "TE_nodes": te_nodes,
        "module_type": graph["module_type"],
        "confidence": graph["functional_context_confidence"],
        "module_id": graph["module_id"],
    }


def build_feature_set(
    feature_set: str,
    features: list[str],
    datasets: list[str],
    context_rows: dict[tuple[str, str], dict[str, Any]],
    module_rows: dict[tuple[str, str], dict[str, Any]],
    args: argparse.Namespace,
) -> dict[str, Any]:
    planned = [(feature, dataset) for feature in features for dataset in datasets if (feature, dataset) in context_rows]
    if args.dry_run:
        return {
            "feature_set": feature_set,
            "planned_subgraphs": len(planned),
            "features": features,
            "datasets": datasets,
        }

    feature_set_dir = args.output_dir / feature_set
    summaries = []
    written_files = []
    for feature, dataset in planned:
        context_row = context_rows[(feature, dataset)]
        module_row = module_rows.get((feature, dataset), context_row)
        graph = build_subgraph(
            center=feature,
            dataset=dataset,
            context_row=context_row,
            module_row=module_row,
            filtered_dir=args.filtered_dir,
            top_n_partners=args.top_n_partners,
            top_n_hubs=args.top_n_hubs,
            max_internal_edges=args.max_internal_edges,
            feature_set=feature_set,
            selection_rule=(
                "center TE plus top positive gene partners, top positive TE partners, "
                "module hub genes, module hub TEs, and positive edges among selected nodes"
            ),
        )
        out_path = feature_set_dir / safe_feature_dir_name(feature) / f"{dataset}.json"
        write_json(out_path, graph)
        written_files.append(str(out_path.relative_to(args.output_dir)))
        summaries.append(summary_row(feature, graph))

    write_tsv(feature_set_dir / "subgraph_summary.tsv", summaries, SUMMARY_COLUMNS)
    manifest = {
        "script": str(Path(__file__).resolve()),
        "feature_set": feature_set,
        "filtered_dir": str(args.filtered_dir),
        "module_dir": str(args.module_dir),
        "interpretation_dir": str(args.interpretation_dir),
        "output_dir": str(feature_set_dir),
        "features": features,
        "datasets": datasets,
        "subgraph_count": len(written_files),
        "top_n_partners": args.top_n_partners,
        "top_n_hubs": args.top_n_hubs,
        "max_internal_edges": args.max_internal_edges,
        "files": written_files,
        "interpretation_limit": "display subgraph only; not causal or regulatory evidence",
    }
    write_json(feature_set_dir / "manifest.json", manifest)
    return manifest


def target_feature_sets(requested: str) -> list[str]:
    if requested == "all":
        return ["selected_cases", "high_confidence"]
    return [requested]


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    summary = read_table(args.interpretation_dir / "te_functional_context_summary.tsv")
    context_rows = {
        (str(row["feature"]), str(row["context_type"])): row
        for row in summary.to_dict("records")
    }
    module_rows = load_module_rows(args.module_dir)

    results = []
    for feature_set in target_feature_sets(args.feature_set):
        features = features_for_set(feature_set, args.features, summary)
        result = build_feature_set(feature_set, features, args.datasets, context_rows, module_rows, args)
        results.append(result)

    if args.dry_run:
        print(json.dumps({"dry_run": True, "results": results}, indent=2, ensure_ascii=False))
        return 0

    write_readme(args.output_dir)
    print(json.dumps({"dry_run": False, "results": results}, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
