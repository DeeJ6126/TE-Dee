from __future__ import annotations

from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
TAXONOMY_ROOT = ROOT / "data" / "taxonomy"


def repo_path(*parts: str) -> Path:
    return ROOT.joinpath(*parts)


def data_path(*parts: str) -> Path:
    return ROOT.joinpath("data", *parts)


def expression_bulk_path(*parts: str) -> Path:
    return data_path("bulk_expression_web", *parts)


def imports_path(*parts: str) -> Path:
    return ROOT.joinpath("imports", *parts)


def taxonomy_path(*parts: str) -> Path:
    return TAXONOMY_ROOT.joinpath("transposon_tree", *parts)


def taxonomy_te234_path(*parts: str) -> Path:
    return TAXONOMY_ROOT.joinpath("te_234", *parts)


def taxonomy_lineage_path(*parts: str) -> Path:
    return TAXONOMY_ROOT.joinpath("lineage", *parts)


def terminology_path(*parts: str) -> Path:
    return ROOT.joinpath("data", "terminology", *parts)


def api_path(*parts: str) -> Path:
    return ROOT.joinpath("api", *parts)


def scripts_path(*parts: str) -> Path:
    return ROOT.joinpath("scripts", *parts)


def assets_path(*parts: str) -> Path:
    return ROOT.joinpath("assets", *parts)
