import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
import re
import sys
import time
from collections import defaultdict
from urllib.request import Request, urlopen

from semantic_aliases import EXTRA_DISEASE_ALIASES, EXTRA_FUNCTION_ALIASES
from path_helpers import ROOT


ENTITY_PATH = ROOT / "data" / "processed" / "entity_descriptions.json"
BACKUP_PATH = ROOT / "data" / "processed" / "entity_descriptions.pre_en_cleanup.json"
REPORT_PATH = ROOT / "data" / "processed" / "entity_description_normalization_report.json"
KEY_CACHE_PATH = ROOT / "data" / "processed" / "entity_description_key_translation_cache.json"
EN_CACHE_PATH = ROOT / "data" / "processed" / "entity_description_en_translation_cache.json"
RELAY_URL = "http://127.0.0.1:18087/chat"
DEFAULT_PROVIDER = "qwen"
DEFAULT_MODEL = "qwen3.5-plus"

ZH_RE = re.compile(r"[\u3400-\u4dbf\u4e00-\u9fff]")
WS_RE = re.compile(r"\s+")

ALIASES = {
    "Disease": EXTRA_DISEASE_ALIASES,
    "Function": EXTRA_FUNCTION_ALIASES,
}


def contains_zh(text: str) -> bool:
    return bool(ZH_RE.search(str(text or "")))


def clean_text(text: str) -> str:
    text = str(text or "").strip()
    text = (
        text.replace("‘", "'")
        .replace("’", "'")
        .replace("“", '"')
        .replace("”", '"')
        .replace("（", "(")
        .replace("）", ")")
    )
    text = WS_RE.sub(" ", text)
    return text.strip(" \t\r\n.;,")


def alias_key(text: str) -> str:
    return clean_text(text).lower()


def source_name_key(text: str) -> str:
    text = clean_text(text)
    return re.sub(r"\s+", "", text).lower()


def load_json(path: Path, default):
    if not path.exists():
        return default
    return json.loads(path.read_text(encoding="utf-8"))


def save_json(path: Path, payload) -> None:
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")


