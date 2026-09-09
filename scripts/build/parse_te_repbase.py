from __future__ import annotations

import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
import re
from typing import Any


from path_helpers import data_path

RAW_PATH = data_path("raw", "TE_Repbase.txt")
OUT_PATH = data_path("processed", "te_repbase_structured.json")
REPORT_PATH = data_path("processed", "te_repbase_report.json")
DB_TE_NAMES_PATH = data_path("processed", "_db_te_names_current.json")
ALIGNMENT_PATH = data_path("processed", "te_repbase_db_alignment.json")
DB_MATCHED_PATH = data_path("processed", "te_repbase_db_matched.json")


SINGLE_VALUE_TAGS = {"ID", "AC", "DE", "NM", "OS", "SQ"}
LIST_VALUE_TAGS = {"DT", "KW", "OC", "DR"}
REFERENCE_VALUE_TAGS = {"RN", "RP", "RA", "RT", "RL"}


def normalize_space(value: str) -> str:
    return " ".join(value.strip().split())


def clean_label(value: str) -> str:
    value = normalize_space(value)
    value = re.sub(r"<[^>]+>", "", value)
    return value.rstrip(".;,")


def canonicalize(value: str) -> str:
    value = clean_label(value).lower()
    value = value.replace("_", "").replace("-", "").replace(" ", "")
    return value


def parse_keyword_lines(values: list[str]) -> list[str]:
    text = " ".join(values).replace(";", "; ")
    parts = [normalize_space(item) for item in text.split(";")]
    return [item for item in parts if item]


def parse_sequence_summary(value: str) -> dict[str, Any]:
    summary = normalize_space(value)
    result: dict[str, Any] = {"raw": summary}
    pieces = [part.strip() for part in summary.split(";") if part.strip()]
    if pieces:
        result["headline"] = pieces[0]
    for piece in pieces[1:]:
        if " " not in piece:
            continue
        left, right = piece.split(" ", 1)
        if left.isdigit():
            result[right.strip().lower()] = int(left)
    return result


def parse_entry(block: str) -> dict[str, Any]:
    entry: dict[str, Any] = {
        "id": "",
        "accession": "",
        "name": "",
        "description": "",
        "dates": [],
        "keywords": [],
        "species": "",
        "classification": [],
        "cross_references": [],
        "references": [],
        "sequence_summary": {},
        "sequence": "",
    }
    current_tag = ""
    current_ref: dict[str, str] | None = None
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
            elif current_tag in REFERENCE_VALUE_TAGS and current_ref is not None:
                field_map = {
                    "RA": "authors",
                    "RT": "title",
                    "RL": "journal",
                    "RP": "positions",
                    "RN": "index",
                }
                key = field_map.get(current_tag)
                if key:
                    current_ref[key] = normalize_space(f"{current_ref.get(key, '')} {continuation}")
            elif current_tag == "OC":
                entry["classification"].extend([item for item in [part.strip() for part in continuation.split(";")] if item])
            elif current_tag == "KW":
                entry["keywords"].append(continuation)
            elif current_tag == "DT":
                entry["dates"].append(continuation)
            elif current_tag == "DR":
                entry["cross_references"].append(continuation)
            elif current_tag in {"DE", "NM", "OS", "ID", "AC"}:
                mapped_key = {
                    "DE": "description",
                    "NM": "name",
                    "OS": "species",
                    "ID": "id",
                    "AC": "accession",
                }[current_tag]
                entry[mapped_key] = normalize_space(f"{entry.get(mapped_key, '')} {continuation}")
            continue

        tag = line[:2]
        value = line[5:] if len(line) > 5 else ""
        current_tag = tag

        if tag == "ID":
            entry["id"] = normalize_space(value.split("repbase;", 1)[0])
        elif tag == "AC":
            entry["accession"] = normalize_space(value)
        elif tag == "NM":
            entry["name"] = normalize_space(value)
        elif tag == "DE":
            entry["description"] = normalize_space(value)
        elif tag == "DT":
            entry["dates"].append(normalize_space(value))
        elif tag == "KW":
            entry["keywords"].append(normalize_space(value))
        elif tag == "OS":
            entry["species"] = normalize_space(value)
        elif tag == "OC":
            entry["classification"].extend([item for item in [part.strip() for part in value.split(";")] if item])
        elif tag == "DR":
            entry["cross_references"].append(normalize_space(value))
        elif tag == "SQ":
            entry["sequence_summary"] = parse_sequence_summary(value)
            current_ref = None
        elif tag == "RN":
            current_ref = {"index": normalize_space(value)}
            entry["references"].append(current_ref)
        elif tag in REFERENCE_VALUE_TAGS:
            if current_ref is None:
                current_ref = {}
                entry["references"].append(current_ref)
            field_map = {
                "RP": "positions",
                "RA": "authors",
                "RT": "title",
                "RL": "journal",
            }
            current_ref[field_map[tag]] = normalize_space(value)

    entry["keywords"] = parse_keyword_lines(entry["keywords"])
    entry["sequence"] = "".join(sequence_lines)
    if not entry["name"]:
        entry["name"] = entry["id"]
    entry["id"] = clean_label(entry["id"])
    entry["accession"] = clean_label(entry["accession"])
    entry["name"] = clean_label(entry["name"])
    entry["description"] = normalize_space(entry["description"])
    entry["species"] = clean_label(entry["species"])
    entry["classification"] = [clean_label(item) for item in entry["classification"] if clean_label(item)]
    entry["keywords"] = [clean_label(item) for item in entry["keywords"] if clean_label(item)]
    return entry


