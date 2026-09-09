<?php
declare(strict_types=1);

require_once __DIR__ . '/coexpression_repository.php';

const TEKG_EQTL_VERSION_KEY = 'gtex_v11_strict_te_overlap_v1';
const TEKG_GENE_MAPPING_AUDIT_VERSION = 'gene_mapping_audit_v1';
const TEKG_GENE_MAPPING_ARTIFACT = 'data/coexpression/feature_annotation/feature_annotation.tsv';

final class TeGeneRepositoryException extends RuntimeException
{
    public function __construct(private string $repositoryErrorCode, string $message, private array $repositoryDetails = []) { parent::__construct($message); }
    public function errorCode(): string { return $this->repositoryErrorCode; }
    public function details(): array { return $this->repositoryDetails; }
}

function tekg_te_gene_rows(string $sql, array $params = [], ?string $types = null): array
{
    try { return tekg_expression_fetch_all($sql, $params, $types); }
    catch (Throwable) { throw new TeGeneRepositoryException('data_contract_error', 'The approved TE-Gene data could not be served.'); }
}

function tekg_te_gene_eqtl_version(): array
{
    $rows = tekg_te_gene_rows('SELECT * FROM eqtl_analysis_versions WHERE is_active=1 LIMIT 2');
    if (count($rows) !== 1 || (string)$rows[0]['version_key'] !== TEKG_EQTL_VERSION_KEY || (string)$rows[0]['status'] !== 'validated') {
        throw new TeGeneRepositoryException('data_contract_error', 'The approved GTEx eQTL result set is unavailable.');
    }
    return $rows[0];
}

function tekg_te_gene_mapping(): array
{
    static $mapping = null;
    if (is_array($mapping)) return $mapping;
    $path = dirname(__DIR__) . '/' . TEKG_GENE_MAPPING_ARTIFACT;
    if (!is_file($path) || !is_readable($path)) throw new TeGeneRepositoryException('data_contract_error', 'The Gene mapping audit artifact is unavailable.');
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new TeGeneRepositoryException('data_contract_error', 'The Gene mapping audit artifact could not be read.');
    $header = fgetcsv($handle, 0, "\t", '"', "\\");
    $mapping = [];
    while (($row = fgetcsv($handle, 0, "\t", '"', "\\")) !== false) {
        if (count($row) < count($header)) continue;
        $item = array_combine($header, $row);
        $symbol = trim((string)($item['feature'] ?? ''));
        if ($symbol !== '' && strtolower((string)($item['feature_type'] ?? '')) === 'gene' && strtolower((string)($item['confidence'] ?? '')) === 'high') {
            $mapping[strtolower($symbol)] = $symbol;
        }
    }
    fclose($handle);
    return $mapping;
}

function tekg_te_gene_metadata(array $eqtl): array
{
    return [
        'eqtl_version' => (string)$eqtl['version_key'],
        'eqtl_source_release' => (string)$eqtl['source_release'],
        'eqtl_genome_build' => (string)$eqtl['genome_build'],
        'gene_mapping_audit_version' => TEKG_GENE_MAPPING_AUDIT_VERSION,
        'gene_mapping_artifact' => TEKG_GENE_MAPPING_ARTIFACT,
        'evidence_disclaimer' => 'TE-overlap and eQTL evidence is positional/statistical evidence, not proof of TE-mediated causality.',
    ];
}

function tekg_te_gene_tissues(array $eqtl): array
{
    $rows = tekg_te_gene_rows('SELECT tissue_key,display_name FROM eqtl_tissues WHERE version_id=? ORDER BY display_name COLLATE utf8mb4_general_ci', [(int)$eqtl['id']], 'i');
    return array_map(static fn(array $r): array => ['key' => (string)$r['tissue_key'], 'label' => (string)$r['display_name']], $rows);
}

