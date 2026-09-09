from __future__ import annotations

import argparse
from dataclasses import dataclass
import json
import os
import sys
from typing import Any
from urllib.parse import parse_qs, quote, unquote_plus, urlparse

from harness_lib import app_url, fail, ok, require, run_check


def graph_counts_from_state(state: dict[str, Any] | None) -> dict[str, int]:
    elements = state.get("currentElements") if isinstance(state, dict) else []
    if not isinstance(elements, list):
        elements = []
    nodes = 0
    edges = 0
    for item in elements:
        data = item.get("data") if isinstance(item, dict) else None
        if not isinstance(data, dict):
            continue
        if data.get("source") and data.get("target"):
            edges += 1
        else:
            nodes += 1
    return {"nodes": nodes, "edges": edges, "elements": len(elements)}


def normalize_label(value: Any) -> str:
    return " ".join(str(value or "").strip().lower().split())


def label_aliases(query: str) -> set[str]:
    value = normalize_label(query)
    aliases = {value}
    if value in {"line1", "line-1", "line 1", "l1", "l1 (line-1)"}:
        aliases.update({"line1", "line-1", "line 1", "l1", "l1 (line-1)"})
    return {item for item in aliases if item}


def current_elements(state: dict[str, Any] | None) -> list[dict[str, Any]]:
    elements = state.get("currentElements") if isinstance(state, dict) else []
    return elements if isinstance(elements, list) else []


def is_edge_data(data: dict[str, Any]) -> bool:
    return bool(data.get("source") and data.get("target"))


def element_data(item: Any) -> dict[str, Any]:
    data = item.get("data") if isinstance(item, dict) else None
    return data if isinstance(data, dict) else {}


def element_key(item: Any) -> str:
    data = element_data(item)
    if not data:
        return ""
    if is_edge_data(data):
        fallback = f"{data.get('source')}__{data.get('relationType') or data.get('relation') or 'RELATION'}__{data.get('target')}"
        return f"edge:{data.get('id') or fallback}"
    return f"node:{data.get('id') or data.get('label') or data.get('rawLabel') or ''}"


def graph_identity(elements: list[dict[str, Any]]) -> dict[str, set[str]]:
    node_ids: set[str] = set()
    edge_ids: set[str] = set()
    keys: set[str] = set()
    for item in elements:
        data = element_data(item)
        key = element_key(item)
        if key:
            keys.add(key)
        if not data:
            continue
        if is_edge_data(data):
            edge_ids.add(str(data.get("id") or key))
        elif data.get("id"):
            node_ids.add(str(data.get("id")))
    return {"nodes": node_ids, "edges": edge_ids, "keys": keys}


def find_center_node(elements: list[dict[str, Any]], query: str) -> dict[str, Any] | None:
    aliases = label_aliases(query)
    for item in elements:
        data = element_data(item)
        if not data or is_edge_data(data):
            continue
        labels = {
            normalize_label(data.get("queryLabel")),
            normalize_label(data.get("rawLabel")),
            normalize_label(data.get("displayLabel")),
            normalize_label(data.get("label")),
            normalize_label(data.get("id")),
        }
        if aliases.intersection(labels):
            return data
    return None


def node_summary(node: dict[str, Any] | None) -> dict[str, Any] | None:
    if not isinstance(node, dict):
        return None
    return {
        "id": node.get("id"),
        "nodeType": node.get("nodeType"),
        "rawLabel": node.get("rawLabel"),
        "displayLabel": node.get("displayLabel"),
        "queryLabel": node.get("queryLabel"),
    }


def node_query_candidates(node: dict[str, Any] | None) -> set[str]:
    if not isinstance(node, dict):
        return set()
    values = [
        node.get("queryLabel"),
        node.get("rawLabel"),
        node.get("displayLabel"),
    ]
    return {normalize_label(value) for value in values if normalize_label(value)}


def query_from_graph_request(url: str) -> str:
    parsed = urlparse(url)
    values = parse_qs(parsed.query).get("q") or []
    return normalize_label(unquote_plus(values[0])) if values else ""


def url_has_query(url: str, query_values: set[str]) -> bool:
    if not url:
        return False
    parsed = urlparse(url)
    values = parse_qs(parsed.query).get("q") or []
    if not values:
        return False
    return normalize_label(unquote_plus(values[0])) in query_values


