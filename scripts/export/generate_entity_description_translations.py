import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
import math
import sys
from urllib.request import Request, urlopen
from path_helpers import ROOT


ENTITY_PATH = ROOT / "data" / "processed" / "entity_descriptions.json"
RELAY_URL = "http://127.0.0.1:18087/chat"
DEFAULT_MODEL = "qwen3.5-plus-2026-02-15"


def load_entities(entity_type: str) -> list[tuple[str, str]]:
    payload = json.loads(ENTITY_PATH.read_text(encoding="utf-8"))
    source = payload.get("en", {}).get(entity_type, {})
    return [(name, desc) for name, desc in source.items() if str(desc or "").strip()]


def build_prompt(entity_type: str, batch: list[tuple[str, str]]) -> str:
    return (
        "You are helping maintain bilingual descriptions for a biomedical transposable-element knowledge graph. "
        f"Translate the following {entity_type} descriptions into concise, natural, academically appropriate Simplified Chinese. "
        "Keep biomedical meaning accurate. Return STRICT JSON only: an array of objects. "
        "Each object must have exactly these keys: name, zh_description. "
        "Do not add markdown, numbering, or extra commentary.\n\n"
        "Items:\n"
        + "\n".join(
            f"- name: {name}\n  description: {description}"
            for name, description in batch
        )
    )


def call_relay(prompt: str, model: str) -> list[dict]:
    payload = {
        "messages": [
            {"role": "system", "content": "You are a precise biomedical translation assistant."},
            {"role": "user", "content": prompt},
        ],
        "temperature": 0.1,
        "enable_thinking": False,
        "model": model,
    }
    req = Request(
        RELAY_URL,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urlopen(req, timeout=180) as resp:
        data = json.loads(resp.read().decode("utf-8"))
    content = data["response"]["choices"][0]["message"]["content"]
    return json.loads(content)


def merge_results(entity_type: str, rows: list[dict]) -> dict:
    payload = json.loads(ENTITY_PATH.read_text(encoding="utf-8"))
    target = payload.setdefault("zh", {}).setdefault(entity_type, {})
    added = 0
    updated = 0
    for row in rows:
        name = str(row.get("name", "")).strip()
        zh_description = str(row.get("zh_description", "")).strip()
        if not name or not zh_description:
            continue
        if name in target:
            if target[name] != zh_description:
                target[name] = zh_description
                updated += 1
        else:
            target[name] = zh_description
            added += 1
    ENTITY_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    return {"added": added, "updated": updated, "zh_total": len(target)}


def main() -> None:
    if len(sys.argv) < 2:
        raise SystemExit(
            "Usage: python scripts/generate_entity_description_translations.py <Disease|Function> [batch_size] [start_index] [model]"
        )

    entity_type = sys.argv[1]
    if entity_type not in {"Disease", "Function"}:
        raise SystemExit("entity_type must be Disease or Function")

    batch_size = int(sys.argv[2]) if len(sys.argv) >= 3 else 100
    start_index = int(sys.argv[3]) if len(sys.argv) >= 4 else 0
    model = sys.argv[4] if len(sys.argv) >= 5 else DEFAULT_MODEL

    entities = load_entities(entity_type)
    batch = entities[start_index:start_index + batch_size]
    if not batch:
        raise SystemExit("No items in requested batch.")

    translated = call_relay(build_prompt(entity_type, batch), model)
    stats = merge_results(entity_type, translated)
    batch_no = math.floor(start_index / batch_size) + 1
    print(
        json.dumps(
            {
                "entity_type": entity_type,
                "batch_no": batch_no,
                "batch_size": len(batch),
                "start_index": start_index,
                "model": model,
                **stats,
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
