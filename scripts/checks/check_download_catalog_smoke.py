from __future__ import annotations

from harness_lib import app_url, ok, require, run_check


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        raise AssertionError("Playwright is not installed")

    with sync_playwright() as p:
        try:
            browser = p.chromium.launch(headless=True)
        except PlaywrightError as exc:
            raise AssertionError(f"Unable to launch Chromium: {exc}") from exc

        page = browser.new_page(viewport={"width": 1440, "height": 980})
        try:
            page.goto(app_url("download.php"), wait_until="networkidle", timeout=30000)
            state = page.evaluate(
                """
                () => {
                    const shell = document.querySelector('.download-shell');
                    return {
                        title: document.querySelector('.download-page-title')?.textContent?.trim() || '',
                        categories: Array.from(document.querySelectorAll('[data-download-category]')).map((button) => button.dataset.downloadCategory || ''),
                        headers: Array.from(document.querySelectorAll('.download-table thead th')).map((th) => th.textContent.trim()),
                        rowCount: document.querySelectorAll('#download-table-body .dataset-row').length,
                        detailCount: document.querySelectorAll('.dataset-detail-row').length,
                        linkCount: document.querySelectorAll('.file-link[href]').length,
                        sizeCount: document.querySelectorAll('.download-file-size').length,
                        hasSearch: Boolean(document.querySelector('#download-search')),
                        hasPageSize: Boolean(document.querySelector('#download-page-size')),
                        catalogText: shell?.textContent || '',
                    };
                }
                """
            )

            require(state["title"] == "Download", f"unexpected title: {state}")
            require(
                state["categories"]
                == ["All", "Literature", "TE Reference", "Expression", "Co-expression", "eQTL"],
                f"category filters missing or out of order: {state}",
            )
            require(
                state["headers"] == ["Dataset", "Contents", "Records", "Format", "Version"],
                f"data-record headers mismatch: {state}",
            )
            require(state["rowCount"] >= 20, f"download datasets missing: {state}")
            require(state["detailCount"] == state["rowCount"], f"detail rows missing: {state}")
            require(state["linkCount"] >= 30, f"download links missing: {state}")
            require(state["sizeCount"] >= 20, f"file sizes missing: {state}")
            require(state["hasSearch"] is False, f"search control should be removed: {state}")
            require(state["hasPageSize"] is False, f"page-size control should be removed: {state}")
            require("TE-Dee" not in state["catalogText"], f"brand name remains in catalog: {state}")
            require("TE-KG" not in state["catalogText"], f"brand name remains in catalog: {state}")

            page.click('[data-download-category="eQTL"]')
            eqtl = page.evaluate(
                """
                () => ({
                    rowCount: document.querySelectorAll('#download-table-body .dataset-row').length,
                    bodyText: document.querySelector('#download-table-body')?.textContent || '',
                })
                """
            )
            require(eqtl["rowCount"] == 9, f"eQTL filter should show nine datasets: {eqtl}")
            require("Variant-gene-tissue associations" in eqtl["bodyText"], f"association export missing: {eqtl}")

            page.click(".dataset-toggle")
            require(page.locator(".dataset-row.is-open").count() == 1, "dataset row should open")
            require(page.locator(".dataset-detail-row:not([hidden])").count() == 1, "one detail row should be visible")
        finally:
            browser.close()

    ok("Download catalog smoke passed")


if __name__ == "__main__":
    run_check(main)
