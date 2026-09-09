from __future__ import annotations

import json
import re
import sys
from collections import Counter, defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import requests

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

from path_helpers import api_path, data_path, taxonomy_path  # noqa: E402


CONFIG_PATH = api_path("config.local.php")
REPORT_PATH = data_path("processed", "tekg3_taxonomy_standardization_report.json")
HOMEPAGE_STATS_PATH = data_path("processed", "tekg3_homepage_taxonomy.json")
SOURCE_DB = "tekg21"
TARGET_DB = "tekg3"
BATCH_SIZE = 500

RANK_PATTERNS: tuple[tuple[str, re.Pattern[str]], ...] = (
    ("class", re.compile(r"^Class [^:]+:\s*(.+)$")),
    ("subclass", re.compile(r"^Subclass [^:]+:\s*(.+)$")),
    ("order", re.compile(r"^Order:\s*(.+)$")),
    ("superfamily", re.compile(r"^Superfamily:\s*(.+)$")),
    ("family", re.compile(r"^Family:\s*(.+)$")),
    ("subclade", re.compile(r"^Subclade:\s*(.+)$")),
)

ROOT_NAMES = {
    "Transposable Elements - Human",
    "Transposable Elements (Mobile element) - Human",
    "Mobile genetic element",
}
VERTICAL_MARK = "│"
BRANCH_MARKS = ("├──", "└──")

VERTICAL_MARK = "\u2502"
BRANCH_MARKS = ("\u251c\u2500\u2500", "\u2514\u2500\u2500")

RING_COLORS = [
    "#4f86df",
    "#80acef",
    "#a7c8ff",
    "#d2e3ff",
    "#5d97f6",
    "#bfd8ff",
]

TAXONOMY_PROP_KEYS = [
    "taxonomy_group",
    "taxonomy_status",
    "taxonomy_source",
    "taxonomy_canonical_name",
    "taxonomy_class",
    "taxonomy_subclass",
    "taxonomy_order",
    "taxonomy_superfamily",
    "taxonomy_family",
    "taxonomy_subclade",
    "is_leaf_standard",
    "homepage_chart_included",
]

CLASSIFICATION_PATH_OVERRIDES: dict[str, dict[str, str]] = {
    "MER131": {
        "class": "Retrotransposons",
        "superfamily": "Other SINE Elements",
    },
}


@dataclass
class TreeNode:
    display_name: str
    raw_name: str
    depth: int
    is_leaf: bool
    path: dict[str, str]
    tree_source: str


@dataclass
class Classification:
    original_name: str
    canonical_name: str
    group: str
    status: str
    tree_source: str
    is_leaf_standard: bool
    homepage_chart_included: bool
    path: dict[str, str]


def load_config() -> dict[str, str]:
    text = CONFIG_PATH.read_text(encoding="utf-8")
    config: dict[str, str] = {}
    for key in ("neo4j_url", "neo4j_user", "neo4j_password"):
        match = re.search(rf"'{key}'\s*=>\s*'([^']*)'", text)
        if match:
            config[key] = match.group(1)
    missing = [key for key in ("neo4j_url", "neo4j_user", "neo4j_password") if not config.get(key)]
    if missing:
        raise RuntimeError(f"Missing Neo4j config keys: {', '.join(missing)}")
    return config


def tx_url(base_url: str, db_name: str) -> str:
    return re.sub(r"/db/[^/]+/tx/commit$", f"/db/{db_name}/tx/commit", base_url)


def system_url(base_url: str) -> str:
    return tx_url(base_url, "system")


def run_cypher(url: str, user: str, password: str, statement: str, parameters: dict[str, Any] | None = None) -> dict[str, Any]:
    payload = {"statements": [{"statement": statement, "parameters": parameters or {}}]}
    response = requests.post(url, auth=(user, password), json=payload, timeout=300)
    response.raise_for_status()
    data = response.json()
    if data.get("errors"):
        raise RuntimeError(json.dumps(data["errors"], ensure_ascii=False))
    return data


