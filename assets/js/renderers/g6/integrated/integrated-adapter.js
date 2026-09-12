(function (root, factory) {
  const api = factory();
  if (root) root.__TEKG_INTEGRATED_ADAPTER = api;
  if (typeof module === 'object' && module.exports) module.exports = api;
}(typeof globalThis === 'object' ? globalThis : null, function () {
  'use strict';
  function toRendererElements(elements) {
    return (Array.isArray(elements) ? elements : []).map(function (element) {
      return { data: { ...(element && element.data ? element.data : {}) } };
    });
  }
  function split(elements) {
    const normalized = toRendererElements(elements);
    return {
      nodes: normalized.filter(function (element) { return !element.data.source; }),
      edges: normalized.filter(function (element) { return !!element.data.source; }),
    };
  }
  return { toRendererElements, split };
}));
