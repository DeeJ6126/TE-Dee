<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_deepthink_service();

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

function call_dt_private(TekgDeepThinkService $service, string $method, array $args): mixed
{
    return (new ReflectionMethod($service, $method))->invokeArgs($service, $args);
}

function dt_fixture_set(array $overrides = []): array
{
    return array_replace([
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
            'business_plugins' => [],
            'execution_goal' => 'Use bootstrap evidence.',
            'citation_resolver_allowed' => true,
            'rationale' => 'No business plugin is needed for this fixture.',
        ],
        'executing' => [
            'schema_version' => 'dt_executing.v1',
            'stage' => 'executing',
            'done' => true,
            'next_plugin' => null,
            'reason' => 'Bootstrap evidence is enough for this fixture.',
            'evidence_summary' => ['Entity resolution completed.'],
            'gaps' => [],
        ],
        'writing' => [
            'schema_version' => 'dt_writing.v1',
            'stage' => 'writing',
            'answer_markdown' => 'WRITER_ARTIFACT_ONLY',
            'limitations' => [],
        ],
    ], $overrides);
}

function dt_service(array $fixtures): TekgDeepThinkService
{
    return new TekgDeepThinkService([
        'deepseek_model' => 'should-not-be-used',
        'deepseek_reasoner_model' => 'should-not-be-used',
        'agent_test_mode' => true,
        'dt_node_fixtures' => $fixtures,
    ]);
}

final class DtFixturePlugin implements TekgAgentPluginInterface
{
    public int $runCount = 0;

    public function __construct(private readonly string $name, private readonly array $citations = [], private readonly array $overrides = [])
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function run(array $context): array
    {
        $this->runCount++;
        return array_replace_recursive([
            'plugin_name' => $this->name,
            'status' => 'ok',
            'query_summary' => $this->name . ' fixture query completed.',
            'results' => ['fixture' => $this->name],
            'display_label' => $this->name . ' fixture',
            'display_summary' => $this->name . ' completed.',
            'display_details' => ['preview_items' => [], 'citations' => $this->citations],
            'result_counts' => ['items' => 1],
            'evidence_items' => [],
            'citations' => $this->citations,
            'errors' => [],
            'latency_ms' => 0,
        ], $this->overrides);
    }
}

function set_dt_plugins(TekgDeepThinkService $service, array $plugins): void
{
    (new ReflectionProperty($service, 'plugins'))->setValue($service, $plugins);
}

$service = dt_service(dt_fixture_set());
assert_true(method_exists($service, 'validateDeepThinkBusinessPlugins'), 'DT service exposes strict business-plugin validation');
assert_true(method_exists($service, 'assertDeepThinkPluginSucceeded'), 'DT service exposes strict plugin execution failure gate');

$validated = call_dt_private($service, 'validateDeepThinkBusinessPlugins', [[
    'Graph Plugin',
    'Sequence Plugin',
    'Expression Plugin',
    'Genome Plugin',
], true]);
assert_same(['Graph Plugin', 'Sequence Plugin', 'Expression Plugin', 'Genome Plugin'], $validated, 'Four or more unique business plugins are allowed');

