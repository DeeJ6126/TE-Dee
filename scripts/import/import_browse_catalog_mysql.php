<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/runtime_config.php';
require_once dirname(__DIR__, 2) . '/api/taxonomy_lib.php';

const BROWSE_CATALOG_EXPECTED_ROWS = 276;

function browse_catalog_fail(string $message): never
{
    throw new RuntimeException($message);
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

function browse_catalog_database_config(): array
{
    $local = tekg_runtime_load_local_config();
    return [
        'host' => browse_catalog_config_value($local, 'mysql_host', ['MYSQL_HOST_BIOLOGY', 'MYSQL_HOST'], '127.0.0.1'),
        'port' => (int)browse_catalog_config_value($local, 'mysql_port', ['MYSQL_PORT_BIOLOGY', 'MYSQL_PORT'], '3306'),
        'database' => browse_catalog_config_value($local, 'mysql_catalog_database', ['MYSQL_CATALOG_DATABASE'], 'tekg_catalog'),
        'user' => browse_catalog_config_value($local, 'mysql_user', ['MYSQL_USER_BIOLOGY', 'MYSQL_USER'], 'root'),
        'password' => browse_catalog_config_value($local, 'mysql_password', ['MYSQL_PASSWORD_BIOLOGY', 'MYSQL_PASSWORD'], ''),
        'charset' => browse_catalog_config_value($local, 'mysql_charset', ['MYSQL_CHARSET_BIOLOGY', 'MYSQL_CHARSET'], 'utf8mb4'),
    ];
}

function browse_catalog_connect_and_migrate(string $schemaPath): mysqli
{
    if (!extension_loaded('mysqli')) {
        browse_catalog_fail('PHP mysqli extension is required.');
    }
    $config = browse_catalog_database_config();
    if (preg_match('/^[A-Za-z0-9_]+$/', $config['database']) !== 1) {
        browse_catalog_fail('mysql_catalog_database must contain only letters, numbers, and underscores.');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = mysqli_init();
    if (!$db instanceof mysqli) {
        browse_catalog_fail('Failed to initialize mysqli.');
    }
    $db->real_connect($config['host'], $config['user'], $config['password'], null, $config['port']);
    $db->query("CREATE DATABASE IF NOT EXISTS `{$config['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($config['database']);
    $db->set_charset($config['charset']);

    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        browse_catalog_fail("Missing schema: {$schemaPath}");
    }
    $db->multi_query($schema);
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
    return $db;
}

function browse_catalog_statement(mysqli $db, string $sql, array $params = [], string $types = ''): mysqli_stmt
{
    $statement = $db->prepare($sql);
    if ($params !== []) {
        $bind = [$types];
        foreach ($params as $index => $_value) {
            $bind[] = &$params[$index];
        }
        if (!call_user_func_array([$statement, 'bind_param'], $bind)) {
            browse_catalog_fail('Failed to bind importer parameters.');
        }
    }
    $statement->execute();
    return $statement;
}

function browse_catalog_json_file(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        browse_catalog_fail("Missing source JSON: {$path}");
    }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        browse_catalog_fail("Invalid source JSON: {$path}");
    }
    return $decoded;
}

function browse_catalog_normalize_label(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return str_replace(['_', '-'], ' ', $value);
}

function browse_catalog_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function browse_catalog_length(array $entry): ?int
{
    $headline = trim((string)($entry['sequence_summary']['headline'] ?? ''));
    if ($headline !== '' && preg_match('/(\d+)\s*BP/i', $headline, $matches) === 1) {
        return (int)$matches[1];
    }
    $sequence = preg_replace('/\s+/', '', (string)($entry['sequence'] ?? '')) ?? '';
    return $sequence === '' ? null : strlen($sequence);
}

