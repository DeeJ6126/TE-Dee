<?php
if (!isset($classificationSession) || !is_array($classificationSession) || empty($classificationSession['path']) || !is_array($classificationSession['path'])) {
    return;
}

$classificationPath = array_values(array_filter(
    $classificationSession['path'],
    static fn ($item) => is_array($item) && trim((string) ($item['display_label'] ?? '')) !== ''
));

if ($classificationPath === []) {
    return;
}

$classificationLastIndex = count($classificationPath) - 1;
?>
<div class="summary-classification">
  <h4 class="summary-classification-title">Classification</h4>
  <div class="summary-classification-stage" aria-label="TE classification path">
    <div class="summary-classification-flow">
      <?php foreach ($classificationPath as $index => $node): ?>
        <span class="summary-classification-node<?= $index === $classificationLastIndex ? ' is-current' : '' ?>">
          <?= htmlspecialchars((string) ($node['display_label'] ?? $node['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </span>
        <?php if ($index < $classificationLastIndex): ?>
          <span class="summary-classification-connector" aria-hidden="true">&rarr;</span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</div>
