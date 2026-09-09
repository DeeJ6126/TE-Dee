#!/usr/bin/env python
"""Consolidate complete GTEx TE-overlap outputs into MySQL import artifacts."""

from __future__ import annotations

import argparse
import csv
import gzip
import hashlib
import io
import json
import os
import re
import shutil
import sqlite3
import tarfile
import time
from pathlib import Path
from typing import Iterable, Iterator

import pandas as pd
import pyarrow.parquet as pq

try:
    from scripts.eqtl import build_gtex_all_tissues as builder
    from scripts.eqtl import gtex_overlap_core as core
except ModuleNotFoundError:  # Direct execution from scripts/eqtl.
    import build_gtex_all_tissues as builder
    import gtex_overlap_core as core


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_ARTIFACT_ROOT = PROJECT_ROOT / "data/eQTL/derived/gtex_v11_strict_te_overlap_v1"
DEFAULT_ARCHIVE = PROJECT_ROOT / "data/eQTL/GTEx_Analysis_v11_eQTL.tar"
DEFAULT_TE_BED = PROJECT_ROOT / "data/JBrowse/repeats/hg38.rmsk.repeats.bed"
DEFAULT_BROWSE_CATALOG = PROJECT_ROOT / "data/processed/te_repbase_db_matched.json"
VERSION_KEY = "gtex_v11_strict_te_overlap_v1"
SCHEMA_VERSION = 1
DEFAULT_PART_ROWS = 250_000


TABLE_COLUMNS = {
    "eqtl_tissues": [
        "tissue_key", "display_name", "source_member", "source_row_count",
        "evidence_row_count",
    ],
    "eqtl_te_instances": [
        "te_instance_key", "te_instance_id", "te_name", "te_class", "te_family",
        "chrom", "te_start", "te_end", "te_strand",
    ],
    "eqtl_variants": [
        "variant_key", "variant_id", "chrom", "variant_start", "variant_end", "ref", "alt",
    ],
    "eqtl_genes": [
        "gene_id", "gene_id_base", "gene_name", "biotype", "chrom",
        "gene_start", "gene_end", "strand",
    ],
    "eqtl_te_variant_overlaps": ["te_instance_key", "variant_key"],
    "eqtl_variant_gene_tissue_associations": [
        "tissue_key", "variant_key", "gene_id", "start_distance", "af",
        "ma_samples", "ma_count", "pval_nominal", "slope", "slope_se",
        "pval_nominal_threshold", "min_pval_nominal", "pval_beta",
    ],
    "eqtl_te_gene_tissue_summary": [
        "tissue_key", "te_name", "gene_id", "supporting_variant_count",
        "supporting_instance_count", "evidence_row_count", "minimum_pval_nominal",
        "maximum_abs_slope", "positive_slope_count", "negative_slope_count",
        "direction_class",
    ],
    "eqtl_te_gene_cross_tissue_summary": [
        "te_name", "gene_id", "tissue_count", "supporting_variant_count",
        "supporting_instance_count", "evidence_row_count", "positive_tissue_count",
        "negative_tissue_count", "mixed_tissue_count", "zero_tissue_count",
        "minimum_pval_nominal", "maximum_abs_slope",
    ],
}


