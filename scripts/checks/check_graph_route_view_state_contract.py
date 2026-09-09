from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def function_block(source: str, name: str, next_name: str) -> str:
    start = source.find(f"function {name}")
    end = source.find(f"function {next_name}", start + 1)
    require(start >= 0 and end > start, f"Could not inspect {name}().")
    return source[start:end]


def main() -> None:
    workspace = read("assets/js/pages/preview/preview-workspace-mode.js")
    bootstrap = read("assets/js/renderers/g6/index-g6.bootstrap.js")

    clear_block = function_block(workspace, "clearGraphParams", "routeForKnowledge")
    route_block = function_block(workspace, "routeForKnowledge", "routeForCoexpression")
    display_block = function_block(workspace, "setTaxonomyDisplayMode", "setTreeVariant")
    apply_block = function_block(bootstrap, "applyPendingLegendFilter", "setLegendHighlight")
    snapshot_block = function_block(bootstrap, "snapshotState", "notifyStateChange")

    for parameter in ("taxonomy", "nodes", "relations", "min_pmids"):
        require(
            f"'{parameter}'" in clear_block or f'"{parameter}"' in clear_block,
            f"Preview routes do not clear the phase 2/4 `{parameter}` parameter when changing workspace routes.",
        )
        require(
            f"searchParams.set('{parameter}'" in workspace
            or f'searchParams.set("{parameter}"' in workspace,
            f"Preview routes never serialize `{parameter}`.",
        )
        require(
            f"get('{parameter}')" in workspace or f'get("{parameter}")' in workspace,
            f"Preview routes never parse `{parameter}` during refresh or Back/Forward restoration.",
        )

    require(
        "taxonomyDisplayMode" in route_block and "'graph'" in route_block,
        "Taxonomy Tree/Graph state is not represented by routeForKnowledge().",
    )
    require(
        "writeRoute" in display_block and "'push'" in display_block,
        "Changing taxonomy Tree/Graph does not create a browser-history route entry.",
    )
    require(
        "visibleTypes" in snapshot_block and "visibleRelations" in snapshot_block,
        "The Graph state snapshot does not expose both applied node and relation filters.",
    )
    require(
        "relationMinPmids" in snapshot_block,
        "The Graph state snapshot does not expose the applied Min PMID value.",
    )
    require(
        "notifyNavigation" in apply_block,
        "Legend Apply does not publish one route update after the pending filters are applied.",
    )

    print("PASS: Graph phase 2/4 route-state static contract")


if __name__ == "__main__":
    main()
