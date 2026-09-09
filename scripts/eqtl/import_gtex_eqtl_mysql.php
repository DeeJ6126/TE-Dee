<?php
declare(strict_types=1);

/** Import versioned GTEx strict TE-overlap artifacts into tekg_expression. */

require_once dirname(__DIR__, 2) . '/api/expression_repository.php';

const EQTL_DEFAULT_VERSION = 'gtex_v11_strict_te_overlap_v1';
const EQTL_MAX_BATCH_BYTES = 16 * 1024 * 1024;

function eqtl_fail(string $message): never
{
    throw new RuntimeException($message);
}

function eqtl_format_duration(float $seconds): string
{
    $seconds = max(0, (int)round($seconds));
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

function eqtl_progress(
    string $label,
    int $current,
    int $total,
    float $startedAt,
    string $unit,
    string $detail = ''
): void {
    static $previousLength = 0;
    static $lastRenderedAt = [];
    $now = microtime(true);
    $force = $current >= $total
        || str_contains($detail, 'committed')
        || str_contains($detail, 'resumed');
    if (!$force && isset($lastRenderedAt[$label]) && $now - $lastRenderedAt[$label] < 1.0) {
        return;
    }
    $lastRenderedAt[$label] = $now;
    $elapsed = max(0.001, $now - $startedAt);
    $rate = $current / $elapsed;
    $percent = $total > 0 ? min(100, 100 * $current / $total) : 100;
    $eta = $rate > 0 && $current < $total ? ($total - $current) / $rate : 0;
    $line = sprintf(
        '%s %6.2f%% [%s/%s %s] %.1f %s/s ETA %s%s',
        $label,
        $percent,
        number_format($current),
        number_format($total),
        $unit,
        $rate,
        $unit,
        eqtl_format_duration($eta),
        $detail === '' ? '' : " | $detail"
    );
    $padding = max(0, $previousLength - strlen($line));
    fwrite(STDERR, "\r" . $line . str_repeat(' ', $padding));
    $previousLength = strlen($line);
    if ($current >= $total) {
        fwrite(STDERR, PHP_EOL);
        $previousLength = 0;
    }
}

function eqtl_json_file(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        eqtl_fail("Missing JSON file: $path");
    }
    $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        eqtl_fail("JSON root must be an object: $path");
    }
    return $value;
}

function eqtl_execute(mysqli $db, string $sql, array $params = []): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        eqtl_fail('MySQL prepare failed: ' . $db->error);
    }
    if ($params !== []) {
        $types = str_repeat('s', count($params));
        $bind = [$types];
        foreach ($params as $index => $_value) {
            $bind[] = &$params[$index];
        }
        if (!call_user_func_array([$stmt, 'bind_param'], $bind)) {
            $error = $stmt->error;
            $stmt->close();
            eqtl_fail('MySQL bind failed: ' . $error);
        }
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        eqtl_fail('MySQL execute failed: ' . $error);
    }
    return $stmt;
}

function eqtl_fetch_one(mysqli $db, string $sql, array $params = []): ?array
{
    $stmt = eqtl_execute($db, $sql, $params);
    $result = $stmt->get_result();
    if (!$result) {
        $error = $stmt->error;
        $stmt->close();
        eqtl_fail('MySQL result failed: ' . $error);
    }
    $row = $result->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function eqtl_apply_schema(mysqli $db): void
{
    $path = dirname(__DIR__, 2) . '/imports/eqtl_mysql_schema.sql';
    $schema = file_get_contents($path);
    if ($schema === false) {
        eqtl_fail("Missing schema: $path");
    }
    if (!$db->multi_query($schema)) {
        eqtl_fail('Schema application failed: ' . $db->error);
    }
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        if (!$db->more_results()) {
            break;
        }
        if (!$db->next_result()) {
            eqtl_fail('Schema application failed: ' . $db->error);
        }
    } while (true);
}

