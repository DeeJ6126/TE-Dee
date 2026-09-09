import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
import math
import sys
from urllib.request import Request, urlopen
from path_helpers import terminology_path


REPORT_PATH = terminology_path("missing_terminology_report.json")
OUTPUT_DIR = terminology_path()
RELAY_URL = "http://127.0.0.1:18087/chat"


SECTION_MAP = {
    "zh_disease": ("zh_mode_exposed_english", "Disease"),
    "zh_function": ("zh_mode_exposed_english", "Function"),
    "en_disease": ("en_mode_exposed_chinese", "Disease"),
    "en_function": ("en_mode_exposed_chinese", "Function"),
}


def load_terms(section_key: str) -> list[str]:
    if section_key not in SECTION_MAP:
        raise SystemExit(f"Unsupported section '{section_key}'. Choices: {', '.join(SECTION_MAP)}")
    report = json.loads(REPORT_PATH.read_text(encoding="utf-8"))
    section, subtype = SECTION_MAP[section_key]
    return list(report[section][subtype])


def build_prompt(section_key: str, terms: list[str]) -> str:
    if section_key.startswith("zh_"):
        return (
            "You are helping maintain a bilingual biomedical terminology table for a transposable-element knowledge graph. "
            "The UI is in Chinese mode but these items still need normalized Chinese display names and canonical English names. "
            "Return STRICT JSON only: an array of objects. "
            "Each object must have exactly these keys: source, display_zh, canonical_en, should_translate, note. "
            "Rules: preserve biomedical accuracy; display_zh should be concise and natural; canonical_en should be normalized English; "
            "for abbreviations, expand only if high confidence; if a term is already Chinese, keep it in display_zh and provide canonical_en when possible; "
            "if a term is too broad or not ideal for translation, explain briefly in note; no markdown, no extra text.\nTerms:\n"
            + "\n".join(f"- {t}" for t in terms)
        )
    return (
        "You are helping maintain a bilingual biomedical terminology table for a transposable-element knowledge graph. "
        "The UI is in English mode but these items still need normalized English display names and natural Chinese display names. "
        "Return STRICT JSON only: an array of objects. "
        "Each object must have exactly these keys: source, display_zh, canonical_en, should_translate, note. "
        "Rules: preserve biomedical accuracy; canonical_en should be concise natural English; display_zh should be concise natural Chinese; "
        "expand abbreviations only if high confidence; no markdown, no extra text.\nTerms:\n"
        + "\n".join(f"- {t}" for t in terms)
    )


def call_relay(prompt: str) -> str:
    payload = {
        "messages": [
            {"role": "system", "content": "You are a precise biomedical terminology normalizer."},
            {"role": "user", "content": prompt},
        ],
        "temperature": 0.1,
        "enable_thinking": False,
    }
    req = Request(
        RELAY_URL,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urlopen(req, timeout=120) as resp:
        payload = json.loads(resp.read().decode("utf-8"))
    return payload["response"]["choices"][0]["message"]["content"]


def main() -> None:
    if len(sys.argv) < 2:
        raise SystemExit(
            "Usage: python scripts/generate_translation_candidates.py <section> [batch_size] [start_index]\n"
            "Sections: zh_disease, zh_function, en_disease, en_function"
        )

    section_key = sys.argv[1]
    batch_size = int(sys.argv[2]) if len(sys.argv) >= 3 else 50
    start_index = int(sys.argv[3]) if len(sys.argv) >= 4 else 0

    terms = load_terms(section_key)
    batch = terms[start_index:start_index + batch_size]
    if not batch:
        raise SystemExit("No terms in requested batch.")

    content = call_relay(build_prompt(section_key, batch))
    batch_no = math.floor(start_index / batch_size) + 1
    out_path = OUTPUT_DIR / f"{section_key}_translation_candidates_batch{batch_no:02d}.json"
    out_path.write_text(content, encoding="utf-8")
    print(out_path)
    print(json.dumps({
        "section": section_key,
        "batch_size": len(batch),
        "start_index": start_index,
        "output": str(out_path),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
