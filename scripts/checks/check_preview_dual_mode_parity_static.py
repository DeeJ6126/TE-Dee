from __future__ import annotations

from harness_lib import ROOT, ok, require, run_check


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def main() -> None:
    preview = read("preview.php")
    coordinator = read("assets/js/pages/preview/preview-workspace-mode.js")
    knowledge = read("assets/js/renderers/g6/index-g6.bootstrap.js")
    coexpression = read("assets/js/pages/preview/coexpression-mode.js")
    workspace = read("templates/preview/coexpression_workspace.php")
    embed = read("assets/js/renderers/g6/coexpression/coexpression-embed.js")
    shell = read("assets/js/pages/preview/preview-shell.js")

    loader_path = ROOT / "assets/js/pages/preview/te-loader.js"
    require(loader_path.exists(), "The shared TE Loader module is missing.")
    loader = loader_path.read_text(encoding="utf-8")
    require(
        "__TEKG_TE_LOADER" in loader
        and "classify" in loader
        and "show" in loader
        and "hide" in loader,
        "The shared TE Loader does not expose the required contract.",
    )
    require(
        "te-loader.js" in preview
        and "coexpression-mechanism-loader-slot" in workspace,
        "Preview does not load the shared TE Loader for both workspaces.",
    )
    require(
        "function getTeLoaderKind" not in knowledge
        and "function renderTeMechanismLoader" not in knowledge,
        "Knowledge Graph still owns a duplicate TE Loader implementation.",
    )

    require(
        "tekg:g6-navigation" in knowledge
        and "routeForKnowledge" in coordinator
        and "routeForCoexpression" in coordinator
        and "writeRoute" in coordinator,
        "Canonical Preview navigation ownership is incomplete.",
    )
    require(
        "getLiveDynamicBridge" in knowledge
        and "if (liveBridge)" in knowledge
        and "dynamicFrame.src = nextSrc" in knowledge,
        "Knowledge Graph does not preserve its live iframe across query handoffs.",
    )
    require(
        "resolveExactTe" in coexpression
        and "LINE1" not in coordinator
        and "L1HS" not in coordinator,
        "Exact catalog matching is missing or a forbidden TE alias is present.",
    )
    require(
        "coexpression-context-select" in workspace
        and "els.context.addEventListener('change'" in coexpression,
        "Co-expression context changes are not wired into selection.",
    )
    require(
        "setLegendFocus" in embed
        and "data-highlight-kind" in workspace,
        "Co-expression legend focus is not exposed end to end.",
    )
    require(
        "setElementVisibility" in read(
            "assets/js/renderers/g6/coexpression/coexpression-renderer.js"
        )
        and "aria-busy" in coexpression,
        "Co-expression filtering is not a bounded visibility-only operation.",
    )
    require(
        "preview-workspace-mode-change" in shell
        and ":not([hidden])" in shell,
        "DeepThink bounds are not anchored to the visible workspace.",
    )

    ok("Preview dual-mode parity and state ownership contracts passed")


if __name__ == "__main__":
    run_check(main)
