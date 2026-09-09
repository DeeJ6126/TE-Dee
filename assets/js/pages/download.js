(() => {
  const header = document.getElementById('protoHeader');
  function syncHeader() {
    if (!header) return;
    header.classList.toggle('is-scrolled', window.scrollY > 12);
  }
  window.addEventListener('scroll', syncHeader, { passive: true });
  syncHeader();

  const dataNode = document.getElementById('download-page-data');
  const rows = dataNode ? JSON.parse(dataNode.textContent || '[]') : [];
  const body = document.getElementById('download-table-body');
  const emptyState = document.getElementById('download-empty');
  const categoryButtons = Array.from(document.querySelectorAll('[data-download-category]'));
  if (!body || !emptyState) return;

  let currentCategory = 'All';

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderFiles(files) {
    return (files || []).map((file) => {
      const size = file.size ? `<span class="download-file-size">${escapeHtml(file.size)}</span>` : '';
      return `
        <li>
          <a class="file-link" href="${escapeHtml(file.path)}" download="${escapeHtml(file.filename)}">${escapeHtml(file.filename)}</a>
          ${size}
        </li>
      `;
    }).join('');
  }

  function closeOpenRows() {
    body.querySelectorAll('.dataset-row.is-open').forEach((row) => {
      row.classList.remove('is-open');
      row.querySelector('.dataset-toggle')?.setAttribute('aria-expanded', 'false');
    });
    body.querySelectorAll('.dataset-detail-row').forEach((row) => {
      row.hidden = true;
    });
  }

  function render() {
    const items = rows.filter((row) => currentCategory === 'All' || row.category === currentCategory);
    body.innerHTML = '';
    emptyState.hidden = items.length !== 0;

    items.forEach((row, index) => {
      const detailId = `download-detail-${index}`;
      const tr = document.createElement('tr');
      tr.className = 'dataset-row';
      tr.innerHTML = `
        <td class="dataset-cell">
          <button class="dataset-toggle" type="button" aria-expanded="false" aria-controls="${detailId}">
            <span class="dataset-title-line">
              <svg class="dataset-caret" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M5.2 2.8 10.4 8l-5.2 5.2-.9-.9L8.6 8 4.3 3.7z"/></svg>
              <span>${escapeHtml(row.dataset)}</span>
            </span>
          </button>
        </td>
        <td>${escapeHtml(row.contents)}</td>
        <td>${escapeHtml(row.records)}</td>
        <td>${escapeHtml(row.format)}</td>
        <td>${escapeHtml(row.version)}</td>
      `;

      const detailRow = document.createElement('tr');
      detailRow.id = detailId;
      detailRow.className = 'dataset-detail-row';
      detailRow.hidden = true;
      detailRow.innerHTML = `
        <td colspan="5">
          <div class="dataset-detail-inner">
            <p>${escapeHtml(row.description)}</p>
            <ul class="download-file-list">${renderFiles(row.files)}</ul>
          </div>
        </td>
      `;

      const toggle = tr.querySelector('.dataset-toggle');
      toggle.addEventListener('click', () => {
        const willOpen = !tr.classList.contains('is-open');
        closeOpenRows();
        if (willOpen) {
          tr.classList.add('is-open');
          toggle.setAttribute('aria-expanded', 'true');
          detailRow.hidden = false;
        }
      });

      body.appendChild(tr);
      body.appendChild(detailRow);
    });
  }

  categoryButtons.forEach((button) => {
    button.addEventListener('click', () => {
      currentCategory = button.dataset.downloadCategory || 'All';
      categoryButtons.forEach((item) => item.classList.toggle('is-active', item === button));
      render();
    });
  });

  render();
})();
