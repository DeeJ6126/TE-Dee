<section id="search-graph-panel" class="graph-panel is-collapsed">
  <div class="graph-panel-head">
    <h3>Local Graph</h3>
    <button id="search-graph-toggle" type="button" class="graph-toggle" aria-expanded="false" aria-controls="search-graph-frame-wrap" title="Expand local graph"><span id="search-graph-toggle-icon" aria-hidden="true">&#9662;</span></button>
  </div>
  <div id="search-graph-frame-wrap" class="graph-frame">
    <iframe
      id="search-g6-frame"
      src="<?= htmlspecialchars($searchGraphSrc, ENT_QUOTES, 'UTF-8') ?>"
      title="Search graph (G6)"
    ></iframe>
  </div>
</section>