foreach ([
    ['Graph Plugin', 'Graph Plugin'],
    ['Entity Resolver'],
    ['Citation Resolver'],
    ['Unknown Plugin'],
] as $invalidPlugins) {
    try {
        call_dt_private($service, 'validateDeepThinkBusinessPlugins', [$invalidPlugins, true]);
        assert_true(false, 'Invalid plugin plan must fail: ' . implode(', ', $invalidPlugins));
    } catch (RuntimeException) {
    }
}
assert_same(
    false,
    call_dt_private($service, 'hasExplicitLiteratureRequest', ['Summarize LINE-1 mechanism evidence sources.']),
    'Generic evidence source wording must not trigger literature'
);
assert_same(
    true,
    call_dt_private($service, 'hasExplicitLiteratureRequest', ['What papers and PMID citations support LINE-1?']),
    'Explicit papers and PMID wording allows literature'
);
assert_same(
    true,
    call_dt_private($service, 'shouldRunCitationResolver', [[
        'Graph Plugin' => ['citations' => [['pmid' => '12345']]],
    ]]),
    'Citation Resolver remains available as an extra resolver when business-plugin citations exist'
);
foreach ([
    [false, ['Literature Plugin' => ['status' => 'ok', 'citations' => [['pmid' => '12345']]]]],
    [true, ['Literature Plugin' => ['status' => 'error', 'citations' => [['pmid' => '12345']]]]],
    [true, ['Literature Plugin' => ['status' => 'ok', 'citations' => []]]],
] as [$explicitLiterature, $pluginResults]) {
    try {
        call_dt_private($service, 'assertDeepThinkBusinessPluginMayRun', ['Literature Reading Plugin', $explicitLiterature, $pluginResults]);
        assert_true(false, 'Literature Reading Plugin must require explicit wording and usable Literature Plugin citations');
    } catch (RuntimeException) {
    }
}
call_dt_private($service, 'assertDeepThinkBusinessPluginMayRun', ['Literature Reading Plugin', true, [
    'Literature Plugin' => ['status' => 'partial', 'citations' => [['pmid' => '12345']]],
]]);
$navigationEvidence = call_dt_private($service, 'aggregateEvidence', [[
    'Site Navigator Plugin' => [
        'evidence_items' => [['claim' => 'Open the sequence panel.', 'support_strength' => 'high']],
    ],
    'Graph Plugin' => [
        'evidence_items' => [['claim' => 'LINE-1 has a graph relation.', 'support_strength' => 'medium']],
    ],
]]);
assert_same(1, count($navigationEvidence), 'Site Navigator Plugin does not enter DT scientific evidence');
assert_same('Graph Plugin', $navigationEvidence[0]['source_plugin'] ?? null, 'DT evidence keeps the scientific source only');
try {
    call_dt_private($service, 'assertDeepThinkPluginSucceeded', ['Graph Plugin', ['status' => 'error', 'errors' => ['Neo4j unavailable.']]]);
    assert_true(false, 'Plugin status=error must fail Executing');
} catch (RuntimeException) {
}
assert_same(
    'deepseek-v4-flash',
    call_dt_private($service, 'resolveModel', [['model' => 'deepseek-v4-pro']]),
    'DT ignores frontend model overrides and resolves flash once'
);

$events = [];
$response = $service->stream([
    'question' => 'LINE-1 relationships',
    'request_id' => 'dt-four-stage-success-test',
    'session_id' => 'dt-four-stage-success-test',
], static function (array $event) use (&$events): void {
    $events[] = $event;
});
assert_same(false, $response['failed'] ?? null, 'Successful DT run is not failed');
assert_same('WRITER_ARTIFACT_ONLY', $response['answer'] ?? null, 'Final answer comes only from Writing artifact');
assert_same(
    ['Understanding', 'Planning', 'Writing'],
    array_values(array_map(
        static fn(array $event): string => (string)($event['payload']['current_stage'] ?? ''),
        array_values(array_filter($events, static fn(array $event): bool => ($event['type'] ?? '') === 'stage_state' && ($event['payload']['status'] ?? '') === 'started'))
    )),
    'DT naturally skips Executing when the validated remaining plugin list is empty'
);
assert_same(3, count(array_filter($events, static fn(array $event): bool => ($event['type'] ?? '') === 'artifact')), 'DT emits no synthetic Executing artifact when no business plugin remains');

$emptyWritingRetryService = dt_service(dt_fixture_set([
    'writing' => [
        [
            'schema_version' => 'dt_writing.v1',
            'stage' => 'writing',
            'answer_markdown' => '',
            'limitations' => ['No direct record was returned.'],
        ],
        [
            'schema_version' => 'dt_writing.v1',
            'stage' => 'writing',
            'answer_markdown' => 'TE-KG did not return a direct record for this question. This does not establish biological absence.',
            'limitations' => [],
        ],
    ],
]));
$emptyWritingRetry = $emptyWritingRetryService->stream([
    'question' => 'Which disease links are recorded for MER41-int?',
    'request_id' => 'dt-empty-writing-retry-test',
    'session_id' => 'dt-empty-writing-retry-test',
], static function (array $event): void {
});
assert_same(false, $emptyWritingRetry['failed'] ?? null, 'DT retries once when the first writing artifact is empty');
assert_same(
    'TE-KG did not return a direct record for this question. This does not establish biological absence.',
    $emptyWritingRetry['answer'] ?? null,
    'DT returns the non-empty user-facing retry'
);

