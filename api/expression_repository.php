<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/path_config.php';

function tekg_expression_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $local = [];
    $path = __DIR__ . '/config.local.php';
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $local = $loaded;
        }
    }

    $config = [
        'host' => tekg_expression_pick_value($local, ['mysql_host'], ['MYSQL_HOST_BIOLOGY', 'MYSQL_HOST'], '127.0.0.1'),
        'port' => (int)tekg_expression_pick_value($local, ['mysql_port'], ['MYSQL_PORT_BIOLOGY', 'MYSQL_PORT'], '3306'),
        'database' => tekg_expression_pick_value($local, ['mysql_expression_database', 'mysql_database_expression', 'mysql_database'], ['MYSQL_EXPRESSION_DATABASE', 'MYSQL_DATABASE_EXPRESSION', 'MYSQL_DATABASE'], 'tekg_expression'),
        'user' => tekg_expression_pick_value($local, ['mysql_user'], ['MYSQL_USER_BIOLOGY', 'MYSQL_USER'], 'root'),
        'password' => tekg_expression_pick_value($local, ['mysql_password'], ['MYSQL_PASSWORD_BIOLOGY', 'MYSQL_PASSWORD'], ''),
        'charset' => tekg_expression_pick_value($local, ['mysql_charset'], ['MYSQL_CHARSET_BIOLOGY', 'MYSQL_CHARSET'], 'utf8mb4'),
    ];

    return $config;
}

function tekg_expression_pick_value(array $local, array $localKeys, array $envNames, string $default = ''): string
{
    foreach ($localKeys as $key) {
        if (array_key_exists($key, $local) && trim((string)$local[$key]) !== '') {
            return trim((string)$local[$key]);
        }
    }

    foreach ($envNames as $name) {
        $value = getenv($name);
        if ($value !== false && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }

    return $default;
}

function tekg_expression_db(): mysqli
{
    static $db = null;
    if ($db instanceof mysqli) {
        return $db;
    }

    if (!extension_loaded('mysqli')) {
        throw new RuntimeException('PHP mysqli extension is required for Expression data access.');
    }

    $cfg = tekg_expression_config();
    mysqli_report(MYSQLI_REPORT_OFF);

    $db = mysqli_init();
    if (!$db) {
        throw new RuntimeException('Failed to initialize mysqli for Expression data access.');
    }

    $connected = @$db->real_connect(
        $cfg['host'],
        $cfg['user'],
        $cfg['password'],
        $cfg['database'],
        $cfg['port']
    );

    if (!$connected) {
        $message = mysqli_connect_error() ?: $db->connect_error ?: 'Unknown MySQL connection failure';
        throw new RuntimeException('Expression MySQL connection failed: ' . $message);
    }

    if (!$db->set_charset($cfg['charset'])) {
        throw new RuntimeException('Failed to set MySQL charset to ' . $cfg['charset']);
    }

    return $db;
}

function tekg_expression_normalize_metric(?string $metric): string
{
    $value = strtolower(trim((string)$metric));
    return match ($value) {
        'max', 'max_value' => 'max',
        'mean', 'average', 'avg', 'mean_value' => 'mean',
        default => 'median',
    };
}

function tekg_expression_normalize_sort(?string $sort): string
{
    $value = strtolower(trim((string)$sort));
    return match ($value) {
        'high_to_low', 'desc', 'value_desc' => 'high_to_low',
        'low_to_high', 'asc', 'value_asc' => 'low_to_high',
        default => 'default',
    };
}

function tekg_expression_normalize_dataset_key(?string $datasetKey): ?string
{
    $value = strtolower(trim((string)$datasetKey));
    return match ($value) {
        'normal_tissue' => 'normal_tissue',
        'normal_cell_line' => 'normal_cell_line',
        'cancer_cell_line' => 'cancer_cell_line',
        default => null,
    };
}

function tekg_expression_metric_column(string $metric): string
{
    return match (tekg_expression_normalize_metric($metric)) {
        'max' => 'max_value',
        'mean' => 'mean_value',
        default => 'median_value',
    };
}

function tekg_expression_browse_sort_sql(string $sort): string
{
    return match (tekg_expression_normalize_sort($sort)) {
        'high_to_low' => 'global_top_context_median_value DESC, te_name ASC',
        'low_to_high' => 'global_top_context_median_value ASC, te_name ASC',
        default => 'te_name ASC',
    };
}

function tekg_expression_prepare_and_execute(string $sql, array $params = [], ?string $types = null): mysqli_stmt
{
    $db = tekg_expression_db();
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('MySQL prepare failed: ' . $db->error);
    }

    if ($params !== []) {
        $bindTypes = $types ?? tekg_expression_guess_param_types($params);
        $values = [$bindTypes];
        foreach ($params as $param) {
            $values[] = $param;
        }
        $refs = [];
        foreach ($values as $index => $value) {
            $refs[$index] = &$values[$index];
        }
        if (!call_user_func_array([$stmt, 'bind_param'], $refs)) {
            $error = $stmt->error ?: 'Unknown bind_param failure';
            $stmt->close();
            throw new RuntimeException('MySQL bind_param failed: ' . $error);
        }
    }

    if (!$stmt->execute()) {
        $error = $stmt->error ?: 'Unknown execute failure';
        $stmt->close();
        throw new RuntimeException('MySQL execute failed: ' . $error);
    }

    return $stmt;
}

