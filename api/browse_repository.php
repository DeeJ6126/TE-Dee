<?php
declare(strict_types=1);

function tekg_browse_catalog_pick_value(
    array $local,
    array $localKeys,
    array $environmentNames,
    string $default = ''
): string {
    foreach ($localKeys as $key) {
        if (array_key_exists($key, $local) && trim((string)$local[$key]) !== '') {
            return trim((string)$local[$key]);
        }
    }

    foreach ($environmentNames as $name) {
        $value = getenv($name);
        if ($value !== false && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }

    return $default;
}

function tekg_browse_catalog_config(): array
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
        'host' => tekg_browse_catalog_pick_value($local, ['mysql_host'], ['MYSQL_HOST_BIOLOGY', 'MYSQL_HOST'], '127.0.0.1'),
        'port' => (int)tekg_browse_catalog_pick_value($local, ['mysql_port'], ['MYSQL_PORT_BIOLOGY', 'MYSQL_PORT'], '3306'),
        'database' => tekg_browse_catalog_pick_value($local, ['mysql_catalog_database'], ['MYSQL_CATALOG_DATABASE'], 'tekg_catalog'),
        'user' => tekg_browse_catalog_pick_value($local, ['mysql_user'], ['MYSQL_USER_BIOLOGY', 'MYSQL_USER'], 'root'),
        'password' => tekg_browse_catalog_pick_value($local, ['mysql_password'], ['MYSQL_PASSWORD_BIOLOGY', 'MYSQL_PASSWORD'], ''),
        'charset' => tekg_browse_catalog_pick_value($local, ['mysql_charset'], ['MYSQL_CHARSET_BIOLOGY', 'MYSQL_CHARSET'], 'utf8mb4'),
    ];

    return $config;
}

function tekg_browse_catalog_db(): mysqli
{
    static $db = null;
    if ($db instanceof mysqli) {
        return $db;
    }

    if (!extension_loaded('mysqli')) {
        throw new RuntimeException('PHP mysqli extension is required for Browse catalog access.');
    }

    $config = tekg_browse_catalog_config();
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = mysqli_init();
    if (!$db) {
        throw new RuntimeException('Failed to initialize mysqli for Browse catalog access.');
    }

    $connected = @$db->real_connect(
        $config['host'],
        $config['user'],
        $config['password'],
        $config['database'],
        $config['port']
    );
    if (!$connected) {
        $message = mysqli_connect_error() ?: $db->connect_error ?: 'Unknown MySQL connection failure';
        throw new RuntimeException('Browse catalog MySQL connection failed: ' . $message);
    }
    if (!$db->set_charset($config['charset'])) {
        throw new RuntimeException('Failed to set Browse catalog MySQL charset.');
    }

    return $db;
}

function tekg_browse_catalog_fetch_all(string $sql): array
{
    $db = tekg_browse_catalog_db();
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Browse catalog query failed: ' . $db->error);
    }

    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    return $rows;
}

function tekg_browse_catalog_decode_keywords(mixed $value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map('strval', $value), static fn(string $item): bool => $item !== ''));
    }

    $decoded = json_decode((string)$value, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Active Browse catalog contains invalid keywords JSON.');
    }

    return array_values(array_filter(array_map('strval', $decoded), static fn(string $item): bool => $item !== ''));
}

function tekg_browse_fetch_active_catalog(): array
{
    $versions = tekg_browse_catalog_fetch_all(
        'SELECT id, version_label, source_sha256, imported_at, row_count
         FROM browse_catalog_versions
         WHERE is_active = 1
         ORDER BY id DESC
         LIMIT 2'
    );
    if (count($versions) !== 1) {
        throw new RuntimeException('Browse catalog must have exactly one active version.');
    }

    $version = $versions[0];
    $versionId = (int)($version['id'] ?? 0);
    $declaredCount = (int)($version['row_count'] ?? 0);
    if ($versionId < 1 || $declaredCount !== 276) {
        throw new RuntimeException('Active Browse catalog version metadata is invalid.');
    }

    $entries = tekg_browse_catalog_fetch_all(
        'SELECT te_name, class_name, family, subtype, description, length_bp, reference_count, keywords_json
         FROM browse_catalog_entries
         WHERE catalog_version_id = ' . $versionId . '
         ORDER BY LOWER(te_name) ASC, te_name ASC'
    );
    if (count($entries) !== $declaredCount) {
        throw new RuntimeException('Active Browse catalog row count does not match its version metadata.');
    }

    $seen = [];
    $items = [];
    foreach ($entries as $entry) {
        $name = trim((string)($entry['te_name'] ?? ''));
        $canonicalName = strtolower($name);
        if ($name === '' || isset($seen[$canonicalName])) {
            throw new RuntimeException('Active Browse catalog contains an empty or duplicate TE name.');
        }
        $seen[$canonicalName] = true;

        $length = $entry['length_bp'] ?? null;
        $items[] = [
            'name' => $name,
            'className' => trim((string)($entry['class_name'] ?? '')),
            'family' => trim((string)($entry['family'] ?? '')),
            'subtype' => trim((string)($entry['subtype'] ?? '')),
            'description' => trim((string)($entry['description'] ?? '')),
            'lengthBp' => $length === null ? null : (int)$length,
            'referenceCount' => (int)($entry['reference_count'] ?? 0),
            'keywords' => tekg_browse_catalog_decode_keywords($entry['keywords_json'] ?? '[]'),
        ];
    }

    usort($items, static function (array $left, array $right): int {
        $caseInsensitive = strcasecmp($left['name'], $right['name']);
        return $caseInsensitive !== 0 ? $caseInsensitive : strcmp($left['name'], $right['name']);
    });

    return [
        'version' => (string)($version['version_label'] ?? ''),
        'importedAt' => (string)($version['imported_at'] ?? ''),
        'rowCount' => $declaredCount,
        'sourceHash' => (string)($version['source_sha256'] ?? ''),
        'items' => $items,
    ];
}
