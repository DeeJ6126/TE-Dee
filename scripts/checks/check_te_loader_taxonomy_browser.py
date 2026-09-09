from __future__ import annotations

from harness_lib import app_url, fail, ok, require, run_check


def wait_loader(page, te: str, kind: str) -> None:
    page.wait_for_function(
        """([te, kind]) => {
          const overlay = document.querySelector('#coexpression-preloader');
          const slot = document.querySelector('#coexpression-mechanism-loader-slot');
          return overlay?.classList.contains('is-visible')
            && overlay.classList.contains(`te-loader-${kind}`)
            && slot?.textContent.includes(te);
        }""",
        arg=[te, kind],
        timeout=15_000,
    )
    require(
        page.locator("#coexpression-preloader-label").inner_text()
        == f"Loading {te} co-expression network...",
        f"Public Loader copy is unstable for {te}.",
    )


def select_te(page, te: str) -> None:
    page.fill("#coexpression-te-search", te)
    page.click("#coexpression-load")


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed.")

    with sync_playwright() as playwright:
        try:
            browser = playwright.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")

        page = browser.new_page(viewport={"width": 1280, "height": 900})
        errors: list[str] = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.on(
            "console",
            lambda message: errors.append(message.text)
            if message.type == "error"
            else None,
        )
        try:
            page.goto(
                app_url(
                    "preview.php?mode=coexpression&te=AluJb"
                    "&context=normal_tissue"
                ),
                wait_until="domcontentloaded",
                timeout=30_000,
            )
            wait_loader(page, "AluJb", "retro")
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'",
                timeout=30_000,
            )

            select_te(page, "HSMAR1")
            wait_loader(page, "HSMAR1", "dna")
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'"
                " && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection?.te === 'HSMAR1'",
                timeout=30_000,
            )

            select_te(page, "SART1")
            wait_loader(page, "SART1", "retro")
            page.wait_for_function(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'"
                " && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection?.te === 'SART1'",
                timeout=30_000,
            )

            page.goto(
                app_url("preview.php?q=LINE1&type=TE"),
                wait_until="domcontentloaded",
                timeout=30_000,
            )
            page.wait_for_function(
                "() => window.__TEKG_G6_BRIDGE && typeof window.__TEKG_G6_BRIDGE.loadGraph === 'function'",
                timeout=30_000,
            )
            page.wait_for_function(
                "() => window.__TEKG_G6_BRIDGE.getState().currentElements.length > 0"
                " && document.querySelector('#graph-preloader')?.getAttribute('aria-hidden') === 'true'",
                timeout=30_000,
            )
            page.evaluate(
                """() => {
                  window.__TEKG_ALUJB_LOADER_SNAPSHOTS = [];
                  const overlay = document.querySelector('#graph-preloader');
                  const capture = () => window.__TEKG_ALUJB_LOADER_SNAPSHOTS.push({
                    className: overlay?.className || '',
                    label: document.querySelector('#graph-preloader-label')?.textContent || '',
                    mechanism: document.querySelector('#te-mechanism-loader-slot')?.textContent || '',
                  });
                  const observer = new MutationObserver(capture);
                  observer.observe(overlay, { attributes: true, childList: true, subtree: true });
                  capture();
                  window.__TEKG_ALUJB_LOAD_PROMISE = window.__TEKG_G6_BRIDGE.loadGraph({
                    query: 'AluJb',
                    queryType: 'TE',
                  }).finally(() => {
                    capture();
                    observer.disconnect();
                  });
                }"""
            )
            page.evaluate("() => window.__TEKG_ALUJB_LOAD_PROMISE")
            snapshots = page.evaluate("() => window.__TEKG_ALUJB_LOADER_SNAPSHOTS")
            require(
                any(
                    "te-loader-retro" in item.get("className", "")
                    and "AluJb" in item.get("mechanism", "")
                    for item in snapshots
                ),
                f"Knowledge Graph did not use the taxonomy-backed AluJb retro Loader: {snapshots}",
            )
            require(not errors, f"Browser errors: {errors[:5]}")
        finally:
            page.close()
            browser.close()

    ok("Taxonomy-backed retro, DNA, and missing-taxonomy Loader behavior passed")


if __name__ == "__main__":
    run_check(main)
