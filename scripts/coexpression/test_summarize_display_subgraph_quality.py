from __future__ import annotations

import json
from pathlib import Path

import summarize_display_subgraph_quality as sdq


def write_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload), encoding="utf-8")


def test_summarize_graph_counts_center_neighbors_and_high_quality(tmp_path: Path) -> None:
    graph_path = tmp_path / "all_te" / "CENTER" / "ctx.json"
    nodes = [
        {"id": "CENTER", "feature_type": "TE", "role": "center", "is_center": True},
        *[{"id": f"G{i}", "feature_type": "gene"} for i in range(1, 6)],
        *[{"id": f"T{i}", "feature_type": "TE"} for i in range(1, 6)],
        {"id": "UNK", "feature_type": "other"},
    ]
    edges = [
        {
            "source": "CENTER",
            "target": f"G{i}",
            "role": "center_neighbor_edge",
            "correlation": 0.5 + i / 100,
            "abs_correlation": 0.5 + i / 100,
        }
        for i in range(1, 6)
    ] + [
        {
            "source": "CENTER",
            "target": f"T{i}",
            "role": "center_neighbor_edge",
            "correlation": -0.4 - i / 100,
            "abs_correlation": 0.4 + i / 100,
        }
        for i in range(1, 6)
    ]
    graph = {
        "center": "CENTER",
        "context_type": "ctx",
        "module_id": "ctx_M001",
        "module_type": "gene-rich",
        "module_size": 20,
        "TE_count": 10,
        "gene_count": 10,
        "functional_context_confidence": "low",
        "top_enriched_terms": "GO:one; KEGG:two",
        "nodes": nodes,
        "edges": edges,
    }
    write_json(graph_path, graph)

    row = sdq.summarize_graph(graph_path, graph, {}, {})

    assert row["feature"] == "CENTER"
    assert row["te_name"] == "CENTER"
    assert row["context"] == "ctx"
    assert row["has_subgraph"] == "true"
    assert row["node_count"] == 12
    assert row["edge_count"] == 10
    assert row["center_edge_count"] == 10
    assert row["gene_neighbor_count"] == 5
    assert row["te_neighbor_count"] == 5
    assert row["other_neighbor_count"] == 0
    assert row["positive_edge_count"] == 5
    assert row["negative_edge_count"] == 5
    assert row["enrichment_term_count"] == 2
    assert row["has_enrichment"] == "true"
    assert row["quality_flag"] == "high"


def test_quality_flag_medium_low_and_empty() -> None:
    assert sdq.quality_flag(center_edge_count=5, node_count=6, gene_neighbor_count=0, has_enrichment=False, confidence="low") == "medium"
    assert sdq.quality_flag(center_edge_count=1, node_count=2, gene_neighbor_count=0, has_enrichment=False, confidence="low") == "low"
    assert sdq.quality_flag(center_edge_count=0, node_count=1, gene_neighbor_count=0, has_enrichment=False, confidence="high") == "empty"
    assert sdq.quality_flag(center_edge_count=1, node_count=2, gene_neighbor_count=0, has_enrichment=False, confidence="high") == "high"
