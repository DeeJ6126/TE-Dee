from __future__ import annotations

from pathlib import Path

from harness_lib import ROOT, ok, run_check


SKIP_DIRS = {".git", ".vscode"}


def should_skip(path: Path) -> bool:
    return any(part in SKIP_DIRS for part in path.parts)


def main() -> None:
    warnings: list[str] = []

    for path in ROOT.rglob("__pycache__"):
        if should_skip(path):
            continue
        warnings.append(f"Python cache directory present: {path.relative_to(ROOT)}")

    for path in ROOT.rglob(".git"):
        if path == ROOT / ".git":
            continue
        if should_skip(path.parent):
            continue
        warnings.append(f"Nested Git checkout present: {path.relative_to(ROOT)}")

    archive = ROOT / "archive"
    if archive.exists():
        warnings.append("archive/ exists in active workspace; confirm it is manifest-only or intentionally retained.")

    large_files: list[str] = []
    for path in ROOT.rglob("*"):
        if should_skip(path) or not path.is_file():
            continue
        try:
            size = path.stat().st_size
        except OSError:
            continue
        if size >= 100 * 1024 * 1024:
            large_files.append(f"{path.relative_to(ROOT)} ({size} bytes)")
    if large_files:
        warnings.append("Large files present:\n  - " + "\n  - ".join(large_files[:20]))

    if warnings:
        print("WARN repository entropy")
        for warning in warnings:
            print(f"- {warning}")
        print("This check is warning-only in Phase 3. Use it as cleanup guidance, not a hard failure.")
        return

    ok("No obvious repository entropy warnings found.")


if __name__ == "__main__":
    run_check(main)
