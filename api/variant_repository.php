<?php
declare(strict_types=1);

require_once __DIR__ . '/expression_repository.php';
require_once __DIR__ . '/taxonomy_lib.php';

const TEKG_VARIANT_EQTL_VERSION = 'gtex_v11_strict_te_overlap_v1';

final class TeVariantRepositoryException extends RuntimeException
{
    public function __construct(private string $codeName, string $message, private array $details = [])
    {
        parent::__construct($message);
    }

    public function codeName(): string { return $this->codeName; }
    public function details(): array { return $this->details; }
}

function tekg_variant_rows(string $sql, array $params = [], ?string $types = null): array
{
    try {
        return tekg_expression_fetch_all($sql, $params, $types);
    } catch (Throwable) {
        throw new TeVariantRepositoryException('data_contract_error', 'Variant data could not be served.');
    }
}

function tekg_variant_active_version(): array
{
    $rows = tekg_variant_rows("SELECT * FROM eqtl_analysis_versions WHERE is_active=1 AND status='validated' LIMIT 2");
    if (count($rows) !== 1 || (string)$rows[0]['version_key'] !== TEKG_VARIANT_EQTL_VERSION) {
        throw new TeVariantRepositoryException('data_contract_error', 'The approved GTEx eQTL result set is unavailable.');
    }
    return $rows[0];
}

function tekg_variant_scope(string $query, int $versionId): array
{
    $query = trim($query);
    if ($query === '') {
        throw new TeVariantRepositoryException('unknown_te', 'A TE query is required.');
    }

    $exact = tekg_variant_rows(
        'SELECT te_instance_key, te_instance_id, te_name, te_family FROM eqtl_te_instances WHERE version_id=? AND LOWER(te_name)=LOWER(?) ORDER BY te_instance_id',
        [$versionId, $query], 'is'
    );
    if ($exact !== []) {
        return ['resolved_te_name' => (string)$exact[0]['te_name'], 'scope' => 'instance', 'keys' => array_map(static fn(array $r): string => $r['te_instance_key'], $exact), 'variant_available' => true, 'reason' => null];
    }

    $family = tekg_variant_rows(
        'SELECT te_instance_key, te_name FROM eqtl_te_instances WHERE version_id=? AND LOWER(te_family)=LOWER(?) ORDER BY te_name, te_instance_id',
        [$versionId, $query], 'is'
    );
    if ($family !== []) {
        return ['resolved_te_name' => $query, 'scope' => 'family', 'keys' => array_map(static fn(array $r): string => $r['te_instance_key'], $family), 'variant_available' => true, 'reason' => null];
    }

    try {
        $taxonomy = tekg_taxonomy_fetch_items([$query]);
        $item = $taxonomy[0] ?? null;
        if (is_array($item)) {
            $path = is_array($item['path'] ?? null) ? $item['path'] : [];
            $familyName = trim((string)($path['family'] ?? ''));
            $superfamilyName = trim((string)($path['superfamily'] ?? ''));
            if ($familyName !== '') {
                $family = tekg_variant_rows('SELECT te_instance_key FROM eqtl_te_instances WHERE version_id=? AND LOWER(te_family)=LOWER(?) ORDER BY te_instance_id', [$versionId, $familyName], 'is');
                if ($family !== []) {
                    return ['resolved_te_name' => $familyName, 'scope' => 'family', 'keys' => array_map(static fn(array $r): string => $r['te_instance_key'], $family), 'variant_available' => true, 'reason' => null];
                }
            }
            if ($superfamilyName !== '' || (string)($item['taxonomy_status'] ?? '') === 'non_leaf') {
                return ['resolved_te_name' => (string)($item['name'] ?? $query), 'scope' => 'superfamily', 'keys' => [], 'variant_available' => false, 'reason' => 'Variants are not available for superfamily-level records.'];
            }
        }
    } catch (Throwable) {
        // Taxonomy availability must not turn an unknown TE into a broad query.
    }

    throw new TeVariantRepositoryException('unknown_te', 'The requested TE has no mapped Variant scope.');
}

function tekg_variant_placeholders(int $count): string
{
    return implode(',', array_fill(0, max(1, $count), '?'));
}

