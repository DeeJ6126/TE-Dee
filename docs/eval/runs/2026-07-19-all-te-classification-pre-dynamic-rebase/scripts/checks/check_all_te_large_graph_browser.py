from __future__ import annotations

import argparse
import json
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import deque
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from harness_lib import HarnessFailure, app_url, fail, ok, require, run_check


ROOT = Path(__file__).resolve().parents[2]
VALID_FOCUS = (
    "initial", "structural-star", "family-expand", "hover-drag", "overlap-motion",
    "legend-source-roundtrip", "all",
)
DEFAULT_TIMEOUT_MS = 60_000


@dataclass
class BrowserErrors:
    console_errors: list[str] = field(default_factory=list)
    page_errors: list[str] = field(default_factory=list)
    failed_requests: list[str] = field(default_factory=list)
    taxonomy_responses: list[dict[str, Any]] = field(default_factory=list)

    def as_dict(self) -> dict[str, Any]:
        return {
            "console_errors": self.console_errors,
            "page_errors": self.page_errors,
            "failed_requests": self.failed_requests,
            "taxonomy_responses": self.taxonomy_responses,
        }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate the real All-TE taxonomy G6 canvas.")
    parser.add_argument("--focus", choices=VALID_FOCUS, default="all")
    parser.add_argument("--screenshot", type=Path)
    parser.add_argument(
        "--baseline-output",
        type=Path,
        help="Write the ungated structural-star metric snapshot before acceptance assertions.",
    )
    return parser.parse_args()


def fetch_taxonomy_payload(source: str) -> tuple[dict[str, Any], int, float]:
    url = app_url(f"api/taxonomy.php?view=tree&source={urllib.parse.quote(source)}")
    request = urllib.request.Request(url, headers={"Accept": "application/json"})
    started = time.perf_counter()
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            raw = response.read()
            status = int(response.status)
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        fail(f"Taxonomy API HTTP {exc.code}: {body[:1000]}")
    except urllib.error.URLError as exc:
        fail(f"Unable to reach taxonomy API: {exc.reason}")

    require(status < 400, f"Taxonomy API returned HTTP {status}")
    try:
        payload = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        fail(f"Taxonomy API returned invalid JSON: {exc}")
    require(isinstance(payload, dict), "Taxonomy API payload must be an object")
    return payload, len(raw), round((time.perf_counter() - started) * 1000, 3)


def validate_taxonomy_payload(
    payload: dict[str, Any],
    decoded_bytes: int,
    request_ms: float | None = None,
) -> dict[str, Any]:
    require(payload.get("ok") is True, f"Taxonomy API returned non-ok payload: {payload.get('error')}")
    nodes = payload.get("nodes")
    edges = payload.get("edges")
    require(isinstance(nodes, list) and nodes, "Taxonomy API nodes must be a nonempty list")
    require(isinstance(edges, list), "Taxonomy API edges must be a list")
    require(payload.get("node_count") == len(nodes), "Taxonomy API node_count does not match nodes.length")
    require(payload.get("edge_count") == len(edges), "Taxonomy API edge_count does not match edges.length")

    node_ids = [str(node.get("name", "")).strip() for node in nodes if isinstance(node, dict)]
    require(len(node_ids) == len(nodes), "Taxonomy API contains a non-object node")
    require(all(node_ids), "Taxonomy API contains an empty node name")
    require(len(set(node_ids)) == len(node_ids), "Taxonomy API contains duplicate node names")
    node_set = set(node_ids)
    depth_by_id = {
        str(node.get("name", "")).strip(): int(node.get("depth", -1))
        for node in nodes
        if isinstance(node, dict)
    }
    default_node_ids = {
        node_id for node_id, depth in depth_by_id.items()
        if 0 <= depth <= 4
    }
    root = str(payload.get("root", "")).strip()
    require(root in node_set, f"Taxonomy root is missing from nodes: {root!r}")

    children: dict[str, list[str]] = {node_id: [] for node_id in node_ids}
    incoming_counts: dict[str, int] = {node_id: 0 for node_id in node_ids}
    edge_ids: set[tuple[str, str]] = set()
    for edge in edges:
        require(isinstance(edge, dict), "Taxonomy API contains a non-object edge")
        child = str(edge.get("child", "")).strip()
        parent = str(edge.get("parent", "")).strip()
        require(parent in node_set and child in node_set, f"Taxonomy edge has missing endpoint: {parent!r} -> {child!r}")
        edge_id = (parent, child)
        require(edge_id not in edge_ids, f"Taxonomy API contains duplicate edge: {parent!r} -> {child!r}")
        edge_ids.add(edge_id)
        children[parent].append(child)
        incoming_counts[child] += 1

    require(len(edges) == len(nodes) - 1, f"Taxonomy tree must have |V|-1 edges: {len(nodes)} nodes, {len(edges)} edges")
    require(incoming_counts[root] == 0, f"Taxonomy root must have no parent: {root!r}")
    invalid_parent_counts = {
        node_id: count for node_id, count in incoming_counts.items()
        if node_id != root and count != 1
    }
    require(not invalid_parent_counts, f"Every non-root taxonomy node must have exactly one parent: {invalid_parent_counts}")

    visited = {root}
    queue: deque[str] = deque([root])
    while queue:
        parent = queue.popleft()
        for child in children.get(parent, []):
            if child not in visited:
                visited.add(child)
                queue.append(child)
    require(len(visited) == len(node_set), f"Taxonomy tree is disconnected: reached {len(visited)} of {len(node_set)} nodes")
    default_edge_count = sum(
        1 for parent, child in edge_ids
        if parent in default_node_ids and child in default_node_ids
    )
    return {
        "source": payload.get("source"),
        "root": root,
        "node_count": len(nodes),
        "edge_count": len(edges),
        "decoded_bytes": decoded_bytes,
        "request_ms": request_ms,
        "connected_node_count": len(visited),
        "default_node_count": len(default_node_ids),
        "default_edge_count": default_edge_count,
    }


def attach_error_capture(page: Any) -> BrowserErrors:
    errors = BrowserErrors()

    def on_console(message: Any) -> None:
        if message.type == "error":
            errors.console_errors.append(message.text)

    def on_response(response: Any) -> None:
        if "/api/taxonomy.php" in response.url:
            errors.taxonomy_responses.append({"url": response.url, "status": response.status})

    page.on("console", on_console)
    page.on("pageerror", lambda exc: errors.page_errors.append(str(exc)))
    page.on("requestfailed", lambda request: errors.failed_requests.append(f"{request.url} :: {request.failure}"))
    page.on("response", on_response)
    return errors


def wait_for_initial_graph(page: Any, source: str = "all") -> None:
    page.wait_for_function(
        """source => {
            const bridge = window.__TEKG_G6_BRIDGE;
            const state = bridge?.getState?.();
            const loader = document.querySelector('#graph-preloader');
            const graph = window.__TEKG_G6_DEFAULT_TREE?.getGraph?.();
            let nodes = [];
            try { nodes = graph?.getNodeData?.() || []; } catch (_error) {}
            return state?.mode === 'taxonomy_graph'
                && state?.treeVariant === source
                && loader?.getAttribute('aria-hidden') === 'true'
                && Array.isArray(nodes)
                && nodes.length > 0;
        }""",
        arg=source,
        timeout=DEFAULT_TIMEOUT_MS,
    )
    page.evaluate("() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))")


def collect_graph_diagnostics(page: Any) -> dict[str, Any]:
    return page.evaluate(
        """() => {
            const host = document.querySelector('#g6-default-tree-surface');
            const loader = document.querySelector('#graph-preloader');
            const graph = window.__TEKG_G6_DEFAULT_TREE?.getGraph?.();
            const state = window.__TEKG_G6_BRIDGE?.getState?.() || null;
            const rect = host?.getBoundingClientRect?.();
            let nodes = [];
            let edges = [];
            try { nodes = graph?.getNodeData?.() || graph?.getData?.()?.nodes || []; } catch (_error) {}
            try { edges = graph?.getEdgeData?.() || graph?.getData?.()?.edges || []; } catch (_error) {}
            const ids = new Set(nodes.map(node => String(node?.id || '')));
            const invalidEdges = edges.filter(edge => !ids.has(String(edge?.source || '')) || !ids.has(String(edge?.target || '')));
            let layout = null;
            try { layout = graph?.getLayout?.() || null; } catch (_error) {}
            return {
                state,
                loader: loader ? { ariaHidden: loader.getAttribute('aria-hidden'), className: loader.className } : null,
                host: rect ? { width: rect.width, height: rect.height } : null,
                nodeCount: nodes.length,
                edgeCount: edges.length,
                invalidEdgeCount: invalidEdges.length,
                layout,
                legendItems: window.__TEKG_G6_DEFAULT_TREE?.getLevelLegendItems?.() || [],
                renderer: window.__TEKG_G6_DEFAULT_TREE?.getDiagnostics?.() || null,
                lifecycle: window.__TEKG_LARGE_FORCE_GRAPH_CORE?.getLifecycleDiagnostics?.() || null,
            };
        }"""
    )


