"""Build strict GTEx v11 eQTL-to-TE overlaps for every tissue in an archive."""

from __future__ import annotations

import argparse
from collections import Counter
import gzip
import json
import os
import shutil
import tarfile
import time
import uuid
from dataclasses import dataclass
from pathlib import Path

import pandas as pd
import pyarrow as pa
import pyarrow.parquet as pq

try:
    import psutil
except ImportError:  # Optional: processing remains correct without memory telemetry.
    psutil = None

try:
    from scripts.eqtl import gtex_overlap_core as core
except ModuleNotFoundError:  # Direct execution from scripts/eqtl.
    import gtex_overlap_core as core


DEFAULT_OUTPUT_ROOT = Path("data/eQTL/derived/gtex_v11_strict_te_overlap_v1")
PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_ARCHIVE = PROJECT_ROOT / "data/eQTL/GTEx_Analysis_v11_eQTL.tar"
DEFAULT_TE_BED = PROJECT_ROOT / "data/JBrowse/repeats/hg38.rmsk.repeats.bed"
DEFAULT_BROWSE_CATALOG = PROJECT_ROOT / "data/processed/te_repbase_db_matched.json"
EGENE_COLUMNS = ["gene_id", "gene_name", "biotype", "gene_chr", "gene_start", "gene_end", "strand"]
OUTPUT_FILES = ("te_variant_gene_overlaps.parquet", "te_gene_summary.parquet", "manifest.json", "report.md")
MANIFEST_SCHEMA_VERSION = 1
GIB = 1024 ** 3
PREFLIGHT_TISSUES = ("Bladder", "Liver", "Nerve_Tibial")


@dataclass(frozen=True)
class TissueMembers:
    tissue: str
    egenes_name: str
    parquet_name: str
    egenes_size: int
    parquet_size: int


def _atomic_json(path: Path, value: dict) -> None:
    temp = path.with_name(f".{path.name}.tmp")
    temp.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    os.replace(temp, path)


def _atomic_text(path: Path, value: str) -> None:
    temp = path.with_name(f".{path.name}.tmp")
    temp.write_text(value, encoding="utf-8")
    os.replace(temp, path)


def _process_peak_rss_bytes() -> int | None:
    if psutil is None:
        return None
    memory = psutil.Process(os.getpid()).memory_info()
    return int(getattr(memory, "peak_wset", memory.rss))


def _directory_file_bytes(path: Path) -> int:
    return sum(item.stat().st_size for item in path.iterdir() if item.is_file())


def discover_tissue_members(archive: Path, expected_count: int = 50) -> dict[str, TissueMembers]:
    """Discover the exact one-to-one GTEx eGene/Parquet pairing in *archive*."""
    if not archive.is_file():
        raise FileNotFoundError(f"GTEx archive not found: {archive}")
    suffixes = (".v11.eGenes.txt.gz", ".v11.eQTLs.signif_pairs.parquet")
    found: dict[str, dict[str, tuple[str, int]]] = {}
    with tarfile.open(archive, "r:*") as tar:
        for member in tar.getmembers():
            if not member.isfile():
                continue
            for suffix in suffixes:
                if member.name.endswith(suffix):
                    tissue = Path(member.name).name[: -len(suffix)]
                    if not tissue:
                        raise ValueError(f"Invalid GTEx member name: {member.name}")
                    kind = "egenes" if suffix == suffixes[0] else "parquet"
                    bucket = found.setdefault(tissue, {})
                    if kind in bucket:
                        raise ValueError(f"Duplicate {kind} member for tissue {tissue}: {bucket[kind][0]} and {member.name}")
                    bucket[kind] = (member.name, int(member.size))
    unpaired = sorted(tissue for tissue, pair in found.items() if set(pair) != {"egenes", "parquet"})
    if unpaired:
        raise ValueError(f"Unpaired GTEx tissue members: {', '.join(unpaired)}")
    if len(found) != expected_count:
        raise ValueError(f"Expected exactly {expected_count} paired GTEx tissues, found {len(found)}")
    return {
        tissue: TissueMembers(tissue, pair["egenes"][0], pair["parquet"][0], pair["egenes"][1], pair["parquet"][1])
        for tissue, pair in sorted(found.items())
    }


