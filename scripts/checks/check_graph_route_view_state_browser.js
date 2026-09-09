const { chromium } = require('playwright');

const base = String(process.env.TEKG_BASE_URL || 'http://127.0.0.1/tedee').replace(/\/$/, '');
const requestedCase = String(process.env.TEKG_ROUTE_STATE_CASE || 'all');

function assert(condition, message, details = null) {
  if (!condition) throw new Error(`${message}${details ? `\n${JSON.stringify(details, null, 2)}` : ''}`);
}

function sorted(values) {
  return [...values].map((value) => String(value)).sort((a, b) => a.localeCompare(b));
}

function commaList(value) {
  if (value === null) return null;
  if (value === 'none') return [];
  return sorted(String(value).split(',').map((item) => item.trim()).filter(Boolean));
}

async function waitForTaxonomy(page, displayMode, treeVariant) {
  const mode = displayMode === 'graph' ? 'taxonomy_graph' : 'tree';
  await page.waitForFunction(({ expectedMode, expectedTree }) => {
    const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
    return state.mode === expectedMode
      && state.treeVariant === expectedTree
      && !document.querySelector('#graph-loader')?.classList.contains('is-visible')
      && !document.querySelector('#graph-preloader')?.classList.contains('is-visible');
  }, { expectedMode: mode, expectedTree: treeVariant }, { timeout: 30000 });
}

async function taxonomySnapshot(page) {
  return page.evaluate(() => {
    const route = new URL(window.location.href).searchParams;
    const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
    return {
      routeTaxonomy: route.get('taxonomy'),
      routeTree: route.get('tree'),
      mode: state.mode,
      treeVariant: state.treeVariant,
      treeSelected: document.querySelector('#preview-taxonomy-display-tree')?.getAttribute('aria-selected'),
      graphSelected: document.querySelector('#preview-taxonomy-display-graph')?.getAttribute('aria-selected'),
      allSelected: document.querySelector('#preview-taxonomy-all')?.getAttribute('aria-selected'),
      rmskSelected: document.querySelector('#preview-taxonomy-rmsk-repbase')?.getAttribute('aria-selected'),
      treeSurfaceVisible: getComputedStyle(document.querySelector('#g6-default-tree-surface')).display !== 'none',
      canvasSurfaceVisible: !document.querySelector('#taxonomy-canvas-surface')?.hidden
        && getComputedStyle(document.querySelector('#taxonomy-canvas-surface')).display !== 'none',
      canvasSource: window.__TEKG_CANVAS_TAXONOMY?.getLayoutMeta?.().source || '',
    };
  });
}

function assertTaxonomy(snapshot, displayMode, treeVariant, label) {
  const graph = displayMode === 'graph';
  assert(snapshot.mode === (graph ? 'taxonomy_graph' : 'tree'), `${label}: renderer mode is wrong.`, snapshot);
  assert(snapshot.treeVariant === treeVariant, `${label}: taxonomy source is wrong.`, snapshot);
  assert(snapshot.routeTaxonomy === (graph ? 'graph' : null), `${label}: taxonomy route must use taxonomy=graph only for Graph.`, snapshot);
  assert(snapshot.routeTree === treeVariant, `${label}: taxonomy source is missing from the route.`, snapshot);
  assert(snapshot.treeSelected === (graph ? 'false' : 'true') && snapshot.graphSelected === (graph ? 'true' : 'false'), `${label}: Tree/Graph segmented control is stale.`, snapshot);
  assert(snapshot.allSelected === (treeVariant === 'all' ? 'true' : 'false') && snapshot.rmskSelected === (treeVariant === 'all' ? 'false' : 'true'), `${label}: source segmented control is stale.`, snapshot);
  assert(snapshot.treeSurfaceVisible === !graph && snapshot.canvasSurfaceVisible === graph, `${label}: the visible taxonomy surface is stale.`, snapshot);
  if (graph) assert(snapshot.canvasSource === treeVariant, `${label}: Canvas rendered a stale taxonomy source.`, snapshot);
}

async function waitForDynamic(page, query = 'L1HS', requireVisibleNodes = true) {
  const expected = String(query).toLowerCase();
  await page.waitForFunction(({ target, requireVisible }) => {
    const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
    const frame = document.querySelector('#g6-dynamic-surface iframe');
    const visible = frame?.contentWindow?.__TEKG_G6_EMBED?.getVisibleSubgraph?.() || {};
    return state.mode === 'dynamic'
      && String(state.query || '').toLowerCase() === target
      && Array.isArray(state.currentElements)
      && state.currentElements.length > 0
      && (!requireVisible || Number(visible.counts?.nodes || visible.nodes?.length || 0) > 0)
      && !document.querySelector('#graph-loader')?.classList.contains('is-visible')
      && !document.querySelector('#graph-preloader')?.classList.contains('is-visible');
  }, { target: expected, requireVisible: requireVisibleNodes }, { timeout: 30000 });
}

