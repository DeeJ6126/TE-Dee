<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';
tekg_agent_require_deepthink_service();

$citation = ['pmid' => '12345', 'title' => 'Retrieved L1HS cancer observation', 'url' => 'https://pubmed.ncbi.nlm.nih.gov/12345/'];
$disease = tekg_agent_make_evidence_item('Graph Plugin', 'The supplied graph links L1HS to Cancer.', 'L1HS', 'medium', [],
    ['title' => 'Cancer'], ['citations' => [$citation], 'evidence_type' => 'graph_relation']);
$function = tekg_agent_make_evidence_item('Graph Plugin', 'The supplied graph links L1HS to Insertional mutagenesis.', 'L1HS', 'medium', [],
    ['title' => 'Insertional mutagenesis'], ['citations' => [$citation], 'evidence_type' => 'graph_relation']);
$plugin = new WrapperFixturePlugin('Graph Plugin', [
    'results' => ['rows' => [
        ['source_name' => 'L1HS', 'target_type' => 'Disease', 'target_name' => 'Cancer', 'relation_type' => 'BIO_RELATION', 'pmids' => ['12345']],
        ['source_name' => 'L1HS', 'target_type' => 'Function', 'target_name' => 'Insertional mutagenesis', 'relation_type' => 'BIO_RELATION', 'pmids' => ['12345']],
    ]],
    'evidence_items' => [$disease, $function], 'citations' => [$citation],
]);

// Both synthesis and explicit listing use the actual native writer. There is
// no deterministic list-answer branch when a model call fails.
foreach (['请整合 L1HS 与 Cancer 的信息。', '列出 L1HS 的全部关系。'] as $question) {
    $writerCalled = false;
    $modelAnswer = '现有图谱记录将 L1HS 与 Cancer、Insertional mutagenesis 相关联；这些记录不足以单独认定因果关系，也不能视为全部关系（PMID 12345）。';
    $writer = static function(array $messages, array $tools, array $options) use (&$writerCalled, $modelAnswer): array {
        $writerCalled = true;
        wrapper_same('none', $options['tool_choice'], 'Relationship synthesis uses the evidence-only final writer.');
        $history = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        wrapper_assert(str_contains($history, 'Cancer') && str_contains($history, 'Insertional mutagenesis'), 'Writer receives both retrieved relation categories.');
        wrapper_assert(str_contains($history, 'supplied PMIDs/URLs'), 'Writing limits citations to returned original sources.');
        wrapper_assert(str_contains($history, 'not calibrated biological strength or causality'), 'Graph relations are not treated as causal strength.');
        return wrapper_turn([], $modelAnswer);
    };
    $service = new TekgDeepThinkService(wrapper_config([
        wrapper_turn([wrapper_call('graph', 'graph_lookup', ['te' => ['L1HS'], 'target_types' => ['Disease', 'Function']])]),
        wrapper_finish(), $writer,
    ]));
    wrapper_inject($service, ['Graph Plugin' => $plugin]);
    $session = 'native-relations-' . bin2hex(random_bytes(8));
    try {
        $response = $service->handle(['question' => $question, 'language' => 'chinese', 'session_id' => $session]);
        wrapper_assert(!$response['failed'] && $writerCalled, 'The actual writer completes the relationship answer: ' . ($response['failure_reason'] ?? 'writer was not invoked'));
        wrapper_same($modelAnswer, $response['answer'], 'The workflow publishes the validated model synthesis.');
        wrapper_same(['12345'], $response['integrity_report']['final']['cited_pmids'], 'The final citation belongs to retrieved evidence.');
        wrapper_assert(!str_contains($response['answer'], 'Graph Plugin') && !str_contains($response['answer'], 'BIO_RELATION'), 'Public prose avoids internal graph vocabulary.');
    } finally { wrapper_cleanup($session); }
}

$invalidCitation = new TekgDeepThinkService(wrapper_config([
    wrapper_turn([wrapper_call('graph', 'graph_lookup', ['te' => ['L1HS']])]), wrapper_finish(),
    wrapper_turn([], 'The relationship is reported (PMID 999999).'),
    wrapper_turn([], 'The relationship is reported (PMID 999999).'),
]));
wrapper_inject($invalidCitation, ['Graph Plugin' => $plugin]);
$response = $invalidCitation->handle(['question' => 'Summarize L1HS graph relationships.', 'language' => 'english']);
wrapper_assert($response['failed'] && $response['answer'] === '', 'Invented citations are rejected after a bounded repair rather than returned.');

$unavailableWriter = new TekgDeepThinkService(wrapper_config([
    wrapper_turn([wrapper_call('graph', 'graph_lookup', ['te' => ['L1HS']])]), wrapper_finish(),
    ['ok' => false, 'error' => 'Fixture writer timeout.', 'error_type' => 'timeout'],
]));
wrapper_inject($unavailableWriter, ['Graph Plugin' => $plugin]);
$response = $unavailableWriter->handle(['question' => 'List all L1HS graph relationships.', 'language' => 'english']);
wrapper_assert($response['failed'] && $response['answer'] === '', 'Writer failure never activates the retired deterministic full-list answer.');

echo "DeepThink native relationship synthesis tests passed.\n";
