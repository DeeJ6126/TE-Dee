from __future__ import annotations

import argparse
import json
from typing import Any
from urllib.parse import quote

from harness_lib import app_url, fail, ok, require, run_check


CLICK_PROBES = [
    (0.35, 0.42, "upper-left-near-node"),
    (0.65, 0.42, "upper-right-near-node"),
    (0.32, 0.58, "lower-left-near-node"),
    (0.68, 0.58, "lower-right-near-node"),
    (0.50, 0.32, "top-near-node"),
    (0.50, 0.68, "bottom-near-node"),
    (0.24, 0.50, "left-near-node"),
    (0.76, 0.50, "right-near-node"),
]


def evidence(data: dict[str, Any]) -> str:
    return json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Diagnose G6 Expand mode incremental layout.")
    parser.add_argument("--query", default="L1HS", help="Initial preview.php query.")
    return parser.parse_args()


def init_script() -> str:
    return r"""
(() => {
  window.__TEKG_G6_EXPAND_LAYOUT_DIAG = {
    calls: [],
    graphs: [],
    record(name, detail) {
      this.calls.push({
        name,
        detail,
        at: Date.now(),
      });
    },
  };

  function summarizeNode(node) {
    if (!node) return null;
    const style = node.style || {};
    return {
      id: node.id,
      nodeType: node.nodeType || node.data?.nodeType || node.type || node.data?.type || null,
      rawLabel: node.rawLabel || node.data?.rawLabel || null,
      displayLabel: node.displayLabel || node.data?.displayLabel || node.label || null,
      queryLabel: node.queryLabel || node.data?.queryLabel || null,
      x: node.x ?? style.x ?? null,
      y: node.y ?? style.y ?? null,
      z: node.z ?? style.z ?? null,
      vx: node.vx ?? style.vx ?? null,
      vy: node.vy ?? style.vy ?? null,
      fx: node.fx ?? style.fx ?? null,
      fy: node.fy ?? style.fy ?? null,
      styleX: style.x ?? null,
      styleY: style.y ?? null,
      hasStyle: !!node.style,
    };
  }

  function patchGraphClass(Graph) {
    if (!Graph || !Graph.prototype || Graph.prototype.__tekgExpandDiagPatched) return;
    Graph.prototype.__tekgExpandDiagPatched = true;
    for (const method of ['addNodeData', 'addEdgeData', 'draw', 'layout', 'render', 'getNodeData', 'getEdgeData', 'getBehaviors']) {
      const original = Graph.prototype[method];
      if (typeof original !== 'function') continue;
      Graph.prototype[method] = function (...args) {
        const diag = window.__TEKG_G6_EXPAND_LAYOUT_DIAG;
        if (diag && !diag.graphs.includes(this)) diag.graphs.push(this);
        const detail = {};
        if (method === 'addNodeData') {
          const nodes = Array.isArray(args[0]) ? args[0] : [];
          detail.count = nodes.length;
          detail.nodes = nodes.map(summarizeNode);
        } else if (method === 'addEdgeData') {
          const edges = Array.isArray(args[0]) ? args[0] : [];
          detail.count = edges.length;
          detail.edges = edges.map((edge) => ({
            id: edge.id || null,
            source: edge.source || null,
            target: edge.target || null,
          }));
        } else {
          detail.argCount = args.length;
        }
        diag?.record(method, detail);
        const result = original.apply(this, args);
        if (result && typeof result.then === 'function') {
          return result.then((value) => {
            diag?.record(`${method}:resolved`, {});
            return value;
          }, (error) => {
            diag?.record(`${method}:rejected`, { message: error && error.message ? error.message : String(error) });
            throw error;
          });
        }
        return result;
      };
    }
  }

  Object.defineProperty(window, 'G6', {
    configurable: true,
    get() {
      return this.__TEKG_G6_VALUE;
    },
    set(value) {
      this.__TEKG_G6_VALUE = value;
      patchGraphClass(value && value.Graph);
    },
  });

  window.__TEKG_G6_EXPAND_LAYOUT_SNAPSHOT = () => {
    const diag = window.__TEKG_G6_EXPAND_LAYOUT_DIAG;
    const graph = diag && diag.graphs[diag.graphs.length - 1];
    let nodes = [];
    let edges = [];
    let behaviors = [];
    try {
      nodes = graph && typeof graph.getNodeData === 'function' ? graph.getNodeData().map(summarizeNode) : [];
    } catch (error) {
      diag?.record('getNodeData:snapshot-error', { message: error && error.message ? error.message : String(error) });
    }
    try {
      edges = graph && typeof graph.getEdgeData === 'function' ? graph.getEdgeData().map((edge) => ({
        id: edge.id || null,
        source: edge.source || null,
        target: edge.target || null,
      })) : [];
    } catch (error) {
      diag?.record('getEdgeData:snapshot-error', { message: error && error.message ? error.message : String(error) });
    }
    try {
      behaviors = graph && typeof graph.getBehaviors === 'function' ? graph.getBehaviors() : [];
    } catch (error) {
      diag?.record('getBehaviors:snapshot-error', { message: error && error.message ? error.message : String(error) });
    }
    return {
      calls: diag ? diag.calls : [],
      nodes,
      edges,
      behaviors,
    };
  };
})();
"""


