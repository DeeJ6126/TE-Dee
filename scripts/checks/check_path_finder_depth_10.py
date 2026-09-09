from __future__ import annotations

import time
import urllib.parse

from harness_lib import app_url, http_json, ok, require, run_check


def timed_payload(params: dict[str, str], timeout: int = 20) -> tuple[dict, float]:
    url = app_url(f"api/path_finder.php?{urllib.parse.urlencode(params)}")
    started = time.perf_counter()
    payload = http_json(url, timeout=timeout)
    return payload, time.perf_counter() - started


def main() -> None:
    paths, path_seconds = timed_payload(
        {
            "source": "AluJb",
            "target": "Cancer",
            "source_type": "TE",
            "target_type": "Disease",
            "max_depth": "10",
        }
    )
    require(paths.get("ok") is True, f"10-hop path request failed: {paths}")
    require(paths.get("max_depth") == 10, f"10-hop path request was not preserved: {paths}")
    require(isinstance(paths.get("paths"), list) and paths["paths"], f"Known connected pair returned no paths: {paths}")
    require(path_seconds < 20, f"10-hop known-pair request was too slow: {path_seconds:.2f}s")

    deeper, deeper_seconds = timed_payload(
        {
            "source": "AluJb",
            "target": "beta-endorphin",
            "source_type": "TE",
            "target_type": "Peptide",
            "max_depth": "10",
        }
    )
    require(deeper.get("ok") is True, f"10-hop deeper-path request failed: {deeper}")
    require(deeper.get("max_depth") == 10, f"deeper request lost max_depth=10: {deeper}")
    deeper_paths = deeper.get("paths")
    require(isinstance(deeper_paths, list) and deeper_paths, f"deeper known pair returned no paths: {deeper}")
    require(max(int(path.get("hop_count", 0)) for path in deeper_paths) >= 4, f"deeper branch was not exercised: {deeper_paths}")
    for path in deeper_paths:
        node_ids = [str(node.get("element_id", "")) for node in path.get("nodes", [])]
        require(len(node_ids) == len(set(node_ids)), f"Path revisits an entity: {node_ids}")
    require(deeper.get("search_truncated") is True, f"bounded deeper search should disclose truncation: {deeper}")
    require(deeper_seconds < 20, f"10-hop deeper request exceeded its total budget: {deeper_seconds:.2f}s")

    candidates, candidate_seconds = timed_payload(
        {
            "view": "connected_candidates",
            "source": "AluJb",
            "source_type": "TE",
            "target_type": "Disease",
            "q": "Cancer",
            "max_depth": "10",
            "limit": "20",
        }
    )
    require(candidates.get("ok") is True, f"10-hop connected-candidate request failed: {candidates}")
    require(candidates.get("max_depth") == 10, f"10-hop candidate request was not preserved: {candidates}")
    require(
        any(str(item.get("name", "")).lower() == "cancer" for item in candidates.get("items", [])),
        f"Known connected target is missing: {candidates}",
    )
    require(candidate_seconds < 20, f"10-hop candidate request was too slow: {candidate_seconds:.2f}s")
    ok(
        "Path Finder depth 10 passed: "
        f"dense={path_seconds:.2f}s deeper={deeper_seconds:.2f}s candidates={candidate_seconds:.2f}s"
    )


if __name__ == "__main__":
    run_check(main)
