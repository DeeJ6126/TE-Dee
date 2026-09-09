from __future__ import annotations

import base64
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_BASE_URL = "http://127.0.0.1/tedee/"


class HarnessFailure(AssertionError):
    pass


def fail(message: str) -> None:
    raise HarnessFailure(message)


def ok(message: str) -> None:
    print(f"[OK] {message}")


def base_url() -> str:
    value = os.environ.get("TEKG_BASE_URL", DEFAULT_BASE_URL).strip()
    if not value:
        value = DEFAULT_BASE_URL
    return value if value.endswith("/") else f"{value}/"


def app_url(path: str) -> str:
    return urllib.parse.urljoin(base_url(), path.lstrip("/"))


def http_json(url: str, timeout: int = 30) -> dict[str, Any]:
    request = urllib.request.Request(url, headers={"Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8", errors="replace")
            status = int(response.status)
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        fail(f"HTTP {exc.code} for {url}\n{body[:1000]}")
    except urllib.error.URLError as exc:
        fail(f"Unable to reach {url}: {exc.reason}")
    except TimeoutError:
        fail(f"Timeout while requesting {url}")

    if status >= 400:
        fail(f"HTTP {status} for {url}")
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        fail(f"Invalid JSON from {url}: {exc}\n{raw[:1000]}")
    if not isinstance(data, dict):
        fail(f"Expected JSON object from {url}, got {type(data).__name__}")
    return data


def read_php_array_config(path: Path) -> dict[str, str]:
    if not path.is_file():
        fail(f"Missing config file: {path}")
    text = path.read_text(encoding="utf-8", errors="replace")
    pairs = re.findall(r"['\"]([^'\"]+)['\"]\s*=>\s*['\"]([^'\"]*)['\"]", text)
    return {key: value for key, value in pairs}


def neo4j_config() -> dict[str, str]:
    config = read_php_array_config(ROOT / "api" / "config.local.php")
    missing = [key for key in ("neo4j_url", "neo4j_user", "neo4j_password") if not config.get(key)]
    if missing:
        fail(f"api/config.local.php missing Neo4j config keys: {', '.join(missing)}")
    return config


def neo4j_database_name(config: dict[str, str]) -> str:
    url = config.get("neo4j_url", "")
    match = re.search(r"/db/([^/]+)/tx/commit", url)
    return urllib.parse.unquote(match.group(1)) if match else ""


def neo4j_query(config: dict[str, str], statement: str, parameters: dict[str, Any] | None = None, timeout: int = 30) -> list[dict[str, Any]]:
    payload = json.dumps({
        "statements": [{
            "statement": statement,
            "parameters": parameters or {},
        }]
    }).encode("utf-8")
    token = base64.b64encode(f"{config['neo4j_user']}:{config['neo4j_password']}".encode("utf-8")).decode("ascii")
    request = urllib.request.Request(
        config["neo4j_url"],
        data=payload,
        headers={
            "Authorization": f"Basic {token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8", errors="replace")
            status = int(response.status)
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        fail(f"Neo4j HTTP {exc.code}: {body[:1000]}")
    except urllib.error.URLError as exc:
        fail(f"Unable to reach Neo4j at {config['neo4j_url']}: {exc.reason}")
    except TimeoutError:
        fail(f"Timeout while requesting Neo4j at {config['neo4j_url']}")

    if status >= 400:
        fail(f"Neo4j HTTP {status}: {raw[:1000]}")
    data = json.loads(raw)
    if data.get("errors"):
        fail(f"Neo4j error: {data['errors'][0].get('message', data['errors'][0])}")
    result = (data.get("results") or [{}])[0]
    columns = result.get("columns") or []
    rows = []
    for entry in result.get("data") or []:
        values = entry.get("row") or []
        rows.append({str(column): values[index] if index < len(values) else None for index, column in enumerate(columns)})
    return rows


def require(condition: bool, message: str) -> None:
    if not condition:
        fail(message)


def run_check(main_func) -> None:
    try:
        main_func()
    except HarnessFailure as exc:
        print(f"[FAIL] {exc}", file=sys.stderr)
        raise SystemExit(1)