function eqtl_expected_columns(): array
{
    return [
        'eqtl_tissues' => ['tissue_key', 'display_name', 'source_member', 'source_row_count', 'evidence_row_count'],
        'eqtl_te_instances' => ['te_instance_key', 'te_instance_id', 'te_name', 'te_class', 'te_family', 'chrom', 'te_start', 'te_end', 'te_strand'],
        'eqtl_variants' => ['variant_key', 'variant_id', 'chrom', 'variant_start', 'variant_end', 'ref', 'alt'],
        'eqtl_genes' => ['gene_id', 'gene_id_base', 'gene_name', 'biotype', 'chrom', 'gene_start', 'gene_end', 'strand'],
        'eqtl_te_variant_overlaps' => ['te_instance_key', 'variant_key'],
        'eqtl_variant_gene_tissue_associations' => ['tissue_key', 'variant_key', 'gene_id', 'start_distance', 'af', 'ma_samples', 'ma_count', 'pval_nominal', 'slope', 'slope_se', 'pval_nominal_threshold', 'min_pval_nominal', 'pval_beta'],
        'eqtl_te_gene_tissue_summary' => ['tissue_key', 'te_name', 'gene_id', 'supporting_variant_count', 'supporting_instance_count', 'evidence_row_count', 'minimum_pval_nominal', 'maximum_abs_slope', 'positive_slope_count', 'negative_slope_count', 'direction_class'],
        'eqtl_te_gene_cross_tissue_summary' => ['te_name', 'gene_id', 'tissue_count', 'supporting_variant_count', 'supporting_instance_count', 'evidence_row_count', 'positive_tissue_count', 'negative_tissue_count', 'mixed_tissue_count', 'zero_tissue_count', 'minimum_pval_nominal', 'maximum_abs_slope'],
    ];
}

function eqtl_resolve_artifact_file(string $root, string $relative): string
{
    if ($relative === '' || str_contains($relative, "\0")) {
        eqtl_fail('Invalid empty artifact path.');
    }
    $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if ($candidate === false || !is_file($candidate)) {
        eqtl_fail("Missing artifact file: $relative");
    }
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with(strtolower($candidate), strtolower($prefix))) {
        eqtl_fail("Artifact path escapes its root: $relative");
    }
    return $candidate;
}

function eqtl_read_header_and_count(string $path, array $expectedHeader): int
{
    $handle = gzopen($path, 'rb');
    if ($handle === false) {
        eqtl_fail("Could not open gzip artifact: $path");
    }
    try {
        $header = fgetcsv($handle, 0, "\t", '"', '\\');
        if ($header !== $expectedHeader) {
            eqtl_fail("Artifact header mismatch: $path");
        }
        $rows = 0;
        while (($row = fgetcsv($handle, 0, "\t", '"', '\\')) !== false) {
            if (count($row) !== count($expectedHeader)) {
                eqtl_fail("Artifact column count mismatch at data row " . ($rows + 1) . ": $path");
            }
            $rows++;
        }
        return $rows;
    } finally {
        gzclose($handle);
    }
}

