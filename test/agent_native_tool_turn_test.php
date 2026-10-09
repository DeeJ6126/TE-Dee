<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/orchestrator/LlmClient.php';

function native_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tekg_agent_http_request(string $url, string $method, array $headers, ?string $body, int $timeout,
    bool $sslVerify, ?string $requestId = null, ?string $stage = null): array
{
    $GLOBALS['native_requests'][] = compact('url', 'method', 'headers', 'body', 'timeout', 'sslVerify', 'requestId', 'stage');
    $response = array_shift($GLOBALS['native_responses']);
    if ($response instanceof Throwable) {
        throw $response;
    }
    native_assert(is_array($response), 'Every native model request must have a queued fake response.');
    return $response;
}

function tekg_agent_append_diagnostic_log(string $requestId, string $event, array $payload): void
{
    $GLOBALS['native_logs'][] = compact('requestId', 'event', 'payload');
}

function native_response(array $message, string $finish = 'stop', array $extra = []): array
{
    return ['status' => 200, 'body' => json_encode([
        'model' => 'deepseek-flash',
        'choices' => [['message' => ['role' => 'assistant'] + $message, 'finish_reason' => $finish]],
    ] + $extra, JSON_THROW_ON_ERROR)];
}

function native_queue(array $responses): void
{
    $GLOBALS['native_responses'] = $responses;
    $GLOBALS['native_requests'] = [];
    $GLOBALS['native_logs'] = [];
}

$key = 'unit-test-owner-secret';
$client = new TekgAgentLlmClient([
    'deepseek_url' => 'https://fixture-provider.invalid/chat/completions',
    'deepseek_key' => $key,
    'agent_core_model' => 'deepseek-v4-pro',
    'agent_writing_model' => 'deepseek-v4-pro',
    'request_id' => 'native-turn-contract',
    'llm_tool_turn_retry_delay_us' => 0,
]);
$tools = [[
    'type' => 'function',
    'function' => ['name' => 'sequence_lookup', 'description' => 'Read a TE sequence.',
        'parameters' => ['type' => 'object', 'properties' => ['te' => ['type' => 'string']], 'required' => ['te']]],
]];
$messages = [
    ['role' => 'system', 'content' => 'Use real scientific tools.'],
    ['role' => 'user', 'content' => 'Find L1HS.'],
];
$call = ['id' => 'call_sequence_1', 'type' => 'function', 'function' => ['name' => 'sequence_lookup', 'arguments' => '{"te":"L1HS"}']];
native_queue([native_response(['content' => null, 'reasoning_content' => 'Internal plan.', 'tool_calls' => [$call]], 'tool_calls', ['headers' => ['Authorization' => $key]])]);
$turn = $client->requestToolTurn($messages, $tools, ['timeout' => 30, 'model' => 'deepseek-v4-pro']);
native_assert($turn['ok'] === true && $turn['content'] === '' && $turn['tool_calls'] === [$call], 'A tool-only turn is successful without retry.');
native_assert($turn['reasoning_content'] === 'Internal plan.', 'The engine receives complete reasoning as private protocol state.');
native_assert($turn['finish_reason'] === 'tool_calls' && $turn['model'] === 'deepseek-flash', 'Every turn uses the trusted Flash model.');
native_assert(count($GLOBALS['native_requests']) === 1, 'Tool-only turn does not trigger legacy empty-content retry.');
$firstPayload = json_decode($GLOBALS['native_requests'][0]['body'], true, 512, JSON_THROW_ON_ERROR);
native_assert($firstPayload['messages'] === $messages && $firstPayload['tools'] === $tools, 'Original messages and schemas are preserved.');
native_assert($firstPayload['tool_choice'] === 'auto' && $firstPayload['stream'] === false, 'Native tool choice is auto and a complete message is requested.');
native_assert($firstPayload['thinking']['type'] === 'enabled', 'Thinking tools mode stays enabled.');
native_assert(in_array('Authorization: Bearer ' . $key, $GLOBALS['native_requests'][0]['headers'], true), 'Key exists only at the HTTP authentication boundary.');
native_assert(!str_contains(json_encode($turn, JSON_THROW_ON_ERROR), $key) && !isset($turn['headers']), 'Upstream headers and owner key never enter the result.');
native_assert($GLOBALS['native_logs'][0]['payload']['requested_model'] === 'deepseek-flash', 'Existing model completion diagnostics remain active.');
native_assert(!str_contains(json_encode($GLOBALS['native_logs'], JSON_THROW_ON_ERROR), 'Internal plan.'), 'Diagnostic model logs do not include private reasoning.');

