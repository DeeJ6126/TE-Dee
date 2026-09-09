#!/usr/bin/env python3
"""Build one readable Agent/DeepThink baseline report from live run artifacts."""

from __future__ import annotations

import argparse
import json
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def read_jsonl(path: Path) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            value = json.loads(line)
            if isinstance(value, dict):
                rows.append(value)
    return rows


def diagnostics_by_request(path: Path) -> dict[str, list[dict[str, Any]]]:
    grouped: dict[str, list[dict[str, Any]]] = defaultdict(list)
    if not path.is_file():
        return grouped
    for row in read_jsonl(path):
        request_id = str(row.get("request_id") or "")
        if request_id:
            grouped[request_id].append(row)
    return grouped


def request_id_for(result: dict[str, Any], mode: str) -> str:
    if mode == "agent":
        run = result.get("run")
        if isinstance(run, dict) and run.get("request_id"):
            return str(run["request_id"])
    for event in result.get("events") or []:
        if isinstance(event, dict) and event.get("request_id"):
            return str(event["request_id"])
    return ""


def ordered_plugins(result: dict[str, Any]) -> list[str]:
    plugins: list[str] = []
    for event in result.get("events") or []:
        if not isinstance(event, dict) or str(event.get("type") or "") != "tool_selected":
            continue
        name = str(event.get("plugin_name") or "").strip()
        if name and name not in plugins:
            plugins.append(name)
    for name in result.get("used_plugins") or []:
        value = str(name).strip()
        if value and value not in plugins:
            plugins.append(value)
    return plugins


def format_ms(value: Any) -> str:
    try:
        milliseconds = int(value)
    except (TypeError, ValueError):
        return "unavailable"
    seconds = milliseconds / 1000
    return f"{milliseconds:,} ms ({seconds:.1f} s)"


def timing_label(stage: str) -> str:
    labels = {
        "llm_dt_understanding": "Understanding",
        "llm_dt_planning": "Planning",
        "llm_dt_executing": "Executing",
        "llm_dt_writing": "Writing",
        "llm_six_stage_understanding": "Understanding",
        "llm_six_stage_planning": "Planning",
        "llm_six_stage_collecting": "Collecting",
        "llm_six_stage_executing": "ExecutingReview",
        "llm_sufficiency": "Sufficiency check",
        "llm_answer_structure": "Answer structure",
        "llm_six_stage_integrating": "Integrating",
        "llm_six_stage_writing": "Writing decision",
        "llm_evidence_walk_draft": "Final writing",
    }
    return labels.get(stage, stage or "Unknown")


def stage_timing_rows(diagnostics: list[dict[str, Any]]) -> list[tuple[str, int, list[int], str]]:
    grouped: dict[str, list[int]] = defaultdict(list)
    statuses: dict[str, list[str]] = defaultdict(list)
    for row in diagnostics:
        event = str(row.get("event") or "")
        if event not in {"http_request_complete", "http_request_error"}:
            continue
        payload = row.get("payload") if isinstance(row.get("payload"), dict) else {}
        stage = timing_label(str(payload.get("stage") or ""))
        duration = payload.get("duration_ms")
        if isinstance(duration, (int, float)):
            grouped[stage].append(int(duration))
        status = "ok" if event == "http_request_complete" else "error"
        statuses[stage].append(status)
    rows = []
    for stage, durations in grouped.items():
        state = "ok" if all(value == "ok" for value in statuses[stage]) else "contains error"
        rows.append((stage, sum(durations), durations, state))
    return rows


def plugin_timing_rows(diagnostics: list[dict[str, Any]]) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for item in diagnostics:
        event = str(item.get("event") or "")
        if event not in {"plugin_completed", "deepthink_plugin_completed"}:
            continue
        payload = item.get("payload") if isinstance(item.get("payload"), dict) else {}
        rows.append({
            "plugin_name": str(payload.get("plugin_name") or "Unknown"),
            "status": str(payload.get("status") or "unknown"),
            "latency_ms": payload.get("latency_ms"),
            "result_counts": payload.get("result_counts") if isinstance(payload.get("result_counts"), dict) else {},
        })
    return rows


def clean_text(value: Any) -> str:
    text = " ".join(str(value or "").split())
    return text.replace("|", "\\|")