def inspect_canvas_layers(page: Any) -> dict[str, Any]:
    return page.evaluate(
        """() => {
            const canvases = Array.from(document.querySelectorAll('#g6-default-tree-surface canvas'));
            const layers = canvases.map((canvas, index) => {
                const rect = canvas.getBoundingClientRect();
                const sample = document.createElement('canvas');
                sample.width = 64;
                sample.height = 64;
                const context = sample.getContext('2d', { willReadFrequently: true });
                let nontransparent = 0;
                let pixelsDifferentFromFirst = 0;
                let uniqueColors = 0;
                let error = null;
                try {
                    context.clearRect(0, 0, 64, 64);
                    context.drawImage(canvas, 0, 0, 64, 64);
                    const data = context.getImageData(0, 0, 64, 64).data;
                    const first = [data[0], data[1], data[2], data[3]];
                    const colors = new Set();
                    for (let offset = 0; offset < data.length; offset += 4) {
                        const rgba = [data[offset], data[offset + 1], data[offset + 2], data[offset + 3]];
                        if (rgba[3] > 0) nontransparent += 1;
                        if (rgba.some((value, channel) => value !== first[channel])) pixelsDifferentFromFirst += 1;
                        if (colors.size < 512) colors.add(rgba.join(','));
                    }
                    uniqueColors = colors.size;
                } catch (caught) {
                    error = String(caught);
                }
                return {
                    index,
                    cssWidth: rect.width,
                    cssHeight: rect.height,
                    backingWidth: canvas.width,
                    backingHeight: canvas.height,
                    nontransparent,
                    pixelsDifferentFromFirst,
                    uniqueColors,
                    error,
                };
            });
            return {
                count: layers.length,
                layers,
                aggregateNontransparent: layers.reduce((sum, layer) => sum + layer.nontransparent, 0),
                aggregateDifferent: layers.reduce((sum, layer) => sum + layer.pixelsDifferentFromFirst, 0),
                contentLayerCount: layers.filter(
                    layer => layer.nontransparent > 0 && layer.uniqueColors > 1 && layer.pixelsDifferentFromFirst > 0
                ).length,
            };
        }"""
    )


def validate_initial_browser_state(
    diagnostics: dict[str, Any],
    canvas: dict[str, Any],
    errors: BrowserErrors,
    api: dict[str, Any],
) -> None:
    state = diagnostics.get("state") or {}
    host = diagnostics.get("host") or {}
    require(state.get("mode") == "taxonomy_graph", f"Expected taxonomy_graph mode: {state}")
    require(state.get("treeVariant") == "all", f"Expected all taxonomy source: {state}")
    require((diagnostics.get("loader") or {}).get("ariaHidden") == "true", f"Loader is still visible: {diagnostics.get('loader')}")
    require(host.get("width", 0) > 100 and host.get("height", 0) > 100, f"Taxonomy host has invalid size: {host}")
    require(diagnostics.get("nodeCount") == api.get("default_node_count"),
            f"Default Graph node count must equal authoritative depths 0-4: API={api}, graph={diagnostics}")
    require(diagnostics.get("edgeCount") == api.get("default_edge_count"),
            f"Default Graph edge count must equal endpoint-valid authoritative depths 0-4: API={api}, graph={diagnostics}")
    require(diagnostics.get("invalidEdgeCount") == 0, f"Visible graph contains invalid edge endpoints: {diagnostics}")
    renderer = diagnostics.get("renderer") or {}
    lifecycle = diagnostics.get("lifecycle") or {}
    require(renderer.get("live") is True, f"All-TE Graph has no live taxonomy renderer: {diagnostics}")
    require(renderer.get("source") == "all" and renderer.get("sourceKind") == "taxonomy"
            and renderer.get("graphId") == "taxonomy:all",
            f"Live taxonomy renderer reports the wrong source: {renderer}")
    require(renderer.get("visible") == {
        "nodes": diagnostics.get("nodeCount"), "edges": diagnostics.get("edgeCount")
    }, f"Renderer/Graph visible counts disagree: {diagnostics}")
    require(lifecycle.get("liveInstanceCount") == 1
            and lifecycle.get("liveInstanceIds") == [renderer.get("instanceId")],
            f"All-TE Graph must own exactly one current renderer: {lifecycle}")
    require((renderer.get("master") or {}).get("nodes") == api.get("node_count")
            and (renderer.get("master") or {}).get("edges") == api.get("edge_count"),
            f"Renderer master counts must equal authoritative API counts: API={api}, renderer={renderer}")
    require(canvas.get("count", 0) > 0, f"Taxonomy graph contains no Canvas layers: {canvas}")
    invalid_layers = [layer for layer in canvas.get("layers", []) if layer.get("backingWidth", 0) <= 0 or layer.get("backingHeight", 0) <= 0]
    require(not invalid_layers, f"Canvas layers have invalid backing dimensions: {invalid_layers}")
    require(canvas.get("aggregateNontransparent", 0) > 0, f"Canvas pixel sampling found no nontransparent pixels: {canvas}")
    require(canvas.get("contentLayerCount", 0) > 0, f"Canvas pixel sampling found no rendered content: {canvas}")
    require(not errors.page_errors, "Page errors detected:\n" + "\n".join(errors.page_errors[:10]))
    reference_errors = [entry for entry in errors.console_errors if "ReferenceError" in entry]
    require(not reference_errors, "ReferenceError detected:\n" + "\n".join(reference_errors[:10]))
    taxonomy_failures = [entry for entry in errors.failed_requests if "/api/taxonomy.php" in entry]
    require(not taxonomy_failures, "Taxonomy request failures detected:\n" + "\n".join(taxonomy_failures[:10]))


def run_initial_check(page: Any, screenshot: Path | None = None, errors: BrowserErrors | None = None) -> dict[str, Any]:
    payload, decoded_bytes, request_ms = fetch_taxonomy_payload("all")
    api = validate_taxonomy_payload(payload, decoded_bytes, request_ms)
    errors = errors or attach_error_capture(page)
    page.goto(app_url("preview.php?tree=all"), wait_until="domcontentloaded", timeout=DEFAULT_TIMEOUT_MS)
    wait_for_initial_graph(page, "all")
    diagnostics = collect_graph_diagnostics(page)
    canvas = inspect_canvas_layers(page)
    validate_initial_browser_state(diagnostics, canvas, errors, api)
    if screenshot:
        screenshot = screenshot if screenshot.is_absolute() else ROOT / screenshot
        screenshot.parent.mkdir(parents=True, exist_ok=True)
        page.screenshot(path=str(screenshot), full_page=True)
    return {"api": api, "graph": diagnostics, "canvas": canvas, "errors": errors.as_dict()}


