from __future__ import annotations

from harness_lib import ROOT, app_url, fail, ok, require, run_check


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
        coexpression_requests: list[str] = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.on("console", lambda message: errors.append(message.text) if message.type == "error" else None)
        page.on(
            "request",
            lambda request: coexpression_requests.append(request.url)
            if "api/coexpression.php" in request.url
            else None,
        )

        try:
            page.goto(app_url("preview.php"), wait_until="domcontentloaded", timeout=30_000)
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE && window.__TEKG_PREVIEW_WORKSPACE_MODE || false",
                timeout=10_000,
            )
            initial = page.evaluate(
                """() => ({
                  knowledgeHidden: document.querySelector('#previewGraphWorkspace').hidden,
                  coexpressionHidden: document.querySelector('#previewCoexpressionWorkspace').hidden,
                  iframeCount: document.querySelectorAll('#coexpression-iframe-host iframe').length,
                })"""
            )
            require(not initial["knowledgeHidden"], f"Knowledge Graph must remain visible by default: {initial}")
            require(initial["coexpressionHidden"], f"Co-expression must remain hidden by default: {initial}")
            require(initial["iframeCount"] == 0, f"Co-expression iframe must be lazy: {initial}")
            require(not coexpression_requests, f"Co-expression API must be lazy: {coexpression_requests}")

            loader_start = page.evaluate(
                """() => {
                  const startedAt = performance.now();
                  window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode('coexpression', {
                    te: 'L1HS',
                    context: 'cancer_cell_line',
                  });
                  return {
                    elapsed: performance.now() - startedAt,
                    visible: document.querySelector('#coexpression-preloader').classList.contains('is-visible'),
                  };
                }"""
            )
            require(loader_start["visible"] and loader_start["elapsed"] < 100, f"Loader did not appear within 100 ms: {loader_start}")
            page.wait_for_function(
                """() => {
                  const value = window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.();
                  return value?.state === 'ready' && value?.selection?.te === 'L1HS'
                    && value?.selection?.context === 'cancer_cell_line';
                }""",
                timeout=30_000,
            )
            first = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
            require(first["iframeCount"] == 1, f"Expected one iframe after activation: {first}")
            require(first["nodeCount"] == 26 and first["edgeCount"] == 100, f"Unexpected L1HS graph: {first}")
            require(first["nonblank"] is True and first["loaderVisible"] is False, f"Loader readiness is incorrect: {first}")
            require(first["requestCounts"]["catalog"] == 1, f"Catalog was not requested exactly once: {first}")
            displays = page.evaluate(
                """() => ({
                  knowledge: getComputedStyle(document.querySelector('#previewGraphWorkspace')).display,
                  coexpression: getComputedStyle(document.querySelector('#previewCoexpressionWorkspace')).display,
                  coexpressionToolbar: getComputedStyle(document.querySelector('#previewCoexpressionWorkspace .preview-graph-toolbar')).display,
                })"""
            )
            require(
                displays["knowledge"] == "none" and displays["coexpression"] != "none" and displays["coexpressionToolbar"] != "none",
                f"Activation did not visibly switch to the hidden Task 6 workspace: {displays}",
            )
            frame_identity = first["frameIdentity"]
            page.click("#qaFab")
            page.wait_for_function("() => !document.querySelector('#qaOverlay').classList.contains('is-open')")
            desktop_screenshot = ROOT / "docs/eval/runs/tmp/coexpression-task6-desktop.png"
            desktop_screenshot.parent.mkdir(parents=True, exist_ok=True)
            page.screenshot(path=str(desktop_screenshot), full_page=True)

            page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.activate({te:'L1HS', context:'normal_cell_line'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection?.context === 'normal_cell_line' && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'",
                timeout=30_000,
            )
            second = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
            require(second["frameIdentity"] == frame_identity and second["iframeCount"] == 1, f"Context switch recreated iframe: {second}")

            page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.activate({te:'L1HS', context:'normal_tissue'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection?.context === 'normal_tissue' && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'",
                timeout=30_000,
            )

            cancer_requests_before = sum(
                "action=network" in url and "context=cancer_cell_line" in url for url in coexpression_requests
            )
            page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.activate({te:'L1HS', context:'cancer_cell_line'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection?.context === 'cancer_cell_line' && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'",
                timeout=30_000,
            )
            cancer_requests_after = sum(
                "action=network" in url and "context=cancer_cell_line" in url for url in coexpression_requests
            )
            require(cancer_requests_after == cancer_requests_before, "Six-entry cache did not reuse the L1HS cancer response")

            page.evaluate(
                """() => {
                  window.__TEKG_TASK6_ORIGINAL_FETCH = window.fetch;
                  window.fetch = (...args) => {
                    const url = String(args[0] || '');
                    if (!url.includes('te=LTR5')) return window.__TEKG_TASK6_ORIGINAL_FETCH(...args);
                    return new Promise((resolve, reject) => {
                      setTimeout(() => window.__TEKG_TASK6_ORIGINAL_FETCH(...args).then(resolve, reject), 500);
                    });
                  };
                  window.__TEKG_COEXPRESSION_MODE.activate({te:'LTR5', context:'cancer_cell_line'});
                  setTimeout(() => {
                    window.__TEKG_COEXPRESSION_MODE.activate({te:'L1HS', context:'cancer_cell_line'});
                  }, 20);
                }"""
            )
            page.wait_for_function(
                """() => {
                  const value = window.__TEKG_COEXPRESSION_MODE.getDiagnostics();
                  return value.state === 'ready' && value.selection?.te === 'L1HS'
                    && value.selection?.context === 'cancer_cell_line';
                }""",
                timeout=10_000,
            )
            page.wait_for_timeout(600)
            stale = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
            require(
                stale["state"] == "ready" and stale["selection"] == {"te": "L1HS", "context": "cancer_cell_line"},
                f"A stale response replaced the newer cached selection: {stale}",
            )
            page.evaluate(
                """() => {
                  window.fetch = window.__TEKG_TASK6_ORIGINAL_FETCH;
                  delete window.__TEKG_TASK6_ORIGINAL_FETCH;
                }"""
            )

            page.evaluate(
                """() => {
                  window.__TEKG_TASK6_ORIGINAL_FETCH = window.fetch;
                  window.fetch = (...args) => String(args[0] || '').includes('te=HERVH-int')
                    ? Promise.reject(new Error('Task 6 injected network failure'))
                    : window.__TEKG_TASK6_ORIGINAL_FETCH(...args);
                  window.__TEKG_COEXPRESSION_MODE.activate({te:'HERVH-int', context:'cancer_cell_line'});
                }"""
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'error'",
                timeout=10_000,
            )
            page.evaluate(
                """() => {
                  window.fetch = window.__TEKG_TASK6_ORIGINAL_FETCH;
                  delete window.__TEKG_TASK6_ORIGINAL_FETCH;
                }"""
            )
            page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.activate({te:'L1HS', context:'cancer_cell_line'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'",
                timeout=10_000,
            )

            page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.activate({te:'CR1', context:'cancer_cell_line'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'unavailable'",
                timeout=10_000,
            )
            unavailable = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
            require(
                unavailable["availableContexts"] == ["normal_cell_line", "normal_tissue"],
                f"CR1 unavailable state lost available contexts: {unavailable}",
            )

            page.evaluate("() => window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode('knowledge')")
            stopped = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
            restored = page.evaluate(
                """() => ({
                  knowledgeHidden: document.querySelector('#previewGraphWorkspace').hidden,
                  coexpressionHidden: document.querySelector('#previewCoexpressionWorkspace').hidden,
                  iframeCount: document.querySelectorAll('#coexpression-iframe-host iframe').length,
                })"""
            )
            require(not restored["knowledgeHidden"] and restored["coexpressionHidden"], f"Deactivation did not restore Knowledge Graph: {restored}")
            require(restored["iframeCount"] == 1, f"Deactivation destroyed the persistent iframe: {restored}")
            require(stopped["layoutStopped"] is True, f"Deactivation did not stop active layout work: {stopped}")

            page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.destroy()")
            destroyed = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
            require(
                destroyed["state"] == "idle" and destroyed["iframeCount"] == 0,
                f"Explicit teardown did not release the Co-expression iframe: {destroyed}",
            )

            require(not errors, f"Browser errors: {errors[:5]}")
            ok("Task 6 lazy workspace, MySQL loading, cache, unavailable state, iframe persistence, and desktop checks passed")
        finally:
            page.close()
            browser.close()


if __name__ == "__main__":
    run_check(main)