function eqtl_validate_artifacts(string $root, array $manifest): array
{
    if (($manifest['status'] ?? null) !== 'complete') {
        eqtl_fail('Artifact manifest is not complete.');
    }
    if ((int)($manifest['artifact_schema_version'] ?? 0) !== 1) {
        eqtl_fail('Unsupported artifact schema version.');
    }
    $expected = eqtl_expected_columns();
    $order = $manifest['import_order'] ?? null;
    if ($order !== array_keys($expected)) {
        eqtl_fail('Artifact import order differs from the importer contract.');
    }
    $validated = [];
    $totalFiles = array_sum(array_map(
        static fn(string $table): int => count($manifest['tables'][$table]['files'] ?? []),
        $order
    ));
    $validatedCount = 0;
    $startedAt = microtime(true);
    foreach ($order as $table) {
        $entry = $manifest['tables'][$table] ?? null;
        if (!is_array($entry) || ($entry['columns'] ?? null) !== $expected[$table]) {
            eqtl_fail("Artifact table contract mismatch: $table");
        }
        $tableRows = 0;
        foreach (($entry['files'] ?? []) as $file) {
            $relative = (string)($file['path'] ?? '');
            $path = eqtl_resolve_artifact_file($root, $relative);
            $hash = hash_file('sha256', $path);
            if (!hash_equals((string)($file['sha256'] ?? ''), $hash)) {
                eqtl_fail("Artifact SHA-256 mismatch: $relative");
            }
            $rows = eqtl_read_header_and_count($path, $expected[$table]);
            if ($rows !== (int)($file['rows'] ?? -1)) {
                eqtl_fail("Artifact row count mismatch: $relative");
            }
            $validated[] = [
                'table' => $table,
                'columns' => $expected[$table],
                'path' => $path,
                'relative' => $relative,
                'sha256' => $hash,
                'rows' => $rows,
            ];
            $tableRows += $rows;
            $validatedCount++;
            eqtl_progress(
                'Validate', $validatedCount, $totalFiles, $startedAt, 'files', $table
            );
        }
        if ($tableRows !== (int)($entry['rows'] ?? -1)) {
            eqtl_fail("Artifact table row count mismatch: $table");
        }
    }
    return $validated;
}

function eqtl_assert_target_database(mysqli $db): void
{
    $row = $db->query('SELECT DATABASE() AS database_name')?->fetch_assoc();
    $configured = (string)(tekg_expression_config()['database'] ?? '');
    if (!$row || (string)$row['database_name'] !== $configured || $configured !== 'tekg_expression') {
        eqtl_fail('Refusing eQTL import outside the configured tekg_expression database.');
    }
}

function eqtl_purge_version(mysqli $db, string $versionKey): void
{
    $row = eqtl_fetch_one(
        $db,
        'SELECT id,is_active FROM eqtl_analysis_versions WHERE version_key=?',
        [$versionKey]
    );
    if (!$row) {
        fwrite(STDOUT, "Version does not exist; nothing to purge: $versionKey\n");
        return;
    }
    if ((int)$row['is_active'] === 1) {
        eqtl_fail("Refusing to purge active eQTL version: $versionKey");
    }
    $stmt = eqtl_execute($db, 'DELETE FROM eqtl_analysis_versions WHERE id=?', [$row['id']]);
    $stmt->close();
    fwrite(STDOUT, "Purged inactive eQTL version: $versionKey\n");
}

