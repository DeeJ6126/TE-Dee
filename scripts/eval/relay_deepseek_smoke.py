#!/usr/bin/env python3
"""Dry-run first relay/deepseek concurrency smoke check."""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
LOCAL_CONFIG = ROOT / "api" / "config.local.php"


def read_php_string_config(path: Path) -> dict[str, str]:
    if not path.is_file():
        return {}
    source = path.read_text(encoding="utf-8", errors="replace")
    values: dict[str, str] = {}
    for match in re.finditer(r"'([^']+)'\s*=>\s*'([^']*)'", source):
        values[match.group(1)] = match.group(2)
    return values


def config_value(local: dict[str, str], key: str, env_names: list[str], default: str = "") -> str:
    if local.get(key, "").strip():
        return local[key].strip()
    for name in env_names:
        value = os.getenv(name, "").strip()
        if value:
            return value
    return default


def build_request_plan(concurrency: int, model: str, prompt: str) -> list[dict[str, Any]]:
    return [
        {
            "request_index": index,
            "provider": "deepseek",
            "model": model,
            "prompt_preview": prompt[:120],
        }
        for index in range(1, concurrency + 1)
    ]


def post_relay_request(relay_url: str, model: str, prompt: str, timeout: int, request_index: int) -> dict[str, Any]:
    payload = {
        "provider": "deepseek",
        "model": model,
        "messages": [
            {"role": "system", "content": "You are a concise smoke-test assistant."},
            {"role": "user", "content": prompt},
        ],
        "temperature": 0,
    }
    data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    request = urllib.request.Request(
        relay_url,
        data=data,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "TEKG-Relay-DeepSeek-Smoke/1.0",
        },
        method="POST",
    )
    started = time.time()
    status = 0
    body = ""
    error = ""
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            status = int(response.status)
            body = response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        status = int(exc.code)
        body = exc.read().decode("utf-8", errors="replace")
        error = body[:500]
    except Exception as exc:
        error = str(exc)
    elapsed_ms = int(round((time.time() - started) * 1000))
    decoded: Any = None
    if body:
        try:
            decoded = json.loads(body)
        except json.JSONDecodeError:
            decoded = None
    return {
        "request_index": request_index,
        "ok": 200 <= status < 300 and not error,
        "http_status": status,
        "duration_ms": elapsed_ms,
        "body_length": len(body),
        "json_response": isinstance(decoded, dict),
        "error": error,
    }


def run_live(args: argparse.Namespace) -> list[dict[str, Any]]:
    results: list[dict[str, Any]] = []
    with ThreadPoolExecutor(max_workers=args.concurrency) as executor:
        futures = [
            executor.submit(post_relay_request, args.relay_url, args.model, args.prompt, args.timeout, index)
            for index in range(1, args.concurrency + 1)
        ]
        for future in as_completed(futures):
            results.append(future.result())
    return sorted(results, key=lambda item: int(item["request_index"]))


def positive_int(value: str) -> int:
    parsed = int(value)
    if parsed < 1:
        raise argparse.ArgumentTypeError("value must be >= 1")
    return parsed


def main(argv: list[str] | None = None) -> int:
    local = read_php_string_config(LOCAL_CONFIG)
    default_relay_url = config_value(local, "llm_relay_url", ["BIOLOGY_LLM_RELAY_URL", "LLM_RELAY_URL"], "http://127.0.0.1:18087/chat")
    default_model = config_value(local, "deepseek_model", ["DEEPSEEK_MODEL"], "deepseek-chat")
    key = config_value(local, "deepseek_key", ["DEEPSEEK_API_KEY"], "")

    parser = argparse.ArgumentParser(description="Smoke test relay/deepseek concurrency. Defaults to dry-run.")
    parser.add_argument("--run-live", action="store_true", help="Actually POST concurrent requests to the relay.")
    parser.add_argument("--concurrency", type=positive_int, default=2)
    parser.add_argument("--model", default=default_model)
    parser.add_argument("--prompt", default="Return the word ok.")
    parser.add_argument("--timeout", type=positive_int, default=30)
    parser.add_argument("--relay-url", default=default_relay_url)
    args = parser.parse_args(argv)

    started = time.time()
    plan = build_request_plan(args.concurrency, args.model, args.prompt)
    results = run_live(args) if args.run_live else []
    ok_count = sum(1 for item in results if item.get("ok"))
    summary = {
        "ok": (ok_count == args.concurrency) if args.run_live else True,
        "mode": "live" if args.run_live else "dry_run",
        "live": bool(args.run_live),
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "relay_url": args.relay_url,
        "model": args.model,
        "provider": "deepseek",
        "concurrency": args.concurrency,
        "timeout": args.timeout,
        "config": {
            "config_path": str(LOCAL_CONFIG),
            "config_present": LOCAL_CONFIG.is_file(),
            "relay_url_from_config_or_env": default_relay_url,
            "model_from_config_or_env": default_model,
            "key_present": bool(key),
        },
        "request_plan": plan,
        "results": results,
        "metrics": {
            "ok_count": ok_count,
            "error_count": len(results) - ok_count,
            "total_ms": int(round((time.time() - started) * 1000)),
        },
    }
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0 if summary["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