async function visibleFilterSnapshot(page) {
  return page.evaluate(() => {
    const params = new URL(window.location.href).searchParams;
    const controls = [...document.querySelectorAll('#graph-legend-list .graph-legend-check')];
    const pick = (key) => controls
      .filter((input) => input.dataset[key])
      .filter((input) => input.checked)
      .map((input) => input.dataset[key]);
    return {
      nodes: params.get('nodes'),
      relations: params.get('relations'),
      minPmids: params.get('min_pmids'),
      checkedNodes: pick('type'),
      checkedRelations: pick('relation'),
      minInput: document.querySelector('#graph-relation-min-pmids')?.value || '',
      nodeCatalog: controls.filter((input) => input.dataset.type).map((input) => input.dataset.type),
      relationCatalog: controls.filter((input) => input.dataset.relation).map((input) => input.dataset.relation),
    };
  });
}

async function dynamicFailureSnapshot(page) {
  return page.evaluate(() => {
    const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
    const frame = document.querySelector('#g6-dynamic-surface iframe');
    const visible = frame?.contentWindow?.__TEKG_G6_EMBED?.getVisibleSubgraph?.() || {};
    const stableMap = (value) => Object.entries(value || {}).sort(([a], [b]) => a.localeCompare(b));
    return {
      url: `${window.location.pathname}${window.location.search}${window.location.hash}`,
      visibleTypes: stableMap(state.visibleTypes),
      visibleRelations: stableMap(state.visibleRelations),
      minPmids: Number(state.relationMinPmids || 0),
      visibleIds: [
        ...(Array.isArray(visible.nodes) ? visible.nodes : []),
        ...(Array.isArray(visible.edges) ? visible.edges : []),
      ].map((item) => String(item.id || '')).filter(Boolean).sort(),
    };
  });
}

async function selectLegendTab(page, mode) {
  await page.click(mode === 'relation' ? '#graph-legend-relation-tab' : '#graph-legend-entity-tab');
  await page.waitForFunction((key) => document.querySelector(`#graph-legend-list .graph-legend-check[data-${key}]`), mode === 'relation' ? 'relation' : 'type');
}

