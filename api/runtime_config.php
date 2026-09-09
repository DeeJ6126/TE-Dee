<?php
declare(strict_types=1);

function tekg_runtime_config_path(): string
{
    return __DIR__ . '/config.local.php';
}

function tekg_runtime_using_local_config(): bool
{
    return is_file(tekg_runtime_config_path());
}

function tekg_runtime_load_local_config(): array
{
    static $local = null;
    if (is_array($local)) {
        return $local;
    }

    $path = tekg_runtime_config_path();
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $local = $loaded;
            return $local;
        }
    }

    $local = [];
    return $local;
}

function tekg_runtime_env_value(array $names, ?string $default = null): ?string
{
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }
    return $default;
}

function tekg_runtime_pick(array $local, string $localKey, array $envNames, ?string $default = null, bool $required = false): ?string
{
    if (isset($local[$localKey]) && trim((string)$local[$localKey]) !== '') {
        return trim((string)$local[$localKey]);
    }

    $value = tekg_runtime_env_value($envNames, $default);
    if ($required && ($value === null || trim($value) === '')) {
        $envList = implode(' or ', $envNames);
        throw new RuntimeException("Runtime config missing {$localKey}. Set api/config.local.php or {$envList}.");
    }

    return $value;
}

function tekg_runtime_neo4j_config(?array $local = null): array
{
    $local = $local ?? tekg_runtime_load_local_config();
    return [
        'neo4j_url' => tekg_runtime_pick($local, 'neo4j_url', ['NEO4J_HTTP_URL_BIOLOGY', 'NEO4J_HTTP_URL'], null, true),
        'neo4j_user' => tekg_runtime_pick($local, 'neo4j_user', ['NEO4J_USER_BIOLOGY', 'NEO4J_USER'], 'neo4j'),
        'neo4j_password' => tekg_runtime_pick($local, 'neo4j_password', ['NEO4J_PASSWORD_BIOLOGY', 'NEO4J_PASSWORD'], null, true),
    ];
}

function tekg_runtime_neo4j_database_name($configOrUrl): string
{
    $url = is_array($configOrUrl) ? (string)($configOrUrl['neo4j_url'] ?? '') : (string)$configOrUrl;
    if (preg_match('#/db/([^/]+)/tx/commit#', $url, $matches) === 1) {
        return (string)$matches[1];
    }
    return '';
}