def artifact_trace(event: dict[str, Any]) -> list[str]:
    payload = event.get("payload") if isinstance(event.get("payload"), dict) else {}
    artifact = payload.get("artifact") if isinstance(payload.get("artifact"), dict) else {}
    if not artifact:
        return []
    parts: list[str] = []
    for key in (
        "question_summary",
        "answer_goal",
        "execution_goal",
        "rationale",
        "reason",
        "decision_rationale",
        "stop_reason",
    ):
        value = artifact.get(key)
        if value:
            parts.append(f"{key}: {clean_text(value)}")
    for key in ("business_plugins", "required_plugins", "gaps", "warnings", "limitations"):
        value = artifact.get(key)
        if isinstance(value, list) and value:
            parts.append(f"{key}: {clean_text('; '.join(str(item) for item in value))}")
    return parts


def visible_trace(result: dict[str, Any], mode: str) -> list[str]:
    trace: list[str] = []
    for event in result.get("events") or []:
        if not isinstance(event, dict):
            continue
        event_type = str(event.get("type") or "")
        node = str(event.get("stage") or event.get("node") or event.get("source") or "Workflow")
        texts: list[str] = []
        if event_type == "artifact":
            texts.extend(artifact_trace(event))
        elif event_type == "node_llm_result":
            summary = event.get("summary") or event.get("message")
            if summary:
                texts.append(clean_text(summary))
        elif event_type in {"analysis", "planning_step", "tool_selected", "tool_result", "reflection", "synthesizing"}:
            message = event.get("message") or event.get("summary") or event.get("display_text")
            if message:
                texts.append(clean_text(message))
        for text in texts:
            line = f"**{node} / {event_type}:** {text}"
            if line not in trace:
                trace.append(line)
    return trace


def quote_block(text: str) -> list[str]:
    if not text:
        return ["> No answer was returned."]
    return ["> " + line if line else ">" for line in text.splitlines()]


def error_text(errors: Any) -> str:
    if not errors:
        return "None"
    return json.dumps(errors, ensure_ascii=False, separators=(",", ":"))