$messages[] = ['role' => 'assistant', 'content' => $turn['content'], 'reasoning_content' => $turn['reasoning_content'], 'tool_calls' => $turn['tool_calls']];
$messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => '{"length":6064,"source":"Repbase"}'];
native_queue([native_response(['content' => 'L1HS is 6064 bp [E1].', 'reasoning_content' => 'Private synthesis.'])]);
$final = $client->requestToolTurn($messages, $tools, ['tool_choice' => 'none', 'stage' => 'native_writing']);
$lastPayload = json_decode($GLOBALS['native_requests'][0]['body'], true, 512, JSON_THROW_ON_ERROR);
native_assert($final['ok'] && $final['tool_calls'] === [] && $final['finish_reason'] === 'stop', 'A final answer is accepted.');
native_assert($lastPayload['messages'] === $messages, 'Assistant role, tool calls, exact arguments, reasoning, and matching tool_call_id round-trip intact.');
native_assert($lastPayload['tool_choice'] === 'none' && $lastPayload['tools'] === $tools, 'Final turn disables calls but keeps tools for the DeepSeek reasoning protocol.');
native_assert($lastPayload['model'] === $firstPayload['model'], 'Decision and writing turn use one model.');

foreach (['{incomplete', '[]', 'null', '"string"'] as $badArguments) {
    $badCall = $call;
    $badCall['function']['arguments'] = $badArguments;
    native_queue([native_response(['content' => '', 'tool_calls' => [$badCall]], 'tool_calls')]);
    $bad = $client->requestToolTurn($messages, $tools);
    native_assert(!$bad['ok'] && $bad['error_type'] === 'invalid_tool_arguments' && $bad['tool_calls'] === [], 'Malformed or non-object arguments cannot be executed.');
    native_assert(count($GLOBALS['native_requests']) === 1, 'Invalid arguments are not blindly retried.');
}
$tooDeep = ['value' => 'untrusted nested input'];
for ($level = 0; $level < 40; $level++) { $tooDeep = ['nested' => $tooDeep]; }
$deepCall = $call;
$deepCall['function']['arguments'] = json_encode($tooDeep, JSON_THROW_ON_ERROR);
native_queue([native_response(['tool_calls' => [$deepCall]], 'tool_calls')]);
$deepResult = $client->requestToolTurn($messages, $tools);
native_assert(!$deepResult['ok'] && $deepResult['error_type'] === 'invalid_tool_arguments' && $deepResult['tool_calls'] === [], 'Client rejects JSON deeper than the engine depth of 32.');
native_assert(count($GLOBALS['native_requests']) === 1, 'Oversized argument depth is not retried or executed.');
foreach (['length', 'content_filter', ''] as $finish) {
    native_queue([native_response(['content' => 'Incomplete scientific conclusion', 'reasoning_content' => 'Partial reasoning.'], $finish)]);
    $bad = $client->requestToolTurn($messages, $tools);
    native_assert(!$bad['ok'] && $bad['content'] === '' && $bad['reasoning_content'] === '', 'Incomplete or filtered turns do not become final answers.');
    native_assert(count($GLOBALS['native_requests']) === 1, 'Incomplete completion does not spend retries.');
    if ($finish === 'length') {
        native_assert($bad['error_type'] === 'incomplete_response' && $bad['finish_reason'] === 'length', 'Output exhaustion has an explicit failure type.');
    }
}
$unknownCall = $call;
$unknownCall['function']['name'] = 'shell';
native_queue([native_response(['tool_calls' => [$unknownCall]], 'tool_calls')]);
native_assert($client->requestToolTurn($messages, $tools)['error_type'] === 'invalid_tool_calls', 'Unknown tools cannot cross the client boundary.');
native_queue([native_response(['tool_calls' => [$call, $call]], 'tool_calls')]);
native_assert($client->requestToolTurn($messages, $tools)['error_type'] === 'invalid_tool_calls', 'Duplicate tool-call IDs are rejected.');
native_queue([native_response(['tool_calls' => [$call]], 'tool_calls')]);
native_assert($client->requestToolTurn($messages, $tools, ['tool_choice' => 'none'])['error_type'] === 'invalid_tool_calls', 'Unexpected tool calls during final-only writing are rejected.');