def run_hover_drag_check(page: Any) -> dict[str, Any]:
    evidence = page.evaluate(
        """async () => {
            const api = window.__TEKG_G6_DEFAULT_TREE;
            const graph = api?.getGraph?.();
            const host = document.querySelector('#g6-default-tree-surface');
            if (!graph || !host || typeof graph.emit !== 'function') throw new Error('Live taxonomy graph is unavailable');
            const nodes = graph.getNodeData?.() || graph.getData?.()?.nodes || [];
            const edges = graph.getEdgeData?.() || graph.getData?.()?.edges || [];
            const node = nodes.find(item => Number(item?.level ?? item?.data?.treeDepth ?? 0) >= 4
                && String(item?.displayLabel || item?.label || item?.data?.displayLabel || item?.data?.rawLabel || '').length > 10)
                || nodes.find(item => Number(item?.level ?? item?.data?.treeDepth ?? 0) >= 4);
            if (!node) throw new Error('No deep taxonomy node is available for hover');
            const label = String(node.data?.rawLabel || node.data?.displayLabel || node.displayLabel || node.label || node.id);
            const level = String(node.data?.taxonomyLevelLabel || `Level ${Number(node.level ?? node.data?.treeDepth ?? 0) || 0}`);
            const counts = { draw: 0, render: 0, layout: 0, destroy: 0, setData: 0, setElementState: 0 };
            for (const name of Object.keys(counts)) {
                if (typeof graph[name] !== 'function') continue;
                const original = graph[name].bind(graph);
                graph[name] = (...args) => {
                    counts[name] += 1;
                    return original(...args);
                };
            }
            const before = { nodeCount: nodes.length, edgeCount: edges.length };
            const beforeDiagnostics = api.getDiagnostics?.();
            const rect = host.getBoundingClientRect();
            const event = { target: { id: String(node.id) }, targetType: 'node', originalEvent: {
                clientX: rect.left + Math.min(120, rect.width / 2), clientY: rect.top + Math.min(120, rect.height / 2),
            }};
            const emit = async (name) => {
                graph.emit(name, event);
                await new Promise(resolve => setTimeout(resolve, 80));
            };
            await emit(window.G6.NodeEvent.POINTER_ENTER);
            const tooltip = host.querySelector('.tekg-taxonomy-graph-tooltip');
            const tooltipRect = tooltip?.getBoundingClientRect?.();
            const shown = tooltip ? {
                count: host.querySelectorAll('.tekg-taxonomy-graph-tooltip').length,
                text: tooltip.textContent,
                display: window.getComputedStyle(tooltip).display,
                withinHost: tooltipRect.left >= rect.left - 1 && tooltipRect.top >= rect.top - 1
                    && tooltipRect.right <= rect.right + 1 && tooltipRect.bottom <= rect.bottom + 1,
            } : null;
            const stateWritesAfterFirstEnter = counts.setElementState;
            await emit(window.G6.NodeEvent.POINTER_ENTER);
            const stateWritesAfterRepeatedEnter = counts.setElementState;
            await emit(window.G6.NodeEvent.DRAG_START);
            await emit(window.G6.NodeEvent.DRAG_END);
            await new Promise(resolve => setTimeout(resolve, 720));
            await emit(window.G6.NodeEvent.POINTER_LEAVE);
            await emit('node:click');
            const hidden = tooltip ? window.getComputedStyle(tooltip).display === 'none' : false;
            const afterNodes = graph.getNodeData?.() || graph.getData?.()?.nodes || [];
            const afterEdges = graph.getEdgeData?.() || graph.getData?.()?.edges || [];
            return {
                label, level, shown, hidden, counts, stateWritesAfterFirstEnter, stateWritesAfterRepeatedEnter,
                before, after: { nodeCount: afterNodes.length, edgeCount: afterEdges.length },
                beforeDiagnostics, afterDiagnostics: api.getDiagnostics?.(),
                sameGraph: api.getGraph() === graph,
            };
        }"""
    )
    require(evidence.get("shown") is not None, f"Hover tooltip was not created: {evidence}")
    shown = evidence["shown"]
    require(shown.get("count") == 1, f"Expected exactly one tooltip: {evidence}")
    require(evidence["label"] in shown.get("text", ""), f"Tooltip omitted full node label: {evidence}")
    require(evidence["level"] in shown.get("text", ""), f"Tooltip omitted taxonomy level: {evidence}")
    require(shown.get("display") != "none" and shown.get("withinHost"), f"Tooltip is hidden or out of bounds: {evidence}")
    require(evidence.get("hidden") is True, f"Tooltip did not hide on pointer leave: {evidence}")
    require(evidence.get("sameGraph") is True and evidence.get("before") == evidence.get("after"), f"Graph identity/counts changed: {evidence}")
    counts = evidence.get("counts", {})
    require(all(counts.get(name, 0) == 0 for name in ("draw", "render", "destroy", "setData")),
            f"Hover/drag rebuilt or redrew the graph: {evidence}")
    require(counts.get("layout", 0) == 1, f"One drag must start exactly one transient layout: {evidence}")
    before_diag = evidence.get("beforeDiagnostics") or {}
    after_diag = evidence.get("afterDiagnostics") or {}
    require(after_diag.get("activeMotionCount") == 0, f"Drag motion did not stop: {evidence}")
    require((after_diag.get("lastStopMs") or 0) <= 800, f"Drag motion exceeded the 800 ms stop gate: {evidence}")
    require(after_diag.get("instanceId") == before_diag.get("instanceId"), f"Drag replaced renderer identity: {evidence}")
    require(counts.get("setElementState", 0) > 0, f"Hover did not perform local state updates: {evidence}")
    require(evidence.get("stateWritesAfterFirstEnter") == evidence.get("stateWritesAfterRepeatedEnter"),
            f"Repeated enter was not a no-op: {evidence}")
    return evidence


def family_expand_fixture() -> dict[str, Any]:
    payload, _decoded_bytes, _request_ms = fetch_taxonomy_payload("all")
    nodes = {
        str(node.get("name", "")): node
        for node in payload.get("nodes", [])
        if isinstance(node, dict) and str(node.get("name", ""))
    }
    children: dict[str, list[str]] = {}
    parent: dict[str, str] = {}
    for edge in payload.get("edges", []):
        if not isinstance(edge, dict):
            continue
        child = str(edge.get("child", ""))
        parent_id = str(edge.get("parent", ""))
        children.setdefault(parent_id, []).append(child)
        parent[child] = parent_id
    candidates = sorted(
        name for name, node in nodes.items()
        if int(node.get("depth", -1)) == 4
        and any(int(nodes.get(child, {}).get("depth", -1)) == 5 for child in children.get(name, []))
    )
    require(candidates, "Authoritative All-TE payload has no Family with direct Subfamily children")
    family = candidates[0]
    direct_children = sorted(
        child for child in children[family]
        if int(nodes.get(child, {}).get("depth", -1)) == 5
    )
    ancestry: list[str] = []
    current = family
    while current in parent:
        current = parent[current]
        ancestry.append(current)
    ancestry.reverse()
    return {
        "family": family,
        "description": str(nodes[family].get("description", "")),
        "directChildren": direct_children,
        "ancestry": ancestry,
        "degree": 1 + len(direct_children),
    }


def run_family_expand_check(page: Any) -> dict[str, Any]:
    fixture = family_expand_fixture()
    evidence = page.evaluate(
        """async fixture => {
            const api = window.__TEKG_G6_DEFAULT_TREE;
            const graph = api?.getGraph?.();
            if (!graph || typeof graph.emit !== 'function') throw new Error('Live taxonomy graph is unavailable');
            const snapshot = () => {
                const nodes = graph.getNodeData?.() || graph.getData?.()?.nodes || [];
                const edges = graph.getEdgeData?.() || graph.getData?.()?.edges || [];
                return { nodes, edges };
            };
            const beforeData = snapshot();
            const beforeDepth5 = beforeData.nodes.filter(node => Number(node?.level ?? node?.data?.treeDepth) >= 5);
            const family = beforeData.nodes.find(node => String(node?.data?.rawLabel || '') === fixture.family
                && Number(node?.level ?? node?.data?.treeDepth) === 4);
            if (!family) throw new Error(`Family fixture is not visible: ${fixture.family}`);
            const before = api.getDiagnostics?.();
            graph.emit('node:click', { target: { id: family.id }, targetType: 'node' });
            await new Promise(resolve => setTimeout(resolve, 100));
            const detail = document.querySelector('#node-details');
            const detailText = String(detail?.textContent || '').replace(/[\x09-\x0d\x20]+/g, ' ').trim();
            const expand = detail?.querySelector?.('[data-taxonomy-graph-expand]') || null;
            const detailEvidence = {
                text: detailText,
                hasExpand: !!expand,
                expandFamilyId: String(expand?.getAttribute?.('data-taxonomy-graph-expand') || ''),
            };
            if (expand) expand.click();
            const deadline = performance.now() + 3000;
            while (performance.now() < deadline) {
                const labels = new Set(snapshot().nodes.map(node => String(node?.data?.rawLabel || '')));
                if (fixture.directChildren.every(label => labels.has(label))) break;
                await new Promise(resolve => setTimeout(resolve, 50));
            }
            const afterData = snapshot();
            const after = api.getDiagnostics?.();
            const collapse = detail?.querySelector?.('[data-taxonomy-graph-collapse]') || null;
            const beforeIds = new Set(beforeData.nodes.map(node => String(node.id)));
            const addedNodes = afterData.nodes.filter(node => !beforeIds.has(String(node.id))).map(node => ({
                id: String(node.id),
                label: String(node?.data?.rawLabel || ''),
                level: Number(node?.level ?? node?.data?.treeDepth),
                parentId: String(node?.data?.parentId || ''),
            }));
            const ids = new Set(afterData.nodes.map(node => String(node.id)));
            const invalidEdges = afterData.edges.filter(edge => (
                !ids.has(String(edge?.source?.id || edge.source))
                || !ids.has(String(edge?.target?.id || edge.target))
            )).length;
            return {
                fixture,
                familyId: String(family.id),
                beforeDepth5Count: beforeDepth5.length,
                detail: detailEvidence,
                before, after,
                beforeCounts: { nodes: beforeData.nodes.length, edges: beforeData.edges.length },
                afterCounts: { nodes: afterData.nodes.length, edges: afterData.edges.length },
                collapseFamilyId: String(collapse?.getAttribute?.('data-taxonomy-graph-collapse') || ''),
                addedNodes,
                invalidEdges,
                sameGraph: api.getGraph?.() === graph,
            };
        }""",
        fixture,
    )
    require(evidence.get("beforeDepth5Count") == 0,
            f"Default All-TE Graph must not display Subfamily depth 5: {evidence}")
    detail = evidence.get("detail") or {}
    detail_text = detail.get("text", "")
    require(evidence["fixture"]["family"] in detail_text, f"Family detail omits its name: {evidence}")
    require(f"TE · degree {evidence['fixture']['degree']}" in detail_text,
            f"Family detail omits degree: {evidence}")
    require(evidence["fixture"]["description"] in detail_text,
            f"Family detail omits description: {evidence}")
    require(all(name in detail_text for name in evidence["fixture"]["ancestry"][-3:]),
            f"Family detail omits Class/Order/Superfamily ancestry: {evidence}")
    require(detail.get("hasExpand") is True and detail.get("expandFamilyId") == evidence.get("familyId"),
            f"Family detail has no scoped Expand action: {evidence}")
    added = evidence.get("addedNodes") or []
    require(sorted(node.get("label") for node in added) == evidence["fixture"]["directChildren"],
            f"Expand must add only the selected Family's direct Subfamily children: {evidence}")
    require(all(node.get("level") == 5 and node.get("parentId") == evidence.get("familyId") for node in added),
            f"Expanded nodes must be direct depth-5 children of the selected Family: {evidence}")
    require(evidence.get("invalidEdges") == 0, f"Family Expand left invalid edge endpoints: {evidence}")
    require(evidence.get("sameGraph") is True, f"Family Expand replaced Graph identity: {evidence}")
    require(evidence.get("collapseFamilyId") == evidence.get("familyId"),
            f"Expanded Family card did not switch to scoped Collapse: {evidence}")
    before = evidence.get("before") or {}
    after = evidence.get("after") or {}
    require(before.get("instanceId") == after.get("instanceId"), f"Family Expand rebuilt renderer: {evidence}")
    for counter in ("create", "destroy", "render", "layoutStart"):
        require((before.get("counters") or {}).get(counter) == (after.get("counters") or {}).get(counter),
                f"Family Expand unexpectedly changed {counter}: {evidence}")
    require((after.get("counters") or {}).get("setData") == (before.get("counters") or {}).get("setData") + 1
            and (after.get("counters") or {}).get("draw") == (before.get("counters") or {}).get("draw") + 1,
            f"Family Expand must perform exactly one setData plus one awaited draw: {evidence}")
    return evidence


