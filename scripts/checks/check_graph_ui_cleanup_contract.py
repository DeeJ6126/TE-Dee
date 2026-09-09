from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(relative: str) -> str:
    return (ROOT / relative).read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> None:
    bootstrap = read("assets/js/renderers/g6/index-g6.bootstrap.js")
    tree = read("assets/js/renderers/g6/default-tree-mindmap.js")
    taxonomy = read("assets/js/renderers/canvas-force/taxonomy-canvas-renderer.js")
    shell = read("assets/js/pages/preview/preview-shell.js")
    preview_css = read("assets/css/pages/preview.css")
    workspace = read("assets/js/pages/preview/preview-workspace-mode.js")
    coexpression_mode = read("assets/js/pages/preview/coexpression-mode.js")
    coexpression_template = read("templates/preview/coexpression_workspace.php")
    knowledge_template = read("templates/preview/knowledge_graph_workspace.php")
    preview_page = read("preview.php")
    knowledge_cards = read("assets/js/renderers/g6/index-g6-shared.js")
    coexpression_cards = read("assets/js/renderers/g6/coexpression/coexpression-renderer.js")

    require("classificationMode && !graphIsLoading" in bootstrap, "Classification export is not enabled.")
    require("getExportSnapshot" in tree, "Taxonomy Tree does not expose an export snapshot.")
    require("exportPngDataUrl" in taxonomy and "exportSvgString" in taxonomy, "Taxonomy Graph export methods are missing.")
    require("__TEKG_LOAD_DYNAMIC_GRAPH(query)" not in tree, "Taxonomy Tree still jumps into the dynamic graph.")

    toggle_start = shell.index("function toggleOverlay")
    toggle_end = shell.index("\n  function ", toggle_start + 10)
    toggle_body = shell[toggle_start:toggle_end]
    require("positionFabBesideDrawer" in toggle_body, "Opening the AI drawer does not reposition the FAB.")
    require(".preview-deepthink-messages" in preview_css and "user-select: text" in preview_css, "AI messages are not selectable.")

    require('id="back-graph"' in preview_page, "Shared Back control is missing from the shared toolbar.")
    require('id="back-graph"' not in knowledge_template, "Back control is still owned by the Knowledge Graph-only toolbar.")
    require('id="coexpression-back"' not in coexpression_template, "Co-expression still defines a second Back control.")
    require("getElementById('back-graph')" in workspace, "Workspace coordinator does not reuse the Knowledge Graph Back control.")
    require("sharedBackHistory" in workspace, "Shared cross-mode Back history is missing.")
    require("insertBefore(sharedBack, coexpressionExpressionButton)" in workspace, "Co-expression Back is not positioned to the left of Expression activity.")
    require("Back to taxonomy" in workspace and "Back to taxonomy" in bootstrap, "Taxonomy Back label is not naturalized.")
    require("coexpression-back" not in coexpression_mode, "Co-expression mode still owns a separate Back control.")

    for card_source in (knowledge_cards, coexpression_cards):
        require("kvRow('Key node'" not in card_source, "Key node is still user-visible.")
        require("kvRow('Category level'" not in card_source, "Category level is still user-visible.")
        require("No evidence text attached to this edge." not in card_source, "Empty Evidence text is still rendered.")
        require("naturalTaxonomySource" in card_source, "Taxonomy Source is not naturalized.")

    require("kvRow('Module', module.id)" not in coexpression_cards, "Raw module ID is still user-visible.")
    require("kvRow('Confidence'" not in coexpression_cards, "Confidence is still user-visible.")
    require("kvRow('Edge role'" not in coexpression_cards, "Edge role is still user-visible.")
    require("kvRow('Metric coverage'" not in knowledge_cards, "Metric coverage is still user-visible.")
    require("stripModuleId" in coexpression_cards, "Raw module IDs are not removed from interpretation text.")

    print("PASS: Graph UI cleanup contract")


if __name__ == "__main__":
    main()
