<div id="search-summary" class="panel-body">
  <?php if ($repbase !== null): ?>
    <div><strong>Matched query: </strong><?= htmlspecialchars($repbase['matched'], ENT_QUOTES, 'UTF-8') ?></div>
    <div><strong>Entity type: </strong>TE</div>
    <div><strong>Name: </strong><?= htmlspecialchars($repbase['nm'] ?: $repbase['id'] ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
    <div><strong>Description: </strong><?= htmlspecialchars($repbase['description'] ?: 'No description', ENT_QUOTES, 'UTF-8') ?></div>
    <div><strong>Keywords: </strong><?= htmlspecialchars($repbase['keywords'] ?: 'No keywords', ENT_QUOTES, 'UTF-8') ?></div>
    <div><strong>Length: </strong><?= htmlspecialchars($repbase['length_bp'] !== null ? ((string) $repbase['length_bp']) . ' bp' : 'No length available', ENT_QUOTES, 'UTF-8') ?></div>
    <div><strong>Reference count: </strong><?= htmlspecialchars((string) ($repbase['reference_count'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
    <?php require __DIR__ . '/classification_path.php'; ?>
  <?php elseif ($query !== ''): ?>
    No structured TE summary is available for the current query yet.
  <?php else: ?>
    Search for a TE, disease, function, or PMID to view a concise summary here.
  <?php endif; ?>
</div>
