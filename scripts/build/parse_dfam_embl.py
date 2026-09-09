from __future__ import annotations

import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import gzip
import json
import re
from typing import Any


from path_helpers import data_path, repo_path

DFAM_EMBL_PATH = data_path("dfam", "Dfam-curated_only-1.embl.gz")
OUT_DIR = data_path("processed", "dfam")
ENTRIES_DIR = OUT_DIR / "entries"
CATALOG_PATH = OUT_DIR / "dfam_curated_catalog.json"
LOOKUP_INDEX_PATH = OUT_DIR / "dfam_lookup_index.json"
GRAPH_REPORT_PATH = OUT_DIR / "dfam_curated_match_report.json"
GRAPH_SOURCE_PATH = repo_path("archive", "processing_history", "data_update_fix", "te_kg2_final_standardized_new_standardized_fix.jsonl")
SUPPORTED_FEATURE_TYPES = {"CDS", "repeat_region", "LTR", "misc_feature", "polyA_signal", "promoter"}


def normalize_space(value: str) -> str:
    return " ".join(value.strip().split())


def clean_label(value: str) -> str:
    value = normalize_space(value)
    value = re.sub(r"<[^>]+>", "", value)
    return value.rstrip(".;,")


def canonicalize(value: str) -> str:
    return clean_label(value).lower().replace("_", "").replace("-", "").replace(" ", "")


def parse_keyword_lines(values: list[str]) -> list[str]:
    text = " ".join(values).replace(";", "; ")
    parts = [normalize_space(item) for item in text.split(";")]
    return [item.rstrip(".") for item in parts if item]


def parse_cross_reference(value: str) -> dict[str, str]:
    parts = [clean_label(part) for part in value.split(";") if clean_label(part)]
    if not parts:
        return {"database": "", "accession": ""}
    database = parts[0]
    accession = parts[1] if len(parts) > 1 else ""
    return {"database": database, "accession": accession}


def parse_feature_location(location: str) -> tuple[int, int] | None:
    numbers = [int(item) for item in re.findall(r"\d+", location)]
    if not numbers:
        return None
    start = min(numbers)
    end = max(numbers)
    if end < start:
        return None
    return start - 1, end


def normalize_feature_label(feature_type: str, qualifiers: dict[str, str]) -> str:
    preferred = (
        qualifiers.get("product")
        or qualifiers.get("note")
        or qualifiers.get("label")
        or feature_type
    )
    preferred = preferred.replace("[", "").replace("]", "").strip()
    preferred = re.sub(r"\s+", " ", preferred)
    if len(preferred) > 42:
        preferred = preferred[:39].rstrip() + "..."
    return preferred or feature_type


def detect_model_type(name: str, description: str) -> tuple[str, bool]:
    haystack = f"{name} {description}".lower()
    patterns = [
        (("3end", "3' end", "three prime"), "fragment_3end"),
        (("5end", "5' end", "five prime"), "fragment_5end"),
        ((("-int", "_int", " internal ")), "fragment_internal"),
        ((("_ltr", "-ltr", " ltr ")), "fragment_ltr"),
    ]
    for needles, model_type in patterns:
        if any(needle in haystack for needle in needles):
            return model_type, True
    if any(token in haystack for token in ("fragment", "partial", "truncated")):
        return "unknown_fragment", True
    return "full", False


def build_display_classification(entry: dict[str, Any]) -> str:
    keywords = entry.get("keywords", []) or []
    keyword_part = keywords[0] if keywords else ""
    species_part = entry.get("species", "")
    if keyword_part and species_part:
        return f"{keyword_part} | {species_part}"
    return keyword_part or species_part or ""


