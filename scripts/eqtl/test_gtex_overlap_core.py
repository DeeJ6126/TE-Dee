import unittest
from unittest import mock

import pandas as pd

from scripts.eqtl import gtex_overlap_core as core


class ArchiveMemberTests(unittest.TestCase):
    def test_member_discovery_is_tissue_specific(self):
        self.assertEqual(
            core.archive_member_name("Liver"),
            "GTEx_Analysis_v11_eQTL/Liver.v11.eQTLs.signif_pairs.parquet",
        )
        self.assertEqual(
            core.archive_member_name("Brain_Cortex"),
            "GTEx_Analysis_v11_eQTL/Brain_Cortex.v11.eQTLs.signif_pairs.parquet",
        )

    def test_pyarrow_19_0_0_fails_fast_with_actionable_message(self):
        with mock.patch.object(core, "pyarrow", type("PyArrow", (), {"__version__": "19.0.0"})), mock.patch.object(core, "pq", object()):
            with self.assertRaisesRegex(RuntimeError, "PyArrow 19.0.0.*19.0.1"):
                core.ensure_pyarrow_compatible()


class VariantAndOverlapTests(unittest.TestCase):
    def test_b38_snp_and_indel_reference_spans(self):
        snp = core.parse_variant_id("chr1_63671_G_A_b38")
        deletion = core.parse_variant_id("2_100_ATG_A_b38")

        self.assertEqual((snp.chrom, snp.start, snp.end, snp.ref, snp.alt), ("chr1", 63670, 63671, "G", "A"))
        self.assertEqual((deletion.chrom, deletion.start, deletion.end, deletion.ref, deletion.alt), ("chr2", 99, 102, "ATG", "A"))

    def test_strict_boundaries_multiple_instances_and_sorting(self):
        variants = pd.DataFrame(
            [
                ("right", "chr1", 250, 251),
                ("shared", "chr1", 175, 176),
                ("left", "chr1", 99, 100),
            ],
            columns=["variant_id", "chrom", "variant_start", "variant_end"],
        )
        intervals = pd.DataFrame(
            [
                ("TE2@chr1:150-250:-", "TE2", "LTR", "ERV", "chr1", 150, 250, "-"),
                ("TE1@chr1:100-200:+", "TE1", "LINE", "L1", "chr1", 100, 200, "+"),
            ],
            columns=core.TE_COLUMNS,
        )

        result = core.match_variant_intervals(variants, intervals)

        self.assertEqual(
            result[["variant_id", "te_instance_id"]].to_dict("records"),
            [
                {"variant_id": "shared", "te_instance_id": "TE1@chr1:100-200:+"},
                {"variant_id": "shared", "te_instance_id": "TE2@chr1:150-250:-"},
            ],
        )

    def test_prebuilt_interval_index_matches_dataframe_query(self):
        variants = pd.DataFrame(
            [
                ("v1", "chr1", 150, 151),
                ("v2", "chr1", 249, 250),
            ],
            columns=["variant_id", "chrom", "variant_start", "variant_end"],
        )
        intervals = pd.DataFrame(
            [
                ("a", "TEA", "LINE", "L1", "chr1", 100, 200, "+"),
                ("b", "TEB", "LTR", "ERV", "chr1", 150, 250, "-"),
            ],
            columns=core.TE_COLUMNS,
        )

        direct = core.match_variant_intervals(variants, intervals)
        indexed = core.match_variant_intervals(
            variants, core.index_te_intervals(intervals)
        )

        pd.testing.assert_frame_equal(direct, indexed)


if __name__ == "__main__":
    unittest.main()
