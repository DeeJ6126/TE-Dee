(() => {
  const header = document.getElementById('protoHeader');
  const root = document.querySelector('[data-home-stats]');
  const tooltip = document.createElement('div');
  let activeTeLevel = 'class';
  let statsRequestEpoch = 0;
  const numberFormatter = new Intl.NumberFormat();
  const percentFormatter = new Intl.NumberFormat(undefined, {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
  });
  const palettes = {
    entity: ['#2f6fbb', '#2a9d8f', '#e76f51', '#8f6ed5', '#d69f1f', '#5d7792', '#b45309', '#0f766e', '#be123c', '#4f46e5', '#64748b'],
    te: ['#2f6fbb', '#e76f51', '#8f6ed5', '#2a9d8f'],
    relation: ['#2563eb', '#dc2626', '#059669', '#7c3aed', '#ea580c', '#0891b2'],
  };

  function syncHeader() {
    if (!header) {
      return;
    }
    if (window.scrollY > 12) {
      header.classList.add('is-scrolled');
    } else {
      header.classList.remove('is-scrolled');
    }
  }

  function formatNumber(value) {
    const numericValue = Number(value);
    if (!Number.isFinite(numericValue)) {
      return '--';
    }
    return numberFormatter.format(Math.max(0, numericValue));
  }

  function setText(selector, text) {
    const element = root ? root.querySelector(selector) : null;
    if (element) {
      element.textContent = text;
    }
  }

  function toPoint(cx, cy, radius, angle) {
    const radians = (angle * Math.PI) / 180;
    return {
      x: cx + radius * Math.cos(radians),
      y: cy + radius * Math.sin(radians),
    };
  }

  function buildDonutPath(cx, cy, outerRadius, innerRadius, startAngle, endAngle) {
    const adjustedEndAngle = Math.min(endAngle, startAngle + 359.99);
    const largeArc = adjustedEndAngle - startAngle > 180 ? 1 : 0;
    const p1 = toPoint(cx, cy, outerRadius, startAngle);
    const p2 = toPoint(cx, cy, outerRadius, adjustedEndAngle);
    const p3 = toPoint(cx, cy, innerRadius, adjustedEndAngle);
    const p4 = toPoint(cx, cy, innerRadius, startAngle);

    return [
      `M ${p1.x.toFixed(3)} ${p1.y.toFixed(3)}`,
      `A ${outerRadius} ${outerRadius} 0 ${largeArc} 1 ${p2.x.toFixed(3)} ${p2.y.toFixed(3)}`,
      `L ${p3.x.toFixed(3)} ${p3.y.toFixed(3)}`,
      `A ${innerRadius} ${innerRadius} 0 ${largeArc} 0 ${p4.x.toFixed(3)} ${p4.y.toFixed(3)}`,
      'Z',
    ].join(' ');
  }

  function normalizeRows(rows) {
    if (!Array.isArray(rows)) {
      return [];
    }
    return rows
      .map((row, index) => {
        const count = Math.max(0, Number(row.count) || 0);
        return {
          label: String(row.label || 'Other'),
          count,
          percentage: Math.max(0, Number(row.percentage) || 0),
          color: '',
          index,
        };
      })
      .filter((row) => row.count > 0);
  }

  function colorRows(kind, rows) {
    const palette = palettes[kind] || palettes.entity;
    return rows.map((row, index) => ({
      ...row,
      color: palette[index % palette.length],
    }));
  }

  function renderLegend(kind, rows) {
    const legend = root ? root.querySelector(`[data-donut-legend="${kind}"]`) : null;
    if (!legend) {
      return;
    }

    legend.replaceChildren();
    rows.forEach((row) => {
      const item = document.createElement('div');
      item.className = 'status-legend-item';

      const swatch = document.createElement('span');
      swatch.className = 'status-legend-swatch';
      swatch.style.backgroundColor = row.color;

      const label = document.createElement('span');
      label.className = 'status-legend-name';
      label.textContent = row.label;

      const count = document.createElement('span');
      count.className = 'status-legend-count';
      count.textContent = formatNumber(row.count);

      const percentage = document.createElement('span');
      percentage.className = 'status-legend-percent';
      percentage.textContent = `${percentFormatter.format(row.percentage)}%`;

      item.append(swatch, label, count, percentage);
      legend.appendChild(item);
    });
  }

  function ensureTooltip() {
    if (!root || tooltip.parentNode) {
      return;
    }
    tooltip.className = 'status-donut-tooltip';
    tooltip.hidden = true;
    root.appendChild(tooltip);
  }

  function hideTooltip() {
    tooltip.hidden = true;
    tooltip.classList.remove('is-visible');
  }

  function setTooltipContent(row) {
    tooltip.replaceChildren();
    const label = document.createElement('strong');
    label.textContent = row.label;
    const meta = document.createElement('span');
    meta.textContent = `${formatNumber(row.count)} · ${percentFormatter.format(row.percentage)}%`;
    tooltip.append(label, meta);
  }

  function showTooltip(row, event) {
    if (!root) {
      return;
    }
    ensureTooltip();
    setTooltipContent(row);
    const rootRect = root.getBoundingClientRect();
    tooltip.style.left = `${event.clientX - rootRect.left}px`;
    tooltip.style.top = `${event.clientY - rootRect.top - 14}px`;
    tooltip.hidden = false;
    tooltip.classList.add('is-visible');
  }

  function drawDonut(kind, rows, progress) {
    const svg = root ? root.querySelector(`[data-donut-chart="${kind}"]`) : null;
    if (!svg) {
      return;
    }

    svg.replaceChildren();
    const track = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    track.setAttribute('class', 'status-donut-track');
    track.setAttribute('cx', '180');
    track.setAttribute('cy', '180');
    track.setAttribute('r', '136');
    svg.appendChild(track);

    let startAngle = -90;
    rows.forEach((row) => {
      const sweep = 360 * (row.percentage / 100) * progress;
      if (sweep <= 0) {
        return;
      }

      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('class', 'status-donut-segment');
      path.setAttribute('d', buildDonutPath(180, 180, 168, 104, startAngle, startAngle + sweep));
      path.setAttribute('fill', row.color);
      path.setAttribute('tabindex', '0');
      path.setAttribute('aria-label', `${row.label}: ${formatNumber(row.count)} (${percentFormatter.format(row.percentage)}%)`);
      path.addEventListener('mouseenter', (event) => {
        path.classList.add('is-hovered');
        showTooltip(row, event);
      });
      path.addEventListener('mousemove', (event) => showTooltip(row, event));
      path.addEventListener('mouseleave', () => {
        path.classList.remove('is-hovered');
        hideTooltip();
      });
      path.addEventListener('focus', () => {
        path.classList.add('is-hovered');
        ensureTooltip();
        setTooltipContent(row);
        tooltip.style.left = '50%';
        tooltip.style.top = '18px';
        tooltip.hidden = false;
        tooltip.classList.add('is-visible');
      });
      path.addEventListener('blur', () => {
        path.classList.remove('is-hovered');
        hideTooltip();
      });
      svg.appendChild(path);
      startAngle += 360 * (row.percentage / 100);
    });
  }

  function animateDonut(kind, rows) {
    const duration = 850;
    const startedAt = window.performance.now();

    function step(now) {
      const elapsed = now - startedAt;
      const linearProgress = Math.min(1, elapsed / duration);
      const easedProgress = 1 - Math.pow(1 - linearProgress, 3);
      drawDonut(kind, rows, easedProgress);
      if (linearProgress < 1) {
        window.requestAnimationFrame(step);
      }
    }

    window.requestAnimationFrame(step);
  }

  function renderChart(kind, rows, total) {
    setText(`[data-donut-total="${kind}"]`, formatNumber(total));
    renderLegend(kind, rows);
    animateDonut(kind, rows);
  }

  function showFailure(message) {
    if (!root) {
      return;
    }
    root.classList.remove('is-loading');
    root.classList.add('has-error');
    const error = root.querySelector('[data-home-stats-error]');
    if (error) {
      error.hidden = false;
      error.textContent = message || 'Live dataset statistics are temporarily unavailable.';
    }
    root.querySelectorAll('.status-loading').forEach((element) => {
      element.textContent = 'No live values available';
    });
  }

  function setTeLoading() {
    const legend = root ? root.querySelector('[data-donut-legend="te"]') : null;
    const svg = root ? root.querySelector('[data-donut-chart="te"]') : null;
    if (svg) {
      svg.replaceChildren();
    }
    setText('[data-donut-total="te"]', '--');
    if (legend) {
      legend.replaceChildren();
      const loading = document.createElement('div');
      loading.className = 'status-loading';
      loading.textContent = 'Loading TE classification';
      legend.appendChild(loading);
    }
  }

  function showTeFailure() {
    const legend = root ? root.querySelector('[data-donut-legend="te"]') : null;
    if (!legend) return;
    const message = document.createElement('div');
    message.className = 'status-loading';
    message.textContent = 'TE classification is temporarily unavailable';
    legend.replaceChildren(message);
  }

  function syncTeLevelButtons() {
    if (!root) {
      return;
    }
    root.querySelectorAll('[data-te-level]').forEach((button) => {
      const isActive = button.dataset.teLevel === activeTeLevel;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  }

  function renderTeChart(data) {
    const teRows = normalizeRows(data.te_classification_composition);
    const teTotal = teRows.reduce((total, row) => total + row.count, 0);
    renderChart('te', colorRows('te', teRows), teTotal);
  }

  function renderStaticCharts(data) {
    const entityRows = normalizeRows(data.entity_composition);
    const relationRows = normalizeRows(data.relation_composition);
    const relationTotal = relationRows.reduce((total, row) => total + row.count, 0);
    renderChart('entity', colorRows('entity', entityRows), data.nodes_total);
    renderChart('relation', colorRows('relation', relationRows), relationTotal);
  }

  function renderStats(data, { teOnly = false } = {}) {
    if (!root) {
      return;
    }

    activeTeLevel = String(data.te_level || activeTeLevel || 'class');
    syncTeLevelButtons();
    if (!teOnly) {
      renderStaticCharts(data);
    }
    renderTeChart(data);
    root.classList.remove('is-loading');
    root.classList.add('is-loaded');
  }

  async function loadHomeStats(teLevel = activeTeLevel, options = {}) {
    if (!root) {
      return;
    }

    const teOnly = options.teOnly === true && root.classList.contains('is-loaded');
    const requestEpoch = ++statsRequestEpoch;
    if (!teOnly) root.classList.add('is-loading');
    activeTeLevel = teLevel || 'class';
    syncTeLevelButtons();
    setTeLoading();
    const apiUrl = root.dataset.homeStatsUrl || 'api/home_stats.php';
    const url = new URL(apiUrl, window.location.href);
    url.searchParams.set('te_level', activeTeLevel);
    try {
      const response = await fetch(url.toString(), {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      const data = await response.json();
      if (!response.ok || data.ok !== true) {
        throw new Error(data.error || `HTTP ${response.status}`);
      }
      if (requestEpoch !== statsRequestEpoch) return;
      renderStats(data, { teOnly });
    } catch (error) {
      if (requestEpoch !== statsRequestEpoch) return;
      if (teOnly) {
        showTeFailure();
      } else {
        showFailure('Live dataset statistics are temporarily unavailable.');
      }
    }
  }

  window.addEventListener('scroll', syncHeader, { passive: true });
  if (root) {
    root.querySelectorAll('[data-te-level]').forEach((button) => {
      button.addEventListener('click', () => {
        const level = button.dataset.teLevel || 'class';
        if (level !== activeTeLevel) {
          loadHomeStats(level, { teOnly: true });
        }
      });
    });
  }
  syncHeader();
  loadHomeStats();
})();
