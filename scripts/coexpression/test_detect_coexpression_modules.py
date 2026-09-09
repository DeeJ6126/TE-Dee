import unittest
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
import detect_coexpression_modules as modules


def edge(source, target, source_type, target_type, correlation):
    return {
        "source": source,
        "target": target,
        "source_type": source_type,
        "target_type": target_type,
        "context_type": "unit",
        "correlation": correlation,
    }


class CoexpressionModuleDetectionTests(unittest.TestCase):
    def test_positive_graph_excludes_negative_edges_and_tracks_positive_degree(self):
        builder = modules.PositiveGraphBuilder("unit")
        builder.consume_edge(edge("TE1", "GENE1", "TE", "gene", 0.8))
        builder.consume_edge(edge("TE1", "GENE2", "TE", "gene", -0.9))
        builder.consume_edge(edge("GENE1", "GENE2", "gene", "gene", 0.4))

        self.assertEqual(sorted(builder.graph.edges()), [("GENE1", "GENE2"), ("TE1", "GENE1")])
        self.assertEqual(builder.feature_types["TE1"], "TE")
        self.assertEqual(builder.positive_degree["TE1"], 1)
        self.assertEqual(builder.positive_degree["GENE1"], 2)
        self.assertAlmostEqual(builder.weighted_positive_degree["GENE1"], 1.2)

    def test_feature_and_module_rows_include_hub_selection_and_internal_summary(self):
        builder = modules.PositiveGraphBuilder("unit")
        for row in [
            edge("TE1", "GENE1", "TE", "gene", 0.9),
            edge("TE1", "GENE2", "TE", "gene", 0.8),
            edge("GENE1", "GENE2", "gene", "gene", 0.7),
            edge("TE2", "GENE3", "TE", "gene", 0.6),
            edge("GENE3", "GENE4", "gene", "gene", 0.5),
            edge("TE2", "GENE4", "TE", "gene", 0.4),
        ]:
            builder.consume_edge(row)

        communities = [{"TE1", "GENE1", "GENE2"}, {"TE2", "GENE3", "GENE4"}]
        feature_rows, module_rows = modules.summarize_communities("unit", builder, communities, min_module_size=3)
        by_feature = {row["feature"]: row for row in feature_rows}

        self.assertEqual(by_feature["TE1"]["module_id"], "unit_M001")
        self.assertEqual(by_feature["TE1"]["module_size"], 3)
        self.assertEqual(by_feature["TE1"]["within_module_degree"], 2)
        self.assertAlmostEqual(by_feature["TE1"]["weighted_within_module_degree"], 1.7)
        self.assertEqual(by_feature["TE1"]["is_module_hub"], "true")
        self.assertEqual(by_feature["TE1"]["module_hub_rank"], 1)

        first_module = module_rows[0]
        self.assertEqual(first_module["module_id"], "unit_M001")
        self.assertEqual(first_module["module_size"], 3)
        self.assertEqual(first_module["TE_count"], 1)
        self.assertEqual(first_module["gene_count"], 2)
        self.assertEqual(first_module["internal_edge_count"], 3)
        self.assertAlmostEqual(first_module["mean_internal_correlation"], 0.8)
        self.assertEqual(first_module["hub_features"], "TE1")
        self.assertEqual(first_module["hub_TEs"], "TE1")
        self.assertEqual(first_module["hub_genes"], "")

    def test_hub_count_is_top_five_percent_with_at_least_one_for_large_modules(self):
        self.assertEqual(modules.hub_count_for_module(3, 3), 1)
        self.assertEqual(modules.hub_count_for_module(20, 3), 1)
        self.assertEqual(modules.hub_count_for_module(21, 3), 2)
        self.assertEqual(modules.hub_count_for_module(2, 3), 0)


if __name__ == "__main__":
    unittest.main()
