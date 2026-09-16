"""Parse the two TE taxonomy trees and import them into active Neo4j tekg3.

Dry-run is read-only against Neo4j. Writes require an explicit flag.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import re
import sys
import urllib.error
import urllib.request
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

if __package__ in (None, ""):
    sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.taxonomy.taxonomy_model import SOURCE_FILES, build_manifest, build_source_tree


ROOT = Path(__file__).resolve().parents[2]
TREE_DIR = ROOT / "data" / "taxonomy" / "transposon_tree"
CONFIG_PATH = ROOT / "api" / "config.local.php"
DEFAULT_MANIFEST = ROOT / "data" / "processed" / "neo4j_taxonomy_import.json"
ACTIVE_DATABASE = "tekg3"
RETIRED_PROPERTIES = (
    "taxonomy_group", "taxonomy_canonical_name", "is_leaf_standard", "homepage_chart_included"
)
TAXONOMY_PROPERTIES = RETIRED_PROPERTIES + (
    "taxonomy_source", "taxonomy_status", "taxonomy_class", "taxonomy_subclass",
    "taxonomy_order", "taxonomy_superfamily", "taxonomy_family", "taxonomy_subclade",
)


def config_from_php() -> dict[str, str]:
    source = CONFIG_PATH.read_text(encoding="utf-8")
    config = {}
    for key in ("neo4j_url", "neo4j_user", "neo4j_password"):
        match = re.search(rf"'{key}'\s*=>\s*'([^']*)'", source)
        if not match or not match.group(1):
            raise RuntimeError(f"Missing {key} in local Neo4j config")
        config[key] = match.group(1)
    return config


def database_url(base_url: str, database: str) -> str:
    if not re.fullmatch(r"[A-Za-z][A-Za-z0-9_]*", database):
        raise ValueError(f"Unsafe Neo4j database name: {database!r}")
    if not re.search(r"/db/[^/]+/tx/commit$", base_url):
        raise ValueError("Neo4j URL must end with /db/<database>/tx/commit")
    return re.sub(r"/db/[^/]+/tx/commit$", f"/db/{database}/tx/commit", base_url)


class Neo4jClient:
    def __init__(self, config: dict[str, str], database: str):
        self.url = database_url(config["neo4j_url"], database)
        self.user = config["neo4j_user"]
        self.password = config["neo4j_password"]

    def run(self, statements: list[dict[str, Any]]) -> list[dict[str, Any]]:
        auth = base64.b64encode(f"{self.user}:{self.password}".encode("utf-8")).decode("ascii")
        request = urllib.request.Request(
            self.url,
            data=json.dumps({"statements": statements}, ensure_ascii=False).encode("utf-8"),
            headers={"Authorization": f"Basic {auth}", "Content-Type": "application/json"},
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=300) as response:
                payload = json.load(response)
        except (urllib.error.URLError, TimeoutError) as exc:
            raise RuntimeError(f"Neo4j connection failed for {self.url}: {exc}") from exc
        errors = payload.get("errors", [])
        if errors:
            raise RuntimeError("Neo4j rejected transaction: " + str(errors[0].get("message", errors[0])))
        return list(payload.get("results", []))

    def query(self, statement: str, parameters: dict[str, Any] | None = None) -> list[dict[str, Any]]:
        results = self.run([{"statement": statement, "parameters": parameters or {}}])
        if not results:
            return []
        columns = results[0].get("columns", [])
        return [dict(zip(columns, record.get("row", []))) for record in results[0].get("data", [])]


def te_name_digest(names: list[str]) -> str:
    return hashlib.sha256(json.dumps(sorted(names), ensure_ascii=False, separators=(",", ":")).encode("utf-8")).hexdigest()


def build_mutation_statements(manifest: dict[str, Any]) -> list[dict[str, Any]]:
    nodes = []
    edges = []
    for tree in manifest["trees"].values():
        nodes.extend({key: row[key] for key in ("node_key", "source", "name", "raw_label", "rank", "depth", "is_leaf", "path_key")} for row in tree["nodes"])
        edges.extend(tree["edges"])
    te_rows = []
    for row in manifest["te_updates"]:
        te_rows.append({"current_name": row["current_name"], **row["properties"]})
    return [
        {"statement": "MATCH (n:TaxonomyNode) DETACH DELETE n", "parameters": {}},
        {"statement": """
