<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/contracts/NodeLlmResult.php';
require_once __DIR__ . '/../api/agent/orchestrator/LlmClient.php';

$schemas = require __DIR__ . '/../api/agent/config/agent_node_schemas.php';
$prompts = require __DIR__ . '/../api/agent/config/agent_node_prompts.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_contains_string(string $needle, string $haystack, string $message): void
{
    assert_true(str_contains($haystack, $needle), $message . " missing {$needle}");
}

function assert_not_contains_string(string $needle, string $haystack, string $message): void
{
    assert_true(!str_contains($haystack, $needle), $message . " unexpectedly contained {$needle}");
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
        $GLOBALS['six_stage_contract_http_request_count'] = (int)($GLOBALS['six_stage_contract_http_request_count'] ?? 0) + 1;
        $GLOBALS['six_stage_contract_http_request'] = [
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
            'ssl_verify' => $sslVerify,
            'request_id' => $requestId,
            'stage' => $stage,
        ];

        if (isset($GLOBALS['six_stage_contract_http_response_queue']) && is_array($GLOBALS['six_stage_contract_http_response_queue'])) {
            $queued = array_shift($GLOBALS['six_stage_contract_http_response_queue']);
            if (is_array($queued)) {
                return [
                    'status' => (int)($queued['status'] ?? 200),
                    'body' => (string)($queued['body'] ?? ''),
                ];
            }
            return [
                'status' => 200,
                'body' => (string)$queued,
            ];
        }

        return [
            'status' => 200,
            'body' => (string)($GLOBALS['six_stage_contract_http_response'] ?? '{}'),
        ];
    }
}

$expectedSchemas = [
    'understanding_result.v1',
    'research_plan.v1',
    'collection_decision.v1',
    'tool_execution_review.v1',
    'claim_evidence_map.v1',
    'writing_decision.v1',
];

foreach ($expectedSchemas as $schemaVersion) {
    assert_true(isset($schemas[$schemaVersion]), "{$schemaVersion} schema exists");
    assert_same($schemaVersion, $schemas[$schemaVersion]['version'] ?? null, "{$schemaVersion} declares version");
    assert_true(is_string($schemas[$schemaVersion]['stage'] ?? null), "{$schemaVersion} declares stage");
    assert_true(is_array($schemas[$schemaVersion]['required'] ?? null), "{$schemaVersion} declares required fields");
    assert_true(is_array($schemas[$schemaVersion]['properties'] ?? null), "{$schemaVersion} declares properties");
    assert_true(in_array('schema_version', $schemas[$schemaVersion]['required'], true), "{$schemaVersion} requires schema_version");
}

foreach ($expectedSchemas as $schemaVersion) {
    $stage = $schemas[$schemaVersion]['stage'];
    assert_true(isset($prompts[$stage]), "{$stage} prompt exists");
    assert_true(is_string($prompts[$stage]['zh'] ?? null) && trim($prompts[$stage]['zh']) !== '', "{$stage} zh prompt exists");
    assert_true(is_string($prompts[$stage]['en'] ?? null) && trim($prompts[$stage]['en']) !== '', "{$stage} en prompt exists");

    foreach (['zh', 'en'] as $language) {
        $prompt = $prompts[$stage][$language];
        assert_contains_string('JSON', $prompt, "{$stage} {$language} prompt requires JSON");
        assert_contains_string('Markdown', $prompt, "{$stage} {$language} prompt forbids Markdown");
        assert_contains_string('PMID', $prompt, "{$stage} {$language} prompt forbids invented PMID");
        assert_contains_string('URL', $prompt, "{$stage} {$language} prompt forbids invented URL");
        if ($stage === 'executing') {
            assert_contains_string('Do not run', $prompt, "{$stage} {$language} prompt forbids running plugins");
            assert_contains_string('Do not simulate', $prompt, "{$stage} {$language} prompt forbids simulating plugins");
            assert_contains_string('Do not invent tool results', $prompt, "{$stage} {$language} prompt forbids invented tool results");
            assert_contains_string('deterministic plugins may run outside the LLM', $prompt, "{$stage} {$language} prompt allows deterministic plugins outside LLM");
        }
    }
}

