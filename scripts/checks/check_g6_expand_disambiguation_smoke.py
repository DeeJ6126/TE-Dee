from __future__ import annotations

import json
from typing import Any
from urllib.parse import parse_qs, quote, unquote_plus, urlparse

from harness_lib import app_url, fail, ok, require, run_check


def evidence(data: dict[str, Any]) -> str:
    return json.dumps(data, ensure_ascii=True, indent=2, sort_keys=True)


def norm(value: Any) -> str:
    return " ".join(str(value or "").strip().lower().split())


def current_elements(state: dict[str, Any] | None) -> list[dict[str, Any]]:
    elements = state.get("currentElements") if isinstance(state, dict) else []
    return elements if isinstance(elements, list) else []


def data_of(item: Any) -> dict[str, Any]:
    data = item.get("data") if isinstance(item, dict) else None
    return data if isinstance(data, dict) else {}


def is_edge(data: dict[str, Any]) -> bool:
    return bool(data.get("source") and data.get("target"))


def find_node(elements: list[dict[str, Any]], label: str, node_type: str) -> dict[str, Any] | None:
    for item in elements:
        data = data_of(item)
        if not data or is_edge(data):
            continue
        labels = {norm(data.get("rawLabel")), norm(data.get("displayLabel")), norm(data.get("queryLabel")), norm(data.get("label"))}
        if norm(label) in labels and data.get("type") == node_type:
            return data
    return None


def request_params(url: str) -> dict[str, list[str]]:
    return parse_qs(urlparse(url).query)