def build_report(cases_path: Path, run_dir: Path, diagnostics_path: Path, output_path: Path) -> None:
    cases = read_jsonl(cases_path)
    diagnostics = diagnostics_by_request(diagnostics_path)
    records: list[tuple[dict[str, Any], dict[str, Any], dict[str, Any]]] = []
    expected_union: list[str] = []
    observed_union: list[str] = []
    for case in cases:
        case_id = str(case.get("case_id") or "")
        raw_path = run_dir / "raw_events" / f"{case_id}.json"
        if not raw_path.is_file():
            raise FileNotFoundError(f"Missing raw case record: {raw_path}")
        record = json.loads(raw_path.read_text(encoding="utf-8"))
        mode = str(case.get("evaluation_mode") or "deep_think")
        result_key = "agent" if mode == "agent" else "dt"
        result = record.get(result_key) if isinstance(record.get(result_key), dict) else {}
        records.append((case, record, result))
        for plugin in case.get("expected_plugins") or []:
            name = str(plugin)
            if name and name not in expected_union:
                expected_union.append(name)
        for plugin in ordered_plugins(result):
            if plugin not in observed_union:
                observed_union.append(plugin)

    completed = sum(1 for _, _, result in records if bool(result.get("ok")))
    total_ms = sum(int((result.get("timings") or {}).get("total_ms") or 0) for _, _, result in records)
    lines = [
        "# TE-KG Agent Page: 13-Question English Baseline",
        "",
        f"Generated: {datetime.now(timezone.utc).isoformat(timespec='seconds')}",
        "",
        "## Protocol",
        "",
        "- Nine common or edge questions used the four-stage DeepThink workflow.",
        "- Four interpretive/report questions used the six-stage Agent workflow.",
        "- All questions were asked in English for reviewer-facing consistency.",
        "- Answers below are reproduced in full without editorial correction or rerun-to-pass cleanup.",
        "- The reasoning section records only workflow artifacts and reasoning messages exposed by TE-KG. It is not hidden model chain-of-thought.",
        "- Stage timing is the measured LLM HTTP-call duration from diagnostics. Event records do not contain timestamps, so unavailable non-LLM wall time is not invented.",
        "",
        "## Overall Results",
        "",
        f"- Cases recorded: {len(records)}",
        f"- Endpoint-complete cases: {completed}/{len(records)}",
        f"- Sum of end-to-end case duration: {format_ms(total_ms)}",
        "",
        "| Case | Mode | Completed | Total time | Plugins | Answer characters |",
        "|---|---|---:|---:|---|---:|",
    ]
    for case, _, result in records:
        mode = "Agent" if case.get("evaluation_mode") == "agent" else "DeepThink"
        plugins = ", ".join(ordered_plugins(result)) or "None"
        answer = str(result.get("answer") or "")
        lines.append(
            f"| {case['case_id']} | {mode} | {bool(result.get('ok'))} | "
            f"{format_ms((result.get('timings') or {}).get('total_ms'))} | {plugins} | {len(answer):,} |"
        )

    lines.extend([
        "",
        "## Actual Plugin Coverage",
        "",
        "| Plugin | Expected by matrix | Observed in events |",
        "|---|---:|---:|",
    ])
    for plugin in expected_union:
        lines.append(f"| {plugin} | Yes | {'Yes' if plugin in observed_union else 'No'} |")

    for case, _, result in records:
        case_id = str(case["case_id"])
        mode_key = str(case.get("evaluation_mode") or "deep_think")
        mode = "Agent" if mode_key == "agent" else "DeepThink"
        request_id = request_id_for(result, mode_key)
        case_diagnostics = diagnostics.get(request_id, [])
        actual_plugins = ordered_plugins(result)
        expected_plugins = [str(value) for value in case.get("expected_plugins") or []]
        missing_plugins = [value for value in expected_plugins if value not in actual_plugins]
        trace = visible_trace(result, mode_key)
        stage_rows = stage_timing_rows(case_diagnostics)
        plugin_rows = plugin_timing_rows(case_diagnostics)
        answer = str(result.get("answer") or "")

        lines.extend([
            "",
            f"## {case_id}: {case.get('category', '')}",
            "",
            f"**Question:** {case.get('question', '')}",
            "",
            f"- Mode: {mode}",
            f"- Completed: {bool(result.get('ok'))}",
            f"- End-to-end duration: {format_ms((result.get('timings') or {}).get('total_ms'))}",
            f"- Expected plugins: {', '.join(expected_plugins) or 'None'}",
            f"- Actual plugins: {', '.join(actual_plugins) or 'None'}",
            f"- Expected but not observed: {', '.join(missing_plugins) or 'None'}",
            f"- Request ID: `{request_id or 'unavailable'}`",
            f"- Errors: {error_text(result.get('errors'))}",
            "",
            "### Stage Timing",
            "",
            "| Stage/call group | Calls | Total measured LLM time | Individual calls | Status |",
            "|---|---:|---:|---|---|",
        ])
        if stage_rows:
            for stage, duration, durations, status in stage_rows:
                calls = ", ".join(f"{value:,}" for value in durations)
                lines.append(f"| {stage} | {len(durations)} | {format_ms(duration)} | {calls} ms | {status} |")
        else:
            lines.append("| Unavailable | 0 | unavailable | unavailable | unavailable |")

        lines.extend([
            "",
            "### Plugin Outcomes",
            "",
            "| Plugin | Status | Plugin latency | Result counts |",
            "|---|---|---:|---|",
        ])
        if plugin_rows:
            for row in plugin_rows:
                counts = clean_text(json.dumps(row["result_counts"], ensure_ascii=False, separators=(",", ":")))
                lines.append(
                    f"| {clean_text(row['plugin_name'])} | {clean_text(row['status'])} | "
                    f"{format_ms(row['latency_ms'])} | `{counts}` |"
                )
        else:
            lines.append("| Unavailable | unavailable | unavailable | `{}` |")

        lines.extend([
            "",
            "### Visible Reasoning And Workflow Trace",
            "",
        ])
        if trace:
            lines.extend(f"{index}. {item}" for index, item in enumerate(trace, start=1))
        else:
            lines.append("No visible reasoning events were returned.")

        lines.extend([
            "",
            "### Complete Answer",
            "",
            *quote_block(answer),
        ])

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--cases", required=True)
    parser.add_argument("--run-dir", required=True)
    parser.add_argument("--diagnostics", required=True)
    parser.add_argument("--out", required=True)
    args = parser.parse_args()
    build_report(Path(args.cases), Path(args.run_dir), Path(args.diagnostics), Path(args.out))
    print(f"Wrote baseline report to {args.out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
