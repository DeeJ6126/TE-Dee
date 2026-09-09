from __future__ import annotations

import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import hashlib
import json
import re
from collections import Counter, defaultdict
from path_helpers import ROOT

RMSK_PATH = ROOT / "data" / "rmsk.txt"
OUT_DIR = ROOT / "data" / "processed" / "rmsk"
KARYOTYPE_DIR = OUT_DIR / "karyotype"
INDEX_PATH = OUT_DIR / "karyotype_index.json"
BIN_SIZE_BP = 1_000_000
ASSEMBLY = "hg38"
ASSEMBLY_LABEL = "Homo sapiens [hg38]"
PRIMARY_CHROMS = [f"chr{i}" for i in range(1, 23)] + ["chrX", "chrY"]
PRIMARY_CHROM_SET = set(PRIMARY_CHROMS)


def clean_label(value: str) -> str:
    value = re.sub(r"<[^>]+>", "", value.strip())
    value = re.sub(r"\s+", " ", value)
    return value.rstrip(".;,")


def lower_label(value: str) -> str:
    return clean_label(value).lower()


def canonicalize_label(value: str) -> str:
    return re.sub(r"[_\-\s]+", "", lower_label(value))


def parse_rmsk_int(value: str) -> int:
    return int(value.strip().strip("()"))


def sanitize_filename(value: str) -> str:
    slug = re.sub(r"[^A-Za-z0-9._-]+", "_", value.strip())
    slug = slug.strip("._-") or "te"
    digest = hashlib.sha1(value.encode("utf-8")).hexdigest()[:10]
    return f"{slug}__{digest}.json"


def build_clusters(counts: Counter[int], chrom_size: int) -> list[list[int]]:
    if not counts:
        return []

    clusters: list[list[int]] = []
    previous_idx: int | None = None
    previous_count: int | None = None

    for bin_idx in sorted(counts.keys()):
        count = counts[bin_idx]
        start = bin_idx * BIN_SIZE_BP + 1
        end = min((bin_idx + 1) * BIN_SIZE_BP, chrom_size)

        if (
            clusters
            and previous_idx is not None
            and previous_count is not None
            and bin_idx == previous_idx + 1
            and count == previous_count
        ):
            clusters[-1][1] = end
        else:
            clusters.append([start, end, count])

        previous_idx = bin_idx
        previous_count = count

    return clusters


def main() -> None:
    if not RMSK_PATH.is_file():
        raise FileNotFoundError(f"Missing rmsk file: {RMSK_PATH}")

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    KARYOTYPE_DIR.mkdir(parents=True, exist_ok=True)

    chrom_sizes: dict[str, int] = {chrom: 0 for chrom in PRIMARY_CHROMS}
    te_bins: dict[str, dict[str, Counter[int]]] = defaultdict(lambda: defaultdict(Counter))
    te_total_hits: Counter[str] = Counter()

    with RMSK_PATH.open("r", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            parts = line.split()
            if len(parts) < 17:
                continue

            chrom = parts[5]
            if chrom not in PRIMARY_CHROM_SET:
                continue

            start = parse_rmsk_int(parts[6])
            end = parse_rmsk_int(parts[7])
            left = parse_rmsk_int(parts[8])
            rep_name = clean_label(parts[10])
            if not rep_name:
                continue

            chrom_sizes[chrom] = max(chrom_sizes[chrom], end + abs(left))
            te_total_hits[rep_name] += 1
            te_bins[rep_name][chrom][start // BIN_SIZE_BP] += 1

    entries: dict[str, dict[str, object]] = {}
    name_index: dict[str, str] = {}
    canonical_index: dict[str, str] = {}

    for te_name in sorted(te_bins.keys(), key=str.lower):
        file_name = sanitize_filename(te_name)
        rel_path = f"/TE-/data/processed/rmsk/karyotype/{file_name}"
        singleton_contigs = []
        chromosomes_present: list[str] = []
        max_bin_count = 0

        for chrom in PRIMARY_CHROMS:
            counts = te_bins[te_name].get(chrom, Counter())
            clusters = build_clusters(counts, chrom_sizes[chrom])
            if clusters:
                chromosomes_present.append(chrom)
                max_bin_count = max(max_bin_count, max(cluster[2] for cluster in clusters))
            singleton_contigs.append(
                {
                    "name": chrom,
                    "size": chrom_sizes[chrom],
                    "hit_clusters": clusters,
                    "nrph_hit_clusters": [],
                }
            )

        te_payload = {
            "te_name": te_name,
            "assembly": ASSEMBLY,
            "assembly_label": ASSEMBLY_LABEL,
            "bin_size_bp": BIN_SIZE_BP,
            "total_hits": te_total_hits[te_name],
            "chromosomes_present": chromosomes_present,
            "max_bin_count": max_bin_count,
            "legend_max": max_bin_count,
            "singleton_contigs": singleton_contigs,
            "remaining_genome_contig": None,
        }
        (KARYOTYPE_DIR / file_name).write_text(json.dumps(te_payload, indent=2, ensure_ascii=False), encoding="utf-8")

        entries[te_name] = {
            "te_name": te_name,
            "assembly": ASSEMBLY,
            "assembly_label": ASSEMBLY_LABEL,
            "bin_size_bp": BIN_SIZE_BP,
            "total_hits": te_total_hits[te_name],
            "chromosomes_present": chromosomes_present,
            "max_bin_count": max_bin_count,
            "legend_max": max_bin_count,
            "data_json_path": rel_path,
        }
        name_index[lower_label(te_name)] = te_name
        canonical_index[canonicalize_label(te_name)] = te_name

    payload = {
        "metadata": {
            "assembly": ASSEMBLY,
            "assembly_label": ASSEMBLY_LABEL,
            "bin_size_bp": BIN_SIZE_BP,
            "chromosomes": PRIMARY_CHROMS,
            "source_file": RMSK_PATH.name,
            "te_count": len(entries),
        },
        "name_index": name_index,
        "canonical_index": canonical_index,
        "entries": entries,
    }
    INDEX_PATH.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps({"te_count": len(entries), "index_path": str(INDEX_PATH), "karyotype_dir": str(KARYOTYPE_DIR)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
