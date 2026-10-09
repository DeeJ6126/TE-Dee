<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_deepthink_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

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
    return wrapper_config([
        wrapper_turn([wrapper_call('genome', 'genome_lookup', ['te' => ['AluY']])]),
        wrapper_finish(),
        wrapper_turn([], '本地基因组数据中有 AluY 的代表性位置记录。'),
    ], ['agent_json_fixtures' => ['conversation_context' => $contextFixture]]);
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
dt_context_assert_same(1, count(tekg_agent_load_session_memory($ambiguousSession)['recent_turns']), 'A clarification does not append a successful DeepThink turn');
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
$beforeFailure = tekg_agent_load_session_memory($sessionId);
$failureConfig = dt_context_config([
    'status' => 'resolved_follow_up', 'effective_question' => '查询 AluY 的基因组位置。',
    'inherited_entities' => ['AluY'], 'reason' => '追问指向唯一的活跃 TE。',
]);
$failureConfig['qa_native_fixtures'] = [['ok' => false, 'error' => 'Fixture model unavailable.', 'error_type' => 'transport_error']];
$failureService = new TekgDeepThinkService($failureConfig);
wrapper_inject($failureService, ['Entity Resolver' => $entityPlugin, 'Genome Plugin' => $genomePlugin]);
$failure = $failureService->handle(['question' => '那它的基因组位置呢？', 'request_id' => 'deepthink-multiturn-failed-request', 'session_id' => $sessionId]);
dt_context_assert_same(true, $failure['failed'] ?? null, 'A failed DeepThink follow-up is visibly failed');
dt_context_assert_same('', $failure['answer'] ?? null, 'A failed follow-up does not invent genomic evidence');
dt_context_assert_same($beforeFailure['recent_turns'], tekg_agent_load_session_memory($sessionId)['recent_turns'], 'A failed DeepThink follow-up cannot alter successful conversation memory');
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
