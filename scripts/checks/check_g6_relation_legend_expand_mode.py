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


def assert_occurs_at_least(path: str, needle: str, expected: int) -> None:
    content = read(path)
    actual = content.count(needle)
    assert actual >= expected, f"{path} should contain {needle!r} at least {expected} times, found {actual}"


def main() -> None:
    assert_not_contains("preview.php", "key-node-level-control")
    assert_not_contains("preview.php", "Key-node level")
    assert_contains("preview.php", "templates/preview/knowledge_graph_workspace.php")
    assert_not_contains("templates/preview/knowledge_graph_workspace.php", "key-node-level-control")
    assert_not_contains("templates/preview/knowledge_graph_workspace.php", "Key-node level")
    assert_contains("templates/preview/knowledge_graph_workspace.php", "toggle-edge-labels")
    assert_contains("templates/preview/knowledge_graph_workspace.php", "Show relations: Off")
    assert_contains("templates/preview/knowledge_graph_workspace.php", "toggle-expand-mode")
    assert_contains("templates/preview/knowledge_graph_workspace.php", "graph-legend-mode-switch")
    assert_contains("templates/preview/knowledge_graph_workspace.php", "graph-relation-min-pmids")
    assert_contains("templates/preview/knowledge_graph_workspace.php", "graph-legend-apply")

    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "relationLegendState")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "function getCurrentGraphElements")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "function getCurrentLegendNodeTypes")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "const presentTypes = getCurrentLegendNodeTypes()")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "window.showEdgeLabels = false")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "showEdgeLabels: window.showEdgeLabels")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "relationLegendKeyForEdge")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "getVisibleRelationPayload")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "relationMinPmids")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "expandModeEnabled")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "expandSelectedNode")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "mergeGraphElements")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "let graphIsLoading = false")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "let legendFilterPending = false")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "function markLegendFilterPending")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "function applyPendingLegendFilter")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "function updateCurrentGraphViewState")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "bridge.setViewState")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "bridge.expandGraph")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "await renderDynamicElementsFromCache(currentQueryGraphElements, {")
    assert_occurs_at_least("assets/js/renderers/g6/index-g6.bootstrap.js", "updateCurrentGraphViewState().catch", 4)
    assert_not_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "rerenderCurrentDynamicGraph().catch")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "function syncToggleButtonState")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "syncToggleButtonState(els.edgeLabelsBtn, window.showEdgeLabels)")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "syncToggleButtonState(els.showLabelsBtn, window.showLabels)")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "syncToggleButtonState(els.fixedBtn, window.fixedView)")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "syncToggleButtonState(els.expandModeBtn, expandModeEnabled)")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "const shouldShow = mode === 'dynamic' && hasItems")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "renderGraphLegendLoading")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "allowInspectCard: window.fixedView && !expandModeEnabled")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "if (expandModeEnabled) {")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "window.fixedView = false")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "syncLegendVisibility(currentMode)")
    assert_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "return [...filteredNodes, ...filteredEdges]")
    assert_not_contains("assets/js/renderers/g6/index-g6.bootstrap.js", "return [...connectedNodes, ...filteredEdges]")
    assert_contains("assets/js/renderers/g6/index-g6-embed.js", "parentDrivesInitialGraph")
    assert_contains("assets/js/renderers/g6/index-g6-embed.js", "if (initialRequest.query && !parentDrivesInitialGraph)")

    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "visibleRelations")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "currentShowEdgeLabels = graphDataOptions.showEdgeLabels === true")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "currentAllowInspectCard = graphDataOptions.allowInspectCard === true")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "function setViewState")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "function expandGraph")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "graph.addNodeData")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "graph.addEdgeData")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "applyCurrentViewState")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "graph.updateNodeData(nextNodes.map")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "graph.updateEdgeData(currentGraphData.edges.map")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "await graph.draw()")
    assert_not_contains("assets/js/renderers/g6/index-g6-shared.js", "graph.setData(currentGraphData)")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "if (currentAllowInspectCard) showInspectCard('node', node, event, data)")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "if (currentAllowInspectCard) showInspectCard('edge', edge, event, data)")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "if (!currentShowEdgeLabels) return ''")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "relationLegendKeyForEdge")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "minRelationPmids")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "relationStyleForType")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "relationLegendMeta")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "const candidateNodes = [...nodes]")
    assert_not_contains("assets/js/renderers/g6/index-g6-shared.js", "const candidateNodes = nonIsolatedNodes.length ? nonIsolatedNodes : [...nodes]")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "const rendered = await renderElements(payload.elements || [], request, {")
    assert_contains("assets/js/renderers/g6/index-g6-shared.js", "return { ...payload, elements: rendered.elements, relationLegendMeta: rendered.relationLegendMeta }")
    assert_contains("assets/js/renderers/g6/index-g6-embed.js", "setViewState(next)")
    assert_contains("assets/js/renderers/g6/index-g6-embed.js", "expandGraph(requestLike, options = {})")

    assert_contains("assets/css/pages/preview.css", "graph-legend-mode-switch")
    assert_contains("assets/css/pages/preview.css", "height: auto")
    assert_contains("assets/css/pages/preview.css", "overflow: visible")
    assert_not_contains("assets/css/pages/preview.css", "min-height: 520px")
    assert_contains("assets/css/pages/preview.css", "graph-legend-footer")
    assert_contains("assets/css/pages/preview.css", "margin-top: 12px")
    assert_not_contains("assets/css/pages/preview.css", "right: 18px")
    assert_not_contains("assets/css/pages/preview.css", "bottom: 16px")
    assert_contains("assets/css/pages/preview.css", "graph-legend-apply")
    assert_not_contains("assets/css/pages/preview.css", "overflow-y: auto;\n          padding-right: 4px;")
    assert_contains("assets/css/pages/preview.css", "text-decoration-thickness: 2px")
    assert_not_contains("assets/css/pages/preview.css", ".graph-legend-tab.is-active {\n          border-radius")
    assert_contains("assets/css/pages/preview.css", "expand-mode")
    assert_contains("assets/css/pages/preview.css", ".preview-graph-toolbar button.is-toggle")
    assert_contains("assets/css/pages/preview.css", ".preview-graph-toolbar button.is-toggle.is-active")
    assert_contains("assets/css/pages/preview.css", "graph-legend-loading")
    assert_contains("assets/css/pages/preview.css", "graph-legend-loading-icon")
    assert_contains("assets/css/pages/preview.css", "width: 96px")
    assert_contains("assets/css/pages/preview.css", "graph-relation-line")


if __name__ == "__main__":
    main()
