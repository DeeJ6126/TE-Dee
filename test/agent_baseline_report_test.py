import importlib.util
import json
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "eval" / "build_agent_baseline_report.py"


class AgentBaselineReportTest(unittest.TestCase):
    def test_builds_human_readable_report_with_trace_and_timings(self):
        self.assertTrue(MODULE_PATH.is_file(), "report builder must exist")
        spec = importlib.util.spec_from_file_location("baseline_report", MODULE_PATH)
        module = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(module)

        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            cases_path = tmp_path / "cases.jsonl"
            run_dir = tmp_path / "run"
            raw_dir = run_dir / "raw_events"
            raw_dir.mkdir(parents=True)
            diagnostics_path = tmp_path / "diagnostics.jsonl"
            output_path = run_dir / "report.md"

            case = {
                "case_id": "AQ01",
                "question": "What is the L1HS consensus length?",
                "category": "sequence_lookup",
                "evaluation_mode": "deep_think",
                "expected_plugins": ["Entity Resolver", "Sequence Plugin"],
            }
            cases_path.write_text(json.dumps(case) + "\n", encoding="utf-8")
            record = {
                "case": case,
                "dt": {
                    "ok": True,
                    "answer": "L1HS is 6064 bp.",
                    "timings": {"total_ms": 12345},
                    "errors": [],
                    "events": [
                        {
                            "type": "artifact",
                            "request_id": "req_fixture",
                            "node": "Understanding",
                            "payload": {"artifact": {"question_summary": "Sequence lookup", "answer_goal": "Return length."}},
                        },
                        {"type": "tool_selected", "plugin_name": "Entity Resolver", "message": "Resolve the entity."},
                        {"type": "tool_selected", "plugin_name": "Sequence Plugin", "message": "Retrieve the sequence."},
                    ],
                },
                "agent": {"skipped": True, "ok": False, "answer": "", "errors": []},
            }
            (raw_dir / "AQ01.json").write_text(json.dumps(record), encoding="utf-8")
            diagnostics = [
                {
                    "request_id": "req_fixture",
                    "event": "http_request_complete",
                    "payload": {"stage": "llm_dt_understanding", "duration_ms": 1200, "status": 200},
                },
                {
                    "request_id": "req_fixture",
                    "event": "deepthink_plugin_completed",
                    "payload": {"plugin_name": "Sequence Plugin", "status": "ok", "latency_ms": 3, "result_counts": {"matched_records": 1}},
                },
            ]
            diagnostics_path.write_text("\n".join(json.dumps(row) for row in diagnostics) + "\n", encoding="utf-8")

            module.build_report(cases_path, run_dir, diagnostics_path, output_path)
            report = output_path.read_text(encoding="utf-8")

            self.assertIn("AQ01", report)
            self.assertIn("What is the L1HS consensus length?", report)
            self.assertIn("L1HS is 6064 bp.", report)
            self.assertIn("Understanding", report)
            self.assertIn("1,200", report)
            self.assertIn("Sequence Plugin", report)
            self.assertIn("Visible Reasoning And Workflow Trace", report)


if __name__ == "__main__":
    unittest.main()