IMPORT_ORDER = list(TABLE_COLUMNS)


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def _connect(path: Path) -> sqlite3.Connection:
    connection = sqlite3.connect(path)
    connection.execute("PRAGMA journal_mode=WAL")
    connection.execute("PRAGMA synchronous=NORMAL")
    connection.execute("PRAGMA foreign_keys=ON")
    connection.execute("PRAGMA temp_store=FILE")
    connection.executescript(
        """
        CREATE TABLE tissues (
          tissue_key TEXT PRIMARY KEY, display_name TEXT NOT NULL,
          source_member TEXT NOT NULL, source_row_count INTEGER NOT NULL,
          evidence_row_count INTEGER NOT NULL
        );
        CREATE TABLE te_instances (
          te_instance_key TEXT PRIMARY KEY, te_instance_id TEXT UNIQUE NOT NULL,
          te_name TEXT NOT NULL, te_class TEXT NOT NULL, te_family TEXT NOT NULL,
          chrom TEXT NOT NULL, te_start INTEGER NOT NULL, te_end INTEGER NOT NULL,
          te_strand TEXT NOT NULL
        );
        CREATE INDEX idx_te_instances_name ON te_instances(te_name, chrom, te_start);
        CREATE TABLE variants (
          variant_key TEXT PRIMARY KEY, variant_id TEXT UNIQUE NOT NULL,
          chrom TEXT NOT NULL, variant_start INTEGER NOT NULL, variant_end INTEGER NOT NULL,
          ref TEXT NOT NULL, alt TEXT NOT NULL
        );
        CREATE INDEX idx_variants_position ON variants(chrom, variant_start);
        CREATE TABLE genes (
          gene_id TEXT PRIMARY KEY, gene_id_base TEXT NOT NULL, gene_name TEXT NOT NULL,
          biotype TEXT NOT NULL, chrom TEXT NOT NULL, gene_start INTEGER NOT NULL,
          gene_end INTEGER NOT NULL, strand TEXT NOT NULL
        );
        CREATE TABLE overlaps (
          te_instance_key TEXT NOT NULL, variant_key TEXT NOT NULL
        );
        CREATE TABLE associations (
          tissue_key TEXT NOT NULL, variant_key TEXT NOT NULL, gene_id TEXT NOT NULL,
          start_distance INTEGER, af REAL, ma_samples INTEGER, ma_count INTEGER,
          pval_nominal REAL, slope REAL, slope_se REAL,
          pval_nominal_threshold REAL, min_pval_nominal REAL, pval_beta REAL
        );
        CREATE TABLE tissue_summary (
          tissue_key TEXT NOT NULL, te_name TEXT NOT NULL, gene_id TEXT NOT NULL,
          supporting_variant_count INTEGER NOT NULL,
          supporting_instance_count INTEGER NOT NULL, evidence_row_count INTEGER NOT NULL,
          minimum_pval_nominal REAL, maximum_abs_slope REAL,
          positive_slope_count INTEGER NOT NULL, negative_slope_count INTEGER NOT NULL,
          direction_class TEXT NOT NULL,
          PRIMARY KEY(tissue_key, te_name, gene_id)
        );
        CREATE INDEX idx_tissue_summary_te ON tissue_summary(te_name, tissue_key);
        CREATE INDEX idx_tissue_summary_gene ON tissue_summary(gene_id, tissue_key);
        CREATE TABLE cross_tissue_summary (
          te_name TEXT NOT NULL, gene_id TEXT NOT NULL, tissue_count INTEGER NOT NULL,
          supporting_variant_count INTEGER NOT NULL,
          supporting_instance_count INTEGER NOT NULL, evidence_row_count INTEGER NOT NULL,
          positive_tissue_count INTEGER NOT NULL, negative_tissue_count INTEGER NOT NULL,
          mixed_tissue_count INTEGER NOT NULL, zero_tissue_count INTEGER NOT NULL,
          minimum_pval_nominal REAL, maximum_abs_slope REAL,
          PRIMARY KEY(te_name, gene_id)
        );
        CREATE INDEX idx_cross_summary_gene ON cross_tissue_summary(gene_id);
        """
    )
    return connection


