<?php
declare(strict_types=1);

require_once __DIR__ . '/expression_repository.php';

const TEKG_COEXPRESSION_VERSION = 'v1_abs0.4_fdr0.05_res1.8';
const TEKG_COEXPRESSION_CONTEXTS = [
    'cancer_cell_line' => 'Cancer cell line',
    'normal_cell_line' => 'Normal cell line',
    'normal_tissue' => 'Normal tissue',
];
const TEKG_COEXPRESSION_TIER_PRIORITY = ['core_case' => 4, 'high_confidence' => 3, 'searchable_all' => 2, 'not_recommended_default' => 1];

final class CoexpressionRepositoryException extends RuntimeException {
    private string $repositoryErrorCode; private array $repositoryDetails;
    public function __construct(string $errorCode, string $message, array $details = []) { parent::__construct($message); $this->repositoryErrorCode=$errorCode; $this->repositoryDetails=$details; }
    public function errorCode(): string { return $this->repositoryErrorCode; }
    public function details(): array { return $this->repositoryDetails; }
}
function tekg_coexpression_rows(string $sql, array $params=[], ?string $types=null): array {
    try { return tekg_expression_fetch_all($sql,$params,$types); }
    catch (Throwable) { throw new CoexpressionRepositoryException('data_contract_error','The approved co-expression data could not be served.'); }
}
function tekg_coexpression_active_version(): array {
    $rows=tekg_coexpression_rows('SELECT id,version_key,method,thresholds_json,default_te,default_context,interpretation_limit FROM coexpression_analysis_versions WHERE is_active=1 LIMIT 2');
    if (count($rows)!==1 || $rows[0]['version_key']!==TEKG_COEXPRESSION_VERSION) throw new CoexpressionRepositoryException('data_contract_error','The approved co-expression result set is unavailable.');
    return $rows[0];
}
function tekg_coexpression_catalog(): array {
    $v=tekg_coexpression_active_version();
    $rows=tekg_coexpression_rows('SELECT center_te, context_key, display_tier, recommended_default FROM coexpression_networks WHERE version_id=? ORDER BY center_te COLLATE utf8mb4_general_ci, context_key',[(int)$v['id']],'i');
    $items=[]; foreach($rows as $row){$te=$row['center_te']; if(!isset($items[$te]))$items[$te]=['te'=>$te,'available_contexts'=>[],'best_tier'=>null,'recommended_default'=>false,'_p'=>-1]; $items[$te]['available_contexts'][]=$row['context_key']; $p=TEKG_COEXPRESSION_TIER_PRIORITY[$row['display_tier']]??-1; if($p>$items[$te]['_p']){$items[$te]['_p']=$p;$items[$te]['best_tier']=$row['display_tier'];}$items[$te]['recommended_default']=$items[$te]['recommended_default']||((int)$row['recommended_default']===1);}
    foreach($items as &$item){sort($item['available_contexts']);unset($item['_p']);} unset($item);
    $items = array_values($items); usort($items, static fn(array $a, array $b): int => strnatcasecmp($a['te'], $b['te']));
    $geneRows=tekg_coexpression_rows(
        "SELECT DISTINCT n.label gene, w.context_key, w.display_tier, w.recommended_default
         FROM coexpression_networks w
         JOIN coexpression_network_nodes n ON n.network_id=w.id
         WHERE w.version_id=? AND n.feature_type='gene'
         ORDER BY n.label COLLATE utf8mb4_general_ci, w.context_key",
        [(int)$v['id']],
        'i'
    );
    $geneItems=[];
    foreach($geneRows as $row){
        $gene=trim((string)$row['gene']);
        if($gene==='')continue;
        if(!isset($geneItems[$gene]))$geneItems[$gene]=['gene'=>$gene,'available_contexts'=>[],'best_tier'=>null,'_p'=>-1];
        $geneItems[$gene]['available_contexts'][]=$row['context_key'];
        $p=TEKG_COEXPRESSION_TIER_PRIORITY[$row['display_tier']]??-1;
        if($p>$geneItems[$gene]['_p']){$geneItems[$gene]['_p']=$p;$geneItems[$gene]['best_tier']=$row['display_tier'];}
    }
    foreach($geneItems as &$item){$item['available_contexts']=array_values(array_unique($item['available_contexts']));sort($item['available_contexts']);unset($item['_p']);} unset($item);
    $geneItems=array_values($geneItems);usort($geneItems,static fn(array $a,array $b):int=>strnatcasecmp($a['gene'],$b['gene']));
    $thresholds=json_decode((string)$v['thresholds_json'],true); if(!is_array($thresholds))throw new CoexpressionRepositoryException('data_contract_error','The co-expression version metadata is invalid.');
    return ['version'=>$v['version_key'],'method'=>$v['method'],'thresholds'=>$thresholds,'default_selection'=>['feature'=>$v['default_te'],'feature_type'=>'TE','te'=>$v['default_te'],'context'=>$v['default_context']],'contexts'=>array_map(static fn($id,$label)=>['id'=>$id,'label'=>$label],array_keys(TEKG_COEXPRESSION_CONTEXTS),array_values(TEKG_COEXPRESSION_CONTEXTS)),'items'=>$items,'gene_items'=>$geneItems,'interpretation_limit'=>$v['interpretation_limit']];
}

