from __future__ import annotations

from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read_text(relative: str) -> str:
    return (ROOT / relative).read_text(encoding="utf-8")


def main() -> int:
    failures: list[str] = []

    index_php = read_text("index.php")
    bootstrap_js = read_text("assets/js/renderers/g6/index-g6.bootstrap.js")
    tree_js = read_text("assets/js/renderers/g6/default-tree-mindmap.js")
    taxonomy_php = read_text("api/taxonomy.php")
    taxonomy_lib = read_text("api/taxonomy_lib.php")

    if "home-architecture-placeholder.svg" not in index_php:
        failures.append("homepage does not use the architecture placeholder image")
    if "preview_graph.html" in index_php or "<iframe" in index_php:
        failures.append("homepage Overview still embeds the graph iframe instead of the architecture placeholder")
    if "const initialTreeVariant = String(params.get('tree') || window.__TEKG_TREE_VARIANT || 'rmsk_repbase')" not in bootstrap_js:
        failures.append("G6 default tree variant is not rmsk_repbase")
    for variant in ("rmsk_repbase", "all"):
        if f"key: '{variant}'" not in bootstrap_js:
            failures.append(f"G6 tree variant list is missing {variant}")
    if "key: 'tekg3'" in bootstrap_js or "Neo4j TE taxonomy" in bootstrap_js:
        failures.append("G6 tree variant list still exposes the illegal tekg3 tree")
    if "source=' + encodeURIComponent" not in tree_js and "source=\" + encodeURIComponent" not in tree_js:
        failures.append("default tree renderer does not pass source to taxonomy API")
    if "$source = trim((string)($_GET['source'] ?? 'rmsk_repbase'));" not in taxonomy_php:
        failures.append("taxonomy tree API does not default to rmsk_repbase")
    if "function tekg_taxonomy_file_tree_payload" not in taxonomy_lib:
        failures.append("taxonomy library cannot build tree payloads from taxonomy text files")
    if "'root' => 'Transposable Elements (Mobile element) - Human'" not in taxonomy_lib:
        failures.append("all tree does not start from the second-level TE-Human-like root")
    if "'tekg3' => 'tekg3'" in taxonomy_lib or "'neo4j' => 'tekg3'" in taxonomy_lib:
        failures.append("taxonomy tree source aliases still allow the illegal tekg3 tree")

    if failures:
        print("FAIL homepage architecture placeholder and taxonomy runtime check")
        for failure in failures:
            print(f"- {failure}")
        return 1

    print("PASS homepage architecture placeholder and taxonomy runtime check")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
