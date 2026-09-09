"""Reusable strict GTEx b38 eVariant-to-TE interval overlap logic."""

from __future__ import annotations

import hashlib
import heapq
import json
import re
import tarfile
from dataclasses import dataclass
from pathlib import Path

import pandas as pd

try:
    import pyarrow
    import pyarrow.parquet as pq
except ImportError as exc:  # pragma: no cover - handled by CLI validation
    pyarrow = None
    pq = None
    PYARROW_IMPORT_ERROR = exc
else:
    PYARROW_IMPORT_ERROR = None


PARQUET_COLUMNS = ["phenotype_id", "variant_id", "start_distance", "af", "ma_samples", "ma_count", "pval_nominal", "slope", "slope_se", "pval_nominal_threshold", "min_pval_nominal", "pval_beta"]
TE_COLUMNS = ["te_instance_id", "te_name", "te_class", "te_family", "chrom", "te_start", "te_end", "te_strand"]
VARIANT_COLUMNS = ["variant_id", "chrom", "variant_start", "variant_end", "ref", "alt"]
EVIDENCE_COLUMNS = ["tissue", "te_instance_id", "te_name", "te_class", "te_family", "chrom", "te_start", "te_end", "te_strand", "variant_id", "variant_start", "variant_end", "ref", "alt", "gene_id", "gene_id_base", "start_distance", "af", "ma_samples", "ma_count", "pval_nominal", "slope", "slope_se", "pval_nominal_threshold", "min_pval_nominal", "pval_beta", "mapping_type"]
VARIANT_ID_PATTERN = re.compile(r"^(?:chr)?(?P<chrom>[1-9]|1[0-9]|2[0-2]|X|Y)_(?P<position>[1-9][0-9]*)_(?P<ref>[ACGTNacgtn]+)_(?P<alt>[ACGTNacgtn]+)_b38$")


@dataclass(frozen=True)
class ParsedVariant:
    chrom: str
    start: int
    end: int
    ref: str
    alt: str


@dataclass(frozen=True)
class TEIntervalIndex:
    """Chromosome-partitioned TE intervals prepared once for repeated queries."""

    records_by_chrom: dict[str, tuple[dict[str, object], ...]]


def ensure_pyarrow_compatible() -> None:
    if pyarrow is None or pq is None:
        raise RuntimeError(f"PyArrow is required: {PYARROW_IMPORT_ERROR}")
    if pyarrow.__version__ == "19.0.0":
        raise RuntimeError("PyArrow 19.0.0 has a known Parquet regression that prevents GTEx v11 row decoding. Use PyArrow 19.0.1 or a later compatible version.")


def normalize_chromosome(value: str) -> str:
    token = str(value).strip()
    if token.lower().startswith("chr"):
        token = token[3:]
    if token.upper() in {"X", "Y"}:
        return "chr" + token.upper()
    if token.isdigit() and 1 <= int(token) <= 22:
        return "chr" + str(int(token))
    raise ValueError(f"Unsupported primary chromosome: {value}")


def parse_variant_id(variant_id: str) -> ParsedVariant:
    match = VARIANT_ID_PATTERN.fullmatch(str(variant_id).strip())
    if match is None:
        raise ValueError(f"Unsupported GTEx b38 variant ID: {variant_id}")
    start = int(match.group("position")) - 1
    ref = match.group("ref").upper()
    return ParsedVariant(normalize_chromosome(match.group("chrom")), start, start + len(ref), ref, match.group("alt").upper())


