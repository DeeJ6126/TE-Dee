from __future__ import annotations

from harness_lib import app_url, fail, ok, require, run_check


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed")

    with sync_playwright() as playwright:
        try:
            browser = playwright.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")

        page = browser.new_page(viewport={"width": 1440, "height": 900})
        catalog_requests: list[str] = []
        page.on(
            "request",
            lambda request: catalog_requests.append(request.url)
            if "api/expression_catalog.php" in request.url
            else None,
        )
        try:
            page.goto(app_url("expression.php"), wait_until="domcontentloaded", timeout=30000)
            keyword = page.locator('input[name="keyword"][type="text"]')
            keyword.fill("AluYb1")
            option = page.locator('[data-te-name="AluYb10"]')
            option.wait_for(state="visible", timeout=30000)
            option.click()
            page.wait_for_url("**keyword=AluYb10**", timeout=30000)
            page.locator(".expression-table tbody tr").first.wait_for(timeout=30000)
            first_name = page.locator(".expression-table tbody tr").first.locator("td").first.text_content() or ""
        finally:
            browser.close()

    require(any("q=AluYb1" in url for url in catalog_requests), f"Query-aware catalog request missing: {catalog_requests}")
    require(first_name.strip() == "AluYb10", f"Expression table did not use selected catalog name: {first_name!r}")
    ok("Expression MySQL catalog autocomplete browser smoke passed")


if __name__ == "__main__":
    run_check(main)
