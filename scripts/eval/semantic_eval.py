#!/usr/bin/env python3
"""Deterministic semantic proxy scoring for saved DT vs Agent eval runs."""

from __future__ import annotations

import argparse
import json
import re
from collections import Counter
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
SCHEMA_VERSION = "dt_agent_semantic_proxy.v1"
LIMITATION_NOTE = (
    "v1 deterministic proxy only: uses saved answer text and artifact presence; "
    "it does not call an external LLM or verify biomedical truth."
)


def clamp(value: float) -> float:
    return round(max(0.0, min(1.0, value)), 3)


def text_value(value: Any) -> str:
    return str(value or "")


def is_nonempty(value: Any) -> bool:
    if value is None:
        return False
    if isinstance(value, (str, list, tuple, set, dict)):
        return bool(value)
    return True


def recursively_collect(value: Any, key_names: set[str] | None = None) -> list[Any]:
    found: list[Any] = []
    if isinstance(value, dict):
        for key, child in value.items():
            if key_names is None or key in key_names:
                found.append(child)
            found.extend(recursively_collect(child, key_names))
    elif isinstance(value, list):
        for child in value:
            found.extend(recursively_collect(child, key_names))
    return found


def count_citations(result: dict[str, Any]) -> int:
    citations = result.get("citations")
    if isinstance(citations, list):
        return len(citations)
    if isinstance(citations, dict):
        return len(citations)
    return 0


def count_recursive_citations(result: dict[str, Any]) -> int:
    direct = count_citations(result)
    if direct:
        return direct
    values = recursively_collect(result, {"citations", "selected_citations"})
    count = 0
    for value in values:
        if isinstance(value, list):
            count += len(value)
        elif isinstance(value, dict):
            count += len(value)
        elif value:
            count += 1
    return count


def evidence_strength(result: dict[str, Any]) -> float:
    evidence_walk = result.get("evidence_walk")
    evidence_package = result.get("evidence_package")
    report_plan = result.get("report_plan")
    score = 0.0
    if is_nonempty(evidence_walk):
        score += 0.35
    if is_nonempty(evidence_package):
        score += 0.3
    if recursively_collect(result, {"supported_claims", "candidate_claims", "claim_clusters", "steps"}):
        score += 0.2
    if is_nonempty(report_plan):
        score += 0.1
    if count_recursive_citations(result):
        score += 0.05
    return clamp(score)


def has_reference_markers(answer: str) -> bool:
    return bool(re.search(r"(\[\d+\]|PMID|doi:|citation|reference|source|来源|参考)", answer, re.I))


def has_limitation_wording(answer: str) -> bool:
    return bool(
        re.search(
            r"(limitation|limited|uncertain|unknown|not enough|missing evidence|hypothesis|caveat|"
            r"限制|不足|缺少证据|尚不清楚|假设|不能证明)",
            answer,
            re.I,
        )
    )


def missing_evidence_strength(result: dict[str, Any]) -> float:
    answer = text_value(result.get("answer"))
    missing = recursively_collect(result, {"missing_evidence", "limitations", "conflicting_claims"})
    explicit_artifacts = any(is_nonempty(item) for item in missing)
    explicit_text = has_limitation_wording(answer)
    if explicit_artifacts and explicit_text:
        return 1.0
    if explicit_artifacts:
        return 0.8
    if explicit_text:
        return 0.65
    return 0.0


def claim_support_score(result: dict[str, Any]) -> float:
    answer = text_value(result.get("answer"))
    if not answer:
        return 0.0
    evidence = evidence_strength(result)
    citations = min(1.0, count_recursive_citations(result) / 3.0)
    marker_bonus = 0.1 if has_reference_markers(answer) else 0.0
    return clamp((0.55 * evidence) + (0.25 * citations) + 0.1 + marker_bonus)


def citation_relevance_score(result: dict[str, Any]) -> float:
    citations = count_recursive_citations(result)
    if citations <= 0:
        return 0.0
    answer = text_value(result.get("answer"))
    evidence = evidence_strength(result)
    marker_bonus = 0.15 if has_reference_markers(answer) else 0.0
    evidence_bonus = 0.25 if evidence >= 0.4 else 0.0
    return clamp(min(0.6, citations / 4.0) + marker_bonus + evidence_bonus)


