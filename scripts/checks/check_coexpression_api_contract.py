from __future__ import annotations

import json
import re
import statistics
import time
import urllib.error
import urllib.request
from typing import Any

from harness_lib import app_url, fail, ok, require, run_check


def request(path: str, method: str = "GET") -> tuple[int, dict[str, str], str]:
    http_request = urllib.request.Request(
        app_url(path),
        headers={"Accept": "application/json"},
        method=method,
    )
    try:
        with urllib.request.urlopen(http_request, timeout=30) as response:
            return int(response.status), dict(response.headers.items()), response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as error:
        return int(error.code), dict(error.headers.items()), error.read().decode("utf-8", errors="replace")
    except urllib.error.URLError as error:
        fail(f"Unable to reach {app_url(path)}: {error.reason}")
    return 0, {}, ""


def json_response(path: str, expected_status: int, method: str = "GET", cacheable: bool | None = None) -> dict[str, Any]:
    status, headers, body = request(path, method)
    require(status == expected_status, f"{method} {path} expected HTTP {expected_status}, received {status}: {body[:500]}")
    require("application/json" in headers.get("Content-Type", "").lower(), f"{method} {path} must return JSON Content-Type")
    cache_control = headers.get("Cache-Control", "").lower()
    if cacheable is True:
        require("public" in cache_control and "no-store" not in cache_control, f"{method} {path} successful response must be cacheable")
    elif cacheable is False:
        require("no-store" in cache_control, f"{method} {path} error response must not be cacheable")
    try:
        payload = json.loads(body)
    except json.JSONDecodeError as error:
        fail(f"{method} {path} returned invalid JSON: {error}: {body[:500]}")
    require(isinstance(payload, dict), f"{method} {path} must return a JSON object")
    require(not re.search(r"[A-Za-z]:[\\/]", body) and "Warning" not in body and "Stack trace" not in body, f"{method} {path} leaked implementation details")
    return payload


def expect_error(path: str, status: int, code: str, method: str = "GET") -> None:
    payload = json_response(path, status, method, cacheable=False)
    require(payload.get("ok") is False, f"{method} {path} error payload must set ok=false")
    error = payload.get("error")
    require(isinstance(error, dict), f"{method} {path} must return an error object")
    require(error.get("code") == code, f"{method} {path} expected {code}, received {error}")
    require(isinstance(error.get("message"), str) and error["message"], f"{method} {path} error message is required")


def timed_json(path: str) -> tuple[dict[str, Any], float]:
    started = time.perf_counter()
    payload = json_response(path, 200, cacheable=True)
    return payload, (time.perf_counter() - started) * 1000


