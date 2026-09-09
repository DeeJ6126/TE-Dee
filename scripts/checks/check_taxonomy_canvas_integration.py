from __future__ import annotations

import re
from pathlib import Path

from harness_lib import ROOT, fail, ok, run_check


def read_text(path: Path, failures: list[str]) -> str:
    if not path.is_file():
        failures.append(f"Missing file: {path.relative_to(ROOT)}")
        return ""
    return path.read_text(encoding="utf-8", errors="replace")


def require_token(text: str, token: str, location: str, message: str, failures: list[str]) -> None:
    if token not in text:
        failures.append(f"{location} missing {token!r}. {message}")


def require_pattern(text: str, pattern: str, location: str, message: str, failures: list[str]) -> re.Match[str] | None:
    match = re.search(pattern, text, flags=re.IGNORECASE | re.DOTALL)
    if match is None:
        failures.append(f"{location} missing contract pattern. {message}")
    return match


def forbid_token(text: str, token: str, location: str, message: str, failures: list[str]) -> None:
    if token in text:
        failures.append(f"{location} contains forbidden marker {token!r}. {message}")


def main() -> None:
    failures: list[str] = []
    preview_path = ROOT / "preview.php"
    workspace_path = ROOT / "templates" / "preview" / "knowledge_graph_workspace.php"
    bootstrap_path = ROOT / "assets" / "js" / "renderers" / "g6" / "index-g6.bootstrap.js"
    renderer_path = ROOT / "assets" / "js" / "renderers" / "canvas-force" / "taxonomy-canvas-renderer.js"
    preview_css_path = ROOT / "assets" / "css" / "pages" / "preview.css"

    preview = read_text(preview_path, failures)
    workspace = read_text(workspace_path, failures)
    bootstrap = read_text(bootstrap_path, failures)
    renderer = read_text(renderer_path, failures)
    preview_css = read_text(preview_css_path, failures)

    display_control = require_pattern(
        preview,
        r'<div\b[^>]*(?:taxonomy[^>]*(?:display|view)|(?:display|view)[^>]*taxonomy)[^>]*>'
        r'.*?<button\b[^>]*>\s*Tree\s*</button>'
        r'.*?<button\b[^>]*>\s*Graph\s*</button>.*?</div>',
        "preview.php",
        "Add a Tree/Graph taxonomy display control.",
        failures,
    )
    source_control_position = preview.find('id="previewTaxonomyMode"')
    if source_control_position < 0:
        failures.append("preview.php missing the All/RMSK taxonomy source control #previewTaxonomyMode.")
    elif display_control is not None and display_control.start() >= source_control_position:
        failures.append("preview.php must place the Tree/Graph display control before the All/RMSK taxonomy source control.")

    require_token(
        workspace,
        'id="taxonomy-canvas-surface"',
        "templates/preview/knowledge_graph_workspace.php",
        "The native Canvas taxonomy renderer needs a dedicated surface.",
        failures,
    )

    renderer_asset = "js/renderers/canvas-force/taxonomy-canvas-renderer.js"
    asset_pattern = rf"['\"]{re.escape(renderer_asset)}['\"]"
    require_pattern(
        preview,
        rf"filemtime\s*\(\s*tekg_assets_fs_path\s*\(\s*{asset_pattern}\s*\)\s*\)",
        "preview.php",
        "The Canvas renderer must participate in preview cache versioning.",
        failures,
    )
    require_pattern(
        preview,
        rf"tekg_assets_url\s*\(\s*{asset_pattern}\s*\)",
        "preview.php",
        "Load the production Canvas renderer asset.",
        failures,
    )

    require_token(
        renderer,
        "window.__TEKG_CANVAS_TAXONOMY",
        renderer_path.relative_to(ROOT).as_posix(),
        "Expose the production Canvas taxonomy bridge.",
        failures,
    )
    api_export = require_pattern(
        renderer,
        r"window\.__TEKG_CANVAS_TAXONOMY\s*=\s*\{(?P<body>.*?)\}\s*;",
        renderer_path.relative_to(ROOT).as_posix(),
        "Export the renderer control API as an object literal.",
        failures,
    )
    if api_export is not None:
        api_body = api_export.group("body")
        for method in (
            "render",
            "applyLevelState",
            "setLevelFocus",
            "getLegendMeta",
            "getLayoutMeta",
            "pause",
            "resume",
            "resize",
        ):
            require_pattern(
                api_body,
                rf"\b{re.escape(method)}\b\s*(?::|,|\(|$)",
                renderer_path.relative_to(ROOT).as_posix(),
                f"The public Canvas bridge must expose {method}().",
                failures,
            )

    require_pattern(
        renderer,
        r"const\s+source\s*=\s*String\([^;]+\)\s*===\s*['\"]all['\"]\s*\?\s*['\"]all['\"]",
        renderer_path.relative_to(ROOT).as_posix(),
        "The All control must request the API's supported source=all value.",
        failures,
    )
    forbid_token(
        renderer,
        "? 'tekg3'",
        renderer_path.relative_to(ROOT).as_posix(),
        "The taxonomy tree API does not accept tekg3 as a tree source.",
        failures,
    )
    require_pattern(
        renderer,
        r"AbortController|fetchWithDeadline|fetchWithTimeout",
        renderer_path.relative_to(ROOT).as_posix(),
        "Canvas taxonomy requests must have a bounded failure path.",
        failures,
    )
    require_pattern(
        renderer,
        r"function\s+nodeId\s*\([^)]*index[^)]*\)[\s\S]{0,300}?`[^`]*\$\{index\}",
        renderer_path.relative_to(ROOT).as_posix(),
        "Node IDs must retain the source index so sanitized names cannot collide.",
        failures,
    )
    require_pattern(
        renderer,
        r"state\.focus[\s\S]{0,400}?edge\.(?:source|target)\.depth",
        renderer_path.relative_to(ROOT).as_posix(),
        "Taxonomy level focus must also dim unrelated edges.",
        failures,
    )
    require_pattern(
        renderer,
        r"const\s+byGalaxy\s*=\s*new\s+Map\s*\(\s*\)",
        renderer_path.relative_to(ROOT).as_posix(),
        "Keep the accepted demo's local galaxy repulsion instead of a static anchored layout.",
        failures,
    )
    for token, message in (
        ("const MIN_BRANCH_SHARE = 0.08", "Class sectors need an 8% minimum share."),
        ("const MAX_BRANCH_SHARE = 0.60", "Class sectors need a 60% maximum share."),
        ("function boundedBranchShares", "Class shares must use bounded square-root weighting."),
        ("descendantMass", "The layout must count all descendants rather than direct children."),
        ("galaxyScale", "Depth-2 galaxy spacing must respond to descendant mass."),
        ("branchStart", "Each Class needs a stable angular sector start."),
        ("branchEnd", "Each Class needs a stable angular sector end."),
    ):
        require_token(
            renderer,
            token,
            renderer_path.relative_to(ROOT).as_posix(),
            message,
            failures,
        )
    forbid_token(
        renderer,
        "branch / starTotal * TAU",
        renderer_path.relative_to(ROOT).as_posix(),
        "Do not give every depth-2 taxonomy node an equal global ring slot.",
        failures,
    )
    require_pattern(
        renderer,
        r"if\s*\(\s*state\.dragging\s*\)[\s\S]{0,350}?reheat\s*\(",
        renderer_path.relative_to(ROOT).as_posix(),
        "Dragging a node must reheat the force simulation so neighboring nodes respond.",
        failures,
    )
    require_pattern(
        renderer,
        r"function\s+endPointer\s*\([^)]*\)[\s\S]{0,350}?reheat\s*\(",
        renderer_path.relative_to(ROOT).as_posix(),
        "Releasing a dragged node must let the local graph settle again.",
        failures,
    )
    require_pattern(
        renderer,
        r"function\s+endPointer\s*\([^)]*\)[\s\S]{0,350}?state\.selected\s*=\s*null",
        renderer_path.relative_to(ROOT).as_posix(),
        "Pointer release must clear persistent node selection while hover focus remains available.",
        failures,
    )

    alias_binding = require_pattern(
        bootstrap,
        r"\b(?:const|let|var)\s+(?P<alias>[A-Za-z_$][\w$]*)\s*=\s*window\s*\.\s*__TEKG_CANVAS_TAXONOMY\b",
        bootstrap_path.relative_to(ROOT).as_posix(),
        "Taxonomy Graph mode must select the native Canvas renderer.",
        failures,
    )
    bridge_refs = [r"window\s*\.\s*__TEKG_CANVAS_TAXONOMY"]
    if alias_binding is not None:
        bridge_refs.append(rf"\b{re.escape(alias_binding.group('alias'))}\b")
    bridge_ref = "(?:" + "|".join(bridge_refs) + ")"
    require_pattern(
        bootstrap,
        rf"{bridge_ref}[\s\S]{{0,500}}?\.\s*render\s*\(\s*\{{[\s\S]{{0,200}}?source\s*:",
        bootstrap_path.relative_to(ROOT).as_posix(),
        "Taxonomy Graph mode must render the selected taxonomy source through Canvas.",
        failures,
    )
    for forbidden in ("__TEKG_LARGE_FORCE_GRAPH", "taxonomy-large-force", "createTaxonomyLargeForceRenderer"):
        forbid_token(
            bootstrap,
            forbidden,
            bootstrap_path.relative_to(ROOT).as_posix(),
            "Do not reactivate the archived large-force taxonomy renderer.",
            failures,
        )
        forbid_token(
            preview,
            forbidden,
            "preview.php",
            "Do not load the archived large-force taxonomy renderer.",
            failures,
        )

    require_pattern(
        bootstrap,
        r"els\s*\.\s*edgeLabelsBtn\s*\.\s*hidden\s*=\s*classificationMode\b",
        bootstrap_path.relative_to(ROOT).as_posix(),
        "Classification Tree/Graph mode must hide Show relations.",
        failures,
    )
    for forbidden, label in (
        (r"els\s*\.\s*entitySearch\s*\.\s*hidden\s*=\s*classificationMode\b", "Search"),
        (r"els\s*\.\s*exportMenuWrap\s*\.\s*hidden\s*=\s*classificationMode\b", "Export"),
    ):
        if re.search(forbidden, bootstrap, flags=re.IGNORECASE | re.DOTALL):
            failures.append(f"{bootstrap_path.relative_to(ROOT).as_posix()} hides {label} in classification mode; it must remain visible.")

    for method, behavior in (
        ("getLegendMeta", "build taxonomy legend metadata"),
        ("applyLevelState", "delegate taxonomy legend Apply"),
        ("setLevelFocus", "delegate taxonomy legend row focus"),
    ):
        require_pattern(
            bootstrap,
            rf"{bridge_ref}[\s\S]{{0,500}}?\.\s*{method}\s*\(",
            bootstrap_path.relative_to(ROOT).as_posix(),
            f"Use the Canvas bridge to {behavior}.",
            failures,
        )

    if failures:
        fail("Taxonomy Canvas integration contract failed:\n- " + "\n- ".join(failures))
    ok("Taxonomy Canvas integration contract is present.")


if __name__ == "__main__":
    run_check(main)
