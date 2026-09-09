from __future__ import annotations

import argparse
import json
import platform
import statistics
import subprocess
from pathlib import Path
from typing import Any, Iterable

from harness_lib import app_url, fail, ok, require, run_check
from check_all_te_large_graph_browser import (
    ROOT,
    attach_error_capture,
    collect_graph_diagnostics,
    fetch_taxonomy_payload,
    inspect_canvas_layers,
    validate_taxonomy_payload,
    wait_for_initial_graph,
)


VIEWPORT = {"width": 1440, "height": 960}
DEVICE_SCALE_FACTOR = 1
TIMEOUT_MS = 60_000
INSTRUMENTATION = r"""
(() => {
  const metrics = window.__TEKG_PHASE1_METRICS = {
    adapter: [],
    render: [],
    rendererCreates: 0,
    rendererDestroys: 0,
    graphLayout: [],
    graphDraw: [],
    longTasks: [],
    limitations: [],
  };
  try {
    const observer = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        metrics.longTasks.push({ start: entry.startTime, duration: entry.duration });
      }
    });
    observer.observe({ entryTypes: ['longtask'] });
  } catch (error) {
    metrics.limitations.push('Long Task PerformanceObserver unavailable: ' + String(error));
  }

  const install = (name, wrap) => {
    let value;
    Object.defineProperty(window, name, {
      configurable: true,
      enumerable: true,
      get() { return value; },
      set(next) { value = wrap(next); },
    });
  };

  install('G6', (library) => {
    const prototype = library?.Graph?.prototype;
    if (!prototype || prototype.__tekgPhase1Wrapped) return library;
    const wrapTimedMethod = (name, bucket) => {
      if (typeof prototype[name] !== 'function') return;
      const original = prototype[name];
      prototype[name] = function (...args) {
        const start = performance.now();
        let result;
        try {
          result = original.apply(this, args);
        } catch (error) {
          metrics[bucket].push({ start, duration: performance.now() - start });
          throw error;
        }
        if (result && typeof result.then === 'function') {
          return result.then(
            (value) => {
              metrics[bucket].push({ start, duration: performance.now() - start });
              return value;
            },
            (error) => {
              metrics[bucket].push({ start, duration: performance.now() - start });
              throw error;
            }
          );
        }
        metrics[bucket].push({ start, duration: performance.now() - start });
        return result;
      };
    };
    wrapTimedMethod('layout', 'graphLayout');
    wrapTimedMethod('draw', 'graphDraw');
    Object.defineProperty(prototype, '__tekgPhase1Wrapped', { value: true });
    return library;
  });

  install('__TEKG_LARGE_FORCE_GRAPH_TAXONOMY_ADAPTER', (adapter) => {
    if (!adapter || adapter.__tekgPhase1Wrapped || typeof adapter.fromTaxonomySource !== 'function') return adapter;
    const original = adapter.fromTaxonomySource;
    adapter.fromTaxonomySource = function (...args) {
      const start = performance.now();
      try { return original.apply(this, args); }
      finally { metrics.adapter.push({ start, duration: performance.now() - start }); }
    };
    Object.defineProperty(adapter, '__tekgPhase1Wrapped', { value: true });
    return adapter;
  });

  install('__TEKG_LARGE_FORCE_GRAPH_CORE', (core) => {
    if (!core || core.__tekgPhase1Wrapped || typeof core.createRenderer !== 'function') return core;
    const originalCreate = core.createRenderer;
    core.createRenderer = function (...args) {
      metrics.rendererCreates += 1;
      const renderer = originalCreate.apply(this, args);
      if (!renderer || renderer.__tekgPhase1Wrapped) return renderer;
      if (typeof renderer.render === 'function') {
        const originalRender = renderer.render;
        renderer.render = async function (...renderArgs) {
          const start = performance.now();
          try { return await originalRender.apply(this, renderArgs); }
          finally { metrics.render.push({ start, duration: performance.now() - start }); }
        };
      }
      if (typeof renderer.destroy === 'function') {
        const originalDestroy = renderer.destroy;
        renderer.destroy = function (...destroyArgs) {
          metrics.rendererDestroys += 1;
          return originalDestroy.apply(this, destroyArgs);
        };
      }
      Object.defineProperty(renderer, '__tekgPhase1Wrapped', { value: true });
      return renderer;
    };
    Object.defineProperty(core, '__tekgPhase1Wrapped', { value: true });
    return core;
  });
})();
"""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Measure the real All-TE taxonomy G6 baseline.")
    parser.add_argument("--source", choices=("all", "rmsk_repbase"), default="all")
    parser.add_argument("--warmups", type=int, default=2)
    parser.add_argument("--runs", type=int, default=5)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--assert-budget", action="store_true")
    args = parser.parse_args()
    require(args.warmups >= 0, "--warmups must be nonnegative")
    require(args.runs > 0, "--runs must be positive")
    return args


