(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.__TEKG_G6_SVG_EXPORT = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  function escapeXml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&apos;',
    })[character]);
  }

  function finite(value, fallback = 0) {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
  }

  function decimal(value) {
    return finite(value).toFixed(2).replace(/\.00$/, '');
  }

  function normalizedLabel(label) {
    if (!label || typeof label !== 'object') return null;
    const lines = Array.isArray(label.lines)
      ? label.lines.map((line) => String(line || '')).filter(Boolean)
      : String(label.text || '').split('\n').filter(Boolean);
    if (!lines.length) return null;
    return {
      lines,
      fontSize: Math.max(1, finite(label.fontSize, 10)),
      fontWeight: Math.max(100, finite(label.fontWeight, 600)),
      fill: String(label.fill || '#111111'),
      stroke: String(label.stroke || '#ffffff'),
      strokeWidth: Math.max(0, finite(label.strokeWidth, 3)),
      opacity: Math.max(0, Math.min(1, finite(label.opacity, 1))),
    };
  }

  function serialize(spec = {}) {
    const nodes = (Array.isArray(spec.nodes) ? spec.nodes : []).map((node) => ({
      ...node,
      id: String(node.id || ''),
      x: finite(node.x),
      y: finite(node.y),
      radius: Math.max(1, finite(node.radius, 5)),
      rings: Array.isArray(node.rings) ? node.rings : [],
      label: normalizedLabel(node.label),
    }));
    if (!nodes.length) throw new Error('SVG export requires at least one positioned node.');
    const nodeById = new Map(nodes.map((node) => [node.id, node]));
    const edges = (Array.isArray(spec.edges) ? spec.edges : [])
      .filter((edge) => nodeById.has(String(edge.source || '')) && nodeById.has(String(edge.target || '')))
      .map((edge) => ({ ...edge, id: String(edge.id || ''), source: String(edge.source), target: String(edge.target) }));

    const padding = Math.max(0, finite(spec.padding, 36));
    const extents = nodes.map((node) => {
      const ringRadius = node.rings.reduce((largest, ring) => Math.max(largest, finite(ring.radius)), node.radius);
      return { x: node.x, y: node.y, radius: Math.max(node.radius, ringRadius) + 8 };
    });
    const minX = Math.min(...extents.map((item) => item.x - item.radius)) - padding;
    const minY = Math.min(...extents.map((item) => item.y - item.radius)) - padding;
    const maxX = Math.max(...extents.map((item) => item.x + item.radius)) + padding;
    const maxY = Math.max(...extents.map((item) => item.y + item.radius)) + padding;
    const width = Math.max(1, maxX - minX);
    const height = Math.max(1, maxY - minY);

    const edgeMarkup = edges.map((edge) => {
      const source = nodeById.get(edge.source);
      const target = nodeById.get(edge.target);
      const dash = Array.isArray(edge.dash) && edge.dash.length
        ? ` stroke-dasharray="${edge.dash.map(decimal).join(' ')}"`
        : '';
      return `<line data-edge-id="${escapeXml(edge.id)}" x1="${decimal(source.x)}" y1="${decimal(source.y)}" x2="${decimal(target.x)}" y2="${decimal(target.y)}" stroke="${escapeXml(edge.stroke || '#64748b')}" stroke-width="${decimal(Math.max(0.1, finite(edge.strokeWidth, 1)))}" stroke-opacity="${decimal(Math.max(0, Math.min(1, finite(edge.opacity, 1))))}" stroke-linecap="round"${dash}/>`;
    }).join('');

    const edgeLabelMarkup = edges.map((edge) => {
      const text = String(edge.label?.text || '').trim();
      if (!text) return '';
      const source = nodeById.get(edge.source);
      const target = nodeById.get(edge.target);
      const x = (source.x + target.x) / 2;
      const y = (source.y + target.y) / 2;
      const fontSize = Math.max(1, finite(edge.label?.fontSize, 10));
      return `<text data-edge-label="${escapeXml(edge.id)}" x="${decimal(x)}" y="${decimal(y)}" text-anchor="middle" dominant-baseline="middle" fill="${escapeXml(edge.label?.fill || '#334155')}" stroke="${escapeXml(edge.label?.stroke || '#ffffff')}" stroke-width="${decimal(Math.max(0, finite(edge.label?.strokeWidth, 4)))}" paint-order="stroke fill" font-family="Arial, Helvetica, sans-serif" font-size="${decimal(fontSize)}" font-weight="600">${escapeXml(text)}</text>`;
    }).join('');

    const ringMarkup = nodes.map((node) => node.rings.map((ring) => `<circle cx="${decimal(node.x)}" cy="${decimal(node.y)}" r="${decimal(Math.max(node.radius, finite(ring.radius, node.radius)))}" fill="none" stroke="${escapeXml(ring.stroke || '#60a5fa')}" stroke-width="${decimal(Math.max(0.1, finite(ring.strokeWidth, 1)))}" stroke-opacity="${decimal(Math.max(0, Math.min(1, finite(ring.opacity, 0.2))))}"/>`).join('')).join('');

    const nodeMarkup = nodes.map((node) => `<circle data-node-id="${escapeXml(node.id)}" cx="${decimal(node.x)}" cy="${decimal(node.y)}" r="${decimal(node.radius)}" fill="${escapeXml(node.fill || '#94a3b8')}" stroke="${escapeXml(node.stroke || '#111111')}" stroke-width="${decimal(Math.max(0.1, finite(node.strokeWidth, 2)))}" opacity="${decimal(Math.max(0, Math.min(1, finite(node.opacity, 1))))}"/>`).join('');

    const labelMarkup = nodes.map((node) => {
      const label = node.label;
      if (!label) return '';
      const firstOffset = -((label.lines.length - 1) * label.fontSize * 0.55);
      const tspans = label.lines.map((line, index) => `<tspan x="${decimal(node.x)}" dy="${decimal(index === 0 ? firstOffset : label.fontSize * 1.1)}">${escapeXml(line)}</tspan>`).join('');
      return `<text data-node-label="${escapeXml(node.id)}" x="${decimal(node.x)}" y="${decimal(node.y)}" text-anchor="middle" dominant-baseline="middle" fill="${escapeXml(label.fill)}" stroke="${escapeXml(label.stroke)}" stroke-width="${decimal(label.strokeWidth)}" stroke-linejoin="round" paint-order="stroke fill" font-family="Arial, Helvetica, sans-serif" font-size="${decimal(label.fontSize)}" font-weight="${decimal(label.fontWeight)}" opacity="${decimal(label.opacity)}">${tspans}</text>`;
    }).join('');

    const title = String(spec.title || 'TE-Dee graph');
    const description = String(spec.description || 'Static vector export of the current TE-Dee graph.');
    const metadata = escapeXml(JSON.stringify(spec.metadata && typeof spec.metadata === 'object' ? spec.metadata : {}));
    const background = String(spec.background || '#ffffff');
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${decimal(width)}" height="${decimal(height)}" viewBox="${decimal(minX)} ${decimal(minY)} ${decimal(width)} ${decimal(height)}" role="img" aria-labelledby="tekg-svg-title tekg-svg-desc"><title id="tekg-svg-title">${escapeXml(title)}</title><desc id="tekg-svg-desc">${escapeXml(description)}</desc><metadata>${metadata}</metadata><rect x="${decimal(minX)}" y="${decimal(minY)}" width="${decimal(width)}" height="${decimal(height)}" fill="${escapeXml(background)}"/><g id="edges">${edgeMarkup}</g><g id="edge-labels">${edgeLabelMarkup}</g><g id="activity-rings">${ringMarkup}</g><g id="nodes">${nodeMarkup}</g><g id="node-labels">${labelMarkup}</g></svg>`;
  }

  return { serialize, escapeXml };
}));
