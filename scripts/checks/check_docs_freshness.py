from __future__ import annotations

import re
from pathlib import Path

from harness_lib import ROOT, fail, ok, run_check


REQUIRED_FILES = [
    "AGENTS.md",
    "ARCHITECTURE.md",
    "docs/architecture/index.md",
    "docs/architecture/current_system.md",
    "docs/architecture/graph_runtime.md",
    "docs/architecture/data_sources.md",
    "docs/architecture/database_contract.md",
    "docs/architecture/frontend_contract.md",
    "docs/exec-plans/README.md",
    "docs/exec-plans/tech-debt-tracker.md",
    "docs/QUALITY_SCORE.md",
    "docs/RELIABILITY.md",
]

CORE_FACTS = {
    "AGENTS.md": ["tekg3", "preview.php", "api/graph.php", "api/graph_service.php"],
    "ARCHITECTURE.md": ["tekg3", "data/bulk_expression_web", "api/taxonomy.php"],
    "docs/architecture/current_system.md": ["tekg3", "data/bulk_expression_web"],
    "docs/architecture/data_sources.md": ["tekg3", "data/bulk_expression_web"],
    "docs/architecture/database_contract.md": ["tekg3", "api/graph.php?q=LINE1"],
}


def markdown_links(text: str) -> list[str]:
    return re.findall(r"\[[^\]]+\]\(([^)]+)\)", text)


def is_local_doc_link(link: str) -> bool:
    return not (
        link.startswith("http://")
        or link.startswith("https://")
        or link.startswith("#")
        or link.startswith("mailto:")
    )


def target_exists(source: Path, link: str) -> bool:
    clean = link.split("#", 1)[0].strip()
    if not clean:
        return True
    target = (source.parent / clean).resolve()
    try:
        target.relative_to(ROOT.resolve())
    except ValueError:
        target = (ROOT / clean).resolve()
    return target.exists()


def main() -> None:
    failures: list[str] = []
    for rel in REQUIRED_FILES:
        path = ROOT / rel
        if not path.exists():
            failures.append(f"Missing required harness doc: {rel}")

    for rel, facts in CORE_FACTS.items():
        path = ROOT / rel
        if not path.exists():
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        for fact in facts:
            if fact not in text:
                failures.append(f"{rel} missing current fact {fact!r}.")

    docs_to_check = [ROOT / rel for rel in REQUIRED_FILES if (ROOT / rel).exists()]
    for path in docs_to_check:
        text = path.read_text(encoding="utf-8", errors="replace")
        for link in markdown_links(text):
            if is_local_doc_link(link) and not target_exists(path, link):
                failures.append(f"{path.relative_to(ROOT)} has broken local link: {link}")

    if failures:
        fail("Docs freshness check failed:\n- " + "\n- ".join(failures))
    ok("Harness docs are present, linked, and contain current core facts.")


if __name__ == "__main__":
    run_check(main)
