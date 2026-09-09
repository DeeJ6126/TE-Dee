from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> None:
    preview = read("preview.php")
    iframe = read("assets/html/preview_graph.html")
    bootstrap = read("assets/js/renderers/g6/index-g6.bootstrap.js")

    require(
        'id="preview-taxonomy-display-tree"' in preview
        and 'class="preview-taxonomy-mode-tab is-active"' in preview,
        "taxonomy display does not expose Tree as the default",
    )
    require(
        'id="preview-taxonomy-display-graph"' in preview,
        "taxonomy display does not expose the Canvas Graph option",
    )
    require(
        "js/renderers/canvas-force/taxonomy-canvas-renderer.js" in preview,
        "preview.php does not load the native Canvas taxonomy renderer",
    )

    retired_runtime_scripts = (
        "large-force-graph/large-force-graph-contract.js",
        "large-force-graph/large-force-graph-layout.js",
        "large-force-graph/large-force-graph-styles.js",
        "large-force-graph/large-force-graph-core.js",
        "large-force-graph/adapters/taxonomy-large-force-adapter.js",
        "taxonomy-dynamic-prototype.js",
    )
    for script in retired_runtime_scripts:
        require(script not in preview, f"preview.php still loads retired taxonomy graph script: {script}")
        require(script not in iframe, f"preview_graph.html still loads retired taxonomy graph script: {script}")

    require(
        "let currentTaxonomyDisplayMode = 'tree';" in bootstrap,
        "taxonomy display mode does not default to tree",
    )
    require(
        "currentTaxonomyDisplayMode === 'graph'" in bootstrap
        and "renderTaxonomyGraph(options)" in bootstrap
        and "renderDefaultTree(options)" in bootstrap,
        "taxonomy entrypoint does not route explicitly between Tree and Canvas Graph",
    )
    require(
        "taxonomyCanvasRenderer.render({ source: currentTreeVariant })" in bootstrap,
        "taxonomy Graph is not rendered through the native Canvas bridge",
    )

    print("PASS: taxonomy runtime defaults to Tree and exposes the isolated Canvas Graph option")


if __name__ == "__main__":
    main()
