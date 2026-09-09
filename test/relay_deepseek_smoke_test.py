import json
import subprocess
import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "eval" / "relay_deepseek_smoke.py"


class RelayDeepseekSmokeTest(unittest.TestCase):
    def test_dry_run_outputs_request_plan_without_live_calls(self):
        result = subprocess.run(
            [
                sys.executable,
                str(SCRIPT),
                "--concurrency",
                "3",
                "--model",
                "deepseek-v4-flash",
                "--prompt",
                "ping",
                "--timeout",
                "7",
                "--relay-url",
                "http://127.0.0.1:18087/chat",
            ],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=True,
        )

        summary = json.loads(result.stdout)
        self.assertEqual("dry_run", summary["mode"])
        self.assertFalse(summary["live"])
        self.assertEqual("http://127.0.0.1:18087/chat", summary["relay_url"])
        self.assertEqual("deepseek-v4-flash", summary["model"])
        self.assertEqual(3, summary["concurrency"])
        self.assertEqual(7, summary["timeout"])
        self.assertEqual(3, len(summary["request_plan"]))
        self.assertTrue(all(item["provider"] == "deepseek" for item in summary["request_plan"]))
        self.assertIn("config", summary)
        self.assertIn("key_present", summary["config"])


if __name__ == "__main__":
    unittest.main()