UNWIND $rows AS row
MERGE (n:TaxonomyNode {node_key: row.node_key})
SET n.source = row.source, n.name = row.name, n.raw_label = row.raw_label,
    n.rank = row.rank, n.depth = row.depth, n.is_leaf = row.is_leaf,
    n.path_key = row.path_key
""", "parameters": {"rows": nodes}},
        {"statement": """
UNWIND $rows AS row
MATCH (parent:TaxonomyNode {node_key: row.parent_key})
MATCH (child:TaxonomyNode {node_key: row.child_key})
MERGE (parent)-[:TAXONOMY_PARENT_OF]->(child)
""", "parameters": {"rows": edges}},
        {"statement": """
UNWIND $rows AS row
MATCH (t:TE {name: row.original_name})
WHERE NOT EXISTS {
  MATCH (other:TE {name: row.rename_to})
  WHERE other <> t
}
SET t.name = row.rename_to
""", "parameters": {"rows": manifest["rename_candidates"]}},
        {"statement": """
MATCH (t:TE)
REMOVE t.taxonomy_group, t.taxonomy_canonical_name,
       t.is_leaf_standard, t.homepage_chart_included,
       t.taxonomy_source, t.taxonomy_status, t.taxonomy_class,
       t.taxonomy_subclass, t.taxonomy_order, t.taxonomy_superfamily,
       t.taxonomy_family, t.taxonomy_subclade
""", "parameters": {}},
        {"statement": """
UNWIND $rows AS row
MATCH (t:TE {name: row.current_name})
SET t.taxonomy_source = row.taxonomy_source,
    t.taxonomy_status = row.taxonomy_status,
    t.taxonomy_class = row.taxonomy_class,
    t.taxonomy_subclass = row.taxonomy_subclass,
    t.taxonomy_order = row.taxonomy_order,
    t.taxonomy_superfamily = row.taxonomy_superfamily,
    t.taxonomy_family = row.taxonomy_family,
    t.taxonomy_subclade = row.taxonomy_subclade
