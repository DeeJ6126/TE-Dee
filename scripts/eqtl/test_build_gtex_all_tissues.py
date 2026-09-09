import gzip
import json
import shutil
import tarfile
import tempfile
import unittest
from pathlib import Path

import pandas as pd
import pyarrow as pa
import pyarrow.parquet as pq

from scripts.eqtl import build_gtex_all_tissues as builder
from scripts.eqtl import gtex_overlap_core as core


class GTExAllTissuesFixtureTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.archive = self.root / "fixture.tar"
        self.bed = self.root / "te.bed"
        self.catalog = self.root / "catalog.json"
        self.output = self.root / "out"
        names = {"TEA": "repA", "TEB": "repB"}
        names.update({f"unused{i}": f"rep{i}" for i in range(274)})
        self.catalog.write_text(json.dumps({"db_to_repbase": names}), encoding="utf-8")
        self.bed.write_text(
            "chr1\t100\t200\tTEA\t0\t+\tLINE\tL1\n"
            "chr1\t150\t250\tTEA\t0\t-\tLINE\tL1\n",
            encoding="utf-8",
        )
        self._write_archive()

    def tearDown(self):
        self.temp.cleanup()

    def _write_archive(self, archive=None, conflicting_duplicate=False):
        archive = archive or self.archive
        work = self.root / "members"
        work.mkdir(exist_ok=True)
        rows = {
            "Tissue_A": [
                ("ENSG000001.1", "chr1_151_A_G_b38", 0.01, 0.5),
                ("ENSG000001.1", "chr1_151_A_G_b38", 0.01, 0.5),
                ("ENSG000001.1", "chr1_100_A_G_b38", 0.02, 0.1),
                ("ENSG000002.2", "chr1_151_A_G_b38", 0.03, -0.7),
            ],
            "Tissue_B": [
                ("ENSG000001.1", "chr1_151_A_G_b38", 0.04, -0.5),
                ("ENSG000002.2", "chr1_151_A_G_b38", 0.05, 0.2),
            ],
        }
        if conflicting_duplicate:
            rows["Tissue_A"].append(("ENSG000001.1", "chr1_151_A_G_b38", 0.99, 0.5))
        with tarfile.open(archive, "w") as tar:
            for tissue, values in rows.items():
                frame = pd.DataFrame({
                    "phenotype_id": [r[0] for r in values], "variant_id": [r[1] for r in values],
                    "start_distance": [1] * len(values), "af": [0.1] * len(values), "ma_samples": [10] * len(values),
                    "ma_count": [2] * len(values), "pval_nominal": [r[2] for r in values], "slope": [r[3] for r in values],
                    "slope_se": [0.1] * len(values), "pval_nominal_threshold": [0.05] * len(values),
                    "min_pval_nominal": [0.01] * len(values), "pval_beta": [0.2] * len(values),
                })
                parquet = work / f"{tissue}.parquet"
                pq.write_table(pa.Table.from_pandas(frame), parquet, row_group_size=1)
                tar.add(parquet, arcname=f"GTEx_Analysis_v11_eQTL/{tissue}.v11.eQTLs.signif_pairs.parquet")
                egenes = work / f"{tissue}.txt.gz"
                with gzip.open(egenes, "wt", encoding="utf-8") as handle:
                    handle.write("gene_id\tgene_name\tbiotype\tgene_chr\tgene_start\tgene_end\tstrand\n")
                    handle.write("ENSG000001.1\tG1\tprotein_coding\tchr1\t101\t200\t+\n")
                    handle.write("ENSG000002.2\tG2\tlncRNA\tchr1\t151\t250\t-\n")
                tar.add(egenes, arcname=f"GTEx_Analysis_v11_eQTL/{tissue}.v11.eGenes.txt.gz")

    def test_discovery_requires_complete_pairs(self):
        members = builder.discover_tissue_members(self.archive, expected_count=2)
        self.assertEqual(sorted(members), ["Tissue_A", "Tissue_B"])
        self.assertTrue(members["Tissue_A"].egenes_name.endswith("eGenes.txt.gz"))

    def test_discovery_rejects_unpaired_and_duplicate_members(self):
        unpaired = self.root / "unpaired.tar"
        shutil.copyfile(self.archive, unpaired)
        with tarfile.open(unpaired, "a") as tar:
            tar.add(self.root / "members" / "Tissue_A.txt.gz", arcname="GTEx_Analysis_v11_eQTL/Only.v11.eGenes.txt.gz")
        with self.assertRaisesRegex(ValueError, "Unpaired"):
            builder.discover_tissue_members(unpaired, expected_count=2)

        duplicate = self.root / "duplicate.tar"
        shutil.copyfile(self.archive, duplicate)
        with tarfile.open(duplicate, "a") as tar:
            tar.add(self.root / "members" / "Tissue_A.txt.gz", arcname="other/Tissue_A.v11.eGenes.txt.gz")
        with self.assertRaisesRegex(ValueError, "Duplicate"):
            builder.discover_tissue_members(duplicate, expected_count=2)

    def test_build_resume_and_atomic_failure(self):
        result = builder.run_build(self.archive, self.bed, self.catalog, self.output, expected_count=2)
        self.assertEqual(result["completed"], ["Tissue_A", "Tissue_B"])
        preflight = builder.build_preflight_report(
            self.archive,
            self.output,
            expected_count=2,
            representative_tissues=("Tissue_A", "Tissue_B"),
        )
        self.assertEqual(preflight["tissue_count"], 2)
        self.assertEqual(preflight["total_source_rows"], 6)
        self.assertTrue((self.output / "preflight_report.json").is_file())
        finalized = builder.finalize_all_tissue_run(
            self.archive,
            self.bed,
            self.catalog,
            self.output,
            expected_count=2,
        )
        self.assertEqual(finalized["counts"]["tissue_count"], 2)
        self.assertEqual(finalized["counts"]["source_association_count"], 6)
        audit = (self.output / "missing_browse_te.tsv").read_text(encoding="utf-8")
        self.assertEqual(len(audit.splitlines()) - 1, 276)
        self.assertIn("TEB\t0\t0\t0", audit)
        evidence = pq.read_table(self.output / "Tissue_A" / "te_variant_gene_overlaps.parquet").to_pandas()
        self.assertEqual(len(evidence), 4)  # one SNP hits both TE instances for two genes
        self.assertEqual(set(evidence.loc[evidence.variant_id == "chr1_151_A_G_b38", "te_instance_id"]), {"TEA@chr1:100-200:+", "TEA@chr1:150-250:-"})
        self.assertEqual(
            evidence[["te_instance_id", "gene_id", "variant_id"]].to_dict("records"),
            [
                {"te_instance_id": "TEA@chr1:100-200:+", "gene_id": "ENSG000001.1", "variant_id": "chr1_151_A_G_b38"},
                {"te_instance_id": "TEA@chr1:100-200:+", "gene_id": "ENSG000002.2", "variant_id": "chr1_151_A_G_b38"},
                {"te_instance_id": "TEA@chr1:150-250:-", "gene_id": "ENSG000001.1", "variant_id": "chr1_151_A_G_b38"},
                {"te_instance_id": "TEA@chr1:150-250:-", "gene_id": "ENSG000002.2", "variant_id": "chr1_151_A_G_b38"},
            ],
        )
        self.assertNotIn("chr1_100_A_G_b38", set(evidence.variant_id))
        tissue_b = pq.read_table(self.output / "Tissue_B" / "te_variant_gene_overlaps.parquet").to_pandas()
        self.assertEqual(
            evidence.loc[evidence.gene_id == "ENSG000001.1", "slope"].unique().tolist(),
            [0.5],
        )
        self.assertEqual(
            tissue_b.loc[tissue_b.gene_id == "ENSG000001.1", "slope"].unique().tolist(),
            [-0.5],
        )
        self.assertIn("ENSG000002.2", set(tissue_b.gene_id))
        _, inventory = core.load_te_intervals(self.bed, core.load_approved_te_names(self.catalog))
        self.assertEqual(inventory["approved_te_names_without_intervals"], 275)
        summary = pq.read_table(self.output / "Tissue_A" / "te_gene_summary.parquet").to_pandas()
        self.assertEqual(len(summary), 2)
        resumed = builder.run_build(self.archive, self.bed, self.catalog, self.output, resume=True, expected_count=2)
        self.assertEqual(resumed["skipped"], ["Tissue_A", "Tissue_B"])
        with (self.output / "Tissue_A" / "report.md").open("a", encoding="utf-8") as handle:
            handle.write("corrupted\n")
        rebuilt = builder.run_build(self.archive, self.bed, self.catalog, self.output, resume=True, expected_count=2)
        self.assertEqual(rebuilt["completed"], ["Tissue_A"])
        self.assertEqual(rebuilt["skipped"], ["Tissue_B"])
        original_manifest = (self.output / "Tissue_B" / "manifest.json").read_bytes()
        with self.assertRaisesRegex(RuntimeError, "injected"):
            builder.run_build(
                self.archive,
                self.bed,
                self.catalog,
                self.output,
                tissues={"Tissue_B"},
                force_tissues={"Tissue_B"},
                expected_count=2,
                publish_failure_injector=lambda tissue: (_ for _ in ()).throw(RuntimeError("injected")),
            )
        self.assertTrue((self.output / "Tissue_B" / "manifest.json").is_file())
        self.assertEqual((self.output / "Tissue_B" / "manifest.json").read_bytes(), original_manifest)
        self.assertFalse(any(path.name.startswith(".Tissue_B.tmp") for path in self.output.iterdir()))
        self.assertFalse(any(".backup-" in path.name for path in self.output.iterdir()))
        state = json.loads((self.output / "run_state.json").read_text(encoding="utf-8"))
        self.assertEqual(state["tissues"]["Tissue_B"]["status"], "failed")
        self.assertIn("elapsed_seconds", state["tissues"]["Tissue_B"])
        self.assertIn("process_peak_rss_bytes", state["tissues"]["Tissue_B"])

    def test_conflicting_duplicate_evidence_fails(self):
        conflict_archive = self.root / "conflict.tar"
        self._write_archive(conflict_archive, conflicting_duplicate=True)
        with self.assertRaisesRegex(ValueError, "Conflicting duplicate overlap evidence"):
            builder.run_build(
                conflict_archive,
                self.bed,
                self.catalog,
                self.root / "conflict-output",
                expected_count=2,
            )


if __name__ == "__main__":
    unittest.main()
