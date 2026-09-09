<?php
declare(strict_types=1);

/** Validate an imported GTEx eQTL version against its artifact manifest. */

require_once dirname(__DIR__, 2) . '/api/expression_repository.php';

function contract_fail(string $message): never
{
    throw new RuntimeException($message);
}

function contract_execute(mysqli $db, string $sql, array $params = []): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        contract_fail('Prepare failed: ' . $db->error);
    }
    if ($params !== []) {
        $types = str_repeat('s', count($params));
        $bind = [$types];
        foreach ($params as $index => $_value) {
            $bind[] = &$params[$index];
        }
        if (!call_user_func_array([$stmt, 'bind_param'], $bind)) {
            contract_fail('Bind failed: ' . $stmt->error);
        }
    }
    if (!$stmt->execute()) {
        contract_fail('Execute failed: ' . $stmt->error);
    }
    return $stmt;
}

function contract_rows(mysqli $db, string $sql, array $params = []): array
{
    $stmt = contract_execute($db, $sql, $params);
    $result = $stmt->get_result();
    if (!$result) {
        contract_fail('Result failed: ' . $stmt->error);
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function contract_one(mysqli $db, string $sql, array $params = []): ?array
{
    return contract_rows($db, $sql, $params)[0] ?? null;
}

function contract_equal_float(mixed $left, mixed $right, string $label): void
{
    if ($left === null || $right === null) {
        if ($left !== $right) {
            contract_fail("NULL mismatch for $label");
        }
        return;
    }
    $a = (float)$left;
    $b = (float)$right;
    $scale = max(1.0, abs($a), abs($b));
    if (abs($a - $b) > 1e-12 * $scale) {
        contract_fail("Floating-point mismatch for $label: $a vs $b");
    }
}

function contract_stage(string $message, float $startedAt): void
{
    fwrite(STDOUT, sprintf("Contract check: %s (elapsed %.1fs)\n", $message, microtime(true) - $startedAt));
}

function contract_artifact_root(string $input): string
{
    $projectRoot = dirname(__DIR__, 2);
    $candidate = preg_match('/^[A-Za-z]:[\\\\\/]/', $input)
        ? $input
        : $projectRoot . DIRECTORY_SEPARATOR . $input;
    $resolved = realpath($candidate);
    if ($resolved === false || !is_dir($resolved)) {
        contract_fail("Artifact root does not exist: $input");
    }
    return $resolved;
}

$options = getopt('', ['version-key:', 'artifact-root:', 'require-active']);
$versionKey = (string)($options['version-key'] ?? 'gtex_v11_strict_te_overlap_v1');
$artifactRoot = contract_artifact_root(
    (string)($options['artifact-root'] ?? 'data/eQTL/derived/gtex_v11_strict_te_overlap_v1/mysql')
);

try {
    $startedAt = microtime(true);
    $manifestPath = $artifactRoot . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifestRaw = file_get_contents($manifestPath);
    if ($manifestRaw === false) {
        contract_fail("Missing artifact manifest: $manifestPath");
    }
    $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
    $db = tekg_expression_db();
    $database = $db->query('SELECT DATABASE() AS name')?->fetch_assoc()['name'] ?? '';
    if ($database !== 'tekg_expression') {
        contract_fail('Contract checker connected to the wrong database.');
    }
    $version = contract_one(
        $db,
        'SELECT * FROM eqtl_analysis_versions WHERE version_key=?',
        [$versionKey]
    );
    if (!$version) {
        contract_fail("Version not found: $versionKey");
    }
    $versionId = (int)$version['id'];
    if ((string)$version['status'] !== 'validated') {
        contract_fail('Version is not validated.');
    }
    if (!hash_equals((string)$version['artifact_manifest_sha256'], hash('sha256', $manifestRaw))) {
        contract_fail('Imported artifact manifest hash differs from the current file.');
    }
    if (isset($options['require-active'])) {
        $active = contract_one(
            $db,
            'SELECT COUNT(*) AS count_value FROM eqtl_analysis_versions WHERE is_active=1'
        );
        if ((int)$version['is_active'] !== 1 || (int)$active['count_value'] !== 1) {
            contract_fail('Exactly one active eQTL version was required.');
        }
    }

    contract_stage('checking manifest row counts', $startedAt);
    foreach ($manifest['import_order'] as $table) {
        $actual = contract_one(
            $db,
            "SELECT COUNT(*) AS count_value FROM `$table` WHERE version_id=?",
            [$versionId]
        );
        if ((int)$actual['count_value'] !== (int)$manifest['tables'][$table]['rows']) {
            contract_fail("Row count mismatch: $table");
        }
    }
    $expectedFileCount = 0;
    foreach ($manifest['tables'] as $entry) {
        $expectedFileCount += count($entry['files']);
    }
    $ledger = contract_one(
        $db,
        "SELECT COUNT(*) AS total,
         SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed
         FROM eqtl_import_files WHERE version_id=?",
        [$versionId]
    );
    if ((int)$ledger['total'] !== $expectedFileCount || (int)$ledger['completed'] !== $expectedFileCount) {
        contract_fail('Import ledger is incomplete.');
    }

    contract_stage('row counts and ledger passed; checking relation integrity', $startedAt);
    $orphanQueries = [
        'TE-overlap to TE-instance' => [
            "SELECT COUNT(*) AS count_value FROM eqtl_te_variant_overlaps o LEFT JOIN eqtl_te_instances t ON t.version_id=o.version_id AND t.te_instance_key=o.te_instance_key WHERE o.version_id=? AND t.te_instance_key IS NULL",
            [$versionId],
        ],
        'TE-overlap to Variant' => [
            "SELECT COUNT(*) AS count_value FROM eqtl_te_variant_overlaps o LEFT JOIN eqtl_variants v ON v.version_id=o.version_id AND v.variant_key=o.variant_key WHERE o.version_id=? AND v.variant_key IS NULL",
            [$versionId],
        ],
        'association to Tissue' => [
            "SELECT COUNT(*) AS count_value FROM (SELECT DISTINCT tissue_key FROM eqtl_variant_gene_tissue_associations WHERE version_id=?) a LEFT JOIN eqtl_tissues t ON t.version_id=? AND t.tissue_key=a.tissue_key WHERE t.tissue_key IS NULL",
            [$versionId, $versionId],
        ],
        'association to Variant' => [
            "SELECT COUNT(*) AS count_value FROM (SELECT DISTINCT variant_key FROM eqtl_variant_gene_tissue_associations WHERE version_id=?) a LEFT JOIN eqtl_variants v ON v.version_id=? AND v.variant_key=a.variant_key WHERE v.variant_key IS NULL",
            [$versionId, $versionId],
        ],
        'association to Gene' => [
            "SELECT COUNT(*) AS count_value FROM (SELECT DISTINCT gene_id FROM eqtl_variant_gene_tissue_associations WHERE version_id=?) a LEFT JOIN eqtl_genes g ON g.version_id=? AND g.gene_id=a.gene_id WHERE g.gene_id IS NULL",
            [$versionId, $versionId],
        ],
        'association Variant to TE-overlap' => [
            "SELECT COUNT(*) AS count_value FROM (SELECT DISTINCT variant_key FROM eqtl_variant_gene_tissue_associations WHERE version_id=?) a LEFT JOIN eqtl_te_variant_overlaps o ON o.version_id=? AND o.variant_key=a.variant_key WHERE o.variant_key IS NULL",
            [$versionId, $versionId],
        ],
    ];
    foreach ($orphanQueries as $label => [$sql, $params]) {
        contract_stage("checking $label", $startedAt);
        $row = contract_one($db, $sql, $params);
        if ((int)$row['count_value'] !== 0) {
            contract_fail("Relation-integrity query returned nonzero rows: $label");
        }
    }

    contract_stage('relation integrity passed; recomputing sample summaries', $startedAt);
    $samples = contract_rows(
        $db,
        "SELECT * FROM eqtl_te_gene_tissue_summary WHERE version_id=?
         ORDER BY tissue_key,te_name,gene_id LIMIT 3",
        [$versionId]
    );
    foreach ($samples as $sample) {
        $rebuilt = contract_one(
            $db,
            "SELECT COUNT(DISTINCT a.variant_key) AS supporting_variant_count,
             COUNT(DISTINCT o.te_instance_key) AS supporting_instance_count,
             COUNT(*) AS evidence_row_count,MIN(a.pval_nominal) AS minimum_pval_nominal,
             MAX(ABS(a.slope)) AS maximum_abs_slope,
             SUM(a.slope>0) AS positive_slope_count,SUM(a.slope<0) AS negative_slope_count
             FROM eqtl_variant_gene_tissue_associations a
             JOIN eqtl_te_variant_overlaps o ON o.version_id=a.version_id AND o.variant_key=a.variant_key
             JOIN eqtl_te_instances t ON t.version_id=o.version_id AND t.te_instance_key=o.te_instance_key
             WHERE a.version_id=? AND a.tissue_key=? AND t.te_name=? AND a.gene_id=?",
            [$versionId, $sample['tissue_key'], $sample['te_name'], $sample['gene_id']]
        );
        foreach (['supporting_variant_count', 'supporting_instance_count', 'evidence_row_count', 'positive_slope_count', 'negative_slope_count'] as $field) {
            if ((int)$sample[$field] !== (int)$rebuilt[$field]) {
                contract_fail("Sample summary count mismatch: $field");
            }
        }
        contract_equal_float($sample['minimum_pval_nominal'], $rebuilt['minimum_pval_nominal'], 'minimum_pval_nominal');
        contract_equal_float($sample['maximum_abs_slope'], $rebuilt['maximum_abs_slope'], 'maximum_abs_slope');
    }

    $crossSample = contract_one(
        $db,
        "SELECT * FROM eqtl_te_gene_cross_tissue_summary WHERE version_id=?
         ORDER BY evidence_row_count,te_name,gene_id LIMIT 1",
        [$versionId]
    );
    if (!$crossSample) {
        contract_fail('No cross-tissue summary was available for recomputation.');
    }
    $crossBase = contract_one(
        $db,
        "SELECT COUNT(DISTINCT a.variant_key) AS supporting_variant_count,
         COUNT(DISTINCT o.te_instance_key) AS supporting_instance_count,
         COUNT(*) AS evidence_row_count,MIN(a.pval_nominal) AS minimum_pval_nominal,
         MAX(ABS(a.slope)) AS maximum_abs_slope
         FROM eqtl_variant_gene_tissue_associations a
         JOIN eqtl_te_variant_overlaps o ON o.version_id=a.version_id AND o.variant_key=a.variant_key
         JOIN eqtl_te_instances t ON t.version_id=o.version_id AND t.te_instance_key=o.te_instance_key
         WHERE a.version_id=? AND t.te_name=? AND a.gene_id=?",
        [$versionId, $crossSample['te_name'], $crossSample['gene_id']]
    );
    $crossDirections = contract_one(
        $db,
        "SELECT COUNT(*) AS tissue_count,
         SUM(direction_class='positive_only') AS positive_tissue_count,
         SUM(direction_class='negative_only') AS negative_tissue_count,
         SUM(direction_class='mixed') AS mixed_tissue_count,
         SUM(direction_class='zero_only') AS zero_tissue_count
         FROM (
           SELECT a.tissue_key,
             CASE
               WHEN SUM(a.slope>0)>0 AND SUM(a.slope<0)>0 THEN 'mixed'
               WHEN SUM(a.slope>0)>0 THEN 'positive_only'
               WHEN SUM(a.slope<0)>0 THEN 'negative_only'
               ELSE 'zero_only'
             END AS direction_class
           FROM eqtl_variant_gene_tissue_associations a
           JOIN eqtl_te_variant_overlaps o ON o.version_id=a.version_id AND o.variant_key=a.variant_key
           JOIN eqtl_te_instances t ON t.version_id=o.version_id AND t.te_instance_key=o.te_instance_key
           WHERE a.version_id=? AND t.te_name=? AND a.gene_id=?
           GROUP BY a.tissue_key
         ) rebuilt_directions",
        [$versionId, $crossSample['te_name'], $crossSample['gene_id']]
    );
    foreach (['supporting_variant_count', 'supporting_instance_count', 'evidence_row_count'] as $field) {
        if ((int)$crossSample[$field] !== (int)$crossBase[$field]) {
            contract_fail("Cross-tissue sample count mismatch: $field");
        }
    }
    foreach (['tissue_count', 'positive_tissue_count', 'negative_tissue_count', 'mixed_tissue_count', 'zero_tissue_count'] as $field) {
        if ((int)$crossSample[$field] !== (int)$crossDirections[$field]) {
            contract_fail("Cross-tissue direction count mismatch: $field");
        }
    }
    contract_equal_float($crossSample['minimum_pval_nominal'], $crossBase['minimum_pval_nominal'], 'cross minimum_pval_nominal');
    contract_equal_float($crossSample['maximum_abs_slope'], $crossBase['maximum_abs_slope'], 'cross maximum_abs_slope');

    $representatives = [];
    foreach (['L1PA4', 'L1HS'] as $teName) {
        $row = contract_one(
            $db,
            'SELECT COUNT(*) AS count_value FROM eqtl_te_gene_tissue_summary WHERE version_id=? AND te_name=?',
            [$versionId, $teName]
        );
        $representatives[$teName] = (int)$row['count_value'];
    }
    $top = contract_one(
        $db,
        "SELECT te_name,COUNT(*) AS pair_count FROM eqtl_te_gene_cross_tissue_summary
         WHERE version_id=? GROUP BY te_name ORDER BY pair_count DESC,te_name LIMIT 1",
        [$versionId]
    );
    $representativeGenes = contract_rows(
        $db,
        "SELECT gene_id,COUNT(*) AS pair_count FROM eqtl_te_gene_cross_tissue_summary
         WHERE version_id=? GROUP BY gene_id ORDER BY pair_count DESC,gene_id LIMIT 3",
        [$versionId]
    );
    if (count($representativeGenes) !== 3) {
        contract_fail('Three representative Genes were required.');
    }
    $teExplain = contract_rows(
        $db,
        "EXPLAIN SELECT gene_id FROM eqtl_te_gene_tissue_summary
         WHERE version_id=? AND te_name='L1HS' AND tissue_key='Liver'",
        [$versionId]
    );
    $geneExplain = contract_rows(
        $db,
        "EXPLAIN SELECT te_name FROM eqtl_te_gene_cross_tissue_summary
         WHERE version_id=? AND gene_id=?",
        [$versionId, $representativeGenes[0]['gene_id']]
    );
    $tissueExplain = contract_rows(
        $db,
        "EXPLAIN SELECT te_name,gene_id FROM eqtl_te_gene_tissue_summary
         WHERE version_id=? AND tissue_key='Liver'",
        [$versionId]
    );
    $explainKeys = [
        'te_centered' => $teExplain[0]['key'] ?? null,
        'gene_centered' => $geneExplain[0]['key'] ?? null,
        'tissue_filtered' => $tissueExplain[0]['key'] ?? null,
    ];
    foreach ($explainKeys as $label => $key) {
        if ($key === null) {
            contract_fail("Representative $label query did not select an index.");
        }
    }

    contract_stage('all checks passed', $startedAt);

    fwrite(STDOUT, json_encode([
        'version_key' => $versionKey,
        'version_id' => $versionId,
        'active' => (int)$version['is_active'],
        'representative_rows' => $representatives,
        'representative_genes' => $representativeGenes,
        'top_cross_tissue_te' => $top,
        'cross_tissue_recomputed' => [
            'te_name' => $crossSample['te_name'],
            'gene_id' => $crossSample['gene_id'],
        ],
        'explain_keys' => $explainKeys,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