def main() -> None:
    catalog, catalog_first_ms = timed_json("api/coexpression.php?action=catalog")
    require(catalog.get("ok") is True, "Catalog must set ok=true")
    require(catalog.get("version") == "v1_abs0.4_fdr0.05_res1.8", "Catalog version mismatch")
    require(catalog.get("method") == "spearman", "Catalog method mismatch")
    require(catalog.get("default_selection", {}).get("te") == "L1HS", "Catalog default TE mismatch")
    require(catalog.get("default_selection", {}).get("feature_type") == "TE", "Catalog default feature type mismatch")
    require(catalog.get("default_selection", {}).get("context") == "cancer_cell_line", "Catalog default context mismatch")
    require(isinstance(catalog.get("items"), list) and len(catalog["items"]) == 285, "Catalog must contain 285 items")
    require(isinstance(catalog.get("gene_items"), list) and len(catalog["gene_items"]) > 100, "Catalog must contain approved Gene items")
    require(isinstance(catalog.get("contexts"), list) and len(catalog["contexts"]) == 3, "Catalog must contain three contexts")
    require(catalog.get("interpretation_limit") == "Co-expression is correlation, not causation or direct regulatory evidence.", "Catalog interpretation limit mismatch")
    ok("Co-expression catalog contract passed")

    network, network_first_ms = timed_json("api/coexpression.php?action=network&te=L1HS&context=cancer_cell_line")
    require(network.get("ok") is True, "Network must set ok=true")
    require(network.get("selection", {}).get("te") == "L1HS", "Network TE mismatch")
    require(network.get("selection", {}).get("context") == "cancer_cell_line", "Network context mismatch")
    require(len(network.get("nodes") or []) == 26, "L1HS cancer network must contain 26 nodes")
    require(len(network.get("edges") or []) == 100, "L1HS cancer network must contain 100 edges")
    ok("Co-expression L1HS network contract passed")

    gene_network, _ = timed_json("api/coexpression.php?action=network&feature_type=Gene&gene=C1orf116&context=cancer_cell_line")
    gene_selection = gene_network.get("selection", {})
    require(gene_selection.get("feature") == "C1orf116" and gene_selection.get("feature_type") == "Gene", "Gene selection mismatch")
    require(isinstance(gene_selection.get("source_center_te"), str) and gene_selection["source_center_te"], "Gene network provenance TE is required")
    centers = [node for node in gene_network.get("nodes") or [] if node.get("is_center") is True]
    require(len(centers) == 1 and centers[0].get("label") == "C1orf116" and centers[0].get("feature_type") == "gene", "Searched Gene must be the sole graph center")
    ok("Co-expression Gene-centered network contract passed")

    missing = json_response("api/coexpression.php?action=network&te=CR1&context=cancer_cell_line", 404)
    require(missing.get("error", {}).get("code") == "network_unavailable", "CR1 cancer must be unavailable")
    require(missing.get("error", {}).get("available_contexts") == ["normal_cell_line", "normal_tissue"], "CR1 contexts mismatch")
    expect_error("api/coexpression.php?action=nope", 400, "invalid_action")
    expect_error("api/coexpression.php?action=network&te=L1HS&context=not-a-context", 400, "invalid_context")
    expect_error("api/coexpression.php?action=network&te=not-a-te&context=normal_tissue", 404, "unknown_te")
    expect_error("api/coexpression.php?action=network&feature_type=Gene&gene=not-a-gene&context=normal_tissue", 404, "unknown_gene")
    expect_error("api/coexpression.php?action=network&te=..%2FL1HS&context=normal_tissue", 404, "unknown_te")
    expect_error("api/coexpression.php?action=catalog", 405, "method_not_allowed", "POST")
    expect_error("api/coexpression.php?action%5B%5D=catalog", 400, "invalid_action")
    expect_error("api/coexpression.php?action=network&te%5B%5D=L1HS&context=normal_tissue", 404, "unknown_te")
    expect_error("api/coexpression.php?action=network&te=L1HS&context%5B%5D=normal_tissue", 400, "invalid_context")

    status, headers, body = request("api/coexpression.php?action=catalog", "OPTIONS")
    require(status == 204 and body == "", f"OPTIONS must return HTTP 204 without a body, got {status}: {body[:200]}")
    require(headers.get("Access-Control-Allow-Methods") == "GET, OPTIONS", "OPTIONS allow-methods mismatch")
    require("no-store" in headers.get("Cache-Control", "").lower(), "OPTIONS response must not be cacheable")
    ok("Co-expression error and OPTIONS contracts passed")

    catalog_times = [catalog_first_ms]
    network_times = [network_first_ms]
    for _ in range(4):
        _, catalog_ms = timed_json("api/coexpression.php?action=catalog")
        _, network_ms = timed_json("api/coexpression.php?action=network&te=L1HS&context=cancer_cell_line")
        catalog_times.append(catalog_ms)
        network_times.append(network_ms)
    catalog_median = statistics.median(catalog_times)
    network_median = statistics.median(network_times)
    require(catalog_median <= 200, f"Catalog median must be <= 200 ms, got {catalog_median:.1f} ms")
    require(network_median <= 150, f"Network median must be <= 150 ms, got {network_median:.1f} ms")
    ok(f"Co-expression performance passed: catalog median {catalog_median:.1f} ms; network median {network_median:.1f} ms")


if __name__ == "__main__":
    run_check(main)