$navigationService = dt_service(dt_fixture_set([
    'planning' => [
        'schema_version' => 'dt_planning.v1',
        'stage' => 'planning',
        'business_plugins' => ['Site Navigator Plugin'],
        'execution_goal' => 'Return the requested TE-KG page link.',
        'citation_resolver_allowed' => false,
        'rationale' => 'The user explicitly requested navigation.',
    ],
    'executing' => [[
        'schema_version' => 'dt_executing.v1',
        'stage' => 'executing',
        'done' => false,
        'next_plugin' => 'Site Navigator Plugin',
        'reason' => 'Retrieve the requested link.',
        'evidence_summary' => [],
        'gaps' => ['navigation'],
    ]],
    'writing' => [
        'schema_version' => 'dt_writing.v1',
        'stage' => 'writing',
        'answer_markdown' => 'WRONG_LLM_NAVIGATION_ANSWER',
        'limitations' => [],
    ],
]));
set_dt_plugins($navigationService, [
    'Entity Resolver' => new DtFixturePlugin('Entity Resolver'),
    'Site Navigator Plugin' => new DtFixturePlugin('Site Navigator Plugin', [], [
        'results' => [
            'answer_markdown' => 'Open [L1HS expression](/TE-/expression_detail.php?te=L1HS).',
        ],
    ]),
]);
$navigation = $navigationService->stream([
    'question' => 'Give me the clickable TE-KG link for viewing L1HS expression data.',
    'request_id' => 'dt-direct-navigation-test',
    'session_id' => 'dt-direct-navigation-test',
], static function (array $event): void {
});
assert_same(
    'Open [L1HS expression](/TE-/expression_detail.php?te=L1HS).',
    $navigation['answer'] ?? null,
    'DT returns the validated Site Navigator answer instead of asking the writing model to recreate a link'
);

$multiRoundService = dt_service(dt_fixture_set([
    'planning' => [
        'schema_version' => 'dt_planning.v1',
        'stage' => 'planning',
        'business_plugins' => ['Graph Plugin'],
        'execution_goal' => 'Collect graph evidence.',
        'citation_resolver_allowed' => true,
        'rationale' => 'Use one business plugin and one extra resolver.',
    ],
    'executing' => [
        [
            'schema_version' => 'dt_executing.v1',
            'stage' => 'executing',
            'done' => false,
            'next_plugin' => 'Graph Plugin',
            'reason' => 'Collect graph evidence.',
            'evidence_summary' => [],
            'gaps' => ['graph'],
        ],
        [
            'schema_version' => 'dt_executing.v1',
            'stage' => 'executing',
            'done' => true,
            'next_plugin' => null,
            'reason' => 'Graph evidence is sufficient.',
            'evidence_summary' => ['Graph evidence collected.'],
            'gaps' => [],
        ],
    ],
]));
set_dt_plugins($multiRoundService, [
    'Entity Resolver' => new DtFixturePlugin('Entity Resolver'),
    'Graph Plugin' => new DtFixturePlugin('Graph Plugin', [['pmid' => '12345']]),
    'Citation Resolver' => new DtFixturePlugin('Citation Resolver', [['pmid' => '12345']]),
]);
$multiRoundEvents = [];
$multiRound = $multiRoundService->stream([
    'question' => 'LINE-1 relationships',
    'request_id' => 'dt-four-stage-multi-round-test',
    'session_id' => 'dt-four-stage-multi-round-test',
], static function (array $event) use (&$multiRoundEvents): void {
    $multiRoundEvents[] = $event;
});
assert_same(false, $multiRound['failed'] ?? null, 'Bounded multi-round Executing run succeeds');
assert_same(['Entity Resolver', 'Graph Plugin', 'Citation Resolver'], $multiRound['used_plugins'] ?? null, 'Citation Resolver runs outside the one-business-plugin execution budget');
assert_same(1, count($multiRound['dt_artifacts']['executing'] ?? []), 'Executing naturally ends after the only remaining business plugin runs');
$graphSelected = array_values(array_filter($multiRoundEvents, static fn(array $event): bool => ($event['type'] ?? '') === 'tool_selected' && ($event['plugin_name'] ?? '') === 'Graph Plugin'))[0] ?? [];
assert_same('Collect graph evidence.', $graphSelected['payload']['selection_reason'] ?? null, 'DT tool_selected payload preserves the raw LLM selection reason');
assert_same('I will use Graph Plugin to collect the next required evidence.', $graphSelected['message'] ?? null, 'English DT tool_selected narration uses a deterministic presentation template');
$graphResult = array_values(array_filter($multiRoundEvents, static fn(array $event): bool => ($event['type'] ?? '') === 'tool_result' && ($event['plugin_name'] ?? '') === 'Graph Plugin'))[0] ?? [];
assert_same('Graph Plugin completed. Scientific details are preserved below.', $graphResult['summary'] ?? null, 'English DT tool_result summary uses presentation copy');
assert_same('Graph Plugin completed. Scientific details are preserved below.', $graphResult['message'] ?? null, 'English DT tool_result message uses presentation copy');

