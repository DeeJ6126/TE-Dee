from __future__ import annotations

from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def main() -> int:
    failures: list[str] = []

    required_files = [
        "path_finder.php",
        "api/path_finder.php",
        "api/path_finder_service.php",
        "assets/js/pages/path_finder.js",
        "assets/css/pages/path_finder.css",
    ]
    for relative in required_files:
        if not (ROOT / relative).is_file():
            failures.append(f"missing {relative}")

    head = text("head.php")
    nav_start = head.find("$navItems = [")
    nav_end = head.find("];", nav_start)
    nav = head[nav_start:nav_end]
    browse_pos = nav.find("'browse'")
    path_pos = nav.find("'path_finder'")
    preview_pos = nav.find("'preview'")
    if not (browse_pos != -1 and path_pos != -1 and preview_pos != -1 and browse_pos < preview_pos < path_pos):
        failures.append("Graph nav item is not between Browse and Path")

    index = text("index.php")
    browse_quick = index.find("'Browse'")
    path_quick = index.find("'Path'")
    preview_quick = index.find("'Graph'")
    if not (browse_quick != -1 and path_quick != -1 and preview_quick != -1 and browse_quick < preview_quick < path_quick):
        failures.append("Graph quick link is not between Browse and Path")

    if (ROOT / "assets/js/pages/path_finder.js").is_file():
        js = text("assets/js/pages/path_finder.js")
        if "https://pubmed.ncbi.nlm.nih.gov/" not in js:
            failures.append("Path Finder JS does not build PubMed PMID links")
        if "compact-path-strip" not in js:
            failures.append("Path Finder JS does not render compact path strips")
        if "Number(path.hop_count || 0) <= 1) {\n      return '';" in js:
            failures.append("Path Finder still suppresses the compact strip for direct paths")
        if "compact-path-edge" not in js or 'aria-expanded="false"' not in js:
            failures.append("Path Finder relationships are not interactive evidence controls")
        if "path-evidence-detail" not in js or "data-path-edge-index" not in js:
            failures.append("Path Finder does not connect relationship arrows to inline literature")
        if "path-evidence-toggle" in js or "path-evidence-list" in js:
            failures.append("Path Finder still renders the legacy evidence-card list")
        if "buildGraphElements" not in js or "payload.paths" not in js:
            failures.append("Path Finder JS does not build graph results from path payloads")
        if "allowNodeActions: false" not in js:
            failures.append("Path Finder graph does not disable G6 jump/expand node actions")
        if "allowInspectCard: true" not in js:
            failures.append("Path Finder graph does not enable shared edge inspection")
        if "Relation type" in js:
            failures.append("Path Finder JS still renders raw relation type metadata")

    if (ROOT / "assets/css/pages/path_finder.css").is_file():
        css = text("assets/css/pages/path_finder.css")
        if ".path-card + .path-card" not in css or "border-top: 1px solid #cfd7e3" not in css:
            failures.append("Path Finder results are not separated by a neutral divider")
        if ".compact-path-edge-arrow::after" not in css:
            failures.append("Path Finder relationship controls do not render arrowheads")
        if "text-transform: uppercase" in css[css.find(".path-rank"):css.find(".path-metrics")]:
            failures.append("Path result labels still force Path to uppercase")

    if (ROOT / "path_finder.php").is_file():
        page = text("path_finder.php")
        for marker in [
            "pathTableView",
            "pathGraphView",
            "pathGraphShowRelations",
            "pathGraphExport",
            "pathGraphExportCsv",
            "pathGraphExportPng",
            "pathGraphExportSvg",
            "index-g6-shared.js",
        ]:
            if marker not in page:
                failures.append(f"Path Finder page is missing graph marker {marker}")
        for obsolete_marker in ["pathResolved", "pathGraphShowNames", "pathGraphDetail"]:
            if obsolete_marker in page:
                failures.append(f"Path Finder page still contains obsolete marker {obsolete_marker}")
        if '<option value="10">10</option>' not in page:
            failures.append("Path Finder page does not offer a 10-hop maximum")

    if (ROOT / "api/path_finder_service.php").is_file():
        service = text("api/path_finder_service.php")
        if "NONE(label IN labels(node) WHERE label = 'Paper')" not in service:
            failures.append("Path Finder service does not exclude Paper nodes from paths")
        if "BIO_RELATION*1.." not in service:
            failures.append("Path Finder service does not query BIO_RELATION paths")
        if "PATH_FINDER_MAX_DEPTH = 10" not in service or "path_finder_clamp_depth" not in service:
            failures.append("Path Finder service does not centralize the 1..10 depth contract")
        if "PATH_FINDER_QUERY_BUDGET_SECONDS" not in service:
            failures.append("Path Finder depth traversal has no request-wide query budget")
        if "size(nodes(p)) = size(reduce(unique_ids" not in service:
            failures.append("Path Finder does not exclude cyclic node-revisiting paths")

    if failures:
        print("FAIL path finder check")
        for failure in failures:
            print(f"- {failure}")
        return 1

    print("PASS path finder check")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
