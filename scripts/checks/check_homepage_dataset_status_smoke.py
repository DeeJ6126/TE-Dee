from __future__ import annotations

from harness_lib import app_url, fail, ok, require, run_check


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail(
            "Playwright is not installed. Run:\n"
            "  pip install -r requirements-dev.txt\n"
            "  python -m playwright install chromium"
        )

    url = app_url("index.php")
    console_errors: list[str] = []
    page_errors: list[str] = []
    failed_requests: list[str] = []
    home_stats_requests: list[str] = []

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
        page.on(
            "console",
            lambda msg: console_errors.append(
                f"{msg.text} :: {msg.location.get('url', '')}"
            ) if msg.type == "error" else None,
        )
        page.on("pageerror", lambda exc: page_errors.append(str(exc)))
        page.on("requestfailed", lambda request: failed_requests.append(f"{request.url} :: {request.failure}"))
        page.on("request", lambda request: home_stats_requests.append(request.url) if "home_stats.php" in request.url else None)

        try:
            page.goto(url, wait_until="domcontentloaded", timeout=30000)
            page.wait_for_selector("[data-home-stats]", timeout=15000)
            page.wait_for_function(
                """() => {
                    const root = document.querySelector('[data-home-stats]');
                    return root
                        && root.classList.contains('is-loaded')
                        && root.querySelectorAll('[data-donut-chart="entity"] .status-donut-segment').length > 0
                        && root.querySelectorAll('[data-donut-chart="te"] .status-donut-segment').length > 0
                        && root.querySelectorAll('[data-donut-chart="relation"] .status-donut-segment').length > 0;
                }""",
                timeout=30000,
            )

            state = page.locator("[data-home-stats]").evaluate(
                """root => {
                    const text = selector => {
                        const el = root.querySelector(selector);
                        return el ? el.textContent.trim() : '';
                    };
                    const entityPaths = root.querySelectorAll('[data-donut-chart="entity"] .status-donut-segment');
                    const tePaths = root.querySelectorAll('[data-donut-chart="te"] .status-donut-segment');
                    const relationPaths = root.querySelectorAll('[data-donut-chart="relation"] .status-donut-segment');
                    const cards = [...root.querySelectorAll('.status-donut-card')].map(card => {
                        const title = card.querySelector('.status-donut-copy h4')?.getBoundingClientRect();
                        const shell = card.querySelector('.status-donut-shell')?.getBoundingClientRect();
                        const legend = card.querySelector('.status-legend');
                        const rect = card.getBoundingClientRect();
                        const last = card.lastElementChild?.getBoundingClientRect();
                        return {
                            titleTop: title ? Math.round(title.top) : 0,
                            shellTop: shell ? Math.round(shell.top) : 0,
                            top: Math.round(rect.top),
                            bottom: Math.round(rect.bottom),
                            bottomGap: last ? Math.round(rect.bottom - last.bottom) : 0,
                            legendAlignSelf: legend ? getComputedStyle(legend).alignSelf : '',
                            width: Math.round(rect.width),
                        };
                    });
                    const legendColumns = selector => {
                        const legend = root.querySelector(selector);
                        const columns = legend ? getComputedStyle(legend).gridTemplateColumns : '';
                        return columns ? columns.split(' ').filter(Boolean).length : 0;
                    };
                    return {
                        classes: root.className,
                        summaryBoxes: root.querySelectorAll('.status-summary-item').length,
                        generatedText: text('[data-home-generated]'),
                        cardCount: cards.length,
                        cards,
                        entitySegments: entityPaths.length,
                        teSegments: tePaths.length,
                        relationSegments: relationPaths.length,
                        entityLegendRows: root.querySelectorAll('[data-donut-legend="entity"] .status-legend-item').length,
                        teLegendRows: root.querySelectorAll('[data-donut-legend="te"] .status-legend-item').length,
                        relationLegendRows: root.querySelectorAll('[data-donut-legend="relation"] .status-legend-item').length,
                        entityLegendColumns: legendColumns('[data-donut-legend="entity"]'),
                        teLegendColumns: legendColumns('[data-donut-legend="te"]'),
                        relationLegendColumns: legendColumns('[data-donut-legend="relation"]'),
                        errorHidden: root.querySelector('[data-home-stats-error]')?.hidden ?? null,
                    };
                }"""
            )
            page.wait_for_timeout(1100)
            page.evaluate(
                """() => {
                    window.__HOME_ENTITY_SENTINEL = document.querySelector('[data-donut-chart="entity"] .status-donut-segment');
                    window.__HOME_RELATION_SENTINEL = document.querySelector('[data-donut-chart="relation"] .status-donut-segment');
                }"""
            )
            first_segment = page.locator('[data-donut-chart="entity"] .status-donut-segment').nth(0)
            before_box = first_segment.bounding_box()
            require(before_box is not None, "entity donut segment should have a bounding box")
            first_segment.dispatch_event(
                "mouseenter",
                {"clientX": before_box["x"] + 10, "clientY": before_box["y"] + 10},
            )
            page.wait_for_selector('.status-donut-tooltip.is-visible', timeout=5000)
            tooltip_text = page.locator('.status-donut-tooltip').inner_text(timeout=5000)
            segment_label = first_segment.get_attribute("aria-label") or ""
            after_transform = first_segment.evaluate("el => getComputedStyle(el).transform")
            page.locator('[data-te-level="superfamily"]').click()
            page.wait_for_function(
                """() => {
                    const root = document.querySelector('[data-home-stats]');
                    return root
                        && root.querySelector('[data-te-level="superfamily"]')?.classList.contains('is-active')
                        && root.querySelectorAll('[data-donut-chart="te"] .status-donut-segment').length > 0;
                }""",
                timeout=30000,
            )
            page.wait_for_timeout(1100)
            te_level_state = page.locator("[data-home-stats]").evaluate(
                """root => ({
                    activeLevel: root.querySelector('[data-te-level].is-active')?.dataset.teLevel || '',
                    activePressed: root.querySelector('[data-te-level="superfamily"]')?.getAttribute('aria-pressed') || '',
                    teSegments: root.querySelectorAll('[data-donut-chart="te"] .status-donut-segment').length,
                    teLegendRows: root.querySelectorAll('[data-donut-legend="te"] .status-legend-item').length,
                    entityUntouched: window.__HOME_ENTITY_SENTINEL?.isConnected === true
                        && root.querySelector('[data-donut-chart="entity"] .status-donut-segment') === window.__HOME_ENTITY_SENTINEL,
                    relationUntouched: window.__HOME_RELATION_SENTINEL?.isConnected === true
                        && root.querySelector('[data-donut-chart="relation"] .status-donut-segment') === window.__HOME_RELATION_SENTINEL,
                    cardBottomGaps: [...root.querySelectorAll('.status-donut-card')].map(card => {
                        const rect = card.getBoundingClientRect();
                        const last = card.lastElementChild?.getBoundingClientRect();
                        return last ? Math.round(rect.bottom - last.bottom) : 0;
                    }),
                })"""
            )
        finally:
            browser.close()

    require(not page_errors, "homepage page errors: " + " | ".join(page_errors[:5]))
    relevant_console_errors = [
        item for item in console_errors
        if "home_stats.php" in item or "assets/js/pages/index.js" in item
    ]
    require(not relevant_console_errors, "homepage console errors: " + " | ".join(relevant_console_errors[:5]))
    relevant_failed = [item for item in failed_requests if "home_stats.php" in item or "index.js" in item]
    require(not relevant_failed, "homepage failed requests: " + " | ".join(relevant_failed[:5]))
    require(state["summaryBoxes"] == 0, f"obsolete summary boxes should be removed: {state}")
    require(state["generatedText"] == "", f"generated timestamp should be removed: {state}")
    require(state["cardCount"] == 3, f"expected three donut cards: {state}")
    card_widths = {card["width"] for card in state["cards"]}
    require(len(card_widths) == 1, f"donut card widths should match: {state}")
    require(len({card["top"] for card in state["cards"]}) == 1, f"donut card top borders should align: {state}")
    require(len({card["bottom"] for card in state["cards"]}) == 1, f"donut card bottom borders should align: {state}")
    require(all(card["legendAlignSelf"] == "center" for card in state["cards"]), f"donut legends should be vertically centered: {state}")
    require(len({card["titleTop"] for card in state["cards"]}) == 1, f"donut card titles should align: {state}")
    require(len({card["shellTop"] for card in state["cards"]}) == 1, f"donut charts should align: {state}")
    require(state["entitySegments"] >= 1, f"entity donut segments missing: {state}")
    require(state["teSegments"] >= 1, f"TE classification donut segments missing: {state}")
    require(state["relationSegments"] >= 1, f"relation donut segments missing: {state}")
    require(state["entityLegendRows"] >= 1, f"entity legend rows missing: {state}")
    require(state["teLegendRows"] >= 1, f"TE classification legend rows missing: {state}")
    require(state["relationLegendRows"] >= 1, f"relation legend rows missing: {state}")
    require(state["entityLegendColumns"] == 2, f"entity legend should render two columns: {state}")
    require(state["teLegendColumns"] == 2, f"TE classification legend should render two columns: {state}")
    require(state["relationLegendColumns"] == 2, f"relation legend should render two columns: {state}")
    require(tooltip_text.strip(), "hover tooltip should show segment details")
    require("·" in tooltip_text and "%" in tooltip_text, f"hover tooltip should include count and percent: {tooltip_text}")
    require(segment_label.split(":", 1)[0] in tooltip_text, f"hover tooltip should include segment label: {tooltip_text}")
    require(after_transform != "none", "hovered donut segment should have a transform")
    require(any("te_level=class" in item for item in home_stats_requests), "initial home_stats request should include te_level=class")
    require(any("te_level=superfamily" in item for item in home_stats_requests), "TE level switch should request superfamily stats")
    require(te_level_state["activeLevel"] == "superfamily", f"superfamily level button should be active: {te_level_state}")
    require(te_level_state["activePressed"] == "true", f"superfamily level button should be aria-pressed: {te_level_state}")
    require(te_level_state["teSegments"] >= 1, f"superfamily TE donut segments missing: {te_level_state}")
    require(te_level_state["teLegendRows"] >= 1, f"superfamily TE legend rows missing: {te_level_state}")
    require(te_level_state["entityUntouched"], "entity chart was redrawn during a TE-only level change")
    require(te_level_state["relationUntouched"], "relation chart was redrawn during a TE-only level change")
    require(state["errorHidden"] is True, f"unexpected homepage stats fallback visible: {state}")
    ok(
        "homepage Dataset Status smoke passed: "
        f"entity_segments={state['entitySegments']}, te_segments={state['teSegments']}, "
        f"relation_segments={state['relationSegments']}"
    )


if __name__ == "__main__":
    run_check(main)
