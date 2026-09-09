import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import csv
import hashlib
import json
from collections import defaultdict

from disease_top_class import normalize_compare_key
from path_helpers import ROOT, imports_path

TMP_DIR = ROOT / "archive" / "processing_history" / "tmp_icd11_csv"
IMPORTS_DIR = imports_path("disease")
PROCESSED_DIR = ROOT / "data" / "processed"

PATHS_CSV = TMP_DIR / "disease_classification_paths.csv"

OUT_NODES = IMPORTS_DIR / "import_disease_category_nodes.cypher"
OUT_CATEGORY_EDGES = IMPORTS_DIR / "import_disease_category_edges.cypher"
OUT_DISEASE_EDGES = IMPORTS_DIR / "import_disease_to_category_edges.cypher"
OUT_REPORT = PROCESSED_DIR / "disease_classification_import_report.json"


def cypher_string(value: str) -> str:
    return json.dumps(value, ensure_ascii=False)


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def make_id(prefix: str, parts: list[str]) -> str:
    key = " | ".join(parts)
    digest = hashlib.sha1(key.encode("utf-8")).hexdigest()[:12]
    return f"{prefix}_{digest}"


def build_found_priority_name_map(rows: list[dict[str, str]]) -> dict[tuple[str, str], str]:
    grouped: dict[tuple[str, str], list[dict[str, str]]] = defaultdict(list)
    for row in rows:
        disease = row["disease"].strip()
        leaf = row["category_1"].strip()
        if not disease or not leaf:
            continue
        grouped[(normalize_compare_key(disease), leaf)].append(row)

    resolved: dict[tuple[str, str], str] = {}
    for key, items in grouped.items():
        found_items = [item for item in items if item["source_status"].strip() == "found"]
        if found_items:
            resolved[key] = found_items[0]["disease"].strip()
    return resolved


def build_import_rows(
    rows: list[dict[str, str]]
) -> tuple[list[dict[str, str]], list[dict[str, str]], list[dict[str, str]]]:
    found_priority_name_map = build_found_priority_name_map(rows)
    category_nodes: dict[str, dict[str, str]] = {}
    category_edges: dict[tuple[str, str], dict[str, str]] = {}
    disease_edges: dict[tuple[str, str, str, str], dict[str, str]] = {}

    for row in rows:
        disease = row["disease"].strip()
        source_status = row["source_status"].strip()
        source_row = row["source_row"].strip()
        description = row["description"].strip()
        path_parts_leaf_to_top = [
            row[f"category_{i}"].strip()
            for i in range(1, 9)
            if row[f"category_{i}"].strip()
        ]
        if not disease or not path_parts_leaf_to_top:
            continue

        path_parts_top_to_leaf = list(reversed(path_parts_leaf_to_top))
        parent_id = ""
        for level, label in enumerate(path_parts_top_to_leaf, start=1):
            prefix_parts = path_parts_top_to_leaf[:level]
            node_id = make_id("dcat", prefix_parts)
            category_nodes[node_id] = {
                "category_node_id": node_id,
                "category_label": label,
                "category_level": str(level),
                "path_key": " | ".join(prefix_parts),
                "top_category": path_parts_top_to_leaf[0],
                "is_leaf": "true" if level == len(path_parts_top_to_leaf) else "false",
            }
            if parent_id:
                edge_key = (parent_id, node_id)
                category_edges[edge_key] = {
                    "parent_category_node_id": parent_id,
                    "child_category_node_id": node_id,
                    "relation_type": "HAS_SUBCATEGORY",
                }
            parent_id = node_id

        leaf_id = parent_id
        found_priority_key = (normalize_compare_key(disease), path_parts_leaf_to_top[0])
        resolved_disease = found_priority_name_map.get(found_priority_key, disease)
        disease_edges[(resolved_disease, leaf_id, source_status, source_row)] = {
            "disease": resolved_disease,
            "source_disease_name": disease,
            "leaf_category_node_id": leaf_id,
            "leaf_category_label": path_parts_leaf_to_top[0],
            "top_category": path_parts_top_to_leaf[0],
            "path_depth": str(len(path_parts_leaf_to_top)),
            "source_status": source_status,
            "source_row": source_row,
            "description": description,
        }

    return (
        sorted(category_nodes.values(), key=lambda r: (int(r["category_level"]), r["path_key"])),
        sorted(category_edges.values(), key=lambda r: (r["parent_category_node_id"], r["child_category_node_id"])),
        sorted(disease_edges.values(), key=lambda r: (r["disease"].lower(), r["leaf_category_label"], r["source_status"], r["source_row"])),
    )


def render_unwind(rows: list[str]) -> str:
    return ",\n".join(rows)


