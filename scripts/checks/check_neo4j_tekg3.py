from __future__ import annotations

from harness_lib import neo4j_config, neo4j_database_name, neo4j_query, ok, require, run_check


REPRESENTATIVE_TE = ["L1HS", "AluJb", "SVA"]


def scalar(config: dict[str, str], statement: str, column: str, parameters: dict | None = None) -> int:
    rows = neo4j_query(config, statement, parameters)
    value = rows[0].get(column) if rows else None
    return int(value or 0)


def main() -> None:
    config = neo4j_config()
    database = neo4j_database_name(config)
    require(database == "tekg3", f"Neo4j runtime database must be tekg3, got {database or 'unknown'}")
    ok("Neo4j config resolves to tekg3")

    ping = scalar(config, "RETURN 1 AS ok", "ok")
    require(ping == 1, "Neo4j RETURN 1 did not return ok=1")
    ok("Neo4j RETURN 1 query works")

    te_count = scalar(config, "MATCH (t:TE) RETURN count(t) AS count", "count")
    relation_count = scalar(config, "MATCH ()-[r:BIO_RELATION]-() RETURN count(r) AS count", "count")
    require(te_count > 0, "Neo4j contains no TE nodes")
    require(relation_count > 0, "Neo4j contains no BIO_RELATION relationships")
    ok(f"Neo4j has {te_count} TE nodes and {relation_count} BIO_RELATION relationships")

    rows = neo4j_query(
        config,
        "MATCH (t:TE) WHERE t.name IN $names RETURN collect(t.name) AS names",
        {"names": REPRESENTATIVE_TE},
    )
    found = set(rows[0].get("names") or []) if rows else set()
    require(found, f"Neo4j did not resolve any representative TE names: {', '.join(REPRESENTATIVE_TE)}")
    ok(f"Representative TE names resolved: {', '.join(sorted(found))}")


if __name__ == "__main__":
    run_check(main)