function tekg_expression_guess_param_types(array $params): string
{
    $types = '';
    foreach ($params as $param) {
        if (is_int($param)) {
            $types .= 'i';
        } elseif (is_float($param)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }
    return $types;
}

function tekg_expression_fetch_all(string $sql, array $params = [], ?string $types = null): array
{
    $stmt = tekg_expression_prepare_and_execute($sql, $params, $types);
    $result = $stmt->get_result();
    if (!$result) {
        $error = $stmt->error ?: 'Result retrieval failed';
        $stmt->close();
        throw new RuntimeException('MySQL get_result failed: ' . $error);
    }

    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function tekg_expression_fetch_one(string $sql, array $params = [], ?string $types = null): ?array
{
    $rows = tekg_expression_fetch_all($sql, $params, $types);
    return $rows[0] ?? null;
}

function tekg_expression_fetch_catalog_items(string $query = '', int $limit = 180): array
{
    $query = trim($query);
    $limit = max(1, min(180, $limit));
    $where = "te_name IS NOT NULL AND TRIM(te_name) <> ''";
    $params = [];
    $types = '';
    if ($query !== '') {
        $where .= ' AND te_name LIKE ?';
        $params[] = '%' . $query . '%';
        $types .= 's';
    }
    $params[] = $limit;
    $types .= 'i';

    $rows = tekg_expression_fetch_all(
        "SELECT TRIM(te_name) AS name
         FROM expression_browse_summary
         WHERE {$where}
         ORDER BY name ASC
         LIMIT ?",
        $params,
        $types
    );

    return array_values(array_filter(array_map(
        static fn(array $row): array => ['name' => trim((string)($row['name'] ?? ''))],
        $rows
    ), static fn(array $item): bool => $item['name'] !== ''));
}

function tekg_expression_resolve_te_name(string $teName): ?string
{
    $trimmed = trim($teName);
    if ($trimmed === '') {
        return null;
    }

    $exact = tekg_expression_fetch_one(
        'SELECT te_name FROM expression_browse_summary WHERE te_name = ? LIMIT 1',
        [$trimmed],
        's'
    );
    if (is_array($exact)) {
        return (string)$exact['te_name'];
    }

    $ci = tekg_expression_fetch_one(
        'SELECT te_name FROM expression_browse_summary WHERE LOWER(te_name) = LOWER(?) LIMIT 1',
        [$trimmed],
        's'
    );
    if (is_array($ci)) {
        return (string)$ci['te_name'];
    }

    return null;
}

function tekg_expression_fetch_filter_options(): array
{
    $catalogRows = tekg_expression_fetch_all(
        'SELECT dataset_key, dataset_label, dataset_order, context_label, context_full_name, context_order, run_count
         FROM expression_context_catalog
         ORDER BY dataset_order ASC, context_order ASC'
    );

    $datasets = [];
    $contextsByDataset = [];
    foreach ($catalogRows as $row) {
        $datasetKey = (string)$row['dataset_key'];
        if (!isset($datasets[$datasetKey])) {
            $datasets[$datasetKey] = [
                'dataset_key' => $datasetKey,
                'dataset_label' => (string)$row['dataset_label'],
                'dataset_order' => (int)$row['dataset_order'],
            ];
            $contextsByDataset[$datasetKey] = [];
        }
        $contextsByDataset[$datasetKey][] = [
            'context_label' => (string)$row['context_label'],
            'context_full_name' => (string)$row['context_full_name'],
            'context_order' => (int)$row['context_order'],
            'run_count' => (int)$row['run_count'],
        ];
    }

    return [
        'datasets' => array_values($datasets),
        'contexts_by_dataset' => $contextsByDataset,
        'metric_options' => [
            ['key' => 'median', 'label' => 'Median'],
            ['key' => 'max', 'label' => 'Max'],
            ['key' => 'min', 'label' => 'Min'],
        ],
        'sort_options' => [
            ['key' => 'default', 'label' => 'Default order'],
            ['key' => 'high_to_low', 'label' => 'High to low'],
            ['key' => 'low_to_high', 'label' => 'Low to high'],
        ],
    ];
}

function tekg_expression_fetch_browse_page(array $filters = [], int $page = 1, int $pageSize = 20, string $sort = 'default'): array
{
    $page = max(1, $page);
    $pageSize = max(1, min(100, $pageSize));
    $offset = ($page - 1) * $pageSize;

    $where = [];
    $params = [];
    $types = '';

    $keyword = trim((string)($filters['keyword'] ?? ''));
    if ($keyword !== '') {
        $where[] = 'te_name LIKE ?';
        $params[] = '%' . $keyword . '%';
        $types .= 's';
    }

    $datasetKey = tekg_expression_normalize_dataset_key((string)($filters['dataset_key'] ?? ''));
    if ($datasetKey !== null) {
        $where[] = $datasetKey . '_available = 1';
    }

    $topContext = trim((string)($filters['top_context'] ?? ''));
    if ($topContext !== '') {
        $like = '%' . $topContext . '%';
        $where[] = '(
            global_top_context_median LIKE ? OR
            global_top_context_median_full_name LIKE ? OR
            normal_tissue_top_context_median LIKE ? OR
            normal_tissue_top_context_median_full_name LIKE ? OR
            normal_cell_line_top_context_median LIKE ? OR
            normal_cell_line_top_context_median_full_name LIKE ? OR
            cancer_cell_line_top_context_median LIKE ? OR
            cancer_cell_line_top_context_median_full_name LIKE ?
        )';
        for ($i = 0; $i < 8; $i++) {
            $params[] = $like;
            $types .= 's';
        }
    }

    if (isset($filters['min_global_median']) && $filters['min_global_median'] !== '' && is_numeric((string)$filters['min_global_median'])) {
        $where[] = 'global_top_context_median_value >= ?';
        $params[] = (float)$filters['min_global_median'];
        $types .= 'd';
    }

    $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));
    $orderSql = tekg_expression_browse_sort_sql($sort);

    $countRow = tekg_expression_fetch_one(
        'SELECT COUNT(*) AS total FROM expression_browse_summary ' . $whereSql,
        $params,
        $types === '' ? null : $types
    );
    $total = (int)($countRow['total'] ?? 0);

    $rows = tekg_expression_fetch_all(
        'SELECT * FROM expression_browse_summary ' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ? OFFSET ?',
        array_merge($params, [$pageSize, $offset]),
        $types . 'ii'
    );

    return [
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
        ],
        'filters' => [
            'keyword' => $keyword,
            'dataset_key' => $datasetKey,
            'top_context' => $topContext,
            'min_global_median' => $filters['min_global_median'] ?? null,
        ],
        'sort' => tekg_expression_normalize_sort($sort),
        'chart' => 'bar',
    ];
}