def query_rows(url: str, user: str, password: str, statement: str, parameters: dict[str, Any] | None = None) -> list[dict[str, Any]]:
    data = run_cypher(url, user, password, statement, parameters)
    result = data["results"][0] if data["results"] else {"columns": [], "data": []}
    columns = result.get("columns", [])
    rows: list[dict[str, Any]] = []
    for item in result.get("data", []):
        row = item.get("row", [])
        rows.append({column: row[idx] for idx, column in enumerate(columns)})
    return rows


def batched(items: list[dict[str, Any]], size: int) -> list[list[dict[str, Any]]]:
    return [items[idx : idx + size] for idx in range(0, len(items), size)]


def escape_identifier(value: str) -> str:
    return "`" + value.replace("`", "``") + "`"


def normalized_key(value: str) -> str:
    return re.sub(r"[^A-Za-z0-9]+", "", value).lower()


def display_aliases(value: str) -> list[str]:
    aliases = {value.strip()}
    no_paren = re.sub(r"\s*\([^)]*\)", "", value).strip()
    if no_paren:
        aliases.add(no_paren)
    paren_match = re.search(r"\(([^)]+)\)", value)
    if paren_match:
        inner = paren_match.group(1).strip()
        if inner:
            aliases.add(inner)
    return [item for item in aliases if item]


def extract_rank(display_name: str) -> tuple[str, str] | None:
    for rank, pattern in RANK_PATTERNS:
        match = pattern.match(display_name)
        if match:
            return rank, match.group(1).strip()
    return None


def parse_tree_content(raw_line: str) -> tuple[int, str]:
    line = raw_line.rstrip("\n")
    if not line.strip():
        return 0, ""
    if line.strip() in {VERTICAL_MARK}:
        return 0, ""
    leading_blocks = (len(line) - len(line.lstrip(" "))) // 4
    depth = leading_blocks + line.count(VERTICAL_MARK)
    branch_pattern = "|".join(re.escape(mark) for mark in BRANCH_MARKS)
    marker_matches = list(re.finditer(rf"({branch_pattern})\s*", line))
    if marker_matches:
        marker_match = marker_matches[-1]
        depth += 1
        content = line[marker_match.end() :].strip()
    else:
        content = re.sub(rf"^[\s{re.escape(VERTICAL_MARK)}{''.join(re.escape(mark) for mark in BRANCH_MARKS)}]+", "", line).strip()
    return depth, content


def parse_tree(tree_file: Path, tree_source: str) -> tuple[dict[str, list[TreeNode]], dict[str, list[TreeNode]]]:
    stack: dict[int, dict[str, Any]] = {}
    exact_map: dict[str, list[TreeNode]] = defaultdict(list)
    normalized_map: dict[str, list[TreeNode]] = defaultdict(list)
    tree_lines = tree_file.read_text(encoding="utf-8").splitlines()
    entries: list[dict[str, Any]] = []

    for raw_line in tree_lines:
        depth, content = parse_tree_content(raw_line)
        if not content or content in ROOT_NAMES:
            continue
        path: dict[str, str] = {}
        parent_entry = None
        if depth > 0:
            parent_entry = stack.get(depth - 1)
            if parent_entry is not None:
                path = dict(parent_entry["path"])
        rank_info = extract_rank(content)
        same_depth_entry = stack.get(depth)
        if rank_info is None and same_depth_entry is not None and len(same_depth_entry["path"]) > len(path):
            path = dict(same_depth_entry["path"])
        display_name = rank_info[1] if rank_info else content
        if rank_info:
            path[rank_info[0]] = display_name

        entry = {
            "display_name": display_name,
            "raw_name": content,
            "depth": depth,
            "path": path,
            "children": 0,
        }
        if depth > 0 and (depth - 1) in stack:
            stack[depth - 1]["children"] += 1
        stack = {key: value for key, value in stack.items() if key < depth}
        stack[depth] = entry
        entries.append(entry)

    for entry in entries:
        node = TreeNode(
            display_name=entry["display_name"],
            raw_name=entry["raw_name"],
            depth=entry["depth"],
            is_leaf=entry["children"] == 0,
            path=entry["path"],
            tree_source=tree_source,
        )
        for alias in display_aliases(node.display_name):
            exact_map[alias].append(node)
            normalized_map[normalized_key(alias)].append(node)

    return exact_map, normalized_map


