from __future__ import annotations

import sys

from harness_lib import app_url, fail, ok, require, run_check


def evidence(
    loader_state: dict | None = None,
    graph_state: dict | None = None,
    frame_state: dict | None = None,
    legend_state: dict | None = None,
    detail_state: dict | None = None,
    console_errors: list[str] | None = None,
    page_errors: list[str] | None = None,
    failed_requests: list[str] | None = None,
) -> str:
    parts = []
    if loader_state is not None:
        parts.append(f"loader={loader_state}")
    if graph_state is not None:
        parts.append(f"surface={graph_state}")
    if frame_state is not None:
        parts.append(f"frame={frame_state}")
    if legend_state is not None:
        parts.append(f"legend={legend_state}")
    if detail_state is not None:
        parts.append(f"detail={detail_state}")
    if page_errors:
        parts.append("page_errors=" + " | ".join(page_errors[:5]))
    if console_errors:
        parts.append("console_errors=" + " | ".join(console_errors[:5]))
    if failed_requests:
        parts.append("failed_requests=" + " | ".join(failed_requests[:5]))
    return "\n".join(parts) if parts else "no extra evidence captured"


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail(
            "Playwright is not installed. Run:\n"
            "  pip install -r requirements-dev.txt\n"
            "  python -m playwright install chromium"
        )

    url = app_url("preview.php?q=LINE1")
    console_errors: list[str] = []
    page_errors: list[str] = []
    failed_requests: list[str] = []

    with sync_playwright() as p:
        try:
            browser = p.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(
                "Unable to launch Chromium. Run:\n"
                "  python -m playwright install chromium\n"
                f"Original error: {exc}"
            )

        page = browser.new_page(viewport={"width": 1440, "height": 960})
        page.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)
        page.on("pageerror", lambda exc: page_errors.append(str(exc)))
        page.on("requestfailed", lambda request: failed_requests.append(f"{request.url} :: {request.failure}"))

        try:
            page.goto(url, wait_until="domcontentloaded", timeout=30000)
            page.wait_for_selector("#g6-dynamic-surface iframe", timeout=30000)
            frame_locator = page.frame_locator("#g6-dynamic-surface iframe")
            frame_locator.locator("#container").wait_for(timeout=30000)
            frame_locator.locator("canvas, svg, #container").first.wait_for(timeout=30000)
            page.wait_for_timeout(2500)
            page.wait_for_function(
                """() => {
                    const loader = document.querySelector('#graph-preloader');
                    return loader && (
                        loader.getAttribute('aria-hidden') === 'true'
                        || !loader.classList.contains('is-visible')
                    );
                }""",
                timeout=30000,
            )

            loader_state = page.locator("#graph-preloader").evaluate(
                """el => ({
                    hidden: el ? el.getAttribute('aria-hidden') : null,
                    cls: el ? el.className : null,
                    text: el ? el.textContent : null
                })"""
            )
            graph_state = page.locator("#g6-dynamic-surface").evaluate(
                """el => {
                    const rect = el.getBoundingClientRect();
                    const iframe = el.querySelector('iframe');
                    return {
                        width: rect.width,
                        height: rect.height,
                        iframe: !!iframe,
                        text: el.textContent || ''
                    };
                }"""
            )
            frame_state = frame_locator.locator("#container").evaluate(
                """el => {
                    const rect = el.getBoundingClientRect();
                    return {
                        width: rect.width,
                        height: rect.height,
                        canvases: el.querySelectorAll('canvas').length,
                        svgs: el.querySelectorAll('svg').length,
                        children: el.children.length,
                        text: el.textContent || ''
                    };
                }"""
            )
            legend_state = page.locator("#graph-type-legend").evaluate(
                """el => {
                    const list = document.querySelector('#graph-legend-list');
                    return {
                        hidden: el ? el.hidden : null,
                        ariaHidden: el ? el.getAttribute('aria-hidden') : null,
                        text: list ? list.textContent.trim() : '',
                        childCount: list ? list.children.length : 0
                    };
                }"""
            )
            detail_state = page.locator("#node-details").evaluate(
                """el => ({
                    text: el ? el.textContent : '',
                    html: el ? el.innerHTML : ''
                })"""
            )

            captured = evidence(loader_state, graph_state, frame_state, legend_state, detail_state, console_errors, page_errors, failed_requests)
            require(graph_state["iframe"], "G6 dynamic surface has no iframe\n" + captured)
            require(graph_state["width"] > 100 and graph_state["height"] > 100, "G6 dynamic surface has invalid size\n" + captured)
            require(frame_state["width"] > 100 and frame_state["height"] > 100, "G6 iframe container has invalid size\n" + captured)
            require(
                frame_state["canvases"] > 0 or frame_state["svgs"] > 0 or frame_state["children"] > 0,
                "G6 iframe container appears blank\n" + captured,
            )
            require(loader_state["hidden"] == "true" or "is-visible" not in str(loader_state["cls"]), "G6 loader still visible\n" + captured)
            require("Loading legend" not in str(legend_state["text"]), "G6 legend still loading after graph render\n" + captured)
            require(legend_state["childCount"] > 0, "G6 legend did not render any entries\n" + captured)

            hidden_controls = page.evaluate(
                """() => {
                    const fixed = document.querySelector('#toggle-fixed-view');
                    const expand = document.querySelector('#toggle-expand-mode');
                    const state = (el) => {
                        if (!el) return { exists: false, visible: false };
                        const rect = el.getBoundingClientRect();
                        const style = window.getComputedStyle(el);
                        return {
                            exists: true,
                            visible: style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0,
                        };
                    };
                    return { fixed: state(fixed), expand: state(expand) };
                }"""
            )
            require(hidden_controls["fixed"]["exists"], f"Fixed view control missing from compatibility DOM: {hidden_controls}")
            require(hidden_controls["expand"]["exists"], f"Expand mode control missing from compatibility DOM: {hidden_controls}")
            require(not hidden_controls["fixed"]["visible"], f"Fixed view control is still user-visible: {hidden_controls}")
            require(not hidden_controls["expand"]["visible"], f"Expand mode control is still user-visible: {hidden_controls}")

            expanded = page.evaluate(
                """async () => {
                    const iframe = document.querySelector('#g6-dynamic-surface iframe');
                    const bridge = iframe?.contentWindow?.__TEKG_G6_EMBED;
                    const before = bridge?.getVisibleSubgraph ? bridge.getVisibleSubgraph() : null;
                    const nodes = before?.nodes || [];
                    const labelOf = (node) => String(node?.queryLabel || node?.rawLabel || node?.label || '').trim();
                    const target = nodes.find((node) => labelOf(node) === 'L1HS')
                        || nodes.find((node) => (node?.nodeType || node?.type) === 'TE' && labelOf(node))
                        || nodes.find((node) => labelOf(node));
                    if (!bridge || !target) {
                        return { ok: false, reason: 'missing bridge or expandable target', before, target: target || null };
                    }
                    const inspected = bridge.inspectNode(target.id);
                    const expanded = await bridge.triggerNodeAction(target.id, 'expand');
                    const after = bridge.getVisibleSubgraph();
                    return {
                        ok: Boolean(inspected && expanded),
                        inspected: Boolean(inspected),
                        expanded: Boolean(expanded),
                        target,
                        beforeCount: (before?.nodes?.length || 0) + (before?.edges?.length || 0),
                        afterCount: (after?.nodes?.length || 0) + (after?.edges?.length || 0),
                    };
                }"""
            )
            require(expanded["ok"], f"Node action card expand did not complete: {expanded}")
            require(expanded["afterCount"] >= expanded["beforeCount"], f"Node action card expand reduced visible graph size: {expanded}")
            page.wait_for_timeout(1000)
            post_toggle = page.locator("#g6-dynamic-surface").evaluate(
                """el => {
                    const rect = el.getBoundingClientRect();
                    const iframe = el.querySelector('iframe');
                    return { width: rect.width, height: rect.height, iframe: !!iframe };
                }"""
            )
            require(post_toggle["iframe"], f"Node action expand removed the G6 iframe: {post_toggle}")
            require(post_toggle["width"] > 100 and post_toggle["height"] > 100, f"Node action expand left invalid graph size: {post_toggle}")
        except PlaywrightTimeoutError as exc:
            fail(f"G6 browser smoke timed out at {url}: {exc}")
        except PlaywrightError as exc:
            fail(f"G6 browser smoke failed at {url}: {exc}")
        finally:
            browser.close()

    reference_errors = [message for message in console_errors + page_errors if "ReferenceError" in message]
    require(not reference_errors, "ReferenceError detected:\n" + "\n".join(reference_errors[:10]))
    require(not page_errors, "Page errors detected:\n" + "\n".join(page_errors[:10]))
    if failed_requests:
        print("[WARN] Failed browser requests:")
        for entry in failed_requests[:10]:
            print(f"  {entry}", file=sys.stderr)
    if console_errors:
        print("[WARN] Console errors:")
        for entry in console_errors[:10]:
            print(f"  {entry}", file=sys.stderr)
    ok(f"G6 browser smoke passed for {url}")


if __name__ == "__main__":
    run_check(main)
