const { chromium } = require('playwright');

const base = String(process.env.TEKG_BASE_URL || 'http://127.0.0.1/tedee').replace(/\/$/, '');

function assert(condition, message, details = null) {
  if (!condition) throw new Error(`${message}${details ? `\n${JSON.stringify(details, null, 2)}` : ''}`);
}

function normalized(value) {
  return String(value || '').trim().toLowerCase();
}

async function waitForDynamic(page, query) {
  const expected = normalized(query);
  await page.waitForFunction((target) => {
    const bridge = window.__TEKG_G6_BRIDGE;
    const state = bridge?.getState?.() || {};
    const frame = document.querySelector('#g6-dynamic-surface iframe');
    const embed = frame?.contentWindow?.__TEKG_G6_EMBED;
    const visible = embed?.getVisibleSubgraph?.() || {};
    const routeQuery = new URL(window.location.href).searchParams.get('q') || '';
    return state.mode === 'dynamic'
      && String(state.query || '').trim().toLowerCase() === target
      && String(visible.query || embed?.getCurrentQuery?.() || '').trim().toLowerCase() === target
      && String(routeQuery).trim().toLowerCase() === target
      && Array.isArray(state.currentElements)
      && state.currentElements.length > 0
      && Number(visible.counts?.nodes || visible.nodes?.length || 0) > 0
      && !document.querySelector('#graph-loader')?.classList.contains('is-visible')
      && !document.querySelector('#graph-preloader')?.classList.contains('is-visible');
  }, expected, { timeout: 30000 });
}

async function navigationSnapshot(page) {
  return page.evaluate(() => {
    const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
    const frame = document.querySelector('#g6-dynamic-surface iframe');
    const embed = frame?.contentWindow?.__TEKG_G6_EMBED;
    const visible = embed?.getVisibleSubgraph?.() || {};
    const elementIds = (items) => (Array.isArray(items) ? items : [])
      .map((item) => String(item?.data?.id || item?.id || ''))
      .filter(Boolean)
      .sort();
    const visibleIds = [
      ...(Array.isArray(visible.nodes) ? visible.nodes : []),
      ...(Array.isArray(visible.edges) ? visible.edges : []),
    ].map((item) => String(item?.id || '')).filter(Boolean).sort();
    return {
      urlQuery: new URL(window.location.href).searchParams.get('q') || '',
      urlType: new URL(window.location.href).searchParams.get('type') || '',
      parentQuery: state.query || '',
      parentType: state.queryType || '',
      searchQuery: document.querySelector('#node-search')?.value || '',
      iframeQuery: visible.query || embed?.getCurrentQuery?.() || '',
      parentIds: elementIds(state.currentElements),
      visibleIds,
      parentCount: Array.isArray(state.currentElements) ? state.currentElements.length : 0,
      visibleCount: visibleIds.length,
      backText: document.querySelector('#back-text')?.textContent || '',
      backHidden: document.querySelector('#back-graph')?.hidden === true,
      sharedBackCount: window.__TEKG_PREVIEW_WORKSPACE_MODE?.getDiagnostics?.().sharedBackHistory?.length || 0,
    };
  });
}

function assertCommitted(snapshot, query, previousSnapshot = null) {
  const expected = normalized(query);
  const identities = {
    urlQuery: snapshot.urlQuery,
    parentQuery: snapshot.parentQuery,
    searchQuery: snapshot.searchQuery,
    iframeQuery: snapshot.iframeQuery,
  };
  assert(
    Object.values(identities).every((value) => normalized(value) === expected),
    `URL, parent state, controls, and iframe do not all identify ${query}.`,
    identities,
  );
  assert(normalized(snapshot.urlType) === 'te' && normalized(snapshot.parentType) === 'te', `The committed type for ${query} is not TE.`, snapshot);
  assert(snapshot.parentCount > 0 && snapshot.visibleCount > 0, `The committed payload for ${query} is empty.`, snapshot);
  if (previousSnapshot) {
    assert(
      JSON.stringify(snapshot.parentIds) !== JSON.stringify(previousSnapshot.parentIds),
      `The parent cache did not change when navigating to ${query}.`,
      { previousCount: previousSnapshot.parentCount, nextCount: snapshot.parentCount },
    );
  }
}