def _read_egenes(tar: tarfile.TarFile, member: TissueMembers) -> dict[str, dict[str, object]]:
    handle = tar.extractfile(member.egenes_name)
    if handle is None:
        raise RuntimeError(f"Could not open {member.egenes_name}")
    with gzip.open(handle, "rt", encoding="utf-8", newline="") as stream:
        frame = pd.read_csv(stream, sep="\t", usecols=EGENE_COLUMNS, dtype=str)
    missing = [name for name in EGENE_COLUMNS if name not in frame.columns]
    if missing:
        raise ValueError(f"eGene member {member.egenes_name} is missing columns: {missing}")
    result: dict[str, dict[str, object]] = {}
    for row in frame.to_dict("records"):
        gene_id = str(row["gene_id"])
        try:
            start, end = int(row["gene_start"]) - 1, int(row["gene_end"])
            chrom = core.normalize_chromosome(str(row["gene_chr"]))
        except ValueError as exc:
            raise ValueError(f"Invalid eGene annotation for {gene_id} in {member.egenes_name}") from exc
        if start < 0 or end <= start:
            raise ValueError(f"Invalid 0-based half-open eGene interval for {gene_id} in {member.egenes_name}")
        strand = str(row["strand"])
        if strand not in {"+", "-"}:
            raise ValueError(f"Invalid eGene strand for {gene_id} in {member.egenes_name}: {strand}")
        annotation = {
            "gene_name": str(row["gene_name"]),
            "biotype": str(row["biotype"]),
            "chrom": chrom,
            "start": start,
            "end": end,
            "strand": strand,
        }
        existing = result.get(gene_id)
        if existing is not None and existing != annotation:
            raise ValueError(f"Conflicting duplicate versioned gene_id annotation: {gene_id}")
        result[gene_id] = annotation
    return result


def _member_parquet(tar: tarfile.TarFile, member: TissueMembers) -> pq.ParquetFile:
    handle = tar.extractfile(member.parquet_name)
    if handle is None:
        raise RuntimeError(f"Could not open {member.parquet_name}")
    parquet = pq.ParquetFile(handle)
    missing = [name for name in core.PARQUET_COLUMNS if name not in parquet.schema_arrow.names]
    if missing:
        raise ValueError(f"GTEx Parquet is missing required columns: {missing}")
    return parquet


def _output_metadata(directory: Path) -> dict[str, dict[str, object]]:
    result = {}
    # A manifest cannot contain a stable hash of itself.  Its hash is recorded in
    # run_state.json after it is finalized; this manifest records its sibling outputs.
    for name in ("te_variant_gene_overlaps.parquet", "te_gene_summary.parquet", "report.md"):
        path = directory / name
        result[name] = {"sha256": core.sha256_file(path), "size_bytes": path.stat().st_size}
        if name.endswith(".parquet"):
            table = pq.ParquetFile(path)
            result[name]["row_count"] = table.metadata.num_rows
            result[name]["schema"] = {field.name: str(field.type) for field in table.schema_arrow}
    return result


def _valid_completed_output(directory: Path, input_hashes: dict[str, str]) -> bool:
    manifest_path = directory / "manifest.json"
    if not all((directory / name).is_file() for name in OUTPUT_FILES):
        return False
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        if manifest.get("manifest_schema_version") != MANIFEST_SCHEMA_VERSION:
            return False
        if manifest.get("input_hashes") != input_hashes:
            return False
        expected = manifest["outputs"]
        actual = _output_metadata(directory)
        return expected == actual
    except (OSError, ValueError, KeyError, pa.ArrowException):
        return False


def _report(tissue: str, counts: dict[str, int], annotations: int) -> str:
    lines = [
        f"# GTEx v11 strict TE overlap: {tissue}",
        "",
        "Reference-span b38 interval intersection; boundary touching is excluded.",
        "",
        f"- eGene annotations validated: {annotations}",
    ]
    lines.extend(f"- {key}: {value}" for key, value in sorted(counts.items()))
    return "\n".join(lines) + "\n"