def patch_existing_g6_script() -> str:
    return r"""
() => {
  const diag = window.__TEKG_G6_EXPAND_LAYOUT_DIAG || {
    calls: [],
    graphs: [],
    record(name, detail) {
      this.calls.push({ name, detail, at: Date.now() });
    },
  };
  window.__TEKG_G6_EXPAND_LAYOUT_DIAG = diag;

  function summarizeNode(node) {
    if (!node) return null;
    const style = node.style || {};
    return {
      id: node.id,
      nodeType: node.nodeType || node.data?.nodeType || node.type || node.data?.type || null,
      rawLabel: node.rawLabel || node.data?.rawLabel || null,
      displayLabel: node.displayLabel || node.data?.displayLabel || node.label || null,
      queryLabel: node.queryLabel || node.data?.queryLabel || null,
      x: node.x ?? style.x ?? null,
      y: node.y ?? style.y ?? null,
      vx: node.vx ?? style.vx ?? null,
      vy: node.vy ?? style.vy ?? null,
      fx: node.fx ?? style.fx ?? null,
      fy: node.fy ?? style.fy ?? null,
      styleX: style.x ?? null,
      styleY: style.y ?? null,
      hasStyle: !!node.style,
    };
  }

  const Graph = window.G6 && window.G6.Graph;
  if (!Graph || !Graph.prototype) {
    diag.record('patch:missing-graph-class', { hasG6: !!window.G6, keys: window.G6 ? Object.keys(window.G6).slice(0, 20) : [] });
    return false;
  }
  if (!Graph.prototype.__tekgExpandDiagPatchedLate) {
    Graph.prototype.__tekgExpandDiagPatchedLate = true;
    for (const method of ['addNodeData', 'addEdgeData', 'draw', 'layout', 'render', 'getNodeData', 'getEdgeData', 'getBehaviors']) {
      const original = Graph.prototype[method];
      if (typeof original !== 'function') continue;
      Graph.prototype[method] = function (...args) {
        if (!diag.graphs.includes(this)) diag.graphs.push(this);
        const detail = {};
        if (method === 'addNodeData') {
          const nodes = Array.isArray(args[0]) ? args[0] : [];
          detail.count = nodes.length;
          detail.nodes = nodes.map(summarizeNode);
        } else if (method === 'addEdgeData') {
          const edges = Array.isArray(args[0]) ? args[0] : [];
          detail.count = edges.length;
          detail.edges = edges.map((edge) => ({
            id: edge.id || null,
            source: edge.source || null,
            target: edge.target || null,
          }));
        } else {
          detail.argCount = args.length;
        }
        diag.record(method, detail);
        const result = original.apply(this, args);
        if (result && typeof result.then === 'function') {
          return result.then((value) => {
            diag.record(`${method}:resolved`, {});
            return value;
          }, (error) => {
            diag.record(`${method}:rejected`, { message: error && error.message ? error.message : String(error) });
            throw error;
          });
        }
        return result;
      };
    }
  }
  window.__TEKG_G6_EXPAND_LAYOUT_SNAPSHOT = () => {
    const graph = diag.graphs[diag.graphs.length - 1];
    let nodes = [];
    let edges = [];
    let behaviors = [];
    try {
      nodes = graph && typeof graph.getNodeData === 'function' ? graph.getNodeData().map(summarizeNode) : [];
    } catch (error) {
      diag.record('getNodeData:snapshot-error', { message: error && error.message ? error.message : String(error) });
    }
    try {
      edges = graph && typeof graph.getEdgeData === 'function' ? graph.getEdgeData().map((edge) => ({
        id: edge.id || null,
        source: edge.source || null,
        target: edge.target || null,
      })) : [];
    } catch (error) {
      diag.record('getEdgeData:snapshot-error', { message: error && error.message ? error.message : String(error) });
    }
    try {
      behaviors = graph && typeof graph.getBehaviors === 'function' ? graph.getBehaviors() : [];
    } catch (error) {
      diag.record('getBehaviors:snapshot-error', { message: error && error.message ? error.message : String(error) });
    }
    return { calls: diag.calls, nodes, edges, behaviors };
  };
  diag.record('patch:installed', { methods: Object.keys(Graph.prototype).filter((key) => typeof Graph.prototype[key] === 'function').slice(0, 40) });
  return true;
}
"""


