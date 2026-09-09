#!/usr/bin/env python3
"""Audit strict Gene-symbol to GTEx eQTL Gene mapping for TE-Gene edges."""

from __future__ import annotations

import argparse
import csv
import gzip
import json
from collections import Counter, defaultdict
from pathlib import Path

from tqdm import tqdm


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_COEX = ROOT / "data/coexpression/analysis/v1/abs0.4_fdr0.05"
DEFAULT_ANNOTATION = ROOT / "data/coexpression/feature_annotation/feature_annotation.tsv"
DEFAULT_EQTL = ROOT / "data/eQTL/derived/gtex_v11_strict_te_overlap_v1/mysql"
DEFAULT_OUT = ROOT / "docs/eqtl/gene_mapping_audit.md"


def read_tsv_gz(path: Path):
    with gzip.open(path, "rt", encoding="utf-8", newline="") as handle:
        yield from csv.DictReader(handle, delimiter="\t")


def load_eqtl_genes(root: Path) -> tuple[dict[str, set[str]], dict[str, dict]]:
    manifest = json.loads((root / "manifest.json").read_text(encoding="utf-8"))
    name_to_bases: dict[str, set[str]] = defaultdict(set)
    base_to_records: dict[str, dict] = {}
    files = manifest["tables"]["eqtl_genes"]["files"]
    for entry in tqdm(files, desc="GTEx Gene annotations", unit="file"):
        for row in read_tsv_gz(root / entry["path"]):
            name = row["gene_name"]
            base = row["gene_id_base"]
            name_to_bases[name].add(base)
            record = base_to_records.setdefault(base, row)
            if record["gene_name"] != name or record["chrom"] != row["chrom"]:
                raise ValueError(f"Conflicting eQTL annotation for {base}")
    return name_to_bases, base_to_records


def load_annotation(path: Path) -> dict[str, dict[str, str]]:
    with path.open("r", encoding="utf-8", newline="") as handle:
        return {row["feature"]: row for row in csv.DictReader(handle, delimiter="\t")}


def load_coexpression_edges(root: Path) -> list[dict[str, str]]:
    edges: list[dict[str, str]] = []
    paths = sorted(root.glob("*_edges.tsv"))
    for path in tqdm(paths, desc="Co-expression edge files", unit="file"):
        with path.open("r", encoding="utf-8", newline="") as handle:
            for row in csv.DictReader(handle, delimiter="\t"):
                if row.get("pair_type") != "TE_gene":
                    continue
                if row.get("source_type") == "TE" and row.get("target_type") == "gene":
                    te, gene = row["source"], row["target"]
                elif row.get("target_type") == "TE" and row.get("source_type") == "gene":
                    te, gene = row["target"], row["source"]
                else:
                    continue
                edges.append({"te": te, "gene": gene, "context": row.get("context_type", "")})
    return edges


def load_eqtl_pairs(root: Path, base_to_records: dict[str, dict]) -> set[tuple[str, str]]:
    manifest = json.loads((root / "manifest.json").read_text(encoding="utf-8"))
    entries = manifest["tables"]["eqtl_te_gene_cross_tissue_summary"]["files"]
    pairs: set[tuple[str, str]] = set()
    for entry in tqdm(entries, desc="eQTL TE-Gene summaries", unit="part"):
        for row in read_tsv_gz(root / entry["path"]):
            base = row["gene_id"].split(".", 1)[0]
            if base not in base_to_records:
                raise ValueError(f"Missing Gene dimension row for {base}")
            pairs.add((row["te_name"], base))
    return pairs


def classify_gene(symbol: str, annotation: dict[str, dict[str, str]], name_to_bases: dict[str, set[str]]) -> tuple[str, set[str], str]:
    bases = name_to_bases.get(symbol, set())
    ann = annotation.get(symbol, {})
    confidence = ann.get("confidence", "").lower()
    feature_type = ann.get("feature_type", "").lower()
    if feature_type == "gene" and confidence == "high" and len(bases) == 1:
        return "unique_high_confidence_match", bases, "high-confidence co-expression Gene; exact eQTL gene_name; one gene_id_base"
    if len(bases) > 1:
        return "ambiguous_match", bases, "exact gene_name maps to multiple gene_id_base values"
    if len(bases) == 1 and confidence != "high":
        return "low_confidence_match", bases, "eQTL name matched, but co-expression annotation is not high confidence"
    if len(bases) == 1:
        return "unique_name_match_not_high_coexpression", bases, "exact eQTL gene_name match, but co-expression annotation is not high confidence"
    return "unmatched", set(), "no exact eQTL gene_name match"


