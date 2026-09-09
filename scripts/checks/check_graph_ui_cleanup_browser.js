const { chromium } = require('playwright');

const base = String(process.env.TEKG_BASE_URL || 'http://127.0.0.1/tedee').replace(/\/$/, '');

function assert(condition, message, details = null) {
  if (!condition) throw new Error(`${message}${details ? `\n${JSON.stringify(details, null, 2)}` : ''}`);
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
    await page.goto(`${base}/preview.php`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForFunction(() => window.__TEKG_G6_EXPORT && window.__TEKG_G6_BRIDGE?.getState?.().mode === 'tree', null, { timeout: 30000 });
    await page.waitForFunction(() => !document.querySelector('#export-menu-toggle')?.disabled, null, { timeout: 30000 });

    const tree = await page.evaluate(async () => {
      const csv = await window.__TEKG_G6_EXPORT.exportCsv({ download: false });
      const png = await window.__TEKG_G6_EXPORT.exportPng({ download: false });
      const svg = await window.__TEKG_G6_EXPORT.exportSvg({ download: false });
      return {
        counts: csv.counts,
        png: String(png.dataUrl || '').slice(0, 22),
        pngLength: String(png.dataUrl || '').length,
        svg: String(svg.svg || '').slice(0, 4),
      };
    });
    assert(tree.counts.nodes > 0 && tree.counts.edges > 0, 'Tree CSV export is empty.', tree);
    assert(tree.png.startsWith('data:image/png') && tree.pngLength > 5000, 'Tree PNG export is invalid or blank.', tree);
    assert(tree.svg === '<svg', 'Tree SVG export is invalid.', tree);

    await page.click('#preview-taxonomy-display-graph');
    await page.waitForFunction(() => window.__TEKG_G6_BRIDGE?.getState?.().mode === 'taxonomy_graph' && !document.querySelector('#export-menu-toggle')?.disabled, null, { timeout: 30000 });
    const graph = await page.evaluate(async () => {
      const csv = await window.__TEKG_G6_EXPORT.exportCsv({ download: false });
      const png = await window.__TEKG_G6_EXPORT.exportPng({ download: false });
      const svg = await window.__TEKG_G6_EXPORT.exportSvg({ download: false });
      return {
        counts: csv.counts,
        png: String(png.dataUrl || '').slice(0, 22),
        pngLength: String(png.dataUrl || '').length,
        svg: String(svg.svg || '').slice(0, 4),
      };
    });
    assert(graph.counts.nodes > 0 && graph.counts.edges > 0, 'Taxonomy Graph CSV export is empty.', graph);
    assert(graph.png.startsWith('data:image/png') && graph.pngLength > 5000, 'Taxonomy Graph PNG export is invalid or blank.', graph);
    assert(graph.svg === '<svg', 'Taxonomy Graph SVG export is invalid.', graph);

    await page.goto(`${base}/preview.php`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForFunction(() => window.__TEKG_G6_BRIDGE?.getState?.().mode === 'tree', null, { timeout: 30000 });
    await page.fill('#node-search', 'L1HS');
    await page.click('#graph-search-submit');
    await page.waitForFunction(() => {
      const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
      return state.mode === 'dynamic'
        && String(state.query || '').toLowerCase() === 'l1hs'
        && Array.isArray(state.currentElements)
        && state.currentElements.length > 0
        && !document.querySelector('#graph-loader')?.classList.contains('is-visible');
    }, null, { timeout: 30000 });
    const taxonomyBackKnowledge = await page.evaluate(() => ({
      text: document.querySelector('#back-text')?.textContent || '',
      hidden: document.querySelector('#back-graph')?.hidden,
    }));
    assert(taxonomyBackKnowledge.text === 'Back to taxonomy' && taxonomyBackKnowledge.hidden === false, 'Knowledge Graph does not expose Back to taxonomy.', taxonomyBackKnowledge);
    await page.click('#preview-mode-coexpression');
    await page.waitForFunction(() => ['ready', 'empty', 'unavailable'].includes(window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.().state), null, { timeout: 30000 });
    const taxonomyBackCoexpression = await page.evaluate(() => ({
      text: document.querySelector('#back-text')?.textContent || '',
      hidden: document.querySelector('#back-graph')?.hidden,
      adjacentToExpression: document.querySelector('#back-graph')?.nextElementSibling?.id === 'coexpression-expression-layer',
    }));
    assert(taxonomyBackCoexpression.text === 'Back to taxonomy' && taxonomyBackCoexpression.hidden === false && taxonomyBackCoexpression.adjacentToExpression, 'Co-expression does not preserve Back to taxonomy to the left of Expression activity.', taxonomyBackCoexpression);
    await page.click('#back-graph');
    await page.waitForFunction(() => window.__TEKG_PREVIEW_WORKSPACE_MODE?.getMode?.() === 'knowledge' && window.__TEKG_G6_BRIDGE?.getState?.().mode === 'tree', null, { timeout: 30000 });

    await page.click('#qaFab');
    await page.waitForFunction(() => !document.querySelector('#qaOverlay').classList.contains('is-open'));
    await page.click('#qaFab');
    await page.waitForFunction(() => document.querySelector('#qaOverlay').classList.contains('is-open'));
    const assistant = await page.evaluate(() => {
      const fab = document.querySelector('#qaFab').getBoundingClientRect();
      const drawer = document.querySelector('#qaDrawer').getBoundingClientRect();
      const messages = document.querySelector('#previewDeepThinkMessages');
      return {
        fabRight: fab.right,
        drawerLeft: drawer.left,
        overlap: fab.right > drawer.left && fab.left < drawer.right && fab.bottom > drawer.top && fab.top < drawer.bottom,
        userSelect: getComputedStyle(messages).userSelect,
      };
    });
    assert(!assistant.overlap && assistant.fabRight <= assistant.drawerLeft, 'AI FAB overlaps the open drawer.', assistant);
    assert(assistant.userSelect === 'text', 'AI messages are not selectable.', assistant);

    await page.goto(`${base}/preview.php?q=LINE1&type=TE`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForFunction(() => {
      const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
      return state.mode === 'dynamic'
        && String(state.query || '').toLowerCase() === 'line1'
        && Array.isArray(state.currentElements)
        && state.currentElements.length > 0
        && document.querySelector('#g6-dynamic-surface iframe')
        && !document.querySelector('#graph-loader')?.classList.contains('is-visible');
    }, null, { timeout: 30000 });
    const jumpFrame = await (await page.locator('#g6-dynamic-surface iframe').elementHandle()).contentFrame();
    assert(jumpFrame, 'Knowledge Graph iframe was not created for the Back workflow.');
    await jumpFrame.waitForFunction(() => window.__TEKG_G6_EMBED?.getVisibleSubgraph?.().counts?.nodes > 0, null, { timeout: 30000 });
    await jumpFrame.waitForFunction(() => !document.querySelector('#graph-preloader')?.classList.contains('is-visible'), null, { timeout: 30000 });
    const jumpTarget = await jumpFrame.evaluate(async () => {
      const graph = window.__TEKG_G6_EMBED.getVisibleSubgraph();
      const node = graph.nodes.find((item) => String(item.label || item.rawLabel).toLowerCase() === 'l1hs');
      if (!node) return null;
      await window.__TEKG_G6_EMBED.triggerNodeAction(node.id, 'jump');
      return { id: node.id, label: node.label || node.rawLabel };
    });
    assert(jumpTarget, 'LINE1 graph does not expose L1HS for the real Jump workflow.');
    await page.waitForFunction(() => {
      const state = window.__TEKG_G6_BRIDGE?.getState?.() || {};
      return String(state.query || '').toLowerCase() === 'l1hs'
        && Array.isArray(state.currentElements)
        && state.currentElements.length > 0
        && new URL(window.location.href).searchParams.get('q')?.toLowerCase() === 'l1hs'
        && !document.querySelector('#graph-loader')?.classList.contains('is-visible');
    }, null, { timeout: 30000 });
    const knowledgeBack = await page.evaluate(() => ({
      text: document.querySelector('#back-text')?.textContent || '',
      hidden: document.querySelector('#back-graph')?.hidden,
      disabled: document.querySelector('#back-graph')?.disabled,
      inKnowledgeToolbar: !!document.querySelector('#previewGraphWorkspace .preview-graph-toolbar #back-graph'),
      canGoBack: window.__TEKG_G6_BRIDGE?.canGoBack?.() || false,
    }));
    assert(knowledgeBack.text === 'Back to LINE1' && knowledgeBack.hidden === false && knowledgeBack.disabled === false && knowledgeBack.inKnowledgeToolbar && knowledgeBack.canGoBack, 'Knowledge Graph Back control is missing after a real node Jump.', knowledgeBack);
    await page.click('#preview-mode-coexpression');
    await page.waitForFunction(() => window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.().state === 'ready' && window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.().selection?.feature === 'L1HS', null, { timeout: 30000 });
    const history = await page.evaluate(() => ({
      text: document.querySelector('#back-text')?.textContent || '',
      hidden: document.querySelector('#back-graph')?.hidden,
      inCoexpressionToolbar: !!document.querySelector('#previewCoexpressionWorkspace .preview-graph-toolbar #back-graph'),
      depth: window.__TEKG_PREVIEW_WORKSPACE_MODE?.getDiagnostics?.().sharedBackHistory?.length || 0,
    }));
    assert(history.text === 'Back to LINE1' && history.hidden === false && history.inCoexpressionToolbar && history.depth >= 1, 'Shared Back control is incorrect after a real node Jump.', history);
    await page.click('#back-graph');
    await page.waitForFunction(() => {
      const diagnostics = window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.() || {};
      return ['ready', 'empty', 'unavailable'].includes(diagnostics.state)
        && diagnostics.selection?.feature === 'LINE1';
    }, null, { timeout: 30000 });

    await page.goto(`${base}/preview.php?q=L1HS&type=TE`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForFunction(() => window.__TEKG_G6_BRIDGE?.getState?.().mode === 'dynamic' && document.querySelector('#g6-dynamic-surface iframe'), null, { timeout: 30000 });
    const knowledgeFrame = await (await page.locator('#g6-dynamic-surface iframe').elementHandle()).contentFrame();
    assert(knowledgeFrame, 'Knowledge Graph iframe was not created.');
    await knowledgeFrame.waitForFunction(() => window.__TEKG_G6_EMBED?.getVisibleSubgraph?.().counts?.nodes > 0, null, { timeout: 30000 });
    const knowledgeCard = await knowledgeFrame.evaluate(async () => {
      const graph = window.__TEKG_G6_EMBED.getVisibleSubgraph();
      const node = graph.nodes.find((item) => String(item.label || item.rawLabel).toLowerCase() === 'l1hs') || graph.nodes[0];
      window.__TEKG_G6_EMBED.inspectNode(node.id);
      await window.__TEKG_G6_EMBED.triggerNodeAction(node.id, 'details');
      return document.querySelector('.inspect-card')?.textContent || '';
    });
    assert(!/Key node|Category level|tree_rmsk_repbase/.test(knowledgeCard), 'Knowledge node card exposes internal fields.', knowledgeCard);

    await page.evaluate(() => window.__TEKG_PREVIEW_WORKSPACE_MODE.setMode('coexpression', { te: 'L1HS', featureType: 'TE', context: 'cancer_cell_line', history: 'none' }));
    await page.waitForFunction(() => window.__TEKG_COEXPRESSION_MODE?.getDiagnostics?.().state === 'ready', null, { timeout: 30000 });
    const coexpressionFrame = await (await page.locator('#coexpression-iframe-host iframe').elementHandle()).contentFrame();
    assert(coexpressionFrame, 'Co-expression iframe was not created.');
    await coexpressionFrame.waitForFunction(() => window.__TEKG_COEXPRESSION_EMBED?.getVisibleSubgraph?.().counts?.nodes > 0, null, { timeout: 30000 });
    const coexpressionCards = await coexpressionFrame.evaluate(async () => {
      const graph = window.__TEKG_COEXPRESSION_EMBED.getVisibleSubgraph();
      const center = graph.nodes.find((item) => item.is_center === true) || graph.nodes[0];
      window.__TEKG_COEXPRESSION_EMBED.inspectNode(center.id);
      document.querySelector('.inspect-card [data-inspect-action="toggle"]')?.click();
      const nodeText = document.querySelector('.inspect-card')?.textContent || '';
      const edge = graph.edges[0];
      window.__TEKG_COEXPRESSION_EMBED.inspectEdge(edge.id);
      document.querySelector('.inspect-card [data-inspect-action="toggle"]')?.click();
      const edgeText = document.querySelector('.inspect-card')?.textContent || '';
      return { nodeText, edgeText };
    });
    assert(!/cancer_cell_line_M002|Confidence/.test(coexpressionCards.nodeText), 'Co-expression node card exposes module ID or confidence.', coexpressionCards.nodeText);
    assert(/Entities/.test(coexpressionCards.nodeText), 'Co-expression node card is missing the naturalized entity summary.', coexpressionCards.nodeText);
    assert(!/Edge role/.test(coexpressionCards.edgeText) && /Adjusted P value \(FDR\)/.test(coexpressionCards.edgeText), 'Co-expression edge card labels are incorrect.', coexpressionCards.edgeText);

    assert(errors.length === 0, 'Browser console errors were reported.', errors);
    console.log('PASS: Graph UI cleanup browser check');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.stack || error.message || String(error));
  process.exit(1);
});
