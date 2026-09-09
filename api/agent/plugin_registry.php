<?php
declare(strict_types=1);

function tekg_agent_require_orchestrator_dependencies(): void
{
    require_once __DIR__ . '/contracts/ConversationContextResult.php';
    require_once __DIR__ . '/orchestrator/Neo4jClient.php';
    require_once __DIR__ . '/orchestrator/LlmClient.php';
    require_once __DIR__ . '/orchestrator/CitationResolver.php';
    require_once __DIR__ . '/orchestrator/EntityNormalizer.php';
    require_once __DIR__ . '/orchestrator/ConversationMemory.php';
    require_once __DIR__ . '/orchestrator/ConversationContextResolver.php';
}

function tekg_agent_plugin_files(): array
{
    return [
        __DIR__ . '/plugins/EntityResolverPlugin.php',
        __DIR__ . '/plugins/SiteNavigatorPlugin.php',
        __DIR__ . '/plugins/GraphPlugin.php',
        __DIR__ . '/plugins/GraphAnalyticsPlugin.php',
        __DIR__ . '/plugins/CypherExplorerPlugin.php',
        __DIR__ . '/plugins/LiteraturePlugin.php',
        __DIR__ . '/plugins/LiteratureReadingPlugin.php',
        __DIR__ . '/plugins/TreePlugin.php',
        __DIR__ . '/plugins/ExpressionPlugin.php',
        __DIR__ . '/plugins/GenomePlugin.php',
        __DIR__ . '/plugins/SequencePlugin.php',
        __DIR__ . '/plugins/CitationResolverPlugin.php',
    ];
}

function tekg_agent_require_plugin_files(): void
{
    foreach (tekg_agent_plugin_files() as $file) {
        require_once $file;
    }
}

function tekg_agent_plugin_directory(): string
{
    $path = __DIR__ . '/plugins/PLUGIN_CATALOG.md';
    if (!is_file($path)) {
        return '';
    }
    $contents = file_get_contents($path);
    return is_string($contents) ? $contents : '';
}

function tekg_agent_require_academic_agent_service(): void
{
    tekg_agent_require_orchestrator_dependencies();
    tekg_agent_require_plugin_files();
    require_once __DIR__ . '/orchestrator/AcademicAgentService.php';
}

function tekg_agent_require_deepthink_service(): void
{
    tekg_agent_require_orchestrator_dependencies();
    tekg_agent_require_plugin_files();
    require_once __DIR__ . '/orchestrator/DeepThinkService.php';
}

function tekg_agent_create_default_plugins(
    array $config,
    TekgAgentNeo4jClient $neo4j,
    TekgAgentLlmClient $llm,
    TekgAgentCitationResolver $citationResolver
): array {
    return [
        'Entity Resolver' => new TekgAgentEntityResolverPlugin(),
        'Site Navigator Plugin' => new TekgAgentSiteNavigatorPlugin(),
        'Graph Plugin' => new TekgAgentGraphPlugin($neo4j, $citationResolver),
        'Graph Analytics Plugin' => new TekgAgentGraphAnalyticsPlugin($neo4j),
        'Cypher Explorer Plugin' => new TekgAgentCypherExplorerPlugin($neo4j, $llm, $config),
        'Literature Plugin' => new TekgAgentLiteraturePlugin($neo4j, $config, $citationResolver),
        'Literature Reading Plugin' => new TekgAgentLiteratureReadingPlugin($llm, $config),
        'Tree Plugin' => new TekgAgentTreePlugin(),
        'Expression Plugin' => new TekgAgentExpressionPlugin(),
        'Genome Plugin' => new TekgAgentGenomePlugin(),
        'Sequence Plugin' => new TekgAgentSequencePlugin(),
        'Citation Resolver' => new TekgAgentCitationResolverPlugin($citationResolver),
    ];
}
