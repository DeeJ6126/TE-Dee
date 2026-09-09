from __future__ import annotations

from pathlib import Path

from harness_lib import ROOT, fail, ok, run_check


RUNTIME_FILES = [
    ROOT / "index.php",
    ROOT / "browse.php",
    ROOT / "search.php",
    ROOT / "preview.php",
    ROOT / "api" / "graph.php",
    ROOT / "api" / "graph_service.php",
    ROOT / "api" / "taxonomy.php",
    ROOT / "api" / "taxonomy_lib.php",
    ROOT / "assets" / "js" / "pages" / "index.js",
    ROOT / "assets" / "js" / "renderers" / "g6" / "index-g6.bootstrap.js",
    ROOT / "assets" / "js" / "renderers" / "g6" / "index-g6-shared.js",
]

LEGACY_TRUTH_TOKENS = [
    "tree_te_lineage.json",
    "graph_demo_data.js",
    "GRAPH_DEMO_DATA",
    "tekg2_0413_tree_rmsk_repbase_lineage.json",
    "tekg2_0413_tree_all_lineage.json",
]


def main() -> None:
    failures: list[str] = []
    for path in RUNTIME_FILES:
        if not path.exists():
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        for token in LEGACY_TRUTH_TOKENS:
            if token in text:
                failures.append(
                    f"{path.relative_to(ROOT)} references legacy taxonomy truth source {token}. "
                    "Fix: route runtime taxonomy through Neo4j/api/taxonomy.php."
                )

    taxonomy_api = ROOT / "api" / "taxonomy.php"
    taxonomy_lib = ROOT / "api" / "taxonomy_lib.php"
    if not taxonomy_api.exists():
        failures.append("api/taxonomy.php is missing; runtime taxonomy needs the API canonical entry.")
    if not taxonomy_lib.exists():
        failures.append("api/taxonomy_lib.php is missing; homepage/runtime taxonomy needs the shared library.")
    if taxonomy_lib.exists():
        text = taxonomy_lib.read_text(encoding="utf-8", errors="replace")
        if "tekg_runtime_neo4j_config" not in text:
            failures.append("api/taxonomy_lib.php should read Neo4j through runtime_config.")

    index = ROOT / "index.php"
    if index.exists():
        text = index.read_text(encoding="utf-8", errors="replace")
        if "tekg_taxonomy_homepage_payload(" not in text:
            failures.append("index.php should build homepage taxonomy from the Neo4j-backed taxonomy helper.")

    if failures:
        fail("Taxonomy runtime truth check failed:\n- " + "\n- ".join(failures))
    ok("Taxonomy runtime truth is routed through Neo4j/API, with no legacy runtime truth source references.")


if __name__ == "__main__":
    run_check(main)
