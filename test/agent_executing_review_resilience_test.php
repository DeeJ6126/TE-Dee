<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/../api/agent/contracts/NodeLlmResult.php';
tekg_agent_require_academic_agent_service();

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

$service = new TekgAcademicAgentService(['agent_test_mode' => true]);
$reflection = new ReflectionClass($service);

assert_true($reflection->hasMethod('shouldContinueAfterExecutingReviewFailure'), 'AcademicAgentService exposes a review failure policy helper');
assert_true($reflection->hasMethod('applyExecutingReviewFailureCaveat'), 'AcademicAgentService exposes a review failure caveat helper');
assert_true($reflection->hasMethod('toolPayloadForUi'), 'AcademicAgentService exposes tool payload builder');
assert_true($reflection->hasMethod('executingReviewRequired'), 'AcademicAgentService exposes selective review policy');
assert_true($reflection->hasMethod('markExecutingReviewNotRequired'), 'AcademicAgentService records deterministic review skips');

$policyMethod = $reflection->getMethod('shouldContinueAfterExecutingReviewFailure');
$caveatMethod = $reflection->getMethod('applyExecutingReviewFailureCaveat');
$toolPayloadMethod = $reflection->getMethod('toolPayloadForUi');
$reviewRequiredMethod = $reflection->getMethod('executingReviewRequired');
$markNotRequiredMethod = $reflection->getMethod('markExecutingReviewNotRequired');

foreach (['Entity Resolver', 'Site Navigator Plugin', 'Tree Plugin', 'Genome Plugin', 'Citation Resolver'] as $pluginName) {
    assert_same(false, $reviewRequiredMethod->invoke($service, $pluginName, ['status' => 'ok', 'evidence_items' => []]), "{$pluginName} skips LLM review");
}
foreach (['Graph Plugin', 'Graph Analytics Plugin', 'Cypher Explorer Plugin', 'Literature Plugin', 'Literature Reading Plugin', 'Expression Plugin'] as $pluginName) {
    assert_same(true, $reviewRequiredMethod->invoke($service, $pluginName, ['status' => 'ok', 'evidence_items' => []]), "{$pluginName} keeps LLM review");
}
assert_same(false, $reviewRequiredMethod->invoke($service, 'Sequence Plugin', [
    'status' => 'ok',
    'evidence_items' => [tekg_agent_make_evidence_item('Sequence Plugin', 'Exact sequence record.', 'L1HS', 'high', [], [], ['evidence_type' => 'sequence_record'])],
]), 'exact sequence retrieval skips LLM review');
assert_same(true, $reviewRequiredMethod->invoke($service, 'Sequence Plugin', [
    'status' => 'ok',
    'evidence_items' => [tekg_agent_make_evidence_item('Sequence Plugin', 'Keyword hint.', 'L1HS', 'low', [], [], ['evidence_type' => 'structure_hint', 'quality_flags' => ['keyword_derived']])],
]), 'interpretive sequence hints keep LLM review');
assert_same(false, $reviewRequiredMethod->invoke($service, 'Graph Plugin', ['status' => 'empty', 'evidence_items' => []]), 'empty plugin result skips LLM review');

$notRequired = $markNotRequiredMethod->invoke($service, ['plugin_name' => 'Genome Plugin', 'status' => 'ok'], 'Deterministic retrieval does not require interpretation.');
assert_same('not_required', $notRequired['executing_review_status'] ?? null, 'skipped review status is explicit');
assert_true(str_contains($notRequired['executing_review_reason'] ?? '', 'Deterministic retrieval'), 'skipped review reason is explicit');

$reviewFailure = new NodeLlmResult(
    'executing',
    '',
    null,
    false,
    ['llm: stage=executing provider=deepseek model=deepseek-v4-flash: Relay returned an empty response.'],
    'tool_execution_review.v1'
);

$okPluginResult = [
    'plugin_name' => 'Literature Reading Plugin',
    'status' => 'ok',
    'display_summary' => 'Synthesized 3 reviewed papers into 2 supported claims.',
    'query_summary' => 'Literature reading succeeded.',
    'evidence_items' => [
        [
            'claim' => 'LINE-1 activation has literature support in Alzheimer disease contexts.',
            'citations' => ['pmid:123'],
        ],
    ],
    'citations' => [
        ['id' => 'pmid:123', 'title' => 'LINE-1 and neurodegeneration evidence'],
    ],
    'result_counts' => ['reviewed' => 3],
    'errors' => [],
];

assert_same(
    true,
    $policyMethod->invoke($service, $okPluginResult, $reviewFailure),
    'ExecutingReview LLM failure is non-fatal when the plugin body returned ok evidence'
);

$withCaveat = $caveatMethod->invoke($service, $okPluginResult, $reviewFailure);
assert_same('ok', (string)($withCaveat['status'] ?? ''), 'Plugin status remains ok after review caveat');
assert_true((array)($withCaveat['evidence_items'] ?? []) !== [], 'Plugin evidence remains available after review caveat');
assert_true((array)($withCaveat['citations'] ?? []) !== [], 'Plugin citations remain available after review caveat');
assert_true(in_array('review_failed', (array)($withCaveat['warnings'] ?? []), true), 'Plugin result records review_failed warning');
assert_true(str_contains((string)($withCaveat['executing_review_status'] ?? ''), 'review_failed'), 'Plugin result records review_failed status');
assert_true(str_contains(implode(' ', (array)($withCaveat['caveats'] ?? [])), 'ExecutingReview unavailable'), 'Plugin result carries ExecutingReview unavailable caveat');

$toolPayload = $toolPayloadMethod->invoke($service, $withCaveat);
assert_true(in_array('review_failed', (array)($toolPayload['warnings'] ?? []), true), 'Tool result event payload carries review_failed warning');
assert_same('review_failed', (string)($toolPayload['executing_review_status'] ?? ''), 'Tool result event payload carries review_failed status');
assert_true(str_contains(implode(' ', (array)($toolPayload['caveats'] ?? [])), 'ExecutingReview unavailable'), 'Tool result event payload carries ExecutingReview unavailable caveat');

$failedPluginResult = $okPluginResult;
$failedPluginResult['status'] = 'error';
$failedPluginResult['errors'] = ['Literature Reading Plugin failed to parse source papers.'];

assert_same(
    false,
    $policyMethod->invoke($service, $failedPluginResult, $reviewFailure),
    'ExecutingReview policy does not convert a real plugin failure into success'
);

echo "Agent executing review resilience tests passed.\n";
