const { chromium } = require('playwright');

const base = String(process.env.TEKG_BASE_URL || 'http://127.0.0.1/tedee').replace(/\/$/, '');
const diseaseClass = 'Neoplasms';

function assert(condition, message, details = null) {
  if (!condition) throw new Error(`${message}${details ? `\n${JSON.stringify(details, null, 2)}` : ''}`);
}

function normalized(value) {
  return String(value || '').trim().toLowerCase();
}

async function waitForDiseaseClassification(page, displayMode) {
  const expectedMode = displayMode === 'graph' ? 'disease_class_graph' : 'disease_class_tree';
  await page.waitForFunction(({ mode, className }) => {
    const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
    const params = new URL(window.location.href).searchParams;
    return state.mode === mode
      && String(state.classQuery || state.query || '').toLowerCase().includes(className.toLowerCase())
      && params.get('type') === 'disease_class'
      && String(params.get('class') || params.get('q') || '').toLowerCase().includes(className.toLowerCase())
      && !document.querySelector('#graph-preloader')?.classList.contains('is-visible');
  }, { mode: expectedMode, className: diseaseClass }, { timeout: 30000 });
}

async function diseaseSnapshot(page) {
  return page.evaluate(() => {
    const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
    const params = new URL(window.location.href).searchParams;
    const canvasExport = window.__TEKG_CANVAS_TAXONOMY?.getExportSnapshot?.() || {};
    return {
      mode: state.mode,
      classQuery: state.classQuery,
      routeQuery: params.get('q'),
      routeType: params.get('type'),
      routeClass: params.get('class'),
      routeTaxonomy: params.get('taxonomy'),
      treeSelected: document.querySelector('#preview-taxonomy-display-tree')?.getAttribute('aria-selected'),
      graphSelected: document.querySelector('#preview-taxonomy-display-graph')?.getAttribute('aria-selected'),
      displayControlHidden: document.querySelector('#previewTaxonomyDisplayMode')?.hidden === true,
      sourceControlHidden: document.querySelector('#previewTaxonomyMode')?.hidden === true,
      treeVisible: getComputedStyle(document.querySelector('#g6-default-tree-surface')).display !== 'none',
      canvasVisible: !document.querySelector('#taxonomy-canvas-surface')?.hidden
        && getComputedStyle(document.querySelector('#taxonomy-canvas-surface')).display !== 'none',
      legendVisible: !document.querySelector('#graph-type-legend')?.hidden,
      legendChecks: document.querySelectorAll('#graph-legend-list .graph-legend-check').length,
      canvasNodes: Array.isArray(canvasExport.nodes) ? canvasExport.nodes : [],
      canvasEdges: Array.isArray(canvasExport.edges) ? canvasExport.edges : [],
    };
  });
}

function assertDiseaseSnapshot(snapshot, displayMode, label) {
  const graph = displayMode === 'graph';
  assert(snapshot.mode === (graph ? 'disease_class_graph' : 'disease_class_tree'), `${label}: wrong disease classification mode.`, snapshot);
  assert(snapshot.routeType === 'disease_class', `${label}: route lost type=disease_class.`, snapshot);
  assert(normalized(snapshot.routeClass).includes('neoplasms'), `${label}: route lost the disease class.`, snapshot);
  assert(snapshot.routeTaxonomy === (graph ? 'graph' : null), `${label}: Graph must use taxonomy=graph and Tree must omit it.`, snapshot);
  assert(!snapshot.displayControlHidden, `${label}: Tree/Graph control is hidden.`, snapshot);
  assert(snapshot.sourceControlHidden, `${label}: TE-only All/RMSK source control is visible.`, snapshot);
  assert(snapshot.treeSelected === (graph ? 'false' : 'true') && snapshot.graphSelected === (graph ? 'true' : 'false'), `${label}: Tree/Graph control is stale.`, snapshot);
  assert(snapshot.treeVisible === !graph && snapshot.canvasVisible === graph, `${label}: wrong renderer surface is visible.`, snapshot);
  if (graph) {
    assert(snapshot.canvasNodes.length > 2 && snapshot.canvasEdges.length > 1, `${label}: disease Canvas is empty.`, snapshot);
    assert(snapshot.legendVisible && snapshot.legendChecks > 0, `${label}: disease Canvas legend is unavailable.`, snapshot);
  }
}

