from __future__ import annotations

from harness_lib import ROOT, ok, require, run_check


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def read_plan() -> str:
    completed = ROOT / "docs/exec-plans/completed/coexpression-graph-dual-mode.md"
    path = completed if completed.exists() else ROOT / "docs/exec-plans/active/coexpression-graph-dual-mode.md"
    return path.read_text(encoding="utf-8")


def main() -> None:
    workspace = read("templates/preview/coexpression_workspace.php")
    mode = read("assets/js/pages/preview/coexpression-mode.js")
    coordinator = read("assets/js/pages/preview/preview-workspace-mode.js")
    embed = read("assets/js/renderers/g6/coexpression/coexpression-embed.js")
    plan = read_plan()

    require(
        'id="coexpression-export-csv"' in workspace
        and 'id="coexpression-export-png"' in workspace
        and 'id="coexpression-retry"' in workspace,
        "Task 9 export or retry controls are missing.",
    )
    require(
        "exportCsvText" in mode
        and "exportPngFile" in mode
        and "interpretation_limit" in mode
        and "analysis_version" in mode,
        "Mode-specific export metadata is incomplete.",
    )
    require(
        "popstate" in coordinator
        and "routeForKnowledge" in coordinator
        and "routeForCoexpression" in coordinator
        and "writeRoute" in coordinator,
        "URL and Back/Forward restoration are incomplete.",
    )
    require(
        "currentKnowledgeQuery" not in coordinator
        and "options.te" in coordinator,
        "The forbidden implicit Knowledge query to Co-expression rule returned.",
    )
    require(
        "graphIdentity" in embed and "expressionCacheKeys" in mode,
        "Renderer identity or Expression cache diagnostics are missing.",
    )
    require(
        "1024x768" in plan and "1280x900" in plan and "1440x960" in plan,
        "The desktop acceptance matrix is missing from the plan.",
    )

    ok("Task 9 URL, export, cache, retry, and desktop contracts passed")


if __name__ == "__main__":
    run_check(main)