def collect_structural_star_metrics(page: Any) -> dict[str, Any]:
    return page.evaluate(
        """() => {
            const api = window.__TEKG_G6_DEFAULT_TREE;
            const graph = api?.getGraph?.();
            if (!graph) throw new Error('Live taxonomy graph is unavailable');
            const rawNodes = graph.getNodeData?.() || graph.getData?.()?.nodes || [];
            const nodes = rawNodes.map(raw => ({
                id: String(raw?.id || ''),
                level: Number(raw?.level ?? raw?.data?.treeDepth),
                x: Number(raw?.style?.x ?? raw?.x),
                y: Number(raw?.style?.y ?? raw?.y),
                size: Number(raw?.style?.size ?? raw?.size ?? 8),
                parentId: String(raw?.data?.parentId || raw?.payload?.parentId || ''),
                metaClassId: String(raw?.data?.classId || raw?.classId || ''),
                metaOrderId: String(raw?.data?.orderId || raw?.orderId || ''),
                metaSuperfamilyId: String(raw?.data?.superfamilyId || raw?.superfamilyId || ''),
                starTier: String(raw?.data?.starTier || raw?.starTier || ''),
                systemRadius: Number(raw?.data?.systemRadius ?? raw?.systemRadius ?? raw?.style?.systemRadius),
            })).filter(node => node.id && Number.isFinite(node.level)
                && Number.isFinite(node.x) && Number.isFinite(node.y));
            const byId = new Map(nodes.map(node => [node.id, node]));
            const ancestryFor = node => {
                const result = { classId: '', orderId: '', superfamilyId: '', error: '' };
                let current = node;
                const seen = new Set();
                while (current) {
                    if (seen.has(current.id)) { result.error = 'cycle'; break; }
                    seen.add(current.id);
                    if (current.level === 1) result.classId = current.id;
                    if (current.level === 2) result.orderId = current.id;
                    if (current.level === 3) result.superfamilyId = current.id;
                    if (current.level === 0) break;
                    if (!current.parentId) { result.error = `missing-parent:${current.id}`; break; }
                    current = byId.get(current.parentId);
                    if (!current) { result.error = `unknown-parent:${node.parentId}`; break; }
                }
                if (!result.error && node.level >= 4 && !result.superfamilyId) result.error = 'missing-superfamily';
                return result;
            };
            const anchors = nodes.filter(node => node.level === 3);
            const ancestry = new Map();
            const invalidAncestry = [];
            const metadataMismatches = [];
            const members = nodes.filter(node => node.level === 4).map(node => {
                const resolved = ancestryFor(node);
                ancestry.set(node.id, resolved);
                if (resolved.error) invalidAncestry.push({ id: node.id, error: resolved.error });
                for (const [key, metaKey] of [['classId', 'metaClassId'], ['orderId', 'metaOrderId'], ['superfamilyId', 'metaSuperfamilyId']]) {
                    if (node[metaKey] && node[metaKey] !== resolved[key]) {
                        metadataMismatches.push({ id: node.id, key, expected: resolved[key], actual: node[metaKey] });
                    }
                }
                return { ...node, resolved };
            });
            let nearestOwnCount = 0;
            let proximityDenominator = 0;
            const containmentViolations = [];
            const perSystem = new Map();
            for (const member of members) {
                if (member.resolved.error) continue;
                const own = byId.get(member.resolved.superfamilyId);
                if (!own) { invalidAncestry.push({ id: member.id, error: 'missing-own-anchor' }); continue; }
                const ownDistance = Math.hypot(member.x - own.x, member.y - own.y);
                const foreignDistances = anchors.filter(anchor => anchor.id !== own.id)
                    .map(anchor => Math.hypot(member.x - anchor.x, member.y - anchor.y));
                const nearestForeign = foreignDistances.length ? Math.min(...foreignDistances) : Infinity;
                const nearestOwn = ownDistance < nearestForeign;
                if (!Number.isFinite(own.systemRadius) || own.systemRadius <= 0
                    || ownDistance + member.size / 2 > own.systemRadius + 0.5) {
                    containmentViolations.push({
                        id: member.id,
                        superfamilyId: own.id,
                        centerDistance: Number(ownDistance.toFixed(3)),
                        memberRadius: Number((member.size / 2).toFixed(3)),
                        systemRadius: Number.isFinite(own.systemRadius) ? own.systemRadius : null,
                    });
                }
                proximityDenominator += 1;
                if (nearestOwn) nearestOwnCount += 1;
                const system = perSystem.get(own.id) || { superfamilyId: own.id, total: 0, nearestOwn: 0, offenders: 0 };
                system.total += 1;
                system.nearestOwn += nearestOwn ? 1 : 0;
                system.offenders += nearestOwn ? 0 : 1;
                perSystem.set(own.id, system);
            }
            let crossSfOverlapPairs = 0;
            let crossSfTotalPenetration = 0;
            const validMembers = members.filter(member => !member.resolved.error && member.resolved.superfamilyId);
            for (let i = 0; i < validMembers.length; i += 1) {
                for (let j = i + 1; j < validMembers.length; j += 1) {
                    const a = validMembers[i], b = validMembers[j];
                    if (a.resolved.superfamilyId === b.resolved.superfamilyId) continue;
                    const penetration = a.size / 2 + b.size / 2 + 2 - Math.hypot(a.x - b.x, a.y - b.y);
                    if (penetration > 0) {
                        crossSfOverlapPairs += 1;
                        crossSfTotalPenetration += penetration;
                    }
                }
            }
            const declaredDiscs = anchors.filter(anchor => Number.isFinite(anchor.systemRadius) && anchor.systemRadius > 0);
            let systemDiscOverlapPairs = 0;
            let systemDiscTotalPenetration = 0;
            for (let i = 0; i < declaredDiscs.length; i += 1) {
                for (let j = i + 1; j < declaredDiscs.length; j += 1) {
                    const a = declaredDiscs[i], b = declaredDiscs[j];
                    const penetration = a.systemRadius + b.systemRadius + 2 - Math.hypot(a.x - b.x, a.y - b.y);
                    if (penetration > 0) {
                        systemDiscOverlapPairs += 1;
                        systemDiscTotalPenetration += penetration;
                    }
                }
            }
            const worstSystems = [...perSystem.values()].map(system => ({
                ...system,
                ratio: system.total ? Number((system.nearestOwn / system.total).toFixed(6)) : 1,
            })).sort((a, b) => b.offenders - a.offenders || a.ratio - b.ratio).slice(0, 10);
            const sortedDiameters = nodes.map(node => node.size).sort((a, b) => a - b);
            const medianVisibleNodeDiameter = sortedDiameters.length
                ? sortedDiameters[Math.floor(sortedDiameters.length / 2)] : 0;
            const minX = Math.min(...nodes.map(node => node.x - node.size / 2));
            const maxX = Math.max(...nodes.map(node => node.x + node.size / 2));
            const minY = Math.min(...nodes.map(node => node.y - node.size / 2));
            const maxY = Math.max(...nodes.map(node => node.y + node.size / 2));
            const worldExtent = { width: maxX - minX, height: maxY - minY };
            const fittedScale = Math.min(innerWidth / worldExtent.width, innerHeight / worldExtent.height);
            const fittedMedianNodeDiameter = medianVisibleNodeDiameter * fittedScale;
            const fingerprintText = nodes.slice().sort((a, b) => a.id.localeCompare(b.id))
                .map(node => `${node.id}:${node.x.toFixed(3)},${node.y.toFixed(3)}`).join('|');
            let hashA = 2166136261, hashB = 0x9e3779b9;
            for (let index = 0; index < fingerprintText.length; index += 1) {
                const code = fingerprintText.charCodeAt(index);
                hashA ^= code; hashA = Math.imul(hashA, 16777619);
                hashB ^= code + index; hashB = Math.imul(hashB, 2246822519);
            }
            return {
                viewport: { width: innerWidth, height: innerHeight },
                nodeCount: nodes.length,
                anchorCount: anchors.length,
                memberCount: members.length,
                visibleDepth5PlusCount: nodes.filter(node => node.level >= 5).length,
                invalidAncestry,
                metadataMismatches,
                nearestOwnCount,
                proximityDenominator,
                ownSuperfamilyNearestRatio: proximityDenominator
                    ? Number((nearestOwnCount / proximityDenominator).toFixed(6)) : 0,
                crossSfOverlapPairs,
                crossSfTotalPenetration: Number(crossSfTotalPenetration.toFixed(2)),
                declaredSystemDiscCount: declaredDiscs.length,
                systemDiscOverlapPairs,
                systemDiscTotalPenetration: Number(systemDiscTotalPenetration.toFixed(2)),
                containmentViolations,
                worldExtent: {
                    width: Number(worldExtent.width.toFixed(3)),
                    height: Number(worldExtent.height.toFixed(3)),
                },
                medianVisibleNodeDiameter,
                fittedMedianNodeDiameter: Number(fittedMedianNodeDiameter.toFixed(3)),
                positionFingerprint: {
                    hashA: hashA >>> 0,
                    hashB: hashB >>> 0,
                    length: fingerprintText.length,
                    firstId: nodes.slice().sort((a, b) => a.id.localeCompare(b.id))[0]?.id || '',
                    lastId: nodes.slice().sort((a, b) => a.id.localeCompare(b.id)).at(-1)?.id || '',
                },
                worstSystems,
                renderer: api.getDiagnostics?.() || null,
            };
        }"""
    )


