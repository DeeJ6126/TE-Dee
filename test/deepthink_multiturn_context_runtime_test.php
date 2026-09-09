<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_deepthink_service();

function dt_context_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function dt_context_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

final class DeepThinkContextFixturePlugin implements TekgAgentPluginInterface
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
        $isGenome = $this->name === 'Genome Plugin';
        return [
            'plugin_name' => $this->name,
            'status' => 'ok',
            'query_summary' => $this->name . ' fixture completed.',
            'results' => ['fixture' => true],
            'display_label' => $this->name . ' fixture',
            'display_summary' => $this->name . ' fixture completed.',
            'display_details' => ['preview_items' => [], 'citations' => []],
            'result_counts' => $isGenome ? ['loci' => 1] : ['resolved_entities' => 1],
            'evidence_items' => $isGenome ? [tekg_agent_make_evidence_item(
                $this->name,
                '本地基因组数据中有 AluY 的代表性位置记录。',
                'AluY',
                'medium',
                ['fixture' => 'deepthink_multiturn'],
                ['title' => 'AluY 基因组位置', 'body' => '存在代表性位置记录。'],
                [
                    'evidence_type' => 'representative_locus',
                    'coverage_dimension' => 'genome',
                    'subject' => 'AluY',
                    'provenance' => ['source' => 'test_fixture'],
                ]
            )] : [],
            'citations' => [],
            'errors' => [],
            'latency_ms' => 0,
        ];
    }
}

function dt_context_config(mixed $contextFixture): array
{
    return [
        'agent_test_mode' => true,
        'agent_json_fixtures' => ['conversation_context' => $contextFixture],
        'dt_node_fixtures' => [
            'understanding' => [
                'schema_version' => 'dt_understanding.v1',
                'stage' => 'understanding',
                'question_summary' => '查询 AluY 的基因组位置。',
                'answer_language' => 'zh',
                'intent' => 'genome',
                'entities' => [['name' => 'AluY', 'canonical_label' => 'AluY', 'type' => 'TE']],
                'answer_goal' => '总结本地基因组位置证据。',
                'evidence_requirements' => ['genome'],
                'warnings' => [],
            ],
            'planning' => [
                'schema_version' => 'dt_planning.v1',
                'stage' => 'planning',
                'business_plugins' => ['Genome Plugin'],
                'execution_goal' => '检索基因组位置证据。',
                'citation_resolver_allowed' => false,
                'rationale' => '基因组问题需要 Genome Plugin。',
            ],
            'executing' => [
                [
                    'schema_version' => 'dt_executing.v1',
                    'stage' => 'executing',
                    'done' => false,
                    'next_plugin' => 'Genome Plugin',
                    'reason' => '检索基因组位置。',
                    'evidence_summary' => [],
                    'gaps' => ['genome'],
                ],
                [
                    'schema_version' => 'dt_executing.v1',
                    'stage' => 'executing',
                    'done' => true,
                    'next_plugin' => null,
                    'reason' => '基因组证据已足够。',
                    'evidence_summary' => ['存在代表性位置记录。'],
                    'gaps' => [],
                ],
            ],
            'writing' => [
                'schema_version' => 'dt_writing.v1',
                'stage' => 'writing',
                'answer_markdown' => '本地基因组数据中有 AluY 的代表性位置记录。',
                'limitations' => [],
            ],
        ],
    ];
}

function dt_context_service(mixed $contextFixture): array
{
    $service = new TekgDeepThinkService(dt_context_config($contextFixture));
    $entity = new DeepThinkContextFixturePlugin('Entity Resolver');
    $genome = new DeepThinkContextFixturePlugin('Genome Plugin');
    (new ReflectionProperty($service, 'plugins'))->setValue($service, [
        'Entity Resolver' => $entity,
        'Genome Plugin' => $genome,
    ]);
    return [$service, $entity, $genome];
}

function dt_context_cleanup(string $sessionId): void
{
    $path = tekg_agent_session_file($sessionId);
    if (is_file($path)) {
        @unlink($path);
    }
}

