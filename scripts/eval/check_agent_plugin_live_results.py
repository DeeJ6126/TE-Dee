#!/usr/bin/env python3
"""Check targeted Agent plugin live-eval raw events.

This checker intentionally does not trust agent_ok alone. It validates:
1. required plugin names appear in used_plugins or event payloads;
2. required evidence keywords appear in the raw event stream;
3. final answer contains required grounding keywords.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any


def load_cases(path: Path) -> dict[str, dict[str, Any]]:
    cases: dict[str, dict[str, Any]] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line:
            continue
        case = json.loads(line)
        cases[str(case["case_id"])] = case
    return cases


def walk_strings(value: Any) -> list[str]:
    strings: list[str] = []
    if isinstance(value, str):
        strings.append(value)
    elif isinstance(value, dict):
        for item in value.values():
            strings.extend(walk_strings(item))
    elif isinstance(value, list):
        for item in value:
            strings.extend(walk_strings(item))
    return strings


def contains_all(haystack: str, needles: list[str]) -> tuple[bool, list[str]]:
    lower = haystack.lower()
    missing = [needle for needle in needles if needle.lower() not in lower]
    return missing == [], missing


def plugin_names(record: dict[str, Any]) -> set[str]:
    names: set[str] = set()
    agent = record.get("agent") if isinstance(record.get("agent"), dict) else {}
    for name in agent.get("used_plugins") or []:
        if isinstance(name, str) and name.strip():
            names.add(name.strip())

    def collect(value: Any) -> None:
        if isinstance(value, dict):
            for key in ("plugin", "plugin_name", "tool", "name"):
                item = value.get(key)
                if isinstance(item, str) and item.strip():
                    names.add(item.strip())
            for item in value.values():
                collect(item)
        elif isinstance(value, list):
            for item in value:
                collect(item)

    collect(agent.get("events") or [])
    return names


def final_answer(record: dict[str, Any]) -> str:
    agent = record.get("agent") if isinstance(record.get("agent"), dict) else {}
    answer = agent.get("answer")
    if isinstance(answer, str) and answer.strip():
        return answer
    for event in reversed(agent.get("events") or []):
        if isinstance(event, dict):
            payload = event.get("payload")
            if isinstance(payload, dict) and isinstance(payload.get("answer"), str):
                return payload["answer"]
    return ""


def check_case(case: dict[str, Any], record: dict[str, Any]) -> dict[str, Any]:
    expectation = case.get("agent_expectation") or {}
    required_plugins = list(map(str, expectation.get("required_plugins") or []))
    required_evidence = list(map(str, expectation.get("required_result_evidence") or []))
    answer_must_include = list(map(str, expectation.get("answer_must_include") or []))

    names = plugin_names(record)
    missing_plugins = [name for name in required_plugins if name not in names]

    event_text = "\n".join(walk_strings(record.get("agent", {}).get("events", [])))
    evidence_ok, missing_evidence = contains_all(event_text, required_evidence)

    answer = final_answer(record)
    answer_ok, missing_answer = contains_all(answer, answer_must_include)
    agent_ok = bool(record.get("evaluation", {}).get("agent_ok"))

    passed = missing_plugins == [] and evidence_ok and answer_ok and bool(answer.strip())
    return {
        "case_id": case["case_id"],
        "pass": passed,
        "agent_ok": agent_ok,
        "plugin_called": missing_plugins == [],
        "plugin_result_evidence_ok": evidence_ok,
        "answer_grounded": answer_ok and bool(answer.strip()),
        "missing": {
            "plugins": missing_plugins,
            "evidence": missing_evidence,
            "answer": missing_answer,
        },
        "observed_plugins": sorted(names),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--cases", required=True, type=Path)
    parser.add_argument("--run-dir", required=True, type=Path)
    args = parser.parse_args()

    cases = load_cases(args.cases)
    raw_dir = args.run_dir / "raw_events"
    results = []
    for case_id, case in cases.items():
        raw_path = raw_dir / f"{case_id}.json"
        if not raw_path.is_file():
            results.append({
                "case_id": case_id,
                "pass": False,
                "agent_ok": False,
                "plugin_called": False,
                "plugin_result_evidence_ok": False,
                "answer_grounded": False,
                "missing": {"raw_events": str(raw_path)},
                "observed_plugins": [],
            })
            continue
        record = json.loads(raw_path.read_text(encoding="utf-8"))
        results.append(check_case(case, record))

    print(json.dumps(results, ensure_ascii=False, indent=2))
    return 0 if all(item["pass"] for item in results) else 1


if __name__ == "__main__":
    raise SystemExit(main())
