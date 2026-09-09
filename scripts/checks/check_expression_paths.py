from __future__ import annotations

from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
CANONICAL_ROOT = ROOT / "data" / "bulk_expression_web"
LEGACY_ROOT_TEXT = "data/raw/new_data/bulk_expression_web"
LEGACY_HELPER_TEXT = 'data_path("raw", "new_data", "bulk_expression_web")'

FILES_TO_SCAN = [
    ROOT / "api" / "expression_data.php",
    ROOT / "scripts" / "build" / "prepare_expression_assets.py",
    ROOT / "scripts" / "path_helpers.py",
]

REQUIRED_FILES = [
    CANONICAL_ROOT / "processed" / "te_expression_context_stats.tsv",
    CANONICAL_ROOT / "processed" / "te_expression_browse_summary.tsv",
    CANONICAL_ROOT / "processed" / "te_expression_dataset_summary.tsv",
    CANONICAL_ROOT / "normal_tissue" / "Normal_tissue_TE_normalized_count.tsv",
    CANONICAL_ROOT / "normal_cell_line" / "Normal_cell_line_TE_normalized_count.tsv",
    CANONICAL_ROOT / "cancer_cell_line" / "CCLE_TE_normalized_count.tsv",
]


def main() -> int:
    failures: list[str] = []

    if not CANONICAL_ROOT.is_dir():
        failures.append(f"canonical expression root is missing: {CANONICAL_ROOT}")

    legacy_root = ROOT / "data" / "raw" / "new_data" / "bulk_expression_web"
    if legacy_root.exists():
        failures.append(f"legacy expression root still exists: {legacy_root}")

    for path in REQUIRED_FILES:
        if not path.is_file():
            failures.append(f"required expression asset missing: {path}")

    for path in FILES_TO_SCAN:
        if not path.is_file():
            failures.append(f"expected file missing: {path}")
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        if LEGACY_ROOT_TEXT in text or LEGACY_HELPER_TEXT in text:
            failures.append(f"legacy expression path reference remains: {path.relative_to(ROOT)}")

    helper_text = (ROOT / "scripts" / "path_helpers.py").read_text(encoding="utf-8", errors="replace")
    if "def expression_bulk_path(" not in helper_text:
        failures.append("scripts/path_helpers.py missing expression_bulk_path helper")

    php_path_config = ROOT / "path_config.php"
    php_text = php_path_config.read_text(encoding="utf-8", errors="replace")
    if "tekg_expression_bulk_fs_path" not in php_text:
        failures.append("path_config.php missing tekg_expression_bulk_fs_path helper")

    if failures:
        print("FAIL expression path consistency")
        for failure in failures:
            print(f"- {failure}")
        return 1

    print("PASS expression path consistency")
    print(f"- canonical root: {CANONICAL_ROOT}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
