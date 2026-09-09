from __future__ import annotations

import sys
from pathlib import Path

from harness_lib import ROOT, fail, ok, run_check


ACTIVE_FILES = [
    ROOT / "api" / "config.local.php.example",
    ROOT / "api" / "runtime_config.php",
    ROOT / "api" / "graph.php",
    ROOT / "api" / "graph_service.php",
    ROOT / "api" / "health.php",
    ROOT / "api" / "taxonomy.php",
    ROOT / "api" / "taxonomy_lib.php",
    ROOT / "api" / "te_metrics.php",
    ROOT / "api" / "agent" / "bootstrap.php",
]

FORBIDDEN = [
    "/db/tekg2/tx/commit",
    "/db/tekg21/tx/commit",
]


def main() -> None:
    failures: list[str] = []
    for path in ACTIVE_FILES:
        if not path.exists():
            failures.append(f"Missing active runtime file: {path.relative_to(ROOT)}")
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        for token in FORBIDDEN:
            if token in text:
                failures.append(
                    f"{path.relative_to(ROOT)} still contains {token}. "
                    "Fix: use api/runtime_config.php and do not restore tekg2/tekg21 runtime fallback."
                )

    example = ROOT / "api" / "config.local.php.example"
    if example.exists() and "/db/tekg3/tx/commit" not in example.read_text(encoding="utf-8", errors="replace"):
        failures.append("api/config.local.php.example must point to tekg3.")

    if failures:
        fail("Legacy DB fallback check failed:\n- " + "\n- ".join(failures))
    ok("No legacy tekg2/tekg21 runtime fallback found in active files.")


if __name__ == "__main__":
    run_check(main)
