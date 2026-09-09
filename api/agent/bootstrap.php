<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/expression_data.php';
require_once dirname(__DIR__) . '/runtime_config.php';
require_once dirname(__DIR__, 2) . '/path_config.php';
require_once dirname(__DIR__, 2) . '/site_i18n.php';

function tekg_agent_env_value(array $names, ?string $default = null): ?string
{
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }
    return $default;
}

function tekg_agent_bool_value(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null) {
        return $default;
    }
    $normalized = strtolower(trim((string)$value));
    if ($normalized === '') {
        return $default;
    }
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return $default;
}

function tekg_agent_local_config(): array
{
    return tekg_runtime_load_local_config();
}

function tekg_agent_ensure_dir(string $path): string
{
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    return $path;
}

require_once __DIR__ . '/bootstrap/run_store.php';
require_once __DIR__ . '/bootstrap/evidence_support.php';

function tekg_agent_entity_alias_map(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $path = __DIR__ . '/config/entity_alias_map.php';
    $loaded = is_file($path) ? require $path : [];
    $map = is_array($loaded) ? $loaded : [];
    return $map;
}

function tekg_agent_routing_policy(): array
{
    static $policy = null;
    if (is_array($policy)) {
        return $policy;
    }
    $path = __DIR__ . '/config/agent_routing_policy.json';
    if (!is_file($path)) {
        $policy = [];
        return $policy;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    $policy = is_array($decoded) ? $decoded : [];
    return $policy;
}

function tekg_agent_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }
    $local = tekg_agent_local_config();
    $neo4jConfig = tekg_runtime_neo4j_config($local);
    $controlModel = trim((string)($local['agent_control_model'] ?? tekg_agent_env_value(['TEKG_AGENT_CONTROL_MODEL'], 'deepseek-v4-flash')));
    $config = [
        'dashscope_url' => trim((string)($local['dashscope_url'] ?? tekg_agent_env_value(['DASHSCOPE_API_URL_BIOLOGY', 'DASHSCOPE_API_URL'], 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions'))),
        'dashscope_key' => trim((string)($local['dashscope_key'] ?? tekg_agent_env_value(['DASHSCOPE_API_KEY_BIOLOGY', 'DASHSCOPE_API_KEY'], ''))),
        'dashscope_model' => trim((string)($local['dashscope_model'] ?? tekg_agent_env_value(['DASHSCOPE_MODEL_BIOLOGY', 'DASHSCOPE_MODEL'], 'qwen3.5-35b-a3b'))),
        'agent_control_model' => $controlModel,
        'agent_core_model' => trim((string)($local['agent_core_model'] ?? tekg_agent_env_value(['TEKG_AGENT_CORE_MODEL'], 'deepseek-v4-pro'))),
        'agent_sufficiency_model' => trim((string)($local['agent_sufficiency_model'] ?? tekg_agent_env_value(['TEKG_AGENT_SUFFICIENCY_MODEL'], $controlModel))),
        'agent_expert_model' => trim((string)($local['agent_expert_model'] ?? tekg_agent_env_value(['TEKG_AGENT_EXPERT_MODEL'], 'deepseek-v4-pro'))),
        'agent_narrator_model' => trim((string)($local['agent_narrator_model'] ?? tekg_agent_env_value(['TEKG_AGENT_NARRATOR_MODEL'], $controlModel))),
        'agent_answer_structure_model' => trim((string)($local['agent_answer_structure_model'] ?? tekg_agent_env_value(['TEKG_AGENT_ANSWER_STRUCTURE_MODEL'], $controlModel))),
        'agent_writing_model' => trim((string)($local['agent_writing_model'] ?? tekg_agent_env_value(['TEKG_AGENT_WRITING_MODEL'], 'deepseek-v4-pro'))),
        'agent_polisher_model' => trim((string)($local['agent_polisher_model'] ?? tekg_agent_env_value(['TEKG_AGENT_POLISHER_MODEL'], $controlModel))),
        'agent_polisher_enabled' => tekg_agent_bool_value($local['agent_polisher_enabled'] ?? tekg_agent_env_value(['TEKG_AGENT_POLISHER_ENABLED'], 'false'), false),
        'deepseek_url' => trim((string)($local['deepseek_url'] ?? tekg_agent_env_value(['DEEPSEEK_API_URL'], 'https://api.deepseek.com/v1/chat/completions'))),
        'deepseek_key' => trim((string)($local['deepseek_key'] ?? tekg_agent_env_value(['DEEPSEEK_API_KEY'], ''))),
        'deepseek_model' => trim((string)($local['deepseek_model'] ?? tekg_agent_env_value(['DEEPSEEK_MODEL'], 'deepseek-chat'))),
        'deepseek_reasoner_model' => trim((string)($local['deepseek_reasoner_model'] ?? tekg_agent_env_value(['DEEPSEEK_REASONER_MODEL'], 'deepseek-reasoner'))),
        'llm_relay_url' => trim((string)($local['llm_relay_url'] ?? tekg_agent_env_value(['BIOLOGY_LLM_RELAY_URL', 'LLM_RELAY_URL'], ''))),
        'ssl_verify' => (bool)($local['ssl_verify'] ?? false),
        'agent_execution_timeout' => (int)($local['agent_execution_timeout'] ?? tekg_agent_env_value(['TEKG_AGENT_EXECUTION_TIMEOUT'], '7200')),
        'llm_narrator_timeout' => (int)($local['llm_narrator_timeout'] ?? tekg_agent_env_value(['TEKG_AGENT_LLM_NARRATOR_TIMEOUT'], '60')),
        'llm_json_timeout' => (int)($local['llm_json_timeout'] ?? tekg_agent_env_value(['TEKG_AGENT_LLM_JSON_TIMEOUT'], '120')),
        'llm_six_stage_node_timeout' => (int)($local['llm_six_stage_node_timeout'] ?? tekg_agent_env_value(['TEKG_AGENT_LLM_SIX_STAGE_NODE_TIMEOUT'], '1200')),
        'llm_answer_timeout' => (int)($local['llm_answer_timeout'] ?? tekg_agent_env_value(['TEKG_AGENT_LLM_ANSWER_TIMEOUT'], '20')),
        'llm_answer_chat_timeout' => (int)($local['llm_answer_chat_timeout'] ?? tekg_agent_env_value(['TEKG_AGENT_LLM_ANSWER_CHAT_TIMEOUT'], '1200')),
        'llm_answer_reasoner_timeout' => (int)($local['llm_answer_reasoner_timeout'] ?? tekg_agent_env_value(['TEKG_AGENT_LLM_ANSWER_REASONER_TIMEOUT'], '1200')),
        'neo4j_url' => $neo4jConfig['neo4j_url'],
        'neo4j_user' => $neo4jConfig['neo4j_user'],
        'neo4j_password' => $neo4jConfig['neo4j_password'],
        'pubmed_tool' => trim((string)tekg_agent_env_value(['PUBMED_TOOL'], 'TEKGAcademicAgent')),
        'pubmed_email' => trim((string)tekg_agent_env_value(['PUBMED_EMAIL'], '')),
        'pubmed_cache_dir' => tekg_agent_pubmed_cache_dir(),
    ];
    return $config;
}

function tekg_agent_normalize_lookup_token(string $value): string
{
    $value = trim(tekg_agent_lower($value));
    $value = preg_replace('/[\s\-_]+/u', '', $value) ?? $value;
    return trim($value);
}

function tekg_agent_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function tekg_agent_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function tekg_agent_substr(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($value, $start, null, 'UTF-8') : mb_substr($value, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

function tekg_agent_normalize_language_code(string $language): string
{
    $value = tekg_agent_lower(trim(str_replace('_', '-', $language)));
    if (in_array($value, ['chinese', 'zh', 'zh-cn', 'zh-hans', 'zh-hant', 'cn', '中文', '汉语', '漢語'], true)) {
        return 'chinese';
    }
    if (in_array($value, ['english', 'en', 'en-us', 'en-gb'], true)) {
        return 'english';
    }
    return '';
}

function tekg_agent_detect_language(array|string $questionOrPayload, string $fallbackOrQuestion = 'english'): string
{
    $question = is_array($questionOrPayload) ? $fallbackOrQuestion : $questionOrPayload;
    $fallback = is_array($questionOrPayload) ? '' : $fallbackOrQuestion;
    if (is_array($questionOrPayload)) {
        $hasExplicitLanguage = false;
        foreach (['language', 'answer_language', 'process_language'] as $key) {
            if (isset($questionOrPayload[$key]) && is_string($questionOrPayload[$key])) {
                $normalized = tekg_agent_normalize_language_code($questionOrPayload[$key]);
                if ($normalized === 'chinese') {
                    return 'chinese';
                }
                if ($normalized === 'english') {
                    $hasExplicitLanguage = true;
                    $fallback = 'english';
                }
            }
        }
        if (!$hasExplicitLanguage && isset($questionOrPayload['question']) && is_string($questionOrPayload['question'])) {
            $question = $questionOrPayload['question'];
        }
    }

    if (preg_match('/[\x{4e00}-\x{9fff}]/u', $question)) {
        return 'chinese';
    }
    $normalizedFallback = tekg_agent_normalize_language_code($fallback);
    return $normalizedFallback !== '' ? $normalizedFallback : 'english';
}

function tekg_agent_make_session_id(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $_) {
        return md5((string)microtime(true) . '::' . (string)mt_rand());
    }
}

function tekg_agent_make_request_id(): string
{
    try {
        return 'req_' . bin2hex(random_bytes(8));
    } catch (Throwable $_) {
        return 'req_' . md5((string)microtime(true) . '::' . (string)mt_rand());
    }
}

function tekg_agent_session_file(string $sessionId): string
{
    return rtrim(tekg_agent_session_cache_dir(), '/\\') . '/' . preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $sessionId) . '.json';
}

function tekg_agent_default_session_memory(): array
{
    return [
        'topic_entities' => [],
        'last_intent' => '',
        'confirmed_claims' => [],
        'citations' => [],
        'failed_aliases' => [],
        'tool_history' => [],
        'resolved_entities' => [],
        'active_gaps' => [],
        'closed_gaps' => [],
        'failed_queries' => [],
        'weak_claims' => [],
        'strong_claims' => [],
        'claim_status_by_source' => [],
        'expert_attempts' => [],
        'compression_notes' => [],
        'next_step_hints' => [],
        'session_snapshot' => [],
        'recent_turns' => [],
        'last_mode' => '',
        'conversation_version' => 1,
    ];
}

function tekg_agent_load_session_memory(string $sessionId): array
{
    $path = tekg_agent_session_file($sessionId);
    if (!is_file($path)) {
        return tekg_agent_default_session_memory();
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return tekg_agent_default_session_memory();
    }
    return array_replace(tekg_agent_default_session_memory(), $decoded);
}

function tekg_agent_save_session_memory(string $sessionId, array $memory): void
{
    $path = tekg_agent_session_file($sessionId);
    file_put_contents($path, json_encode($memory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function tekg_agent_json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function tekg_agent_append_diagnostic_log(string $requestId, string $event, array $payload = []): void
{
    $record = [
        'ts' => gmdate('c'),
        'request_id' => $requestId,
        'event' => $event,
        'payload' => tekg_agent_json_safe($payload),
    ];
    $path = rtrim(tekg_agent_diagnostics_dir(), '/\\') . '/answer-chain.jsonl';
    @file_put_contents($path, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function tekg_agent_http_request(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null,
    int $timeout = 45,
    bool $sslVerify = false,
    ?string $requestId = null,
    ?string $stage = null
): array
{
    $startedAt = microtime(true);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (!$sslVerify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch) ?: 'Unknown HTTP transport error';
            curl_close($ch);
            if ($requestId !== null) {
                tekg_agent_append_diagnostic_log($requestId, 'http_request_error', [
                    'stage' => $stage,
                    'url' => $url,
                    'timeout' => $timeout,
                    'error' => $error,
                    'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                ]);
            }
            throw new RuntimeException($error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = ['status' => $status, 'body' => (string)$raw];
        if ($requestId !== null) {
            tekg_agent_append_diagnostic_log($requestId, 'http_request_complete', [
                'stage' => $stage,
                'url' => $url,
                'timeout' => $timeout,
                'status' => $status,
                'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                'body_length' => strlen((string)$raw),
            ]);
        }
        return $result;
    }

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        if ($requestId !== null) {
            tekg_agent_append_diagnostic_log($requestId, 'http_request_error', [
                'stage' => $stage,
                'url' => $url,
                'timeout' => $timeout,
                'error' => 'HTTP request failed.',
                'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            ]);
        }
        throw new RuntimeException('HTTP request failed.');
    }
    $status = 200;
    foreach (($http_response_header ?? []) as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$headerLine, $matches)) {
            $status = (int)$matches[1];
            break;
        }
    }
    $result = ['status' => $status, 'body' => (string)$raw];
    if ($requestId !== null) {
        tekg_agent_append_diagnostic_log($requestId, 'http_request_complete', [
            'stage' => $stage,
            'url' => $url,
            'timeout' => $timeout,
            'status' => $status,
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'body_length' => strlen((string)$raw),
        ]);
    }
    return $result;
}
