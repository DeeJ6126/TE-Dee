import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
import re
import requests

from path_helpers import api_path, repo_path


CONFIG_PATH = api_path("config.local.php")
PLAN_PATH = repo_path("tekg_semantic_standardization_report.json")


MERGE_STATEMENT = """
MATCH (keep:%LABEL%)
WHERE keep.name = $canonical
MATCH (drop:%LABEL%)
WHERE drop.name = $drop
WITH keep, drop
WHERE elementId(keep) <> elementId(drop)
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
SET keep.description = CASE WHEN coalesce(keep.description, '') = '' THEN drop.description ELSE keep.description END
DETACH DELETE drop
"""


def load_config() -> dict[str, str]:
    text = CONFIG_PATH.read_text(encoding="utf-8")
    config: dict[str, str] = {}
    for key in ("neo4j_url", "neo4j_user", "neo4j_password"):
        match = re.search(rf"'{key}'\s*=>\s*'([^']*)'", text)
        if match:
            config[key] = match.group(1)
    return config


def run_statement(config: dict[str, str], statement: str, parameters: dict) -> dict:
    payload = {"statements": [{"statement": statement, "parameters": parameters}]}
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


def main() -> None:
    config = load_config()
    plan = json.loads(PLAN_PATH.read_text(encoding="utf-8"))
    executed = []
    for item in plan:
        statement = MERGE_STATEMENT.replace("%LABEL%", item["label"])
        for drop in item["drops"]:
            run_statement(config, statement, {"canonical": item["canonical"], "drop": drop})
            executed.append({"label": item["label"], "canonical": item["canonical"], "drop": drop})
    print(json.dumps({"executed": len(executed), "items": executed}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
