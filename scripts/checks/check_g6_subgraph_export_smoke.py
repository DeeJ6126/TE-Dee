from __future__ import annotations

import json
import os
from typing import Any
from urllib.parse import quote

from harness_lib import app_url, fail, ok, require, run_check


def evidence(data: dict[str, Any]) -> str:
    return json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True)


def parse_csv_rows(text: str) -> list[list[str]]:
    rows: list[list[str]] = []
    row: list[str] = []
    field: list[str] = []
    in_quotes = False
    index = 0
    while index < len(text):
        char = text[index]
        if in_quotes:
            if char == '"' and index + 1 < len(text) and text[index + 1] == '"':
                field.append('"')
                index += 1
            elif char == '"':
                in_quotes = False
            else:
                field.append(char)
        else:
            if char == '"':
                in_quotes = True
            elif char == ",":
                row.append("".join(field))
                field = []
            elif char == "\n":
                row.append("".join(field))
                rows.append(row)
                row = []
                field = []
            elif char != "\r":
                field.append(char)
        index += 1
    if field or row:
        row.append("".join(field))
        rows.append(row)
    return rows


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

    query = str(os.environ.get("TEKG_EXPORT_QUERY") or "LINE1").strip() or "LINE1"
    url = app_url(f"preview.php?q={quote(query)}")
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
            frame = page.frame_locator("#g6-dynamic-surface iframe")
            frame.locator("#container").wait_for(timeout=30000)
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
            page.wait_for_function(
                """() => {
                    const list = document.querySelector('#graph-legend-list');
                    return list && !/Loading legend/i.test(list.textContent || '') && list.children.length > 0;
                }""",
                timeout=30000,
            )

            controls = page.evaluate(
                """() => ({
                    mainButtons: document.querySelectorAll('#export-menu-toggle').length,
                    oldCsvButtons: document.querySelectorAll('#export-csv').length,
                    oldPngButtons: document.querySelectorAll('#export-png').length,
                    menu: !!document.querySelector('#export-menu'),
                    csvItem: !!document.querySelector('#export-menu-csv'),
                    pngItem: !!document.querySelector('#export-menu-png'),
                    svgItem: !!document.querySelector('#export-menu-svg'),
                    exportBridge: !!window.__TEKG_G6_EXPORT,
                    exportMethods: window.__TEKG_G6_EXPORT ? Object.keys(window.__TEKG_G6_EXPORT).sort() : [],
                    mainDisabled: document.querySelector('#export-menu-toggle')?.disabled ?? null,
                    svgDisabled: document.querySelector('#export-menu-svg')?.disabled ?? null,
                    svgText: document.querySelector('#export-menu-svg')?.textContent?.trim() || '',
                })"""
            )
            require(controls["mainButtons"] == 1, "Expected exactly one Export main button\n" + evidence(controls))
            require(controls["oldCsvButtons"] == 0 and controls["oldPngButtons"] == 0, "Old separate export buttons are still present\n" + evidence(controls))
            require(controls["menu"], "Missing Export menu\n" + evidence(controls))
            require(controls["csvItem"], "Missing CSV menu item\n" + evidence(controls))
            require(controls["pngItem"], "Missing PNG menu item\n" + evidence(controls))
            require(controls["svgItem"], "Missing SVG menu item\n" + evidence(controls))
            require(controls["exportBridge"], "Missing parent export bridge\n" + evidence(controls))
            require(controls["mainDisabled"] is False, "Export main button is disabled after graph load\n" + evidence(controls))
            require(controls["svgDisabled"] is False, "SVG menu item is disabled after graph load\n" + evidence(controls))
            require(controls["svgText"] == "SVG", "SVG menu item still exposes placeholder copy\n" + evidence(controls))
            require("exportSvg" in controls["exportMethods"], "Parent export bridge is missing exportSvg\n" + evidence(controls))

            page.locator("#export-menu-toggle").hover(timeout=15000)
            page.wait_for_selector("#export-menu:not([hidden])", timeout=5000)
            hover_state = page.evaluate(
                """() => ({
                    expanded: document.querySelector('#export-menu-toggle')?.getAttribute('aria-expanded'),
                    hidden: document.querySelector('#export-menu')?.hidden ?? null,
                })"""
            )
            require(hover_state["expanded"] == "true" and hover_state["hidden"] is False, "Export menu did not open on hover\n" + evidence(hover_state))
            page.mouse.move(10, 10)
            page.wait_for_timeout(500)

            page.locator("#export-menu-toggle").click(timeout=15000)
            page.wait_for_selector("#export-menu:not([hidden])", timeout=5000)

            page.evaluate(
                """() => {
                    window.__TEKG_EXPORT_DOWNLOADS = [];
                    if (!HTMLAnchorElement.prototype.__tekgExportOriginalClick) {
                        HTMLAnchorElement.prototype.__tekgExportOriginalClick = HTMLAnchorElement.prototype.click;
                    }
                    HTMLAnchorElement.prototype.click = function () {
                        window.__TEKG_EXPORT_DOWNLOADS.push({
                            download: this.download || '',
                            hrefPrefix: String(this.href || '').slice(0, 40),
                        });
                    };
                }"""
            )
            page.locator("#export-menu-csv").click(timeout=15000)
            page.wait_for_function("() => (window.__TEKG_EXPORT_DOWNLOADS || []).filter((entry) => /\\.csv$/i.test(entry.download)).length >= 2", timeout=10000)
            csv_downloads = page.evaluate("() => window.__TEKG_EXPORT_DOWNLOADS")
            require(
                len([entry for entry in csv_downloads if str(entry.get("download", "")).endswith(".csv")]) >= 2,
                "CSV menu item did not trigger two CSV downloads\n" + evidence({"downloads": csv_downloads}),
            )

            page.keyboard.press("Escape")
            page.wait_for_timeout(300)
            page.locator("#export-menu-toggle").click(timeout=15000)
            page.wait_for_selector("#export-menu:not([hidden])", timeout=5000)
            page.locator("#export-menu-png").click(timeout=15000)
            page.wait_for_function("() => (window.__TEKG_EXPORT_DOWNLOADS || []).some((entry) => /\\.png$/i.test(entry.download))", timeout=10000)
            png_downloads = page.evaluate("() => window.__TEKG_EXPORT_DOWNLOADS")
            require(
                any(str(entry.get("download", "")).endswith(".png") for entry in png_downloads),
                "PNG menu item did not trigger a PNG download\n" + evidence({"downloads": png_downloads}),
            )

            subgraph = page.evaluate("() => window.__TEKG_G6_EXPORT.getVisibleSubgraph()")
            require(isinstance(subgraph, dict), "Visible subgraph export did not return an object")
            require(subgraph.get("counts", {}).get("nodes", 0) > 0, "Visible subgraph has no nodes\n" + evidence(subgraph))
            require(subgraph.get("counts", {}).get("edges", 0) > 0, "Visible subgraph has no edges\n" + evidence(subgraph))

            csv_payload = page.evaluate("() => window.__TEKG_G6_EXPORT.exportCsv({ download: false })")
            require(isinstance(csv_payload, dict), "CSV export did not return an object")
            nodes_csv = str(csv_payload.get("nodesCsv") or "")
            edges_csv = str(csv_payload.get("edgesCsv") or "")
            require(nodes_csv.startswith("id,label,rawLabel,type,description,pmid"), "Nodes CSV header is incorrect\n" + nodes_csv[:200])
            require(edges_csv.startswith("id,source,target,relation,relationType,pmids,evidence"), "Edges CSV header is incorrect\n" + edges_csv[:200])
            node_rows = parse_csv_rows(nodes_csv)
            edge_rows = parse_csv_rows(edges_csv)
            require(len(node_rows) - 1 == subgraph["counts"]["nodes"], "Nodes CSV row count does not match visible node count\n" + evidence({"rows": len(node_rows) - 1, "counts": subgraph["counts"]}))
            require(len(edge_rows) - 1 == subgraph["counts"]["edges"], "Edges CSV row count does not match visible edge count\n" + evidence({"rows": len(edge_rows) - 1, "counts": subgraph["counts"]}))

            png_payload = page.evaluate("() => window.__TEKG_G6_EXPORT.exportPng({ download: false })")
            require(isinstance(png_payload, dict), "PNG export did not return an object")
            data_url = str(png_payload.get("dataUrl") or "")
            require(data_url.startswith("data:image/png;base64,"), "PNG export did not return a PNG data URL")
            require(int(png_payload.get("byteLength") or 0) > 1024, "PNG export data is unexpectedly small\n" + evidence(png_payload))

            svg_payload = page.evaluate("() => window.__TEKG_G6_EXPORT.exportSvg({ download: false })")
            require(isinstance(svg_payload, dict), "SVG export did not return an object")
            svg_text = str(svg_payload.get("svg") or "")
            require(svg_text.startswith("<svg"), "SVG export did not return an SVG document")
            svg_report = page.evaluate(
                """(svg) => {
                    const doc = new DOMParser().parseFromString(svg, 'image/svg+xml');
                    return {
                        root: doc.documentElement.localName,
                        errors: doc.querySelectorAll('parsererror').length,
                        nodes: doc.querySelectorAll('[data-node-id]').length,
                        edges: doc.querySelectorAll('[data-edge-id]').length,
                        labels: doc.querySelectorAll('text').length,
                        viewBox: doc.documentElement.getAttribute('viewBox') || '',
                    };
                }""",
                svg_text,
            )
            require(
                svg_report["root"] == "svg"
                and svg_report["errors"] == 0
                and svg_report["nodes"] == subgraph["counts"]["nodes"]
                and svg_report["edges"] == subgraph["counts"]["edges"]
                and svg_report["labels"] > 0
                and len(svg_report["viewBox"].split()) == 4,
                "SVG structure does not match the visible Knowledge Graph\n" + evidence(svg_report),
            )

            page.locator("#export-menu-toggle").click(timeout=15000)
            page.wait_for_selector("#export-menu:not([hidden])", timeout=5000)
            page.locator("#export-menu-svg").click(timeout=15000)
            page.wait_for_function("() => (window.__TEKG_EXPORT_DOWNLOADS || []).some((entry) => /\\.svg$/i.test(entry.download))", timeout=10000)

            filter_result = page.evaluate(
                """async () => {
                    const before = await window.__TEKG_G6_EXPORT.getVisibleSubgraph();
                    const checks = [...document.querySelectorAll('#graph-legend-list input.graph-legend-check')];
                    const disease = checks.find((input) => /Disease/i.test(input.getAttribute('aria-label') || input.dataset.type || ''));
                    if (!disease) return { skipped: true, reason: 'Disease legend checkbox not found', before };
                    if (disease.checked) disease.click();
                    await new Promise((resolve) => window.setTimeout(resolve, 250));
                    const apply = document.querySelector('#graph-legend-apply');
                    if (apply && !apply.disabled) apply.click();
                    await new Promise((resolve) => window.setTimeout(resolve, 1500));
                    const after = await window.__TEKG_G6_EXPORT.getVisibleSubgraph();
                    return { skipped: false, before, after };
                }"""
            )
            require(not filter_result.get("skipped"), "Filter export assertion was skipped\n" + evidence(filter_result))
            before_count = filter_result["before"]["counts"]["nodes"] + filter_result["before"]["counts"]["edges"]
            after_count = filter_result["after"]["counts"]["nodes"] + filter_result["after"]["counts"]["edges"]
            require(after_count < before_count, "Filtered export count did not shrink after hiding Disease\n" + evidence(filter_result))

        except PlaywrightTimeoutError as exc:
            fail(f"G6 subgraph export smoke timed out at {url}: {exc}")
        except PlaywrightError as exc:
            fail(f"G6 subgraph export smoke failed at {url}: {exc}")
        finally:
            browser.close()

    require(not page_errors, "Page errors detected:\n" + "\n".join(page_errors[:10]))
    reference_errors = [message for message in console_errors if "ReferenceError" in message]
    require(not reference_errors, "ReferenceError detected:\n" + "\n".join(reference_errors[:10]))
    require(not failed_requests, "Failed browser requests:\n" + "\n".join(failed_requests[:10]))
    ok(f"G6 subgraph export smoke passed for {url}")


if __name__ == "__main__":
    run_check(main)