def unique_match(matches: list[TreeNode]) -> TreeNode | None:
    unique_by_name: dict[str, TreeNode] = {}
    for item in matches:
        unique_by_name[item.display_name] = item
    if len(unique_by_name) == 1:
        return next(iter(unique_by_name.values()))
    return None


def classify_te_names(
    te_names: list[str],
    rep_exact: dict[str, list[TreeNode]],
    rep_norm: dict[str, list[TreeNode]],
    all_exact: dict[str, list[TreeNode]],
    all_norm: dict[str, list[TreeNode]],
    rep_text_lower: str,
    all_text_lower: str,
) -> list[Classification]:
    classifications: list[Classification] = []
    for name in sorted(te_names):
        aliases = display_aliases(name)
        name_norm = normalized_key(name)
        rep_exact_match = unique_match(rep_exact.get(name, []))
        rep_norm_match = unique_match(rep_norm.get(name_norm, []))
        all_exact_match = unique_match(all_exact.get(name, []))
        all_norm_match = unique_match(all_norm.get(name_norm, []))
        rep_present = any(alias in rep_exact for alias in aliases) or name_norm in rep_norm or name.lower() in rep_text_lower
        all_present = any(alias in all_exact for alias in aliases) or name_norm in all_norm or name.lower() in all_text_lower

        node: TreeNode | None = None
        group = "unresolved"
        status = "unresolved"
        tree_source = "none"
        canonical_name = name
        is_leaf_standard = False
        homepage_chart_included = False
        path: dict[str, str] = {}

        if rep_exact_match is not None:
            node = rep_exact_match
            canonical_name = node.display_name
            tree_source = "tree_rmsk_repbase"
            is_leaf_standard = node.is_leaf
            status = "leaf" if node.is_leaf else "non_leaf"
            group = "standard" if node.is_leaf else "B"
            homepage_chart_included = node.is_leaf
            path = dict(node.path)
        elif rep_norm_match is not None:
            node = rep_norm_match
            canonical_name = node.display_name
            tree_source = "tree_rmsk_repbase"
            is_leaf_standard = node.is_leaf
            status = "leaf" if node.is_leaf else "non_leaf"
            group = "C" if node.is_leaf else "B"
            homepage_chart_included = node.is_leaf
            path = dict(node.path)
        elif all_exact_match is not None or all_norm_match is not None:
            node = all_exact_match or all_norm_match
            canonical_name = node.display_name
            tree_source = "tree_all"
            is_leaf_standard = node.is_leaf
            status = "leaf" if node.is_leaf else "non_leaf"
            group = "A"
            homepage_chart_included = False
            path = dict(node.path)
        elif rep_present:
            tree_source = "tree_rmsk_repbase"
            status = "non_leaf"
            group = "B"
            is_leaf_standard = False
            homepage_chart_included = False
            path = {}
        elif all_present:
            tree_source = "tree_all"
            status = "non_leaf"
            group = "A"
            is_leaf_standard = False
            homepage_chart_included = False
            path = {}

        if name in CLASSIFICATION_PATH_OVERRIDES:
            override_path = CLASSIFICATION_PATH_OVERRIDES[name]
            path = dict(path)
            path.update(override_path)

        classifications.append(
            Classification(
                original_name=name,
                canonical_name=canonical_name,
                group=group,
                status=status,
                tree_source=tree_source,
                is_leaf_standard=is_leaf_standard,
                homepage_chart_included=homepage_chart_included,
                path=path,
            )
        )
    return classifications