async function setAllChecks(page, checked) {
  await page.locator('#graph-legend-list .graph-legend-check').evaluateAll((inputs, next) => {
    for (const input of inputs) {
      if (input.checked === next) continue;
      input.checked = next;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, checked);
}

async function applyFilters(page) {
  const apply = page.locator('#graph-legend-apply');
  await apply.waitFor({ state: 'visible' });
  assert(!(await apply.isDisabled()), 'Legend Apply did not become enabled after a pending change.');
  await apply.click();
  await page.waitForFunction(() => document.querySelector('#graph-legend-apply')?.disabled === true
    && !document.querySelector('#graph-preloader')?.classList.contains('is-visible'), null, { timeout: 30000 });
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
    await page.goto(`${base}/preview.php?tree=rmsk_repbase`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForTaxonomy(page, 'tree', 'rmsk_repbase');
    assertTaxonomy(await taxonomySnapshot(page), 'tree', 'rmsk_repbase', 'initial taxonomy');

    if (requestedCase !== 'filter-failure') {
      if (requestedCase !== 'taxonomy-race' && requestedCase !== 'source-race') {
        const taxonomyRenderRejected = await page.evaluate(async () => {
      const renderer = window.__TEKG_CANVAS_TAXONOMY;
      const original = renderer.render;
      renderer.render = async () => { throw new Error('forced taxonomy Canvas render rejection'); };
      try {
        await window.__TEKG_PREVIEW_WORKSPACE_MODE.setTaxonomyDisplayMode('graph', { history: 'push' });
        return false;
      } catch (_error) {
        return true;
      } finally {
        renderer.render = original;
      }
    });
        assert(taxonomyRenderRejected, 'The forced taxonomy Canvas render did not reject.');
        assertTaxonomy(await taxonomySnapshot(page), 'tree', 'rmsk_repbase', 'taxonomy rejection rollback');
      }

      await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
      await waitForTaxonomy(page, 'tree', 'rmsk_repbase');
      if (requestedCase !== 'source-race') await page.evaluate(async () => {
      const renderer = window.__TEKG_CANVAS_TAXONOMY;
      const original = renderer.render.bind(renderer);
      renderer.render = async (...args) => {
        await new Promise((resolve) => setTimeout(resolve, 500));
        return original(...args);
      };
      try {
        const olderGraph = window.__TEKG_PREVIEW_WORKSPACE_MODE.setTaxonomyDisplayMode('graph', { history: 'push' });
        await new Promise((resolve) => setTimeout(resolve, 30));
        const latestTree = window.__TEKG_PREVIEW_WORKSPACE_MODE.setTaxonomyDisplayMode('tree', { history: 'push' });
        await Promise.allSettled([olderGraph, latestTree]);
      } finally {
        renderer.render = original;
      }
      });
      if (requestedCase !== 'source-race') {
        assertTaxonomy(await taxonomySnapshot(page), 'tree', 'rmsk_repbase', 'rapid display transition');
      }

    await page.goto(`${base}/preview.php?tree=rmsk_repbase&taxonomy=graph`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForTaxonomy(page, 'graph', 'rmsk_repbase');
    await page.evaluate(async () => {
      const renderer = window.__TEKG_CANVAS_TAXONOMY;
      const original = renderer.render.bind(renderer);
      renderer.render = async (options = {}) => {
        await new Promise((resolve) => setTimeout(resolve, options.source === 'all' ? 500 : 20));
        return original(options);
      };
      try {
        const olderAll = window.__TEKG_PREVIEW_WORKSPACE_MODE.setTreeVariant('all', { history: 'push' });
        await new Promise((resolve) => setTimeout(resolve, 30));
        const latestRmsk = window.__TEKG_PREVIEW_WORKSPACE_MODE.setTreeVariant('rmsk_repbase', { history: 'push' });
        await Promise.allSettled([olderAll, latestRmsk]);
      } finally {
        renderer.render = original;
      }
    });
      assertTaxonomy(await taxonomySnapshot(page), 'graph', 'rmsk_repbase', 'rapid source transition');
    }

    await page.goto(`${base}/preview.php?tree=rmsk_repbase`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForTaxonomy(page, 'tree', 'rmsk_repbase');

    await page.click('#preview-taxonomy-display-graph');
    await waitForTaxonomy(page, 'graph', 'rmsk_repbase');
    assertTaxonomy(await taxonomySnapshot(page), 'graph', 'rmsk_repbase', 'Graph selection');

    await page.click('#preview-taxonomy-all');
    await waitForTaxonomy(page, 'graph', 'all');
    assertTaxonomy(await taxonomySnapshot(page), 'graph', 'all', 'All selection');

    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForTaxonomy(page, 'graph', 'all');
    assertTaxonomy(await taxonomySnapshot(page), 'graph', 'all', 'taxonomy refresh');

    await page.goBack({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForTaxonomy(page, 'graph', 'rmsk_repbase');
    assertTaxonomy(await taxonomySnapshot(page), 'graph', 'rmsk_repbase', 'taxonomy Back');
    await page.goBack({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForTaxonomy(page, 'tree', 'rmsk_repbase');
    assertTaxonomy(await taxonomySnapshot(page), 'tree', 'rmsk_repbase', 'taxonomy second Back');
    await page.goForward({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForTaxonomy(page, 'graph', 'rmsk_repbase');
    assertTaxonomy(await taxonomySnapshot(page), 'graph', 'rmsk_repbase', 'taxonomy Forward');

    await page.goto(`${base}/preview.php?q=L1HS&type=TE`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page);
    let filters = await visibleFilterSnapshot(page);
    assert(filters.nodes === null && filters.relations === null && filters.minPmids === null, 'Default all-applied filters must keep the URL short.', filters);

    await selectLegendTab(page, 'entity');
    let nodeChecks = page.locator('#graph-legend-list .graph-legend-check[data-type]');
    assert(await nodeChecks.count() >= 2, 'The L1HS graph needs at least two node types for the failed-Apply rollback test.');
    const beforeFailedApply = await dynamicFailureSnapshot(page);
    await nodeChecks.last().uncheck();
    const forcedApplyFailure = await page.evaluate(async () => {
      const frame = document.querySelector('#g6-dynamic-surface iframe');
      const embed = frame?.contentWindow?.__TEKG_G6_EMBED;
      const original = embed.renderElements.bind(embed);
      embed.renderElements = async (...args) => {
        const rendered = await original(...args);
        throw new Error('forced cached iframe renderElements rejection after mutation');
      };
      try {
        document.querySelector('#graph-legend-apply').click();
        const deadline = Date.now() + 30000;
        while (Date.now() < deadline) {
          if (document.querySelector('#graph-legend-apply')?.disabled === true
            && !document.querySelector('#graph-preloader')?.classList.contains('is-visible')
            && /forced cached iframe renderElements rejection/.test(document.querySelector('#node-details')?.textContent || '')) {
            return true;
          }
          await new Promise((resolve) => setTimeout(resolve, 25));
        }
        return false;
      } finally {
        embed.renderElements = original;
      }
    });
    assert(forcedApplyFailure, 'The forced cached iframe renderElements rejection was not observed.');
    const afterFailedApply = await dynamicFailureSnapshot(page);
    assert(JSON.stringify(afterFailedApply.visibleTypes) === JSON.stringify(beforeFailedApply.visibleTypes), 'Failed Legend Apply did not restore the previous parent node filter map.', { beforeFailedApply, afterFailedApply });
    assert(JSON.stringify(afterFailedApply.visibleRelations) === JSON.stringify(beforeFailedApply.visibleRelations), 'Failed Legend Apply did not restore the previous parent relation filter map.', { beforeFailedApply, afterFailedApply });
    assert(afterFailedApply.minPmids === beforeFailedApply.minPmids, 'Failed Legend Apply did not restore the previous parent Min PMID.', { beforeFailedApply, afterFailedApply });
    assert(afterFailedApply.url === beforeFailedApply.url, 'Failed Legend Apply changed the committed URL.', { beforeFailedApply, afterFailedApply });
    assert(JSON.stringify(afterFailedApply.visibleIds) === JSON.stringify(beforeFailedApply.visibleIds), 'Failed Legend Apply left a partially filtered iframe subgraph visible.', {
      beforeVisibleCount: beforeFailedApply.visibleIds.length,
      afterVisibleCount: afterFailedApply.visibleIds.length,
      missingIds: beforeFailedApply.visibleIds.filter((id) => !afterFailedApply.visibleIds.includes(id)),
      unexpectedIds: afterFailedApply.visibleIds.filter((id) => !beforeFailedApply.visibleIds.includes(id)),
    });

    await selectLegendTab(page, 'entity');
    nodeChecks = page.locator('#graph-legend-list .graph-legend-check[data-type]');
    assert(await nodeChecks.count() >= 2, 'The L1HS graph needs at least two node types for the applied-list test.');
    const urlBeforePendingNodeChange = page.url();
    await nodeChecks.last().uncheck();
    assert(page.url() === urlBeforePendingNodeChange, 'A temporary node checkbox change updated the URL before Apply.');
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page);
    await selectLegendTab(page, 'entity');
    filters = await visibleFilterSnapshot(page);
    assert(filters.checkedNodes.length === filters.nodeCatalog.length, 'A checkbox draft that was never applied survived refresh.', filters);

    nodeChecks = page.locator('#graph-legend-list .graph-legend-check[data-type]');
    await nodeChecks.last().uncheck();
    await applyFilters(page);
    filters = await visibleFilterSnapshot(page);
    assert(JSON.stringify(commaList(filters.nodes)) === JSON.stringify(sorted(filters.checkedNodes)), 'nodes must contain exactly the applied node identifiers.', filters);
    assert(filters.relations === null, 'Changing node filters serialized an unchanged all-applied relation filter.', filters);
    const expectedPartialNodes = commaList(filters.nodes);

    await page.goBack({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page);
    await selectLegendTab(page, 'entity');
    filters = await visibleFilterSnapshot(page);
    assert(filters.nodes === null && filters.checkedNodes.length === filters.nodeCatalog.length, 'Browser Back did not restore the default applied node filter.', filters);
    await page.goForward({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page);
    await selectLegendTab(page, 'entity');
    filters = await visibleFilterSnapshot(page);
    assert(JSON.stringify(sorted(filters.checkedNodes)) === JSON.stringify(expectedPartialNodes), 'Browser Forward did not restore the applied node filter.', filters);

    await selectLegendTab(page, 'relation');
    let relationChecks = page.locator('#graph-legend-list .graph-legend-check[data-relation]');
    assert(await relationChecks.count() >= 2, 'The L1HS graph needs at least two relation types for the applied-list test.');
    const urlBeforePendingRelationChange = page.url();
    await relationChecks.last().uncheck();
    assert(page.url() === urlBeforePendingRelationChange, 'A temporary relation checkbox change updated the URL before Apply.');
    await applyFilters(page);
    filters = await visibleFilterSnapshot(page);
    assert(JSON.stringify(commaList(filters.relations)) === JSON.stringify(sorted(filters.checkedRelations)), 'relations must contain exactly the applied relation identifiers.', filters);

    await selectLegendTab(page, 'entity');
    await setAllChecks(page, false);
    await applyFilters(page);
    filters = await visibleFilterSnapshot(page);
    assert(filters.nodes === 'none', 'An applied empty node selection must serialize nodes=none.', filters);

    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page, 'L1HS', false);
    await selectLegendTab(page, 'entity');
    filters = await visibleFilterSnapshot(page);
    assert(filters.nodes === 'none' && filters.nodeCatalog.length > 0 && filters.checkedNodes.length === 0, 'Refresh did not restore nodes=none.', filters);

    await setAllChecks(page, true);
    await applyFilters(page);
    filters = await visibleFilterSnapshot(page);
    assert(filters.nodes === null, 'Returning to all applied node types must omit nodes.', filters);

    await selectLegendTab(page, 'relation');
    await setAllChecks(page, false);
    await applyFilters(page);
    filters = await visibleFilterSnapshot(page);
    assert(filters.relations === 'none', 'An applied empty relation selection must serialize relations=none.', filters);

    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page);
    await selectLegendTab(page, 'relation');
    filters = await visibleFilterSnapshot(page);
    assert(filters.relations === 'none' && filters.relationCatalog.length > 0 && filters.checkedRelations.length === 0, 'Refresh did not restore relations=none.', filters);

    await setAllChecks(page, true);
    await page.fill('#graph-relation-min-pmids', '2');
    await page.locator('#graph-relation-min-pmids').dispatchEvent('change');
    await applyFilters(page);
    filters = await visibleFilterSnapshot(page);
    assert(filters.relations === null && filters.minPmids === '2', 'All relations must be omitted while nonzero Min PMID persists independently.', filters);

    await selectLegendTab(page, 'entity');
    nodeChecks = page.locator('#graph-legend-list .graph-legend-check[data-type]');
    await nodeChecks.last().uncheck();
    await applyFilters(page);
    await selectLegendTab(page, 'relation');
    relationChecks = page.locator('#graph-legend-list .graph-legend-check[data-relation]');
    await relationChecks.last().uncheck();
    await applyFilters(page);
    filters = await visibleFilterSnapshot(page);
    const expectedNodes = commaList(filters.nodes);
    const expectedRelations = commaList(filters.relations);
    assert(expectedNodes?.length && expectedRelations?.length, 'Could not prepare known applied filters for restoration.', filters);

    const restoreUrl = new URL(page.url());
    restoreUrl.searchParams.set('nodes', `${expectedNodes.join(',')},UNKNOWN_NODE_TYPE`);
    restoreUrl.searchParams.set('relations', `${expectedRelations.join(',')},UNKNOWN_RELATION_TYPE`);
    await page.goto(restoreUrl.href, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page);
    await selectLegendTab(page, 'entity');
    let restored = await visibleFilterSnapshot(page);
    assert(JSON.stringify(sorted(restored.checkedNodes)) === JSON.stringify(expectedNodes), 'Refresh did not restore known node filters after the node legend loaded.', restored);
    assert(!restored.nodeCatalog.includes('UNKNOWN_NODE_TYPE'), 'An unknown node identifier was added to the legend catalog.', restored);
    await selectLegendTab(page, 'relation');
    restored = await visibleFilterSnapshot(page);
    assert(JSON.stringify(sorted(restored.checkedRelations)) === JSON.stringify(expectedRelations), 'Refresh did not restore known relation filters after the relation legend loaded.', restored);
    assert(!restored.relationCatalog.includes('UNKNOWN_RELATION_TYPE'), 'An unknown relation identifier was added to the legend catalog.', restored);
    assert(restored.minInput === '2', 'Refresh did not restore Min PMID.', restored);

    await page.fill('#graph-relation-min-pmids', '0');
    await page.locator('#graph-relation-min-pmids').dispatchEvent('change');
    await applyFilters(page);
    restored = await visibleFilterSnapshot(page);
    assert(restored.minPmids === null, 'Min PMID 0 must be omitted from the URL.', restored);

    const unknownOnlyUrl = new URL(page.url());
    unknownOnlyUrl.searchParams.set('nodes', 'UNKNOWN_NODE_TYPE');
    unknownOnlyUrl.searchParams.delete('relations');
    await page.goto(unknownOnlyUrl.href, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page);
    await selectLegendTab(page, 'entity');
    restored = await visibleFilterSnapshot(page);
    assert(restored.nodeCatalog.length > 0 && restored.checkedNodes.length === restored.nodeCatalog.length, 'An unknown-only node list did not fall back to all applied.', restored);

    assert(errors.length === 0, 'Browser console errors were reported.', errors);
    console.log('PASS: Graph phase 2/4 route-state browser check');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.stack || error.message || String(error));
  process.exit(1);
});
