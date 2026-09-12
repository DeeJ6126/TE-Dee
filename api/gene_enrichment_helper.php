<?php
declare(strict_types=1);

const TEKG_ENRICHMENT_STRING_URL = 'https://version-12-0.string-db.org/api/json';

function tekg_enrichment_genes($value): array {
    $items = is_array($value) ? $value : preg_split('/[\s,;]+/', (string)$value);
    $seen = []; $out = [];
    foreach ($items ?: [] as $item) {
        $gene = trim((string)$item); $key = strtolower($gene);
        if ($gene !== '' && !isset($seen[$key])) { $seen[$key] = true; $out[] = $gene; }
    }
    return $out;
}

function tekg_enrichment_http(string $url, string $method = 'GET', array $fields = [], bool $form = false): array {
    if (!function_exists('curl_init')) return [false, 'cURL is unavailable'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $method]);
    if ($fields) {
        if ($form) curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        else { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']); }
    }
    $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($body !== false && $status >= 200 && $status < 300) ? [true, (string)$body] : [false, 'Enrichment provider request failed'];
}

function tekg_enrichment_enrichr_config(string $species): ?array {
    $key = strtolower(trim($species));
    $configs = [
        'human' => ['id'=>'human','label'=>'Homo sapiens','base'=>'https://maayanlab.cloud/Enrichr','go'=>'GO_Biological_Process_2021','kegg'=>'KEGG_2021_Human'],
        'mouse' => ['id'=>'mouse','label'=>'Mus musculus','base'=>'https://maayanlab.cloud/Enrichr','go'=>'GO_Biological_Process_2021','kegg'=>'KEGG_2019_Mouse'],
        'fish' => ['id'=>'fish','label'=>'Danio rerio','base'=>'https://maayanlab.cloud/FishEnrichr','go'=>'','kegg'=>''],
        'fly' => ['id'=>'fly','label'=>'Drosophila melanogaster','base'=>'https://maayanlab.cloud/FlyEnrichr','go'=>'','kegg'=>''],
        'worm' => ['id'=>'worm','label'=>'Caenorhabditis elegans','base'=>'https://maayanlab.cloud/WormEnrichr','go'=>'','kegg'=>''],
        'yeast' => ['id'=>'yeast','label'=>'Saccharomyces cerevisiae','base'=>'https://maayanlab.cloud/YeastEnrichr','go'=>'','kegg'=>''],
    ];
    foreach ($configs as $config) if ($key === $config['id'] || $key === strtolower($config['label'])) return $config;
    return null;
}

function tekg_enrichment_term_id(string $term): string {
    if (preg_match('/(GO:\d+|KEGG:\d+|hsa\d+|mmu\d+)/i', $term, $m)) return $m[1];
    return '';
}

function tekg_enrichment_enrichr(string $species, array $genes): array {
    $config = tekg_enrichment_enrichr_config($species);
    if (!$config) return [false, 'Unsupported Enrichr species'];
    [$ok, $raw] = tekg_enrichment_http($config['base'].'/addList', 'POST', ['list'=>implode("\n", $genes), 'description'=>'TE-Dee TE-Gene selected genes'], true);
    $decoded = $ok ? json_decode($raw, true) : null; $listId = (int)($decoded['userListId'] ?? 0);
    if (!$listId) return [false, $ok ? 'Invalid Enrichr response' : $raw];
    if ($config['go'] === '' || $config['kegg'] === '') {
        [$statsOk, $statsRaw] = tekg_enrichment_http($config['base'].'/datasetStatistics');
        $stats = $statsOk ? (json_decode($statsRaw, true)['statistics'] ?? []) : [];
        foreach ($stats as $entry) {
            $libraryName = (string)($entry['libraryName'] ?? '');
            $libraryLower = strtolower($libraryName);
            if ($config['go'] === '' && str_contains($libraryLower, 'go_biological_process')) $config['go'] = $libraryName;
            if ($config['kegg'] === '' && str_contains($libraryLower, 'kegg')) $config['kegg'] = $libraryName;
        }
    }
    if ($config['go'] === '' && $config['kegg'] === '') return [false, 'Enrichr libraries unavailable for this species'];
    $result = ['go'=>[], 'kegg'=>[]];
    foreach (['go'=>'go','kegg'=>'kegg'] as $target=>$key) {
        $library = $config[$key];
        if ($library === '') continue;
        [$ok, $raw] = tekg_enrichment_http($config['base'].'/enrich?userListId='.$listId.'&backgroundType='.rawurlencode($library));
        $rows = $ok ? (json_decode($raw, true)[$library] ?? []) : [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) < 7 || !is_numeric($row[6] ?? null)) continue;
            $term = (string)($row[1] ?? ''); $genesFound = tekg_enrichment_genes($row[5] ?? []);
            if ($term === '' || !$genesFound) continue;
            $result[$target][] = ['ID'=>tekg_enrichment_term_id($term) ?: $library.':'.(int)($row[0] ?? 0), 'Description'=>preg_replace('/\s*\((GO:\d+|KEGG:\d+)\)\s*$/i','',$term), 'p.adjust'=>(float)$row[6], 'Count'=>count($genesFound), 'Gene'=>implode('/', $genesFound)];
        }
    }
    return [true, ['species'=>['id'=>$config['id'],'label'=>$config['label']], ...$result]];
}

function tekg_enrichment_string(string $species, array $genes): array {
    $speciesId = ctype_digit($species) ? $species : '9606';
    [$ok, $raw] = tekg_enrichment_http(TEKG_ENRICHMENT_STRING_URL.'/enrichment', 'POST', ['identifiers'=>implode("\r", $genes), 'species'=>$speciesId, 'caller_identity'=>'te-dee'], false);
    if (!$ok) return [false, $raw];
    $rows = json_decode($raw, true); if (!is_array($rows)) return [false, 'Invalid STRING response'];
    $result = ['go'=>[], 'kegg'=>[]];
    foreach ($rows as $row) {
        if (!is_array($row)) continue; $category = strtolower((string)($row['category'] ?? ''));
        $target = in_array($category, ['process','component','function'], true) ? 'go' : (str_contains($category, 'kegg') ? 'kegg' : '');
        if ($target === '' || !is_numeric($row['fdr'] ?? null)) continue;
        $found = tekg_enrichment_genes($row['preferredNames'] ?? ($row['inputGenes'] ?? []));
        $result[$target][] = ['ID'=>(string)($row['term'] ?? ''), 'Description'=>(string)($row['description'] ?? ''), 'p.adjust'=>(float)$row['fdr'], 'Count'=>(int)($row['number_of_genes'] ?? count($found)), 'Gene'=>implode('/', $found)];
    }
    return [true, ['species'=>['id'=>$speciesId,'label'=>$speciesId], ...$result]];
}
