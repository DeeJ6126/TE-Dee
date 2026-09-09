from __future__ import annotations

from harness_lib import ROOT, app_url, fail, ok, require, run_check


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed.")

    evidence_dir = ROOT / "docs" / "eval" / "runs" / "2026-07-25-coexpression-dual-mode" / "desktop"
    evidence_dir.mkdir(parents=True, exist_ok=True)

    with sync_playwright() as playwright:
        try:
            browser = playwright.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")

        page = browser.new_page(viewport={"width": 1280, "height": 900})
        errors: list[str] = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.on("console", lambda message: errors.append(message.text) if message.type == "error" else None)
        try:
            page.goto(
                app_url("preview.php?mode=coexpression&te=LTR5&context=normal_tissue"),
                wait_until="domcontentloaded",
                timeout=30_000,
            )
            page.wait_for_function(
                """() => {
                  const state = window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.();
                  return state?.state === 'ready'
                    && state.selection?.te === 'LTR5'
                    && state.selection?.context === 'normal_tissue';
                }""",
                timeout=30_000,
            )
            if page.locator("#qaOverlay.is-open").count():
                page.click("#qaFab")
                page.wait_for_function("() => !document.querySelector('#qaOverlay').classList.contains('is-open')")

            before_expression = page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()"
            )
            page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.setExpressionEnabled(false)")
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().expressionEnabled === false"
            )
            page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.setExpressionEnabled(true)")
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().expressionEnabled === true"
            )
            after_expression = page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()"
            )
            require(
                before_expression["frameIdentity"] == after_expression["frameIdentity"]
                and before_expression["renderCount"] == after_expression["renderCount"],
                f"Expression toggling recreated or rerendered the graph: {before_expression} -> {after_expression}",
            )

            csv_text = page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.exportCsvText()"
            )
            require(
                "record_type,id,label,feature_type" in csv_text
                and "correlation" in csv_text
                and "fdr" in csv_text
                and "analysis_version" in csv_text
                and "not causation" in csv_text,
                "CSV export is missing graph or interpretation metadata.",
            )
            require(
                "D:\\" not in csv_text and "/data/coexpression/" not in csv_text,
                "CSV export leaked an absolute or internal data path.",
            )
            png = page.evaluate(
                "() => document.querySelector('#coexpression-graph-frame').contentWindow"
                ".__TEKG_COEXPRESSION_EMBED.exportPngDataUrl()"
            )
            require(
                isinstance(png, str) and png.startswith("data:image/png") and len(png) > 1000,
                "PNG export is blank.",
            )

            page.evaluate(
                "() => window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode('knowledge', {history:'push'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_PREVIEW_WORKSPACE_MODE.getMode() === 'knowledge'"
            )
            page.evaluate(
                "() => window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode('coexpression', "
                "{te:'L1HS', context:'cancer_cell_line', history:'push'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'"
                " && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection?.te === 'L1HS'",
                timeout=30_000,
            )
            page.evaluate("() => history.back()")
            page.wait_for_function(
                "() => window.__TEKG_PREVIEW_WORKSPACE_MODE.getMode() === 'knowledge'",
                timeout=20_000,
            )
            page.evaluate("() => history.back()")
            page.wait_for_function(
                """() => {
                  const mode = window.__TEKG_PREVIEW_WORKSPACE_MODE?.getMode?.();
                  const state = window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.();
                  return mode === 'coexpression' && state?.state === 'ready'
                    && state.selection?.te === 'LTR5'
                    && state.selection?.context === 'normal_tissue';
                }""",
                timeout=30_000,
            )
            require(
                "mode=coexpression" in page.url
                and "te=LTR5" in page.url
                and "context=normal_tissue" in page.url,
                f"Back/Forward restored state but not URL: {page.url}",
            )

            cache_cases = [
                ("L1HS", "cancer_cell_line"),
                ("LTR5", "cancer_cell_line"),
                ("MER11B", "normal_cell_line"),
                ("HERVH-int", "cancer_cell_line"),
                ("CR1", "normal_tissue"),
                ("AluJb", "normal_cell_line"),
                ("SART1", "normal_tissue"),
            ]
            for te, context in cache_cases:
                page.evaluate(
                    "([te, context]) => window.__TEKG_COEXPRESSION_MODE.activate({te, context})",
                    [te, context],
                )
                page.wait_for_function(
                    """([te, context]) => {
                      const state = window.__TEKG_COEXPRESSION_MODE.getDiagnostics();
                      return state.state === 'ready'
                        && state.selection?.te === te
                        && state.selection?.context === context;
                    }""",
                    arg=[te, context],
                    timeout=30_000,
                )
            cache = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
            require(
                len(cache["cacheKeys"]) == 6 and len(cache["expressionCacheKeys"]) <= 6,
                f"Bounded cache eviction failed: {cache}",
            )
            require(
                all(key.startswith("v1_abs0.4_fdr0.05_res1.8") for key in cache["cacheKeys"]),
                f"Network cache keys are not version-bound: {cache['cacheKeys']}",
            )

            failed_once = {"value": False}

            def fail_first_sart1(route) -> None:
                if (
                    not failed_once["value"]
                    and "api/coexpression.php" in route.request.url
                    and "action=network" in route.request.url
                    and "te=MER11B" in route.request.url
                    and "context=normal_tissue" in route.request.url
                ):
                    failed_once["value"] = True
                    route.abort()
                    return
                route.continue_()

            page.route("**/api/coexpression.php**", fail_first_sart1)
            page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.activate({te:'MER11B', context:'normal_tissue'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'error'",
                timeout=20_000,
            )
            failed = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
            require(
                failed["selection"] == {"te": "MER11B", "context": "normal_tissue"}
                and not page.locator("#coexpression-retry").is_hidden(),
                f"Failure recovery lost the requested selection or Retry control: {failed}",
            )
            page.unroute("**/api/coexpression.php**", fail_first_sart1)
            page.click("#coexpression-retry")
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'"
                " && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection?.te === 'MER11B'",
                timeout=30_000,
            )

            for width, height in ((1440, 960), (1280, 900), (1024, 768)):
                page.set_viewport_size({"width": width, "height": height})
                page.wait_for_timeout(150)
                layout = page.evaluate(
                    """() => {
                      const toolbar = document.querySelector('#previewCoexpressionWorkspace .preview-graph-toolbar');
                      const children = [...toolbar.children].filter((node) => getComputedStyle(node).display !== 'none');
                      const rects = children.map((node) => {
                        const rect = node.getBoundingClientRect();
                        return { id: node.id || node.className, left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom };
                      });
                      const overlaps = [];
                      for (let i = 0; i < rects.length; i += 1) {
                        for (let j = i + 1; j < rects.length; j += 1) {
                          const a = rects[i];
                          const b = rects[j];
                          if (a.left < b.right - 1 && a.right > b.left + 1 && a.top < b.bottom - 1 && a.bottom > b.top + 1) {
                            overlaps.push([a.id, b.id]);
                          }
                        }
                      }
                      const surface = document.querySelector('#previewCoexpressionWorkspace .preview-g6-surface-stack').getBoundingClientRect();
                      return { overlaps, surfaceWidth: surface.width, surfaceHeight: surface.height };
                    }"""
                )
                require(
                    not layout["overlaps"]
                    and layout["surfaceWidth"] > 700
                    and layout["surfaceHeight"] > 420,
                    f"Desktop controls overlap or collapse the graph at {width}x{height}: {layout}",
                )
                page.screenshot(
                    path=str(evidence_dir / f"desktop-{width}x{height}.png"),
                    full_page=True,
                )

            unexpected_errors = [error for error in errors if "net::ERR_FAILED" not in error]
            require(not unexpected_errors, f"Browser errors: {unexpected_errors[:5]}")
            ok("Task 9 URL, export, bounded caches, retry, and desktop viewport checks passed")
        finally:
            page.close()
            browser.close()


if __name__ == "__main__":
    run_check(main)
