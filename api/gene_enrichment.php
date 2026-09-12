<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/gene_enrichment_helper.php';

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$provider = strtolower(trim((string) ($input['provider'] ?? 'string')));
$species = $provider === 'string' ? '9606' : 'human';
$genes = tekg_enrichment_genes($input['genes'] ?? []);
if (!in_array($provider, ['string', 'enrichr'], true)) { echo json_encode(['success' => false, 'error' => 'Unsupported provider']); exit; }
if (!$genes) { echo json_encode(['success' => false, 'error' => 'No genes selected']); exit; }
[$ok, $result] = $provider === 'enrichr' ? tekg_enrichment_enrichr($species, $genes) : tekg_enrichment_string($species, $genes);
if (!$ok) { echo json_encode(['success' => false, 'error' => $result]); exit; }
echo json_encode([
    'success' => true,
    'provider' => $provider,
    'species' => $result['species'] ?? ['id' => $species, 'label' => $species],
    'input' => ['genes' => $genes, 'count' => count($genes)],
    'go' => $result['go'] ?? [],
    'kegg' => $result['kegg'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
