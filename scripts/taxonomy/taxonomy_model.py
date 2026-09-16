"""Pure, source-scoped model for the two canonical TE classification trees."""

from __future__ import annotations

import hashlib
import json
import re
from collections import Counter, defaultdict
from dataclasses import asdict, dataclass
from pathlib import Path


SOURCE_FILES = {
    "rmsk_repbase": "tree_rmsk_repbase.txt",
    "all": "tree_all.txt",
}
SOURCE_ROOTS = {
    "rmsk_repbase": "Transposable Elements - Human",
    "all": "Transposable Elements (Mobile element) - Human",
}
RANKS = ("class", "subclass", "order", "superfamily", "family", "subclade")
SLIM_PROPERTIES = ("taxonomy_source", "taxonomy_status") + tuple(f"taxonomy_{rank}" for rank in RANKS)
LITERATURE_NAME_ALIASES = {
    "LINE": "Non-LTR Retrotransposons (LINEs)",
    "LTR": "LTR Retrotransposons",
    "Merlin": "Merlin/IS1016",
    "Penelope": "Penelope / Neptune",
    "Retrotransposon": "Retrotransposons",
    "SINE3": "SINE3/5S",
    "retroposon": "Retrotransposons",
}
BRANCH_PATTERN = re.compile(r"(?:├──|└──)\s*")
RANK_PATTERNS = (
    ("class", re.compile(r"^Class [^:]+:\s*(.+)$")),
    ("subclass", re.compile(r"^Subclass [^:]+:\s*(.+)$")),
    ("order", re.compile(r"^Order:\s*(.+)$")),
    ("superfamily", re.compile(r"^Superfamily:\s*(.+)$")),
    ("family", re.compile(r"^Family:\s*(.+)$")),
    ("subclade", re.compile(r"^Subclade:\s*(.+)$")),
)


@dataclass(frozen=True)
class TaxonomyNodeRecord:
    node_key: str
    source: str
    name: str
    raw_label: str
    rank: str
    depth: int
    is_leaf: bool
    path_key: str
    path: dict[str, str]


@dataclass(frozen=True)
class TaxonomyEdgeRecord:
    source: str
    parent_key: str
    child_key: str


@dataclass(frozen=True)
class SourceTree:
    source: str
    root_key: str
    nodes: tuple[TaxonomyNodeRecord, ...]
    edges: tuple[TaxonomyEdgeRecord, ...]
    source_sha256: str


@dataclass(frozen=True)
class TeClassification:
    original_name: str
    matched_name: str | None
    source: str
    status: str
    match_method: str
    rename_to: str | None
    path: dict[str, str]


def parse_tree_line(raw_line: str) -> tuple[int, str] | None:
    """Read the branch marker's true four-column position, including blank blocks."""
    line = raw_line.rstrip("\r\n")
    if not line.strip() or re.fullmatch(r"[\s│]*", line):
        return None
    matches = list(BRANCH_PATTERN.finditer(line))
    if not matches:
        return 0, line.strip()
    marker = matches[-1]
    if marker.start() % 4:
        raise ValueError(f"Tree branch marker is not four-column aligned: {line!r}")
    return marker.start() // 4 + 1, line[marker.end() :].strip()


def extract_rank(raw_label: str) -> tuple[str, str] | None:
    for rank, pattern in RANK_PATTERNS:
        match = pattern.match(raw_label)
        if match:
            return rank, match.group(1).strip()
    return None


def normalized_key(value: str) -> str:
    return re.sub(r"[^A-Za-z0-9]+", "", value).lower()


def display_aliases(value: str) -> set[str]:
    aliases = {value.strip()}
    outside = re.sub(r"\s*\([^)]*\)", "", value).strip()
    if outside:
        aliases.add(outside)
    for inner in re.findall(r"\(([^)]+)\)", value):
        if inner.strip():
            aliases.add(inner.strip())
    return aliases - {""}


