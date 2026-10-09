<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

// Explicit model-selected tools replace the old fixed queue and plugin-once gate.
$statistics = new WrapperFixturePlugin('Graph Analytics Plugin');
$unusedLiterature = new WrapperFixturePlugin('Literature Plugin');
$unusedGraph = new WrapperFixturePlugin('Graph Plugin');
$minimal = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('statistics', 'graph_statistics', ['metric' => 'diseases_by_te_count'])]),
    wrapper_finish(),
    wrapper_turn([], 'The supplied graph record reports an observation involving cancer (PMID 12345). Graph connectivity does not measure biological association strength.'),
]));
wrapper_inject($minimal, ['Graph Analytics Plugin' => $statistics, 'Literature Plugin' => $unusedLiterature, 'Graph Plugin' => $unusedGraph]);
$minimalSession = 'native-routing-minimal-' . bin2hex(random_bytes(6));
$minimalResponse = $minimal->handle(['question' => 'Rank diseases connected to transposable elements.', 'session_id' => $minimalSession]);
wrapper_same(false, $minimalResponse['failed'], 'The selected graph statistics tool completes the question through final writing.');
wrapper_same(1, $statistics->runCount, 'A sufficient selected lookup executes once.');
wrapper_same(0, $unusedLiterature->runCount, 'No mandatory literature fan-out is introduced for a graph-only request.');
wrapper_same(0, $unusedGraph->runCount, 'No redundant fixed-neighborhood tool is automatically queued.');
wrapper_same('completed', $minimalResponse['tool_loop']['stop_reason'], 'Explicit finish stops retrieval before unrelated plugins.');
wrapper_cleanup($minimalSession);

$literature = new WrapperFixturePlugin('Literature Plugin');
$events = [];
$repeat = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('page0', 'literature_search', ['te' => ['L1HS']])]),
    wrapper_turn([wrapper_call('same-page', 'literature_search', ['limit' => 8, 'page' => 0, 'focus_keywords' => [], 'te' => 'L1HS'])]),
    wrapper_turn([wrapper_call('page1', 'literature_search', ['te' => ['L1HS'], 'page' => 1])]),
    wrapper_finish(),
    wrapper_turn([], 'The supplied paper reports an observation of L1HS in cancer (PMID 12345).'),
]));
wrapper_inject($repeat, ['Literature Plugin' => $literature]);
$repeatSession = 'native-routing-signatures-' . bin2hex(random_bytes(6));
$repeatResponse = $repeat->stream(['question' => 'Find additional L1HS literature.', 'session_id' => $repeatSession], static function (array $event) use (&$events): void { $events[] = $event; });
wrapper_same(false, $repeatResponse['failed'], 'Further evidence can be retrieved by changing explicit page parameters.');
wrapper_same(2, $literature->runCount, 'Omitted versus explicit defaults share one signature; a new page executes independently.');
wrapper_same(3, $repeatResponse['tool_loop']['tool_actions'], 'Duplicate requests count against the budget even when source retrieval is reused.');
wrapper_same(1, count(array_filter($events, static fn(array $event): bool => $event['type'] === 'reflection')), 'An identical successful query produces a visible reuse event.');
wrapper_same([0, 1], array_column(array_column($literature->contexts, 'qa_tool_arguments'), 'page'), 'The plugin sees the two distinct normalized retrieval pages.');
wrapper_assert(in_array('Executing->Collecting', $repeatResponse['workflow_state']['traversed_edges'], true), 'Recollection is an actual permitted workflow edge.');
wrapper_terminal($events, false);
wrapper_cleanup($repeatSession);

// Exhausting the retrieval budget must preserve time and material for final writing.
$boundedLiterature = new WrapperFixturePlugin('Literature Plugin');
$budget = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('budget-page0', 'literature_search', ['te' => ['L1HS'], 'page' => 0])]),
    wrapper_turn([wrapper_call('budget-page1', 'literature_search', ['te' => ['L1HS'], 'page' => 1])]),
    static function (array $messages, array $tools, array $options): array {
        wrapper_same('none', $options['tool_choice'], 'Tool exhaustion immediately transitions to final writing.');
        wrapper_assert(str_contains(json_encode($messages, JSON_THROW_ON_ERROR), 'tool_budget'), 'The final writer receives the retrieval-budget limitation.');
        return wrapper_turn([], 'The supplied paper reports an observation of L1HS in cancer (PMID 12345). The retrieval budget was reached, so these records are not exhaustive.');
    },
], ['qa_agent_max_tools' => 2]));
wrapper_inject($budget, ['Literature Plugin' => $boundedLiterature]);
$budgetSession = 'native-routing-budget-' . bin2hex(random_bytes(6));
$budgetResponse = $budget->handle(['question' => 'Review L1HS cancer papers.', 'session_id' => $budgetSession]);
wrapper_same(false, $budgetResponse['failed'], 'A bounded run writes a supported answer instead of silently truncating the workflow.');
wrapper_same(2, $boundedLiterature->runCount, 'The configured tool action bound is enforced.');
wrapper_same('tool_budget', $budgetResponse['tool_loop']['stop_reason'], 'The actual retrieval stop reason is explicit.');
wrapper_assert(str_contains(implode(' ', $budgetResponse['limits']), 'tool_budget'), 'Budget exclusions remain visible limitations rather than biological negatives.');
wrapper_cleanup($budgetSession);

// Empty primary results retain a parameterized fallback rather than a false hard stop.
$emptyStatistics = new WrapperFixturePlugin('Graph Analytics Plugin', [
    'status' => 'empty', 'results' => [], 'evidence_items' => [], 'citations' => [], 'result_counts' => ['rows' => 0],
    'display_summary' => 'No stored ranking was returned.', 'display_details' => ['preview_items' => [], 'citations' => []],
]);
$cypher = new WrapperFixturePlugin('Cypher Explorer Plugin');
$fallback = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('empty-ranking', 'graph_statistics', ['metric' => 'diseases_by_te_count'])]),
    wrapper_turn([wrapper_call('fallback-query', 'cypher_query', ['query' => 'MATCH (t:TE)-[r]->(d:Disease) RETURN d.name AS disease, count(DISTINCT t) AS distinct_te_count LIMIT 10', 'params' => []])]),
    wrapper_finish(),
    wrapper_turn([], 'The supplied fallback record reports an observation involving cancer (PMID 12345). No biological absence follows from the empty first retrieval.'),
]));
wrapper_inject($fallback, ['Graph Analytics Plugin' => $emptyStatistics, 'Cypher Explorer Plugin' => $cypher]);
$fallbackSession = 'native-routing-fallback-' . bin2hex(random_bytes(6));
$fallbackResponse = $fallback->handle(['question' => 'Find graph evidence for diseases linked to transposable elements.', 'session_id' => $fallbackSession]);
wrapper_same(false, $fallbackResponse['failed'], 'An empty first tool does not block a relevant explicit read-only fallback.');
wrapper_same(1, $emptyStatistics->runCount, 'The empty primary remains a separate retrieval result.');
wrapper_same(1, $cypher->runCount, 'The selected fallback executes once with explicit query parameters.');
wrapper_same('empty', $fallbackResponse['plugin_calls'][0]['status'], 'The empty result is not fabricated into successful evidence.');
wrapper_assert(str_contains($cypher->contexts[0]['qa_tool_arguments']['query'], 'count(DISTINCT t)'), 'The fallback uses a defined graph-count metric.');
wrapper_cleanup($fallbackSession);

echo "Agent native routing, signatures, budget and fallback tests passed.\n";
