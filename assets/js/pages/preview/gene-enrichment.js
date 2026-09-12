(function () {
  'use strict';
  var root = document.getElementById('previewCoexpressionWorkspace');
  if (!root) return;
  var byId = function (id) { return document.getElementById(id); };
  var els = {
    count: byId('coexpression-gene-selection-count'), list: byId('coexpression-gene-list'), toggle: byId('coexpression-gene-list-toggle'),
    all: byId('coexpression-gene-select-all'), clear: byId('coexpression-gene-clear'), run: byId('coexpression-run-enrichment'), provider: byId('coexpression-enrichment-provider'), select: byId('coexpression-gene-select'), listPanel: byId('coexpression-gene-list-panel'),
    panel: byId('gene-enrichment-panel'), status: byId('gene-enrichment-status'), table: byId('gene-enrichment-table'), plot: byId('gene-enrichment-plot'),
    tabs: Array.prototype.slice.call(document.querySelectorAll('[data-enrichment-tab]')), top: byId('gene-enrichment-top'), csv: byId('gene-enrichment-csv'), close: byId('gene-enrichment-close'), minimize: byId('gene-enrichment-minimize'), page: byId('gene-enrichment-pagination')
  };
  var selecting = false; var networkKey = null; var selected = new Set(); var genes = []; var result = null; var tab = 'go'; var page = 1; var requestId = 0;
  function text(value) { return String(value == null ? '' : value); }
  function esc(value) { return text(value).replace(/[&<>\"]/g, function (c) { return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '\"':'&quot;' })[c]; }); }
  function bridge() { var frame = document.querySelector('#coexpression-iframe-host iframe'); return frame && frame.contentWindow && frame.contentWindow.__TEKG_COEXPRESSION_EMBED; }
  function visibleGenes() { var sub = bridge() && bridge().getVisibleSubgraph ? bridge().getVisibleSubgraph() : {}; var map = new Map(); (sub.nodes || []).filter(function (n) { return text(n.kind || n.feature_type || n.nodeType).toLowerCase() === 'gene'; }).forEach(function (n) { var name = text(n.label || n.displayLabel || n.id).trim(); if (name && !map.has(name.toLowerCase())) map.set(name.toLowerCase(), name); }); return Array.from(map.values()); }
  function pushSelection() {
    var api = bridge(); if (!api || !api.setEnrichmentSelection) return;
    var sub = api.getVisibleSubgraph();
    var ids = (sub.nodes || []).filter(function(n) { return text(n.kind || n.feature_type || n.nodeType).toLowerCase() === 'gene' && selected.has(text(n.label || n.displayLabel || n.id).trim()); }).map(function(n) { return n.id; });
    Promise.resolve(api.setEnrichmentSelection({ active: selecting && !root.hidden, nodeIds: ids })).catch(function(error) { els.status.textContent = error.message; });
  }
  function sync() { fitGeneList(); pushSelection(); els.select.setAttribute('aria-pressed', String(selecting)); if (els.count) els.count.textContent = 'Genes selected: ' + selected.size; if (els.run) els.run.disabled = selected.size < 1; }
  function renderList() { genes = visibleGenes(); Array.from(selected).forEach(function (s) { if (!genes.some(function (g) { return g.toLowerCase() === s.toLowerCase(); })) selected.delete(s); }); if (els.list) { els.list.innerHTML = genes.length ? genes.map(function (g) { var checked = Array.from(selected).some(function (s) { return s.toLowerCase() === g.toLowerCase(); }); return '<label class="graph-legend-item"><input class="graph-legend-check" type="checkbox" data-gene="' + esc(g) + '" ' + (checked ? 'checked' : '') + '><span class="graph-legend-swatch coexpression-legend-gene" aria-hidden="true"></span><span class="graph-legend-text" title="' + esc(g) + '">' + esc(g) + '</span></label>'; }).join('') : '<span>No visible Gene nodes</span>'; Array.prototype.forEach.call(els.list.querySelectorAll('input[data-gene]'), function (input) { input.addEventListener('change', function () { var g = input.getAttribute('data-gene'); if (input.checked) selected.add(g); else Array.from(selected).forEach(function (s) { if (s.toLowerCase() === g.toLowerCase()) selected.delete(s); }); sync(); }); }); } sync(); }

  function enrichmentPlot(items) {
    if (!items.length) return '';
    var valid = items.filter(function(r) { return Number.isFinite(Number(r['p.adjust'])) && Number(r['p.adjust']) >= 0 && Number(r['p.adjust']) <= 1; });
    var max = Math.max(1, ...valid.map(function(r) { return -Math.log10(Math.max(Number(r['p.adjust']), 1e-300)); }));
    var maxCount = Math.max(1, ...valid.map(function(r) { return Number(r.Count) || 0; }));
    var height = valid.length * 54 + 110;
    var svg = '<svg viewBox="0 0 560 ' + height + '" role="img" aria-label="Enrichment dot plot: position and color show minus log10 adjusted p-value; area shows gene count"><text x="12" y="24" font-size="16" font-weight="700">' + tab.toUpperCase() + ' Enrichment</text>';
    for (var t=0;t<=4;t++) { var x=270+t*65; svg += '<path d="M'+x+' 40 V'+(height-65)+'" stroke="#e2e8f0"/><text x="'+x+'" y="'+(height-44)+'" text-anchor="middle">'+(max*t/4).toFixed(1)+'</text>'; }
    valid.forEach(function(r,i) {
      var value=-Math.log10(Math.max(Number(r['p.adjust']),1e-300)), y=65+i*54, ratio=value/max;
      var color='hsl('+(270-220*ratio)+',60%,'+(38+20*ratio)+'%)';
      var title=esc(r.Description)+'; p.adjust='+esc(r['p.adjust'])+'; Count='+esc(r.Count);
      var label=text(r.Description), lines=[];
      while(label.length>30 && lines.length<2) { var cut=label.lastIndexOf(' ',30); if(cut<10) cut=30; lines.push(label.slice(0,cut)); label=label.slice(cut).trim(); }
      lines.push(label.length>30?label.slice(0,27)+'…':label);
      svg += '<path d="M270 '+y+' H530" stroke="#eef2f6"/><text x="258" y="'+(y-(lines.length-1)*7)+'" text-anchor="end"><title>'+title+'</title>'+lines.map(function(line,j){return '<tspan x="258" dy="'+(j?14:0)+'">'+esc(line)+'</tspan>';}).join('')+'</text><circle cx="'+(270+260*ratio)+'" cy="'+y+'" r="'+(16*Math.sqrt(Math.max(1,Number(r.Count)||0)/maxCount))+'" fill="'+color+'"><title>'+title+'</title></circle>';
    });
    return svg+'<text x="400" y="'+(height-22)+'" text-anchor="middle">−log10(p.adjust)</text><text x="12" y="'+(height-4)+'">Point area: Count · Color: −log10(p.adjust), purple → yellow</text></svg>';
  }
  function fitGeneList() {
    if (els.listPanel.hidden || !root.getBoundingClientRect) return;
    var surface=root.querySelector('.g6-surface-stack'); if(!surface) return;
    var bounds=surface.getBoundingClientRect(), panel=els.listPanel.getBoundingClientRect();
    var top=Math.max(0,bounds.top)+24;
    els.listPanel.style.maxHeight=Math.max(120,Math.min(window.innerHeight-20,panel.bottom)-top)+'px';
  }
  window.addEventListener('resize',fitGeneList);
  window.addEventListener('scroll',fitGeneList,true);
  if (typeof ResizeObserver !== 'undefined') new ResizeObserver(fitGeneList).observe(root);
  ['nw','ne','sw','se'].forEach(function(corner) {
    var handle=els.panel.querySelector('[data-enrichment-resize="'+corner+'"]'); if(!handle) return;
    var resizing=null;
    handle.addEventListener('pointerdown',function(e) {
      if(e.button!==0 || els.panel.classList.contains('is-minimized')) return;
      resizing={rect:els.panel.getBoundingClientRect(),x:e.clientX,y:e.clientY,id:e.pointerId}; handle.setPointerCapture(e.pointerId); e.preventDefault();
    });
    handle.addEventListener('pointermove',function(e) {
      if(!resizing || resizing.id!==e.pointerId) return;
      var r=resizing.rect, dx=e.clientX-resizing.x,dy=e.clientY-resizing.y;
      var left=corner.includes('w')?Math.max(0,Math.min(r.left+dx,r.right-360)):r.left;
      var top=corner.includes('n')?Math.max(0,Math.min(r.top+dy,r.bottom-220)):r.top;
      var right=corner.includes('e')?Math.min(window.innerWidth,Math.max(r.right+dx,left+360)):r.right;
      var bottom=corner.includes('s')?Math.min(window.innerHeight,Math.max(r.bottom+dy,top+220)):r.bottom;
      Object.assign(els.panel.style,{left:left+'px',top:top+'px',width:(right-left)+'px',height:(bottom-top)+'px',right:'auto',bottom:'auto'});
    });
    ['pointerup','pointercancel','lostpointercapture'].forEach(function(type){handle.addEventListener(type,function(){resizing=null;});});
  });

  function rows() { return result && Array.isArray(result[tab]) ? result[tab] : []; }
  // Pointer capture keeps dragging reliable across the graph iframe.
  var dragHeader = els.panel.querySelector('.gene-enrichment-head');
  if (dragHeader) {
    var drag = null;
    function moveResult(left, top) {
      var rect = els.panel.getBoundingClientRect();
      els.panel.style.left = Math.max(0, Math.min(left, window.innerWidth - rect.width)) + 'px';
      els.panel.style.top = Math.max(0, Math.min(top, window.innerHeight - 48)) + 'px';
      els.panel.style.right = 'auto';
      els.panel.style.bottom = 'auto';
    }
    dragHeader.addEventListener('pointerdown', function(event) {
      if (event.button !== 0 || event.target.closest('button')) return;
      var rect = els.panel.getBoundingClientRect();
      drag = { id: event.pointerId, x: event.clientX - rect.left, y: event.clientY - rect.top };
      dragHeader.setPointerCapture(event.pointerId);
      event.preventDefault();
    });
    dragHeader.addEventListener('pointermove', function(event) {
      if (!drag || drag.id !== event.pointerId) return;
      moveResult(event.clientX - drag.x, event.clientY - drag.y);
    });
    function endDrag() { drag = null; }
    dragHeader.addEventListener('pointerup', endDrag);
    dragHeader.addEventListener('pointercancel', endDrag);
    dragHeader.addEventListener('lostpointercapture', endDrag);
    window.addEventListener('resize', function() {
      if (els.panel.hidden || !els.panel.style.left) return;
      var rect = els.panel.getBoundingClientRect(); moveResult(rect.left, rect.top);
    });
  }
  function renderResult() { var all = rows(); var limit = els.top.value === 'all' ? all.length : Number(els.top.value); var shown = all.slice(0, limit); var per = 10; var pages = Math.max(1, Math.ceil(shown.length / per)); page = Math.min(page, pages); var chunk = shown.slice((page - 1) * per, page * per); els.table.innerHTML = chunk.length ? '<table><thead><tr><th>ID</th><th>Description</th><th>p.adjust</th><th>Count</th><th>Gene</th></tr></thead><tbody>' + chunk.map(function (r) { return '<tr><td>' + esc(r.ID) + '</td><td title="' + esc(r.Description) + '">' + esc(r.Description) + '</td><td>' + Number(r['p.adjust'] || 0).toExponential(2) + '</td><td>' + esc(r.Count) + '</td><td>' + esc(r.Gene) + '</td></tr>'; }).join('') + '</tbody></table>' : '<div class="gene-enrichment-empty">No enrichment terms returned.</div>'; els.plot.innerHTML = enrichmentPlot(chunk); els.page.innerHTML = pages > 1 ? '<button type="button" data-page="prev" ' + (page === 1 ? 'disabled' : '') + '>Prev</button><span>' + page + ' / ' + pages + '</span><button type="button" data-page="next" ' + (page === pages ? 'disabled' : '') + '>Next</button>' : ''; var prev = els.page.querySelector('[data-page="prev"]'); var next = els.page.querySelector('[data-page="next"]'); if (prev) prev.addEventListener('click', function () { page -= 1; renderResult(); }); if (next) next.addEventListener('click', function () { page += 1; renderResult(); }); }
  function run() { var id = ++requestId; els.panel.hidden = false; els.panel.classList.remove('is-minimized'); result = null; els.csv.disabled = true; els.table.innerHTML = ''; els.plot.innerHTML = ''; els.page.innerHTML = ''; els.status.textContent = 'Running enrichment...'; var provider = els.provider.value; var body = { provider: provider, genes: Array.from(selected) }; fetch('api/gene_enrichment.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json().then(function (p) { return { ok: r.ok, payload: p }; }); }).then(function (x) { if (id !== requestId) return; if (!x.ok || x.payload.success !== true) throw new Error(x.payload.error || 'Enrichment failed'); result = x.payload; els.csv.disabled = false; page = 1; els.status.textContent = 'Human · ' + (provider === 'string' ? 'STRING' : 'Enrichr') + ' · ' + body.genes.length + ' genes'; renderResult(); }).catch(function (error) { if (id !== requestId) return; els.status.textContent = error.message || 'Enrichment failed'; els.table.innerHTML = ''; els.plot.innerHTML = ''; }); }
  els.toggle.addEventListener('click', function () { els.listPanel.hidden = !els.listPanel.hidden; els.toggle.setAttribute('aria-expanded', String(!els.listPanel.hidden)); renderList(); fitGeneList(); });
  els.select.addEventListener('click', function() { selecting = !selecting; sync(); });
  Array.prototype.forEach.call(root.querySelectorAll('[data-enrichment-pane]'), function(button) {
    button.addEventListener('click', function() {
      var enrichment = button.getAttribute('data-enrichment-pane') === 'enrichment';
      byId('coexpression-legend-pane').hidden = enrichment;
      byId('coexpression-enrichment-pane').hidden = !enrichment;
      root.querySelectorAll('[data-enrichment-pane]').forEach(function(tabButton) { var active = tabButton === button; tabButton.classList.toggle('is-active', active); tabButton.setAttribute('aria-pressed', String(active)); });
      if (!enrichment) { selecting = false; els.listPanel.hidden = true; els.toggle.setAttribute('aria-expanded','false'); }
      renderList();
    });
  });
  new MutationObserver(function() { if (root.hidden) selecting = false; sync(); }).observe(root, { attributes:true, attributeFilter:['hidden'] });
  els.all.addEventListener('click', function () { visibleGenes().forEach(function (g) { selected.add(g); }); renderList(); }); els.clear.addEventListener('click', function () { selected.clear(); selecting = false; renderList(); }); els.run.addEventListener('click', run); els.top.addEventListener('change', function () { page = 1; renderResult(); });
  els.tabs.forEach(function (button) { button.addEventListener('click', function () { tab = button.getAttribute('data-enrichment-tab'); els.tabs.forEach(function (b) { b.classList.toggle('is-active', b === button); }); page = 1; renderResult(); }); });
  els.close.addEventListener('click', function () { els.panel.hidden = true; }); els.minimize.addEventListener('click', function () { els.panel.classList.toggle('is-minimized'); });
  els.csv.addEventListener('click', function () { var csv = [['ID','Description','p.adjust','Count','Gene']].concat(rows().map(function (r) { return [r.ID,r.Description,r['p.adjust'],r.Count,r.Gene]; })).map(function (r) { return r.map(function (v) { return '"' + text(v).replace(/"/g, '""') + '"'; }).join(','); }).join('\n'); var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = 'gene-enrichment-' + tab + '.csv'; a.click(); URL.revokeObjectURL(a.href); });
  var host = window.__TEKG_COEXPRESSION_GRAPH_HOST; if (host) { var oldSelection = host.onSelection; host.onSelection = function (node) { if (oldSelection) oldSelection(node); var type = text(node && (node.kind || node.nodeType || node.coexpressionFeatureType)).toLowerCase(); if (!selecting || type !== 'gene') return; var name = text(node.label || node.displayLabel || node.rawLabel || node.id).trim(); var existing = Array.from(selected).find(function (s) { return s.toLowerCase() === name.toLowerCase(); }); if (existing) selected.delete(existing); else selected.add(name); renderList(); }; var oldBlank = host.onNonblank; host.onNonblank = function (report) { if (oldBlank) oldBlank(report);
    var api = bridge(); var key = JSON.stringify(api && api.getDiagnostics ? api.getDiagnostics().selection : null);
    if (key !== networkKey) { selected.clear(); selecting = false; networkKey = key; }
    window.setTimeout(renderList, 0); }; }
  els.csv.disabled = true; window.setTimeout(renderList, 800); sync();
}());
