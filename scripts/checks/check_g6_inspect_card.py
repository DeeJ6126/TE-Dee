from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def assert_contains(path: str, needle: str) -> None:
    content = read(path)
    assert needle in content, f"{path} is missing {needle!r}"


def assert_not_contains(path: str, needle: str) -> None:
    content = read(path)
    assert needle not in content, f"{path} should not contain {needle!r}"


def main() -> None:
    bootstrap = "assets/js/renderers/g6/index-g6.bootstrap.js"
    shared = "assets/js/renderers/g6/index-g6-shared.js"
    preview_css = "assets/css/pages/preview.css"

    assert_contains(bootstrap, "window.fixedView = true")

    assert_contains(shared, "function showInspectCard")
    assert_contains(shared, "function hideInspectCard")
    assert_contains(shared, "function resolveInspectQuadrant")
    assert_contains(shared, "function renderNodeInspectCard")
    assert_contains(shared, "function renderEdgeInspectCard")
    assert_contains(shared, "function pubmedUrl")
    assert_contains(shared, "https://pubmed.ncbi.nlm.nih.gov/")
    assert_contains(shared, "inspect-card")
    assert_contains(shared, "inspect-card is-expanded")
    assert_contains(shared, "Inspect card")
    assert_contains(shared, "showInspectCard('node', node, event, data)")
    assert_contains(shared, "showInspectCard('edge', edge, event, data)")
    assert_contains(shared, "graph.on('canvas:click', () => {")
    assert_contains(shared, "hideInspectCard()")
    assert_contains(shared, "pmid: String(data.pmid || '')")
    assert_contains(shared, "taxonomy: data.taxonomy && typeof data.taxonomy === 'object' ? data.taxonomy : null")
    assert_not_contains(shared, "kvRow('Group', taxonomy.group)")
    assert_contains(preview_css, ".preview-graph-panel > .detail")
    assert_contains(preview_css, "display: none;")

    forbidden_labels = [
        "Pin node",
        "Hide node",
        "Hide edge",
        "Copy PMID",
        "Open all PubMed",
    ]
    for label in forbidden_labels:
        assert_not_contains(shared, label)


if __name__ == "__main__":
    main()
