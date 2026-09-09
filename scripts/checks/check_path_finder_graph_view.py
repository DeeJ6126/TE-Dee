from __future__ import annotations

from harness_lib import app_url, fail, ok, require, run_check


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed")

    with sync_playwright() as p:
        try:
            browser = p.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")

        page = browser.new_page(viewport={"width": 1440, "height": 980})
        console_errors: list[str] = []
        graph_api_requests: list[str] = []
        page.on(
            "console",
            lambda msg: console_errors.append(msg.text)
            if msg.type == "error" and "ERR_NETWORK_ACCESS_DENIED" not in msg.text
            else None,
        )
        page.on("request", lambda request: graph_api_requests.append(request.url) if "api/graph.php" in request.url else None)
        try:
            page.goto(app_url("path_finder.php"), wait_until="domcontentloaded", timeout=30000)
            page.locator("#pathSourceType").select_option("TE")
            page.locator("#pathSource").fill("AluJb")
            page.locator("#pathTargetType").select_option("Disease")
            page.locator("#pathTarget").fill("Cancer")
            page.locator("#pathMaxDepth").select_option("3")
            page.locator("#pathSubmit").click()
            page.locator("#pathResults .path-card").first.wait_for(timeout=60000)

            table_text = page.locator("#pathResults").text_content(timeout=10000) or ""
            page.locator("#pathGraphView").click()
            page.locator("#pathGraphPanel").wait_for(state="visible", timeout=10000)
            page.locator("#pathGraphSurface canvas").first.wait_for(timeout=60000)
            page.wait_for_function(
                "() => !document.querySelector('#pathGraphExport')?.disabled",
                timeout=60000,
            )
            page.wait_for_function(
                "() => !!window.__TEKG_PATH_FINDER_GRAPH_DEBUG?.getVisibleSubgraph()?.nodes?.length",
                timeout=60000,
            )
            graph_debug_state = page.evaluate(
                """
                () => {
                  const subgraph = window.__TEKG_PATH_FINDER_GRAPH_DEBUG.getVisibleSubgraph();
                  const nodes = subgraph?.nodes || [];
                  const highlighted = nodes.filter((node) => node.endpointHighlight === true);
                  const minSize = nodes.reduce((value, node) => Math.min(value, Number(node.size || 0)), Infinity);
                  return {
                    nodeCount: nodes.length,
                    edgeCount: subgraph?.edges?.length || 0,
                    highlighted: highlighted.map((node) => ({ label: node.label, size: node.size })),
                    minSize: Number.isFinite(minSize) ? minSize : 0,
                  };
                }
                """
            )
            edge_inspect_state = page.evaluate(
                """
                () => {
                  const debug = window.__TEKG_PATH_FINDER_GRAPH_DEBUG;
                  const subgraph = debug?.getVisibleSubgraph?.();
                  const edge = (subgraph?.edges || []).find((item) => (item.pmids || []).length > 0)
                    || (subgraph?.edges || [])[0];
                  return {
                    edgeId: edge?.id || '',
                    inspected: edge?.id ? debug.inspectEdge(edge.id) : false,
                  };
                }
                """
            )
            page.locator("#pathGraphSurface .inspect-card").wait_for(timeout=10000)
            page.locator("#pathGraphSurface [data-inspect-action='toggle']").click()
            page.locator("#pathGraphSurface .inspect-card.is-expanded").wait_for(timeout=10000)
            edge_card_state = page.locator("#pathGraphSurface .inspect-card.is-expanded").evaluate(
                """
                card => ({
                  title: card.querySelector('.inspect-card__title')?.textContent.trim() || '',
                  tableCount: card.querySelectorAll('.edge-evidence-table').length,
                  pubmedLinks: card.querySelectorAll('a[href^="https://pubmed.ncbi.nlm.nih.gov/"]').length,
                })
                """
            )
            state = page.evaluate(
                """
                () => ({
                  tableHidden: document.querySelector('#pathResults')?.hidden === true,
                  graphHidden: document.querySelector('#pathGraphPanel')?.hidden === true,
                  showNamesRemoved: document.querySelector('#pathGraphShowNames') === null,
                  showRelationsText: document.querySelector('#pathGraphShowRelations')?.textContent.trim() || '',
                  showRelationsPressed: document.querySelector('#pathGraphShowRelations')?.getAttribute('aria-pressed') || '',
                  exportDisabled: document.querySelector('#pathGraphExport')?.disabled === true,
                  exportOptions: Array.from(document.querySelectorAll('#pathGraphExportMenu [role="menuitem"]')).map((item) => item.textContent.trim()),
                  canvasCount: document.querySelectorAll('#pathGraphSurface canvas').length,
                })
                """
            )
        finally:
            browser.close()

    require(not console_errors, "Path Finder graph console errors: " + " | ".join(console_errors[:5]))
    require(not graph_api_requests, "Path Finder graph view should not request graph.php: " + " | ".join(graph_api_requests[:5]))
    require("Relation type" not in table_text, "Path Finder table should not show raw relation type metadata")
    require("BIO_RELATION" not in table_text, "Path Finder table should not show raw BIO_RELATION labels")
    require(state["tableHidden"] is True, f"Table results should hide in graph mode: {state}")
    require(state["graphHidden"] is False, f"Graph panel should be visible in graph mode: {state}")
    require(state["showNamesRemoved"] is True, f"Redundant Show names control should be removed: {state}")
    require(state["showRelationsPressed"] == "true" and "On" in state["showRelationsText"], f"Show relations should default on: {state}")
    require(state["exportDisabled"] is False, f"Export should be enabled after graph render: {state}")
    require(state["exportOptions"] == ["CSV", "PNG", "SVG"], f"Export options should match Graph: {state}")
    require(state["canvasCount"] > 0, f"Graph surface should contain a G6 canvas: {state}")
    require(graph_debug_state["nodeCount"] > 0, f"Graph should expose visible nodes: {graph_debug_state}")
    require(graph_debug_state["edgeCount"] > 0, f"Graph should expose visible edges: {graph_debug_state}")
    require(len(graph_debug_state["highlighted"]) == 2, f"Source and target should be highlighted: {graph_debug_state}")
    require(edge_inspect_state["edgeId"] and edge_inspect_state["inspected"] is True, f"Path edge inspection should be available: {edge_inspect_state}")
    require(edge_card_state["title"], f"Path edge card should identify the relation: {edge_card_state}")
    require(edge_card_state["tableCount"] == 1, f"Expanded Path edge card should show its evidence table: {edge_card_state}")
    require(edge_card_state["pubmedLinks"] > 0, f"Expanded Path edge card should expose PubMed links: {edge_card_state}")
    require(all(node["size"] >= 50 for node in graph_debug_state["highlighted"]), f"Highlighted endpoints should respect the Path node minimum: {graph_debug_state}")
    require(graph_debug_state["minSize"] >= 50, f"Path Finder graph nodes should respect nodeMinSize=50: {graph_debug_state}")
    ok("Path Finder graph view smoke passed")


if __name__ == "__main__":
    run_check(main)
