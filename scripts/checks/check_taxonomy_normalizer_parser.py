from __future__ import annotations

import importlib.util
import re
import sys
import types
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
NORMALIZER = ROOT / "scripts" / "normalize" / "build_tekg3_from_tekg21.py"
TREE_FILES = [
    ROOT / "data" / "taxonomy" / "transposon_tree" / "tree_rmsk_repbase.txt",
    ROOT / "data" / "taxonomy" / "transposon_tree" / "tree_all.txt",
]
BRANCH_PATTERN = re.compile(r"(?:├──|└──)\s*")


def load_normalizer():
    # Parser checks do not use HTTP; keep this focused check independent of the
    # optional requests package used by the database rebuild entry point.
    sys.modules.setdefault("requests", types.SimpleNamespace())
    spec = importlib.util.spec_from_file_location("tekg3_taxonomy_normalizer", NORMALIZER)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Unable to load normalizer: {NORMALIZER}")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def main() -> int:
    normalizer = load_normalizer()
    failures: list[str] = []

    for tree_file in TREE_FILES:
        for line_number, line in enumerate(tree_file.read_text(encoding="utf-8").splitlines(), 1):
            matches = list(BRANCH_PATTERN.finditer(line))
            if not matches:
                continue
            marker = matches[-1]
            marker_column = len(line[: marker.start()])
            if marker_column % 4 != 0:
                failures.append(
                    f"{tree_file.name}:{line_number} branch marker is not aligned to a four-column tree block"
                )
                continue
            expected_depth = marker_column // 4 + 1
            expected_content = line[marker.end() :].strip()
            actual_depth, actual_content = normalizer.parse_tree_content(line)
            if (actual_depth, actual_content) != (expected_depth, expected_content):
                failures.append(
                    f"{tree_file.name}:{line_number} expected depth/content "
                    f"{(expected_depth, expected_content)!r}, got {(actual_depth, actual_content)!r}"
                )

        exact_map, _normalized_map = normalizer.parse_tree(tree_file, tree_file.stem)
        nodes_by_raw_name = {}
        seen_node_ids: set[int] = set()
        for matches in exact_map.values():
            for node in matches:
                if id(node) in seen_node_ids:
                    continue
                seen_node_ids.add(id(node))
                nodes_by_raw_name[node.raw_name] = node

        expected_paths = {
            "X4_LINE": {
                "class": "Retrotransposons",
                "order": "Non-LTR Retrotransposons (LINEs)",
                "superfamily": "R4",
            },
            "UCON84": {
                "class": "DNA Transposons",
                "subclass": "Harbingers",
            },
            "AluJb": {
                "class": "Retrotransposons",
                "order": "SINEs",
                "superfamily": "SINE1/7SL (Alu)",
                "family": "AluJ",
            },
            "HERVL18-int": {
                "class": "Retrotransposons",
                "order": "LTR Retrotransposons",
                "superfamily": "Other ERV Elements",
            },
            "MER75": {
                "class": "DNA Transposons",
                "subclass": "TIR",
                "superfamily": "piggyBac",
                "family": "T2_type",
            },
        }
        for raw_name, expected_path in expected_paths.items():
            node = nodes_by_raw_name.get(raw_name)
            if node is None:
                failures.append(f"{tree_file.name} is missing parser regression node {raw_name}")
            elif node.path != expected_path:
                failures.append(
                    f"{tree_file.name}:{raw_name} expected path {expected_path!r}, got {node.path!r}"
                )

    if failures:
        for failure in failures[:20]:
            print(f"[FAIL] {failure}", file=sys.stderr)
        if len(failures) > 20:
            print(f"[FAIL] ... and {len(failures) - 20} more parser mismatches", file=sys.stderr)
        return 1

    print("[OK] Python taxonomy normalizer matches tree-marker column depths for both TXT sources")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