def build_indexes(entries: list[dict[str, Any]]) -> tuple[dict[str, str], dict[str, str]]:
    strict_index: dict[str, str] = {}
    canonical_index: dict[str, str] = {}
    for entry in entries:
        canonical = entry["id"] or entry["name"]
        candidates = [entry["id"], entry["name"]]
        for candidate in candidates:
            if not candidate:
                continue
            strict_key = clean_label(candidate).lower()
            canonical_key = canonicalize(candidate)
            if strict_key and strict_key not in strict_index:
                strict_index[strict_key] = canonical
            if canonical_key and canonical_key not in canonical_index:
                canonical_index[canonical_key] = canonical
    return strict_index, canonical_index


def build_alignment(entries: list[dict[str, Any]], canonical_index: dict[str, str]) -> dict[str, Any]:
    if not DB_TE_NAMES_PATH.is_file():
        return {
            "db_te_source": str(DB_TE_NAMES_PATH),
            "db_te_present": False,
        }

    db_names = json.loads(DB_TE_NAMES_PATH.read_text(encoding="utf-8-sig"))
    db_names = [clean_label(name) for name in db_names if clean_label(name)]

    matched: list[dict[str, str]] = []
    unmatched: list[str] = []
    for name in db_names:
        key = canonicalize(name)
        repbase_id = canonical_index.get(key)
        if repbase_id:
            matched.append({"db_name": name, "repbase_id": repbase_id})
        else:
            unmatched.append(name)

    repbase_ids = {entry["id"] for entry in entries if entry["id"]}
    matched_repbase_ids = {item["repbase_id"] for item in matched}
    repbase_only = sorted(repbase_ids - matched_repbase_ids)

    return {
        "db_te_source": str(DB_TE_NAMES_PATH),
        "db_te_present": True,
        "db_te_count": len(db_names),
        "repbase_entry_count": len(entries),
        "matched_count": len(matched),
        "unmatched_db_te_count": len(unmatched),
        "repbase_only_count": len(repbase_only),
        "matched": matched,
        "unmatched_db_te": unmatched,
        "repbase_only_ids": repbase_only,
    }


def build_db_matched_payload(entries: list[dict[str, Any]], alignment: dict[str, Any]) -> dict[str, Any]:
    entry_by_id = {entry["id"]: entry for entry in entries if entry["id"]}
    matches = alignment.get("matched", [])
    matched_ids = []
    seen_ids: set[str] = set()
    db_to_repbase: dict[str, str] = {}
    for item in matches:
        db_name = item["db_name"]
        repbase_id = item["repbase_id"]
        db_to_repbase[db_name] = repbase_id
        if repbase_id not in seen_ids:
            seen_ids.add(repbase_id)
            matched_ids.append(repbase_id)

    matched_entries = [entry_by_id[repbase_id] for repbase_id in matched_ids if repbase_id in entry_by_id]
    strict_index = {clean_label(item["db_name"]).lower(): item["repbase_id"] for item in matches}
    canonical_index = {canonicalize(item["db_name"]): item["repbase_id"] for item in matches}

    return {
        "meta": {
            "source": str(RAW_PATH),
            "db_te_source": str(DB_TE_NAMES_PATH),
            "matched_db_te_count": len(matches),
            "matched_entry_count": len(matched_entries),
        },
        "db_to_repbase": db_to_repbase,
        "entries": matched_entries,
        "name_index": strict_index,
        "canonical_index": canonical_index,
    }


def main() -> None:
    text = RAW_PATH.read_text(encoding="utf-8")
    blocks = [block.strip() for block in text.replace("\r\n", "\n").split("//") if block.strip()]
    entries = [parse_entry(block) for block in blocks]
    entries = [entry for entry in entries if entry["id"]]

    name_index, canonical_index = build_indexes(entries)
    alignment = build_alignment(entries, canonical_index)
    payload = {
        "meta": {
            "source": str(RAW_PATH),
            "entry_count": len(entries),
        },
        "entries": entries,
        "name_index": name_index,
        "canonical_index": canonical_index,
    }
    OUT_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")

    report = {
        "source": str(RAW_PATH),
        "output": str(OUT_PATH),
        "entry_count": len(entries),
        "strict_name_index_count": len(name_index),
        "canonical_index_count": len(canonical_index),
        "with_description": sum(1 for entry in entries if entry["description"]),
        "with_keywords": sum(1 for entry in entries if entry["keywords"]),
        "with_references": sum(1 for entry in entries if entry["references"]),
        "with_sequence": sum(1 for entry in entries if entry["sequence"]),
        "db_alignment": {
            "matched_count": alignment.get("matched_count"),
            "unmatched_db_te_count": alignment.get("unmatched_db_te_count"),
            "repbase_only_count": alignment.get("repbase_only_count"),
        },
    }
    REPORT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    ALIGNMENT_PATH.write_text(json.dumps(alignment, ensure_ascii=False, indent=2), encoding="utf-8")
    DB_MATCHED_PATH.write_text(
        json.dumps(build_db_matched_payload(entries, alignment), ensure_ascii=False, indent=2),
        encoding="utf-8",
    )


if __name__ == "__main__":
    main()
