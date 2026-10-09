<?php
declare(strict_types=1);

function wrapper_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function wrapper_same(mixed $expected, mixed $actual, string $message): void
{
    wrapper_assert($expected === $actual, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function wrapper_turn(array $calls = [], string $content = '', string $reasoning = ''): array
{
    return ['ok' => true, 'content' => $content, 'reasoning_content' => $reasoning, 'tool_calls' => $calls, 'finish_reason' => $calls !== [] ? 'tool_calls' : 'stop'];
}

function wrapper_call(string $id, string $name, array $arguments): array
{
    return ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)]];
}

function wrapper_finish(string $id = 'finish'): array
{
    return wrapper_turn([wrapper_call($id, 'finish_research', ['reason' => 'The retrieved original material is sufficient for this fixture question.'])]);
}

function wrapper_config(array $fixtures, array $overrides = []): array
{
    return array_replace(['agent_test_mode' => true, 'agent_model' => 'deepseek-flash', 'qa_native_fixtures' => $fixtures], $overrides);
}

function wrapper_inject(object $service, array $plugins): void
{
    (new ReflectionProperty($service, 'plugins'))->setValue($service, $plugins);
}

function wrapper_cleanup(string $sessionId): void
{
    $path = tekg_agent_session_file($sessionId);
    if (is_file($path)) { unlink($path); }
}

function wrapper_stages(array $events): array
{
    return array_values(array_unique(array_map(
        static fn(array $event): string => (string)($event['payload']['current_stage'] ?? ''),
        array_filter($events, static fn(array $event): bool => ($event['type'] ?? '') === 'stage_state')
    )));
}

function wrapper_terminal(array $events, bool $failed): void
{
    wrapper_assert($events !== [], 'The service emits real workflow events.');
    $sequences = array_column($events, 'sequence');
    wrapper_same(range(1, count($events)), $sequences, 'Every emitted event keeps a consecutive sequence.');
    wrapper_same('done', $events[array_key_last($events)]['type'] ?? '', 'Every run emits terminal done.');
    wrapper_same($failed, $events[array_key_last($events)]['payload']['failed'] ?? null, 'Terminal done exposes the actual failure state.');
    if ($failed) {
        wrapper_same('error', $events[count($events) - 2]['type'] ?? '', 'Terminal failure emits error before done.');
        wrapper_assert(!in_array('answer', array_column($events, 'type'), true), 'A failed run never publishes an invented answer.');
        wrapper_same('', $events[array_key_last($events)]['payload']['answer'] ?? null, 'A failed done has no fallback scientific answer.');
    } else {
        wrapper_same('answer', $events[count($events) - 2]['type'] ?? '', 'Successful runs publish answer before done.');
    }
}

final class WrapperFixturePlugin implements TekgAgentPluginInterface
{
    public int $runCount = 0;
    public array $contexts = [];

    public function __construct(private readonly string $name, private readonly array $overrides = []) {}
    public function getName(): string { return $this->name; }
    public function run(array $context): array
    {
        $this->runCount++;
        $this->contexts[] = $context;
        $bootstrap = $this->name === 'Entity Resolver';
        $citation = ['pmid' => '12345', 'title' => 'Retrieved L1HS cancer observation', 'url' => 'https://pubmed.ncbi.nlm.nih.gov/12345/',
            'abstract_text' => 'A measured observation of L1HS in cancer was reported in the supplied record.', 'material_level' => 'abstract'];
        $item = tekg_agent_make_evidence_item($this->name, 'The supplied record reports an observation of L1HS in cancer.', 'L1HS', 'medium', [],
            ['title' => 'L1HS observation'], ['citations' => [$citation], 'evidence_type' => 'literature_record', 'provenance' => ['source' => 'test_fixture']]);
        return array_replace([
            'plugin_name' => $this->name, 'status' => 'ok', 'query_summary' => 'A bounded fixture lookup completed.',
            'results' => ['rows' => [['source_name' => 'L1HS', 'source_type' => 'TE', 'target_name' => 'Cancer', 'target_type' => 'Disease']]],
            'display_label' => $this->name, 'display_summary' => 'One supplied record was retrieved.',
            'display_details' => ['preview_items' => [], 'citations' => $bootstrap ? [] : [$citation]],
            'result_counts' => ['records' => 1], 'evidence_items' => $bootstrap ? [] : [$item],
            'citations' => $bootstrap ? [] : [$citation], 'errors' => [], 'latency_ms' => 0,
        ], $this->overrides);
    }
}
