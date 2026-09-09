from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> None:
    workspace = read("assets/js/pages/preview/preview-workspace-mode.js")
    bootstrap = read("assets/js/renderers/g6/index-g6.bootstrap.js")
    tree = read("assets/js/renderers/g6/default-tree-mindmap.js")
    canvas = read("assets/js/renderers/canvas-force/taxonomy-canvas-renderer.js")

    require(
        "disease_class_graph" in bootstrap,
        "Disease classification has no distinct Canvas Graph runtime state.",
    )
    require(
        "disease_class_graph" in workspace,
        "The Preview parent does not understand the disease classification Graph state.",
    )
    require(
        "taxonomyDisplayMode" in workspace and "queryType === 'disease_class'" in workspace,
        "The existing taxonomy=graph parameter is not applied to disease classification routes.",
    )
    require(
        "renderModel" in canvas or "renderData" in canvas,
        "The Canvas force renderer has no data-adapter entrypoint for the existing disease hierarchy payload.",
    )
    require(
        "onNodeActivate" in canvas or "onNodeClick" in canvas,
        "The Canvas force renderer cannot distinguish inspect-only classification nodes from concrete Disease navigation.",
    )
    require(
        "DiseaseCategory" in bootstrap and "queryType: 'Disease'" in bootstrap,
        "Concrete Disease leaves are not routed as typed canonical Disease navigation.",
    )
    require(
        "currentMode === 'disease_class_graph'" in bootstrap,
        "Disease classification Graph is not integrated with legend/export/state behavior.",
    )
    require(
        "horizontalGap: 36" in bootstrap and "getActiveTreeConfig()?.horizontalGap" in tree,
        "Disease classification Tree does not use its shorter inter-level spacing.",
    )

    print("PASS: Disease classification Canvas route and interaction static contract")


if __name__ == "__main__":
    main()
