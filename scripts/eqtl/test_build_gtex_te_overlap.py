import unittest
import subprocess
import sys
import tempfile
from pathlib import Path

import pandas as pd

from scripts.eqtl import build_gtex_te_overlap as overlap
from scripts.eqtl import gtex_overlap_core as core


class CompatibilityTests(unittest.TestCase):
    def test_bed_case_mapping(self):
        with tempfile.TemporaryDirectory() as directory:
            bed = Path(directory) / "repeats.bed"
            bed.write_text("chr1\t10\t20\tCharlie10\t0\t+\tDNA\thAT\nchrY\t30\t40\tTigger1\t0\t-\tDNA\tTcMar\n", encoding="utf8")
            frame, counts = core.load_te_intervals(bed, {"CHARLIE10", "TIGGER1"})
            self.assertEqual(set(frame.te_name), {"CHARLIE10", "TIGGER1"})
            self.assertEqual(counts["approved_te_names_with_intervals"], 2)
            self.assertEqual(frame.iloc[0].te_instance_id, "CHARLIE10@chr1:10-20:+")
            with self.assertRaisesRegex(ValueError, "Ambiguous"):
                core.load_te_intervals(bed, {"Charlie10", "CHARLIE10"})

    def test_public_aliases_reference_core_objects(self):
        self.assertIs(overlap.parse_variant_id, core.parse_variant_id)
        self.assertIs(overlap.match_variant_intervals, core.match_variant_intervals)
        self.assertIs(overlap.ensure_pyarrow_compatible, core.ensure_pyarrow_compatible)

    def test_direct_script_help_uses_import_fallback(self):
        script = Path(overlap.__file__).resolve()
        result = subprocess.run(
            [sys.executable, str(script), "--help"],
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("--validate-inputs-only", result.stdout)


class VariantCoordinateTests(unittest.TestCase):
    def test_parse_snp_to_zero_based_half_open_interval(self):
        parsed = overlap.parse_variant_id("chr1_63671_G_A_b38")

        self.assertEqual(parsed.chrom, "chr1")
        self.assertEqual(parsed.start, 63670)
        self.assertEqual(parsed.end, 63671)
        self.assertEqual(parsed.ref, "G")
        self.assertEqual(parsed.alt, "A")

    def test_parse_deletion_uses_reference_allele_span(self):
        parsed = overlap.parse_variant_id("chr2_100_ATG_A_b38")

        self.assertEqual((parsed.start, parsed.end), (99, 102))

    def test_parse_normalizes_chromosome_without_chr_prefix(self):
        parsed = overlap.parse_variant_id("7_42_C_T_b38")

        self.assertEqual(parsed.chrom, "chr7")

    def test_parse_rejects_symbolic_or_wrong_build_variants(self):
        for variant_id in (
            "chr1_100_A_<DEL>_b38",
            "chr1_100_A_G_b37",
            "chrM_100_A_G_b38",
            "not-a-variant",
        ):
            with self.subTest(variant_id=variant_id):
                with self.assertRaises(ValueError):
                    overlap.parse_variant_id(variant_id)


class StrictOverlapTests(unittest.TestCase):
    def setUp(self):
        self.intervals = pd.DataFrame(
            [
                {
                    "te_instance_id": "TE1@chr1:100-200:+",
                    "te_name": "TE1",
                    "te_class": "LINE",
                    "te_family": "L1",
                    "chrom": "chr1",
                    "te_start": 100,
                    "te_end": 200,
                    "te_strand": "+",
                },
                {
                    "te_instance_id": "TE2@chr1:150-250:-",
                    "te_name": "TE2",
                    "te_class": "LTR",
                    "te_family": "ERV",
                    "chrom": "chr1",
                    "te_start": 150,
                    "te_end": 250,
                    "te_strand": "-",
                },
            ]
        )

    def _variants(self, rows):
        return pd.DataFrame(rows, columns=["variant_id", "chrom", "variant_start", "variant_end"])

    def test_touching_left_or_right_boundary_is_not_overlap(self):
        variants = self._variants(
            [
                ("left", "chr1", 99, 100),
                ("right", "chr1", 200, 201),
            ]
        )

        result = overlap.match_variant_intervals(variants, self.intervals.iloc[[0]])

        self.assertEqual(result.to_dict("records"), [])

    def test_one_base_inside_is_overlap(self):
        variants = self._variants([("inside", "chr1", 199, 200)])

        result = overlap.match_variant_intervals(variants, self.intervals.iloc[[0]])

        self.assertEqual(result[["variant_id", "te_name"]].to_dict("records"), [{"variant_id": "inside", "te_name": "TE1"}])

    def test_overlapping_te_instances_emit_deterministic_multiple_matches(self):
        variants = self._variants([("shared", "chr1", 175, 176)])

        result = overlap.match_variant_intervals(variants, self.intervals)

        self.assertEqual(
            result[["variant_id", "te_name"]].to_dict("records"),
            [
                {"variant_id": "shared", "te_name": "TE1"},
                {"variant_id": "shared", "te_name": "TE2"},
            ],
        )

    def test_chromosome_mismatch_is_not_overlap(self):
        variants = self._variants([("other", "chr2", 175, 176)])

        result = overlap.match_variant_intervals(variants, self.intervals)

        self.assertEqual(result.to_dict("records"), [])


if __name__ == "__main__":
    unittest.main()