def build_source_tree(path: Path, source: str) -> SourceTree:
    if source not in SOURCE_ROOTS:
        raise ValueError(f"Unsupported taxonomy source: {source}")
    raw_bytes = path.read_bytes()
    raw_lines = raw_bytes.decode("utf-8-sig").splitlines()
    source_sha256 = hashlib.sha256(raw_bytes).hexdigest()
    records: list[dict[str, object]] = []
    edges: list[TaxonomyEdgeRecord] = []
    stack: dict[int, dict[str, object]] = {}
    root_depth: int | None = None
    root_key: str | None = None

    for line_number, raw_line in enumerate(raw_lines, 1):
        parsed = parse_tree_line(raw_line)
        if parsed is None:
            continue
        original_depth, raw_label = parsed
        if root_depth is None:
            if raw_label != SOURCE_ROOTS[source]:
                if source == "all" and raw_label == "Mobile genetic element":
                    continue
                raise ValueError(f"{path}:{line_number}: expected root {SOURCE_ROOTS[source]!r}")
            root_depth = original_depth
        depth = original_depth - root_depth
        if depth < 0 or (records and depth == 0):
            raise ValueError(f"{path}:{line_number}: unexpected root-level node {raw_label!r}")
        parent = stack.get(depth - 1) if depth else None
        if depth and parent is None:
            raise ValueError(f"{path}:{line_number}: parent missing for {raw_label!r}")
        ancestry = list(parent["ancestry"]) if parent is not None else []
        ancestry.append(raw_label)
        path_key = json.dumps(ancestry, ensure_ascii=False, separators=(",", ":"))
        node_key = hashlib.sha256(f"{source}\0{path_key}".encode("utf-8")).hexdigest()
        rank_info = extract_rank(raw_label)
        name = rank_info[1] if rank_info else raw_label
        lineage = dict(parent["path"]) if parent is not None else {}
        if rank_info:
            lineage[rank_info[0]] = name
        record: dict[str, object] = {
            "node_key": node_key,
            "source": source,
            "name": name,
            "raw_label": raw_label,
            "rank": rank_info[0] if rank_info else ("root" if depth == 0 else "category"),
            "depth": depth,
            "is_leaf": True,
            "path_key": path_key,
            "path": lineage,
            "ancestry": ancestry,
        }
        if parent is not None:
            parent["is_leaf"] = False
            edges.append(TaxonomyEdgeRecord(source, str(parent["node_key"]), node_key))
        stack = {level: node for level, node in stack.items() if level < depth}
        stack[depth] = record
        records.append(record)
        if depth == 0:
            root_key = node_key

    if root_key is None:
        raise ValueError(f"Root {SOURCE_ROOTS[source]!r} not found in {path}")
    nodes = []
    for record in records:
        record.pop("ancestry")
        if record["rank"] == "category" and record["is_leaf"]:
            record["rank"] = "te"
        nodes.append(TaxonomyNodeRecord(**record))
    tree = SourceTree(source, root_key, tuple(nodes), tuple(edges), source_sha256)
    validate_source_tree(tree)
    return tree


def validate_source_tree(tree: SourceTree) -> None:
    keys = [node.node_key for node in tree.nodes]
    paths = [node.path_key for node in tree.nodes]
    raw_labels = [node.raw_label for node in tree.nodes]
    if len(keys) != len(set(keys)) or len(paths) != len(set(paths)):
        raise ValueError(f"Duplicate taxonomy ancestry in {tree.source}")
    if len(raw_labels) != len(set(raw_labels)):
        raise ValueError(f"Duplicate raw labels in {tree.source}; update name-keyed Graph renderers before import")
    if len(tree.edges) != len(tree.nodes) - 1:
        raise ValueError(f"Wrong edge count in {tree.source}")
    roots = [node for node in tree.nodes if node.rank == "root"]
    if len(roots) != 1 or roots[0].node_key != tree.root_key:
        raise ValueError(f"Wrong root count in {tree.source}")
    node_keys = set(keys)
    parents: dict[str, str] = {}
    children: dict[str, list[str]] = defaultdict(list)
    for edge in tree.edges:
        if edge.parent_key not in node_keys or edge.child_key not in node_keys:
            raise ValueError(f"Missing taxonomy edge endpoint in {tree.source}")
        if edge.child_key in parents:
            raise ValueError(f"Multiple parents in {tree.source}")
        parents[edge.child_key] = edge.parent_key
        children[edge.parent_key].append(edge.child_key)
    seen: set[str] = set()
    pending = [tree.root_key]
    while pending:
        key = pending.pop()
        if key in seen:
            raise ValueError(f"Taxonomy cycle in {tree.source}")
        seen.add(key)
        pending.extend(children[key])
    if seen != node_keys:
        raise ValueError(f"Disconnected taxonomy node in {tree.source}")


def _select_match(candidates: list[TaxonomyNodeRecord]) -> TaxonomyNodeRecord | None:
    unique = {node.node_key: node for node in candidates}
    leaves = [node for node in unique.values() if node.is_leaf]
    if len(leaves) == 1:
        return leaves[0]
    if len(unique) == 1:
        return next(iter(unique.values()))
    # Adjacent category levels can have the same name, but different lineages
    # cannot be assigned to one literature TE without an explicit resolution.
    return None