$zhService = dt_service(dt_fixture_set([
    'understanding' => [
        'schema_version' => 'dt_understanding.v1',
        'stage' => 'understanding',
        'question_summary' => '查询 LINE-1 关系。',
        'answer_language' => 'zh',
        'intent' => 'relationship',
        'entities' => [['name' => 'LINE-1', 'type' => 'TE']],
        'answer_goal' => '总结有支持的关系。',
        'evidence_requirements' => ['graph'],
        'warnings' => [],
    ],
    'planning' => [
        'schema_version' => 'dt_planning.v1',
        'stage' => 'planning',
        'business_plugins' => ['Graph Plugin'],
        'execution_goal' => '收集图谱关系。',
        'citation_resolver_allowed' => false,
        'rationale' => '使用本地图谱。',
    ],
    'executing' => [
        [
            'schema_version' => 'dt_executing.v1',
            'stage' => 'executing',
            'done' => false,
            'next_plugin' => 'Graph Plugin',
            'reason' => '这是可能漂移的 LLM reason。',
            'evidence_summary' => [],
            'gaps' => ['graph'],
        ],
    ],
    'writing' => [
        'schema_version' => 'dt_writing.v1',
        'stage' => 'writing',
        'answer_markdown' => 'LINE-1 与 Disease:Alzheimer 相关。',
        'limitations' => [],
    ],
]));
$protectedRaw = [
    'entity' => 'LINE-1',
    'paper_title' => 'A LINE-1 paper title',
    'url' => 'https://example.test/LINE-1?relation=ASSOCIATED_WITH',
    'plugin_registry_name' => 'Graph Plugin',
    'sequence' => 'ACGTN',
    'relation_type' => 'ASSOCIATED_WITH',
];
set_dt_plugins($zhService, [
    'Entity Resolver' => new DtFixturePlugin('Entity Resolver'),
    'Graph Plugin' => new DtFixturePlugin('Graph Plugin', [], ['results' => $protectedRaw]),
]);
$zhEvents = [];
$zhResponse = $zhService->stream([
    'question' => 'LINE-1 和哪些疾病相关？',
    'request_id' => 'dt-four-stage-language-zh-test',
    'session_id' => 'dt-four-stage-language-zh-test',
], static function (array $event) use (&$zhEvents): void {
    $zhEvents[] = $event;
});
assert_same(false, $zhResponse['failed'] ?? null, 'Chinese DT language fixture succeeds');
$zhStartedStages = array_values(array_filter($zhEvents, static fn(array $event): bool => ($event['type'] ?? '') === 'stage_state' && ($event['payload']['status'] ?? '') === 'started'));
assert_same('Understanding', $zhStartedStages[0]['payload']['display_label'] ?? null, 'Chinese DT stage display label remains English while current_stage stays stable');
assert_same('Understanding', $zhStartedStages[0]['payload']['current_stage'] ?? null, 'DT stage id remains stable for Chinese requests');
$zhSelected = array_values(array_filter($zhEvents, static fn(array $event): bool => ($event['type'] ?? '') === 'tool_selected' && ($event['plugin_name'] ?? '') === 'Graph Plugin'))[0] ?? [];
assert_same('这是可能漂移的 LLM reason。', $zhSelected['payload']['selection_reason'] ?? null, 'Chinese DT tool_selected payload preserves raw LLM reason');
assert_same('我将使用 Graph Plugin 收集下一步所需证据。', $zhSelected['message'] ?? null, 'Chinese DT tool_selected narration uses deterministic Chinese presentation copy');
$zhResult = array_values(array_filter($zhEvents, static fn(array $event): bool => ($event['type'] ?? '') === 'tool_result' && ($event['plugin_name'] ?? '') === 'Graph Plugin'))[0] ?? [];
assert_same('Graph Plugin 已完成。科研详情保留如下。', $zhResult['summary'] ?? null, 'Chinese DT tool_result summary uses presentation copy');
assert_same('Graph Plugin 已完成。科研详情保留如下。', $zhResult['message'] ?? null, 'Chinese DT tool_result message uses presentation copy');
foreach ($protectedRaw as $key => $value) {
    assert_same($value, $zhResult['payload']['raw_result'][$key] ?? null, "DT presentation copy preserves raw scientific field {$key}");
}
assert_same('Planning 阶段失败，请稍后重试。', call_dt_private($zhService, 'localizedFailureMessage', ['Planning', 'zh']), 'Chinese DT errors use localized narration while preserving the English shell stage');
assert_same('Planning failed. Please try again.', call_dt_private($zhService, 'localizedFailureMessage', ['Planning', 'en']), 'English DT errors use localized presentation copy');

