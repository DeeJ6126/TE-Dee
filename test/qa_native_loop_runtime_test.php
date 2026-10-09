<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_orchestrator_dependencies();
tekg_agent_require_plugin_files();
require_once __DIR__ . '/../api/agent/orchestrator/QaResearchLoop.php';

function loop_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function loop_turn(array $calls = [], string $content = '', string $reasoning = ''): array {
    return ['ok' => true, 'content' => $content, 'reasoning_content' => $reasoning, 'tool_calls' => $calls, 'finish_reason' => $calls !== [] ? 'tool_calls' : 'stop'];
}
function loop_call(string $id, string $name, array $arguments): array {
    return ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)]];
}
final class LoopFixturePlugin implements TekgAgentPluginInterface {
    public int $calls = 0;
    public array $contexts = [];
    public function __construct(private string $name, private bool $firstError = false) {}
    public function getName(): string { return $this->name; }
    public function run(array $context): array {
        $this->calls++; $this->contexts[] = $context;
        if ($this->firstError && $this->calls === 1) { throw new RuntimeException('Fixture lookup unavailable.'); }
        $citation = ['pmid' => '12345', 'title' => 'Fixture L1HS cancer paper', 'url' => 'https://pubmed.ncbi.nlm.nih.gov/12345/', 'abstract_text' => str_repeat('A measured observation of L1HS was reported. ', 15), 'material_level' => 'abstract'];
        $item = tekg_agent_make_evidence_item($this->name, 'A retrieved record describes L1HS in cancer.', 'L1HS', 'medium', [], ['title' => 'L1HS'], ['citations' => [$citation], 'evidence_type' => 'literature_record']);
        return ['plugin_name' => $this->name, 'status' => 'ok', 'query_summary' => 'Fixture explicit lookup.', 'results' => ['rows' => [['label' => 'L1HS']]], 'display_label' => 'Fixture lookup', 'display_summary' => 'One fixture record retrieved.', 'display_details' => ['preview_items' => [], 'citations' => [$citation]], 'result_counts' => ['records' => 1], 'evidence_items' => [$item], 'citations' => [$citation], 'errors' => [], 'latency_ms' => 0];
    }
}
$history = []; $events = [];
$literature = new LoopFixturePlugin('Literature Plugin');
$graph = new LoopFixturePlugin('Graph Plugin', true);
$fixtures = [
    loop_turn([loop_call('g1', 'graph_lookup', ['te' => ['L1HS'], 'disease' => 'cancer'])], '', 'private-first-reasoning'),
    function(array $messages) use (&$history): array {
        $history[] = $messages;
        return loop_turn([loop_call('l1', 'literature_search', ['te' => ['L1HS'], 'disease' => 'cancer', 'page' => 0])], '', 'private-second-reasoning');
    },
    loop_turn([loop_call('l2', 'literature_search', ['te' => ['L1HS'], 'disease' => 'cancer', 'page' => 1])]),
    loop_turn([loop_call('l3', 'literature_search', ['te' => ['L1HS'], 'disease' => 'cancer', 'page' => 1])]),
    function(array $messages) use (&$history): array {
        $history[] = $messages;
        $id = '';
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? '') !== 'tool') { continue; }
            $data = json_decode($message['content'], true);
            if (!empty($data['artifact_id'])) { $id = $data['artifact_id']; break; }
        }
        return loop_turn([loop_call('r1', 'read_evidence', ['artifact_id' => $id, 'section' => 'citations', 'pmids' => ['12345']])]);
    },
    loop_turn([loop_call('f1', 'finish_research', ['reason' => 'Relevant sources obtained.'])]),
    function(array $messages, array $tools, array $options) use (&$history): array {
        $history[] = $messages;
        loop_assert($options['tool_choice'] === 'none', 'Final writer cannot request extra tools.');
        loop_assert($tools !== [], 'Final writer retains tool definitions to preserve protocol context.');
        return loop_turn([], 'The retrieved study reports an observation of L1HS in cancer (PMID 12345).');
    },
];
$config = ['agent_test_mode' => true, 'qa_native_fixtures' => $fixtures, 'deepseek_key' => 'never-in-public-output'];
$loop = new TekgAgentQaResearchLoop($config, ['Graph Plugin' => $graph, 'Literature Plugin' => $literature]);
$response = $loop->run(['question' => 'Find papers supporting L1HS and cancer.', 'session_id' => 'native-fixture-' . bin2hex(random_bytes(6))], 'agent', static function(array $event) use (&$events): void { $events[] = $event; });
loop_assert(!$response['failed'], 'Recoverable tool failure must not terminate research.');
loop_assert($literature->calls === 2, 'Different pages execute twice, identical successful page executes only once.');
loop_assert($response['tool_loop']['tool_actions'] === 5, 'Actual requested actions include reads and duplicate requests, but not the finish signal.');
loop_assert(in_array('Executing->Collecting', $response['workflow_state']['traversed_edges'], true), 'Agent collection and execution can recur.');
$ids = array_column(array_filter($events, static fn(array $e): bool => $e['type'] === 'tool_result'), 'detail_payload_id');
loop_assert(count($ids) === count(array_unique($ids)), 'Each displayed operation keeps unique detail identity.');
loop_assert(!in_array('error', array_column($events, 'type'), true), 'A recoverable lookup error must not set the global failed event.');
loop_assert(in_array('answer', array_column($events, 'type'), true) && end($events)['type'] === 'done', 'Successful publication emits answer then done.');
$public = json_encode([$response, $events], JSON_THROW_ON_ERROR);
loop_assert(!str_contains($public, 'private-first-reasoning') && !str_contains($public, 'never-in-public-output'), 'Private reasoning and credentials stay outside public events/response.');
loop_assert(str_contains(json_encode($history, JSON_THROW_ON_ERROR), 'private-first-reasoning'), 'Research and writing preserve prior reasoning in the same history.');
loop_assert(str_contains(json_encode($history, JSON_THROW_ON_ERROR), 'A measured observation'), 'Reader delivers the complete retained abstract to the ongoing context.');

$failureLoop = new TekgAgentQaResearchLoop(['agent_test_mode' => true, 'qa_native_fixtures' => [['ok' => false, 'error' => 'Fixture model unavailable.', 'error_type' => 'transport_error']]], ['Graph Plugin' => new LoopFixturePlugin('Graph Plugin')]);
$failed = $failureLoop->run(['question' => 'Find L1HS cancer evidence.'], 'deepthink');
loop_assert($failed['failed'] && $failed['writing_failed'] && $failed['answer'] === '', 'Model failure publishes no deterministic scientific answer and marks terminal failure.');
echo "Native shared loop runtime tests passed.\n";