function browse_catalog_infer_lineage(string $teName, array $entry): array
{
    $description = browse_catalog_lower((string)($entry['description'] ?? ''));
    $keywords = array_map(
        static fn(mixed $keyword): string => browse_catalog_lower((string)$keyword),
        is_array($entry['keywords'] ?? null) ? $entry['keywords'] : []
    );
    $haystack = browse_catalog_lower($teName . ' ' . $description . ' ' . implode(' ', $keywords));
    $className = 'Unclassified';
    $family = '';
    $subtype = '';

    if (str_contains($haystack, 'endogenous retrovirus') || str_contains($haystack, 'herv') || str_contains($haystack, ' erv') || str_contains($haystack, 'ltr')) {
        $className = 'Retrotransposon';
        foreach ($entry['keywords'] ?? [] as $keyword) {
            if (preg_match('/^(ERV\d+|ERVL|ERVK|HERV[\w\-]+)$/i', (string)$keyword) === 1) {
                $family = (string)$keyword;
                break;
            }
        }
        $family = $family !== '' ? $family : 'ERV';
        $subtype = str_starts_with($teName, 'LTR') ? 'LTR' : '';
    } elseif (str_contains($haystack, 'non-ltr retrotransposon') || str_contains($haystack, ' line ') || str_contains($haystack, 'l1 (line) family')) {
        $className = 'Retrotransposon';
        $family = 'LINE';
        foreach (['CR1', 'L1', 'L2', 'RTE'] as $candidate) {
            if (str_contains($haystack, browse_catalog_lower($candidate))) {
                $subtype = $candidate;
                break;
            }
        }
    } elseif (str_contains($haystack, 'sine')) {
        $className = 'Retrotransposon';
        $family = 'SINE';
        $subtype = str_contains($haystack, 'alu') ? 'Alu' : '';
    } elseif (str_contains($haystack, 'dna transposon')) {
        $className = 'DNA Transposon';
        foreach (['hAT-Charlie', 'hAT', 'Mariner/Tc1', 'piggyBac', 'Merlin', 'Helitron'] as $candidate) {
            if (str_contains($haystack, browse_catalog_lower($candidate))) {
                $family = $candidate;
                break;
            }
        }
    }

    return [
        'className' => browse_catalog_normalize_label($className),
        'family' => browse_catalog_normalize_label($family),
        'subtype' => browse_catalog_normalize_label($subtype),
    ];
}

function browse_catalog_taxonomy_snapshot(): array
{
    try {
        $config = tekg_taxonomy_config();
        $items = tekg_taxonomy_fetch_items(null, $config);
        $encoded = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return [
            'available' => true,
            'items' => $items,
            'sha256' => hash('sha256', $encoded),
            'database' => tekg_runtime_neo4j_database_name($config),
            'error' => null,
        ];
    } catch (Throwable $error) {
        return [
            'available' => false,
            'items' => [],
            'sha256' => null,
            'database' => null,
            'error' => $error->getMessage(),
        ];
    }
}