$graphFixture = new DtFixturePlugin('Graph Plugin');
$sequenceFixture = new DtFixturePlugin('Sequence Plugin');
$expressionFixture = new DtFixturePlugin('Expression Plugin');
$genomeFixture = new DtFixturePlugin('Genome Plugin');
$scalableService = dt_service(dt_fixture_set([
    'planning' => [
        'schema_version' => 'dt_planning.v1',
        'stage' => 'planning',
        'business_plugins' => ['Graph Plugin', 'Sequence Plugin', 'Expression Plugin', 'Genome Plugin'],
        'execution_goal' => 'Collect four required evidence layers.',
        'citation_resolver_allowed' => false,
        'rationale' => 'The requested report needs four distinct plugins.',
    ],
    'executing' => [
        ['schema_version' => 'dt_executing.v1', 'stage' => 'executing', 'done' => false, 'next_plugin' => 'Graph Plugin', 'reason' => 'Collect graph evidence.', 'evidence_summary' => [], 'gaps' => ['graph']],
        ['schema_version' => 'dt_executing.v1', 'stage' => 'executing', 'done' => false, 'next_plugin' => 'Sequence Plugin', 'reason' => 'Collect sequence evidence.', 'evidence_summary' => [], 'gaps' => ['sequence']],
        ['schema_version' => 'dt_executing.v1', 'stage' => 'executing', 'done' => false, 'next_plugin' => 'Expression Plugin', 'reason' => 'Collect expression evidence.', 'evidence_summary' => [], 'gaps' => ['expression']],
        ['schema_version' => 'dt_executing.v1', 'stage' => 'executing', 'done' => false, 'next_plugin' => 'Genome Plugin', 'reason' => 'Collect genome evidence.', 'evidence_summary' => [], 'gaps' => ['genome']],
    ],
]));
set_dt_plugins($scalableService, [
    'Entity Resolver' => new DtFixturePlugin('Entity Resolver'),
    'Graph Plugin' => $graphFixture,
    'Sequence Plugin' => $sequenceFixture,
    'Expression Plugin' => $expressionFixture,
    'Genome Plugin' => $genomeFixture,
]);
$scalable = $scalableService->handle([
    'question' => 'Build a LINE-1 report',
    'request_id' => 'dt-four-stage-scalable-plan-test',
    'session_id' => 'dt-four-stage-scalable-plan-test',
]);
assert_same(false, $scalable['failed'] ?? null, 'DT accepts a four-plugin validated plan');
assert_same(4, count($scalable['dt_artifacts']['executing'] ?? []), 'Remaining plugins naturally end execution without an extra model decision');
foreach ([$graphFixture, $sequenceFixture, $expressionFixture, $genomeFixture] as $fixturePlugin) {
    assert_same(1, $fixturePlugin->runCount, "{$fixturePlugin->getName()} runs at most once");
}

