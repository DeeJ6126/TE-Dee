from __future__ import annotations

import csv
import json
from pathlib import Path

import pandas as pd
import pytest

import build_display_subgraphs as bds


def write_tsv(path: Path, rows: list[dict[str, object]], columns: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, delimiter="\t")
        writer.writeheader()
        writer.writerows(rows)


def test_selected_cases_uses_default_features_when_no_explicit_features() -> None:
    summary = pd.DataFrame(
        [
            {"feature": "L1HS", "functional_context_confidence": "high"},
            {"feature": "OTHER", "functional_context_confidence": "high"},
        ]
    )

    assert bds.features_for_set("selected_cases", None, summary) == ["L1HS", "LTR5", "HERVH-int", "CR1", "MER11B"]


def test_high_confidence_features_are_read_from_summary_and_sorted() -> None:
    summary = pd.DataFrame(
        [
            {"feature": "B", "functional_context_confidence": "medium"},
            {"feature": "C", "functional_context_confidence": "high"},
            {"feature": "A", "functional_context_confidence": "high"},
            {"feature": "A", "functional_context_confidence": "high"},
        ]
    )

    assert bds.features_for_set("high_confidence", None, summary) == ["A", "C"]


def test_all_te_features_are_read_from_summary_and_sorted() -> None:
    summary = pd.DataFrame(
        [
            {"feature": "B", "functional_context_confidence": "medium"},
            {"feature": "A", "functional_context_confidence": "high"},
            {"feature": "A", "functional_context_confidence": "low"},
        ]
    )

    assert bds.features_for_set("all_te", None, summary) == ["A", "B"]
    assert bds.target_feature_sets("all_te") == ["all_te"]


def test_build_subgraph_selects_positive_partners_hubs_and_internal_edges(tmp_path: Path) -> None:
    filtered_dir = tmp_path / "filtered"
    module_dir = tmp_path / "modules"
    columns = [
        "source",
        "target",
        "source_type",
        "target_type",
        "pair_type",
        "correlation",
        "abs_correlation",
        "fdr",
    ]
    write_tsv(
        filtered_dir / "ctx_edges.tsv",
        [
            {"source": "CENTER", "target": "G1", "source_type": "TE", "target_type": "gene", "pair_type": "te_gene", "correlation": 0.9, "abs_correlation": 0.9, "fdr": 0.001},
            {"source": "CENTER", "target": "T1", "source_type": "TE", "target_type": "TE", "pair_type": "te_te", "correlation": 0.8, "abs_correlation": 0.8, "fdr": 0.002},
            {"source": "CENTER", "target": "NEG", "source_type": "TE", "target_type": "gene", "pair_type": "te_gene", "correlation": -0.95, "abs_correlation": 0.95, "fdr": 0.001},
            {"source": "G1", "target": "HubGene", "source_type": "gene", "target_type": "gene", "pair_type": "gene_gene", "correlation": 0.7, "abs_correlation": 0.7, "fdr": 0.01},
            {"source": "T1", "target": "HubTE", "source_type": "TE", "target_type": "TE", "pair_type": "te_te", "correlation": 0.6, "abs_correlation": 0.6, "fdr": 0.02},
        ],
        columns,
    )
    write_tsv(
        module_dir / "te_module_summary.tsv",
        [
            {
                "context_type": "ctx",
                "feature": "CENTER",
                "feature_type": "TE",
                "module_id": "ctx_M001",
                "module_size": 50,
                "within_module_degree": 3,
                "weighted_within_module_degree": 2.3,
                "positive_degree": 4,
                "weighted_positive_degree": 3.1,
                "is_module_hub": "false",
                "module_hub_rank": "",
                "TE_count": 5,
                "gene_count": 45,
                "TE_fraction": 0.1,
                "hub_features": "HubGene;HubTE",
                "hub_TEs": "HubTE",
                "hub_genes": "HubGene",
            }
        ],
        [
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
        ],
    )
    context_row = {
        "feature": "CENTER",
        "context_type": "ctx",
        "module_id": "ctx_M001",
        "module_type": "gene-rich",
        "module_size": 50,
        "TE_count": 5,
        "gene_count": 45,
        "TE_fraction": 0.1,
        "functional_context_confidence": "high",
        "candidate_label": "cell cycle",
        "top_enriched_terms": "GO:cell cycle",
        "interpretation_statement_zh": "zh",
        "interpretation_statement_en": "en",
    }
    module_rows = bds.load_module_rows(module_dir)

    graph = bds.build_subgraph(
        center="CENTER",
        dataset="ctx",
        context_row=context_row,
        module_row=module_rows[("CENTER", "ctx")],
        filtered_dir=filtered_dir,
        top_n_partners=1,
        top_n_hubs=1,
        max_internal_edges=10,
        feature_set="selected_cases",
        selection_rule="test rule",
    )

    node_ids = {node["id"] for node in graph["nodes"]}
    assert {"CENTER", "G1", "T1", "HubGene", "HubTE"}.issubset(node_ids)
    assert "NEG" not in node_ids
    assert any(edge["source"] == "CENTER" and edge["target"] == "G1" for edge in graph["edges"])
    assert any(edge["source"] == "G1" and edge["target"] == "HubGene" for edge in graph["edges"])
    assert graph["selection"]["max_internal_edges"] == 10


