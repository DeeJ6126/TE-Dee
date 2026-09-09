from __future__ import annotations

from harness_lib import ROOT, ok, require, run_check


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def read_plan() -> str:
    completed = ROOT / "docs/exec-plans/completed/coexpression-graph-dual-mode.md"
    path = completed if completed.exists() else ROOT / "docs/exec-plans/active/coexpression-graph-dual-mode.md"
    return path.read_text(encoding="utf-8")


def main() -> None:
    plan = read_plan()
    knowledge = read("templates/preview/knowledge_graph_workspace.php")
    coexpression = read("templates/preview/coexpression_workspace.php")
    mode = read("assets/js/pages/preview/coexpression-mode.js")
    embed = read("assets/js/renderers/g6/coexpression/coexpression-embed.js")
    adapter = read("assets/js/renderers/g6/coexpression/coexpression-dynamic-adapter.js")
    renderer = read("assets/js/renderers/g6/coexpression/coexpression-renderer.js")
    bootstrap = read("assets/js/renderers/g6/index-g6.bootstrap.js")
    preview = read("preview.php")
    knowledge_embed_html = read("assets/html/preview_g6_embed.html")
    coexpression_embed_html = read("assets/html/preview_coexpression_embed.html")

    require(
        'id="coexpression-expression-layer"' in coexpression
        and "Expression activity: On" in coexpression,
        "Co-expression is missing its Expression activity toolbar control.",
    )
    require(
        'id="graph-expression-layer"' not in knowledge
        and "graph-expression-layer" not in bootstrap
        and "graph_expression.php" not in bootstrap,
        "Knowledge Graph still exposes or requests the retired Expression overlay.",
    )
    require(
        'id="coexpression-legend"' in coexpression
        and 'id="coexpression-legend-apply"' in coexpression
        and 'id="coexpression-edge-scope"' in coexpression
        and 'id="coexpression-correlation-notice"' in coexpression,
        "Co-expression legend, Apply workflow, edge scope, or scientific notice is missing.",
    )
    require(
        'id="coexpression-export-svg"' in coexpression
        and "exportSvgText" in mode
        and "exportSvgFile" in mode
        and "exportSvgString" in embed
        and "exportSvgString" in renderer,
        "Co-expression SVG export is not wired through the menu, parent mode, iframe bridge, and renderer.",
    )
    require(
        "g6-svg-export.js" in knowledge_embed_html
        and "g6-svg-export.js" in coexpression_embed_html
        and "__TEKG_G6_SVG_EXPORT" in renderer,
        "Knowledge Graph and Co-expression must load and use the same SVG serializer.",
    )
    require(
        "g6-svg-export.js" in preview
        and "frameUrl + `?v=${encodeURIComponent(version)}`" in mode
        and "url.searchParams.set('v', version)" in bootstrap,
        "SVG-capable iframe documents must be cache-busted with the preview asset version.",
    )
    require(
        "loadVersionedScripts" in knowledge_embed_html
        and "loadVersionedScripts" in coexpression_embed_html,
        "Both SVG-capable iframe documents must version their internal renderer scripts.",
    )
    require(
        "fetchExpressionSummaries" in mode
        and "expressionSummaryCache" in mode
        and "setExpressionEnabled" in mode
        and "setViewOptions" in mode,
        "Co-expression mode does not own Expression loading and view controls.",
    )
    require(
        ".filter((node) => ['te', 'gene'].includes(node.kind))" in mode
        and "if (!['TE', 'Gene'].includes(node.nodeType)) continue;" in renderer,
        "Expression activity must include both TE and Gene nodes.",
    )
    require(
        "setExpressionOverlay" in embed and "setViewOptions" in embed,
        "The Co-expression iframe bridge is missing Expression or filter updates.",
    )
    require(
        "['TE', 'Gene'].includes(node.nodeType) && currentExpressionOverlay.enabled" in renderer
        and "['TE', 'Gene'].includes(node.nodeType) ? renderExpressionEvidenceHtml" in renderer,
        "Gene Expression activity is missing from Co-expression tooltips or detail cards.",
    )
    require(
        "coexpressionModule" in adapter
        and "correlationToCenter" in adapter
        and "expressionActivity" in renderer
        and "renderCoexpressionNodeInspectCard" in renderer
        and "renderCoexpressionEdgeInspectCard" in renderer,
        "The adapter/renderer lacks Co-expression-specific detail and activity contracts.",
    )
    require(
        "Expression values provide activity context" in mode
        and "Correlation does not imply causation" in adapter,
        "The Expression/correlation scientific boundary is missing.",
    )
    runtime_copy = "\n".join((coexpression, mode, embed, adapter, renderer)).lower()
    for prohibited in (" regulates ", " activates ", " causes "):
        require(
            prohibited not in runtime_copy,
            f"Runtime Co-expression copy contains prohibited causal wording: {prohibited.strip()}",
        )
    for name in ("L1HS", "LTR5", "MER11B", "HERVH-int", "CR1"):
        require(name in plan, f"The representative acceptance plan is missing {name}.")

    ok("Task 8 static Expression, details, legend, and representative-case contracts passed")


if __name__ == "__main__":
    run_check(main)
