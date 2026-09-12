'use strict';
// Exercise the real controller with a minimal DOM and iframe bridge, without network requests.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const elements = new Map();
function element(id) {
  if (elements.has(id)) return elements.get(id);
  const e = { hidden: false, disabled: false, value: '', attrs: {}, handlers: {}, inputs: [],
    classList: { toggle() {}, remove() {} },
    setAttribute(k, v) { this.attrs[k] = v; }, getAttribute(k) { return this.attrs[k]; },
    addEventListener(k, fn) { this.handlers[k] = fn; },
    fire(k = 'click') { this.handlers[k]?.(); },
    querySelectorAll() { return this.inputs; }, querySelector() { return null; },
    set innerHTML(html) {
      this.inputs = [...html.matchAll(/data-gene="([^"]+)"\s*(checked)?/g)].map((m, i) => {
        const input = element('input-' + i); input.attrs['data-gene'] = m[1]; input.checked = !!m[2]; return input;
      });
    },
  };
  elements.set(id, e); return e;
}
const root = element('previewCoexpressionWorkspace');
const panel = element('gene-enrichment-panel');
const header = element('result-header');
panel.querySelector = () => header;
panel.style = {};
panel.getBoundingClientRect = () => ({ left:20, top:20, width:400 });
header.setPointerCapture = () => {};
const panes = ['legend', 'enrichment'].map(name => { const e = element(name); e.attrs['data-enrichment-pane'] = name; return e; });
root.querySelectorAll = () => panes;
let nodes = [{id:'a',label:'TP53',nodeType:'Gene'}, {id:'b',label:'BRCA1',nodeType:'Gene'}, {id:'te',label:'L1HS',nodeType:'TE'}];
let selection = {feature:'L1HS',context:'normal_tissue'};
let visual;
const api = { getVisibleSubgraph: () => ({nodes}), getDiagnostics: () => ({selection}),
  setEnrichmentSelection: state => { visual = state; return Promise.resolve(); } };
const host = {};
const document = { getElementById: element, querySelectorAll: () => [],
  querySelector: () => ({contentWindow:{__TEKG_COEXPRESSION_EMBED:api}}) };
const window = {__TEKG_COEXPRESSION_GRAPH_HOST:host,setTimeout:fn=>fn(),addEventListener() {},innerWidth:1000,innerHeight:800};
vm.runInNewContext(fs.readFileSync('assets/js/pages/preview/gene-enrichment.js','utf8').replace('  function rows()', '  window.testPlot = enrichmentPlot;\n  function rows()'), {
  document, window, MutationObserver: class {observe() {}}, Set, Map, Promise,
});
const get = suffix => element('coexpression-' + suffix);
host.onNonblank({});
host.onSelection(nodes[0]);
assert.equal(get('gene-selection-count').textContent,'Genes selected: 0','normal inspection must not select');
panes[1].fire(); get('gene-select').fire(); host.onSelection(nodes[0]);
assert.equal(visual.active,true);
assert.equal(visual.nodeIds.join(','),'a');
assert.equal(get('gene-list').inputs[0].checked,true,'canvas selection checks list');
host.onSelection(nodes[0]); assert.equal(visual.nodeIds.length,0,'second click deselects');
const input = get('gene-list').inputs[1]; input.checked=true; input.fire('change');
assert.equal(visual.nodeIds.join(','),'b','list selection updates renderer');
get('gene-select-all').fire(); assert.equal(visual.nodeIds.join(','),'a,b','All selects only genes');
nodes=nodes.filter(n=>n.id!=='b'); host.onNonblank({});
assert.equal(visual.nodeIds.join(','),'a','filter prunes hidden genes');
selection={feature:'L1PA2',context:'normal_tissue'}; host.onNonblank({});
assert.equal(visual.nodeIds.length,0,'new query clears selection');
assert.equal(visual.active,false);
get('gene-select-all').fire(); get('gene-clear').fire();
assert.equal(visual.nodeIds.length,0); assert.equal(get('run-enrichment').disabled,true);
get('gene-select').fire(); panes[0].fire(); assert.equal(visual.active,false,'legend page exits selection mode');
header.handlers.pointerdown({button:0,pointerId:1,clientX:30,clientY:30,target:{closest:()=>null},preventDefault(){}});
header.handlers.pointermove({pointerId:1,clientX:210,clientY:160});
assert.equal(panel.style.left,'200px'); assert.equal(panel.style.top,'150px');
header.handlers.pointerup();
header.handlers.pointermove({pointerId:1,clientX:400,clientY:300});
assert.equal(panel.style.left,'200px','pointerup ends dragging');
console.log('Gene enrichment selection: PASS');

const plot = window.testPlot([{Description:'Term A',Count:4,'p.adjust':0.01},{Description:'Term B',Count:16,'p.adjust':0.0001}]);
assert.equal((plot.match(/<circle /g)||[]).length,2);
assert.ok(plot.includes('cx="400"')); assert.ok(plot.includes('cx="530"'));
assert.ok(plot.includes('r="8"')); assert.ok(plot.includes('r="16"'));
assert.ok(plot.includes('−log10(p.adjust)'));
console.log('Enrichment dot plot coordinates and count area: PASS');
