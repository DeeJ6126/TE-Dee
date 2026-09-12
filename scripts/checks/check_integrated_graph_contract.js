'use strict';
const assert = require('assert');
const contract = require('../../assets/js/renderers/g6/integrated/integrated-contract.js');
const knowledgeElements = [
  { data: { id: '4:te', label: 'L1HS', rawLabel: 'L1HS', type: 'TE', taxonomy: { canonical_name: 'L1HS' } } },
  { data: { id: '4:gene', label: 'IFI16', rawLabel: 'IFI16', type: 'Gene' } },
  { data: { id: '4:edge', source: '4:te', target: '4:gene', relation: 'regulates', relationType: 'REGULATES', pmids: ['1'] } },
];
const teGeneElements = [
  { data: { id: 'L1HS', label: 'L1HS', rawLabel: 'L1HS', type: 'TE', coexpression: true } },
  { data: { id: 'gene:IFI16', label: 'ifi16', rawLabel: 'ifi16', type: 'Gene', coexpression: true } },
  { data: { id: 'L1HS::gene:IFI16::0', source: 'L1HS', target: 'gene:IFI16', relation: 'Both', relationType: 'Both' } },
];
const merged = contract.mergeSourceElements({ knowledgeElements, teGeneElements });
const nodes = merged.filter(function (element) { return !element.data.source; });
const edges = merged.filter(function (element) { return !!element.data.source; });
assert.strictEqual(nodes.length, 2);
assert.strictEqual(edges.length, 2);
assert(nodes.every(function (node) { return node.data.integrated.sources.length === 2; }));
assert(edges.some(function (edge) { return edge.data.id.startsWith('kg-edge:'); }));
assert(edges.some(function (edge) { return edge.data.id.startsWith('te-gene-edge:'); }));
assert(edges.every(function (edge) { return edge.data.parallelCount === 2; }));
assert.deepStrictEqual(edges.map(function (edge) { return edge.data.parallelIndex; }), [0, 1]);
assert.strictEqual(contract.canonicalEntityKey({ type: 'Disease', label: 'Cancer' }), '');
assert.strictEqual(contract.canonicalEntityKey({ type: 'TE', label: 'alias', taxonomy: { canonical_name: ' L1HS ' } }), 'TE:l1hs');
console.log('Integrated graph merge contract: PASS');