def _recover_interrupted_publications(output_root: Path) -> None:
    """Restore a backup left between the two directory renames, if any."""
    for backup in output_root.glob(".*.backup-*"):
        if not backup.is_dir():
            continue
        tissue = backup.name[1:].split(".backup-", 1)[0]
        destination = output_root / tissue
        if destination.exists():
            shutil.rmtree(backup)
        else:
            os.replace(backup, destination)


def _publish_tissue(temp: Path, destination: Path, publish_failure_injector=None) -> None:
    """Publish a verified directory with rollback when the second rename fails."""
    backup = destination.parent / f".{destination.name}.backup-{uuid.uuid4().hex}"
    moved_old = False
    try:
        if destination.exists():
            os.replace(destination, backup)
            moved_old = True
        if publish_failure_injector:
            publish_failure_injector(destination.name)
        os.replace(temp, destination)
    except Exception:
        if moved_old and not destination.exists() and backup.exists():
            os.replace(backup, destination)
        raise
    else:
        if moved_old:
            shutil.rmtree(backup)


def _write_tissue(
    archive: Path,
    member: TissueMembers,
    te_intervals: core.TEIntervalIndex,
    input_hashes: dict[str, str],
    destination: Path,
    annotations_seen: dict[str, dict[str, object]],
    failure_injector=None,
    publish_failure_injector=None,
) -> dict:
    temp = destination.parent / f".{member.tissue}.tmp-{os.getpid()}-{time.time_ns()}"
    temp.mkdir(parents=True)
    started = time.monotonic()
    try:
        all_evidence: list[pd.DataFrame] = []
        with tarfile.open(archive, "r:*") as tar:
            annotations = _read_egenes(tar, member)
            for gene_id, annotation in annotations.items():
                prior = annotations_seen.get(gene_id)
                if prior is not None and prior != annotation:
                    raise ValueError(f"Conflicting versioned gene_id annotation between tissues: {gene_id}")
                annotations_seen[gene_id] = annotation
            parquet = _member_parquet(tar, member)
            for row_group in range(parquet.metadata.num_row_groups):
                frame = parquet.read_row_group(row_group, columns=core.PARQUET_COLUMNS, use_threads=True).to_pandas()
                variants, _ = core.parse_unique_variants(frame)
                evidence, _ = core.build_evidence_rows(
                    frame, variants, te_intervals, member.tissue
                )
                if not evidence.empty:
                    all_evidence.append(evidence)
        evidence = (
            pd.concat(all_evidence, ignore_index=True)
            if all_evidence
            else pd.DataFrame(columns=core.EVIDENCE_COLUMNS)
        )
        primary_key = ["tissue", "te_instance_id", "variant_id", "gene_id"]
        duplicate_rows = evidence.loc[
            evidence.duplicated(primary_key, keep=False)
        ]
        for key, group in duplicate_rows.groupby(primary_key, dropna=False, sort=False):
            if len(group.drop(columns=primary_key).drop_duplicates()) > 1:
                raise ValueError(
                    f"Conflicting duplicate overlap evidence for primary key: {key}"
                )
        evidence = (
            evidence.drop_duplicates(primary_key)
            .sort_values(["te_name", "te_instance_id", "gene_id", "variant_id"], kind="stable")
            .reset_index(drop=True)
        )
        missing_annotations = sorted(set(evidence["gene_id"]) - set(annotations))
        if missing_annotations:
            preview = ", ".join(missing_annotations[:5])
            raise ValueError(
                f"Overlap evidence references genes absent from {member.egenes_name}: {preview}"
            )
        # Reuse the established summary implementation without changing its predicate.
        if evidence.empty:
            summary = pd.DataFrame(
                columns=[
                    "tissue", "te_name", "gene_id", "gene_id_base",
                    "supporting_variant_count", "supporting_instance_count",
                    "evidence_row_count", "minimum_pval_nominal",
                    "maximum_abs_slope", "positive_slope_count",
                    "negative_slope_count", "mapping_type",
                ]
            )
        else:
            summary = evidence.groupby(
                ["tissue", "te_name", "gene_id", "gene_id_base"], sort=True
            ).agg(
                supporting_variant_count=("variant_id", "nunique"),
                supporting_instance_count=("te_instance_id", "nunique"),
                evidence_row_count=("variant_id", "size"),
                minimum_pval_nominal=("pval_nominal", "min"),
                maximum_abs_slope=("slope", lambda values: float(values.abs().max())),
                positive_slope_count=("slope", lambda values: int((values > 0).sum())),
                negative_slope_count=("slope", lambda values: int((values < 0).sum())),
            ).reset_index()
            summary["mapping_type"] = "strict_te_overlap"
        variant_te_pairs = evidence[["variant_id", "te_instance_id"]].drop_duplicates()
        counts = {
            "eqtl_row_count": int(parquet.metadata.num_rows),
            "parquet_row_group_count": int(parquet.metadata.num_row_groups),
            "overlap_evidence_row_count": int(len(evidence)),
            "te_gene_pair_count": int(len(summary)),
            "overlapping_unique_variant_count": int(evidence["variant_id"].nunique()),
            "overlapping_unique_gene_count": int(evidence["gene_id"].nunique()),
            "overlapping_unique_te_instance_count": int(evidence["te_instance_id"].nunique()),
            "overlapping_unique_te_name_count": int(evidence["te_name"].nunique()),
            "variant_te_overlap_pair_count": int(len(variant_te_pairs)),
        }
        pq.write_table(
            pa.Table.from_pandas(evidence, preserve_index=False),
            temp / "te_variant_gene_overlaps.parquet",
            compression="zstd",
        )
        pq.write_table(
            pa.Table.from_pandas(summary, preserve_index=False),
            temp / "te_gene_summary.parquet",
            compression="zstd",
        )
        (temp / "report.md").write_text(_report(member.tissue, counts, len(annotations)), encoding="utf-8")
        if failure_injector:
            failure_injector(member.tissue)
        manifest = {
            "manifest_schema_version": MANIFEST_SCHEMA_VERSION,
            "tissue": member.tissue,
            "input_hashes": input_hashes,
            "members": {
                "egenes": {"name": member.egenes_name, "size_bytes": member.egenes_size},
                "parquet": {"name": member.parquet_name, "size_bytes": member.parquet_size},
            },
            "counts": counts,
            "gene_annotation_count": len(annotations),
            "annotation_coordinate_contract": "0-based-half-open",
            "elapsed_seconds": round(time.monotonic() - started, 6),
            "process_peak_rss_bytes": _process_peak_rss_bytes(),
        }
        _atomic_json(temp / "manifest.json", manifest)
        manifest["outputs"] = _output_metadata(temp)
        _atomic_json(temp / "manifest.json", manifest)
        _publish_tissue(temp, destination, publish_failure_injector)
        return manifest
    except Exception:
        shutil.rmtree(temp, ignore_errors=True)
        raise


