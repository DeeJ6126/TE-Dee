import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import csv
import json
import re
from pathlib import Path

from semantic_aliases import EXTRA_DISEASE_ALIASES
from path_helpers import ROOT


FOUND_CSV = ROOT / "archive" / "processing_history" / "tmp_icd11_csv" / "Found.csv"
NOT_FOUND_CSV = ROOT / "archive" / "processing_history" / "tmp_icd11_csv" / "Not Found.csv"
OUTPUT_JSON = ROOT / "data" / "processed" / "disease_top_class_map.json"


def normalize_whitespace(text: str) -> str:
    return re.sub(r"\s+", " ", str(text or "").strip())


def normalize_key(text: str) -> str:
    return normalize_whitespace(text).casefold()


def normalize_compare_key(text: str) -> str:
    text = normalize_key(text)
    text = text.replace("`", "'")
    text = re.sub(r"\(.*?\)", "", text)
    text = re.sub(r"[\s\-_]", "", text)
    return text


COMPARE_ALIAS_LOOKUP = {
    normalize_compare_key(alias): canonical
    for alias, canonical in EXTRA_DISEASE_ALIASES.items()
}


def canonicalize_disease_name(name: str) -> str:
    base = normalize_whitespace(name)
    if not base:
        return ""
    key = normalize_key(base)
    if key in EXTRA_DISEASE_ALIASES:
        return EXTRA_DISEASE_ALIASES[key]
    compare_key = normalize_compare_key(base)
    if compare_key in COMPARE_ALIAS_LOOKUP:
        return COMPARE_ALIAS_LOOKUP[compare_key]
    return base


def choose_class(existing: str | None, incoming: str) -> str:
    if not existing:
        return incoming
    if existing == incoming:
        return existing
    if existing == "others" and incoming != "others":
        return incoming
    if incoming == "others" and existing != "others":
        return existing
    return existing


def iter_rows():
    for path in (FOUND_CSV, NOT_FOUND_CSV):
        with path.open("r", encoding="utf-8-sig", newline="") as handle:
            for row in csv.DictReader(handle):
                disease = normalize_whitespace(row.get("Disease", ""))
                top_class = normalize_whitespace(row.get("Top_Class", ""))
                if disease and top_class:
                    yield {
                        "source": path.name,
                        "disease": disease,
                        "canonical_disease": canonicalize_disease_name(disease),
                        "top_class": top_class,
                    }


def build_disease_top_class_map() -> dict[str, str]:
    mapping: dict[str, str] = {}
    for row in iter_rows():
        canonical_name = row["canonical_disease"]
        mapping[canonical_name] = choose_class(mapping.get(canonical_name), row["top_class"])
    return dict(sorted(mapping.items(), key=lambda item: item[0].casefold()))


def build_compare_lookup(class_map: dict[str, str]) -> dict[str, str]:
    compare_lookup: dict[str, str] = {}
    for disease_name, top_class in class_map.items():
        compare_key = normalize_compare_key(disease_name)
        compare_lookup[compare_key] = choose_class(compare_lookup.get(compare_key), top_class)
    return compare_lookup


def lookup_disease_class(name: str, class_map: dict[str, str] | None = None) -> str:
    class_map = class_map or build_disease_top_class_map()
    canonical_name = canonicalize_disease_name(name)
    direct = class_map.get(canonical_name)
    if direct:
        return direct

    compare_lookup = build_compare_lookup(class_map)
    compare_key = normalize_compare_key(canonical_name)
    if compare_key in compare_lookup:
        return compare_lookup[compare_key]

    if compare_key.endswith("s") and compare_key[:-1] in compare_lookup:
        return compare_lookup[compare_key[:-1]]

    if canonical_name.endswith("s"):
        singular_name = canonical_name[:-1]
        if singular_name in class_map:
            return class_map[singular_name]

    return ""


def write_map(output_path: Path = OUTPUT_JSON) -> Path:
    mapping = build_disease_top_class_map()
    output_path.write_text(
        json.dumps(mapping, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    return output_path


if __name__ == "__main__":
    target = write_map()
    print(f"Wrote: {target}")
    print(f"Entries: {len(build_disease_top_class_map())}")