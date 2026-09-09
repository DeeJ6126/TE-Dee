#!/usr/bin/env python3
"""Run live DT vs Agent evaluation through the same endpoints used by agent.php."""

from __future__ import annotations

import argparse
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
EVAL_DIR = Path(__file__).resolve().parent
if str(EVAL_DIR) not in sys.path:
    sys.path.insert(0, str(EVAL_DIR))

from semantic_eval import score_semantic_proxy

DEFAULT_CASES = ROOT / "docs" / "eval" / "dt_agent_golden_cases.jsonl"


def read_cases(path: Path) -> list[dict[str, Any]]:
    cases: list[dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as handle:
        for line_no, line in enumerate(handle, start=1):
            stripped = line.strip()
            if not stripped:
                continue
            try:
                case = json.loads(stripped)
            except json.JSONDecodeError as exc:
                raise SystemExit(f"Invalid JSONL line {line_no}: {exc}") from exc
            if not isinstance(case, dict):
                raise SystemExit(f"Invalid JSONL line {line_no}: expected object")
            cases.append(case)
    return cases


def post_json(url: str, payload: dict[str, Any], timeout: int) -> tuple[int, str, dict[str, str]]:
    data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    request = urllib.request.Request(
        url,
        data=data,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "User-Agent": "TEKG-DT-Agent-Live-Eval/1.0",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            body = response.read().decode("utf-8", errors="replace")
            return response.status, body, dict(response.headers.items())
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        return exc.code, body, dict(exc.headers.items())


def get_json(url: str, timeout: int) -> dict[str, Any]:
    request = urllib.request.Request(
        url,
        headers={"Accept": "application/json", "User-Agent": "TEKG-DT-Agent-Live-Eval/1.0"},
        method="GET",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            status = response.status
            headers = dict(response.headers.items())
            body = response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        status = exc.code
        headers = dict(exc.headers.items())
        body = exc.read().decode("utf-8", errors="replace")
    try:
        decoded = json.loads(body)
    except json.JSONDecodeError as exc:
        raise RuntimeError(json.dumps({
            "type": "non_json_response",
            "status": status,
            "headers": headers,
            "body_preview": body[:1000],
        }, ensure_ascii=False)) from exc
    if not isinstance(decoded, dict):
        raise RuntimeError("Expected JSON object from status endpoint")
    return decoded


def parse_sse(body: str) -> list[dict[str, Any]]:
    events: list[dict[str, Any]] = []
    event_type = ""
    data_lines: list[str] = []
    for raw_line in body.splitlines():
        line = raw_line.rstrip("\r")
        if line == "":
            if data_lines:
                events.append(parse_sse_event(event_type, "\n".join(data_lines)))
            event_type = ""
            data_lines = []
            continue
        if line.startswith("event:"):
            event_type = line[6:].strip()
        elif line.startswith("data:"):
            data_lines.append(line[5:].strip())
    if data_lines:
        events.append(parse_sse_event(event_type, "\n".join(data_lines)))
    return events


def parse_sse_event(event_type: str, data: str) -> dict[str, Any]:
    try:
        payload = json.loads(data)
    except json.JSONDecodeError:
        payload = {"message": data}
    if isinstance(payload, dict):
        payload.setdefault("type", event_type or payload.get("type") or "message")
        return payload
    return {"type": event_type or "message", "payload": payload}


def run_dt(case: dict[str, Any], base_url: str, timeout: int, model: str) -> dict[str, Any]:
    payload = {
        "question": case["question"],
        "mode": "deepthink",
        "model": model,
        "writing_model": model,
        "source_page": "agent",
        "current_url": base_url.rstrip("/") + "/agent.php",
        "page_context": {"eval_case_id": case.get("case_id"), "eval_phase": "5B"},
        "session_id": str(case.get("session_id") or ("eval-dt-" + str(case.get("case_id", "")))),
    }
    started = time.time()
    status, body, headers = post_json(base_url.rstrip("/") + "/api/deep_think_stream.php", payload, timeout)
    events = parse_sse(body)
    answer = ""
    done_payload: dict[str, Any] = {}
    errors: list[Any] = []
    for event in events:
        event_type = str(event.get("type", ""))
        if event_type == "answer":
            answer += str(event.get("message") or event.get("content") or "")
        elif event_type == "done":
            payload_obj = event.get("payload") if isinstance(event.get("payload"), dict) else event
            done_payload = payload_obj if isinstance(payload_obj, dict) else {}
            if not answer:
                answer = str(done_payload.get("answer") or done_payload.get("message") or "")
        elif event_type == "error":
            errors.append(event)
    return {
        "ok": status == 200 and not errors,
        "http_status": status,
        "headers": headers,
        "events": events,
        "answer": answer,
        "done_payload": done_payload,
        "used_plugins": extract_plugins_from_events(events),
        "citations": done_payload.get("citations", []) if isinstance(done_payload, dict) else [],
        "timings": {"total_ms": int(round((time.time() - started) * 1000))},
        "errors": errors,
    }


def run_agent(case: dict[str, Any], base_url: str, timeout: int, poll_interval: float, model: str) -> dict[str, Any]:
    payload = {
        "question": case["question"],
        "mode": "academic",
        "model": model,
        "source_page": "agent",
        "current_url": base_url.rstrip("/") + "/agent.php",
        "page_context": {"eval_case_id": case.get("case_id"), "eval_phase": "5B"},
        "session_id": str(case.get("session_id") or ("eval-agent-" + str(case.get("case_id", "")))),
    }
    create_url = base_url.rstrip("/") + "/api/agent_runs.php"
    started = time.time()
    status, body, headers = post_json(create_url, payload, timeout)
    try:
        create = json.loads(body)
    except json.JSONDecodeError:
        return {
            "ok": False,
            "http_status": status,
            "headers": headers,
            "create": {},
            "raw_body_preview": body[:1000],
            "events": [],
            "run": {},
            "answer": "",
            "errors": [{"type": "create_non_json", "status": status, "body_preview": body[:1000]}],
            "timings": {"total_ms": int(round((time.time() - started) * 1000))},
        }
    run_id = str(create.get("run_id") or create.get("id") or "")
    if status >= 400 or not run_id:
        return {
            "ok": False,
            "http_status": status,
            "headers": headers,
            "create": create,
            "events": [],
            "run": {},
            "answer": "",
            "errors": [{"type": "create_failed", "body": create}],
            "timings": {"total_ms": int(round((time.time() - started) * 1000))},
        }
    after = 0
    events: list[dict[str, Any]] = []
    final_run: dict[str, Any] = {}
    deadline = time.time() + timeout
    while time.time() < deadline:
        query = urllib.parse.urlencode({"run_id": run_id, "after": after})
        status_url = base_url.rstrip("/") + "/api/agent_run_status.php?" + query
        status_payload = get_json(status_url, min(30, timeout))
        final_run = status_payload.get("run", {}) if isinstance(status_payload.get("run"), dict) else {}
        new_events = status_payload.get("events", [])
        if isinstance(new_events, list):
            for event in new_events:
                if isinstance(event, dict):
                    events.append(event)
                    after = max(after, int(event.get("sequence") or after))
        run_status = str(final_run.get("status") or "")
        if run_status in {"completed", "failed", "cancelled"}:
            break
        time.sleep(poll_interval)
    answer = extract_agent_answer(events, final_run)
    response_payload = extract_agent_done_payload(events)
    synthesis_payload = extract_agent_synthesis_payload(events)
    return {
        "ok": str(final_run.get("status") or "") == "completed",
        "http_status": status,
        "headers": headers,
        "create": create,
        "run_id": run_id,
        "run": final_run,
        "events": events,
        "answer": answer,
        "done_payload": response_payload,
        "used_plugins": response_payload.get("used_plugins", []) if isinstance(response_payload, dict) else [],
        "citations": response_payload.get("citations", []) if isinstance(response_payload, dict) else [],
        "evidence_package": first_dict(response_payload.get("evidence_package") if isinstance(response_payload, dict) else None, synthesis_payload.get("evidence_package")),
        "evidence_walk": first_dict(response_payload.get("evidence_walk") if isinstance(response_payload, dict) else None, synthesis_payload.get("evidence_walk")),
        "report_plan": first_dict(response_payload.get("report_plan") if isinstance(response_payload, dict) else None, synthesis_payload.get("report_plan")),
        "integrity_report": response_payload.get("integrity_report", {}) if isinstance(response_payload, dict) else {},
        "evaluation_report": response_payload.get("evaluation_report", {}) if isinstance(response_payload, dict) else {},
        "models": response_payload.get("models", {}) if isinstance(response_payload, dict) else {},
        "writing_failed": bool(response_payload.get("writing_failed", False)) if isinstance(response_payload, dict) else False,
        "failure_stage": response_payload.get("failure_stage", "") if isinstance(response_payload, dict) else "",
        "failure_reason": response_payload.get("failure_reason", "") if isinstance(response_payload, dict) else "",
        "timings": {"total_ms": int(round((time.time() - started) * 1000)), **(response_payload.get("timings", {}) if isinstance(response_payload, dict) and isinstance(response_payload.get("timings"), dict) else {})},
        "errors": [] if str(final_run.get("status") or "") == "completed" else [{"type": "run_not_completed", "run": final_run}],
    }


def extract_plugins_from_events(events: list[dict[str, Any]]) -> list[str]:
    plugins: list[str] = []
    for event in events:
        for key in ("plugin_name", "plugin", "tool"):
            value = event.get(key)
            if isinstance(value, str) and value:
                plugins.append(value)
        payload = event.get("payload")
        if isinstance(payload, dict):
            value = payload.get("plugin_name") or payload.get("plugin")
            if isinstance(value, str) and value:
                plugins.append(value)
    return sorted(set(plugins))


def extract_agent_done_payload(events: list[dict[str, Any]]) -> dict[str, Any]:
    for event in reversed(events):
        payload = event.get("payload")
        event_type = str(event.get("type") or (payload.get("type") if isinstance(payload, dict) else ""))
        if event_type == "done":
            if isinstance(payload, dict):
                nested = payload.get("payload")
                if isinstance(nested, dict):
                    return nested
                return payload
    return {}


def extract_agent_synthesis_payload(events: list[dict[str, Any]]) -> dict[str, Any]:
    for event in reversed(events):
        if str(event.get("type") or "") != "synthesizing":
            continue
        payload = event.get("payload")
        if isinstance(payload, dict):
            return payload
    return {}


def first_dict(*values: Any) -> dict[str, Any]:
    for value in values:
        if isinstance(value, dict) and value:
            return value
    return {}


def extract_agent_answer(events: list[dict[str, Any]], run: dict[str, Any]) -> str:
    done = extract_agent_done_payload(events)
    if done.get("answer"):
        return str(done["answer"])
    if run.get("answer"):
        return str(run["answer"])
    for event in reversed(events):
        payload = event.get("payload")
        if isinstance(payload, dict) and payload.get("answer"):
            return str(payload["answer"])
        if event.get("answer"):
            return str(event["answer"])
    return ""


def score_locally(case: dict[str, Any], dt: dict[str, Any], agent: dict[str, Any]) -> dict[str, Any]:
    expected = str(case.get("expected_best_mode") or "")
    dt_artifact = 0.25 if dt.get("answer") else 0.0
    dt_artifact += 0.1 * min(3, len(dt.get("used_plugins") or []))
    dt_artifact += 0.2 if dt.get("citations") else 0.0
    agent_artifact = 0.0
    agent_artifact += 0.2 if agent.get("evidence_package") else 0.0
    agent_artifact += 0.2 if agent.get("evidence_walk") else 0.0
    agent_artifact += 0.15 if agent.get("report_plan") else 0.0
    agent_artifact += 0.2 if agent.get("integrity_report") else 0.0
    agent_artifact += 0.15 if agent.get("citations") else 0.0
    agent_artifact += 0.1 if agent.get("answer") else 0.0
    agent_artifact_score = round(min(1.0, agent_artifact), 3)
    depth_delta = round(agent_artifact - dt_artifact, 3)
    recommended = "agent" if expected in {"agent", "boundary_agent"} else "deep_think"
    agent_overkill = (
        expected in {"deep_think", "boundary_deep_think"}
        and len(agent.get("used_plugins") or []) > 2
        and agent_artifact_score > 0.5
    )
    return {
        "schema_version": "mode_comparison_live_proxy.v1",
        "case_id": case.get("case_id"),
        "expected_best_mode": expected,
        "recommended_mode": recommended,
        "dt_ok": bool(dt.get("ok")),
        "agent_ok": bool(agent.get("ok")),
        "dt_answer_length": len(str(dt.get("answer") or "")),
        "agent_answer_length": len(str(agent.get("answer") or "")),
        "dt_plugins": dt.get("used_plugins") or [],
        "agent_plugins": agent.get("used_plugins") or [],
        "dt_latency_ms": (dt.get("timings") or {}).get("total_ms", 0),
        "agent_latency_ms": (agent.get("timings") or {}).get("total_ms", 0),
        "agent_artifact_score": agent_artifact_score,
        "dt_artifact_score": round(min(1.0, dt_artifact), 3),
        "depth_delta": depth_delta,
        "agent_value_added": "high" if depth_delta >= 0.45 else ("medium" if depth_delta >= 0.25 else ("low" if depth_delta > 0.05 else "none")),
        "agent_overkill": agent_overkill,
        "dt_errors": dt.get("errors") or [],
        "agent_errors": agent.get("errors") or [],
    }


def write_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2), encoding="utf-8")


def append_jsonl(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(value, ensure_ascii=False, separators=(",", ":")) + "\n")


def build_case_result_row(record: dict[str, Any]) -> dict[str, Any]:
    case = record["case"]
    row = {
        "case_id": case.get("case_id"),
        "question": case.get("question"),
        "expected_best_mode": case.get("expected_best_mode"),
        "evaluation": record["evaluation"],
    }
    if isinstance(record.get("semantic_evaluation"), dict):
        row["semantic_evaluation"] = record["semantic_evaluation"]
    return row


def write_summary(out_dir: Path, results: list[dict[str, Any]]) -> None:
    total = len(results)
    summary = {
        "schema_version": "dt_agent_live_eval_summary.v1",
        "total_cases": total,
        "dt_ok": sum(1 for item in results if item["evaluation"]["dt_ok"]),
        "agent_ok": sum(1 for item in results if item["evaluation"]["agent_ok"]),
        "agent_value_added": {},
        "agent_overkill_count": sum(1 for item in results if item["evaluation"]["agent_overkill"]),
    }
    for item in results:
        key = item["evaluation"]["agent_value_added"]
        summary["agent_value_added"][key] = int(summary["agent_value_added"].get(key, 0)) + 1
    semantic_evaluations = [
        item["semantic_evaluation"]
        for item in results
        if isinstance(item.get("semantic_evaluation"), dict)
    ]
    if semantic_evaluations:
        winner_counts: dict[str, int] = {}
        for semantic in semantic_evaluations:
            key = str(semantic.get("semantic_winner") or "unknown")
            winner_counts[key] = winner_counts.get(key, 0) + 1
        summary["semantic_winner_counts"] = dict(sorted(winner_counts.items()))
    write_json(out_dir / "summary.json", summary)
    lines = [
        "# DT vs Agent Live Evaluation Summary",
        "",
        f"- Total cases: {summary['total_cases']}",
        f"- DT completed: {summary['dt_ok']}",
        f"- Agent completed: {summary['agent_ok']}",
        f"- Agent overkill count: {summary['agent_overkill_count']}",
        f"- Agent value added: {summary['agent_value_added']}",
    ]
    if "semantic_winner_counts" in summary:
        lines.append(f"- Semantic winner counts: {summary['semantic_winner_counts']}")
    lines.extend([
        "",
        "| Case | Expected | DT ok | Agent ok | Value added | Overkill | DT ms | Agent ms |",
        "|---|---|---:|---:|---|---:|---:|---:|",
    ])
    for item in results:
        ev = item["evaluation"]
        lines.append(
            f"| {ev['case_id']} | {ev['expected_best_mode']} | {ev['dt_ok']} | {ev['agent_ok']} | "
            f"{ev['agent_value_added']} | {ev['agent_overkill']} | {ev['dt_latency_ms']} | {ev['agent_latency_ms']} |"
        )
    (out_dir / "summary.md").write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default="http://127.0.0.1/TE-")
    parser.add_argument("--cases", default=str(DEFAULT_CASES))
    parser.add_argument("--out-dir", default="")
    parser.add_argument("--limit", type=int, default=0)
    parser.add_argument("--case-id", default="")
    parser.add_argument("--dt-only", action="store_true")
    parser.add_argument("--agent-only", action="store_true")
    parser.add_argument("--timeout", type=int, default=240)
    parser.add_argument("--poll-interval", type=float, default=2.0)
    parser.add_argument("--model", default="deepseek-v4-flash")
    parser.add_argument("--rescore-existing", action="store_true")
    parser.add_argument("--semantic-proxy", action="store_true")
    args = parser.parse_args()

    if args.rescore_existing:
        out_dir = Path(args.out_dir)
        if not out_dir:
            raise SystemExit("--rescore-existing requires --out-dir")
        return rescore_existing(out_dir, semantic_proxy=args.semantic_proxy)

    cases = read_cases(Path(args.cases))
    if args.case_id:
        cases = [case for case in cases if str(case.get("case_id")) == args.case_id]
    if args.limit:
        cases = cases[: args.limit]
    if not cases:
        raise SystemExit("No cases selected")

    run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    out_dir = Path(args.out_dir) if args.out_dir else ROOT / "docs" / "eval" / "runs" / run_id
    out_dir.mkdir(parents=True, exist_ok=True)
    results: list[dict[str, Any]] = []
    for index, case in enumerate(cases, start=1):
        case_id = str(case.get("case_id") or f"case_{index}")
        print(f"[{index}/{len(cases)}] {case_id}: {case.get('question')}", flush=True)
        dt_result: dict[str, Any] = {"ok": False, "skipped": True, "answer": "", "errors": []}
        agent_result: dict[str, Any] = {"ok": False, "skipped": True, "answer": "", "errors": []}
        try:
            if not args.agent_only:
                dt_result = run_dt(case, args.base_url, args.timeout, args.model)
        except (urllib.error.URLError, TimeoutError, RuntimeError, json.JSONDecodeError) as exc:
            dt_result = {"ok": False, "answer": "", "errors": [{"type": "dt_exception", "message": str(exc)}], "timings": {"total_ms": 0}}
        try:
            if not args.dt_only:
                agent_result = run_agent(case, args.base_url, args.timeout, args.poll_interval, args.model)
        except (urllib.error.URLError, TimeoutError, RuntimeError, json.JSONDecodeError) as exc:
            agent_result = {"ok": False, "answer": "", "errors": [{"type": "agent_exception", "message": str(exc)}], "timings": {"total_ms": 0}}
        evaluation = score_locally(case, dt_result, agent_result)
        record = {"case": case, "dt": dt_result, "agent": agent_result, "evaluation": evaluation}
        if args.semantic_proxy:
            record["semantic_evaluation"] = score_semantic_proxy(case, dt_result, agent_result)
        write_json(out_dir / "raw_events" / f"{case_id}.json", record)
        append_jsonl(out_dir / "case_results.jsonl", build_case_result_row(record))
        results.append(record)
        print(
            f"  dt_ok={evaluation['dt_ok']} agent_ok={evaluation['agent_ok']} "
            f"value={evaluation['agent_value_added']} overkill={evaluation['agent_overkill']}",
            flush=True,
        )
    write_summary(out_dir, results)
    print(f"Wrote live eval results to {out_dir}")
    return 0