def call_relay_json(system_prompt: str, user_prompt: str, provider: str, model: str, retries: int = 3):
    payload = {
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_prompt},
        ],
        "temperature": 0.1,
        "enable_thinking": False,
        "provider": provider,
        "model": model,
    }
    req = Request(
        RELAY_URL,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    last_error = None
    for attempt in range(1, retries + 1):
        try:
            with urlopen(req, timeout=180) as resp:
                data = json.loads(resp.read().decode("utf-8"))
            content = data["response"]["choices"][0]["message"]["content"]
            try:
                return json.loads(content)
            except Exception:
                match = re.search(r"(\[.*\]|\{.*\})", content, re.S)
                if match:
                    return json.loads(match.group(1))
                raise
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            if attempt < retries:
                time.sleep(1.5 * attempt)
    raise RuntimeError(f"Relay request failed after {retries} attempts: {last_error}")


def ensure_rows(payload):
    if isinstance(payload, list):
        return payload
    if isinstance(payload, dict):
        return [payload]
    if isinstance(payload, str):
        parsed = json.loads(payload)
        return ensure_rows(parsed)
    raise TypeError(f"Unexpected relay payload type: {type(payload).__name__}")


def translate_chinese_keys(entity_type: str, names: list[str], cache: dict, provider: str, model: str, batch_size: int):
    pending = [name for name in names if source_name_key(name) not in cache]
    if not pending:
        return

    system_prompt = (
        "You normalize biomedical entity names for a transposable-element knowledge graph. "
        "Return strict JSON only."
    )
    for start in range(0, len(pending), batch_size):
        batch = pending[start:start + batch_size]
        user_prompt = (
            f"Convert the following {entity_type} names into canonical English biomedical entity names. "
            "Keep names concise and standardized. Do not explain. "
            "Return a JSON array. Each item must have exactly these keys: source_name, canonical_en_name.\n\n"
            "Names:\n- " + "\n- ".join(batch)
        )
        try:
            rows = ensure_rows(call_relay_json(system_prompt, user_prompt, provider, model))
        except Exception:
            if len(batch) == 1:
                raise
            midpoint = max(1, len(batch) // 2)
            translate_chinese_keys(entity_type, batch[:midpoint], cache, provider, model, max(1, midpoint))
            translate_chinese_keys(entity_type, batch[midpoint:], cache, provider, model, max(1, len(batch) - midpoint))
            continue
        for row in rows:
            source_name = clean_text(row.get("source_name", ""))
            canonical_en_name = clean_text(row.get("canonical_en_name", ""))
            if source_name and canonical_en_name:
                cache[source_name_key(source_name)] = canonical_en_name
        save_json(KEY_CACHE_PATH, cache)


def normalize_en_key(name: str, entity_type: str, key_cache: dict) -> str:
    cleaned = clean_text(name)
    if not cleaned:
        return cleaned
    if contains_zh(cleaned):
        mapped = clean_text(key_cache.get(source_name_key(cleaned), cleaned))
        if mapped:
            cleaned = mapped
    alias_map = ALIASES.get(entity_type, {})
    aliased = alias_map.get(alias_key(cleaned))
    return clean_text(aliased or cleaned)


def prefer_description(existing: str, incoming: str, lang: str) -> str:
    existing = str(existing or "").strip()
    incoming = str(incoming or "").strip()
    if not existing:
        return incoming
    if not incoming:
        return existing

    existing_has_zh = contains_zh(existing)
    incoming_has_zh = contains_zh(incoming)

    if lang == "en":
        if existing_has_zh and not incoming_has_zh:
            return incoming
        if incoming_has_zh and not existing_has_zh:
            return existing
    else:
        if not existing_has_zh and incoming_has_zh:
            return incoming
        if existing_has_zh and not incoming_has_zh:
            return existing

    return incoming if len(incoming) > len(existing) else existing


def uppercase_after_first_char_count(text: str) -> int:
    if not text:
        return 0
    return sum(1 for ch in text[1:] if ch.isalpha() and ch.isupper())


def choose_case_canonical_key(keys: list[str]) -> str:
    def score(key: str):
        return (
            uppercase_after_first_char_count(key),
            0 if key[:1].isupper() else 1,
            len(key),
            key.lower(),
        )

    return min(keys, key=score)


def spelling_variant_key(text: str) -> str:
    text = clean_text(text).lower()
    replacements = [
        ("tumours", "tumors"),
        ("tumour", "tumor"),
        ("haemophilia", "hemophilia"),
        ("oesophageal", "esophageal"),
        ("haematopoietic", "hematopoietic"),
        ("leukaemia", "leukemia"),
        ("foetal", "fetal"),
        ("paediatric", "pediatric"),
    ]
    for source, target in replacements:
        text = text.replace(source, target)
    return text


def choose_spelling_canonical_key(keys: list[str]) -> str:
    def score(key: str):
        lower = key.lower()
        penalties = 0
        if "tumour" in lower:
            penalties += 3
        if "haem" in lower:
            penalties += 3
        if "oes" in lower:
            penalties += 3
        if "leuka" in lower:
            penalties += 3
        if key[:1].islower():
            penalties += 1
        return (penalties, len(key), lower)

    return min(keys, key=score)


def collapse_case_duplicates(payload: dict):
    merge_count = 0
    chosen_keys = {}
    collapsed = {"zh": {"Disease": {}, "Function": {}}, "en": {"Disease": {}, "Function": {}}}

    for entity_type in ("Disease", "Function"):
        en_source = payload.get("en", {}).get(entity_type, {})
        buckets = defaultdict(list)
        for key in en_source:
            buckets[key.lower()].append(key)

        canonical_by_lower = {
            lower_key: choose_case_canonical_key(keys)
            for lower_key, keys in buckets.items()
        }

        for lang in ("zh", "en"):
            source = payload.get(lang, {}).get(entity_type, {})
            target = collapsed[lang][entity_type]
            for key, description in source.items():
                canonical = canonical_by_lower.get(key.lower(), key)
                chosen_keys[key] = canonical
                if canonical in target and canonical != key:
                    merge_count += 1
                target[canonical] = prefer_description(target.get(canonical, ""), description, lang)

    return collapsed, {"case_merged_entries": merge_count, "chosen_keys": chosen_keys}


def collapse_spelling_variants(payload: dict):
    merge_count = 0
    chosen_keys = {}
    collapsed = {"zh": {"Disease": {}, "Function": {}}, "en": {"Disease": {}, "Function": {}}}

    for entity_type in ("Disease", "Function"):
        en_source = payload.get("en", {}).get(entity_type, {})
        buckets = defaultdict(list)
        for key in en_source:
            buckets[spelling_variant_key(key)].append(key)

        canonical_by_bucket = {
            bucket_key: choose_spelling_canonical_key(keys)
            for bucket_key, keys in buckets.items()
        }

        for lang in ("zh", "en"):
            source = payload.get(lang, {}).get(entity_type, {})
            target = collapsed[lang][entity_type]
            for key, description in source.items():
                canonical = canonical_by_bucket.get(spelling_variant_key(key), key)
                chosen_keys[key] = canonical
                if canonical in target and canonical != key:
                    merge_count += 1
                target[canonical] = prefer_description(target.get(canonical, ""), description, lang)

    return collapsed, {"spelling_merged_entries": merge_count, "chosen_keys": chosen_keys}


def remap_keys(payload: dict, key_cache: dict):
    remapped = {"zh": {"Disease": {}, "Function": {}}, "en": {"Disease": {}, "Function": {}}}
    merge_count = 0
    zh_key_count = 0
    name_changes = {}

    for lang in ("zh", "en"):
        for entity_type in ("Disease", "Function"):
            source = payload.get(lang, {}).get(entity_type, {})
            target = remapped[lang][entity_type]
            for raw_name, description in source.items():
                canonical = normalize_en_key(raw_name, entity_type, key_cache)
                if contains_zh(raw_name):
                    zh_key_count += 1
                if canonical != raw_name:
                    name_changes[raw_name] = canonical
                if canonical in target:
                    merge_count += 1
                target[canonical] = prefer_description(target.get(canonical, ""), description, lang)

    return remapped, {"merged_entries": merge_count, "zh_keys_seen": zh_key_count, "name_changes": name_changes}


def translate_en_descriptions(payload: dict, cache: dict, provider: str, model: str, batch_size: int):
    pending = []
    for entity_type in ("Disease", "Function"):
        source = payload.get("en", {}).get(entity_type, {})
        for name, description in source.items():
            if contains_zh(description) and name not in cache:
                pending.append((entity_type, name, description))

    if not pending:
        return

    system_prompt = (
        "You translate biomedical descriptions into concise, natural academic English. "
        "Return strict JSON only."
    )
    for start in range(0, len(pending), batch_size):
        batch = pending[start:start + batch_size]
        lines = []
        for entity_type, name, description in batch:
            lines.append(
                f"- entity_type: {entity_type}\n"
                f"  name: {name}\n"
                f"  description_zh: {description}"
            )
        user_prompt = (
            "Translate the following Chinese biomedical descriptions into English. "
            "Keep terminology precise and concise. "
            "Return a JSON array. Each item must have exactly these keys: entity_type, name, en_description.\n\n"
            "Items:\n" + "\n".join(lines)
        )
        try:
            rows = ensure_rows(call_relay_json(system_prompt, user_prompt, provider, model))
        except Exception:
            if len(batch) == 1:
                raise
            for entity_type_single, name_single, description_single in batch:
                if name_single in cache:
                    continue
                single_prompt = (
                    "Translate the following Chinese biomedical description into English. "
                    "Keep terminology precise and concise. "
                    "Return a JSON array with exactly one item. "
                    "The item must have exactly these keys: entity_type, name, en_description.\n\n"
                    f"Items:\n- entity_type: {entity_type_single}\n  name: {name_single}\n  description_zh: {description_single}"
                )
                single_rows = ensure_rows(call_relay_json(system_prompt, single_prompt, provider, model))
                for row in single_rows:
                    name = clean_text(row.get('name', ''))
                    en_description = clean_text(row.get('en_description', ''))
                    if name and en_description:
                        cache[name] = en_description
                save_json(EN_CACHE_PATH, cache)
            continue
        for row in rows:
            name = clean_text(row.get("name", ""))
            en_description = clean_text(row.get("en_description", ""))
            if name and en_description:
                cache[name] = en_description
        save_json(EN_CACHE_PATH, cache)


def apply_en_description_translations(payload: dict, cache: dict):
    updated = 0
    for entity_type in ("Disease", "Function"):
        source = payload.get("en", {}).get(entity_type, {})
        for name, description in list(source.items()):
            if contains_zh(description) and cache.get(name):
                source[name] = clean_text(cache[name])
                updated += 1
    return updated


def build_report(before_payload: dict, after_payload: dict, key_stats: dict, translated_en_count: int, case_stats: dict, spelling_stats: dict):
    report = {
        "before": {},
        "after": {},
        "key_normalization": {
            "merged_entries": key_stats["merged_entries"],
            "zh_keys_seen": key_stats["zh_keys_seen"],
            "renamed_key_count": len(key_stats["name_changes"]),
            "sample_name_changes": dict(list(sorted(key_stats["name_changes"].items()))[:80]),
        },
        "case_normalization": {
            "case_merged_entries": case_stats["case_merged_entries"],
            "sample_case_changes": dict(
                list(
                    (k, v)
                    for k, v in sorted(case_stats["chosen_keys"].items())
                    if k != v
                )[:80]
            ),
        },
        "spelling_normalization": {
            "spelling_merged_entries": spelling_stats["spelling_merged_entries"],
            "sample_spelling_changes": dict(
                list(
                    (k, v)
                    for k, v in sorted(spelling_stats["chosen_keys"].items())
                    if k != v
                )[:80]
            ),
        },
        "en_description_translations_applied": translated_en_count,
    }
    for stage_name, payload in (("before", before_payload), ("after", after_payload)):
        for lang in ("zh", "en"):
            report[stage_name].setdefault(lang, {})
            for entity_type in ("Disease", "Function"):
                section = payload.get(lang, {}).get(entity_type, {})
                report[stage_name][lang][entity_type] = {
                    "total": len(section),
                    "zh_keys": sum(1 for key in section if contains_zh(key)),
                    "zh_values": sum(1 for value in section.values() if contains_zh(value)),
                }
    return report


def main():
    provider = sys.argv[1] if len(sys.argv) >= 2 else DEFAULT_PROVIDER
    model = sys.argv[2] if len(sys.argv) >= 3 else DEFAULT_MODEL
    batch_size = int(sys.argv[3]) if len(sys.argv) >= 4 else 80

    original = load_json(ENTITY_PATH, {})
    save_json(BACKUP_PATH, original)

    raw_key_cache = load_json(KEY_CACHE_PATH, {})
    key_cache = {}
    for raw_name, canonical in raw_key_cache.items():
        key_cache[source_name_key(raw_name)] = canonical
    en_cache = load_json(EN_CACHE_PATH, {})

    zh_key_names = {"Disease": set(), "Function": set()}
    for lang in ("zh", "en"):
        for entity_type in ("Disease", "Function"):
            for key in original.get(lang, {}).get(entity_type, {}):
                if contains_zh(key):
                    zh_key_names[entity_type].add(clean_text(key))

    for entity_type in ("Disease", "Function"):
        translate_chinese_keys(
            entity_type,
            sorted(zh_key_names[entity_type]),
            key_cache,
            provider,
            model,
            batch_size,
        )

    remapped, key_stats = remap_keys(original, key_cache)
    translate_en_descriptions(remapped, en_cache, provider, model, batch_size)
    translated_en_count = apply_en_description_translations(remapped, en_cache)
    collapsed, case_stats = collapse_case_duplicates(remapped)
    spelling_collapsed, spelling_stats = collapse_spelling_variants(collapsed)

    report = build_report(original, spelling_collapsed, key_stats, translated_en_count, case_stats, spelling_stats)
    save_json(ENTITY_PATH, spelling_collapsed)
    save_json(REPORT_PATH, report)

    print(json.dumps({
        "ok": True,
        "provider": provider,
        "model": model,
        "batch_size": batch_size,
        "renamed_key_count": len(key_stats["name_changes"]),
        "merged_entries": key_stats["merged_entries"],
        "case_merged_entries": case_stats["case_merged_entries"],
        "spelling_merged_entries": spelling_stats["spelling_merged_entries"],
        "translated_en_descriptions": translated_en_count,
        "report_path": str(REPORT_PATH),
        "backup_path": str(BACKUP_PATH),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
