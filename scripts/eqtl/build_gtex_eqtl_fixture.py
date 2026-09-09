#!/usr/bin/env python
"""Build the persistent two-tissue fixture used to test the MySQL importer."""

from __future__ import annotations

import gzip
import json
import os
import tarfile
import time
from pathlib import Path

import pandas as pd
import pyarrow as pa
import pyarrow.parquet as pq

try:
    from scripts.eqtl import build_gtex_all_tissues as builder
    from scripts.eqtl import consolidate_gtex_mysql_artifacts as consolidator
except ModuleNotFoundError:
    import build_gtex_all_tissues as builder
    import consolidate_gtex_mysql_artifacts as consolidator


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DESTINATION = PROJECT_ROOT / "data/eQTL/derived/gtex_v11_strict_te_overlap_fixture"
VERSION_KEY = "gtex_v11_strict_te_overlap_fixture"


def _write_sources(root: Path) -> tuple[Path, Path, Path]:
    sources = root / "fixture_sources"
    sources.mkdir(parents=True)
    archive = sources / "fixture.tar"
    bed = sources / "te.bed"
    catalog = sources / "catalog.json"
    names = {"TEA": "repA", "TEB": "repB"}
    names.update({f"unused{i}": f"rep{i}" for i in range(274)})
    catalog.write_text(json.dumps({"db_to_repbase": names}), encoding="utf-8")
    bed.write_text(
        "chr1\t100\t200\tTEA\t0\t+\tLINE\tL1\n"
        "chr1\t150\t250\tTEA\t0\t-\tLINE\tL1\n",
        encoding="utf-8",
    )
    member_root = sources / "members"
    member_root.mkdir()
    tissue_rows = {
        "Tissue_A": [
            ("ENSG000001.1", "chr1_151_A_G_b38", 0.01, 0.5),
            ("ENSG000002.2", "chr1_151_A_G_b38", 0.02, -0.7),
            ("ENSG000001.1", "chr1_100_A_G_b38", 0.03, 0.1),
        ],
        "Tissue_B": [
            ("ENSG000001.1", "chr1_151_A_G_b38", 0.04, -0.5),
            ("ENSG000002.2", "chr1_151_A_G_b38", 0.05, 0.2),
        ],
    }
    with tarfile.open(archive, "w") as tar:
        for tissue, rows in tissue_rows.items():
            frame = pd.DataFrame(
                {
                    "phenotype_id": [row[0] for row in rows],
                    "variant_id": [row[1] for row in rows],
                    "start_distance": [1] * len(rows),
                    "af": [0.1] * len(rows),
                    "ma_samples": [10] * len(rows),
                    "ma_count": [2] * len(rows),
                    "pval_nominal": [row[2] for row in rows],
                    "slope": [row[3] for row in rows],
                    "slope_se": [0.1] * len(rows),
                    "pval_nominal_threshold": [0.05] * len(rows),
                    "min_pval_nominal": [0.01] * len(rows),
                    "pval_beta": [0.2] * len(rows),
                }
            )
            parquet = member_root / f"{tissue}.parquet"
            pq.write_table(pa.Table.from_pandas(frame), parquet, row_group_size=1)
            tar.add(
                parquet,
                arcname=f"GTEx_Analysis_v11_eQTL/{tissue}.v11.eQTLs.signif_pairs.parquet",
            )
            egenes = member_root / f"{tissue}.txt.gz"
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
    return archive, bed, catalog


def main() -> int:
    temp = DESTINATION.parent / f".{DESTINATION.name}.tmp-{os.getpid()}-{time.time_ns()}"
    temp.mkdir(parents=True)
    try:
        archive, bed, catalog = _write_sources(temp)
        builder.run_build(archive, bed, catalog, temp, expected_count=2)
        builder.build_preflight_report(
            archive,
            temp,
            expected_count=2,
            representative_tissues=("Tissue_A", "Tissue_B"),
        )
        builder.finalize_all_tissue_run(
            archive, bed, catalog, temp, expected_count=2
        )
        manifest = consolidator.consolidate(
            temp,
            archive,
            bed,
            catalog,
            expected_count=2,
            part_rows=2,
            version_key=VERSION_KEY,
        )
        builder._publish_tissue(temp, DESTINATION)
        print(
            json.dumps(
                {"version_key": manifest["version_key"], "tables": {
                    name: entry["rows"] for name, entry in manifest["tables"].items()
                }},
                indent=2,
                sort_keys=True,
            )
        )
        return 0
    except BaseException:
        if temp.exists():
            import shutil
            shutil.rmtree(temp, ignore_errors=True)
        raise


if __name__ == "__main__":
    raise SystemExit(main())
