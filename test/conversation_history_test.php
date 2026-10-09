<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/orchestrator/ConversationMemory.php';

function history_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function history_true(bool $value, string $message): void
{
    history_same(true, $value, $message);
}

function history_append(array $memory, string $question, string $answer, array $references = []): array
{
    return ConversationMemory::appendCompletedTurn($memory, 'agent', $question, 'Resolved L1HS question.', $answer, [
        'intent' => 'sequence',
        'normalized_entities' => [['canonical_label' => 'L1HS']],
        'reasoning_content' => 'DO_NOT_STORE_PRIVATE_REASONING',
        'tool_calls' => [['id' => 'DO_NOT_STORE_TOOL_CALL']],
        'plugin_results' => ['DO_NOT_STORE_PLUGIN_PAYLOAD'],
    ], $references);
}

$isolated = ConversationMemory::historyView(tekg_agent_default_session_memory());
history_same([], $isolated['messages'], 'A new session cannot inherit another session\'s history.');
history_same(0, $isolated['retained_turns'], 'A new session has no complete turns.');
history_same(false, $isolated['legacy_summary_only'], 'A new session is not a legacy summary session.');

$legacy = array_replace(tekg_agent_default_session_memory(), ['recent_turns' => [[
    'original_question' => 'Old short question', 'answer_summary' => 'Old partial summary',
]]]);
$legacyView = ConversationMemory::historyView($legacy);
history_same([], $legacyView['messages'], 'A partial legacy summary never becomes a complete assistant answer.');
history_same(true, $legacyView['legacy_summary_only'], 'Missing original legacy history is explicit.');

