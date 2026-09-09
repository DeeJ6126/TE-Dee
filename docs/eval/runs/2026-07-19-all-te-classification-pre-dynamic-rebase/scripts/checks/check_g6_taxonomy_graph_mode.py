from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
TREE = ROOT / "assets/js/renderers/g6/default-tree-mindmap.js"
BOOTSTRAP = ROOT / "assets/js/renderers/g6/index-g6.bootstrap.js"
ADAPTER = ROOT / "assets/js/renderers/g6/large-force-graph/adapters/taxonomy-large-force-adapter.js"
LAYOUT = ROOT / "assets/js/renderers/g6/large-force-graph/large-force-graph-layout.js"
CORE = ROOT / "assets/js/renderers/g6/large-force-graph/large-force-graph-core.js"


def require(text: str, needle: str, message: str) -> None:
    if needle not in text:
        raise SystemExit(f"[FAIL] {message}: missing {needle!r}")


def reject(text: str, needle: str, message: str) -> None:
    if needle in text:
        raise SystemExit(f"[FAIL] {message}: unexpected {needle!r}")


def main() -> None:
    tree = TREE.read_text(encoding="utf-8")
    bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
    adapter = ADAPTER.read_text(encoding="utf-8")
    layout = LAYOUT.read_text(encoding="utf-8")
    core = CORE.read_text(encoding="utf-8")

    require(tree, "function buildTaxonomyLevelLegendItems", "taxonomy graph must expose level legend metadata")
    require(tree, "function applyTaxonomyGraphLevelState", "taxonomy graph must support legend filtering/highlighting")
    require(adapter, "performanceProfile: 'bounded-dynamic'",
            "production taxonomy adapter must request the bounded dynamic profile")
    require(layout, "if (options.performanceProfile === 'large-static') return { type: 'preset' }",
            "large-static taxonomy graph must resolve to preset")
    require(layout, "if (profile === 'taxonomy-force') return createTaxonomyLargeForceLayout(options)",
            "bounded force must remain explicit while large-static stays available as fallback")
    require(layout, "iterations: 24", "production bounded force must have an explicit iteration cap")
    require(layout, "distanceMax: 360", "production force must bound many-body work")
    require(layout, "function createTransientDragLayout", "drag may use one bounded transient-force profile")
    require(core, "createTransientDragLayout", "production drag must opt into transient force explicitly")
    reject(tree, "taxonomyRipple", "taxonomy graph must not use ripple animation")
    reject(tree, "tekg-taxonomy-ripple-circle", "taxonomy graph must not register ripple nodes")
    require(tree, "data.hasGraphEntity === true", "jumpable taxonomy nodes must be explicit graph-entity TE nodes")
    require(tree, "TAXONOMY_ALWAYS_LABELS", "taxonomy graph must expose a manual always-label list")
    require(tree, "taxonomyGraphHoverNodeId", "taxonomy graph must support hover-only labels")
    require(tree, "isTaxonomyAlwaysLabeled", "taxonomy graph labels must be controlled by the manual list")
    reject(tree, "directChildCount >= 14", "taxonomy labels must not be auto-shown by dense children")
    reject(tree, "descendantCount >= 45", "taxonomy labels must not be auto-shown by large descendants")
    reject(tree, "depth <= 3) return true", "taxonomy labels must not be auto-shown by depth")
    require(tree, "visibleNodes = nodes.filter", "hidden taxonomy levels must be pruned from graph data")
    require(tree, "visibleEdges = edges.filter", "hidden taxonomy edges must be pruned from graph data")
    require(tree, "activeTaxonomyGraphConfig?.visibleTaxonomyLevels", "active taxonomy level state must be applied before pruning")
    require(tree, "taxonomyGraphDragging", "taxonomy graph must reduce label cost while dragging")
    require(tree, "item.depth < 6", "taxonomy levels 6/7/8 must be hidden by default")
    require(tree, "taxonomyLevelKey", "taxonomy graph nodes must carry level keys for legend behavior")
    require(bootstrap, "function getTaxonomyLegendMeta", "parent legend must have taxonomy-specific metadata")
    require(bootstrap, "let currentTaxonomyDisplayMode = 'graph'", "taxonomy graph must be the default taxonomy view")
    require(bootstrap, "item.depth < 6", "parent taxonomy legend state must hide levels 6/7/8 by default")
    require(bootstrap, "currentMode === 'taxonomy_graph'", "legend visibility must support taxonomy graph mode")
    require(bootstrap, "applyTaxonomyLegendFilter", "taxonomy legend checkbox changes must re-render taxonomy graph")
    require(bootstrap, "setTaxonomyLegendHighlight", "taxonomy legend click must highlight taxonomy levels")

    print("[OK] G6 taxonomy graph mode contract passed")


if __name__ == "__main__":
    main()
