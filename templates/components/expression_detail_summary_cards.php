<?php
if (!isset($summary) || !is_array($summary)) {
    return;
}

$datasetLabels = $datasetLabels ?? [];
?>
<div class="detail-summary-grid">
  <div class="detail-card"><span>Datasets</span><strong><?= htmlspecialchars($datasetLabels === [] ? '-' : implode(' / ', $datasetLabels), ENT_QUOTES, 'UTF-8') ?></strong></div>
  <div class="detail-card"><span>Top Global Context</span><strong><?= htmlspecialchars((string) ((($summary['global_top_context_median_full_name'] ?? '') ?: ($summary['global_top_context_median'] ?? '-'))), ENT_QUOTES, 'UTF-8') ?></strong></div>
  <div class="detail-card"><span>Top Global Dataset</span><strong><?= htmlspecialchars((string) ($summary['global_top_context_median_dataset'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
  <div class="detail-card"><span>Global Median</span><strong><?= htmlspecialchars(exd_fmt($summary['global_top_context_median_value'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong></div>
</div>