def git_output(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=ROOT,
        text=True,
        encoding="utf-8",
        errors="replace",
        capture_output=True,
        check=False,
    )
    return result.stdout.strip()


def median(values: Iterable[float | int | None]) -> float | None:
    present = [float(value) for value in values if value is not None]
    return round(statistics.median(present), 3) if present else None


def wait_two_frames(page: Any) -> float:
    return float(page.evaluate("() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve(performance.now()))))"))


def measure_interactions(page: Any) -> dict[str, float | None]:
    return page.evaluate(
        """async () => {
          const twoFrames = () => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
          const tree = window.__TEKG_G6_DEFAULT_TREE;
          const graph = tree?.getGraph?.();
          const result = {
            hover_leave_ms: null,
            legend_focus_ms: null,
            legend_visibility_apply_ms: null,
            observed_draw_calls: 0,
          };
          if (!tree || !graph) return result;

          let nodes = [];
          try { nodes = graph.getNodeData?.() || []; } catch (_error) {}
          const target = nodes.find(node => !node?.data?.isRoot) || nodes[0];
          const enterEvent = window.G6?.NodeEvent?.POINTER_ENTER;
          const leaveEvent = window.G6?.NodeEvent?.POINTER_LEAVE;
          if (target?.id && typeof graph.emit === 'function' && enterEvent && leaveEvent && typeof graph.draw === 'function') {
            const originalDraw = graph.draw;
            let lastDraw = null;
            graph.draw = function (...args) {
              result.observed_draw_calls += 1;
              lastDraw = Promise.resolve(originalDraw.apply(this, args));
              return lastDraw;
            };
            try {
              const start = performance.now();
              graph.emit(enterEvent, { target: { id: target.id }, targetType: 'node' });
              await Promise.resolve();
              if (lastDraw) await lastDraw;
              lastDraw = null;
              graph.emit(leaveEvent, { target: { id: target.id }, targetType: 'node' });
              await Promise.resolve();
              if (lastDraw) await lastDraw;
              await twoFrames();
              result.hover_leave_ms = performance.now() - start;
              const items = tree.getLevelLegendItems?.() || [];
              const focusItem = items.find(item => item.focusable !== false && item.visible !== false && item.depth > 0);
              if (focusItem && typeof tree.setLevelFocus === 'function') {
                const focusStart = performance.now();
                await tree.setLevelFocus(focusItem.key);
                await twoFrames();
                result.legend_focus_ms = performance.now() - focusStart;
                await tree.setLevelFocus(null);
                await twoFrames();
              }
            } finally {
              graph.draw = originalDraw;
            }
          }

          const items = tree.getLevelLegendItems?.() || [];
          const hideItem = [...items]
            .filter(item => item.visible !== false && item.depth > 0)
            .sort((left, right) => Number(right.depth || 0) - Number(left.depth || 0))[0];
          if (hideItem && typeof tree.applyLevelState === 'function') {
            const originalState = Object.fromEntries(items.map(item => [item.key, item.visible !== false]));
            const hiddenState = { ...originalState, [hideItem.key]: false };
            const start = performance.now();
            await tree.applyLevelState(hiddenState);
            await twoFrames();
            result.legend_visibility_apply_ms = performance.now() - start;
            await tree.applyLevelState(originalState);
            await twoFrames();
          }
          return result;
        }"""
    )


