const { chromium } = require('playwright');

const base = String(process.env.TEKG_BASE_URL || 'http://127.0.0.1/tedee').replace(/\/$/, '');

function assert(condition, message, details = null) {
  if (!condition) throw new Error(`${message}${details ? `\n${JSON.stringify(details, null, 2)}` : ''}`);
}

function sse(events) {
  return events.map((event) => `data: ${JSON.stringify(event)}\n\n`).join('');
}

const answer = 'L1HS is associated with colorectal cancer in the available TE-Dee evidence.';
const graphEvidence = {
  graph_elements: {
    nodes: [
      { id: 'te-l1hs', label: 'L1HS', type: 'TE' },
      { id: 'disease-crc', label: 'Colorectal cancer', type: 'Disease' },
      { id: 'disease-cancer', label: 'Cancer', type: 'Disease' },
      { id: 'gene-tp53', label: 'TP53', type: 'Gene' },
    ],
    edges: [
      { id: 'edge-disease', source: 'te-l1hs', target: 'disease-crc', relation: 'associate_with' },
      { id: 'edge-generic-cancer', source: 'te-l1hs', target: 'disease-cancer', relation: 'associate_with' },
      { id: 'edge-gene', source: 'te-l1hs', target: 'gene-tp53', relation: 'target' },
    ],
  },
};

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 960 } });
  try {
    await page.route('**/api/deep_think_stream.php', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'text/event-stream; charset=utf-8',
        body: sse([
          { type: 'stage_state', payload: { current_stage: 'Executing' } },
          {
            type: 'tool_result',
            plugin_name: 'Sequence Plugin',
            payload: {
              graph_elements: {
                nodes: [
                  { id: 'sequence-l1hs', label: 'L1HS', type: 'TE' },
                  { id: 'sequence-orf1', label: 'ORF1', type: 'Sequence feature' },
                ],
                edges: [
                  { id: 'edge-sequence', source: 'sequence-l1hs', target: 'sequence-orf1', relation: 'contains' },
                ],
              },
            },
          },
          { type: 'tool_result', plugin_name: 'Graph Plugin', payload: graphEvidence },
          { type: 'answer', message: answer },
          { type: 'done', payload: { answer, failed: false } },
        ]),
      });
    });

    await page.goto(`${base}/preview.php?q=L1HS&type=TE`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForFunction(() => window.__TEKG_G6_BRIDGE?.getState?.().mode === 'dynamic', null, { timeout: 30000 });
    await page.evaluate(() => {
      window.__answerGraphCalls = { apply: [], ensure: 0 };
      const bridge = window.__TEKG_G6_BRIDGE;
      const workspace = window.__TEKG_PREVIEW_WORKSPACE_MODE;
      bridge.applyAnswerGraph = async (payload) => {
        window.__answerGraphCalls.apply.push(payload);
        return true;
      };
      workspace.ensureKnowledgeForGraphAction = async () => {
        window.__answerGraphCalls.ensure += 1;
        return true;
      };
    });

    await page.evaluate(() => window.__TEKG_PREVIEW_SHELL.openAssistant());
    await page.waitForFunction(() => document.querySelector('#qaOverlay')?.classList.contains('is-open'));
    await page.fill('#previewDeepThinkInput', 'Which diseases are associated with L1HS?');
    await page.click('#previewDeepThinkSubmit');
    await page.waitForFunction((expected) => {
      const text = document.querySelector('#previewDeepThinkMessages')?.textContent || '';
      return text.includes(expected);
    }, answer, { timeout: 15000 });

    const beforeClick = await page.evaluate(() => ({
      calls: window.__answerGraphCalls,
      buttonText: document.querySelector('[data-answer-graph-action="view"]')?.textContent?.trim() || '',
      summary: document.querySelector('[data-answer-graph-summary]')?.textContent?.trim() || '',
    }));
    assert(beforeClick.calls.apply.length === 0, 'A Graph tool_result must not automatically replace the current Graph.', beforeClick);
    assert(beforeClick.calls.ensure === 0, 'A Graph tool_result must not automatically switch to Knowledge Graph mode.', beforeClick);
    assert(beforeClick.buttonText === '', 'View answer graph must remain hidden while entity recognition is under review.', beforeClick);
    assert(beforeClick.summary === '', 'Answer-subgraph counts must remain hidden with the action.', beforeClick);
    const afterAnswer = await page.evaluate(() => window.__answerGraphCalls);
    assert(afterAnswer.apply.length === 0 && afterAnswer.ensure === 0, 'A completed answer must not drive Graph while the feature is hidden.', afterAnswer);

    console.log('PASS: Graph answer-subgraph action is hidden and does not drive Graph.');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.stack || String(error));
  process.exit(1);
});
