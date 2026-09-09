from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

RUNTIME_FILES = [
    ROOT / "api" / "config.local.php.example",
    ROOT / "api" / "graph.php",
    ROOT / "api" / "health.php",
    ROOT / "api" / "taxonomy_lib.php",
    ROOT / "api" / "te_metrics.php",
    ROOT / "api" / "agent" / "bootstrap.php",
]

OLD_DB_URL_PARTS = [
    "/db/tekg2/tx/commit",
    "/db/tekg21/tx/commit",
]


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def main() -> int:
    failures: list[str] = []

    runtime_config = ROOT / "api" / "runtime_config.php"
    if not runtime_config.is_file():
        failures.append("api/runtime_config.php is missing")
    else:
        text = read(runtime_config)
        for required in [
            "tekg_runtime_load_local_config",
            "tekg_runtime_neo4j_config",
            "tekg_runtime_neo4j_database_name",
        ]:
            if required not in text:
                failures.append(f"api/runtime_config.php missing {required}")

    for path in RUNTIME_FILES:
        if not path.is_file():
            failures.append(f"runtime config file is missing: {path.relative_to(ROOT)}")
            continue
        text = read(path)
        for old_url_part in OLD_DB_URL_PARTS:
            if old_url_part in text:
                failures.append(f"{path.relative_to(ROOT)} still references {old_url_part}")

    example = ROOT / "api" / "config.local.php.example"
    if example.is_file() and "/db/tekg3/tx/commit" not in read(example):
        failures.append("api/config.local.php.example does not point to tekg3")

    health = ROOT / "api" / "health.php"
    if health.is_file() and "neo4j_database" not in read(health):
        failures.append("api/health.php does not expose neo4j_database")

    if failures:
        print("FAIL runtime DB config")
        for failure in failures:
            print(f"- {failure}")
        return 1

    print("PASS runtime DB config")
    return 0


if __name__ == "__main__":
    sys.exit(main())
