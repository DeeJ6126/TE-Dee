<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';

function context_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function context_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$resultFile = __DIR__ . '/../api/agent/contracts/ConversationContextResult.php';
$resolverFile = __DIR__ . '/../api/agent/orchestrator/ConversationContextResolver.php';
context_assert_true(is_file($resultFile), 'ConversationContextResult implementation exists');
context_assert_true(is_file($resolverFile), 'ConversationContextResolver implementation exists');
tekg_agent_require_orchestrator_dependencies();

function context_memory(array $entities, array $turns = []): array
{
    return array_replace(tekg_agent_default_session_memory(), [
        'topic_entities' => $entities,
        'last_intent' => 'relationship',
        'last_mode' => 'agent',
        'recent_turns' => $turns,
    ]);
}

function context_resolver(mixed $fixture): ConversationContextResolver
{
    $config = [
        'agent_test_mode' => true,
        'agent_json_fixtures' => ['conversation_context' => $fixture],
    ];
    return new ConversationContextResolver(
        new TekgAgentEntityNormalizer(),
        new TekgAgentLlmClient($config)
    );
}

$standalone = context_resolver(null)->resolve(
    'Show expression evidence for L1HS.',
    'english',
    tekg_agent_default_session_memory(),
    'agent',
    'fixture-model'
);
context_assert_same('standalone', $standalone->status, 'Explicit standalone question bypasses inheritance');
context_assert_same($standalone->originalQuestion, $standalone->effectiveQuestion, 'Standalone text is unchanged');
context_assert_same(['L1HS'], $standalone->explicitEntities, 'Standalone result records explicit TE');

foreach ([
    'What is the consensus sequence length of L1HS, and what source supports it?',
    'Show representative genomic locations for SVA_F and explain whether they are examples or a complete locus set.',
    'Which genes and diseases are connected to HERVK-int, and what can these links establish?',
] as $selfContainedQuestion) {
    $selfContained = context_resolver(null)->resolve(
        $selfContainedQuestion,
        'english',
        tekg_agent_default_session_memory(),
        'agent',
        'fixture-model'
    );
    context_assert_same('standalone', $selfContained->status, 'A pronoun referring to an entity already named in the question stays standalone');
}

$memoryWithL1hs = context_memory(['L1HS'], [[
    'mode' => 'agent',
    'original_question' => 'Tell me about L1HS.',
    'effective_question' => 'Tell me about L1HS.',
    'answer_summary' => 'L1HS is an active human LINE-1 subfamily.',
    'entities' => ['L1HS'],
    'intent' => 'relationship',
]]);
$followUp = context_resolver([
    'status' => 'resolved_follow_up',
    'effective_question' => 'Show expression evidence for L1HS.',
    'inherited_entities' => ['L1HS'],
    'reason' => 'The possessive pronoun refers to the sole active TE.',
])->resolve('What about its expression?', 'english', $memoryWithL1hs, 'agent', 'fixture-model');
context_assert_same('resolved_follow_up', $followUp->status, 'English pronoun follow-up resolves');
context_assert_true(str_contains($followUp->effectiveQuestion, 'L1HS'), 'Effective question names inherited L1HS');
context_assert_same(['L1HS'], $followUp->inheritedEntities, 'Inherited entity is recorded');
context_assert_same('llm', $followUp->resolutionSource, 'Valid fixture is accepted as LLM resolution');

$zhFollowUp = context_resolver([
    'status' => 'resolved_follow_up',
    'effective_question' => '查询 AluY 的基因组位置。',
    'inherited_entities' => ['AluY'],
    'reason' => '“它”指向唯一的活跃 TE。',
])->resolve('那它的基因组位置呢？', 'chinese', context_memory(['AluY']), 'deepthink', 'fixture-model');
context_assert_same('resolved_follow_up', $zhFollowUp->status, 'Chinese pronoun follow-up resolves');
context_assert_true(str_contains($zhFollowUp->effectiveQuestion, 'AluY'), 'Chinese effective question names inherited TE');