def validate_structural_star_metrics(metrics: dict[str, Any]) -> None:
    summary = {key: value for key, value in metrics.items()
               if key not in ("renderer", "positionFingerprint")}
    require(metrics.get("viewport") == {"width": 1440, "height": 960},
            f"Structural-star metrics must use the fixed 1440x960 viewport: {metrics}")
    require(metrics.get("anchorCount", 0) > 1, f"Expected multiple visible Superfamily anchors: {metrics}")
    require(metrics.get("memberCount", 0) > 0, f"Expected visible depth-4 taxonomy members: {summary}")
    require(metrics.get("visibleDepth5PlusCount") == 0,
            f"Default All-TE Graph must stop at Family depth 4: {summary}")
    require(not metrics.get("invalidAncestry"),
            f"Depth-4+ nodes have invalid/missing Superfamily ancestry: {metrics.get('invalidAncestry')}")
    require(not metrics.get("metadataMismatches"),
            f"Optional star metadata contradicts payload.parentId ancestry: {metrics.get('metadataMismatches')}")
    require(not metrics.get("containmentViolations"),
            f"Visible Family nodes escape their declared Superfamily system disc: {metrics.get('containmentViolations')[:20]}")
    require(metrics.get("proximityDenominator") == metrics.get("memberCount"),
            f"Proximity gate silently omitted members: {metrics}")
    require(metrics.get("ownSuperfamilyNearestRatio", 0) >= 0.85,
            f"Own-Superfamily nearest-anchor ratio is below 0.85: {metrics}")
    require(metrics.get("crossSfOverlapPairs", 203) <= 203 * 0.60,
            f"Cross-Superfamily overlap pairs did not improve 40% from Task 8 baseline 203: {metrics}")
    require(metrics.get("crossSfTotalPenetration", 805.41) <= 805.41 * 0.60,
            f"Cross-Superfamily penetration did not improve 40% from Task 8 baseline 805.41: {metrics}")
    if metrics.get("declaredSystemDiscCount", 0) > 0:
        require(metrics.get("declaredSystemDiscCount") == metrics.get("anchorCount"),
                f"Only some Superfamily anchors declare systemRadius: {metrics}")
        require(metrics.get("systemDiscOverlapPairs", 0) == 0,
                f"Declared Superfamily system discs overlap: {metrics}")
    require(2 <= metrics.get("fittedMedianNodeDiameter", 0) <= 24,
            "World extent produces a pathological fitted median node diameter outside 2-24px: "
            f"extent={metrics.get('worldExtent')}, median={metrics.get('medianVisibleNodeDiameter')}, "
            f"fitted={metrics.get('fittedMedianNodeDiameter')}")


def start_real_drag_frame_sampler(page: Any, target_id: str, point: dict[str, float]) -> None:
    page.evaluate(
        """({ targetId, point }) => {
            const graph = window.__TEKG_G6_DEFAULT_TREE?.getGraph?.();
            const host = document.querySelector('#g6-default-tree-surface');
            if (!graph || !host) throw new Error('Cannot start real-drag frame sampler');
            const all = graph.getNodeData?.() || graph.getData?.()?.nodes || [];
            const targetPosition = graph.getElementPosition?.(targetId);
            const affectedIds = all.filter(node => String(node.id) !== targetId).map(node => {
                const position = graph.getElementPosition?.(String(node.id));
                return { id: String(node.id), distance: position && targetPosition
                    ? Math.hypot(position[0] - targetPosition[0], position[1] - targetPosition[1]) : Infinity };
            }).sort((a, b) => a.distance - b.distance).slice(0, 64).map(entry => entry.id);
            const region = { left: point.x - 120, top: point.y - 90, width: 240, height: 180 };
            const sampleCanvas = document.createElement('canvas');
            sampleCanvas.width = 80;
            sampleCanvas.height = 60;
            const sampleContext = sampleCanvas.getContext('2d', { willReadFrequently: true });
            const pixelHash = () => {
                sampleContext.clearRect(0, 0, 80, 60);
                for (const canvas of host.querySelectorAll('canvas')) {
                    const rect = canvas.getBoundingClientRect();
                    const left = Math.max(region.left, rect.left);
                    const top = Math.max(region.top, rect.top);
                    const right = Math.min(region.left + region.width, rect.right);
                    const bottom = Math.min(region.top + region.height, rect.bottom);
                    if (right <= left || bottom <= top || rect.width <= 0 || rect.height <= 0) continue;
                    const sx = (left - rect.left) * canvas.width / rect.width;
                    const sy = (top - rect.top) * canvas.height / rect.height;
                    const sw = (right - left) * canvas.width / rect.width;
                    const sh = (bottom - top) * canvas.height / rect.height;
                    const dx = (left - region.left) * 80 / region.width;
                    const dy = (top - region.top) * 60 / region.height;
                    const dw = (right - left) * 80 / region.width;
                    const dh = (bottom - top) * 60 / region.height;
                    sampleContext.drawImage(canvas, sx, sy, sw, sh, dx, dy, dw, dh);
                }
                const data = sampleContext.getImageData(0, 0, 80, 60).data;
                let hash = 2166136261;
                for (let index = 0; index < data.length; index += 4) {
                    hash ^= data[index]; hash = Math.imul(hash, 16777619);
                    hash ^= data[index + 1]; hash = Math.imul(hash, 16777619);
                    hash ^= data[index + 2]; hash = Math.imul(hash, 16777619);
                    hash ^= data[index + 3]; hash = Math.imul(hash, 16777619);
                }
                return hash >>> 0;
            };
            const state = { targetId, affectedIds, region, frames: [], done: false, startedAt: performance.now() };
            window.__TEKG_TASK9_DRAG_SAMPLER = state;
            const sample = now => {
                const positions = affectedIds.map(id => {
                    const position = graph.getElementPosition?.(id);
                    return position ? [id, Number(position[0]), Number(position[1])] : [id, null, null];
                });
                state.frames.push({ ms: Number((now - state.startedAt).toFixed(2)), positions, pixelHash: pixelHash() });
                if (!state.done && now - state.startedAt < 1400) requestAnimationFrame(sample);
            };
            requestAnimationFrame(sample);
            return { affectedCount: affectedIds.length, region };
        }""",
        {"targetId": target_id, "point": point},
    )


