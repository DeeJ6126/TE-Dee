<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';

/** A native lookup failure is recoverable; usable partial evidence is retained. */
final class ResilientNativeGraphFixture implements TekgAgentPluginInterface
{
    public int $runCount = 0;
    public function getName(): string { return 'Graph Plugin'; }
    public function run(array $context): array
    {
        $this->runCount++;
        if ($this->runCount === 1) {
            return ['plugin_name' => $this->getName(), 'status' => 'error', 'query_summary' => 'Graph lookup unavailable.',
                'results' => [], 'display_label' => 'Graph lookup unavailable', 'display_summary' => 'Temporary graph retrieval failure.',
                'display_details' => [], 'result_counts' => [], 'evidence_items' => [], 'citations' => [],
                'errors' => ['Fixture graph connection failed.'], 'latency_ms' => 0];
        }
        return (new WrapperFixturePlugin($this->getName(), [
            'status' => 'partial', 'errors' => ['One requested source was unavailable; the supplied record remains usable.'],
        ]))->run($context);
    }
}

$graph = new ResilientNativeGraphFixture();
$researchHistory = [];
$service = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('attempt1', 'graph_lookup', ['te' => ['L1HS'], 'disease' => 'Cancer'])]),
    static function (array $messages) use (&$researchHistory): array {
        $researchHistory = $messages;
        $last = json_decode($messages[array_key_last($messages)]['content'], true, 32, JSON_THROW_ON_ERROR);
        wrapper_same('error', $last['status'], 'The failed lookup is visible as an error rather than scientific evidence.');
        wrapper_assert($last['errors'] !== [], 'The model receives the original retrieval failure.');
        return wrapper_turn([wrapper_call('attempt2', 'graph_lookup', ['te' => ['L1HS'], 'disease' => 'Cancer'])]);
    },
    wrapper_finish(),
    static function (array $messages, array $tools, array $options): array {
        wrapper_same('none', $options['tool_choice'], 'Only final writing follows source recovery; no independent JSON review node is inserted.');
        $partial = false;
        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'tool') { continue; }
            $material = json_decode($message['content'], true);
            $partial = $partial || ($material['status'] ?? '') === 'partial';
        }
        wrapper_assert($partial, 'The final writer receives usable partial evidence and its limitation.');
        return wrapper_turn([], 'The supplied record reports an observation of L1HS in cancer (PMID 12345). Some requested source material was unavailable.');
    },
]));
wrapper_inject($service, ['Graph Plugin' => $graph]);
$events = [];
$session = 'native-retrieval-resilience-' . bin2hex(random_bytes(6));
$response = $service->stream(['question' => 'Find L1HS evidence in cancer.', 'session_id' => $session], static function (array $event) use (&$events): void { $events[] = $event; });
wrapper_same(false, $response['failed'], 'A recoverable retrieval failure does not terminate the run.');
wrapper_same(2, $graph->runCount, 'One explicit retry executes the same failed lookup and obtains partial evidence.');
wrapper_same(4, $response['tool_loop']['model_steps'], 'Research, retry, finish and writing use the shared conversation without extra review calls.');
wrapper_same('error', $response['plugin_calls'][0]['status'], 'A genuine plugin failure is not relabelled success.');
wrapper_same([], $response['plugin_calls'][0]['evidence_items'], 'Failed retrieval contributes no scientific claims.');
wrapper_same('partial', $response['plugin_calls'][1]['status'], 'Usable data retains its partial state.');
wrapper_assert($response['plugin_calls'][1]['evidence_items'] !== [] && $response['plugin_calls'][1]['citations'] !== [], 'Partial evidence and citations reach final synthesis.');
wrapper_assert($response['evidence_package']['claims'] !== [], 'The recovered scientific record reaches the evidence package.');
$toolEvents = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_result'));
wrapper_same(['error', 'partial'], array_column(array_column($toolEvents, 'payload'), 'status'), 'The UI reflects actual retrieval states.');
wrapper_assert($toolEvents[1]['payload']['errors'] !== [] && $toolEvents[1]['payload']['citations'] !== [], 'The UI retains both limitations and usable references.');
wrapper_assert(!in_array('error', array_column($events, 'type'), true), 'Recoverable tool errors are not global workflow failures.');
wrapper_terminal($events, false);
wrapper_cleanup($session);

// A model transport failure after retrieval never creates a deterministic answer.
$failedService = new TekgAcademicAgentService(wrapper_config([
    wrapper_turn([wrapper_call('retrieve', 'graph_lookup', ['te' => ['L1HS']])]),
    wrapper_finish(),
    ['ok' => false, 'error_type' => 'transport_error', 'error' => 'The final model turn was unavailable.'],
]));
wrapper_inject($failedService, ['Graph Plugin' => new WrapperFixturePlugin('Graph Plugin')]);
$failedEvents = [];
$failedSession = 'native-writer-failure-' . bin2hex(random_bytes(6));
$failed = $failedService->stream(['question' => 'Summarize L1HS evidence.', 'session_id' => $failedSession], static function (array $event) use (&$failedEvents): void { $failedEvents[] = $event; });
wrapper_same(true, $failed['failed'], 'A failed final model turn produces a terminal failed run.');
wrapper_same('', $failed['answer'], 'Recovered source evidence is not converted into a fabricated fallback answer.');
wrapper_terminal($failedEvents, true);
wrapper_cleanup($failedSession);

echo "Agent native retrieval resilience tests passed.\n";
