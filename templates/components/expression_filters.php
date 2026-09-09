<form class="expression-panel" method="get" action="<?= htmlspecialchars(tekg_app_url('expression.php'), ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="value_mode" value="<?= htmlspecialchars($valueMode, ENT_QUOTES, 'UTF-8') ?>">
  <h3>Filters</h3>
  <div class="expression-filter-grid">
    <div class="expression-filter-group">
      <div class="expression-filter-label">Keyword</div>
      <div class="te-autocomplete" data-te-autocomplete-root data-te-autocomplete-source="expression-catalog">
        <input class="expression-filter-input" type="text" name="keyword" value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search a TE such as L1HS or AluY" data-te-autocomplete data-te-autocomplete-submit="true">
        <button class="te-autocomplete-toggle" type="button" aria-label="Show TE names" aria-expanded="false" data-te-autocomplete-toggle></button>
        <div class="te-autocomplete-menu" data-te-autocomplete-menu hidden></div>
      </div>
    </div>

    <div class="expression-filter-group">
      <div class="expression-filter-label">Top Context Contains</div>
      <input class="expression-filter-input" type="text" name="top_context" value="<?= htmlspecialchars($topContext, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. skin, ESCC, lung">
    </div>

    <div class="expression-filter-group">
      <div class="expression-filter-label">Min Global Median</div>
      <input class="expression-filter-input" type="number" step="0.01" min="0" name="min_global_median" value="<?= htmlspecialchars((string)($minGlobalMedian ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="0.00">
    </div>

    <div class="expression-filter-group">
      <div class="expression-filter-label">Sort</div>
      <select class="expression-filter-select" name="sort">
        <?php foreach (($options['sort_options'] ?? []) as $option): ?>
          <?php $sortKey = (string)($option['key'] ?? 'default'); ?>
          <option value="<?= htmlspecialchars($sortKey, ENT_QUOTES, 'UTF-8') ?>" <?= $sort === $sortKey ? 'selected' : '' ?>><?= htmlspecialchars((string)($option['label'] ?? $sortKey), ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="expression-filter-actions">
      <button class="expression-filter-btn is-primary" type="submit">Apply</button>
      <a class="expression-filter-btn" href="<?= htmlspecialchars(site_url_with_state(tekg_app_url('expression.php'), $siteLang), ENT_QUOTES, 'UTF-8') ?>">Reset</a>
    </div>
  </div>
</form>
