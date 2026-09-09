<?php
declare(strict_types=1);
require_once __DIR__ . '/te_gene_repository.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
function tekg_te_gene_response(int $status, array $payload, bool $cacheable=false): never { http_response_code($status); header($cacheable?'Cache-Control: public, max-age=300':'Cache-Control: no-store, max-age=0'); echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function tekg_te_gene_error(int $status,string $code,string $message,array $details=[]): never { $error=['code'=>$code,'message'=>$message]; foreach(['tissues'] as $key) if(isset($details[$key])) $error[$key]=array_values($details[$key]); tekg_te_gene_response($status,['ok'=>false,'error'=>$error]); }
function tekg_te_gene_query(string $key): string { $v=$_GET[$key]??''; return is_string($v)?trim($v):''; }
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET')); if($method==='OPTIONS'){http_response_code(204);exit;} if($method!=='GET') tekg_te_gene_error(405,'method_not_allowed','Only GET and OPTIONS requests are supported.');
$action=tekg_te_gene_query('action'); if(!in_array($action,['catalog','network'],true)) tekg_te_gene_error(400,'invalid_action','The requested TE-Gene action is invalid.');
try { if($action==='catalog') tekg_te_gene_response(200,['ok'=>true]+tekg_te_gene_catalog(),true); $te=tekg_te_gene_query('te'); $scope=tekg_te_gene_query('scope')?:'all'; $tissue=tekg_te_gene_query('tissue'); if($te==='') tekg_te_gene_error(404,'unknown_te','The requested TE is not present in the TE-Gene catalog.'); tekg_te_gene_response(200,['ok'=>true]+tekg_te_gene_load_network($te,$scope,$tissue!==''?$tissue:null),true); }
catch(TeGeneRepositoryException $e){$status=match($e->errorCode()){ 'invalid_scope','invalid_tissue'=>400, 'unknown_te'=>404, default=>500}; tekg_te_gene_error($status,$e->errorCode(),$e->getMessage(),$e->details());}
catch(Throwable){tekg_te_gene_error(500,'data_contract_error','The approved TE-Gene data could not be served.');}