$validUnderstanding = [
    'schema_version' => 'understanding_result.v1',
    'stage' => 'understanding',
    'language' => 'en',
    'question_summary' => 'Find evidence for LINE-1 in cancer.',
    'intent' => 'literature',
    'entities' => [['name' => 'LINE-1', 'type' => 'transposable_element']],
    'ambiguities' => [],
    'mode_boundary' => 'agent_research',
    'required_evidence' => ['literature'],
    'warnings' => [],
];
$validResult = NodeLlmResult::fromRawJson('understanding', json_encode($validUnderstanding, JSON_THROW_ON_ERROR), $schemas['understanding_result.v1']);
assert_same(true, $validResult->ok, 'valid understanding result is ok');
assert_same($validUnderstanding, $validResult->parsed_json, 'valid understanding parsed JSON preserved');
assert_same('understanding_result.v1', $validResult->schema_version, 'valid understanding schema version recorded');
assert_same([], $validResult->errors, 'valid understanding has no errors');

$nullableStopDecision = [
    'schema_version' => 'collection_decision.v1',
    'stage' => 'collecting',
    'is_sufficient' => false,
    'missing_dimensions' => ['expression'],
    'next_plugin' => 'Expression Plugin',
    'stop_reason' => null,
    'evidence_gaps' => ['Expression data has not been collected.'],
    'decision_rationale' => 'Collection must continue.',
];
$nullableStopResult = NodeLlmResult::fromRawJson('collecting', json_encode($nullableStopDecision, JSON_THROW_ON_ERROR), $schemas['collection_decision.v1']);
assert_same(true, $nullableStopResult->ok, 'collection decision accepts null stop_reason while collection continues');

$missingField = $validUnderstanding;
unset($missingField['intent']);
$schemaViolation = NodeLlmResult::fromRawJson('understanding', json_encode($missingField, JSON_THROW_ON_ERROR), $schemas['understanding_result.v1']);
assert_same(false, $schemaViolation->ok, 'missing required field fails');
assert_true(in_array('schema: intent is required', $schemaViolation->errors, true), 'missing field reports schema violation');

$notJson = NodeLlmResult::fromRawJson('understanding', 'Here is the answer, not JSON.', $schemas['understanding_result.v1']);
assert_same(false, $notJson->ok, 'non JSON fails');
assert_true($notJson->parsed_json === null, 'non JSON does not synthesize parsed data');
assert_true($notJson->raw_text === 'Here is the answer, not JSON.', 'non JSON raw text preserved');
assert_true((bool)preg_grep('/^parse:/', $notJson->errors), 'non JSON reports parse error');

$wrongVersion = $validUnderstanding;
$wrongVersion['schema_version'] = 'fallback_success.v1';
$noFallback = NodeLlmResult::fromRawJson('understanding', json_encode($wrongVersion, JSON_THROW_ON_ERROR), $schemas['understanding_result.v1']);
assert_same(false, $noFallback->ok, 'wrong schema version cannot fallback to success');
assert_true(in_array('schema: schema_version must be understanding_result.v1', $noFallback->errors, true), 'wrong version reports schema violation');

$executingNoReview = [
    'schema_version' => 'tool_execution_review.v1',
    'stage' => 'executing',
    'plugin_name' => 'Graph Analytics Plugin',
    'plugin_result' => ['status' => 'ok', 'summary' => 'Ranked graph entities.'],
    'review_status' => 'not_required',
    'review_not_required_reason' => 'Deterministic graph ranking result is already normalized by plugin_result_envelope.v1.',
    'usable' => true,
    'evidence_summary' => 'The ranking output can be passed downstream as deterministic evidence.',
    'caveats' => [],
    'normalized_findings' => [],
];
$executingResult = NodeLlmResult::fromRawJson('executing', json_encode($executingNoReview, JSON_THROW_ON_ERROR), $schemas['tool_execution_review.v1']);
assert_same(true, $executingResult->ok, 'executing schema accepts review_not_required_reason');

