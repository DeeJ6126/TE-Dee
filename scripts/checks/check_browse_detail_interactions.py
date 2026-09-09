from __future__ import annotations

from pathlib import Path

from harness_lib import ok, require, run_check


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def main() -> None:
    search_php = read("search.php")
    search_css = read("assets/css/pages/search.css")
    search_js = read("assets/js/pages/search.js")

    require('id="search-sequence-copy"' in search_php, "Sequence copy button is missing")
    require('data-raw-sequence=' in search_php, "Sequence markup does not expose the unformatted sequence")
    require('><?= htmlspecialchars($rawRepbaseSequence, ENT_QUOTES, \'UTF-8\') ?></pre>' in search_php, "Visible sequence still uses server-inserted fixed line breaks")
    require('id="search-sequence-copy-status"' in search_php, "Sequence copy status region is missing")
    require('id="search-karyotype-feedback"' in search_php, "Karyotype feedback region is missing")
    require('id="searchJBrowseRestoreHits" hidden>Back</button>' in search_php, "Chromosome-filter return action is not labeled Back")
    require('aria-live="polite"' in search_php, "Interaction feedback is not announced accessibly")

    require("copyRawSequence" in search_js, "Raw sequence copy handler is missing")
    require("navigator.clipboard.writeText" in search_js, "Clipboard API is not used")
    require("replace(/\\s+/g, '')" in search_js, "Copied sequence is not normalized to raw bases")
    require("showGenomicHitUpdated" in search_js, "Genomic-hit update feedback is missing")
    require("is-hit-updated" in search_js, "Genomic-hit selector highlight is not controlled")
    require("syncGenomeBrowserHeight" in search_js, "Dynamic JBrowse height synchronizer is missing")
    require("estimateGenomeBrowserHeight" in search_js, "Track-aware JBrowse height estimate is missing")

    sequence_wrap_rule = search_css[search_css.index(".sequence-code-wrap"):search_css.index(".sequence-copy-button {")]
    require("width: 100%" in sequence_wrap_rule and "width: fit-content" not in sequence_wrap_rule, "Sequence box still uses content width")
    sequence_rule = search_css[search_css.index(".sequence-code {"):search_css.index(".sequence-plot {")]
    require("width: 100%" in sequence_rule, "Visible sequence does not use the available row width")
    require("white-space: normal" in sequence_rule, "Visible sequence still preserves fixed server-side lines")
    require("word-break: break-all" in sequence_rule, "Visible sequence cannot wrap to the responsive container width")
    require("height: 840px" not in search_css, "JBrowse still has a fixed 840px height")
    require("--jbrowse-view-height" in search_css, "JBrowse height CSS custom property is missing")
    require(".is-hit-updated" in search_css, "Genomic-hit updated visual state is missing")
    restore_rule = search_css[search_css.index(".jbrowse-hit-restore {"):search_css.index(".jbrowse-hit-picker-select {")]
    require("border-radius: 6px" in restore_rule and "border-radius: 999px" not in restore_rule, "Back button does not match the site action-button corner radius")

    ok("Browse entity-detail interaction contracts passed")


if __name__ == "__main__":
    run_check(main)