def connected_to_clicked(
    after_elements: list[dict[str, Any]],
    clicked_node_id: str,
    new_node_ids: set[str],
    new_edge_ids: set[str],
) -> bool:
    for item in after_elements:
        data = element_data(item)
        if not data or not is_edge_data(data):
            continue
        source = str(data.get("source") or "")
        target = str(data.get("target") or "")
        edge_id = str(data.get("id") or element_key(item))
        touches_clicked = clicked_node_id in {source, target}
        touches_new_node = source in new_node_ids or target in new_node_ids
        if touches_clicked and (edge_id in new_edge_ids or touches_new_node):
            return True
    return False


def classify_failure(
    before: dict[str, Any],
    after: dict[str, Any],
    graph_requests: list[str],
    failed_requests: list[str],
    console_errors: list[str],
    page_errors: list[str],
    clicked: bool,
) -> str:
    messages = " | ".join(console_errors + page_errors)
    graph_failures = [entry for entry in failed_requests if "api/graph.php" in entry]
    if page_errors or "ReferenceError" in messages:
        return "render/draw"
    if "G6 embed bridge cannot expand graph requests" in messages:
        return "iframe bridge"
    if graph_failures:
        return "API request"
    if clicked and not graph_requests:
        return "parent click handling"
    if graph_requests and after["counts"]["elements"] <= before["counts"]["elements"]:
        return "expandGraph or mergeGraphElements"
    if after["frame"]["canvases"] <= 0 and after["frame"]["children"] <= 0:
        return "render/draw"
    return "undetermined"


def evidence(data: dict[str, Any]) -> str:
    return json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True)


@dataclass
class ClickProbe:
    x_ratio: float
    y_ratio: float
    label: str


