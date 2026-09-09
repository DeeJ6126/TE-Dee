from __future__ import annotations

from typing import Any

from harness_lib import (
    app_url,
    http_json,
    neo4j_config,
    neo4j_database_name,
    neo4j_query,
    ok,
    require,
    run_check,
)


REQUIRED_KEYS = {
    "ok",
    "nodes_total",
    "relationships_total",
    "te_level",
    "entity_composition",
    "relation_composition",
    "te_classification_composition",
    "generated_at",
}


def require_composition(name: str, rows: Any) -> None:
    require(isinstance(rows, list), f"{name} must be a list")
    require(rows, f"{name} must not be empty")
    total = 0
    for index, row in enumerate(rows):
        require(isinstance(row, dict), f"{name}[{index}] must be an object")
        label = row.get("label")
        count = row.get("count")
        percentage = row.get("percentage")
        require(isinstance(label, str) and label.strip(), f"{name}[{index}].label must be a non-empty string")
        require(isinstance(count, int) and count >= 0, f"{name}[{index}].count must be a non-negative int")
        require(isinstance(percentage, (int, float)), f"{name}[{index}].percentage must be numeric")
        require(0 <= float(percentage) <= 100, f"{name}[{index}].percentage must be within 0..100")
        total += count
    require(total > 0, f"{name} count total must be positive")


