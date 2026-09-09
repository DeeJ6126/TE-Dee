from __future__ import annotations

from harness_lib import app_url, fail, ok, require, run_check


def assert_shares(meta: dict, expected: dict[str, tuple[float, float]]) -> None:
    branches = meta.get("branches") or []
    require(len(branches) == 3, f"Expected three Class sectors, got: {branches}")
    total = sum(float(branch.get("share") or 0) for branch in branches)
    require(abs(total - 1) < 1e-6, f"Class sector shares must sum to 1, got {total}")
    by_name = {str(branch.get("name") or ""): branch for branch in branches}
    for name, bounds in expected.items():
        require(name in by_name, f"Missing Class sector {name!r}: {list(by_name)}")
        share = float(by_name[name].get("share") or 0)
        require(bounds[0] <= share <= bounds[1], f"{name} share {share:.4f} outside {bounds}")
        require(by_name[name].get("galaxies"), f"{name} has no depth-2 galaxy metadata")


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed. Run: pip install -r requirements-dev.txt")

    page_errors: list[str] = []
    with sync_playwright() as playwright:
        try:
            browser = playwright.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")

        page = browser.new_page(viewport={"width": 1440, "height": 960})
        page.on("pageerror", lambda exc: page_errors.append(str(exc)))
        page.goto(app_url("preview.php"), wait_until="domcontentloaded", timeout=30000)
        page.get_by_role("tab", name="Graph", exact=True).click()
        page.wait_for_function(
            """() => {
                const bridge = window.__TEKG_CANVAS_TAXONOMY;
                const meta = bridge?.getLayoutMeta?.();
                return meta?.source === 'rmsk_repbase' && meta?.branches?.length === 3;
            }""",
            timeout=30000,
        )
        rmsk_meta = page.evaluate("() => window.__TEKG_CANVAS_TAXONOMY.getLayoutMeta()")
        assert_shares(
            rmsk_meta,
            {
                "Class I: Retrotransposons": (0.55, 0.59),
                "Class II: DNA Transposons": (0.33, 0.37),
                "Others": (0.08, 0.10),
            },
        )

        page.get_by_role("tab", name="All", exact=True).click()
        page.wait_for_function(
            "() => window.__TEKG_CANVAS_TAXONOMY?.getLayoutMeta?.().source === 'all'",
            timeout=30000,
        )
        all_meta = page.evaluate("() => window.__TEKG_CANVAS_TAXONOMY.getLayoutMeta()")
        assert_shares(
            all_meta,
            {
                "Class I: Retrotransposons": (0.54, 0.58),
                "Class II: DNA Transposons": (0.32, 0.36),
                "Others": (0.09, 0.12),
            },
        )
        visible_contract = page.evaluate(
            """() => ({
                legendDepths: window.__TEKG_CANVAS_TAXONOMY.getLegendMeta().map(item => item.depth),
                exportedDepths: window.__TEKG_CANVAS_TAXONOMY.getExportSnapshot().nodes.map(node => node.taxonomyLevel),
                legendText: document.querySelector('#graph-legend-list')?.textContent || '',
            })"""
        )
        require(max(visible_contract["legendDepths"], default=-1) == 5,
                f"Taxonomy legend must end at Level 5: {visible_contract}")
        require(max(visible_contract["exportedDepths"], default=-1) <= 5,
                f"Taxonomy Graph still exposes Level 6-8 nodes: {visible_contract}")
        for hidden_label in ("Level 6", "Level 7", "Level 8"):
            require(hidden_label not in visible_contract["legendText"],
                    f"Taxonomy legend still displays {hidden_label}")

        surface = page.locator("#taxonomy-canvas-surface").evaluate(
            """element => {
                const rect = element.getBoundingClientRect();
                return { hidden: element.hidden, width: rect.width, height: rect.height };
            }"""
        )
        require(not surface["hidden"], f"Canvas taxonomy surface is hidden: {surface}")
        require(surface["width"] > 300 and surface["height"] > 300, f"Canvas surface is too small: {surface}")
        require(not page_errors, "Canvas taxonomy page errors:\n" + "\n".join(page_errors))
        browser.close()

    ok("Taxonomy Canvas weighted layout browser contract passed.")


if __name__ == "__main__":
    run_check(main)
