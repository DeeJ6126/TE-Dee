<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/orchestrator/traits/AcademicAgentPlanningTrait.php';
require_once __DIR__ . '/../api/agent/orchestrator/traits/AcademicAgentNarrationTrait.php';
require_once __DIR__ . '/../api/agent/orchestrator/traits/AcademicAgentPluginResultTrait.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

final class TekgAgentNarrationTaskComplexityHarness
{
    use TekgAcademicAgentPlanningTrait {
        buildPlan as public captureBuildPlan;
    }
    use TekgAcademicAgentNarrationTrait {
        emitAnalysisThoughtFlow as public captureAnalysisThoughtFlow;
        emitPlanningThoughtFlow as public capturePlanningThoughtFlow;
    }
    use TekgAcademicAgentPluginResultTrait {
        toolSelectedMessage as public captureToolSelectedMessage;
        synthesizingMessage as public captureSynthesizingMessage;
        reflectionMessage as public captureReflectionMessage;
    }

    public function capture(array $analysis, string $language = 'english'): array
    {
        $events = [];
        $sequence = 0;
        $this->captureAnalysisThoughtFlow(
            static function (array $event) use (&$events): void {
                $events[] = $event;
            },
            'test-session',
            'test-model',
            $language,
            $analysis,
            $sequence
        );
        return $events;
    }

    public function capturePlanning(array $planning, string $language = 'english'): array
    {
        $events = [];
        $sequence = 0;
        $this->capturePlanningThoughtFlow(
            static function (array $event) use (&$events): void {
                $events[] = $event;
            },
            'test-session',
            'test-model',
            $language,
            $planning,
            $sequence
        );
        return $events;
    }

    private function emitEvent(?callable $emit, int &$eventSequence, array $event): void
    {
        $event['sequence'] = ++$eventSequence;
        if ($emit !== null) {
            $emit($event);
        }
    }
}

$harness = new TekgAgentNarrationTaskComplexityHarness();
$events = $harness->capture([
    'intent' => 'sequence',
    'complexity' => 'single_hop_reasoning',
    'task_complexity' => 'simple_lookup',
    'recommended_mode' => 'deepthink',
    'task_complexity_reason' => 'Direct lookup covered by Deep Think.',
    'normalized_entities' => [[
        'type' => 'TE',
        'canonical_label' => 'L1HS',
        'matched_alias' => 'L1HS',
    ]],
]);

$suggestionEvents = array_values(array_filter($events, static function (array $event): bool {
    $message = (string)($event['message'] ?? '');
    return str_contains($message, 'Deep Think')
        || str_contains($message, 'quick lookup')
        || str_contains($message, 'usually faster');
}));

assert_true($suggestionEvents === [], 'Agent narration should not recommend Deep Think or discourage Agent use.');

$allText = implode("\n", array_map(static fn(array $event): string => (string)($event['message'] ?? ''), $events));
assert_true(str_contains($allText, 'Deep Think') === false, 'User-facing Agent narration should not mention Deep Think as a better path.');
assert_true(str_contains($allText, 'quick lookup') === false, 'User-facing Agent narration should not label the run as a quick lookup.');
assert_true(str_contains($allText, 'task_complexity') === false, 'User-facing recommendation should not expose internal field names.');
assert_true(str_contains($allText, 'recommended_mode') === false, 'User-facing recommendation should not expose internal field names.');
assert_true(
    ($events[0]['payload']['complexity'] ?? null) === 'single_hop_reasoning',
    'Legacy complexity should remain in payload.'
);

$zhEvents = $harness->capture([
    'intent' => 'sequence',
    'complexity' => 'single_hop_reasoning',
    'normalized_entities' => [[
        'entity_type' => 'TE',
        'canonical_label' => 'L1HS',
        'matched_alias' => 'LINE-1',
    ]],
], 'chinese');
$zhText = implode("\n", array_map(static fn(array $event): string => (string)($event['message'] ?? ''), $zhEvents));
assert_true(str_contains($zhText, '识别到的实体：L1HS (TE)，匹配别名 LINE-1。'), 'Chinese Agent analysis narration localizes prose but preserves entity labels and aliases.');
assert_true(str_contains($zhText, '问题类型：sequence。复杂度：single_hop_reasoning。'), 'Chinese Agent analysis narration localizes deterministic metadata prose.');