""", "parameters": {"rows": te_rows}},
    ]


def fetch_te_names(client: Neo4jClient) -> list[str]:
    rows = client.query("MATCH (t:TE) RETURN t.name AS name ORDER BY name")
    names = [str(row["name"]) for row in rows if row.get("name")]
    if len(names) != len(set(names)):
        raise RuntimeError("Active Neo4j contains duplicate TE.name values")
    return names


def names_from_legacy_report(path: Path) -> list[str]:
    report = json.loads(path.read_text(encoding="utf-8"))
    final = report.get("final")
    if not isinstance(final, dict):
        raise ValueError(f"Legacy report has no final TE map: {path}")
    return sorted(final)


def manifest_for_names(names: list[str]) -> dict[str, Any]:
    trees = {source: build_source_tree(TREE_DIR / filename, source) for source, filename in SOURCE_FILES.items()}
    manifest = build_manifest(names, trees)
    manifest["metadata"]["te_name_sha256"] = te_name_digest(names)
    manifest["metadata"]["te_count"] = len(names)
    return manifest


def verify_input_hashes(manifest: dict[str, Any]) -> None:
    for source, filename in SOURCE_FILES.items():
        actual = hashlib.sha256((TREE_DIR / filename).read_bytes()).hexdigest()
        expected = manifest["metadata"]["source_sha256"][source]
        if actual != expected:
            raise RuntimeError(f"Taxonomy TXT changed after manifest creation: {filename}")


def save_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def report_summary(manifest: dict[str, Any], name_source: str) -> dict[str, Any]:
    return {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "name_source": name_source,
        "source_sha256": manifest["metadata"]["source_sha256"],
        "te_count": manifest["metadata"]["te_count"],
        "summary": manifest["summary"],
        "rename_candidates": manifest["rename_candidates"],
        "rename_conflicts": manifest["rename_conflicts"],
        "unresolved_te": [row["original_name"] for row in manifest["te_updates"] if row["properties"]["taxonomy_status"] == "unresolved"],
    }


def source_snapshot(client: Neo4jClient) -> dict[str, Any]:
    nodes = client.query(
        "MATCH (n) RETURN elementId(n) AS source_id, labels(n) AS labels, properties(n) AS props ORDER BY source_id"
    )
    relationships = client.query(
        "MATCH (a)-[r]->(b) RETURN elementId(a) AS src, elementId(b) AS dst, "
        "type(r) AS type, properties(r) AS props ORDER BY src, dst"
    )
    te_names = sorted(str(row["props"]["name"]) for row in nodes if "TE" in row["labels"])
    if len(te_names) != len(set(te_names)):
        raise RuntimeError("Source has duplicate TE.name values")
    label_counts = Counter(label for row in nodes for label in row["labels"])
    relationship_counts = Counter(row["type"] for row in relationships)
    return {
        "nodes": nodes,
        "relationships": relationships,
        "te_names": te_names,
        "label_counts": dict(sorted(label_counts.items())),
        "relationship_counts": dict(sorted(relationship_counts.items())),
    }


def prepare_taxonomy_schema(client: Neo4jClient) -> None:
    for statement in (
        "CREATE CONSTRAINT taxonomy_node_key IF NOT EXISTS FOR (n:TaxonomyNode) REQUIRE n.node_key IS UNIQUE",
        "CREATE INDEX taxonomy_node_source IF NOT EXISTS FOR (n:TaxonomyNode) ON (n.source)",
        "CREATE INDEX taxonomy_node_name IF NOT EXISTS FOR (n:TaxonomyNode) ON (n.name)",
    ):
        client.run([{"statement": statement, "parameters": {}}])


def verify_database(client: Neo4jClient, manifest: dict[str, Any]) -> dict[str, Any]:
    expected_counts = manifest["summary"]["tree_nodes"]
    actual_nodes = client.query(
        "MATCH (n:TaxonomyNode) RETURN n.source AS source, count(n) AS count ORDER BY source"
    )
    actual_counts = {row["source"]: row["count"] for row in actual_nodes}
    if actual_counts != expected_counts:
        raise RuntimeError(f"Taxonomy node count mismatch: {actual_counts} != {expected_counts}")
    actual_edges = client.query(
        "MATCH (a:TaxonomyNode)-[r:TAXONOMY_PARENT_OF]->(b:TaxonomyNode) "
        "RETURN a.source AS source, count(r) AS count ORDER BY source"
    )
    edge_counts = {row["source"]: row["count"] for row in actual_edges}
    if edge_counts != manifest["summary"]["tree_edges"]:
        raise RuntimeError("Taxonomy relationship counts differ from manifest")
    bad = client.query(
        "MATCH (n:TaxonomyNode) OPTIONAL MATCH (n)-[r]-(m) "
        "WHERE m IS NOT NULL AND (NOT m:TaxonomyNode OR m.source <> n.source) "
        "RETURN count(DISTINCT CASE WHEN n:TE THEN n END) AS dual_label, "
        "count(DISTINCT r) AS cross_relationship"
    )[0]
    if bad["dual_label"] or bad["cross_relationship"]:
        raise RuntimeError(f"Taxonomy isolation violated: {bad}")
    te_rows = client.query(
        "MATCH (t:TE) RETURN t.name AS name, t.taxonomy_source AS source, "
        "t.taxonomy_status AS status, t.taxonomy_order AS taxonomy_order, "
        "t.taxonomy_superfamily AS taxonomy_superfamily, "
        "t.taxonomy_family AS taxonomy_family, "
        "any(k IN keys(t) WHERE k IN $retired) AS has_retired ORDER BY name",
        {"retired": list(RETIRED_PROPERTIES)},
    )
    if len(te_rows) != len(manifest["te_updates"]):
        raise RuntimeError("Literature TE count changed during taxonomy migration")
    expected_te = {row["current_name"]: row["properties"] for row in manifest["te_updates"]}
    if {row["name"] for row in te_rows} != set(expected_te):
        raise RuntimeError("Literature TE names differ from approved rename map")
    for row in te_rows:
        expected = expected_te[row["name"]]
        if row["has_retired"] or row["source"] != expected["taxonomy_source"] or row["status"] != expected["taxonomy_status"]:
            raise RuntimeError(f"TE taxonomy status or retired-property mismatch: {row['name']}")
        for rank in ("order", "superfamily", "family"):
            if row[f"taxonomy_{rank}"] != expected[f"taxonomy_{rank}"]:
                raise RuntimeError(f"TE taxonomy path mismatch: {row['name']}.{rank}")
    return {"taxonomy_nodes": actual_counts, "taxonomy_edges": edge_counts, "te_count": len(te_rows), "isolation": bad}


def validate_manifest_against_database(client: Neo4jClient, manifest: dict[str, Any]) -> list[str]:
    verify_input_hashes(manifest)
    names = fetch_te_names(client)
    if te_name_digest(names) != manifest["metadata"]["te_name_sha256"]:
        raise RuntimeError("TE names changed after manifest creation; regenerate the dry-run")
    return names


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--database", default=ACTIVE_DATABASE)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--te-names-report", type=Path, help="Offline preview only; not proof of current Neo4j state")
    parser.add_argument("--manifest", type=Path, default=DEFAULT_MANIFEST)
    parser.add_argument("--tree-dir", type=Path, help="Verified TXT directory; default is the application data tree")
    parser.add_argument("--config-path", type=Path, help="Existing application config; credentials are never printed")
    parser.add_argument("--apply-active", action="store_true")
    args = parser.parse_args()
    if args.tree_dir is not None:
        global TREE_DIR
        TREE_DIR = args.tree_dir.resolve()
    if args.config_path is not None:
        global CONFIG_PATH
        CONFIG_PATH = args.config_path.resolve()
    if args.dry_run:
        if args.apply_active:
            parser.error("--dry-run cannot be combined with a write mode")
        if args.te_names_report:
            names = names_from_legacy_report(args.te_names_report)
            name_source = f"legacy_report:{args.te_names_report}"
        else:
            names = fetch_te_names(Neo4jClient(config_from_php(), args.database))
            name_source = f"neo4j:{args.database}"
        manifest = manifest_for_names(names)
        verify_input_hashes(manifest)
        save_json(args.manifest, manifest)
        print(json.dumps(report_summary(manifest, name_source), ensure_ascii=False, indent=2))
        return 0

    if args.te_names_report:
        parser.error("A legacy name report is allowed only for offline --dry-run")
    if not args.apply_active:
        parser.error("No database write mode selected; use --dry-run or --apply-active")
    if args.database != ACTIVE_DATABASE:
        parser.error("--apply-active is restricted to tekg3")
    manifest = json.loads(args.manifest.read_text(encoding="utf-8"))
    config = config_from_php()
    source_client = Neo4jClient(config, ACTIVE_DATABASE)
    validate_manifest_against_database(source_client, manifest)
    prepare_taxonomy_schema(source_client)
    source_client.run(build_mutation_statements(manifest))
    result = verify_database(source_client, manifest)
    print(json.dumps({"active_verified": True, "database": ACTIVE_DATABASE, "after": result}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