def main() -> None:
    config = neo4j_config()
    require(neo4j_database_name(config) == "tekg3", "Neo4j runtime target must be tekg3")

    data = http_json(app_url("api/home_stats.php"))
    missing = sorted(REQUIRED_KEYS.difference(data))
    require(not missing, f"home_stats missing keys: {', '.join(missing)}")
    require(data.get("ok") is True, "home_stats ok must be true")
    require(isinstance(data.get("nodes_total"), int) and data["nodes_total"] > 0, "nodes_total must be a positive int")
    require(
        isinstance(data.get("relationships_total"), int) and data["relationships_total"] > 0,
        "relationships_total must be a positive int",
    )
    require(isinstance(data.get("generated_at"), str) and data["generated_at"], "generated_at must be a string")
    require(data.get("te_level") == "class", f"default te_level must be class: {data.get('te_level')}")
    require_composition("entity_composition", data.get("entity_composition"))
    require_composition("relation_composition", data.get("relation_composition"))
    require_composition("te_classification_composition", data.get("te_classification_composition"))

    direct_nodes = neo4j_query(config, "MATCH (n) RETURN count(n) AS count")[0]["count"]
    direct_relationships = neo4j_query(config, "MATCH ()-[r]->() RETURN count(r) AS count")[0]["count"]
    direct_bio_relations = neo4j_query(config, "MATCH ()-[r:BIO_RELATION]->() RETURN count(r) AS count")[0]["count"]

    relation_rows_all = neo4j_query(
        config,
        """
MATCH ()-[r:BIO_RELATION]->()
WITH CASE
  WHEN trim(toString(coalesce(r.predicate, ''))) = '' THEN type(r)
  ELSE trim(toString(r.predicate))
END AS label
RETURN label, count(*) AS count
ORDER BY count DESC, label ASC
""",
    )
    broad = {"associate with", "participate in", "involve in"}
    relation_rows = [row for row in relation_rows_all if str(row["label"]).lower() not in broad]
    expected_relation_total = sum(int(row["count"]) for row in relation_rows)
    te_rows = neo4j_query(
        config,
        """
MATCH (t:TE)
WHERE coalesce(t.homepage_chart_included, false) = true
  AND coalesce(t.taxonomy_source, '') = 'tree_rmsk_repbase'
  AND trim(toString(coalesce(t.taxonomy_class, ''))) <> ''
WITH trim(toString(t.taxonomy_class)) AS label
RETURN label, count(*) AS count
ORDER BY count DESC, label ASC
""",
    )

    entity_total = sum(row["count"] for row in data["entity_composition"])
    relation_total = sum(row["count"] for row in data["relation_composition"])
    te_total = sum(row["count"] for row in data["te_classification_composition"])
    entity_percentage_total = sum(float(row["percentage"]) for row in data["entity_composition"])
    relation_percentage_total = sum(float(row["percentage"]) for row in data["relation_composition"])
    expected_top_labels = [str(row["label"]) for row in relation_rows[:5]]
    actual_top_labels = [str(row["label"]) for row in data["relation_composition"][:5]]
    expected_other = sum(int(row["count"]) for row in relation_rows[5:])
    actual_other = next((int(row["count"]) for row in data["relation_composition"] if row["label"] == "Other"), 0)
    expected_te = {str(row["label"]): int(row["count"]) for row in te_rows}
    actual_te = {str(row["label"]): int(row["count"]) for row in data["te_classification_composition"]}

    require(data["nodes_total"] == direct_nodes, "nodes_total must match direct Neo4j count")
    require(data["relationships_total"] == direct_relationships, "relationships_total must match direct Neo4j count")
    require(entity_total == data["nodes_total"], f"entity composition sum {entity_total} must equal nodes_total")
    require(direct_bio_relations > relation_total, "specific relation composition should exclude broad predicates")
    require(relation_total == expected_relation_total, f"relation composition sum {relation_total} must equal specific predicate count")
    require(te_total == sum(expected_te.values()), f"TE classification sum {te_total} must equal Repbase-prioritized TE class count")
    require(actual_te == expected_te, f"TE classification mismatch: {actual_te} != {expected_te}")
    require(abs(entity_percentage_total - 100.0) <= 0.2, "entity composition percentages should sum to about 100")
    require(abs(relation_percentage_total - 100.0) <= 0.2, "relation composition percentages should sum to about 100")
    require(len(data["relation_composition"]) <= 6, "relation_composition must contain at most Top 5 plus Other")
    require(actual_top_labels == expected_top_labels, f"relation Top 5 labels mismatch: {actual_top_labels} != {expected_top_labels}")
    require(actual_other == expected_other, f"relation Other count {actual_other} must equal {expected_other}")
    require(
        any(row.get("label") == "Other" for row in data["relation_composition"]) or len(data["relation_composition"]) <= 5,
        "relation_composition must be Top 5 plus Other when needed",
    )
    for level, property_name in {
        "order": "taxonomy_order",
        "superfamily": "taxonomy_superfamily",
        "family": "taxonomy_family",
    }.items():
        level_data = http_json(app_url(f"api/home_stats.php?te_level={level}"))
        require(level_data.get("ok") is True, f"home_stats te_level={level} ok must be true")
        require(level_data.get("te_level") == level, f"home_stats must echo te_level={level}")
        require_composition(f"te_classification_composition[{level}]", level_data.get("te_classification_composition"))
        level_rows = neo4j_query(
            config,
            f"""
MATCH (t:TE)
WHERE coalesce(t.homepage_chart_included, false) = true
  AND coalesce(t.taxonomy_source, '') = 'tree_rmsk_repbase'
  AND trim(toString(coalesce(t.{property_name}, ''))) <> ''
WITH trim(toString(t.{property_name})) AS label
RETURN label, count(*) AS count
ORDER BY count DESC, label ASC
""",
        )
        expected_level = {str(row["label"]): int(row["count"]) for row in level_rows}
        actual_level = {str(row["label"]): int(row["count"]) for row in level_data["te_classification_composition"]}
        require(actual_level == expected_level, f"TE classification mismatch for {level}: {actual_level} != {expected_level}")
    ok(
        "home stats API contract passed: "
        f"nodes={data['nodes_total']}, relationships={data['relationships_total']}, "
        f"entities={len(data['entity_composition'])}, relations={len(data['relation_composition'])}"
    )


if __name__ == "__main__":
    run_check(main)