def run_build(
    archive: Path,
    te_bed: Path,
    browse_catalog: Path,
    output_root: Path,
    *,
    resume: bool = False,
    tissues: set[str] | None = None,
    force_tissues: set[str] | None = None,
    validate_inputs_only: bool = False,
    continue_on_error: bool = False,
    expected_count: int = 50,
    failure_injector=None,
    publish_failure_injector=None,
) -> dict:
    core.ensure_pyarrow_compatible()
    archive, te_bed, browse_catalog, output_root = map(Path, (archive, te_bed, browse_catalog, output_root))
    members = discover_tissue_members(archive, expected_count)
    selected = sorted(tissues or set(members))
    unknown = set(selected) - set(members)
    if unknown:
        raise ValueError(f"Unknown GTEx tissues requested: {', '.join(sorted(unknown))}")
    hashes = {
        "archive_sha256": core.sha256_file(archive),
        "te_bed_sha256": core.sha256_file(te_bed),
        "browse_catalog_sha256": core.sha256_file(browse_catalog),
    }
    approved = core.load_approved_te_names(browse_catalog)
    te_intervals, inventory = core.load_te_intervals(te_bed, approved)
    te_interval_index = core.index_te_intervals(te_intervals)
    # This also verifies every Parquet schema without decoding its data.
    with tarfile.open(archive, "r:*") as tar:
        for member in members.values():
            _member_parquet(tar, member)
    if validate_inputs_only:
        return {"validated": selected, "input_hashes": hashes, "te_inventory": inventory}
    # Gene annotations are a shared offline contract, so detect cross-tissue
    # conflicts before creating any final tissue directory.
    annotations_seen: dict[str, dict[str, object]] = {}
    with tarfile.open(archive, "r:*") as tar:
        for member in members.values():
            for gene_id, annotation in _read_egenes(tar, member).items():
                prior = annotations_seen.get(gene_id)
                if prior is not None and prior != annotation:
                    raise ValueError(f"Conflicting versioned gene_id annotation between tissues: {gene_id}")
                annotations_seen[gene_id] = annotation
    output_root.mkdir(parents=True, exist_ok=True)
    _recover_interrupted_publications(output_root)
    state_path = output_root / "run_state.json"
    state = {"input_hashes": hashes, "tissues": {}}
    completed, skipped, failures = [], [], {}
    for tissue in selected:
        destination = output_root / tissue
        force = tissue in (force_tissues or set())
        if resume and not force and _valid_completed_output(destination, hashes):
            skipped.append(tissue)
            existing = json.loads((destination / "manifest.json").read_text(encoding="utf-8"))
            outputs = dict(existing["outputs"])
            outputs["manifest.json"] = {
                "sha256": core.sha256_file(destination / "manifest.json"),
                "size_bytes": (destination / "manifest.json").stat().st_size,
            }
            state["tissues"][tissue] = {
                "status": "skipped",
                "members": existing["members"],
                "outputs": outputs,
                "counts": existing["counts"],
                "elapsed_seconds": existing["elapsed_seconds"],
                "process_peak_rss_bytes": existing["process_peak_rss_bytes"],
            }
        else:
            if destination.exists() and not force and not resume:
                raise FileExistsError(f"Final tissue output exists; use --resume or --force-tissue {tissue}: {destination}")
            tissue_started = time.monotonic()
            try:
                manifest = _write_tissue(
                    archive,
                    members[tissue],
                    te_interval_index,
                    hashes,
                    destination,
                    annotations_seen,
                    failure_injector,
                    publish_failure_injector,
                )
                completed.append(tissue)
                outputs = dict(manifest["outputs"])
                outputs["manifest.json"] = {
                    "sha256": core.sha256_file(destination / "manifest.json"),
                    "size_bytes": (destination / "manifest.json").stat().st_size,
                }
                state["tissues"][tissue] = {
                    "status": "completed",
                    "members": manifest["members"],
                    "outputs": outputs,
                    "counts": manifest["counts"],
                    "elapsed_seconds": manifest["elapsed_seconds"],
                    "process_peak_rss_bytes": manifest["process_peak_rss_bytes"],
                }
            except Exception as exc:
                failures[tissue] = str(exc)
                state["tissues"][tissue] = {
                    "status": "failed",
                    "error": str(exc),
                    "members": members[tissue].__dict__,
                    "elapsed_seconds": round(time.monotonic() - tissue_started, 6),
                    "process_peak_rss_bytes": _process_peak_rss_bytes(),
                }
                _atomic_json(state_path, state)
                if not continue_on_error:
                    raise
        _atomic_json(state_path, state)
    return {"completed": completed, "skipped": skipped, "failures": failures, "input_hashes": hashes}