def finish_real_drag_frame_sampler(page: Any) -> dict[str, Any]:
    return page.evaluate(
        """() => {
            const state = window.__TEKG_TASK9_DRAG_SAMPLER;
            if (!state) throw new Error('Real-drag frame sampler is missing');
            state.done = true;
            const intervals = [];
            const signatures = new Set();
            for (const frame of state.frames) {
                signatures.add(frame.positions.map(item => `${item[0]}:${Number(item[1]).toFixed(2)},${Number(item[2]).toFixed(2)}`).join('|'));
            }
            for (let index = 1; index < state.frames.length; index += 1) {
                const before = state.frames[index - 1], after = state.frames[index];
                const beforeById = new Map(before.positions.map(item => [item[0], item]));
                let maxNonTargetDelta = 0;
                for (const item of after.positions) {
                    const prior = beforeById.get(item[0]);
                    if (!prior || !Number.isFinite(item[1]) || !Number.isFinite(prior[1])) continue;
                    maxNonTargetDelta = Math.max(maxNonTargetDelta, Math.hypot(item[1] - prior[1], item[2] - prior[2]));
                }
                intervals.push({
                    index,
                    maxNonTargetDelta: Number(maxNonTargetDelta.toFixed(3)),
                    pixelChanged: before.pixelHash !== after.pixelHash,
                    qualifies: maxNonTargetDelta > 0.05 && before.pixelHash !== after.pixelHash,
                });
            }
            let consecutiveQualifiedIntervals = 0;
            let run = 0;
            for (const interval of intervals) {
                run = interval.qualifies ? run + 1 : 0;
                consecutiveQualifiedIntervals = Math.max(consecutiveQualifiedIntervals, run);
            }
            return {
                targetId: state.targetId,
                affectedCount: state.affectedIds.length,
                region: state.region,
                frameCount: state.frames.length,
                distinctCoordinateSnapshots: signatures.size,
                qualifyingIntervalCount: intervals.filter(item => item.qualifies).length,
                consecutiveQualifiedIntervals,
                intervals,
            };
        }"""
    )


def run_overlap_motion_check(page: Any) -> dict[str, Any]:
    evidence = page.evaluate(
        """async () => {
            const api = window.__TEKG_G6_DEFAULT_TREE;
            const graph = api?.getGraph?.();
            if (!graph || typeof graph.emit !== 'function') throw new Error('Live taxonomy graph is unavailable');
            const snapshot = () => (graph.getNodeData?.() || graph.getData?.()?.nodes || []).map(node => ({
                id: String(node.id),
                x: Number(node.style?.x ?? node.x),
                y: Number(node.style?.y ?? node.y),
                size: Number(node.style?.size ?? node.size ?? 8),
                level: Number(node.level ?? node.data?.treeDepth ?? 0),
                branchId: String(node.data?.branchId || node.branchId || ''),
            })).filter(node => Number.isFinite(node.x) && Number.isFinite(node.y));
            const metrics = nodes => {
                const coincident = new Map();
                for (const node of nodes) {
                    const key = `${node.x.toFixed(2)},${node.y.toFixed(2)}`;
                    coincident.set(key, (coincident.get(key) || 0) + 1);
                }
                let overlapPairs = 0;
                let totalPenetration = 0;
                let maxPenetration = 0;
                for (let i = 0; i < nodes.length; i += 1) {
                    for (let j = i + 1; j < nodes.length; j += 1) {
                        const a = nodes[i], b = nodes[j];
                        const distance = Math.hypot(a.x - b.x, a.y - b.y);
                        const penetration = a.size / 2 + b.size / 2 + 2 - distance;
                        if (penetration > 0) {
                            overlapPairs += 1;
                            totalPenetration += penetration;
                            maxPenetration = Math.max(maxPenetration, penetration);
                        }
                    }
                }
                const boxes = new Map();
                for (const node of nodes) {
                    if (!node.branchId || node.level === 0) continue;
                    const radius = node.size / 2;
                    const box = boxes.get(node.branchId) || { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity };
                    box.minX = Math.min(box.minX, node.x - radius);
                    box.minY = Math.min(box.minY, node.y - radius);
                    box.maxX = Math.max(box.maxX, node.x + radius);
                    box.maxY = Math.max(box.maxY, node.y + radius);
                    boxes.set(node.branchId, box);
                }
                const entries = [...boxes.entries()];
                let branchIntersectionPairs = 0;
                let branchIntersectionArea = 0;
                for (let i = 0; i < entries.length; i += 1) {
                    for (let j = i + 1; j < entries.length; j += 1) {
                        const a = entries[i][1], b = entries[j][1];
                        const width = Math.max(0, Math.min(a.maxX, b.maxX) - Math.max(a.minX, b.minX));
                        const height = Math.max(0, Math.min(a.maxY, b.maxY) - Math.max(a.minY, b.minY));
                        if (width > 0 && height > 0) {
                            branchIntersectionPairs += 1;
                            branchIntersectionArea += width * height;
                        }
                    }
                }
                return {
                    nodeCount: nodes.length,
                    coincidentGroups: [...coincident.values()].filter(count => count > 1).length,
                    overlapPairs,
                    totalPenetration: Number(totalPenetration.toFixed(2)),
                    maxPenetration: Number(maxPenetration.toFixed(2)),
                    branchIntersectionPairs,
                    branchIntersectionArea: Number(branchIntersectionArea.toFixed(2)),
                };
            };
            const maxDelta = (before, after) => {
                const byId = new Map(after.map(node => [node.id, node]));
                return Math.max(0, ...before.map(node => {
                    const next = byId.get(node.id);
                    return next ? Math.hypot(node.x - next.x, node.y - next.y) : 0;
                }));
            };
            const beforeNodes = snapshot();
            const beforeDiagnostics = api.getDiagnostics?.();
            const target = beforeNodes.find(node => node.level >= 3) || beforeNodes[0];
            const event = { target: { id: target.id }, targetType: 'node' };
            graph.emit(window.G6.NodeEvent.DRAG_START, event);
            await new Promise(resolve => setTimeout(resolve, 180));
            const movingNodes = snapshot();
            graph.emit(window.G6.NodeEvent.DRAG_END, event);
            await new Promise(resolve => setTimeout(resolve, 750));
            const stoppedNodes = snapshot();
            const stoppedDiagnostics = api.getDiagnostics?.();
            await new Promise(resolve => setTimeout(resolve, 500));
            const idleNodes = snapshot();
            const idleDiagnostics = api.getDiagnostics?.();
            return {
                before: metrics(beforeNodes),
                stopped: metrics(stoppedNodes),
                targetId: target.id,
                motionDelta: maxDelta(beforeNodes, movingNodes),
                settleDelta: maxDelta(movingNodes, stoppedNodes),
                idleDelta: maxDelta(stoppedNodes, idleNodes),
                beforeDiagnostics, stoppedDiagnostics, idleDiagnostics,
                sameGraph: graph === api.getGraph?.(),
            };
        }"""
    )
    require(evidence.get("sameGraph") is True, f"Motion replaced graph identity: {evidence}")
    baseline_overlap_pairs = 4998
    baseline_total_penetration = 30130.61
    initial_metrics = evidence.get("before") or {}
    stopped_metrics = evidence.get("stopped") or {}
    require(initial_metrics.get("overlapPairs", baseline_overlap_pairs) <= baseline_overlap_pairs * 0.75,
            f"Preset overlap pairs did not improve by at least 25% from baseline: {evidence}")
    require(initial_metrics.get("totalPenetration", baseline_total_penetration) <= baseline_total_penetration * 0.75,
            f"Preset overlap penetration did not improve by at least 25% from baseline: {evidence}")
    require(stopped_metrics.get("overlapPairs", initial_metrics.get("overlapPairs", 0))
            <= initial_metrics.get("overlapPairs", 0) * 0.75,
            f"Transient motion did not materially reduce remaining overlap: {evidence}")
    stopped = evidence.get("stoppedDiagnostics") or {}
    idle = evidence.get("idleDiagnostics") or {}
    require(stopped.get("activeMotionCount") == 0, f"Motion did not stop after release: {evidence}")
    require((stopped.get("lastStopMs") or 0) <= 800, f"Motion exceeded 800 ms stop gate: {evidence}")
    require(evidence.get("motionDelta", 0) > 0.1, f"Transient force did not move graph coordinates: {evidence}")
    require(evidence.get("idleDelta", 1) < 0.25, f"Coordinates continued moving after stop: {evidence}")
    require(idle.get("counters", {}).get("motionTick") == stopped.get("counters", {}).get("motionTick"),
            f"Motion ticks continued during idle window: {evidence}")
    point = page.evaluate(
        """targetId => {
            const graph = window.__TEKG_G6_DEFAULT_TREE?.getGraph?.();
            const host = document.querySelector('#g6-default-tree-surface');
            const position = graph?.getElementPosition?.(targetId);
            if (!graph || !host || !position || typeof graph.getViewportByCanvas !== 'function') return null;
            const viewport = graph.getViewportByCanvas(position);
            const rect = host.getBoundingClientRect();
            return { x: rect.left + Number(viewport[0]), y: rect.top + Number(viewport[1]) };
        }""",
        evidence.get("targetId"),
    )
    require(point and point.get("x") is not None and point.get("y") is not None,
            f"Could not resolve a real drag target: {evidence}")
    real_before = page.evaluate("() => window.__TEKG_G6_DEFAULT_TREE?.getDiagnostics?.()")
    real_position_before = page.evaluate(
        "targetId => window.__TEKG_G6_DEFAULT_TREE?.getGraph?.()?.getElementPosition?.(targetId)",
        evidence.get("targetId"),
    )
    start_real_drag_frame_sampler(page, evidence["targetId"], point)
    page.mouse.move(point["x"], point["y"])
    page.mouse.down()
    for step in range(1, 9):
        page.mouse.move(point["x"] + 90 * step / 8, point["y"] + 35 * step / 8)
        page.wait_for_timeout(35)
    page.mouse.up()
    page.wait_for_timeout(750)
    frame_evidence = finish_real_drag_frame_sampler(page)
    real_after = page.evaluate("() => window.__TEKG_G6_DEFAULT_TREE?.getDiagnostics?.()")
    real_position_after = page.evaluate(
        "targetId => window.__TEKG_G6_DEFAULT_TREE?.getGraph?.()?.getElementPosition?.(targetId)",
        evidence.get("targetId"),
    )
    require(real_after["counters"]["motionStart"] == real_before["counters"]["motionStart"] + 1,
            f"Real mouse drag did not start transient motion: {point}, {real_before}, {real_after}")
    require(real_after.get("activeMotionCount") == 0 and (real_after.get("lastStopMs") or 0) <= 800,
            f"Real mouse drag did not cool within 800 ms: {real_after}")
    real_delta = ((real_position_after[0] - real_position_before[0]) ** 2
                  + (real_position_after[1] - real_position_before[1]) ** 2) ** 0.5
    require(real_delta > 5, f"Real mouse drag did not move the target node: {real_position_before}, {real_position_after}")
    require(frame_evidence.get("affectedCount", 0) > 0,
            f"Real drag sampler did not track non-target affected nodes: {frame_evidence}")
    require(frame_evidence.get("distinctCoordinateSnapshots", 0) >= 3,
            f"Real drag did not expose at least three non-target coordinate snapshots: {frame_evidence}")
    require(frame_evidence.get("consecutiveQualifiedIntervals", 0) >= 2,
            "Real drag did not paint non-target coordinate and Canvas pixel changes in at least two "
            f"consecutive rAF intervals: {frame_evidence}")
    require(real_after.get("instanceId") == real_before.get("instanceId")
            and real_after.get("visible") == real_before.get("visible"),
            f"Real mouse drag changed renderer identity/counts: {real_before}, {real_after}")
    evidence["realMouse"] = {
        "point": point, "targetDelta": real_delta,
        "positionBefore": real_position_before, "positionAfter": real_position_after,
        "before": real_before, "after": real_after, "frameSampler": frame_evidence,
    }
    return evidence


