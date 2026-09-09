from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

from harness_lib import app_url, fail, ok, require, run_check


def fetch(params: dict[str, str | int], timeout: int = 30) -> dict[str, Any]:
    url = app_url(f"api/path_finder.php?{urllib.parse.urlencode(params)}")
    request = urllib.request.Request(url, headers={"Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        fail(f"HTTP {exc.code} for {url}\n{body[:1000]}")
    except urllib.error.URLError as exc:
        fail(f"Unable to reach {url}: {exc.reason}")

    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        fail(f"Invalid JSON from {url}: {exc}\n{raw[:1000]}")
    require(isinstance(payload, dict), f"Expected JSON object from {url}")
    return payload


def candidate_sources() -> list[tuple[str, str]]:
    pairs: list[tuple[str, str]] = []
    for entity_type in ["TE", "Disease", "Gene", "Function"]:
        payload = fetch({"view": "entities", "type": entity_type, "limit": 12})
        require(payload.get("ok") is True, f"entities API failed for {entity_type}: {payload}")
        for item in payload.get("items", []):
            if not isinstance(item, dict):
                continue
            name = str(item.get("name", "")).strip()
            if name:
                pairs.append((entity_type, name))
    return pairs


def first_connected_payload() -> tuple[str, str, str, dict[str, Any]]:
    failures: list[str] = []
    target_types = ["Disease", "Gene", "Function", "TE"]
    for source_type, source in candidate_sources():
        for target_type in target_types:
            if source_type == target_type:
                continue
            try:
                payload = fetch(
                    {
                        "view": "connected_candidates",
                        "source": source,
                        "source_type": source_type,
                        "target_type": target_type,
                        "q": "",
                        "max_depth": 3,
                        "limit": 20,
                    },
                    timeout=40,
                )
            except AssertionError as exc:
                failures.append(f"{source_type}:{source} -> {target_type}: {exc}")
                continue

            if payload.get("ok") is not True:
                failures.append(f"{source_type}:{source} -> {target_type}: {payload.get('error')}")
                continue

            items = payload.get("items")
            if isinstance(items, list) and items:
                return source_type, source, target_type, payload

    if failures:
        fail("No connected candidates found. Sample failures:\n- " + "\n- ".join(failures[:8]))
    fail("No connected candidates found in sampled sources")
    raise AssertionError("unreachable")


def hop_group_label(min_hop: int) -> str:
    if min_hop == 1:
        return "Direct connection"
    if min_hop == 2:
        return "2-hop path"
    return "3-hop path"


def assert_api_contract(source_type: str, source: str, target_type: str, payload: dict[str, Any]) -> dict[str, Any]:
    require(payload.get("source") == "tekg3", f"connected candidates source should be tekg3: {payload}")
    require(payload.get("database") == "tekg3", f"connected candidates database should be tekg3: {payload}")
    require(payload.get("source_type") == source_type, f"source_type should be preserved: {payload}")
    require(payload.get("target_type") == target_type, f"target_type should be preserved: {payload}")
    require(payload.get("max_depth") == 3, f"requested max_depth should be preserved: {payload}")

    items = payload.get("items")
    require(isinstance(items, list) and items, f"connected candidates should return items: {payload}")
    first = items[0]
    require(isinstance(first, dict), f"candidate item should be an object: {first}")
    require(first.get("type") == target_type, f"candidate type should match target_type: {first}")
    require("Paper" not in first.get("labels", []), f"candidate should not be Paper: {first}")
    require(first.get("min_hop") in [1, 2, 3], f"candidate min_hop should be 1..3: {first}")
    require(isinstance(first.get("path_count"), int) and first["path_count"] >= 1, f"path_count should be positive: {first}")
    require(isinstance(first.get("pmid_count"), int), f"pmid_count should be an int: {first}")

    prefix = str(first.get("name", ""))[:1]
    if prefix:
        prefixed = fetch(
            {
                "view": "connected_candidates",
                "source": source,
                "source_type": source_type,
                "target_type": target_type,
                "q": prefix,
                "max_depth": 3,
                "limit": 20,
            },
            timeout=40,
        )
        require(prefixed.get("ok") is True, f"prefixed connected candidates should succeed: {prefixed}")
        prefixed_names = [
            str(item.get("name", "")).strip()
            for item in prefixed.get("items", [])
            if isinstance(item, dict)
        ]
        require(prefixed_names, f"prefixed connected candidates should return names for {prefix!r}: {prefixed}")
        require(all(name.lower().startswith(prefix.lower()) for name in prefixed_names), f"prefix filter failed: {prefixed_names[:5]}")

    return first


def assert_depth_clamp_contract() -> None:
    payload = fetch(
        {
            "view": "connected_candidates",
            "source": "",
            "source_type": "TE",
            "target_type": "Disease",
            "max_depth": 99,
            "limit": 1,
        }
    )
    require(payload.get("ok") is True, f"empty-source depth contract request should succeed: {payload}")
    require(payload.get("max_depth") == 10, f"max_depth should clamp to 10: {payload}")
    require(payload.get("items") == [], f"empty source should not query connected candidates: {payload}")


def assert_browser_grouping(source_type: str, source: str, target_type: str, expected_first: dict[str, Any]) -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed")

    expected_hop_label = hop_group_label(int(expected_first["min_hop"]))
    expected_name = str(expected_first.get("name", "")).strip()

    with sync_playwright() as p:
        try:
            browser = p.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")

        page = browser.new_page(viewport={"width": 1440, "height": 960})
        console_errors: list[str] = []
        page.on(
            "console",
            lambda msg: console_errors.append(msg.text)
            if msg.type == "error" and "ERR_NETWORK_ACCESS_DENIED" not in msg.text
            else None,
        )
        try:
            page.goto(app_url("path_finder.php"), wait_until="domcontentloaded", timeout=30000)
            page.locator("#pathSourceType").select_option(source_type)
            page.locator("#pathSource").fill(source)
            page.locator("#pathTargetType").select_option(target_type)
            target_input = page.locator("#pathTarget")
            target_root = target_input.locator("xpath=ancestor::*[@data-te-autocomplete-root][1]")
            require(target_root.count() == 1, "target input should be wrapped by te-autocomplete root")
            target_root.locator("[data-te-autocomplete-toggle]").click()
            target_root.locator(".te-autocomplete-meta").first.wait_for(timeout=30000)
            state = target_root.evaluate(
                """
                root => ({
                    groups: Array.from(root.querySelectorAll('.te-autocomplete-group')).map(el => el.textContent.trim()),
                    options: Array.from(root.querySelectorAll('.te-autocomplete-option')).map(el => el.textContent.trim()),
                    metas: Array.from(root.querySelectorAll('.te-autocomplete-meta')).map(el => el.textContent.trim()),
                })
                """
            )
            target_root.locator("[data-te-autocomplete-toggle]").click()
            page.locator("#pathSource").fill("__not_a_real_tekg_endpoint__")
            target_root.locator("[data-te-autocomplete-toggle]").click()
            target_root.locator(".te-autocomplete-option").first.wait_for(timeout=30000)
            fallback_state = target_root.evaluate(
                """
                root => ({
                    groups: Array.from(root.querySelectorAll('.te-autocomplete-group')).map(el => el.textContent.trim()),
                    optionCount: root.querySelectorAll('.te-autocomplete-option').length,
                    emptyText: root.querySelector('.te-autocomplete-empty')?.textContent.trim() || '',
                })
                """
            )
        finally:
            browser.close()

    require(not console_errors, "Path Finder autocomplete console errors: " + " | ".join(console_errors[:5]))
    require(state["groups"] == [], f"hop groups should be shown in option meta, not separate headers: {state}")
    require(any(expected_name in option for option in state["options"]), f"expected API candidate {expected_name!r} in browser dropdown: {state}")
    require(any(expected_hop_label in meta for meta in state["metas"]), f"connected candidate meta should include hop label: {state}")
    require(any("Shortest Path" in meta and "PMID" in meta for meta in state["metas"]), f"connected candidate meta should identify shortest-path counts: {state}")
    require(all("in best path" not in meta for meta in state["metas"]), f"PMID meta should not say in best path: {state}")
    require(fallback_state["optionCount"] > 0, f"invalid source should fall back to all target entities: {fallback_state}")
    require(fallback_state["groups"] == [], f"fallback all-entity dropdown should not show connected groups: {fallback_state}")


def main() -> None:
    assert_depth_clamp_contract()
    source_type, source, target_type, payload = first_connected_payload()
    first = assert_api_contract(source_type, source, target_type, payload)
    assert_browser_grouping(source_type, source, target_type, first)
    ok(
        "Path Finder connected candidates passed: "
        f"{source_type}:{source} -> {target_type}, "
        f"first={first.get('name')} ({first.get('min_hop')} hop), "
        f"items={len(payload.get('items', []))}"
    )


if __name__ == "__main__":
    run_check(main)
