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

function call_deepthink_private_sequence_local(TekgDeepThinkService $service, string $methodName, array $args): mixed
{
    $method = new ReflectionMethod($service, $methodName);
    return $method->invokeArgs($service, $args);
}

$service = new TekgDeepThinkService([
    'deepseek_model' => 'deepseek-v4-flash',
    'deepseek_reasoner_model' => 'deepseek-v4-flash',
]);

$analysis = [
    'intent' => 'sequence',
    'answer_language' => 'english',
    'asks_for_sequence' => true,
    'asks_for_papers' => false,
];

$pluginResults = [
    'Sequence Plugin' => [
        'plugin_name' => 'Sequence Plugin',
        'status' => 'ok',
        'results' => [
            'matched_records' => [[
                'entity_label' => 'L1HS',
                'matched_alias' => 'L1HS',
                'alias_mode' => 'strict',
                'repbase_name' => 'L1HS',
                'length' => 6065,
                'entry' => [
                    'name' => 'L1HS',
                    'length' => 6065,
                    'sequence' => 'ACGTACGTACGT',
                ],
            ]],
        ],
        'result_counts' => ['matched_records' => 1],
        'evidence_items' => [[
            'claim' => 'L1HS maps to a Repbase-backed sequence record with a consensus length of 6065 bp.',
            'source_plugin' => 'Sequence Plugin',
            'source' => 'repbase',
        ]],
        'citations' => [[
            'source' => 'repbase',
            'title' => 'Repbase Reports entry for L1HS',
            'journal' => 'Repbase Reports',
            'year' => '2012',
        ]],
    ],
];

$answer = call_deepthink_private_sequence_local(
    $service,
    'buildDeterministicAnswer',
    ['What is the consensus length and evidence source of L1HS?', 'english', $analysis, $pluginResults, $pluginResults['Sequence Plugin']['citations']]
);

assert_true(is_array($answer), 'Consensus length/source question should use a deterministic local sequence answer.');
assert_true(($answer['path'] ?? '') === 'direct_sequence_fact', 'Consensus length/source question should not use the full-sequence path.');
assert_true(str_contains((string)($answer['body'] ?? ''), '6065 bp'), 'Deterministic sequence fact answer should include consensus length.');
assert_true(str_contains((string)($answer['body'] ?? ''), 'Repbase'), 'Deterministic sequence fact answer should include the evidence source.');
assert_true(!str_contains((string)($answer['body'] ?? ''), '```text'), 'Length/source answer should not dump the full sequence.');
assert_true(($answer['summary_required'] ?? true) === false, 'Deterministic local fact answers should not require LLM summary.');
assert_true(
    call_deepthink_private_sequence_local($service, 'deterministicDiagnosticModel', [$answer]) === 'deterministic',
    'Deterministic diagnostics should not imply an LLM summary when summary_required is false.'
);

echo "DeepThink sequence local answer tests passed.\n";
