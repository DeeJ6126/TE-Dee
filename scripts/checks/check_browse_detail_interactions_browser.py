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

        page = browser.new_page(viewport={"width": 1600, "height": 1000})
        page.add_init_script(
            """
            Object.defineProperty(navigator, 'clipboard', {
              configurable: true,
              value: {
                writeText(text) {
                  window.__tekgCopiedSequence = String(text || '');
                  return Promise.resolve();
                }
              }
            });
            """
        )
        console_errors: list[str] = []
        page.on(
            "console",
            lambda message: console_errors.append(message.text)
            if message.type == "error"
            and "ERR_NETWORK_ACCESS_DENIED" not in message.text
            and "Failed to load resource" not in message.text
            else None,
        )

        try:
            page.goto(app_url("search.php?q=L1HS&type=TE"), wait_until="domcontentloaded", timeout=30000)
            page.locator("#search-sequence-copy").wait_for(timeout=15000)
            page.locator("#search_jbrowse_linear_genome_view").wait_for(timeout=15000)

            sequence_layout = page.locator("#search-sequence-panel").evaluate(
                """
                panel => {
                  const box = panel.querySelector('.sequence-code-wrap');
                  const panelRect = panel.getBoundingClientRect();
                  const boxRect = box.getBoundingClientRect();
                  const panelStyle = getComputedStyle(panel);
                  const contentWidth = panelRect.width - parseFloat(panelStyle.paddingLeft) - parseFloat(panelStyle.paddingRight);
                  const contentRight = panelRect.right - parseFloat(panelStyle.paddingRight);
                  return {
                    panelWidth: panelRect.width,
                    contentWidth,
                    boxWidth: boxRect.width,
                    rightGap: Math.abs(contentRight - boxRect.right),
                  };
                }
                """
            )

            page.locator("#search-sequence-copy").click()
            page.wait_for_function("() => Boolean(window.__tekgCopiedSequence)", timeout=5000)
            copied_sequence = page.evaluate("() => window.__tekgCopiedSequence || ''")

            updated = page.locator("#search-karyotype-view").evaluate(
                """
                view => {
                  const config = JSON.parse(document.querySelector('#search-page-config').textContent || '{}');
                  const bins = config?.karyotypeHitMap?.bins || {};
                  const key = Object.keys(bins).find(item => Array.isArray(bins[item]?.hits) && bins[item].hits.length > 0);
                  if (!key) return { dispatched: false, key: '' };
                  const match = key.match(/^([^:]+):(\\d+)-(\\d+)$/);
                  if (!match) return { dispatched: false, key };
                  view.dispatchEvent(new CustomEvent('karyotypeclicked', {
                    detail: { contig: match[1], start: Number(match[2]), end: Number(match[3]) }
                  }));
                  return { dispatched: true, key };
                }
                """
            )
            require(updated["dispatched"], f"No mapped karyotype bin was available: {updated}")
            page.locator("#search-karyotype-feedback.is-visible").wait_for(timeout=5000)
            feedback = page.locator("#search-karyotype-feedback").text_content() or ""
            picker_highlighted = page.locator(".jbrowse-hit-picker").evaluate(
                "picker => picker.classList.contains('is-hit-updated')"
            )

            page.wait_for_timeout(500)
            all_tracks_height = page.locator("#search_jbrowse_linear_genome_view").evaluate(
                "mount => mount.getBoundingClientRect().height"
            )
            page.locator("#searchJBrowseTrackControls input[data-track-id]").evaluate_all(
                """
                inputs => inputs.forEach((input, index) => {
                  input.checked = index === 0;
                  input.dispatchEvent(new Event('change', { bubbles: true }));
                })
                """
            )
            page.wait_for_timeout(650)
            one_track = page.locator("#search_jbrowse_linear_genome_view").evaluate(
                """
                mount => ({
                  height: mount.getBoundingClientRect().height,
                  configuredHeight: Number(mount.dataset.browserHeight || 0),
                  checked: document.querySelectorAll('#searchJBrowseTrackControls input[data-track-id]:checked').length,
                })
                """
            )

            page.set_viewport_size({"width": 430, "height": 900})
            page.wait_for_timeout(350)
            narrow = page.locator("#search-sequence-panel").evaluate(
                """
                panel => ({
                  pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                  panelWidth: panel.getBoundingClientRect().width,
                  sequenceWidth: panel.querySelector('.sequence-code-wrap').getBoundingClientRect().width,
                  copyVisible: panel.querySelector('#search-sequence-copy').getBoundingClientRect().width > 0,
                })
                """
            )
        finally:
            browser.close()

    require(not console_errors, "Browse detail console errors: " + " | ".join(console_errors[:5]))
    require(sequence_layout["boxWidth"] >= sequence_layout["contentWidth"] * 0.98, f"Sequence box is not full width: {sequence_layout}")
    require(sequence_layout["rightGap"] <= 2, f"Sequence box does not align to the panel edge: {sequence_layout}")
    require(copied_sequence != "", "Copy button produced an empty sequence")
    require(not any(char.isspace() for char in copied_sequence), "Copied sequence contains formatting whitespace")
    require(set(copied_sequence.lower()) <= set("acgtnryswkmbdhvu.-"), "Copied value contains non-sequence display text")
    require(feedback.strip() == "Genomic hit updated", f"Mapped bin feedback is incorrect: {feedback!r}")
    require(picker_highlighted, "Genomic hit selector was not highlighted")
    require(one_track["checked"] == 1, f"Track test did not leave exactly one selected track: {one_track}")
    require(one_track["height"] == one_track["configuredHeight"], f"Configured browser height was not applied: {one_track}")
    require(one_track["height"] <= 400, f"One-track browser remains excessively tall: {one_track}")
    require(all_tracks_height >= one_track["height"] + 200, f"Browser height did not respond to track selection: all={all_tracks_height}, one={one_track}")
    require(narrow["pageOverflow"] <= 2, f"Narrow layout has horizontal page overflow: {narrow}")
    require(narrow["sequenceWidth"] >= narrow["panelWidth"] * 0.84 and narrow["copyVisible"], f"Narrow sequence controls are unusable: {narrow}")
    ok("Browse detail sequence copy, genomic-hit feedback, and dynamic JBrowse height passed")


if __name__ == "__main__":
    run_check(main)
