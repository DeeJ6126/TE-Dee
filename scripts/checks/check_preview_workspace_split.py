from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
PREVIEW = ROOT / "preview.php"
KNOWLEDGE_WORKSPACE = ROOT / "templates" / "preview" / "knowledge_graph_workspace.php"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> None:
    preview = PREVIEW.read_text(encoding="utf-8")

    require(KNOWLEDGE_WORKSPACE.exists(), "Knowledge Graph workspace partial is missing")
    workspace = KNOWLEDGE_WORKSPACE.read_text(encoding="utf-8")

    require(
        "require __DIR__ . '/templates/preview/knowledge_graph_workspace.php';" in preview,
        "preview.php does not synchronously include the Knowledge Graph workspace",
    )
    require('id="previewGraphWorkspace"' not in preview, "Knowledge Graph markup still lives in preview.php")
    require('id="previewGraphWorkspace"' in workspace, "Knowledge Graph workspace root is missing")

    required_ids = (
        "graphSearchType",
        "node-search",
        "graph-search-submit",
        "toggle-expand-mode",
        "toggle-edge-labels",
        "toggle-fixed-view",
        "g6-default-tree-surface",
        "g6-dynamic-surface",
        "graph-preloader",
        "graph-type-legend",
        "node-details",
    )
    for element_id in required_ids:
        require(f'id="{element_id}"' in workspace, f"Workspace is missing #{element_id}")
    require('id="toggle-taxonomy-source"' not in workspace, "Legacy taxonomy source toggle remains in the toolbar")
    require('id="previewTaxonomyMode"' in preview, "Shared taxonomy selector is missing from preview.php")

    require("<script" not in workspace, "Workspace partial must not own JavaScript loading")
    require(
        "index-g6.bootstrap.js" in preview,
        "Existing Knowledge Graph JavaScript loading moved out of preview.php",
    )
    require(
        preview.index("knowledge_graph_workspace.php") < preview.rindex("index-g6.bootstrap.js"),
        "Knowledge Graph workspace must exist before its bootstrap script runs",
    )
    require('id="qaOverlay"' in preview, "Shared DeepThink shell must remain in preview.php")

    print("PASS: preview.php composes an isolated Knowledge Graph workspace without moving JavaScript")


if __name__ == "__main__":
    main()
