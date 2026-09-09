from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


page = read("path_finder.php")
script = read("assets/js/pages/path_finder.js")
path_css = read("assets/css/pages/path_finder.css")
preview_css = read("assets/css/pages/preview.css")

for removed_id in ("pathResolved", "pathGraphShowNames", "pathGraphDetail"):
    assert f'id="{removed_id}"' not in page, f"obsolete Path control remains: {removed_id}"

for option_id, label in (
    ("pathGraphExportCsv", "CSV"),
    ("pathGraphExportPng", "PNG"),
    ("pathGraphExportSvg", "SVG"),
):
    assert f'id="{option_id}"' in page, f"missing Path export option: {label}"

assert "g6-svg-export.js" in page, "Path page must load the shared SVG serializer"
assert "showAllLabels: true" in script, "Path graph names must remain visible"
assert "allowInspectCard: true" in script, "Path graph edges must use the shared inspect card"
assert "allowNodeActions: false" in script, "Path graph must keep node Jump/Expand actions disabled"
assert "exportSvgString" in script, "Path SVG export handler is missing"
assert "getVisibleSubgraph" in script, "Path CSV export must use the visible graph"
assert ".path-command-control" in path_css and "border-radius: 6px" in path_css
assert ".preview-graph-command" in preview_css and "border-radius: 6px" in preview_css

print("Path/Graph toolbar contract OK")
