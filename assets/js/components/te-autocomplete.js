(() => {
  const cache = new Map();
  const sourceProviders = new Map();
  const maxVisible = 180;
  const maxConnectedDepth = 10;

  function apiUrl(path) {
    const paths = window.__TEKG_PATHS;
    if (!paths || typeof paths.apiUrl !== 'function') {
      throw new Error('TE-Dee runtime paths are unavailable.');
    }
    return paths.apiUrl(path);
  }

  function taxonomyUrl() {
    return apiUrl('taxonomy.php?view=items');
  }

  function typeControl(root) {
    const selector = String(root.dataset.teAutocompleteTypeSource || '').trim();
    return selector ? document.querySelector(selector) : null;
  }

  function controlFromSelector(selector) {
    const normalized = String(selector || '').trim();
    return normalized ? document.querySelector(normalized) : null;
  }

  function currentEntityType(root) {
    const control = typeControl(root);
    return String((control && control.value) || root.dataset.teAutocompleteType || 'TE').trim() || 'TE';
  }

  function controlValue(selector) {
    const control = controlFromSelector(selector);
    return String((control && control.value) || '').trim();
  }

  function connectedMaxDepth(root) {
    const value = Number(controlValue(root.dataset.teAutocompleteConnectedMaxDepthSource) || 3);
    if (!Number.isFinite(value)) {
      return 3;
    }
    return Math.max(1, Math.min(maxConnectedDepth, Math.trunc(value)));
  }

  function connectedSource(root) {
    return controlValue(root.dataset.teAutocompleteConnectedSource);
  }

  function connectedSourceType(root) {
    return controlValue(root.dataset.teAutocompleteConnectedSourceType);
  }

  function connectedTargetType(root) {
    return controlValue(root.dataset.teAutocompleteConnectedTargetType) || currentEntityType(root);
  }

  function sourceConfig(root, input) {
    const source = String(root.dataset.teAutocompleteSource || 'taxonomy').trim();
    const provider = sourceProviders.get(source);
    if (provider) {
      const query = String(input.value || '').trim();
      return {
        key: provider.queryAware === true
          ? `provider:${source}:${currentEntityType(root).toLowerCase()}:${query.toLowerCase()}`
          : `provider:${source}`,
        label: String(provider.label || 'TE').trim() || 'TE',
        connected: false,
        provider,
      };
    }
    if (source === 'path-finder-entities') {
      const sourceEntity = connectedSource(root);
      const entityType = currentEntityType(root);
      const query = String(input.value || '').trim();
      const fallbackParams = new URLSearchParams({
        view: 'entities',
        type: entityType,
        q: query,
        limit: String(maxVisible),
      });
      const fallback = {
        key: `path-finder-entities:${entityType}:${query.toLowerCase()}`,
        url: apiUrl(`path_finder.php?${fallbackParams.toString()}`),
        label: entityType,
        connected: false,
      };
      if (sourceEntity) {
        const sourceType = connectedSourceType(root);
        const targetType = connectedTargetType(root);
        const maxDepth = connectedMaxDepth(root);
        const params = new URLSearchParams({
          view: 'connected_candidates',
          source: sourceEntity,
          source_type: sourceType,
          target_type: targetType,
          q: query,
          max_depth: String(maxDepth),
          limit: String(maxVisible),
        });
        return {
          key: `path-finder-connected:${sourceType}:${sourceEntity.toLowerCase()}:${targetType}:${query.toLowerCase()}:${maxDepth}`,
          url: apiUrl(`path_finder.php?${params.toString()}`),
          label: targetType,
          connected: true,
          fallback,
        };
      }

      return fallback;
    }

    return {
      key: 'taxonomy:items',
      url: taxonomyUrl(),
      label: 'TE',
      connected: false,
    };
  }

  function loadOptions(root, input) {
    const config = sourceConfig(root, input);
    if (config.provider) {
      if (cache.has(config.key)) {
        return cache.get(config.key);
      }
      const providerPromise = Promise.resolve(config.provider.loadOptions({
        query: String(input.value || '').trim(),
        entityType: currentEntityType(root),
        root,
      }))
        .then((items) => uniqueOptions(
          (Array.isArray(items) ? items : [])
            .map((item) => normalizeOption(item, false))
            .filter((item) => item.name),
        ).sort((left, right) => left.name.localeCompare(right.name, undefined, { sensitivity: 'base' })));
      cache.set(config.key, providerPromise);
      return providerPromise;
    }
    function fetchOptions(activeConfig) {
      return fetch(activeConfig.url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`autocomplete API HTTP ${response.status}`);
          }
          return response.json();
        })
        .then((payload) => {
          if (payload && payload.ok === false) {
            throw new Error(String(payload.error || 'autocomplete API failed'));
          }
          const options = Array.isArray(payload.items)
            ? payload.items.map((item) => normalizeOption(item, activeConfig.connected)).filter((item) => item.name)
            : [];
          if (activeConfig.connected) {
            return uniqueOptions(options);
          }
          return uniqueOptions(options).sort((left, right) => left.name.localeCompare(right.name, undefined, { sensitivity: 'base' }));
        });
    }

    if (cache.has(config.key)) {
      return cache.get(config.key);
    }
    const promise = fetchOptions(config)
      .then((options) => {
        if (!config.connected || options.length || !config.fallback) {
          return options;
        }
        if (cache.has(config.fallback.key)) {
          return cache.get(config.fallback.key);
        }
        const fallbackPromise = fetchOptions(config.fallback);
        cache.set(config.fallback.key, fallbackPromise);
        return fallbackPromise;
      })
      .catch((error) => {
        if (config.connected && config.fallback) {
          if (cache.has(config.fallback.key)) {
            return cache.get(config.fallback.key);
          }
          const fallbackPromise = fetchOptions(config.fallback);
          cache.set(config.fallback.key, fallbackPromise);
          return fallbackPromise;
        }
        throw error;
      });
    cache.set(config.key, promise);
    return promise;
  }

  function normalizeOption(item, connected) {
    if (typeof item === 'string') {
      return { name: item.trim() };
    }
    const option = item && typeof item === 'object' ? item : {};
    const normalized = {
      name: String(option.name || option.te || '').trim(),
    };
    if (connected) {
      const minHop = Number(option.min_hop || 0);
      normalized.min_hop = minHop >= 1 && minHop <= maxConnectedDepth ? minHop : null;
      normalized.path_count = Math.max(0, Number(option.path_count || 0));
      normalized.pmid_count = Math.max(0, Number(option.pmid_count || option.best_path_pmid_count || 0));
    }
    return normalized;
  }

  function uniqueOptions(options) {
    const seen = new Set();
    const unique = [];
    options.forEach((option) => {
      const key = option.name.toLowerCase();
      if (!key || seen.has(key)) {
        return;
      }
      seen.add(key);
      unique.push(option);
    });
    return unique;
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function optionHtml(name, query) {
    const text = String(name || '');
    const prefix = String(query || '').trim();
    if (!prefix || !text.toLowerCase().startsWith(prefix.toLowerCase())) {
      return `<span class="te-autocomplete-rest">${escapeHtml(text)}</span>`;
    }
    return [
      `<span class="te-autocomplete-match">${escapeHtml(text.slice(0, prefix.length))}</span>`,
      `<span class="te-autocomplete-rest">${escapeHtml(text.slice(prefix.length))}</span>`,
    ].join('');
  }

  function filteredOptions(root, options, query) {
    if (String(root.dataset.teAutocompleteSource || '').trim() === 'path-finder-entities') {
      return options.slice(0, maxVisible);
    }
    const prefix = String(query || '').trim().toLowerCase();
    if (!prefix) {
      return options.slice(0, maxVisible);
    }
    return options.filter((option) => option.name.toLowerCase().startsWith(prefix)).slice(0, maxVisible);
  }

  function groupLabel(minHop) {
    if (minHop === 1) {
      return 'Direct connection';
    }
    return minHop >= 2 && minHop <= maxConnectedDepth ? `${minHop}-hop path` : '';
  }

  function optionMeta(option) {
    if (!(option.min_hop >= 1 && option.min_hop <= maxConnectedDepth)) {
      return '';
    }
    const pathCount = Number(option.path_count || 0);
    const pmidCount = Number(option.pmid_count || 0);
    const parts = [groupLabel(option.min_hop)];
    parts.push(`${pathCount} Shortest Path${pathCount === 1 ? '' : 's'}`);
    parts.push(`${pmidCount} PMID${pmidCount === 1 ? '' : 's'}`);
    return `<span class="te-autocomplete-meta">${escapeHtml(parts.join(' | '))}</span>`;
  }

  function initAutocomplete(root) {
    const input = root.querySelector('[data-te-autocomplete]');
    const button = root.querySelector('[data-te-autocomplete-toggle]');
    const menu = root.querySelector('[data-te-autocomplete-menu]');
    if (!input || !button || !menu || root.dataset.teAutocompleteReady === '1') {
      return;
    }
    root.dataset.teAutocompleteReady = '1';

    let isOpen = false;
    let requestSerial = 0;

    function closeMenu() {
      isOpen = false;
      root.classList.remove('is-open');
      button.setAttribute('aria-expanded', 'false');
      menu.hidden = true;
      menu.innerHTML = '';
    }

    function dispatchSelection() {
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      input.dispatchEvent(new CustomEvent('te-autocomplete-select', { bubbles: true, detail: { value: input.value } }));
      if (input.dataset.teAutocompleteSubmit === 'true') {
        const form = input.form;
        if (form && typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else if (form) {
          form.submit();
        }
      }
    }

    function renderOptions(options) {
      const query = input.value;
      const matches = filteredOptions(root, options, query);
      if (!matches.length) {
        menu.innerHTML = `<div class="te-autocomplete-empty">No ${escapeHtml(sourceConfig(root, input).label)} names match this prefix.</div>`;
        return;
      }
      menu.innerHTML = matches.map((option) => {
        return [
          `<button type="button" class="te-autocomplete-option" data-te-name="${escapeHtml(option.name)}">`,
          optionHtml(option.name, query),
          optionMeta(option),
          '</button>',
        ].join('');
      }).join('');
    }

    function loadAndRender() {
      const serial = ++requestSerial;
      loadOptions(root, input)
        .then((options) => {
          if (serial === requestSerial) {
            renderOptions(options);
          }
        })
        .catch(() => {
          if (serial === requestSerial) {
            menu.innerHTML = `<div class="te-autocomplete-empty">${escapeHtml(sourceConfig(root, input).label)} name list is unavailable.</div>`;
          }
        });
    }

    function openMenu() {
      isOpen = true;
      root.classList.add('is-open');
      button.setAttribute('aria-expanded', 'true');
      menu.hidden = false;
      menu.innerHTML = `<div class="te-autocomplete-empty">Loading ${escapeHtml(sourceConfig(root, input).label)} names...</div>`;
      loadAndRender();
    }

    function refreshMenu() {
      if (!isOpen) {
        return;
      }
      loadAndRender();
    }

    button.addEventListener('click', () => {
      if (isOpen) {
        closeMenu();
      } else {
        input.focus();
        openMenu();
      }
    });
    input.addEventListener('focus', openMenu);
    input.addEventListener('input', refreshMenu);
    const control = typeControl(root);
    if (control) {
      control.addEventListener('change', () => {
        if (root.dataset.teAutocompleteClearOnTypeChange === 'true') {
          input.value = '';
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (isOpen) {
          menu.innerHTML = `<div class="te-autocomplete-empty">Loading ${escapeHtml(sourceConfig(root, input).label)} names...</div>`;
          refreshMenu();
        }
      });
    }
    [
      root.dataset.teAutocompleteConnectedSource,
      root.dataset.teAutocompleteConnectedSourceType,
      root.dataset.teAutocompleteConnectedTargetType,
      root.dataset.teAutocompleteConnectedMaxDepthSource,
    ].forEach((selector) => {
      const dependentControl = controlFromSelector(selector);
      if (!dependentControl || dependentControl === input || dependentControl === control) {
        return;
      }
      dependentControl.addEventListener('input', refreshMenu);
      dependentControl.addEventListener('change', refreshMenu);
    });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeMenu();
      }
    });
    menu.addEventListener('click', (event) => {
      const option = event.target.closest('[data-te-name]');
      if (!option) {
        return;
      }
      input.value = option.dataset.teName || '';
      closeMenu();
      dispatchSelection();
    });
    document.addEventListener('click', (event) => {
      if (!root.contains(event.target)) {
        closeMenu();
      }
    });
  }

  function initAll() {
    document.querySelectorAll('[data-te-autocomplete-root]').forEach(initAutocomplete);
  }

  function registerSource(name, provider) {
    const sourceName = String(name || '').trim();
    if (!sourceName || !provider || typeof provider.loadOptions !== 'function') return false;
    sourceProviders.set(sourceName, provider);
    cache.delete(`provider:${sourceName}`);
    return true;
  }

  window.TEKGTeAutocomplete = { initAll, registerSource };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll, { once: true });
  } else {
    initAll();
  }
})();
