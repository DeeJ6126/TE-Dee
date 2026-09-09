from __future__ import annotations

from urllib.parse import quote

from harness_lib import app_url, http_json, ok, require, run_check


def graph_elements_contract(payload: dict) -> None:
    elements = payload.get("elements")
    require(isinstance(elements, list) and len(elements) > 0, "api/graph.php returned empty or invalid elements")
    nodes = []
    edges = []
    for item in elements:
        data = item.get("data") if isinstance(item, dict) else None
        if not isinstance(data, dict):
            continue
        if data.get("source") and data.get("target"):
            edges.append(data)
        else:
            nodes.append(data)
    require(nodes, "api/graph.php elements contain no nodes")
    require(edges, "api/graph.php elements contain no edges")
    for node in nodes[:5]:
        require(node.get("id"), f"Graph node missing id: {node}")
        require(node.get("type"), f"Graph node missing type: {node}")
        require("label" in node or "rawLabel" in node, f"Graph node missing label/rawLabel: {node}")
    for edge in edges[:5]:
        require(edge.get("id"), f"Graph edge missing id: {edge}")
        require(edge.get("source"), f"Graph edge missing source: {edge}")
        require(edge.get("target"), f"Graph edge missing target: {edge}")
        require(edge.get("relationType") or edge.get("relation"), f"Graph edge missing relation metadata: {edge}")


def node_data_items(payload: dict) -> list[dict]:
    items: list[dict] = []
    for item in payload.get("elements") or []:
        data = item.get("data") if isinstance(item, dict) else None
        if isinstance(data, dict) and not (data.get("source") and data.get("target")):
            items.append(data)
    return items


def find_node(payload: dict, label: str, node_type: str) -> dict:
    for node in node_data_items(payload):
        labels = {
            str(node.get("label") or ""),
            str(node.get("rawLabel") or ""),
        }
        if label in labels and node.get("type") == node_type:
            return node
    require(False, f"Could not find {node_type}:{label} in graph payload")
    return {}


def main() -> None:
    health = http_json(app_url("api/health.php"))
    require(health.get("ok") is True, "api/health.php ok must be true")
    require(health.get("neo4j_database") == "tekg3", f"api/health.php neo4j_database must be tekg3, got {health.get('neo4j_database')!r}")
    require(health.get("neo4j_reachable") is True, f"api/health.php neo4j_reachable must be true: {health.get('neo4j_message')}")
    ok("api/health.php contract passed")

    graph = http_json(app_url("api/graph.php?q=LINE1"))
    require(graph.get("ok") is True, f"api/graph.php?q=LINE1 ok must be true: {graph.get('error')}")
    require(isinstance(graph.get("anchor"), dict), "api/graph.php?q=LINE1 missing anchor object")
    graph_elements_contract(graph)
    ok("api/graph.php?q=LINE1 contract passed")

    line1_graph = http_json(app_url("api/graph.php?q=LINE-1"))
    require(line1_graph.get("ok") is True, f"api/graph.php?q=LINE-1 ok must be true: {line1_graph.get('error')}")
    disease_aging = find_node(line1_graph, "Aging", "Disease")
    disease_aging_id = str(disease_aging.get("id") or "")
    require(disease_aging_id, f"Disease:Aging node missing id: {disease_aging}")
    expanded = http_json(app_url(
        "api/graph.php?"
        f"q={quote('Aging')}"
        f"&expand_query={quote('Aging')}"
        f"&expand_node_type={quote('Disease')}"
        f"&expand_node_id={quote(disease_aging_id, safe='')}"
        "&key_level=1"
    ))
    require(expanded.get("ok") is True, f"same-label expand API ok must be true: {expanded.get('error')}")
    anchor = expanded.get("anchor")
    require(isinstance(anchor, dict), "same-label expand API missing anchor")
    require(anchor.get("name") == "Aging", f"same-label expand anchor name must be Aging: {anchor}")
    require(anchor.get("type") == "Disease", f"same-label expand anchor type must stay Disease: {anchor}")
    expanded_source = expanded.get("expanded_source")
    require(isinstance(expanded_source, dict), f"same-label expand API missing expanded_source: {expanded_source}")
    require(expanded_source.get("id") == disease_aging_id, f"same-label expanded_source id mismatch: {expanded_source}")
    require(expanded_source.get("type") == "Disease", f"same-label expanded_source type must be Disease: {expanded_source}")
    require(expanded_source.get("resolution") in {"id", "name_type"}, f"unexpected expanded_source resolution: {expanded_source}")
    ok("api/graph.php same-label expand disambiguation contract passed")

    tree = http_json(app_url("api/taxonomy.php?view=tree&source=rmsk_repbase"))
    require(tree.get("ok") is True, f"taxonomy file tree ok must be true: {tree.get('error')}")
    require(tree.get("root"), "taxonomy file tree missing root")
    require(isinstance(tree.get("nodes"), list) and tree["nodes"], "taxonomy file tree missing nodes")
    require(isinstance(tree.get("edges"), list), "taxonomy file tree missing edges list")
    ok("api/taxonomy.php file tree contract passed")

    items = http_json(app_url("api/taxonomy.php?names=L1HS,AluJb,SVA"))
    require(items.get("ok") is True, f"taxonomy items ok must be true: {items.get('error')}")
    require(items.get("source") == "tekg3", f"taxonomy items source must be tekg3, got {items.get('source')!r}")
    require(isinstance(items.get("items"), list) and items["items"], "taxonomy items missing items list")
    resolved = {str(item.get("name")) for item in items["items"] if isinstance(item, dict)}
    require(resolved, "taxonomy items did not resolve representative names")
    ok(f"api/taxonomy.php items contract passed: {', '.join(sorted(resolved))}")

    coexpression_catalog = http_json(app_url("api/coexpression.php?action=catalog"))
    require(coexpression_catalog.get("ok") is True, "co-expression catalog ok must be true")
    require(coexpression_catalog.get("version") == "v1_abs0.4_fdr0.05_res1.8", "co-expression catalog version mismatch")
    require(isinstance(coexpression_catalog.get("items"), list) and len(coexpression_catalog["items"]) == 285, "co-expression catalog must list 285 TE items")
    ok("api/coexpression.php catalog contract passed")

    coexpression_network = http_json(app_url("api/coexpression.php?action=network&te=L1HS&context=cancer_cell_line"))
    require(coexpression_network.get("ok") is True, "co-expression network ok must be true")
    require(len(coexpression_network.get("nodes") or []) == 26, "co-expression L1HS network must contain 26 nodes")
    require(len(coexpression_network.get("edges") or []) == 100, "co-expression L1HS network must contain 100 edges")
    ok("api/coexpression.php network contract passed")


if __name__ == "__main__":
    run_check(main)