def graph_elements(state: dict[str, Any] | None) -> list[dict[str, Any]]:
    elements = state.get("currentElements") if isinstance(state, dict) else []
    return elements if isinstance(elements, list) else []


def element_data(item: Any) -> dict[str, Any]:
    data = item.get("data") if isinstance(item, dict) else None
    return data if isinstance(data, dict) else {}


def parent_ids(elements: list[dict[str, Any]]) -> dict[str, set[str]]:
    nodes: set[str] = set()
    edges: set[str] = set()
    for item in elements:
      data = element_data(item)
      if not data:
          continue
      if data.get("source") and data.get("target"):
          edges.add(str(data.get("id") or f"{data.get('source')}__{data.get('relationType') or data.get('relation') or 'RELATION'}__{data.get('target')}"))
      elif data.get("id"):
          nodes.add(str(data.get("id")))
    return {"nodes": nodes, "edges": edges}


def coordinate_key(node: dict[str, Any]) -> str:
    x = node.get("x")
    y = node.get("y")
    if x is None or y is None:
        return "missing"
    try:
        return f"{round(float(x), 2)},{round(float(y), 2)}"
    except (TypeError, ValueError):
        return "invalid"


def run_one(query: str) -> None:
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
        page.add_init_script(init_script())
        page.on("console", lambda msg: console_errors.append(msg.text) if msg.type in {"error", "warning"} else None)
        page.on("pageerror", lambda exc: page_errors.append(str(exc)))
        page.on("requestfailed", lambda request: failed_requests.append(f"{request.url} :: {request.failure}"))
        page.on("request", lambda request: graph_requests.append(request.url) if "api/graph.php" in request.url else None)

        def parent_snapshot() -> dict[str, Any]:
            state = page.evaluate(
                """() => {
                    const bridge = window.__TEKG_G6_BRIDGE;
                    if (!bridge || typeof bridge.getState !== 'function') return null;
                    return bridge.getState();
                }"""
            )
            frame = page.frame_locator("#g6-dynamic-surface iframe").locator("#container").evaluate(
                """el => ({
                    canvases: el.querySelectorAll('canvas').length,
                    children: el.children.length,
                })"""
            )
            loader = page.locator("#graph-preloader").evaluate(
                """el => ({
                    hidden: el ? el.getAttribute('aria-hidden') : null,
                    cls: el ? el.className : null,
                })"""
            )
            return {
                "state": state,
                "frame": frame,
                "loader": loader,
                "elements": graph_elements(state if isinstance(state, dict) else None),
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
            patched = page.frame_locator("#g6-dynamic-surface iframe").locator("body").evaluate(patch_existing_g6_script())
            require(patched is True, "Unable to patch existing G6 Graph class for layout diagnostics")

            before_parent = parent_snapshot()
            before_ids = parent_ids(before_parent["elements"])
            before_diag = page.frame_locator("#g6-dynamic-surface iframe").locator("body").evaluate(
                "() => window.__TEKG_G6_EXPAND_LAYOUT_SNAPSHOT ? window.__TEKG_G6_EXPAND_LAYOUT_SNAPSHOT() : null"
            )

            request_count = len(graph_requests)
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
            require(expanded.get("ok") is True, "Could not trigger card Expand request\n" + evidence({"query": query, "expanded": expanded, "graph_requests": graph_requests}))
            clicked_probe = "card-expand"
            require(len(graph_requests) > request_count, "Card Expand did not issue graph request\n" + evidence({"query": query, "expanded": expanded, "graph_requests": graph_requests}))
            page.wait_for_timeout(8000)
            try:
                page.wait_for_function(
                    """() => {
                        const loader = document.querySelector('#graph-preloader');
                        return loader && loader.getAttribute('aria-hidden') === 'true';
                    }""",
                    timeout=30000,
                )
            except PlaywrightTimeoutError:
                pass

            after_parent = parent_snapshot()
            after_ids = parent_ids(after_parent["elements"])
            after_diag = page.frame_locator("#g6-dynamic-surface iframe").locator("body").evaluate(
                "() => window.__TEKG_G6_EXPAND_LAYOUT_SNAPSHOT ? window.__TEKG_G6_EXPAND_LAYOUT_SNAPSHOT() : null"
            )

            new_node_ids = after_ids["nodes"] - before_ids["nodes"]
            new_edge_ids = after_ids["edges"] - before_ids["edges"]
            graph_nodes = {str(node.get("id")): node for node in (after_diag or {}).get("nodes", []) if node.get("id")}
            new_graph_nodes = [graph_nodes[node_id] for node_id in sorted(new_node_ids) if node_id in graph_nodes]
            coord_keys = [coordinate_key(node) for node in new_graph_nodes]
            unique_coord_keys = sorted(set(coord_keys))
            missing_in_graph = sorted(node_id for node_id in new_node_ids if node_id not in graph_nodes)
            call_names = [item.get("name") for item in (after_diag or {}).get("calls", [])]
            evidence_payload = {
                "query": query,
                "clicked_probe": clicked_probe,
                "new_node_ids": sorted(new_node_ids),
                "new_edge_ids": sorted(new_edge_ids),
                "new_graph_nodes": new_graph_nodes,
                "coordinate_keys": coord_keys,
                "unique_coordinate_keys": unique_coord_keys,
                "missing_in_graph": missing_in_graph,
                "before_diag_call_names": [item.get("name") for item in (before_diag or {}).get("calls", [])],
                "after_diag_call_names": call_names,
                "add_node_calls": [item for item in (after_diag or {}).get("calls", []) if item.get("name") == "addNodeData"],
                "add_edge_calls": [item for item in (after_diag or {}).get("calls", []) if item.get("name") == "addEdgeData"],
                "draw_calls": [item for item in (after_diag or {}).get("calls", []) if item.get("name") in {"draw", "draw:resolved"}],
                "layout_calls": [item for item in (after_diag or {}).get("calls", []) if item.get("name") in {"layout", "layout:resolved"}],
                "render_calls": [item for item in (after_diag or {}).get("calls", []) if item.get("name") in {"render", "render:resolved"}],
                "behaviors": (after_diag or {}).get("behaviors", []),
                "frame": after_parent["frame"],
                "loader": after_parent["loader"],
                "console_errors": console_errors,
                "page_errors": page_errors,
                "failed_requests": failed_requests,
            }

            print("[INFO] Expand layout evidence:")
            print(evidence(evidence_payload))

            require(new_node_ids, "Expand did not add new nodes\n" + evidence(evidence_payload))
            require(not missing_in_graph, "New parent nodes are missing from G6 graph data\n" + evidence(evidence_payload))
            require("addNodeData" in call_names, "expandGraph did not call graph.addNodeData\n" + evidence(evidence_payload))
            require("addEdgeData" in call_names, "expandGraph did not call graph.addEdgeData\n" + evidence(evidence_payload))
            require("draw" in call_names, "expandGraph did not call graph.draw\n" + evidence(evidence_payload))
            require("layout" in call_names or "render" in call_names, "expandGraph added nodes and drew them, but did not trigger layout/render\n" + evidence(evidence_payload))
            require(len(unique_coord_keys) > 1 and "missing" not in unique_coord_keys, "New G6 nodes are missing coordinates or collapsed to one coordinate\n" + evidence(evidence_payload))
            require(not page_errors, "Page errors during expand layout smoke\n" + evidence(evidence_payload))
            require(not failed_requests, "Failed requests during expand layout smoke\n" + evidence(evidence_payload))
        finally:
            browser.close()


def main() -> None:
    args = parse_args()
    run_one(args.query.strip() or "L1HS")
    ok(f"G6 expand layout smoke passed for {args.query}")


if __name__ == "__main__":
    run_check(main)