def build_preflight_report(
    archive: Path,
    output_root: Path,
    *,
    expected_count: int = 50,
    representative_tissues: tuple[str, ...] = PREFLIGHT_TISSUES,
) -> dict:
    members = discover_tissue_members(Path(archive), expected_count)
    missing = [name for name in representative_tissues if name not in members]
    if missing:
        raise ValueError(f"Unknown preflight tissues: {', '.join(missing)}")

    source_rows_by_tissue: dict[str, int] = {}
    with tarfile.open(archive, "r:*") as tar:
        for tissue, member in members.items():
            source_rows_by_tissue[tissue] = int(
                _member_parquet(tar, member).metadata.num_rows
            )

    measurements = []
    measured_source_rows = 0
    measured_artifact_bytes = 0
    conservative_rows_per_second = None
    expected_hashes = None
    for tissue in representative_tissues:
        directory = Path(output_root) / tissue
        manifest_path = directory / "manifest.json"
        if not manifest_path.is_file():
            raise FileNotFoundError(f"Missing representative output: {manifest_path}")
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        if manifest.get("manifest_schema_version") != MANIFEST_SCHEMA_VERSION:
            raise ValueError(f"Unsupported representative manifest: {manifest_path}")
        if expected_hashes is None:
            expected_hashes = manifest["input_hashes"]
        elif manifest["input_hashes"] != expected_hashes:
            raise ValueError("Representative tissues were built from different input identities.")
        source_rows = int(manifest["counts"]["eqtl_row_count"])
        if source_rows != source_rows_by_tissue[tissue]:
            raise ValueError(f"Source row count drift for representative tissue {tissue}.")
        artifact_bytes = _directory_file_bytes(directory)
        elapsed_seconds = float(manifest["elapsed_seconds"])
        rows_per_second = source_rows / elapsed_seconds
        measured_source_rows += source_rows
        measured_artifact_bytes += artifact_bytes
        conservative_rows_per_second = (
            rows_per_second
            if conservative_rows_per_second is None
            else min(conservative_rows_per_second, rows_per_second)
        )
        measurements.append(
            {
                "tissue": tissue,
                "source_rows": source_rows,
                "evidence_rows": int(manifest["counts"]["overlap_evidence_row_count"]),
                "artifact_bytes": artifact_bytes,
                "elapsed_seconds": elapsed_seconds,
                "rows_per_second": rows_per_second,
                "process_peak_rss_bytes": manifest["process_peak_rss_bytes"],
            }
        )

    total_source_rows = sum(source_rows_by_tissue.values())
    parquet_bytes_per_source_row = measured_artifact_bytes / measured_source_rows
    projected_parquet_bytes = int(total_source_rows * parquet_bytes_per_source_row)
    # Until consolidation is run, these explicit conservative factors reserve
    # room for compressed TSVs, indexed SQLite staging, and InnoDB data/indexes.
    projected_tsv_gzip_bytes = projected_parquet_bytes * 2
    projected_sqlite_bytes = projected_parquet_bytes * 8
    projected_mysql_bytes = projected_parquet_bytes * 18
    projected_remaining_artifact_bytes = (
        projected_parquet_bytes + projected_tsv_gzip_bytes + projected_sqlite_bytes
    )
    required_free_bytes = max(
        50 * GIB,
        3 * projected_remaining_artifact_bytes + projected_mysql_bytes,
    )
    free_bytes = shutil.disk_usage(Path(output_root).resolve().anchor).free
    projected_processing_seconds = int(
        total_source_rows / float(conservative_rows_per_second)
    )
    report = {
        "status": "passed" if free_bytes >= required_free_bytes else "failed",
        "input_hashes": expected_hashes,
        "tissue_count": len(members),
        "total_source_rows": total_source_rows,
        "representative_measurements": measurements,
        "projection": {
            "basis": "measured representative Parquet artifact bytes per source row",
            "parquet_bytes_per_source_row": parquet_bytes_per_source_row,
            "projected_parquet_bytes": projected_parquet_bytes,
            "projected_tsv_gzip_bytes": projected_tsv_gzip_bytes,
            "projected_sqlite_bytes": projected_sqlite_bytes,
            "projected_mysql_data_and_index_bytes": projected_mysql_bytes,
            "conservative_processing_seconds": projected_processing_seconds,
            "multipliers": {"tsv_gzip": 2, "sqlite": 8, "mysql_data_and_index": 18},
        },
        "disk_gate": {
            "free_bytes": free_bytes,
            "required_free_bytes": required_free_bytes,
            "rule": "max(50 GiB, 3 * projected remaining artifacts + projected MySQL)",
        },
    }
    output_root = Path(output_root)
    _atomic_json(output_root / "preflight_report.json", report)
    lines = [
        "# GTEx v11 all-tissue preflight",
        "",
        f"- Status: **{report['status']}**",
        f"- Tissues: {len(members)}",
        f"- Source associations: {total_source_rows:,}",
        f"- Conservative processing projection: {projected_processing_seconds / 60:.1f} minutes",
        f"- Free disk: {free_bytes / GIB:.1f} GiB",
        f"- Required reserve: {required_free_bytes / GIB:.1f} GiB",
        "",
        "The SQLite and MySQL projections use explicit conservative multipliers until actual consolidation and import sizes are measured.",
        "",
    ]
    _atomic_text(output_root / "preflight_report.md", "\n".join(lines))
    return report