def _insert_te_instances(
    connection: sqlite3.Connection, frame: pd.DataFrame
) -> dict[str, str]:
    keys: dict[str, str] = {}
    key_owners: dict[str, str] = {}
    rows = []
    for row in frame.itertuples(index=False):
        instance_id = str(row.te_instance_id)
        key = sha256_text(instance_id)
        owner = key_owners.get(key)
        if owner is not None and owner != instance_id:
            raise ValueError(f"TE instance SHA-256 collision: {owner} and {instance_id}")
        key_owners[key] = instance_id
        keys[instance_id] = key
        rows.append(
            (
                key, instance_id, str(row.te_name), str(row.te_class),
                str(row.te_family), str(row.chrom), int(row.te_start),
                int(row.te_end), str(row.te_strand),
            )
        )
    connection.executemany(
        "INSERT INTO te_instances VALUES (?,?,?,?,?,?,?,?,?)", rows
    )
    return keys


def _load_genes(
    connection: sqlite3.Connection,
    archive: Path,
    members: dict[str, builder.TissueMembers],
) -> None:
    seen: dict[str, tuple[object, ...]] = {}
    with tarfile.open(archive, "r:*") as tar:
        for member in members.values():
            for gene_id, annotation in builder._read_egenes(tar, member).items():
                row = (
                    re.sub(r"\.[0-9]+$", "", gene_id), annotation["gene_name"],
                    annotation["biotype"], annotation["chrom"], annotation["start"],
                    annotation["end"], annotation["strand"],
                )
                prior = seen.get(gene_id)
                if prior is not None and prior != row:
                    raise ValueError(f"Conflicting cross-tissue Gene annotation: {gene_id}")
                seen[gene_id] = row
    connection.executemany(
        "INSERT INTO genes VALUES (?,?,?,?,?,?,?,?)",
        ((gene_id, *values) for gene_id, values in sorted(seen.items())),
    )


def _assert_consistent_duplicates(
    frame: pd.DataFrame,
    key_columns: list[str],
    label: str,
) -> pd.DataFrame:
    duplicates = frame.loc[frame.duplicated(key_columns, keep=False)]
    for key, group in duplicates.groupby(key_columns, dropna=False, sort=False):
        if len(group.drop(columns=key_columns).drop_duplicates()) > 1:
            raise ValueError(f"Conflicting duplicate {label}: {key}")
    return frame.drop_duplicates(key_columns, keep="first")


def _insert_tissue_evidence(
    connection: sqlite3.Connection,
    tissue: str,
    evidence_path: Path,
    variant_cache: dict[str, tuple[object, ...]],
    variant_key_owners: dict[str, str],
    te_keys: dict[str, str],
    overlap_seen: set[tuple[str, str]],
) -> None:
    evidence = pq.read_table(evidence_path).to_pandas()
    variants = _assert_consistent_duplicates(
        evidence[["variant_id", "chrom", "variant_start", "variant_end", "ref", "alt"]],
        ["variant_id"],
        "Variant attributes",
    ).copy()
    new_variant_rows = []
    variant_keys: dict[str, str] = {}
    for row in variants.itertuples(index=False):
        variant_id = str(row.variant_id)
        key = sha256_text(variant_id)
        attributes = (
            key, str(row.chrom), int(row.variant_start), int(row.variant_end),
            str(row.ref), str(row.alt),
        )
        prior = variant_cache.get(variant_id)
        if prior is not None and prior != attributes:
            raise ValueError(f"Conflicting Variant identity across tissues: {variant_id}")
        owner = variant_key_owners.get(key)
        if owner is not None and owner != variant_id:
            raise ValueError(f"Variant SHA-256 collision: {owner} and {variant_id}")
        variant_keys[variant_id] = key
        if prior is None:
            variant_cache[variant_id] = attributes
            variant_key_owners[key] = variant_id
            new_variant_rows.append((key, variant_id, *attributes[1:]))
    if new_variant_rows:
        connection.executemany(
            "INSERT INTO variants VALUES (?,?,?,?,?,?,?)", new_variant_rows
        )

    overlaps = evidence[["te_instance_id", "variant_id"]].drop_duplicates()
    new_overlaps = []
    for row in overlaps.itertuples(index=False):
        instance_id = str(row.te_instance_id)
        if instance_id not in te_keys:
            raise ValueError(f"Evidence references an unknown TE instance: {instance_id}")
        pair = (te_keys[instance_id], variant_keys[str(row.variant_id)])
        if pair not in overlap_seen:
            overlap_seen.add(pair)
            new_overlaps.append(pair)
    if new_overlaps:
        connection.executemany("INSERT INTO overlaps VALUES (?,?)", new_overlaps)

    association_columns = [
        "variant_id", "gene_id", "start_distance", "af", "ma_samples", "ma_count",
        "pval_nominal", "slope", "slope_se", "pval_nominal_threshold",
        "min_pval_nominal", "pval_beta",
    ]
    associations = _assert_consistent_duplicates(
        evidence[association_columns], ["variant_id", "gene_id"], "association attributes"
    )
    association_rows = [
        (
            tissue, variant_keys[str(row.variant_id)], str(row.gene_id),
            _nullable(row.start_distance), _nullable(row.af),
            _nullable(row.ma_samples), _nullable(row.ma_count),
            _nullable(row.pval_nominal), _nullable(row.slope),
            _nullable(row.slope_se), _nullable(row.pval_nominal_threshold),
            _nullable(row.min_pval_nominal), _nullable(row.pval_beta),
        )
        for row in associations.itertuples(index=False)
    ]
    connection.executemany(
        "INSERT INTO associations VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
        association_rows,
    )


