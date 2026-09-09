from __future__ import annotations

import math

from harness_lib import ROOT, app_url, fail, ok, require, run_check


def is_te_selection(selection: object, name: str, context: str) -> bool:
    return (
        isinstance(selection, dict)
        and selection.get("feature") == name
        and selection.get("featureType") == "TE"
        and selection.get("te") == name
        and selection.get("context") == context
    )


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail("Playwright is not installed.")

    with sync_playwright() as playwright:
        try:
            browser = playwright.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium: {exc}")

        page = browser.new_page(viewport={"width": 1280, "height": 900})
        errors: list[str] = []
        failed_requests: list[str] = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.on("console", lambda message: errors.append(message.text) if message.type == "error" else None)
        page.on(
            "requestfailed",
            lambda request: failed_requests.append(
                f"{request.url} :: {request.failure}"
            ),
        )

        try:
            page.goto(app_url("preview.php?q=LINE1"), wait_until="domcontentloaded", timeout=30_000)
            page.wait_for_function(
                """() => {
                  const state = window.__TEKG_G6_BRIDGE?.getState?.();
                  return window.__TEKG_PREVIEW_WORKSPACE_MODE
                    && state?.mode === 'dynamic'
                    && state?.query === 'LINE1'
                    && state.currentElements?.length > 0;
                }""",
                timeout=60_000,
            )

            initial = page.evaluate(
                """() => {
                  const state = window.__TEKG_G6_BRIDGE.getState();
                  const mode = window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics();
                  return {
                    query: state.query,
                    elementCount: state.currentElements.length,
                    historyDepth: state.historyDepth,
                    visibleRelations: state.visibleRelations,
                    canGoBack: window.__TEKG_G6_BRIDGE.canGoBack(),
                    searchValue: document.querySelector('#node-search').value,
                    knowledgeFrames: document.querySelectorAll('#g6-dynamic-surface #g6-dynamic-frame').length,
                    coexpressionFrames: document.querySelectorAll('#coexpression-iframe-host iframe').length,
                    selectedMode: mode.mode,
                    knowledgePressed: document.querySelector('#preview-mode-knowledge').getAttribute('aria-pressed'),
                    coexpressionPressed: document.querySelector('#preview-mode-coexpression').getAttribute('aria-pressed'),
                  };
                }"""
            )
            require(initial["selectedMode"] == "knowledge", f"Knowledge Graph must be the default: {initial}")
            require(
                initial["knowledgePressed"] == "true" and initial["coexpressionPressed"] == "false",
                f"Mode selector semantics are incorrect: {initial}",
            )
            require(initial["knowledgeFrames"] == 1, f"Expected one Knowledge Graph iframe: {initial}")
            require(initial["coexpressionFrames"] == 0, f"Co-expression iframe must remain lazy: {initial}")

            page.click("#preview-mode-coexpression")
            page.wait_for_timeout(1000)
            page.wait_for_function(
                """() => {
                  const mode = window.__TEKG_PREVIEW_WORKSPACE_MODE?.getDiagnostics?.();
                  const coexpression = window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.();
                  return mode?.mode === 'coexpression'
                    && coexpression?.state === 'unavailable'
                    && coexpression?.selection?.te === 'LINE1';
                }""",
                timeout=60_000,
            )
            awaiting_selection = page.evaluate(
                """() => ({
                  input: document.querySelector('#coexpression-te-search').value,
                  message: document.querySelector('#coexpression-state').textContent,
                  iframeCount: document.querySelectorAll('#coexpression-iframe-host iframe').length,
                })"""
            )
            require(
                awaiting_selection["input"] == "LINE1"
                and "No co-expression data is available for LINE1." in awaiting_selection["message"]
                and awaiting_selection["iframeCount"] == 0,
                f"Nonmatching Knowledge TE was not preserved as unavailable: {awaiting_selection}",
            )
            page.click("#preview-mode-knowledge")
            page.wait_for_function(
                "() => window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics().mode === 'knowledge'"
            )
            page.click("#preview-mode-coexpression")
            page.wait_for_function(
                """() => {
                  const state = window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state;
                  return ['awaiting-selection', 'unavailable', 'ready', 'empty', 'error'].includes(state);
                }""",
                timeout=15_000,
            )
            awaiting_roundtrip = page.evaluate(
                """() => {
                  const graph = window.__TEKG_COEXPRESSION_MODE.getDiagnostics();
                  return {
                    state: graph.state,
                    selection: graph.selection,
                    iframeCount: graph.iframeCount,
                    input: document.querySelector('#coexpression-te-search').value,
                    buttonText: document.querySelector('#coexpression-load').textContent.trim(),
                  };
                }"""
            )
            selection = awaiting_roundtrip.get("selection") or {}
            require(
                awaiting_roundtrip.get("state") == "unavailable"
                and selection.get("feature") == "LINE1"
                and selection.get("featureType") == "TE"
                and selection.get("te") == "LINE1"
                and selection.get("context") == ""
                and awaiting_roundtrip.get("iframeCount") == 0
                and awaiting_roundtrip.get("input") == "LINE1"
                and awaiting_roundtrip.get("buttonText") == "Search",
                f"Explicit unavailable selection was not retained across a mode roundtrip: {awaiting_roundtrip}",
            )
            page.goto(
                app_url("preview.php?q=LTR5&type=TE"),
                wait_until="domcontentloaded",
                timeout=30_000,
            )
            page.wait_for_function(
                """() => {
                  const state = window.__TEKG_G6_BRIDGE?.getState?.();
                  return window.__TEKG_PREVIEW_WORKSPACE_MODE
                    && state?.mode === 'dynamic'
                    && state?.query === 'LTR5'
                    && state.currentElements?.length > 0;
                }""",
                timeout=30_000,
            )
            page.click("#preview-mode-coexpression")
            page.wait_for_function(
                """() => {
                  const state = window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.();
                  return state?.state === 'ready'
                    && state.selection?.te === 'LTR5';
                }""",
                timeout=30_000,
            )
            page.fill("#coexpression-te-search", "L1H")
            page.wait_for_selector(
                '#previewCoexpressionWorkspace [data-te-name="L1HS"]',
                state="visible",
                timeout=10_000,
            )
            page.click('#previewCoexpressionWorkspace [data-te-name="L1HS"]')
            page.click("#coexpression-load")
            page.wait_for_function(
                """() => {
                  const graph = window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.();
                  return graph?.state === 'ready'
                    && graph?.selection?.te === 'L1HS'
                    && graph?.selection?.context === 'cancer_cell_line';
                }""",
                timeout=30_000,
            )
            first_coexpression = page.evaluate(
                """() => ({
                  frameIdentity: window.__TEKG_COEXPRESSION_MODE.getDiagnostics().frameIdentity,
                  iframeCount: document.querySelectorAll('#coexpression-iframe-host iframe').length,
                })"""
            )
            require(first_coexpression["iframeCount"] == 1, f"Expected one Co-expression iframe: {first_coexpression}")
            semantic_boundary = page.evaluate(
                """() => ({
                  coexpressionText: document.querySelector('#previewCoexpressionWorkspace').textContent,
                  knowledgeText: document.querySelector('#previewGraphWorkspace').textContent,
                })"""
            )
            require(
                "PMID" not in semantic_boundary["coexpressionText"]
                and "Disease" not in semantic_boundary["coexpressionText"]
                and "Co-expression" not in semantic_boundary["knowledgeText"],
                f"Mode-specific semantics crossed workspace boundaries: {semantic_boundary}",
            )
            screenshot = ROOT / "docs/eval/runs/tmp/coexpression-task7-switch.png"
            screenshot.parent.mkdir(parents=True, exist_ok=True)
            page.screenshot(path=str(screenshot), full_page=True)

            page.evaluate(
                """() => window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode('coexpression', {
                  te: 'LTR5',
                  context: 'cancer_cell_line',
                })"""
            )
            page.wait_for_function(
                """() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'
                  && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection?.te === 'LTR5'""",
                timeout=30_000,
            )
            page.click("#preview-mode-coexpression")
            page.wait_for_timeout(150)
            repeated_selected_tab = page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection"
            )
            require(
                is_te_selection(repeated_selected_tab, "LTR5", "cancer_cell_line"),
                f"Clicking the selected mode reset its retained selection: {repeated_selected_tab}",
            )

            page.evaluate(
                """() => {
                  const bridge = document.querySelector('#coexpression-graph-frame').contentWindow
                    .__TEKG_COEXPRESSION_EMBED;
                  window.__TEKG_TASK7_VISIBLE_ORIGINAL_RENDER = bridge.renderNetwork;
                  bridge.renderNetwork = async (network) => {
                    if (network?.selection?.te === 'L1HS') {
                      await new Promise((resolve) => setTimeout(resolve, 450));
                    }
                    return window.__TEKG_TASK7_VISIBLE_ORIGINAL_RENDER.call(bridge, network);
                  };
                  window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode('coexpression', {
                    te: 'L1HS',
                    context: 'cancer_cell_line',
                  });
                  setTimeout(() => {
                    window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode('coexpression', {
                      te: 'LTR5',
                      context: 'cancer_cell_line',
                    });
                  }, 20);
                }"""
            )
            page.wait_for_function(
                """() => {
                  const host = window.__TEKG_COEXPRESSION_MODE.getDiagnostics();
                  const frame = document.querySelector('#coexpression-graph-frame').contentWindow
                    .__TEKG_COEXPRESSION_EMBED.getDiagnostics();
                  return host.state === 'ready'
                    && host.selection?.te === 'LTR5'
                    && frame.selection?.feature === 'LTR5';
                }""",
                timeout=15_000,
            )
            page.wait_for_timeout(500)
            visible_supersession = page.evaluate(
                """() => {
                  const frameBridge = document.querySelector('#coexpression-graph-frame').contentWindow
                    .__TEKG_COEXPRESSION_EMBED;
                  return {
                    host: window.__TEKG_COEXPRESSION_MODE.getDiagnostics(),
                    frame: frameBridge.getDiagnostics(),
                  };
                }"""
            )
            require(
                visible_supersession["host"]["state"] == "ready"
                and is_te_selection(visible_supersession["host"]["selection"], "LTR5", "cancer_cell_line")
                and visible_supersession["frame"]["selection"]["feature"] == "LTR5"
                and visible_supersession["frame"]["selection"]["context"] == "cancer_cell_line",
                f"A stale visible render replaced the newer selection: {visible_supersession}",
            )
            page.evaluate(
                """() => {
                  const bridge = document.querySelector('#coexpression-graph-frame').contentWindow
                    .__TEKG_COEXPRESSION_EMBED;
                  bridge.renderNetwork = window.__TEKG_TASK7_VISIBLE_ORIGINAL_RENDER;
                  delete window.__TEKG_TASK7_VISIBLE_ORIGINAL_RENDER;
                }"""
            )

            page.evaluate(
                """() => {
                  const bridge = document.querySelector('#coexpression-graph-frame').contentWindow
                    .__TEKG_COEXPRESSION_EMBED;
                  window.__TEKG_TASK7_ORIGINAL_RENDER = bridge.renderNetwork;
                  bridge.renderNetwork = async (network) => {
                    await new Promise((resolve) => setTimeout(resolve, 450));
                    return window.__TEKG_TASK7_ORIGINAL_RENDER.call(bridge, network);
                  };
                  window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode('coexpression', {
                    te: 'L1HS',
                    context: 'cancer_cell_line',
                  });
                  setTimeout(() => {
                    window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode('knowledge');
                  }, 20);
                }"""
            )
            page.wait_for_function(
                """() => window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics().mode === 'knowledge'
                  && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().layoutStopped === true""",
                timeout=10_000,
            )
            page.wait_for_timeout(4500)
            stale_render = page.evaluate(
                """() => ({
                  mode: window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics().mode,
                  graph: window.__TEKG_COEXPRESSION_MODE.getDiagnostics(),
                })"""
            )
            require(
                stale_render["mode"] == "knowledge"
                and stale_render["graph"]["layoutStopped"] is True
                and stale_render["graph"]["state"] == "ready"
                and is_te_selection(stale_render["graph"]["selection"], "LTR5", "cancer_cell_line"),
                f"A stale render survived deactivation or replaced stable state: {stale_render}",
            )
            page.evaluate(
                """() => {
                  const bridge = document.querySelector('#coexpression-graph-frame').contentWindow
                    .__TEKG_COEXPRESSION_EMBED;
                  bridge.renderNetwork = window.__TEKG_TASK7_ORIGINAL_RENDER;
                  delete window.__TEKG_TASK7_ORIGINAL_RENDER;
                }"""
            )
            page.evaluate(
                """() => window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode('coexpression', {
                  te: 'LTR5',
                  context: 'cancer_cell_line',
                })"""
            )
            page.wait_for_function(
                """() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'
                  && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().selection?.te === 'LTR5'""",
                timeout=30_000,
            )
            retained_render_count = page.evaluate(
                "() => window.__TEKG_COEXPRESSION_MODE.getDiagnostics().renderCount"
            )
            page.click("#preview-mode-knowledge")
            page.wait_for_function(
                """() => {
                  const state = window.__TEKG_G6_BRIDGE.getState();
                  return window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics().mode === 'knowledge'
                    && state.query === 'LTR5'
                    && state.mode === 'dynamic'
                    && !document.querySelector('#graph-preloader').classList.contains('is-visible')
                    && window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics().pendingKnowledgeLoads === 0;
                }""",
                timeout=60_000,
            )

            for _ in range(5):
                page.click("#preview-mode-coexpression")
                page.wait_for_function(
                    """() => window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics().mode === 'coexpression'
                      && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'""",
                    timeout=15_000,
                )
                page.click("#preview-mode-knowledge")
                page.wait_for_function(
                    "() => window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics().mode === 'knowledge'"
                )
            page.click("#preview-mode-coexpression")
            page.wait_for_function(
                """() => window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics().mode === 'coexpression'
                  && window.__TEKG_COEXPRESSION_MODE.getDiagnostics().state === 'ready'""",
                timeout=15_000,
            )

            retained_after_roundtrips = page.evaluate(
                """() => {
                  const graph = window.__TEKG_COEXPRESSION_MODE.getDiagnostics();
                  return {selection: graph.selection, renderCount: graph.renderCount};
                }"""
            )
            require(
                is_te_selection(retained_after_roundtrips["selection"], "LTR5", "cancer_cell_line")
                and retained_after_roundtrips["renderCount"] == retained_render_count,
                f"Co-expression selection or renderer state was rebuilt across roundtrips: {retained_after_roundtrips}",
            )

            drag_point = page.evaluate(
                """() => {
                  const frame = document.querySelector('#coexpression-graph-frame');
                  const win = frame.contentWindow;
                  const snapshot = win.__TEKG_COEXPRESSION_EMBED.getInteractionSnapshot('LTR5');
                  const host = win.document.querySelector('#container');
                  if (!host || !snapshot?.viewport) return null;
                  const frameRect = frame.getBoundingClientRect();
                  const hostRect = host.getBoundingClientRect();
                  return {
                    x: frameRect.left + hostRect.left + Number(snapshot.viewport[0]),
                    y: frameRect.top + hostRect.top + Number(snapshot.viewport[1]),
                  };
                }"""
            )
            require(drag_point is not None, "Could not resolve LTR5 after the mode roundtrip.")
            snapshot_script = """() => {
              const win = document.querySelector('#coexpression-graph-frame').contentWindow;
              const bridge = win.__TEKG_COEXPRESSION_EMBED;
              return bridge.getInteractionSnapshot('LTR5').positions;
            }"""
            before_resume_drag = page.evaluate(snapshot_script)
            page.mouse.move(drag_point["x"], drag_point["y"])
            page.mouse.down()
            for step in range(1, 7):
                page.mouse.move(
                    drag_point["x"] + 120 * step / 6,
                    drag_point["y"] + 55 * step / 6,
                )
                page.wait_for_timeout(45)
            during_resume_drag = page.evaluate(snapshot_script)
            page.mouse.up()

            def moved(left: list[float], right: list[float]) -> float:
                return math.hypot(right[0] - left[0], right[1] - left[1])

            resumed_target_delta = moved(
                before_resume_drag["LTR5"],
                during_resume_drag["LTR5"],
            )
            resumed_partner_moves = sum(
                moved(before_resume_drag[node_id], during_resume_drag[node_id]) > 0.5
                for node_id in before_resume_drag
                if node_id != "LTR5"
            )
            require(
                resumed_target_delta > 30 and resumed_partner_moves >= 2,
                "Force drag did not reheat after Knowledge -> Co-expression resume: "
                f"target={resumed_target_delta:.2f}, partners={resumed_partner_moves}",
            )

            page.click("#preview-mode-knowledge")
            page.wait_for_function(
                """() => {
                  const state = window.__TEKG_G6_BRIDGE.getState();
                  return window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics().mode === 'knowledge'
                    && state.mode === 'dynamic'
                    && state.query === 'LTR5'
                    && !document.querySelector('#graph-preloader').classList.contains('is-visible')
                    && window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics().pendingKnowledgeLoads === 0;
                }""",
                timeout=60_000,
            )
            restored = page.evaluate(
                """() => {
                  const state = window.__TEKG_G6_BRIDGE.getState();
                  const coexpression = window.__TEKG_COEXPRESSION_MODE.getDiagnostics();
                  const mode = window.__TEKG_PREVIEW_WORKSPACE_MODE.getDiagnostics();
                  return {
                    query: state.query,
                    graphMode: state.mode,
                    elementCount: state.currentElements.length,
                    historyDepth: state.historyDepth,
                    visibleRelations: state.visibleRelations,
                    canGoBack: window.__TEKG_G6_BRIDGE.canGoBack(),
                    searchValue: document.querySelector('#node-search').value,
                    knowledgeFrames: document.querySelectorAll('#g6-dynamic-surface #g6-dynamic-frame').length,
                    coexpressionFrames: document.querySelectorAll('#coexpression-iframe-host iframe').length,
                    coexpressionFrameIdentity: coexpression.frameIdentity,
                    coexpressionLayoutStopped: coexpression.layoutStopped,
                    mode: mode.mode,
                    knowledgeVisible: getComputedStyle(document.querySelector('#previewGraphWorkspace')).display !== 'none',
                    coexpressionVisible: getComputedStyle(document.querySelector('#previewCoexpressionWorkspace')).display !== 'none',
                  };
                }"""
            )
            require(
                restored["query"] == "LTR5" and restored["graphMode"] == "dynamic",
                f"Knowledge did not receive the active Co-expression TE: {restored}",
            )
            require(
                restored["searchValue"] == "LTR5",
                f"Knowledge query controls did not follow the exact TE handoff: {restored}",
            )
            require(
                restored["knowledgeFrames"] == 1 and restored["coexpressionFrames"] == 1,
                f"A graph iframe was recreated or duplicated: {restored}",
            )
            require(
                restored["coexpressionFrameIdentity"] == first_coexpression["frameIdentity"],
                f"The Co-expression renderer was recreated: {restored}",
            )
            require(restored["coexpressionLayoutStopped"] is True, f"Hidden layout is still running: {restored}")
            require(
                restored["knowledgeVisible"] and not restored["coexpressionVisible"],
                f"Exactly one workspace must be visible: {restored}",
            )

            page.goto(app_url("preview.php"), wait_until="domcontentloaded", timeout=30_000)
            page.wait_for_function(
                """() => window.__TEKG_PREVIEW_WORKSPACE_MODE
                  && window.__TEKG_G6_BRIDGE?.getState?.().mode === 'tree'""",
                timeout=30_000,
            )
            tree_controls = page.evaluate(
                """() => ({
                  taxonomyVisible: !document.querySelector('#previewTaxonomyMode').hidden,
                  workspaceVisible: !document.querySelector('#previewWorkspaceMode').hidden,
                })"""
            )
            require(
                tree_controls == {"taxonomyVisible": True, "workspaceVisible": False},
                f"Taxonomy tree displayed the wrong upper-right selector: {tree_controls}",
            )
            page.click("#preview-taxonomy-all")
            page.wait_for_function(
                "() => window.__TEKG_G6_BRIDGE.getState().treeVariant === 'all'",
                timeout=30_000,
            )
            page.click("#preview-taxonomy-rmsk-repbase")
            page.wait_for_function(
                "() => window.__TEKG_G6_BRIDGE.getState().treeVariant === 'rmsk_repbase'",
                timeout=30_000,
            )
            tree_restored = page.evaluate(
                """() => ({
                  graphMode: window.__TEKG_G6_BRIDGE.getState().mode,
                  knowledgeVisible: !document.querySelector('#previewGraphWorkspace').hidden,
                  coexpressionVisible: !document.querySelector('#previewCoexpressionWorkspace').hidden,
                })"""
            )
            require(
                tree_restored == {
                    "graphMode": "tree",
                    "knowledgeVisible": True,
                    "coexpressionVisible": False,
                },
                f"Taxonomy tree was not preserved across a mode roundtrip: {tree_restored}",
            )

            page.goto(
                app_url("preview.php?mode=coexpression&te=L1HS&context=normal_tissue"),
                wait_until="domcontentloaded",
                timeout=30_000,
            )
            page.wait_for_function(
                """() => {
                  const mode = window.__TEKG_PREVIEW_WORKSPACE_MODE?.getDiagnostics?.();
                  const graph = window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.();
                  return mode?.mode === 'coexpression'
                    && graph?.state === 'ready'
                    && graph?.selection?.te === 'L1HS'
                    && graph?.selection?.context === 'normal_tissue';
                }""",
                timeout=30_000,
            )
            direct = page.evaluate(
                """() => ({
                  knowledgeVisible: !document.querySelector('#previewGraphWorkspace').hidden,
                  coexpressionVisible: !document.querySelector('#previewCoexpressionWorkspace').hidden,
                  selected: document.querySelector('#preview-mode-coexpression').getAttribute('aria-selected'),
                })"""
            )
            require(
                direct == {
                    "knowledgeVisible": False,
                    "coexpressionVisible": True,
                    "selected": "true",
                },
                f"Direct Co-expression activation did not select exactly one workspace: {direct}",
            )
            page.goto(
                app_url("preview.php?mode=coexpression&gene=C1orf116&context=cancer_cell_line"),
                wait_until="domcontentloaded",
                timeout=30_000,
            )
            page.wait_for_function(
                """() => {
                  const graph = window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.();
                  return graph?.state === 'ready'
                    && graph?.selection?.feature === 'C1orf116'
                    && graph?.selection?.featureType === 'Gene';
                }""",
                timeout=30_000,
            )
            page.select_option("#coexpression-search-type", "TE")
            page.select_option("#coexpression-search-type", "Gene")
            page.fill("#coexpression-te-search", "C1orf")
            page.focus("#coexpression-te-search")
            page.wait_for_selector(
                '#previewCoexpressionWorkspace [data-te-name="C1orf116"]',
                timeout=15_000,
            )
            page.click('#previewCoexpressionWorkspace [data-te-name="C1orf116"]')
            page.click("#coexpression-load")
            page.wait_for_function(
                """() => {
                  const graph = window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.();
                  return graph?.state === 'ready'
                    && graph?.selection?.feature === 'C1orf116'
                    && graph?.selection?.featureType === 'Gene';
                }""",
                timeout=30_000,
            )
            gene_state = page.evaluate(
                """() => {
                  const diagnostics = window.__TEKG_COEXPRESSION_MODE.getDiagnostics();
                  const frame = document.querySelector('#coexpression-graph-frame');
                  const nodes = frame?.contentWindow?.__TEKG_COEXPRESSION_EMBED?.getVisibleSubgraph?.().nodes || [];
                  const centers = nodes.filter((node) => node.is_center === true);
                  return {
                    selection: diagnostics.selection,
                    input: document.querySelector('#coexpression-te-search').value,
                    searchType: document.querySelector('#coexpression-search-type').value,
                    centerCount: centers.length,
                    centerLabel: centers[0]?.label || '',
                    centerType: centers[0]?.type || '',
                    url: location.search,
                  };
                }"""
            )
            require(
                gene_state["input"] == "C1orf116"
                and gene_state["searchType"] == "Gene"
                and gene_state["centerCount"] == 1
                and gene_state["centerLabel"] == "C1orf116"
                and gene_state["centerType"] == "Gene"
                and "gene=C1orf116" in gene_state["url"],
                f"Gene-centered Co-expression search did not preserve its center or route: {gene_state}",
            )
            page.set_viewport_size({"width": 1024, "height": 768})
            page.wait_for_timeout(150)
            desktop_bounds = page.evaluate(
                """() => {
                  const fullscreen = document.querySelector('#previewFullscreenBtn').getBoundingClientRect();
                  const mode = document.querySelector('#previewWorkspaceMode').getBoundingClientRect();
                  const search = document.querySelector('#previewCoexpressionWorkspace .preview-entity-search').getBoundingClientRect();
                  const context = document.querySelector('.coexpression-context-control').getBoundingClientRect();
                  const controls = document.querySelector('.preview-top-controls');
                  const toolbar = document.querySelector('#previewCoexpressionWorkspace .preview-graph-toolbar');
                  return {
                    controlsInToolbar: controls.parentElement === toolbar,
                    controlsBeforeContext: controls.nextElementSibling === document.querySelector('.coexpression-context-control'),
                    controlsSeparated: fullscreen.right <= mode.left
                      || mode.right <= fullscreen.left
                      || fullscreen.bottom <= mode.top
                      || mode.bottom <= fullscreen.top,
                    modeInsideViewport: mode.right <= innerWidth,
                    toolbarSeparated: search.right <= context.left
                      || context.right <= search.left
                      || search.bottom <= context.top
                      || context.bottom <= search.top,
                  };
                }"""
            )
            require(
                all(desktop_bounds.values()),
                f"Task 7 controls overlap at the supported 1024x768 desktop viewport: {desktop_bounds}",
            )
            require(
                not errors,
                f"Browser errors: {errors[:5]}; failed requests: {failed_requests[:5]}",
            )
            ok("Task 7 dynamic/tree roundtrip, direct activation, iframe persistence, and mode isolation checks passed")
        finally:
            page.close()
            browser.close()


if __name__ == "__main__":
    run_check(main)