$validFixturesBySchema = [
    'understanding_result.v1' => $validUnderstanding,
    'research_plan.v1' => [
        'schema_version' => 'research_plan.v1',
        'stage' => 'planning',
        'research_goal' => 'Build evidence for LINE-1 involvement in cancer.',
        'evidence_dimensions' => ['graph', 'literature'],
        'plugin_route' => ['GraphPlugin', 'LiteraturePlugin'],
        'required_plugins' => ['GraphPlugin'],
        'optional_plugins' => ['LiteraturePlugin'],
        'success_criteria' => ['At least one graph relation is supported by evidence.'],
        'risks' => [],
    ],
    'collection_decision.v1' => [
        'schema_version' => 'collection_decision.v1',
        'stage' => 'collecting',
        'is_sufficient' => false,
        'missing_dimensions' => ['literature'],
        'next_plugin' => 'LiteraturePlugin',
        'stop_reason' => '',
        'evidence_gaps' => ['Need source papers.'],
        'decision_rationale' => 'Graph evidence exists but literature support is still missing.',
    ],
    'tool_execution_review.v1' => $executingNoReview,
    'claim_evidence_map.v1' => [
        'schema_version' => 'claim_evidence_map.v1',
        'stage' => 'integrating',
        'claims' => [['claim_id' => 'c1', 'text' => 'LINE-1 has cancer associations.']],
        'evidence_links' => [['claim_id' => 'c1', 'evidence_id' => 'e1']],
        'unsupported_claims' => [],
        'conflicts' => [],
        'limitations' => [],
        'integrity_notes' => ['No unsupported claims detected.'],
    ],
    'writing_decision.v1' => [
        'schema_version' => 'writing_decision.v1',
        'stage' => 'writing',
        'writing_strategy' => 'Write a concise evidence-first answer.',
        'required_sections' => ['Answer', 'Evidence', 'Limitations'],
        'forbidden_claims' => ['Do not claim causality without evidence.'],
        'citation_requirements' => ['Use only provided citations.'],
        'tone' => 'academic',
        'final_checks' => ['Check every claim has evidence.'],
    ],
];

foreach ($validFixturesBySchema as $schemaVersion => $fixture) {
    $stage = $schemas[$schemaVersion]['stage'];
    $result = NodeLlmResult::fromRawJson($stage, json_encode($fixture, JSON_THROW_ON_ERROR), $schemas[$schemaVersion]);
    assert_same(true, $result->ok, "{$schemaVersion} valid fixture is ok");
}

$callerStageMismatch = NodeLlmResult::fromRawJson('planning', json_encode($validUnderstanding, JSON_THROW_ON_ERROR), $schemas['understanding_result.v1']);
assert_same(false, $callerStageMismatch->ok, 'caller stage mismatch fails');
assert_true(in_array('schema: caller stage must match schema stage understanding', $callerStageMismatch->errors, true), 'caller stage mismatch reports schema stage violation');

$payloadStageMismatch = $validUnderstanding;
$payloadStageMismatch['stage'] = 'planning';
$payloadStageMismatchResult = NodeLlmResult::fromRawJson('understanding', json_encode($payloadStageMismatch, JSON_THROW_ON_ERROR), $schemas['understanding_result.v1']);
assert_same(false, $payloadStageMismatchResult->ok, 'payload stage mismatch fails');
assert_true(in_array('schema: stage must be understanding', $payloadStageMismatchResult->errors, true), 'payload stage mismatch reports parsed stage violation');

$invalidReviewStatus = $executingNoReview;
$invalidReviewStatus['review_status'] = 'invented';
$invalidReviewStatusResult = NodeLlmResult::fromRawJson('executing', json_encode($invalidReviewStatus, JSON_THROW_ON_ERROR), $schemas['tool_execution_review.v1']);
assert_same(false, $invalidReviewStatusResult->ok, 'invalid review_status fails');
assert_true((bool)preg_grep('/review_status must be one of/', $invalidReviewStatusResult->errors), 'invalid review_status reports enum violation');

$missingReviewReason = $executingNoReview;
unset($missingReviewReason['review_not_required_reason']);
$missingReviewReasonResult = NodeLlmResult::fromRawJson('executing', json_encode($missingReviewReason, JSON_THROW_ON_ERROR), $schemas['tool_execution_review.v1']);
assert_same(false, $missingReviewReasonResult->ok, 'not_required without review_not_required_reason fails');
assert_true(in_array('schema: review_not_required_reason is required', $missingReviewReasonResult->errors, true), 'missing review_not_required_reason reports conditional violation');

