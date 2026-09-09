import gzip
import json
import tarfile
import tempfile
import unittest
from pathlib import Path

import pandas as pd
import pyarrow as pa
import pyarrow.parquet as pq

from scripts.eqtl import build_gtex_all_tissues as builder
from scripts.eqtl import consolidate_gtex_mysql_artifacts as consolidator


class ConsolidationFixtureTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.archive = self.root / "fixture.tar"
        self.bed = self.root / "te.bed"
        self.catalog = self.root / "catalog.json"
        self.artifacts = self.root / "artifacts"
        names = {"TEA": "repA", "TEB": "repB"}
        names.update({f"unused{i}": f"rep{i}" for i in range(274)})
        self.catalog.write_text(json.dumps({"db_to_repbase": names}), encoding="utf-8")
        self.bed.write_text(
            "chr1\t100\t200\tTEA\t0\t+\tLINE\tL1\n"
            "chr1\t150\t250\tTEA\t0\t-\tLINE\tL1\n",
            encoding="utf-8",
        )
        self._write_archive()
        builder.run_build(
            self.archive, self.bed, self.catalog, self.artifacts, expected_count=2
        )
        builder.build_preflight_report(
            self.archive,
            self.artifacts,
            expected_count=2,
            representative_tissues=("Tissue_A", "Tissue_B"),
        )
        builder.finalize_all_tissue_run(
            self.archive,
            self.bed,
            self.catalog,
            self.artifacts,
            expected_count=2,
        )

    def tearDown(self):
        self.temp.cleanup()

    def _write_archive(self):
        members = self.root / "members"
        members.mkdir()
        rows = {
            "Tissue_A": [
                ("ENSG000001.1", 0.01, 0.5),
                ("ENSG000002.2", 0.02, -0.7),
            ],
            "Tissue_B": [
                ("ENSG000001.1", 0.03, -0.5),
                ("ENSG000002.2", 0.04, 0.2),
            ],
        }
        with tarfile.open(self.archive, "w") as tar:
            for tissue, values in rows.items():
                frame = pd.DataFrame(
                    {
                        "phenotype_id": [row[0] for row in values],
                        "variant_id": ["chr1_151_A_G_b38"] * len(values),
                        "start_distance": [1] * len(values),
                        "af": [0.1] * len(values),
                        "ma_samples": [10] * len(values),
                        "ma_count": [2] * len(values),
                        "pval_nominal": [row[1] for row in values],
                        "slope": [row[2] for row in values],
                        "slope_se": [0.1] * len(values),
                        "pval_nominal_threshold": [0.05] * len(values),
                        "min_pval_nominal": [0.01] * len(values),
                        "pval_beta": [0.2] * len(values),
                    }
                )
                parquet = members / f"{tissue}.parquet"
                pq.write_table(pa.Table.from_pandas(frame), parquet, row_group_size=1)
                tar.add(
                    parquet,
                    arcname=f"GTEx_Analysis_v11_eQTL/{tissue}.v11.eQTLs.signif_pairs.parquet",
                )
                egenes = members / f"{tissue}.txt.gz"
                with gzip.open(egenes, "wt", encoding="utf-8") as handle:
                    handle.write(
                        "gene_id\tgene_name\tbiotype\tgene_chr\tgene_start\tgene_end\tstrand\n"
                    )
                    handle.write("ENSG000001.1\tG1\tprotein_coding\tchr1\t101\t200\t+\n")
                    handle.write("ENSG000002.2\tG2\tlncRNA\tchr1\t151\t250\t-\n")
                tar.add(
                    egenes,
                    arcname=f"GTEx_Analysis_v11_eQTL/{tissue}.v11.eGenes.txt.gz",
                )

    def test_normalized_deterministic_exports(self):
        first = consolidator.consolidate(
            self.artifacts,
            self.archive,
            self.bed,
            self.catalog,
            expected_count=2,
            part_rows=2,
        )
        expected = {
            "eqtl_tissues": 2,
            "eqtl_te_instances": 2,
            "eqtl_variants": 1,
            "eqtl_genes": 2,
            "eqtl_te_variant_overlaps": 2,
            "eqtl_variant_gene_tissue_associations": 4,
            "eqtl_te_gene_tissue_summary": 4,
            "eqtl_te_gene_cross_tissue_summary": 2,
        }
        self.assertEqual(
            {name: value["rows"] for name, value in first["tables"].items()},
            expected,
        )
        for entry in first["tables"].values():
            self.assertTrue(all(part["rows"] <= 2 for part in entry["files"]))

        database = self.artifacts / "mysql" / "staging" / "consolidation.sqlite"
        import sqlite3

        connection = sqlite3.connect(database)
        row = connection.execute(
            """SELECT positive_tissue_count, negative_tissue_count,
            mixed_tissue_count FROM cross_tissue_summary
            WHERE te_name='TEA' AND gene_id='ENSG000001.1'"""
        ).fetchone()
        connection.close()
        self.assertEqual(row, (1, 1, 0))

        first_hashes = {
            part["path"]: part["sha256"]
            for table in first["tables"].values()
            for part in table["files"]
        }
        second = consolidator.consolidate(
            self.artifacts,
            self.archive,
            self.bed,
            self.catalog,
            expected_count=2,
            part_rows=2,
        )
        second_hashes = {
            part["path"]: part["sha256"]
            for table in second["tables"].values()
            for part in table["files"]
        }
        self.assertEqual(first_hashes, second_hashes)


if __name__ == "__main__":
    unittest.main()
