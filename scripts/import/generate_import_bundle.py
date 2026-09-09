import json
import sys
from pathlib import Path


LABEL_MAP = {
    "transposons": "TE",
    "diseases": "Disease",
    "functions": "Function",
    "papers": "Paper",
}


def cypher_string(value):
    return json.dumps(value, ensure_ascii=False)


def build_nodes_block(group_name: str, label: str, items: list[dict]) -> str:
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


def build_name_to_label(seed):
    mapping = {}
    for group, items in seed["nodes"].items():
        label = LABEL_MAP[group]
        for item in items:
            mapping[item["name"]] = label
    return mapping


def build_paper_title_to_pmids(seed):
    mapping = {}
    for item in seed["nodes"]["papers"]:
        title = item["name"]
        pmid = item.get("pmid", "")
        if not title or not pmid:
            continue
        mapping.setdefault(title, []).append(pmid)
    return mapping


def build_core_block(title, source_label, target_label, relations):
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


def build_paper_block(title, target_label, relations, paper_title_to_pmids):
    lines = [f"// {title}", "UNWIND ["]
    payload_lines = []
    for rel in relations:
        pmids = rel.get("pmids", [])
        paper_pmids = paper_title_to_pmids.get(rel["source"], [])
        matched_pmids = [pmid for pmid in pmids if pmid in paper_pmids] if pmids else list(paper_pmids)
        if not matched_pmids and paper_pmids:
            matched_pmids = list(paper_pmids)
        payload_lines.append(
            "  {paper_pmids: "
            + cypher_string(matched_pmids)
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
    lines.append("UNWIND row.paper_pmids AS paper_pmid")
    lines.append("MATCH (p:Paper {pmid: paper_pmid})")
    lines.append(f"MATCH (t:{target_label} {{name: row.target}})")
    lines.append("MERGE (p)-[r:EVIDENCE_RELATION {predicate: row.predicate}]->(t)")
    lines.append("SET r.pmids = row.pmids, r.source_group = 'paper_relation';")
    lines.append("")
    return "\n".join(lines)


def main():
    if len(sys.argv) < 3:
        raise SystemExit("Usage: python generate_import_bundle.py <seed.json> <output_prefix>")

    input_file = Path(sys.argv[1])
    prefix = Path(sys.argv[2])
    seed = json.loads(input_file.read_text(encoding="utf-8"))
    name_to_label = build_name_to_label(seed)
    paper_title_to_pmids = build_paper_title_to_pmids(seed)

    nodes_output = prefix.with_name(prefix.name + "_graph_nodes.cypher")
    core_output = prefix.with_name(prefix.name + "_core_relations.cypher")
    paper_output = prefix.with_name(prefix.name + "_paper_relations.cypher")

    node_blocks = [
        f"// Generated from {input_file.name}",
        "// Import deduplicated entity nodes before importing relations.",
        "",
    ]
    for group_name in ("transposons", "diseases", "functions", "papers"):
        node_blocks.append(build_nodes_block(group_name, LABEL_MAP[group_name], seed["nodes"][group_name]))
    nodes_output.write_text("\n".join(node_blocks), encoding="utf-8")

    core_groups = {}
    paper_to_te = []
    paper_to_disease = []
    paper_to_function = []
    for rel in seed["relations"]:
        source_label = name_to_label.get(rel["source"])
        target_label = name_to_label.get(rel["target"])
        if source_label == "Paper":
            if target_label == "TE":
                paper_to_te.append(rel)
            elif target_label == "Disease":
                paper_to_disease.append(rel)
            elif target_label == "Function":
                paper_to_function.append(rel)
            continue
        if source_label and target_label:
            core_groups.setdefault((source_label, target_label), []).append(rel)

    core_blocks = [
        f"// Generated from {input_file.name}",
        "// Import core biological relations.",
        "",
    ]
    for (source_label, target_label), relations in sorted(core_groups.items()):
        if source_label == "Paper":
            continue
        title = f"Import {source_label} -> {target_label} relations"
        core_blocks.append(build_core_block(title, source_label, target_label, relations))
    core_output.write_text("\n".join(core_blocks), encoding="utf-8")

    paper_blocks = [
        f"// Generated from {input_file.name}",
        "// Import evidence relations from Paper nodes to entity nodes.",
        "",
        build_paper_block("Import Paper -> TE relations", "TE", paper_to_te, paper_title_to_pmids),
        build_paper_block("Import Paper -> Disease relations", "Disease", paper_to_disease, paper_title_to_pmids),
        build_paper_block("Import Paper -> Function relations", "Function", paper_to_function, paper_title_to_pmids),
    ]
    paper_output.write_text("\n".join(paper_blocks), encoding="utf-8")

    print(f"Wrote: {nodes_output}")
    print(f"Wrote: {core_output}")
    print(f"Wrote: {paper_output}")
    print(f"Core relation groups: {len([k for k in core_groups if k[0] != 'Paper'])}")


if __name__ == "__main__":
    main()
