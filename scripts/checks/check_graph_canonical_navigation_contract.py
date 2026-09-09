from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> None:
    workspace = read("assets/js/pages/preview/preview-workspace-mode.js")
    bootstrap = read("assets/js/renderers/g6/index-g6.bootstrap.js")
    shared = read("assets/js/renderers/g6/index-g6-shared.js")
    embed = read("assets/js/renderers/g6/index-g6-embed.js")

    require(
        "navigateGraph" in bootstrap,
        "The parent bridge does not expose the canonical navigateGraph entrypoint.",
    )
    require(
        "onNodeJump" in shared,
        "The shared renderer still has no typed onNodeJump hook.",
    )
    require(
        "hooks.onNodeJump" in shared,
        "Node Jump is not delegated through the shared runner hook.",
    )
    require(
        "pushNodeJump" in embed and "onNodeJump: pushNodeJump" in embed,
        "The iframe does not forward node Jump requests to its parent host.",
    )
    require(
        "onNodeJump" in bootstrap,
        "The parent graph host does not receive iframe Jump requests.",
    )
    require(
        "navigateGraph" in workspace,
        "Preview workspace navigation does not use the canonical semantic entrypoint.",
    )
    require(
        "claimGeneration: true" in bootstrap and "options.claimGeneration === true" in shared,
        "Cached Back/filter renders do not invalidate an in-flight iframe graph load.",
    )
    require(
        "restoringBrowserRoute" in workspace and "resetSharedBackHistory" in workspace,
        "Browser route restoration does not guard the cross-mode Back history.",
    )
    require(
        "graphIsLoading || !legendFilterPending" in bootstrap
        and "relationMinPmidsInput.disabled = graphIsLoading" in bootstrap,
        "Legend Apply and Min PMID are not guarded during semantic navigation.",
    )

    jump_start = shared.find("if (nextAction === 'jump')")
    jump_end = shared.find("function renderEdgeInspectCard", jump_start)
    jump_block = shared[jump_start:jump_end]
    require(
        jump_start >= 0 and jump_end > jump_start and "hooks.onNodeJump" in jump_block,
        "Jump can still bypass the parent-owned hook.",
    )

    print("PASS: canonical Graph navigation static contract")


if __name__ == "__main__":
    main()
