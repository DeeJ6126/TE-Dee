"""Verify that a casefold rebuild only adds previously omitted Browse TE names."""
from __future__ import annotations
import argparse
from collections import Counter
import json
from pathlib import Path
import pandas as pd
import pyarrow.parquet as pq


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--old-root", type=Path, required=True)
    parser.add_argument("--new-root", type=Path, required=True)
    args = parser.parse_args()
    old = pd.read_csv(args.old_root / "missing_browse_te.tsv", sep="\t")
    new = pd.read_csv(args.new_root / "missing_browse_te.tsv", sep="\t")
    old_names = set(old.loc[old.has_hg38_instance == 1, "te_name"])
    new_names = set(new.loc[new.has_hg38_instance == 1, "te_name"])
    added = new_names - old_names
    assert len(old_names) == 202 and len(new_names) == 226 and len(added) == 24
    assert old_names <= new_names
    assert int(new.instance_count.sum()) - int(old.instance_count.sum()) == 44316
    manifest = json.loads((args.new_root / "all_tissue_manifest.json").read_text())
    assert len(manifest["tissues"]) == 50
    added_evidence = Counter()
    verified = []
    for tissue in sorted(manifest["tissues"]):
        for filename in ("te_variant_gene_overlaps.parquet", "te_gene_summary.parquet"):
            before = pq.read_table(args.old_root / tissue / filename).to_pandas()
            after = pq.read_table(args.new_root / tissue / filename).to_pandas()
            retained = after.loc[after.te_name.isin(old_names)].reset_index(drop=True)
            pd.testing.assert_frame_equal(before.reset_index(drop=True), retained, check_exact=True)
            assert set(after.te_name) <= new_names
            if filename == "te_variant_gene_overlaps.parquet":
                added_evidence.update(after.loc[after.te_name.isin(added), "te_name"])
        verified.append(tissue)
        print(f"Unchanged original evidence and summaries: {tissue}", flush=True)
    assert set(added_evidence) == added
    result = {
        "status": "passed", "verified_tissues": verified,
        "old_te_names": len(old_names), "new_te_names": len(new_names),
        "added_instances": 44316,
        "added_evidence_rows_by_te": dict(sorted(added_evidence.items())),
        "old_results_exactly_unchanged": True,
    }
    (args.new_root / "casefold_regression_audit.json").write_text(json.dumps(result, indent=2) + "\n")
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
