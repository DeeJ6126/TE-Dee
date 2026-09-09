from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

from harness_lib import app_url, fail, http_json, ok, require, run_check


BASE_PAGES = [
    ("browse.php", "#browseKeyword", None, "TE", "L"),
    ("expression.php", 'input[name="keyword"]', None, "TE", "L"),
]


def http_json_any_status(url: str, timeout: int = 30) -> tuple[int, dict[str, Any]]:
    request = urllib.request.Request(url, headers={"Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8", errors="replace")
            status = int(response.status)
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        status = int(exc.code)
    except urllib.error.URLError as exc:
        fail(f"Unable to reach {url}: {exc.reason}")
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        fail(f"Invalid JSON from {url}: {exc}\n{raw[:1000]}")
    require(isinstance(data, dict), f"Expected JSON object from {url}, got {type(data).__name__}")
    return status, data


def main() -> None:
    taxonomy = http_json(app_url("api/taxonomy.php?view=items"))
    require(taxonomy.get("ok") is True, "taxonomy items API must return ok=true")
    require(taxonomy.get("source") == "tekg3", f"taxonomy source must be tekg3: {taxonomy.get('source')}")
    names = [str(item.get("name", "")).strip() for item in taxonomy.get("items", []) if isinstance(item, dict)]
    require(any(name.lower().startswith("l") for name in names), "taxonomy items should include L-prefix TE names")

    entity_types = http_json(app_url("api/path_finder.php?view=entity_types"))
    require(entity_types.get("ok") is True, "path finder entity_types API must return ok=true")
    require(entity_types.get("source") == "tekg3", f"path finder entity_types source must be tekg3: {entity_types.get('source')}")
    require("TE" in entity_types.get("entity_types", []), "path finder entity types should include TE")
    require("Disease" in entity_types.get("entity_types", []), "path finder entity types should include Disease")

    path_entity_names: dict[str, set[str]] = {}
    te_entities = http_json(app_url("api/path_finder.php?view=entities&type=TE&q=L&limit=180"))
    require(te_entities.get("ok") is True, "path finder TE entity API must return ok=true")
    require(te_entities.get("source") == "tekg3", f"path finder TE entity source must be tekg3: {te_entities.get('source')}")
    path_entity_names["TE"] = {
        str(item.get("name", "")).strip()
        for item in te_entities.get("items", [])
        if isinstance(item, dict) and str(item.get("name", "")).strip()
    }
    require(path_entity_names["TE"], "path finder TE entity API should return L-prefix names")
    require(
        all(isinstance(item, dict) and item.get("type") == "TE" for item in te_entities.get("items", [])),
        f"path finder TE entity API should return only TE items: {te_entities.get('items', [])[:3]}",
    )
    require(all(name.lower().startswith("l") for name in path_entity_names["TE"]), "path finder TE names should be L-prefix filtered")

    disease_entities = http_json(app_url("api/path_finder.php?view=entities&type=Disease&limit=20"))
    require(disease_entities.get("ok") is True, "path finder Disease entity API must return ok=true")
    require(disease_entities.get("source") == "tekg3", f"path finder Disease entity source must be tekg3: {disease_entities.get('source')}")
    disease_names = [
        str(item.get("name", "")).strip()
        for item in disease_entities.get("items", [])
        if isinstance(item, dict) and str(item.get("name", "")).strip()
    ]
    require(disease_names, "path finder Disease entity API should return names")
    disease_prefix = disease_names[0][0]
    disease_prefixed = http_json(app_url(f"api/path_finder.php?view=entities&type=Disease&q={disease_prefix}&limit=180"))
    path_entity_names["Disease"] = {
        str(item.get("name", "")).strip()
        for item in disease_prefixed.get("items", [])
        if isinstance(item, dict) and str(item.get("name", "")).strip()
    }
    require(path_entity_names["Disease"], f"path finder Disease entity API should return {disease_prefix}-prefix names")
    require(
        all(isinstance(item, dict) and item.get("type") == "Disease" for item in disease_prefixed.get("items", [])),
        f"path finder Disease entity API should return only Disease items: {disease_prefixed.get('items', [])[:3]}",
    )

    typed_path_query = urllib.parse.urlencode({
        "source": "L1HS",
        "target": "Aging",
        "source_type": "TE",
        "target_type": "Disease",
        "max_depth": "3",
    })
    typed_path = http_json(app_url(f"api/path_finder.php?{typed_path_query}"), timeout=60)
    require(typed_path.get("ok") is True, "typed Path Finder query should resolve source and target")
    require(typed_path.get("source_type") == "TE", f"typed Path Finder source_type should stay TE: {typed_path.get('source_type')}")
    require(typed_path.get("target_type") == "Disease", f"typed Path Finder target_type should stay Disease: {typed_path.get('target_type')}")
    require((typed_path.get("source") or {}).get("type") == "TE", f"typed source should resolve as TE: {typed_path.get('source')}")
    require((typed_path.get("target") or {}).get("type") == "Disease", f"typed target should resolve as Disease: {typed_path.get('target')}")

    invalid_type_query = urllib.parse.urlencode({
        "source": "L1HS",
        "target": "Aging",
        "source_type": "Disease",
        "target_type": "Disease",
        "max_depth": "3",
    })
    status, invalid_type_path = http_json_any_status(app_url(f"api/path_finder.php?{invalid_type_query}"), timeout=60)
    require(status == 404, f"wrong-type Path Finder query should return 404, got HTTP {status}")
    require(invalid_type_path.get("ok") is False, f"wrong-type Path Finder query should fail: {invalid_type_path}")
    require(invalid_type_path.get("source") is None, f"wrong-type source should not resolve L1HS as Disease: {invalid_type_path.get('source')}")

    pages = [
        *BASE_PAGES,
        ("path_finder.php", "#pathSource", "#pathSourceType", "TE", "L"),
        ("path_finder.php", "#pathTarget", "#pathTargetType", "Disease", disease_prefix),
    ]

    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        raise AssertionError("Playwright is not installed")

    with sync_playwright() as p:
        try:
            browser = p.chromium.launch(headless=True)
        except PlaywrightError as exc:
            raise AssertionError(f"Unable to launch Chromium: {exc}") from exc

        page = browser.new_page(viewport={"width": 1440, "height": 960})
        try:
            for path, selector, type_selector, entity_type, prefix in pages:
                page.goto(app_url(path), wait_until="domcontentloaded", timeout=30000)
                if type_selector:
                    page.locator(type_selector).select_option(entity_type)
                input_box = page.locator(selector).first
                root = input_box.locator("xpath=ancestor::*[@data-te-autocomplete-root][1]")
                require(root.count() == 1, f"{path} {selector} missing TE autocomplete wrapper")
                root.locator("[data-te-autocomplete-toggle]").click()
                root.locator(".te-autocomplete-option").first.wait_for(timeout=30000)
                input_box.fill(prefix)
                page.wait_for_function(
                    f"""root => {{
                        const prefix = {json.dumps(prefix)};
                        const option = root.querySelector('.te-autocomplete-option');
                        return option && option.textContent.trim().toLowerCase().startsWith(prefix.toLowerCase())
                            && option.querySelector('.te-autocomplete-match')
                            && option.querySelector('.te-autocomplete-rest');
                    }}""",
                    arg=root.element_handle(),
                    timeout=10000,
                )
                option_text = root.locator(".te-autocomplete-option").first.inner_text().strip()
                if type_selector:
                    require(option_text in path_entity_names[entity_type], f"{path} {selector} option should come from selected {entity_type} API: {option_text}")
                match_color = root.locator(".te-autocomplete-match").first.evaluate("el => getComputedStyle(el).color")
                rest_color = root.locator(".te-autocomplete-rest").first.evaluate("el => getComputedStyle(el).color")
                require(match_color != rest_color, f"{path} {selector} should style prefix differently")
        finally:
            browser.close()

    ok("TE autocomplete smoke passed for Browse, Path Finder, and Expression")


if __name__ == "__main__":
    run_check(main)
