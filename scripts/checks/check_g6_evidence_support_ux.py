from __future__ import annotations

import csv
import io
import json
from typing import Any
from urllib.parse import quote

from harness_lib import app_url, fail, ok, require, run_check


def evidence(data: dict[str, Any]) -> str:
    return json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True)


def parse_csv(text: str) -> list[dict[str, str]]:
    reader = csv.DictReader(io.StringIO(text))
    return list(reader)


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

    query = "LINE1"
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
            frame_locator = page.frame_locator("#g6-dynamic-surface iframe")
            frame_locator.locator("#container").wait_for(timeout=30000)
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

            edge_choice = page.evaluate(
                """async () => {
                    const iframe = document.querySelector('#g6-dynamic-surface iframe');
                    const embed = iframe && iframe.contentWindow ? iframe.contentWindow.__TEKG_G6_EMBED : null;
                    if (!embed || typeof embed.getVisibleSubgraph !== 'function') {
                        return { error: 'missing embed bridge' };
                    }
                    const subgraph = await embed.getVisibleSubgraph();
                    const edges = Array.isArray(subgraph && subgraph.edges) ? subgraph.edges : [];
                    const edge = edges
                        .filter((item) => Array.isArray(item.pmids) && item.pmids.length > 0)
                        .sort((left, right) => (Number(right.support_pmid_count || right.pmids.length) || 0) - (Number(left.support_pmid_count || left.pmids.length) || 0))[0];
                    const zeroCoverage = edges.some((item) => Number(item.support_metric_coverage) === 0);
                    return {
                        edge,
                        zeroCoverage,
                        edgeCount: edges.length,
                        hasInspectEdge: !!embed && typeof embed.inspectEdge === 'function',
                    };
                }"""
            )
            require(not edge_choice.get("error"), "Failed to inspect visible graph\n" + evidence(edge_choice))
            require(edge_choice.get("hasInspectEdge") is True, "Diagnostic inspectEdge bridge is missing\n" + evidence(edge_choice))
            edge = edge_choice.get("edge")
            require(isinstance(edge, dict) and edge.get("id"), "No PMID-backed edge available for evidence UX smoke\n" + evidence(edge_choice))
            require(edge_choice.get("edgeCount", 0) > 0, "Visible graph has no edges\n" + evidence(edge_choice))

            inspected = page.evaluate(
                """async (edgeId) => {
                    const iframe = document.querySelector('#g6-dynamic-surface iframe');
                    const embed = iframe && iframe.contentWindow ? iframe.contentWindow.__TEKG_G6_EMBED : null;
                    return embed.inspectEdge(edgeId);
                }""",
                edge["id"],
            )
            require(inspected is True, "inspectEdge did not render edge detail\n" + evidence({"edge": edge, "result": inspected}))

            detail_state = page.locator("#node-details").evaluate(
                """el => ({
                    text: el.textContent || '',
                    evidenceTables: el.querySelectorAll('.edge-evidence-table').length,
                    csvButtons: el.querySelectorAll('.edge-evidence-download').length,
                    visible: !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length),
                    rect: (() => {
                        const box = el.getBoundingClientRect();
                        return { width: box.width, height: box.height };
                    })(),
                    display: window.getComputedStyle(el).display,
                })"""
            )
            require(
                detail_state["visible"] is False or detail_state["rect"]["height"] <= 2,
                "Legacy detail area must be visually hidden or occupy no obvious layout space\n" + evidence(detail_state),
            )
            require(detail_state["evidenceTables"] == 0, "Lower detail area must not render duplicate evidence table\n" + evidence(detail_state))
            require(detail_state["csvButtons"] == 0, "Lower detail area must not render evidence CSV button\n" + evidence(detail_state))

            frame_locator.locator(".inspect-card").wait_for(timeout=10000)
            frame_locator.locator(".inspect-card__button[data-inspect-action='toggle']").click(timeout=10000)
            frame_locator.locator(".inspect-card.is-expanded .edge-evidence-table").wait_for(timeout=10000)

            table_state = frame_locator.locator(".inspect-card.is-expanded").evaluate(
                """el => {
                    const pubmedSection = [...el.querySelectorAll('.inspect-card__section')]
                        .find((section) => /PubMed/i.test(section.querySelector('.inspect-card__section-title')?.textContent || ''));
                    const table = pubmedSection ? pubmedSection.querySelector('.edge-evidence-table') : null;
                    const headers = table ? [...table.querySelectorAll('th')].map((cell) => cell.textContent.trim()) : [];
                    const rows = table ? [...table.querySelectorAll('tbody tr')] : [];
                    const firstLink = table ? table.querySelector('tbody a[href^="https://pubmed.ncbi.nlm.nih.gov/"]') : null;
                    const download = pubmedSection ? pubmedSection.querySelector('.edge-evidence-download') : null;
                    const journalCell = table ? table.querySelector('tbody tr td:nth-child(3)') : null;
                    const titleCell = table ? table.querySelector('tbody tr td:nth-child(7)') : null;
                    const journalBox = journalCell ? journalCell.getBoundingClientRect() : { width: 0 };
                    const titleBoxCell = titleCell ? titleCell.getBoundingClientRect() : { width: 0 };
                    const tableWrap = table ? table.closest('.edge-evidence-table-wrap') : null;
                    const tableBox = table ? table.getBoundingClientRect() : { width: 0 };
                    const wrapBox = tableWrap ? tableWrap.getBoundingClientRect() : { width: 0 };
                    const pubmedHead = pubmedSection ? pubmedSection.querySelector('.inspect-card__section-head') : null;
                    const downloadBox = download ? download.getBoundingClientRect() : { left: 0, top: 0 };
                    const titleBox = pubmedHead ? pubmedHead.querySelector('.inspect-card__section-title')?.getBoundingClientRect() : { right: 0, top: 0 };
                    return {
                        text: el.textContent || '',
                        hasPubMedSection: !!pubmedSection,
                        hasTable: !!table,
                        headers,
                        rowCount: rows.length,
                        firstHref: firstLink ? firstLink.href : '',
                        hasDownload: !!download,
                        downloadText: download ? download.textContent.trim() : '',
                        journalTitle: journalCell ? journalCell.getAttribute('title') || '' : '',
                        titleTitle: titleCell ? titleCell.getAttribute('title') || '' : '',
                        journalWidth: journalBox.width,
                        titleWidth: titleBoxCell.width,
                        tableScrollWidth: tableWrap ? tableWrap.scrollWidth : 0,
                        tableClientWidth: tableWrap ? tableWrap.clientWidth : 0,
                        tableBoxWidth: tableBox.width,
                        wrapBoxWidth: wrapBox.width,
                        downloadRightOfTitle: !!download && !!pubmedHead && downloadBox.left >= titleBox.right,
                    };
                }"""
            )
            require(table_state["hasPubMedSection"], "Evidence table was not rendered inside the card PubMed section\n" + evidence({"edge": edge, "card": table_state}))
            require(table_state["hasTable"], "Evidence table was not rendered\n" + evidence({"edge": edge, "detail": table_state}))
            for header in ["PMID", "Year", "Journal", "IF", "JCR", "Match", "Title"]:
                require(header in table_state["headers"], f"Evidence table missing {header} column\n" + evidence(table_state))
            require(table_state["rowCount"] == len(edge.get("pmids") or []), "Evidence table row count does not match selected edge PMIDs\n" + evidence({"edge": edge, "detail": table_state}))
            require(table_state["firstHref"].startswith("https://pubmed.ncbi.nlm.nih.gov/"), "PMID link href is incorrect\n" + evidence(table_state))
            require(table_state["journalTitle"], "Journal cell must expose full value via title tooltip\n" + evidence(table_state))
            require(table_state["titleTitle"], "Title cell must expose full value via title tooltip\n" + evidence(table_state))
            require(table_state["titleWidth"] > table_state["journalWidth"], "Title column should be wider than Journal column\n" + evidence(table_state))
            require(table_state["tableScrollWidth"] <= table_state["tableClientWidth"] + 1, "Evidence table must not require horizontal scroll\n" + evidence(table_state))
            require(table_state["tableBoxWidth"] <= table_state["wrapBoxWidth"] + 1, "Evidence table must fit inside the card PubMed section\n" + evidence(table_state))
            require("confidence" not in table_state["text"].lower(), "Evidence UI must not use confidence wording\n" + evidence(table_state))

            if len(edge.get("pmids") or []) > 10:
                require(table_state["hasDownload"], "Download CSV button missing for >10 PMID edge\n" + evidence({"edge": edge, "detail": table_state}))
                require(table_state["downloadRightOfTitle"], "Download CSV button should sit to the right of the PubMed title\n" + evidence(table_state))
                csv_text = frame_locator.locator(".inspect-card.is-expanded .edge-evidence-download").evaluate(
                    """button => decodeURIComponent(button.getAttribute('data-evidence-csv') || '')"""
                )
                rows = parse_csv(csv_text)
                require(len(rows) == len(edge.get("pmids") or []), "Evidence CSV row count does not match table PMIDs\n" + evidence({"rows": len(rows), "edge": edge}))
                require(rows and rows[0].get("pmid"), "Evidence CSV is missing pmid data")
                require("pubmed_title" in rows[0], "Evidence CSV must include full pubmed_title")
                require(rows[0].get("pubmed_title"), "Evidence CSV must include complete title value")
                require(rows[0].get("pubmed_journal_title"), "Evidence CSV must include complete journal value")
            else:
                require(table_state["hasDownload"] is False, "Download CSV should only appear for >10 evidence rows\n" + evidence(table_state))

            edge_visuals = page.evaluate(
                """async (edgeId) => {
                    const iframe = document.querySelector('#g6-dynamic-surface iframe');
                    const embed = iframe && iframe.contentWindow ? iframe.contentWindow.__TEKG_G6_EMBED : null;
                    return embed.getEdgeVisuals(edgeId);
                }""",
                edge["id"],
            )
            require(edge_visuals.get("width", 0) > 1.5, "Evidence edge width was not computed from support_pmid_count\n" + evidence(edge_visuals))
            require(0.2 <= edge_visuals.get("opacity", 0) <= 0.9, "Evidence edge opacity outside visible bounded range\n" + evidence(edge_visuals))
            require("confidence" not in json.dumps(edge_visuals).lower(), "Visual diagnostics must not use confidence wording\n" + evidence(edge_visuals))
            require(edge_choice.get("zeroCoverage") is not None, "Coverage diagnostic missing")

        except PlaywrightTimeoutError as exc:
            fail(f"G6 evidence support UX smoke timed out at {url}: {exc}")
        except PlaywrightError as exc:
            fail(f"G6 evidence support UX smoke failed at {url}: {exc}")
        finally:
            browser.close()

    require(not page_errors, "Page errors detected:\n" + "\n".join(page_errors[:10]))
    reference_errors = [message for message in console_errors if "ReferenceError" in message]
    require(not reference_errors, "ReferenceError detected:\n" + "\n".join(reference_errors[:10]))
    require(not failed_requests, "Failed browser requests:\n" + "\n".join(failed_requests[:10]))
    ok(f"G6 evidence support UX smoke passed for {url}")


if __name__ == "__main__":
    run_check(main)
