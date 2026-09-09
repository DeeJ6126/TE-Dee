<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_config.php';

header('Content-Type: application/json; charset=utf-8');

$local = tekg_runtime_load_local_config();

$config = [
    'deepseek_key' => tekg_runtime_pick($local, 'deepseek_key', ['DEEPSEEK_API_KEY']),
    'deepseek_model' => tekg_runtime_pick($local, 'deepseek_model', ['DEEPSEEK_MODEL'], 'deepseek-chat'),
];

$neo4jConfig = null;
$neo4jConfigError = '';
try {
    $neo4jConfig = tekg_runtime_neo4j_config($local);
} catch (Throwable $e) {
    $neo4jConfigError = $e->getMessage();
}

$neo4jReachable = false;
$neo4jMessage = $neo4jConfigError !== '' ? $neo4jConfigError : 'not tested';
if (function_exists('curl_init') && is_array($neo4jConfig) && $neo4jConfig['neo4j_password']) {
    $ch = curl_init($neo4jConfig['neo4j_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $neo4jConfig['neo4j_user'] . ':' . $neo4jConfig['neo4j_password'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => '{"statements":[{"statement":"RETURN 1 AS ok"}]}',
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw !== false && $status < 400) {
        $neo4jReachable = true;
        $neo4jMessage = 'ok';
    } else {
        $neo4jMessage = $error !== '' ? $error : ('HTTP ' . $status);
    }
}

echo json_encode([
    'ok' => true,
    'php_version' => PHP_VERSION,
    'curl_loaded' => function_exists('curl_init'),
    'deepseek_key_present' => $config['deepseek_key'] !== null && $config['deepseek_key'] !== '',
    'deepseek_model' => $config['deepseek_model'],
    'neo4j_url' => is_array($neo4jConfig) ? $neo4jConfig['neo4j_url'] : null,
    'neo4j_database' => is_array($neo4jConfig) ? tekg_runtime_neo4j_database_name($neo4jConfig) : null,
    'neo4j_user' => is_array($neo4jConfig) ? $neo4jConfig['neo4j_user'] : null,
    'neo4j_password_present' => is_array($neo4jConfig) && $neo4jConfig['neo4j_password'] !== null && $neo4jConfig['neo4j_password'] !== '',
    'neo4j_config_error' => $neo4jConfigError,
    'neo4j_reachable' => $neo4jReachable,
    'neo4j_message' => $neo4jMessage,
    'using_local_config' => tekg_runtime_using_local_config(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
