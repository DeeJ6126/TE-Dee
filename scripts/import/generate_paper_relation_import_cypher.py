import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
from path_helpers import data_path, imports_path


INPUT_FILE = data_path("processed", "te_kg2_graph_seed.json")
OUTPUT_FILE = imports_path("neo4j", "import_paper_relations.cypher")

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


def build_paper_title_to_pmids(seed):
    mapping = {}
    for item in seed["nodes"]["papers"]:
        title = item["name"]
        pmid = item.get("pmid", "")
        if not title or not pmid:
            continue
        mapping.setdefault(title, []).append(pmid)
    return mapping


def build_block(title, target_label, relations, paper_title_to_pmids):
    lines = [f"// {title}", "UNWIND ["]
    payload_lines = []
    for rel in relations:
        pmids = rel.get("pmids", [])
        paper_pmids = paper_title_to_pmids.get(rel["source"], [])
        if pmids:
            matched_pmids = [pmid for pmid in pmids if pmid in paper_pmids]
        else:
            matched_pmids = list(paper_pmids)
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
    seed = json.loads(INPUT_FILE.read_text(encoding="utf-8"))
    name_to_label = build_name_to_label(seed)
    paper_title_to_pmids = build_paper_title_to_pmids(seed)

    paper_to_te = []
    paper_to_disease = []
    paper_to_function = []

    for rel in seed["relations"]:
        source_label = name_to_label.get(rel["source"])
        target_label = name_to_label.get(rel["target"])
        if source_label != "Paper":
            continue
        if target_label == "TE":
            paper_to_te.append(rel)
        elif target_label == "Disease":
            paper_to_disease.append(rel)
        elif target_label == "Function":
            paper_to_function.append(rel)

    blocks = [
        "// Generated from data/processed/te_kg2_graph_seed.json",
        "// Import evidence relations from Paper nodes to entity nodes.",
        "",
        build_block("Import Paper -> TE relations", "TE", paper_to_te, paper_title_to_pmids),
        build_block("Import Paper -> Disease relations", "Disease", paper_to_disease, paper_title_to_pmids),
        build_block("Import Paper -> Function relations", "Function", paper_to_function, paper_title_to_pmids),
    ]

    OUTPUT_FILE.write_text("\n".join(blocks), encoding="utf-8")
    print(f"Wrote: {OUTPUT_FILE}")
    print(f"Paper->TE: {len(paper_to_te)}")
    print(f"Paper->Disease: {len(paper_to_disease)}")
    print(f"Paper->Function: {len(paper_to_function)}")


if __name__ == "__main__":
    main()