CLICK_PROBES = [
    ClickProbe(0.35, 0.42, "upper-left-near-node"),
    ClickProbe(0.65, 0.42, "upper-right-near-node"),
    ClickProbe(0.32, 0.58, "lower-left-near-node"),
    ClickProbe(0.68, 0.58, "lower-right-near-node"),
    ClickProbe(0.50, 0.32, "top-near-node"),
    ClickProbe(0.50, 0.68, "bottom-near-node"),
    ClickProbe(0.24, 0.50, "left-near-node"),
    ClickProbe(0.76, 0.50, "right-near-node"),
]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Smoke-test TE-KG G6 Expand mode semantics.")
    parser.add_argument(
        "--query",
        action="append",
        help="Initial preview.php query to test. May be passed multiple times. Defaults to LINE1, L1HS, LINE-1.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    queries = [item.strip() for item in (args.query or []) if item and item.strip()]
    if not queries:
        env_query = os.environ.get("TEKG_G6_EXPAND_QUERY", "").strip()
        queries = [env_query] if env_query else ["LINE1", "L1HS", "LINE-1"]

    results: list[dict[str, Any]] = []

    for query in queries:
        run_one_query(query, results)

    ok("G6 expand mode smoke passed for " + ", ".join(queries))


def run_one_query(query: str, results: list[dict[str, Any]]) -> None:
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

    url = app_url(f"preview.php?q={quote(query)}")
    console_errors: list[str] = []
    page_errors: list[str] = []
    failed_requests: list[str] = []
    graph_requests: list[str] = []
    graph_responses: list[str] = []

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
        page.on("request", lambda request: graph_requests.append(request.url) if "api/graph.php" in request.url else None)
        page.on("response", lambda response: graph_responses.append(f"{response.status} {response.url}") if "api/graph.php" in response.url else None)

        def snapshot() -> dict[str, Any]:
            loader = page.locator("#graph-preloader").evaluate(
                """el => ({
                    hidden: el ? el.getAttribute('aria-hidden') : null,
                    cls: el ? el.className : null,
                    text: el ? el.textContent.trim() : ''
                })"""
            )
            legend = page.locator("#graph-type-legend").evaluate(
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
            surface = page.locator("#g6-dynamic-surface").evaluate(
                """el => {
                    const rect = el.getBoundingClientRect();
                    const iframe = el.querySelector('iframe');
                    return {
                        width: rect.width,
                        height: rect.height,
                        iframe: !!iframe,
                        iframeSrc: iframe ? iframe.src : '',
                        text: el.textContent || ''
                    };
                }"""
            )
            frame = page.frame_locator("#g6-dynamic-surface iframe").locator("#container").evaluate(
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
            state = page.evaluate(
                """() => {
                    const bridge = window.__TEKG_G6_BRIDGE;
                    if (!bridge || typeof bridge.getState !== 'function') return null;
                    return bridge.getState();
                }"""
            )
            return {
                "loader": loader,
                "legend": legend,
                "surface": surface,
                "frame": frame,
                "state": {
                    "mode": state.get("mode") if isinstance(state, dict) else None,
                    "source": state.get("source") if isinstance(state, dict) else None,
                    "query": state.get("query") if isinstance(state, dict) else None,
                    "expandModeEnabled": state.get("expandModeEnabled") if isinstance(state, dict) else None,
                    "selectedNode": state.get("selectedNode") if isinstance(state, dict) else None,
                },
                "counts": graph_counts_from_state(state if isinstance(state, dict) else None),
                "elements": current_elements(state if isinstance(state, dict) else None),
            }

        try:
            page.goto(url, wait_until="domcontentloaded", timeout=30000)
            page.wait_for_selector("#g6-dynamic-surface iframe", timeout=30000)
            page.frame_locator("#g6-dynamic-surface iframe").locator("#container").wait_for(timeout=30000)
            page.wait_for_function(
                """() => {
                    const loader = document.querySelector('#graph-preloader');
                    const legend = document.querySelector('#graph-legend-list');
                    const iframe = document.querySelector('#g6-dynamic-surface iframe');
                    return loader
                        && legend
                        && iframe
                        && loader.getAttribute('aria-hidden') === 'true'
                        && !legend.textContent.includes('Loading legend');
                }""",
                timeout=45000,
            )

            before = snapshot()
            before_elements = before["elements"]
            before_identity = graph_identity(before_elements)
            center_node = find_center_node(before_elements, query)
            center_node_id = str(center_node.get("id") or "") if center_node else ""
            require(before["counts"]["nodes"] > 0, "Initial graph has no parent node count\n" + evidence({"before": before}))
            require(before["frame"]["canvases"] > 0 or before["frame"]["children"] > 0, "Initial graph appears blank\n" + evidence({"before": before}))
            require(center_node_id, "Initial center node is missing from currentElements\n" + evidence({
                "query": query,
                "before_counts": before["counts"],
                "sample_node_labels": [
                    node_summary(element_data(item))
                    for item in before_elements
                    if element_data(item) and not is_edge_data(element_data(item))
                ][:12],
            }))

            after_toggle = snapshot()
            require(after_toggle["state"]["mode"] == "dynamic", "Card Expand setup left graph outside dynamic mode\n" + evidence({"before": before, "after_toggle": after_toggle}))
            require(after_toggle["state"]["source"] == "query", "Card Expand setup left graph outside query source\n" + evidence({"before": before, "after_toggle": after_toggle}))
            require(after_toggle["state"]["query"] == query, "Card Expand setup changed the center query\n" + evidence({"before": before, "after_toggle": after_toggle}))
            require(after_toggle["frame"]["canvases"] > 0 or after_toggle["frame"]["children"] > 0, "Card Expand setup has blank graph\n" + evidence({"before": before, "after_toggle": after_toggle}))
            require(after_toggle["loader"]["hidden"] == "true" or "is-visible" not in str(after_toggle["loader"]["cls"]), "Card Expand setup left loader visible\n" + evidence({"before": before, "after_toggle": after_toggle}))

            initial_request_count = len(graph_requests)
            expanded = page.evaluate(
                """async () => {
                    const iframe = document.querySelector('#g6-dynamic-surface iframe');
                    const embed = iframe && iframe.contentWindow ? iframe.contentWindow.__TEKG_G6_EMBED : null;
                    if (!embed || typeof embed.getVisibleSubgraph !== 'function' || typeof embed.triggerNodeAction !== 'function') {
                        return { ok: false, error: 'node action bridge missing' };
                    }
                    const subgraph = await embed.getVisibleSubgraph();
                    const nodes = Array.isArray(subgraph && subgraph.nodes) ? subgraph.nodes : [];
                    const preferred = nodes.find((item) => String(item.rawLabel || item.label || '').trim() === 'L1HS');
                    const node = preferred || nodes.find((item) => item.id && item.type !== 'Paper' && String(item.rawLabel || item.label || '').trim() !== String(subgraph.query || '').trim());
                    if (!node) return { ok: false, error: 'expand target not found' };
                    await embed.inspectNode(node.id);
                    const result = await embed.triggerNodeAction(node.id, 'expand');
                    return { ok: true, result, node };
                }"""
            )
            clicked_probe = "card-expand"
            click_snapshots: list[dict[str, Any]] = [{"probe": clicked_probe, "expanded": expanded}]
            require(expanded.get("ok") is True, "Card Expand did not trigger a node interaction\n" + evidence({"before": before, "after_toggle": after_toggle, "expanded": expanded}))
            page.wait_for_timeout(8000)

            after_click = snapshot()
            after_elements = after_click["elements"]
            after_identity = graph_identity(after_elements)
            clicked_node = after_click["state"]["selectedNode"]
            clicked_node_id = str(clicked_node.get("id") or "") if isinstance(clicked_node, dict) else ""
            clicked_queries = node_query_candidates(clicked_node)
            graph_requests_after_click = graph_requests[initial_request_count:]
            graph_request_queries = [query_from_graph_request(item) for item in graph_requests_after_click]
            new_node_ids = after_identity["nodes"] - before_identity["nodes"]
            new_edge_ids = after_identity["edges"] - before_identity["edges"]
            center_still_present = center_node_id in after_identity["nodes"]
            neighbor_added = connected_to_clicked(after_elements, clicked_node_id, new_node_ids, new_edge_ids)
            if after_click["loader"]["hidden"] == "false" or "is-visible" in str(after_click["loader"]["cls"]):
                try:
                    page.wait_for_function(
                        """() => {
                            const loader = document.querySelector('#graph-preloader');
                            return loader && loader.getAttribute('aria-hidden') === 'true';
                        }""",
                        timeout=30000,
                    )
                except PlaywrightTimeoutError:
                    after_timeout = snapshot()
                    layer = classify_failure(after_toggle, after_timeout, graph_requests_after_click, failed_requests, console_errors, page_errors, True)
                    fail("Expand mode loader stuck after node click; layer=" + layer + "\n" + evidence({
                        "clicked_probe": clicked_probe,
                        "before": before,
                        "after_toggle": after_toggle,
                        "after_timeout": after_timeout,
                        "click_snapshots": click_snapshots,
                        "graph_requests_after_click": graph_requests_after_click,
                        "graph_responses": graph_responses,
                        "failed_requests": failed_requests,
                        "console_errors": console_errors,
                        "page_errors": page_errors,
                    }))
                after_click = snapshot()
                after_elements = after_click["elements"]
                after_identity = graph_identity(after_elements)
                clicked_node = after_click["state"]["selectedNode"]
                clicked_node_id = str(clicked_node.get("id") or "") if isinstance(clicked_node, dict) else ""
                clicked_queries = node_query_candidates(clicked_node)
                graph_requests_after_click = graph_requests[initial_request_count:]
                graph_request_queries = [query_from_graph_request(item) for item in graph_requests_after_click]
                new_node_ids = after_identity["nodes"] - before_identity["nodes"]
                new_edge_ids = after_identity["edges"] - before_identity["edges"]
                center_still_present = center_node_id in after_identity["nodes"]
                neighbor_added = connected_to_clicked(after_elements, clicked_node_id, new_node_ids, new_edge_ids)

            layer = classify_failure(after_toggle, after_click, graph_requests_after_click, failed_requests, console_errors, page_errors, True)
            captured = {
                "query": query,
                "center_node": node_summary(center_node),
                "clicked_probe": clicked_probe,
                "clicked_node": node_summary(clicked_node),
                "clicked_query_candidates": sorted(clicked_queries),
                "before": before,
                "after_toggle": after_toggle,
                "after_click": after_click,
                "graph_requests_after_click": graph_requests_after_click,
                "graph_request_queries": graph_request_queries,
                "graph_responses": graph_responses,
                "new_node_ids": sorted(new_node_ids),
                "new_edge_ids": sorted(new_edge_ids),
                "center_still_present": center_still_present,
                "neighbor_added": neighbor_added,
                "click_snapshots": click_snapshots,
                "failed_requests": failed_requests,
                "console_errors": console_errors,
                "page_errors": page_errors,
                "classified_layer": layer,
            }
            require(clicked_probe is not None, "Card Expand did not trigger a node interaction; layer=" + layer + "\n" + evidence(captured))
            require(clicked_node_id, "Expand mode click did not expose clicked node metadata\n" + evidence(captured))
            require(clicked_node_id != center_node_id, "Expand mode clicked the initial center node, not a non-center node\n" + evidence(captured))
            require(clicked_queries, "Clicked node has no queryLabel/rawLabel/displayLabel fallback\n" + evidence(captured))
            require(graph_requests_after_click, "Expand mode click did not issue api/graph.php request\n" + evidence(captured))
            require(
                any(item in clicked_queries for item in graph_request_queries),
                "Expand mode api/graph.php request does not match clicked node query\n" + evidence(captured),
            )
            require(after_click["state"]["mode"] == "dynamic", "Expand mode click changed parent mode\n" + evidence(captured))
            require(after_click["state"]["source"] == "query", "Expand mode click changed parent source\n" + evidence(captured))
            require(after_click["state"]["query"] == query, "Expand mode click changed parent center query\n" + evidence(captured))
            require(center_still_present, "Initial center node disappeared from currentElements\n" + evidence(captured))
            require(not url_has_query(after_click["surface"]["iframeSrc"], clicked_queries), "Iframe URL jumped to clicked node query\n" + evidence(captured))
            require(after_click["frame"]["canvases"] > 0 or after_click["frame"]["children"] > 0, "Expand mode click blanked graph; layer=" + layer + "\n" + evidence(captured))
            require(after_click["loader"]["hidden"] == "true" or "is-visible" not in str(after_click["loader"]["cls"]), "Expand mode click left loader visible; layer=" + layer + "\n" + evidence(captured))
            require("Loading legend" not in str(after_click["legend"]["text"]), "Expand mode click returned legend to loading state; layer=" + layer + "\n" + evidence(captured))
            require(not page_errors, "Page errors after Expand mode click; layer=" + layer + "\n" + evidence(captured))
            require(not [message for message in console_errors if "ReferenceError" in message], "ReferenceError after Expand mode click; layer=" + layer + "\n" + evidence(captured))
            require(new_node_ids or new_edge_ids, "Expand mode click did not add new node/edge IDs\n" + evidence(captured))
            require(neighbor_added, "Expand mode click added elements, but not as clicked-node neighbors\n" + evidence(captured))
            print("[INFO] Expand evidence:")
            print(evidence({
                "query": query,
                "center_node": node_summary(center_node),
                "clicked_probe": clicked_probe,
                "clicked_node": node_summary(clicked_node),
                "graph_request_queries": graph_request_queries,
                "new_node_ids": sorted(new_node_ids),
                "new_edge_ids": sorted(new_edge_ids),
                "center_still_present": center_still_present,
                "neighbor_added": neighbor_added,
                "before_counts": before["counts"],
                "after_toggle_counts": after_toggle["counts"],
                "after_click_counts": after_click["counts"],
                "after_toggle_frame": after_toggle["frame"],
                "after_click_frame": after_click["frame"],
                "after_click_loader": after_click["loader"],
                "after_click_legend": after_click["legend"],
                "graph_requests_after_click": graph_requests_after_click,
                "graph_responses": graph_responses,
                "failed_requests": failed_requests,
                "console_errors": console_errors,
                "page_errors": page_errors,
                "classified_layer": layer,
            }))
            results.append({
                "query": query,
                "clicked_node": node_summary(clicked_node),
                "before_counts": before["counts"],
                "after_click_counts": after_click["counts"],
                "new_nodes": len(new_node_ids),
                "new_edges": len(new_edge_ids),
            })
        except PlaywrightTimeoutError as exc:
            fail(f"G6 expand mode smoke timed out at {url}: {exc}")
        except PlaywrightError as exc:
            fail(f"G6 expand mode smoke failed at {url}: {exc}")
        finally:
            browser.close()

    if failed_requests:
        print("[WARN] Failed browser requests:")
        for entry in failed_requests[:10]:
            print(f"  {entry}", file=sys.stderr)


if __name__ == "__main__":
    run_check(main)