$failedEvents = [];
$failedService = dt_service(dt_fixture_set([
    'planning' => [
        'schema_version' => 'dt_planning.v1',
        'stage' => 'planning',
        'business_plugins' => ['Graph Plugin', 'Graph Plugin'],
        'execution_goal' => 'Duplicate plugin.',
        'citation_resolver_allowed' => true,
        'rationale' => 'Fixture must fail.',
    ],
]));
$failed = $failedService->stream([
    'question' => 'LINE-1 relationships',
    'request_id' => 'dt-four-stage-failure-test',
    'session_id' => 'dt-four-stage-failure-test',
], static function (array $event) use (&$failedEvents): void {
    $failedEvents[] = $event;
});
assert_same(true, $failed['failed'] ?? null, 'Duplicate planning plugin fails run');
assert_same('', $failed['answer'] ?? null, 'Failed run answer is empty');
assert_same('Planning', $failed['failure_stage'] ?? null, 'Planning failure is not mislabeled as Writing');
assert_same('error', $failedEvents[count($failedEvents) - 2]['type'] ?? null, 'Failure emits explicit error before done');
assert_same('done', $failedEvents[count($failedEvents) - 1]['type'] ?? null, 'Failure emits terminal done');
assert_same('', $failedEvents[count($failedEvents) - 1]['payload']['answer'] ?? null, 'Terminal failed done answer is empty');

$writingFailedEvents = [];
$writingFailedService = dt_service(dt_fixture_set([
    'writing' => [
        'schema_version' => 'dt_writing.v1',
        'stage' => 'writing',
        'answer_markdown' => '',
        'limitations' => ['No supported answer.'],
    ],
]));
$writingFailed = $writingFailedService->stream([
    'question' => 'LINE-1 relationships',
    'request_id' => 'dt-four-stage-writing-failure-test',
    'session_id' => 'dt-four-stage-writing-failure-test',
], static function (array $event) use (&$writingFailedEvents): void {
    $writingFailedEvents[] = $event;
});
assert_same(true, $writingFailed['failed'] ?? null, 'Empty Writing artifact fails run');
assert_same(true, $writingFailed['writing_failed'] ?? null, 'Writing failure sets writing_failed');
assert_same('Writing', $writingFailed['failure_stage'] ?? null, 'Writing failure names Writing stage');
assert_same('', $writingFailed['answer'] ?? null, 'Writing failure answer is empty');

