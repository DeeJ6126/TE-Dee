<?php
require_once __DIR__ . '/path_config.php';
$pageTitle = TEKG_PRODUCT_NAME . ' Expression';
$activePage = 'expression';
$protoCurrentPath = tekg_app_url('expression.php');
$protoSubtitle = 'Expression-oriented TE exploration workflows';
$pageExtraStylesheets = [
    tekg_assets_url('css/components/te-autocomplete.css'),
    tekg_assets_url('css/pages/expression.css'),
];

require __DIR__ . '/api/expression_data.php';

function tekg_expression_page_int(string $key, int $default, int $min = 1, ?int $max = null): int
{
    $value = $_GET[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    if (!is_numeric((string)$value)) {
        return $default;
    }
    $parsed = (int)$value;
    if ($parsed < $min) {
        $parsed = $min;
    }
    if ($max !== null && $parsed > $max) {
        $parsed = $max;
    }
    return $parsed;
}

function tekg_expression_page_float_or_null(string $key): ?string
{
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return (string)(float)$value;
}

function tekg_expression_page_build_url(array $overrides = [], ?string $hash = null): string
{
    global $siteLang, $valueMode;

    $params = [
        'keyword' => trim((string)($_GET['keyword'] ?? '')),
        'top_context' => trim((string)($_GET['top_context'] ?? '')),
        'min_global_median' => trim((string)($_GET['min_global_median'] ?? '')),
        'sort' => trim((string)($_GET['sort'] ?? 'default')),
        'value_mode' => trim((string)($_GET['value_mode'] ?? $valueMode ?? 'median')),
        'page_size' => (string)tekg_expression_page_int('page_size', 10, 5, 100),
        'page' => (string)tekg_expression_page_int('page', 1, 1),
    ];

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = (string)$value;
        }
    }

    $params = array_filter($params, static fn (string $value): bool => $value !== '');

    $url = tekg_app_url('expression.php') . '?' . http_build_query($params);
    if ($hash !== null && $hash !== '') {
        $url .= '#' . rawurlencode($hash);
    }
    return $url;
}

function tekg_expression_format_number($value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float)$value, $decimals, '.', ',');
}

function tekg_expression_metric_label(string $valueMode): string
{
    return match (tekg_expression_normalize_browse_value_mode($valueMode)) {
        'max' => 'Max',
        'min' => 'Min',
        default => 'Median',
    };
}

function tekg_expression_row_context_name(array $row, string $datasetKey, string $valueMode): string
{
    $mode = tekg_expression_normalize_browse_value_mode($valueMode);
    $primary = $row[$datasetKey . '_top_context_' . $mode . '_full_name'] ?? null;
    if ($primary !== null && trim((string)$primary) !== '') {
        return trim((string)$primary);
    }

    $fallback = $row[$datasetKey . '_top_context_' . $mode] ?? null;
    if ($fallback !== null && trim((string)$fallback) !== '') {
        return trim((string)$fallback);
    }

    return '-';
}

function tekg_expression_row_context_value(array $row, string $datasetKey, string $valueMode)
{
    $mode = tekg_expression_normalize_browse_value_mode($valueMode);
    return $row[$datasetKey . '_top_context_' . $mode . '_value'] ?? null;
}

function tekg_expression_row_cv_payload(array $row): array
{
    $candidates = [
        'normal_tissue' => [
            'label' => 'Normal Tissue',
            'value' => $row['normal_tissue_top_context_median_value'] ?? null,
            'cv' => $row['normal_tissue_cv_of_median'] ?? null,
        ],
        'normal_cell_line' => [
            'label' => 'Normal Cell Line',
            'value' => $row['normal_cell_line_top_context_median_value'] ?? null,
            'cv' => $row['normal_cell_line_cv_of_median'] ?? null,
        ],
        'cancer_cell_line' => [
            'label' => 'Cancer Cell Line',
            'value' => $row['cancer_cell_line_top_context_median_value'] ?? null,
            'cv' => $row['cancer_cell_line_cv_of_median'] ?? null,
        ],
    ];

    uasort($candidates, static function (array $a, array $b): int {
        return ($b['value'] <=> $a['value']);
    });

    $best = reset($candidates);
    return is_array($best) ? $best : ['label' => '-', 'value' => null, 'cv' => null];
}

$keyword = trim((string)($_GET['keyword'] ?? ''));
$topContext = trim((string)($_GET['top_context'] ?? ''));
$minGlobalMedian = tekg_expression_page_float_or_null('min_global_median');
$sort = tekg_expression_normalize_sort((string)($_GET['sort'] ?? 'default'));
$valueMode = tekg_expression_normalize_browse_value_mode((string)($_GET['value_mode'] ?? 'median'));
$page = tekg_expression_page_int('page', 1, 1);
$pageSize = tekg_expression_page_int('page_size', 10, 5, 100);

