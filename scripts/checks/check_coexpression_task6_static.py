from pathlib import Path
import re


ROOT = Path(__file__).resolve().parents[2]
PREVIEW = ROOT / "preview.php"
WORKSPACE = ROOT / "templates" / "preview" / "coexpression_workspace.php"
EMBED_HTML = ROOT / "assets" / "html" / "preview_coexpression_embed.html"
EMBED_JS = ROOT / "assets" / "js" / "renderers" / "g6" / "coexpression" / "coexpression-embed.js"
MODE_JS = ROOT / "assets" / "js" / "pages" / "preview" / "coexpression-mode.js"
CSS = ROOT / "assets" / "css" / "pages" / "preview.css"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def read(path: Path) -> str:
    require(path.is_file(), f"missing Task 6 file: {path.relative_to(ROOT)}")
    return path.read_text(encoding="utf-8")


def main() -> None:
    preview = read(PREVIEW)
    workspace = read(WORKSPACE)
    embed_html = read(EMBED_HTML)
    embed_js = read(EMBED_JS)
    mode_js = read(MODE_JS)
    css = read(CSS)

    require(
        "require __DIR__ . '/templates/preview/coexpression_workspace.php';" in preview,
        "preview.php must synchronously include the isolated Co-expression workspace",
    )
    require('id="previewCoexpressionWorkspace"' in workspace, "Co-expression workspace root is missing")
    root_tag = re.search(r"<div[^>]*id=\"previewCoexpressionWorkspace\"[^>]*>", workspace)
    require(root_tag is not None and "hidden" in root_tag.group(0), "workspace must be hidden by default")
    for element_id in (
        "coexpression-te-search",
        "coexpression-context-select",
        "coexpression-load",
        "coexpression-iframe-host",
        "coexpression-preloader",
        "coexpression-preloader-label",
        "coexpression-state",
        "coexpression-node-details",
    ):
        require(f'id="{element_id}"' in workspace, f"workspace is missing #{element_id}")
    expected_embed_scripts = (
        "../js/tekg_paths.php",
        "../vendor/g6/g6.min.js",
        "../js/renderers/g6/index-g6-type-meta.js",
        "../js/renderers/g6/coexpression/coexpression-contract.js",
        "../js/renderers/g6/coexpression/coexpression-dynamic-adapter.js",
        "../js/renderers/g6/coexpression/coexpression-renderer.js",
        "../js/renderers/g6/coexpression/coexpression-embed.js",
    )
    for script in expected_embed_scripts:
        require(script in embed_html, f"Co-expression iframe is missing script: {script}")
    require('id="container"' in embed_html, "Co-expression iframe must retain the Dynamic Graph container")

    for marker in (
        "__TEKG_COEXPRESSION_EMBED",
        "renderNetwork",
        "setVisible",
        "exportPngDataUrl",
        "getDiagnostics",
        "destroy",
        "stopLayout",
        "onNonblank",
    ):
        require(marker in embed_js, f"Co-expression bridge is missing: {marker}")
    for forbidden in ("fetch(", "coexpression.php", "data/coexpression"):
        require(forbidden not in embed_js, f"iframe bridge must not own runtime data loading: {forbidden}")

    for marker in (
        "__TEKG_COEXPRESSION_MODE",
        "action=catalog",
        "url.searchParams.set('action', 'network')",
        "AbortController",
        "requestEpoch",
        "CACHE_LIMIT = 6",
        "loading-iframe",
        "network.nodes.length === 0",
        "normalizeCatalog",
        "normalizeNetwork",
        "preview_coexpression_embed.html",
        "activate(",
        "deactivate(",
        "getDiagnostics",
        "coexpression-catalog",
    ):
        require(marker in mode_js, f"Co-expression mode controller is missing: {marker}")
    for forbidden in ("display_subgraphs", ".tsv", "data/coexpression"):
        require(forbidden not in mode_js, f"controller must use the MySQL-backed API only: {forbidden}")

    require(".preview-coexpression-workspace" in css, "Co-expression workspace CSS boundary is missing")
    require(".coexpression-state" in css, "Co-expression loading/error state CSS is missing")

    print("PASS: Task 6 static isolation, iframe, bridge, controller, and MySQL-only contracts")


if __name__ == "__main__":
    main()