function tekg_expression_context_stat_quartile_columns(): array
{
    static $state = null;
    if (is_array($state)) {
        return $state;
    }

    $state = ['q1' => false, 'q3' => false];
    $rows = tekg_expression_fetch_all("SHOW COLUMNS FROM expression_context_stats");
    foreach ($rows as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field === 'q1_value') {
            $state['q1'] = true;
        }
        if ($field === 'q3_value') {
            $state['q3'] = true;
        }
    }
    return $state;
}

function tekg_expression_fetch_quartile_maps_from_tsv(string $teName): array
{
    static $cache = [];
    if (isset($cache[$teName])) {
        return $cache[$teName];
    }

    $path = tekg_expression_bulk_fs_path('processed/te_expression_context_stats.tsv');
    if (!is_file($path)) {
        return $cache[$teName] = [];
    }

    $handle = fopen($path, 'r');
    if (!$handle) {
        return $cache[$teName] = [];
    }

    $separator = chr(9);
    $enclosure = '"';
    $escape = "\\";
    $header = fgetcsv($handle, 0, $separator, $enclosure, $escape);
    if (!is_array($header)) {
        fclose($handle);
        return $cache[$teName] = [];
    }
    $columns = array_flip($header);
    $map = [];
    $seenTarget = false;

    while (($row = fgetcsv($handle, 0, $separator, $enclosure, $escape)) !== false) {
        $rowTe = isset($columns['te_name']) ? (string)($row[$columns['te_name']] ?? '') : '';
        if ($rowTe === '') {
            continue;
        }
        if ($seenTarget && $rowTe !== $teName) {
            break;
        }
        if ($rowTe !== $teName) {
            continue;
        }
        $seenTarget = true;
        $rowDataset = isset($columns['dataset_key']) ? (string)($row[$columns['dataset_key']] ?? '') : '';
        $contextLabel = isset($columns['context_label']) ? (string)($row[$columns['context_label']] ?? '') : '';
        if ($rowDataset === '' || $contextLabel === '') {
            continue;
        }
        $map[$rowDataset][$contextLabel] = [
            'q1_value' => isset($columns['q1_value']) && ($row[$columns['q1_value']] ?? '') !== '' ? (float)$row[$columns['q1_value']] : null,
            'q3_value' => isset($columns['q3_value']) && ($row[$columns['q3_value']] ?? '') !== '' ? (float)$row[$columns['q3_value']] : null,
        ];
    }

    fclose($handle);
    return $cache[$teName] = $map;
}