$emptyReviewReason = $executingNoReview;
$emptyReviewReason['review_not_required_reason'] = '   ';
$emptyReviewReasonResult = NodeLlmResult::fromRawJson('executing', json_encode($emptyReviewReason, JSON_THROW_ON_ERROR), $schemas['tool_execution_review.v1']);
assert_same(false, $emptyReviewReasonResult->ok, 'not_required with empty review_not_required_reason fails');
assert_true(in_array('schema: review_not_required_reason is required', $emptyReviewReasonResult->errors, true), 'empty review_not_required_reason reports conditional violation');

$typeViolation = $validFixturesBySchema['collection_decision.v1'];
$typeViolation['is_sufficient'] = 'false';
$typeViolationResult = NodeLlmResult::fromRawJson('collecting', json_encode($typeViolation, JSON_THROW_ON_ERROR), $schemas['collection_decision.v1']);
assert_same(false, $typeViolationResult->ok, 'representative field type error fails');
assert_true(in_array('schema: is_sufficient must be boolean', $typeViolationResult->errors, true), 'representative field type error reports type violation');

$nodeFixtures = [
    'understanding' => json_encode($validUnderstanding, JSON_THROW_ON_ERROR),
    'planning' => json_encode($validFixturesBySchema['research_plan.v1'], JSON_THROW_ON_ERROR),
    'collecting' => json_encode($validFixturesBySchema['collection_decision.v1'], JSON_THROW_ON_ERROR),
    'executing' => json_encode($executingNoReview, JSON_THROW_ON_ERROR),
    'integrating' => json_encode($validFixturesBySchema['claim_evidence_map.v1'], JSON_THROW_ON_ERROR),
    'writing' => json_encode($validFixturesBySchema['writing_decision.v1'], JSON_THROW_ON_ERROR),
];

$productionFixtureClient = new TekgAgentLlmClient(['six_stage_node_fixtures' => $nodeFixtures]);
$runnerPayload = ['question' => 'How is LINE-1 related to cancer?', 'language' => 'en'];
$productionFixtureResult = $productionFixtureClient->runUnderstandingNode('fake-model', 'en', $runnerPayload);
assert_same(false, $productionFixtureResult->ok, 'production config does not consume six-stage fixtures without explicit test mode');
assert_true(
    (bool)preg_grep('/stage=understanding.*provider=deepseek.*model=fake-model/', $productionFixtureResult->errors),
    'production fixture guard failure includes stage provider and model'
);

$fixtureClient = new TekgAgentLlmClient([
    'agent_test_mode' => true,
    'six_stage_node_fixtures' => $nodeFixtures,
]);

$understandingNode = $fixtureClient->runUnderstandingNode('fake-model', 'en', $runnerPayload);
assert_true($understandingNode instanceof NodeLlmResult, 'understanding wrapper returns NodeLlmResult');
assert_same(true, $understandingNode->ok, 'understanding wrapper validates fixture');
assert_same('understanding_result.v1', $understandingNode->schema_version, 'understanding wrapper records schema version');

$wrapperResults = [
    'planning' => $fixtureClient->runPlanningNode('fake-model', 'en', $runnerPayload),
    'collecting' => $fixtureClient->runCollectingNode('fake-model', 'en', $runnerPayload),
    'executing' => $fixtureClient->runExecutingReviewNode('fake-model', 'en', $runnerPayload),
    'integrating' => $fixtureClient->runIntegratingNode('fake-model', 'en', $runnerPayload),
    'writing' => $fixtureClient->runWritingDecisionNode('fake-model', 'en', $runnerPayload),
];
foreach ($wrapperResults as $stage => $result) {
    assert_true($result instanceof NodeLlmResult, "{$stage} wrapper returns NodeLlmResult");
    assert_same(true, $result->ok, "{$stage} wrapper validates fixture");
    assert_same($stage, $result->stage, "{$stage} wrapper records stage");
}

$badJsonClient = new TekgAgentLlmClient([
    'agent_test_mode' => true,
    'six_stage_node_fixtures' => ['understanding' => 'not json'],
]);
$badJsonResult = $badJsonClient->runUnderstandingNode('fake-model', 'en', $runnerPayload);
assert_same(false, $badJsonResult->ok, 'fixture parse failure returns ok=false');
assert_true((bool)preg_grep('/^parse:/', $badJsonResult->errors), 'fixture parse failure reports parse error');