$zhPlanning = $harness->capturePlanning([
    'knowledge_gaps' => [[
        'gap_type' => 'ASSOCIATED_WITH',
        'why_needed' => 'PMID:12345 https://example.test/paper',
    ]],
    'subtasks' => ['Inspect DNA sequence ACGTN.'],
], 'chinese');
$zhPlanningText = implode("\n", array_map(static fn(array $event): string => (string)($event['message'] ?? ''), $zhPlanning));
assert_true(str_contains($zhPlanningText, '当前知识缺口：ASSOCIATED_WITH，因为 PMID:12345 https://example.test/paper。'), 'Chinese planning narration localizes prose without translating relation types, PMIDs, or URLs.');
assert_true(str_contains($zhPlanningText, '子任务：Inspect DNA sequence ACGTN.'), 'Chinese planning narration preserves raw subtask data.');

$planning = ['knowledge_gaps' => [['gap_type' => 'ASSOCIATED_WITH']]];
assert_true(
    str_contains($harness->captureToolSelectedMessage('Graph Plugin', $planning, 'chinese'), 'Graph Plugin'),
    'Chinese Agent tool selection preserves plugin registry names.'
);
assert_true(
    str_contains($harness->captureSynthesizingMessage($planning, ['Graph Plugin' => []], [['claim' => 'raw'] ], 'chinese'), 'Graph Plugin'),
    'Chinese Agent synthesis preserves plugin registry names.'
);
$reflection = $harness->captureReflectionMessage('Graph Plugin', [
    'status' => 'ok',
    'display_summary' => 'LINE-1 ASSOCIATED_WITH Disease:Alzheimer https://example.test/evidence ACGTN',
], ['Graph Plugin'], 0, 'chinese');
assert_true(str_contains($reflection, 'LINE-1 ASSOCIATED_WITH Disease:Alzheimer https://example.test/evidence ACGTN'), 'Chinese Agent reflection preserves raw scientific data.');
assert_true(str_contains($reflection, '当前没有排队中的其他工具。'), 'Chinese Agent reflection localizes deterministic shell prose.');

$zhMechanismPlan = $harness->captureBuildPlan('L1HS如何导致疾病', [
    'intent' => 'mechanism',
    'complexity' => 'mechanism_chain',
    'answer_language' => 'chinese',
    'normalized_entities' => [[
        'entity_type' => 'TE',
        'canonical_label' => 'L1HS',
        'matched_alias' => 'L1HS',
    ]],
], []);
$zhMechanismPlanningEvents = $harness->capturePlanning($zhMechanismPlan, 'chinese');
$zhMechanismPlanningText = implode("\n", array_map(static fn(array $event): string => (string)($event['message'] ?? ''), $zhMechanismPlanningEvents));
foreach ([
    'The system must resolve',
    'Mechanism questions first',
    'Collect evidence for',
    'Resolve the canonical identity',
] as $englishLeak) {
    assert_true(!str_contains($zhMechanismPlanningText, $englishLeak), 'Chinese Agent mechanism planning should not leak English template: ' . $englishLeak);
}
assert_true(str_contains($zhMechanismPlanningText, '实体标准化'), 'Chinese Agent mechanism planning localizes entity-normalization gap.');
assert_true(str_contains($zhMechanismPlanningText, '稳定的标准实体和别名链'), 'Chinese Agent mechanism planning explains entity normalization in Chinese.');
assert_true(str_contains($zhMechanismPlanningText, '子任务：解析 L1HS 的标准身份和别名边界。'), 'Chinese Agent mechanism planning localizes subtasks while preserving entity labels.');

echo "Agent narration task complexity tests passed.\n";
