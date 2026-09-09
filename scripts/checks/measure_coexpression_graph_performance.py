from __future__ import annotations

import argparse
import json
import math
import time
from pathlib import Path

from harness_lib import ROOT, app_url, fail, ok, require, run_check


EXPECTED_COUNTS = {
    ("L1HS", "cancer_cell_line"): (26, 100),
    ("LTR5", "cancer_cell_line"): (13, 36),
    ("MER11B", "cancer_cell_line"): (15, 100),
    ("HERVH-int", "cancer_cell_line"): (19, 100),
    ("CR1", "normal_tissue"): (13, 77),
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Verify the isolated Co-expression fork of the production Dynamic Graph.")
    parser.add_argument("--te", default="L1HS")
    parser.add_argument("--context", default="cancer_cell_line")
    parser.add_argument("--screenshot", type=Path, default=Path("docs/eval/runs/tmp/coexpression-renderer-L1HS-cancer.png"))
    return parser.parse_args()


def distance(left: list[float], right: list[float]) -> float:
    return math.hypot(right[0] - left[0], right[1] - left[1])


def max_position_delta(before: dict[str, list[float]], after: dict[str, list[float]], ids: list[str]) -> float:
    return max((distance(before[node_id], after[node_id]) for node_id in ids), default=0.0)


def centroid(snapshot: dict[str, list[float]], ids: list[str]) -> list[float]:
    points = [snapshot[node_id] for node_id in ids]
    return [
        sum(point[0] for point in points) / len(points),
        sum(point[1] for point in points) / len(points),
    ]


def pairwise_distance_change(before: dict[str, list[float]], after: dict[str, list[float]], ids: list[str]) -> float:
    changes = []
    for left_index, left_id in enumerate(ids):
        for right_id in ids[left_index + 1:]:
            changes.append(abs(distance(before[left_id], before[right_id]) - distance(after[left_id], after[right_id])))
    return max(changes, default=0.0)


def main() -> None:
    args = parse_args()
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed. Install requirements-dev.txt and Chromium.")

    url = app_url(f"test/coexpression_renderer_harness.html?te={args.te}&context={args.context}")
    screenshot = args.screenshot if args.screenshot.is_absolute() else ROOT / args.screenshot
    screenshot.parent.mkdir(parents=True, exist_ok=True)

    with sync_playwright() as playwright:
        try:
            browser = playwright.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")

        page = browser.new_page(viewport={"width": 1280, "height": 900}, device_scale_factor=1)
        errors: list[str] = []
        page.on("console", lambda message: errors.append(message.text) if message.type == "error" else None)
        page.on("pageerror", lambda error: errors.append(str(error)))
        started = time.perf_counter()

        try:
            page.goto(url, wait_until="domcontentloaded", timeout=30_000)
            page.wait_for_function(
                """() => window.__TEKG_COEXPRESSION_HARNESS_ERROR
                  || (window.__TEKG_COEXPRESSION_HARNESS_READY_AT
                    && window.__TEKG_COEXPRESSION_HARNESS?.renderer?.getGraph?.())""",
                timeout=30_000,
            )
            harness_error = page.evaluate("window.__TEKG_COEXPRESSION_HARNESS_ERROR || null")
            require(not harness_error, f"Harness failed: {harness_error}")
            page.wait_for_timeout(500)

            evidence = page.evaluate(
                """() => {
                  const harness = window.__TEKG_COEXPRESSION_HARNESS;
                  const host = document.querySelector('#g6-default-tree-surface');
                  const canvases = Array.from(host.querySelectorAll('canvas'));
                  const sample = document.createElement('canvas');
                  sample.width = 48;
                  sample.height = 48;
                  const context = sample.getContext('2d');
                  let nontransparentPixels = 0;
                  canvases.forEach((canvas) => {
                    context.clearRect(0, 0, 48, 48);
                    context.drawImage(canvas, 0, 0, 48, 48);
                    const pixels = context.getImageData(0, 0, 48, 48).data;
                    for (let index = 3; index < pixels.length; index += 4) {
                      if (pixels[index] > 0) nontransparentPixels += 1;
                    }
                  });
                  return {
                    nodeCount: harness.data.nodes.length,
                    edgeCount: harness.data.edges.length,
                    canvasCount: canvases.length,
                    nontransparentPixels,
                    rendererMs: window.__TEKG_COEXPRESSION_HARNESS_READY_AT - harness.rendererStartedAt,
                    graphIdentity: String(harness.renderer.getGraph()),
                  };
                }"""
            )
            expected = EXPECTED_COUNTS.get((args.te, args.context))
            if expected:
                require(
                    evidence["nodeCount"] == expected[0],
                    f"Expected {expected[0]} nodes, found {evidence['nodeCount']}",
                )
                require(
                    evidence["edgeCount"] == expected[1],
                    f"Expected {expected[1]} edges, found {evidence['edgeCount']}",
                )
            else:
                require(
                    0 < evidence["nodeCount"] <= 50 and 0 <= evidence["edgeCount"] <= 150,
                    f"Graph size is outside the runtime contract: {evidence}",
                )
            require(evidence["canvasCount"] > 0 and evidence["nontransparentPixels"] > 0, f"Canvas is blank: {evidence}")

            target_id = args.te
            point = page.evaluate(
                """targetId => {
                  const graph = window.__TEKG_COEXPRESSION_HARNESS.renderer.getGraph();
                  const host = document.querySelector('#g6-default-tree-surface');
                  const position = graph?.getElementPosition?.(targetId);
                  if (!graph || !host || !position || typeof graph.getViewportByCanvas !== 'function') return null;
                  const viewport = graph.getViewportByCanvas(position);
                  const rect = host.getBoundingClientRect();
                  return { x: rect.left + Number(viewport[0]), y: rect.top + Number(viewport[1]) };
                }""",
                target_id,
            )
            require(point and math.isfinite(point["x"]) and math.isfinite(point["y"]), f"Could not resolve drag point for {target_id}: {point}")

            snapshot_script = """() => {
              const harness = window.__TEKG_COEXPRESSION_HARNESS;
              const graph = harness.renderer.getGraph();
              return Object.fromEntries(harness.data.nodes.map((node) => {
                const position = graph.getElementPosition(node.id);
                return [node.id, [Number(position[0]), Number(position[1])]];
              }));
            }"""
            before = page.evaluate(snapshot_script)
            graph_handle = page.evaluate_handle("window.__TEKG_COEXPRESSION_HARNESS.renderer.getGraph()")

            page.mouse.move(point["x"], point["y"])
            page.mouse.down()
            drag_snapshots: list[dict[str, list[float]]] = []
            for step in range(1, 11):
                page.mouse.move(point["x"] + 150 * step / 10, point["y"] + 70 * step / 10)
                page.wait_for_timeout(45)
                drag_snapshots.append(page.evaluate(snapshot_script))
            page.mouse.up()

            during = drag_snapshots[-1]
            non_target_ids = [node_id for node_id in before if node_id != target_id]
            target_delta = distance(before[target_id], during[target_id])
            moved_non_targets = sum(distance(before[node_id], during[node_id]) > 0.5 for node_id in non_target_ids)
            centroid_delta = distance(centroid(before, non_target_ids), centroid(during, non_target_ids))
            sample_ids = non_target_ids[:10]
            geometry_change = pairwise_distance_change(before, during, sample_ids)
            distinct_target_frames = len({
                (round(snapshot[target_id][0], 1), round(snapshot[target_id][1], 1))
                for snapshot in drag_snapshots
            })

            require(target_delta > 30, f"Real drag barely moved {target_id}: {target_delta:.2f}px")
            require(distinct_target_frames >= 4, f"Real drag did not expose intermediate target positions: {distinct_target_frames}")
            require(moved_non_targets >= 2, f"Force response did not move enough non-target nodes: {moved_non_targets}")
            require(geometry_change > 0.5, f"Non-target geometry stayed rigid during drag: max pairwise change {geometry_change:.3f}px")
            require(target_delta > centroid_delta * 1.5, f"Drag resembled whole-graph translation: target={target_delta:.2f}, centroid={centroid_delta:.2f}")

            settled = during
            settle_ms = 0
            for _ in range(20):
                page.wait_for_timeout(200)
                settle_ms += 200
                current = page.evaluate(snapshot_script)
                if max_position_delta(settled, current, list(before)) < 0.75:
                    settled = current
                    break
                settled = current
            else:
                fail("Dynamic Graph force did not settle within 4 seconds after real mouse release.")

            page.wait_for_timeout(400)
            stable = page.evaluate(snapshot_script)
            idle_delta = max_position_delta(settled, stable, list(before))
            require(idle_delta < 1.0, f"Coordinates kept moving after the settling gate: {idle_delta:.3f}px")

            require(
                page.evaluate("(graph) => graph === window.__TEKG_COEXPRESSION_HARNESS.renderer.getGraph()", graph_handle),
                "Real drag replaced the G6 instance.",
            )

            final_point = page.evaluate(
                """targetId => {
                  const graph = window.__TEKG_COEXPRESSION_HARNESS.renderer.getGraph();
                  const host = document.querySelector('#g6-default-tree-surface');
                  const position = graph.getElementPosition(targetId);
                  const viewport = graph.getViewportByCanvas(position);
                  const rect = host.getBoundingClientRect();
                  return { x: rect.left + Number(viewport[0]), y: rect.top + Number(viewport[1]) };
                }""",
                target_id,
            )
            page.mouse.click(final_point["x"], final_point["y"])
            page.wait_for_timeout(100)
            require(page.locator(".inspect-card").count() == 1, "Copied Dynamic Graph inspect card did not open on node click.")

            page.screenshot(path=str(screenshot), full_page=True)
            require(not errors, f"Browser errors: {errors[:5]}")

            result = {
                "renderer_first_nonblank_ms": round(evidence["rendererMs"], 2),
                "end_to_end_check_ms": round((time.perf_counter() - started) * 1000, 2),
                "nodes": evidence["nodeCount"],
                "edges": evidence["edgeCount"],
                "target_drag_px": round(target_delta, 2),
                "distinct_target_frames": distinct_target_frames,
                "moved_non_target_nodes": moved_non_targets,
                "non_target_pairwise_change_px": round(geometry_change, 2),
                "non_target_centroid_px": round(centroid_delta, 2),
                "settle_ms": settle_ms,
                "idle_delta_px": round(idle_delta, 3),
                "screenshot": str(screenshot),
            }
            print(json.dumps(result, indent=2))
            ok("Co-expression production Dynamic Graph fork passed real-drag and visual-surface verification")
        finally:
            page.close()
            browser.close()


if __name__ == "__main__":
    run_check(main)
