<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';
tekg_agent_require_academic_agent_service();
tekg_agent_require_deepthink_service();

foreach (['zh-cn', 'zh_cn', '中文'] as $language) {
    wrapper_same('chinese', tekg_agent_detect_language(['language' => $language], 'English question'), 'Explicit Chinese language wins over English wording.');
}
wrapper_same('chinese', tekg_agent_detect_language(['question' => '中文问题']), 'Question heuristic detects Chinese when language is absent.');
wrapper_same('english', tekg_agent_detect_language(['question' => 'English question']), 'English question keeps the English default.');

// Exercise the real shared workflow rather than retired narrator/answer-plan
// methods. Native research and final writing must receive one chosen language.
foreach (['agent', 'deepthink'] as $mode) {
    foreach (['zh-cn' => 'chinese', 'english' => 'english'] as $requested => $expected) {
        $calls = 0;
        $history = [];
        $answer = $expected === 'chinese'
            ? '所提供的记录报告了 L1HS 与癌症的关联观察，不能据此认定因果关系（PMID 12345）。'
            : 'The supplied record reports an observation of L1HS in cancer, which does not establish causation (PMID 12345).';
        $capture = static function(array $messages, array $tools, array $options) use (&$calls, &$history, $expected, $answer): array {
            $history[] = $messages;
            wrapper_assert(str_contains($messages[0]['content'], 'Respond in ' . $expected), 'Native system prompt keeps the selected answer language.');
            if ($calls++ === 0) {
                wrapper_same('auto', $options['tool_choice'], 'Research uses native tools.');
                return wrapper_turn([wrapper_call('literature', 'literature_search', ['te' => ['L1HS'], 'disease' => 'Cancer'])], '', 'private-language-protocol');
            }
            wrapper_same('none', $options['tool_choice'], 'Final writing cannot invent another tool result.');
            wrapper_assert(str_contains($messages[array_key_last($messages)]['content'], 'final answer in ' . $expected), 'Final writing instruction explicitly keeps the chosen language.');
            return wrapper_turn([], $answer, 'private-language-synthesis');
        };
        $config = wrapper_config([$capture, wrapper_finish(), $capture]);
        $service = $mode === 'agent' ? new TekgAcademicAgentService($config) : new TekgDeepThinkService($config);
        wrapper_inject($service, ['Literature Plugin' => new WrapperFixturePlugin('Literature Plugin')]);
        $events = [];
        $session = 'native-language-' . bin2hex(random_bytes(8));
        try {
            $response = $service->stream(['question' => 'Find literature about L1HS and Cancer.', 'language' => $requested, 'session_id' => $session], static function(array $event) use (&$events): void { $events[] = $event; });
            wrapper_same(false, $response['failed'], 'Real native workflow succeeds with provided evidence.');
            wrapper_same($expected, $response['language'], 'Published answer uses the normalized language.');
            wrapper_same($answer, $response['answer'], 'The model-written answer remains intact.');
            wrapper_same('deepseek-flash', $response['model'], 'Both languages and modes share Flash.');
            wrapper_same(2, $calls, 'Research and final writing were actually invoked.');
            foreach ($events as $event) { wrapper_same($expected, $event['language'], 'Progress events carry the same selected language.'); }
            wrapper_assert(str_contains(json_encode($history, JSON_THROW_ON_ERROR), 'private-language-protocol'), 'Private protocol reasoning survives into final model history.');
            wrapper_assert(!str_contains(json_encode([$response, $events], JSON_THROW_ON_ERROR), 'private-language-'), 'Private reasoning stays out of the public answer and progress.');
            wrapper_assert(($response['integrity_report']['final']['cited_pmids'] ?? []) === ['12345'], 'Only the retrieved citation is used.');
            wrapper_terminal($events, false);
        } finally { wrapper_cleanup($session); }
    }
}

echo "Agent native workflow language tests passed.\n";