def _nullable(value: object) -> object | None:
    if pd.isna(value):
        return None
    return value.item() if hasattr(value, "item") else value


def _build_summaries(connection: sqlite3.Connection) -> None:
    connection.execute("DELETE FROM cross_tissue_summary")
    connection.execute("DELETE FROM tissue_summary")
    connection.execute(
        """INSERT INTO tissue_summary
        SELECT a.tissue_key, ti.te_name, a.gene_id,
          COUNT(DISTINCT a.variant_key), COUNT(DISTINCT o.te_instance_key), COUNT(*),
          MIN(a.pval_nominal), MAX(ABS(a.slope)),
          SUM(CASE WHEN a.slope>0 THEN 1 ELSE 0 END),
          SUM(CASE WHEN a.slope<0 THEN 1 ELSE 0 END),
          CASE
            WHEN SUM(CASE WHEN a.slope>0 THEN 1 ELSE 0 END)>0
             AND SUM(CASE WHEN a.slope<0 THEN 1 ELSE 0 END)>0 THEN 'mixed'
            WHEN SUM(CASE WHEN a.slope>0 THEN 1 ELSE 0 END)>0 THEN 'positive_only'
            WHEN SUM(CASE WHEN a.slope<0 THEN 1 ELSE 0 END)>0 THEN 'negative_only'
            ELSE 'zero_only'
          END
        FROM associations a
        JOIN overlaps o ON o.variant_key=a.variant_key
        JOIN te_instances ti ON ti.te_instance_key=o.te_instance_key
        GROUP BY a.tissue_key, ti.te_name, a.gene_id"""
    )
    connection.execute(
        """CREATE TEMP TABLE cross_base AS
        SELECT ti.te_name, a.gene_id,
          COUNT(DISTINCT a.variant_key) AS supporting_variant_count,
          COUNT(DISTINCT o.te_instance_key) AS supporting_instance_count,
          COUNT(*) AS evidence_row_count,
          MIN(a.pval_nominal) AS minimum_pval_nominal,
          MAX(ABS(a.slope)) AS maximum_abs_slope
        FROM associations a
        JOIN overlaps o ON o.variant_key=a.variant_key
        JOIN te_instances ti ON ti.te_instance_key=o.te_instance_key
        GROUP BY ti.te_name, a.gene_id"""
    )
    connection.execute(
        """INSERT INTO cross_tissue_summary
        SELECT b.te_name, b.gene_id, COUNT(*),
          b.supporting_variant_count, b.supporting_instance_count, b.evidence_row_count,
          SUM(CASE WHEN s.direction_class='positive_only' THEN 1 ELSE 0 END),
          SUM(CASE WHEN s.direction_class='negative_only' THEN 1 ELSE 0 END),
          SUM(CASE WHEN s.direction_class='mixed' THEN 1 ELSE 0 END),
          SUM(CASE WHEN s.direction_class='zero_only' THEN 1 ELSE 0 END),
          b.minimum_pval_nominal, b.maximum_abs_slope
        FROM cross_base b
        JOIN tissue_summary s ON s.te_name=b.te_name AND s.gene_id=b.gene_id
        GROUP BY b.te_name, b.gene_id"""
    )