$malformedStageFixtures = [
    'Understanding' => [
        'understanding' => [
            'schema_version' => 'dt_understanding.v1',
            'stage' => 'understanding',
            'question_summary' => 'Missing required fields.',
        ],
    ],
    'Planning' => [
        'planning' => [
            'schema_version' => 'dt_planning.v1',
            'stage' => 'planning',
            'business_plugins' => [],
        ],
    ],
    'Executing' => [
        'planning' => [
            'schema_version' => 'dt_planning.v1',
            'stage' => 'planning',
            'business_plugins' => ['Graph Plugin'],
            'execution_goal' => 'Reach Executing validation.',
            'citation_resolver_allowed' => false,
            'rationale' => 'Fixture exercises malformed Executing output.',
        ],
        'executing' => [
            'schema_version' => 'dt_executing.v1',
            'stage' => 'executing',
            'done' => true,
        ],
    ],
    'Writing' => [
        'writing' => [
            'schema_version' => 'dt_writing.v1',
            'stage' => 'writing',
            'answer_markdown' => 'Must not escape a malformed Writing artifact.',
        ],
    ],
];
$orderedStages = array_keys($malformedStageFixtures);
foreach ($malformedStageFixtures as $failedStage => $fixtureOverride) {
    $stageEvents = [];
    $stageFailureService = dt_service(dt_fixture_set($fixtureOverride));
    $stageFailure = $stageFailureService->stream([
        'question' => 'LINE-1 relationships',
        'request_id' => 'dt-four-stage-malformed-' . strtolower($failedStage),
        'session_id' => 'dt-four-stage-malformed-' . strtolower($failedStage),
    ], static function (array $event) use (&$stageEvents): void {
        $stageEvents[] = $event;
    });
    assert_same(true, $stageFailure['failed'] ?? null, "{$failedStage} malformed artifact fails run");
    assert_same($failedStage, $stageFailure['failure_stage'] ?? null, "{$failedStage} malformed artifact reports exact failure stage");
    assert_same('', $stageFailure['answer'] ?? null, "{$failedStage} malformed artifact leaves answer empty");
    assert_same('error', $stageEvents[count($stageEvents) - 2]['type'] ?? null, "{$failedStage} malformed artifact emits explicit error before done");
    assert_same('done', $stageEvents[count($stageEvents) - 1]['type'] ?? null, "{$failedStage} malformed artifact emits terminal done");
    assert_same('', $stageEvents[count($stageEvents) - 1]['payload']['answer'] ?? null, "{$failedStage} malformed terminal done answer is empty");

    $failedStageIndex = array_search($failedStage, $orderedStages, true);
    $laterStages = array_slice($orderedStages, $failedStageIndex + 1);
    $startedStages = array_values(array_map(
        static fn(array $event): string => (string)($event['payload']['current_stage'] ?? ''),
        array_values(array_filter($stageEvents, static fn(array $event): bool => ($event['type'] ?? '') === 'stage_state' && ($event['payload']['status'] ?? '') === 'started'))
    ));
    foreach ($laterStages as $laterStage) {
        assert_same(false, in_array($laterStage, $startedStages, true), "{$failedStage} failure stops before {$laterStage}");
    }
}

foreach (['Literature Plugin', 'Literature Reading Plugin'] as $literaturePlugin) {
    try {
        call_dt_private($service, 'validateDeepThinkBusinessPlugins', [[$literaturePlugin], false]);
        assert_true(false, "{$literaturePlugin} must require explicit literature wording");
    } catch (RuntimeException) {
    }
}

$endpointSource = (string)file_get_contents(__DIR__ . '/../api/deep_think_stream.php');
foreach (["'failed' => true", "'writing_failed' => false", "'failure_stage' => 'Endpoint'", "'failure_reason'", "'answer' => ''"] as $needle) {
    assert_true(str_contains($endpointSource, $needle), "Endpoint terminal payload includes {$needle}");
}
assert_true(str_contains($endpointSource, "'presentation_failure_reason'"), 'Endpoint terminal payload includes localized presentation failure copy');
assert_true(str_contains($endpointSource, "'language' => \$answerLanguage"), 'Endpoint terminal payload exposes the detected request language');
$serviceSource = (string)file_get_contents(__DIR__ . '/../api/agent/orchestrator/DeepThinkService.php');
foreach (['deepthink_stage_artifact', 'deepthink_terminal_failure', 'deepthink_terminal_success'] as $needle) {
    assert_true(str_contains($serviceSource, $needle), "DT diagnostics include {$needle}");
}
assert_true(substr_count($serviceSource, "'plugin_directory' => tekg_agent_plugin_directory()") >= 2, 'DT Planning and Executing receive the plugin directory');

echo "DeepThink four-stage runtime tests passed.\n";