native_queue([
    ['status' => 429, 'body' => '{"error":{"message":"Rate limited"}}'],
    native_response(['content' => 'Recovered.']),
]);
$retry = $client->requestToolTurn($messages, $tools);
native_assert($retry['ok'] && $retry['attempts'] === 2 && count($GLOBALS['native_requests']) === 2, 'Transient HTTP errors permit one bounded retry by default.');
native_queue([['status' => 401, 'body' => json_encode(['error' => ['message' => 'Rejected ' . $key]], JSON_THROW_ON_ERROR)]]);
$unauthorized = $client->requestToolTurn($messages, $tools);
native_assert(!$unauthorized['ok'] && count($GLOBALS['native_requests']) === 1 && !str_contains(json_encode($unauthorized, JSON_THROW_ON_ERROR), $key), 'Credential errors are redacted and never retried.');
native_queue([new RuntimeException('Operation timed out'), new RuntimeException('Operation timed out')]);
$timedOut = $client->requestToolTurn($messages, $tools, ['timeout' => 1]);
native_assert(!$timedOut['ok'] && $timedOut['error_type'] === 'timeout' && $timedOut['attempts'] === 2, 'Transport timeout returns a bounded explicit error.');
native_assert($GLOBALS['native_requests'][0]['timeout'] === 1, 'A one-second configured timeout still makes the initial request.');
native_queue([new RuntimeException('Connection reset'), new RuntimeException('Connection reset'), new RuntimeException('Connection reset')]);
$bounded = $client->requestToolTurn($messages, $tools, ['max_retries' => 999]);
native_assert(!$bounded['ok'] && count($GLOBALS['native_requests']) === 3, 'Retry count cannot exceed two retries.');
native_queue([['status' => 200, 'body' => 'not-json']]);
native_assert($client->requestToolTurn($messages, $tools)['error_type'] === 'invalid_response' && count($GLOBALS['native_requests']) === 1, 'Malformed provider JSON is not retried.');

native_queue([]);
$relay = new TekgAgentLlmClient(['llm_relay_url' => 'http://unsupported-relay.invalid/chat', 'deepseek_key' => $key]);
$unsupported = $relay->requestToolTurn($messages, $tools);
native_assert(!$unsupported['ok'] && $unsupported['error_type'] === 'native_tools_unsupported' && $GLOBALS['native_requests'] === [], 'Unsupported relay fails explicitly without text or direct fallback.');
native_assert((new TekgAgentLlmClient([]))->requestToolTurn($messages, $tools)['error_type'] === 'missing_credentials', 'Missing private runtime credentials fail clearly.');
native_assert($client->requestToolTurn([['role' => 'tool', 'content' => '{}']], $tools)['error_type'] === 'invalid_request', 'Tool result without call ID is rejected before HTTP.');

echo "Native tool turn contract tests passed.\n";
