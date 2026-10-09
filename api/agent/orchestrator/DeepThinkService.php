<?php
declare(strict_types=1);

if (!function_exists('tekg_agent_create_default_plugins')) {
    require_once dirname(__DIR__) . '/plugin_registry.php';
}
require_once __DIR__ . '/QaResearchLoop.php';

final class TekgDeepThinkService
{
    /** Existing plugin injection remains available to trusted test configurations. */
    private array $plugins;

    public function __construct(private readonly array $config)
    {
        $this->plugins = tekg_agent_create_default_plugins($config, new TekgAgentNeo4jClient($config), new TekgAgentLlmClient($config), new TekgAgentCitationResolver());
    }

    public function handle(array $payload): array { return $this->execute($payload, null); }
    public function stream(array $payload, callable $emit): array { return $this->execute($payload, $emit); }

    private function execute(array $payload, ?callable $emit): array
    {
        return (new TekgAgentQaResearchLoop($this->config, $this->plugins))->run($payload, 'deepthink', $emit);
    }

    private function resolveModel(...$arguments): string { return AgentModelPolicy::resolve($this->config); }
    private function resolveNarratorModel(...$arguments): string { return AgentModelPolicy::resolve($this->config); }
    private function resolveAnswerStructureModel(...$arguments): string { return AgentModelPolicy::resolve($this->config); }
    private function resolveWritingModel(...$arguments): string { return AgentModelPolicy::resolve($this->config); }
}
