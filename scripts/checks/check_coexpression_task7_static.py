from __future__ import annotations

from harness_lib import ROOT, fail, ok, require, run_check


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def main() -> None:
    preview = read("preview.php")
    knowledge_workspace = read("templates/preview/knowledge_graph_workspace.php")
    bootstrap = read("assets/js/renderers/g6/index-g6.bootstrap.js")
    workspace = read("templates/preview/coexpression_workspace.php")
    autocomplete = read("assets/js/components/te-autocomplete.js")
    css = read("assets/css/pages/preview.css")
    coordinator = read("assets/js/pages/preview/preview-workspace-mode.js")
    coexpression = read("assets/js/pages/preview/coexpression-mode.js")
    deepthink = read("assets/js/pages/preview/preview-deepthink.js")

    require('id="previewWorkspaceMode"' in preview, "The shared mode selector is missing.")
    require('id="preview-mode-knowledge"' in preview, "The Knowledge Graph mode tab is missing.")
    require('id="preview-mode-coexpression"' in preview, "The Co-expression mode tab is missing.")
    require(
        'id="previewTaxonomyMode"' in preview
        and 'id="preview-taxonomy-all"' in preview
        and 'id="preview-taxonomy-rmsk-repbase"' in preview,
        "The shared taxonomy selector is missing.",
    )
    require(
        ".preview-workspace-mode[hidden]" in css
        and ".preview-taxonomy-mode[hidden]" in css
        and "display: none !important" in css,
        "Hidden upper-right selectors can be redisplayed by their grid CSS.",
    )
    require(
        'id="toggle-taxonomy-source"' not in knowledge_workspace,
        "The legacy taxonomy toolbar toggle must be removed.",
    )
    require(
        "setTreeVariant" in bootstrap and "setTreeVariant" in coordinator,
        "The explicit taxonomy variant bridge is missing.",
    )
    require(
        "preview-workspace-mode.js" in preview
        and preview.index("coexpression-mode.js") < preview.index("preview-workspace-mode.js"),
        "The coordinator must load after the Co-expression controller.",
    )
    require(
        'id="coexpression-te-search"' in workspace
        and 'data-te-autocomplete-source="coexpression-catalog"' in workspace
        and 'id="coexpression-te-select"' not in workspace,
        "Co-expression must use the same searchable autocomplete structure as Knowledge Graph.",
    )
    require(
        'id="coexpression-load"' in workspace and ">Search</button>" in workspace,
        "The Co-expression search action must use the shared Search label.",
    )
    require(
        "registerSource" in autocomplete and "coexpression-catalog" in coexpression,
        "The shared autocomplete provider contract is missing.",
    )
    require(
        "__TEKG_PREVIEW_WORKSPACE_MODE" in coordinator
        and "setMode" in coordinator
        and "ensureKnowledgeForGraphAction" in coordinator
        and "syncTopControlVisibility" in coordinator
        and "placeTopControls" in coordinator
        and "knowledgeToolbar.insertBefore(topControls, edgeLabelsButton)" in coordinator
        and "coexpressionToolbar.insertBefore(topControls, coexpressionContextControl)" in coordinator,
        "The thin workspace coordinator contract is incomplete.",
    )
    require(
        "previewGraphWorkspace" not in coexpression,
        "The Co-expression controller must not own Knowledge Graph visibility.",
    )
    require(
        "resume" in coexpression and "renderCount" in coexpression,
        "Persistent Co-expression resume diagnostics are missing.",
    )
    require(
        "LOADING_STATES.has(currentState)" in coexpression
        and "currentSelection = { ...stableSelection };" in coexpression
        and "LOADING_STATES.has(previous.state)" in coexpression,
        "Cancelled Co-expression transitions can leave a stale loading state instead of restoring the stable graph.",
    )
    require(
        "nextCatalog.defaultSelection.te" not in coexpression
        and "awaitFeatureSelection" in coexpression
        and "Select a ${type} to explore its co-expression network." in coexpression,
        "Co-expression must never infer L1HS when no feature is selected.",
    )
    require(
        "ensureKnowledgeForGraphAction" in deepthink,
        "DeepThink graph actions must explicitly activate Knowledge Graph mode.",
    )
    ok("Task 7 static mode ownership and DeepThink routing contracts passed")


if __name__ == "__main__":
    run_check(main)
