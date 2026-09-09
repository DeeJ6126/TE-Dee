<section id="search-variants-panel" class="data-panel variants-panel">
  <div class="variants-panel-head">
    <div>
      <h3>Variants</h3>
    </div>
    <div id="search-variants-status" class="variants-status" role="status" aria-live="polite">Loading</div>
  </div>
  <div id="search-variants-view-tabs" class="variants-tabs variants-view-tabs" role="tablist" aria-label="Variant detail level">
    <button type="button" class="variants-tab is-active" role="tab" aria-selected="true" data-variant-view="variant">Variants</button>
    <button type="button" class="variants-tab" role="tab" aria-selected="false" data-variant-view="evidence">Evidence rows</button>
  </div>
  <div id="search-variants-table-wrap" class="variants-table-wrap">
    <div class="variants-loading-skeleton" aria-label="Loading variants">
      <span></span><span></span><span></span><span></span>
    </div>
  </div>
  <div id="search-variants-pagination" class="variants-pagination" hidden>
    <div class="variants-page-size">
      <span class="variants-page-size-label">Items per page:</span>
      <select id="search-variants-page-size" class="variants-page-size-select" aria-label="Items per page">
        <option value="10" selected>10</option>
        <option value="25">25</option>
        <option value="50">50</option>
      </select>
    </div>
    <div id="search-variants-page-status" class="variants-page-status">0 - 0 of 0</div>
    <div class="variants-page-jump">
      <span class="variants-page-jump-label">Page</span>
      <input id="search-variants-page-jump" class="variants-page-jump-input" type="number" min="1" step="1" value="1" aria-label="Page number">
    </div>
    <div class="variants-page-actions">
      <button id="search-variants-prev" class="variants-page-button" type="button" aria-label="Previous page">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"></path></svg>
      </button>
      <button id="search-variants-next" class="variants-page-button" type="button" aria-label="Next page">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"></path></svg>
      </button>
    </div>
  </div>
</section>
