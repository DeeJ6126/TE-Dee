<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';
tekg_agent_require_deepthink_service();

// Synthetic bases test the delivery contract against the exact plugin record;
// this fixture does not assert a biological sequence from outside the database.
$bases = str_repeat('ACGT', 1516);
$length = strlen($bases);
$source = ['source' => 'repbase', 'title' => 'Repbase Reports entry for L1HS', 'journal' => 'Repbase Reports', 'year' => '2012'];
$item = tekg_agent_make_evidence_item('Sequence Plugin', 'L1HS maps to a Repbase-backed consensus record of ' . $length . ' bp. The stored consensus sequence is available for exact FASTA delivery.',
    'L1HS', 'medium', ['length' => $length], ['title' => 'L1HS'], ['citations' => [$source], 'evidence_type' => 'sequence_record']);
$material = [
    'plugin_name' => 'Sequence Plugin', 'status' => 'ok', 'results' => ['matched_records' => [[
        'entity_label' => 'L1HS', 'matched_alias' => 'L1HS', 'alias_mode' => 'strict', 'repbase_name' => 'L1HS', 'length' => $length,
        'entry' => ['name' => 'L1HS', 'length' => $length, 'sequence' => $bases],
    ]]],
    'result_counts' => ['matched_records' => 1], 'evidence_items' => [$item], 'citations' => [$source],
    'display_details' => ['preview_items' => [], 'citations' => [$source], 'full_sequences' => [['label' => 'L1HS', 'length' => $length, 'sequence' => $bases]]],
];
$plugin = new WrapperFixturePlugin('Sequence Plugin', $material);

foreach ([false, true] as $full) {
    $question = $full ? 'Provide the complete consensus sequence of L1HS in FASTA format and its source.' : 'What is the consensus length and evidence source of L1HS?';
    $writerCalled = false;
    $writer = static function(array $messages, array $tools, array $options) use (&$writerCalled, $length, $full): array {
        $writerCalled = true;
        wrapper_same('none', $options['tool_choice'], 'Even a local sequence lookup requires validated final model writing.');
        $prompt = $messages[array_key_last($messages)]['content'];
        wrapper_assert(str_contains($prompt, (string)$length) && str_contains($prompt, 'Repbase'), 'Writer receives the retrieved length and provenance.');
        if ($full) {
            wrapper_assert(str_contains($prompt, 'sequence_records') && str_contains($prompt, '"complete":true'), 'Writer is explicitly told verified stored bases will be delivered.');
        }
        return wrapper_turn([], 'The L1HS consensus record is ' . $length . ' bp in the Repbase-aligned library.'
            . ($full ? " Its exact FASTA follows.\n\n```fasta\n>model-generated\nNNNN\n```" : ''));
    };
    $service = new TekgDeepThinkService(wrapper_config([
        wrapper_turn([wrapper_call('sequence', 'sequence_lookup', ['te' => ['L1HS'], 'full_sequence' => $full])]),
        wrapper_finish(), $writer,
    ]));
    wrapper_inject($service, ['Sequence Plugin' => $plugin]);
    $session = 'native-sequence-' . bin2hex(random_bytes(8));
    try {
        $response = $service->handle(['question' => $question, 'language' => 'english', 'session_id' => $session]);
        wrapper_assert(!$response['failed'] && $writerCalled, 'The native sequence workflow completes with actual writer invocation.');
        wrapper_assert(str_contains($response['answer'], $length . ' bp') && str_contains($response['answer'], 'Repbase'), 'Final answer preserves exact retrieved length and source.');
        if ($full) {
            wrapper_assert(preg_match('/```fasta\n>[^\n]*\n([^`]+)```/', $response['answer'], $match) === 1, 'An explicit full-sequence request receives FASTA.');
            $delivered = preg_replace('/\s+/', '', $match[1]);
            wrapper_same($bases, $delivered, 'The delivered full sequence matches the original plugin record byte for byte.');
            wrapper_same(hash('sha256', $bases), hash('sha256', $delivered), 'Sequence integrity hash is preserved.');
            wrapper_assert(!str_contains($response['answer'], 'model-generated'), 'Application replaces invented model nucleotide blocks.');
        } else {
            wrapper_assert(!str_contains($response['answer'], '```') && !str_contains($response['answer'], $bases), 'Length/source summary does not dump unrequested full sequence.');
        }
    } finally { wrapper_cleanup($session); }
}

$mismatch = $material;
$mismatch['results']['matched_records'][0]['entry']['length'] = $length + 1;
$delivery = SequenceDelivery::append('Retrieved record.', 'Provide the full sequence of L1HS.', 'english', $mismatch);
wrapper_assert(!str_contains($delivery, '```fasta') && str_contains($delivery, 'does not match its declared length'), 'Length mismatch prevents claims of exact complete FASTA.');
$broad = $material;
$broad['results']['matched_records'][0]['alias_mode'] = 'broad';
wrapper_assert(str_contains(SequenceDelivery::append('Retrieved record.', 'Provide the full sequence of L1HS.', 'english', $broad), 'not an exact sequence match'), 'Broad aliases retain the exact-subfamily limitation.');

$service = new TekgDeepThinkService(wrapper_config([
    wrapper_turn([wrapper_call('sequence', 'sequence_lookup', ['te' => ['L1HS'], 'full_sequence' => true])]), wrapper_finish(),
    ['ok' => false, 'error' => 'Fixture writer unavailable.', 'error_type' => 'transport_error'],
]));
wrapper_inject($service, ['Sequence Plugin' => $plugin]);
$failed = $service->handle(['question' => 'Provide the complete consensus sequence of L1HS in FASTA format.']);
wrapper_assert($failed['failed'] && $failed['answer'] === '', 'Model failure publishes no retired deterministic sequence answer.');
wrapper_same('Writing', $failed['failure_stage'], 'The tested failure occurs at the model writer after successful sequence retrieval.');

echo "DeepThink native sequence delivery tests passed.\n";