function tekg_coexpression_te_availability(string $teName): ?array {
    $trimmed=trim($teName);
    if($trimmed==='' || !tekg_expression_is_public_te_name($trimmed))return null;
    $v=tekg_coexpression_active_version();
    $rows=tekg_coexpression_rows(
        'SELECT center_te, context_key FROM coexpression_networks WHERE version_id=? AND LOWER(center_te)=LOWER(?) ORDER BY context_key',
        [(int)$v['id'],$trimmed],
        'is'
    );
    if($rows===[])return null;
    $contexts=array_values(array_unique(array_filter(array_map(
        static fn(array $row):string=>trim((string)($row['context_key']??'')),
        $rows
    ),static fn(string $context):bool=>array_key_exists($context,TEKG_COEXPRESSION_CONTEXTS))));
    if($contexts===[])return null;
    $preferred=in_array('normal_tissue',$contexts,true)?'normal_tissue':$contexts[0];
    return [
        'te'=>(string)$rows[0]['center_te'],
        'contexts'=>$contexts,
        'preferred_context'=>$preferred,
        'labels'=>array_intersect_key(TEKG_COEXPRESSION_CONTEXTS,array_flip($contexts)),
    ];
}

function tekg_coexpression_network_payload(array $v,array $n,array $selection,?string $selectedNodeId=null): array {
    $nodes=tekg_coexpression_rows('SELECT node_id id,label,feature_type,role,module_id,is_center,is_module_hub,degree_hint FROM coexpression_network_nodes WHERE network_id=? ORDER BY node_order',[(int)$n['id']],'i'); foreach($nodes as &$node){$node['is_center']=(int)$node['is_center']===1;$node['is_module_hub']=(int)$node['is_module_hub']===1;if($node['degree_hint']!==null)$node['degree_hint']=(float)$node['degree_hint'];}unset($node);
    if($selectedNodeId!==null){
        $found=false;
        foreach($nodes as &$node){
            $node['is_center']=strcasecmp((string)$node['id'],$selectedNodeId)===0;
            if($node['is_center']){$node['role']='selected_gene';$found=true;}
        }
        unset($node);
        if(!$found)throw new CoexpressionRepositoryException('data_contract_error','The selected Gene is missing from its approved display network.');
    }
    $edges=tekg_coexpression_rows('SELECT source_id source,target_id target,correlation,abs_correlation,fdr,pair_type,role FROM coexpression_network_edges WHERE network_id=? ORDER BY edge_order',[(int)$n['id']],'i'); foreach($edges as &$edge){$edge['correlation']=(float)$edge['correlation'];$edge['abs_correlation']=(float)$edge['abs_correlation'];$edge['fdr']=(float)$edge['fdr'];}unset($edge);
    if(count($nodes)>100||count($edges)>150)throw new CoexpressionRepositoryException('data_contract_error','The co-expression network exceeds the approved display size.'); $terms=json_decode((string)$n['enrichment_terms_json'],true); if(!is_array($terms))throw new CoexpressionRepositoryException('data_contract_error','The co-expression enrichment metadata is invalid.');
    $selection['display_tier']=$n['display_tier'];$selection['quality_flag']=$n['quality_flag'];$selection['recommended_default']=(int)$n['recommended_default']===1;
    return ['version'=>$v['version_key'],'selection'=>$selection,'module'=>['id'=>$n['module_id'],'type'=>$n['module_type'],'size'=>(int)$n['module_size'],'te_count'=>(int)$n['te_count'],'gene_count'=>(int)$n['gene_count'],'confidence'=>$n['confidence'],'candidate_label'=>$n['candidate_label'],'top_enriched_terms'=>array_values($terms)],'interpretation'=>['statement_en'=>$n['statement_en'],'statement_zh'=>$n['statement_zh'],'limit'=>$v['interpretation_limit']],'nodes'=>$nodes,'edges'=>$edges];
}

