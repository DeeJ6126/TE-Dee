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
    "te_source",
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
    require(data.get("te_level") == "superfamily", f"default te_level must be superfamily: {data.get('te_level')}")
    require(data.get("te_source") == "rmsk_repbase", f"TE source must be rmsk_repbase: {data.get('te_source')}")
    require_composition("entity_composition", data.get("entity_composition"))
    require_composition("relation_composition", data.get("relation_composition"))
    require_composition("te_classification_composition", data.get("te_classification_composition"))

    taxonomy_data = http_json(app_url("api/taxonomy.php?view=tree&source=rmsk_repbase"))
    taxonomy_nodes = {str(row.get("name", "")) for row in taxonomy_data.get("nodes", []) if row.get("name")}
    taxonomy_parents = {str(row.get("parent", "")) for row in taxonomy_data.get("edges", []) if row.get("parent")}
    taxonomy_te_count = len(taxonomy_nodes.difference(taxonomy_parents))
    require(taxonomy_te_count > 0, "RMSK + RepBase taxonomy leaf count must be positive")
    direct_non_te_entities = neo4j_query(
        config,
        "MATCH (n) WHERE NOT 'Paper' IN labels(n) AND NOT 'TE' IN labels(n) RETURN count(n) AS count",
    )[0]["count"]
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
    entity_total = sum(row["count"] for row in data["entity_composition"])
    relation_total = sum(row["count"] for row in data["relation_composition"])
    te_total = sum(row["count"] for row in data["te_classification_composition"])
    entity_percentage_total = sum(float(row["percentage"]) for row in data["entity_composition"])
    relation_percentage_total = sum(float(row["percentage"]) for row in data["relation_composition"])
    expected_relation = {
        str(row["label"]): int(row["count"])
        for row in relation_rows
        if expected_relation_total > 0 and (int(row["count"]) / expected_relation_total) >= 0.01
    }
    expected_other = sum(
        int(row["count"])
        for row in relation_rows
        if expected_relation_total > 0 and (int(row["count"]) / expected_relation_total) < 0.01
    )
    if expected_other > 0:
        expected_relation["others"] = expected_other
    actual_relation = {str(row["label"]): int(row["count"]) for row in data["relation_composition"]}
    actual_entity = {str(row["label"]): int(row["count"]) for row in data["entity_composition"]}
    require(data["nodes_total"] == direct_non_te_entities + taxonomy_te_count, "nodes_total must combine non-TE Neo4j entities and RMSK + RepBase TEs")
    require(actual_entity.get("TE") == taxonomy_te_count, "Entity composition TE count must match RMSK + RepBase taxonomy leaves")
    require(data["relationships_total"] == direct_relationships, "relationships_total must match direct Neo4j count")
    require(entity_total == data["nodes_total"], f"entity composition sum {entity_total} must equal nodes_total")
    require(direct_bio_relations > relation_total, "specific relation composition should exclude broad predicates")
    require(relation_total == expected_relation_total, f"relation composition sum {relation_total} must equal specific predicate count")
    require(te_total == taxonomy_te_count, f"TE classification sum {te_total} must equal taxonomy leaf count {taxonomy_te_count}")
    require(abs(entity_percentage_total - 100.0) <= 0.2, "entity composition percentages should sum to about 100")
    require(abs(relation_percentage_total - 100.0) <= 0.2, "relation composition percentages should sum to about 100")
    require(actual_relation == expected_relation, f"relation composition mismatch: {actual_relation} != {expected_relation}")
    for level in ("class", "order", "superfamily", "family"):
        level_data = http_json(app_url(f"api/home_stats.php?te_level={level}"))
        require(level_data.get("ok") is True, f"home_stats te_level={level} ok must be true")
        require(level_data.get("te_level") == level, f"home_stats must echo te_level={level}")
        require_composition(f"te_classification_composition[{level}]", level_data.get("te_classification_composition"))
        level_total = sum(int(row["count"]) for row in level_data["te_classification_composition"])
        require(level_total == taxonomy_te_count, f"TE classification total mismatch for {level}: {level_total} != {taxonomy_te_count}")
    ok(
        "home stats API contract passed: "
        f"nodes={data['nodes_total']}, relationships={data['relationships_total']}, "
        f"entities={len(data['entity_composition'])}, relations={len(data['relation_composition'])}"
    )


if __name__ == "__main__":
    run_check(main)
