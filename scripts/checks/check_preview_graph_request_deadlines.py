from __future__ import annotations

from harness_lib import ROOT, ok, require, run_check


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def main() -> None:
    shared = read("assets/js/renderers/g6/index-g6-shared.js")
    coexpression_renderer = read("assets/js/renderers/g6/coexpression/coexpression-renderer.js")
    coexpression_mode = read("assets/js/pages/preview/coexpression-mode.js")

    for name, source in (
        ("Knowledge Graph renderer", shared),
        ("Co-expression renderer", coexpression_renderer),
        ("Co-expression parent mode", coexpression_mode),
    ):
        require(
            "function fetchWithDeadline" in source,
            f"{name} has no bounded browser request helper.",
        )

    require(
        "fetchWithDeadline(endpoint.toString()" in shared
        and "fetchWithDeadline(window.__TEKG_PATHS.apiUrl('taxonomy.php?view=tree')" in shared,
        "Knowledge Graph API and prerequisite resource requests are not bounded.",
    )
    require(
        "fetchWithDeadline(url, {" in coexpression_mode
        and "fetchWithDeadline(paths.apiUrl('graph_expression.php')" in coexpression_mode,
        "Co-expression network/catalog or Expression requests are not bounded.",
    )
    require(
        "fetchWithDeadline(window.__TEKG_PATHS.apiUrl('taxonomy.php?view=tree')" in coexpression_renderer,
        "Co-expression renderer prerequisite resources are not bounded.",
    )
    require(
        "catalogPromise = null;\n        throw error;" in coexpression_mode
        and "frameBridgePromise = pending.catch" in coexpression_mode,
        "Transient Co-expression catalog or iframe bridge failures remain permanently cached.",
    )

    ok("Preview graph API and renderer prerequisite requests have browser deadlines")


if __name__ == "__main__":
    run_check(main)
