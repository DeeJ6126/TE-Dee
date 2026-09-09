from __future__ import annotations

from harness_lib import app_url, fail, require


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail('Playwright is not installed.')
    with sync_playwright() as playwright:
        try:
            browser = playwright.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f'Unable to launch Chromium: {exc}')
        page = browser.new_page(viewport={'width': 1280, 'height': 900})
        requests: list[str] = []
        errors: list[str] = []
        page.on('request', lambda request: requests.append(request.url) if 'api/te_gene.php' in request.url else None)
        page.on('pageerror', lambda error: errors.append(str(error)))
        try:
            page.goto(app_url('preview.php?mode=te_gene&te=L1HS&scope=all'), wait_until='domcontentloaded', timeout=30_000)
            page.wait_for_function("() => window.__TEKG_COEXPRESSION_MODE && window.__TEKG_PREVIEW_WORKSPACE_MODE", timeout=10_000)
            page.wait_for_function("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'", timeout=45_000)
            state = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
            require(state['selection'].get('te') == 'L1HS', f'Unexpected initial selection: {state}')
            require(state['selection'].get('scope') == 'all', f'Initial scope is not all: {state}')
            require(any('action=catalog' in url for url in requests), f'TE-Gene catalog request missing: {requests}')
            require(any('action=network' in url and 'scope=all' in url for url in requests), f'All-tissue request missing: {requests}')
            page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.activate({te:'L1HS', featureType:'TE', scope:'Liver', context:'normal_tissue'})")
            page.wait_for_function("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection?.scope === 'Liver' && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'", timeout=45_000)
            liver = page.evaluate("() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics()")
            require(any('action=network' in url and 'scope=tissue' in url and 'tissue=Liver' in url for url in requests), f'Liver request missing: {requests}')
            require(liver['selection'].get('scope') == 'Liver', f'Liver scope was not retained: {liver}')
            require(not errors, f'Browser page errors: {errors}')
            print(f"TE-Gene Graph browser check: PASS (all nodes={state['nodeCount']}, liver nodes={liver['nodeCount']})")
        finally:
            browser.close()


if __name__ == '__main__':
    main()