def _finalize_base_relations(connection: sqlite3.Connection) -> None:
    duplicate = connection.execute(
        """SELECT tissue_key,variant_key,gene_id,COUNT(*) FROM associations
        GROUP BY tissue_key,variant_key,gene_id HAVING COUNT(*)>1 LIMIT 1"""
    ).fetchone()
    if duplicate:
        raise ValueError(f"Duplicate normalized association key: {duplicate[:3]}")
    connection.execute(
        "CREATE UNIQUE INDEX IF NOT EXISTS uq_associations "
        "ON associations(tissue_key,variant_key,gene_id)"
    )
    connection.execute(
        "CREATE INDEX IF NOT EXISTS idx_associations_variant ON associations(variant_key)"
    )
    connection.execute(
        "CREATE INDEX IF NOT EXISTS idx_associations_gene_tissue "
        "ON associations(gene_id,tissue_key)"
    )
    # A terminated run can leave this table present but uncommitted/empty while
    # the original overlaps table is still authoritative.
    if connection.execute(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='overlaps_deduplicated'"
    ).fetchone():
        connection.execute("DROP TABLE overlaps_deduplicated")
    connection.execute(
        """CREATE TABLE overlaps_deduplicated (
        te_instance_key TEXT NOT NULL, variant_key TEXT NOT NULL,
        PRIMARY KEY(te_instance_key,variant_key)) WITHOUT ROWID"""
    )
    connection.execute(
        "INSERT INTO overlaps_deduplicated SELECT DISTINCT te_instance_key,variant_key FROM overlaps"
    )
    connection.execute("DROP TABLE overlaps")
    connection.execute("ALTER TABLE overlaps_deduplicated RENAME TO overlaps")
    connection.execute("CREATE INDEX IF NOT EXISTS idx_overlaps_variant ON overlaps(variant_key)")

    checks = {
        "TE instance": """SELECT COUNT(*) FROM overlaps o LEFT JOIN te_instances t
        ON t.te_instance_key=o.te_instance_key WHERE t.te_instance_key IS NULL""",
        "Variant overlap": """SELECT COUNT(*) FROM overlaps o LEFT JOIN variants v
        ON v.variant_key=o.variant_key WHERE v.variant_key IS NULL""",
        "association Tissue": """SELECT COUNT(*) FROM associations a LEFT JOIN tissues t
        ON t.tissue_key=a.tissue_key WHERE t.tissue_key IS NULL""",
        "association Variant": """SELECT COUNT(*) FROM associations a LEFT JOIN variants v
        ON v.variant_key=a.variant_key WHERE v.variant_key IS NULL""",
        "association Gene": """SELECT COUNT(*) FROM associations a LEFT JOIN genes g
        ON g.gene_id=a.gene_id WHERE g.gene_id IS NULL""",
    }
    for label, sql in checks.items():
        count = int(connection.execute(sql).fetchone()[0])
        if count:
            raise ValueError(f"Orphan {label} rows in consolidation staging: {count}")


def _open_deterministic_gzip(path: Path) -> tuple[io.TextIOWrapper, gzip.GzipFile, io.BufferedWriter]:
    raw = path.open("wb")
    compressed = gzip.GzipFile(filename="", mode="wb", fileobj=raw, mtime=0)
    text = io.TextIOWrapper(compressed, encoding="utf-8", newline="")
    return text, compressed, raw


