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
        page = browser.new_page(viewport={'width': 1440, 'height': 900})
        errors: list[str] = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        try:
            page.goto(app_url('test/integrated_graph_renderer_harness.html'), wait_until='domcontentloaded', timeout=30_000)
            page.wait_for_function('() => window.__INTEGRATED_HARNESS_READY === true', timeout=45_000)
            elements = page.evaluate('() => window.__INTEGRATED_HARNESS_ELEMENTS')
            nodes = [item for item in elements if not item['data'].get('source')]
            edges = [item for item in elements if item['data'].get('source')]
            require(len(nodes) == 2 and len(edges) == 2, f'Unexpected merged graph size: {len(nodes)} nodes, {len(edges)} edges')
            require(all(edge['data']['parallelCount'] == 2 for edge in edges), 'Parallel-edge metadata is missing.')
            visible = page.eval_on_selector(
                '#harness-frame',
                '(frame) => frame.contentWindow.__TEKG_INTEGRATED_EMBED.getVisibleSubgraph()',
            )
            source_graphs = {edge.get('source_graph') for edge in visible.get('edges', [])}
            exported_ids = {edge.get('id') for edge in visible.get('edges', [])}
            require(source_graphs == {'knowledge', 'te_gene'}, f'Visible export lost source provenance: {source_graphs}')
            require(len(exported_ids) == 2, f'Visible export collapsed parallel edge IDs: {exported_ids}')
            frame = page.frame_locator('#harness-frame')
            shared_node = next(item for item in nodes if item['data']['type'] == 'TE')
            page.eval_on_selector('#harness-frame', '(frame, id) => frame.contentWindow.__TEKG_INTEGRATED_EMBED.inspectNode(id)', shared_node['data']['id'])
            frame.locator('.inspect-card__source-tab').first.wait_for(timeout=5_000)
            require(frame.locator('.inspect-card__source-tab').count() == 2, 'Shared node does not expose two source tabs.')
            page.eval_on_selector('#harness-frame', '(frame, id) => frame.contentWindow.__TEKG_INTEGRATED_EMBED.inspectEdge(id)', edges[0]['data']['id'])
            first_text = frame.locator('.inspect-card').inner_text()
            page.eval_on_selector('#harness-frame', '(frame, id) => frame.contentWindow.__TEKG_INTEGRATED_EMBED.inspectEdge(id)', edges[1]['data']['id'])
            second_text = frame.locator('.inspect-card').inner_text()
            require(first_text != second_text, 'The two source edges do not produce distinct evidence cards.')
            require(not errors, f'Browser page errors: {errors}')
            print('Integrated Graph browser harness: PASS')
        finally:
            browser.close()


if __name__ == '__main__':
    main()