$filters = [
    'keyword' => $keyword,
    'top_context' => $topContext,
    'min_global_median' => $minGlobalMedian,
];

$options = [];
$browse = [
    'rows' => [],
    'pagination' => ['page' => 1, 'page_size' => $pageSize, 'total' => 0, 'total_pages' => 0],
    'filters' => $filters,
    'sort' => $sort,
];
$errorMessage = null;
$expressionCatalogApiUrl = tekg_api_url('expression_catalog.php?view=items');

try {
    $options = tekg_expression_fetch_filter_options();
    $browse = tekg_expression_fetch_browse_page($filters, $page, $pageSize, $sort);
    $browse['rows'] = tekg_expression_enrich_browse_rows($browse['rows']);
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

require __DIR__ . '/head.php';
?>
      <section class="expression-shell">
        <div class="proto-container">
          <h1 class="expression-page-title">Expression</h1>

          <div class="expression-layout">
            <?php require __DIR__ . '/templates/components/expression_filters.php'; ?>

            <section class="expression-results" id="expressionResults">
              <?php if ($errorMessage !== null): ?>
                <div class="expression-error">Expression browse query failed: <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
              <?php elseif (empty($browse['rows'])): ?>
                <div class="expression-empty">No TE matched the current filters. Try clearing the keyword or relaxing the minimum global median threshold.</div>
              <?php else: ?>
                <div class="expression-table-wrap">
                  <table class="expression-table">
                    <colgroup>
                      <col style="width: 18%">
                      <col style="width: 24%">
                      <col style="width: 24%">
                      <col style="width: 24%">
                      <col style="width: 10%">
                    </colgroup>
                    <thead>
                      <tr>
                        <th>TE Name</th>
                        <th>Top Normal Tissue</th>
                        <th>Top Normal Cell Line</th>
                        <th>Top Cancer Type</th>
                        <th>CV</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($browse['rows'] as $row): ?>
                        <?php $cvPayload = tekg_expression_row_cv_payload($row); ?>
                        <?php $detailUrl = site_url_with_state(tekg_app_url('expression_detail.php'), $siteLang, null, ['te' => (string)$row['te_name'], 'metric' => 'median', 'sort' => 'default']); ?>
                        <tr>
                          <td class="expression-name-cell"><a class="expression-te-name" href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$row['te_name'], ENT_QUOTES, 'UTF-8') ?></a></td>
                          <td class="expression-context-cell">
                            <div class="expression-context-stack">
                              <strong><?= htmlspecialchars(tekg_expression_row_context_name($row, 'normal_tissue', $valueMode), ENT_QUOTES, 'UTF-8') ?></strong>
                              <small><?= htmlspecialchars(tekg_expression_metric_label($valueMode) . ' ' . tekg_expression_format_number(tekg_expression_row_context_value($row, 'normal_tissue', $valueMode)), ENT_QUOTES, 'UTF-8') ?></small>
                            </div>
                          </td>
                          <td class="expression-context-cell">
                            <div class="expression-context-stack">
                              <strong><?= htmlspecialchars(tekg_expression_row_context_name($row, 'normal_cell_line', $valueMode), ENT_QUOTES, 'UTF-8') ?></strong>
                              <small><?= htmlspecialchars(tekg_expression_metric_label($valueMode) . ' ' . tekg_expression_format_number(tekg_expression_row_context_value($row, 'normal_cell_line', $valueMode)), ENT_QUOTES, 'UTF-8') ?></small>
                            </div>
                          </td>
                          <td class="expression-context-cell">
                            <div class="expression-context-stack">
                              <strong><?= htmlspecialchars(tekg_expression_row_context_name($row, 'cancer_cell_line', $valueMode), ENT_QUOTES, 'UTF-8') ?></strong>
                              <small><?= htmlspecialchars(tekg_expression_metric_label($valueMode) . ' ' . tekg_expression_format_number(tekg_expression_row_context_value($row, 'cancer_cell_line', $valueMode)), ENT_QUOTES, 'UTF-8') ?></small>
                            </div>
                          </td>
                          <td class="expression-cv-cell">
                            <div class="expression-context-stack">
                              <strong><?= htmlspecialchars(tekg_expression_format_number($cvPayload['cv'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong>
                              <small><?= htmlspecialchars((string)($cvPayload['label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></small>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php require __DIR__ . '/templates/components/expression_pagination.php'; ?>
              <?php endif; ?>
            </section>
          </div>
        </div>
      </section>
      <script id="expression-page-data" type="application/json"><?= json_encode(['expressionCatalogApiUrl' => $expressionCatalogApiUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/components/te-autocomplete.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/expression.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require __DIR__ . '/foot.php'; ?>