def recreate_database(base_url: str, user: str, password: str, target_db: str) -> None:
    system = system_url(base_url)
    db_rows = query_rows(system, user, password, "SHOW DATABASES YIELD name RETURN name ORDER BY name")
    existing = {row["name"] for row in db_rows}
    if target_db in existing:
        run_cypher(system, user, password, f"STOP DATABASE {escape_identifier(target_db)} WAIT")
        run_cypher(system, user, password, f"DROP DATABASE {escape_identifier(target_db)} WAIT")
    run_cypher(system, user, password, f"CREATE DATABASE {escape_identifier(target_db)} WAIT")


def fetch_source_schema(source_url: str, user: str, password: str) -> tuple[list[str], list[str]]:
    constraint_rows = query_rows(
        source_url,
        user,
        password,
        """
        SHOW CONSTRAINTS YIELD createStatement
        RETURN createStatement
        ORDER BY createStatement
        """,
    )
    index_rows = query_rows(
        source_url,
        user,
        password,
        """
        SHOW INDEXES YIELD createStatement, type, owningConstraint
        WHERE owningConstraint IS NULL AND type <> 'LOOKUP'
        RETURN createStatement
        ORDER BY createStatement
        """,
    )
    constraints = [row["createStatement"] for row in constraint_rows if row.get("createStatement")]
    indexes = [row["createStatement"] for row in index_rows if row.get("createStatement")]
    return constraints, indexes


def fetch_graph_snapshot(source_url: str, user: str, password: str) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    node_rows = query_rows(
        source_url,
        user,
        password,
        """
        MATCH (n)
        RETURN id(n) AS legacy_id, labels(n) AS labels, properties(n) AS props
        ORDER BY legacy_id
        """,
    )
    rel_rows = query_rows(
        source_url,
        user,
        password,
        """
        MATCH (a)-[r]->(b)
        RETURN id(a) AS src, id(b) AS dst, type(r) AS type, properties(r) AS props
        ORDER BY src, dst
        """,
    )
    return node_rows, rel_rows


def import_graph(target_url: str, user: str, password: str, nodes: list[dict[str, Any]], relationships: list[dict[str, Any]]) -> None:
    grouped_nodes: dict[tuple[str, ...], list[dict[str, Any]]] = defaultdict(list)
    for row in nodes:
        label_key = tuple(sorted(row["labels"]))
        grouped_nodes[label_key].append(row)

    for labels, rows in grouped_nodes.items():
        label_fragment = "".join(f":{escape_identifier(label)}" for label in labels)
        statement = f"""
        UNWIND $rows AS row
        CREATE (n:ImportedNode{label_fragment})
        SET n = row.props
        SET n.__legacy_id = row.legacy_id
        """
        for batch in batched(rows, BATCH_SIZE):
            run_cypher(target_url, user, password, statement, {"rows": batch})

    run_cypher(
        target_url,
        user,
        password,
        "CREATE RANGE INDEX imported_legacy_id IF NOT EXISTS FOR (n:ImportedNode) ON (n.__legacy_id)",
    )

    grouped_rels: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for row in relationships:
        grouped_rels[row["type"]].append(row)

    for rel_type, rows in grouped_rels.items():
        statement = f"""
        UNWIND $rows AS row
        MATCH (src:ImportedNode {{__legacy_id: row.src}})
        MATCH (dst:ImportedNode {{__legacy_id: row.dst}})
        CREATE (src)-[r:{escape_identifier(rel_type)}]->(dst)
        SET r = row.props
        """
        for batch in batched(rows, BATCH_SIZE):
            run_cypher(target_url, user, password, statement, {"rows": batch})


def fetch_te_names(target_url: str, user: str, password: str) -> list[str]:
    rows = query_rows(
        target_url,
        user,
        password,
        """
        MATCH (n:TE)
        RETURN DISTINCT n.name AS name
        ORDER BY name
        """,
    )
    return [row["name"] for row in rows if row.get("name")]


def rename_te(target_url: str, user: str, password: str, old_name: str, new_name: str) -> None:
    run_cypher(
        target_url,
        user,
        password,
        """
        MATCH (n:TE {name: $old_name})
        SET n.name = $new_name
        """,
        {"old_name": old_name, "new_name": new_name},
    )


