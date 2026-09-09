(() => {
  const dataNode = document.getElementById('browse-page-data');
  const config = dataNode ? JSON.parse(dataNode.textContent || '{}') : {};
  const browseApiUrl = String(config.browseApiUrl || '');
  const browseSearchBase = String(config.browseSearchBase || '');
  let browseRows = [];
  let filteredRows = [];
  let pageSize = 10;
  let currentPage = 1;
  let loadFailed = false;

  const keywordInput = document.getElementById('browseKeyword');
  const autocompleteRoot = keywordInput ? keywordInput.closest('[data-te-autocomplete-root]') : null;
  const autocompleteToggle = autocompleteRoot ? autocompleteRoot.querySelector('[data-te-autocomplete-toggle]') : null;
  const classSelect = document.getElementById('browseClass');
  const familySelect = document.getElementById('browseFamily');
  const subtypeSelect = document.getElementById('browseSubtype');
  const applyBtn = document.getElementById('browseApplyBtn');
  const resetBtn = document.getElementById('browseResetBtn');
  const prevBtn = document.getElementById('browsePrevBtn');
  const nextBtn = document.getElementById('browseNextBtn');
  const pageSizeSelect = document.getElementById('browsePageSize');
  const pageJumpInput = document.getElementById('browsePageJump');
  const pageStatus = document.getElementById('browsePageStatus');
  const tableBody = document.getElementById('browseTableBody');
  const emptyState = document.getElementById('browseEmpty');
  const controls = [keywordInput, autocompleteToggle, classSelect, familySelect, subtypeSelect, applyBtn, resetBtn, pageSizeSelect, pageJumpInput, prevBtn, nextBtn];

  if (!browseApiUrl || !browseSearchBase || controls.some((control) => !control) || !pageStatus || !tableBody || !emptyState) return;

  function normalizeItem(item) {
    const row = item && typeof item === 'object' ? item : {};
    return {
      name: String(row.name || '').trim(),
      className: String(row.className || '').trim(),
      family: String(row.family || '').trim(),
      subtype: String(row.subtype || '').trim(),
      description: String(row.description || '').trim(),
      lengthBp: row.lengthBp === null ? null : Number(row.lengthBp),
      referenceCount: Number(row.referenceCount || 0),
      keywords: Array.isArray(row.keywords) ? row.keywords.map(String) : [],
    };
  }

  const browseCatalogPromise = fetch(browseApiUrl, {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
    cache: 'no-store',
  })
    .then((response) => {
      if (!response.ok) throw new Error(`Browse API HTTP ${response.status}`);
      return response.json();
    })
    .then((payload) => {
      if (!payload || payload.ok !== true || payload.source !== 'mysql' || !Array.isArray(payload.items)) {
        throw new Error('Browse API returned an invalid catalog payload');
      }
      const rows = payload.items.map(normalizeItem);
      const declaredCount = Number(payload.catalog && payload.catalog.rowCount);
      const names = new Set(rows.map((row) => row.name.toLowerCase()));
      if (rows.length !== 276 || declaredCount !== rows.length || names.size !== rows.length || rows.some((row) => !row.name)) {
        throw new Error('Browse API returned an incomplete catalog');
      }
      return rows;
    });

  if (window.TEKGTeAutocomplete) {
    window.TEKGTeAutocomplete.registerSource('browse-catalog', {
      label: 'Browse TE',
      loadOptions: () => browseCatalogPromise.then((rows) => rows.map((row) => ({ name: row.name }))),
    });
  }

  function setControlsEnabled(enabled) {
    [keywordInput, autocompleteToggle, classSelect, familySelect, subtypeSelect, applyBtn, resetBtn, pageSizeSelect].forEach((control) => {
      control.disabled = !enabled;
    });
  }

  function fillSelect(select, values) {
    values.forEach((value) => {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = value;
      select.appendChild(option);
    });
  }

  function uniqueValues(key) {
    return [...new Set(browseRows.map((row) => row[key]).filter(Boolean))].sort((a, b) => a.localeCompare(b));
  }

  function createCell(text, className = '') {
    const td = document.createElement('td');
    td.textContent = text;
    if (className) td.className = className;
    td.title = text || '';
    return td;
  }

  function createDescriptionCell(text) {
    const td = document.createElement('td');
    td.className = 'browse-description-cell';
    td.textContent = text || '-';
    td.title = text || '';
    return td;
  }

  function renderRows() {
    tableBody.innerHTML = '';
    if (loadFailed) return;

    const total = filteredRows.length;
    const totalPages = total === 0 ? 1 : Math.ceil(total / pageSize);
    currentPage = Math.max(1, Math.min(currentPage, totalPages));
    const startIndex = (currentPage - 1) * pageSize;
    const pageRows = filteredRows.slice(startIndex, startIndex + pageSize);

    pageRows.forEach((row) => {
      const tr = document.createElement('tr');
      const targetUrl = new URL(browseSearchBase, window.location.origin);
      targetUrl.searchParams.set('q', row.name);
      targetUrl.searchParams.set('type', 'TE');

      const nameTd = document.createElement('td');
      const link = document.createElement('a');
      link.className = 'browse-row-link';
      link.href = targetUrl.toString();
      link.textContent = row.name;
      nameTd.appendChild(link);
      nameTd.className = 'browse-name-cell';
      nameTd.title = row.name;
      tr.appendChild(nameTd);
      tr.appendChild(createCell(row.className || '-', 'browse-meta-cell'));
      tr.appendChild(createCell(row.family || '-', 'browse-meta-cell'));
      tr.appendChild(createCell(row.subtype || '-', 'browse-meta-cell'));
      tr.appendChild(createDescriptionCell(row.description || '-'));
      tr.appendChild(createCell(row.lengthBp ? `${row.lengthBp} bp` : '-', 'browse-meta-cell'));
      tableBody.appendChild(tr);
    });

    emptyState.textContent = 'No TE records match the current search and filter combination. Try clearing one or more conditions.';
    emptyState.classList.toggle('is-visible', total === 0);
    pageStatus.textContent = total === 0
      ? '0 - 0 of 0'
      : `${startIndex + 1} - ${Math.min(startIndex + pageSize, total)} of ${total}`;
    pageJumpInput.value = total === 0 ? '1' : String(currentPage);
    prevBtn.disabled = currentPage <= 1 || total === 0;
    nextBtn.disabled = currentPage >= totalPages || total === 0;
    pageJumpInput.disabled = total === 0;
  }

  function applyFilters() {
    const keyword = (keywordInput.value || '').trim().toLowerCase();
    const classValue = classSelect.value;
    const familyValue = familySelect.value;
    const subtypeValue = subtypeSelect.value;

    filteredRows = browseRows.filter((row) => {
      const haystack = [row.name, row.className, row.family, row.subtype, row.description, ...row.keywords].join(' ').toLowerCase();
      if (keyword && !haystack.includes(keyword)) return false;
      if (classValue && row.className !== classValue) return false;
      if (familyValue && row.family !== familyValue) return false;
      if (subtypeValue && row.subtype !== subtypeValue) return false;
      return true;
    });
    currentPage = 1;
    renderRows();
  }

  function resetFilters() {
    keywordInput.value = '';
    classSelect.value = '';
    familySelect.value = '';
    subtypeSelect.value = '';
    filteredRows = browseRows.slice();
    currentPage = 1;
    renderRows();
  }

  function jumpToPage() {
    const totalPages = filteredRows.length === 0 ? 1 : Math.ceil(filteredRows.length / pageSize);
    const requestedPage = Number.parseInt(pageJumpInput.value || '1', 10);
    if (Number.isNaN(requestedPage)) {
      pageJumpInput.value = String(currentPage);
      return;
    }
    currentPage = Math.max(1, Math.min(requestedPage, totalPages));
    renderRows();
  }

  setControlsEnabled(false);
  prevBtn.disabled = true;
  nextBtn.disabled = true;
  pageJumpInput.disabled = true;
  pageStatus.textContent = 'Loading catalog...';
  emptyState.textContent = 'Loading Browse catalog...';
  emptyState.classList.add('is-visible');

  applyBtn.addEventListener('click', applyFilters);
  resetBtn.addEventListener('click', resetFilters);
  prevBtn.addEventListener('click', () => { if (currentPage > 1) { currentPage -= 1; renderRows(); } });
  nextBtn.addEventListener('click', () => {
    const totalPages = filteredRows.length === 0 ? 1 : Math.ceil(filteredRows.length / pageSize);
    if (currentPage < totalPages) { currentPage += 1; renderRows(); }
  });
  pageSizeSelect.addEventListener('change', () => {
    const nextSize = Number.parseInt(pageSizeSelect.value || '10', 10);
    pageSize = Number.isNaN(nextSize) ? 10 : nextSize;
    currentPage = 1;
    renderRows();
  });
  pageJumpInput.addEventListener('keydown', (event) => { if (event.key === 'Enter') jumpToPage(); });
  keywordInput.addEventListener('keydown', (event) => { if (event.key === 'Enter') applyFilters(); });
  keywordInput.addEventListener('te-autocomplete-select', applyFilters);
  [classSelect, familySelect, subtypeSelect].forEach((select) => select.addEventListener('change', applyFilters));

  browseCatalogPromise
    .then((rows) => {
      browseRows = rows;
      filteredRows = rows.slice();
      fillSelect(classSelect, uniqueValues('className'));
      fillSelect(familySelect, uniqueValues('family'));
      fillSelect(subtypeSelect, uniqueValues('subtype'));
      setControlsEnabled(true);
      loadFailed = false;
      renderRows();
    })
    .catch(() => {
      loadFailed = true;
      setControlsEnabled(false);
      prevBtn.disabled = true;
      nextBtn.disabled = true;
      pageJumpInput.disabled = true;
      tableBody.innerHTML = '';
      pageStatus.textContent = 'Catalog unavailable';
      emptyState.textContent = 'Catalog unavailable. Browse records could not be loaded from MySQL.';
      emptyState.classList.add('is-visible');
    });
})();
