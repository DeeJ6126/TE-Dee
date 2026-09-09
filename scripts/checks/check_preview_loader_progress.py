from __future__ import annotations

from harness_lib import ROOT, ok, require, run_check


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def main() -> None:
    knowledge_markup = read("templates/preview/knowledge_graph_workspace.php")
    coexpression_markup = read("templates/preview/coexpression_workspace.php")
    loader = read("assets/js/pages/preview/te-loader.js")
    knowledge = read("assets/js/renderers/g6/index-g6.bootstrap.js")
    coexpression = read("assets/js/pages/preview/coexpression-mode.js")
    preview = read("preview.php")

    for name, markup, prefix in (
        ("Knowledge Graph", knowledge_markup, "graph"),
        ("Co-expression", coexpression_markup, "coexpression"),
    ):
        label = f'id="{prefix}-preloader-label"'
        progress = f'id="{prefix}-preloader-progress"'
        phase = f'id="{prefix}-preloader-phase"'
        require(label in markup, f"{name} loader label is missing.")
        require(progress in markup, f"{name} loader progressbar is missing.")
        require(phase in markup, f"{name} loader phase text is missing.")
        require(
            markup.index(label) < markup.index(progress) < markup.index(phase),
            f"{name} loader must place label, progressbar, and phase text in that order.",
        )
        require('role="progressbar"' in markup, f"{name} progressbar is not accessible.")

    require("function setProgress" in loader, "Shared TE loader has no progress controller.")
    require("function stopProgress" in loader, "Shared TE loader cannot stop its progress timer.")
    require("progress: setProgress" in loader, "Shared TE loader does not export progress updates.")
    require(
        preview.index("$previewVersion = max(") < preview.index("require __DIR__ . '/head.php';"),
        "Preview computes its asset version after rendering the stylesheet links.",
    )
    require(
        "css/pages/preview.css') . '?v=' . $previewVersion" in preview,
        "Preview CSS is not cache-versioned with the loader markup and scripts.",
    )

    for phase in ("request", "prepare", "render"):
        require(
            f"phase: '{phase}'" in knowledge or f"setGraphLoadingPhase('{phase}'" in knowledge,
            f"Knowledge Graph does not report the {phase} loading phase.",
        )
        require(
            f"phase: '{phase}'" in coexpression,
            f"Co-expression does not report the {phase} loading phase.",
        )

    ok("Preview graph loaders expose shared staged progress UI")


if __name__ == "__main__":
    run_check(main)
