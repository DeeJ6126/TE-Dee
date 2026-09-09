<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();

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
    return [
        'agent_test_mode' => true,
        'agent_polisher_enabled' => false,
        'agent_json_fixtures' => [
            'conversation_context' => $contextFixture,
            'answer_structure' => [
                'response_mode' => 'evidence_summary',
                'opening_claim' => 'Expression evidence is available.',
                'section_plan' => [],
                'claim_order' => ['The local expression dataset contains expression evidence for L1HS.'],
                'citation_policy' => 'Use no citation when none is supplied.',
                'uncertainty_notes' => [],
            ],
        ],
        'agent_text_fixtures' => [
            'evidence_walk_draft' => 'The local expression dataset contains expression evidence for L1HS.',
        ],
        'six_stage_node_fixtures' => [
            'understanding' => [
                'schema_version' => 'understanding_result.v1',
                'stage' => 'understanding',
                'language' => 'en',
                'question_summary' => 'Retrieve expression evidence for L1HS.',
                'intent' => 'expression',
                'entities' => [['name' => 'L1HS', 'type' => 'TE']],
                'ambiguities' => [],
                'mode_boundary' => 'agent_research',
                'required_evidence' => ['expression'],
                'warnings' => [],
            ],
            'planning' => [
                'schema_version' => 'research_plan.v1',
                'stage' => 'planning',
                'research_goal' => 'Retrieve local expression evidence.',
                'evidence_dimensions' => ['expression'],
                'plugin_route' => ['Entity Resolver', 'Expression Plugin'],
                'required_plugins' => ['Expression Plugin'],
                'optional_plugins' => [],
                'success_criteria' => ['Expression evidence is returned.'],
                'risks' => [],
            ],
            'collecting' => [
                'schema_version' => 'collection_decision.v1',
                'stage' => 'collecting',
                'is_sufficient' => false,
                'missing_dimensions' => ['expression'],
                'next_plugin' => 'Entity Resolver',
                'stop_reason' => '',
                'evidence_gaps' => ['expression'],
                'decision_rationale' => 'Run the validated route.',
            ],
            'executing' => [
                'schema_version' => 'tool_execution_review.v1',
                'stage' => 'executing',
                'plugin_name' => 'Expression Plugin',
                'plugin_result' => ['status' => 'ok'],
                'review_status' => 'reviewed',
                'usable' => true,
                'evidence_summary' => 'Expression evidence is usable.',
                'caveats' => [],
                'normalized_findings' => ['Expression evidence is available.'],
            ],
            'integrating' => [
                'schema_version' => 'claim_evidence_map.v1',
                'stage' => 'integrating',
                'claims' => ['The local expression dataset contains expression evidence for L1HS.'],
                'evidence_links' => [],
                'unsupported_claims' => [],
                'conflicts' => [],
                'limitations' => [],
                'integrity_notes' => [],
            ],
            'writing' => [
                'schema_version' => 'writing_decision.v1',
                'stage' => 'writing',
                'writing_strategy' => 'Answer directly in user-facing language.',
                'required_sections' => [],
                'forbidden_claims' => [],
                'citation_requirements' => [],
                'tone' => 'concise',
                'final_checks' => ['Do not expose internal labels.'],
            ],
        ],
    ];
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
agent_context_cleanup($sessionId);

echo "Agent multi-turn context runtime tests passed.\n";
