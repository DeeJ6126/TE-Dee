<?php
declare(strict_types=1);

// Imports the approved, versioned display artifacts. Runtime code never reads these files.
require_once dirname(__DIR__, 2) . '/api/expression_repository.php';

const COEX_VERSION = 'v1_abs0.4_fdr0.05_res1.8';
const COEX_LIMIT = 'Co-expression is correlation, not causation or direct regulatory evidence.';
$root = dirname(__DIR__, 2) . '/data/coexpression/display_subgraphs/' . COEX_VERSION;
function fail(string $message): never { throw new RuntimeException($message); }
function json_file(string $path): array { $raw = file_get_contents($path); if ($raw === false) fail("Missing source: $path"); $v = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); if (!is_array($v)) fail("Invalid JSON: $path"); return $v; }
function bool_value(mixed $v): int { return in_array(strtolower(trim((string)$v)), ['true','1'], true) ? 1 : 0; }
function stmt(mysqli $db, string $sql, array $params = [], string $types = ''): mysqli_stmt { $s=$db->prepare($sql); if (!$s) fail($db->error); if ($params !== []) { $bind=[$types]; foreach($params as $i=>$v) $bind[]=&$params[$i]; if (!call_user_func_array([$s,'bind_param'],$bind)) fail($s->error); } if (!$s->execute()) fail($s->error); return $s; }
try {
  $db=tekg_expression_db(); $schema=file_get_contents(dirname(__DIR__,2).'/imports/coexpression_mysql_schema.sql'); if ($schema===false) fail('Missing schema');
  foreach (array_filter(array_map('trim', explode(';',$schema))) as $sql) { if (!$db->query($sql)) fail($db->error); }
  $manifest=json_file($root.'/all_te/manifest.json'); $tiers=[]; $h=fopen($root.'/display_tier_recommendations.tsv','rb'); $header=fgetcsv($h,0,"\t",'"','\\'); while(($row=fgetcsv($h,0,"\t",'"','\\'))!==false){$r=array_combine($header,$row);$tiers[$r['te_name']][$r['context']]=$r;} fclose($h);
  $db->begin_transaction();
  stmt($db,'UPDATE coexpression_analysis_versions SET is_active=0');
  stmt($db,'INSERT INTO coexpression_analysis_versions (version_key,method,thresholds_json,default_te,default_context,interpretation_limit,is_active) VALUES (?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE method=VALUES(method),thresholds_json=VALUES(thresholds_json),default_te=VALUES(default_te),default_context=VALUES(default_context),interpretation_limit=VALUES(interpretation_limit),is_active=1',[COEX_VERSION,'spearman',json_encode(['min_abs_correlation'=>0.4,'max_fdr'=>0.05,'module_resolution'=>1.8,'positive_display_edges_only'=>true]),'L1HS','cancer_cell_line',COEX_LIMIT],'ssssss');
  $v=stmt($db,'SELECT id FROM coexpression_analysis_versions WHERE version_key=?',[COEX_VERSION],'s')->get_result()->fetch_assoc(); $versionId=(int)$v['id'];
  stmt($db,'DELETE FROM coexpression_networks WHERE version_id=?',[$versionId],'i');
  $networkSql='INSERT INTO coexpression_networks (version_id,center_te,context_key,display_tier,quality_flag,recommended_default,module_id,module_type,module_size,te_count,gene_count,confidence,candidate_label,enrichment_terms_json,statement_en,statement_zh) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
  foreach ($manifest['files'] as $relative) { $network=json_file($root.'/'.$relative); $te=$network['center']; $context=$network['context_type']; $tier=$tiers[$te][$context]??null; if (!$tier) fail("No tier for $te/$context");
    $terms = array_values(array_filter(array_map('trim', explode(';', (string)$network['top_enriched_terms']))));
    stmt($db,$networkSql,[$versionId,$te,$context,$tier['display_tier'],$tier['quality_flag'],bool_value($tier['recommended_default']),$network['module_id'],$network['module_type'],(int)$network['module_size'],(int)$network['TE_count'],(int)$network['gene_count'],$network['functional_context_confidence'],$network['candidate_label'],json_encode($terms),$network['interpretation_statement_en'],$network['interpretation_statement_zh']],'issssissiiisssss');
    $nid=(int)$db->insert_id;
    foreach ($network['nodes'] as $i=>$n) stmt($db,'INSERT INTO coexpression_network_nodes (network_id,node_order,node_id,label,feature_type,role,module_id,is_center,is_module_hub,degree_hint) VALUES (?,?,?,?,?,?,?,?,?,?)',[$nid,$i,$n['id'],$n['label'],$n['feature_type'],$n['role'],$n['module_id'],!empty($n['is_center'])?1:0,!empty($n['is_module_hub'])?1:0,isset($n['degree_hint'])?(float)$n['degree_hint']:null],'iisssssiid');
    foreach ($network['edges'] as $i=>$e) stmt($db,'INSERT INTO coexpression_network_edges (network_id,edge_order,source_id,target_id,correlation,abs_correlation,fdr,pair_type,role) VALUES (?,?,?,?,?,?,?,?,?)',[$nid,$i,$e['source'],$e['target'],(float)$e['correlation'],(float)$e['abs_correlation'],(float)$e['fdr'],$e['pair_type'],$e['role']],'iissdddss');
  }
  $db->commit(); $counts=$db->query('SELECT (SELECT COUNT(*) FROM coexpression_analysis_versions WHERE is_active=1) versions,(SELECT COUNT(*) FROM coexpression_networks WHERE version_id='.$versionId.') networks,(SELECT COUNT(*) FROM coexpression_network_nodes n JOIN coexpression_networks w ON w.id=n.network_id WHERE w.version_id='.$versionId.') nodes,(SELECT COUNT(*) FROM coexpression_network_edges e JOIN coexpression_networks w ON w.id=e.network_id WHERE w.version_id='.$versionId.') edges')->fetch_assoc(); echo 'Imported '.json_encode($counts).PHP_EOL;
} catch(Throwable $e) { if (isset($db) && $db->errno===0) { try{$db->rollback();}catch(Throwable){} } fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); exit(1); }
