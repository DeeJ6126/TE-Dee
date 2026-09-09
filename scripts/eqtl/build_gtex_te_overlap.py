#!/usr/bin/env python
"""Build strict TE-instance overlaps for one GTEx v11 cis-eQTL tissue."""
from __future__ import annotations
import argparse
import json
import os
import platform
import sys
import tarfile
from datetime import datetime, timezone
from pathlib import Path
import pandas as pd

try:
    from scripts.eqtl import gtex_overlap_core as _core
except ModuleNotFoundError:
    import gtex_overlap_core as _core

# Backwards-compatible public API; shared logic has one implementation in core.
ParsedVariant = _core.ParsedVariant
PARQUET_COLUMNS = _core.PARQUET_COLUMNS
TE_COLUMNS = _core.TE_COLUMNS
VARIANT_COLUMNS = _core.VARIANT_COLUMNS
EVIDENCE_COLUMNS = _core.EVIDENCE_COLUMNS
ensure_pyarrow_compatible = _core.ensure_pyarrow_compatible
normalize_chromosome = _core.normalize_chromosome
parse_variant_id = _core.parse_variant_id
sha256_file = _core.sha256_file
load_approved_te_names = _core.load_approved_te_names
load_te_intervals = _core.load_te_intervals
parse_unique_variants = _core.parse_unique_variants
match_variant_intervals = _core.match_variant_intervals
index_te_intervals = _core.index_te_intervals
archive_member_name = _core.archive_member_name
inspect_parquet_member = _core.inspect_parquet_member
read_eqtl_member = _core.read_eqtl_member
build_evidence = _core.build_evidence

PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_ARCHIVE = PROJECT_ROOT / "data/eQTL/GTEx_Analysis_v11_eQTL.tar"
DEFAULT_TE_BED = PROJECT_ROOT / "data/JBrowse/repeats/hg38.rmsk.repeats.bed"
DEFAULT_BROWSE_CATALOG = PROJECT_ROOT / "data/processed/te_repbase_db_matched.json"
DEFAULT_OUTPUT_ROOT = PROJECT_ROOT / "data/eQTL/derived/gtex_v11_te_overlap"

def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build strict TE-overlapping GTEx v11 eQTL evidence for one tissue.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("--archive", type=Path, default=DEFAULT_ARCHIVE)
    parser.add_argument("--tissue", default="Liver")
    parser.add_argument("--te-bed", type=Path, default=DEFAULT_TE_BED)
    parser.add_argument("--browse-catalog", type=Path, default=DEFAULT_BROWSE_CATALOG)
    parser.add_argument(
        "--output-dir", type=Path, default=None,
        help="Exact output directory. Defaults to the versioned root plus tissue name.",
    )
    parser.add_argument(
        "--validate-inputs-only", action="store_true",
        help="Validate files, Parquet metadata, schema, and dependency compatibility without reading all rows.",
    )
    return parser.parse_args(argv)