$unknownStageResult = $fixtureClient->runSixStageNode('unknown', 'fake-model', 'en', $runnerPayload);
assert_same(false, $unknownStageResult->ok, 'unknown stage does not silently succeed');
assert_true(in_array('stage: unknown six-stage node unknown', $unknownStageResult->errors, true), 'unknown stage reports whitelist error');

$zhUnderstanding = $validUnderstanding;
$zhUnderstanding['language'] = 'zh';
$zhUnderstanding['question_summary'] = '解释 LINE-1 与癌症之间的证据。';
$GLOBALS['six_stage_contract_http_response'] = json_encode([
    'response' => [
        'choices' => [
            ['message' => ['content' => json_encode($zhUnderstanding, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
unset($GLOBALS['six_stage_contract_http_request']);
$zhClient = new TekgAgentLlmClient([
    'llm_relay_url' => 'http://fixture-relay.local/chat',
    'request_id' => 'six-stage-zh-contract',
]);
$zhResult = $zhClient->runUnderstandingNode('deepseek-v4-flash', 'chinese', [
    'question' => '请解释 LINE-1 与癌症之间的证据。',
    'language' => 'chinese',
]);
assert_same(true, $zhResult->ok, 'zh runner path validates relay JSON response');
$capturedRequest = (array)($GLOBALS['six_stage_contract_http_request'] ?? []);
$capturedPayload = json_decode((string)($capturedRequest['body'] ?? ''), true);
assert_true(is_array($capturedPayload), 'zh runner sends JSON payload to relay');
assert_same(20, $capturedPayload['timeout'] ?? null, 'relay payload includes effective timeout');
$userPrompt = (string)($capturedPayload['messages'][1]['content'] ?? '');
assert_contains_string('只返回 JSON。', $userPrompt, 'zh runner uses Chinese six-stage prompt');
assert_contains_string('understanding_result.v1', $userPrompt, 'zh runner includes expected schema prompt');

$catalogSentinel = 'AGENT_PLUGIN_DIRECTORY_SENTINEL';
foreach ([
    'planning' => ['method' => 'runPlanningNode', 'fixture' => $validFixturesBySchema['research_plan.v1']],
    'collecting' => ['method' => 'runCollectingNode', 'fixture' => $validFixturesBySchema['collection_decision.v1']],
] as $catalogStage => $catalogCase) {
    $GLOBALS['six_stage_contract_http_response'] = json_encode([
        'response' => [
            'choices' => [
                ['message' => ['content' => json_encode($catalogCase['fixture'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    unset($GLOBALS['six_stage_contract_http_request']);
    $catalogResult = $zhClient->{$catalogCase['method']}('deepseek-v4-flash', 'en', [
        'question' => 'Collect LINE-1 evidence.',
        'plugin_directory' => $catalogSentinel,
    ]);
    assert_same(true, $catalogResult->ok, "{$catalogStage} runner accepts relay fixture");
    $catalogRequest = json_decode((string)($GLOBALS['six_stage_contract_http_request']['body'] ?? ''), true);
    $catalogPrompt = (string)($catalogRequest['messages'][1]['content'] ?? '');
    assert_contains_string('plugin_directory', $catalogPrompt, "{$catalogStage} prompt includes plugin_directory key");
    assert_contains_string($catalogSentinel, $catalogPrompt, "{$catalogStage} prompt includes plugin directory text");
}

$GLOBALS['six_stage_contract_http_response'] = json_encode([
    'response' => [
        'choices' => [
            ['message' => ['content' => json_encode([
                'is_sufficient' => true,
                'reason' => 'Enough evidence.',
                'missing_dimensions' => [],
                'recommended_next_experts' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
unset($GLOBALS['six_stage_contract_http_request']);
$sufficiency = $zhClient->assessSufficiency('deepseek-v4-flash', [
    'question' => 'Is the LINE-1 evidence enough?',
    'plugin_directory' => $catalogSentinel,
]);
assert_same(true, $sufficiency['is_sufficient'] ?? null, 'iterative sufficiency runner accepts relay fixture');
$sufficiencyRequest = json_decode((string)($GLOBALS['six_stage_contract_http_request']['body'] ?? ''), true);
$sufficiencyPrompt = (string)($sufficiencyRequest['messages'][1]['content'] ?? '');
assert_contains_string('plugin_directory', $sufficiencyPrompt, 'iterative sufficiency prompt includes plugin_directory key');
assert_contains_string($catalogSentinel, $sufficiencyPrompt, 'iterative sufficiency prompt includes plugin directory text');

$GLOBALS['six_stage_contract_http_response_queue'] = [
    json_encode([
        'response' => [
            'choices' => [
                ['message' => ['content' => '']],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    json_encode([
        'response' => [
            'choices' => [
                ['message' => ['content' => json_encode($validFixturesBySchema['tool_execution_review.v1'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
];
$GLOBALS['six_stage_contract_http_request_count'] = 0;
$retryClient = new TekgAgentLlmClient([
    'llm_relay_url' => 'http://fixture-relay.local/chat',
    'request_id' => 'six-stage-empty-content-retry',
]);
$retryResult = $retryClient->runExecutingReviewNode('deepseek-v4-flash', 'en', [
    'question' => 'Review the plugin result.',
    'plugin_name' => 'Literature Reading Plugin',
    'plugin_result' => ['status' => 'ok'],
]);
assert_same(true, $retryResult->ok, 'empty relay content is retried once and the retry result is used');
assert_same(2, (int)($GLOBALS['six_stage_contract_http_request_count'] ?? 0), 'empty relay content retry performs exactly one extra request');
unset($GLOBALS['six_stage_contract_http_response_queue'], $GLOBALS['six_stage_contract_http_request_count']);

$secretRelayBody = 'upstream Authorization: Bearer sk-secret-relay-key ' . str_repeat('x', 900);
$secretRelaySummary = 'summary Authorization: Bearer sk-secret-summary-key ' . str_repeat('y', 900);
$GLOBALS['six_stage_contract_http_response_queue'] = [
    [
        'status' => 500,
        'body' => json_encode([
            'ok' => false,
            'error_type' => 'upstream_http_error',
            'upstream_status' => 429,
            'error' => 'upstream rate limit from provider',
            'upstream_body' => $secretRelayBody,
            'upstream_body_summary' => $secretRelaySummary,
            'upstream_body_truncated' => true,
            'upstream_body_length' => 948,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ],
];
$relayErrorClient = new TekgAgentLlmClient([
    'llm_relay_url' => 'http://fixture-relay.local/chat?api_key=sk-secret-url-key',
]);
$relayErrorResult = $relayErrorClient->runUnderstandingNode('deepseek-v4-flash', 'en', [
    'question' => 'How is LINE-1 related to cancer?',
    'language' => 'english',
]);
$relayErrorText = implode("\n", $relayErrorResult->errors);
assert_same(false, $relayErrorResult->ok, 'relay HTTP error fails the node');
assert_contains_string('LLM provider returned HTTP 500', $relayErrorText, 'relay HTTP error keeps status');
assert_contains_string('error_type=upstream_http_error', $relayErrorText, 'relay HTTP error includes error_type');
assert_contains_string('upstream_status=429', $relayErrorText, 'relay HTTP error includes upstream_status');
assert_contains_string('error=upstream rate limit from provider', $relayErrorText, 'relay HTTP error includes relay error text');
assert_contains_string('upstream_body_summary=summary Authorization: [redacted]', $relayErrorText, 'relay HTTP error includes redacted upstream body summary');
assert_contains_string('upstream_body_truncated=1', $relayErrorText, 'relay HTTP error includes upstream body truncated flag');
assert_contains_string('upstream_body_length=948', $relayErrorText, 'relay HTTP error includes upstream body length');
assert_not_contains_string('sk-secret-relay-key', $relayErrorText, 'relay HTTP error redacts API keys');
assert_not_contains_string('sk-secret-summary-key', $relayErrorText, 'relay HTTP error redacts summary API keys');
assert_not_contains_string('sk-secret-url-key', $relayErrorText, 'relay HTTP error redacts relay URL API keys');
assert_true(strlen($relayErrorText) < 750, 'relay HTTP error detail is bounded');
unset($GLOBALS['six_stage_contract_http_response_queue']);

echo "Six-stage LLM contract tests passed.\n";