def merge_te(target_url: str, user: str, password: str, old_name: str, new_name: str) -> None:
    run_cypher(
        target_url,
        user,
        password,
        """
        MATCH (keep:TE {name: $new_name})
        MATCH (drop:TE {name: $old_name})
        WITH keep, drop
        WHERE elementId(keep) <> elementId(drop)
        CALL {
          WITH keep, drop
          MATCH (src)-[r:SUBFAMILY_OF]->(drop)
          MERGE (src)-[:SUBFAMILY_OF]->(keep)
          DELETE r
        }
        CALL {
          WITH keep, drop
          MATCH (drop)-[r:SUBFAMILY_OF]->(dst)
          MERGE (keep)-[:SUBFAMILY_OF]->(dst)
          DELETE r
        }
        CALL {
          WITH keep, drop
          MATCH (src)-[r:BIO_RELATION]->(drop)
          MERGE (src)-[r2:BIO_RELATION {predicate: r.predicate}]->(keep)
          SET r2.pmids = reduce(acc = coalesce(r2.pmids, []), x IN coalesce(r.pmids, []) | CASE WHEN x IN acc THEN acc ELSE acc + x END),
              r2.source_group = coalesce(r2.source_group, r.source_group)
          DELETE r
        }
        CALL {
          WITH keep, drop
          MATCH (drop)-[r:BIO_RELATION]->(dst)
          MERGE (keep)-[r2:BIO_RELATION {predicate: r.predicate}]->(dst)
          SET r2.pmids = reduce(acc = coalesce(r2.pmids, []), x IN coalesce(r.pmids, []) | CASE WHEN x IN acc THEN acc ELSE acc + x END),
              r2.source_group = coalesce(r2.source_group, r.source_group)
          DELETE r
        }
        SET keep.description = CASE
          WHEN coalesce(keep.description, '') = '' THEN drop.description
          ELSE keep.description
        END
        DETACH DELETE drop
        """,
        {"old_name": old_name, "new_name": new_name},
    )


def apply_name_standardization(target_url: str, user: str, password: str, classifications: list[Classification]) -> list[dict[str, str]]:
    operations: list[dict[str, str]] = []
    existing_names = set(fetch_te_names(target_url, user, password))
    for item in classifications:
        old_name = item.original_name
        new_name = item.canonical_name
        if old_name == new_name:
            continue
        if old_name not in existing_names:
            continue
        if new_name in existing_names:
            merge_te(target_url, user, password, old_name, new_name)
            existing_names.remove(old_name)
            op = "merge"
        else:
            rename_te(target_url, user, password, old_name, new_name)
            existing_names.remove(old_name)
            existing_names.add(new_name)
            op = "rename"
        operations.append({"operation": op, "old_name": old_name, "new_name": new_name, "group": item.group})
    return operations


def build_final_classifications(classifications: list[Classification]) -> dict[str, Classification]:
    final_map: dict[str, Classification] = {}
    priority = {"C": 0, "A": 1, "B": 2, "standard": 3, "unresolved": 4}
    for item in classifications:
        final_name = item.canonical_name
        current = final_map.get(final_name)
        if current is None or priority[item.group] < priority[current.group]:
            final_map[final_name] = Classification(
                original_name=item.original_name,
                canonical_name=final_name,
                group=item.group,
                status=item.status,
                tree_source=item.tree_source,
                is_leaf_standard=item.is_leaf_standard,
                homepage_chart_included=item.homepage_chart_included,
                path=dict(item.path),
            )
    return final_map


