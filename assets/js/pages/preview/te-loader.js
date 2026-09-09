(function () {
  'use strict';

  const KEYWORDS = Object.freeze({
    retro: ['LINE', 'LINE1', 'LINE-1', 'L1', 'L1HS', 'SINE', 'Alu', 'SVA', 'ERV', 'HERV', 'LTR', 'SART1', 'retrotransposon'],
    dna: ['DNA transposon', 'Tc1', 'Mariner', 'hAT', 'piggyBac', 'PIF', 'Harbinger', 'Merlin', 'Mutator'],
  });
  const taxonomyKinds = new Map();
  const taxonomyKindRequests = new Map();

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function normalize(value) {
    return String(value || '')
      .replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'")
      .replace(/\s+/g, ' ')
      .trim();
  }

  function matchText(value) {
    return ` ${normalize(value)
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, ' ')
      .replace(/\bretrotransposons\b/g, 'retrotransposon')
      .replace(/\btransposons\b/g, 'transposon')
      .replace(/\bsines\b/g, 'sine')
      .replace(/\blines\b/g, 'line')
      .replace(/\bltrs\b/g, 'ltr')
      .replace(/\bervs\b/g, 'erv')
      .replace(/\bhervs\b/g, 'herv')
      .replace(/\s+/g, ' ')
      .trim()} `;
  }

  function phraseMatch(text, phrase) {
    const wanted = matchText(phrase).trim();
    return !!wanted && text.includes(` ${wanted} `);
  }

  function semanticKind(text) {
    const normalized = matchText(text);
    if (!normalized.trim()) return 'default';
    if (
      phraseMatch(normalized, 'class ii')
      || phraseMatch(normalized, 'class 2')
      || phraseMatch(normalized, 'dna transposon')
      || phraseMatch(normalized, 'tir')
      || KEYWORDS.dna.some((keyword) => phraseMatch(normalized, keyword))
    ) return 'dna';
    if (
      phraseMatch(normalized, 'class i')
      || phraseMatch(normalized, 'class 1')
      || phraseMatch(normalized, 'retrotransposon')
      || KEYWORDS.retro.some((keyword) => phraseMatch(normalized, keyword))
    ) return 'retro';
    return 'default';
  }

  function classify(nodeOrQuery) {
    const parts = [];
    if (nodeOrQuery && typeof nodeOrQuery === 'object') {
      const taxonomy = nodeOrQuery.taxonomy && typeof nodeOrQuery.taxonomy === 'object'
        ? nodeOrQuery.taxonomy
        : {};
      parts.push(
        nodeOrQuery.queryLabel,
        nodeOrQuery.rawLabel,
        nodeOrQuery.displayLabel,
        nodeOrQuery.label,
        nodeOrQuery.nodeType,
        nodeOrQuery.type,
        taxonomy.canonical_name,
        taxonomy.display_path,
        Array.isArray(taxonomy.path_labels) ? taxonomy.path_labels.join(' ') : '',
      );
    } else {
      parts.push(nodeOrQuery);
    }
    return semanticKind(parts.filter(Boolean).join(' '));
  }

  async function resolveKind(nodeOrQuery) {
    if (nodeOrQuery && typeof nodeOrQuery === 'object') return classify(nodeOrQuery);
    const name = normalize(nodeOrQuery);
    if (!name) return 'default';
    const key = name.toLowerCase();
    if (taxonomyKinds.has(key)) return taxonomyKinds.get(key);
    if (taxonomyKindRequests.has(key)) return taxonomyKindRequests.get(key);
    const fallback = classify(name);
    const request = (async () => {
      try {
        if (!window.__TEKG_PATHS?.apiUrl) return fallback;
        const url = new URL(window.__TEKG_PATHS.apiUrl('taxonomy.php'), window.location.origin);
        url.searchParams.set('view', 'loader_kinds');
        url.searchParams.set('source', 'rmsk_repbase');
        url.searchParams.set('names', name);
        const response = await fetch(url.toString(), { credentials: 'same-origin' });
        if (!response.ok) return fallback;
        const payload = await response.json();
        const item = Array.isArray(payload?.items) ? payload.items[0] : null;
        const resolved = item?.taxonomy_found === true && ['retro', 'dna', 'default'].includes(item?.kind)
          ? item.kind
          : fallback;
        taxonomyKinds.set(key, resolved);
        return resolved;
      } catch (_error) {
        taxonomyKinds.set(key, fallback);
        return fallback;
      } finally {
        taxonomyKindRequests.delete(key);
      }
    })();
    taxonomyKindRequests.set(key, request);
    return request;
  }

  function labelFromText(label) {
    return normalize(label)
      .replace(/^Preparing\s+/i, '')
      .replace(/^Loading\s+/i, '')
      .replace(/^Expanding\s+/i, '')
      .replace(/\s+(?:co-expression\s+)?network\s*$/i, '')
      .replace(/\s*\.\.\.\s*$/i, '')
      .trim();
  }

  function shortLabel(label) {
    const raw = normalize(label);
    return raw.length <= 8 ? raw : `${raw.slice(0, 3).trim()}...`;
  }

  function svg(kind, label) {
    const full = normalize(label) || 'TE';
    const compact = shortLabel(full);
    if (kind === 'retro') {
      return [
        `<svg class="te-mechanism-loader__svg" viewBox="0 0 560 300" role="img" aria-label="Retrotransposon-inspired loader for ${escapeHtml(full)}">`,
        `<title>Retrotransposon-inspired loader for ${escapeHtml(full)}</title>`,
        '<g class="te-loader-source-dna"><path class="te-loader-dna-backbone" d="M48 72 H250" />',
        '<rect class="te-loader-te-segment" x="102" y="50" width="96" height="44" rx="12" />',
        `<text class="te-loader-label" x="150" y="72">${escapeHtml(compact)}</text>`,
        '<text class="te-loader-muted-label" x="96" y="34">source DNA</text></g>',
        '<g class="te-loader-retro-complex"><path class="te-loader-rna" d="M132 104 C164 132, 194 122, 224 150" />',
        '<text class="te-loader-muted-label te-loader-rna-label" x="166" y="142">RNA</text>',
        '<circle class="te-loader-enzyme-fill" cx="224" cy="150" r="18" />',
        '<text class="te-loader-label te-loader-rt-label" x="224" y="150">RT</text></g>',
        '<g class="te-loader-target-dna"><path class="te-loader-dna-backbone te-loader-target-left" d="M250 232 H354" />',
        '<path class="te-loader-dna-backbone te-loader-target-right" d="M432 232 H510" />',
        '<path class="te-loader-target-open" d="M362 232 C374 206, 412 206, 424 232" />',
        '<text class="te-loader-muted-label" x="388" y="268">target DNA</text></g>',
        '<g class="te-loader-copy"><rect class="te-loader-te-segment" x="354" y="210" width="78" height="44" rx="10" />',
        `<text class="te-loader-label te-loader-copy-label" x="393" y="232">${escapeHtml(compact)}</text></g></svg>`,
      ].join('');
    }
    if (kind === 'dna') {
      return [
        `<svg class="te-mechanism-loader__svg" viewBox="0 0 560 300" role="img" aria-label="DNA transposon-inspired loader for ${escapeHtml(full)}">`,
        `<title>DNA transposon-inspired loader for ${escapeHtml(full)}</title>`,
        '<g class="te-loader-source-dna"><path class="te-loader-dna-backbone te-loader-source-left" d="M48 72 H102" />',
        '<path class="te-loader-dna-backbone te-loader-source-right" d="M198 72 H250" />',
        '<text class="te-loader-muted-label" x="96" y="34">source DNA</text>',
        '<text class="te-loader-muted-label" x="150" y="120">donor gap</text></g>',
        '<g class="te-loader-target-dna"><path class="te-loader-dna-backbone te-loader-target-left" d="M250 232 H344" />',
        '<path class="te-loader-dna-backbone te-loader-target-right" d="M440 232 H510" />',
        '<path class="te-loader-target-open" d="M352 232 C366 206, 418 206, 432 232" />',
        '<text class="te-loader-muted-label" x="388" y="268">target DNA</text></g>',
        '<g class="te-loader-dna-segment-moving"><rect class="te-loader-te-segment" x="102" y="50" width="96" height="44" rx="12" />',
        `<text class="te-loader-label" x="150" y="72">${escapeHtml(compact)}</text></g></svg>`,
      ].join('');
    }
    return '';
  }

  function defaultColors() {
    const meta = window.__TEKG_G6_TYPE_META || {};
    return {
      te: String(meta.colors?.TE || '#4e79ff'),
      teStroke: String(meta.strokes?.TE || '#1f3f99'),
    };
  }

  const progressTimers = new WeakMap();
  const progressPhases = {
    request: { value: 10, ceiling: 34, text: 'Requesting graph data...' },
    prepare: { value: 42, ceiling: 64, text: 'Preparing and transforming graph data...' },
    render: { value: 72, ceiling: 95, text: 'Rendering graph and running force layout...' },
    complete: { value: 100, ceiling: 100, text: 'Graph ready.' },
  };

  function stopProgress(overlay) {
    const timer = overlay ? progressTimers.get(overlay) : 0;
    if (timer) window.clearInterval(timer);
    if (overlay) progressTimers.delete(overlay);
  }

  function setProgress({ overlay, phase = 'request', text = '', value = null }) {
    if (!overlay) return null;
    stopProgress(overlay);
    const config = progressPhases[phase] || progressPhases.request;
    const progress = overlay.querySelector('.graph-preloader-progress');
    const fill = overlay.querySelector('.graph-preloader-progress-fill');
    const phaseLabel = overlay.querySelector('.graph-preloader-phase');
    let current = Math.max(0, Math.min(100, Number.isFinite(value) ? value : config.value));

    const paint = () => {
      if (fill) fill.style.width = `${current.toFixed(1)}%`;
      if (progress) progress.setAttribute('aria-valuenow', String(Math.round(current)));
    };
    paint();
    if (phaseLabel) phaseLabel.textContent = text || config.text;

    if (config.ceiling > current && phase !== 'complete') {
      const timer = window.setInterval(() => {
        const remaining = config.ceiling - current;
        if (remaining <= 0.25) {
          stopProgress(overlay);
          return;
        }
        current = Math.min(config.ceiling, current + Math.max(0.35, remaining * 0.08));
        paint();
      }, 350);
      progressTimers.set(overlay, timer);
    }
    return { phase, value: current, text: text || config.text };
  }

  function render({ overlay, slot, kind, label, colors = {} }) {
    const safeKind = kind === 'retro' || kind === 'dna' ? kind : 'default';
    const palette = { ...defaultColors(), ...colors };
    overlay?.classList.remove('te-loader-retro', 'te-loader-dna', 'te-loader-default');
    overlay?.classList.add(`te-loader-${safeKind}`);
    if (!slot || safeKind === 'default') {
      if (slot) {
        slot.innerHTML = '';
        slot.setAttribute('aria-hidden', 'true');
        slot.removeAttribute('style');
      }
      return { kind: safeKind, label: normalize(label), rendered: false };
    }
    slot.style.setProperty('--te-loader-te-color', palette.te);
    slot.style.setProperty('--te-loader-te-stroke', palette.teStroke);
    slot.innerHTML = `<div class="te-mechanism-loader te-loader-${safeKind}">${svg(safeKind, label)}</div>`;
    slot.setAttribute('aria-hidden', 'false');
    return { kind: safeKind, label: normalize(label), rendered: true };
  }

  function show({ overlay, slot, label, nodeOrQuery, kind, phase = 'request', phaseText = '' }) {
    const explicitNodeLabel = typeof nodeOrQuery === 'string' ? normalize(nodeOrQuery) : '';
    const cleanLabel = explicitNodeLabel || labelFromText(label) || 'TE';
    if (overlay) {
      overlay.classList.add('is-visible');
      overlay.setAttribute('aria-hidden', 'false');
    }
    const result = render({
      overlay,
      slot,
      kind: ['retro', 'dna', 'default'].includes(kind) ? kind : classify(nodeOrQuery || cleanLabel),
      label: cleanLabel,
    });
    setProgress({ overlay, phase, text: phaseText });
    return result;
  }

  function hide({ overlay, slot }) {
    stopProgress(overlay);
    if (overlay) {
      overlay.classList.remove('is-visible');
      overlay.setAttribute('aria-hidden', 'true');
    }
    return render({ overlay, slot, kind: 'default', label: '' });
  }

  window.__TEKG_TE_LOADER = {
    classify,
    resolveKind,
    show,
    hide,
    render,
    progress: setProgress,
    labelFromText,
  };
}());