function eqtl_create_or_resume_version(
    mysqli $db,
    string $versionKey,
    array $manifest,
    string $manifestHash,
    bool $resume,
    bool $activate
): array {
    $existing = eqtl_fetch_one(
        $db,
        'SELECT * FROM eqtl_analysis_versions WHERE version_key=?',
        [$versionKey]
    );
    $hashes = $manifest['input_hashes'];
    if ($existing) {
        foreach ([
            'archive_sha256' => 'archive_sha256',
            'te_bed_sha256' => 'te_bed_sha256',
            'browse_catalog_sha256' => 'browse_catalog_sha256',
        ] as $column => $key) {
            if (!hash_equals((string)$existing[$column], (string)$hashes[$key])) {
                eqtl_fail("Existing version input identity differs: $column");
            }
        }
        if (!hash_equals((string)$existing['artifact_manifest_sha256'], $manifestHash)) {
            eqtl_fail('Existing version artifact manifest differs. Use a new version key.');
        }
        if ($activate && (string)$existing['status'] === 'validated') {
            return ['id' => (int)$existing['id'], 'activation_only' => true];
        }
        if (!$resume) {
            eqtl_fail("Version already exists; use --resume: $versionKey");
        }
        if ((int)$existing['is_active'] === 1) {
            eqtl_fail('Refusing to resume an active version.');
        }
        $stmt = eqtl_execute(
            $db,
            "UPDATE eqtl_analysis_versions SET status='importing' WHERE id=?",
            [$existing['id']]
        );
        $stmt->close();
        return ['id' => (int)$existing['id'], 'activation_only' => false];
    }

    $parameters = json_encode([
        'artifact_schema_version' => $manifest['artifact_schema_version'],
        'part_row_limit' => $manifest['part_row_limit'],
        'coordinate_rule' => '0-based half-open REF-span intersection',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $stmt = eqtl_execute(
        $db,
        "INSERT INTO eqtl_analysis_versions
        (version_key,source_release,genome_build,mapping_type,parameters_json,
         archive_sha256,te_bed_sha256,browse_catalog_sha256,artifact_manifest_sha256,
         tissue_count,status,is_active)
        VALUES (?,'GTEx_v11','GRCh38','strict_te_overlap',?,?,?,?,?,?, 'importing',0)",
        [
            $versionKey,
            $parameters,
            $hashes['archive_sha256'],
            $hashes['te_bed_sha256'],
            $hashes['browse_catalog_sha256'],
            $manifestHash,
            (string)$manifest['tables']['eqtl_tissues']['rows'],
        ]
    );
    $stmt->close();
    return ['id' => (int)$db->insert_id, 'activation_only' => false];
}

function eqtl_row_placeholders(array $columns): string
{
    $parts = ['?'];
    foreach ($columns as $column) {
        $parts[] = in_array($column, ['te_instance_key', 'variant_key'], true)
            ? 'UNHEX(?)'
            : '?';
    }
    return '(' . implode(',', $parts) . ')';
}

function eqtl_insert_batch(
    mysqli $db,
    string $table,
    array $columns,
    int $versionId,
    array $rows
): void {
    if ($rows === []) {
        return;
    }
    $columnSql = implode(',', array_map(static fn(string $value): string => "`$value`", $columns));
    $placeholder = eqtl_row_placeholders($columns);
    $sql = "INSERT INTO `$table` (`version_id`,$columnSql) VALUES "
        . implode(',', array_fill(0, count($rows), $placeholder));
    $params = [];
    foreach ($rows as $row) {
        $params[] = (string)$versionId;
        foreach ($row as $value) {
            $params[] = $value;
        }
    }
    $stmt = eqtl_execute($db, $sql, $params);
    $stmt->close();
}

function eqtl_import_part(
    mysqli $db,
    int $versionId,
    array $file,
    int $batchSize,
    ?callable $onProgress = null
): int {
    $handle = gzopen($file['path'], 'rb');
    if ($handle === false) {
        eqtl_fail('Could not open artifact for import: ' . $file['relative']);
    }
    $rowsImported = 0;
    $batch = [];
    $batchBytes = 0;
    try {
        $header = fgetcsv($handle, 0, "\t", '"', '\\');
        if ($header !== $file['columns']) {
            eqtl_fail('Artifact header changed before import: ' . $file['relative']);
        }
        while (($row = fgetcsv($handle, 0, "\t", '"', '\\')) !== false) {
            if (count($row) !== count($file['columns'])) {
                eqtl_fail('Artifact column count changed before import: ' . $file['relative']);
            }
            $decoded = [];
            $rowBytes = 64;
            foreach ($row as $value) {
                $decodedValue = $value === '\\N' ? null : $value;
                $decoded[] = $decodedValue;
                $rowBytes += $decodedValue === null ? 0 : strlen($decodedValue);
            }
            if ($batch !== [] && (count($batch) >= $batchSize || $batchBytes + $rowBytes > EQTL_MAX_BATCH_BYTES)) {
                eqtl_insert_batch($db, $file['table'], $file['columns'], $versionId, $batch);
                $rowsImported += count($batch);
                if ($onProgress !== null) {
                    $onProgress($rowsImported);
                }
                $batch = [];
                $batchBytes = 0;
            }
            if ($rowBytes > EQTL_MAX_BATCH_BYTES) {
                eqtl_fail('One artifact row exceeds the 16 MiB importer ceiling: ' . $file['relative']);
            }
            $batch[] = $decoded;
            $batchBytes += $rowBytes;
        }
        eqtl_insert_batch($db, $file['table'], $file['columns'], $versionId, $batch);
        $rowsImported += count($batch);
        if ($onProgress !== null) {
            $onProgress($rowsImported);
        }
    } finally {
        gzclose($handle);
    }
    return $rowsImported;
}

function eqtl_import_files(
    mysqli $db,
    int $versionId,
    array $files,
    int $batchSize,
    bool $resume,
    ?string $testFailFile = null
): void {
    $totalFiles = count($files);
    $totalRows = array_sum(array_map(
        static fn(array $file): int => (int)$file['rows'],
        $files
    ));
    $completedRows = 0;
    $startedAt = microtime(true);
    foreach ($files as $fileIndex => $file) {
        $fileNumber = $fileIndex + 1;
        $ledger = eqtl_fetch_one(
            $db,
            'SELECT * FROM eqtl_import_files WHERE version_id=? AND file_key=?',
            [$versionId, $file['relative']]
        );
        if ($ledger) {
            if (!hash_equals((string)$ledger['file_sha256'], $file['sha256'])
                || (int)$ledger['expected_rows'] !== (int)$file['rows']) {
                eqtl_fail('Import ledger differs from artifact manifest: ' . $file['relative']);
            }
            if ((string)$ledger['status'] === 'completed') {
                if (!$resume) {
                    eqtl_fail('Completed file requires --resume: ' . $file['relative']);
                }
                $completedRows += (int)$file['rows'];
                eqtl_progress(
                    'Import', $completedRows, $totalRows, $startedAt, 'rows',
                    "file $fileNumber/$totalFiles (resumed)"
                );
                continue;
            }
        } else {
            $stmt = eqtl_execute(
                $db,
                "INSERT INTO eqtl_import_files
                (version_id,file_key,file_sha256,expected_rows,status)
                VALUES (?,?,?,?,'pending')",
                [$versionId, $file['relative'], $file['sha256'], $file['rows']]
            );
            $stmt->close();
        }
        $stmt = eqtl_execute(
            $db,
            "UPDATE eqtl_import_files SET status='importing',started_at=CURRENT_TIMESTAMP,
             completed_at=NULL,error_message=NULL WHERE version_id=? AND file_key=?",
            [$versionId, $file['relative']]
        );
        $stmt->close();
        try {
            $db->begin_transaction();
            $rowsBeforeFile = $completedRows;
            $rows = eqtl_import_part(
                $db,
                $versionId,
                $file,
                $batchSize,
                static function (int $partRows) use (
                    $rowsBeforeFile, $totalRows, $startedAt, $fileNumber, $totalFiles
                ): void {
                    eqtl_progress(
                        'Import', $rowsBeforeFile + $partRows, $totalRows,
                        $startedAt, 'rows', "file $fileNumber/$totalFiles"
                    );
                }
            );
            if ($rows !== (int)$file['rows']) {
                eqtl_fail('Imported row count differs: ' . $file['relative']);
            }
            if ($testFailFile !== null && $file['relative'] === $testFailFile) {
                eqtl_fail('Injected fixture import failure: ' . $file['relative']);
            }
            $stmt = eqtl_execute(
                $db,
                "UPDATE eqtl_import_files SET status='completed',imported_rows=?,
                 completed_at=CURRENT_TIMESTAMP,error_message=NULL
                 WHERE version_id=? AND file_key=?",
                [$rows, $versionId, $file['relative']]
            );
            $stmt->close();
            $db->commit();
            $completedRows += $rows;
            eqtl_progress(
                'Import', $completedRows, $totalRows, $startedAt, 'rows',
                "file $fileNumber/$totalFiles committed"
            );
        } catch (Throwable $error) {
            $db->rollback();
            $stmt = eqtl_execute(
                $db,
                "UPDATE eqtl_import_files SET status='failed',error_message=?
                 WHERE version_id=? AND file_key=?",
                [substr($error->getMessage(), 0, 65000), $versionId, $file['relative']]
            );
            $stmt->close();
            throw $error;
        }
    }
}

function eqtl_validate_database_version(
    mysqli $db,
    int $versionId,
    array $manifest,
    bool $markValidated
): void {
    $validationStartedAt = microtime(true);
    fwrite(STDOUT, "Validation: checking manifest row counts...\n");
    foreach ($manifest['import_order'] as $table) {
        $row = eqtl_fetch_one(
            $db,
            "SELECT COUNT(*) AS row_count FROM `$table` WHERE version_id=?",
            [$versionId]
        );
        if ((int)$row['row_count'] !== (int)$manifest['tables'][$table]['rows']) {
            eqtl_fail("MySQL row count differs from artifact manifest: $table");
        }
    }
    fwrite(
        STDOUT,
        sprintf("Validation: row counts passed (%.1fs); checking distinct association Variants...\n", microtime(true) - $validationStartedAt)
    );
    $orphan = eqtl_fetch_one(
        $db,
        "SELECT COUNT(*) AS row_count
         FROM (
           SELECT DISTINCT variant_key
           FROM eqtl_variant_gene_tissue_associations
           WHERE version_id=?
         ) a
         LEFT JOIN eqtl_te_variant_overlaps o
           ON o.version_id=? AND o.variant_key=a.variant_key
         WHERE o.variant_key IS NULL",
        [$versionId, $versionId]
    );
    if ((int)$orphan['row_count'] !== 0) {
        eqtl_fail('MySQL contains associations without a TE overlap.');
    }
    $incomplete = eqtl_fetch_one(
        $db,
        "SELECT COUNT(*) AS row_count FROM eqtl_import_files
         WHERE version_id=? AND status<>'completed'",
        [$versionId]
    );
    if ((int)$incomplete['row_count'] !== 0) {
        eqtl_fail('Import ledger still contains incomplete files.');
    }
    fwrite(
        STDOUT,
        sprintf("Validation: relation integrity and import ledger passed (%.1fs).\n", microtime(true) - $validationStartedAt)
    );
    if ($markValidated) {
        $source = eqtl_fetch_one(
            $db,
            'SELECT COALESCE(SUM(source_row_count),0) AS value FROM eqtl_tissues WHERE version_id=?',
            [$versionId]
        );
        $stmt = eqtl_execute(
            $db,
            "UPDATE eqtl_analysis_versions SET status='validated',validated_at=CURRENT_TIMESTAMP,
             source_association_count=?,overlap_association_count=?,
             te_gene_tissue_count=?,te_gene_cross_tissue_count=? WHERE id=? AND is_active=0",
            [
                $source['value'],
                $manifest['tables']['eqtl_variant_gene_tissue_associations']['rows'],
                $manifest['tables']['eqtl_te_gene_tissue_summary']['rows'],
                $manifest['tables']['eqtl_te_gene_cross_tissue_summary']['rows'],
                $versionId,
            ]
        );
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            eqtl_fail('Could not mark the inactive candidate version as validated.');
        }
        $stmt->close();
    }
}

function eqtl_activate(mysqli $db, int $versionId): void
{
    $db->begin_transaction();
    try {
        $candidate = eqtl_fetch_one(
            $db,
            'SELECT id,status,is_active FROM eqtl_analysis_versions WHERE id=? FOR UPDATE',
            [$versionId]
        );
        if (!$candidate || (string)$candidate['status'] !== 'validated') {
            eqtl_fail('Only a validated candidate can be activated.');
        }
        if ((int)$candidate['is_active'] === 1) {
            $db->commit();
            return;
        }
        if (!$db->query('UPDATE eqtl_analysis_versions SET is_active=0,activated_at=NULL WHERE is_active=1')) {
            eqtl_fail('Could not deactivate the previous eQTL version: ' . $db->error);
        }
        $stmt = eqtl_execute(
            $db,
            "UPDATE eqtl_analysis_versions SET is_active=1,activated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='validated'",
            [$versionId]
        );
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            eqtl_fail('Activation did not affect exactly one candidate row.');
        }
        $stmt->close();
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

$options = getopt('', [
    'artifact-root:', 'version-key:', 'batch-size:', 'validate-only',
    'resume', 'activate', 'purge-version', 'test-fail-file:',
]);
$projectRoot = dirname(__DIR__, 2);
$artifactRootInput = (string)($options['artifact-root'] ?? 'data/eQTL/derived/' . EQTL_DEFAULT_VERSION . '/mysql');
$artifactRoot = realpath(
    preg_match('/^[A-Za-z]:[\\\\\/]/', $artifactRootInput)
        ? $artifactRootInput
        : $projectRoot . DIRECTORY_SEPARATOR . $artifactRootInput
);
$versionKeyOption = isset($options['version-key']) ? (string)$options['version-key'] : null;
$batchSize = (int)($options['batch-size'] ?? 500);
if ($batchSize < 1 || $batchSize > 2000) {
    fwrite(STDERR, "FAIL: --batch-size must be between 1 and 2000.\n");
    exit(1);
}

$db = null;
$versionId = null;
try {
    $db = tekg_expression_db();
    eqtl_assert_target_database($db);
    if (isset($options['purge-version'])) {
        if ($versionKeyOption === null || $versionKeyOption === '') {
            eqtl_fail('--purge-version requires --version-key.');
        }
        eqtl_apply_schema($db);
        eqtl_purge_version($db, $versionKeyOption);
        exit(0);
    }
    if ($artifactRoot === false || !is_dir($artifactRoot)) {
        eqtl_fail("Artifact root does not exist: $artifactRootInput");
    }
    $manifestPath = $artifactRoot . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifest = eqtl_json_file($manifestPath);
    $versionKey = $versionKeyOption ?? (string)($manifest['version_key'] ?? '');
    if ($versionKey === '' || $versionKey !== (string)($manifest['version_key'] ?? '')) {
        eqtl_fail('Requested version key differs from the artifact manifest.');
    }
    $testFailFile = isset($options['test-fail-file'])
        ? (string)$options['test-fail-file']
        : null;
    if ($testFailFile !== null && !str_contains($versionKey, 'fixture')) {
        eqtl_fail('--test-fail-file is restricted to fixture versions.');
    }
    $validatedFiles = eqtl_validate_artifacts($artifactRoot, $manifest);
    if (isset($options['validate-only'])) {
        fwrite(STDOUT, 'Validated ' . count($validatedFiles) . " artifact files for $versionKey.\n");
        exit(0);
    }

    eqtl_apply_schema($db);
    $candidate = eqtl_create_or_resume_version(
        $db,
        $versionKey,
        $manifest,
        hash_file('sha256', $manifestPath),
        isset($options['resume']),
        isset($options['activate'])
    );
    $versionId = (int)$candidate['id'];
    if (!$candidate['activation_only']) {
        eqtl_import_files(
            $db,
            $versionId,
            $validatedFiles,
            $batchSize,
            isset($options['resume']),
            $testFailFile
        );
        eqtl_validate_database_version($db, $versionId, $manifest, true);
    } else {
        eqtl_validate_database_version($db, $versionId, $manifest, false);
    }
    if (isset($options['activate'])) {
        eqtl_activate($db, $versionId);
    }
    fwrite(
        STDOUT,
        "eQTL version ready: $versionKey (id=$versionId, active="
        . (isset($options['activate']) ? 'yes' : 'no') . ")\n"
    );
} catch (Throwable $error) {
    if ($db instanceof mysqli && $versionId !== null) {
        try {
            $row = eqtl_fetch_one($db, 'SELECT is_active FROM eqtl_analysis_versions WHERE id=?', [$versionId]);
            if ($row && (int)$row['is_active'] === 0) {
                $stmt = eqtl_execute(
                    $db,
                    "UPDATE eqtl_analysis_versions SET status='failed' WHERE id=?",
                    [$versionId]
                );
                $stmt->close();
            }
        } catch (Throwable) {
            // Preserve the original error.
        }
    }
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
