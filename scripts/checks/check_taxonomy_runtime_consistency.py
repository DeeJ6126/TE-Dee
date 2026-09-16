from __future__ import annotations

import argparse
import base64
from collections import Counter
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.taxonomy.rebuild_neo4j_taxonomy import (
    Neo4jClient,
    database_url,
    verify_database,
    verify_input_hashes,
)


API_BASE = "http://127.0.0.1/tedee/api"
REPRESENTATIVE_TE = ["L1HS", "AluJb", "MER131", "SVA", "ERVL"]

RUNTIME_FILES_TO_SCAN = [
    "browse.php",
    "search.php",
    "api/agent/plugins/TreePlugin.php",
    "api/agent/orchestrator/EntityNormalizer.php",
    "assets/js/tekg_runtime_data.js",
    "assets/js/renderers/g6/index-g6-runtime.js",
    "assets/js/renderers/g6/index-g6-shared.js",
    "assets/js/renderers/g6/index-g6.bootstrap.js",
    "assets/js/renderers/g6/default-tree.js",
    "assets/js/renderers/g6/default-tree-mindmap.js",
    "assets/html/preview_graph.html",
]

LEGACY_PATTERNS = [
    "tree_te_lineage.json",
    "graph_demo_data.js",
    "GRAPH_DEMO_DATA",
    "tekg2_0413_tree_rmsk_repbase_lineage.json",
    "tekg2_0413_tree_all_lineage.json",
]


def read_local_config() -> dict[str, str]:
    path = ROOT / "api" / "config.local.php"
    text = path.read_text(encoding="utf-8")
    config: dict[str, str] = {}
    for key in ["neo4j_url", "neo4j_user", "neo4j_password"]:
        match = re.search(rf"'{re.escape(key)}'\s*=>\s*'([^']*)'", text)
        if match:
            config[key] = match.group(1)
    return config


def http_json(url: str, payload: dict[str, Any] | None = None, auth: tuple[str, str] | None = None) -> dict[str, Any]:
    data = None
    headers = {"Accept": "application/json"}
    method = "GET"
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
        method = "POST"
    if auth is not None:
        token = base64.b64encode(f"{auth[0]}:{auth[1]}".encode("ascii")).decode("ascii")
        headers["Authorization"] = f"Basic {token}"
    request = urllib.request.Request(url, data=data, headers=headers, method=method)
    with urllib.request.urlopen(request, timeout=20) as response:
        return json.loads(response.read().decode("utf-8"))


def neo4j_rows(config: dict[str, str], statement: str, parameters: dict[str, Any] | None = None) -> list[dict[str, Any]]:
    payload = {
        "statements": [
            {
                "statement": statement,
                "parameters": parameters or {},
            }
        ]
    }
    response = http_json(
        config["neo4j_url"],
        payload,
        (config["neo4j_user"], config["neo4j_password"]),
    )
    if response.get("errors"):
        raise RuntimeError(response["errors"][0].get("message", "Neo4j query failed"))
    result = response["results"][0]
    columns = result["columns"]
    rows: list[dict[str, Any]] = []
    for entry in result["data"]:
        rows.append(dict(zip(columns, entry["row"])))
    return rows


def taxonomy_path(row: dict[str, Any]) -> dict[str, Any]:
    return {
        "class": row.get("taxonomy_class"),
        "subclass": row.get("taxonomy_subclass"),
        "order": row.get("taxonomy_order"),
        "superfamily": row.get("taxonomy_superfamily"),
        "family": row.get("taxonomy_family"),
        "subclade": row.get("taxonomy_subclade"),
    }


def check_neo4j_taxonomy(config: dict[str, str], failures: list[str]) -> dict[str, dict[str, Any]]:
    rows = neo4j_rows(
        config,
        """
        MATCH (t:TE)
        WHERE t.name IN $names
        RETURN t.name AS name,
               t.taxonomy_status AS taxonomy_status,
               t.taxonomy_source AS taxonomy_source,
               t.taxonomy_class AS taxonomy_class,
               t.taxonomy_subclass AS taxonomy_subclass,
               t.taxonomy_order AS taxonomy_order,
               t.taxonomy_superfamily AS taxonomy_superfamily,
               t.taxonomy_family AS taxonomy_family,
               t.taxonomy_subclade AS taxonomy_subclade
        ORDER BY t.name
        """,
        {"names": REPRESENTATIVE_TE},
    )
    by_name = {str(row["name"]): row for row in rows}
    for name in REPRESENTATIVE_TE:
        row = by_name.get(name)
        if row is None:
            failures.append(f"Neo4j missing representative TE: {name}")
            continue
        if not row.get("taxonomy_status"):
            failures.append(f"Neo4j missing taxonomy_status for {name}")
        if not any(value for value in taxonomy_path(row).values()):
            failures.append(f"Neo4j missing taxonomy path for {name}")
    return by_name


def check_taxonomy_api(expected: dict[str, dict[str, Any]], failures: list[str]) -> None:
    url = f"{API_BASE}/taxonomy.php?names={urllib.parse.quote(','.join(REPRESENTATIVE_TE))}"
    try:
        payload = http_json(url)
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
        failures.append(f"taxonomy API request failed: {exc}")
        return
    if payload.get("ok") is not True:
        failures.append(f"taxonomy API returned non-ok payload: {payload}")
        return
    items = {str(item.get("name")): item for item in payload.get("items", [])}
    for name, row in expected.items():
        item = items.get(name)
        if item is None:
            failures.append(f"taxonomy API missing {name}")
            continue
        for key in ["taxonomy_status", "taxonomy_source"]:
            if item.get(key) != row.get(key):
                failures.append(f"taxonomy API mismatch for {name}.{key}: {item.get(key)!r} != {row.get(key)!r}")
        if item.get("path") != taxonomy_path(row):
            failures.append(f"taxonomy API path mismatch for {name}")


