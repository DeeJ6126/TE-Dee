<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/contracts/AgentModelPolicy.php";

final class TekgAgentLiteratureReadingPlugin implements TekgAgentPluginInterface
{
    public function __construct(
        private readonly TekgAgentLlmClient $llm,
        private readonly array $config,
    ) {
    }

    public function getName(): string
    {
        return 'Literature Reading Plugin';
    }

    public function run(array $context): array
    {
        $started = microtime(true);
        $question = trim((string)($context['question'] ?? ''));
        $analysis = tekg_agent_context_analysis($context);
        $literature = tekg_agent_context_plugin_result($context, 'Literature Plugin');
        $citations = tekg_agent_plugin_result_citations($literature);
        $selected = $this->selectCitations($citations, 12);

        if ($selected === []) {
            return [
                'plugin_name' => $this->getName(),
                'status' => 'empty',
                'query_summary' => 'No literature records were available for synthesis.',
                'results' => [
                    'reviewed_count' => 0,
                    'selected_count' => 0,
                    'generation_mode' => 'none',
                    'metadata_summary' => [],
                    'claim_clusters' => [],
                    'citation_groups' => [],
                    'supported_claims' => [],
                    'conflicting_claims' => [],
                    'missing_evidence' => ['No literature records were available to synthesize.'],
                ],
                'display_label' => 'Synthesized 0 literature claims',
                'display_summary' => 'No literature records were available for deeper synthesis in this round.',
                'display_details' => [
                    'summary' => 'No literature records were available for deeper synthesis in this round.',
                    'preview_items' => [],
                    'evidence_items' => [],
                    'citations' => [],
                    'raw_preview' => ['selected_citations' => []],
                    'result_message' => 'The literature reading layer had no records to summarize.',
                ],
                'result_counts' => ['reviewed_count' => 0, 'selected_count' => 0, 'claim_clusters' => 0],
                'evidence_items' => [],
                'citations' => [],
                'errors' => [],
                'latency_ms' => (int)round((microtime(true) - $started) * 1000),
            ];
        }

        $model = AgentModelPolicy::resolve($this->config);
        $hasAbstract = count(array_filter($selected, fn(array $citation): bool => $this->citationAbstract($citation) !== '')) > 0;
        $synthesis = $hasAbstract ? $this->synthesize($question, $analysis, $selected, $model) : null;
        $generationMode = $synthesis !== null ? 'llm' : 'metadata_fallback';
        $errors = $synthesis !== null ? array_values((array)($synthesis['citation_validation_errors'] ?? [])) : [$hasAbstract
            ? 'LLM synthesis was unavailable or invalid; citation metadata was preserved without generating supported claims.'
            : 'No abstract text was available; citation metadata was preserved without generating supported claims.'];
        $claimClusters = $synthesis !== null ? array_values((array)($synthesis['claim_clusters'] ?? [])) : [];
        $supportedClaims = $synthesis !== null ? array_values((array)($synthesis['supported_claims'] ?? [])) : [];
        $conflictingClaims = $synthesis !== null ? array_values((array)($synthesis['conflicting_claims'] ?? [])) : [];
        $missingEvidence = $synthesis !== null
            ? array_values((array)($synthesis['missing_evidence'] ?? []))
            : [$hasAbstract ? 'Claim synthesis was not available; inspect the selected citation material directly.' : 'No abstract text was available for scientific claim synthesis.'];
        $metadataSummary = array_values(array_map(fn(array $citation): array => [
            'title' => trim((string)($citation['title'] ?? '')),
            'pmid' => trim((string)($citation['pmid'] ?? '')),
            'journal' => trim((string)($citation['journal'] ?? '')),
            'year' => trim((string)($citation['year'] ?? '')),
            'url' => trim((string)($citation['url'] ?? '')),
            'abstract_text' => $this->citationAbstract($citation),
            'abstract_summary' => $this->citationAbstract($citation),
            'material_level' => $this->citationAbstract($citation) !== '' ? 'abstract' : 'metadata',
            'entity_scopes' => array_values((array)($citation['entity_scopes'] ?? [])),
            'query_scopes' => array_values((array)($citation['query_scopes'] ?? [])),
        ], $selected));

        $previewItems = [];
        $evidenceItems = [];
        foreach (array_slice($claimClusters, 0, 5) as $cluster) {
            if (!is_array($cluster)) {
                continue;
            }
            $claim = trim((string)($cluster['claim'] ?? ''));
            if ($claim === '') {
                continue;
            }
            $citCount = count((array)($cluster['citations'] ?? []));
            $clusterCitations = $this->clusterCitations($cluster, $selected);
            $previewItems[] = [
                'title' => $claim,
                'meta' => 'citations ' . count($clusterCitations),
                'body' => trim((string)($cluster['summary'] ?? '')),
            ];
            $evidenceItems[] = tekg_agent_make_evidence_item(
                $this->getName(),
                $claim,
                $claim,
                'medium',
                [
                    'citation_count' => count($clusterCitations),
                    'question_type' => (string)($analysis['intent'] ?? 'literature'),
                ],
                [
                    'title' => $claim,
                    'meta' => 'citations ' . count($clusterCitations),
                    'body' => trim((string)($cluster['summary'] ?? '')),
                ],
                [
                    'evidence_type' => 'literature_synthesis',
                    'coverage_dimension' => 'literature_evidence',
                    'subject' => $claim,
                    'provenance' => ['source' => 'literature_reading_llm'],
                    'citations' => $clusterCitations,
                    'quality_flags' => ['abstract_level', 'full_text_not_retrieved'],
                ]
            );
        }

        if ($generationMode === 'metadata_fallback') {
            $evidenceItems[] = tekg_agent_make_diagnostic_item(
                $this->getName(),
                $errors[0],
                [
                    'generation_mode' => $generationMode,
                    'selected_citation_count' => count($selected),
                ],
                [
                    'title' => 'Literature synthesis unavailable',
                    'meta' => count($selected) . ' citations preserved',
                    'body' => $errors[0],
                ],
                [
                    'evidence_type' => 'literature_synthesis_status',
                    'coverage_dimension' => 'literature_evidence',
                    'provenance' => ['source' => 'literature_reading_plugin'],
                ]
            );
        }

        $summary = $generationMode === 'llm'
            ? 'The literature reading layer reviewed ' . count($citations) . ' normalized citations and synthesized ' . count($claimClusters) . ' claim clusters.'
            : 'The literature reading layer preserved ' . count($selected) . ' selected citations, but LLM claim synthesis was unavailable.';

        return [
            'plugin_name' => $this->getName(),
            'status' => tekg_agent_plugin_status(true, $errors),
            'query_summary' => $generationMode === 'llm'
                ? 'Synthesized selected literature records into grouped claims and evidence gaps.'
                : 'Preserved selected literature metadata after LLM synthesis was unavailable.',
            'results' => [
                'reviewed_count' => count($citations),
                'selected_count' => count($selected),
                'generation_mode' => $generationMode,
                'metadata_summary' => $metadataSummary,
                'claim_clusters' => $claimClusters,
                'citation_groups' => $this->groupCitations($selected),
                'supported_claims' => $supportedClaims,
                'conflicting_claims' => $conflictingClaims,
                'missing_evidence' => $missingEvidence,
            ],
            'display_label' => $generationMode === 'llm'
                ? 'Synthesized ' . count($claimClusters) . ' literature claims'
                : 'Preserved ' . count($selected) . ' literature records',
            'display_summary' => $summary,
            'display_details' => [
                'summary' => $summary,
                'preview_items' => $previewItems,
                'evidence_items' => $evidenceItems,
                'citations' => $selected,
                'raw_preview' => [
                    'selected_citations' => $selected,
                    'claim_clusters' => $claimClusters,
                    'supported_claims' => $supportedClaims,
                    'conflicting_claims' => $conflictingClaims,
                    'missing_evidence' => $missingEvidence,
                ],
                'result_message' => $generationMode === 'llm'
                    ? 'These grouped literature claims can be passed to later evidence synthesis or answer-writing nodes as JSON.'
                    : 'Citation metadata remains available, but this result does not contain synthesized supported claims.',
            ],
            'result_counts' => [
                'reviewed_count' => count($citations),
                'selected_count' => count($selected),
                'claim_clusters' => count($claimClusters),
                'supported_claims' => count($supportedClaims),
                'conflicting_claims' => count($conflictingClaims),
            ],
            'evidence_items' => $evidenceItems,
            'citations' => $selected,
            'errors' => $errors,
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    private function synthesize(string $question, array $analysis, array $citations, string $model): ?array
    {
        $payload = [
            'question' => $question,
            'answer_language' => (string)($analysis['answer_language'] ?? $analysis['language'] ?? 'english'),
            'process_language' => (string)($analysis['process_language'] ?? $analysis['answer_language'] ?? $analysis['language'] ?? 'english'),
            'intent' => (string)($analysis['intent'] ?? 'literature'),
            'evidence_policy' => [
                'Use the supplied complete abstract_text for scientific claims, and preserve each citation entity_scopes/query_scopes when comparing entities.',
                'Titles and bibliographic metadata identify records; they cannot generate supported biological claims without abstract text.',
                'Only cite supplied PMIDs or titles. Every supported claim must occur in a claim_cluster with citations containing available abstract text.',
                'An abstract is not full text. An association or biomarker observation supports that observation, not an unreported causal mechanism.',
                'When a requested entity or mechanism lacks supplied supporting text, list that specific evidence gap rather than infer it from another entity.',
            ],
            'citations' => array_map(function (array $citation): array {
                return [
                    'title' => (string)($citation['title'] ?? ''),
                    'pmid' => (string)($citation['pmid'] ?? ''),
                    'journal' => (string)($citation['journal'] ?? ''),
                    'year' => (string)($citation['year'] ?? ''),
                    'abstract_text' => $this->citationAbstract($citation),
                    'abstract_summary' => $this->citationAbstract($citation),
                    'material_level' => $this->citationAbstract($citation) !== '' ? 'abstract' : 'metadata',
                    'entity_scopes' => array_values((array)($citation['entity_scopes'] ?? [])),
                    'query_scopes' => array_values((array)($citation['query_scopes'] ?? [])),
                    'relevance' => (string)($citation['relevance'] ?? ''),
                ];
            }, $citations),
        ];

        $generated = $this->llm->generateJson(
            $model,
            TekgAgentPromptLibrary::jsonInstructionPrompt('literature_reading', (string)($analysis['answer_language'] ?? $analysis['language'] ?? 'english')),
            $payload,
            null,
            'literature_reading'
        );

        if ($this->isValidSynthesis($generated)) {
            return $this->validateSynthesisCitations($generated, $citations);
        }
        return null;
    }

    private function citationAbstract(array $citation): string
    {
        $abstract = trim((string)($citation['abstract_text'] ?? ''));
        return $abstract !== '' ? $abstract : trim((string)($citation['abstract_summary'] ?? ''));
    }

    private function selectCitations(array $citations, int $limit): array
    {
        $groups = [];
        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $scopes = array_values(array_filter(array_map('strval', (array)($citation['entity_scopes'] ?? []))));
            $key = $scopes[0] ?? '_unscoped';
            $groups[$key][] = $citation;
        }
        foreach ($groups as &$group) {
            usort($group, fn(array $left, array $right): int => ($this->citationAbstract($right) !== '') <=> ($this->citationAbstract($left) !== ''));
        }
        unset($group);
        // Unscoped local metadata should not occupy the full budget before per-TE abstracts.
        if (isset($groups['_unscoped'])) {
            $unscoped = $groups['_unscoped'];
            unset($groups['_unscoped']);
            $groups['_unscoped'] = $unscoped;
        }
        $selected = [];
        $seen = [];
        while ($groups !== [] && count($selected) < $limit) {
            foreach ($groups as $key => &$group) {
                $citation = array_shift($group);
                if ($group === []) {
                    unset($groups[$key]);
                }
                if (!is_array($citation)) {
                    continue;
                }
                $citationKey = tekg_agent_citation_key($citation);
                if (!isset($seen[$citationKey])) {
                    $selected[] = $citation;
                    $seen[$citationKey] = true;
                }
                if (count($selected) >= $limit) {
                    break;
                }
            }
            unset($group);
        }
        return $selected;
    }

    private function validateSynthesisCitations(array $synthesis, array $citations): array
    {
        $validClusters = [];
        $validClaims = [];
        $missingEvidence = array_values((array)$synthesis['missing_evidence']);
        $validationErrors = [];
        foreach ((array)$synthesis['claim_clusters'] as $cluster) {
            if (!is_array($cluster)) {
                continue;
            }
            $references = $this->clusterCitations($cluster, $citations);
            $abstractReferences = array_values(array_filter($references, fn(array $citation): bool => $this->citationAbstract($citation) !== ''));
            $claim = trim((string)($cluster['claim'] ?? ''));
            if ($claim === '' || $abstractReferences === []) {
                if ($claim !== '') {
                    $missingEvidence[] = 'No supplied abstract-backed citation validated the proposed claim: ' . $claim;
                    $validationErrors[] = 'Rejected a claim cluster without a supplied abstract-backed citation.';
                }
                continue;
            }
            $cluster['citations'] = array_values(array_map(static fn(array $citation): string => trim((string)($citation['pmid'] ?? '')) ?: trim((string)($citation['title'] ?? '')), $abstractReferences));
            $validClusters[] = $cluster;
            $validClaims[] = $claim;
        }
        $synthesis['claim_clusters'] = $validClusters;
        // Canonical claims come from citation-validated clusters. The model's duplicate
        // supported_claims field may paraphrase them and carries no independent evidence.
        $synthesis['supported_claims'] = array_values(array_unique($validClaims));
        $synthesis['missing_evidence'] = array_values(array_unique($missingEvidence));
        $synthesis['citation_validation_errors'] = array_values(array_unique($validationErrors));
        return $synthesis;
    }

    private function isValidSynthesis(mixed $synthesis): bool
    {
        if (!is_array($synthesis)) {
            return false;
        }
        foreach (['claim_clusters', 'supported_claims', 'conflicting_claims', 'missing_evidence'] as $key) {
            if (!array_key_exists($key, $synthesis) || !is_array($synthesis[$key])) {
                return false;
            }
        }
        return true;
    }

    private function groupCitations(array $citations): array
    {
        $groups = [];
        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $source = trim((string)($citation['source'] ?? 'unknown')) ?: 'unknown';
            $groups[$source] = ($groups[$source] ?? 0) + 1;
        }

        $result = [];
        foreach ($groups as $source => $count) {
            $result[] = ['source' => $source, 'count' => $count];
        }
        return $result;
    }

    private function clusterCitations(array $cluster, array $selected): array
    {
        $refs = array_values(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            (array)($cluster['citations'] ?? [])
        )));
        if ($refs === []) {
            return [];
        }

        $matches = [];
        foreach ($selected as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $pmid = trim((string)($citation['pmid'] ?? ''));
            $title = trim((string)($citation['title'] ?? ''));
            foreach ($refs as $ref) {
                if (($pmid !== '' && strcasecmp($pmid, $ref) === 0) || ($title !== '' && strcasecmp($title, $ref) === 0)) {
                    $matches[] = $citation;
                    continue 2;
                }
            }
        }

        return tekg_agent_dedupe_citations($matches);
    }
}
