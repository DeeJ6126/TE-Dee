from __future__ import annotations

import csv
import json
from pathlib import Path

import build_display_tier_recommendations as tiers


def write_quality_summary(path: Path) -> None:
    rows = [
        {
            "feature": "L1HS",
            "te_name": "L1HS",
            "context": "cancer_cell_line",
            "json_path": str(path.parent / "all_te/L1HS/cancer_cell_line.json"),
            "has_subgraph": "true",
            "node_count": "26",
            "edge_count": "100",
            "center_edge_count": "25",
            "gene_neighbor_count": "15",
            "te_neighbor_count": "10",
            "other_neighbor_count": "0",
            "positive_edge_count": "100",
            "negative_edge_count": "0",
            "mean_abs_center_correlation": "0.8",
            "max_abs_center_correlation": "0.9",
            "module_id": "cancer_cell_line_M001",
            "module_size": "120",
            "module_te_count": "40",
            "module_gene_count": "80",
            "module_classification": "gene-rich",
            "functional_context_confidence": "high",
            "enrichment_term_count": "5",
            "has_enrichment": "true",
            "quality_flag": "high",
        },
        {
            "feature": "LOW1",
            "te_name": "LOW1",
            "context": "normal_tissue",
            "json_path": str(path.parent / "all_te/LOW1/normal_tissue.json"),
            "has_subgraph": "true",
            "node_count": "3",
            "edge_count": "1",
            "center_edge_count": "1",
            "gene_neighbor_count": "0",
            "te_neighbor_count": "1",
            "other_neighbor_count": "0",
            "positive_edge_count": "1",
            "negative_edge_count": "0",
            "mean_abs_center_correlation": "0.5",
            "max_abs_center_correlation": "0.5",
            "module_id": "normal_tissue_M099",
            "module_size": "10",
            "module_te_count": "9",
            "module_gene_count": "1",
            "module_classification": "TE-rich",
            "functional_context_confidence": "not_interpretable",
            "enrichment_term_count": "0",
            "has_enrichment": "false",
            "quality_flag": "low",
        },
    ]
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=tiers.OUTPUT_COLUMNS, delimiter="\t", extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def test_build_recommendations_marks_core_case_and_low_default_exclusion(tmp_path: Path) -> None:
    display_root = tmp_path / "display"
    summary_path = display_root / "all_te_quality_summary.tsv"
    summary_path.parent.mkdir(parents=True)
    write_quality_summary(summary_path)
    selected_manifest = display_root / "selected_cases" / "manifest.json"
    selected_manifest.parent.mkdir(parents=True)
    selected_manifest.write_text(
        json.dumps({"files": ["selected_cases\\L1HS\\cancer_cell_line.json"]}),
        encoding="utf-8",
    )
    high_manifest = display_root / "high_confidence" / "manifest.json"
    high_manifest.parent.mkdir(parents=True)
    high_manifest.write_text(
        json.dumps({"files": ["high_confidence\\L1HS\\cancer_cell_line.json"]}),
        encoding="utf-8",
    )

    rows = tiers.build_recommendations(summary_path, display_root)

    by_name = {(row["te_name"], row["context"]): row for row in rows}
    assert by_name[("L1HS", "cancer_cell_line")]["display_tier"] == "core_case"
    assert by_name[("L1HS", "cancer_cell_line")]["recommended_default"] == "true"
    assert by_name[("LOW1", "normal_tissue")]["display_tier"] == "not_recommended_default"
    assert by_name[("LOW1", "normal_tissue")]["recommended_default"] == "false"
    assert "相关" in by_name[("L1HS", "cancer_cell_line")]["reason_cn"]
