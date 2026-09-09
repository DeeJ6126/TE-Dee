<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$memoryClass = __DIR__ . '/../api/agent/orchestrator/ConversationMemory.php';
assert_true(is_file($memoryClass), 'ConversationMemory implementation exists');
require_once $memoryClass;

$defaults = tekg_agent_default_session_memory();
assert_same([], $defaults['recent_turns'] ?? null, 'Default memory has no recent turns');
assert_same('', $defaults['last_mode'] ?? null, 'Default memory has no prior mode');
assert_same(1, $defaults['conversation_version'] ?? null, 'Conversation memory schema is versioned');

$memory = $defaults;
foreach (['L1HS', 'AluY', 'SVA_F', 'HERVK-int'] as $index => $entity) {
    $memory = ConversationMemory::appendCompletedTurn(
        $memory,
        $index % 2 === 0 ? 'agent' : 'deepthink',
        str_repeat("Question {$index} about {$entity} ", 40),
        str_repeat("Standalone question {$index} about {$entity} ", 50),
        str_repeat("Answer {$index} ", 120),
        [
            'intent' => 'relationship',
            'normalized_entities' => [
                ['canonical_label' => $entity, 'type' => 'TE'],
                ['label' => $entity, 'type' => 'TE'],
                ['name' => '', 'type' => 'TE'],
            ],
        ]
    );
}

assert_same(3, count($memory['recent_turns']), 'Only three recent turns remain');
assert_same('AluY', $memory['recent_turns'][0]['entities'][0] ?? null, 'Oldest retained turn is the second appended turn');
assert_same('HERVK-int', $memory['topic_entities'][0] ?? null, 'Latest successful entity becomes active');
assert_same('deepthink', $memory['last_mode'] ?? null, 'Latest successful mode is retained');
assert_same('relationship', $memory['last_intent'] ?? null, 'Latest successful intent is retained');
assert_true(tekg_agent_strlen((string)$memory['recent_turns'][2]['original_question']) <= 500, 'Original question is bounded');
assert_true(tekg_agent_strlen((string)$memory['recent_turns'][2]['effective_question']) <= 700, 'Effective question is bounded');
assert_true(tekg_agent_strlen((string)$memory['recent_turns'][2]['answer_summary']) <= 800, 'Answer summary is bounded');
assert_same(['HERVK-int'], $memory['recent_turns'][2]['entities'] ?? null, 'Turn entities are non-empty and deduplicated');
assert_true(!array_key_exists('plugin_results', $memory['recent_turns'][2]), 'Recent turn excludes plugin payloads');
assert_true(!array_key_exists('answer', $memory['recent_turns'][2]), 'Recent turn excludes the full answer');

$view = ConversationMemory::contextView($memory);
assert_same(
    ['recent_turns', 'active_entities', 'last_intent', 'last_mode'],
    array_keys($view),
    'Resolver context exposes only the bounded conversation view'
);
assert_same(['HERVK-int'], $view['active_entities'], 'Resolver view exposes active entities');
assert_true(!array_key_exists('citations', $view), 'Resolver view excludes citations');
assert_true(!array_key_exists('confirmed_claims', $view), 'Resolver view excludes evidence claims');
assert_true(!array_key_exists('tool_history', $view), 'Resolver view excludes tool history');

echo "Conversation memory tests passed.\n";
