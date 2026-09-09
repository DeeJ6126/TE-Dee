from __future__ import annotations

from harness_lib import app_url, fail, ok, require, run_check


EXPECTED_MEDIA = [
    "about-resource-overview.png",
    "about-resource-data-routes.png",
    "about-resource-evidence-table.png",
    "about-home-overview.png",
    "about-home-dataset-status.png",
    "about-browse-main.png",
    "about-browse-selector.gif",
    "about-pathfinder-search.gif",
    "about-pathfinder-results.png",
    "about-pathfinder-evidence.png",
    "about-tekg-graph.gif",
    "about-tekg-legend.png",
    "about-agent-main.png",
    "about-expression-main.png",
    "about-download-main.png",
    "about-download-filter.gif",
]


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

        page = browser.new_page(viewport={"width": 1440, "height": 960})
        try:
            page.goto(app_url("about.php"), wait_until="networkidle", timeout=30000)
            state = page.evaluate(
                """
                () => ({
                    title: document.querySelector('.page-title-hero')?.textContent?.trim() || '',
                    nav: Array.from(document.querySelectorAll('.about-nav-parent')).map((link) => link.textContent.trim()),
                    activeNav: document.querySelector('.about-nav a.is-active')?.dataset.pane || '',
                    searchPlaceholder: document.querySelector('#about-search-input')?.getAttribute('placeholder') || '',
                    parentNav: document.querySelectorAll('.about-nav-parent').length,
                    childNav: document.querySelectorAll('.about-nav-child').length,
                    docSections: document.querySelectorAll('.about-doc-section').length,
                    docSubsections: document.querySelectorAll('.about-doc-subsection').length,
                    openLinks: document.querySelectorAll('.about-open-link[href]').length,
                    detailCards: document.querySelectorAll('.about-detail-card').length,
                    mediaFigures: document.querySelectorAll('.about-placeholder-media').length,
                    mediaImages: document.querySelectorAll('.about-media-image').length,
                    mediaFilenames: Array.from(document.querySelectorAll('.about-placeholder-media')).map((node) => node.dataset.mediaFilename || ''),
                    gifMedia: Array.from(document.querySelectorAll('.about-placeholder-media')).filter((node) => (node.dataset.mediaFilename || '').endsWith('.gif')).length,
                    imageSources: Array.from(document.querySelectorAll('.about-media-image')).map((node) => node.getAttribute('src') || ''),
                    lazyImages: Array.from(document.querySelectorAll('.about-media-image')).filter((node) => node.getAttribute('loading') === 'lazy').length,
                    asyncImages: Array.from(document.querySelectorAll('.about-media-image')).filter((node) => node.getAttribute('decoding') === 'async').length,
                    pageText: document.body.textContent,
                })
                """
            )
            expected_nav = ["Resource", "Home", "Browse", "Path Finder", "TE-KG", "Agent", "Expression", "Download", "About"]
            require(state["title"] == "About", f"unexpected About title: {state}")
            require(state["nav"][: len(expected_nav)] == expected_nav, f"About navigation mismatch: {state}")
            require(state["activeNav"] == "resource", f"Resource nav should be active by default: {state}")
            require(state["searchPlaceholder"] == "Search this guide", f"About search input should be visible: {state}")
            require(state["parentNav"] == len(expected_nav), f"About should expose top-level TOC links: {state}")
            require(state["childNav"] >= 28, f"About should expose subsection TOC links: {state}")
            require(state["docSections"] == len(expected_nav), f"About should render all sections as a long help page: {state}")
            require(state["docSubsections"] >= 28, f"About should render detailed subsections: {state}")
            require(state["openLinks"] == 0, f"Open page links should be removed: {state}")
            require(state["detailCards"] >= 28, f"About should provide detailed guide cards for every page: {state}")
            require(state["mediaFigures"] == 16, f"About should render exactly 16 core media figures: {state}")
            require(state["mediaImages"] == 16, f"About should render real image files for all core media: {state}")
            require(state["lazyImages"] == 16, f"About images should use lazy loading: {state}")
            require(state["asyncImages"] == 16, f"About images should use async decoding: {state}")
            require(state["mediaFilenames"] == EXPECTED_MEDIA, f"About core media filenames mismatch: {state}")
            require(state["gifMedia"] == 4, f"About should include the expected GIF workflow media: {state}")
            for filename in EXPECTED_MEDIA:
                require(
                    any(f"assets/img/about/{filename}" in source for source in state["imageSources"]),
                    f"About image src missing core asset {filename}: {state}",
                )
            require("about-browse-selector.gif" in state["mediaFilenames"], f"Browse GIF placeholder filename missing: {state}")
            require("about-download-filter.gif" in state["mediaFilenames"], f"Download GIF placeholder filename missing: {state}")
            require("Best for" not in state["pageText"], f"Best for text should be removed: {state}")
            require("Open page" not in state["pageText"], f"Open page text should be removed: {state}")
            for phrase in (
                "What the page contains",
                "What TE-KG is",
                "Evidence principles",
                "Recommended workflow",
                "Dataset Status",
                "category selector",
                "evidence tables",
                "interactive G6 graph",
                "natural-language assistant",
                "category filter",
                "Do not interpret journal IF as confidence",
                "data/bulk_expression_web",
            ):
                require(phrase in state["pageText"], f"About guide text missing phrase {phrase!r}: {state}")

            page.fill("#about-search-input", "journal IF")
            page.wait_for_timeout(250)
            search_state = page.evaluate(
                """
                () => ({
                    query: document.querySelector('#about-search-input')?.value || '',
                    visibleSections: Array.from(document.querySelectorAll('.about-doc-section:not([hidden])')).map((node) => node.dataset.section),
                    visibleChildren: Array.from(document.querySelectorAll('.about-nav-child:not([hidden])')).map((node) => node.textContent.trim()),
                    noResultsHidden: document.querySelector('.about-no-results')?.hidden ?? true,
                })
                """
            )
            require(search_state["query"] == "journal IF", f"Search query should stay in the input: {search_state}")
            require("resource" in search_state["visibleSections"], f"Evidence section should remain visible for journal IF search: {search_state}")
            require(search_state["visibleChildren"], f"Search should leave matching child links visible: {search_state}")
            require(search_state["noResultsHidden"], f"Search should not show no-results state for journal IF: {search_state}")
            page.fill("#about-search-input", "")

            page.click('[data-pane="pathfinder"]')
            page.wait_for_timeout(700)
            path_state = page.evaluate(
                """
                () => ({
                    hash: window.location.hash,
                    activeNav: document.querySelector('.about-nav a.is-active')?.dataset.pane || '',
                    sectionTop: Math.round(document.querySelector('#section-pathfinder')?.getBoundingClientRect().top || 0),
                })
                """
            )
            require(path_state["hash"] == "#section-pathfinder", f"Path Finder hash should update: {path_state}")
            require(path_state["activeNav"] == "pathfinder", f"Path Finder nav should activate: {path_state}")
            require(abs(path_state["sectionTop"]) < 220, f"Path Finder section should scroll near the viewport top: {path_state}")
        finally:
            browser.close()

    ok("About page smoke passed")


if __name__ == "__main__":
    run_check(main)
