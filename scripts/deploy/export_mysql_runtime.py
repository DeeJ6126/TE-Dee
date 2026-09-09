from __future__ import annotations

import argparse
import gzip
import hashlib
import json
import os
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

from tqdm import tqdm


DATABASES = {
    "tekg_catalog": (),
    "tekg_expression": ("expression_context_stats_q13_stage",),
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Export the production TE-KG MySQL databases as compressed SQL."
    )
    parser.add_argument("--mysqldump", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=3306)
    parser.add_argument("--user", default="root")
    parser.add_argument(
        "--net-buffer-length",
        type=int,
        default=64 * 1024,
        help=(
            "Maximum approximate size of extended INSERT statements emitted by "
            "mysqldump (default: 65536 bytes)."
        ),
    )
    parser.add_argument(
        "--defaults-extra-file",
        type=Path,
        help="Optional MySQL client option file. This argument must precede other client options.",
    )
    return parser.parse_args()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle, tqdm(
        total=path.stat().st_size,
        desc=f"hash {path.name}",
        unit="B",
        unit_scale=True,
        unit_divisor=1024,
    ) as progress:
        while chunk := handle.read(8 * 1024 * 1024):
            digest.update(chunk)
            progress.update(len(chunk))
    return digest.hexdigest()


def build_command(args: argparse.Namespace, database: str) -> list[str]:
    command = [str(args.mysqldump)]
    if args.defaults_extra_file:
        command.append(f"--defaults-extra-file={args.defaults_extra_file.resolve()}")
    command.extend(
        [
            f"--host={args.host}",
            f"--port={args.port}",
            f"--user={args.user}",
            f"--net-buffer-length={args.net_buffer_length}",
            "--default-character-set=utf8mb4",
            "--column-statistics=0",
            "--set-gtid-purged=OFF",
            "--single-transaction",
            "--quick",
            "--hex-blob",
            "--routines",
            "--events",
            "--triggers",
            "--no-tablespaces",
            "--skip-comments",
            "--databases",
            database,
        ]
    )
    command.extend(
        f"--ignore-table={database}.{table}" for table in DATABASES[database]
    )
    return command


def export_database(args: argparse.Namespace, database: str, output_dir: Path) -> dict:
    archive_path = output_dir / f"{database}.sql.gz"
    partial_path = archive_path.with_suffix(archive_path.suffix + ".partial")
    if partial_path.exists():
        partial_path.unlink()

    command = build_command(args, database)
    with tempfile.TemporaryFile() as stderr_handle:
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=stderr_handle)
        assert process.stdout is not None
        with partial_path.open("wb") as raw_output, gzip.GzipFile(
            filename=f"{database}.sql",
            mode="wb",
            compresslevel=1,
            fileobj=raw_output,
            mtime=0,
        ) as compressed, tqdm(
            desc=f"dump {database}",
            unit="B",
            unit_scale=True,
            unit_divisor=1024,
        ) as progress:
            while chunk := process.stdout.read(8 * 1024 * 1024):
                compressed.write(chunk)
                progress.update(len(chunk))

        return_code = process.wait()
        stderr_handle.seek(0)
        stderr_text = stderr_handle.read().decode("utf-8", errors="replace").strip()

    if return_code != 0:
        partial_path.unlink(missing_ok=True)
        raise RuntimeError(f"mysqldump failed for {database}: {stderr_text}")

    os.replace(partial_path, archive_path)
    return {
        "database": database,
        "archive": archive_path.name,
        "archive_size": archive_path.stat().st_size,
        "archive_sha256": sha256_file(archive_path),
        "excluded_tables": list(DATABASES[database]),
    }


def main() -> int:
    args = parse_args()
    if not args.mysqldump.is_file():
        raise FileNotFoundError(args.mysqldump)
    if not 4096 <= args.net_buffer_length <= 1024 * 1024:
        raise ValueError("--net-buffer-length must be between 4096 and 1048576")
    output_dir = args.output_dir.resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    records = [export_database(args, database, output_dir) for database in DATABASES]
    manifest = {
        "created_at": datetime.now(timezone.utc).isoformat(),
        "mysqldump": str(args.mysqldump.resolve()),
        "net_buffer_length": args.net_buffer_length,
        "databases": records,
    }
    manifest_path = output_dir / "mysql-release-manifest.json"
    manifest_path.write_text(
        json.dumps(manifest, indent=2, ensure_ascii=True) + "\n",
        encoding="utf-8",
    )
    print(json.dumps(manifest, indent=2, ensure_ascii=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
