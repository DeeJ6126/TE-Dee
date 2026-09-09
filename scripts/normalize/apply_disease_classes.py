import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
import sys

from disease_top_class import build_disease_top_class_map, canonicalize_disease_name, lookup_disease_class
from path_helpers import ROOT


PRIMARY_RAW_FILE = ROOT / "data" / "raw" / "te_kg2.jsonl"
LEGACY_RAW_FILE = ROOT / "data" / "archive" / "legacy" / "raw" / "output.jsonl"
REPORT_JSON = ROOT / "data" / "processed" / "disease_top_class_apply_report.json"


def iter_json_objects(path: Path):
    decoder = json.JSONDecoder()
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            raw = line.strip()
            if not raw:
                continue
            index = 0
            while index < len(raw):
                obj, end = decoder.raw_decode(raw, index)
                yield obj
                index = end
                while index < len(raw) and raw[index].isspace():
                    index += 1


def annotate_jsonl(path: Path, class_map: dict[str, str]) -> dict:
    updated_records = 0
    updated_entities = 0
    unmatched_entities: dict[str, int] = {}
    lines_out: list[str] = []

    for record in iter_json_objects(path):
        touched = False
        for disease in (record.get("entities") or {}).get("diseases", []) or []:
            raw_name = disease.get("name", "")
            canonical_name = canonicalize_disease_name(raw_name)
            top_class = lookup_disease_class(canonical_name, class_map)
            if top_class:
                if disease.get("disease_class") != top_class:
                    disease["disease_class"] = top_class
                    touched = True
                    updated_entities += 1
            else:
                unmatched_entities[canonical_name] = unmatched_entities.get(canonical_name, 0) + 1
        if touched:
            updated_records += 1
        lines_out.append(json.dumps(record, ensure_ascii=False))

    path.write_text("\n".join(lines_out) + "\n", encoding="utf-8")
    return {
        "file": str(path),
        "updated_records": updated_records,
        "updated_entities": updated_entities,
        "unmatched_unique_diseases": len(unmatched_entities),
        "unmatched_diseases": dict(sorted(unmatched_entities.items(), key=lambda item: item[0].casefold())),
    }


def main() -> None:
    class_map = build_disease_top_class_map()
    include_legacy = "--include-legacy-output-jsonl" in sys.argv
    raw_files = [PRIMARY_RAW_FILE]
    if include_legacy:
        raw_files.append(LEGACY_RAW_FILE)

    reports = [annotate_jsonl(path, class_map) for path in raw_files if path.exists()]
    payload = {
        "class_map_entries": len(class_map),
        "legacy_output_jsonl_included": include_legacy,
        "files": reports,
    }
    REPORT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(payload, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
