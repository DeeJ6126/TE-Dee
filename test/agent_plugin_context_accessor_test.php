<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/agent/bootstrap/evidence_support.php';
require_once __DIR__ . '/../api/agent/plugins/EntityResolverPlugin.php';

if (!function_exists('tekg_agent_lower')) {
    function tekg_agent_lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$context = [
    'analysis' => [
        'intent' => 'sequence',
        'normalized_entities' => [
            ['label' => 'SHOULD_NOT_WIN', 'type' => 'TE'],
        ],
        'alias_chains' => [
            [
                'label' => 'ALIAS_FALLBACK',
                'canonical_label' => 'ALIAS_FALLBACK',
                'type' => 'TE',
                'matched_alias' => 'ALIAS_FALLBACK',
                'aliases' => ['ALIAS_FALLBACK'],
            ],
        ],
    ],
    'plugin_results' => [
        'Entity Resolver' => [
            'results' => [
                'resolved_entities' => [
                    [
                        'label' => 'L1HS',
                        'canonical_label' => 'L1HS',
                        'type' => 'TE',
                        'matched_alias' => 'L1HS',
                        'aliases' => ['L1HS', 'L1HS-Ta'],
                        'confidence' => 0.93,
                    ],
                    [
                        'label' => 'Cancer',
                        'canonical_label' => 'Cancer',
                        'type' => 'Disease',
                    ],
                ],
                'alias_chains' => [
                    ['label' => 'L1HS', 'canonical_label' => 'L1HS', 'type' => 'TE', 'aliases' => ['L1HS']],
                ],
            ],
        ],
        'Literature Plugin' => [
            'citations' => [
                ['pmid' => '111', 'title' => 'Top level citation'],
            ],
            'result_envelope' => [
                'citations' => [
                    ['pmid' => '222', 'title' => 'Envelope citation'],
                ],
                'evidence_items' => [
                    [
                        'claim' => 'Envelope evidence citation.',
                        'citations' => [
                            ['pmid' => '444', 'title' => 'Envelope item citation'],
                        ],
                    ],
                ],
            ],
            'results' => [
                'citations' => [
                    ['doi' => '10.1/example', 'title' => 'Nested result citation'],
                ],
            ],
            'display_details' => [
                'citations' => [
                    ['url' => 'https://example.test/paper', 'title' => 'Display citation'],
                ],
            ],
            'evidence_items' => [
                [
                    'claim' => 'Evidence with own citation.',
                    'citations' => [
                        ['pmid' => '333', 'title' => 'Evidence citation'],
                        ['pmid' => '111', 'title' => 'Top level citation duplicate'],
                    ],
                ],
            ],
        ],
    ],
];

assert_same('sequence', tekg_agent_context_analysis($context)['intent'], 'analysis accessor');
assert_same(['Entity Resolver', 'Literature Plugin'], array_keys(tekg_agent_context_plugin_results($context)), 'plugin_results accessor');
assert_same('Literature Plugin', tekg_agent_context_plugin_result($context, 'Literature Plugin')['plugin_name'] ?? 'Literature Plugin', 'plugin result accessor exact result');

$entities = tekg_agent_context_resolved_entities($context);
assert_same('L1HS', $entities[0]['canonical_label'], 'Entity Resolver resolved_entities wins over analysis fallback');
assert_same('Cancer', $entities[1]['canonical_label'], 'multiple resolved entities preserved');

$teEntities = tekg_agent_context_resolved_entities($context, 'TE');
assert_same(1, count($teEntities), 'type filter keeps only TE');
assert_same('L1HS', $teEntities[0]['label'], 'type filter entity label');

$chains = tekg_agent_context_alias_chains($context);
assert_same('L1HS', $chains[0]['canonical_label'], 'alias chains prefer Entity Resolver results');

$citations = tekg_agent_plugin_result_citations($context['plugin_results']['Literature Plugin']);
assert_same(6, count($citations), 'plugin citation accessor collects all citation locations and dedupes duplicate PMID');
assert_same(['111', '222', '', '', '333', '444'], array_map(static fn(array $item): string => (string)($item['pmid'] ?? ''), $citations), 'citation order is deterministic');

$contextCitations = tekg_agent_context_citations($context, ['Literature Plugin']);
assert_same([], $contextCitations, 'context citation accessor can exclude plugins');

$resolver = new TekgAgentEntityResolverPlugin();
$resolverResult = $resolver->run([
    'analysis' => [
        'alias_chains' => [
            [
                'label' => 'L1HS',
                'canonical_label' => 'L1HS',
                'type' => 'TE',
                'matched_alias' => 'L1HS',
                'aliases' => ['L1HS', 'L1HS-Ta'],
                'confidence' => 0.93,
            ],
        ],
    ],
]);
assert_same('L1HS', $resolverResult['results']['resolved_entities'][0]['canonical_label'] ?? '', 'Entity Resolver emits results.resolved_entities');
assert_same('TE', $resolverResult['results']['resolved_entities'][0]['type'] ?? '', 'Entity Resolver preserves entity type');

echo "Agent plugin context accessor tests passed.\n";
