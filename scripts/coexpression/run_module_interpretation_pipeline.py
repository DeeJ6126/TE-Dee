#!/usr/bin/env python
"""Run module detection, enrichment, and TE functional-context joining."""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_ANALYSIS_ROOT = PROJECT_ROOT / "data/coexpression/analysis/v1"
DEFAULT_MODULE_ROOT = PROJECT_ROOT / "data/coexpression/modules"
DEFAULT_INTERPRETATION_ROOT = PROJECT_ROOT / "data/coexpression/interpretation"
DEFAULT_DATASETS = ["cancer_cell_line", "normal_cell_line", "normal_tissue"]


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run the co-expression module interpretation pipeline end to end.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("--filtered-network-dir", type=Path, required=True, help="Filtered network directory.")
    parser.add_argument("--run-label", required=True, help="Output label, e.g. v1_abs0.4_fdr0.05_res1.8.")
    parser.add_argument("--resolution", type=float, required=True, help="Louvain resolution.")
    parser.add_argument("--module-root", type=Path, default=DEFAULT_MODULE_ROOT)
    parser.add_argument("--interpretation-root", type=Path, default=DEFAULT_INTERPRETATION_ROOT)
    parser.add_argument("--datasets", nargs="+", choices=DEFAULT_DATASETS, default=DEFAULT_DATASETS)
    parser.add_argument("--min-genes", type=int, default=20)
    parser.add_argument("--sleep-seconds", type=float, default=0.5)
    parser.add_argument("--skip-module-detection", action="store_true")
    parser.add_argument("--skip-enrichment", action="store_true")
    parser.add_argument("--skip-te-context", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)
    if args.resolution <= 0:
        parser.error("--resolution must be positive")
    return args


def script_path(name: str) -> Path:
    return PROJECT_ROOT / "scripts/coexpression" / name


def run_command(cmd: list[str], dry_run: bool) -> None:
    printable = " ".join(str(part) for part in cmd)
    print(f"[pipeline] {printable}")
    if dry_run:
        return
    subprocess.run(cmd, cwd=PROJECT_ROOT, check=True)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    module_dir = args.module_root / args.run_label
    interpretation_dir = args.interpretation_root / args.run_label

    if not args.filtered_network_dir.exists():
        raise FileNotFoundError(f"Filtered network directory not found: {args.filtered_network_dir}")

    datasets = [str(x) for x in args.datasets]

    if not args.skip_module_detection:
        run_command(
            [
                sys.executable,
                str(script_path("detect_coexpression_modules.py")),
                "--input-dir",
                str(args.filtered_network_dir),
                "--output-dir",
                str(module_dir),
                "--resolution",
                str(args.resolution),
                "--datasets",
                *datasets,
            ],
            args.dry_run,
        )

    if not args.skip_enrichment:
        run_command(
            [
                sys.executable,
                str(script_path("enrich_coexpression_modules.py")),
                "--module-dir",
                str(module_dir),
                "--output-dir",
                str(interpretation_dir),
                "--min-genes",
                str(args.min_genes),
                "--sleep-seconds",
                str(args.sleep_seconds),
                "--datasets",
                *datasets,
            ],
            args.dry_run,
        )

    if not args.skip_te_context:
        run_command(
            [
                sys.executable,
                str(script_path("build_te_functional_context.py")),
                "--module-dir",
                str(module_dir),
                "--interpretation-dir",
                str(interpretation_dir),
                "--output",
                str(interpretation_dir / "te_functional_context_summary.tsv"),
                "--l1hs-output",
                str(interpretation_dir / "l1hs_functional_context_summary.tsv"),
            ],
            args.dry_run,
        )

    if not args.dry_run:
        interpretation_dir.mkdir(parents=True, exist_ok=True)
        manifest = {
            "script": str(Path(__file__).resolve()),
            "filtered_network_dir": str(args.filtered_network_dir),
            "run_label": args.run_label,
            "resolution": args.resolution,
            "module_dir": str(module_dir),
            "interpretation_dir": str(interpretation_dir),
            "datasets": datasets,
            "min_genes": args.min_genes,
            "steps": {
                "module_detection": not args.skip_module_detection,
                "enrichment": not args.skip_enrichment,
                "te_context": not args.skip_te_context,
            },
        }
        (interpretation_dir / "pipeline_manifest.json").write_text(
            json.dumps(manifest, indent=2, ensure_ascii=False),
            encoding="utf-8",
        )
    print(f"[pipeline] module_dir={module_dir}")
    print(f"[pipeline] interpretation_dir={interpretation_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