def build_category_nodes(rows: list[dict[str, str]]) -> str:
    payload = []
    for row in rows:
        payload.append(
            "  {category_node_id: "
            + cypher_string(row["category_node_id"])
            + ", category_label: "
            + cypher_string(row["category_label"])
            + ", category_level: "
            + str(int(row["category_level"]))
            + ", path_key: "
            + cypher_string(row["path_key"])
            + ", top_category: "
            + cypher_string(row["top_category"])
            + ", is_leaf: "
            + ("true" if row["is_leaf"].strip().lower() == "true" else "false")
            + "}"
        )
    return "\n".join(
        [
            "// Generated from tmp_icd11_csv/disease_category_nodes.csv",
            "UNWIND [",
            render_unwind(payload),
            "] AS row",
            "MERGE (n:DiseaseCategory {category_node_id: row.category_node_id})",
            "SET n.name = row.category_label,",
            "    n.category_label = row.category_label,",
            "    n.category_level = row.category_level,",
            "    n.path_key = row.path_key,",
            "    n.top_category = row.top_category,",
            "    n.is_leaf = row.is_leaf,",
            "    n.source_group = \"icd11_disease_classification\";",
            "",
        ]
    )


def build_category_edges(rows: list[dict[str, str]]) -> str:
    payload = []
    for row in rows:
        payload.append(
            "  {parent_category_node_id: "
            + cypher_string(row["parent_category_node_id"])
            + ", child_category_node_id: "
            + cypher_string(row["child_category_node_id"])
            + "}"
        )
    return "\n".join(
        [
            "// Generated from tmp_icd11_csv/disease_category_edges.csv",
            "UNWIND [",
            render_unwind(payload),
            "] AS row",
            "MATCH (parent:DiseaseCategory {category_node_id: row.parent_category_node_id})",
            "MATCH (child:DiseaseCategory {category_node_id: row.child_category_node_id})",
            "MERGE (parent)-[r:HAS_SUBCATEGORY]->(child)",
            "SET r.source_group = \"icd11_disease_classification\";",
            "",
        ]
    )


def build_disease_edges(rows: list[dict[str, str]]) -> str:
    payload = []
    for row in rows:
        payload.append(
            "  {disease: "
            + cypher_string(row["disease"])
            + ", source_disease_name: "
            + cypher_string(row.get("source_disease_name", row["disease"]))
            + ", leaf_category_node_id: "
            + cypher_string(row["leaf_category_node_id"])
            + ", leaf_category_label: "
            + cypher_string(row["leaf_category_label"])
            + ", top_category: "
            + cypher_string(row["top_category"])
            + ", path_depth: "
            + str(int(row["path_depth"]))
            + ", source_status: "
            + cypher_string(row["source_status"])
            + ", source_row: "
            + cypher_string(row["source_row"])
            + ", description: "
            + cypher_string(row["description"])
            + "}"
        )
    return "\n".join(
        [
            "// Generated from tmp_icd11_csv/disease_to_category_edges.csv",
            "UNWIND [",
            render_unwind(payload),
            "] AS row",
            "MERGE (d:Disease {name: row.disease})",
            "ON CREATE SET d.description = row.description,",
            "              d.source_group = \"diseases_classification_only\"",
            "MATCH (c:DiseaseCategory {category_node_id: row.leaf_category_node_id})",
            "MERGE (d)-[r:CLASSIFIED_AS]->(c)",
            "SET r.leaf_category_label = row.leaf_category_label,",
            "    r.top_category = row.top_category,",
            "    r.path_depth = row.path_depth,",
            "    r.source_status = row.source_status,",
            "    r.source_row = row.source_row,",
            "    r.source_disease_name = row.source_disease_name,",
            "    r.source_group = \"icd11_disease_classification\";",
            "",
        ]
    )


def main() -> None:
    path_rows = read_csv(PATHS_CSV)
    category_nodes, category_edges, disease_edges = build_import_rows(path_rows)

    OUT_NODES.write_text(build_category_nodes(category_nodes), encoding="utf-8")
    OUT_CATEGORY_EDGES.write_text(build_category_edges(category_edges), encoding="utf-8")
    OUT_DISEASE_EDGES.write_text(build_disease_edges(disease_edges), encoding="utf-8")

    report = {
        "source_files": {
            "paths_csv": str(PATHS_CSV.relative_to(ROOT)),
        },
        "generated_files": {
            "category_nodes_cypher": str(OUT_NODES.relative_to(ROOT)),
            "category_edges_cypher": str(OUT_CATEGORY_EDGES.relative_to(ROOT)),
            "disease_edges_cypher": str(OUT_DISEASE_EDGES.relative_to(ROOT)),
        },
        "counts": {
            "paths_csv": len(path_rows),
            "category_nodes_payload": len(category_nodes),
            "category_edges_payload": len(category_edges),
            "disease_edges_payload": len(disease_edges),
        },
        "coverage_ok": len(disease_edges) == len(path_rows),
    }
    OUT_REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Wrote: {OUT_NODES}")
    print(f"Wrote: {OUT_CATEGORY_EDGES}")
    print(f"Wrote: {OUT_DISEASE_EDGES}")
    print(f"Wrote: {OUT_REPORT}")


if __name__ == "__main__":
    main()
