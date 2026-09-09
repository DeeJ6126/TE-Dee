<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/contracts/NodeLlmResult.php';
require_once __DIR__ . '/../api/agent/orchestrator/LlmClient.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

if (!function_exists('tekg_agent_http_request')) {
    function tekg_agent_http_request(
        string $url,
        string $method,
        array $headers,
        ?string $body,
        int $timeout,
        bool $sslVerify,
        ?string $requestId = null,
        string $stage = 'llm'
    ): array {
        $GLOBALS['dt_contract_requests'][] = [
            'url' => $url,
            'body' => $body,
            'stage' => $stage,
        ];
        $response = array_shift($GLOBALS['dt_contract_responses']);
        return [
            'status' => (int)($response['status'] ?? 200),
            'body' => (string)($response['body'] ?? ''),
        ];
    }
}

function relay_body(array $artifact): string
{
    return json_encode([
        'response' => [
            'choices' => [
                ['message' => ['content' => json_encode($artifact, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

$schemas = require __DIR__ . '/../api/agent/config/dt_node_schemas.php';
$prompts = require __DIR__ . '/../api/agent/config/dt_node_prompts.php';

$fixtures = [
    'understanding' => [
        'schema_version' => 'dt_understanding.v1',
        'stage' => 'understanding',
        'question_summary' => 'Find LINE-1 relations.',
        'answer_language' => 'en',
        'intent' => 'relationship',
        'entities' => [['name' => 'LINE-1', 'type' => 'TE']],
        'answer_goal' => 'Summarize supported relations.',
        'evidence_requirements' => ['graph'],
        'warnings' => [],
    ],
    'planning' => [
        'schema_version' => 'dt_planning.v1',
        'stage' => 'planning',
        'business_plugins' => ['Graph Plugin'],
        'execution_goal' => 'Collect graph relations.',
        'citation_resolver_allowed' => true,
        'rationale' => 'Use local structured evidence.',
    ],
    'executing' => [
        'schema_version' => 'dt_executing.v1',
        'stage' => 'executing',
        'done' => true,
        'next_plugin' => null,
        'reason' => 'Evidence is sufficient.',
        'evidence_summary' => ['Graph evidence collected.'],
        'gaps' => [],
    ],
    'writing' => [
        'schema_version' => 'dt_writing.v1',
        'stage' => 'writing',
        'answer_markdown' => 'LINE-1 has supported graph relations.',
        'limitations' => [],
    ],
];

foreach ($fixtures as $stage => $fixture) {
    assert_true(isset($schemas[$stage]), "{$stage} DT schema exists");
    assert_true(isset($prompts[$stage]['en'], $prompts[$stage]['zh']), "{$stage} DT bilingual prompts exist");
    assert_true(str_contains($prompts[$stage]['en'], 'JSON'), "{$stage} English prompt requires JSON");
    assert_true(str_contains($prompts[$stage]['zh'], 'JSON'), "{$stage} Chinese prompt requires JSON");
    assert_true(str_contains($prompts[$stage]['en'], 'request language'), "{$stage} English prompt locks generated narration to the request language");
    assert_true(str_contains($prompts[$stage]['zh'], '请求语言'), "{$stage} Chinese prompt locks generated narration to the request language");
    assert_true(str_contains($prompts[$stage]['en'], 'Do not translate'), "{$stage} English prompt protects raw scientific data");
    assert_true(str_contains($prompts[$stage]['zh'], '不要翻译'), "{$stage} Chinese prompt protects raw scientific data");
}
assert_true(in_array('answer_markdown', $schemas['writing']['required'] ?? [], true), 'Writing schema requires answer_markdown');

$client = new TekgAgentLlmClient([
    'llm_relay_url' => 'http://fixture-relay.local/chat',
    'llm_empty_content_retry_delay_us' => 0,
]);
$GLOBALS['dt_contract_requests'] = [];
$GLOBALS['dt_contract_responses'] = array_map(
    static fn(array $fixture): array => ['body' => relay_body($fixture)],
    array_values($fixtures)
);

$results = [
    $client->runDeepThinkUnderstandingNode('deepseek-v4-flash', 'en', ['question' => 'LINE-1 relations']),
    $client->runDeepThinkPlanningNode('deepseek-v4-flash', 'en', ['understanding' => $fixtures['understanding'], 'plugin_directory' => 'DT_CATALOG_SENTINEL']),
    $client->runDeepThinkExecutingNode('deepseek-v4-flash', 'en', ['planning' => $fixtures['planning'], 'plugin_directory' => 'DT_CATALOG_SENTINEL']),
    $client->runDeepThinkWritingNode('deepseek-v4-flash', 'en', ['execution' => $fixtures['executing']]),
];
foreach ($results as $result) {
    assert_true($result instanceof NodeLlmResult, 'DT wrapper returns NodeLlmResult');
    assert_same(true, $result->ok, "{$result->stage} DT artifact validates");
}
assert_same('LINE-1 has supported graph relations.', $results[3]->parsed_json['answer_markdown'] ?? null, 'Writing artifact carries final Markdown answer');

$models = [];
$promptsSentToRelay = [];
foreach ($GLOBALS['dt_contract_requests'] as $request) {
    $payload = json_decode((string)($request['body'] ?? ''), true);
    $models[] = $payload['model'] ?? null;
    $promptsSentToRelay[] = (string)($payload['messages'][1]['content'] ?? '');
}
assert_same(
    ['deepseek-v4-flash', 'deepseek-v4-flash', 'deepseek-v4-flash', 'deepseek-v4-flash'],
    $models,
    'All four DT nodes send the same flash model to the relay'
);
assert_true(str_contains($promptsSentToRelay[1] ?? '', 'plugin_directory'), 'DT Planning payload reaches the final LLM prompt');
assert_true(str_contains($promptsSentToRelay[2] ?? '', 'plugin_directory'), 'DT Executing payload reaches the final LLM prompt');
assert_true(str_contains($promptsSentToRelay[1] ?? '', 'DT_CATALOG_SENTINEL'), 'DT Planning prompt contains catalog text');
assert_true(str_contains($promptsSentToRelay[2] ?? '', 'DT_CATALOG_SENTINEL'), 'DT Executing prompt contains catalog text');

$GLOBALS['dt_contract_requests'] = [];
$GLOBALS['dt_contract_responses'] = [[
    'body' => json_encode(['response' => ['choices' => [['message' => ['content' => '']]]]], JSON_THROW_ON_ERROR),
]];
$empty = $client->runDeepThinkUnderstandingNode('deepseek-v4-flash', 'en', ['question' => 'LINE-1 relations']);
assert_same(false, $empty->ok, 'Empty DT artifact fails');
assert_same(1, count($GLOBALS['dt_contract_requests']), 'Empty DT artifact is not implicitly retried');

echo "DeepThink four-stage contract tests passed.\n";