$override = context_resolver([
    'status' => 'resolved_follow_up',
    'effective_question' => 'Wrong fixture output.',
    'inherited_entities' => ['L1HS'],
    'reason' => 'This fixture must be bypassed.',
])->resolve('What about AluY expression?', 'english', $memoryWithL1hs, 'agent', 'fixture-model');
context_assert_same('standalone', $override->status, 'Explicit new TE overrides old context');
context_assert_same('What about AluY expression?', $override->effectiveQuestion, 'Explicit override stays unchanged');
context_assert_same(['AluY'], $override->explicitEntities, 'Explicit override records AluY');

$comparison = context_resolver([
    'status' => 'resolved_follow_up',
    'effective_question' => 'Compare L1HS with SVA_F.',
    'inherited_entities' => ['L1HS'],
    'reason' => 'The backward pronoun refers to L1HS.',
])->resolve('Compare it with SVA_F.', 'english', $memoryWithL1hs, 'agent', 'fixture-model');
context_assert_same('resolved_follow_up', $comparison->status, 'Backward comparison resolves');
context_assert_same(['SVA_F'], $comparison->explicitEntities, 'Comparison preserves explicit new TE');
context_assert_same(['L1HS'], $comparison->inheritedEntities, 'Comparison carries prior TE');

$ambiguousMemory = context_memory(['L1HS', 'SVA_F'], [[
    'mode' => 'agent',
    'original_question' => 'Compare L1HS with SVA_F.',
    'effective_question' => 'Compare L1HS with SVA_F.',
    'answer_summary' => 'The answer compared both TEs.',
    'entities' => ['L1HS', 'SVA_F'],
    'intent' => 'comparison',
]]);
$ambiguous = context_resolver([
    'status' => 'needs_clarification',
    'effective_question' => '',
    'inherited_entities' => [],
    'reason' => 'Two active TEs are equally plausible.',
])->resolve('What about its expression?', 'english', $ambiguousMemory, 'deepthink', 'fixture-model');
context_assert_same('needs_clarification', $ambiguous->status, 'Ambiguous antecedent is not guessed');
context_assert_same(['L1HS', 'SVA_F'], $ambiguous->clarificationCandidates, 'Clarification lists active candidates');
context_assert_true(str_contains($ambiguous->clarificationMessage('english'), 'L1HS'), 'English clarification is user-facing');
context_assert_true(str_contains($ambiguous->clarificationMessage('chinese'), 'SVA_F'), 'Chinese clarification is user-facing');

$noContext = context_resolver(null)->resolve(
    'What about its expression?',
    'english',
    tekg_agent_default_session_memory(),
    'agent',
    'fixture-model'
);
context_assert_same('needs_clarification', $noContext->status, 'Pronoun without prior entity requires clarification');
context_assert_same([], $noContext->clarificationCandidates, 'No-context clarification has no invented candidate');

$malformedFallback = context_resolver(null)->resolve(
    'What about its expression?',
    'english',
    $memoryWithL1hs,
    'agent',
    'fixture-model'
);
context_assert_same('resolved_follow_up', $malformedFallback->status, 'Missing model output uses safe single-entity fallback');
context_assert_same('deterministic_fallback', $malformedFallback->resolutionSource, 'Fallback source is diagnostic');
context_assert_true(str_contains($malformedFallback->effectiveQuestion, 'L1HS'), 'Fallback inserts sole active entity');

$inventedEntity = context_resolver([
    'status' => 'resolved_follow_up',
    'effective_question' => 'Show expression evidence for HERVK-int.',
    'inherited_entities' => ['HERVK-int'],
    'reason' => 'Invented entity.',
])->resolve('What about its expression?', 'english', $memoryWithL1hs, 'agent', 'fixture-model');
context_assert_same('deterministic_fallback', $inventedEntity->resolutionSource, 'Invented entity output is rejected');
context_assert_same(['L1HS'], $inventedEntity->inheritedEntities, 'Fallback retains only allowed active entity');
context_assert_true(!str_contains($inventedEntity->effectiveQuestion, 'HERVK-int'), 'Invented entity never reaches effective question');

echo "Conversation context resolver tests passed.\n";