def parse_entry(block: str) -> dict[str, Any]:
    entry: dict[str, Any] = {
        "id": "",
        "accession": "",
        "name": "",
        "description": "",
        "keywords": [],
        "species": "",
        "classification": [],
        "cross_references": [],
        "references": [],
        "sequence_summary": {},
        "sequence": "",
        "feature_table": [],
        "display_classification": "",
        "model_type": "full",
        "is_fragment": False,
        "length_bp": None,
    }
    current_tag = ""
    current_ref: dict[str, str] | None = None
    current_feature: dict[str, Any] | None = None
    last_feature_qualifier: str | None = None
    sequence_lines: list[str] = []

    for raw_line in block.splitlines():
        line = raw_line.rstrip()
        if not line or line == "XX":
            continue
        if line == "//":
            break

        if line.startswith("     "):
            continuation = normalize_space(line)
            if current_tag == "SQ":
                sequence_lines.append("".join(ch for ch in continuation.lower() if ch in {"a", "c", "g", "t", "n"}))
            elif current_tag in {"RN", "RA", "RT", "RL"} and current_ref is not None:
                field_map = {
                    "RA": "authors",
                    "RT": "title",
                    "RL": "journal",
                    "RN": "index",
                }
                key = field_map.get(current_tag)
                if key:
                    current_ref[key] = normalize_space(f"{current_ref.get(key, '')} {continuation}")
            elif current_tag == "OC":
                entry["classification"].extend([item for item in [part.strip() for part in continuation.split(";")] if item])
            elif current_tag == "KW":
                entry["keywords"].append(continuation)
            elif current_tag == "DR":
                entry["cross_references"].append(parse_cross_reference(continuation))
            elif current_tag in {"DE", "NM", "OS", "ID", "AC"}:
                mapped_key = {
                    "DE": "description",
                    "NM": "name",
                    "OS": "species",
                    "ID": "id",
                    "AC": "accession",
                }[current_tag]
                entry[mapped_key] = normalize_space(f"{entry.get(mapped_key, '')} {continuation}")
            elif current_tag == "FT" and current_feature is not None:
                if continuation.startswith("/") and "=" in continuation:
                    qualifier, qualifier_value = continuation.split("=", 1)
                    qualifier_key = qualifier.lstrip("/").strip()
                    qualifier_clean = qualifier_value.strip().strip('"')
                    current_feature["qualifiers"][qualifier_key] = qualifier_clean
                    last_feature_qualifier = qualifier_key
                elif last_feature_qualifier:
                    continuation_value = continuation.strip().strip('"')
                    existing = current_feature["qualifiers"].get(last_feature_qualifier, "")
                    current_feature["qualifiers"][last_feature_qualifier] = normalize_space(
                        f"{existing} {continuation_value}"
                    )
            continue

        tag = line[:2]
        value = line[5:] if len(line) > 5 else ""
        current_tag = tag

        if tag == "ID":
            entry["id"] = normalize_space(value)
        elif tag == "AC":
            entry["accession"] = normalize_space(value)
        elif tag == "NM":
            entry["name"] = normalize_space(value)
        elif tag == "DE":
            entry["description"] = normalize_space(value)
        elif tag == "KW":
            entry["keywords"].append(normalize_space(value))
        elif tag == "OS":
            entry["species"] = normalize_space(value)
        elif tag == "OC":
            entry["classification"].extend([item for item in [part.strip() for part in value.split(";")] if item])
        elif tag == "DR":
            entry["cross_references"].append(parse_cross_reference(value))
        elif tag == "SQ":
            entry["sequence_summary"] = {"raw": normalize_space(value)}
            current_ref = None
            current_feature = None
            last_feature_qualifier = None
        elif tag == "RN":
            current_ref = {"index": normalize_space(value)}
            entry["references"].append(current_ref)
            current_feature = None
            last_feature_qualifier = None
        elif tag in {"RA", "RT", "RL"}:
            if current_ref is None:
                current_ref = {}
                entry["references"].append(current_ref)
            field_map = {"RA": "authors", "RT": "title", "RL": "journal"}
            current_ref[field_map[tag]] = normalize_space(value)
        elif tag == "FT":
            current_ref = None
            ft_value = value.rstrip()
            if not ft_value.strip():
                continue
            stripped = ft_value.strip()
            if stripped.startswith("/") and current_feature is not None and "=" in stripped:
                qualifier, qualifier_value = stripped.split("=", 1)
                qualifier_key = qualifier.lstrip("/").strip()
                qualifier_clean = qualifier_value.strip().strip('"')
                current_feature["qualifiers"][qualifier_key] = qualifier_clean
                last_feature_qualifier = qualifier_key
                continue

            feature_type = ft_value[:15].strip()
            location = ft_value[15:].strip()
            if feature_type not in SUPPORTED_FEATURE_TYPES:
                current_feature = None
                last_feature_qualifier = None
                continue
            parsed_location = parse_feature_location(location)
            if parsed_location is None:
                current_feature = None
                last_feature_qualifier = None
                continue
            start, end = parsed_location
            current_feature = {
                "type": feature_type,
                "location": location,
                "start": start,
                "end": end,
                "qualifiers": {},
            }
            entry["feature_table"].append(current_feature)
            last_feature_qualifier = None

    entry["keywords"] = parse_keyword_lines(entry["keywords"])
    entry["sequence"] = "".join(sequence_lines)
    entry["id"] = clean_label(entry["id"])
    entry["accession"] = clean_label(entry["accession"])
    entry["name"] = clean_label(entry["name"])
    entry["description"] = normalize_space(entry["description"])
    entry["species"] = clean_label(entry["species"])
    entry["classification"] = [clean_label(item) for item in entry["classification"] if clean_label(item)]
    entry["cross_references"] = [
        {"database": clean_label(item.get("database", "")), "accession": clean_label(item.get("accession", ""))}
        for item in entry["cross_references"]
        if clean_label(item.get("database", "")) or clean_label(item.get("accession", ""))
    ]
    entry["length_bp"] = len(entry["sequence"]) if entry["sequence"] else None
    model_type, is_fragment = detect_model_type(entry["name"], entry["description"])
    entry["model_type"] = model_type
    entry["is_fragment"] = is_fragment
    for feature in entry["feature_table"]:
        feature["label"] = normalize_feature_label(feature["type"], feature["qualifiers"])
    entry["display_classification"] = build_display_classification(entry)
    repbase_aliases = [
        clean_label(item.get("accession", ""))
        for item in entry["cross_references"]
        if item.get("database", "").lower() == "repbase" and clean_label(item.get("accession", ""))
    ]
    entry["repbase_aliases"] = sorted(set(repbase_aliases))
    return entry