function tekg_expression_fetch_dataset_summaries(string $teName): array
{
    $resolved = tekg_expression_resolve_te_name($teName);
    if ($resolved === null) {
        return [];
    }

    return tekg_expression_fetch_all(
        'SELECT * FROM expression_dataset_summary WHERE te_name = ? ORDER BY dataset_order ASC',
        [$resolved],
        's'
    );
}

function tekg_expression_fetch_context_stats(string $teName, string $datasetKey, string $metric = 'median', string $sort = 'default', bool $includeQuartiles = false): array
{
    $resolved = tekg_expression_resolve_te_name($teName);
    $datasetKey = tekg_expression_normalize_dataset_key($datasetKey);
    if ($resolved === null || $datasetKey === null) {
        return [];
    }

    $metricColumn = tekg_expression_metric_column($metric);
    $orderSql = match (tekg_expression_normalize_sort($sort)) {
        'high_to_low' => $metricColumn . ' DESC, context_full_name ASC',
        'low_to_high' => $metricColumn . ' ASC, context_full_name ASC',
        default => 'context_order ASC, context_full_name ASC',
    };

    $quartileColumns = tekg_expression_context_stat_quartile_columns();
    $q1Select = $quartileColumns['q1'] ? 'q1_value' : 'NULL AS q1_value';
    $q3Select = $quartileColumns['q3'] ? 'q3_value' : 'NULL AS q3_value';

    $rows = tekg_expression_fetch_all(
        'SELECT te_name, dataset_key, dataset_label, dataset_order, context_label, context_full_name, context_order, sample_count,
                min_value, ' . $q1Select . ', median_value, ' . $q3Select . ', mean_value, max_value, std_value, cv_value
         FROM expression_context_stats
         WHERE te_name = ? AND dataset_key = ?
         ORDER BY ' . $orderSql,
        [$resolved, $datasetKey],
        'ss'
    );

    if (!$includeQuartiles) {
        return $rows;
    }

    $needsQuartileFallback = !$quartileColumns['q1'] || !$quartileColumns['q3'];
    if (!$needsQuartileFallback) {
        foreach ($rows as $row) {
            if (($row['q1_value'] ?? null) === null || ($row['q3_value'] ?? null) === null) {
                $needsQuartileFallback = true;
                break;
            }
        }
    }

    if ($needsQuartileFallback) {
        $quartileMaps = tekg_expression_fetch_quartile_maps_from_tsv($resolved);
        $quartileMap = $quartileMaps[$datasetKey] ?? [];
        foreach ($rows as &$row) {
            $contextLabel = (string)($row['context_label'] ?? '');
            $extra = $quartileMap[$contextLabel] ?? null;
            if ($extra !== null) {
                $row['q1_value'] = $extra['q1_value'];
                $row['q3_value'] = $extra['q3_value'];
            }
        }
        unset($row);
    }

    return $rows;
}

