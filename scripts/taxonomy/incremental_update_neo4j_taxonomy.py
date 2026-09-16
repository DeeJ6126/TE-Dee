"""Incrementally add selected taxonomy leaves and refresh selected literature TE properties."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

if __package__ in (None, ""):
    sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.taxonomy.rebuild_neo4j_taxonomy import (  # noqa: E402
    ACTIVE_DATABASE,
    Neo4jClient,
    config_from_php,
    fetch_te_names,
)
from scripts.taxonomy.taxonomy_model import SOURCE_FILES, build_manifest, build_source_tree  # noqa: E402


ROOT = Path(__file__).resolve().parents[2]
TREE_DIR = ROOT / "data" / "taxonomy" / "transposon_tree"
NEW_TAXONOMY_NAMES = ("L1P", "MER44", "MER46", "MER47")
TE_NAMES = (
    "L1P", "LINE", "LTR", "MER44", "MER46", "MER47", "Merlin",
    "Penelope", "Retrotransposon", "SINE3", "retroposon",
)


def build_incremental_payload(manifest: dict[str, Any]) -> dict[str, Any]:
    nodes: list[dict[str, Any]] = []
    edges: list[dict[str, Any]] = []
    for source, tree in manifest["trees"].items():
        selected = {
            row["node_key"]: row
            for row in tree["nodes"]
            if row["name"] in NEW_TAXONOMY_NAMES and row["is_leaf"]
        }
        found_names = {row["name"] for row in selected.values()}
        if found_names != set(NEW_TAXONOMY_NAMES):
            raise RuntimeError(f"{source}: expected four selected leaves, found {sorted(found_names)}")
        selected_edges = [row for row in tree["edges"] if row["child_key"] in selected]
        if len(selected_edges) != len(selected):
            raise RuntimeError(f"{source}: each selected leaf must have exactly one parent")
        nodes.extend(selected.values())
        edges.extend(selected_edges)

    by_name = {row["original_name"]: row for row in manifest["te_updates"]}
    missing = sorted(set(TE_NAMES) - set(by_name))
    if missing:
        raise RuntimeError(f"Literature TE nodes missing from manifest: {missing}")
    te_rows = []
    for name in TE_NAMES:
        row = by_name[name]
        if row["properties"]["taxonomy_status"] == "unresolved":
            raise RuntimeError(f"Literature TE remains unresolved: {name}")
        te_rows.append({"name": name, **row["properties"]})
    return {"nodes": nodes, "edges": edges, "te_rows": te_rows}


def build_incremental_statements(payload: dict[str, Any]) -> list[dict[str, Any]]:
    return [
        {"statement": """
UNWIND $rows AS row
MERGE (n:TaxonomyNode {node_key: row.node_key})
SET n.source = row.source, n.name = row.name, n.raw_label = row.raw_label,
    n.rank = row.rank, n.depth = row.depth, n.is_leaf = row.is_leaf,
    n.path_key = row.path_key
""", "parameters": {"rows": payload["nodes"]}},
        {"statement": """
UNWIND $rows AS row
MATCH (parent:TaxonomyNode {node_key: row.parent_key})
MATCH (child:TaxonomyNode {node_key: row.child_key})
MERGE (parent)-[:TAXONOMY_PARENT_OF]->(child)
""", "parameters": {"rows": payload["edges"]}},
        {"statement": """
UNWIND $rows AS row
MATCH (t:TE {name: row.name})
SET t.taxonomy_source = row.taxonomy_source,
    t.taxonomy_status = row.taxonomy_status,
    t.taxonomy_class = row.taxonomy_class,
    t.taxonomy_subclass = row.taxonomy_subclass,
    t.taxonomy_order = row.taxonomy_order,
    t.taxonomy_superfamily = row.taxonomy_superfamily,
    t.taxonomy_family = row.taxonomy_family,
    t.taxonomy_subclade = row.taxonomy_subclade
""", "parameters": {"rows": payload["te_rows"]}},
    ]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--database", default=ACTIVE_DATABASE)
    parser.add_argument("--tree-dir", type=Path, default=TREE_DIR)
    parser.add_argument("--apply-active", action="store_true")
    args = parser.parse_args()
    if args.database != ACTIVE_DATABASE:
        parser.error("This targeted update is restricted to tekg3")

    client = Neo4jClient(config_from_php(), args.database)
    trees = {
        source: build_source_tree(args.tree_dir / filename, source)
        for source, filename in SOURCE_FILES.items()
    }
    manifest = build_manifest(fetch_te_names(client), trees)
    payload = build_incremental_payload(manifest)
    summary = {
        "taxonomy_nodes": len(payload["nodes"]),
        "taxonomy_edges": len(payload["edges"]),
        "te_updates": len(payload["te_rows"]),
        "sources": sorted({row["source"] for row in payload["nodes"]}),
    }
    if not args.apply_active:
        print(json.dumps({"dry_run": True, **summary}, ensure_ascii=False, indent=2))
        return 0

    before_te_count = client.query("MATCH (t:TE) RETURN count(t) AS count")[0]["count"]
    client.run(build_incremental_statements(payload))
    after_te_count = client.query("MATCH (t:TE) RETURN count(t) AS count")[0]["count"]
    if before_te_count != after_te_count:
        raise RuntimeError(f"TE node count changed: {before_te_count} -> {after_te_count}")
    print(json.dumps({"applied": True, **summary, "te_count": after_te_count}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