function browse_catalog_build_rows(array $source, array $taxonomy): array
{
    $mapping = $source['db_to_repbase'] ?? null;
    $entries = $source['entries'] ?? null;
    if (!is_array($mapping) || count($mapping) !== BROWSE_CATALOG_EXPECTED_ROWS) {
        browse_catalog_fail('Expected exactly 276 db_to_repbase mappings.');
    }
    if (!is_array($entries) || count($entries) !== BROWSE_CATALOG_EXPECTED_ROWS) {
        browse_catalog_fail('Expected exactly 276 RepBase entries.');
    }

    $entryById = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            browse_catalog_fail('RepBase entries must be objects.');
        }
        $id = trim((string)($entry['id'] ?? ''));
        if ($id === '' || isset($entryById[$id])) {
            browse_catalog_fail("Invalid or duplicate RepBase entry id: {$id}");
        }
        $entryById[$id] = $entry;
    }

    $taxonomyIndex = tekg_taxonomy_index_items($taxonomy['items']);
    $caseIndex = [];
    $rows = [];
    foreach ($mapping as $teName => $repbaseId) {
        $teName = trim((string)$teName);
        $repbaseId = trim((string)$repbaseId);
        if ($teName === '' || !isset($entryById[$repbaseId])) {
            browse_catalog_fail("Unresolved db_to_repbase mapping: {$teName} => {$repbaseId}");
        }
        $caseKey = browse_catalog_lower($teName);
        if (isset($caseIndex[$caseKey])) {
            browse_catalog_fail("Case-insensitive TE name conflict: {$caseIndex[$caseKey]} / {$teName}");
        }
        $caseIndex[$caseKey] = $teName;

        $entry = $entryById[$repbaseId];
        $inferred = browse_catalog_infer_lineage($teName, $entry);
        $taxonomyItem = $taxonomyIndex[$teName] ?? $taxonomyIndex[tekg_taxonomy_canonical_key($teName)] ?? null;
        $path = is_array($taxonomyItem) && is_array($taxonomyItem['path'] ?? null) ? $taxonomyItem['path'] : [];
        $hasTaxonomy = $path !== [];
        $className = trim((string)($path['class'] ?? '')) ?: $inferred['className'];
        $family = trim((string)($path['order'] ?? '')) ?: (trim((string)($path['superfamily'] ?? '')) ?: $inferred['family']);
        $subtype = trim((string)($path['subclade'] ?? '')) ?: (trim((string)($path['family'] ?? '')) ?: $inferred['subtype']);
        $keywords = is_array($entry['keywords'] ?? null)
            ? array_values(array_filter(array_map('strval', $entry['keywords']), static fn(string $value): bool => trim($value) !== ''))
            : [];
        $rows[] = [
            'teName' => $teName,
            'repbaseId' => $repbaseId,
            'className' => browse_catalog_normalize_label($className !== '' ? $className : 'Unclassified'),
            'family' => browse_catalog_normalize_label($family),
            'subtype' => browse_catalog_normalize_label($subtype),
            'description' => trim((string)($entry['description'] ?? '')),
            'lengthBp' => browse_catalog_length($entry),
            'referenceCount' => is_array($entry['references'] ?? null) ? count($entry['references']) : 0,
            'keywordsJson' => json_encode($keywords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'lineageSource' => $hasTaxonomy ? 'neo4j-taxonomy' : 'repbase-inference',
            'lineageSnapshotJson' => json_encode(
                $hasTaxonomy ? ['taxonomy_name' => $taxonomyItem['name'] ?? $teName, 'path' => $path] : ['inferred' => $inferred],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ];
    }
    if (count($rows) !== BROWSE_CATALOG_EXPECTED_ROWS) {
        browse_catalog_fail('Importer did not build exactly 276 rows.');
    }
    return $rows;
}

function browse_catalog_activate(mysqli $db, string $selector): array
{
    $sql = ctype_digit($selector)
        ? 'SELECT id, version_label, row_count FROM browse_catalog_versions WHERE id = ? FOR UPDATE'
        : 'SELECT id, version_label, row_count FROM browse_catalog_versions WHERE version_label = ? FOR UPDATE';
    $type = ctype_digit($selector) ? 'i' : 's';
    $value = ctype_digit($selector) ? (int)$selector : $selector;
    $db->begin_transaction();
    try {
        $version = browse_catalog_statement($db, $sql, [$value], $type)->get_result()->fetch_assoc();
        if (!is_array($version)) {
            browse_catalog_fail("Browse catalog version not found: {$selector}");
        }
        $versionId = (int)$version['id'];
        $count = browse_catalog_statement(
            $db,
            'SELECT COUNT(*) AS row_count, COUNT(DISTINCT LOWER(te_name)) AS unique_names FROM browse_catalog_entries WHERE catalog_version_id = ?',
            [$versionId],
            'i'
        )->get_result()->fetch_assoc();
        if ((int)$version['row_count'] !== BROWSE_CATALOG_EXPECTED_ROWS || (int)$count['row_count'] !== BROWSE_CATALOG_EXPECTED_ROWS || (int)$count['unique_names'] !== BROWSE_CATALOG_EXPECTED_ROWS) {
            browse_catalog_fail('Refusing to activate a catalog version without 276 unique entries.');
        }
        $db->query('UPDATE browse_catalog_versions SET is_active = 0, activated_at = NULL WHERE is_active = 1');
        browse_catalog_statement($db, 'UPDATE browse_catalog_versions SET is_active = 1, activated_at = CURRENT_TIMESTAMP WHERE id = ?', [$versionId], 'i');
        $db->commit();
        return $version;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

try {
    $root = dirname(__DIR__, 2);
    $options = getopt('', ['activate-version:', 'version-label:', 'source:', 'no-activate', 'allow-taxonomy-unavailable-activation']);
    $db = browse_catalog_connect_and_migrate($root . '/imports/browse_mysql_schema.sql');

    if (isset($options['activate-version'])) {
        $version = browse_catalog_activate($db, trim((string)$options['activate-version']));
        echo "Activated Browse catalog {$version['version_label']} ({$version['row_count']} rows)." . PHP_EOL;
        exit(0);
    }

    $sourcePath = isset($options['source']) ? (string)$options['source'] : $root . '/data/processed/te_repbase_db_matched.json';
    if (!is_file($sourcePath)) {
        browse_catalog_fail("Browse catalog source does not exist: {$sourcePath}");
    }
    $sourceSha256 = hash_file('sha256', $sourcePath);
    if (!is_string($sourceSha256)) {
        browse_catalog_fail('Could not calculate the source SHA-256.');
    }
    $source = browse_catalog_json_file($sourcePath);
    $taxonomy = browse_catalog_taxonomy_snapshot();
    $rows = browse_catalog_build_rows($source, $taxonomy);
    $versionLabel = trim((string)($options['version-label'] ?? ''));
    if ($versionLabel === '') {
        $versionLabel = 'browse-' . gmdate('Ymd\THis\Z') . '-' . substr($sourceSha256, 0, 12) . '-' . bin2hex(random_bytes(3));
    }
    if (strlen($versionLabel) > 128) {
        browse_catalog_fail('version-label must be at most 128 characters.');
    }

    $taxonomyIndex = tekg_taxonomy_index_items($taxonomy['items']);
    $taxonomyMatched = 0;
    foreach (array_keys($source['db_to_repbase']) as $teName) {
        if (isset($taxonomyIndex[$teName]) || isset($taxonomyIndex[tekg_taxonomy_canonical_key((string)$teName)])) {
            $taxonomyMatched++;
        }
    }
    $snapshotJson = json_encode([
        'available' => $taxonomy['available'],
        'database' => $taxonomy['database'],
        'sha256' => $taxonomy['sha256'],
        'item_count' => count($taxonomy['items']),
        'matched_count' => $taxonomyMatched,
        'fallback_count' => BROWSE_CATALOG_EXPECTED_ROWS - $taxonomyMatched,
        'captured_at_utc' => gmdate(DATE_ATOM),
        'error' => $taxonomy['error'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $db->begin_transaction();
    try {
        browse_catalog_statement(
            $db,
            'INSERT INTO browse_catalog_versions (version_label, source_sha256, taxonomy_sha256, taxonomy_database, taxonomy_snapshot_json, row_count, is_active) VALUES (?, ?, ?, ?, ?, ?, 0)',
            [$versionLabel, $sourceSha256, $taxonomy['sha256'], $taxonomy['database'], $snapshotJson, BROWSE_CATALOG_EXPECTED_ROWS],
            'sssssi'
        );
        $versionId = (int)$db->insert_id;
        $insertSql = 'INSERT INTO browse_catalog_entries (catalog_version_id, te_name, repbase_id, class_name, family, subtype, description, length_bp, reference_count, keywords_json, lineage_source, lineage_snapshot_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        foreach ($rows as $row) {
            browse_catalog_statement($db, $insertSql, [
                $versionId,
                $row['teName'],
                $row['repbaseId'],
                $row['className'],
                $row['family'],
                $row['subtype'],
                $row['description'],
                $row['lengthBp'],
                $row['referenceCount'],
                $row['keywordsJson'],
                $row['lineageSource'],
                $row['lineageSnapshotJson'],
            ], 'issssssiisss');
        }
        $count = browse_catalog_statement(
            $db,
            'SELECT COUNT(*) AS row_count, COUNT(DISTINCT LOWER(te_name)) AS unique_names FROM browse_catalog_entries WHERE catalog_version_id = ?',
            [$versionId],
            'i'
        )->get_result()->fetch_assoc();
        if ((int)$count['row_count'] !== BROWSE_CATALOG_EXPECTED_ROWS || (int)$count['unique_names'] !== BROWSE_CATALOG_EXPECTED_ROWS) {
            browse_catalog_fail('Imported version failed the 276 unique-name invariant.');
        }

        $activate = !isset($options['no-activate']);
        $activeCount = (int)$db->query('SELECT COUNT(*) AS count_active FROM browse_catalog_versions WHERE is_active = 1')->fetch_assoc()['count_active'];
        if ($activate && !$taxonomy['available'] && $activeCount > 0 && !isset($options['allow-taxonomy-unavailable-activation'])) {
            $activate = false;
        }
        if ($activate) {
            $db->query('UPDATE browse_catalog_versions SET is_active = 0, activated_at = NULL WHERE is_active = 1');
            browse_catalog_statement($db, 'UPDATE browse_catalog_versions SET is_active = 1, activated_at = CURRENT_TIMESTAMP WHERE id = ?', [$versionId], 'i');
        }
        $db->commit();

        $status = $activate ? 'active' : 'inactive';
        echo "Imported Browse catalog {$versionLabel}: 276 rows, {$taxonomyMatched} taxonomy matches, status={$status}." . PHP_EOL;
        if (!$activate && !$taxonomy['available'] && $activeCount > 0 && !isset($options['no-activate'])) {
            fwrite(STDERR, "WARNING: taxonomy was unavailable; the existing active catalog was preserved. Review the snapshot and use --activate-version when appropriate.\n");
        }
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
