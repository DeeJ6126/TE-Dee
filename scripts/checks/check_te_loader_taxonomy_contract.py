from __future__ import annotations

from urllib.parse import quote

from harness_lib import ROOT, app_url, http_json, ok, require, run_check


def main() -> None:
    names = ["AluJb", "LTR5", "HSMAR1", "Tigger2", "UCON1", "SART1"]
    payload = http_json(
        app_url(
            "api/taxonomy.php?view=loader_kinds&source=rmsk_repbase&names="
            + quote(",".join(names))
        )
    )
    require(payload.get("ok") is True, f"Loader taxonomy API failed: {payload}")
    items = {
        str(item.get("name") or ""): item
        for item in payload.get("items") or []
        if isinstance(item, dict)
    }
    expected = {
        "AluJb": ("retro", True),
        "LTR5": ("retro", True),
        "HSMAR1": ("dna", True),
        "Tigger2": ("dna", True),
        "UCON1": ("default", True),
        "SART1": ("default", False),
    }
    for name, (kind, found) in expected.items():
        require(name in items, f"Loader taxonomy response omitted {name}: {payload}")
        require(
            items[name].get("kind") == kind
            and items[name].get("taxonomy_found") is found,
            f"Unexpected Loader taxonomy classification for {name}: {items[name]}",
        )

    loader = (ROOT / "assets/js/pages/preview/te-loader.js").read_text(encoding="utf-8")
    mode = (ROOT / "assets/js/pages/preview/coexpression-mode.js").read_text(encoding="utf-8")
    bootstrap = (ROOT / "assets/js/renderers/g6/index-g6.bootstrap.js").read_text(encoding="utf-8")
    require(
        "resolveKind" in loader
        and "loader_kinds" in loader
        and "taxonomy_found" in loader,
        "The shared Loader does not resolve kinds from the taxonomy API.",
    )
    require(
        "resolveKind" in mode and "loaderKind" in mode,
        "Co-expression does not await the taxonomy-backed Loader kind.",
    )
    require(
        "await teLoader.resolveKind(q)" in bootstrap,
        "Knowledge Graph does not await the taxonomy-backed Loader kind.",
    )
    ok("Taxonomy-backed TE Loader classification contract passed")


if __name__ == "__main__":
    run_check(main)