function tekg_coexpression_load_network(string $teName,string $context,bool $includeEqtl=true): array {
    if(!array_key_exists($context,TEKG_COEXPRESSION_CONTEXTS))throw new CoexpressionRepositoryException('invalid_context','The requested co-expression context is invalid.');
    $v=tekg_coexpression_active_version(); $known=tekg_coexpression_rows('SELECT DISTINCT center_te FROM coexpression_networks WHERE version_id=? AND LOWER(center_te)=LOWER(?)',[(int)$v['id'],trim($teName)],'is');
    if(count($known)!==1)throw new CoexpressionRepositoryException('unknown_te','The requested TE is not present in the approved co-expression catalog.'); $te=$known[0]['center_te'];
    $available=tekg_coexpression_rows('SELECT context_key FROM coexpression_networks WHERE version_id=? AND center_te=? ORDER BY context_key',[(int)$v['id'],$te],'is'); $contexts=array_column($available,'context_key');
    $network=tekg_coexpression_rows('SELECT * FROM coexpression_networks WHERE version_id=? AND center_te=? AND context_key=? LIMIT 1',[(int)$v['id'],$te,$context],'iss');
    if($network===[])throw new CoexpressionRepositoryException('network_unavailable',"No display network is available for {$te} in {$context}.",['available_contexts'=>$contexts]);
    $payload = tekg_coexpression_network_payload($v,$network[0],['feature'=>$te,'feature_type'=>'TE','te'=>$te,'context'=>$context,'available_contexts'=>$contexts]);
    return $includeEqtl ? tekg_coexpression_append_eqtl_edges($payload,$te) : $payload;
}

