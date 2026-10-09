<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

function agent_context_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function agent_context_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

final class AgentContextFixturePlugin implements TekgAgentPluginInterface
{
    public int $runCount = 0;
    public array $contexts = [];

    public function __construct(private readonly string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function run(array $context): array
    {
        $this->runCount++;
        $this->contexts[] = $context;
        $isExpression = $this->name === 'Expression Plugin';
        return [
            'plugin_name' => $this->name,
            'status' => 'ok',
            'query_summary' => $this->name . ' fixture completed.',
            'results' => ['fixture' => true],
            'display_label' => $this->name . ' fixture',
            'display_summary' => $this->name . ' fixture completed.',
            'display_details' => ['preview_items' => [], 'citations' => []],
            'result_counts' => $isExpression ? ['expression_profiles' => 1] : ['resolved_entities' => 1],
            'evidence_items' => $isExpression ? [tekg_agent_make_evidence_item(
                $this->name,
                'The local expression dataset contains expression evidence for L1HS.',
                'L1HS',
                'medium',
                ['fixture' => 'agent_multiturn'],
                ['title' => 'L1HS expression', 'body' => 'Expression evidence is available.'],
                [
                    'evidence_type' => 'expression_observation',
                    'coverage_dimension' => 'expression',
                    'subject' => 'L1HS',
                    'provenance' => ['source' => 'test_fixture'],
                ]
            )] : [],
            'citations' => [],
            'errors' => [],
            'latency_ms' => 0,
        ];
    }
}

function agent_context_fixtures(array $contextFixture): array
{
    return wrapper_config([
        wrapper_turn([wrapper_call('expression', 'expression_lookup', ['te' => ['L1HS']])]),
        wrapper_finish(),
        wrapper_turn([], 'The local expression dataset contains expression evidence for L1HS.'),
    ], ['agent_json_fixtures' => ['conversation_context' => $contextFixture]]);
}
function agent_context_service(array $contextFixture): array
{
    $service = new TekgAcademicAgentService(agent_context_fixtures($contextFixture));
    $entity = new AgentContextFixturePlugin('Entity Resolver');
    $expression = new AgentContextFixturePlugin('Expression Plugin');
    (new ReflectionProperty($service, 'plugins'))->setValue($service, [
        'Entity Resolver' => $entity,
        'Expression Plugin' => $expression,
    ]);
    return [$service, $entity, $expression];
}

function agent_context_cleanup(string $sessionId): void
{
    $path = tekg_agent_session_file($sessionId);
    if (is_file($path)) {
        @unlink($path);
    }
}

$ambiguousSession = 'agent-multiturn-ambiguous-test';
agent_context_cleanup($ambiguousSession);
tekg_agent_save_session_memory($ambiguousSession, array_replace(tekg_agent_default_session_memory(), [
    'topic_entities' => ['L1HS', 'SVA_F'],
    'recent_turns' => [[
        'mode' => 'agent',
        'original_question' => 'Compare L1HS with SVA_F.',
        'effective_question' => 'Compare L1HS with SVA_F.',
        'answer_summary' => 'The answer compared both TEs.',
        'entities' => ['L1HS', 'SVA_F'],
        'intent' => 'comparison',
    ]],
]));
[$ambiguousService, $ambiguousEntity, $ambiguousExpression] = agent_context_service([
    'status' => 'needs_clarification',
    'effective_question' => '',
    'inherited_entities' => [],
    'reason' => 'Both prior TEs are plausible.',
]);
$ambiguousResponse = $ambiguousService->handle([
    'question' => 'What about its expression?',
    'request_id' => 'agent-multiturn-ambiguous-request',
    'session_id' => $ambiguousSession,
]);
agent_context_assert_same([], $ambiguousResponse['used_plugins'] ?? null, 'Ambiguous Agent follow-up runs no plugins');
agent_context_assert_same('needs_clarification', $ambiguousResponse['context_resolution']['status'] ?? null, 'Agent returns context clarification status');
agent_context_assert_true(str_contains((string)($ambiguousResponse['answer'] ?? ''), 'L1HS'), 'Clarification names the first candidate');
agent_context_assert_true(str_contains((string)($ambiguousResponse['answer'] ?? ''), 'SVA_F'), 'Clarification names the second candidate');
agent_context_assert_same(0, $ambiguousEntity->runCount, 'Ambiguous follow-up skips Entity Resolver');
agent_context_assert_same(0, $ambiguousExpression->runCount, 'Ambiguous follow-up skips Expression Plugin');
agent_context_assert_same(1, count(tekg_agent_load_session_memory($ambiguousSession)['recent_turns']), 'A clarification does not append a successful Agent turn');
agent_context_cleanup($ambiguousSession);

$sessionId = 'agent-multiturn-success-test';
agent_context_cleanup($sessionId);
[$service, $entityPlugin, $expressionPlugin] = agent_context_service([
    'status' => 'resolved_follow_up',
    'effective_question' => 'L1HS expression profile.',
    'inherited_entities' => ['L1HS'],
    'reason' => 'The pronoun refers to the sole active TE.',
]);
$first = $service->handle([
    'question' => 'L1HS expression profile.',
    'request_id' => 'agent-multiturn-first-request',
    'session_id' => $sessionId,
    'polisher_enabled' => false,
]);
agent_context_assert_same(false, $first['writing_failed'] ?? null, 'First Agent turn succeeds');
agent_context_assert_same('standalone', $first['context_resolution']['status'] ?? null, 'First Agent turn is standalone');

$second = $service->handle([
    'question' => 'What about its expression?',
    'request_id' => 'agent-multiturn-second-request',
    'session_id' => $sessionId,
    'polisher_enabled' => false,
]);
agent_context_assert_same(false, $second['writing_failed'] ?? null, 'Follow-up Agent turn succeeds');
agent_context_assert_same('What about its expression?', $second['question'] ?? null, 'Agent response preserves original follow-up');
agent_context_assert_same('resolved_follow_up', $second['context_resolution']['status'] ?? null, 'Agent reports resolved context');
agent_context_assert_true(
    $expressionPlugin->contexts !== [],
    'Expression Plugin executes for the paired Agent fixture; used=' . json_encode($second['used_plugins'] ?? [])
);
$lastExpressionContext = $expressionPlugin->contexts[array_key_last($expressionPlugin->contexts)];
agent_context_assert_true(str_contains((string)($lastExpressionContext['question'] ?? ''), 'L1HS'), 'Expression Plugin receives effective L1HS question');
$analysisLabels = array_values(array_filter(array_map(
    static fn(array $entity): string => (string)($entity['canonical_label'] ?? $entity['label'] ?? ''),
    (array)($second['analysis']['normalized_entities'] ?? [])
)));
agent_context_assert_true(in_array('L1HS', $analysisLabels, true), 'Agent routing analysis contains inherited L1HS');
$savedMemory = tekg_agent_load_session_memory($sessionId);
agent_context_assert_same(2, count($savedMemory['recent_turns'] ?? []), 'Both successful Agent turns are recorded');
agent_context_assert_same('What about its expression?', $savedMemory['recent_turns'][1]['original_question'] ?? null, 'Stored turn preserves original follow-up');
agent_context_assert_true(str_contains((string)($savedMemory['recent_turns'][1]['effective_question'] ?? ''), 'L1HS'), 'Stored turn preserves effective entity question');
$beforeFailure = tekg_agent_load_session_memory($sessionId);
$failureConfig = agent_context_fixtures([
    'status' => 'resolved_follow_up', 'effective_question' => 'L1HS expression profile.',
    'inherited_entities' => ['L1HS'], 'reason' => 'The follow-up refers to the sole previously resolved TE.',
]);
$failureConfig['qa_native_fixtures'] = [['ok' => false, 'error' => 'Fixture writing service unavailable.', 'error_type' => 'transport_error']];
$failureService = new TekgAcademicAgentService($failureConfig);
wrapper_inject($failureService, ['Entity Resolver' => $entityPlugin, 'Expression Plugin' => $expressionPlugin]);
$failure = $failureService->handle(['question' => 'What about its expression?', 'request_id' => 'agent-multiturn-failed-request', 'session_id' => $sessionId]);
agent_context_assert_same(true, $failure['failed'] ?? null, 'A failed follow-up is visibly failed');
agent_context_assert_same('', $failure['answer'] ?? null, 'A failed follow-up does not invent expression evidence');
agent_context_assert_same($beforeFailure['recent_turns'], tekg_agent_load_session_memory($sessionId)['recent_turns'], 'A failed follow-up cannot alter successful conversation memory');
agent_context_cleanup($sessionId);

$isolatedSession = 'agent-multiturn-isolation-test';
agent_context_cleanup($isolatedSession);
[$isolatedService, $isolatedEntity, $isolatedExpression] = agent_context_service([]);
$isolated = $isolatedService->handle(['question' => 'What about its expression?', 'request_id' => 'agent-multiturn-isolation-request', 'session_id' => $isolatedSession]);
agent_context_assert_same('needs_clarification', $isolated['context_resolution']['status'] ?? null, 'A different Agent session cannot inherit L1HS');
agent_context_assert_same(0, $isolatedEntity->runCount, 'An isolated unresolved pronoun skips entity bootstrap');
agent_context_assert_same(0, $isolatedExpression->runCount, 'An isolated session pronoun executes no expression lookup');
agent_context_assert_same([], tekg_agent_load_session_memory($isolatedSession)['recent_turns'], 'An isolated clarification is not saved as a successful scientific answer');
agent_context_cleanup($isolatedSession);
echo "Agent multi-turn context runtime tests passed.\n";