def write_report(path: Path, stats: dict, gene_rows: list[dict], edge_rows: list[dict]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    lines = [
        "# Co-expression Gene to GTEx eQTL Mapping Audit",
        "",
        "## Scope and rule",
        "",
        "This is a read-only audit of the approved co-expression network at "
        "`data/coexpression/analysis/v1/abs0.4_fdr0.05` against the active GTEx "
        "version `gtex_v11_strict_te_overlap_v1`. Only `TE_gene` co-expression "
        "edges are counted. A Gene is eligible for evidence integration only "
        "when its co-expression feature is annotated as a high-confidence Gene, "
        "its symbol exactly matches eQTL `gene_name`, and that name resolves to "
        "one `gene_id_base`. Version suffixes such as `.16` are normalized by "
        "`gene_id_base`, while original IDs remain available in eQTL data.",
        "",
        "## Gene-level results",
        "",
        f"- Co-expression Gene symbols in TE-Gene edges: **{stats['gene_symbols']}**",
        f"- Unique high-confidence matches: **{stats['gene_status'].get('unique_high_confidence_match', 0)}**",
        f"- Exact name matches with non-high-confidence co-expression annotation: **{stats['gene_status'].get('unique_name_match_not_high_coexpression', 0)}**",
        f"- Low-confidence matches: **{stats['gene_status'].get('low_confidence_match', 0)}**",
        f"- Ambiguous matches: **{stats['gene_status'].get('ambiguous_match', 0)}**",
        f"- Unmatched: **{stats['gene_status'].get('unmatched', 0)}**",
        "",
        "## Edge-level results",
        "",
        f"- TE-Gene co-expression edge rows across contexts: **{stats['edge_rows']}**",
        f"- Distinct TE-Gene pairs: **{stats['pairs']}**",
        f"- Edges with eligible unique high-confidence Gene mapping: **{stats['eligible_edges']}**",
        f"- Eligible edges with any cross-tissue TE-overlap eQTL evidence: **{stats['eqlt_edges']}**",
        f"- Eligible edges without cross-tissue TE-overlap eQTL evidence: **{stats['eligible_edges'] - stats['eqlt_edges']}**",
        f"- Potential `Both` pairs (any tissue, before tissue-filter display): **{stats['both_pairs']}**",
        "",
        "## Interpretation",
        "",
        "The strict integration set is the unique high-confidence category. "
        "Low-confidence, ambiguous, and unmatched names are retained as audit "
        "outcomes but do not participate in `Both`. A mapped Gene without an "
        "eQTL pair is different from an unmatched Gene: its identity is known, "
        "but this strict TE-overlap dataset provides no corresponding evidence.",
        "",
        "## Status counts",
        "",
        "| Mapping status | Gene symbols | TE-Gene edge rows |",
        "|---|---:|---:|",
    ]
    edge_counts = Counter(row["mapping_status"] for row in edge_rows)
    for status in [
        "unique_high_confidence_match",
        "unique_name_match_not_high_coexpression",
        "low_confidence_match",
        "ambiguous_match",
        "unmatched",
    ]:
        lines.append(f"| `{status}` | {stats['gene_status'].get(status, 0)} | {edge_counts.get(status, 0)} |")
    lines.extend(["", "## Examples", "", "| Symbol | Status | eQTL gene_id_base | Reason |", "|---|---|---|---|"])
    examples = []
    for status in ["unmatched", "ambiguous_match", "low_confidence_match", "unique_high_confidence_match"]:
        examples.extend(row for row in gene_rows if row["mapping_status"] == status)
    for row in examples[:40]:
        bases = ", ".join(sorted(row["bases"])) or "-"
        lines.append(f"| `{row['symbol']}` | `{row['mapping_status']}` | `{bases}` | {row['reason']} |")
    lines.extend(["", "## Evidence boundary", "", "`Both` means the same TE–Gene pair has an eligible co-expression mapping and at least one eQTL TE–Gene cross-tissue summary in any GTEx tissue. It remains statistical/positional evidence, not proof of TE-mediated causality.", ""])
    path.write_text("\n".join(lines), encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--coexpression-root", type=Path, default=DEFAULT_COEX)
    parser.add_argument("--annotation", type=Path, default=DEFAULT_ANNOTATION)
    parser.add_argument("--eqtl-root", type=Path, default=DEFAULT_EQTL)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUT)
    args = parser.parse_args()

    annotation = load_annotation(args.annotation)
    name_to_bases, base_to_records = load_eqtl_genes(args.eqtl_root)
    edges = load_coexpression_edges(args.coexpression_root)
    eqtl_pairs = load_eqtl_pairs(args.eqtl_root, base_to_records)
    gene_symbols = sorted({row["gene"] for row in edges})
    gene_rows = []
    status_by_symbol = {}
    for symbol in tqdm(gene_symbols, desc="Classifying co-expression Genes", unit="Gene"):
        status, bases, reason = classify_gene(symbol, annotation, name_to_bases)
        status_by_symbol[symbol] = status
        gene_rows.append({"symbol": symbol, "mapping_status": status, "bases": bases, "reason": reason})
    pair_set = {(row["te"], row["gene"]) for row in edges}
    eligible_pairs = {
        pair for pair in pair_set if status_by_symbol[pair[1]] == "unique_high_confidence_match"
    }
    both_pairs = {
        (te, gene)
        for te, gene in eligible_pairs
        if any((te, base) in eqtl_pairs for base in name_to_bases[gene])
    }
    edge_rows = [dict(row, mapping_status=status_by_symbol[row["gene"]]) for row in edges]
    stats = {
        "gene_symbols": len(gene_symbols),
        "gene_status": Counter(status_by_symbol.values()),
        "edge_rows": len(edges),
        "pairs": len(pair_set),
        "eligible_edges": sum(row["mapping_status"] == "unique_high_confidence_match" for row in edge_rows),
        "eqlt_edges": sum((row["te"], row["gene"]) in both_pairs for row in edge_rows),
        "both_pairs": len(both_pairs),
    }
    write_report(args.output, stats, gene_rows, edge_rows)
    print(json.dumps({**stats, "gene_status": dict(stats["gene_status"])}, indent=2, sort_keys=True))


if __name__ == "__main__":
    main()
