from __future__ import annotations

import argparse
import hashlib
import json
import os
import tarfile
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

from tqdm import tqdm


ROOT = Path(__file__).resolve().parents[2]


@dataclass(frozen=True)
class Group:
    name: str
    target: str
    entries: tuple[tuple[Path, Path], ...]


GROUPS = (
    Group(
        "jbrowse-reference",
        "/data/tekg/runtime/JBrowse",
        tuple(
            (ROOT / "data" / "JBrowse" / name, Path(name))
            for name in ("hg38.fa", "hg38.fa.fai", "hg38.chrom.sizes")
        ),
    ),
    Group(
        "jbrowse-clinvar",
        "/data/tekg/runtime/JBrowse",
        tuple(
            (ROOT / "data" / "JBrowse" / name, Path(name))
            for name in ("clinvarMain.bb", "clinvarCnv.bb")
        ),
    ),
    Group(
        "jbrowse-annotations",
        "/data/tekg/runtime/JBrowse",
        tuple(
            (ROOT / "data" / "JBrowse" / name, Path(name))
            for name in (
                "hg38.ncbiRefSeq.gtf",
                "repeats",
                "cache",
                "jbrowse_assets_manifest.json",
            )
        ),
    ),
    Group(
        "bulk-expression-web",
        "/data/tekg/runtime/bulk_expression_web",
        ((ROOT / "data" / "bulk_expression_web", Path(".")),),
    ),
    Group(
        "coexpression-feature-annotation",
        "/data/tekg/runtime/coexpression/feature_annotation",
        (
            (
                ROOT / "data" / "coexpression" / "feature_annotation" / "feature_annotation.tsv",
                Path("feature_annotation.tsv"),
            ),
        ),
    ),
)


class ProgressReader:
    def __init__(self, handle, progress: tqdm, digest: hashlib._Hash) -> None:
        self.handle = handle
        self.progress = progress
        self.digest = digest

    def read(self, size: int = -1) -> bytes:
        chunk = self.handle.read(size)
        if chunk:
            self.progress.update(len(chunk))
            self.digest.update(chunk)
        return chunk


def iter_files(group: Group) -> list[tuple[Path, Path]]:
    files: list[tuple[Path, Path]] = []
    for source, archive_root in group.entries:
        if not source.exists():
            raise FileNotFoundError(source)
        if source.is_file():
            files.append((source, archive_root))
            continue
        for path in sorted(item for item in source.rglob("*") if item.is_file()):
            relative = path.relative_to(source)
            archive_path = relative if archive_root == Path(".") else archive_root / relative
            files.append((path, archive_path))
    return files


def sha256_file(path: Path, label: str) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle, tqdm(
        total=path.stat().st_size,
        desc=label,
        unit="B",
        unit_scale=True,
        unit_divisor=1024,
    ) as progress:
        while chunk := handle.read(8 * 1024 * 1024):
            digest.update(chunk)
            progress.update(len(chunk))
    return digest.hexdigest()


def package_group(group: Group, output_dir: Path) -> dict:
    files = iter_files(group)
    total_bytes = sum(path.stat().st_size for path, _ in files)
    archive_path = output_dir / f"{group.name}.tar.gz"
    partial_path = archive_path.with_suffix(archive_path.suffix + ".partial")
    records: list[dict] = []

    if partial_path.exists():
        partial_path.unlink()

    with tarfile.open(partial_path, "w:gz", compresslevel=1, format=tarfile.PAX_FORMAT) as archive, tqdm(
        total=total_bytes,
        desc=f"pack {group.name}",
        unit="B",
        unit_scale=True,
        unit_divisor=1024,
    ) as progress:
        for source, archive_name in files:
            info = archive.gettarinfo(str(source), arcname=archive_name.as_posix())
            digest = hashlib.sha256()
            with source.open("rb") as handle:
                archive.addfile(info, ProgressReader(handle, progress, digest))
            records.append(
                {
                    "path": archive_name.as_posix(),
                    "size": info.size,
                    "sha256": digest.hexdigest(),
                }
            )

    os.replace(partial_path, archive_path)
    archive_sha256 = sha256_file(archive_path, f"hash {group.name}")
    manifest = {
        "group": group.name,
        "target": group.target,
        "archive": archive_path.name,
        "archive_size": archive_path.stat().st_size,
        "archive_sha256": archive_sha256,
        "source_size": total_bytes,
        "file_count": len(records),
        "files": records,
    }
    (output_dir / f"{group.name}.manifest.json").write_text(
        json.dumps(manifest, indent=2, ensure_ascii=True) + "\n",
        encoding="utf-8",
    )
    return manifest


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Package TE-KG server runtime assets.")
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--group", action="append", choices=[group.name for group in GROUPS])
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    output_dir = args.output_dir.resolve()
    output_dir.mkdir(parents=True, exist_ok=True)
    selected = set(args.group or [])
    groups = [group for group in GROUPS if not selected or group.name in selected]
    manifests = [package_group(group, output_dir) for group in groups]
    release = {
        "created_at": datetime.now(timezone.utc).isoformat(),
        "groups": [
            {
                key: manifest[key]
                for key in ("group", "target", "archive", "archive_size", "archive_sha256", "source_size", "file_count")
            }
            for manifest in manifests
        ],
    }
    (output_dir / "release-manifest.json").write_text(
        json.dumps(release, indent=2, ensure_ascii=True) + "\n",
        encoding="utf-8",
    )
    print(json.dumps(release, indent=2, ensure_ascii=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