function tekg_variant_load(string $query, string $source = 'eqtl', string $view = 'variant', int $page = 1, int $pageSize = 25): array
{
    if (!in_array($source, ['eqtl', 'clinvar_variant', 'clinvar_cnv'], true)) throw new TeVariantRepositoryException('invalid_source', 'The requested Variant source is invalid.');
    if (!in_array($view, ['variant', 'evidence'], true)) throw new TeVariantRepositoryException('invalid_view', 'The requested Variant view is invalid.');
    $page = max(1, $page); $pageSize = min(100, max(10, $pageSize));
    $eqtl = tekg_variant_active_version();
    $scope = tekg_variant_scope($query, (int)$eqtl['id']);
    if ($source !== 'eqtl') {
        return ['source' => $source, 'view' => 'variant', 'rows' => [], 'total' => 0, 'page' => $page, 'page_size' => $pageSize, 'available' => false, 'unavailable_reason' => 'ClinVar BigBed tracks are available in Genome Browser, but no local tabular ClinVar source is configured.'];
    }
    $publicScope = $scope; unset($publicScope['keys']);
    if (!$scope['variant_available']) {
        return ['source' => $source, 'view' => $view, 'scope' => $publicScope, 'rows' => [], 'total' => 0, 'page' => $page, 'page_size' => $pageSize, 'available' => false, 'unavailable_reason' => $scope['reason']];
    }

    $keys = $scope['keys']; $in = tekg_variant_placeholders(count($keys));
    $base = [(int)$eqtl['id'], ...$keys];
    if ($view === 'variant') {
        $count = tekg_variant_rows("SELECT COUNT(*) AS total FROM (SELECT o.variant_key FROM eqtl_te_variant_overlaps o WHERE o.version_id=? AND o.te_instance_key IN ($in) GROUP BY o.variant_key) x", $base, 'i' . str_repeat('s', count($keys)));
        $sql = "SELECT v.variant_id, v.chrom, v.variant_start, v.variant_end, v.ref, v.alt,
                       COUNT(DISTINCT a.gene_id) gene_count, COUNT(DISTINCT a.tissue_key) tissue_count,
                       MIN(a.pval_nominal) minimum_pval_nominal, COUNT(a.variant_key) evidence_row_count,
                       GROUP_CONCAT(DISTINCT g.gene_name ORDER BY g.gene_name SEPARATOR ', ') gene_names,
                       GROUP_CONCAT(DISTINCT t.display_name ORDER BY t.display_name SEPARATOR ', ') tissue_names
                FROM eqtl_variants v
                LEFT JOIN eqtl_variant_gene_tissue_associations a ON a.version_id=v.version_id AND a.variant_key=v.variant_key
                LEFT JOIN eqtl_genes g ON g.version_id=a.version_id AND g.gene_id=a.gene_id
                LEFT JOIN eqtl_tissues t ON t.version_id=a.version_id AND t.tissue_key=a.tissue_key
                WHERE v.version_id=? AND EXISTS (SELECT 1 FROM eqtl_te_variant_overlaps o WHERE o.version_id=v.version_id AND o.variant_key=v.variant_key AND o.te_instance_key IN ($in))
                GROUP BY v.variant_key,v.variant_id,v.chrom,v.variant_start,v.variant_end,v.ref,v.alt
                ORDER BY v.chrom,v.variant_start,v.variant_id LIMIT ? OFFSET ?";
        $rows = tekg_variant_rows($sql, [...$base, $pageSize, ($page - 1) * $pageSize], 'i' . str_repeat('s', count($keys)) . 'ii');
    } else {
        $count = tekg_variant_rows("SELECT COUNT(*) AS total FROM eqtl_variant_gene_tissue_associations a WHERE a.version_id=? AND EXISTS (SELECT 1 FROM eqtl_te_variant_overlaps o WHERE o.version_id=a.version_id AND o.variant_key=a.variant_key AND o.te_instance_key IN ($in))", $base, 'i' . str_repeat('s', count($keys)));
        $sql = "SELECT v.variant_id,v.chrom,v.variant_start,v.variant_end,v.ref,v.alt,
                       g.gene_id,g.gene_name,t.tissue_key,t.display_name tissue_name,a.pval_nominal,a.slope,a.slope_se,a.af,a.ma_count
                FROM eqtl_variant_gene_tissue_associations a
                JOIN eqtl_variants v ON v.version_id=a.version_id AND v.variant_key=a.variant_key
                JOIN eqtl_genes g ON g.version_id=a.version_id AND g.gene_id=a.gene_id
                JOIN eqtl_tissues t ON t.version_id=a.version_id AND t.tissue_key=a.tissue_key
                WHERE a.version_id=? AND EXISTS (SELECT 1 FROM eqtl_te_variant_overlaps o WHERE o.version_id=a.version_id AND o.variant_key=a.variant_key AND o.te_instance_key IN ($in))
                ORDER BY v.chrom,v.variant_start,v.variant_id,g.gene_name,t.display_name LIMIT ? OFFSET ?";
        $rows = tekg_variant_rows($sql, [...$base, $pageSize, ($page - 1) * $pageSize], 'i' . str_repeat('s', count($keys)) . 'ii');
    }
    return ['source' => $source, 'view' => $view, 'scope' => $publicScope, 'rows' => $rows, 'total' => (int)($count[0]['total'] ?? 0), 'page' => $page, 'page_size' => $pageSize, 'available' => true, 'metadata' => ['eqtl_version' => $eqtl['version_key'], 'genome_build' => $eqtl['genome_build'], 'tissue_scope' => 'all']];
}
