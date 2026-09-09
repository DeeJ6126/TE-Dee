from __future__ import annotations

from harness_lib import app_url, fail, ok, require, run_check


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed.")

    with sync_playwright() as playwright:
        try:
            browser = playwright.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")

        try:
            for width, height in ((1440, 960), (1024, 768)):
                page = browser.new_page(viewport={"width": width, "height": height})
                page.goto(
                    app_url(
                        "preview.php?mode=coexpression&te=L1HS"
                        "&context=cancer_cell_line"
                    ),
                    wait_until="domcontentloaded",
                    timeout=30_000,
                )
                page.wait_for_function(
                    "() => window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.().state === 'ready'",
                    timeout=30_000,
                )
                bounds = page.evaluate(
                    """() => {
                      const drawer = document.querySelector('#qaDrawer').getBoundingClientRect();
                      const surface = document.querySelector(
                        '#previewCoexpressionWorkspace:not([hidden]) .preview-g6-surface-stack'
                      ).getBoundingClientRect();
                      return {
                        drawer: {left: drawer.left, top: drawer.top, right: drawer.right,
                          bottom: drawer.bottom, width: drawer.width, height: drawer.height},
                        surface: {left: surface.left, top: surface.top, right: surface.right,
                          bottom: surface.bottom},
                      };
                    }"""
                )
                drawer = bounds["drawer"]
                surface = bounds["surface"]
                require(
                    drawer["left"] >= surface["left"] - 1
                    and drawer["top"] >= surface["top"] - 1
                    and drawer["right"] <= surface["right"] + 1
                    and drawer["bottom"] <= surface["bottom"] + 1,
                    f"DeepThink is outside the visible Co-expression surface at "
                    f"{width}x{height}: {bounds}",
                )
                require(
                    drawer["width"] >= (420 if width == 1440 else 320)
                    and drawer["left"] > surface["left"] + surface["right"] * 0.35,
                    f"DeepThink used the hidden-workspace minimum bounds at "
                    f"{width}x{height}: {bounds}",
                )
                page.close()
        finally:
            browser.close()

    ok("DeepThink stays inside the visible Preview workspace")


if __name__ == "__main__":
    run_check(main)
