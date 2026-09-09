from __future__ import annotations

import json

from harness_lib import app_url, fail, ok, require, run_check


DIRECT_PAYLOAD = {
    "ok": True,
    "source": {"element_id": "n1", "name": "L1HS", "type": "TE", "labels": ["TE"]},
    "target": {"element_id": "n2", "name": "Cancer", "type": "Disease", "labels": ["Disease"]},
    "max_depth": 3,
    "search_truncated": True,
    "searched_through_hop": 1,
    "path_count": 1,
    "paths": [
        {
            "id": "path_1",
            "hop_count": 1,
            "pmid_count": 1,
            "pmids": ["25352549"],
            "nodes": [
                {"element_id": "n1", "name": "L1HS", "type": "TE", "labels": ["TE"]},
                {"element_id": "n2", "name": "Cancer", "type": "Disease", "labels": ["Disease"]},
            ],
            "edges": [
                {
                    "source": "n1",
                    "target": "n2",
                    "relation_type": "BIO_RELATION",
                    "relation_label": "associate with",
                    "evidence": "Curated literature evidence.",
                    "pmid_count": 1,
                    "pmids": ["25352549"],
                    "evidence_records": [
                        {
                            "pmid": "25352549",
                            "pubmed_url": "https://pubmed.ncbi.nlm.nih.gov/25352549/",
                            "pubmed_title": "A curated direct relationship",
                            "pubmed_journal_title": "Nucleic Acids Research",
                            "pubmed_publication_year": 2015,
                            "journal_metric_value": 13.1,
                            "journal_jcr_quartile": "Q1",
                            "journal_metric_match_method": "eissn",
                        }
                    ],
                }
            ],
        }
    ],
}


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
        failed_requests: list[str] = []
        page.on(
            "console",
            lambda msg: console_errors.append(msg.text)
            if msg.type == "error" and "ERR_NETWORK_ACCESS_DENIED" not in msg.text
            else None,
        )
        page.on("requestfailed", lambda request: failed_requests.append(f"{request.url}: {request.failure}"))

        def fulfill_path(route) -> None:
            route.fulfill(
                status=200,
                content_type="application/json",
                body=json.dumps(DIRECT_PAYLOAD),
            )

        page.route("**/api/path_finder.php?source=DirectSource*", fulfill_path)
        try:
            page.goto(app_url("path_finder.php"), wait_until="domcontentloaded", timeout=30000)
            page.evaluate(
                """
                () => {
                  document.querySelector('#pathSource').value = 'DirectSource';
                  document.querySelector('#pathTarget').value = 'DirectTarget';
                }
                """
            )
            page.locator("#pathSubmit").click()
            page.locator("#pathResults .path-card").wait_for(timeout=10000)

            initial = page.locator("#pathResults .path-card").evaluate(
                """
                card => {
                  const edge = card.querySelector('.compact-path-edge');
                  const cardStyle = getComputedStyle(card);
                  return {
                    stripNodes: card.querySelectorAll('.compact-path-strip .compact-path-node').length,
                    stripEdges: card.querySelectorAll('.compact-path-strip .compact-path-edge').length,
                    arrows: card.querySelectorAll('.compact-path-edge-arrow').length,
                    expanded: edge?.getAttribute('aria-expanded') || '',
                    visibleEvidence: card.querySelectorAll('.path-evidence-detail:not([hidden])').length,
                    borderRadius: cardStyle.borderRadius,
                    borderWidth: cardStyle.borderWidth,
                  };
                }
                """
            )
            status_text = page.locator("#pathStatus").text_content() or ""

            edge = page.locator("#pathResults .compact-path-edge")
            edge.click()
            page.locator("#pathResults .path-evidence-detail:not([hidden]) .edge-evidence-table").wait_for(timeout=10000)
            opened = page.locator("#pathResults .path-card").evaluate(
                """
                card => ({
                  expanded: card.querySelector('.compact-path-edge')?.getAttribute('aria-expanded') || '',
                  active: card.querySelector('.compact-path-edge')?.classList.contains('is-active') === true,
                  visibleEvidence: card.querySelectorAll('.path-evidence-detail:not([hidden])').length,
                  pubmedLinks: card.querySelectorAll('.path-evidence-detail:not([hidden]) a[href^="https://pubmed.ncbi.nlm.nih.gov/"]').length,
                })
                """
            )

            edge.click()
            closed = page.locator("#pathResults .path-card").evaluate(
                """
                card => ({
                  expanded: card.querySelector('.compact-path-edge')?.getAttribute('aria-expanded') || '',
                  active: card.querySelector('.compact-path-edge')?.classList.contains('is-active') === true,
                  visibleEvidence: card.querySelectorAll('.path-evidence-detail:not([hidden])').length,
                })
                """
            )
        finally:
            browser.close()

    require(
        not console_errors,
        "Path table console errors: " + " | ".join(console_errors[:5]) + " | failed: " + " | ".join(failed_requests[:5]),
    )
    require("Showing 1 path" in status_text and "hop 1" in status_text and "safety limit" in status_text, f"Truncated search should disclose its boundary: {status_text!r}")
    require(initial["stripNodes"] == 2 and initial["stripEdges"] == 1, f"Direct path strip is incomplete: {initial}")
    require(initial["arrows"] == 1 and initial["expanded"] == "false", f"Relationship arrow should start collapsed: {initial}")
    require(initial["visibleEvidence"] == 0, f"Literature should not occupy space before a relationship is selected: {initial}")
    require(initial["borderRadius"] == "0px" and initial["borderWidth"] == "0px", f"Path result should not render as a card: {initial}")
    require(opened["expanded"] == "true" and opened["active"] is True, f"Selected relationship should expose active state: {opened}")
    require(opened["visibleEvidence"] == 1 and opened["pubmedLinks"] == 1, f"Selected relationship should show its literature: {opened}")
    require(closed["expanded"] == "false" and closed["active"] is False, f"Relationship should return to collapsed state: {closed}")
    require(closed["visibleEvidence"] == 0, f"Collapsed relationship should hide its literature: {closed}")
    ok("Path Finder relationship-arrow literature disclosure passed")


if __name__ == "__main__":
    run_check(main)
