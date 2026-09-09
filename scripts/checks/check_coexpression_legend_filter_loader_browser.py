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

        page = browser.new_page(viewport={"width": 1280, "height": 900})
        try:
            page.goto(
                app_url(
                    "preview.php?mode=coexpression&te=L1HS"
                    "&context=cancer_cell_line"
                ),
                wait_until="domcontentloaded",
                timeout=30_000,
            )
            page.wait_for_function(
                "() => document.querySelector('#coexpression-preloader')"
                ".classList.contains('is-visible')",
                timeout=10_000,
            )
            loading_copy = page.evaluate(
                """() => ({
                  message: document.querySelector('#coexpression-preloader-label').textContent.trim(),
                  mechanism: document.querySelector('#coexpression-mechanism-loader-slot').textContent,
                })"""
            )
            require(
                loading_copy["message"] == "Loading L1HS co-expression network..."
                and "L1HS" in loading_copy["mechanism"]
                and "Co-" not in loading_copy["mechanism"]
                and "Ren" not in loading_copy["mechanism"],
                f"Loader exposed an internal stage instead of the TE label: {loading_copy}",
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.().state === 'ready'",
                timeout=30_000,
            )
            require(
                page.locator("#coexpression-mechanism-loader-slot").count() == 1,
                "Co-expression does not expose the shared mechanism Loader slot.",
            )
            ripple_state = page.evaluate(
                """() => document.querySelector('#coexpression-graph-frame')
                  .contentWindow.__TEKG_COEXPRESSION_EMBED.getVisibleSubgraph().nodes
                  .filter((node) => node.nodeType === 'TE' && node.expression_value !== null)
                  .map((node) => ({id: node.id, ripple: node.expression_ripple}))"""
            )
            require(
                len(ripple_state) >= 2
                and all(node["ripple"] is True for node in ripple_state),
                f"Expression-enabled TE nodes are not stable ripple nodes: {ripple_state}",
            )
            module_hub = page.locator(
                "#coexpression-legend [data-highlight-value='module-hub']"
            )
            require(module_hub.count() == 1, "Module hub legend focus row is missing.")
            module_hub.click()
            page.wait_for_function(
                "() => document.querySelector("
                "\"#coexpression-legend [data-highlight-value='module-hub']\""
                ").classList.contains('is-highlight-active')"
            )
            help_text = page.locator("#coexpression-legend").inner_text()
            require(
                "top 5%" in help_text and "log-normalized" in help_text,
                "Scientific legend encodings remain unexplained.",
            )

            rejected = page.evaluate(
                """async () => {
                  const frame = document.querySelector('#coexpression-graph-frame');
                  const bridge = frame.contentWindow.__TEKG_COEXPRESSION_EMBED;
                  const original = bridge.setViewOptions;
                  bridge.setViewOptions = () => Promise.reject(new Error('injected filter failure'));
                  try {
                    await window.__TEKG_COEXPRESSION_MODE.setViewOptions({
                      showTE: true,
                      showGene: false,
                      edgeScope: 'all',
                    });
                  } finally {
                    bridge.setViewOptions = original;
                  }
                  return {
                    diagnostics: window.__TEKG_COEXPRESSION_MODE.getDiagnostics(),
                    busy: document.querySelector('#coexpression-legend-apply')
                      .getAttribute('aria-busy'),
                    applyDisabled: document.querySelector('#coexpression-legend-apply').disabled,
                    detail: document.querySelector('#coexpression-node-details').textContent,
                  };
                }"""
            )
            require(
                rejected["diagnostics"]["state"] == "ready"
                and rejected["diagnostics"]["filterBusy"] is False
                and rejected["busy"] == "false"
                and rejected["applyDisabled"] is False
                and "injected filter failure" in rejected["detail"],
                f"Rejected filter did not recover cleanly: {rejected}",
            )

            rapid = page.evaluate(
                """async () => {
                  const mode = window.__TEKG_COEXPRESSION_MODE;
                  await Promise.all([
                    mode.setViewOptions({showTE: true, showGene: true, edgeScope: 'all'}),
                    mode.setViewOptions({showTE: true, showGene: false, edgeScope: 'center'}),
                  ]);
                  return mode.getDiagnostics();
                }"""
            )
            require(
                rapid["state"] == "ready" and rapid["filterBusy"] is False,
                f"Rapid repeated Apply left filtering stuck: {rapid}",
            )

            before = page.evaluate(
                """() => {
                  const state = window.__TEKG_COEXPRESSION_MODE.getDiagnostics();
                  const graph = document.querySelector('#coexpression-graph-frame')
                    .contentWindow.__TEKG_COEXPRESSION_EMBED.getDiagnostics();
                  return {state, graph, started: performance.now()};
                }"""
            )
            page.uncheck("#coexpression-show-gene")
            page.select_option("#coexpression-edge-scope", "center")
            page.click("#coexpression-legend-apply")
            page.wait_for_function(
                "() => document.querySelector('#coexpression-legend-apply').getAttribute('aria-busy') === 'false'"
                " && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'",
                timeout=5_000,
            )
            after = page.evaluate(
                """() => {
                  const state = window.__TEKG_COEXPRESSION_MODE.getDiagnostics();
                  const win = document.querySelector('#coexpression-graph-frame').contentWindow;
                  return {
                    state,
                    graph: win.__TEKG_COEXPRESSION_EMBED.getDiagnostics(),
                    visible: win.__TEKG_COEXPRESSION_EMBED.getVisibleSubgraph(),
                    elapsed: performance.now(),
                    loader: document.querySelector('#coexpression-preloader')
                      .classList.contains('is-visible'),
                  };
                }"""
            )
            require(
                after["elapsed"] - before["started"] < 1000,
                f"Visibility-only filter was too slow: {after['elapsed'] - before['started']} ms",
            )
            require(
                before["state"]["renderCount"] == after["state"]["renderCount"]
                and before["graph"]["graphIdentity"] == after["graph"]["graphIdentity"],
                f"Filtering rebuilt or rerendered the graph: {before} -> {after}",
            )
            require(
                not after["loader"]
                and all(
                    edge["source"] == "L1HS" or edge["target"] == "L1HS"
                    for edge in after["visible"]["edges"]
                ),
                "Filter Loader or center-edge visibility is incorrect.",
            )
        finally:
            page.close()
            browser.close()

    ok("Co-expression Loader, legend focus, and lightweight filtering passed")


if __name__ == "__main__":
    run_check(main)
