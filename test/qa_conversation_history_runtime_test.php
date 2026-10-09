<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();
tekg_agent_require_deepthink_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

function conversation_runtime_config(array $fixtures): array
{
    // Exhausted fixtures must fail locally, never fall through to a live model call.
    return wrapper_config(array_merge($fixtures, array_fill(0, 20, [
        'ok' => false, 'error' => 'The bounded fixture sequence was exhausted.', 'error_type' => 'transport_error',
    ])));
}

function conversation_runtime_service(string $mode, array $fixtures, array $plugins): object
{
    $config = conversation_runtime_config($fixtures);
    $service = $mode === 'agent' ? new TekgAcademicAgentService($config) : new TekgDeepThinkService($config);
    wrapper_inject($service, $plugins);
    return $service;
}

function conversation_runtime_cleanup(string $session): void
{
    foreach ([tekg_agent_session_file($session), tekg_agent_conversation_file($session)] as $path) {
        if (is_file($path)) { unlink($path); }
    }
}

function conversation_runtime_history(array $messages, string $material, string $priorAnswer, string $currentQuestion, bool $initialResearch): void
{
    wrapper_same('system', $messages[0]['role'] ?? '', 'The server system policy precedes historical user material.');
    wrapper_same(['role' => 'user', 'content' => $material], $messages[1] ?? null, 'Complete user material remains in chronological dialogue order.');
    wrapper_same(['role' => 'assistant', 'content' => $priorAnswer], $messages[2] ?? null, 'The complete earlier answer survives without reasoning or tool-call fields.');
    wrapper_same('user', $messages[3]['role'] ?? '', 'The latest request follows the earlier user/assistant pair.');
    $request = json_decode((string)($messages[3]['content'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    wrapper_same($currentQuestion, $request['original_question'] ?? null, 'The current original question is preserved after all prior dialogue.');
    if ($initialResearch) {
        wrapper_same(4, count($messages), 'The first model request contains history and the latest question only.');
        wrapper_same($messages[3], $messages[array_key_last($messages)], 'The latest user request is last on the first research turn.');
        wrapper_assert(!str_contains(json_encode($messages, JSON_THROW_ON_ERROR), 'PRIVATE_FIRST_RESEARCH'), 'Private reasoning from the completed previous run cannot cross into a fresh request.');
        wrapper_assert(!in_array('tool', array_column($messages, 'role'), true), 'Previous runs\' native tool messages cannot cross into a fresh request.');
    }
}

function conversation_runtime_success(array $response, array $events, string $mode): void
{
    wrapper_same(false, $response['failed'], 'The actual service completes the conversational task: ' . (string)($response['failure_reason'] ?? ''));
    wrapper_terminal($events, false);
    $stages = wrapper_stages($events);
    wrapper_same('Understanding', $stages[0] ?? '', 'The conversation still starts in the actual Understanding phase.');
    wrapper_assert(in_array('Planning', $stages, true), 'The conversation emits its actual Planning phase.');
    wrapper_same('Writing', $stages[array_key_last($stages)] ?? '', 'The conversation reaches its actual Writing phase.');
    wrapper_same(true, $response['workflow_state']['complete'] ?? null, 'The final phase completes the workflow.');
    if ($mode === 'agent') {
        wrapper_assert(in_array('Integrating', $stages, true), 'Agent keeps its Integrating phase for supplied-text tasks.');
    }
}

$sessions = [];
try {
    foreach (['agent', 'deepthink'] as $firstMode) {
        $secondMode = $firstMode === 'agent' ? 'deepthink' : 'agent';
        $session = 'conversation-runtime-' . $firstMode . '-' . bin2hex(random_bytes(6));
        $isolatedSession = 'conversation-isolated-' . $firstMode . '-' . bin2hex(random_bytes(6));
        $sessions[] = $session;
        $sessions[] = $isolatedSession;
        $materialTail = 'MATERIALTAIL' . strtoupper($firstMode);
        $answerTail = 'ANSWERTAIL' . strtoupper($firstMode);
        $material = "Read and remember the following pasted material for later editing.\n\n"
            . str_repeat('This paragraph is supplied by the user and preserves author attribution and uncertainty. ', 35)
            . "\nThe author mentions L1HS and ORF2 as topics, and provides PMID 67890 as a source identifier.\n"
            . $materialTail;
        $firstAnswer = "Your supplied material mentions L1HS and ORF2, with the author-provided identifier PMID 67890.\n\n"
            . str_repeat('The wording is retained as supplied text, with author attribution and no independent verification. ', 20)
            . "\n" . $answerTail;
        wrapper_assert(strlen($material) > 500 && strlen($firstAnswer) > 800, 'The fixture exercises the old question and answer-summary cutoffs.');
        $unused = new WrapperFixturePlugin('Graph Plugin');
        $first = conversation_runtime_service($firstMode, [
            wrapper_turn([wrapper_call('first-finish', 'finish_research', ['reason' => 'The user supplied material for editing.'])], '', 'PRIVATE_FIRST_RESEARCH'),
            static function (array $messages, array $tools, array $options) use ($material, $firstAnswer): array {
                wrapper_same('none', $options['tool_choice'], 'A supplied-text task still reaches the real final writer.');
                $initialRequest = json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR);
                wrapper_same($material, $initialRequest['original_question'] ?? null, 'The initial long pasted material reaches the model intact.');
                return wrapper_turn([], $firstAnswer, 'PRIVATE_FIRST_WRITER');
            },
        ], ['Graph Plugin' => $unused])->handle(['question' => $material, 'session_id' => $session]);
        wrapper_same(false, $first['failed'], 'A supplied text mentioning real TE features is accepted without unnecessary retrieval.');
        wrapper_same($firstAnswer, $first['answer'], 'The first complete answer is retained, including its distant tail.');
        wrapper_same(0, $unused->runCount, 'Text-only work does not execute the available scientific plugin.');
        wrapper_same([], $first['used_plugins'], 'Text-only work reports zero executed plugins.');
        $firstView = ConversationMemory::historyView(tekg_agent_load_session_memory($session));
        wrapper_same(1, $firstView['retained_turns'], 'The successful long turn is persisted once.');
        wrapper_same($material, $firstView['messages'][0]['content'], 'Persistence preserves long user material after the old summary cutoff.');
        wrapper_same($firstAnswer, $firstView['messages'][1]['content'], 'Persistence preserves the whole assistant answer.');
        $publicMemory = (string)file_get_contents(tekg_agent_session_file($session));
        $privateMemory = (string)file_get_contents(tekg_agent_conversation_file($session));
        wrapper_assert(!str_contains($publicMemory, $materialTail) && !str_contains($publicMemory, $answerTail), 'Complete distant material is absent from the public legacy-summary file.');
        wrapper_assert(str_contains($privateMemory, $materialTail) && str_contains($privateMemory, $answerTail), 'Complete history is kept in the private conversation file.');
        wrapper_assert(!str_contains($privateMemory, 'PRIVATE_FIRST_') && !str_contains($privateMemory, 'tool_calls'), 'The persisted conversation has no private reasoning or tool protocol.');

        $followQuestion = 'Summarize the previous material and rewrite the previous answer in one short paragraph.';
        $followAnswer = "Your supplied text discusses L1HS and ORF2 with the author-provided identifier PMID 67890; its closing label is {$materialTail}. The previous response ends with {$answerTail}.";
        $researchChecked = false;
        $writerChecked = false;
        $follow = conversation_runtime_service($secondMode, [
            static function (array $messages, array $tools, array $options) use ($material, $firstAnswer, $followQuestion, &$researchChecked): array {
                conversation_runtime_history($messages, $material, $firstAnswer, $followQuestion, true);
                $researchChecked = true;
                return wrapper_finish('follow-finish');
            },
            static function (array $messages, array $tools, array $options) use ($material, $firstAnswer, $followQuestion, $followAnswer, &$writerChecked): array {
                conversation_runtime_history($messages, $material, $firstAnswer, $followQuestion, false);
                wrapper_same('none', $options['tool_choice'], 'The follow-up writer is reached with its full earlier material still available.');
                $writerChecked = true;
                return wrapper_turn([], $followAnswer);
            },
        ], ['Graph Plugin' => $unused]);
        $events = [];
        $followResponse = $follow->stream(['question' => $followQuestion, 'session_id' => $session], static function (array $event) use (&$events): void { $events[] = $event; });
        conversation_runtime_success($followResponse, $events, $secondMode);
        wrapper_same(true, $researchChecked && $writerChecked, 'Both real research and writing model requests receive full multi-turn material.');
        wrapper_same(0, $unused->runCount, 'A mode switch does not cause unnecessary scientific queries for a rewrite.');
        wrapper_same([], $followResponse['used_plugins'], 'A pure rewrite succeeds with zero plugin results.');
        wrapper_same(1, $followResponse['conversation_context']['retained_turns'] ?? null, 'The new mode reports the prior retained turn.');
        wrapper_same(2, $followResponse['conversation_context_after']['retained_turns'] ?? null, 'Cross-mode completion persists the second full turn.');

        // A new server session can see neither another session's pasted material nor its answer.
        $isolationChecked = false;
        $isolated = conversation_runtime_service($secondMode, [
            static function (array $messages) use ($materialTail, $answerTail, &$isolationChecked): array {
                $encoded = json_encode($messages, JSON_THROW_ON_ERROR);
                wrapper_assert(!str_contains($encoded, $materialTail) && !str_contains($encoded, $answerTail), 'An isolated session cannot inherit the other user\'s material.');
                wrapper_same(2, count($messages), 'An isolated request contains no historical pair.');
                $isolationChecked = true;
                return wrapper_finish('isolated-finish');
            },
            wrapper_turn([], 'Please provide the text you want me to summarize.'),
        ], ['Graph Plugin' => new WrapperFixturePlugin('Graph Plugin')])->handle(['question' => 'Summarize the previous material.', 'session_id' => $isolatedSession]);
        wrapper_same(false, $isolated['failed'], 'A request for missing text can complete as a clarification.');
        wrapper_same(true, $isolationChecked, 'Isolation is checked at the actual model request boundary.');

        $beforeFailure = ConversationMemory::historyView(tekg_agent_load_session_memory($session));
        $beforeFailureFile = (string)file_get_contents(tekg_agent_conversation_file($session));
        $failedWriter = conversation_runtime_service($secondMode, [
            wrapper_finish('failed-writer-finish'),
            ['ok' => false, 'error' => 'Fixture writer unavailable.', 'error_type' => 'transport_error'],
        ], ['Graph Plugin' => $unused]);
        $failureEvents = [];
        $failed = $failedWriter->stream(['question' => 'Rewrite the previous answer more briefly.', 'session_id' => $session], static function (array $event) use (&$failureEvents): void { $failureEvents[] = $event; });
        wrapper_same(true, $failed['failed'], 'An unavailable writer produces an actual failure.');
        wrapper_same('Writing', $failed['failure_stage'], 'The failure names the real writing phase.');
        wrapper_terminal($failureEvents, true);
        wrapper_same($beforeFailure['messages'], ConversationMemory::historyView(tekg_agent_load_session_memory($session))['messages'], 'Failed writing cannot append a full conversation turn.');
        wrapper_same($beforeFailureFile, (string)file_get_contents(tekg_agent_conversation_file($session)), 'Failed writing leaves the persisted full-history file unchanged.');

        // A supplied source identifier may be preserved during editing; invented identifiers still fail.
        $unknown = 'The rewritten text cites PMID 87654321.';
        $unknownService = conversation_runtime_service($secondMode, [wrapper_finish('unknown-finish'), wrapper_turn([], $unknown), wrapper_turn([], $unknown)], ['Graph Plugin' => $unused]);
        $unknownEvents = [];
        $unknownResponse = $unknownService->stream(['question' => 'Rewrite the previous answer as one sentence.', 'session_id' => $session], static function (array $event) use (&$unknownEvents): void { $unknownEvents[] = $event; });
        wrapper_same(true, $unknownResponse['failed'], 'Text transformations still reject fabricated source identifiers after one repair.');
        wrapper_same('Writing', $unknownResponse['failure_stage'], 'Unknown citations are rejected at the actual final-writing gate.');
        wrapper_terminal($unknownEvents, true);
        wrapper_same($beforeFailure['messages'], ConversationMemory::historyView(tekg_agent_load_session_memory($session))['messages'], 'Rejected citations do not contaminate future full conversation context.');

        // Prior conversational biology and source identifiers never substitute for a fresh scientific lookup.
        $scienceQuestion = 'Retrieve fresh experimental papers on L1HS and cancer.';
        $noLookup = conversation_runtime_service($secondMode, [
            wrapper_turn([], 'The earlier text already mentions L1HS.'),
            wrapper_turn([], 'The earlier source identifier is PMID 67890.'),
        ], ['Graph Plugin' => $unused]);
        $noLookupResponse = $noLookup->handle(['question' => $scienceQuestion, 'session_id' => $session]);
        wrapper_same(true, $noLookupResponse['failed'], 'A new scientific question cannot be answered solely from earlier user or assistant text.');
        wrapper_same('', $noLookupResponse['answer'], 'No unverified scientific fallback answer is published.');
        wrapper_same($beforeFailure['messages'], ConversationMemory::historyView(tekg_agent_load_session_memory($session))['messages'], 'An evidence-free scientific failure leaves full conversation history intact.');

        foreach ([
            'Rewrite this answer and find eQTL associations for LTR7A',
            'Rewrite the previous answer, then tell me the eQTL associations for LTR7A.',
        ] as $mixedQuestion) {
            $mixedService = conversation_runtime_service($secondMode, [
                wrapper_finish('mixed-finish'),
                wrapper_turn([], 'The earlier answer is rewritten and LTR7A has eQTL associations.'),
            ], ['Graph Plugin' => $unused]);
            $mixedEvents = [];
            $mixedResponse = $mixedService->stream(['question' => $mixedQuestion, 'session_id' => $session], static function (array $event) use (&$mixedEvents): void { $mixedEvents[] = $event; });
            wrapper_same(true, $mixedResponse['failed'], 'A mixed rewrite and new eQTL query cannot complete solely from previous prose: ' . $mixedQuestion);
            wrapper_same('Planning', $mixedResponse['failure_stage'], 'Missing evidence for a mixed scientific query is rejected before writing.');
            wrapper_terminal($mixedEvents, true);
            wrapper_same($beforeFailure['messages'], ConversationMemory::historyView(tekg_agent_load_session_memory($session))['messages'], 'A mixed-query failure cannot append an unsupported conversational answer.');
        }

        $missingMaterialSession = 'conversation-no-material-' . $firstMode . '-' . bin2hex(random_bytes(6));
        $sessions[] = $missingMaterialSession;
        $missingMaterial = conversation_runtime_service($secondMode, [
            wrapper_finish('missing-material-finish'),
            wrapper_turn([], 'L1HS has a biological mechanism that can be summarized without retrieved material.'),
        ], ['Graph Plugin' => $unused]);
        $missingEvents = [];
        $missingResponse = $missingMaterial->stream(['question' => 'Summarize this mechanism of L1HS', 'session_id' => $missingMaterialSession], static function (array $event) use (&$missingEvents): void { $missingEvents[] = $event; });
        wrapper_same(true, $missingResponse['failed'], 'Summarizing a mechanism without supplied text cannot bypass scientific retrieval.');
        wrapper_same('Planning', $missingResponse['failure_stage'], 'The missing-material scientific shortcut is rejected before writing.');
        wrapper_terminal($missingEvents, true);
        wrapper_same([], ConversationMemory::historyView(tekg_agent_load_session_memory($missingMaterialSession))['messages'], 'A new session cannot retain a fabricated summary of material it never received.');

        $freshGraph = new WrapperFixturePlugin('Graph Plugin');
        $oldIdentifier = 'The retrieved study reports an observation of L1HS in cancer (PMID 67890).';
        $oldCitationService = conversation_runtime_service($secondMode, [
            wrapper_turn([wrapper_call('fresh-graph-old-citation', 'graph_lookup', ['te' => ['L1HS'], 'disease' => 'cancer'])]),
            wrapper_finish('old-citation-finish'), wrapper_turn([], $oldIdentifier), wrapper_turn([], $oldIdentifier),
        ], ['Graph Plugin' => $freshGraph]);
        $oldCitationResponse = $oldCitationService->handle(['question' => $scienceQuestion, 'session_id' => $session]);
        wrapper_same(1, $freshGraph->runCount, 'The scientific follow-up actually retrieves fresh records.');
        wrapper_same(true, $oldCitationResponse['failed'], 'A PMID present only in prior user prose is not approved as a fresh scientific citation.');
        wrapper_same($beforeFailure['messages'], ConversationMemory::historyView(tekg_agent_load_session_memory($session))['messages'], 'A fresh lookup with an unsupported old citation cannot append an answer.');

        $verified = 'The retrieved study reports an observation of L1HS in cancer (PMID 12345).';
        $freshService = conversation_runtime_service($secondMode, [
            wrapper_turn([wrapper_call('fresh-graph', 'graph_lookup', ['te' => ['L1HS'], 'disease' => 'cancer'])]),
            wrapper_finish('fresh-finish'), wrapper_turn([], $verified),
        ], ['Graph Plugin' => $freshGraph]);
        $freshEvents = [];
        $freshResponse = $freshService->stream(['question' => $scienceQuestion, 'session_id' => $session], static function (array $event) use (&$freshEvents): void { $freshEvents[] = $event; });
        conversation_runtime_success($freshResponse, $freshEvents, $secondMode);
        wrapper_same(2, $freshGraph->runCount, 'Fresh scientific evidence is retrieved again for the valid run, not reused as prior assistant prose.');
        wrapper_assert(in_array('Executing', wrapper_stages($freshEvents), true), 'A real scientific lookup emits its actual execution phase.');
        wrapper_same(['Graph Plugin'], $freshResponse['used_plugins'], 'The validated scientific answer reports its actual retrieved source.');
        wrapper_same(3, $freshResponse['conversation_context_after']['retained_turns'] ?? null, 'Only the two successful text turns and valid scientific answer are retained.');

        // A no-pronoun sequence follow-up still has one unambiguous TE in prior context.
        $sequenceSession = 'conversation-fasta-' . $firstMode . '-' . bin2hex(random_bytes(6));
        $sessions[] = $sequenceSession;
        $sequenceMemory = ConversationMemory::appendCompletedTurn([], $firstMode, 'Show the consensus length and source of L1HS.', 'Show the consensus length and source of L1HS.', 'The previous lookup concerned L1HS consensus sequence.', [
            'intent' => 'sequence', 'normalized_entities' => [['type' => 'TE', 'canonical_label' => 'L1HS']],
        ]);
        tekg_agent_save_session_memory($sequenceSession, $sequenceMemory);
        $bases = str_repeat('ACGT', 1516);
        $sequencePlugin = new WrapperFixturePlugin('Sequence Plugin', [
            'results' => ['matched_records' => [[
                'entity_label' => 'L1HS', 'alias_mode' => 'strict',
                'entry' => ['name' => 'L1HS', 'length' => strlen($bases), 'sequence' => $bases],
            ]]],
            'evidence_items' => [tekg_agent_make_evidence_item('Sequence Plugin', 'The supplied L1HS consensus record contains the complete stored sequence.', 'L1HS', 'medium', [], ['title' => 'L1HS consensus record'], ['evidence_type' => 'sequence_record', 'provenance' => ['source' => 'test_fixture']])],
            'citations' => [], 'display_details' => ['preview_items' => [], 'citations' => []],
        ]);
        $fastaQuestion = '请给我完整序列，用FASTA格式。';
        $fastaConfig = conversation_runtime_config([
            static function (array $messages) use ($fastaQuestion): array {
                $current = json_decode($messages[array_key_last($messages)]['content'], true, 512, JSON_THROW_ON_ERROR);
                wrapper_same($fastaQuestion, $current['original_question'] ?? null, 'The short FASTA follow-up reaches the actual model as the latest request.');
                return wrapper_turn([wrapper_call('followup-sequence', 'sequence_lookup', ['te' => ['L1HS'], 'full_sequence' => true])]);
            },
            wrapper_finish('followup-sequence-finish'),
            wrapper_turn([], '已取得 L1HS 的记录共识序列，以下由应用输出原始 FASTA。'),
        ]);
        $fastaConfig['agent_json_fixtures'] = ['conversation_context' => [
            'status' => 'resolved_follow_up', 'effective_question' => '请给出 L1HS 完整共识序列，用FASTA格式。',
            'inherited_entities' => ['L1HS'], 'reason' => 'The previous completed turn has one unambiguous TE.',
        ]];
        $fastaService = $secondMode === 'agent' ? new TekgAcademicAgentService($fastaConfig) : new TekgDeepThinkService($fastaConfig);
        wrapper_inject($fastaService, ['Sequence Plugin' => $sequencePlugin]);
        $fastaEvents = [];
        $fastaResponse = $fastaService->stream(['question' => $fastaQuestion, 'session_id' => $sequenceSession], static function (array $event) use (&$fastaEvents): void { $fastaEvents[] = $event; });
        conversation_runtime_success($fastaResponse, $fastaEvents, $secondMode);
        wrapper_same(1, $sequencePlugin->runCount, 'Implicit sequence follow-up executes its fresh, explicitly scoped sequence retrieval.');
        wrapper_same('L1HS', $sequencePlugin->contexts[0]['analysis']['normalized_entities'][0]['canonical_label'] ?? null, 'The sequence plugin receives the unambiguous previously discussed TE.');
        wrapper_assert(!preg_match('/which TE|哪个\s*TE|请.*(?:明确|提供|指定).*TE/iu', $fastaResponse['answer']), 'The sequence follow-up does not ask the user to repeat the known TE name.');
        preg_match('/```fasta\n>[^\n]+\n(.*?)\n```/s', $fastaResponse['answer'], $fastaMatch);
        wrapper_same($bases, preg_replace('/\s+/', '', $fastaMatch[1] ?? ''), 'Implicit sequence follow-up delivers the complete retrieved bases unchanged.');
        wrapper_same(6064, strlen(preg_replace('/\s+/', '', $fastaMatch[1] ?? '')), 'The complete 6064 bp record is delivered without a partial excerpt.');
    }
} finally {
    foreach ($sessions as $session) { conversation_runtime_cleanup($session); }
}

echo "Conversation history service runtime tests passed.\n";