def first_param(url: str, name: str) -> str:
    values = request_params(url).get(name) or []
    return unquote_plus(values[0]) if values else ""


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

    url = app_url(f"preview.php?q={quote('LINE-1')}")
    console_errors: list[str] = []
    page_errors: list[str] = []
    failed_requests: list[str] = []
    graph_requests: list[str] = []
    graph_responses: list[dict[str, Any]] = []

    with sync_playwright() as p:
        try:
            browser = p.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium. Run python -m playwright install chromium. Original error: {exc}")

        page = browser.new_page(viewport={"width": 1440, "height": 960})
        page.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)
        page.on("pageerror", lambda exc: page_errors.append(str(exc)))
        page.on("requestfailed", lambda request: failed_requests.append(f"{request.url} :: {request.failure}"))
        page.on("request", lambda request: graph_requests.append(request.url) if "api/graph.php" in request.url else None)

        def on_response(response: Any) -> None:
            if "api/graph.php" not in response.url:
                return
            try:
                payload = response.json()
            except Exception:
                payload = None
            graph_responses.append({"status": response.status, "url": response.url, "payload": payload})

        page.on("response", on_response)

        try:
            page.goto(url, wait_until="domcontentloaded", timeout=30000)
            page.wait_for_selector("#g6-dynamic-surface iframe", timeout=30000)
            page.frame_locator("#g6-dynamic-surface iframe").locator("#container").wait_for(timeout=30000)
            page.wait_for_function(
                """() => {
                    const loader = document.querySelector('#graph-preloader');
                    const legend = document.querySelector('#graph-legend-list');
                    return loader
                        && legend
                        && loader.getAttribute('aria-hidden') === 'true'
                        && !legend.textContent.includes('Loading legend');
                }""",
                timeout=45000,
            )

            state = page.evaluate(
                """() => {
                    const bridge = window.__TEKG_G6_BRIDGE;
                    return bridge && typeof bridge.getState === 'function' ? bridge.getState() : null;
                }"""
            )
            elements = current_elements(state if isinstance(state, dict) else None)
            target = find_node(elements, "Aging", "Disease")
            require(target is not None, "LINE-1 graph does not expose Disease:Aging in currentElements")
            target_id = str(target.get("id") or "")
            require(target_id, f"Disease:Aging missing node id: {target}")

            request_start = len(graph_requests)
            response_start = len(graph_responses)
            expanded = page.evaluate(
                """async ({ targetId }) => {
                    const bridge = window.__TEKG_G6_BRIDGE;
                    const iframe = document.querySelector('#g6-dynamic-surface iframe');
                    const embed = iframe && iframe.contentWindow ? iframe.contentWindow.__TEKG_G6_EMBED : null;
                    if (!bridge || typeof bridge.getState !== 'function' || !embed || typeof embed.triggerNodeAction !== 'function') {
                        return { ok: false, error: 'node action bridge missing' };
                    }
                    const state = bridge.getState();
                    const elements = Array.isArray(state.currentElements) ? state.currentElements : [];
                    const item = elements.find((entry) => entry && entry.data && entry.data.id === targetId);
                    if (!item) return { ok: false, error: 'target node not found' };
                    await embed.inspectNode(targetId);
                    const result = await embed.triggerNodeAction(targetId, 'expand');
                    return { ok: true, result, node: item.data };
                }""",
                {"targetId": target_id},
            )
            require(expanded.get("ok") is True, "Disease:Aging card Expand failed\n" + evidence({"expanded": expanded}))
            page.wait_for_timeout(1000)

            expand_requests = graph_requests[request_start:]
            expand_responses = graph_responses[response_start:]
            require(expand_requests, "Disease:Aging expand did not issue api/graph.php request")
            expand_url = expand_requests[-1]
            anchor_payload = None
            for entry in reversed(expand_responses):
                if entry.get("payload") and "api/graph.php" in str(entry.get("url")):
                    anchor_payload = entry["payload"]
                    break

            final_state = page.evaluate(
                """() => {
                    const bridge = window.__TEKG_G6_BRIDGE;
                    return bridge && typeof bridge.getState === 'function' ? bridge.getState() : null;
                }"""
            )
            iframe_canvas = page.frame_locator("#g6-dynamic-surface iframe").locator("#container").evaluate(
                """el => ({ canvases: el.querySelectorAll('canvas').length, children: el.children.length })"""
            )
            loader = page.locator("#graph-preloader").evaluate(
                """el => ({ hidden: el ? el.getAttribute('aria-hidden') : null, cls: el ? el.className : null })"""
            )

            captured = {
                "target": target,
                "expanded": expanded,
                "expand_url": expand_url,
                "expand_params": request_params(expand_url),
                "anchor_payload": anchor_payload,
                "final_state": {
                    "query": final_state.get("query") if isinstance(final_state, dict) else None,
                    "mode": final_state.get("mode") if isinstance(final_state, dict) else None,
                    "source": final_state.get("source") if isinstance(final_state, dict) else None,
                },
                "iframe_canvas": iframe_canvas,
                "loader": loader,
                "console_errors": console_errors,
                "page_errors": page_errors,
                "failed_requests": failed_requests,
            }

            require(first_param(expand_url, "q") == "Aging", "Disease:Aging expand q must be Aging\n" + evidence(captured))
            require(first_param(expand_url, "expand_node_id") == target_id, "Disease:Aging expand request missing exact node id\n" + evidence(captured))
            require(first_param(expand_url, "expand_node_type") == "Disease", "Disease:Aging expand request missing node type\n" + evidence(captured))
            require(first_param(expand_url, "expand_query") == "Aging", "Disease:Aging expand request missing expand_query\n" + evidence(captured))
            require(isinstance(anchor_payload, dict), "Disease:Aging expand response payload missing\n" + evidence(captured))
            anchor = anchor_payload.get("anchor")
            expanded_source = anchor_payload.get("expanded_source")
            require(isinstance(anchor, dict), "Disease:Aging expand response missing anchor\n" + evidence(captured))
            require(anchor.get("name") == "Aging", "Disease:Aging expand anchor name mismatch\n" + evidence(captured))
            require(anchor.get("type") == "Disease", "Disease:Aging expand anchor type must stay Disease\n" + evidence(captured))
            require(isinstance(expanded_source, dict), "Disease:Aging expand response missing expanded_source\n" + evidence(captured))
            require(expanded_source.get("id") == target_id, "Disease:Aging expanded_source id mismatch\n" + evidence(captured))
            require(expanded_source.get("type") == "Disease", "Disease:Aging expanded_source type mismatch\n" + evidence(captured))
            require(final_state.get("query") == "LINE-1", "Disease:Aging expand changed parent center query\n" + evidence(captured))
            require(iframe_canvas["canvases"] > 0 or iframe_canvas["children"] > 0, "Disease:Aging expand blanked iframe canvas\n" + evidence(captured))
            require(loader["hidden"] == "true" or "is-visible" not in str(loader["cls"]), "Disease:Aging expand left loader visible\n" + evidence(captured))
            require(not page_errors, "Disease:Aging expand produced page errors\n" + evidence(captured))
            require(not failed_requests, "Disease:Aging expand produced failed requests\n" + evidence(captured))
            print("[INFO] G6 same-label expand evidence:")
            print(evidence(captured))
        except PlaywrightTimeoutError as exc:
            fail(f"G6 same-label expand smoke timed out: {exc}")
        except PlaywrightError as exc:
            fail(f"G6 same-label expand smoke failed: {exc}")
        finally:
            browser.close()

    ok("G6 expand same-label disambiguation smoke passed for LINE-1 Disease:Aging")


if __name__ == "__main__":
    run_check(main)
