import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
import re
import requests

from path_helpers import api_path, data_path, imports_path
from semantic_aliases import EXTRA_DISEASE_ALIASES, EXTRA_FUNCTION_ALIASES


CONFIG_PATH = api_path("config.local.php")
OUTPUT_CYPHER = imports_path("merge", "tekg_semantic_standardization_merge.cypher")
OUTPUT_REPORT = data_path("processed", "tekg_semantic_standardization_report.json")


def load_config() -> dict[str, str]:
    text = CONFIG_PATH.read_text(encoding="utf-8")
    config: dict[str, str] = {}
    for key in ("neo4j_url", "neo4j_user", "neo4j_password"):
        match = re.search(rf"'{key}'\s*=>\s*'([^']*)'", text)
        if match:
            config[key] = match.group(1)
    return config


def cypher_string(value: str) -> str:
    return json.dumps(value, ensure_ascii=False)


def normalize_key(text: str) -> str:
    value = str(text or "")
    value = value.replace("\u2019", "'").replace("\u2018", "'")
    value = value.replace("\u2010", "-").replace("\u2011", "-").replace("\u2012", "-")
    value = value.replace("\u2013", "-").replace("\u2014", "-").replace("\u2212", "-")
    value = value.casefold()
    return re.sub(r"[\s\-_'\"]+", "", value)


def query_names(label: str, config: dict[str, str]) -> list[str]:
    payload = {
        "statements": [
            {"statement": f"MATCH (n:{label}) RETURN DISTINCT n.name AS name ORDER BY name"}
        ]
    }
    response = requests.post(
        config["neo4j_url"],
        auth=(config["neo4j_user"], config["neo4j_password"]),
        json=payload,
        timeout=60,
    )
    response.raise_for_status()
    return [row["row"][0] for row in response.json()["results"][0]["data"] if row["row"][0]]


def build_merge_block(label: str, duplicate_name: str, canonical_name: str) -> str:
    return f"""// Merge {label}: {duplicate_name} -> {canonical_name}
MATCH (keep:{label})
WHERE keep.name = {cypher_string(canonical_name)}
MATCH (drop:{label})
WHERE drop.name = {cypher_string(duplicate_name)}
WITH keep, drop
WHERE id(keep) <> id(drop)
CALL {{
  WITH keep, drop
  MATCH (src)-[r:BIO_RELATION]->(drop)
  MERGE (src)-[r2:BIO_RELATION {{predicate: r.predicate}}]->(keep)
  SET r2.pmids = reduce(acc = coalesce(r2.pmids, []), x IN coalesce(r.pmids, []) | CASE WHEN x IN acc THEN acc ELSE acc + x END),
      r2.source_group = coalesce(r2.source_group, r.source_group)
  DELETE r
}}
CALL {{
  WITH keep, drop
  MATCH (drop)-[r:BIO_RELATION]->(dst)
  MERGE (keep)-[r2:BIO_RELATION {{predicate: r.predicate}}]->(dst)
  SET r2.pmids = reduce(acc = coalesce(r2.pmids, []), x IN coalesce(r.pmids, []) | CASE WHEN x IN acc THEN acc ELSE acc + x END),
      r2.source_group = coalesce(r2.source_group, r.source_group)
  DELETE r
}}
CALL {{
  WITH keep, drop
  MATCH (src)-[r:EVIDENCE_RELATION]->(drop)
  MERGE (src)-[r2:EVIDENCE_RELATION {{predicate: r.predicate}}]->(keep)
  SET r2.pmids = reduce(acc = coalesce(r2.pmids, []), x IN coalesce(r.pmids, []) | CASE WHEN x IN acc THEN acc ELSE acc + x END),
      r2.source_group = coalesce(r2.source_group, r.source_group)
  DELETE r
}}
SET keep.description = CASE WHEN coalesce(keep.description, '') = '' THEN drop.description ELSE keep.description END
DETACH DELETE drop;
"""


def build_merge_plan(existing_names: list[str], alias_map: dict[str, str], label: str) -> list[dict]:
    existing_set = set(existing_names)
    by_norm = {normalize_key(name): name for name in existing_names}
    grouped: dict[str, set[str]] = {}

    def choose_existing_canonical(canonical: str, alias_actual: str) -> str:
        canonical_norm = normalize_key(canonical)
        canonical_actual = by_norm.get(canonical_norm)
        if canonical_actual:
            return canonical_actual
        bucket = sorted(
            {
                name
                for name in existing_names
                if normalize_key(name) in {canonical_norm, normalize_key(alias_actual)}
            },
            key=lambda name: (len(name), name.casefold()),
            reverse=True,
        )
        if bucket:
            return bucket[0]
        return canonical

    for alias, canonical in alias_map.items():
        alias_norm = normalize_key(alias)
        alias_actual = by_norm.get(alias_norm)
        canonical_actual = choose_existing_canonical(canonical, alias_actual) if alias_actual else canonical
        if not alias_actual or alias_actual == canonical_actual:
            continue
        grouped.setdefault(canonical_actual, set()).add(alias_actual)

    plan = []
    for canonical, drops in sorted(grouped.items(), key=lambda item: item[0].casefold()):
        plan.append(
            {
                "label": label,
                "canonical": canonical,
                "drops": sorted(drops, key=str.casefold),
            }
        )
    return plan


def main() -> None:
    config = load_config()
    disease_names = query_names("Disease", config)
    function_names = query_names("Function", config)

    plan = build_merge_plan(disease_names, EXTRA_DISEASE_ALIASES, "Disease")
    plan += build_merge_plan(function_names, EXTRA_FUNCTION_ALIASES, "Function")

    blocks = ["// Generated semantic standardization merge cypher", ""]
    for item in plan:
        for drop in item["drops"]:
            blocks.append(build_merge_block(item["label"], drop, item["canonical"]))

    OUTPUT_CYPHER.write_text("\n".join(blocks), encoding="utf-8")
    OUTPUT_REPORT.write_text(json.dumps(plan, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Wrote: {OUTPUT_CYPHER}")
    print(f"Wrote: {OUTPUT_REPORT}")
    print(json.dumps({
        "Disease": sum(1 for item in plan if item["label"] == "Disease"),
        "Function": sum(1 for item in plan if item["label"] == "Function"),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
