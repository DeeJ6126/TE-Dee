from __future__ import annotations

from harness_lib import app_url, http_json, ok, require, run_check


COUNT_FIELDS = [
    "support_pmid_count",
    "support_metric_paper_count",
    "support_jcr_q1_count",
    "support_jcr_q2_count",
    "support_jcr_q3_count",
    "support_jcr_q4_count",
    "support_journal_count",
]
IF_FIELDS = [
    "support_if_max",
    "support_if_mean",
    "support_if_median",
]
YEAR_FIELDS = [
    "support_publication_year_min",
    "support_publication_year_max",
]


def edge_data(payload: dict) -> list[dict]:
    edges: list[dict] = []
    for item in payload.get("elements") or []:
        data = item.get("data") if isinstance(item, dict) else None
        if isinstance(data, dict) and data.get("source") and data.get("target"):
            edges.append(data)
    return edges


def is_number(value: object) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def main() -> None:
    payload = http_json(app_url("api/graph.php?q=LINE1"))
    require(payload.get("ok") is True, f"graph API failed: {payload.get('error')}")
    edges = edge_data(payload)
    require(edges, "api/graph.php?q=LINE1 returned no edges")

    support_edges = [edge for edge in edges if "support_pmid_count" in edge]
    require(support_edges, f"no edge has support_* fields; sample edge keys={sorted(edges[0].keys())}")
    edge = support_edges[0]

    for field in COUNT_FIELDS:
        require(field in edge, f"edge missing {field}: {edge}")
        require(isinstance(edge[field], int) and not isinstance(edge[field], bool), f"{field} must be int: {edge[field]!r}")
        require(edge[field] >= 0, f"{field} must be >= 0: {edge[field]!r}")

    coverage = edge.get("support_metric_coverage")
    require(is_number(coverage), f"support_metric_coverage must be number: {coverage!r}")
    require(0 <= float(coverage) <= 1, f"support_metric_coverage outside [0,1]: {coverage!r}")

    for field in IF_FIELDS:
        require(field in edge, f"edge missing {field}: {edge}")
        require(edge[field] is None or is_number(edge[field]), f"{field} must be number or null: {edge[field]!r}")

    for field in YEAR_FIELDS:
        require(field in edge, f"edge missing {field}: {edge}")
        require(edge[field] is None or (isinstance(edge[field], int) and not isinstance(edge[field], bool)), (
            f"{field} must be int or null: {edge[field]!r}"
        ))

    ok(
        "graph API evidence support edge contract passed: "
        f"support_pmid_count={edge['support_pmid_count']}, "
        f"coverage={edge['support_metric_coverage']}, "
        f"if_mean={edge['support_if_mean']}"
    )


if __name__ == "__main__":
    run_check(main)
