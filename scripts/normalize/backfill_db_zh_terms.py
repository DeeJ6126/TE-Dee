import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import base64
import json
import re
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen
from path_helpers import ROOT, terminology_path


CONFIG_PATH = ROOT / "api" / "config.local.php"
TERMINOLOGY_PATH = terminology_path("te_terminology.json")
OVERRIDES_PATH = terminology_path("te_terminology_overrides.json")
REPORT_PATH = terminology_path("db_translation_backfill_report.json")
RELAY_URL = "http://127.0.0.1:18087/chat"


def load_php_config() -> dict[str, str]:
    text = CONFIG_PATH.read_text(encoding="utf-8")
    keys = ["neo4j_url", "neo4j_user", "neo4j_password"]
    return {
        key: re.search(rf"'{key}' => '([^']+)'", text).group(1)
        for key in keys
    }


def neo4j_query(statement: str) -> list[str]:
    cfg = load_php_config()
    payload = {"statements": [{"statement": statement}]}
    req = Request(
        cfg["neo4j_url"],
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Authorization": "Basic "
            + base64.b64encode(
                f"{cfg['neo4j_user']}:{cfg['neo4j_password']}".encode("utf-8")
            ).decode("ascii"),
        },
        method="POST",
    )
    with urlopen(req, timeout=120) as resp:
        data = json.loads(resp.read().decode("utf-8"))
    return [row["row"][0] for row in data["results"][0]["data"]]


def has_cjk(text: str) -> bool:
    return any("\u4e00" <= ch <= "\u9fff" for ch in text)


def has_ascii_alpha(text: str) -> bool:
    return any(("A" <= ch <= "Z") or ("a" <= ch <= "z") for ch in text)


def load_tables() -> tuple[dict, dict]:
    base = json.loads(TERMINOLOGY_PATH.read_text(encoding="utf-8"))
    overrides = json.loads(OVERRIDES_PATH.read_text(encoding="utf-8"))
    return base, overrides


def combined_zh_names(base: dict, overrides: dict) -> dict[str, str]:
    merged = {}
    merged.update(base.get("names", {}).get("zh", {}))
    merged.update(overrides.get("names", {}).get("zh", {}))
    return merged


def uncovered_english_only(label: str, merged_zh: dict[str, str]) -> list[str]:
    rows = neo4j_query(f"MATCH (n:{label}) RETURN DISTINCT n.name AS name ORDER BY name")
    missing = []
    for row in rows:
        if row in merged_zh:
            continue
        if has_ascii_alpha(row) and not has_cjk(row):
            missing.append(row)
    return missing


def build_prompt(label: str, terms: list[str]) -> str:
    kind = "functions/mechanisms" if label == "Function" else "diseases"
    return (
        "You are maintaining a Chinese UI for a biomedical transposable-element knowledge graph. "
        f"Translate these English {kind} into concise, natural, domain-appropriate Chinese display names. "
        "Return STRICT JSON only: an array of objects. "
        "Each object must have exactly these keys: source, display_zh, canonical_en. "
        "Rules: preserve biomedical accuracy, keep abbreviations like DNA/RNA/L1/LINE-1 when natural in Chinese, "
        "do not add explanations, do not omit any source term.\nTerms:\n"
        + "\n".join(f"- {term}" for term in terms)
    )


def parse_response_text(text: str):
    cleaned = text.strip()
    if cleaned.startswith("```"):
        cleaned = re.sub(r"^```(?:json)?\s*", "", cleaned)
        cleaned = re.sub(r"\s*```$", "", cleaned)
    start = cleaned.find("[")
    end = cleaned.rfind("]")
    if start == -1 or end == -1:
        raise ValueError("Model response does not contain JSON array.")
    return json.loads(cleaned[start : end + 1])


def relay_translate(label: str, terms: list[str]) -> list[dict[str, str]]:
    payload = {
        "messages": [
            {
                "role": "system",
                "content": "You are a precise biomedical terminology translator.",
            },
            {"role": "user", "content": build_prompt(label, terms)},
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
    with urlopen(req, timeout=180) as resp:
        result = json.loads(resp.read().decode("utf-8"))
    content = result["response"]["choices"][0]["message"]["content"]
    return parse_response_text(content)


def relay_translate_resilient(
    label: str, terms: list[str], min_batch_size: int = 20
) -> list[dict[str, str]]:
    if not terms:
        return []
    try:
        return relay_translate(label, terms)
    except (HTTPError, URLError, TimeoutError, ValueError, json.JSONDecodeError):
        if len(terms) <= min_batch_size:
            raise
        midpoint = len(terms) // 2
        left = relay_translate_resilient(label, terms[:midpoint], min_batch_size)
        right = relay_translate_resilient(label, terms[midpoint:], min_batch_size)
        return left + right


def save_overrides(overrides: dict) -> None:
    OVERRIDES_PATH.write_text(
        json.dumps(overrides, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def main() -> None:
    batch_size = int(sys.argv[1]) if len(sys.argv) >= 2 else 120
    pause_seconds = float(sys.argv[2]) if len(sys.argv) >= 3 else 0.3

    base, overrides = load_tables()
    overrides.setdefault("names", {}).setdefault("zh", {})
    overrides.setdefault("names", {}).setdefault("en", {})
    overrides.setdefault("relations", {}).setdefault("zh", {})
    overrides.setdefault("relations", {}).setdefault("en", {})

    merged_zh = combined_zh_names(base, overrides)
    pending = {
        "Disease": uncovered_english_only("Disease", merged_zh),
        "Function": uncovered_english_only("Function", merged_zh),
    }

    report = {
        "batch_size": batch_size,
        "counts_before": {k: len(v) for k, v in pending.items()},
        "counts_after": {},
        "added_zh": 0,
        "updated_zh": 0,
        "added_en": 0,
        "updated_en": 0,
        "batches": [],
    }

    # Hard-fix relation labels used by TE tree/dynamic graph.
    overrides["relations"]["zh"]["SUBFAMILY_OF"] = "包含亚家族"
    overrides["relations"]["en"]["SUBFAMILY_OF"] = "contains subfamily"

    for label, terms in pending.items():
        for start in range(0, len(terms), batch_size):
            batch = terms[start : start + batch_size]
            translated = relay_translate_resilient(label, batch)
            report["batches"].append(
                {"label": label, "start": start, "count": len(batch)}
            )
            for item in translated:
                source = str(item.get("source", "")).strip()
                display_zh = str(item.get("display_zh", "")).strip()
                canonical_en = str(item.get("canonical_en", "")).strip() or source
                if not source or not display_zh:
                    continue
                previous_zh = overrides["names"]["zh"].get(source)
                if previous_zh is None:
                    report["added_zh"] += 1
                elif previous_zh != display_zh:
                    report["updated_zh"] += 1
                overrides["names"]["zh"][source] = display_zh

                previous_en = overrides["names"]["en"].get(display_zh)
                if previous_en is None:
                    report["added_en"] += 1
                elif previous_en != canonical_en:
                    report["updated_en"] += 1
                overrides["names"]["en"][display_zh] = canonical_en

            save_overrides(overrides)
            time.sleep(pause_seconds)

    merged_zh_after = combined_zh_names(base, overrides)
    report["counts_after"] = {
        "Disease": len(uncovered_english_only("Disease", merged_zh_after)),
        "Function": len(uncovered_english_only("Function", merged_zh_after)),
    }
    REPORT_PATH.write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