$ambiguousSession = 'deepthink-multiturn-ambiguous-test';
dt_context_cleanup($ambiguousSession);
tekg_agent_save_session_memory($ambiguousSession, array_replace(tekg_agent_default_session_memory(), [
    'topic_entities' => ['L1HS', 'SVA_F'],
    'recent_turns' => [[
        'mode' => 'deepthink',
        'original_question' => 'Compare L1HS with SVA_F.',
        'effective_question' => 'Compare L1HS with SVA_F.',
        'answer_summary' => 'The answer compared both TEs.',
        'entities' => ['L1HS', 'SVA_F'],
        'intent' => 'comparison',
    ]],
]));
[$ambiguousService, $ambiguousEntity, $ambiguousGenome] = dt_context_service([
    'status' => 'needs_clarification',
    'effective_question' => '',
    'inherited_entities' => [],
    'reason' => '两个 TE 都可能是指代对象。',
]);
$ambiguous = $ambiguousService->handle([
    'question' => '那它的基因组位置呢？',
    'request_id' => 'deepthink-multiturn-ambiguous-request',
    'session_id' => $ambiguousSession,
]);
dt_context_assert_same([], $ambiguous['used_plugins'] ?? null, 'Ambiguous DeepThink follow-up runs no plugins');
dt_context_assert_same('needs_clarification', $ambiguous['context_resolution']['status'] ?? null, 'DeepThink reports clarification status');
dt_context_assert_true(str_contains((string)($ambiguous['answer'] ?? ''), 'L1HS'), 'Chinese clarification names L1HS');
dt_context_assert_true(str_contains((string)($ambiguous['answer'] ?? ''), 'SVA_F'), 'Chinese clarification names SVA_F');
dt_context_assert_same(0, $ambiguousEntity->runCount, 'Ambiguous DeepThink skips Entity Resolver');
dt_context_assert_same(0, $ambiguousGenome->runCount, 'Ambiguous DeepThink skips Genome Plugin');
dt_context_cleanup($ambiguousSession);

$sessionId = 'deepthink-multiturn-success-test';
dt_context_cleanup($sessionId);
[$service, $entityPlugin, $genomePlugin] = dt_context_service([
    'status' => 'resolved_follow_up',
    'effective_question' => '查询 AluY 的基因组位置。',
    'inherited_entities' => ['AluY'],
    'reason' => '“它”指向唯一的活跃 TE。',
]);
$first = $service->handle([
    'question' => 'AluY 的基因组位置。',
    'request_id' => 'deepthink-multiturn-first-request',
    'session_id' => $sessionId,
]);
dt_context_assert_same(false, $first['failed'] ?? null, 'First DeepThink turn succeeds');
dt_context_assert_same('standalone', $first['context_resolution']['status'] ?? null, 'First DeepThink turn is standalone');

$second = $service->handle([
    'question' => '那它的基因组位置呢？',
    'request_id' => 'deepthink-multiturn-second-request',
    'session_id' => $sessionId,
]);
dt_context_assert_same(false, $second['failed'] ?? null, 'DeepThink follow-up succeeds');
dt_context_assert_same('那它的基因组位置呢？', $second['question'] ?? null, 'DeepThink preserves original follow-up');
dt_context_assert_same('resolved_follow_up', $second['context_resolution']['status'] ?? null, 'DeepThink reports resolved context');
dt_context_assert_true($genomePlugin->contexts !== [], 'Genome Plugin executes for paired DeepThink fixture');
$lastGenomeContext = $genomePlugin->contexts[array_key_last($genomePlugin->contexts)];
dt_context_assert_true(str_contains((string)($lastGenomeContext['question'] ?? ''), 'AluY'), 'Genome Plugin receives effective AluY question');
$savedMemory = tekg_agent_load_session_memory($sessionId);
dt_context_assert_same(2, count($savedMemory['recent_turns'] ?? []), 'Both successful DeepThink turns are recorded');
dt_context_assert_same('那它的基因组位置呢？', $savedMemory['recent_turns'][1]['original_question'] ?? null, 'Stored DeepThink turn preserves original question');
dt_context_cleanup($sessionId);

$isolatedSession = 'deepthink-multiturn-isolation-test';
dt_context_cleanup($isolatedSession);
[$isolatedService, $isolatedEntity, $isolatedGenome] = dt_context_service(null);
$isolated = $isolatedService->handle([
    'question' => '那它的基因组位置呢？',
    'request_id' => 'deepthink-multiturn-isolation-request',
    'session_id' => $isolatedSession,
]);
dt_context_assert_same('needs_clarification', $isolated['context_resolution']['status'] ?? null, 'A different session cannot inherit AluY');
dt_context_assert_same(0, $isolatedGenome->runCount, 'Isolated session pronoun runs no Genome Plugin');
dt_context_cleanup($isolatedSession);

echo "DeepThink multi-turn context runtime tests passed.\n";