def run_legend_source_roundtrip_check(page: Any) -> dict[str, Any]:
    evidence = page.evaluate(
        """async () => {
            const api = window.__TEKG_G6_DEFAULT_TREE;
            const graph = api?.getGraph?.();
            const before = api?.getDiagnostics?.();
            const items = api?.getLevelLegendItems?.() || [];
            const item = [...items].reverse().find(entry => entry.visible !== false && entry.count > 0);
            if (!graph || !before || !item) throw new Error('Legend lifecycle diagnostics are unavailable');
            const state = Object.fromEntries(items.map(entry => [entry.key, entry.visible !== false]));
            await api.applyLevelState({ ...state, [item.key]: false });
            const hidden = api.getDiagnostics();
            const hiddenGraph = api.getGraph();
            const hiddenNodes = hiddenGraph.getNodeData?.() || hiddenGraph.getData?.()?.nodes || [];
            const hiddenEdges = hiddenGraph.getEdgeData?.() || hiddenGraph.getData?.()?.edges || [];
            const hiddenIds = new Set(hiddenNodes.map(node => String(node.id)));
            const invalidHiddenEdges = hiddenEdges.filter(edge => (
                !hiddenIds.has(String(edge.source?.id || edge.source))
                || !hiddenIds.has(String(edge.target?.id || edge.target))
            )).length;
            await api.applyLevelState({ ...state, [item.key]: true });
            const restored = api.getDiagnostics();
            const beforeFocus = api.getDiagnostics();
            await api.setLevelFocus(item.key);
            const focused = api.getDiagnostics();
            const focusNode = (graph.getNodeData?.() || []).find(node => (
                node?.legendKeys?.includes?.(item.key) || node?.data?.legendKeys?.includes?.(item.key)
            ));
            const focusedLocally = focusNode
                ? (graph.getElementState?.(focusNode.id) || []).includes('legend-focus')
                : false;
            await api.applyLevelState({ ...state, [item.key]: true });
            const focusedApply = api.getDiagnostics();
            const reappliedLocally = focusNode
                ? (graph.getElementState?.(focusNode.id) || []).includes('legend-focus')
                : false;
            await api.setLevelFocus(null);
            const cleared = api.getDiagnostics();
            const clearedLocally = focusNode
                ? !(graph.getElementState?.(focusNode.id) || []).includes('legend-focus')
                : false;
            return {
                key: item.key,
                before, hidden, restored, beforeFocus, focused, focusedApply, cleared,
                sameGraph: graph === api.getGraph() && graph === hiddenGraph,
                invalidHiddenEdges, focusedLocally, reappliedLocally, clearedLocally,
            };
        }"""
    )
    require(evidence.get("sameGraph") is True, f"Same-source Apply replaced graph identity: {evidence}")
    before = evidence["before"]
    hidden = evidence["hidden"]
    restored = evidence["restored"]
    require(before.get("instanceId") == hidden.get("instanceId") == restored.get("instanceId"),
            f"Same-source Apply replaced renderer identity: {evidence}")
    require(hidden["visible"]["nodes"] < before["visible"]["nodes"], f"Legend hide did not remove nodes: {evidence}")
    require(restored.get("visible") == before.get("visible"), f"Legend restore did not restore counts: {evidence}")
    require(evidence.get("invalidHiddenEdges") == 0, f"Legend hide left invalid edge endpoints: {evidence}")
    for after, delta in ((hidden, 1), (restored, 2)):
        require(after["counters"]["setData"] - before["counters"]["setData"] == delta,
                f"Apply must call setData exactly once: {evidence}")
        require(after["counters"]["draw"] - before["counters"]["draw"] == delta,
                f"Apply must call draw exactly once: {evidence}")
        for name in ("create", "destroy", "render", "layoutStart"):
            require(after["counters"][name] == before["counters"][name],
                    f"Apply unexpectedly changed {name}: {evidence}")
    require(evidence["focused"]["counters"] == evidence["beforeFocus"]["counters"],
            f"Focus triggered global work: {evidence}")
    focus_apply = evidence["focusedApply"]["counters"]
    focused = evidence["focused"]["counters"]
    require(focus_apply["setData"] == focused["setData"] + 1
            and focus_apply["draw"] == focused["draw"] + 1,
            f"Focus visibility Apply must perform one setData/draw: {evidence}")
    require(all(focus_apply[name] == focused[name] for name in ("create", "destroy", "render", "layoutStart")),
            f"Focus visibility Apply triggered forbidden lifecycle work: {evidence}")
    require(evidence["cleared"]["counters"] == focus_apply,
            f"Clear after visibility Apply triggered global work: {evidence}")
    require(all(evidence.get(name) is True for name in ("focusedLocally", "reappliedLocally", "clearedLocally")),
            f"Focus local state did not survive Apply and clear correctly: {evidence}")
    canvas = inspect_canvas_layers(page)
    require(canvas.get("contentLayerCount", 0) > 0, f"Legend restore left a blank Canvas: {canvas}")
    evidence["canvas"] = canvas

    transitions: list[dict[str, Any]] = []

    def transition(button: str, mode: str, source: str) -> None:
        page.click(button)
        page.wait_for_function(
            """expected => {
                const state = window.__TEKG_G6_BRIDGE?.getState?.();
                const loader = document.querySelector('#graph-preloader');
                return state?.mode === expected.mode && state?.treeVariant === expected.source
                    && loader?.getAttribute('aria-hidden') === 'true';
            }""",
            arg={"mode": mode, "source": source},
            timeout=DEFAULT_TIMEOUT_MS,
        )
        page.evaluate("() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))")
        snapshot = collect_graph_diagnostics(page)
        transitions.append({"mode": mode, "source": source, "snapshot": snapshot})

    transition("#toggle-taxonomy-display", "tree", "all")
    transition("#toggle-taxonomy-display", "taxonomy_graph", "all")
    transition("#toggle-taxonomy-source", "taxonomy_graph", "rmsk_repbase")
    transition("#toggle-taxonomy-display", "tree", "rmsk_repbase")
    transition("#toggle-taxonomy-display", "taxonomy_graph", "rmsk_repbase")
    transition("#toggle-taxonomy-source", "taxonomy_graph", "all")

    graph_instance_ids = []
    departed_instance_id = before.get("instanceId")
    for transition_item in transitions:
        snapshot = transition_item["snapshot"]
        require((snapshot.get("loader") or {}).get("ariaHidden") == "true",
                f"Transition loader did not settle: {transition_item}")
        renderer = snapshot.get("renderer")
        lifecycle = snapshot.get("lifecycle") or {}
        if transition_item["mode"] == "tree":
            require(renderer is None, f"Tree boundary retained a live taxonomy renderer: {transition_item}")
            require(lifecycle.get("liveInstanceCount") == 0,
                    f"Tree boundary retained live renderer ownership: {transition_item}")
            require(departed_instance_id in lifecycle.get("destroyedInstanceIds", []),
                    f"Departed renderer was not recorded destroyed: {transition_item}")
            departed_instance_id = None
            continue
        require(renderer and renderer.get("live") is True, f"Graph boundary has no live renderer: {transition_item}")
        require(renderer.get("source") == transition_item["source"] and renderer.get("sourceKind") == "taxonomy",
                f"Renderer diagnostics report the wrong source: {transition_item}")
        require(renderer.get("graphId") == f"taxonomy:{transition_item['source']}",
                f"Graph boundary mixed taxonomy source data: {transition_item}")
        require(renderer["visible"]["nodes"] == snapshot.get("nodeCount")
                and renderer["visible"]["edges"] == snapshot.get("edgeCount"),
                f"Graph boundary counts disagree: {transition_item}")
        require(snapshot.get("invalidEdgeCount") == 0, f"Graph boundary has invalid endpoints: {transition_item}")
        require(lifecycle.get("liveInstanceCount") == 1
                and lifecycle.get("liveInstanceIds") == [renderer.get("instanceId")],
                f"Graph boundary must own exactly the current renderer: {transition_item}")
        if departed_instance_id:
            require(departed_instance_id in lifecycle.get("destroyedInstanceIds", []),
                    f"Source transition did not record the departed renderer: {transition_item}")
        graph_instance_ids.append(renderer.get("instanceId"))
        departed_instance_id = renderer.get("instanceId")
    require(len(graph_instance_ids) == len(set(graph_instance_ids)),
            f"Renderer identity must change at every source/mode boundary: {transitions}")
    require(before.get("instanceId") not in graph_instance_ids,
            f"Renderer identity survived a mode boundary: {transitions}")
    evidence["transitions"] = transitions
    callback_probe = page.evaluate(
        """async () => {
            const api = window.__TEKG_G6_DEFAULT_TREE;
            const graph = api?.getGraph?.();
            const node = (graph?.getNodeData?.() || [])[0];
            if (!graph || !node) throw new Error('Current renderer callback probe is unavailable');
            const before = api.getDiagnostics();
            graph.emit(window.G6.NodeEvent.POINTER_ENTER, { target: { id: node.id }, targetType: 'node' });
            await new Promise(resolve => setTimeout(resolve, 80));
            const after = api.getDiagnostics();
            graph.emit(window.G6.NodeEvent.POINTER_LEAVE, { target: { id: node.id }, targetType: 'node' });
            await new Promise(resolve => setTimeout(resolve, 80));
            return { before, after };
        }"""
    )
    require(callback_probe["after"]["counters"]["hover"] == callback_probe["before"]["counters"]["hover"] + 1,
            f"Graph re-entry duplicated the current renderer hover handler: {callback_probe}")
    evidence["callbackProbe"] = callback_probe
    return evidence


