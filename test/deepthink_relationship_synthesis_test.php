<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/../api/agent/orchestrator/DeepThinkService.php';
tekg_agent_require_academic_agent_service();

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function call_deepthink_private(TekgDeepThinkService $service, string $methodName, array $args): mixed
{
    $method = new ReflectionMethod($service, $methodName);
    return $method->invokeArgs($service, $args);
}

$service = new TekgDeepThinkService([
    'deepseek_model' => 'deepseek-v4-flash',
    'deepseek_reasoner_model' => 'deepseek-v4-flash',
]);

$analysis = [
    'intent' => 'relationship',
    'answer_language' => 'chinese',
];

$pluginResults = [
    'Graph Plugin' => [
        'status' => 'ok',
        'results' => [
            'rows' => [
                [
                    'source_name' => 'L1HS',
                    'target_type' => 'Disease',
                    'target_labels' => ['Disease'],
                    'target_name' => 'Cancer',
                    'relation_type' => 'BIO_RELATION',
                    'relation_description' => 'L1HS mobilization in somatic tissues contributes to diseases such as cancer.',
                ],
                [
                    'source_name' => 'L1HS',
                    'target_type' => 'Function',
                    'target_labels' => ['Function'],
                    'target_name' => 'Insertional mutagenesis',
                    'relation_type' => 'BIO_RELATION',
                    'relation_description' => 'LINE-1 retrotransposition mediates insertional mutagenesis in cancer.',
                ],
            ],
        ],
    ],
];

$synthesisQuestion = '你整合一下L1HS的信息';
$synthesisAnswer = call_deepthink_private(
    $service,
    'buildDeterministicAnswer',
    [$synthesisQuestion, 'chinese', $analysis, $pluginResults, []]
);
assert_true($synthesisAnswer === null, 'Integration/synthesis relationship questions should use synthesis writing, not the full relationship list dump.');

$listQuestion = '列出 L1HS 的全部关系';
$listAnswer = call_deepthink_private(
    $service,
    'buildDeterministicAnswer',
    [$listQuestion, 'chinese', $analysis, $pluginResults, []]
);
assert_true(is_array($listAnswer), 'Explicit list-all relationship questions should keep the deterministic full relationship list.');
assert_true(($listAnswer['path'] ?? '') === 'direct_full_relationship_list', 'Explicit list-all relationship questions keep the direct list path.');

$writingContext = call_deepthink_private(
    $service,
    'extraWritingContext',
    [$synthesisQuestion, $analysis, $pluginResults]
);
assert_true(isset($writingContext['relationship_groups']), 'Synthesis relationship questions should pass grouped graph context into the writer.');
assert_true(isset($writingContext['relationship_groups']['Disease']), 'Grouped graph context should include disease relations.');
assert_true(isset($writingContext['relationship_groups']['Function']), 'Grouped graph context should include function relations.');

echo "DeepThink relationship synthesis tests passed.\n";