def atomic_write_parquet(frame: pd.DataFrame, path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    frame.to_parquet(temporary, index=False, engine="pyarrow", compression="zstd")
    os.replace(temporary, path)

def atomic_write_text(content: str, path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(content, encoding="utf-8")
    os.replace(temporary, path)

def build_report(tissue: str, manifest: dict[str, object], evidence: pd.DataFrame) -> str:
    counts = manifest["counts"]
    top = evidence.groupby("te_name").agg(supporting_variants=("variant_id", "nunique"), supporting_instances=("te_instance_id", "nunique"), genes=("gene_id", "nunique"), evidence_rows=("variant_id", "size")).sort_values(["supporting_variants", "genes", "te_name"], ascending=[False, False, True]).head(20).reset_index()
    chromosome = evidence.groupby("chrom").agg(variants=("variant_id", "nunique"), te_instances=("te_instance_id", "nunique"), genes=("gene_id", "nunique"), evidence_rows=("variant_id", "size")).reset_index()
    keys = ("eqtl_row_count", "unique_variant_count", "parsed_unique_variant_count", "rejected_unique_variant_count", "approved_te_name_count", "approved_te_interval_count", "approved_te_names_with_intervals", "variant_te_overlap_pair_count", "overlap_evidence_row_count", "overlapping_unique_variant_count", "overlapping_unique_gene_count", "overlapping_unique_te_instance_count", "overlapping_unique_te_name_count", "te_gene_pair_count")
    lines = [f"# GTEx v11 {tissue} strict TE-overlap report", "", f"Generated: {manifest['generated_at_utc']}", "", "## Method", "", "Only GTEx b38 eVariants whose 0-based reference span intersects an approved Browse TE instance are retained.", "The overlap predicate is `variant_start < te_end AND variant_end > te_start`.", "No flanking window is used.", "", "## Counts", "", "| Metric | Count |", "| --- | ---: |"]
    for key in keys:
        lines.append(f"| `{key}` | {int(counts[key]):,} |")
    lines.extend(["", "## Top TE names", "", top.to_markdown(index=False) if not top.empty else "No overlaps were found.", "", "## Chromosome sanity summary", "", chromosome.to_markdown(index=False) if not chromosome.empty else "No overlaps were found.", "", "## Interpretation boundary", "", "These records show positional overlap between a GTEx eVariant and a reference TE instance, plus a statistical eVariant-Gene association in the selected tissue.", "They do not prove that the TE causes, activates, represses, or otherwise regulates the Gene. Linkage disequilibrium and nearby regulatory elements remain alternative explanations.", "", "## Provenance", "", f"- GTEx archive SHA-256: `{manifest['inputs']['archive_sha256']}`", f"- RepeatMasker BED SHA-256: `{manifest['inputs']['te_bed_sha256']}`", f"- Browse catalog SHA-256: `{manifest['inputs']['browse_catalog_sha256']}`", f"- PyArrow: `{manifest['software']['pyarrow']}`", ""])
    return "\n".join(lines)

def output_directory(args): return args.output_dir if args.output_dir is not None else DEFAULT_OUTPUT_ROOT / args.tissue

def validate_inputs(args: argparse.Namespace) -> dict[str, object]:
    ensure_pyarrow_compatible()
    approved_names = load_approved_te_names(args.browse_catalog)
    parquet = inspect_parquet_member(args.archive, args.tissue)
    if not args.te_bed.is_file():
        raise FileNotFoundError(f"RepeatMasker BED not found: {args.te_bed}")
    return {"status": "valid", "tissue": args.tissue, "approved_te_name_count": len(approved_names), "parquet": parquet, "pyarrow": _core.pyarrow.__version__, "output_dir": str(output_directory(args))}

def run_pipeline(args: argparse.Namespace) -> dict[str, object]:
    approved = load_approved_te_names(args.browse_catalog)
    intervals, te_stats = load_te_intervals(args.te_bed, approved)
    eqtl, parquet = read_eqtl_member(args.archive, args.tissue)
    variants, variant_stats = parse_unique_variants(eqtl)
    interval_index = index_te_intervals(intervals)
    evidence, summary, overlap_stats = build_evidence(
        eqtl, variants, interval_index, args.tissue
    )
    counts = {"eqtl_row_count": int(len(eqtl)), "approved_te_name_count": int(len(approved)), **te_stats, **variant_stats, **overlap_stats}
    outputs = {"evidence": "te_variant_gene_overlaps.parquet", "summary": "te_gene_summary.parquet", "manifest": "run_manifest.json", "report": "liver_overlap_report.md" if args.tissue == "Liver" else f"{args.tissue.lower()}_overlap_report.md"}
    manifest = {"analysis_version": "gtex_v11_strict_te_overlap_phase1", "generated_at_utc": datetime.now(timezone.utc).isoformat(), "tissue": args.tissue, "coordinate_contract": {"genome_build": "GRCh38/b38", "te_interval": "0-based half-open BED", "variant_interval": "0-based half-open REF span converted from 1-based GTEx variant_id", "overlap_predicate": "variant_start < te_end AND variant_end > te_start", "mapping_type": "strict_te_overlap"}, "inputs": {"archive": str(args.archive.resolve()), "archive_sha256": sha256_file(args.archive), "parquet_member": parquet, "te_bed": str(args.te_bed.resolve()), "te_bed_sha256": sha256_file(args.te_bed), "browse_catalog": str(args.browse_catalog.resolve()), "browse_catalog_sha256": sha256_file(args.browse_catalog)}, "software": {"python": platform.python_version(), "python_executable": sys.executable, "pandas": pd.__version__, "pyarrow": _core.pyarrow.__version__}, "counts": counts, "interpretation_limit": "Positional overlap plus eQTL association; not proof of TE-mediated regulation or causality.", "outputs": outputs}
    destination = output_directory(args)
    atomic_write_parquet(evidence, destination / outputs["evidence"])
    atomic_write_parquet(summary, destination / outputs["summary"])
    atomic_write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", destination / outputs["manifest"])
    atomic_write_text(build_report(args.tissue, manifest, evidence), destination / outputs["report"])
    return manifest

def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        result = validate_inputs(args) if args.validate_inputs_only else run_pipeline(args)
    except (FileNotFoundError, ValueError, RuntimeError, tarfile.TarError, OSError, json.JSONDecodeError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
