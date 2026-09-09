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
    css = "assets/css/pages/preview.css"

    assert_contains(bootstrap, "LEGEND_DISEASE_TYPES")
    assert_contains(bootstrap, "legendTypeForNodeType")
    assert_contains(bootstrap, "rawRelationLegendMeta")
    assert_contains(bootstrap, "collectRelationLegendMetaFromElements")
    assert_contains(bootstrap, "graph-legend-mode-switch")
    assert_not_contains(bootstrap, "graph-legend-tab is-active")

    assert_contains(shared, "isClassificationRelation")
    assert_contains(shared, "if (!isClassificationRelation(relationType) && pmids.length < minRelationPmids)")
    assert_contains(shared, "const expanded = await Promise.resolve(hooks.onNodeExpand(node));")
    assert_contains(shared, "if (expanded) return;")
    assert_not_contains(shared, "if (expanded || expanded === false) return;")
    assert_not_contains(shared, "synthesizeDiseaseClasses")
    assert_not_contains(shared, "diseaseMembers")
    assert_not_contains(shared, "disease-class::")
    assert_not_contains(shared, "relationType: 'DISEASE_CLASSIFICATION'")

    assert_contains(bootstrap, "makeDiseaseTreeNodeId")
    assert_contains(bootstrap, "sourceId")
    assert_contains(bootstrap, "treePath")

    assert_contains(css, "graph-legend-mode-switch")
    assert_not_contains(css, "graph-legend-tabs")


if __name__ == "__main__":
    main()