def collect_run(context: Any, source: str, include_probes: bool, run_label: str) -> dict[str, Any]:
    page = context.new_page()
    errors = attach_error_capture(page)
    page.add_init_script(INSTRUMENTATION)
    try:
        page.goto(
            app_url(f"preview.php?tree={source}"),
            wait_until="domcontentloaded",
            timeout=TIMEOUT_MS,
        )
        wait_for_initial_graph(page, source)
        stable_now = wait_two_frames(page)
        initial_instrumentation = page.evaluate("() => window.__TEKG_PHASE1_METRICS || {}")
        adapter_entries = initial_instrumentation.get("adapter") or []
        render_entries = initial_instrumentation.get("render") or []
        adapter_entry = adapter_entries[0] if adapter_entries else None
        render_entry = render_entries[0] if render_entries else None
        layout_entries = [
            entry for entry in initial_instrumentation.get("graphLayout", [])
            if float(entry.get("start", 0)) <= stable_now
        ]
        draw_entries = [
            entry for entry in initial_instrumentation.get("graphDraw", [])
            if float(entry.get("start", 0)) <= stable_now
        ]
        action_start = min(
            [float(entry.get("start", stable_now)) for entry in (adapter_entry, render_entry) if isinstance(entry, dict)]
            or [0.0]
        )
        initial_long_tasks = [
            entry for entry in initial_instrumentation.get("longTasks", [])
            if float(entry.get("start", 0)) <= stable_now
        ]
        diagnostics = collect_graph_diagnostics(page)
        canvas = inspect_canvas_layers(page)
        probes = measure_interactions(page) if include_probes else {
            "hover_leave_ms": None,
            "legend_focus_ms": None,
            "legend_visibility_apply_ms": None,
        }
        final_instrumentation = page.evaluate("() => window.__TEKG_PHASE1_METRICS || {}")
        prototype_draw_delta = len(final_instrumentation.get("graphDraw") or []) \
            - len(initial_instrumentation.get("graphDraw") or [])
        interaction_counters = {
            "renderer_creates": int(final_instrumentation.get("rendererCreates") or 0)
            - int(initial_instrumentation.get("rendererCreates") or 0),
            "renderer_destroys": int(final_instrumentation.get("rendererDestroys") or 0)
            - int(initial_instrumentation.get("rendererDestroys") or 0),
            "layout_calls": len(final_instrumentation.get("graphLayout") or [])
            - len(initial_instrumentation.get("graphLayout") or []),
            "draw_calls": prototype_draw_delta
            if prototype_draw_delta > 0
            else int(probes.get("observed_draw_calls") or 0),
            "draw_call_source": "prototype" if prototype_draw_delta > 0 else "direct-probe-fallback",
        }
        reference_errors = [entry for entry in errors.console_errors if "ReferenceError" in entry]
        taxonomy_failures = [entry for entry in errors.failed_requests if "/api/taxonomy.php" in entry]
        require(not errors.page_errors, f"{run_label}: page errors: {errors.page_errors[:5]}")
        require(not reference_errors, f"{run_label}: ReferenceError: {reference_errors[:5]}")
        require(not taxonomy_failures, f"{run_label}: taxonomy request failures: {taxonomy_failures[:5]}")
        require(diagnostics.get("nodeCount", 0) > 0, f"{run_label}: graph has no nodes")
        require(diagnostics.get("invalidEdgeCount") == 0, f"{run_label}: graph has invalid edge endpoints")
        require(canvas.get("contentLayerCount", 0) > 0, f"{run_label}: Canvas pixel sample is blank")
        return {
            "label": run_label,
            "adapter_ms": round(float(adapter_entry["duration"]), 3) if adapter_entry else None,
            "render_ms": round(float(render_entry["duration"]), 3) if render_entry else None,
            "layout_ms": round(sum(float(entry.get("duration", 0)) for entry in layout_entries), 3) if layout_entries else None,
            "draw_ms": round(sum(float(entry.get("duration", 0)) for entry in draw_entries), 3) if draw_entries else None,
            "stable_ms": round(stable_now - action_start, 3),
            "page_to_interactive_ms": round(stable_now, 3),
            **{key: round(float(value), 3) if value is not None else None for key, value in probes.items()},
            "long_tasks": initial_long_tasks,
            "longest_task_ms": round(max((float(entry.get("duration", 0)) for entry in initial_long_tasks), default=0.0), 3),
            "renderer_creates": initial_instrumentation.get("rendererCreates"),
            "renderer_destroys": initial_instrumentation.get("rendererDestroys"),
            "interaction_counters": interaction_counters,
            "graph": diagnostics,
            "canvas": canvas,
            "errors": errors.as_dict(),
            "instrumentation_limitations": final_instrumentation.get("limitations", []),
        }
    finally:
        page.close()


def summarize(runs: list[dict[str, Any]]) -> dict[str, Any]:
    return {
        "adapter_median_ms": median(run.get("adapter_ms") for run in runs),
        "render_median_ms": median(run.get("render_ms") for run in runs),
        "layout_median_ms": median(run.get("layout_ms") for run in runs),
        "draw_median_ms": median(run.get("draw_ms") for run in runs),
        "stable_median_ms": median(run.get("stable_ms") for run in runs),
        "page_to_interactive_median_ms": median(run.get("page_to_interactive_ms") for run in runs),
        "hover_leave_median_ms": median(run.get("hover_leave_ms") for run in runs),
        "legend_focus_median_ms": median(run.get("legend_focus_ms") for run in runs),
        "legend_visibility_apply_median_ms": median(run.get("legend_visibility_apply_ms") for run in runs),
        "longest_task_max_ms": round(max((float(run.get("longest_task_ms") or 0) for run in runs), default=0.0), 3),
    }