def _format_tsv_value(value: object) -> object:
    if value is None:
        return r"\N"
    if isinstance(value, bytes):
        return value.hex()
    return value


def _export_query(
    connection: sqlite3.Connection,
    destination: Path,
    table_name: str,
    sql: str,
    params: tuple[object, ...] = (),
    *,
    part_rows: int,
    file_prefix: str = "part",
) -> list[dict[str, object]]:
    destination.mkdir(parents=True, exist_ok=True)
    columns = TABLE_COLUMNS[table_name]
    cursor = connection.execute(sql, params)
    files = []
    part_number = 0
    current_rows = 0
    text = compressed = raw = writer = path = None

    def close_part() -> None:
        nonlocal text, compressed, raw, writer, path, current_rows
        if text is None or path is None:
            return
        text.flush()
        text.close()
        if raw is not None:
            raw.close()
        files.append(
            {
                "path": path.relative_to(destination.parent).as_posix(),
                "sha256": core.sha256_file(path),
                "rows": current_rows,
                "size_bytes": path.stat().st_size,
            }
        )
        text = compressed = raw = writer = path = None

    for row in cursor:
        if text is None or current_rows >= part_rows:
            close_part()
            path = destination / f"{file_prefix}-{part_number:05d}.tsv.gz"
            text, compressed, raw = _open_deterministic_gzip(path)
            writer = csv.writer(
                text, delimiter="\t", quotechar='"', lineterminator="\n",
                quoting=csv.QUOTE_MINIMAL,
            )
            writer.writerow(columns)
            current_rows = 0
            part_number += 1
        writer.writerow([_format_tsv_value(value) for value in row])
        current_rows += 1
    close_part()
    if not files:
        path = destination / f"{file_prefix}-00000.tsv.gz"
        text, _, raw = _open_deterministic_gzip(path)
        csv.writer(text, delimiter="\t", lineterminator="\n").writerow(columns)
        text.close()
        raw.close()
        files.append(
            {
                "path": path.relative_to(destination.parent).as_posix(),
                "sha256": core.sha256_file(path),
                "rows": 0,
                "size_bytes": path.stat().st_size,
            }
        )
    return files


def _verify_export_file(root: Path, entry: dict[str, object], columns: list[str]) -> None:
    path = root / str(entry["path"])
    if core.sha256_file(path) != entry["sha256"]:
        raise ValueError(f"Export hash mismatch: {path}")
    with gzip.open(path, "rt", encoding="utf-8", newline="") as handle:
        reader = csv.reader(handle, delimiter="\t", quotechar='"')
        header = next(reader, None)
        if header != columns:
            raise ValueError(f"Export header mismatch: {path}")
        rows = sum(1 for _ in reader)
    if rows != int(entry["rows"]):
        raise ValueError(f"Export row count mismatch: {path}")