function tekg_te_gene_catalog(): array
{
    $eqtl = tekg_te_gene_eqtl_version();
    $coex = tekg_coexpression_catalog();
    $teMap = [];
    foreach ($coex['items'] as $item) $teMap[strtolower((string)$item['te'])] = ['te' => (string)$item['te'], 'source' => 'coexpression'];
    $eqtlRows = tekg_te_gene_rows('SELECT DISTINCT te_name FROM eqtl_te_gene_cross_tissue_summary WHERE version_id=? ORDER BY te_name COLLATE utf8mb4_general_ci', [(int)$eqtl['id']], 'i');
    foreach ($eqtlRows as $row) {
        $te = trim((string)$row['te_name']); if ($te === '') continue;
        $key = strtolower($te);
        if (isset($teMap[$key])) $teMap[$key]['source'] = 'coexpression+eqtl';
        else $teMap[$key] = ['te' => $te, 'source' => 'eqtl'];
    }
    $items = array_values($teMap); usort($items, static fn(array $a, array $b): int => strnatcasecmp($a['te'], $b['te']));
    foreach ($items as &$item) { $item['available_contexts'] = array_keys(TEKG_COEXPRESSION_CONTEXTS); $item['best_tier'] = null; $item['recommended_default'] = false; } unset($item);
    return [
        'version' => 'te_gene_graph_v1',
        'items' => $items,
        'gene_items' => $coex['gene_items'],
        'contexts' => $coex['contexts'],
        'default_selection' => ['feature' => $coex['default_selection']['feature'], 'feature_type' => 'TE', 'te' => $coex['default_selection']['te'], 'context' => $coex['default_selection']['context']],
        'method' => $coex['method'],
        'thresholds' => $coex['thresholds'],
        'interpretation_limit' => $coex['interpretation_limit'],
        'tissues' => tekg_te_gene_tissues($eqtl),
        'metadata' => tekg_te_gene_metadata($eqtl),
    ];
}

function tekg_te_gene_resolve_te(string $te): string
{
    $needle = strtolower(trim($te)); if ($needle === '') throw new TeGeneRepositoryException('unknown_te', 'The requested TE is not present in the TE-Gene catalog.');
    foreach (tekg_te_gene_catalog()['items'] as $item) if (strtolower($item['te']) === $needle) return $item['te'];
    throw new TeGeneRepositoryException('unknown_te', 'The requested TE is not present in the TE-Gene catalog.');
}

