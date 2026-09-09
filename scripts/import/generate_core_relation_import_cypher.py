import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
from path_helpers import data_path, imports_path


INPUT_FILE = data_path("processed", "te_kg2_graph_seed.json")
OUTPUT_FILE = imports_path("neo4j", "import_core_relations.cypher")

LABEL_MAP = {
    "transposons": "TE",
    "diseases": "Disease",
    "functions": "Function",
    "papers": "Paper",
}


def cypher_string(value):
    return json.dumps(value, ensure_ascii=False)


def build_name_to_label(seed):
    mapping = {}
    for group, items in seed["nodes"].items():
        label = LABEL_MAP[group]
        for item in items:
            mapping[item["name"]] = label
    return mapping


def build_block(title, source_label, target_label, relations):
    lines = [f"// {title}", "UNWIND ["]
    payload_lines = []
    for rel in relations:
        payload_lines.append(
            "  {source: "
            + cypher_string(rel["source"])
            + ", predicate: "
            + cypher_string(rel["relation"])
            + ", target: "
            + cypher_string(rel["target"])
            + ", pmids: "
            + cypher_string(rel.get("pmids", []))
            + "}"
        )
    lines.append(",\n".join(payload_lines))
    lines.append("] AS row")
    lines.append(f"MATCH (s:{source_label} {{name: row.source}})")
    lines.append(f"MATCH (t:{target_label} {{name: row.target}})")
    lines.append("MERGE (s)-[r:BIO_RELATION {predicate: row.predicate}]->(t)")
    lines.append("SET r.pmids = row.pmids, r.source_group = 'core_relation';")
    lines.append("")
    return "\n".join(lines)


def main():
    seed = json.loads(INPUT_FILE.read_text(encoding="utf-8"))
    name_to_label = build_name_to_label(seed)

    te_to_function = []
    te_to_disease = []

    for rel in seed["relations"]:
        source_label = name_to_label.get(rel["source"])
        target_label = name_to_label.get(rel["target"])
        if source_label == "TE" and target_label == "Function":
            te_to_function.append(rel)
        elif source_label == "TE" and target_label == "Disease":
            te_to_disease.append(rel)

    blocks = [
        "// Generated from data/processed/te_kg2_graph_seed.json",
        "// Import core biological relations first.",
        "// Relationship type is kept stable as BIO_RELATION; original verb is stored in r.predicate.",
        "",
        build_block("Import TE -> Function relations", "TE", "Function", te_to_function),
        build_block("Import TE -> Disease relations", "TE", "Disease", te_to_disease),
    ]

    OUTPUT_FILE.write_text("\n".join(blocks), encoding="utf-8")
    print(f"Wrote: {OUTPUT_FILE}")
    print(f"TE->Function: {len(te_to_function)}")
    print(f"TE->Disease: {len(te_to_disease)}")


if __name__ == "__main__":
    main()