def write_taxonomy_properties(target_url: str, user: str, password: str, final_map: dict[str, Classification]) -> None:
    clear_statement = """
    MATCH (n:TE)
    REMOVE n.taxonomy_group, n.taxonomy_status, n.taxonomy_source, n.taxonomy_canonical_name,
           n.taxonomy_class, n.taxonomy_subclass, n.taxonomy_order, n.taxonomy_superfamily,
           n.taxonomy_family, n.taxonomy_subclade, n.is_leaf_standard, n.homepage_chart_included
    """
    run_cypher(target_url, user, password, clear_statement)

    rows: list[dict[str, Any]] = []
    for name, item in sorted(final_map.items()):
        rows.append(
            {
                "name": name,
                "taxonomy_group": item.group,
                "taxonomy_status": item.status,
                "taxonomy_source": item.tree_source,
                "taxonomy_canonical_name": name,
                "taxonomy_class": item.path.get("class"),
                "taxonomy_subclass": item.path.get("subclass"),
                "taxonomy_order": item.path.get("order"),
                "taxonomy_superfamily": item.path.get("superfamily"),
                "taxonomy_family": item.path.get("family"),
                "taxonomy_subclade": item.path.get("subclade"),
                "is_leaf_standard": item.is_leaf_standard,
                "homepage_chart_included": item.homepage_chart_included,
            }
        )

    statement = """
    UNWIND $rows AS row
    MATCH (n:TE {name: row.name})
    SET n.taxonomy_group = row.taxonomy_group,
        n.taxonomy_status = row.taxonomy_status,
        n.taxonomy_source = row.taxonomy_source,
        n.taxonomy_canonical_name = row.taxonomy_canonical_name,
        n.taxonomy_class = row.taxonomy_class,
        n.taxonomy_subclass = row.taxonomy_subclass,
        n.taxonomy_order = row.taxonomy_order,
        n.taxonomy_superfamily = row.taxonomy_superfamily,
        n.taxonomy_family = row.taxonomy_family,
        n.taxonomy_subclade = row.taxonomy_subclade,
        n.is_leaf_standard = row.is_leaf_standard,
        n.homepage_chart_included = row.homepage_chart_included
    """
    for batch in batched(rows, BATCH_SIZE):
        run_cypher(target_url, user, password, statement, {"rows": batch})


def finalize_target_schema(target_url: str, user: str, password: str, constraints: list[str], indexes: list[str]) -> None:
    run_cypher(target_url, user, password, "DROP INDEX imported_legacy_id IF EXISTS")
    run_cypher(
        target_url,
        user,
        password,
        """
        MATCH (n:ImportedNode)
        REMOVE n:ImportedNode
        REMOVE n.__legacy_id
        """,
    )
    for statement in constraints:
        run_cypher(target_url, user, password, statement)
    for statement in indexes:
        run_cypher(target_url, user, password, statement)