/** Append eQTL evidence to the existing co-expression payload without replacing its graph. */
function tekg_coexpression_append_eqtl_edges(array $payload, string $te, ?string $geneFilter = null): array
{
    try {
        $versions = tekg_coexpression_rows('SELECT * FROM eqtl_analysis_versions WHERE is_active=1 LIMIT 2');
        if (count($versions) !== 1) return $payload;
        $version = $versions[0];
        $rows = tekg_coexpression_rows(
            'SELECT s.*, g.gene_name FROM eqtl_te_gene_cross_tissue_summary s
             JOIN eqtl_genes g ON g.version_id=s.version_id AND g.gene_id=s.gene_id
             WHERE s.version_id=? AND LOWER(s.te_name)=LOWER(?)
               AND (? IS NULL OR LOWER(g.gene_name)=LOWER(?)) ORDER BY g.gene_name',
            [(int)$version['id'], $te, $geneFilter, $geneFilter], 'isss'
        );
        if ($rows === []) return $payload;
        $nodeIds = [];
        foreach ($payload['nodes'] as $node) $nodeIds[strtolower((string)$node['label'])] = (string)$node['id'];
        $teId = $nodeIds[strtolower($te)] ?? $te;
        if (!isset($nodeIds[strtolower($te)])) {
            $payload['nodes'][] = ['id'=>$teId,'label'=>$te,'feature_type'=>'TE','role'=>'eqtl_te','is_center'=>true,'is_module_hub'=>false];
            $nodeIds[strtolower($te)] = $teId;
        }
        $edgePairs = [];
        foreach ($payload['edges'] as $edge) $edgePairs[(string)$edge['source'] . "\0" . (string)$edge['target']] = true;
        // Preserve the most informative overlap first: merge eQTL with existing
        // co-expression Gene nodes before spending the display node budget on
        // eQTL-only Gene nodes.
        usort($rows, static function (array $left, array $right) use ($nodeIds): int {
            $leftExisting = isset($nodeIds[strtolower(trim((string)($left['gene_name'] ?? '')))]);
            $rightExisting = isset($nodeIds[strtolower(trim((string)($right['gene_name'] ?? '')))]);
            return (int)$rightExisting <=> (int)$leftExisting
                ?: strnatcasecmp((string)($left['gene_name'] ?? ''), (string)($right['gene_name'] ?? ''));
        });
        $added = 0;
        $maxNodes = 100;
        $maxEdges = 150;
        foreach ($rows as $row) {
            $gene = trim((string)($row['gene_name'] ?? ''));
            if ($gene === '') continue;
            if (!tekg_coexpression_eqtl_gene_is_high_confidence($gene)) continue;
            $geneId = 'gene:' . $gene;
            if (!isset($nodeIds[strtolower($gene)]) && count($payload['nodes']) >= $maxNodes) break;
            if (!isset($nodeIds[strtolower($gene)])) {
                $payload['nodes'][] = ['id'=>$geneId,'label'=>$gene,'feature_type'=>'gene','role'=>'eqtl_gene','is_center'=>false,'is_module_hub'=>false,'expression_disabled'=>true];
                $nodeIds[strtolower($gene)] = $geneId;
            } else {
                $geneId = $nodeIds[strtolower($gene)];
                foreach ($payload['nodes'] as &$candidateNode) {
                    if ((string)$candidateNode['id'] === $geneId && strtolower((string)($candidateNode['feature_type'] ?? '')) === 'gene') {
                        $candidateNode['expression_disabled'] = true;
                        break;
                    }
                }
                unset($candidateNode);
            }
            $edgeKey = $teId . "\0" . $geneId;
            if (isset($edgePairs[$edgeKey])) {
                foreach ($payload['edges'] as &$existing) {
                    if ((string)$existing['source'] === $teId && (string)$existing['target'] === $geneId) {
                        $existing['role'] = 'Both';
                        $existing['edge_label'] = 'Both';
                        $existing['eqtl_evidence'] = ['scope'=>'all','tissue_count'=>(int)($row['tissue_count'] ?? 0),'supporting_variant_count'=>(int)$row['supporting_variant_count'],'minimum_pval_nominal'=>$row['minimum_pval_nominal'] === null ? null : (float)$row['minimum_pval_nominal']];
                        break;
                    }
                }
                unset($existing);
                continue;
            }
            if (count($payload['edges']) >= $maxEdges) break;
            $payload['edges'][] = ['id'=>'eqtl:' . sha1($te . "\0" . $gene),'source'=>$teId,'target'=>$geneId,'correlation'=>0.0,'abs_correlation'=>0.0,'fdr'=>1.0,'pair_type'=>'TE_gene_eqtl','role'=>'eQTL','edge_label'=>'eQTL','eqtl_evidence'=>['scope'=>'all','tissue_count'=>(int)($row['tissue_count'] ?? 0),'supporting_variant_count'=>(int)$row['supporting_variant_count'],'minimum_pval_nominal'=>$row['minimum_pval_nominal'] === null ? null : (float)$row['minimum_pval_nominal']]];
            $added++;
            if ($added >= 100) break;
        }
        $payload['metadata']['te_gene_mode'] = 'appended_to_coexpression';
        $payload['metadata']['eqtl_version'] = (string)($version['version_key'] ?? '');
        $payload['counts'] = ['coexpression_edges'=>count($payload['edges'])-$added,'eqtl_edges'=>$added,'aggregate_edges'=>count($payload['edges'])];
    } catch (Throwable) {
        // eQTL is an additive layer; an unavailable eQTL table must not break co-expression.
    }
    return $payload;
}