def research_usefulness_score(case: dict[str, Any], result: dict[str, Any]) -> float:
    answer = text_value(result.get("answer"))
    if not answer:
        return 0.0
    expected = text_value(case.get("expected_best_mode"))
    answer_len = len(answer)
    if expected in {"deep_think", "boundary_deep_think"} and answer_len <= 800 and evidence_strength(result) < 0.4:
        return 0.25
    length_score = 0.15 if answer_len >= 400 else (0.08 if answer_len >= 120 else 0.02)
    score = (
        length_score
        + 0.35 * evidence_strength(result)
        + 0.25 * min(1.0, count_recursive_citations(result) / 3.0)
        + 0.15 * missing_evidence_strength(result)
    )
    if expected in {"agent", "boundary_agent"}:
        score += 0.1
    return clamp(score)


def compact_dt_score(case: dict[str, Any], result: dict[str, Any]) -> float:
    answer = text_value(result.get("answer"))
    if not answer:
        return 0.0
    expected = text_value(case.get("expected_best_mode"))
    score = 0.25
    score += 0.2 if count_recursive_citations(result) else 0.0
    score += 0.15 if has_reference_markers(answer) else 0.0
    score += 0.2 if expected in {"deep_think", "boundary_deep_think"} else 0.0
    score += 0.1 if len(answer) <= 1200 else 0.05
    return clamp(score)


def score_semantic_proxy(case: dict[str, Any], dt_result: dict[str, Any], agent_result: dict[str, Any]) -> dict[str, Any]:
    notes = [LIMITATION_NOTE]
    case_id = case.get("case_id")
    expected = text_value(case.get("expected_best_mode"))

    if not dt_result.get("answer"):
        notes.append("Deep Think answer text is missing or unavailable in saved artifacts.")
    if not agent_result.get("answer"):
        notes.append("Agent answer text is missing or unavailable in saved artifacts.")
    if not is_nonempty(agent_result.get("evidence_walk")) and not is_nonempty(agent_result.get("evidence_package")):
        notes.append("Agent evidence_walk/evidence_package artifacts are missing or empty.")
    if not count_recursive_citations(agent_result):
        notes.append("Agent citation artifacts are missing or empty.")

    support = claim_support_score(agent_result)
    citation = citation_relevance_score(agent_result)
    missing = missing_evidence_strength(agent_result)
    usefulness = research_usefulness_score(case, agent_result)
    agent_total = clamp((0.32 * support) + (0.24 * citation) + (0.18 * missing) + (0.26 * usefulness))
    dt_total = compact_dt_score(case, dt_result)

    if expected in {"deep_think", "boundary_deep_think"}:
        dt_total = clamp(dt_total + 0.12)
    elif expected in {"agent", "boundary_agent"}:
        agent_total = clamp(agent_total + 0.06)

    if agent_total >= dt_total + 0.12 and support >= 0.5 and citation >= 0.45:
        winner = "agent"
    elif dt_total >= agent_total + 0.08:
        winner = "deep_think"
    else:
        winner = "tie"

    return {
        "schema_version": SCHEMA_VERSION,
        "case_id": case_id,
        "claim_support_score": support,
        "citation_relevance_score": citation,
        "missing_evidence_score": missing,
        "research_usefulness_score": usefulness,
        "semantic_winner": winner,
        "semantic_notes": notes,
    }


def read_jsonl(path: Path) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if line:
                records.append(json.loads(line))
    return records


def load_saved_records(run_dir: Path) -> list[dict[str, Any]]:
    case_results = run_dir / "case_results.jsonl"
    if not case_results.exists():
        raise SystemExit(f"Missing case_results.jsonl: {case_results}")
    summary_records = read_jsonl(case_results)
    raw_dir = run_dir / "raw_events"
    records: list[dict[str, Any]] = []
    for row in summary_records:
        case_id = text_value(row.get("case_id"))
        raw_file = raw_dir / f"{case_id}.json"
        if raw_file.exists():
            raw = json.loads(raw_file.read_text(encoding="utf-8"))
            records.append(raw)
            continue
        evaluation = row.get("evaluation") if isinstance(row.get("evaluation"), dict) else {}
        records.append(
            {
                "case": {
                    "case_id": row.get("case_id"),
                    "question": row.get("question"),
                    "expected_best_mode": row.get("expected_best_mode") or evaluation.get("expected_best_mode"),
                },
                "dt": {
                    "ok": evaluation.get("dt_ok"),
                    "answer": "",
                    "used_plugins": evaluation.get("dt_plugins") or [],
                    "citations": [],
                },
                "agent": {
                    "ok": evaluation.get("agent_ok"),
                    "answer": "",
                    "used_plugins": evaluation.get("agent_plugins") or [],
                    "citations": [],
                },
                "evaluation": evaluation,
                "_semantic_limitations": ["raw_events file missing; only summary evaluation fields were available."],
            }
        )
    return records


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def write_jsonl(path: Path, records: list[dict[str, Any]]) -> None:
    with path.open("w", encoding="utf-8") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False, separators=(",", ":")) + "\n")