def classify_te_names(names: list[str], trees: dict[str, SourceTree]) -> list[TeClassification]:
    results: list[TeClassification] = []
    for original_name in sorted(set(names)):
        lookup_name = LITERATURE_NAME_ALIASES.get(original_name, original_name)
        matched: TaxonomyNodeRecord | None = None
        method = "none"
        ambiguous = False
        for source in ("rmsk_repbase", "all"):
            nodes = trees[source].nodes
            lookups = (
                ("explicit_alias", [node for node in nodes if node.name == lookup_name]) if lookup_name != original_name else ("exact", [node for node in nodes if node.name == original_name]),
                ("exact_alias", [node for node in nodes if lookup_name in display_aliases(node.name) and node.name != lookup_name]),
                ("normalized", [node for node in nodes if normalized_key(node.name) == normalized_key(lookup_name) and node.name != lookup_name]),
                ("normalized_alias", [node for node in nodes if any(normalized_key(alias) == normalized_key(lookup_name) for alias in display_aliases(node.name)) and normalized_key(node.name) != normalized_key(lookup_name)]),
            )
            for candidate_method, candidates in lookups:
                if not candidates:
                    continue
                matched = _select_match(candidates)
                method = candidate_method
                ambiguous = matched is None
                break
            if method != "none":
                break
        if ambiguous:
            results.append(TeClassification(original_name, None, "none", "unresolved", "ambiguous", None, {}))
        elif matched is None:
            results.append(TeClassification(original_name, None, "none", "unresolved", "none", None, {}))
        else:
            rename_to = matched.name if matched.is_leaf and matched.name != original_name else None
            results.append(TeClassification(
                original_name, matched.name,
                "tree_rmsk_repbase" if matched.source == "rmsk_repbase" else "tree_all",
                "leaf" if matched.is_leaf else "non_leaf",
                method, rename_to, dict(matched.path),
            ))
    return results


def build_manifest(names: list[str], trees: dict[str, SourceTree] | None = None) -> dict[str, object]:
    if trees is None:
        tree_dir = Path(__file__).resolve().parents[2] / "data" / "taxonomy" / "transposon_tree"
        trees = {source: build_source_tree(tree_dir / filename, source) for source, filename in SOURCE_FILES.items()}
    classifications = classify_te_names(names, trees)
    ambiguous_names = [item.original_name for item in classifications if item.match_method == "ambiguous"]
    if ambiguous_names:
        raise ValueError(f"Ambiguous TE taxonomy matches: {', '.join(ambiguous_names)}")
    name_set = set(names)
    candidates: list[dict[str, str]] = []
    conflicts: list[dict[str, str]] = []
    proposed_targets = Counter(item.rename_to for item in classifications if item.rename_to)
    for item in classifications:
        if not item.rename_to:
            continue
        row = {"original_name": item.original_name, "rename_to": item.rename_to, "match_method": item.match_method}
        if item.rename_to in name_set or proposed_targets[item.rename_to] > 1:
            row["reason"] = "occupied_target" if item.rename_to in name_set else "duplicate_target"
            conflicts.append(row)
        else:
            candidates.append(row)
    approved = {row["original_name"]: row["rename_to"] for row in candidates}
    te_updates = []
    for item in classifications:
        properties = {
            "taxonomy_source": item.source,
            "taxonomy_status": item.status,
            **{f"taxonomy_{rank}": item.path.get(rank) for rank in RANKS},
        }
        te_updates.append({
            "original_name": item.original_name,
            "current_name": approved.get(item.original_name, item.original_name),
            "matched_name": item.matched_name,
            "match_method": item.match_method,
            "properties": properties,
        })
    return {
        "metadata": {
            "schema_version": 1,
            "source_sha256": {source: tree.source_sha256 for source, tree in trees.items()},
        },
        "trees": {
            source: {
                "root_key": tree.root_key,
                "nodes": [asdict(node) for node in tree.nodes],
                "edges": [asdict(edge) for edge in tree.edges],
            }
            for source, tree in trees.items()
        },
        "te_updates": te_updates,
        "rename_candidates": candidates,
        "rename_conflicts": conflicts,
        "summary": {
            "tree_nodes": {source: len(tree.nodes) for source, tree in trees.items()},
            "tree_edges": {source: len(tree.edges) for source, tree in trees.items()},
            "te_status": dict(Counter(item.status for item in classifications)),
            "rename_candidates": len(candidates),
            "rename_conflicts": len(conflicts),
        },
    }
