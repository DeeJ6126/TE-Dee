from __future__ import annotations

from harness_lib import ROOT, fail, ok, run_check


def require_text(path, token: str, message: str, failures: list[str]) -> None:
    if not path.exists():
        failures.append(f"Missing file: {path.relative_to(ROOT)}")
        return
    text = path.read_text(encoding="utf-8", errors="replace")
    if token not in text:
        failures.append(f"{path.relative_to(ROOT)} missing {token!r}. {message}")


def forbid_text(path, token: str, message: str, failures: list[str]) -> None:
    if not path.exists():
        return
    text = path.read_text(encoding="utf-8", errors="replace")
    if token in text:
        failures.append(f"{path.relative_to(ROOT)} contains {token!r}. {message}")


def main() -> None:
    failures: list[str] = []
    preview = ROOT / "preview.php"
    knowledge_workspace = ROOT / "templates" / "preview" / "knowledge_graph_workspace.php"
    bootstrap = ROOT / "assets" / "js" / "renderers" / "g6" / "index-g6.bootstrap.js"
    shared = ROOT / "assets" / "js" / "renderers" / "g6" / "index-g6-shared.js"
    embed = ROOT / "assets" / "js" / "renderers" / "g6" / "index-g6-embed.js"

    require_text(preview, "knowledge_graph_workspace.php", "Preview must include the Knowledge Graph workspace.", failures)
    require_text(knowledge_workspace, "toggle-expand-mode", "Expand mode toggle must remain present.", failures)
    require_text(knowledge_workspace, "graph-legend-apply", "Legend filters must be applied explicitly through Apply.", failures)
    require_text(knowledge_workspace, "toggle-edge-labels", "Show labels toggle must remain present.", failures)

    require_text(bootstrap, "function updateCurrentGraphViewState()", "Top toggles should use lightweight view-state updates.", failures)
    require_text(bootstrap, "bridge.setViewState", "Parent page must update iframe state without full graph reload.", failures)
    require_text(bootstrap, "legendFilterPending", "Legend Apply must keep pending state before redraw.", failures)
    require_text(bootstrap, "renderGraphLegendLoading", "Legend loading state must stay visible while graph data is loading.", failures)
    require_text(bootstrap, "bridge.expandGraph", "Expand mode should call the iframe-side expand path.", failures)

    require_text(shared, "function setViewState(next = {})", "Iframe runner must expose lightweight state updates.", failures)
    require_text(shared, "function expandGraph(requestLike, options = {})", "Iframe runner must expose graph expansion.", failures)
    require_text(shared, "currentShowEdgeLabels", "Show labels state must be tracked inside iframe runner.", failures)
    require_text(embed, "setViewState(next)", "Embed bridge must expose setViewState.", failures)
    require_text(embed, "expandGraph(requestLike, options = {})", "Embed bridge must expose expandGraph.", failures)

    forbid_text(preview, "Key-node level", "Legacy key-node level UI should not return to preview.php.", failures)
    forbid_text(knowledge_workspace, "Key-node level", "Legacy key-node level UI should not return to the Knowledge Graph workspace.", failures)

    if failures:
        fail("G6 static contract check failed:\n- " + "\n- ".join(failures))
    ok("G6 static contract markers are present.")


if __name__ == "__main__":
    run_check(main)