def assert_budgets(summary: dict[str, Any], output_path: Path) -> None:
    budgets = {
        "adapter_median_ms": 50,
        "render_median_ms": 800,
        "stable_median_ms": 1000,
        "page_to_interactive_median_ms": 1500,
        "hover_leave_median_ms": 50,
        "legend_focus_median_ms": 50,
        "legend_visibility_apply_median_ms": 1000,
    }
    failures: list[str] = []
    for key, maximum in budgets.items():
        value = summary.get(key)
        if value is None:
            failures.append(f"{key} is unavailable")
        elif float(value) > maximum:
            failures.append(f"{key}={value} exceeds {maximum}")

    baseline_path = output_path.parent / "baseline.json"
    if baseline_path.resolve() == output_path.resolve() or not baseline_path.is_file():
        failures.append(f"same-directory baseline is required for relative improvement: {baseline_path}")
    else:
        baseline = json.loads(baseline_path.read_text(encoding="utf-8"))
        baseline_render = (baseline.get("summary") or {}).get("render_median_ms")
        current_render = summary.get("render_median_ms")
        if baseline_render is None or current_render in (None, 0):
            failures.append("render median is unavailable for relative improvement")
        elif float(baseline_render) / float(current_render) < 2:
            failures.append(
                f"render improvement is {float(baseline_render) / float(current_render):.2f}x, below 2x"
            )
    require(not failures, "Performance budget failures:\n- " + "\n- ".join(failures))


def main() -> None:
    args = parse_args()
    payload, decoded_bytes, request_ms = fetch_taxonomy_payload(args.source)
    api = validate_taxonomy_payload(payload, decoded_bytes, request_ms)
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed. Install requirements-dev.txt and Chromium.")

    with sync_playwright() as playwright:
        try:
            browser = playwright.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")
        context = browser.new_context(viewport=VIEWPORT, device_scale_factor=DEVICE_SCALE_FACTOR)
        browser_version = browser.version
        try:
            collect_run(context, args.source, False, "cold-unmeasured")
            for index in range(args.warmups):
                collect_run(context, args.source, False, f"warmup-{index + 1}")
            measured = [
                collect_run(context, args.source, True, f"run-{index + 1}")
                for index in range(args.runs)
            ]
        finally:
            context.close()
            browser.close()

    limitations = [
        "Timing values are local reference evidence, not cross-machine correctness assertions.",
        "The unmeasured cold page is excluded; measured pages share one Chromium cache and use fresh pages.",
        "Stable means loader hidden, taxonomy state/data present, and two animation frames; force motion may remain visible.",
        "Repeated pages measure warm PHP/browser resources while each page creates a fresh taxonomy renderer.",
        "Exact layout-start and live-instance counters are unavailable until later runtime diagnostics are implemented.",
    ]
    for run in measured:
        for entry in run.get("instrumentation_limitations", []):
            if entry not in limitations:
                limitations.append(entry)
    if all(run.get("layout_ms") is None for run in measured):
        limitations.append("The bundled G6 runtime did not call the public graph.layout() method during render; layout duration is null and remains included in aggregate renderer.render().")
    if all(run.get("draw_ms") is None for run in measured):
        limitations.append("The bundled G6 runtime did not call the public graph.draw() method during initial render; draw duration is null and remains included in aggregate renderer.render().")
    result = {
        "source": args.source,
        "api": api,
        "environment": {
            "viewport": VIEWPORT,
            "device_scale_factor": DEVICE_SCALE_FACTOR,
            "browser_version": browser_version,
            "platform": platform.platform(),
            "python_version": platform.python_version(),
            "git_commit": git_output("rev-parse", "HEAD"),
            "dirty_files": git_output("status", "--short", "--untracked-files=all").splitlines(),
        },
        "warmups": args.warmups,
        "measured_run_count": args.runs,
        "runs": measured,
        "summary": summarize(measured),
        "limitations": limitations,
    }
    output_path = args.output if args.output.is_absolute() else ROOT / args.output
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(result, indent=2, ensure_ascii=True) + "\n", encoding="utf-8")
    if args.assert_budget:
        assert_budgets(result["summary"], output_path)
    print(json.dumps(result["summary"], indent=2))
    ok(f"All-TE performance evidence written to {output_path}")

if __name__ == "__main__":
    run_check(main)
