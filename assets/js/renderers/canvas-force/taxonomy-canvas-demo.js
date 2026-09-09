(function () {
  'use strict';

  const LEVEL_LABELS = ['Human TE', 'Class', 'Order', 'Superfamily', 'Family', 'Subfamily', 'Level 6', 'Level 7', 'Level 8'];
  const LEVEL_COLORS = ['#113f8c', '#1f66d1', '#2d8bdc', '#54a6db', '#86bfe8', '#b7d7f1', '#d4e6f7', '#e5eef8', '#eef5fb'];
  const TWO_PI = Math.PI * 2;
  const STAR_DEPTH = 2;

  const canvas = document.getElementById('taxonomy-canvas');
  const stage = canvas.parentElement;
  const ctx = canvas.getContext('2d');
  const sourceSelect = document.getElementById('taxonomy-source');
  const coreLabelsInput = document.getElementById('show-core-labels');
  const restartButton = document.getElementById('restart-layout');
  const fitButton = document.getElementById('fit-view');
  const legendEl = document.getElementById('taxonomy-legend');
  const statusEl = document.getElementById('taxonomy-status');
  const tooltipEl = document.getElementById('taxonomy-tooltip');

  const state = {
    nodes: [],
    edges: [],
    visibleLevels: new Set(),
    nodeById: new Map(),
    adjacency: new Map(),
    transform: { x: 0, y: 0, k: 1 },
    pointer: { x: 0, y: 0 },
    hoverId: null,
    selectedId: null,
    draggingNode: null,
    panning: false,
    lastPointer: null,
    alpha: 1,
    running: false,
    frame: 0,
    showCoreLabels: false,
  };

  function nodeId(name) {
    return `n_${String(name || '').trim().replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'node'}`;
  }

  function setStatus(text) {
    statusEl.textContent = text;
  }

  function levelLabel(depth) {
    return LEVEL_LABELS[depth] || `Level ${depth}`;
  }

  function levelColor(depth) {
    return LEVEL_COLORS[Math.min(Math.max(0, depth), LEVEL_COLORS.length - 1)] || '#94a3b8';
  }

  function parseTaxonomy(payload) {
    const rawNodes = Array.isArray(payload && payload.nodes) ? payload.nodes : [];
    const rawEdges = Array.isArray(payload && payload.edges) ? payload.edges : [];
    const maxDepth = rawNodes.reduce((m, n) => Math.max(m, Math.max(0, Number(n && n.depth) || 0)), 0);
    const children = new Map();
    const parentByChild = new Map();
    const depthByName = new Map();
    for (const raw of rawNodes) {
      const name = String(raw && raw.name || '').trim();
      if (name) depthByName.set(name, Math.max(0, Number(raw && raw.depth) || 0));
    }
    for (const edge of rawEdges) {
      const parent = String(edge && edge.parent || '').trim();
      const child = String(edge && edge.child || '').trim();
      if (!parent || !child) continue;
      if (!children.has(parent)) children.set(parent, []);
      children.get(parent).push(child);
      parentByChild.set(child, parent);
    }
    const branchIndex = new Map();
    const rootChildren = children.get('TE') || children.get('Human TE') || [];
    rootChildren.forEach((name, index) => branchIndex.set(name, index));
    const starNames = rawNodes
      .map((raw) => String(raw && raw.name || '').trim())
      .filter((name) => (depthByName.get(name) || 0) === STAR_DEPTH);
    const starIndex = new Map(starNames.map((name, index) => [name, index]));
    function ancestorAtDepth(name, depth) {
      let current = name;
      const seen = new Set();
      while (current && !seen.has(current)) {
        seen.add(current);
        if (depthByName.get(current) === depth) return current;
        current = parentByChild.get(current);
      }
      return '';
    }
    function topBranch(name) {
      let current = name;
      const seen = new Set();
      while (current && !seen.has(current)) {
        seen.add(current);
        if (branchIndex.has(current)) return branchIndex.get(current);
        current = parentByChild.get(current);
      }
      return 0;
    }

    const nodes = rawNodes.map((raw, index) => {
      const name = String(raw && raw.name || '').trim();
      const depth = Math.max(0, Number(raw && raw.depth) || 0);
      const childCount = (children.get(name) || []).length;
      const starName = depth <= STAR_DEPTH ? name : ancestorAtDepth(name, STAR_DEPTH);
      const branch = starIndex.has(starName) ? starIndex.get(starName) : topBranch(name);
      const starTotal = Math.max(1, starNames.length || rootChildren.length || 6);
      const starAngle = (branch / starTotal) * TWO_PI;
      const starRing = 290 + (branch % 4) * 105;
      const starX = Math.cos(starAngle) * starRing;
      const starY = Math.sin(starAngle) * starRing;
      const localAngle = ((index * 2.399963229728653) % TWO_PI) + Math.random() * 0.3;
      const localRadius = depth <= STAR_DEPTH ? 0 : 34 + (depth - STAR_DEPTH) * 25 + Math.random() * 38;
      const size = depth === 0
        ? 18
        : depth === STAR_DEPTH
          ? Math.max(8, 12 + Math.min(7, Math.sqrt(childCount + 1)))
          : Math.max(1.8, 7.2 - (depth - STAR_DEPTH) * 0.85 + Math.min(2.2, Math.sqrt(childCount + 1) * 0.25));
      return {
        id: nodeId(name),
        name,
        depth,
        childCount,
        degree: 0,
        branch,
        starName,
        starId: nodeId(starName || name),
        starX,
        starY,
        x: starX + Math.cos(localAngle) * localRadius,
        y: starY + Math.sin(localAngle) * localRadius,
        vx: 0,
        vy: 0,
        size,
        visible: true,
        color: levelColor(depth),
        description: String(raw && raw.description || ''),
      };
    }).filter((node) => node.name);
    const byName = new Map(nodes.map((node) => [node.name, node]));
    const edges = rawEdges.map((raw) => {
      const source = byName.get(String(raw && raw.parent || '').trim());
      const target = byName.get(String(raw && raw.child || '').trim());
      if (!source || !target) return null;
      source.degree += 1;
      target.degree += 1;
      return { source, target };
    }).filter(Boolean);
    for (const node of nodes) {
      node.size = Math.max(2.5, node.size + Math.min(5, Math.sqrt(node.degree) * 0.45));
    }
    return { nodes, edges, maxDepth };
  }

  function buildAdjacency() {
    state.nodeById = new Map(state.nodes.map((node) => [node.id, node]));
    state.adjacency = new Map(state.nodes.map((node) => [node.id, new Set()]));
    for (const edge of state.edges) {
      state.adjacency.get(edge.source.id)?.add(edge.target.id);
      state.adjacency.get(edge.target.id)?.add(edge.source.id);
    }
  }

  function resize() {
    const rect = stage.getBoundingClientRect();
    const dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
    canvas.width = Math.floor(rect.width * dpr);
    canvas.height = Math.floor(rect.height * dpr);
    canvas.style.width = `${rect.width}px`;
    canvas.style.height = `${rect.height}px`;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    draw();
  }

  function screenToWorld(x, y) {
    const t = state.transform;
    return { x: (x - t.x) / t.k, y: (y - t.y) / t.k };
  }

  function worldToScreen(x, y) {
    const t = state.transform;
    return { x: x * t.k + t.x, y: y * t.k + t.y };
  }

  function fitView() {
    const visible = state.nodes.filter((node) => isVisible(node));
    if (!visible.length) return;
    const rect = stage.getBoundingClientRect();
    const xs = visible.map((node) => node.x);
    const ys = visible.map((node) => node.y);
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    const minY = Math.min(...ys);
    const maxY = Math.max(...ys);
    const width = Math.max(1, maxX - minX);
    const height = Math.max(1, maxY - minY);
    const k = Math.max(0.18, Math.min(2.2, Math.min((rect.width - 80) / width, (rect.height - 80) / height)));
    state.transform.k = k;
    state.transform.x = rect.width / 2 - ((minX + maxX) / 2) * k;
    state.transform.y = rect.height / 2 - ((minY + maxY) / 2) * k;
    draw();
  }

  function isVisible(node) {
    return state.visibleLevels.has(node.depth);
  }

  function isEmphasized(node) {
    if (!state.hoverId && !state.selectedId) return false;
    const active = state.selectedId || state.hoverId;
    return node.id === active || state.adjacency.get(active)?.has(node.id);
  }

  function shouldDrawLabel(node) {
    if (!isVisible(node)) return false;
    if (node.depth === 0) return true;
    if (node.depth === STAR_DEPTH) return true;
    if (state.showCoreLabels && node.depth <= 1) return true;
    return isEmphasized(node);
  }

  function drawLabel(node) {
    const p = worldToScreen(node.x, node.y);
    const label = node.name;
    ctx.save();
    ctx.font = `${node.depth === 0 ? '700' : '600'} ${node.depth === 0 ? 15 : 12}px "Segoe UI", sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    const w = ctx.measureText(label).width + 12;
    const h = 20;
    const y = p.y + node.size * state.transform.k + 7;
    ctx.fillStyle = 'rgba(255,255,255,0.88)';
    roundRect(p.x - w / 2, y - 2, w, h, 7);
    ctx.fill();
    ctx.fillStyle = node.depth === 0 ? '#123d7a' : '#1e293b';
    ctx.fillText(label, p.x, y + 2);
    ctx.restore();
  }

  function roundRect(x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  function draw() {
    const rect = stage.getBoundingClientRect();
    ctx.clearRect(0, 0, rect.width, rect.height);
    ctx.save();
    ctx.lineCap = 'round';
    for (const edge of state.edges) {
      if (!isVisible(edge.source) || !isVisible(edge.target)) continue;
      const s = worldToScreen(edge.source.x, edge.source.y);
      const t = worldToScreen(edge.target.x, edge.target.y);
      const active = state.selectedId || state.hoverId;
      const emph = active && (edge.source.id === active || edge.target.id === active);
      ctx.beginPath();
      ctx.moveTo(s.x, s.y);
      ctx.lineTo(t.x, t.y);
      ctx.strokeStyle = emph ? 'rgba(37, 99, 235, 0.72)' : 'rgba(100, 116, 139, 0.16)';
      ctx.lineWidth = emph ? 1.8 : 0.8;
      ctx.stroke();
    }
    for (const node of state.nodes) {
      if (!isVisible(node)) continue;
      const p = worldToScreen(node.x, node.y);
      const r = Math.max(1.35, node.size * state.transform.k);
      const emph = isEmphasized(node);
      ctx.beginPath();
      ctx.arc(p.x, p.y, r + (emph ? 3 : 0), 0, TWO_PI);
      ctx.fillStyle = node.color;
      ctx.globalAlpha = emph ? 1 : node.depth > STAR_DEPTH ? 0.58 : 0.9;
      ctx.fill();
      ctx.globalAlpha = 1;
      if (emph || node.depth === 0) {
        ctx.strokeStyle = node.depth === 0 ? '#0f2d63' : 'rgba(15, 23, 42, .55)';
        ctx.lineWidth = node.depth === 0 ? 2 : 1.2;
        ctx.stroke();
      }
    }
    for (const node of state.nodes) {
      if (shouldDrawLabel(node)) drawLabel(node);
    }
    ctx.restore();
  }

  function applyForces() {
    const alpha = state.alpha;
    const visible = state.nodes.filter((node) => isVisible(node));
    for (const edge of state.edges) {
      if (!isVisible(edge.source) || !isVisible(edge.target)) continue;
      const dx = edge.target.x - edge.source.x;
      const dy = edge.target.y - edge.source.y;
      const dist = Math.max(1, Math.hypot(dx, dy));
      const sameGalaxy = edge.source.starId === edge.target.starId;
      const target = edge.target.depth <= STAR_DEPTH ? 135 : edge.target.depth >= 5 ? 16 : sameGalaxy ? 30 : 90;
      const strength = edge.target.depth > STAR_DEPTH ? 0.038 : 0.012;
      const force = (dist - target) * strength * alpha;
      const fx = (dx / dist) * force;
      const fy = (dy / dist) * force;
      if (!edge.source.fixed) {
        edge.source.vx += fx;
        edge.source.vy += fy;
      }
      if (!edge.target.fixed) {
        edge.target.vx -= fx;
        edge.target.vy -= fy;
      }
    }

    const stars = visible.filter((node) => node.depth <= STAR_DEPTH);
    for (let i = 0; i < stars.length; i += 1) {
      for (let j = i + 1; j < stars.length; j += 1) {
        const a = stars[i];
        const b = stars[j];
        const dx = a.x - b.x;
        const dy = a.y - b.y;
        const d2 = Math.max(100, dx * dx + dy * dy);
        const d = Math.sqrt(d2);
        const force = (a.depth === STAR_DEPTH && b.depth === STAR_DEPTH ? 9000 : 2600) / d2 * alpha;
        a.vx += (dx / d) * force;
        a.vy += (dy / d) * force;
        b.vx -= (dx / d) * force;
        b.vy -= (dy / d) * force;
      }
    }

    const byGalaxy = new Map();
    for (const node of visible) {
      if (node.depth <= STAR_DEPTH) continue;
      if (!byGalaxy.has(node.starId)) byGalaxy.set(node.starId, []);
      byGalaxy.get(node.starId).push(node);
    }
    for (const group of byGalaxy.values()) {
      for (let i = 0; i < group.length; i += 1) {
        const a = group[i];
        for (let j = i + 1; j < group.length; j += 1) {
          const b = group[j];
          const dx = a.x - b.x;
          const dy = a.y - b.y;
          const d2 = Math.max(12, dx * dx + dy * dy);
          if (d2 > 3600) continue;
          const d = Math.sqrt(d2);
          const force = 28 / d2 * alpha;
          a.vx += (dx / d) * force;
          a.vy += (dy / d) * force;
          b.vx -= (dx / d) * force;
          b.vy -= (dy / d) * force;
        }
      }
    }

    for (const node of visible) {
      if (node.depth === STAR_DEPTH) {
        node.vx += (node.starX - node.x) * 0.018 * alpha;
        node.vy += (node.starY - node.y) * 0.018 * alpha;
      } else if (node.depth <= 1) {
        node.vx += -node.x * 0.018 * alpha;
        node.vy += -node.y * 0.018 * alpha;
      } else {
        const star = state.nodeById.get(node.starId);
        const anchorX = star ? star.x : node.starX;
        const anchorY = star ? star.y : node.starY;
        const dx = anchorX - node.x;
        const dy = anchorY - node.y;
        const distance = Math.max(1, Math.hypot(dx, dy));
        const preferred = 42 + (node.depth - STAR_DEPTH) * 24;
        const force = (distance - preferred) * 0.014 * alpha;
        node.vx += (dx / distance) * force;
        node.vy += (dy / distance) * force;
      }
      if (!node.fixed) {
        node.vx *= node.depth > STAR_DEPTH ? 0.82 : 0.88;
        node.vy *= node.depth > STAR_DEPTH ? 0.82 : 0.88;
        node.x += node.vx;
        node.y += node.vy;
      }
    }
    state.alpha *= 0.985;
    if (state.alpha < 0.015) state.running = false;
  }

  function animate() {
    if (state.running) {
      for (let i = 0; i < 2; i += 1) applyForces();
      draw();
      state.frame = requestAnimationFrame(animate);
    }
  }

  function restart(alpha = 1) {
    state.alpha = alpha;
    if (!state.running) {
      state.running = true;
      cancelAnimationFrame(state.frame);
      state.frame = requestAnimationFrame(animate);
    }
  }

  function hitTest(clientX, clientY) {
    const rect = canvas.getBoundingClientRect();
    const x = clientX - rect.left;
    const y = clientY - rect.top;
    let best = null;
    let bestDist = Infinity;
    for (const node of state.nodes) {
      if (!isVisible(node)) continue;
      const p = worldToScreen(node.x, node.y);
      const r = Math.max(8, node.size * state.transform.k + 5);
      const d = Math.hypot(p.x - x, p.y - y);
      if (d <= r && d < bestDist) {
        best = node;
        bestDist = d;
      }
    }
    return best;
  }

  function updateTooltip(node, event) {
    if (!node) {
      tooltipEl.style.display = 'none';
      return;
    }
    const rect = stage.getBoundingClientRect();
    tooltipEl.innerHTML = `<strong>${escapeHtml(node.name)}</strong><br>${levelLabel(node.depth)}<br>Connections: ${node.degree}<br>Children: ${node.childCount}`;
    tooltipEl.style.display = 'block';
    const x = Math.min(Math.max(event.clientX - rect.left + 12, 6), rect.width - 330);
    const y = Math.min(Math.max(event.clientY - rect.top, 42), rect.height - 42);
    tooltipEl.style.left = `${x}px`;
    tooltipEl.style.top = `${y}px`;
  }

  function escapeHtml(text) {
    return String(text || '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[ch]));
  }

  function renderLegend(maxDepth) {
    legendEl.innerHTML = '';
    for (let depth = 0; depth <= maxDepth; depth += 1) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `legend-item${state.visibleLevels.has(depth) ? '' : ' off'}`;
      button.innerHTML = `<span class="dot" style="background:${levelColor(depth)}"></span>${escapeHtml(levelLabel(depth))}`;
      button.addEventListener('click', () => {
        if (state.visibleLevels.has(depth)) state.visibleLevels.delete(depth);
        else state.visibleLevels.add(depth);
        button.classList.toggle('off', !state.visibleLevels.has(depth));
        restart(0.35);
        draw();
      });
      legendEl.appendChild(button);
    }
  }

  async function load(source) {
    setStatus('Loading taxonomy...');
    const response = await fetch(window.__TEKG_PATHS.apiUrl(`taxonomy.php?view=tree&source=${encodeURIComponent(source)}`), { credentials: 'same-origin' });
    if (!response.ok) throw new Error(`taxonomy API HTTP ${response.status}`);
    const payload = await response.json();
    const parsed = parseTaxonomy(payload);
    state.nodes = parsed.nodes;
    state.edges = parsed.edges;
    state.visibleLevels = new Set(Array.from({ length: parsed.maxDepth + 1 }, (_, i) => i));
    buildAdjacency();
    renderLegend(parsed.maxDepth);
    resize();
    fitView();
    restart(1);
    setStatus(`Loaded ${state.nodes.length} nodes and ${state.edges.length} edges.\nGalaxy layout: Order nodes act as stars; deeper TE nodes orbit their own star.\nHover or click a node to reveal its local neighborhood.`);
  }

  canvas.addEventListener('pointermove', (event) => {
    const rect = canvas.getBoundingClientRect();
    const sx = event.clientX - rect.left;
    const sy = event.clientY - rect.top;
    state.pointer = { x: sx, y: sy };
    if (state.draggingNode) {
      const world = screenToWorld(sx, sy);
      state.draggingNode.x = world.x;
      state.draggingNode.y = world.y;
      state.draggingNode.vx = 0;
      state.draggingNode.vy = 0;
      restart(0.18);
      draw();
      return;
    }
    if (state.panning && state.lastPointer) {
      state.transform.x += sx - state.lastPointer.x;
      state.transform.y += sy - state.lastPointer.y;
      state.lastPointer = { x: sx, y: sy };
      draw();
      return;
    }
    const hit = hitTest(event.clientX, event.clientY);
    const nextHover = hit ? hit.id : null;
    if (nextHover !== state.hoverId) {
      state.hoverId = nextHover;
      draw();
    }
    updateTooltip(hit, event);
  });

  canvas.addEventListener('pointerdown', (event) => {
    canvas.setPointerCapture(event.pointerId);
    const hit = hitTest(event.clientX, event.clientY);
    const rect = canvas.getBoundingClientRect();
    if (hit) {
      state.draggingNode = hit;
      hit.fixed = true;
      state.selectedId = hit.id;
    } else {
      state.panning = true;
    }
    state.lastPointer = { x: event.clientX - rect.left, y: event.clientY - rect.top };
    canvas.classList.add('dragging');
    draw();
  });

  canvas.addEventListener('pointerup', (event) => {
    if (state.draggingNode) state.draggingNode.fixed = false;
    state.draggingNode = null;
    state.panning = false;
    state.lastPointer = null;
    canvas.classList.remove('dragging');
    try { canvas.releasePointerCapture(event.pointerId); } catch (_) {}
    restart(0.12);
  });

  canvas.addEventListener('pointerleave', () => {
    state.hoverId = null;
    tooltipEl.style.display = 'none';
    draw();
  });

  canvas.addEventListener('wheel', (event) => {
    event.preventDefault();
    const rect = canvas.getBoundingClientRect();
    const sx = event.clientX - rect.left;
    const sy = event.clientY - rect.top;
    const before = screenToWorld(sx, sy);
    const factor = event.deltaY < 0 ? 1.12 : 0.89;
    state.transform.k = Math.max(0.12, Math.min(5, state.transform.k * factor));
    state.transform.x = sx - before.x * state.transform.k;
    state.transform.y = sy - before.y * state.transform.k;
    draw();
  }, { passive: false });

  sourceSelect.addEventListener('change', () => {
    load(sourceSelect.value).catch((error) => setStatus(`Failed: ${error.message || error}`));
  });
  coreLabelsInput.addEventListener('change', () => {
    state.showCoreLabels = coreLabelsInput.checked;
    draw();
  });
  restartButton.addEventListener('click', () => restart(1));
  fitButton.addEventListener('click', fitView);
  window.addEventListener('resize', resize);

  load(sourceSelect.value).catch((error) => setStatus(`Failed: ${error.message || error}`));
}());
