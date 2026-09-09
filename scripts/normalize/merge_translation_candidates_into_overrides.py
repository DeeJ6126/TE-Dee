import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
from path_helpers import terminology_path


TERMINOLOGY_DIR = terminology_path()
OVERRIDES_PATH = TERMINOLOGY_DIR / "te_terminology_overrides.json"
REPORT_PATH = TERMINOLOGY_DIR / "translation_candidate_merge_report.json"


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def save_json(path: Path, payload) -> None:
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def candidate_files() -> list[Path]:
    files = [TERMINOLOGY_DIR / "zh_disease_translation_candidates.json"]
    files.extend(sorted(TERMINOLOGY_DIR.glob("zh_function_translation_candidates_batch*.json")))
    return [p for p in files if p.exists()]


def main() -> None:
    payload = load_json(OVERRIDES_PATH)
    payload.setdefault("names", {}).setdefault("zh", {})
    payload.setdefault("names", {}).setdefault("en", {})
    payload.setdefault("relations", {}).setdefault("zh", {})
    payload.setdefault("relations", {}).setdefault("en", {})

    names_zh = payload["names"]["zh"]
    names_en = payload["names"]["en"]

    stats = {
        "files_processed": [],
        "items_processed": 0,
        "zh_entries_added": 0,
        "zh_entries_updated": 0,
        "en_entries_added": 0,
        "en_entries_updated": 0,
    }

    for file_path in candidate_files():
        items = load_json(file_path)
        stats["files_processed"].append(file_path.name)
        for item in items:
            stats["items_processed"] += 1
            source = str(item.get("source", "")).strip()
            display_zh = str(item.get("display_zh", "")).strip()
            canonical_en = str(item.get("canonical_en", "")).strip()
            if not source or not display_zh or not canonical_en:
                continue

            # Chinese mode lookup: source term -> preferred Chinese display
            previous_zh = names_zh.get(source)
            if previous_zh is None:
                names_zh[source] = display_zh
                stats["zh_entries_added"] += 1
            elif previous_zh != display_zh:
                names_zh[source] = display_zh
                stats["zh_entries_updated"] += 1

            # English mode lookup: Chinese display -> canonical English
            previous_en = names_en.get(display_zh)
            if previous_en is None:
                names_en[display_zh] = canonical_en
                stats["en_entries_added"] += 1
            elif previous_en != canonical_en:
                names_en[display_zh] = canonical_en
                stats["en_entries_updated"] += 1

    save_json(OVERRIDES_PATH, payload)
    save_json(REPORT_PATH, stats)
    print(json.dumps(stats, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
