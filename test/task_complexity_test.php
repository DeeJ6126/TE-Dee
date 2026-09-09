<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/orchestrator/EntityNormalizer.php';

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$normalizer = new TekgAgentEntityNormalizer();

$cases = [
    'sequence evidence source stays local' => [
        'question' => 'What is the consensus length and evidence source of L1HS?',
        'task_complexity' => 'simple_lookup',
        'recommended_mode' => 'deepthink',
        'intent' => 'sequence',
        'asks_for_sequence' => true,
        'asks_for_papers' => false,
    ],
    'sequence lookup stays in Deep Think' => [
        'question' => 'L1HS的序列是什么',
        'task_complexity' => 'simple_lookup',
        'recommended_mode' => 'deepthink',
    ],
    'genome location lookup stays in Deep Think' => [
        'question' => 'L1HS位于哪里',
        'task_complexity' => 'simple_lookup',
        'recommended_mode' => 'deepthink',
    ],
    'expression lookup stays in Deep Think' => [
        'question' => 'L1HS在哪些组织表达',
        'task_complexity' => 'simple_lookup',
        'recommended_mode' => 'deepthink',
    ],
    'site navigation page-name lookup stays in Deep Think' => [
        'question' => '我想看 L1HS 的 Genome Annotation Distribution，应该点哪里？',
        'task_complexity' => 'simple_lookup',
        'recommended_mode' => 'deepthink',
    ],
    'english open sequence panel is site navigation' => [
        'question' => 'Where can I open the L1HS sequence panel in TE-KG?',
        'task_complexity' => 'simple_lookup',
        'recommended_mode' => 'deepthink',
        'asks_for_site_navigation' => true,
        'asks_for_sequence' => true,
    ],
    'ordinary relationship list stays in Deep Think' => [
        'question' => 'L1HS和哪些疾病相关',
        'task_complexity' => 'single_hop',
        'recommended_mode' => 'deepthink',
    ],
    'mechanism question goes to Agent' => [
        'question' => 'LINE-1是如何导致癌症的？',
        'task_complexity' => 'mechanism_chain',
        'recommended_mode' => 'agent',
    ],
    'literature evidence question goes to Agent' => [
        'question' => "What papers support LINE-1 and Alzheimer's disease?",
        'task_complexity' => 'research_synthesis',
        'recommended_mode' => 'agent',
    ],
    'evidence comparison goes to Agent' => [
        'question' => '比较 L1HS、AluY、HERVK 与癌症的证据强度',
        'task_complexity' => 'research_synthesis',
        'recommended_mode' => 'agent',
    ],
    'graph analytics ranking goes to Agent' => [
        'question' => '现在知识图谱里面，哪一个疾病与转座子关联度最大？',
        'task_complexity' => 'research_synthesis',
        'recommended_mode' => 'agent',
    ],
    'research report goes to Agent' => [
        'question' => '为 L1HS 生成一份研究报告',
        'task_complexity' => 'research_synthesis',
        'recommended_mode' => 'agent',
    ],
    'multi-facet L1HS report does not become site navigation' => [
        'question' => 'Generate a research report for L1HS including sequence, genomic location, expression, disease links, and literature evidence.',
        'task_complexity' => 'research_synthesis',
        'recommended_mode' => 'agent',
        'asks_for_site_navigation' => false,
        'asks_for_sequence' => true,
        'asks_for_genome' => true,
        'asks_for_expression' => true,
        'asks_for_papers' => true,
    ],
];

foreach ($cases as $name => $case) {
    $analysis = $normalizer->analyze($case['question']);
    assert_same($case['task_complexity'], $analysis['task_complexity'] ?? null, "{$name}: task_complexity");
    assert_same($case['recommended_mode'], $analysis['recommended_mode'] ?? null, "{$name}: recommended_mode");
    if (array_key_exists('intent', $case)) {
        assert_same($case['intent'], $analysis['intent'] ?? null, "{$name}: intent");
    }
    foreach (['asks_for_site_navigation', 'asks_for_sequence', 'asks_for_genome', 'asks_for_expression', 'asks_for_papers'] as $flag) {
        if (array_key_exists($flag, $case)) {
            assert_same($case[$flag], $analysis[$flag] ?? null, "{$name}: {$flag}");
        }
    }
    assert_true(
        isset($analysis['task_complexity_reason'])
            && is_string($analysis['task_complexity_reason'])
            && trim($analysis['task_complexity_reason']) !== ''
            && preg_match('/^[\x20-\x7E]+$/', $analysis['task_complexity_reason']) === 1,
        "{$name}: task_complexity_reason"
    );
}

echo "Task complexity tests passed.\n";
