import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
import re
import requests

from path_helpers import api_path


CONFIG_PATH = api_path("config.local.php")

TE_CANONICAL_MAP = {
    "LINE-1": "LINE1",
    "L1": "LINE1",
    "DNA transposon": "DNA Transposon",
    "Endogenous retrovirus": "Endogenous Retrovirus",
    "Mer61": "MER61",
    "PiggyBac": "piggyBac",
}


def load_config() -> dict[str, str]:
    text = CONFIG_PATH.read_text(encoding="utf-8")
    config: dict[str, str] = {}
    for key in ("neo4j_url", "neo4j_user", "neo4j_password"):
        match = re.search(rf"'{key}'\s*=>\s*'([^']*)'", text)
        if match:
            config[key] = match.group(1)
    return config


def run_statement(config: dict[str, str], statement: str, parameters: dict | None = None) -> dict:
    payload = {"statements": [{"statement": statement, "parameters": parameters or {}}]}
    response = requests.post(
        config["neo4j_url"],
        auth=(config["neo4j_user"], config["neo4j_password"]),
        json=payload,
        timeout=180,
    )
    response.raise_for_status()
    data = response.json()
    if data.get("errors"):
        raise RuntimeError(json.dumps(data["errors"], ensure_ascii=False))
    return data


def merge_te_name(config: dict[str, str], drop: str, canonical: str) -> None:
    statement = """
MERGE (keep:TE {name: $canonical})
WITH keep
MATCH (drop:TE {name: $drop})
WITH keep, drop
WHERE elementId(keep) <> elementId(drop)
CALL {
  WITH keep, drop
  MATCH (src)-[r:SUBFAMILY_OF]->(drop)
  MERGE (src)-[:SUBFAMILY_OF]->(keep)
  DELETE r
}
CALL {
  WITH keep, drop
  MATCH (drop)-[r:SUBFAMILY_OF]->(dst)
  MERGE (keep)-[:SUBFAMILY_OF]->(dst)
  DELETE r
}
CALL {
  WITH keep, drop
  MATCH (src)-[r:BIO_RELATION]->(drop)
  MERGE (src)-[r2:BIO_RELATION {predicate: r.predicate}]->(keep)
  SET r2.pmids = reduce(acc = coalesce(r2.pmids, []), x IN coalesce(r.pmids, []) | CASE WHEN x IN acc THEN acc ELSE acc + x END),
      r2.source_group = coalesce(r2.source_group, r.source_group)
  DELETE r
}
CALL {
  WITH keep, drop
  MATCH (drop)-[r:BIO_RELATION]->(dst)
  MERGE (keep)-[r2:BIO_RELATION {predicate: r.predicate}]->(dst)
  SET r2.pmids = reduce(acc = coalesce(r2.pmids, []), x IN coalesce(r.pmids, []) | CASE WHEN x IN acc THEN acc ELSE acc + x END),
      r2.source_group = coalesce(r2.source_group, r.source_group)
  DELETE r
}
CALL {
  WITH keep, drop
  MATCH (src)-[r:EVIDENCE_RELATION]->(drop)
  MERGE (src)-[r2:EVIDENCE_RELATION {predicate: r.predicate}]->(keep)
  SET r2.pmids = reduce(acc = coalesce(r2.pmids, []), x IN coalesce(r.pmids, []) | CASE WHEN x IN acc THEN acc ELSE acc + x END),
      r2.source_group = coalesce(r2.source_group, r.source_group)
  DELETE r
}
CALL {
  WITH keep, drop
  MATCH (drop)-[r:EVIDENCE_RELATION]->(dst)
  MERGE (keep)-[r2:EVIDENCE_RELATION {predicate: r.predicate}]->(dst)
  SET r2.pmids = reduce(acc = coalesce(r2.pmids, []), x IN coalesce(r.pmids, []) | CASE WHEN x IN acc THEN acc ELSE acc + x END),
      r2.source_group = coalesce(r2.source_group, r.source_group)
  DELETE r
}
SET keep.description = CASE WHEN coalesce(keep.description, '') = '' THEN drop.description ELSE keep.description END
DETACH DELETE drop
"""
    run_statement(config, statement, {"canonical": canonical, "drop": drop})


def cleanup_te_relations(config: dict[str, str]) -> None:
    statements = [
        "MATCH (a:TE)-[r:SUBFAMILY_OF]->(a) DELETE r",
        """
MATCH (a:TE)-[r1:SUBFAMILY_OF]->(b:TE)
WITH a, b, collect(r1) AS rels
WHERE size(rels) > 1
FOREACH (r IN tail(rels) | DELETE r)
""",
    ]
    for statement in statements:
        run_statement(config, statement)


def main() -> None:
    config = load_config()
    executed = []
    for drop, canonical in TE_CANONICAL_MAP.items():
        merge_te_name(config, drop, canonical)
        executed.append({"drop": drop, "canonical": canonical})
    cleanup_te_relations(config)
    print(json.dumps({"executed": executed}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
