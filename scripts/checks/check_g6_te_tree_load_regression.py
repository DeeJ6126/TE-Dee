from __future__ import annotations

import json
from typing import Any
from urllib.parse import quote

from harness_lib import app_url, fail, ok, require, run_check


SEARCH_QUERIES = [
    "LINE1",
    "L1HS",
    "SINEs",
    "Class I: Retrotransposons",
    "Class II: DNA Transposons",
    "others",
]

TREE_TARGETS = [
    "LINE1",
    "L1HS",
    "SINEs",
    "Class I: Retrotransposons",
    "Class II: DNA Transposons",
    "others",
]


def evidence(data: Any) -> str:
    return json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True)


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

    console_errors: list[str] = []
    page_errors: list[str] = []
    failed_requests: list[str] = []
    graph_requests: list[dict[str, Any]] = []

    with sync_playwright() as p:
        try:
            browser = p.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium. Run python -m playwright install chromium. Original error: {exc}")

        page = browser.new_page(viewport={"width": 1440, "height": 960})
        page.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)
        page.on("pageerror", lambda exc: page_errors.append(str(exc)))
        page.on("requestfailed", lambda request: failed_requests.append(f"{request.url} :: {request.failure}"))
        page.on(
            "response",
            lambda response: graph_requests.append(
                {
                    "url": response.url,
                    "status": response.status,
                }
            )
            if "/api/graph.php" in response.url
            else None,
        )

        try:
            page.goto(app_url("preview.php"), wait_until="domcontentloaded", timeout=30000)
            page.wait_for_function(
                """() => {
                    return window.__TEKG_G6_BRIDGE
                        && typeof window.__TEKG_G6_BRIDGE.getState === 'function'
                        && typeof window.__TEKG_G6_BRIDGE.loadGraph === 'function'
                        && typeof window.__TEKG_G6_BRIDGE.getTeLoaderKind === 'function';
                }""",
                timeout=30000,
            )
            page.wait_for_function(
                """() => {
                    const loader = document.querySelector('#graph-preloader');
                    return loader && loader.getAttribute('aria-hidden') === 'true';
                }""",
                timeout=45000,
            )

            taxonomy_probe = page.evaluate(
                """async () => {
                    const source = window.__TEKG_TREE_VARIANT || 'rmsk_repbase';
                    const url = window.__TEKG_PATHS.apiUrl('taxonomy.php?view=tree&source=' + encodeURIComponent(source));
                    const response = await fetch(url, { credentials: 'same-origin' });
                    const payload = await response.json();
                    const nodes = Array.isArray(payload.nodes) ? payload.nodes : [];
                    const names = nodes.map((node) => String(node && node.name || ''));
                    return {
                        source,
                        ok: response.ok,
                        status: response.status,
                        nodeCount: nodes.length,
                        edgeCount: Array.isArray(payload.edges) ? payload.edges.length : 0,
                        hasLINE1: names.includes('LINE1'),
                        hasL1HS: names.includes('L1HS'),
                        hasSINE: names.includes('SINE'),
                        hasOthers: names.includes('others') || names.includes('Others'),
                        hasClassI: names.includes('Retrotransposon'),
                        hasClassII: names.includes('DNA Transposon'),
                        sample: names.slice(0, 30),
                    };
                }"""
            )
            require(taxonomy_probe["ok"] is True, "Taxonomy API failed\n" + evidence(taxonomy_probe))

            tree_probe = page.evaluate(
                """() => {
                    const host = document.querySelector('#g6-default-tree-surface');
                    const canvas = host ? host.querySelector('canvas') : null;
                    const hostBox = host ? host.getBoundingClientRect() : null;
                    const canvasBox = canvas ? canvas.getBoundingClientRect() : null;
                    const state = window.__TEKG_G6_BRIDGE.getState();
                    return {
                        mode: state.mode,
                        treeVariant: state.treeVariant,
                        hostVisible: !!(host && getComputedStyle(host).display !== 'none'),
                        hostWidth: hostBox ? hostBox.width : 0,
                        hostHeight: hostBox ? hostBox.height : 0,
                        canvas: !!canvas,
                        canvasWidth: canvasBox ? canvasBox.width : 0,
                        canvasHeight: canvasBox ? canvasBox.height : 0,
                        canvasTop: canvasBox ? canvasBox.top : 0,
                        loaderHidden: document.querySelector('#graph-preloader')?.getAttribute('aria-hidden'),
                    };
                }"""
            )
            require(tree_probe["canvas"] is True, "Initial TE tree canvas missing\n" + evidence({"tree": tree_probe, "taxonomy": taxonomy_probe}))
            require(tree_probe["loaderHidden"] == "true", "Initial TE tree loader should hide\n" + evidence(tree_probe))
            require(tree_probe["hostWidth"] > 300 and tree_probe["hostHeight"] > 300, "Initial TE tree host has abnormal size\n" + evidence(tree_probe))
            require(tree_probe["canvasHeight"] > 300, "Initial TE tree canvas has abnormal height\n" + evidence(tree_probe))

            kind_probe = page.evaluate(
                """() => ({
                    SINEs: window.__TEKG_G6_BRIDGE.getTeLoaderKind('SINEs'),
                    classI: window.__TEKG_G6_BRIDGE.getTeLoaderKind('Class I: Retrotransposons'),
                    classII: window.__TEKG_G6_BRIDGE.getTeLoaderKind('Class II: DNA Transposons'),
                    others: window.__TEKG_G6_BRIDGE.getTeLoaderKind('others')
                })"""
            )

            diagnostics: list[dict[str, Any]] = []

            def probe_api(query: str) -> dict[str, Any]:
                return page.evaluate(
                    """async (query) => {
                        const url = window.__TEKG_PATHS.apiUrl('graph.php?q=' + encodeURIComponent(query));
                        const response = await fetch(url, { credentials: 'same-origin' });
                        let payload = {};
                        try { payload = await response.json(); } catch (_error) {}
                        const elements = Array.isArray(payload.elements) ? payload.elements : [];
                        const nodes = elements.filter((item) => item && item.data && !item.data.source && !item.data.target).length;
                        const edges = elements.filter((item) => item && item.data && item.data.source && item.data.target).length;
                        return { ok: response.ok, status: response.status, nodes, edges, url };
                    }""",
                    query,
                )

            def probe_load(query: str, source: str) -> dict[str, Any]:
                graph_requests.clear()
                console_errors.clear()
                failed_requests.clear()
                page_errors.clear()
                page.goto(app_url(f"preview.php?q={quote(query)}"), wait_until="domcontentloaded", timeout=30000)
                page.wait_for_function(
                    """() => window.__TEKG_G6_BRIDGE && typeof window.__TEKG_G6_BRIDGE.getState === 'function'""",
                    timeout=30000,
                )
                result = page.evaluate(
                    """async ({ query, source }) => {
                        const loaderSnapshots = [];
                        const loader = document.querySelector('#graph-preloader');
                        const capture = () => {
                            const svg = loader ? loader.querySelector('svg.te-mechanism-loader__svg') : null;
                            loaderSnapshots.push({
                                hidden: loader ? loader.getAttribute('aria-hidden') : '',
                                className: loader ? loader.className : '',
                                label: document.querySelector('#graph-preloader-label')?.textContent || '',
                                viewBox: svg ? svg.getAttribute('viewBox') : '',
                            });
                        };
                        capture();
                        const observer = new MutationObserver(capture);
                        if (loader) {
                            observer.observe(loader, { attributes: true, childList: true, subtree: true, attributeFilter: ['class', 'aria-hidden'] });
                        }
                        let ok = true;
                        let error = '';
                        const start = Date.now();
                        while (Date.now() - start < 12000) {
                            const state = window.__TEKG_G6_BRIDGE.getState();
                            const hidden = loader ? loader.getAttribute('aria-hidden') : '';
                            if (hidden === 'true' && state.mode === 'dynamic' && state.query === query) break;
                            await new Promise((resolve) => setTimeout(resolve, 100));
                        }
                        capture();
                        observer.disconnect();
                        await new Promise((resolve) => setTimeout(resolve, 250));
                        const state = window.__TEKG_G6_BRIDGE.getState();
                        const iframe = document.querySelector('#g6-dynamic-surface iframe');
                        const embed = iframe && iframe.contentWindow ? iframe.contentWindow.__TEKG_G6_EMBED : null;
                        let subgraph = null;
                        try {
                            subgraph = embed && typeof embed.getVisibleSubgraph === 'function' ? await embed.getVisibleSubgraph() : null;
                        } catch (_error) {}
                        const iframeCanvas = iframe && iframe.contentDocument ? iframe.contentDocument.querySelector('canvas') : null;
                        return {
                            query,
                            source,
                            ok,
                            error,
                            parentQuery: state.query,
                            parentMode: state.mode,
                            parentElements: Array.isArray(state.currentElements) ? state.currentElements.length : 0,
                            iframeNodes: Array.isArray(subgraph && subgraph.nodes) ? subgraph.nodes.length : 0,
                            iframeEdges: Array.isArray(subgraph && subgraph.edges) ? subgraph.edges.length : 0,
                            iframeRendered: !!iframeCanvas,
                            loaderHidden: loader ? loader.getAttribute('aria-hidden') : '',
                            loaderKind: loaderSnapshots.find((item) => item.className.includes('te-loader-retro') || item.className.includes('te-loader-dna') || item.className.includes('te-loader-default'))?.className || '',
                            loaderSnapshots,
                            actionCardVisible: !!document.querySelector('.inspect-card'),
                        };
                    }""",
                    {"query": query, "source": source},
                )
                result["graphRequests"] = list(graph_requests)
                result["consoleErrors"] = list(console_errors)
                result["failedRequests"] = list(failed_requests)
                result["pageErrors"] = list(page_errors)
                api = probe_api(query)
                result["api"] = api
                result["failureLayer"] = classify_failure(result)
                diagnostics.append(result)
                return result

            def classify_failure(result: dict[str, Any]) -> str:
                api = result.get("api") or {}
                if not api.get("ok"):
                    return "api-failure"
                if api.get("nodes", 0) <= 0:
                    return "api-empty"
                if result.get("error") == "timeout":
                    return "frontend-timeout"
                if result.get("loaderHidden") != "true":
                    return "loader-not-hidden"
                if not result.get("iframeRendered") or result.get("iframeNodes", 0) <= 0:
                    return "iframe-render"
                return "ok"

            for query in SEARCH_QUERIES:
                probe_load(query, "search")

            page.goto(app_url("preview.php"), wait_until="domcontentloaded", timeout=30000)
            page.wait_for_function(
                """() => {
                    const loader = document.querySelector('#graph-preloader');
                    return window.__TEKG_G6_BRIDGE
                        && loader
                        && loader.getAttribute('aria-hidden') === 'true'
                        && window.__TEKG_G6_BRIDGE.getState().mode === 'tree';
                }""",
                timeout=45000,
            )
            tree_actions = page.evaluate(
                """async () => {
                    const actions = [];
                    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
                    const graph = window.__TEKG_G6_DEFAULT_TREE && window.__TEKG_G6_DEFAULT_TREE.getGraph
                        ? window.__TEKG_G6_DEFAULT_TREE.getGraph()
                        : null;
                    const allNodes = graph && typeof graph.getNodeData === 'function' ? graph.getNodeData() : [];
                    const findNode = (label) => {
                        const expected = String(label || '').toLowerCase();
                        return allNodes.find((node) => {
                            const data = node && node.data ? node.data : {};
                            const texts = [
                                node.id,
                                node.name,
                                node.style && node.style.labelText,
                                data.rawLabel,
                                data.queryLabel,
                            ].map((item) => String(item || '').toLowerCase());
                            return texts.some((text) => text === expected || text.includes(expected));
                        });
                    };
                    const clickNode = async (label) => {
                        const node = findNode(label);
                        if (!node || !graph || typeof graph.emit !== 'function') {
                            actions.push({ label, found: !!node, emitted: false });
                            return;
                        }
                        const before = window.__TEKG_G6_BRIDGE.getState();
                        const target = { id: node.id };
                        graph.emit('node:click', { target, targetType: 'node' });
                        await sleep(1200);
                        const after = window.__TEKG_G6_BRIDGE.getState();
                        actions.push({
                            label,
                            found: true,
                            emitted: true,
                            beforeMode: before.mode,
                            afterMode: after.mode,
                            beforeQuery: before.query,
                            afterQuery: after.query,
                            directChildCount: Number(node.style && node.style.directChildCount || node.data && node.data.directChildCount || 0),
                            loaderHidden: document.querySelector('#graph-preloader')?.getAttribute('aria-hidden'),
                        });
                    };
                    await clickNode('Class I: Retrotransposons');
                    await clickNode('Class II: DNA Transposons');
                    await clickNode('Others');
                    const expandTo = async (label) => {
                        const expected = String(label || '').toLowerCase();
                        const findStateNode = (node) => {
                            if (!node) return null;
                            const texts = [
                                node.name,
                                node.id,
                                node.data && node.data.rawLabel,
                                node.data && node.data.queryLabel,
                                node.style && node.style.labelText,
                            ].map((item) => String(item || '').toLowerCase());
                            if (texts.some((text) => text === expected || text.includes(expected))) return node;
                            for (const child of Array.isArray(node.children) ? node.children : []) {
                                const found = findStateNode(child);
                                if (found) return found;
                            }
                            return null;
                        };
                        const target = findStateNode(window.__TEKG_G6_DEFAULT_TREE.getStateTree ? window.__TEKG_G6_DEFAULT_TREE.getStateTree() : null);
                        const path = [];
                        let current = target;
                        while (current) {
                            path.unshift(current);
                            current = current._parent || null;
                        }
                        for (const node of path.slice(0, -1)) {
                            graph.emit('tekg-mindmap-collapse-expand', { id: node.id, collapsed: false });
                            await sleep(120);
                        }
                        await sleep(800);
                        return { found: !!target, path: path.map((node) => node.name || node.id) };
                    };
                    const l1Path = await expandTo('L1HS');
                    const latestGraph = window.__TEKG_G6_DEFAULT_TREE && window.__TEKG_G6_DEFAULT_TREE.getGraph
                        ? window.__TEKG_G6_DEFAULT_TREE.getGraph()
                        : graph;
                    let latestNodes = [];
                    try {
                        latestNodes = latestGraph && typeof latestGraph.getNodeData === 'function' ? latestGraph.getNodeData() : [];
                    } catch (_error) {}
                    const l1Node = latestNodes.find((node) => {
                        const data = node && node.data ? node.data : {};
                        return [node.id, node.style && node.style.labelText, data.rawLabel, data.queryLabel]
                            .map((item) => String(item || '').trim())
                            .includes('L1HS');
                    });
                    let l1Jump = null;
                    if (l1Path.found) {
                        const before = window.__TEKG_G6_BRIDGE.getState();
                        await window.__TEKG_G6_BRIDGE.loadGraph('L1HS');
                        const start = Date.now();
                        while (Date.now() - start < 12000) {
                            const state = window.__TEKG_G6_BRIDGE.getState();
                            const hidden = document.querySelector('#graph-preloader')?.getAttribute('aria-hidden');
                            if (hidden === 'true' && state.mode === 'dynamic' && state.query === 'L1HS') break;
                            await sleep(100);
                        }
                        const after = window.__TEKG_G6_BRIDGE.getState();
                        const iframe = document.querySelector('#g6-dynamic-surface iframe');
                        const embed = iframe && iframe.contentWindow ? iframe.contentWindow.__TEKG_G6_EMBED : null;
                        let subgraph = null;
                        try {
                            subgraph = embed && typeof embed.getVisibleSubgraph === 'function' ? await embed.getVisibleSubgraph() : null;
                        } catch (_error) {}
                        l1Jump = {
                            beforeMode: before.mode,
                            afterMode: after.mode,
                            afterQuery: after.query,
                            loaderHidden: document.querySelector('#graph-preloader')?.getAttribute('aria-hidden'),
                            nodeId: l1Node ? l1Node.id : '',
                            visibleLabels: latestNodes.slice(0, 30).map((node) => node.style?.labelText || node.data?.rawLabel || node.id),
                            nodes: Array.isArray(subgraph && subgraph.nodes) ? subgraph.nodes.length : 0,
                            edges: Array.isArray(subgraph && subgraph.edges) ? subgraph.edges.length : 0,
                        };
                    }
                    return {
                        actions,
                        l1Path,
                        l1NodeFound: !!l1Node,
                        l1Jump,
                        nodeCount: allNodes.length,
                        labels: allNodes.slice(0, 20).map((node) => node.style?.labelText || node.data?.rawLabel || node.id)
                    };
                }"""
            )

            summary = {
                "taxonomy": taxonomy_probe,
                "tree": tree_probe,
                "kind": kind_probe,
                "treeActions": tree_actions,
                "diagnostics": [
                    {
                        "source": row["source"],
                        "query": row["query"],
                        "requestQ": row["graphRequests"][-1]["url"] if row["graphRequests"] else "",
                        "apiOk": row["api"]["ok"],
                        "apiStatus": row["api"]["status"],
                        "apiNodes": row["api"]["nodes"],
                        "apiEdges": row["api"]["edges"],
                        "iframeNodes": row["iframeNodes"],
                        "iframeEdges": row["iframeEdges"],
                        "iframeRendered": row["iframeRendered"],
                        "loaderHidden": row["loaderHidden"],
                        "failureLayer": row["failureLayer"],
                        "loaderKind": row["loaderKind"],
                        "error": row["error"],
                    }
                    for row in diagnostics
                ],
            }

            require(kind_probe["SINEs"] == "retro", "SINEs should classify as retro\n" + evidence(summary))
            require(kind_probe["classI"] == "retro", "Class I should classify as retro\n" + evidence(summary))
            require(kind_probe["classII"] == "dna", "Class II should classify as dna\n" + evidence(summary))
            require(kind_probe["others"] == "default", "others should keep default loader\n" + evidence(summary))

            for row in diagnostics:
                require(row["loaderHidden"] == "true", "Loader stuck after graph load\n" + evidence(summary))
                require(row["failureLayer"] in ("ok", "api-empty", "iframe-render"), "Graph load regression detected\n" + evidence(summary))
                if row["failureLayer"] == "iframe-render":
                    require(row["api"]["nodes"] == 1 and row["api"]["edges"] == 0, "Only single-node API results may skip canvas render\n" + evidence(summary))

            for action in tree_actions["actions"]:
                require(action["found"] is True, "Expected tree action node not found\n" + evidence(summary))
                require(action["loaderHidden"] == "true", "Tree action left loader visible\n" + evidence(summary))
                require(action["afterMode"] == "tree", "Category tree action should stay in tree mode instead of graph querying\n" + evidence(summary))
            require(tree_actions["l1Path"]["found"] is True, "L1HS should exist in frontend tree state\n" + evidence(summary))
            require(tree_actions["l1Jump"] and tree_actions["l1Jump"]["afterMode"] == "dynamic", "Tree L1HS click should jump to dynamic graph\n" + evidence(summary))
            require(tree_actions["l1Jump"]["afterQuery"] == "L1HS", "Tree L1HS jump should query L1HS\n" + evidence(summary))
            require(tree_actions["l1Jump"]["loaderHidden"] == "true", "Tree L1HS jump should hide loader\n" + evidence(summary))
            require(tree_actions["l1Jump"]["nodes"] > 0 and tree_actions["l1Jump"]["edges"] > 0, "Tree L1HS jump should render graph nodes/edges\n" + evidence(summary))

            ok("G6 TE tree/load regression diagnostics passed\n" + evidence(summary))
        except PlaywrightTimeoutError as exc:
            fail(f"Timed out while checking G6 TE tree/load regression: {exc}")
        finally:
            browser.close()


if __name__ == "__main__":
    run_check(main)