function tekg_expression_fetch_detail_bundle(string $teName, string $metric = 'median', string $sort = 'default', string $chartType = 'bar'): ?array
{
    $resolved = tekg_expression_resolve_te_name($teName);
    if ($resolved === null) {
        return null;
    }

    $browseSummary = tekg_expression_fetch_one(
        'SELECT * FROM expression_browse_summary WHERE te_name = ? LIMIT 1',
        [$resolved],
        's'
    );
    if (!is_array($browseSummary)) {
        return null;
    }

    $datasetSummaries = tekg_expression_fetch_dataset_summaries($resolved);
    $catalog = tekg_expression_fetch_filter_options();
    $datasets = [];

    foreach ($datasetSummaries as $datasetSummary) {
        $datasetKey = (string)$datasetSummary['dataset_key'];
        $datasets[$datasetKey] = [
            'summary' => $datasetSummary,
            'contexts' => tekg_expression_fetch_context_stats($resolved, $datasetKey, $metric, $sort, ($chartType ?? 'bar') === 'box'),
            'catalog' => $catalog['contexts_by_dataset'][$datasetKey] ?? [],
        ];
    }

    return [
        'te_name' => $resolved,
        'browse_summary' => $browseSummary,
        'datasets' => $datasets,
        'metric' => tekg_expression_normalize_metric($metric),
        'sort' => tekg_expression_normalize_sort($sort),
        'chart' => strtolower(trim($chartType)) === 'box' ? 'box' : 'bar',
    ];
}
function tekg_expression_normalize_browse_value_mode(?string $mode): string
{
    $value = strtolower(trim((string)$mode));
    return match ($value) {
        'max' => 'max',
        'min' => 'min',
        default => 'median',
    };
}

function tekg_expression_fetch_min_context_map(array $teNames): array
{
    $teNames = array_values(array_unique(array_filter(array_map(static fn ($value): string => trim((string)$value), $teNames), static fn (string $value): bool => $value !== '')));
    if ($teNames === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($teNames), '?'));
    $rows = tekg_expression_fetch_all(
        'SELECT te_name, dataset_key, context_label, context_full_name, min_value
         FROM (
           SELECT te_name, dataset_key, context_label, context_full_name, min_value,
                  ROW_NUMBER() OVER (PARTITION BY te_name, dataset_key ORDER BY min_value ASC, context_full_name ASC) AS rn
           FROM expression_context_stats
           WHERE te_name IN (' . $placeholders . ')
         ) ranked
         WHERE rn = 1',
        $teNames,
        str_repeat('s', count($teNames))
    );

    $mapped = [];
    foreach ($rows as $row) {
        $mapped[(string)$row['te_name']][(string)$row['dataset_key']] = [
            'context_label' => (string)$row['context_label'],
            'context_full_name' => (string)$row['context_full_name'],
            'value' => $row['min_value'] !== null ? (float)$row['min_value'] : null,
        ];
    }

    return $mapped;
}

function tekg_expression_enrich_browse_rows(array $rows): array
{
    if ($rows === []) {
        return $rows;
    }

    $teNames = array_map(static fn (array $row): string => (string)($row['te_name'] ?? ''), $rows);
    $minMap = tekg_expression_fetch_min_context_map($teNames);
    $datasets = ['normal_tissue', 'normal_cell_line', 'cancer_cell_line'];

    foreach ($rows as &$row) {
        $teName = (string)($row['te_name'] ?? '');
        foreach ($datasets as $datasetKey) {
            $minContext = $minMap[$teName][$datasetKey] ?? null;
            $row[$datasetKey . '_top_context_min'] = $minContext['context_label'] ?? null;
            $row[$datasetKey . '_top_context_min_full_name'] = $minContext['context_full_name'] ?? null;
            $row[$datasetKey . '_top_context_min_value'] = $minContext['value'] ?? null;
        }
    }
    unset($row);

    return $rows;
}