def assert_suite_errors(name: str, errors: BrowserErrors) -> None:
    require(not errors.console_errors,
            f"Console errors detected during {name}:\n" + "\n".join(errors.console_errors[:10]))
    require(not errors.page_errors,
            f"Page errors detected during {name}:\n" + "\n".join(errors.page_errors[:10]))
    require(not errors.failed_requests,
            f"Request failures detected during {name}:\n" + "\n".join(errors.failed_requests[:10]))
    require(errors.taxonomy_responses and all(item.get("status", 500) < 400 for item in errors.taxonomy_responses),
            f"No successful taxonomy response was observed during {name}: {errors.taxonomy_responses}")


def run_browser_suite(context: Any, focus: str, args: argparse.Namespace) -> dict[str, Any]:
    page = context.new_page()
    errors = attach_error_capture(page)
    try:
        evidence = run_initial_check(page, args.screenshot if focus == "initial" else None, errors)
        if focus == "structural-star":
            samples = [collect_structural_star_metrics(page)]
            for _sample_index in range(2):
                page.goto(app_url("preview.php?tree=all"), wait_until="domcontentloaded", timeout=DEFAULT_TIMEOUT_MS)
                wait_for_initial_graph(page, "all")
                samples.append(collect_structural_star_metrics(page))
            evidence["structural_star"] = {"samples": samples}
            if args.baseline_output:
                baseline_path = args.baseline_output if args.baseline_output.is_absolute() else ROOT / args.baseline_output
                baseline_path.parent.mkdir(parents=True, exist_ok=True)
                baseline_path.write_text(json.dumps({
                    "kind": "task8-pre-phase9-structural-star-baseline",
                    "viewport": {"width": 1440, "height": 960},
                    "samples": samples,
                }, ensure_ascii=True, indent=2) + "\n", encoding="utf-8")
                evidence["structural_star"]["baselineOutput"] = str(baseline_path)
            fingerprints = [sample.get("positionFingerprint") for sample in samples]
            fingerprint_keys = [json.dumps(item, sort_keys=True) for item in fingerprints]
            require(len(set(fingerprint_keys)) == 1,
                    f"Structural-star positions are not deterministic across three reloads: {fingerprints}")
            validate_structural_star_metrics(samples[0])
        elif focus == "family-expand":
            evidence["family_expand"] = run_family_expand_check(page)
        elif focus == "hover-drag":
            evidence["hover_drag"] = run_hover_drag_check(page)
        elif focus == "overlap-motion":
            evidence["overlap_motion"] = run_overlap_motion_check(page)
        elif focus == "legend-source-roundtrip":
            evidence["legend_source_roundtrip"] = run_legend_source_roundtrip_check(page)
        assert_suite_errors(focus, errors)
        return evidence
    finally:
        page.close()


def main() -> None:
    args = parse_args()
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed. Install requirements-dev.txt and Chromium.")

    with sync_playwright() as playwright:
        try:
            browser = playwright.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")
        context = browser.new_context(viewport={"width": 1440, "height": 960}, device_scale_factor=1)
        try:
            focuses = list(VALID_FOCUS[:-1]) if args.focus == "all" else [args.focus]
            suites: dict[str, Any] = {}
            suite_failures: dict[str, str] = {}
            for focus in focuses:
                try:
                    suites[focus] = run_browser_suite(context, focus, args)
                except HarnessFailure as exc:
                    suite_failures[focus] = str(exc)
        finally:
            context.close()
            browser.close()

    print(json.dumps(suites, ensure_ascii=True, indent=2, default=str))
    if suite_failures:
        fail("Browser suites failed independently:\n" + "\n".join(
            f"- {name}: {message}" for name, message in suite_failures.items()
        ))
    for focus in focuses:
        evidence = suites[focus]
        ok(
            f"All-TE {focus} browser contract passed: "
            f"API {evidence['api']['node_count']}/{evidence['api']['edge_count']}, "
            f"visible {evidence['graph']['nodeCount']}/{evidence['graph']['edgeCount']}"
        )


if __name__ == "__main__":
    run_check(main)
