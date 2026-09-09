#!/usr/bin/env python3
"""Regression tests for the DT vs Agent semantic proxy scorer."""

from __future__ import annotations

import importlib.util
import json
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SCORER_PATH = ROOT / "scripts" / "eval" / "semantic_eval.py"


def load_scorer():
    spec = importlib.util.spec_from_file_location("semantic_eval", SCORER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError(f"Could not load semantic scorer module: {SCORER_PATH}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def assert_equal(actual, expected, message: str) -> None:
    if actual != expected:
        raise AssertionError(f"{message}: expected {expected!r}, got {actual!r}")


def assert_in(actual, expected_values, message: str) -> None:
    if actual not in expected_values:
        raise AssertionError(f"{message}: expected one of {expected_values!r}, got {actual!r}")


def test_agent_with_supported_research_artifacts_beats_deep_think() -> None:
    scorer = load_scorer()
    result = scorer.score_semantic_proxy(
        {"case_id": "P5A_B_sem_agent", "expected_best_mode": "agent"},
        {
            "ok": True,
            "answer": "LINE-1 is associated with cancer through insertional mutagenesis.",
            "citations": [],
        },
        {
            "ok": True,
            "answer": (
                "LINE-1 may contribute to cancer through insertional mutagenesis, "
                "epigenetic dysregulation, and immune signaling. This synthesis is "
                "supported by the retrieved papers [1][2]. Limitations: disease "
                "specific causality remains incomplete."
            ),
            "citations": [{"pmid": "123"}, {"pmid": "456"}],
            "evidence_walk": {
                "steps": [
                    {"claim": "LINE-1 insertion can disrupt tumor suppressors.", "support": "high"},
                    {"claim": "Hypomethylation can activate LINE-1 in tumors.", "support": "medium"},
                ]
            },
            "evidence_package": {
                "supported_claims": [
                    "LINE-1 insertion can disrupt tumor suppressors.",
                    "Hypomethylation can activate LINE-1 in tumors.",
                ],
                "missing_evidence": ["No single study proves pan-cancer causality."],
            },
            "report_plan": {"sections": ["mechanism", "evidence", "limitations"]},
        },
    )

    assert_equal(result["schema_version"], "dt_agent_semantic_proxy.v1", "schema version")
    assert_equal(result["semantic_winner"], "agent", "supported Agent should win")
    assert result["claim_support_score"] > 0.6
    assert result["citation_relevance_score"] > 0.6
    assert result["missing_evidence_score"] > 0.5
    assert result["research_usefulness_score"] > 0.6


def test_long_agent_answer_without_evidence_or_citations_does_not_win() -> None:
    scorer = load_scorer()
    result = scorer.score_semantic_proxy(
        {"case_id": "P5A_B_sem_unsupported", "expected_best_mode": "agent"},
        {
            "ok": True,
            "answer": "Deep Think gives a concise answer with a source [1].",
            "citations": [{"source": "local"}],
        },
        {
            "ok": True,
            "answer": " ".join(["This is a broad research-style narrative without source support."] * 80),
            "citations": [],
            "evidence_walk": {},
            "evidence_package": {},
        },
    )

    assert_in(result["semantic_winner"], {"deep_think", "tie"}, "unsupported long Agent should not win")
    assert result["citation_relevance_score"] < 0.5
    assert result["claim_support_score"] < 0.5


def test_expected_deep_think_with_compact_agent_does_not_force_agent_win() -> None:
    scorer = load_scorer()
    result = scorer.score_semantic_proxy(
        {"case_id": "P5A_B_sem_dt", "expected_best_mode": "deep_think"},
        {
            "ok": True,
            "answer": "L1HS consensus length is 6064 bp [1].",
            "citations": [{"source": "repbase"}],
        },
        {
            "ok": True,
            "answer": "L1HS consensus length is 6064 bp.",
            "citations": [],
            "used_plugins": ["Entity Resolver"],
        },
    )

    assert_in(result["semantic_winner"], {"deep_think", "tie"}, "compact Agent should not win DT case")


def test_cli_falls_back_when_raw_events_are_missing() -> None:
    scorer = load_scorer()
    with tempfile.TemporaryDirectory() as temp_name:
        run_dir = Path(temp_name)
        (run_dir / "case_results.jsonl").write_text(
            json.dumps(
                {
                    "case_id": "P5C_MISSING_RAW",
                    "question": "A saved summary-only case.",
                    "expected_best_mode": "deep_think",
                    "evaluation": {
                        "expected_best_mode": "deep_think",
                        "dt_ok": True,
                        "agent_ok": True,
                        "dt_plugins": ["Entity Resolver"],
                        "agent_plugins": ["Entity Resolver", "Site Navigator Plugin"],
                    },
                },
                ensure_ascii=False,
                separators=(",", ":"),
            )
            + "\n",
            encoding="utf-8",
        )

        assert_equal(scorer.run_cli(run_dir), 0, "semantic CLI exit code")

        rows = [
            json.loads(line)
            for line in (run_dir / "semantic_case_results.jsonl").read_text(encoding="utf-8").splitlines()
            if line.strip()
        ]
        summary = json.loads((run_dir / "semantic_summary.json").read_text(encoding="utf-8"))
        assert_equal(rows[0]["case_id"], "P5C_MISSING_RAW", "fallback case id")
        assert any("raw_events file missing" in note for note in rows[0]["semantic_notes"])
        assert_equal(summary["limitation_case_count"], 1, "fallback limitation count")


if __name__ == "__main__":
    test_agent_with_supported_research_artifacts_beats_deep_think()
    test_long_agent_answer_without_evidence_or_citations_does_not_win()
    test_expected_deep_think_with_compact_agent_does_not_force_agent_win()
    test_cli_falls_back_when_raw_events_are_missing()
    print("DT Agent semantic eval tests passed.")
