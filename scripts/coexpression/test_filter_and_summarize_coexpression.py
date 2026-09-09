import unittest
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
import filter_and_summarize_coexpression as post


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


def edge(source, target, source_type, target_type, correlation, fdr):
    pair_type = post.pair_type_for(source_type, target_type)
    return {
        "source": source,
        "target": target,
        "source_type": source_type,
        "target_type": target_type,
        "pair_type": pair_type,
        "edge_type": "co_expression",
        "context_type": "unit",
        "method": "spearman",
        "correlation": correlation,
        "abs_correlation": abs(correlation),
        "p_value": 0.01,
        "fdr": fdr,
        "sample_count": 5,
        "source_detection_rate": 1.0,
        "target_detection_rate": 1.0,
        "expression_filter": "unit",
        "variance_metric": "MAD",
        "interpretation_level": "correlation_only",
    }


class CoexpressionPostprocessTests(unittest.TestCase):
    def test_parse_thresholds_builds_stable_labels(self):
        thresholds = post.parse_thresholds(["0.3:0.05", "0.40:5e-2"])
        self.assertEqual([t.label for t in thresholds], ["abs0.3_fdr0.05", "abs0.4_fdr0.05"])
        self.assertEqual(thresholds[0].abs_correlation, 0.3)
        self.assertEqual(thresholds[0].fdr, 0.05)

    def test_aggregate_edges_tracks_degrees_and_top_partners(self):
        agg = post.DatasetAggregator("unit")
        rows = [
            edge("TE1", "GENE1", "TE", "gene", 0.9, 1e-5),
            edge("TE1", "GENE2", "TE", "gene", -0.8, 2e-4),
            edge("TE1", "TE2", "TE", "TE", 0.7, 0.001),
            edge("GENE1", "GENE2", "gene", "gene", -0.6, 0.002),
        ]
        agg.consume_rows(rows)

        dataset_row = agg.dataset_summary_row("raw.tsv", "filtered.tsv")
        self.assertEqual(dataset_row["total_edges"], 4)
        self.assertEqual(dataset_row["positive_edges"], 2)
        self.assertEqual(dataset_row["negative_edges"], 2)
        self.assertEqual(dataset_row["TE_gene_edges"], 2)
        self.assertEqual(dataset_row["TE_TE_edges"], 1)
        self.assertEqual(dataset_row["gene_gene_edges"], 1)

        feature_rows = {row["feature"]: row for row in agg.feature_summary_rows()}
        self.assertEqual(feature_rows["TE1"]["degree_total"], 3)
        self.assertEqual(feature_rows["TE1"]["positive_degree"], 2)
        self.assertEqual(feature_rows["TE1"]["negative_degree"], 1)
        self.assertEqual(feature_rows["TE1"]["TE_neighbor_count"], 1)
        self.assertEqual(feature_rows["TE1"]["gene_neighbor_count"], 2)
        self.assertEqual(feature_rows["TE1"]["TE_TE_edge_count"], 1)
        self.assertEqual(feature_rows["TE1"]["TE_gene_edge_count"], 2)
        self.assertEqual(feature_rows["TE1"]["gene_gene_edge_count"], 0)
        self.assertEqual(feature_rows["TE1"]["top_positive_partners"], "GENE1|r=0.9|fdr=1e-05;TE2|r=0.7|fdr=0.001")
        self.assertEqual(feature_rows["TE1"]["top_negative_partners"], "GENE2|r=-0.8|fdr=0.0002")

    def test_hub_rule_marks_top_five_percent_with_minimum_ten_when_available(self):
        agg = post.DatasetAggregator("unit")
        for i in range(20):
            agg.consume_rows([edge("TE_HUB", f"GENE{i:02d}", "TE", "gene", 0.5, 0.01)])
        for i in range(10):
            agg.consume_rows([edge("TE_SECOND", f"GENE{i:02d}", "TE", "gene", 0.4, 0.02)])

        rows = agg.feature_summary_rows()
        hubs = [row for row in rows if row["is_hub"] == "true"]
        self.assertEqual(len(hubs), 10)
        self.assertEqual(hubs[0]["feature"], "TE_HUB")
        self.assertEqual(hubs[0]["hub_rank"], 1)
        self.assertEqual(hubs[1]["feature"], "TE_SECOND")


if __name__ == "__main__":
    unittest.main()
