<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/../api/agent/contracts/PluginResultContract.php';
require_once __DIR__ . '/../api/agent/contracts/PluginResultProjection.php';
tekg_agent_require_orchestrator_dependencies();
tekg_agent_require_plugin_files();

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

function literature_context(array $citations): array
{
    return [
        'question' => 'What literature supports the L1HS disease association?',
        'analysis' => ['intent' => 'literature', 'answer_language' => 'english'],
        'plugin_results' => [
            'Literature Plugin' => [
                'plugin_name' => 'Literature Plugin',
                'status' => $citations === [] ? 'empty' : 'ok',
                'citations' => $citations,
                'results' => ['citations' => $citations],
            ],
        ],
        'config' => ['deepseek_model' => 'deepseek-chat'],
    ];
}

$llmWithoutCredentials = new TekgAgentLlmClient([
    'deepseek_key' => '',
    'dashscope_key' => '',
    'llm_relay_url' => '',
]);
$plugin = new TekgAgentLiteratureReadingPlugin($llmWithoutCredentials, ['deepseek_model' => 'deepseek-chat']);

$citations = [[
    'source' => 'pubmed',
    'pmid' => '12345',
    'title' => 'LINE-1 activity in cancer',
    'journal' => 'Example Journal',
    'year' => '2024',
    'url' => 'https://pubmed.ncbi.nlm.nih.gov/12345/',
    'abstract_summary' => 'The abstract reports an association between LINE-1 activity and cancer.',
]];
$fallback = $plugin->run(literature_context($citations));
assert_same('partial', $fallback['status'], 'unavailable LLM with usable citations is partial');
assert_same('metadata_fallback', $fallback['results']['generation_mode'] ?? null, 'fallback mode is explicit');
assert_same([], $fallback['results']['supported_claims'] ?? null, 'title fallback does not fabricate supported claims');
assert_same([], $fallback['results']['claim_clusters'] ?? null, 'title fallback does not fabricate claim clusters');
assert_same(1, count($fallback['results']['metadata_summary'] ?? []), 'fallback preserves citation metadata for inspection');
assert_true($fallback['errors'] !== [], 'fallback exposes a visible synthesis warning');
assert_true(str_contains(implode(' ', $fallback['errors']), 'LLM synthesis'), 'fallback warning identifies unavailable LLM synthesis');
assert_same('none', $fallback['evidence_items'][0]['support_strength'] ?? null, 'fallback record is diagnostic rather than synthesized evidence');
assert_same(true, PluginResultContract::validate('Literature Reading Plugin', $fallback)['ok'], 'fallback result satisfies native contract');
$fallbackUi = PluginResultProjection::forUi($fallback);
assert_same('metadata_fallback', $fallbackUi['raw_result']['generation_mode'] ?? null, 'UI projection exposes degraded generation mode');

$fixtureLlm = new TekgAgentLlmClient([
    'agent_test_mode' => true,
    'agent_json_fixtures' => [
        'literature_reading' => [
            'claim_clusters' => [[
                'claim' => 'LINE-1 activity is associated with cancer in the supplied abstract.',
                'summary' => 'The supplied abstract reports an association.',
                'citations' => ['12345'],
            ]],
            'supported_claims' => ['LINE-1 activity is associated with cancer in the supplied abstract.'],
            'conflicting_claims' => [],
            'missing_evidence' => ['Full text was not supplied.'],
        ],
    ],
]);
$fixturePlugin = new TekgAgentLiteratureReadingPlugin($fixtureLlm, ['deepseek_model' => 'deepseek-chat']);
$synthesized = $fixturePlugin->run(literature_context($citations));
assert_same('ok', $synthesized['status'], 'valid LLM synthesis is ok');
assert_same('llm', $synthesized['results']['generation_mode'] ?? null, 'valid synthesis mode is explicit');
assert_same(1, count($synthesized['results']['claim_clusters'] ?? []), 'valid synthesis preserves claim clusters');
assert_same('medium', $synthesized['evidence_items'][0]['support_strength'] ?? null, 'citation count alone does not elevate synthesis to high support');
assert_same(true, PluginResultContract::validate('Literature Reading Plugin', $synthesized)['ok'], 'valid synthesis satisfies native contract');

$empty = $plugin->run(literature_context([]));
assert_same('empty', $empty['status'], 'no citations remains empty');
assert_same('none', $empty['results']['generation_mode'] ?? null, 'empty result states that synthesis did not run');
assert_same([], $empty['errors'], 'empty input is not an execution error');
assert_same(true, PluginResultContract::validate('Literature Reading Plugin', $empty)['ok'], 'empty result satisfies native contract');

echo "Literature Reading fallback contract tests passed.\n";
