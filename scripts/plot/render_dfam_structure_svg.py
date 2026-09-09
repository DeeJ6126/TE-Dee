from __future__ import annotations

import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import argparse
import json
import sys

SCRIPT_ROOT = Path(__file__).resolve().parents[1]
if str(SCRIPT_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPT_ROOT))
if str(SCRIPT_ROOT / "plot") not in sys.path:
    sys.path.insert(0, str(SCRIPT_ROOT / "plot"))

from path_helpers import data_path  # noqa: E402

from base_SVG import render_structure_svg  # noqa: E402

CATALOG_PATH = data_path("processed", "dfam", "dfam_curated_catalog.json")
PLOTS_DIR = data_path("processed", "dfam", "plots")

FRAGMENT_LABELS = {
    "fragment_3end": "3' end fragment model",
    "fragment_5end": "5' end fragment model",
    "fragment_internal": "internal fragment model",
    "fragment_ltr": "LTR fragment model",
    "unknown_fragment": "fragment model",
}


def load_catalog() -> dict:
    return json.loads(CATALOG_PATH.read_text(encoding="utf-8"))


def entry_for_accession(catalog: dict, accession: str) -> dict | None:
    index = catalog.get("accession_index", {}).get(accession)
    if index is None:
        return None
    entries = catalog.get("entries", [])
    if not isinstance(index, int) or index < 0 or index >= len(entries):
        return None
    return entries[index]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("accession")
    parser.add_argument("--force", action="store_true")
    args = parser.parse_args()

    catalog = load_catalog()
    entry = entry_for_accession(catalog, args.accession)
    if entry is None:
        print(json.dumps({"ok": False, "error": "accession_not_found", "accession": args.accession}))
        return 1

    output_path = PLOTS_DIR / f"{args.accession}.svg"
    if output_path.exists() and not args.force:
        print(json.dumps({
            "ok": True,
            "cached": True,
            "output_path": str(output_path),
            "accession": args.accession,
        }))
        return 0

    feature_table = entry.get("feature_table", []) or []
    model_type = str(entry.get("model_type") or "full")
    fragment_label = FRAGMENT_LABELS.get(model_type, "")
    subtitle_parts = []
    if entry.get("display_classification"):
        subtitle_parts.append(str(entry["display_classification"]))
    if entry.get("accession"):
        subtitle_parts.append(str(entry["accession"]))
    subtitle = " | ".join(subtitle_parts)

    render_structure_svg(
        sequence_length=int(entry.get("length_bp") or 1),
        title=str(entry.get("name") or args.accession),
        output_path=output_path,
        feature_table=feature_table,
        subtitle=subtitle,
        fragment_label=fragment_label,
    )

    print(json.dumps({
        "ok": True,
        "cached": False,
        "output_path": str(output_path),
        "accession": args.accession,
        "feature_count": len(feature_table),
        "model_type": model_type,
    }))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
