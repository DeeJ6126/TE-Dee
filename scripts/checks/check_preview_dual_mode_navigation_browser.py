from __future__ import annotations

from harness_lib import app_url, fail, ok, require, run_check


def wait_ready(page, te: str, context: str) -> None:
    page.wait_for_function(
        """([te, context]) => {
          const state = window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.();
          return state?.state === 'ready'
            && state.selection?.te === te
            && state.selection?.context === context;
        }""",
        arg=[te, context],
        timeout=30_000,
    )


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

        page = browser.new_page(viewport={"width": 1280, "height": 900})
        errors: list[str] = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.on(
            "console",
            lambda message: errors.append(message.text)
            if message.type == "error"
            else None,
        )
        try:
            page.goto(
                app_url("preview.php?q=L1HS&type=TE"),
                wait_until="domcontentloaded",
                timeout=30_000,
            )
            page.wait_for_function(
                "() => window.__TEKG_G6_BRIDGE?.getState?.().query === 'L1HS'",
                timeout=30_000,
            )
            page.fill("#node-search", "LTR5")
            page.click("#graph-search-submit")
            page.wait_for_function(
                "() => window.__TEKG_G6_BRIDGE.getState().query === 'LTR5'"
                " && new URL(location.href).searchParams.get('q') === 'LTR5'",
                timeout=30_000,
            )
            page.fill("#node-search", "L1HS")
            page.click("#graph-search-submit")
            page.wait_for_function(
                "() => window.__TEKG_G6_BRIDGE.getState().query === 'L1HS'"
                " && new URL(location.href).searchParams.get('q') === 'L1HS'",
                timeout=30_000,
            )
            page.click("#preview-mode-coexpression")
            wait_ready(page, "L1HS", "cancer_cell_line")
            require(
                page.input_value("#coexpression-te-search") == "L1HS",
                "Knowledge-to-Co-expression handoff cleared an exact TE.",
            )

            page.evaluate(
                "() => window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode("
                "'knowledge', {history:'push'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_G6_BRIDGE.getState().query === 'L1HS'"
                " && window.__TEKG_G6_BRIDGE.getState().mode === 'dynamic'",
                timeout=30_000,
            )

            page.evaluate(
                """() => {
                  const bridge = window.__TEKG_G6_BRIDGE;
                  window.__TEKG_TEST_ORIGINAL_G6_STATE = bridge.getState;
                  bridge.getState = () => ({
                    ...window.__TEKG_TEST_ORIGINAL_G6_STATE(),
                    mode: 'dynamic',
                    query: 'LINE1',
                    queryType: 'TE',
                  });
                }"""
            )
            page.click("#preview-mode-coexpression")
            page.wait_for_function(
                "() => ['unavailable','awaiting-selection'].includes("
                "window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state)",
                timeout=15_000,
            )
            page.evaluate(
                """() => {
                  window.__TEKG_G6_BRIDGE.getState = window.__TEKG_TEST_ORIGINAL_G6_STATE;
                  delete window.__TEKG_TEST_ORIGINAL_G6_STATE;
                }"""
            )
            negative = page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()"
            )
            require(
                negative.get("selection", {}).get("te") != "L1HS",
                f"LINE1 was incorrectly aliased to L1HS: {negative}",
            )

            page.evaluate(
                "() => window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode("
                "'coexpression', {te:'LTR5', context:'cancer_cell_line', history:'push'})"
            )
            wait_ready(page, "LTR5", "cancer_cell_line")
            page.evaluate(
                "() => { window.__testKnowledgeFrameWindow = "
                "document.querySelector('#g6-dynamic-frame')?.contentWindow || null; }"
            )
            page.click("#preview-mode-knowledge")
            page.wait_for_function(
                "() => window.__TEKG_G6_BRIDGE.getState().mode === 'dynamic'"
                " && window.__TEKG_G6_BRIDGE.getState().query === 'LTR5'",
                timeout=30_000,
            )
            page.wait_for_function(
                "() => window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics().pendingKnowledgeLoads === 0",
                timeout=60_000,
            )
            require(
                page.evaluate(
                    "() => window.__testKnowledgeFrameWindow === "
                    "document.querySelector('#g6-dynamic-frame')?.contentWindow"
                ),
                "Knowledge Graph iframe/G6 instance was replaced during the TE handoff.",
            )
            require(
                "q=LTR5" in page.url and "type=TE" in page.url,
                f"Co-expression-to-Knowledge route is stale: {page.url}",
            )

            page.click("#preview-mode-coexpression")
            wait_ready(page, "LTR5", "cancer_cell_line")
            page.wait_for_function(
                "() => window.__TEKG_PREVIEW_WORKSPACE_MODE.getMode() === 'coexpression'",
                timeout=60_000,
            )
            page.select_option("#coexpression-context-select", "normal_tissue")
            require(
                "context=normal_tissue" in page.url,
                f"Context route did not update optimistically: {page.url}",
            )
            wait_ready(page, "LTR5", "normal_tissue")

            page.evaluate(
                """() => {
                  document.querySelector('#coexpression-te-search').value = 'L1HS';
                  document.querySelector('#coexpression-load').click();
                }"""
            )
            page.wait_for_function("() => new URL(location.href).searchParams.get('te') === 'L1HS'", timeout=500)
            started = page.evaluate(
                """() => ({
                  value: document.querySelector('#coexpression-te-search').value,
                  url: location.href,
                  state: window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state,
                })"""
            )
            require(
                started["value"] == "L1HS"
                and "te=L1HS" in started["url"]
                and started["state"].startswith("loading"),
                f"TE selection was not accepted synchronously: {started}",
            )
            wait_ready(page, "L1HS", "normal_tissue")
            page.evaluate("() => history.back()")
            wait_ready(page, "LTR5", "normal_tissue")
            require(
                "te=LTR5" in page.url and "context=normal_tissue" in page.url,
                f"Back did not restore the canonical Co-expression route: {page.url}",
            )
            page.evaluate("() => history.forward()")
            wait_ready(page, "L1HS", "normal_tissue")
            require(
                "te=L1HS" in page.url and "context=normal_tissue" in page.url,
                f"Forward did not restore the canonical Co-expression route: {page.url}",
            )
            require(not errors, f"Browser errors: {errors[:5]}")
        finally:
            page.close()
            browser.close()

    ok("Preview exact TE handoff, route, and context navigation passed")


if __name__ == "__main__":
    run_check(main)