async function jumpTo(page, query) {
  const frame = await (await page.locator('#g6-dynamic-surface iframe').elementHandle()).contentFrame();
  assert(frame, 'Knowledge Graph iframe is missing.');
  const target = normalized(query);
  const result = await frame.evaluate(async (expected) => {
    const graph = window.__TEKG_G6_EMBED?.getVisibleSubgraph?.() || {};
    const node = (graph.nodes || []).find((item) => String(item.label || item.rawLabel || item.displayLabel || '').trim().toLowerCase() === expected);
    if (!node) return null;
    const handled = await window.__TEKG_G6_EMBED.triggerNodeAction(node.id, 'jump');
    return { handled, id: node.id, label: node.label || node.rawLabel || node.displayLabel };
  }, target);
  assert(result?.handled === true, `No Jump target for ${query} was handled.`, result);
  await waitForDynamic(page, query);
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
    await page.goto(`${base}/preview.php?q=LINE1&type=TE`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page, 'LINE1');
    const line1 = await navigationSnapshot(page);
    assertCommitted(line1, 'LINE1');

    await jumpTo(page, 'L1HS');
    const l1hs = await navigationSnapshot(page);
    const jumpFailures = [];
    for (const [owner, value] of Object.entries({
      url: l1hs.urlQuery,
      parent: l1hs.parentQuery,
      search: l1hs.searchQuery,
      iframe: l1hs.iframeQuery,
    })) {
      if (normalized(value) !== 'l1hs') jumpFailures.push(`${owner} still identifies ${value || '(empty)'}`);
    }
    if (JSON.stringify(l1hs.parentIds) === JSON.stringify(line1.parentIds)) {
      jumpFailures.push(`parent currentElements still has the LINE1 cache (${l1hs.parentCount} elements)`);
    }
    assert(jumpFailures.length === 0, 'Jump did not commit one canonical L1HS state.', {
      failures: jumpFailures,
      before: { query: line1.parentQuery, elements: line1.parentCount },
      after: { query: l1hs.parentQuery, elements: l1hs.parentCount },
    });
    assertCommitted(l1hs, 'L1HS');
    assert(normalized(l1hs.backText) === 'back to line1' && !l1hs.backHidden, 'Page Back does not describe the previous committed entity.', l1hs);

    const apply = page.locator('#graph-legend-apply');
    const checkbox = page.locator('#graph-legend-list .graph-legend-check[data-type="Disease"]').first();
    if (await checkbox.count()) {
      await checkbox.uncheck();
      await apply.click();
      await page.waitForTimeout(250);
      await waitForDynamic(page, 'L1HS');
      const filtered = await navigationSnapshot(page);
      assertCommitted(filtered, 'L1HS');
      assert(JSON.stringify(filtered.parentIds) === JSON.stringify(l1hs.parentIds), 'Legend Apply replaced the committed L1HS cache.', {
        before: l1hs.parentCount,
        after: filtered.parentCount,
      });
      await checkbox.check();
      await apply.click();
    }

    await page.click('#graph-legend-relation-tab');
    await page.locator('#graph-relation-min-pmids').fill('1');
    await page.locator('#graph-relation-min-pmids').dispatchEvent('change');
    await apply.click();
    await waitForDynamic(page, 'L1HS');
    const minPmidFiltered = await navigationSnapshot(page);
    assertCommitted(minPmidFiltered, 'L1HS');
    assert(JSON.stringify(minPmidFiltered.parentIds) === JSON.stringify(l1hs.parentIds), 'Min PMID filtering replaced the committed L1HS cache.');
    const exportPayload = await page.evaluate(() => window.__TEKG_G6_EXPORT.exportCsv({ download: false }));
    assert(normalized(exportPayload?.query) === 'l1hs', 'Export metadata does not identify the committed L1HS graph.', exportPayload);
    await page.locator('#graph-relation-min-pmids').fill('0');
    await page.locator('#graph-relation-min-pmids').dispatchEvent('change');
    await apply.click();

    await page.goto(`${base}/preview.php?q=LINE1&type=TE`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page, 'LINE1');
    await jumpTo(page, 'L1HS');
    await page.click('#back-graph');
    await waitForDynamic(page, 'LINE1');
    const pageBack = await navigationSnapshot(page);
    assertCommitted(pageBack, 'LINE1');

    await jumpTo(page, 'L1HS');
    await page.goBack({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page, 'LINE1');
    const browserBack = await navigationSnapshot(page);
    assertCommitted(browserBack, 'LINE1');
    assert(browserBack.backHidden, 'Browser Back left stale page-level Graph history visible.', browserBack);
    assert(browserBack.sharedBackCount === 0, 'Browser Back left stale cross-mode entity history.', browserBack);

    await page.goForward({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitForDynamic(page, 'L1HS');
    const browserForward = await navigationSnapshot(page);
    assertCommitted(browserForward, 'L1HS');
    assert(browserForward.backHidden, 'Browser Forward rebuilt stale page-level Graph history.', browserForward);
    assert(browserForward.sharedBackCount === 0, 'Browser Forward rebuilt stale cross-mode entity history.', browserForward);
    assert(errors.length === 0, 'Browser console errors were reported.', errors);

    const stableBeforeFailure = browserForward;
    await page.route('**/api/graph.php*', async (route) => {
      const url = new URL(route.request().url());
      if (normalized(url.searchParams.get('q')) === 'forced_navigation_failure') {
        await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false }) });
        return;
      }
      await route.continue();
    });
    const failedNavigation = await page.evaluate(async () => {
      try {
        await window.__TEKG_G6_BRIDGE.navigateGraph({ query: 'forced_navigation_failure', queryType: 'TE' }, { history: 'push' });
        return false;
      } catch (_error) {
        return true;
      }
    });
    assert(failedNavigation, 'The forced navigation failure did not reject.');
    await waitForDynamic(page, 'L1HS');
    const stableAfterFailure = await navigationSnapshot(page);
    assertCommitted(stableAfterFailure, 'L1HS');
    assert(JSON.stringify(stableAfterFailure.parentIds) === JSON.stringify(stableBeforeFailure.parentIds), 'A failed navigation replaced the last stable payload cache.');
    await page.unroute('**/api/graph.php*');

    await page.evaluate(() => window.__TEKG_G6_BRIDGE.showTree());
    await page.waitForFunction(() => {
      const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
      return state.mode === 'tree'
        && getComputedStyle(document.querySelector('#g6-default-tree-surface')).display !== 'none'
        && getComputedStyle(document.querySelector('#g6-dynamic-surface')).display === 'none';
    });
    await page.route('**/api/graph.php*', async (route) => {
      const url = new URL(route.request().url());
      if (normalized(url.searchParams.get('q')) === 'forced_tree_failure') {
        await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false }) });
        return;
      }
      await route.continue();
    });
    await page.evaluate(async () => {
      try {
        await window.__TEKG_G6_BRIDGE.navigateGraph({ query: 'forced_tree_failure', queryType: 'TE' }, { history: 'push' });
      } catch (_error) {}
    });
    await page.waitForFunction(() => {
      const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
      return state.mode === 'tree'
        && getComputedStyle(document.querySelector('#g6-default-tree-surface')).display !== 'none'
        && getComputedStyle(document.querySelector('#g6-dynamic-surface')).display === 'none';
    });
    await page.unroute('**/api/graph.php*');

    await Promise.allSettled([
      page.evaluate(() => window.__TEKG_G6_BRIDGE.navigateGraph({ query: 'LINE1', queryType: 'TE' }, { history: 'none' })),
      page.evaluate(() => window.__TEKG_G6_BRIDGE.navigateGraph({ query: 'L1HS', queryType: 'TE' }, { history: 'replace' })),
    ]);
    await waitForDynamic(page, 'L1HS');
    const rapidFinal = await navigationSnapshot(page);
    assertCommitted(rapidFinal, 'L1HS');

    const dynamicFrame = await (await page.locator('#g6-dynamic-surface iframe').elementHandle()).contentFrame();
    const expanded = await dynamicFrame.evaluate(async () => {
      const graph = window.__TEKG_G6_EMBED?.getVisibleSubgraph?.() || {};
      const node = (graph.nodes || []).find((item) => String(item.label || item.rawLabel || '').trim().toLowerCase() !== 'l1hs');
      return node ? window.__TEKG_G6_EMBED.triggerNodeAction(node.id, 'expand') : false;
    });
    assert(expanded === true, 'No L1HS neighbor could be expanded for the canonical-cache regression.');
    await waitForDynamic(page, 'L1HS');
    const expandedState = await navigationSnapshot(page);
    assertCommitted(expandedState, 'L1HS');
    assert(rapidFinal.parentIds.every((id) => expandedState.parentIds.includes(id)), 'Expand discarded elements from the committed L1HS cache.');

    const diseaseJump = await dynamicFrame.evaluate(async () => {
      const graph = window.__TEKG_G6_EMBED?.getVisibleSubgraph?.() || {};
      const node = (graph.nodes || []).find((item) => String(item.nodeType || item.type || '').toLowerCase() === 'disease');
      if (!node) return null;
      const label = String(node.queryLabel || node.rawLabel || node.label || node.displayLabel || '').trim();
      const handled = await window.__TEKG_G6_EMBED.triggerNodeAction(node.id, 'jump');
      return { handled, label };
    });
    assert(diseaseJump?.handled === true && diseaseJump.label, 'No Disease Jump target was available in the expanded L1HS graph.', diseaseJump);
    await waitForDynamic(page, diseaseJump.label);
    const diseaseState = await navigationSnapshot(page);
    assert(normalized(diseaseState.urlType) === 'disease' && normalized(diseaseState.parentType) === 'disease', 'A non-TE Jump did not preserve its entity type.', diseaseState);

    await page.evaluate(() => window.__TEKG_G6_BRIDGE.navigateGraph({ query: 'LINE1', queryType: 'TE' }, { history: 'replace' }));
    await waitForDynamic(page, 'LINE1');
    await jumpTo(page, 'L1HS');
    await page.route('**/api/graph.php*', async (route) => {
      const url = new URL(route.request().url());
      if (normalized(url.searchParams.get('q')) === 'hervk') {
        await new Promise((resolve) => setTimeout(resolve, 1800));
      }
      await route.continue();
    });
    await page.evaluate(() => {
      void window.__TEKG_G6_BRIDGE.navigateGraph({ query: 'HERVK', queryType: 'TE' }, { history: 'push' });
    });
    await page.waitForFunction(() => document.querySelector('#graph-preloader')?.classList.contains('is-visible'));
    const loadingFilterState = await page.evaluate(() => ({
      applyDisabled: document.querySelector('#graph-legend-apply')?.disabled === true,
      minPmidsDisabled: document.querySelector('#graph-relation-min-pmids')?.disabled === true,
      legendInputsDisabled: [...document.querySelectorAll('#graph-legend-list input')].every((input) => input.disabled),
    }));
    assert(
      loadingFilterState.applyDisabled && loadingFilterState.minPmidsDisabled && loadingFilterState.legendInputsDisabled,
      'Cache-backed legend controls remained interactive during semantic navigation.',
      loadingFilterState,
    );
    await page.click('#back-graph');
    await waitForDynamic(page, 'LINE1');
    await page.waitForTimeout(2200);
    const backDuringLoad = await navigationSnapshot(page);
    assertCommitted(backDuringLoad, 'LINE1');
    await page.unroute('**/api/graph.php*');

    console.log('PASS: canonical Graph Jump/cache/URL/history browser check');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.stack || error.message || String(error));
  process.exit(1);
});