def summarize(records: list[dict[str, Any]], semantic_records: list[dict[str, Any]]) -> dict[str, Any]:
    winner_counts = Counter(record["semantic_winner"] for record in semantic_records)
    limitation_cases = [
        record["case_id"]
        for record in semantic_records
        if any("missing" in note.lower() or "unavailable" in note.lower() for note in record["semantic_notes"])
    ]
    return {
        "schema_version": SCHEMA_VERSION,
        "case_count": len(semantic_records),
        "semantic_winner_counts": dict(sorted(winner_counts.items())),
        "average_claim_support_score": clamp(sum(r["claim_support_score"] for r in semantic_records) / max(1, len(semantic_records))),
        "average_citation_relevance_score": clamp(sum(r["citation_relevance_score"] for r in semantic_records) / max(1, len(semantic_records))),
        "average_missing_evidence_score": clamp(sum(r["missing_evidence_score"] for r in semantic_records) / max(1, len(semantic_records))),
        "average_research_usefulness_score": clamp(sum(r["research_usefulness_score"] for r in semantic_records) / max(1, len(semantic_records))),
        "limitation_case_count": len(limitation_cases),
        "limitation_cases": limitation_cases,
        "limitations": [
            LIMITATION_NOTE,
            "Saved summary-only case_results rows cannot be semantically inspected without raw answer/evidence artifacts.",
        ],
    }


def write_markdown(path: Path, summary: dict[str, Any], semantic_records: list[dict[str, Any]]) -> None:
    lines = [
        "# Semantic Evaluation Summary",
        "",
        f"- Schema: `{summary['schema_version']}`",
        f"- Cases: {summary['case_count']}",
        f"- Winner counts: {json.dumps(summary['semantic_winner_counts'], ensure_ascii=False)}",
        f"- Average claim support: {summary['average_claim_support_score']}",
        f"- Average citation relevance: {summary['average_citation_relevance_score']}",
        f"- Average missing-evidence handling: {summary['average_missing_evidence_score']}",
        f"- Average research usefulness: {summary['average_research_usefulness_score']}",
        "",
        "## Limitations",
        "",
    ]
    for limitation in summary["limitations"]:
        lines.append(f"- {limitation}")
    lines.extend(["", "## Case Results", ""])
    for record in semantic_records:
        lines.append(
            f"- `{record['case_id']}`: {record['semantic_winner']} "
            f"(support={record['claim_support_score']}, citation={record['citation_relevance_score']}, "
            f"missing={record['missing_evidence_score']}, usefulness={record['research_usefulness_score']})"
        )
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def run_cli(run_dir: Path) -> int:
    records = load_saved_records(run_dir)
    semantic_records: list[dict[str, Any]] = []
    for record in records:
        case = record.get("case") if isinstance(record.get("case"), dict) else {}
        dt = record.get("dt") if isinstance(record.get("dt"), dict) else {}
        agent = record.get("agent") if isinstance(record.get("agent"), dict) else {}
        scored = score_semantic_proxy(case, dt, agent)
        for note in record.get("_semantic_limitations") or []:
            if note not in scored["semantic_notes"]:
                scored["semantic_notes"].append(note)
        semantic_records.append(scored)

    summary = summarize(records, semantic_records)
    write_jsonl(run_dir / "semantic_case_results.jsonl", semantic_records)
    write_json(run_dir / "semantic_summary.json", summary)
    write_markdown(run_dir / "semantic_summary.md", summary, semantic_records)
    print(f"Wrote semantic eval outputs to {run_dir}")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Score saved DT vs Agent run artifacts with a deterministic semantic proxy.")
    parser.add_argument("--run-dir", required=True, help="Existing eval run directory containing case_results.jsonl")
    args = parser.parse_args()
    return run_cli(Path(args.run_dir))


if __name__ == "__main__":
    raise SystemExit(main())