def test_build_subgraph_prioritizes_center_edges_over_internal_cap(tmp_path: Path) -> None:
    filtered_dir = tmp_path / "filtered"
    module_dir = tmp_path / "modules"
    columns = [
        "source",
        "target",
        "source_type",
        "target_type",
        "pair_type",
        "correlation",
        "abs_correlation",
        "fdr",
    ]
    rows = [
        {"source": "CENTER", "target": "G1", "source_type": "TE", "target_type": "gene", "pair_type": "TE_gene", "correlation": 0.6, "abs_correlation": 0.6, "fdr": 0.01},
        {"source": "CENTER", "target": "T1", "source_type": "TE", "target_type": "TE", "pair_type": "TE_TE", "correlation": 0.55, "abs_correlation": 0.55, "fdr": 0.02},
    ]
    for idx in range(5):
        rows.append(
            {
                "source": f"G{idx + 2}",
                "target": f"G{idx + 3}",
                "source_type": "gene",
                "target_type": "gene",
                "pair_type": "gene_gene",
                "correlation": 0.99 - idx * 0.01,
                "abs_correlation": 0.99 - idx * 0.01,
                "fdr": 0.001,
            }
        )
    write_tsv(filtered_dir / "ctx_edges.tsv", rows, columns)
    write_tsv(
        module_dir / "te_module_summary.tsv",
        [
            {
                "context_type": "ctx",
                "feature": "CENTER",
                "feature_type": "TE",
                "module_id": "ctx_M001",
                "module_size": 50,
                "within_module_degree": 3,
                "weighted_within_module_degree": 2.3,
                "positive_degree": 4,
                "weighted_positive_degree": 3.1,
                "is_module_hub": "false",
                "module_hub_rank": "",
                "TE_count": 5,
                "gene_count": 45,
                "TE_fraction": 0.1,
                "hub_features": "G2;G3;G4;G5;G6;G7",
                "hub_TEs": "",
                "hub_genes": "G2;G3;G4;G5;G6;G7",
            }
        ],
        bds.MODULE_COLUMNS,
    )
    context_row = {
        "feature": "CENTER",
        "context_type": "ctx",
        "module_id": "ctx_M001",
        "module_type": "gene-rich",
        "module_size": 50,
        "TE_count": 5,
        "gene_count": 45,
        "TE_fraction": 0.1,
        "functional_context_confidence": "high",
        "candidate_label": "cell cycle",
        "top_enriched_terms": "GO:cell cycle",
        "interpretation_statement_zh": "zh",
        "interpretation_statement_en": "en",
    }
    module_rows = bds.load_module_rows(module_dir)

    graph = bds.build_subgraph(
        center="CENTER",
        dataset="ctx",
        context_row=context_row,
        module_row=module_rows[("CENTER", "ctx")],
        filtered_dir=filtered_dir,
        top_n_partners=1,
        top_n_hubs=5,
        max_internal_edges=1,
        feature_set="selected_cases",
        selection_rule="test rule",
    )

    center_edges = [
        edge for edge in graph["edges"] if edge["role"] == "center_neighbor_edge"
    ]
    assert len(center_edges) == 2
    assert {tuple(sorted((edge["source"], edge["target"]))) for edge in center_edges} == {
        ("CENTER", "G1"),
        ("CENTER", "T1"),
    }


def test_dry_run_does_not_create_output_directory(tmp_path: Path) -> None:
    filtered_dir = tmp_path / "filtered"
    module_dir = tmp_path / "modules"
    interp_dir = tmp_path / "interp"
    output_dir = tmp_path / "out"
    write_tsv(
        interp_dir / "te_functional_context_summary.tsv",
        [
            {
                "feature": "L1HS",
                "context_type": "ctx",
                "module_id": "ctx_M001",
                "module_size": 1,
                "TE_count": 1,
                "gene_count": 0,
                "TE_fraction": 1,
                "module_type": "TE-rich",
                "functional_context_confidence": "low",
                "candidate_label": "",
                "top_enriched_terms": "",
                "interpretation_statement_zh": "",
                "interpretation_statement_en": "",
            }
        ],
        bds.CONTEXT_COLUMNS,
    )
    write_tsv(
        module_dir / "te_module_summary.tsv",
        [
            {
                "context_type": "ctx",
                "feature": "L1HS",
                "feature_type": "TE",
                "module_id": "ctx_M001",
                "module_size": 1,
                "within_module_degree": 0,
                "weighted_within_module_degree": 0,
                "positive_degree": 0,
                "weighted_positive_degree": 0,
                "is_module_hub": "false",
                "module_hub_rank": "",
                "TE_count": 1,
                "gene_count": 0,
                "TE_fraction": 1,
                "hub_features": "",
                "hub_TEs": "",
                "hub_genes": "",
            }
        ],
        bds.MODULE_COLUMNS,
    )
    filtered_dir.mkdir()

    code = bds.main(
        [
            "--filtered-dir",
            str(filtered_dir),
            "--module-dir",
            str(module_dir),
            "--interpretation-dir",
            str(interp_dir),
            "--output-dir",
            str(output_dir),
            "--datasets",
            "ctx",
            "--dry-run",
        ]
    )

    assert code == 0
    assert not output_dir.exists()
