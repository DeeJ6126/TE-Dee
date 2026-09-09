import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
from path_helpers import data_path, imports_path


INPUT_FILE = data_path("processed", "te_kg2_graph_seed.json")
OUTPUT_FILE = imports_path("neo4j", "import_graph_nodes.cypher")


LABEL_MAP = {
    "transposons": "TE",
    "diseases": "Disease",
    "functions": "Function",
    "papers": "Paper",
}


def cypher_string(value: str) -> str:
    return json.dumps(value, ensure_ascii=False)


def build_block(group_name: str, label: str, items: list[dict]) -> str:
    lines = [f"// Import {group_name}", "UNWIND ["]
    payload_lines = []
    if group_name == "papers":
        for item in items:
            payload_lines.append(
                "  {pmid: "
                + cypher_string(item.get("pmid", ""))
                + ", name: "
                + cypher_string(item.get("name", ""))
                + ", description: "
                + cypher_string(item.get("description", ""))
                + "}"
            )
        lines.append(",\n".join(payload_lines))
        lines.append(f"] AS row\nMERGE (n:{label} {{pmid: row.pmid}})")
        lines.append(
            "ON CREATE SET n.name = row.name, n.description = row.description, "
            + f"n.source_group = {cypher_string(group_name)};"
        )
    else:
        for item in items:
            payload = (
                "  {name: "
                + cypher_string(item.get("name", ""))
                + ", description: "
                + cypher_string(item.get("description", ""))
            )
            if group_name == "diseases":
                payload += ", disease_class: " + cypher_string(item.get("disease_class", ""))
            payload += "}"
            payload_lines.append(payload)
        lines.append(",\n".join(payload_lines))
        lines.append(f"] AS row\nMERGE (n:{label} {{name: row.name}})")
        lines.append("SET n.description = row.description, " + f"n.source_group = {cypher_string(group_name)}")
        if group_name == "diseases":
            lines[-1] += ", n.disease_class = row.disease_class;"
        else:
            lines[-1] += ";"
    lines.append("")
    return "\n".join(lines)


def main() -> None:
    seed = json.loads(INPUT_FILE.read_text(encoding="utf-8"))
    node_groups = seed["nodes"]

    blocks = [
        "// Generated from data/processed/te_kg2_graph_seed.json",
        "// Import deduplicated entity nodes before importing relations.",
        "",
    ]

    for group_name in ("transposons", "diseases", "functions", "papers"):
        blocks.append(build_block(group_name, LABEL_MAP[group_name], node_groups[group_name]))

    OUTPUT_FILE.write_text("\n".join(blocks), encoding="utf-8")
    print(f"Wrote: {OUTPUT_FILE}")


if __name__ == "__main__":
    main()
