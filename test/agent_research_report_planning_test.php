<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/orchestrator/EntityNormalizer.php';
require_once __DIR__ . '/../api/agent/orchestrator/traits/AcademicAgentPlanningTrait.php';
require_once __DIR__ . '/../api/agent/orchestrator/traits/AcademicAgentEvidenceTrait.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

final class TekgAgentResearchReportPlanningHarness
{
    use TekgAcademicAgentEvidenceTrait;
    use TekgAcademicAgentPlanningTrait {
        buildPlan as public capturePlan;
        routingPolicyFor as public captureRoutingPolicy;
        initialPluginQueue as public captureInitialPluginQueue;
    }

    public function captureEvaluateSufficiency(
        array $analysis,
        array $plan,
        array $pluginResults,
        array $collectionState,
        array $routingPolicy
    ): array {
        return $this->evaluateSufficiency(
            'unused-model',
            'Generate a research report for L1HS including sequence, genomic location, expression, disease links, and literature evidence.',
            $analysis,
            $plan,
            $pluginResults,
            $collectionState,
            $routingPolicy
        );
    }

    private object $llm;
    private array $config = [];

    public function __construct()
    {
        $this->llm = new class {
            public function assessSufficiency(string $model, array $payload, int $timeout): ?array
            {
                return [
                    'is_sufficient' => true,
                    'reason' => 'Model would stop early.',
                    'missing_dimensions' => [],
                    'recommended_next_experts' => [],
                ];
            }
        };
    }
}

$question = 'Generate a research report for L1HS including sequence, genomic location, expression, disease links, and literature evidence.';
$normalizer = new TekgAgentEntityNormalizer();
$analysis = $normalizer->analyze($question);
$harness = new TekgAgentResearchReportPlanningHarness();
$plan = $harness->capturePlan($question, $analysis, []);
$routingPolicy = $harness->captureRoutingPolicy($analysis);
$queue = $harness->captureInitialPluginQueue($analysis, $plan, $routingPolicy);

$gapTypes = array_values(array_map(static fn(array $gap): string => (string)($gap['gap_type'] ?? ''), (array)($plan['knowledge_gaps'] ?? [])));
$plugins = array_values(array_map(static fn(array $item): string => (string)($item['plugin'] ?? ''), (array)($plan['tool_plan'] ?? [])));

assert_true(!in_array('site navigation', $gapTypes, true), 'Research report should not create a site navigation knowledge gap.');
assert_true(in_array('sequence and structure context', $gapTypes, true), 'Research report should request sequence evidence.');
assert_true(in_array('genomic loci', $gapTypes, true), 'Research report should request genomic location evidence.');
assert_true(in_array('expression context', $gapTypes, true), 'Research report should request expression evidence.');
assert_true(in_array('literature evidence', $gapTypes, true), 'Research report should request literature evidence.');
assert_true(in_array('literature synthesis', $gapTypes, true), 'Research report should request literature synthesis.');
assert_true(in_array('structured disease relations', $gapTypes, true), 'Research report should request graph disease-link evidence.');

foreach (['Graph Plugin', 'Sequence Plugin', 'Genome Plugin', 'Expression Plugin', 'Literature Plugin', 'Literature Reading Plugin'] as $plugin) {
    assert_true(in_array($plugin, $plugins, true), "{$plugin} should be in the research report tool plan.");
    assert_true(in_array($plugin, $queue, true), "{$plugin} should be in the initial research report plugin queue.");
}
assert_true(!in_array('Site Navigator Plugin', $plugins, true), 'Site Navigator Plugin should not be in a data-oriented research report tool plan.');
assert_true(!in_array('Site Navigator Plugin', $queue, true), 'Site Navigator Plugin should not be in a data-oriented research report plugin queue.');

$partialPluginResults = [
    'Entity Resolver' => [
        'plugin_name' => 'Entity Resolver',
        'status' => 'ok',
        'evidence_items' => [['claim' => 'Resolved L1HS.']],
        'citations' => [],
    ],
    'Graph Plugin' => [
        'plugin_name' => 'Graph Plugin',
        'status' => 'ok',
        'evidence_items' => [['claim' => 'Graph has a disease link.']],
        'citations' => [],
        'result_counts' => ['relations' => 1],
    ],
    'Literature Plugin' => [
        'plugin_name' => 'Literature Plugin',
        'status' => 'ok',
        'evidence_items' => [['claim' => 'Literature evidence exists.']],
        'citations' => [['pmid' => '12345']],
        'result_counts' => ['reviewed' => 1],
    ],
];
$partialCollectionState = [
    'remaining_candidates' => array_values(array_filter($queue, static fn(string $plugin): bool => !isset($partialPluginResults[$plugin]))),
];
$partialSufficiency = $harness->captureEvaluateSufficiency($analysis, $plan, $partialPluginResults, $partialCollectionState, $routingPolicy);
assert_true(($partialSufficiency['is_sufficient'] ?? true) === false, 'Research report must not stop before all required data plugins have run.');
foreach (['Sequence Plugin', 'Genome Plugin', 'Expression Plugin', 'Literature Reading Plugin'] as $plugin) {
    assert_true(in_array($plugin, (array)($partialSufficiency['recommended_next_experts'] ?? []), true), "{$plugin} should remain recommended before research report sufficiency.");
}

$navigationQuestion = 'Where can I open the L1HS sequence panel in TE-KG?';
$navigationAnalysis = $normalizer->analyze($navigationQuestion);
$navigationPlan = $harness->capturePlan($navigationQuestion, $navigationAnalysis, []);
$navigationPlugins = array_values(array_map(static fn(array $item): string => (string)($item['plugin'] ?? ''), (array)($navigationPlan['tool_plan'] ?? [])));
assert_true((bool)($navigationAnalysis['asks_for_site_navigation'] ?? false), 'English open-panel question should be recognized as site navigation.');
assert_true(in_array('Site Navigator Plugin', $navigationPlugins, true), 'English open-panel question should route through Site Navigator Plugin.');
assert_true(in_array('Sequence Plugin', $navigationPlugins, true), 'English open-panel question may still include Sequence Plugin as supporting context.');
assert_true(
    array_search('Site Navigator Plugin', $navigationPlugins, true) < array_search('Sequence Plugin', $navigationPlugins, true),
    'Site Navigator Plugin should run before Sequence Plugin for sequence panel navigation.'
);

echo "Agent research report planning tests passed.\n";
