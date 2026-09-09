from __future__ import annotations

from harness_lib import ROOT, fail, ok, run_check


FILES = [
    ROOT / "api" / "graph_service.php",
    ROOT / "assets" / "js" / "renderers" / "g6" / "index-g6.bootstrap.js",
    ROOT / "assets" / "js" / "renderers" / "g6" / "index-g6-shared.js",
    ROOT / "assets" / "js" / "renderers" / "g6" / "index-g6-runtime.js",
]

FORBIDDEN = [
    "disease-class::Disease",
    "Disease aggregate",
    "synthesizeDiseaseClass",
    "synthesizeDiseaseCategory",
    "legacy Disease aggregate",
]


def main() -> None:
    failures: list[str] = []
    for path in FILES:
        if not path.exists():
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        for token in FORBIDDEN:
            if token in text:
                failures.append(
                    f"{path.relative_to(ROOT)} contains legacy aggregate marker {token!r}. "
                    "Fix: use DiseaseCategory/Disease nodes from Neo4j; do not synthesize a large Disease aggregate node."
                )

    if failures:
        fail("Legacy G6 disease aggregate check failed:\n- " + "\n- ".join(failures))
    ok("No legacy synthesized Disease aggregate markers found in active graph runtime.")


if __name__ == "__main__":
    run_check(main)
