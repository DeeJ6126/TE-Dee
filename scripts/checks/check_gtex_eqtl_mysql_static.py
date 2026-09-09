#!/usr/bin/env python
"""Static contract checks for the versioned GTEx eQTL MySQL schema/importer."""

from __future__ import annotations

import re
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parents[2]
SCHEMA = PROJECT_ROOT / "imports/eqtl_mysql_schema.sql"
IMPORTER = PROJECT_ROOT / "scripts/eqtl/import_gtex_eqtl_mysql.php"

TABLES = [
    "eqtl_analysis_versions",
    "eqtl_import_files",
    "eqtl_tissues",
    "eqtl_te_instances",
    "eqtl_variants",
    "eqtl_genes",
    "eqtl_te_variant_overlaps",
    "eqtl_variant_gene_tissue_associations",
    "eqtl_te_gene_tissue_summary",
    "eqtl_te_gene_cross_tissue_summary",
]


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> int:
    schema = SCHEMA.read_text(encoding="utf-8")
    normalized = re.sub(r"\s+", " ", schema).lower()
    for table in TABLES:
        require(
            f"create table if not exists {table}" in normalized,
            f"Missing idempotent schema table: {table}",
        )
    require("unique key uq_eqtl_active_slot (active_slot)" in normalized, "Missing one-active-version guard.")
    require("case when is_active = 1 then 1 else null end" in normalized, "Active slot is not generated correctly.")
    require("status in ('importing', 'validated', 'failed')" in normalized, "Missing version status constraint.")
    require(normalized.count("on delete cascade") >= 9, "Expected version-scoped cascade constraints are missing.")
    for table in TABLES[1:]:
        match = re.search(
            rf"create table if not exists {table} \((.*?)\) engine=innodb",
            normalized,
        )
        require(match is not None, f"Cannot parse table block: {table}")
        require("version_id" in match.group(1), f"Table is not version scoped: {table}")
    require("binary(32)" in normalized, "Scientific SHA-256 keys must use BINARY(32).")
    require("insert ignore" not in normalized, "Schema unexpectedly contains silent insert suppression.")

    if IMPORTER.is_file():
        importer = IMPORTER.read_text(encoding="utf-8").lower()
        require("insert ignore" not in importer, "Importer must not use INSERT IGNORE.")
        require("local infile" not in importer, "Importer must not depend on LOCAL INFILE.")
    print(f"GTEx eQTL MySQL static contract verified: {len(TABLES)} versioned tables.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
