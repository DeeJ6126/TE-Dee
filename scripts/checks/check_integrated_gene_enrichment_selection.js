'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const elements = new Map();
function element(id) {
  if (elements.has(id)) return elements.get(id);
  const value = {
    id, hidden: false, disabled: false, value: '', attrs: {}, handlers: {}, inputs: [], style: {}, html: '',
    classList: { toggle() {}, remove() {} },
    setAttribute(name, next) { this.attrs[name] = next; },
    getAttribute(name) { return this.attrs[name]; },
    addEventListener(name, handler) { this.handlers[name] = handler; },
    fire(name = 'click') { this.handlers[name]?.(); },
    querySelectorAll() { return this.inputs; },
    querySelector() { return null; },
    getBoundingClientRect() { return { top: 100, bottom: 500 }; },
    set innerHTML(html) {
      this.html = html;
      this.inputs = [...html.matchAll(/data-gene="([^"]+)"\s*(checked)?/g)].map((match, index) => {
        const input = element('gene-input-' + index);
        input.attrs['data-gene'] = match[1];
        input.checked = !!match[2];
        return input;
      });
    },
    get innerHTML() { return this.html; },
  };
  elements.set(id, value);
  return value;
}

const root = element('previewIntegratedWorkspace');
const surface = element('integrated-surface');
root.querySelector = selector => selector === '.g6-surface-stack' ? surface : null;
const entityTab = element('entity-tab'); entityTab.attrs['data-enrichment-pane'] = 'legend';
const relationTab = element('relation-tab'); relationTab.attrs['data-enrichment-pane'] = 'legend';
const enrichmentTab = element('enrichment-tab'); enrichmentTab.attrs['data-enrichment-pane'] = 'enrichment';
const panes = [entityTab, relationTab, enrichmentTab];
root.querySelectorAll = selector => selector === '[data-enrichment-pane]' ? panes : [];

const nodes = [
  { id: 'tp53', label: 'TP53', nodeType: 'Gene', source_graphs: ['knowledge', 'te_gene'] },
  { id: 'brca1', label: 'BRCA1', nodeType: 'Gene', source_graphs: ['knowledge'] },
  { id: 'egfr', label: 'EGFR', nodeType: 'Gene', source_graphs: ['te_gene'] },
  { id: 'l1hs', label: 'L1HS', nodeType: 'TE', source_graphs: ['knowledge', 'te_gene'] },
];
let visual = null;
const bridge = {
  getVisibleSubgraph: () => ({ nodes }),
  getDiagnostics: () => ({ renderCount: 1 }),
  setEnrichmentSelection: state => { visual = state; return Promise.resolve(); },
};
const host = { onSelection() {}, onNonblank() {} };
let submitted = null;
const window = {
  __TEKG_GENE_ENRICHMENT: { run: (genes, provider) => { submitted = { genes, provider }; } },
  __TEKG_INTEGRATED_GRAPH_HOST: host,
  addEventListener() {}, setTimeout: handler => handler(), innerHeight: 900,
};
const document = {
  getElementById: element,
  querySelector: selector => selector === '#integrated-iframe-host iframe' ? { contentWindow: { __TEKG_INTEGRATED_EMBED: bridge } } : null,
};

vm.runInNewContext(fs.readFileSync('assets/js/pages/preview/integrated-gene-enrichment.js', 'utf8'), {
  document, window, Set, Map, Promise,
  ResizeObserver: class { observe() {} },
  MutationObserver: class { observe() {} },
});

host.onNonblank({});
enrichmentTab.fire();
const list = element('integrated-gene-list');
assert.ok(list.html.includes('Knowledge Graph'));
assert.ok(list.html.includes('TE-Gene Graph'));
assert.ok(list.html.includes('enrichment-gene-source-divider'));
assert.equal((list.html.match(/title="TP53"/g) || []).length, 2, 'a merged Gene appears in both provenance groups');

element('integrated-gene-select').fire();
host.onSelection(nodes[0]);
assert.equal(element('integrated-gene-selection-count').textContent, 'Genes selected: 1');
assert.deepEqual(Array.from(visual.nodeIds), ['tp53']);
assert.equal(list.inputs.filter(input => input.attrs['data-gene'] === 'tp53').every(input => input.checked), true);

element('integrated-gene-select-all').fire();
assert.equal(element('integrated-gene-selection-count').textContent, 'Genes selected: 3');
element('integrated-enrichment-provider').value = 'enrichr';
element('integrated-run-enrichment').fire();
assert.equal(submitted.provider, 'enrichr');
assert.deepEqual(Array.from(submitted.genes), ['BRCA1', 'EGFR', 'TP53']);
console.log('Integrated gene enrichment selection: PASS');