async function clickCanvasNode(page, predicate, drag = false) {
  const target = await page.evaluate((wanted) => {
    const snapshot = window.__TEKG_CANVAS_TAXONOMY?.getExportSnapshot?.() || {};
    const nodes = Array.isArray(snapshot.nodes) ? snapshot.nodes : [];
    const node = nodes.find((item) => wanted === 'disease'
      ? String(item.type || item.nodeType) === 'Disease'
      : String(item.type || item.nodeType) !== 'Disease');
    if (!node) return null;
    const rect = document.querySelector('#taxonomy-canvas')?.getBoundingClientRect();
    return rect ? { x: rect.left + Number(node.x), y: rect.top + Number(node.y), node } : null;
  }, predicate);
  assert(target, `No ${predicate} Canvas node was available for interaction.`);
  if (drag) {
    await page.mouse.move(target.x, target.y);
    await page.mouse.down();
    await page.mouse.move(target.x + 45, target.y + 25, { steps: 5 });
    await page.mouse.up();
  } else {
    await page.mouse.click(target.x, target.y);
  }
  return target.node;
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 960 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(String(error)));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });

  try {
    const treeUrl = `${base}/preview.php?q=${encodeURIComponent(diseaseClass)}&type=disease_class&class=${encodeURIComponent(diseaseClass)}`;
    await page.goto(treeUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDiseaseClassification(page, 'tree');
    assertDiseaseSnapshot(await diseaseSnapshot(page), 'tree', 'direct Tree route');
    await page.click('#preview-taxonomy-display-graph');
    await waitForDiseaseClassification(page, 'graph');
    let graphSnapshot = await diseaseSnapshot(page);
    assertDiseaseSnapshot(graphSnapshot, 'graph', 'Graph selection');

    const checks = page.locator('#graph-legend-list .graph-legend-check');
    const beforeFilterCount = graphSnapshot.canvasNodes.length;
    assert(await checks.count() > 1, 'Disease Canvas needs multiple legend levels for filtering.');
    await checks.last().uncheck();
    const apply = page.locator('#graph-legend-apply');
    assert(!(await apply.isDisabled()), 'Disease Canvas legend Apply did not become enabled.', await page.evaluate(() => ({
      state: window.__TEKG_G6_BRIDGE?.getState?.(),
      applyDisabled: document.querySelector('#graph-legend-apply')?.disabled,
      checked: [...document.querySelectorAll('#graph-legend-list .graph-legend-check')].map((item) => item.checked),
    })));
    await apply.click();
    await page.waitForFunction(() => document.querySelector('#graph-legend-apply')?.disabled === true);
    graphSnapshot = await diseaseSnapshot(page);
    assert(graphSnapshot.canvasNodes.length < beforeFilterCount, 'Disease Canvas legend did not filter the exported visible subgraph.', {
      before: beforeFilterCount,
      after: graphSnapshot.canvasNodes.length,
    });

    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDiseaseClassification(page, 'graph');
    assertDiseaseSnapshot(await diseaseSnapshot(page), 'graph', 'Graph refresh/share route');

    const classificationUrl = page.url();
    await clickCanvasNode(page, 'classification');
    await page.waitForTimeout(250);
    assert(page.url() === classificationUrl, 'A classification-only Canvas node navigated away from the disease class.', {
      before: classificationUrl,
      after: page.url(),
    });

    const draggedDisease = await clickCanvasNode(page, 'disease', true);
    await page.waitForTimeout(250);
    assert(page.url() === classificationUrl, 'Dragging a concrete Disease node triggered navigation.', draggedDisease);

    const clickedDisease = await clickCanvasNode(page, 'disease');
    await page.waitForFunction((label) => {
      const params = new URL(window.location.href).searchParams;
      const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
      const clean = (value) => String(value || '').trim().toLowerCase();
      return state.mode === 'dynamic'
        && params.get('type') === 'Disease'
        && clean(params.get('q')) === clean(label);
    }, clickedDisease.label || clickedDisease.rawLabel, { timeout: 30000 });

    await page.click('#back-graph');
    await waitForDiseaseClassification(page, 'graph');
    assertDiseaseSnapshot(await diseaseSnapshot(page), 'graph', 'page Back to disease Graph');

    const exported = await page.evaluate(async () => {
      const csv = await window.__TEKG_G6_EXPORT.exportCsv({ download: false });
      const svg = await window.__TEKG_G6_EXPORT.exportSvg({ download: false });
      return { query: csv.query, nodesCsv: csv.nodesCsv, svg: svg.svg };
    });
    assert(!normalized(exported.query).includes('taxonomy_graph'), 'Disease Graph export masquerades as TE taxonomy.', exported);
    assert(String(exported.nodesCsv).includes('Disease'), 'Disease Graph CSV lacks disease node types.', exported);
    assert(/disease classification/i.test(String(exported.svg)) && !/TE-Dee taxonomy graph/i.test(String(exported.svg)), 'Disease Graph SVG metadata masquerades as TE taxonomy.', {
      query: exported.query,
      svgHead: String(exported.svg).slice(0, 500),
    });

    await page.goBack({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDiseaseClassification(page, 'graph');
    await page.goBack({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDiseaseClassification(page, 'tree');
    assertDiseaseSnapshot(await diseaseSnapshot(page), 'tree', 'browser Back to Tree');
    await page.goForward({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDiseaseClassification(page, 'graph');
    assertDiseaseSnapshot(await diseaseSnapshot(page), 'graph', 'browser Forward to Graph');

    assert(errors.length === 0, 'Browser console errors were reported.', errors);
    console.log('PASS: Disease classification Canvas route, interaction, export, and Back browser check');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.stack || error);
  process.exit(1);
});
