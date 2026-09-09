from __future__ import annotations

from pathlib import Path

from harness_lib import ROOT, app_url, fail, ok, require, run_check


CASES = [
    ("L1HS", "cancer_cell_line", 26, 100),
    ("L1HS", "normal_cell_line", 26, 100),
    ("L1HS", "normal_tissue", 26, 100),
    ("LTR5", "cancer_cell_line", 13, 36),
    ("MER11B", "cancer_cell_line", 15, 100),
    ("HERVH-int", "cancer_cell_line", 19, 100),
    ("CR1", "normal_tissue", 13, 77),
]


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed.")

    evidence_dir = ROOT / "docs" / "eval" / "runs" / "2026-07-25-coexpression-dual-mode" / "representative-cases"
    evidence_dir.mkdir(parents=True, exist_ok=True)

    with sync_playwright() as playwright:
        try:
            browser = playwright.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")

        page = browser.new_page(viewport={"width": 1440, "height": 960})
        errors: list[str] = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.on("console", lambda message: errors.append(message.text) if message.type == "error" else None)
        try:
            center_expression: dict[str, float] = {}
            page.goto(app_url("preview.php"), wait_until="domcontentloaded", timeout=30_000)
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE && window.__TEKG_PREVIEW_WORKSPACE_MODE",
                timeout=15_000,
            )
            require(
                page.locator("#graph-expression-layer").count() == 0,
                "Knowledge Graph still exposes the retired Expression control.",
            )
            if page.locator("#qaOverlay.is-open").count():
                page.click("#qaFab")
                page.wait_for_function("() => !document.querySelector('#qaOverlay').classList.contains('is-open')")

            for te, context, nodes, edges in CASES:
                page.evaluate(
                    """([te, context]) => window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode(
                      'coexpression',
                      { te, context }
                    )""",
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
                state = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
                require(
                    state["nodeCount"] == nodes and state["edgeCount"] == edges,
                    f"Unexpected {te}/{context} graph: {state}",
                )
                require(
                    state["expressionEnabled"] is True and state["expressionAvailableCount"] > 0,
                    f"Expression activity did not load for {te}/{context}: {state}",
                )
                graph = page.evaluate(
                    "() => document.querySelector('#coexpression-graph-frame').contentWindow.__TEKG_COEXPRESSION_EMBED.getVisibleSubgraph()"
                )
                ids = {node["id"] for node in graph["nodes"]}
                require(
                    all(edge["source"] in ids and edge["target"] in ids for edge in graph["edges"]),
                    f"Visible edges have missing endpoints for {te}/{context}.",
                )
                te_nodes = [node for node in graph["nodes"] if node["type"] == "TE"]
                gene_nodes = [node for node in graph["nodes"] if node["type"] == "Gene"]
                center_node = next(node for node in graph["nodes"] if node["id"] == te)
                require(
                    float(center_node["size"]) > 0,
                    f"Center TE has an invalid standard node size for {te}/{context}: {center_node}",
                )
                require(
                    any(node["expression_value"] is not None for node in te_nodes),
                    f"No TE activity values reached the renderer for {te}/{context}.",
                )
                require(
                    not gene_nodes or all(node["expression_value"] is not None for node in gene_nodes),
                    f"Gene activity values did not reach the renderer for {te}/{context}.",
                )
                if context == "cancer_cell_line" and te in {"LTR5", "L1HS", "HERVH-int"}:
                    center_expression[te] = float(center_node["expression_value"])
                if te in {"LTR5", "MER11B", "HERVH-int", "CR1"}:
                    page.screenshot(
                        path=str(evidence_dir / f"{te.replace('/', '_')}-{context}.png"),
                        full_page=True,
                    )

            require(
                center_expression["LTR5"] < center_expression["L1HS"] < center_expression["HERVH-int"],
                f"Representative center Expression ordering changed: {center_expression}",
            )
            svg_text = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.exportSvgText()")
            svg_report = page.evaluate(
                """(svg) => {
                  const documentNode = new DOMParser().parseFromString(svg, 'image/svg+xml');
                  return {
                    root: documentNode.documentElement.localName,
                    parserErrors: documentNode.querySelectorAll('parsererror').length,
                    circles: documentNode.querySelectorAll('circle').length,
                    lines: documentNode.querySelectorAll('line').length,
                    labels: documentNode.querySelectorAll('text').length,
                    viewBox: documentNode.documentElement.getAttribute('viewBox') || '',
                  };
                }""",
                svg_text,
            )
            current = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
            require(
                svg_report["root"] == "svg"
                and svg_report["parserErrors"] == 0
                and svg_report["circles"] >= current["nodeCount"]
                and svg_report["lines"] >= current["edgeCount"]
                and svg_report["labels"] > 0
                and len(svg_report["viewBox"].split()) == 4,
                f"SVG export is incomplete or invalid: {svg_report}",
            )
            svg_render = page.evaluate(
                """async (svg) => {
                  const image = new Image();
                  const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
                  try {
                    await new Promise((resolve, reject) => {
                      image.onload = resolve;
                      image.onerror = () => reject(new Error('SVG image failed to load'));
                      image.src = url;
                    });
                    const canvas = document.createElement('canvas');
                    canvas.width = 96;
                    canvas.height = 96;
                    const context = canvas.getContext('2d');
                    context.drawImage(image, 0, 0, 96, 96);
                    const pixels = context.getImageData(0, 0, 96, 96).data;
                    let colored = 0;
                    for (let index = 0; index < pixels.length; index += 4) {
                      if (pixels[index + 3] > 0 && (pixels[index] < 245 || pixels[index + 1] < 245 || pixels[index + 2] < 245)) colored += 1;
                    }
                    return { width: image.naturalWidth, height: image.naturalHeight, colored };
                  } finally {
                    URL.revokeObjectURL(url);
                  }
                }""",
                svg_text,
            )
            require(
                svg_render["width"] > 0 and svg_render["height"] > 0 and svg_render["colored"] > 20,
                f"SVG export did not render as a nonblank image: {svg_render}",
            )
            page.click("#coexpression-export-menu-toggle")
            with page.expect_download(timeout=10_000) as download_info:
                page.click("#coexpression-export-svg")
            download = download_info.value
            download_path = download.path()
            require(
                download.suggested_filename.endswith("_coexpression.svg")
                and download_path is not None
                and Path(download_path).read_text(encoding="utf-8").startswith("<svg"),
                f"SVG menu download failed or used an unexpected filename: {download.suggested_filename}",
            )

            page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.activate({te:'LTR5', context:'cancer_cell_line'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'"
                " && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection?.te === 'LTR5'",
                timeout=30_000,
            )
            inspected = page.evaluate(
                """() => {
                  const win = document.querySelector('#coexpression-graph-frame').contentWindow;
                  const embed = win.__TEKG_COEXPRESSION_EMBED;
                  const graph = embed.getVisibleSubgraph();
                  const edgeId = graph.edges[0]?.id || '';
                  const nodeOpened = embed.inspectNode('LTR5');
                  const nodeText = win.document.querySelector('.inspect-card')?.textContent || '';
                  const edgeOpened = edgeId ? embed.inspectEdge(edgeId) : false;
                  const edgeText = win.document.querySelector('.inspect-card')?.textContent || '';
                  return { nodeOpened, edgeOpened, nodeText, edgeText };
                }"""
            )
            require(
                inspected["nodeOpened"]
                and "Co-expression Node" in inspected["nodeText"]
                and "Expression Activity" in inspected["nodeText"],
                f"Center TE detail card is incomplete: {inspected}",
            )
            require(
                inspected["edgeOpened"]
                and "Spearman r" in inspected["edgeText"]
                and "FDR" in inspected["edgeText"],
                f"Co-expression edge detail card is incomplete: {inspected}",
            )

            page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.setViewOptions({showTE:true, showGene:false, edgeScope:'all'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'",
                timeout=30_000,
            )
            te_only = page.evaluate(
                "() => document.querySelector('#coexpression-graph-frame').contentWindow.__TEKG_COEXPRESSION_EMBED.getVisibleSubgraph()"
            )
            require(
                all(node["type"] == "TE" for node in te_only["nodes"]),
                "Gene visibility filtering left Gene nodes visible.",
            )

            page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.setViewOptions({showTE:true, showGene:true, edgeScope:'center'})"
            )
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'",
                timeout=30_000,
            )
            center_only = page.evaluate(
                "() => document.querySelector('#coexpression-graph-frame').contentWindow.__TEKG_COEXPRESSION_EMBED.getVisibleSubgraph()"
            )
            require(
                all(edge["source"] == "LTR5" or edge["target"] == "LTR5" for edge in center_only["edges"]),
                "Center-edge filtering retained internal edges.",
            )

            page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.setExpressionEnabled(false)")
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().expressionEnabled === false",
                timeout=10_000,
            )
            expression_off = page.evaluate(
                "() => document.querySelector('#coexpression-graph-frame').contentWindow.__TEKG_COEXPRESSION_EMBED.getVisibleSubgraph()"
            )
            require(
                all(node["expression_context"] == "off" for node in expression_off["nodes"] if node["type"] == "TE"),
                "Expression Off left active TE activity state in the renderer.",
            )
            page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.setExpressionEnabled(true)")
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().expressionEnabled === true",
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
                f"CR1 unavailable recovery lost valid alternatives: {unavailable}",
            )

            require(not errors, f"Browser errors: {errors[:5]}")
            ok("Task 8 representative networks, Expression activity, filtering, and scientific isolation passed")
        finally:
            page.close()
            browser.close()


if __name__ == "__main__":
    run_check(main)