// Preserve user material after the old 500-character question and 800-character answer cutoffs.
$question = "Please analyze this pasted material:\n\n" . str_repeat('中文用户材料；', 5000) . "\nMATERIAL_TAIL_731\n";
$answer = "### Exact earlier answer\n\n" . str_repeat('Evidence discussion. ', 1000) . "\nANSWER_TAIL_842\n";
$memory = history_append($legacy, $question, $answer, [
    'request_id' => 'run-abc_123', 'run_id' => 'run-abc_123',
    'evidence_ids' => ['evidence_a', 'evidence_a', 'evidence_b', ['payload' => 'DO_NOT_STORE_NESTED']],
    'citation_ids' => ['PMID:12345'], 'pmids' => [12345, '67890', 'not-a-pmid'],
    'urls' => ['https://pubmed.ncbi.nlm.nih.gov/12345/', 'https://pubmed.ncbi.nlm.nih.gov/12345/',
        'https://user:password@example.org/', 'file:///private/path', '/tedee/agent.php'],
    'api_key' => 'DO_NOT_STORE_API_KEY', 'reasoning_content' => 'DO_NOT_STORE_REFERENCE_REASONING',
    'messages' => [['role' => 'tool', 'content' => 'DO_NOT_STORE_REFERENCE_TOOL']],
]);
$view = ConversationMemory::historyView($memory);
history_same([
    ['role' => 'user', 'content' => $question],
    ['role' => 'assistant', 'content' => $answer],
], $view['messages'], 'The exact full text and formatting survive a completed turn.');
history_same(strlen($question) + strlen($answer), $view['total_bytes'], 'The budget counts actual UTF-8 bytes.');
history_same(2, $memory['conversation_version'], 'Full dialogue persistence uses a new schema version.');
history_same(false, $view['legacy_summary_only'], 'A new full turn remains separate from old partial summaries.');
history_same(['evidence_a', 'evidence_b'], $view['evidence_references'][0]['evidence_ids'], 'References are bounded, scalar and deduplicated.');
history_same(['12345', '67890'], $view['evidence_references'][0]['pmids'], 'Only valid PMID pointers survive.');
history_same(['https://pubmed.ncbi.nlm.nih.gov/12345/'], $view['evidence_references'][0]['urls'], 'Source links are unique HTTP(S) URLs without embedded credentials.');
history_true(str_contains($view['history_notice'], 'not verified scientific evidence'), 'Earlier answer text carries an explicit provenance boundary.');
$encoded = json_encode($memory['dialogue_turns'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
history_true(!str_contains($encoded, 'DO_NOT_STORE'), 'Analysis, private reasoning, tool messages and unknown reference fields never enter complete history.');
history_true(tekg_agent_strlen($memory['recent_turns'][1]['original_question']) <= 500, 'The resolver\'s separate bounded summary remains compatible.');

// This is also the exact serialization used for persisted session memory.
$roundTrip = json_decode(json_encode($memory, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
history_same($view['messages'], ConversationMemory::historyView($roundTrip)['messages'], 'Full text survives a session JSON round trip.');

$many = [];
for ($i = 1; $i <= 14; $i++) {
    $many = history_append($many, "Question {$i}", "Answer {$i}");
}
$manyView = ConversationMemory::historyView($many);
history_same(12, $manyView['retained_turns'], 'Exactly 12 complete turns remain.');
history_same(2, $manyView['trimmed_turns'], 'Older whole-turn eviction is counted.');
history_same(0, $manyView['omitted_turns'], 'Valid count-based eviction is not reported as an omitted oversized turn.');
history_same('Question 3', $manyView['messages'][0]['content'], 'Whole oldest user/assistant pairs are evicted together.');
history_same('Answer 14', $manyView['messages'][23]['content'], 'The newest complete answer stays intact.');
history_same(3, count($many['recent_turns']), 'Three-turn entity resolver summaries remain bounded.');

$budget = [];
$large = str_repeat('x', 349999);
foreach (['A', 'B', 'C', 'D'] as $label) {
    $budget = history_append($budget, $label, $large);
}
$budgetView = ConversationMemory::historyView($budget);
history_same(2, $budgetView['retained_turns'], 'The byte budget evicts complete pairs before the turn limit.');
history_same(2, $budgetView['trimmed_turns'], 'Byte-based evictions are counted.');
history_same('C', $budgetView['messages'][0]['content'], 'Byte eviction keeps the most recent whole turns.');
history_same(700000, $budgetView['total_bytes'], 'The retained complete text fits the byte budget.');
history_same($large, $budgetView['messages'][3]['content'], 'A long answer is never silently shortened to fit.');

$priorMessages = $budgetView['messages'];
$oversized = history_append($budget, 'Huge', str_repeat('z', ConversationMemory::MAX_DIALOGUE_TURN_BYTES));
$oversizedView = ConversationMemory::historyView($oversized);
history_same($priorMessages, $oversizedView['messages'], 'An oversized new turn cannot silently replace or truncate prior complete turns.');
history_same('omitted', $oversizedView['last_turn_status'], 'The caller can report an omitted complete turn to the user.');
history_same('turn_exceeds_byte_limit', $oversizedView['last_omission_reason'], 'The oversized omission has an actionable reason.');
history_same(1, $oversizedView['omitted_turns'], 'An omitted oversized turn is counted once.');
$afterOmission = history_append($oversized, 'Next', 'Small answer');
history_same(1, ConversationMemory::historyView($afterOmission)['omitted_turns'], 'Reading and appending history do not repeatedly count a prior omission.');
history_same('retained', ConversationMemory::historyView($afterOmission)['last_turn_status'], 'A subsequent valid turn clears the latest omission status.');

$invalid = history_append([], "Invalid \xFF material", 'Valid answer');
history_same([], ConversationMemory::historyView($invalid)['messages'], 'Invalid UTF-8 cannot silently disappear during JSON encoding.');
history_same('invalid_utf8', ConversationMemory::historyView($invalid)['last_omission_reason'], 'Invalid UTF-8 is reported as an explicit omission.');

$tampered = ['dialogue_turns' => [[
    'mode' => 'agent', 'original_question' => 'Real question', 'answer' => 'Real answer',
    'reasoning_content' => 'DO_NOT_STORE_UNEXPECTED_REASONING',
    'tool_calls' => [['id' => 'DO_NOT_STORE_UNEXPECTED_TOOL']],
    'evidence_references' => ['request_id' => '../other-session', 'pmids' => [123]],
], ['messages' => [['role' => 'tool', 'content' => 'DO_NOT_STORE_INCOMPLETE_TURN']]]]];
$clean = history_append($tampered, 'Current question', 'Current answer');
history_same(1, ConversationMemory::historyView($clean)['omitted_turns'], 'Malformed incomplete persisted entries are excluded and counted.');
history_true(!str_contains(json_encode($clean['dialogue_turns'], JSON_THROW_ON_ERROR), 'DO_NOT_STORE'), 'Persisted unapproved fields are discarded before a new append.');
history_true(!isset(ConversationMemory::historyView($clean)['evidence_references'][0]['request_id']), 'Source pointers cannot contain filesystem traversal.');

$urls = [];
for ($i = 0; $i < 30; $i++) {
    $urls[] = "https://example.org/paper/{$i}";
}
$urlBounds = ConversationMemory::historyView(history_append([], 'Q', 'A', ['urls' => array_merge([
    'https://example.org/' . str_repeat('x', 2048), 'https://example.org/with space',
], $urls)]));
history_same(24, count($urlBounds['evidence_references'][0]['urls']), 'Source URL pointers have a fixed count budget.');
history_same('https://example.org/paper/0', $urlBounds['evidence_references'][0]['urls'][0], 'Overlong or malformed links are excluded, not truncated.');

echo "Conversation history tests passed.\n";
