import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
import re
from collections import defaultdict
import requests

from path_helpers import api_path, data_path, imports_path
from normalize.normalize_te_kg2_graph import DISEASE_ALIASES, FUNCTION_ALIASES


CONFIG_PATH = api_path("config.local.php")
OUTPUT_CYPHER = imports_path("merge", "tekg_exact_duplicate_merge.cypher")
OUTPUT_REPORT = data_path("processed", "tekg_exact_duplicate_report.json")


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
    value = re.sub(r"[\s\-_'\"]+", "", value)
    return value


def has_garbled_chars(text: str) -> bool:
    return bool(re.search(r"[桶湛汕艣艢脕鈥€]", text))


def query_names(label: str, config: dict[str, str]) -> list[str]:
    payload = {
        "statements": [
            {
                "statement": f"MATCH (n:{label}) RETURN DISTINCT n.name AS name ORDER BY name"
            }
        ]
    }
    response = requests.post(
        config["neo4j_url"],
        auth=(config["neo4j_user"], config["neo4j_password"]),
        json=payload,
        timeout=30,
    )
    response.raise_for_status()
    data = response.json()
    return [row["row"][0] for row in data["results"][0]["data"] if row["row"][0]]


def choose_canonical(label: str, names: list[str]) -> str:
    alias_values = set(DISEASE_ALIASES.values() if label == "Disease" else FUNCTION_ALIASES.values())

    def score(name: str) -> tuple[int, int, int, str]:
        alias_bonus = 10 if name in alias_values else 0
        garble_penalty = -10 if has_garbled_chars(name) else 0
        if label == "Disease":
            case_bonus = 2 if name[:1].isupper() else 0
        else:
            case_bonus = 2 if name == name.lower() else 0
        return (alias_bonus + garble_penalty + case_bonus, -len(name), 0, name.casefold())

    return sorted(names, key=score, reverse=True)[0]


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


def find_duplicate_groups(label: str, names: list[str]) -> list[dict]:
    groups: dict[str, list[str]] = defaultdict(list)
    for name in names:
        groups[normalize_key(name)].append(name)

    result = []
    for key, bucket in groups.items():
        unique_bucket = sorted(set(bucket), key=str.casefold)
        if len(unique_bucket) < 2:
            continue
        canonical = choose_canonical(label, unique_bucket)
        drops = [name for name in unique_bucket if name != canonical]
        result.append(
            {
                "label": label,
                "key": key,
                "canonical": canonical,
                "drops": drops,
                "names": unique_bucket,
            }
        )
    return sorted(result, key=lambda item: (item["label"], item["canonical"].casefold(), item["key"]))


def main() -> None:
    config = load_config()
    disease_names = query_names("Disease", config)
    function_names = query_names("Function", config)

    groups = find_duplicate_groups("Disease", disease_names) + find_duplicate_groups("Function", function_names)

    blocks = ["// Generated exact duplicate merge cypher from current tekg database", ""]
    for group in groups:
        for drop in group["drops"]:
            blocks.append(build_merge_block(group["label"], drop, group["canonical"]))

    OUTPUT_CYPHER.write_text("\n".join(blocks), encoding="utf-8")
    OUTPUT_REPORT.write_text(json.dumps(groups, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"Wrote: {OUTPUT_CYPHER}")
    print(f"Wrote: {OUTPUT_REPORT}")
    print(f"Duplicate groups: {len(groups)}")
    print(
        json.dumps(
            {
                "Disease": sum(1 for group in groups if group["label"] == "Disease"),
                "Function": sum(1 for group in groups if group["label"] == "Function"),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
