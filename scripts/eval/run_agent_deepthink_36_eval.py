#!/usr/bin/env python3
"""Run the fixed/adaptive Agent and DeepThink conversation evaluation."""

from __future__ import annotations

import argparse
import concurrent.futures
import json
import re
import sys
import time
from collections import defaultdict
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
EVAL_DIR = Path(__file__).resolve().parent
if str(EVAL_DIR) not in sys.path:
    sys.path.insert(0, str(EVAL_DIR))

from run_dt_agent_live_eval import run_agent, run_dt


DEFAULT_CASES = ROOT / "docs" / "eval" / "agent_deepthink_36_question_cases.jsonl"
INTERNAL_PATTERNS = {
    "plugin_name": re.compile(r"\b(?:plugin|Entity Resolver|Citation Resolver)\b", re.I),
    "raw_status": re.compile(r"\b(?:keyword_derived|association_not_causality|generation_mode|metadata_fallback|support_strength)\b", re.I),
    "pipeline_term": re.compile(r"\b(?:evidence package|evidence walk|resolver|routing policy|schema field|raw result)\b", re.I),
}


def read_cases(path: Path, phase: str) -> list[dict[str, Any]]:
    cases: list[dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as handle:
        for line_no, line in enumerate(handle, 1):
            if not line.strip():
                continue
            item = json.loads(line)
            if item.get("phase") == phase:
                item["_line"] = line_no
                cases.append(item)
    return cases


def scan_answer(answer: str) -> list[str]:
    return [name for name, pattern in INTERNAL_PATTERNS.items() if pattern.search(answer)]


def result_row(case: dict[str, Any], result: dict[str, Any]) -> dict[str, Any]:
    done = result.get("done_payload") if isinstance(result.get("done_payload"), dict) else {}
    answer = str(result.get("answer") or "")
    plugins = list(result.get("used_plugins") or [])
    expected = list(case.get("expected_plugin_families") or [])
    missing = [plugin for plugin in expected if plugin not in plugins]
    leaks = scan_answer(answer)
    errors = list(result.get("errors") or [])
    context = done.get("context_resolution") if isinstance(done, dict) else None
    return {
        "question_id": case["question_id"],
        "phase": case["phase"],
        "mode": case["mode"],
        "language": case.get("language", "en"),
        "original_question": case["question"],
        "session_group": case["session_group"],
        "turn_index": case.get("turn_index", 1),
        "selection_reason": case.get("selection_reason", ""),
        "expected_evidence_dimensions": case.get("expected_evidence_dimensions", []),
        "expected_plugin_families": expected,
        "response": answer,
        "context_resolution": context,
        "actual_plugin_chain": plugins,
        "elapsed_ms": int((result.get("timings") or {}).get("total_ms") or 0),
        "citations": result.get("citations") or done.get("citations", []),
        "errors": errors,
        "quality_judgment": {
            "status": "needs_human_review" if result.get("ok") and answer else "fail",
            "automatic_concerns": {
                "missing_expected_plugins": missing,
                "internal_language_leaks": leaks,
                "empty_answer": not bool(answer.strip()),
            },
            "user_perspective_note": "Pending ordinary scientific-user review.",
        },
        "raw": result,
    }


def run_group(group_cases: list[dict[str, Any]], base_url: str, timeout: int, model: str) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    session_id = "eval-36-" + str(group_cases[0]["session_group"]) + "-" + str(int(time.time() * 1000))
    for case in sorted(group_cases, key=lambda item: (int(item.get("turn_index", 1)), item["question_id"])):
        runtime_case = dict(case)
        runtime_case["case_id"] = case["question_id"]
        runtime_case["session_id"] = session_id
        if case["mode"] == "agent":
            result = run_agent(runtime_case, base_url, timeout, 1.0, model)
        else:
            result = run_dt(runtime_case, base_url, timeout, model)
        returned_session = str((result.get("done_payload") or {}).get("session_id") or "")
        if returned_session:
            session_id = returned_session
        rows.append(result_row(case, result))
    return rows


def write_rows(path: Path, rows: list[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as handle:
        for row in sorted(rows, key=lambda item: int(item["question_id"][1:])):
            handle.write(json.dumps(row, ensure_ascii=False, separators=(",", ":")) + "\n")


def read_result_rows(path: Path) -> list[dict[str, Any]]:
    if not path.is_file():
        return []
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--phase", choices=("fixed", "adaptive"), required=True)
    parser.add_argument("--cases", default=str(DEFAULT_CASES))
    parser.add_argument("--out", required=True)
    parser.add_argument("--base-url", default="http://127.0.0.1/TE-")
    parser.add_argument("--model", default="deepseek-v4-flash")
    parser.add_argument("--timeout", type=int, default=300)
    parser.add_argument("--workers", type=int, default=6)
    parser.add_argument("--question-ids", default="", help="Comma-separated IDs to rerun.")
    parser.add_argument("--merge-existing", action="store_true", help="Replace selected rows while preserving other existing output rows.")
    args = parser.parse_args()

    cases = read_cases(Path(args.cases), args.phase)
    selected_ids = {value.strip() for value in args.question_ids.split(",") if value.strip()}
    if selected_ids:
        cases = [case for case in cases if str(case.get("question_id")) in selected_ids]
    if not cases:
        raise SystemExit(f"No {args.phase} cases found")
    groups: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for case in cases:
        groups[str(case["session_group"])].append(case)

    rows: list[dict[str, Any]] = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=max(1, min(args.workers, len(groups)))) as pool:
        futures = {
            pool.submit(run_group, group, args.base_url, args.timeout, args.model): name
            for name, group in groups.items()
        }
        for future in concurrent.futures.as_completed(futures):
            name = futures[future]
            try:
                completed = future.result()
            except Exception as exc:  # Keep the batch auditable even when one group fails.
                completed = []
                for case in groups[name]:
                    completed.append(result_row(case, {"ok": False, "answer": "", "errors": [{"type": "runner_exception", "message": str(exc)}]}))
            rows.extend(completed)
            print(f"Completed {name}: {len(completed)} case(s)", flush=True)

    output_path = Path(args.out)
    if args.merge_existing:
        merged = {str(row.get("question_id")): row for row in read_result_rows(output_path)}
        merged.update({str(row.get("question_id")): row for row in rows})
        rows_to_write = list(merged.values())
    else:
        rows_to_write = rows
    write_rows(output_path, rows_to_write)
    print(f"Wrote {len(rows_to_write)} results to {args.out} ({len(rows)} executed)")
    return 0 if len(rows) == len(cases) else 1


if __name__ == "__main__":
    raise SystemExit(main())