def sha256_file(path: Path, chunk_size: int = 8 * 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(chunk_size):
            digest.update(chunk)
    return digest.hexdigest()


def load_approved_te_names(path: Path) -> set[str]:
    if not path.is_file():
        raise FileNotFoundError(f"Browse catalog source not found: {path}")
    with path.open("r", encoding="utf-8") as handle:
        payload = json.load(handle)
    mapping = payload.get("db_to_repbase")
    if not isinstance(mapping, dict) or len(mapping) != 276:
        raise ValueError("Browse catalog source must contain exactly 276 db_to_repbase mappings.")
    names = {str(name).strip() for name in mapping if str(name).strip()}
    if len(names) != 276:
        raise ValueError("Browse catalog TE names must be unique and non-empty.")
    return names


def load_te_intervals(path: Path, approved_names: set[str]) -> tuple[pd.DataFrame, dict[str, int]]:
    if not path.is_file():
        raise FileNotFoundError(f"RepeatMasker BED not found: {path}")
    rows, total_rows, invalid_rows, non_primary_rows = [], 0, 0, 0
    with path.open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            total_rows += 1
            parts = line.rstrip("\r\n").split("\t")
            if len(parts) < 8:
                invalid_rows += 1
                continue
            te_name = parts[3].strip()
            if te_name not in approved_names:
                continue
            try:
                chrom, start, end = normalize_chromosome(parts[0]), int(parts[1]), int(parts[2])
            except ValueError:
                non_primary_rows += 1
                continue
            if start < 0 or end <= start:
                invalid_rows += 1
                continue
            rows.append({"te_instance_id": f"{te_name}@{chrom}:{start}-{end}:{'-' if parts[5] == '-' else '+'}", "te_name": te_name, "te_class": parts[6].strip(), "te_family": parts[7].strip(), "chrom": chrom, "te_start": start, "te_end": end, "te_strand": "-" if parts[5] == "-" else "+", "_bed_line": line_number})
    frame = pd.DataFrame(rows)
    if frame.empty:
        raise ValueError("No approved Browse TE intervals were found in the RepeatMasker BED.")
    duplicate_ids = frame["te_instance_id"].duplicated(keep=False)
    if duplicate_ids.any():
        frame.loc[duplicate_ids, "te_instance_id"] = frame.loc[duplicate_ids].apply(lambda row: f"{row['te_instance_id']}#bed{row['_bed_line']}", axis=1)
    frame = frame.drop(columns=["_bed_line"]).sort_values(["chrom", "te_start", "te_end", "te_name", "te_instance_id"], kind="stable").reset_index(drop=True)
    return frame, {"bed_rows_total": total_rows, "bed_rows_invalid": invalid_rows, "approved_non_primary_rows_excluded": non_primary_rows, "approved_te_interval_count": int(len(frame)), "approved_te_names_with_intervals": int(frame["te_name"].nunique()), "approved_te_names_without_intervals": int(len(approved_names - set(frame["te_name"])))}


def parse_unique_variants(eqtl: pd.DataFrame) -> tuple[pd.DataFrame, dict[str, int]]:
    rows, rejected = [], 0
    for variant_id in sorted(eqtl["variant_id"].dropna().astype(str).unique()):
        try:
            parsed = parse_variant_id(variant_id)
        except ValueError:
            rejected += 1
            continue
        rows.append({"variant_id": variant_id, "chrom": parsed.chrom, "variant_start": parsed.start, "variant_end": parsed.end, "ref": parsed.ref, "alt": parsed.alt})
    return pd.DataFrame(rows, columns=VARIANT_COLUMNS), {"unique_variant_count": int(eqtl["variant_id"].nunique(dropna=True)), "parsed_unique_variant_count": len(rows), "rejected_unique_variant_count": rejected}


def index_te_intervals(te_intervals: pd.DataFrame) -> TEIntervalIndex:
    records_by_chrom: dict[str, tuple[dict[str, object], ...]] = {}
    for chrom, frame in te_intervals.groupby("chrom", sort=True):
        ordered = frame.sort_values(
            ["te_start", "te_end", "te_name", "te_instance_id"], kind="stable"
        )
        records_by_chrom[str(chrom)] = tuple(ordered.to_dict("records"))
    return TEIntervalIndex(records_by_chrom=records_by_chrom)


def match_variant_intervals(
    variants: pd.DataFrame,
    te_intervals: pd.DataFrame | TEIntervalIndex,
) -> pd.DataFrame:
    output_columns = VARIANT_COLUMNS + [column for column in TE_COLUMNS if column != "chrom"]
    if variants.empty:
        return pd.DataFrame(columns=output_columns)
    interval_index = (
        te_intervals
        if isinstance(te_intervals, TEIntervalIndex)
        else index_te_intervals(te_intervals)
    )
    if not interval_index.records_by_chrom:
        return pd.DataFrame(columns=output_columns)
    matches = []
    for chrom in sorted(set(variants["chrom"]) & set(interval_index.records_by_chrom)):
        chrom_variants = variants.loc[variants["chrom"] == chrom].sort_values(
            ["variant_start", "variant_end", "variant_id"], kind="stable"
        )
        records = interval_index.records_by_chrom[chrom]
        active: dict[int, dict[str, object]] = {}
        endings: list[tuple[int, int]] = []
        next_interval = 0
        for variant in chrom_variants.to_dict("records"):
            start, end = int(variant["variant_start"]), int(variant["variant_end"])
            while next_interval < len(records) and int(records[next_interval]["te_start"]) < end:
                interval = records[next_interval]
                if int(interval["te_end"]) > start:
                    active[next_interval] = interval
                    heapq.heappush(endings, (int(interval["te_end"]), next_interval))
                next_interval += 1
            while endings and endings[0][0] <= start:
                _, interval_number = heapq.heappop(endings)
                active.pop(interval_number, None)
            matches.extend(
                {**variant, **interval}
                for interval in active.values()
                if int(interval["te_start"]) < end and int(interval["te_end"]) > start
            )
    if not matches:
        return pd.DataFrame(columns=output_columns)
    return pd.DataFrame(matches, columns=output_columns).sort_values(["chrom", "variant_start", "variant_end", "variant_id", "te_start", "te_end", "te_instance_id"], kind="stable").reset_index(drop=True)


def archive_member_name(tissue: str) -> str:
    normalized = str(tissue).strip()
    if not normalized or not re.fullmatch(r"[A-Za-z0-9_-]+", normalized):
        raise ValueError(f"Invalid GTEx tissue name: {tissue}")
    return f"GTEx_Analysis_v11_eQTL/{normalized}.v11.eQTLs.signif_pairs.parquet"


def inspect_parquet_member(archive: Path, tissue: str) -> dict[str, object]:
    ensure_pyarrow_compatible()
    if not archive.is_file():
        raise FileNotFoundError(f"GTEx archive not found: {archive}")
    member_name = archive_member_name(tissue)
    with tarfile.open(archive, "r") as tar:
        try:
            member = tar.getmember(member_name)
        except KeyError as exc:
            raise FileNotFoundError(f"GTEx tissue member not found: {member_name}") from exc
        handle = tar.extractfile(member)
        if handle is None:
            raise RuntimeError(f"Could not open GTEx tissue member: {member_name}")
        parquet = pq.ParquetFile(handle)
        missing = [column for column in PARQUET_COLUMNS if column not in parquet.schema_arrow.names]
        if missing:
            raise ValueError(f"GTEx Parquet is missing required columns: {missing}")
        return {"member_name": member_name, "member_size_bytes": int(member.size), "row_count": int(parquet.metadata.num_rows), "row_group_count": int(parquet.metadata.num_row_groups), "created_by": parquet.metadata.created_by, "schema": {field.name: str(field.type) for field in parquet.schema_arrow}}


def read_eqtl_member(archive: Path, tissue: str) -> tuple[pd.DataFrame, dict[str, object]]:
    metadata = inspect_parquet_member(archive, tissue)
    with tarfile.open(archive, "r") as tar:
        handle = tar.extractfile(str(metadata["member_name"]))
        if handle is None:
            raise RuntimeError(f"Could not open GTEx tissue member: {metadata['member_name']}")
        table = pq.ParquetFile(handle).read(columns=PARQUET_COLUMNS, use_threads=True)
    frame = table.to_pandas()
    if len(frame) != int(metadata["row_count"]):
        raise RuntimeError("Decoded GTEx row count does not match Parquet metadata.")
    return frame, metadata


def build_evidence_rows(
    eqtl: pd.DataFrame,
    variants: pd.DataFrame,
    te_intervals: pd.DataFrame | TEIntervalIndex,
    tissue: str,
) -> tuple[pd.DataFrame, int]:
    matches = match_variant_intervals(variants, te_intervals)
    evidence = eqtl.rename(columns={"phenotype_id": "gene_id"}).merge(matches, on="variant_id", how="inner", validate="many_to_many")
    evidence["tissue"] = tissue
    evidence["gene_id"] = evidence["gene_id"].astype(str)
    evidence["gene_id_base"] = evidence["gene_id"].str.replace(r"\.[0-9]+$", "", regex=True)
    evidence["mapping_type"] = "strict_te_overlap"
    return evidence[EVIDENCE_COLUMNS], int(len(matches))


def build_evidence(
    eqtl: pd.DataFrame,
    variants: pd.DataFrame,
    te_intervals: pd.DataFrame | TEIntervalIndex,
    tissue: str,
) -> tuple[pd.DataFrame, pd.DataFrame, dict[str, int]]:
    evidence, match_count = build_evidence_rows(eqtl, variants, te_intervals, tissue)
    evidence = evidence.drop_duplicates(
        ["tissue", "te_instance_id", "variant_id", "gene_id"], keep="first"
    ).sort_values(
        ["te_name", "te_instance_id", "gene_id", "variant_id"], kind="stable"
    ).reset_index(drop=True)
    summary_columns = ["tissue", "te_name", "gene_id", "gene_id_base", "supporting_variant_count", "supporting_instance_count", "evidence_row_count", "minimum_pval_nominal", "maximum_abs_slope", "positive_slope_count", "negative_slope_count", "mapping_type"]
    if evidence.empty:
        summary = pd.DataFrame(columns=summary_columns)
    else:
        summary = evidence.groupby(["tissue", "te_name", "gene_id", "gene_id_base"], sort=True).agg(supporting_variant_count=("variant_id", "nunique"), supporting_instance_count=("te_instance_id", "nunique"), evidence_row_count=("variant_id", "size"), minimum_pval_nominal=("pval_nominal", "min"), maximum_abs_slope=("slope", lambda values: float(values.abs().max())), positive_slope_count=("slope", lambda values: int((values > 0).sum())), negative_slope_count=("slope", lambda values: int((values < 0).sum()))).reset_index()
        summary["mapping_type"] = "strict_te_overlap"
    return evidence, summary, {"variant_te_overlap_pair_count": match_count, "overlap_evidence_row_count": int(len(evidence)), "overlapping_unique_variant_count": int(evidence["variant_id"].nunique()), "overlapping_unique_gene_count": int(evidence["gene_id"].nunique()), "overlapping_unique_te_instance_count": int(evidence["te_instance_id"].nunique()), "overlapping_unique_te_name_count": int(evidence["te_name"].nunique()), "te_gene_pair_count": int(len(summary))}
