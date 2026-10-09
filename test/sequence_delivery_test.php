<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/agent/contracts/SequenceDelivery.php';
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$sequence = str_repeat('ACGTRYSWKMBDHVN', 500);
$result = ['status' => 'ok', 'results' => ['matched_records' => [[
    'entity_label' => 'L1HS', 'alias_mode' => 'strict',
    'entry' => ['name' => 'L1HS', 'length' => strlen($sequence), 'sequence' => $sequence],
]]]];
$question = 'Please provide the complete consensus sequence of L1HS in FASTA format.';
$answer = SequenceDelivery::append("Retrieved record.\n```fasta\n>L1HS\nACGT...\n```", $question, 'english', $result);
preg_match('/```fasta\n>[^\n]+\n(.*?)\n```/s', $answer, $match);
$delivered = preg_replace('/\s+/', '', $match[1] ?? '');
check($delivered === $sequence, 'Retrieved bases must be delivered in full and unchanged.');
check(substr_count($answer, '```fasta') === 1, 'Model sequence block must be replaced, not duplicated.');
$modelFasta = "Record.\n```fasta\n>L1HS\nACGT…\n1. More bases omitted\n```";
$replacedFasta = SequenceDelivery::append($modelFasta, $question, 'english', $result);
check(substr_count($replacedFasta, '```fasta') === 1 && !str_contains($replacedFasta, 'More bases omitted'), 'Annotated or truncated model FASTA must not precede the authoritative record.');
$emptyFasta = SequenceDelivery::append($modelFasta, $question, 'english', ['status' => 'empty']);
check(!str_contains($emptyFasta, '```fasta'), 'Unavailable retrieval must not retain model-generated FASTA.');
check(SequenceDelivery::append('Summary.', 'What is the consensus length and evidence source of L1HS?', 'english', $result) === 'Summary.', 'Summary template must not dump sequence.');
$context = SequenceDelivery::writingContext([], $question, $result);
check(!isset($context['sequence_records'][0]['sequence']), 'Model receives metadata rather than bases to reproduce.');
check($context['sequence_records'][0]['sha256'] === hash('sha256', $sequence), 'Sequence provenance digest must match.');
$result['results']['matched_records'][0]['entry']['length']++;
check(!str_contains(SequenceDelivery::append('Record.', $question, 'english', $result), '```fasta'), 'Length mismatch must not be called complete.');
$result['results']['matched_records'][0]['entry']['length']--;
$result['results']['matched_records'][0]['alias_mode'] = 'broad';
check(str_contains(SequenceDelivery::append('Record.', $question, 'english', $result), 'broad alias'), 'Broad match must stay explicit.');
check(str_contains(SequenceDelivery::append('Record.', $question, 'english', ['status' => 'error']), 'unavailable'), 'Missing sequences must remain unavailable.');
check(SequenceDelivery::requested('请给出L1HS完整共识序列'), 'Chinese complete sequence request must be recognized.');
require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_plugin_files();
$plugin = new TekgAgentSequencePlugin();
$method = new ReflectionMethod($plugin, 'structureHints');
$hints = $method->invoke($plugin, ['description'=>'A non-LTR retrotransposon in the LINE family; environmental polymorphism.', 'keywords'=>[]]);
check(str_contains($hints,'NON-LTR') && str_contains($hints,'LINE') && !in_array('LTR',explode(', ',$hints),true), 'Non-LTR must not become an LTR hint.');
check(!str_contains($hints,'ENV') && !str_contains($hints,'POL'), 'Gene hints must match tokens, not arbitrary word substrings.');
$summaryContext = SequenceDelivery::writingContext([], 'Summarize L1HS sequence.', $result);
check($summaryContext['sequence_delivery']['requested'] === false, 'Summary writing explicitly knows no FASTA will be attached.');
echo "Sequence delivery tests passed.\n";