function tekg_te_gene_load_network(string $teName, string $scope = 'all', ?string $tissue = null): array
{
    if (!in_array($scope, ['all', 'tissue'], true)) throw new TeGeneRepositoryException('invalid_scope', 'The requested tissue scope is invalid.');
    $eqtl = tekg_te_gene_eqtl_version();
    $te = tekg_te_gene_resolve_te($teName);
    $tissueMap = []; foreach (tekg_te_gene_tissues($eqtl) as $row) $tissueMap[$row['key']] = $row['label'];
    if ($scope === 'tissue' && (!is_string($tissue) || !isset($tissueMap[$tissue]))) throw new TeGeneRepositoryException('invalid_tissue', 'The requested GTEx tissue is invalid.', ['tissues' => array_keys($tissueMap)]);

    $mapping = tekg_te_gene_mapping();
    $nodes = []; $coexByPair = [];
    $coexVersion = tekg_coexpression_active_version();
    $networks = tekg_te_gene_rows('SELECT * FROM coexpression_networks WHERE version_id=? AND LOWER(center_te)=LOWER(?) ORDER BY context_key', [(int)$coexVersion['id'], $te], 'is');
    foreach ($networks as $network) {
        $networkId = (int)$network['id'];
        foreach (tekg_te_gene_rows('SELECT node_id id,label,feature_type,role,module_id,is_center,is_module_hub,degree_hint FROM coexpression_network_nodes WHERE network_id=? ORDER BY node_order', [$networkId], 'i') as $node) {
            $type = strtolower((string)$node['feature_type']) === 'gene' ? 'Gene' : 'TE';
            $label = (string)$node['label']; $id = $type === 'Gene' ? 'gene:' . $label : 'te:' . $label;
            if (!isset($nodes[$id])) $nodes[$id] = ['id' => $id, 'label' => $label, 'feature_type' => $type === 'Gene' ? 'gene' : 'TE', 'role' => (string)$node['role'], 'is_center' => $type === 'TE' && strcasecmp($label, $te) === 0];
        }
        $edgeRows = tekg_te_gene_rows('SELECT e.source_id,e.target_id,e.correlation,e.abs_correlation,e.fdr,e.pair_type,e.role, s.label source_label,s.feature_type source_type,t.label target_label,t.feature_type target_type FROM coexpression_network_edges e JOIN coexpression_network_nodes s ON s.network_id=e.network_id AND s.node_id=e.source_id JOIN coexpression_network_nodes t ON t.network_id=e.network_id AND t.node_id=e.target_id WHERE e.network_id=? ORDER BY e.edge_order', [$networkId], 'i');
        foreach ($edgeRows as $edge) {
            if (strtolower((string)$edge['source_type']) === strtolower((string)$edge['target_type'])) continue;
            $gene = strtolower((string)$edge['source_type']) === 'gene' ? (string)$edge['source_label'] : (string)$edge['target_label'];
            $key = strtolower($te) . "\0" . strtolower($gene);
            if (!isset($coexByPair[$key])) $coexByPair[$key] = ['te' => $te, 'gene' => $gene, 'details' => []];
            $coexByPair[$key]['details'][] = ['context' => (string)$network['context_key'], 'correlation' => (float)$edge['correlation'], 'abs_correlation' => (float)$edge['abs_correlation'], 'fdr' => (float)$edge['fdr'], 'pair_type' => (string)$edge['pair_type'], 'role' => (string)$edge['role']];
        }
    }
    $eqtlByPair = [];
    if ($scope === 'all') {
        $rows = tekg_te_gene_rows('SELECT s.*, g.gene_id_base, g.gene_name FROM eqtl_te_gene_cross_tissue_summary s JOIN eqtl_genes g ON g.version_id=s.version_id AND g.gene_id=s.gene_id WHERE s.version_id=? AND s.te_name=? ORDER BY g.gene_name', [(int)$eqtl['id'], $te], 'is');
        foreach ($rows as $row) { $gene = trim((string)$row['gene_name']); if (!isset($mapping[strtolower($gene)])) continue; $key = strtolower($te)."\0".strtolower($gene); $eqtlByPair[$key] = ['te'=>$te,'gene'=>$gene,'details'=>[['scope'=>'all','tissue_count'=>(int)$row['tissue_count'],'supporting_tissues'=>[],'supporting_variant_count'=>(int)$row['supporting_variant_count'],'supporting_instance_count'=>(int)$row['supporting_instance_count'],'evidence_row_count'=>(int)$row['evidence_row_count'],'minimum_pval_nominal'=>$row['minimum_pval_nominal']===null?null:(float)$row['minimum_pval_nominal'],'maximum_abs_slope'=>$row['maximum_abs_slope']===null?null:(float)$row['maximum_abs_slope']]]]; }
        foreach ($eqtlByPair as &$item) { $trows = tekg_te_gene_rows('SELECT t.tissue_key,t.display_name FROM eqtl_te_gene_tissue_summary s JOIN eqtl_tissues t ON t.version_id=s.version_id AND t.tissue_key=s.tissue_key JOIN eqtl_genes g ON g.version_id=s.version_id AND g.gene_id=s.gene_id WHERE s.version_id=? AND s.te_name=? AND g.gene_name=? ORDER BY t.display_name', [(int)$eqtl['id'],$te,$item['gene']], 'iss'); $item['details'][0]['supporting_tissues'] = array_map(static fn(array $r): array => ['key'=>(string)$r['tissue_key'],'label'=>(string)$r['display_name']], $trows); } unset($item);
    } else {
        $rows = tekg_te_gene_rows('SELECT s.*, g.gene_id_base, g.gene_name, t.display_name FROM eqtl_te_gene_tissue_summary s JOIN eqtl_genes g ON g.version_id=s.version_id AND g.gene_id=s.gene_id JOIN eqtl_tissues t ON t.version_id=s.version_id AND t.tissue_key=s.tissue_key WHERE s.version_id=? AND s.te_name=? AND s.tissue_key=? ORDER BY g.gene_name', [(int)$eqtl['id'],$te,$tissue], 'iss');
        foreach ($rows as $row) { $gene=trim((string)$row['gene_name']); if(!isset($mapping[strtolower($gene)])) continue; $key=strtolower($te)."\0".strtolower($gene); $eqtlByPair[$key]=['te'=>$te,'gene'=>$gene,'details'=>[['scope'=>'tissue','tissue'=>['key'=>$tissue,'label'=>$tissueMap[$tissue]],'supporting_tissues'=>[['key'=>$tissue,'label'=>$tissueMap[$tissue]]],'supporting_variant_count'=>(int)$row['supporting_variant_count'],'supporting_instance_count'=>(int)$row['supporting_instance_count'],'evidence_row_count'=>(int)$row['evidence_row_count'],'minimum_pval_nominal'=>$row['minimum_pval_nominal']===null?null:(float)$row['minimum_pval_nominal'],'maximum_abs_slope'=>$row['maximum_abs_slope']===null?null:(float)$row['maximum_abs_slope'],'direction_class'=>(string)$row['direction_class']]]]; }
    }
    $allKeys = array_values(array_unique(array_merge(array_keys($coexByPair), array_keys($eqtlByPair)))); $edges=[];
    foreach ($allKeys as $key) { $c=$coexByPair[$key]??null; $q=$eqtlByPair[$key]??null; $gene=$c['gene']??$q['gene']; $label=$c&&$q?'Both':($c?'Co-expression':'eQTL'); $primary=$c['details'][0]??[]; $edges[]=['id'=>'edge:'.sha1($key.'|'.$scope.'|'.($tissue??'')),'source'=>'te:'.$te,'target'=>'gene:'.$gene,'edge_label'=>$label,'scope'=>$scope,'supporting_tissues'=>$q['details'][0]['supporting_tissues']??[],'coexpression_evidence'=>$c['details']??[],'eqtl_evidence'=>$q['details']??[],'correlation'=>$c?(float)$primary['correlation']:0.000001,'abs_correlation'=>$c?(float)$primary['abs_correlation']:0.000001,'fdr'=>$c?(float)$primary['fdr']:1.0,'pair_type'=>'TE_gene','role'=>$label]; if(!isset($nodes['gene:'.$gene])) $nodes['gene:'.$gene]=['id'=>'gene:'.$gene,'label'=>$gene,'feature_type'=>'gene','role'=>'eqtl_gene','is_center'=>false]; }
    usort($edges, static function (array $a, array $b): int { $rank = ['Both'=>0, 'Co-expression'=>1, 'eQTL'=>2]; return ($rank[$a['edge_label']] ?? 9) <=> ($rank[$b['edge_label']] ?? 9) ?: strnatcasecmp((string)$a['target'], (string)$b['target']); });
    $totalEdges = count($edges); $displayEdges = array_slice($edges, 0, 49); $displayNodeIds = ['te:' . $te => true]; foreach ($displayEdges as $edge) $displayNodeIds[$edge['target']] = true;
    $displayNodes = array_values(array_filter($nodes, static fn(array $node): bool => isset($displayNodeIds[$node['id']])));
    return ['version'=>'te_gene_graph_v1','selection'=>['feature'=>$te,'feature_type'=>'TE','te'=>$te,'context'=>'all_tissues','scope'=>$scope,'tissue'=>$scope==='tissue'?$tissue:null],'module'=>['id'=>'te_gene_graph','type'=>'aggregate','size'=>count($displayNodes),'te_count'=>1,'gene_count'=>max(0,count($displayNodes)-1)],'interpretation'=>['statement_en'=>'TE-Gene evidence combines unchanged co-expression with positional/statistical GTEx eQTL overlap evidence.','statement_zh'=>'TE-Gene证据由共表达和GTEx eQTL重叠证据组成。','limit'=>'Correlation and eQTL overlap do not prove causality.'],'metadata'=>tekg_te_gene_metadata($eqtl)+['display_edge_limit'=>49,'display_truncated'=>$totalEdges>49],'nodes'=>$displayNodes,'edges'=>$displayEdges,'counts'=>['coexpression_edges'=>count($coexByPair),'eqtl_edges'=>count($eqtlByPair),'aggregate_edges'=>$totalEdges,'display_edges'=>count($displayEdges)]];
}