function tekg_coexpression_eqtl_gene_is_high_confidence(string $gene): bool
{
    static $allowed = null;
    if ($allowed === null) {
        $allowed = [];
        $path = dirname(__DIR__) . '/data/coexpression/feature_annotation/feature_annotation.tsv';
        $handle = @fopen($path, 'rb');
        if ($handle !== false) {
            $header = fgetcsv($handle, 0, "\t", '"', '');
            while (($row = fgetcsv($handle, 0, "\t", '"', '')) !== false) {
                if (!is_array($header) || count($row) < count($header)) continue;
                $item = array_combine($header, $row);
                if (strtolower(trim((string)($item['feature_type'] ?? ''))) !== 'gene') continue;
                if (strtolower(trim((string)($item['confidence'] ?? ''))) !== 'high') continue;
                $symbol = strtolower(trim((string)($item['feature'] ?? '')));
                if ($symbol !== '') $allowed[$symbol] = true;
            }
            fclose($handle);
        }
    }
    return isset($allowed[strtolower(trim($gene))]);
}

function tekg_coexpression_load_gene_network(string $geneName,string $context,bool $includeEqtl=true): array {
    if(!array_key_exists($context,TEKG_COEXPRESSION_CONTEXTS))throw new CoexpressionRepositoryException('invalid_context','The requested co-expression context is invalid.');
    $v=tekg_coexpression_active_version();
    $known=tekg_coexpression_rows(
        "SELECT DISTINCT n.label gene
         FROM coexpression_networks w JOIN coexpression_network_nodes n ON n.network_id=w.id
         WHERE w.version_id=? AND n.feature_type='gene' AND LOWER(n.label)=LOWER(?)",
        [(int)$v['id'],trim($geneName)],
        'is'
    );
    if(count($known)!==1)throw new CoexpressionRepositoryException('unknown_gene','The requested Gene is not present in the approved co-expression catalog.');
    $gene=(string)$known[0]['gene'];
    $available=tekg_coexpression_rows(
        "SELECT DISTINCT w.context_key
         FROM coexpression_networks w JOIN coexpression_network_nodes n ON n.network_id=w.id
         WHERE w.version_id=? AND n.feature_type='gene' AND LOWER(n.label)=LOWER(?)
         ORDER BY w.context_key",
        [(int)$v['id'],$gene],
        'is'
    );
    $contexts=array_column($available,'context_key');
    $network=tekg_coexpression_rows(
        "SELECT w.*, n.node_id selected_node_id,
                (SELECT COUNT(*) FROM coexpression_network_edges e
                 WHERE e.network_id=w.id AND (e.source_id=n.node_id OR e.target_id=n.node_id)) selected_degree
         FROM coexpression_networks w JOIN coexpression_network_nodes n ON n.network_id=w.id
         WHERE w.version_id=? AND w.context_key=? AND n.feature_type='gene' AND LOWER(n.label)=LOWER(?)
         ORDER BY selected_degree DESC, w.recommended_default DESC,
                  CASE w.display_tier WHEN 'core_case' THEN 4 WHEN 'high_confidence' THEN 3 WHEN 'searchable_all' THEN 2 ELSE 1 END DESC,
                  w.center_te COLLATE utf8mb4_general_ci
         LIMIT 1",
        [(int)$v['id'],$context,$gene],
        'iss'
    );
    if($network===[])throw new CoexpressionRepositoryException('network_unavailable',"No display network is available for {$gene} in {$context}.",['available_contexts'=>$contexts]);
    $n=$network[0];
    $selection=['feature'=>$gene,'feature_type'=>'Gene','gene'=>$gene,'context'=>$context,'available_contexts'=>$contexts,'source_center_te'=>$n['center_te']];
    $payload = tekg_coexpression_network_payload($v,$n,$selection,(string)$n['selected_node_id']);
    return $includeEqtl ? tekg_coexpression_append_eqtl_edges($payload,(string)$n['center_te'],$gene) : $payload;
}

function tekg_coexpression_load_feature_network(string $featureName,string $featureType,string $context,bool $includeEqtl=true): array {
    return strcasecmp(trim($featureType),'Gene')===0
        ? tekg_coexpression_load_gene_network($featureName,$context,$includeEqtl)
        : tekg_coexpression_load_network($featureName,$context,$includeEqtl);
}
