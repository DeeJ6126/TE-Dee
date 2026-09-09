from __future__ import annotations

import json
from typing import Any
from urllib.parse import quote

from harness_lib import app_url, fail, ok, require, run_check


def evidence(data: dict[str, Any]) -> str:
    return json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True)


def main() -> None:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
        from playwright.sync_api import sync_playwright
    except ImportError:
        fail(
            "Playwright is not installed. Run:\n"
            "  pip install -r requirements-dev.txt\n"
            "  python -m playwright install chromium"
        )

    console_errors: list[str] = []
    page_errors: list[str] = []
    failed_requests: list[str] = []

    with sync_playwright() as p:
        try:
            browser = p.chromium.launch(headless=True)
        except PlaywrightError as exc:
            fail(f"Unable to launch Chromium. Run python -m playwright install chromium. Original error: {exc}")

        page = browser.new_page(viewport={"width": 1440, "height": 960})
        page.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)
        page.on("pageerror", lambda exc: page_errors.append(str(exc)))
        page.on("requestfailed", lambda request: failed_requests.append(f"{request.url} :: {request.failure}"))

        try:
            page.goto(app_url(f"preview.php?q={quote('LINE-1')}"), wait_until="domcontentloaded", timeout=30000)
            console_errors.clear()
            failed_requests.clear()
            page_errors.clear()
            page.wait_for_selector("#g6-dynamic-surface iframe", timeout=30000)
            page.wait_for_function(
                """() => window.__TEKG_G6_BRIDGE && typeof window.__TEKG_G6_BRIDGE.previewTeLoader === 'function'""",
                timeout=30000,
            )
            page.wait_for_function(
                """() => {
                    const loader = document.querySelector('#graph-preloader');
                    const state = window.__TEKG_G6_BRIDGE ? window.__TEKG_G6_BRIDGE.getState() : null;
                    const elements = state && Array.isArray(state.currentElements) ? state.currentElements : [];
                    return loader && loader.getAttribute('aria-hidden') === 'true' && elements.length > 0;
                }""",
                timeout=45000,
            )

            kind_probe = page.evaluate(
                """() => ({
                    classI: window.__TEKG_G6_BRIDGE.getTeLoaderKind('Class I: Retrotransposons'),
                    objectClassI: window.__TEKG_G6_BRIDGE.getTeLoaderKind({ nodeType: 'Category', label: 'Class I: Retrotransposons' }),
                    retroPlural: window.__TEKG_G6_BRIDGE.getTeLoaderKind('Retrotransposons'),
                    ltrRetro: window.__TEKG_G6_BRIDGE.getTeLoaderKind('LTR retrotransposon'),
                    line1: window.__TEKG_G6_BRIDGE.getTeLoaderKind('LINE1'),
                    lineDash: window.__TEKG_G6_BRIDGE.getTeLoaderKind('LINE-1'),
                    l1hs: window.__TEKG_G6_BRIDGE.getTeLoaderKind('L1HS'),
                    classII: window.__TEKG_G6_BRIDGE.getTeLoaderKind('Class II: DNA transposons'),
                    objectClassII: window.__TEKG_G6_BRIDGE.getTeLoaderKind({ nodeType: 'Category', label: 'Class II: DNA transposons' }),
                    dnaPlural: window.__TEKG_G6_BRIDGE.getTeLoaderKind('DNA transposons'),
                    tc1Mariner: window.__TEKG_G6_BRIDGE.getTeLoaderKind('Tc1-Mariner'),
                    unknown: window.__TEKG_G6_BRIDGE.getTeLoaderKind('Aging')
                })"""
            )
            require(kind_probe["classI"] == "retro", "Class I taxonomy label should classify as retro\n" + evidence(kind_probe))
            require(kind_probe["objectClassI"] == "retro", "Class I taxonomy object should classify as retro\n" + evidence(kind_probe))
            require(kind_probe["retroPlural"] == "retro", "Retrotransposons label should classify as retro\n" + evidence(kind_probe))
            require(kind_probe["ltrRetro"] == "retro", "LTR retrotransposon label should classify as retro\n" + evidence(kind_probe))
            require(kind_probe["line1"] == "retro", "LINE1 should classify as retro\n" + evidence(kind_probe))
            require(kind_probe["lineDash"] == "retro", "LINE-1 should classify as retro\n" + evidence(kind_probe))
            require(kind_probe["l1hs"] == "retro", "L1HS should classify as retro\n" + evidence(kind_probe))
            require(kind_probe["classII"] == "dna", "Class II taxonomy label should classify as dna\n" + evidence(kind_probe))
            require(kind_probe["objectClassII"] == "dna", "Class II taxonomy object should classify as dna\n" + evidence(kind_probe))
            require(kind_probe["dnaPlural"] == "dna", "DNA transposons label should classify as dna\n" + evidence(kind_probe))
            require(kind_probe["tc1Mariner"] == "dna", "Tc1-Mariner label should classify as dna\n" + evidence(kind_probe))
            require(kind_probe["unknown"] == "default", "Unknown query should classify as default\n" + evidence(kind_probe))

            search_loader = page.evaluate(
                """() => {
                    window.__TEKG_SEARCH_LOADER_SNAPSHOTS = [];
                    const loader = document.querySelector('#graph-preloader');
                    const capture = () => {
                        const svg = loader ? loader.querySelector('svg.te-mechanism-loader__svg') : null;
                        window.__TEKG_SEARCH_LOADER_SNAPSHOTS.push({
                            className: loader ? loader.className : '',
                            hidden: loader ? loader.getAttribute('aria-hidden') : '',
                            label: document.querySelector('#graph-preloader-label')?.textContent || '',
                            viewBox: svg ? svg.getAttribute('viewBox') : '',
                        });
                    };
                    capture();
                    if (window.__TEKG_SEARCH_LOADER_OBSERVER) window.__TEKG_SEARCH_LOADER_OBSERVER.disconnect();
                    window.__TEKG_SEARCH_LOADER_OBSERVER = new MutationObserver(capture);
                    if (loader) {
                        window.__TEKG_SEARCH_LOADER_OBSERVER.observe(loader, {
                            attributes: true,
                            childList: true,
                            subtree: true,
                            attributeFilter: ['class', 'aria-hidden']
                        });
                    }
                    window.__TEKG_SEARCH_LOADER_PROMISE = window.__TEKG_G6_BRIDGE.loadGraph('LINE1')
                        .then(() => ({ ok: true }))
                        .catch((error) => ({ ok: false, error: String(error && error.message || error) }))
                        .finally(() => {
                        capture();
                        if (window.__TEKG_SEARCH_LOADER_OBSERVER) window.__TEKG_SEARCH_LOADER_OBSERVER.disconnect();
                    });
                    return true;
                }"""
            )
            require(search_loader is True, "Search loader capture did not start")
            search_result = page.evaluate("() => window.__TEKG_SEARCH_LOADER_PROMISE")
            snapshots = page.evaluate("() => window.__TEKG_SEARCH_LOADER_SNAPSHOTS || []")
            require(
                any("te-loader-retro" in str(item.get("className", "")) and item.get("viewBox") == "0 0 560 300" for item in snapshots),
                "LINE1 graph loading should show retro mechanism loader\n" + evidence({"search_result": search_result, "snapshots": snapshots[:20]}),
            )
            console_errors.clear()
            failed_requests.clear()
            page_errors.clear()

            retro = page.evaluate(
                """() => {
                    const preview = window.__TEKG_G6_BRIDGE.previewTeLoader({ label: 'L1HS' });
                    const loader = document.querySelector('#graph-preloader');
                    const svg = loader ? loader.querySelector('svg.te-mechanism-loader__svg') : null;
                    const dna = loader ? loader.querySelector('.te-loader-dna-backbone') : null;
                    const te = loader ? loader.querySelector('.te-loader-te-segment') : null;
                    const rna = loader ? loader.querySelector('.te-loader-rna') : null;
                    const complex = loader ? loader.querySelector('.te-loader-retro-complex') : null;
                    const target = loader ? loader.querySelector('.te-loader-target-dna') : null;
                    const targetLeft = loader ? loader.querySelector('.te-loader-target-left') : null;
                    const targetRight = loader ? loader.querySelector('.te-loader-target-right') : null;
                    const targetContinuous = loader ? loader.querySelector('.te-loader-target-continuous') : null;
                    const arrows = loader ? loader.querySelectorAll('.te-loader-arrow') : [];
                    const copyText = loader ? loader.querySelector('.te-loader-copy-label') : null;
                    const copyGroup = loader ? loader.querySelector('.te-loader-copy') : null;
                    const labelText = loader ? loader.querySelector('.te-loader-label') : null;
                    const children = svg ? Array.from(svg.children).map((item) => item.getAttribute('class') || item.tagName) : [];
                    const rectNumber = (value) => Number(String(value || '').replace(/[^\\d.-]/g, ''));
                    const pathEndX = (path) => {
                        const d = path ? path.getAttribute('d') || '' : '';
                        const nums = d.match(/-?\\d+(?:\\.\\d+)?/g) || [];
                        return nums.length ? Number(nums[nums.length - 1]) : NaN;
                    };
                    const pathStartX = (path) => {
                        const d = path ? path.getAttribute('d') || '' : '';
                        const nums = d.match(/-?\\d+(?:\\.\\d+)?/g) || [];
                        return nums.length ? Number(nums[0]) : NaN;
                    };
                    const copyRect = copyGroup ? copyGroup.querySelector('rect') : null;
                    const targetY = (() => {
                        const d = targetLeft ? targetLeft.getAttribute('d') || '' : '';
                        const nums = d.match(/-?\\d+(?:\\.\\d+)?/g) || [];
                        return nums.length > 1 ? Number(nums[1]) : NaN;
                    })();
                    const svgBox = svg ? svg.getBoundingClientRect() : { width: 0, height: 0 };
                    return {
                        preview,
                        className: loader ? loader.className : '',
                        text: loader ? loader.textContent : '',
                        svg: !!svg,
                        viewBox: svg ? svg.getAttribute('viewBox') : '',
                        svgWidth: svgBox.width,
                        svgHeight: svgBox.height,
                        svgText: svg ? svg.textContent : '',
                        complexChildren: complex ? Array.from(complex.children).map((item) => item.className.baseVal || item.className || item.tagName) : [],
                        targetClass: target ? target.getAttribute('class') : '',
                        targetAnimation: target ? getComputedStyle(target).animationName : '',
                        targetLeft: !!targetLeft,
                        targetRight: !!targetRight,
                        targetContinuous: !!targetContinuous,
                        targetLeftEndX: pathEndX(targetLeft),
                        targetRightStartX: pathStartX(targetRight),
                        arrowCount: arrows.length,
                        arrowVisibleCount: Array.from(arrows).filter((item) => getComputedStyle(item).display !== 'none' && getComputedStyle(item).visibility !== 'hidden').length,
                        copyText: copyText ? copyText.textContent.trim() : '',
                        copyX: copyRect ? rectNumber(copyRect.getAttribute('x')) : null,
                        copyY: copyRect ? rectNumber(copyRect.getAttribute('y')) : null,
                        copyWidth: copyRect ? rectNumber(copyRect.getAttribute('width')) : null,
                        copyHeight: copyRect ? rectNumber(copyRect.getAttribute('height')) : null,
                        copyCenterY: copyRect ? rectNumber(copyRect.getAttribute('y')) + (rectNumber(copyRect.getAttribute('height')) / 2) : null,
                        targetY,
                        targetIndex: children.findIndex((item) => String(item).includes('te-loader-target-dna')),
                        copyIndex: children.findIndex((item) => String(item).includes('te-loader-copy')),
                        dnaStroke: dna ? getComputedStyle(dna).stroke : '',
                        teFill: te ? getComputedStyle(te).fill : '',
                        rnaStroke: rna ? getComputedStyle(rna).stroke : '',
                        rnaOpacity: rna ? getComputedStyle(rna).opacity : '',
                        labelStroke: labelText ? getComputedStyle(labelText).stroke : '',
                        labelPaintOrder: labelText ? getComputedStyle(labelText).paintOrder : '',
                        aria: svg ? svg.getAttribute('aria-label') : '',
                    };
                }"""
            )
            require(retro["preview"]["kind"] == "retro", "L1HS should use retro loader\n" + evidence(retro))
            require("te-loader-retro" in retro["className"], "Retro loader class missing\n" + evidence(retro))
            require(retro["svg"] is True, "Retro loader SVG missing\n" + evidence(retro))
            require(retro["viewBox"] == "0 0 560 300", "Retro loader should use 560x300 viewBox\n" + evidence(retro))
            require(retro["svgWidth"] >= 540 and retro["svgHeight"] >= 280, "Retro loader rendered size should be near 560x300\n" + evidence(retro))
            require("L1HS" in retro["svgText"], "Retro loader must include TE label\n" + evidence(retro))
            require("RT" in retro["svgText"] and "RNA" in retro["svgText"], "Retro loader must include RT/RNA labels\n" + evidence(retro))
            require(retro["arrowCount"] == 0 and retro["arrowVisibleCount"] == 0, "Retro loader must not use arrow paths\n" + evidence(retro))
            require("rgb(100, 116, 139)" in retro["dnaStroke"], "Non-TE DNA should use gray\n" + evidence(retro))
            complex_classes = " ".join(str(item) for item in retro["complexChildren"])
            require(
                all(token in complex_classes for token in ("te-loader-rna", "te-loader-rna-label", "te-loader-enzyme-fill", "te-loader-rt-label")),
                "Retro RNA/label/RT must share one moving group\n" + evidence(retro),
            )
            require(retro["copyText"] == "L1HS", "Retro target copy label should use current TE label\n" + evidence(retro))
            require("te-loader-target-dna" in retro["targetClass"], "Retro target DNA group missing\n" + evidence(retro))
            require(retro["targetAnimation"] in ("none", ""), "Retro target DNA should not slide or animate\n" + evidence(retro))
            require(retro["targetLeft"] and retro["targetRight"] and not retro["targetContinuous"], "Retro target DNA should be split into left/right gap segments\n" + evidence(retro))
            require(retro["copyIndex"] > retro["targetIndex"] >= 0, "Retro copy should render above target DNA/opening arc\n" + evidence(retro))
            require(abs(retro["copyX"] - retro["targetLeftEndX"]) <= 6, "Retro copy left edge should connect to target left segment\n" + evidence(retro))
            require(abs((retro["copyX"] + retro["copyWidth"]) - retro["targetRightStartX"]) <= 6, "Retro copy right edge should connect to target right segment\n" + evidence(retro))
            require(abs(retro["copyCenterY"] - retro["targetY"]) <= 4, "Retro copy should sit on the target DNA backbone, not hover above it\n" + evidence(retro))
            require(retro["teFill"] == retro["rnaStroke"], "RNA should use TE hue\n" + evidence(retro))
            require(float(retro["rnaOpacity"]) < 0.75, "RNA should be lighter/transparent relative to TE segment\n" + evidence(retro))
            require("rgb(255, 255, 255)" in retro["labelStroke"], "SVG labels should have white stroke\n" + evidence(retro))
            require("stroke" in retro["labelPaintOrder"], "SVG labels should use paint-order stroke\n" + evidence(retro))

            dna = page.evaluate(
                """() => {
                    const preview = window.__TEKG_G6_BRIDGE.previewTeLoader({ label: 'Tc1 Mariner DNA transposon' });
                    const loader = document.querySelector('#graph-preloader');
                    const svg = loader ? loader.querySelector('svg.te-mechanism-loader__svg') : null;
                    const moving = loader ? loader.querySelector('.te-loader-dna-segment-moving') : null;
                    const target = loader ? loader.querySelector('.te-loader-target-dna') : null;
                    const targetLeft = loader ? loader.querySelector('.te-loader-target-left') : null;
                    const targetRight = loader ? loader.querySelector('.te-loader-target-right') : null;
                    const targetContinuous = loader ? loader.querySelector('.te-loader-target-continuous') : null;
                    const sourceLeft = loader ? loader.querySelector('.te-loader-source-left') : null;
                    const sourceRight = loader ? loader.querySelector('.te-loader-source-right') : null;
                    const sourceContinuous = loader ? loader.querySelector('.te-loader-source-continuous') : null;
                    const cuts = loader ? loader.querySelectorAll('.te-loader-cut') : [];
                    const arrows = loader ? loader.querySelectorAll('.te-loader-arrow') : [];
                    const inserted = loader ? loader.querySelector('.te-loader-inserted-label') : null;
                    const insertedGroup = loader ? loader.querySelector('.te-loader-inserted-copy') : null;
                    const movingRect = moving ? moving.querySelector('rect') : null;
                    const children = svg ? Array.from(svg.children).map((item) => item.getAttribute('class') || item.tagName) : [];
                    const rectNumber = (value) => Number(String(value || '').replace(/[^\\d.-]/g, ''));
                    const pathEndX = (path) => {
                        const d = path ? path.getAttribute('d') || '' : '';
                        const nums = d.match(/-?\\d+(?:\\.\\d+)?/g) || [];
                        return nums.length ? Number(nums[nums.length - 1]) : NaN;
                    };
                    const pathStartX = (path) => {
                        const d = path ? path.getAttribute('d') || '' : '';
                        const nums = d.match(/-?\\d+(?:\\.\\d+)?/g) || [];
                        return nums.length ? Number(nums[0]) : NaN;
                    };
                    const pathY = (path) => {
                        const d = path ? path.getAttribute('d') || '' : '';
                        const nums = d.match(/-?\\d+(?:\\.\\d+)?/g) || [];
                        return nums.length > 1 ? Number(nums[1]) : NaN;
                    };
                    return {
                        preview,
                        className: loader ? loader.className : '',
                        text: loader ? loader.textContent : '',
                        svg: !!svg,
                        viewBox: svg ? svg.getAttribute('viewBox') : '',
                        svgText: svg ? svg.textContent : '',
                        movingChildren: moving ? Array.from(moving.children).map((item) => item.tagName) : [],
                        movingAnimation: moving ? getComputedStyle(moving).animationName : '',
                        targetClass: target ? target.getAttribute('class') : '',
                        targetAnimation: target ? getComputedStyle(target).animationName : '',
                        targetLeft: !!targetLeft,
                        targetRight: !!targetRight,
                        targetContinuous: !!targetContinuous,
                        sourceLeft: !!sourceLeft,
                        sourceRight: !!sourceRight,
                        sourceContinuous: !!sourceContinuous,
                        sourceLeftEndX: pathEndX(sourceLeft),
                        sourceRightStartX: pathStartX(sourceRight),
                        targetLeftEndX: pathEndX(targetLeft),
                        targetRightStartX: pathStartX(targetRight),
                        targetY: pathY(targetLeft),
                        movingX: movingRect ? rectNumber(movingRect.getAttribute('x')) : null,
                        movingY: movingRect ? rectNumber(movingRect.getAttribute('y')) : null,
                        movingWidth: movingRect ? rectNumber(movingRect.getAttribute('width')) : null,
                        movingHeight: movingRect ? rectNumber(movingRect.getAttribute('height')) : null,
                        targetIndex: children.findIndex((item) => String(item).includes('te-loader-target-dna')),
                        movingIndex: children.findIndex((item) => String(item).includes('te-loader-dna-segment-moving')),
                        cutCount: cuts.length,
                        arrowCount: arrows.length,
                        insertedText: inserted ? inserted.textContent.trim() : '',
                        insertedGroupExists: !!insertedGroup,
                        hasRna: !!(loader && loader.querySelector('.te-loader-rna')),
                        hasRt: svg ? svg.textContent.includes('RT') : false,
                    };
                }"""
            )
            require(dna["preview"]["kind"] == "dna", "DNA transposon query should use DNA loader\n" + evidence(dna))
            require("te-loader-dna" in dna["className"], "DNA loader class missing\n" + evidence(dna))
            require(dna["svg"] is True, "DNA loader SVG missing\n" + evidence(dna))
            require(dna["viewBox"] == "0 0 560 300", "DNA loader should use 560x300 viewBox\n" + evidence(dna))
            require("Tc1..." in dna["svgText"], "DNA loader should include truncated TE label\n" + evidence(dna))
            require({"rect", "text"}.issubset({str(item).lower() for item in dna["movingChildren"]}), "DNA moving segment should contain rect + text\n" + evidence(dna))
            require(dna["insertedGroupExists"] is False and dna["insertedText"] == "", "DNA loader should not use inserted-copy duplicate TE path\n" + evidence(dna))
            require("te-loader-target-dna" in dna["targetClass"], "DNA target DNA group missing\n" + evidence(dna))
            require(dna["targetAnimation"] in ("none", ""), "DNA target DNA should stay fixed; only gap segments animate\n" + evidence(dna))
            require(dna["targetLeft"] and dna["targetRight"] and not dna["targetContinuous"], "DNA target DNA should be split into left/right gap segments\n" + evidence(dna))
            require(dna["sourceLeft"] and dna["sourceRight"] and not dna["sourceContinuous"], "DNA source donor site should use split DNA segments\n" + evidence(dna))
            require(abs(dna["sourceLeftEndX"] - dna["movingX"]) <= 4, "DNA source left segment should initially connect to TE left edge\n" + evidence(dna))
            require(abs(dna["sourceRightStartX"] - (dna["movingX"] + dna["movingWidth"])) <= 4, "DNA source right segment should initially connect to TE right edge\n" + evidence(dna))
            require(abs(dna["targetLeftEndX"] - (dna["movingX"] + 242)) <= 8, "DNA moving TE final left edge should align with target left segment\n" + evidence(dna))
            require(abs(dna["targetRightStartX"] - (dna["movingX"] + 242 + dna["movingWidth"])) <= 8, "DNA moving TE final right edge should align with target right segment\n" + evidence(dna))
            require(abs((dna["movingY"] + 160 + (dna["movingHeight"] / 2)) - dna["targetY"]) <= 4, "DNA moving TE should land on the target DNA backbone, not above the gap\n" + evidence(dna))
            require(dna["movingIndex"] > dna["targetIndex"] >= 0, "DNA moving TE should render above target opening arc/segments\n" + evidence(dna))
            require(dna["cutCount"] == 0, "DNA transposon loader should not use slash cut marks\n" + evidence(dna))
            require(dna["arrowCount"] == 0, "DNA transposon loader must not use arrow paths\n" + evidence(dna))
            require(dna["movingAnimation"] == "te-loader-dna-segment-transfer", "DNA moving TE should use the two-phase transfer animation\n" + evidence(dna))
            require(dna["hasRna"] is False and dna["hasRt"] is False, "DNA loader must not include RNA/RT\n" + evidence(dna))

            default = page.evaluate(
                """() => {
                    const preview = window.__TEKG_G6_BRIDGE.previewTeLoader({ label: 'Aging' });
                    const loader = document.querySelector('#graph-preloader');
                    return {
                        preview,
                        className: loader ? loader.className : '',
                        svgCount: loader ? loader.querySelectorAll('svg.te-mechanism-loader__svg').length : 0,
                        iconVisible: !!(loader && loader.querySelector('.graph-preloader-icon')),
                    };
                }"""
            )
            require(default["preview"]["kind"] == "default", "Unknown/non-TE query should keep default loader\n" + evidence(default))
            require(default["svgCount"] == 0, "Default loader should not render mechanism SVG\n" + evidence(default))
            require(default["iconVisible"] is True, "Default loader should keep existing icon\n" + evidence(default))

            page.goto(app_url(f"preview.php?q={quote('LINE-1')}"), wait_until="domcontentloaded", timeout=30000)
            page.wait_for_selector("#g6-dynamic-surface iframe", timeout=30000)
            page.wait_for_function(
                """() => window.__TEKG_G6_BRIDGE && typeof window.__TEKG_G6_BRIDGE.getState === 'function'""",
                timeout=30000,
            )
            page.wait_for_function(
                """() => {
                    const loader = document.querySelector('#graph-preloader');
                    const state = window.__TEKG_G6_BRIDGE ? window.__TEKG_G6_BRIDGE.getState() : null;
                    const elements = state && Array.isArray(state.currentElements) ? state.currentElements : [];
                    return loader && loader.getAttribute('aria-hidden') === 'true' && elements.length > 0;
                }""",
                timeout=45000,
            )

            expand = page.evaluate(
                """async () => {
                    const iframe = document.querySelector('#g6-dynamic-surface iframe');
                    const embed = iframe && iframe.contentWindow ? iframe.contentWindow.__TEKG_G6_EMBED : null;
                    const subgraph = embed && typeof embed.getVisibleSubgraph === 'function' ? await embed.getVisibleSubgraph() : null;
                    const nodes = Array.isArray(subgraph && subgraph.nodes) ? subgraph.nodes : [];
                    const node = nodes.find((item) => String(item.rawLabel || item.label || '') === 'L1HS')
                        || nodes.find((item) => String(item.type || item.nodeType || '') === 'TE')
                        || nodes[0];
                    if (!node) return { result: false, texts: [], error: 'no expandable node' };
                    window.__TEKG_TE_LOADER_TEXTS = [];
                    const label = document.querySelector('#graph-preloader-label');
                    const capture = () => window.__TEKG_TE_LOADER_TEXTS.push(label ? label.textContent || '' : '');
                    capture();
                    const observer = new MutationObserver(capture);
                    if (label) observer.observe(label, { childList: true, characterData: true, subtree: true });
                    const result = await embed.triggerNodeAction(node.id, 'expand');
                    capture();
                    observer.disconnect();
                    return {
                        result,
                        texts: window.__TEKG_TE_LOADER_TEXTS,
                        hidden: document.querySelector('#graph-preloader')?.getAttribute('aria-hidden'),
                    };
                }"""
            )
            require(expand["result"] is True, "Expand action failed\n" + evidence(expand))
            require(any("Expanding" in str(item) for item in expand["texts"]), "Expand text must remain Expanding\n" + evidence(expand))

            page.wait_for_function(
                """() => {
                    const loader = document.querySelector('#graph-preloader');
                    return loader && loader.getAttribute('aria-hidden') === 'true';
                }""",
                timeout=30000,
            )
            hidden = page.locator("#graph-preloader").evaluate("el => el.getAttribute('aria-hidden')")
            require(hidden == "true", "Loader should be hidden after graph load\n" + evidence({"hidden": hidden}))

            tolerated_console = [
                item for item in console_errors
                if not ("G6 graph failed: TypeError: Failed to fetch" in item and search_result and search_result.get("ok") is False)
            ]
            tolerated_failed = [
                item for item in failed_requests
                if "ERR_ABORTED" not in item
            ]
            require(not tolerated_console, "Console errors:\n" + "\n".join(tolerated_console[:10]))
            require(not page_errors, "Page errors:\n" + "\n".join(page_errors[:10]))
            require(not tolerated_failed, "Failed requests:\n" + "\n".join(tolerated_failed[:10]))
        except PlaywrightTimeoutError as exc:
            fail(f"Timed out while checking TE mechanism loader: {exc}")
        finally:
            browser.close()

    ok("G6 TE mechanism loader smoke passed")


if __name__ == "__main__":
    run_check(main)