def consolidate(
    artifact_root: Path = DEFAULT_ARTIFACT_ROOT,
    archive: Path = DEFAULT_ARCHIVE,
    te_bed: Path = DEFAULT_TE_BED,
    browse_catalog: Path = DEFAULT_BROWSE_CATALOG,
    *,
    expected_count: int = 50,
    part_rows: int = DEFAULT_PART_ROWS,
    version_key: str = VERSION_KEY,
    resume_temp: Path | None = None,
) -> dict:
    started = time.monotonic()
    artifact_root = Path(artifact_root)
    all_manifest_path = artifact_root / "all_tissue_manifest.json"
    all_manifest = json.loads(all_manifest_path.read_text(encoding="utf-8"))
    if all_manifest.get("status") != "complete":
        raise ValueError("All-tissue manifest is not complete.")
    if int(all_manifest["counts"]["tissue_count"]) != expected_count:
        raise ValueError("All-tissue manifest tissue count differs from the expected count.")
    members = builder.discover_tissue_members(Path(archive), expected_count)
    approved_names = core.load_approved_te_names(Path(browse_catalog))
    te_intervals, _ = core.load_te_intervals(Path(te_bed), approved_names)

    destination = artifact_root / "mysql"
    owns_temp = resume_temp is None
    temp = (
        artifact_root / f".mysql.tmp-{os.getpid()}-{time.time_ns()}"
        if resume_temp is None else Path(resume_temp).resolve()
    )
    if owns_temp:
        temp.mkdir(parents=True)
    elif temp.parent != artifact_root.resolve() or not temp.name.startswith(".mysql.tmp-"):
        raise ValueError("--resume-temp must be a .mysql.tmp-* directory under artifact root.")
    staging = temp / "staging"
    if owns_temp:
        staging.mkdir()
    database_path = staging / "consolidation.sqlite"
    if not owns_temp and not database_path.is_file():
        raise FileNotFoundError(f"Resume staging database is missing: {database_path}")
    connection = _connect(database_path) if owns_temp else sqlite3.connect(database_path)
    try:
        if owns_temp:
            te_keys = _insert_te_instances(connection, te_intervals)
            _load_genes(connection, Path(archive), members)
            variant_cache: dict[str, tuple[object, ...]] = {}
            variant_key_owners: dict[str, str] = {}
            overlap_seen: set[tuple[str, str]] = set()
            for tissue, member in members.items():
                counts = all_manifest["tissues"][tissue]["counts"]
                connection.execute(
                    "INSERT INTO tissues VALUES (?,?,?,?,?)",
                    (
                        tissue, tissue.replace("_", " "), member.parquet_name,
                        int(counts["eqtl_row_count"]), int(counts["overlap_evidence_row_count"]),
                    ),
                )
                _insert_tissue_evidence(
                    connection,
                    tissue,
                    artifact_root / tissue / "te_variant_gene_overlaps.parquet",
                    variant_cache,
                    variant_key_owners,
                    te_keys,
                    overlap_seen,
                )
                connection.commit()
        else:
            staged_tissues = {
                row[0]: (int(row[1]), int(row[2]))
                for row in connection.execute(
                    "SELECT tissue_key,source_row_count,evidence_row_count FROM tissues"
                )
            }
            expected_tissues = {
                tissue: (
                    int(all_manifest["tissues"][tissue]["counts"]["eqtl_row_count"]),
                    int(all_manifest["tissues"][tissue]["counts"]["overlap_evidence_row_count"]),
                )
                for tissue in members
            }
            if staged_tissues != expected_tissues:
                raise ValueError("Resume staging Tissue inventory/counts do not match manifest.")
            for child in temp.iterdir():
                if child.name != "staging":
                    if child.is_dir():
                        shutil.rmtree(child)
                    else:
                        child.unlink()
        _finalize_base_relations(connection)
        _build_summaries(connection)
        connection.commit()

        queries = {
            "eqtl_tissues": "SELECT * FROM tissues ORDER BY tissue_key",
            "eqtl_te_instances": "SELECT * FROM te_instances ORDER BY te_instance_key",
            "eqtl_variants": "SELECT * FROM variants ORDER BY variant_key",
            "eqtl_genes": "SELECT * FROM genes ORDER BY gene_id",
            "eqtl_te_variant_overlaps": "SELECT * FROM overlaps ORDER BY te_instance_key,variant_key",
            "eqtl_variant_gene_tissue_associations": "SELECT * FROM associations ORDER BY tissue_key,variant_key,gene_id",
            "eqtl_te_gene_tissue_summary": "SELECT * FROM tissue_summary ORDER BY tissue_key,te_name,gene_id",
            "eqtl_te_gene_cross_tissue_summary": "SELECT * FROM cross_tissue_summary ORDER BY te_name,gene_id",
        }
        table_entries = {}
        for table_name in IMPORT_ORDER:
            table_destination = temp / table_name
            files = []
            if table_name in {
                "eqtl_variant_gene_tissue_associations",
                "eqtl_te_gene_tissue_summary",
            }:
                source_table = (
                    "associations" if table_name.endswith("associations") else "tissue_summary"
                )
                for tissue in sorted(members):
                    files.extend(
                        _export_query(
                            connection,
                            table_destination,
                            table_name,
                            f"SELECT * FROM {source_table} WHERE tissue_key=? ORDER BY tissue_key,"
                            + ("variant_key,gene_id" if source_table == "associations" else "te_name,gene_id"),
                            (tissue,),
                            part_rows=part_rows,
                            file_prefix=f"{tissue}.part",
                        )
                    )
            else:
                files = _export_query(
                    connection,
                    table_destination,
                    table_name,
                    queries[table_name],
                    part_rows=part_rows,
                )
            table_entries[table_name] = {
                "columns": TABLE_COLUMNS[table_name],
                "rows": sum(int(item["rows"]) for item in files),
                "files": files,
            }

        orphan_associations = connection.execute(
            """SELECT COUNT(*) FROM associations a LEFT JOIN overlaps o
            ON o.variant_key=a.variant_key WHERE o.variant_key IS NULL"""
        ).fetchone()[0]
        if orphan_associations:
            raise ValueError(f"Associations without a TE overlap: {orphan_associations}")
        connection.execute("PRAGMA wal_checkpoint(TRUNCATE)")
        connection.close()
        connection = None

        for table_name, entry in table_entries.items():
            for file_entry in entry["files"]:
                _verify_export_file(temp, file_entry, entry["columns"])
        manifest = {
            "artifact_schema_version": SCHEMA_VERSION,
            "version_key": version_key,
            "status": "complete",
            "input_hashes": all_manifest["input_hashes"],
            "all_tissue_manifest_sha256": core.sha256_file(all_manifest_path),
            "import_order": IMPORT_ORDER,
            "part_row_limit": part_rows,
            "tables": table_entries,
            "staging": {
                "path": "staging/consolidation.sqlite",
                "sha256": core.sha256_file(database_path),
                "size_bytes": database_path.stat().st_size,
            },
            "elapsed_seconds": round(time.monotonic() - started, 6),
        }
        (temp / "manifest.json").write_text(
            json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8"
        )
        builder._publish_tissue(temp, destination)
        return manifest
    except BaseException:
        if connection is not None:
            connection.close()
        if owns_temp and temp.exists():
            shutil.rmtree(temp, ignore_errors=True)
        raise


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--artifact-root", type=Path, default=DEFAULT_ARTIFACT_ROOT)
    parser.add_argument("--archive", type=Path, default=DEFAULT_ARCHIVE)
    parser.add_argument("--te-bed", type=Path, default=DEFAULT_TE_BED)
    parser.add_argument("--browse-catalog", type=Path, default=DEFAULT_BROWSE_CATALOG)
    parser.add_argument("--part-rows", type=int, default=DEFAULT_PART_ROWS)
    parser.add_argument("--version-key", default=VERSION_KEY)
    parser.add_argument(
        "--resume-temp", type=Path,
        help="Resume finalization/export from a preserved .mysql.tmp-* staging directory.",
    )
    args = parser.parse_args()
    if args.part_rows < 1 or args.part_rows > DEFAULT_PART_ROWS:
        parser.error(f"--part-rows must be between 1 and {DEFAULT_PART_ROWS}")
    manifest = consolidate(
        args.artifact_root,
        args.archive,
        args.te_bed,
        args.browse_catalog,
        part_rows=args.part_rows,
        version_key=args.version_key,
        resume_temp=args.resume_temp,
    )
    print(json.dumps({"status": manifest["status"], "tables": {
        name: entry["rows"] for name, entry in manifest["tables"].items()
    }}, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
