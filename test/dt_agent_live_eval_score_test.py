#!/usr/bin/env python3
"""Regression tests for the DT vs Agent live eval local scorer."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SCORER_PATH = ROOT / "scripts" / "eval" / "run_dt_agent_live_eval.py"


def load_scorer():
    spec = importlib.util.spec_from_file_location("run_dt_agent_live_eval", SCORER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError(f"Could not load scorer module: {SCORER_PATH}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def assert_equal(actual, expected, message: str) -> None:
    if actual != expected:
        raise AssertionError(f"{message}: expected {expected!r}, got {actual!r}")


def write_saved_run(out_dir: Path) -> None:
    raw_dir = out_dir / "raw_events"
    raw_dir.mkdir(parents=True, exist_ok=True)
    record = {
        "case": {
            "case_id": "P5C_T4_001",
            "question": "What papers support LINE-1 and Alzheimer's disease?",
            "expected_best_mode": "agent",
        },
        "dt": {
            "ok": True,
            "answer": "A compact answer with one source marker [1].",
            "used_plugins": ["reference_materials"],
            "citations": [{"title": "DT citation"}],
            "timings": {"total_ms": 11},
            "errors": [],
        },
        "agent": {
            "ok": True,
            "answer": "A longer research answer with PMID evidence, caveats, and citations.",
            "used_plugins": ["graph_search", "reference_materials"],
            "citations": [{"title": "Agent citation A"}, {"title": "Agent citation B"}],
            "evidence_package": {"supported_claims": [{"claim": "LINE-1 activation is reported."}]},
            "evidence_walk": {"steps": [{"claim": "Disease association evidence collected."}]},
            "report_plan": {"sections": ["Evidence", "Limitations"]},
            "integrity_report": {"ok": True},
            "timings": {"total_ms": 22},
            "errors": [],
        },
    }
    (raw_dir / "P5C_T4_001.json").write_text(json.dumps(record, ensure_ascii=False, indent=2), encoding="utf-8")


def read_jsonl(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def test_compact_agent_artifact_is_not_overkill() -> None:
    scorer = load_scorer()
    evaluation = scorer.score_locally(
        {"case_id": "P5A_B_001", "expected_best_mode": "deep_think"},
        {"ok": True, "answer": "compact DT answer", "used_plugins": [], "citations": [], "timings": {"total_ms": 10}},
        {
            "ok": True,
            "answer": "compact Agent answer",
            "used_plugins": ["task_complexity", "site_navigator", "reference_materials"],
            "citations": [],
            "timings": {"total_ms": 20},
            "errors": [],
        },
    )

    assert_equal(evaluation["agent_artifact_score"], 0.1, "compact preflight artifact score")
    assert_equal(evaluation["agent_overkill"], False, "compact preflight should not be overkill")


def test_rescore_existing_omits_semantic_fields_by_default() -> None:
    scorer = load_scorer()
    with tempfile.TemporaryDirectory() as temp_name:
        out_dir = Path(temp_name)
        write_saved_run(out_dir)

        scorer.rescore_existing(out_dir)

        raw = json.loads((out_dir / "raw_events" / "P5C_T4_001.json").read_text(encoding="utf-8"))
        rows = read_jsonl(out_dir / "case_results.jsonl")
        summary = json.loads((out_dir / "summary.json").read_text(encoding="utf-8"))
        assert_equal("semantic_evaluation" in raw, False, "raw record should not gain semantic fields by default")
        assert_equal("semantic_evaluation" in rows[0], False, "case row should not gain semantic fields by default")
        assert_equal("semantic_winner_counts" in summary, False, "summary should not gain semantic fields by default")


def test_rescore_existing_adds_semantic_fields_when_enabled() -> None:
    scorer = load_scorer()
    with tempfile.TemporaryDirectory() as temp_name:
        out_dir = Path(temp_name)
        write_saved_run(out_dir)

        scorer.rescore_existing(out_dir, semantic_proxy=True)

        raw = json.loads((out_dir / "raw_events" / "P5C_T4_001.json").read_text(encoding="utf-8"))
        rows = read_jsonl(out_dir / "case_results.jsonl")
        summary = json.loads((out_dir / "summary.json").read_text(encoding="utf-8"))
        assert_equal(raw["semantic_evaluation"]["schema_version"], "dt_agent_semantic_proxy.v1", "raw semantic schema")
        assert_equal(rows[0]["semantic_evaluation"]["case_id"], "P5C_T4_001", "case row semantic case id")
        assert_equal(summary["semantic_winner_counts"].get(raw["semantic_evaluation"]["semantic_winner"]), 1, "summary winner count")


def test_main_loop_adds_semantic_fields_when_enabled_without_live_api() -> None:
    scorer = load_scorer()
    with tempfile.TemporaryDirectory() as temp_name:
        temp_dir = Path(temp_name)
        cases_path = temp_dir / "cases.jsonl"
        out_dir = temp_dir / "out"
        cases_path.write_text(
            json.dumps(
                {
                    "case_id": "P5C_T4_MAIN",
                    "question": "Write a short evidence synthesis.",
                    "expected_best_mode": "agent",
                },
                ensure_ascii=False,
            )
            + "\n",
            encoding="utf-8",
        )

        original_run_dt = scorer.run_dt
        original_run_agent = scorer.run_agent
        original_argv = sys.argv[:]
        try:
            scorer.run_dt = lambda case, base_url, timeout, model: {
                "ok": True,
                "answer": "Deep Think concise answer [1].",
                "used_plugins": ["reference_materials"],
                "citations": [{"title": "DT citation"}],
                "timings": {"total_ms": 10},
                "errors": [],
            }
            scorer.run_agent = lambda case, base_url, timeout, poll_interval, model: {
                "ok": True,
                "answer": "Agent evidence synthesis with citations [1][2] and limitations.",
                "used_plugins": ["graph_search", "reference_materials"],
                "citations": [{"title": "A"}, {"title": "B"}],
                "evidence_package": {"supported_claims": [{"claim": "Supported claim."}]},
                "evidence_walk": {"steps": [{"claim": "Supported claim."}]},
                "report_plan": {"sections": ["Evidence", "Limitations"]},
                "integrity_report": {"ok": True},
                "timings": {"total_ms": 20},
                "errors": [],
            }
            sys.argv = [
                "run_dt_agent_live_eval.py",
                "--cases",
                str(cases_path),
                "--out-dir",
                str(out_dir),
                "--semantic-proxy",
            ]

            assert_equal(scorer.main(), 0, "main loop exit code")
        finally:
            scorer.run_dt = original_run_dt
            scorer.run_agent = original_run_agent
            sys.argv = original_argv

        raw = json.loads((out_dir / "raw_events" / "P5C_T4_MAIN.json").read_text(encoding="utf-8"))
        rows = read_jsonl(out_dir / "case_results.jsonl")
        summary = json.loads((out_dir / "summary.json").read_text(encoding="utf-8"))
        assert_equal(raw["semantic_evaluation"]["schema_version"], "dt_agent_semantic_proxy.v1", "main raw semantic schema")
        assert_equal(rows[0]["semantic_evaluation"]["case_id"], "P5C_T4_MAIN", "main case row semantic case id")
        assert_equal("semantic_winner_counts" in summary, True, "main summary includes semantic counts")


if __name__ == "__main__":
    test_compact_agent_artifact_is_not_overkill()
    test_rescore_existing_omits_semantic_fields_by_default()
    test_rescore_existing_adds_semantic_fields_when_enabled()
    test_main_loop_adds_semantic_fields_when_enabled_without_live_api()
    print("DT Agent live eval scorer tests passed.")
