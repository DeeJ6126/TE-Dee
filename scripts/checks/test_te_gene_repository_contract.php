<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/te_gene_repository.php';

function te_gene_test(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

$started = microtime(true);
$catalog = tekg_te_gene_catalog();
te_gene_test(count($catalog['tissues']) === 50, 'catalog must expose 50 GTEx tissues');
te_gene_test(in_array('L1HS', array_column($catalog['items'], 'te'), true), 'L1HS must be catalogued');
te_gene_test(in_array('Alu', array_column($catalog['items'], 'te'), true), 'Alu must be catalogued');

$all = tekg_te_gene_load_network('L1HS', 'all');
$labels = array_count_values(array_map(static fn(array $edge): string => $edge['edge_label'], $all['edges']));
te_gene_test(($labels['Both'] ?? 0) > 0, 'L1HS must expose Both evidence');
te_gene_test(($labels['eQTL'] ?? 0) > 0, 'L1HS must expose eQTL-only evidence');
te_gene_test(($labels['Co-expression'] ?? 0) > 0, 'L1HS must expose co-expression-only evidence');
te_gene_test(($all['counts']['aggregate_edges'] ?? 0) >= count($all['edges']), 'aggregate count cannot be below display count');
te_gene_test(($all['metadata']['display_truncated'] ?? false) === true, 'large All tissues network must advertise display truncation');
te_gene_test(count($all['nodes']) <= 100 && count($all['edges']) <= 150, 'display payload must respect G6 limits');

$liver = tekg_te_gene_load_network('L1HS', 'tissue', 'Liver');
te_gene_test(($liver['selection']['scope'] ?? '') === 'tissue', 'Liver response must use tissue scope');
te_gene_test(($liver['selection']['tissue'] ?? '') === 'Liver', 'Liver response must retain exact tissue key');
foreach ($liver['edges'] as $edge) {
    te_gene_test(($edge['scope'] ?? '') === 'tissue', 'every tissue edge must carry tissue scope');
    foreach (($edge['supporting_tissues'] ?? []) as $support) {
        te_gene_test(($support['key'] ?? '') === 'Liver', 'tissue edge cannot expose another tissue');
    }
}

$coexpressionOnly = tekg_te_gene_load_network('Alu', 'all');
te_gene_test(count($coexpressionOnly['edges']) > 0, 'Alu must retain co-expression edges');
te_gene_test(!in_array('Both', array_column($coexpressionOnly['edges'], 'edge_label'), true), 'Alu representative must remain co-expression-only');

try {
    tekg_te_gene_load_network('L1HS', 'tissue', 'not_a_gtex_tissue');
    te_gene_test(false, 'invalid tissue must be rejected');
} catch (TeGeneRepositoryException $error) {
    te_gene_test($error->errorCode() === 'invalid_tissue', 'invalid tissue must use stable error code');
}

printf("TE-Gene repository contract: PASS (%.1fs)\n", microtime(true) - $started);
