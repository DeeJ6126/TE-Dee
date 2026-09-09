from __future__ import annotations

from harness_lib import ROOT, ok, require, run_check


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def main() -> None:
    javascript = read("assets/js/pages/index.js")
    stylesheet = read("assets/css/pages/index.css")
    page = read("index.php")

    require("function renderTeChart" in javascript, "Home has no isolated TE chart renderer.")
    require("function renderStaticCharts" in javascript, "Home has no one-time side chart renderer.")
    require(
        "loadHomeStats(level, { teOnly: true })" in javascript,
        "TE level changes still invoke a full three-chart refresh.",
    )
    require(
        "if (!teOnly)" in javascript,
        "Home refresh does not protect the two static side charts.",
    )
    require("align-items: stretch;" in stylesheet, "Donut cards do not share the tallest card height.")
    require("height: 100%;" in stylesheet, "Short donut cards do not extend to the shared bottom edge.")
    require("align-self: center;" in stylesheet, "Short chart legends are not vertically centered.")
    require(
        "js/pages/index.js') . '?v=' . $indexAssetVersion" in page,
        "Home JavaScript changes are not cache-versioned.",
    )

    ok("Home TE level changes update only the center chart with equal-height centered legends")


if __name__ == "__main__":
    run_check(main)
