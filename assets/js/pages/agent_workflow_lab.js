(function () {
  const state = {
    spec: null,
    selectedNodeId: null,
    drag: null,
    nodeContracts: {},
  };

  const els = {
    canvas: document.getElementById('workflowCanvas'),
    edges: document.getElementById('workflowEdges'),
    detail: document.getElementById('workflowDetail'),
    routes: document.getElementById('workflowRoutes'),
    contracts: document.getElementById('workflowContracts'),
    editor: document.getElementById('workflowEditor'),
    status: document.getElementById('workflowStatus'),
    reload: document.getElementById('workflowReload'),
    save: document.getElementById('workflowSave'),
    apply: document.getElementById('workflowApply'),
  };

  function setStatus(text) {
    if (els.status) {
      els.status.textContent = text;
    }
  }

  async function loadSpec() {
    setStatus('Loading workflow spec...');
    const response = await fetch(window.__TEKG_PATHS.apiUrl('agent_workflow_lab.php'), { credentials: 'same-origin' });
    const payload = await response.json();
    if (!payload.ok) {
      throw new Error(payload.error || 'Failed to load workflow spec.');
    }
    state.spec = payload.spec || {};
    state.nodeContracts = payload.node_contracts || {};
    renderAll();
    setStatus('Spec loaded.');
  }

  function renderAll() {
    renderCanvas();
    renderEditor();
    renderRoutes();
    renderContracts();
    renderDetail();
  }

  function renderCanvas() {
    if (!els.canvas || !els.edges || !state.spec) {
      return;
    }
    els.canvas.innerHTML = '';
    els.edges.innerHTML = '';
    ensureArrowDefs();

    const nodes = Array.isArray(state.spec.nodes) ? state.spec.nodes : [];
    const edges = Array.isArray(state.spec.edges) ? state.spec.edges : [];
    const lookup = new Map(nodes.map((node) => [Number(node.id), node]));

    edges.forEach((edge) => {
      const from = lookup.get(Number(edge.from));
      const to = lookup.get(Number(edge.to));
      if (!from || !to) {
        return;
      }
      const fromX = Number(from.position?.x || 0) + 210;
      const fromY = Number(from.position?.y || 0) + 46;
      const toX = Number(to.position?.x || 0);
      const toY = Number(to.position?.y || 0) + 46;
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      const midX = (fromX + toX) / 2;
      path.setAttribute('d', `M ${fromX} ${fromY} C ${midX} ${fromY}, ${midX} ${toY}, ${toX} ${toY}`);
      path.setAttribute('fill', 'none');
      path.setAttribute('stroke', '#90a8cb');
      path.setAttribute('stroke-width', '2');
      path.setAttribute('marker-end', 'url(#workflowArrow)');
      els.edges.appendChild(path);

      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      text.setAttribute('x', String(midX));
      text.setAttribute('y', String((fromY + toY) / 2 - 6));
      text.setAttribute('text-anchor', 'middle');
      text.setAttribute('class', 'workflow-lab__edge-label');
      text.textContent = edge.label || '';
      els.edges.appendChild(text);
    });

    nodes.forEach((node) => {
      const element = document.createElement('button');
      element.type = 'button';
      element.className = 'workflow-lab__node' + (Number(node.id) === Number(state.selectedNodeId) ? ' is-selected' : '');
      element.dataset.id = String(node.id);
      element.dataset.kind = String(node.kind || 'core');
      element.style.left = `${Number(node.position?.x || 0)}px`;
      element.style.top = `${Number(node.position?.y || 0)}px`;
      element.innerHTML = `
        <span class="workflow-lab__node-id">${node.id}</span>
        <h4 class="workflow-lab__node-name">${escapeHtml(node.name || '')}</h4>
        <p class="workflow-lab__node-kind">${escapeHtml(node.kind || '')}</p>
        <p class="workflow-lab__node-kind">${escapeHtml(node.model || '')}</p>
      `;
      element.addEventListener('click', () => {
        state.selectedNodeId = Number(node.id);
        renderCanvas();
        renderDetail();
      });
      element.addEventListener('pointerdown', (event) => startDrag(event, node));
      els.canvas.appendChild(element);
    });
  }

  function ensureArrowDefs() {
    if (!els.edges) {
      return;
    }
    const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
    const marker = document.createElementNS('http://www.w3.org/2000/svg', 'marker');
    marker.setAttribute('id', 'workflowArrow');
    marker.setAttribute('viewBox', '0 0 10 10');
    marker.setAttribute('refX', '9');
    marker.setAttribute('refY', '5');
    marker.setAttribute('markerWidth', '8');
    marker.setAttribute('markerHeight', '8');
    marker.setAttribute('markerUnits', 'strokeWidth');
    marker.setAttribute('orient', 'auto');

    const arrowPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    arrowPath.setAttribute('d', 'M 0 0 L 10 5 L 0 10 z');
    arrowPath.setAttribute('fill', '#90a8cb');
    marker.appendChild(arrowPath);
    defs.appendChild(marker);
    els.edges.appendChild(defs);
  }

  function renderDetail() {
    if (!els.detail || !state.spec) {
      return;
    }
    const nodes = Array.isArray(state.spec.nodes) ? state.spec.nodes : [];
    const edges = Array.isArray(state.spec.edges) ? state.spec.edges : [];
    const node = nodes.find((item) => Number(item.id) === Number(state.selectedNodeId)) || nodes[0];
    if (!node) {
      els.detail.innerHTML = '<p>No nodes defined.</p>';
      return;
    }
    state.selectedNodeId = Number(node.id);
    const incoming = edges.filter((edge) => Number(edge.to) === Number(node.id));
    const outgoing = edges.filter((edge) => Number(edge.from) === Number(node.id));

    els.detail.innerHTML = `
      <h4>${escapeHtml(String(node.id))}. ${escapeHtml(node.name || '')}</h4>
      <p><strong>Kind:</strong> ${escapeHtml(node.kind || '')}</p>
      <p><strong>Model:</strong> ${escapeHtml(node.model || 'not assigned')}</p>
      <p>${escapeHtml(node.description || '')}</p>
      <div class="workflow-lab__detail-code"><strong>Inputs</strong>\n${escapeJson(node.inputs || [])}</div>
      <div class="workflow-lab__detail-code"><strong>Outputs</strong>\n${escapeJson(node.outputs || [])}</div>
      <div class="workflow-lab__detail-code"><strong>Incoming</strong>\n${escapeJson(incoming)}</div>
      <div class="workflow-lab__detail-code"><strong>Outgoing</strong>\n${escapeJson(outgoing)}</div>
    `;
  }

  function renderRoutes() {
    if (!els.routes || !state.spec) {
      return;
    }
    const routes = Array.isArray(state.spec.routing_examples) ? state.spec.routing_examples : [];
    els.routes.innerHTML = routes.map((route) => `
      <div class="workflow-lab__route">
        <div class="workflow-lab__route-title">${escapeHtml(route.question_type || 'route')}</div>
        <div class="workflow-lab__route-seq">${escapeHtml((route.route || []).join(' -> '))}</div>
      </div>
    `).join('');
  }

  function renderContracts() {
    if (!els.contracts) {
      return;
    }
    els.contracts.textContent = JSON.stringify(state.nodeContracts || {}, null, 2);
  }

  function renderEditor() {
    if (!els.editor || !state.spec) {
      return;
    }
    els.editor.value = JSON.stringify(state.spec, null, 2);
  }

  function applyFromEditor() {
    if (!els.editor) {
      return;
    }
    try {
      state.spec = JSON.parse(els.editor.value);
      renderAll();
      setStatus('Editor JSON applied locally.');
    } catch (error) {
      setStatus(`Invalid JSON: ${error.message}`);
    }
  }

  async function saveSpec() {
    if (!state.spec) {
      return;
    }
    setStatus('Saving workflow spec...');
    const response = await fetch(window.__TEKG_PATHS.apiUrl('agent_workflow_lab.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ spec: state.spec }),
    });
    const payload = await response.json();
    if (!payload.ok) {
      throw new Error(payload.error || 'Failed to save workflow spec.');
    }
    renderEditor();
    setStatus(`Saved to ${payload.path}`);
  }

  function startDrag(event, node) {
    const target = event.currentTarget;
    if (!(target instanceof HTMLElement)) {
      return;
    }
    const rect = target.getBoundingClientRect();
    state.drag = {
      id: Number(node.id),
      offsetX: event.clientX - rect.left,
      offsetY: event.clientY - rect.top,
    };
    target.setPointerCapture(event.pointerId);
  }

  function handlePointerMove(event) {
    if (!state.drag || !state.spec || !els.canvas) {
      return;
    }
    const canvasRect = els.canvas.getBoundingClientRect();
    const node = (state.spec.nodes || []).find((item) => Number(item.id) === Number(state.drag.id));
    if (!node) {
      return;
    }
    const x = Math.max(20, Math.round(event.clientX - canvasRect.left - state.drag.offsetX));
    const y = Math.max(20, Math.round(event.clientY - canvasRect.top - state.drag.offsetY));
    node.position = { x, y };
    renderCanvas();
    renderEditor();
    renderDetail();
  }

  function handlePointerUp() {
    if (!state.drag) {
      return;
    }
    state.drag = null;
    setStatus('Node position updated locally. Save if you want to persist it.');
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function escapeJson(value) {
    return escapeHtml(JSON.stringify(value, null, 2));
  }

  if (els.reload) {
    els.reload.addEventListener('click', () => {
      loadSpec().catch((error) => setStatus(error.message));
    });
  }
  if (els.apply) {
    els.apply.addEventListener('click', applyFromEditor);
  }
  if (els.save) {
    els.save.addEventListener('click', () => {
      saveSpec().catch((error) => setStatus(error.message));
    });
  }

  window.addEventListener('pointermove', handlePointerMove);
  window.addEventListener('pointerup', handlePointerUp);

  loadSpec().catch((error) => setStatus(error.message));
})();
