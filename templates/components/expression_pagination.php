<?php $currentPage = (int)($browse['pagination']['page'] ?? 1); ?>
<?php $totalPages = max(1, (int)($browse['pagination']['total_pages'] ?? 1)); ?>
<?php $totalRows = (int)($browse['pagination']['total'] ?? 0); ?>
<?php $from = $totalRows === 0 ? 0 : (($currentPage - 1) * $pageSize) + 1; ?>
<?php $to = min($currentPage * $pageSize, $totalRows); ?>
<div class="expression-pagination">
  <form method="get" action="<?= htmlspecialchars(tekg_app_url('expression.php') . '#expressionResults', ENT_QUOTES, 'UTF-8') ?>" class="expression-page-size">
    <input type="hidden" name="keyword" value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="top_context" value="<?= htmlspecialchars($topContext, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="min_global_median" value="<?= htmlspecialchars((string)($minGlobalMedian ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="value_mode" value="<?= htmlspecialchars($valueMode, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="page" value="1">
    <span class="expression-page-size-label">Items Per Page:</span>
    <select class="expression-page-size-select" name="page_size" onchange="this.form.submit()">
      <?php foreach ([10, 20, 50, 100] as $sizeOption): ?>
        <option value="<?= $sizeOption ?>" <?= $pageSize === $sizeOption ? 'selected' : '' ?>><?= $sizeOption ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <div class="expression-page-status"><?= $from ?> - <?= $to ?> of <?= number_format($totalRows) ?></div>

  <form method="get" action="<?= htmlspecialchars(tekg_app_url('expression.php') . '#expressionResults', ENT_QUOTES, 'UTF-8') ?>" class="expression-page-jump">
    <input type="hidden" name="keyword" value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="top_context" value="<?= htmlspecialchars($topContext, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="min_global_median" value="<?= htmlspecialchars((string)($minGlobalMedian ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="page_size" value="<?= $pageSize ?>">
    <input type="hidden" name="value_mode" value="<?= htmlspecialchars($valueMode, ENT_QUOTES, 'UTF-8') ?>">
    <span class="expression-page-jump-label">Page</span>
    <input class="expression-page-jump-input" name="page" type="number" min="1" step="1" value="<?= $currentPage ?>">
  </form>

  <div class="expression-value-mode">
    <span class="expression-value-mode-label">Value</span>
    <div class="expression-value-mode-group">
      <?php foreach (['median' => 'Median', 'max' => 'Max', 'min' => 'Min'] as $modeKey => $modeLabel): ?>
        <a class="expression-value-mode-btn <?= $valueMode === $modeKey ? 'is-active' : '' ?>" href="<?= htmlspecialchars(tekg_expression_page_build_url(['value_mode' => $modeKey, 'page' => 1], 'expressionResults'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="expression-page-actions">
    <a class="expression-page-btn <?= $currentPage <= 1 ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars(tekg_expression_page_build_url(['page' => max(1, $currentPage - 1)], 'expressionResults'), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"></path></svg>
    </a>
    <a class="expression-page-btn <?= $currentPage >= $totalPages ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars(tekg_expression_page_build_url(['page' => min($totalPages, $currentPage + 1)], 'expressionResults'), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"></path></svg>
    </a>
  </div>
</div>
