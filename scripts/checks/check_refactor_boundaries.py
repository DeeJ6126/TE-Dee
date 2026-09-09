from __future__ import annotations

import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


EXPECTED_FILES = [
    "includes/search_detail_helpers.php",
    "api/graph_service.php",
    "api/expression_repository.php",
    "includes/jbrowse_session.php",
    "assets/js/renderers/g6/index-g6-type-meta.js",
]

PHP_SYNTAX_FILES = [
    "search.php",
    "includes/search_detail_helpers.php",
    "api/graph.php",
    "api/graph_service.php",
    "api/expression_data.php",
    "api/expression_repository.php",
    "jbrowse.php",
    "includes/jbrowse_session.php",
]

JS_SYNTAX_FILES = [
    "assets/js/renderers/g6/index-g6-type-meta.js",
    "assets/js/renderers/g6/index-g6-runtime.js",
    "assets/js/renderers/g6/index-g6-shared.js",
    "assets/js/renderers/g6/index-g6.bootstrap.js",
]


def read_text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def run(command: list[str]) -> tuple[int, str]:
    proc = subprocess.run(
        command,
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
    )
    return proc.returncode, proc.stdout.strip()


def main() -> int:
    failures: list[str] = []

    for path in EXPECTED_FILES:
        if not (ROOT / path).is_file():
            failures.append(f"missing expected split file: {path}")

    entry_expectations = {
        "search.php": [
            "require_once __DIR__ . '/includes/search_detail_helpers.php';",
        ],
        "api/graph.php": [
            "require_once __DIR__ . '/graph_service.php';",
        ],
        "api/expression_data.php": [
            "require_once __DIR__ . '/expression_repository.php';",
        ],
        "jbrowse.php": [
            "require __DIR__ . '/includes/jbrowse_session.php';",
        ],
    }
    for path, needles in entry_expectations.items():
        text = read_text(path)
        for needle in needles:
            if needle not in text:
                failures.append(f"{path} missing include: {needle}")

    moved_symbols = {
        "search.php": ["function tekg_repbase_lookup_proto", "function tekg_jbrowse_lookup_proto"],
        "api/graph.php": ["final class GraphService", "private function runNeo4j"],
        "api/expression_data.php": ["function tekg_expression_db", "function tekg_expression_fetch_detail_bundle"],
        "jbrowse.php": ["function jbrowse_build_locus_from_params", "function jbrowse_collect_refseq_rows"],
    }
    for path, needles in moved_symbols.items():
        text = read_text(path)
        for needle in needles:
            if needle in text:
                failures.append(f"{path} still owns moved symbol: {needle}")

    helper_expectations = {
        "includes/search_detail_helpers.php": ["function tekg_repbase_lookup_proto", "function tekg_jbrowse_lookup_proto"],
        "api/graph_service.php": ["final class GraphService", "private function runNeo4j"],
        "api/expression_repository.php": ["function tekg_expression_db", "function tekg_expression_fetch_detail_bundle"],
        "includes/jbrowse_session.php": ["function jbrowse_build_locus_from_params", "function jbrowse_collect_refseq_rows"],
        "assets/js/renderers/g6/index-g6-type-meta.js": ["window.__TEKG_G6_TYPE_META", "DiseaseClass"],
    }
    for path, needles in helper_expectations.items():
        if not (ROOT / path).is_file():
            continue
        text = read_text(path)
        for needle in needles:
            if needle not in text:
                failures.append(f"{path} missing moved symbol: {needle}")

    for path in [
        "preview.php",
        "assets/html/preview_graph.html",
        "assets/html/preview_g6_embed.html",
    ]:
        text = read_text(path)
        if "index-g6-type-meta.js" not in text:
            failures.append(f"{path} does not load index-g6-type-meta.js")

    for path in [
        "assets/js/renderers/g6/index-g6-runtime.js",
        "assets/js/renderers/g6/index-g6-shared.js",
        "assets/js/renderers/g6/index-g6.bootstrap.js",
    ]:
        text = read_text(path)
        if "__TEKG_G6_TYPE_META" not in text:
            failures.append(f"{path} does not read shared G6 type metadata")

    if failures:
        print("Refactor boundary check failed:")
        for failure in failures:
            print(f"- {failure}")
        return 1

    for path in PHP_SYNTAX_FILES:
        code, output = run(["php", "-l", str(ROOT / path)])
        if code != 0:
            print(output)
            return code

    for path in JS_SYNTAX_FILES:
        code, output = run(["node", "--check", str(ROOT / path)])
        if code != 0:
            print(output)
            return code

    print("Refactor boundary check passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