def rescore_existing(out_dir: Path, semantic_proxy: bool = False) -> int:
    raw_dir = out_dir / "raw_events"
    if not raw_dir.is_dir():
        raise SystemExit(f"Missing raw_events directory: {raw_dir}")
    old_results = out_dir / "case_results.jsonl"
    if old_results.exists():
        old_results.unlink()
    results: list[dict[str, Any]] = []
    for raw_file in sorted(raw_dir.glob("*.json")):
        record = json.loads(raw_file.read_text(encoding="utf-8"))
        case = record["case"]
        agent = record["agent"]
        if not agent.get("evidence_package") or not agent.get("evidence_walk") or not agent.get("report_plan"):
            synthesis_payload = extract_agent_synthesis_payload(agent.get("events") or [])
            agent["evidence_package"] = first_dict(agent.get("evidence_package"), synthesis_payload.get("evidence_package"))
            agent["evidence_walk"] = first_dict(agent.get("evidence_walk"), synthesis_payload.get("evidence_walk"))
            agent["report_plan"] = first_dict(agent.get("report_plan"), synthesis_payload.get("report_plan"))
        evaluation = score_locally(case, record["dt"], agent)
        record["agent"] = agent
        record["evaluation"] = evaluation
        if semantic_proxy:
            record["semantic_evaluation"] = score_semantic_proxy(case, record["dt"], agent)
        else:
            record.pop("semantic_evaluation", None)
        raw_file.write_text(json.dumps(record, ensure_ascii=False, indent=2), encoding="utf-8")
        append_jsonl(out_dir / "case_results.jsonl", build_case_result_row(record))
        results.append(record)
    write_summary(out_dir, results)
    print(f"Rescored existing live eval results in {out_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
