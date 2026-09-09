<?php
declare(strict_types=1);

const EXPECTED_BROWSE_CATALOG_ROWS = 276;

function browse_catalog_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function browse_catalog_config_value(array $local, string $key, array $environment, string $default): string
{
    if (isset($local[$key]) && trim((string)$local[$key]) !== '') {
        return trim((string)$local[$key]);
    }
    foreach ($environment as $name) {
        $value = getenv($name);
        if ($value !== false && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }
    return $default;
}

try {
    $root = dirname(__DIR__, 2);
    $schemaPath = $root . '/imports/browse_mysql_schema.sql';
    $importerPath = $root . '/scripts/import/import_browse_catalog_mysql.php';
    browse_catalog_check(is_file($schemaPath), 'Browse catalog schema is missing.');
    browse_catalog_check(is_file($importerPath), 'Browse catalog importer is missing.');

    $sourcePath = $root . '/data/processed/te_repbase_db_matched.json';
    $source = json_decode((string)file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
    $mapping = $source['db_to_repbase'] ?? null;
    $entries = $source['entries'] ?? null;
    browse_catalog_check(is_array($mapping) && count($mapping) === EXPECTED_BROWSE_CATALOG_ROWS, 'Expected 276 db_to_repbase names.');
    browse_catalog_check(is_array($entries) && count($entries) === EXPECTED_BROWSE_CATALOG_ROWS, 'Expected 276 RepBase entries.');
    $lowerNames = array_map(static fn(string $name): string => strtolower(trim($name)), array_keys($mapping));
    browse_catalog_check(count(array_unique($lowerNames)) === EXPECTED_BROWSE_CATALOG_ROWS, 'db_to_repbase names conflict case-insensitively.');

    browse_catalog_check(extension_loaded('mysqli'), 'PHP mysqli extension is required.');
    $localPath = $root . '/api/config.local.php';
    $local = is_file($localPath) ? require $localPath : [];
    $local = is_array($local) ? $local : [];
    $host = browse_catalog_config_value($local, 'mysql_host', ['MYSQL_HOST_BIOLOGY', 'MYSQL_HOST'], '127.0.0.1');
    $port = (int)browse_catalog_config_value($local, 'mysql_port', ['MYSQL_PORT_BIOLOGY', 'MYSQL_PORT'], '3306');
    $database = browse_catalog_config_value($local, 'mysql_catalog_database', ['MYSQL_CATALOG_DATABASE'], 'tekg_catalog');
    $user = browse_catalog_config_value($local, 'mysql_user', ['MYSQL_USER_BIOLOGY', 'MYSQL_USER'], 'root');
    $password = browse_catalog_config_value($local, 'mysql_password', ['MYSQL_PASSWORD_BIOLOGY', 'MYSQL_PASSWORD'], '');

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $user, $password, $database, $port);
    $db->set_charset('utf8mb4');
    $counts = $db->query(
        'SELECT '
        . '(SELECT COUNT(*) FROM browse_catalog_versions WHERE is_active = 1) AS active_versions, '
        . '(SELECT COUNT(*) FROM browse_catalog_entries e JOIN browse_catalog_versions v ON v.id = e.catalog_version_id WHERE v.is_active = 1) AS active_entries'
    )->fetch_assoc();
    browse_catalog_check((int)$counts['active_versions'] === 1, 'Expected exactly one active Browse catalog version.');
    browse_catalog_check((int)$counts['active_entries'] === EXPECTED_BROWSE_CATALOG_ROWS, 'Expected 276 active Browse catalog entries.');

    $version = $db->query('SELECT id, version_label, source_sha256, taxonomy_sha256, taxonomy_database, taxonomy_snapshot_json, imported_at, row_count FROM browse_catalog_versions WHERE is_active = 1')->fetch_assoc();
    browse_catalog_check(is_array($version), 'Active Browse catalog version is unavailable.');
    browse_catalog_check((int)$version['row_count'] === EXPECTED_BROWSE_CATALOG_ROWS, 'Active version row_count is not 276.');
    browse_catalog_check(preg_match('/^[a-f0-9]{64}$/', (string)$version['source_sha256']) === 1, 'Active version source SHA-256 is invalid.');
    browse_catalog_check(trim((string)$version['imported_at']) !== '', 'Active version import time is missing.');
    if ($version['taxonomy_sha256'] !== null) {
        browse_catalog_check(preg_match('/^[a-f0-9]{64}$/', (string)$version['taxonomy_sha256']) === 1, 'Taxonomy SHA-256 is invalid.');
        browse_catalog_check((string)$version['taxonomy_database'] === 'tekg3', 'Taxonomy snapshot must come from tekg3.');
    }
    json_decode((string)$version['taxonomy_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);

    $versionId = (int)$version['id'];
    $bad = $db->query(
        "SELECT COUNT(*) AS count_bad FROM browse_catalog_entries WHERE catalog_version_id = {$versionId} "
        . "AND (te_name = '' OR class_name = '' OR lineage_source NOT IN ('neo4j-taxonomy', 'repbase-inference') OR JSON_VALID(keywords_json) = 0)"
    )->fetch_assoc();
    browse_catalog_check((int)$bad['count_bad'] === 0, 'Active catalog contains invalid rows or provenance.');

    $names = $db->query(
        "SELECT te_name FROM browse_catalog_entries WHERE catalog_version_id = {$versionId} AND te_name IN ('L1HS', 'AluYa5', 'AluYb10') ORDER BY te_name"
    )->fetch_all(MYSQLI_ASSOC);
    browse_catalog_check(count($names) === 3, 'Representative Browse names are missing.');

    $mappedNames = $db->query(
        "SELECT te_name, repbase_id FROM browse_catalog_entries WHERE catalog_version_id = {$versionId} "
        . "AND te_name IN ('MLT1N2', 'PrimLTR79', 'SVA_A') ORDER BY te_name"
    )->fetch_all(MYSQLI_ASSOC);
    browse_catalog_check(count($mappedNames) === 3, 'Mapped-key TE names are missing.');
    foreach ($mappedNames as $mappedName) {
        browse_catalog_check($mappedName['te_name'] === $mappedName['repbase_id'], 'Catalog name must come from the db_to_repbase key.');
    }

    echo "PASS: Browse MySQL catalog has one active version and 276 valid entries.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