def build_indexes(entries: list[dict[str, Any]]) -> tuple[dict[str, str], dict[str, str], dict[str, int]]:
    strict_index: dict[str, str] = {}
    canonical_index: dict[str, str] = {}
    accession_index: dict[str, int] = {}

    for index, entry in enumerate(entries):
        accession = entry["accession"]
        accession_index[accession] = index
        candidates = [entry["name"], entry["accession"], *entry.get("repbase_aliases", [])]
        for candidate in candidates:
            if not candidate:
                continue
            strict_key = clean_label(candidate).lower()
            canonical_key = canonicalize(candidate)
            if strict_key and strict_key not in strict_index:
                strict_index[strict_key] = accession
            if canonical_key and canonical_key not in canonical_index:
                canonical_index[canonical_key] = accession
    return strict_index, canonical_index, accession_index


def collect_graph_te_names() -> list[str]:
    names: set[str] = set()
    with GRAPH_SOURCE_PATH.open("r", encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            for entity in row.get("entities", {}).get("transposons", []) or []:
                name = clean_label(entity.get("name", ""))
                if name:
                    names.add(name)
    return sorted(names)


def build_lookup_index(entries: list[dict[str, Any]], strict_index: dict[str, str], canonical_index: dict[str, str]) -> dict[str, Any]:
    return {
        "source_file": str(DFAM_EMBL_PATH),
        "entry_count": len(entries),
        "name_index": strict_index,
        "canonical_index": canonical_index,
    }


def slim_entry(entry: dict[str, Any]) -> dict[str, Any]:
    return {
        "accession": entry.get("accession", ""),
        "name": entry.get("name", ""),
        "description": entry.get("description", ""),
        "keywords": entry.get("keywords", []),
        "species": entry.get("species", ""),
        "display_classification": entry.get("display_classification", ""),
        "classification": entry.get("classification", []),
        "sequence_summary": entry.get("sequence_summary", {}),
        "sequence": entry.get("sequence", ""),
        "length_bp": entry.get("length_bp"),
        "sequence_length_bp": entry.get("length_bp"),
        "model_type": entry.get("model_type", "full"),
        "is_fragment": entry.get("is_fragment", False),
        "feature_table": entry.get("feature_table", []),
        "repbase_aliases": entry.get("repbase_aliases", []),
    }


def write_entry_files(entries: list[dict[str, Any]]) -> None:
    ENTRIES_DIR.mkdir(parents=True, exist_ok=True)
    for existing in ENTRIES_DIR.glob('*.json'):
        existing.unlink()
    for entry in entries:
        accession = entry.get('accession', '')
        if not accession:
            continue
        (ENTRIES_DIR / f'{accession}.json').write_text(
            json.dumps(slim_entry(entry), ensure_ascii=False, indent=2),
            encoding='utf-8',
        )


def build_match_report(entries: list[dict[str, Any]], strict_index: dict[str, str], canonical_index: dict[str, str]) -> dict[str, Any]:
    graph_te_names = collect_graph_te_names()
    accession_index = {entry['accession']: entry for entry in entries if entry.get('accession')}
    matched: list[dict[str, Any]] = []
    unmatched: list[str] = []

    for name in graph_te_names:
        strict_key = clean_label(name).lower()
        canonical_key = canonicalize(name)
        accession = strict_index.get(strict_key) or canonical_index.get(canonical_key)
        if not accession or accession not in accession_index:
            unmatched.append(name)
            continue
        entry = accession_index[accession]
        matched.append({
            'graph_te_name': name,
            'dfam_accession': accession,
            'dfam_name': entry.get('name', ''),
            'sequence_length': entry.get('length_bp'),
            'model_type': entry.get('model_type'),
            'is_fragment': entry.get('is_fragment'),
            'repbase_aliases': entry.get('repbase_aliases', []),
        })

    return {
        'source_file': str(DFAM_EMBL_PATH),
        'graph_source': str(GRAPH_SOURCE_PATH),
        'dfam_entry_count': len(entries),
        'graph_unique_te_names': len(graph_te_names),
        'matched_graph_te_names': len(matched),
        'unmatched_graph_te_names': len(unmatched),
        'coverage_ratio': round(len(matched) / len(graph_te_names), 4) if graph_te_names else None,
        'sample_matched': matched[:50],
        'sample_unmatched': unmatched[:120],
    }


def main() -> None:
    if not DFAM_EMBL_PATH.is_file():
        raise FileNotFoundError(f"Dfam file not found: {DFAM_EMBL_PATH}")

    OUT_DIR.mkdir(parents=True, exist_ok=True)

    blocks: list[str] = []
    current: list[str] = []
    with gzip.open(DFAM_EMBL_PATH, 'rt', encoding='utf-8', errors='replace') as handle:
        for line in handle:
            current.append(line.rstrip('\n'))
            if line.strip() == '//':
                blocks.append('\n'.join(current))
                current = []

    entries = [parse_entry(block) for block in blocks if '\nID   ' in f'\n{block}']
    strict_index, canonical_index, accession_index = build_indexes(entries)

    catalog = {
        'source_file': str(DFAM_EMBL_PATH),
        'entry_count': len(entries),
        'entries': entries,
        'name_index': strict_index,
        'canonical_index': canonical_index,
        'accession_index': accession_index,
    }
    CATALOG_PATH.write_text(json.dumps(catalog, ensure_ascii=False, indent=2), encoding='utf-8')

    lookup_index = build_lookup_index(entries, strict_index, canonical_index)
    LOOKUP_INDEX_PATH.write_text(json.dumps(lookup_index, ensure_ascii=False, indent=2), encoding='utf-8')

    write_entry_files(entries)

    report = build_match_report(entries, strict_index, canonical_index)
    GRAPH_REPORT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')

    print(json.dumps({
        'catalog_path': str(CATALOG_PATH),
        'lookup_index_path': str(LOOKUP_INDEX_PATH),
        'entries_dir': str(ENTRIES_DIR),
        'report_path': str(GRAPH_REPORT_PATH),
        'entry_count': len(entries),
        'graph_matched_count': report['matched_graph_te_names'],
        'coverage_ratio': report['coverage_ratio'],
    }, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