def finalize_all_tissue_run(
    archive: Path,
    te_bed: Path,
    browse_catalog: Path,
    output_root: Path,
    *,
    expected_count: int = 50,
) -> dict:
    archive = Path(archive)
    te_bed = Path(te_bed)
    browse_catalog = Path(browse_catalog)
    output_root = Path(output_root)
    members = discover_tissue_members(archive, expected_count)
    input_hashes = {
        "archive_sha256": core.sha256_file(archive),
        "te_bed_sha256": core.sha256_file(te_bed),
        "browse_catalog_sha256": core.sha256_file(browse_catalog),
    }
    approved_names = core.load_approved_te_names(browse_catalog)
    te_intervals, inventory = core.load_te_intervals(te_bed, approved_names)
    interval_counts = Counter(te_intervals["te_name"].astype(str))
    evidence_counts: Counter[str] = Counter()
    tissue_entries = {}
    total_source_rows = 0
    total_evidence_rows = 0
    total_summary_rows = 0
    total_elapsed_seconds = 0.0
    for tissue in sorted(members):
        directory = output_root / tissue
        if not _valid_completed_output(directory, input_hashes):
            raise ValueError(f"Tissue output is missing or invalid: {tissue}")
        manifest_path = directory / "manifest.json"
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        counts = manifest["counts"]
        total_source_rows += int(counts["eqtl_row_count"])
        total_evidence_rows += int(counts["overlap_evidence_row_count"])
        total_summary_rows += int(counts["te_gene_pair_count"])
        total_elapsed_seconds += float(manifest["elapsed_seconds"])
        evidence_table = pq.read_table(
            directory / "te_variant_gene_overlaps.parquet", columns=["te_name"]
        )
        evidence_counts.update(evidence_table.column("te_name").to_pylist())
        tissue_entries[tissue] = {
            "manifest_sha256": core.sha256_file(manifest_path),
            "counts": counts,
            "elapsed_seconds": manifest["elapsed_seconds"],
            "process_peak_rss_bytes": manifest["process_peak_rss_bytes"],
        }

    audit_lines = ["te_name\thas_hg38_instance\tinstance_count\tevidence_count"]
    for te_name in sorted(approved_names):
        instance_count = int(interval_counts[te_name])
        audit_lines.append(
            f"{te_name}\t{1 if instance_count else 0}\t{instance_count}\t{int(evidence_counts[te_name])}"
        )
    _atomic_text(output_root / "missing_browse_te.tsv", "\n".join(audit_lines) + "\n")

    preflight_path = output_root / "preflight_report.json"
    if not preflight_path.is_file():
        raise FileNotFoundError(f"Missing preflight report: {preflight_path}")
    preflight = json.loads(preflight_path.read_text(encoding="utf-8"))
    if preflight.get("status") != "passed":
        raise ValueError("Preflight disk gate did not pass.")
    manifest = {
        "analysis_version": "gtex_v11_strict_te_overlap_v1",
        "manifest_schema_version": MANIFEST_SCHEMA_VERSION,
        "status": "complete",
        "coordinate_contract": {
            "genome_build": "GRCh38/b38",
            "te_interval": "0-based half-open BED",
            "variant_interval": "0-based half-open REF span converted from 1-based GTEx variant_id",
            "overlap_predicate": "variant_start < te_end AND variant_end > te_start",
            "mapping_type": "strict_te_overlap",
        },
        "input_hashes": input_hashes,
        "counts": {
            "tissue_count": len(tissue_entries),
            "source_association_count": total_source_rows,
            "overlap_evidence_row_count": total_evidence_rows,
            "te_gene_tissue_summary_count": total_summary_rows,
            **inventory,
        },
        "processing": {
            "summed_tissue_elapsed_seconds": total_elapsed_seconds,
            "preflight_report_sha256": core.sha256_file(preflight_path),
        },
        "outputs": {
            "missing_browse_te": {
                "path": "missing_browse_te.tsv",
                "sha256": core.sha256_file(output_root / "missing_browse_te.tsv"),
                "row_count": len(approved_names),
            }
        },
        "tissues": tissue_entries,
        "interpretation_limit": "Positional TE-Variant overlap plus tissue eQTL association; not proof of TE-mediated regulation or causality.",
    }
    _atomic_json(output_root / "all_tissue_manifest.json", manifest)
    report_lines = [
        "# GTEx v11 all-tissue strict TE-overlap report",
        "",
        f"- Tissues: {len(tissue_entries)}",
        f"- Source associations: {total_source_rows:,}",
        f"- TE-Variant-Gene evidence rows: {total_evidence_rows:,}",
        f"- TE-Gene tissue summaries: {total_summary_rows:,}",
        f"- Browse TE names: {len(approved_names)}",
        f"- Browse TE names with hg38 instances: {inventory['approved_te_names_with_intervals']}",
        f"- Browse TE names without hg38 instances: {inventory['approved_te_names_without_intervals']}",
        f"- Summed tissue processing time: {total_elapsed_seconds / 60:.1f} minutes",
        "",
        "Only b38 Variant reference spans intersecting approved hg38 TE intervals are retained; boundary touching alone is excluded.",
        "These data are positional/statistical evidence and do not establish TE-mediated causality.",
        "",
    ]
    _atomic_text(output_root / "all_tissue_report.md", "\n".join(report_lines))
    return manifest


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--archive", type=Path, default=DEFAULT_ARCHIVE)
    parser.add_argument("--te-bed", type=Path, default=DEFAULT_TE_BED)
    parser.add_argument("--browse-catalog", type=Path, default=DEFAULT_BROWSE_CATALOG)
    parser.add_argument("--output-root", type=Path, default=PROJECT_ROOT / DEFAULT_OUTPUT_ROOT)
    parser.add_argument("--resume", action="store_true")
    parser.add_argument("--tissues", action="append", default=[], help="Comma-separated tissue names; may be repeated.")
    parser.add_argument("--force-tissue", action="append", default=[])
    parser.add_argument("--validate-inputs-only", action="store_true")
    parser.add_argument("--continue-on-error", action="store_true")
    parser.add_argument("--preflight-report-only", action="store_true")
    parser.add_argument("--finalize-only", action="store_true")
    args = parser.parse_args()
    if args.preflight_report_only:
        report = build_preflight_report(args.archive, args.output_root)
        print(json.dumps(report, indent=2, sort_keys=True))
        return
    if args.finalize_only:
        manifest = finalize_all_tissue_run(
            args.archive,
            args.te_bed,
            args.browse_catalog,
            args.output_root,
        )
        print(json.dumps(manifest["counts"], indent=2, sort_keys=True))
        return
    tissues = {item.strip() for value in args.tissues for item in value.split(",") if item.strip()}
    result = run_build(
        args.archive,
        args.te_bed,
        args.browse_catalog,
        args.output_root,
        resume=args.resume,
        tissues=tissues or None,
        force_tissues=set(args.force_tissue),
        validate_inputs_only=args.validate_inputs_only,
        continue_on_error=args.continue_on_error,
    )
    print(json.dumps(result, indent=2, sort_keys=True))


if __name__ == "__main__":
    main()
