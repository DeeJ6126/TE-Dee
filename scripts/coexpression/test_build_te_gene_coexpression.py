import unittest
import sys
from pathlib import Path

import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build_te_gene_coexpression as coexp


class FeatureFeatureEdgeTests(unittest.TestCase):
    def test_build_feature_feature_edges_emits_pair_types_without_self_edges(self):
        features = ["TE1", "TE2", "GENE1", "GENE2"]
        feature_types = pd.Series({"TE1": "TE", "TE2": "TE", "GENE1": "gene", "GENE2": "gene"})
        detection = pd.Series({"TE1": 1.0, "TE2": 1.0, "GENE1": 1.0, "GENE2": 1.0})
        corr = np.array(
            [
                [1.0, 0.7, -0.2, 0.1],
                [0.7, 1.0, 0.4, 0.2],
                [-0.2, 0.4, 1.0, 0.6],
                [0.1, 0.2, 0.6, 1.0],
            ]
        )
        p_values = np.array(
            [
                [0.0, 0.01, 0.2, 0.5],
                [0.01, 0.0, 0.03, 0.4],
                [0.2, 0.03, 0.0, 0.02],
                [0.5, 0.4, 0.02, 0.0],
            ]
        )
        fdr = p_values.copy()

        edges = coexp.build_feature_feature_edges(
            features=features,
            feature_types=feature_types,
            corr=corr,
            p_values=p_values,
            fdr=fdr,
            context_type="unit",
            method="spearman",
            sample_count=4,
            detection=detection,
            expression_filter="unit filter",
            min_abs_correlation=0.0,
            max_fdr=1.0,
        )

        self.assertEqual(len(edges), 6)
        self.assertEqual(set(edges["pair_type"]), {"TE_TE", "TE_gene", "gene_gene"})
        self.assertFalse((edges["source"] == edges["target"]).any())


if __name__ == "__main__":
    unittest.main()