def slugify(value: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", value.lower()).strip("-")
    return slug or "segment"


def segment_color(index: int) -> str:
    return RING_COLORS[index % len(RING_COLORS)]


def build_chart_view(
    label: str,
    segments_counter: Counter[str],
    next_prefix: str | None = None,
    next_overrides: dict[str, str] | None = None,
) -> dict[str, Any]:
    ordered = sorted(segments_counter.items(), key=lambda item: (-item[1], item[0].lower()))
    total = sum(segments_counter.values())
    segments: list[dict[str, Any]] = []
    for idx, (name, count) in enumerate(ordered):
        segment = {
            "key": slugify(name),
            "label": name,
            "count": count,
            "color": segment_color(idx),
            "description": name,
        }
        if next_overrides and name in next_overrides:
            segment["nextView"] = next_overrides[name]
        elif next_prefix:
            segment["nextView"] = f"{next_prefix}{slugify(name)}"
        segments.append(segment)
    return {"count": total, "label": label, "segments": segments}


def retro_primary_bucket(item: Classification) -> str:
    order = item.path.get("order")
    if order:
        return order
    superfamily = item.path.get("superfamily", "")
    if "SINE" in superfamily.upper():
        return "SINEs"
    return "Unclassified"


def dna_primary_bucket(item: Classification) -> str:
    return item.path.get("subclass") or item.path.get("order") or "Unclassified"


def build_segment_counter(records: list[Classification], rank: str) -> Counter[str]:
    counter: Counter[str] = Counter()
    for item in records:
        value = item.path.get(rank)
        if value:
            counter[value] += 1
        else:
            counter["Unclassified"] += 1
    return counter


def build_deep_counter(records: list[Classification]) -> Counter[str]:
    counter: Counter[str] = Counter()
    for item in records:
        value = item.path.get("family") or item.path.get("subclade")
        if value:
            counter[value] += 1
        else:
            counter["Unclassified"] += 1
    return counter


def build_homepage_stats(final_map: dict[str, Classification]) -> dict[str, Any]:
    included_records = [item for item in final_map.values() if item.homepage_chart_included and item.path.get("class")]
    root_counter = Counter(item.path["class"] for item in included_records)
    chart_views: dict[str, Any] = {"root": build_chart_view("Classified TE", root_counter, next_prefix="class::")}

    by_class: dict[str, list[Classification]] = defaultdict(list)
    for item in included_records:
        by_class[item.path["class"]].append(item)

    for class_name, records in by_class.items():
        class_view_key = f"class::{slugify(class_name)}"
        grouped: dict[str, list[Classification]] = defaultdict(list)

        if class_name == "Retrotransposons":
            for item in records:
                grouped[retro_primary_bucket(item)].append(item)
            counter = Counter({bucket: len(bucket_records) for bucket, bucket_records in grouped.items()})
            chart_views[class_view_key] = build_chart_view(class_name, counter, next_prefix=f"{class_view_key}::")

            for bucket_name, bucket_records in grouped.items():
                segment_key = f"{class_view_key}::{slugify(bucket_name)}"
                superfamily_counter = build_segment_counter(bucket_records, "superfamily")
                next_overrides: dict[str, str] = {}
                for superfamily_name in sorted(superfamily_counter):
                    sub_records = [record for record in bucket_records if (record.path.get("superfamily") or "Unclassified") == superfamily_name]
                    deep_counter = build_deep_counter(sub_records)
                    if len(deep_counter) > 1:
                        next_overrides[superfamily_name] = f"{segment_key}::{slugify(superfamily_name)}"
                        chart_views[next_overrides[superfamily_name]] = build_chart_view(superfamily_name, deep_counter)
                chart_views[segment_key] = build_chart_view(bucket_name, superfamily_counter, next_overrides=next_overrides)
            continue

        if class_name == "DNA Transposons":
            for item in records:
                grouped[dna_primary_bucket(item)].append(item)
            counter = Counter({bucket: len(bucket_records) for bucket, bucket_records in grouped.items()})
            chart_views[class_view_key] = build_chart_view(class_name, counter, next_prefix=f"{class_view_key}::")

            for bucket_name, bucket_records in grouped.items():
                segment_key = f"{class_view_key}::{slugify(bucket_name)}"
                superfamily_counter = build_segment_counter(bucket_records, "superfamily")
                next_overrides: dict[str, str] = {}
                for superfamily_name in sorted(superfamily_counter):
                    sub_records = [record for record in bucket_records if (record.path.get("superfamily") or "Unclassified") == superfamily_name]
                    deep_counter = build_deep_counter(sub_records)
                    if len(deep_counter) > 1:
                        next_overrides[superfamily_name] = f"{segment_key}::{slugify(superfamily_name)}"
                        chart_views[next_overrides[superfamily_name]] = build_chart_view(superfamily_name, deep_counter)
                chart_views[segment_key] = build_chart_view(bucket_name, superfamily_counter, next_overrides=next_overrides)
            continue

        chart_views[class_view_key] = build_chart_view(class_name, Counter())

    summary = {
        "total_te_nodes": len(final_map),
        "classified_for_homepage": sum(1 for item in final_map.values() if item.homepage_chart_included and item.path.get("class")),
        "excluded_non_leaf": sum(1 for item in final_map.values() if item.status == "non_leaf"),
        "excluded_unresolved": sum(1 for item in final_map.values() if item.group == "unresolved"),
    }
    return {"views": chart_views, "summary": summary}


def write_outputs(classifications: list[Classification], operations: list[dict[str, str]], final_map: dict[str, Classification], homepage_stats: dict[str, Any]) -> None:
    input_counts = dict(Counter(item.group for item in classifications))
    input_status_counts = dict(Counter(item.status for item in classifications))
    post_merge_counts = dict(Counter(item.group for item in final_map.values()))
    post_merge_status_counts = dict(Counter(item.status for item in final_map.values()))
    report = {
        "source_db": SOURCE_DB,
        "target_db": TARGET_DB,
        "counts": post_merge_counts,
        "status_counts": post_merge_status_counts,
        "input_counts": input_counts,
        "input_status_counts": input_status_counts,
        "input_records": len(classifications),
        "post_merge_counts": post_merge_counts,
        "post_merge_status_counts": post_merge_status_counts,
        "post_merge_te_nodes": len(final_map),
        "operation_counts": dict(Counter(item["operation"] for item in operations)),
        "homepage_chart_nodes": int(homepage_stats.get("summary", {}).get("classified_for_homepage", 0)),
        "operations": operations,
        "items": [
            {
                "original_name": item.original_name,
                "canonical_name": item.canonical_name,
                "group": item.group,
                "status": item.status,
                "tree_source": item.tree_source,
                "is_leaf_standard": item.is_leaf_standard,
                "homepage_chart_included": item.homepage_chart_included,
                "path": item.path,
            }
            for item in classifications
        ],
        "final": {
            name: {
                "group": item.group,
                "status": item.status,
                "tree_source": item.tree_source,
                "is_leaf_standard": item.is_leaf_standard,
                "homepage_chart_included": item.homepage_chart_included,
                "path": item.path,
            }
            for name, item in sorted(final_map.items())
        },
    }
    REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
    REPORT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    HOMEPAGE_STATS_PATH.write_text(json.dumps(homepage_stats, ensure_ascii=False, indent=2), encoding="utf-8")


def main() -> None:
    config = load_config()
    base_url = config["neo4j_url"]
    user = config["neo4j_user"]
    password = config["neo4j_password"]
    source_url = tx_url(base_url, SOURCE_DB)
    target_url = tx_url(base_url, TARGET_DB)

    rep_exact, rep_norm = parse_tree(taxonomy_path("tree_rmsk_repbase.txt"), "tree_rmsk_repbase")
    all_exact, all_norm = parse_tree(taxonomy_path("tree_all.txt"), "tree_all")
    rep_text_lower = taxonomy_path("tree_rmsk_repbase.txt").read_text(encoding="utf-8").lower()
    all_text_lower = taxonomy_path("tree_all.txt").read_text(encoding="utf-8").lower()

    recreate_database(base_url, user, password, TARGET_DB)
    constraints, indexes = fetch_source_schema(source_url, user, password)
    nodes, relationships = fetch_graph_snapshot(source_url, user, password)
    import_graph(target_url, user, password, nodes, relationships)

    te_names = fetch_te_names(target_url, user, password)
    classifications = classify_te_names(te_names, rep_exact, rep_norm, all_exact, all_norm, rep_text_lower, all_text_lower)
    operations = apply_name_standardization(target_url, user, password, classifications)
    final_map = build_final_classifications(classifications)
    write_taxonomy_properties(target_url, user, password, final_map)
    finalize_target_schema(target_url, user, password, constraints, indexes)
    homepage_stats = build_homepage_stats(final_map)
    write_outputs(classifications, operations, final_map, homepage_stats)

    print(
        json.dumps(
            {
                "target_db": TARGET_DB,
                "node_count": len(nodes),
                "relationship_count": len(relationships),
                "te_count": len(te_names),
                "classification_counts": dict(Counter(item.group for item in classifications)),
                "status_counts": dict(Counter(item.status for item in classifications)),
                "operations": len(operations),
                "homepage_stats_path": str(HOMEPAGE_STATS_PATH),
                "report_path": str(REPORT_PATH),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