def check_graph_api(expected: dict[str, dict[str, Any]], failures: list[str]) -> None:
    for name, row in expected.items():
        try:
            payload = http_json(f"{API_BASE}/graph.php?q={urllib.parse.quote(name)}")
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
            failures.append(f"graph API request failed for {name}: {exc}")
            continue
        if payload.get("ok") is not True:
            failures.append(f"graph API returned non-ok payload for {name}: {payload}")
            continue
        node = next(
            (
                element.get("data", {})
                for element in payload.get("elements", [])
                if element.get("data", {}).get("rawLabel") == name or element.get("data", {}).get("label") == name
            ),
            None,
        )
        if node is None:
            failures.append(f"graph API missing TE node for {name}")
            continue
        taxonomy = node.get("taxonomy")
        if not isinstance(taxonomy, dict):
            failures.append(f"graph API missing taxonomy payload for {name}")
            continue
        if taxonomy.get("status") != row.get("taxonomy_status"):
            failures.append(f"graph API taxonomy status mismatch for {name}")
        if taxonomy.get("path") != taxonomy_path(row):
            failures.append(f"graph API taxonomy path mismatch for {name}")


def check_legacy_runtime_references(failures: list[str]) -> None:
    for rel_path in RUNTIME_FILES_TO_SCAN:
        path = ROOT / rel_path
        if not path.is_file():
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        for pattern in LEGACY_PATTERNS:
            if pattern in text:
                failures.append(f"legacy runtime taxonomy reference remains: {rel_path} contains {pattern}")


def check_homepage_uses_realtime_taxonomy(failures: list[str]) -> None:
    index_path = ROOT / "index.php"
    if not index_path.is_file():
        failures.append("index.php is missing")
        return
    text = index_path.read_text(encoding="utf-8", errors="replace")
    if "api/taxonomy_lib.php" not in text:
        failures.append("homepage ring chart does not load api/taxonomy_lib.php")
    if "tekg_taxonomy_homepage_payload(" not in text:
        failures.append("homepage ring chart does not build views from realtime Neo4j taxonomy")
    json_pos = text.find("tekg3_homepage_taxonomy.json")
    live_pos = text.find("tekg_taxonomy_homepage_payload(")
    if json_pos != -1 and (live_pos == -1 or json_pos < live_pos):
        failures.append("homepage ring chart reads JSON before realtime Neo4j taxonomy")


def check_taxonomy_tree_api(manifest: dict[str, Any], failures: list[str]) -> None:
    for source in ("rmsk_repbase", "all"):
        try:
            payload = http_json(f"{API_BASE}/taxonomy.php?view=tree&source={source}")
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
            failures.append(f"taxonomy tree API request failed for {source}: {exc}")
            continue
        if payload.get("ok") is not True:
            failures.append(f"taxonomy tree API returned non-ok for {source}")
            continue
        expected_nodes = {(row["raw_label"], row["depth"]) for row in manifest["trees"][source]["nodes"]}
        actual_nodes = {(row["original_label"], row["depth"]) for row in payload.get("nodes", [])}
        if actual_nodes != expected_nodes:
            failures.append(f"taxonomy tree API node set differs for {source}")
        names = {row["node_key"]: row["raw_label"] for row in manifest["trees"][source]["nodes"]}
        expected_edges = {(names[row["child_key"]], names[row["parent_key"]]) for row in manifest["trees"][source]["edges"]}
        actual_edges = {(row["child"], row["parent"]) for row in payload.get("edges", [])}
        if actual_edges != expected_edges:
            failures.append(f"taxonomy tree API edge set differs for {source}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--database", default="tekg3")
    parser.add_argument("--manifest", type=Path, default=ROOT / "data" / "processed" / "neo4j_taxonomy_import.json")
    args = parser.parse_args()
    failures: list[str] = []
    manifest = json.loads(args.manifest.read_text(encoding="utf-8"))
    verify_input_hashes(manifest)
    config = read_local_config()
    missing = [key for key in ["neo4j_url", "neo4j_user", "neo4j_password"] if not config.get(key)]
    if missing:
        failures.append(f"api/config.local.php missing keys: {', '.join(missing)}")
        expected: dict[str, dict[str, Any]] = {}
    else:
        config["neo4j_url"] = database_url(config["neo4j_url"], args.database)
        try:
            verify_database(Neo4jClient(config, args.database), manifest)
        except (RuntimeError, ValueError) as exc:
            failures.append(str(exc))
        expected = check_neo4j_taxonomy(config, failures)
    if expected and args.database == "tekg3":
        check_taxonomy_api(expected, failures)
        check_graph_api(expected, failures)
        check_taxonomy_tree_api(manifest, failures)
    check_homepage_uses_realtime_taxonomy(failures)
    check_legacy_runtime_references(failures)

    if failures:
        print("FAIL taxonomy runtime consistency")
        for failure in failures:
            print(f"- {failure}")
        return 1
    print("PASS taxonomy runtime consistency")
    for name in REPRESENTATIVE_TE:
        print(f"- {name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
