<?php
declare(strict_types=1);

trait TekgAcademicAgentEvidenceTrait
{
    private function evaluateSufficiency(
        string $model,
        string $question,
        array $analysis,
        array $planning,
        array $pluginResults,
        array $collectionState,
        array $routingPolicy
    ): array {
        if (($analysis['asks_for_site_navigation'] ?? false) && isset($pluginResults['Site Navigator Plugin'])) {
            $siteResult = (array)$pluginResults['Site Navigator Plugin'];
            if (in_array((string)($siteResult['status'] ?? ''), ['ok', 'partial'], true)) {
                return [
                    'is_sufficient' => true,
                    'reason' => 'The site navigator returned clickable TE-Dee routes for this page-location question.',
                    'missing_dimensions' => [],
                    'recommended_next_experts' => [],
                ];
            }
        }

        $researchRequired = $this->missingResearchSynthesisPlugins($analysis, $planning, $pluginResults, $routingPolicy);
        if ($researchRequired !== []) {
            return [
                'is_sufficient' => false,
                'reason' => 'The research report still has required evidence layers that have not run.',
                'missing_dimensions' => array_map(static fn(string $plugin): string => 'required research plugin ' . $plugin . ' has not run', $researchRequired),
                'recommended_next_experts' => $researchRequired,
            ];
        }

        $hardStop = $this->evaluateHardStopCondition($analysis, $pluginResults, $routingPolicy);
        if ($hardStop !== null) {
            return $hardStop;
        }

        $hardGate = $this->evaluateMinimumEvidenceGate($pluginResults, $routingPolicy);
        if (!$hardGate['passed']) {
            return [
                'is_sufficient' => false,
                'reason' => $hardGate['reason'],
                'missing_dimensions' => $hardGate['missing_dimensions'],
                'recommended_next_experts' => $this->recommendedNextExperts($routingPolicy, $pluginResults, $hardGate['missing_dimensions'], $analysis),
            ];
        }

        if ($this->isHardStopIntent((string)($analysis['intent'] ?? ''))) {
            return [
                'is_sufficient' => true,
                'reason' => 'The primary plugin returned enough evidence for this question type, so the route stopped at the minimal path.',
                'missing_dimensions' => [],
                'recommended_next_experts' => [],
            ];
        }

        $payload = [
            'question' => $question,
            'answer_language' => (string)($analysis['answer_language'] ?? $analysis['language'] ?? 'english'),
            'process_language' => (string)($analysis['process_language'] ?? $analysis['answer_language'] ?? $analysis['language'] ?? 'english'),
            'analysis' => [
                'intent' => (string)($analysis['intent'] ?? ''),
                'complexity' => (string)($analysis['complexity'] ?? ''),
                'normalized_entities' => array_slice((array)($analysis['normalized_entities'] ?? []), 0, 4),
            ],
            'planning' => [
                'question_type' => (string)($planning['question_type'] ?? ''),
                'required_evidence' => array_values((array)($planning['required_evidence'] ?? [])),
            ],
            'collection_state' => $collectionState,
            'plugin_results' => $this->compressedPluginResults($pluginResults),
            'minimum_evidence_gate' => $routingPolicy['minimum_evidence_gate'] ?? [],
            'plugin_directory' => tekg_agent_plugin_directory(),
        ];
        $generated = $this->llm->assessSufficiency($model, $payload, max(10, (int)($this->config['llm_json_timeout'] ?? 15)));
        if (is_array($generated)) {
            $requestedExperts = array_values(array_map('strval', (array)($generated['recommended_next_experts'] ?? [])));
            $allowedExperts = $this->registeredRecommendedExperts(
                $requestedExperts,
                $analysis,
                $pluginResults,
                $routingPolicy
            );
            return [
                'is_sufficient' => (bool)($generated['is_sufficient'] ?? false),
                'reason' => trim((string)($generated['reason'] ?? 'The sufficiency assessor returned no reason.')),
                'missing_dimensions' => array_values(array_map('strval', (array)($generated['missing_dimensions'] ?? []))),
                'recommended_next_experts' => $allowedExperts,
                'rejected_recommended_experts' => array_values(array_filter(
                    $requestedExperts,
                    fn(string $plugin): bool => !$this->routingPolicyAllowsPlugin($plugin, $analysis, $routingPolicy)
                )),
            ];
        }

        $remainingPrimaryPath = $this->remainingPrimaryPath($routingPolicy, $pluginResults);
        if ($remainingPrimaryPath !== []) {
            return [
                'is_sufficient' => false,
                'reason' => 'The minimum evidence gate passed, but model-driven sufficiency assessment was unavailable, so the remaining primary path should continue.',
                'missing_dimensions' => ['model-driven sufficiency assessment unavailable'],
                'recommended_next_experts' => $remainingPrimaryPath,
            ];
        }

        return [
            'is_sufficient' => true,
            'reason' => 'The minimum evidence gate passed and no further model-driven expansion was available.',
            'missing_dimensions' => [],
            'recommended_next_experts' => [],
        ];
    }

    private function evaluateMinimumEvidenceGate(array $pluginResults, array $routingPolicy): array
    {
        $gate = (array)($routingPolicy['minimum_evidence_gate'] ?? []);
        $missing = [];

        foreach ((array)($gate['require_all_plugins'] ?? []) as $pluginName) {
            if ($pluginName === 'Site Navigator Plugin') {
                continue;
            }
            if (!isset($pluginResults[$pluginName])) {
                $missing[] = 'required plugin ' . $pluginName . ' has not run';
                continue;
            }
            if (!in_array((string)($pluginResults[$pluginName]['status'] ?? ''), ['ok', 'partial'], true)) {
                $allowExplicitEmpty = in_array($pluginName, (array)($gate['allow_explicit_empty_from'] ?? []), true);
                if (!$allowExplicitEmpty || (string)($pluginResults[$pluginName]['status'] ?? '') !== 'empty') {
                    $missing[] = 'required plugin ' . $pluginName . ' did not return usable results';
                }
            }
        }

        $requireAny = array_values(array_filter(
            (array)($gate['require_any_plugins'] ?? []),
            static fn($pluginName): bool => $pluginName !== 'Site Navigator Plugin'
        ));
        if ($requireAny !== []) {
            $matched = false;
            foreach ($requireAny as $pluginName) {
                if (!isset($pluginResults[$pluginName])) {
                    continue;
                }
                $status = (string)($pluginResults[$pluginName]['status'] ?? '');
                if (in_array($status, ['ok', 'partial'], true)) {
                    $matched = true;
                    break;
                }
                if (in_array($pluginName, (array)($gate['allow_explicit_empty_from'] ?? []), true) && $status === 'empty') {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $missing[] = 'none of the preferred experts produced a usable result';
            }
        }

        $evidenceCount = 0;
        $citationCount = 0;
        foreach ($pluginResults as $pluginName => $result) {
            if ($pluginName === 'Site Navigator Plugin') {
                continue;
            }
            $evidenceCount += count((array)($result['evidence_items'] ?? []));
            $citationCount += count(tekg_agent_plugin_result_citations((array)$result));
        }
        if ((int)($gate['min_evidence_items'] ?? 0) > $evidenceCount) {
            $missing[] = 'insufficient evidence items';
        }
        if ((int)($gate['min_citations'] ?? 0) > $citationCount) {
            $missing[] = 'insufficient traceable citations';
        }
        if ((bool)($gate['require_sortable_statistics'] ?? false)) {
            $hasSortable = false;
            foreach (['Graph Analytics Plugin', 'Cypher Explorer Plugin'] as $pluginName) {
                $rows = (array)($pluginResults[$pluginName]['results']['analytics_result']['top_k'] ?? $pluginResults[$pluginName]['results']['cypher_result']['rows'] ?? []);
                if ($rows !== []) {
                    $hasSortable = true;
                    break;
                }
            }
            if (!$hasSortable) {
                $missing[] = 'no sortable graph statistics were collected';
            }
        }

        return [
            'passed' => $missing === [],
            'reason' => $missing === [] ? 'The minimum evidence gate has been satisfied.' : 'The minimum evidence gate is still missing required dimensions.',
            'missing_dimensions' => $missing,
        ];
    }

    private function missingResearchSynthesisPlugins(
        array $analysis,
        array $planning,
        array $pluginResults,
        array $routingPolicy = []
    ): array
    {
        if ((string)($analysis['task_complexity'] ?? '') !== 'research_synthesis') {
            return [];
        }

        $required = [];
        foreach ((array)($planning['tool_plan'] ?? []) as $item) {
            $plugin = trim((string)($item['plugin'] ?? ''));
            if ($plugin === ''
                || $plugin === 'Citation Resolver'
                || $plugin === 'Site Navigator Plugin'
                || !$this->routingPolicyAllowsPlugin($plugin, $analysis, $routingPolicy)
            ) {
                continue;
            }
            if ($plugin === 'Literature Reading Plugin' && !$this->academicBusinessPluginMayRun($plugin, $analysis, $pluginResults)) {
                continue;
            }
            $required[] = $plugin;
        }

        if ($required === []) {
            return [];
        }

        $missing = [];
        foreach (array_values(array_unique($required)) as $plugin) {
            if (!isset($pluginResults[$plugin])) {
                $missing[] = $plugin;
                continue;
            }
            if (!in_array((string)($pluginResults[$plugin]['status'] ?? ''), ['ok', 'partial', 'empty'], true)) {
                $missing[] = $plugin;
            }
        }

        return array_values(array_unique($missing));
    }

    private function recommendedNextExperts(array $routingPolicy, array $pluginResults, array $missingDimensions, array $analysis = []): array
    {
        $executed = array_keys($pluginResults);
        $candidates = array_values(array_filter(array_map('strval', array_merge(
            (array)($routingPolicy['fallback_path'] ?? []),
            (array)($routingPolicy['candidate_experts'] ?? [])
        )), fn(string $plugin): bool => $plugin !== ''
            && $plugin !== 'Citation Resolver'
            && $this->routingPolicyAllowsPlugin($plugin, $analysis, $routingPolicy)));
        $recommended = [];
        foreach ($candidates as $plugin) {
            if (!in_array($plugin, $executed, true)) {
                $recommended[] = $plugin;
            }
        }
        if (($routingPolicy['cypher_explorer_fallback'] ?? false) && !in_array('Cypher Explorer Plugin', $executed, true)) {
            $recommended[] = 'Cypher Explorer Plugin';
        }
        return $this->registeredRecommendedExperts($recommended, $analysis, $pluginResults, $routingPolicy);
    }

    private function remainingPrimaryPath(array $routingPolicy, array $pluginResults): array
    {
        $executed = array_keys($pluginResults);
        $forbidden = array_values(array_filter(array_map('strval', (array)($routingPolicy['forbidden_path'] ?? []))));
        $remaining = [];
        foreach ((array)($routingPolicy['primary_path'] ?? []) as $pluginName) {
            $pluginName = trim((string)$pluginName);
            if ($pluginName === '' || in_array($pluginName, $executed, true) || in_array($pluginName, $forbidden, true)) {
                continue;
            }
            $remaining[] = $pluginName;
        }
        return array_values(array_unique($remaining));
    }

    private function maybeAppendPlugins(array $analysis, array $planning, string $pluginName, array $result, array $queue): array
    {
        $append = [];
        $intent = (string)($analysis['intent'] ?? 'relationship');
        $simpleQuestion = (string)($analysis['task_complexity'] ?? '') !== 'research_synthesis'
            && in_array($intent, ['sequence', 'relationship', 'classification', 'expression', 'genome'], true);
        $explicitLiteratureNeed = (bool)($analysis['asks_for_papers'] ?? false) || in_array($intent, ['literature', 'mechanism', 'comparison'], true);

        if ($pluginName === 'Graph Plugin') {
            $relationCount = (int)($result['result_counts']['relations'] ?? 0);
            if ($relationCount === 0 && $explicitLiteratureNeed && !in_array('Literature Plugin', $queue, true)) {
                $append[] = 'Literature Plugin';
            }
            if (($analysis['asks_for_graph_analytics'] ?? false) && !in_array('Graph Analytics Plugin', $queue, true)) {
                $append[] = 'Graph Analytics Plugin';
            }
            if (($analysis['asks_for_cypher_explorer'] ?? false) && !in_array('Cypher Explorer Plugin', $queue, true)) {
                $append[] = 'Cypher Explorer Plugin';
            }
            if ($relationCount < 3 && $intent === 'mechanism' && !in_array('Sequence Plugin', $queue, true) && ($analysis['asks_for_sequence'] ?? false)) {
                $append[] = 'Sequence Plugin';
            }
        }

        if ($pluginName === 'Graph Analytics Plugin') {
            $topRows = (int)($result['result_counts']['top_k'] ?? 0);
            if ($topRows === 0 && !in_array('Cypher Explorer Plugin', $queue, true)) {
                $append[] = 'Cypher Explorer Plugin';
            }
        }

        if ($pluginName === 'Literature Plugin') {
            $reviewedCount = (int)($result['result_counts']['reviewed'] ?? 0);
            if ($reviewedCount === 0 && ($analysis['asks_for_classification'] ?? false) && !in_array('Tree Plugin', $queue, true)) {
                $append[] = 'Tree Plugin';
            }
            if ($reviewedCount > 0
                && !$simpleQuestion
                && $this->academicBusinessPluginMayRun('Literature Reading Plugin', $analysis, ['Literature Plugin' => $result])
                && !in_array('Literature Reading Plugin', $queue, true)
            ) {
                $append[] = 'Literature Reading Plugin';
            }
        }

        return array_values(array_unique($append));
    }

    private function academicBusinessPluginMayRun(string $pluginName, array $analysis, array $pluginResults): bool
    {
        if ($pluginName !== 'Literature Reading Plugin') {
            return true;
        }
        $literature = (array)($pluginResults['Literature Plugin'] ?? []);
        $intent = (string)($analysis['intent'] ?? '');
        $hasLiteratureSemantics = (bool)($analysis['asks_for_papers'] ?? false)
            || in_array($intent, ['literature', 'mechanism', 'comparison'], true)
            || (string)($analysis['task_complexity'] ?? '') === 'research_synthesis';
        return $hasLiteratureSemantics
            && in_array((string)($literature['status'] ?? ''), ['ok', 'partial'], true)
            && tekg_agent_plugin_result_citations($literature) !== [];
    }

    private function registeredRecommendedExperts(
        array $plugins,
        array $analysis,
        array $pluginResults,
        array $routingPolicy = []
    ): array
    {
        $registered = array_keys($this->plugins);
        $executed = array_keys($pluginResults);
        $recommended = [];
        foreach (array_values(array_map('strval', $plugins)) as $plugin) {
            $plugin = trim($plugin);
            if ($plugin === ''
                || in_array($plugin, ['Entity Resolver', 'Citation Resolver'], true)
                || !in_array($plugin, $registered, true)
                || in_array($plugin, $executed, true)
                || !$this->routingPolicyAllowsPlugin($plugin, $analysis, $routingPolicy)
                || !$this->academicBusinessPluginMayRun($plugin, $analysis, $pluginResults)
            ) {
                continue;
            }
            $recommended[] = $plugin;
        }
        return array_values(array_unique($recommended));
    }

    private function routingPolicyAllowsPlugin(string $pluginName, array $analysis, array $routingPolicy): bool
    {
        $pluginName = trim($pluginName);
        if ($pluginName === '') {
            return false;
        }
        $forbidden = array_values(array_filter(array_map('strval', (array)($routingPolicy['forbidden_path'] ?? []))));
        if (!in_array($pluginName, $forbidden, true)) {
            return true;
        }

        return match ($pluginName) {
            'Literature Plugin', 'Literature Reading Plugin' => (bool)($analysis['asks_for_papers'] ?? false),
            'Cypher Explorer Plugin' => (bool)($analysis['asks_for_cypher_explorer'] ?? false),
            'Site Navigator Plugin' => (bool)($analysis['asks_for_site_navigation'] ?? false),
            'Tree Plugin' => (bool)($analysis['asks_for_classification'] ?? false),
            'Expression Plugin' => (bool)($analysis['asks_for_expression'] ?? false),
            'eQTL Plugin' => (bool)($analysis['asks_for_eqtl'] ?? false),
            'Genome Plugin' => (bool)($analysis['asks_for_genome'] ?? false),
            'Sequence Plugin' => (bool)($analysis['asks_for_sequence'] ?? false),
            'Graph Analytics Plugin' => (bool)($analysis['asks_for_graph_analytics'] ?? false),
            default => false,
        };
    }

    private function normalizeRoutingPolicy(array $selected, string $intent): array
    {
        $primaryPath = array_values(array_filter(array_map('strval', (array)($selected['primary_path'] ?? []))));
        $fallbackPath = array_values(array_filter(array_map('strval', (array)($selected['fallback_path'] ?? []))));
        $forbiddenPath = array_values(array_filter(array_map('strval', (array)($selected['forbidden_path'] ?? []))));
        $candidateExperts = array_values(array_filter(array_map('strval', (array)($selected['candidate_experts'] ?? []))));
        if ($candidateExperts === []) {
            $candidateExperts = array_values(array_unique(array_merge($primaryPath, $fallbackPath)));
        }

        $selected['question_type'] = $intent;
        $selected['primary_path'] = $primaryPath;
        $selected['fallback_path'] = $fallbackPath;
        $selected['forbidden_path'] = $forbiddenPath;
        $selected['candidate_experts'] = $candidateExperts;
        return $selected;
    }

    private function isHardStopIntent(string $intent): bool
    {
        return in_array($intent, ['sequence', 'relationship', 'classification', 'expression', 'eqtl', 'genome', 'graph_analytics'], true);
    }

    private function evaluateHardStopCondition(array $analysis, array $pluginResults, array $routingPolicy): ?array
    {
        $intent = (string)($analysis['intent'] ?? '');
        $hardStop = (array)($routingPolicy['hard_stop_conditions'] ?? []);
        $primaryPlugin = trim((string)($hardStop['primary_plugin'] ?? ''));
        if ($primaryPlugin === '' || !isset($pluginResults[$primaryPlugin])) {
            return null;
        }

        $result = (array)$pluginResults[$primaryPlugin];
        $status = (string)($result['status'] ?? '');
        $allowedStatuses = array_values(array_filter(array_map('strval', (array)($hardStop['allow_statuses'] ?? ['ok', 'partial']))));
        if (!in_array($status, $allowedStatuses, true)) {
            return null;
        }

        $countKey = trim((string)($hardStop['min_result_count_key'] ?? ''));
        if ($countKey !== '') {
            $countValue = (int)($result['result_counts'][$countKey] ?? 0);
            $minCount = max(1, (int)($hardStop['min_result_count'] ?? 1));
            if ($countValue < $minCount) {
                return null;
            }
        }

        if ((bool)($hardStop['require_sortable_statistics'] ?? false)) {
            $rows = (array)($result['results']['analytics_result']['top_k'] ?? $result['results']['cypher_result']['rows'] ?? []);
            if ($rows === []) {
                return null;
            }
        }

        return [
            'is_sufficient' => true,
            'reason' => 'The primary path has already produced enough evidence for this question type.',
            'missing_dimensions' => [],
            'recommended_next_experts' => [],
        ];
    }

    private function aggregateEvidence(array $pluginResults): array
    {
        $all = [];
        foreach ($pluginResults as $pluginName => $result) {
            if ($pluginName === 'Site Navigator Plugin') {
                continue;
            }
            foreach ((array)($result['evidence_items'] ?? []) as $item) {
                $normalized = tekg_agent_normalize_evidence_item($item, $pluginName);
                if ($normalized !== null && !tekg_agent_is_diagnostic_evidence($normalized)) {
                    $all[] = $normalized;
                }
            }
        }

        $seen = [];
        $unique = [];
        foreach ($all as $item) {
            $key = strtolower(trim((string)($item['claim'] ?? ''))) . '::' . strtolower(trim((string)($item['source_plugin'] ?? '')));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $item;
        }

        usort($unique, static function (array $left, array $right): int {
            $order = ['high' => 3, 'medium' => 2, 'low' => 1];
            return ($order[$right['support_strength']] ?? 0) <=> ($order[$left['support_strength']] ?? 0);
        });

        return $unique;
    }

    private function compressedPluginResults(array $pluginResults): array
    {
        $compressed = [];
        foreach ($pluginResults as $pluginName => $result) {
            $compressed[$pluginName] = [
                'plugin_name' => $pluginName,
                'status' => (string)($result['status'] ?? 'unknown'),
                'compressed_result' => (array)($result['compressed_result'] ?? []),
            ];
        }
        return $compressed;
    }

    private function buildSynthesizedEvidence(array $pluginResults, array $evidence): array
    {
        $supportedClaims = [];
        $conflictingClaims = [];
        $missingEvidence = [];
        $claimClusters = [];

        $literatureSynthesis = (array)($pluginResults['Literature Reading Plugin']['results'] ?? []);
        if ($literatureSynthesis !== []) {
            $supportedClaims = array_values(array_map('strval', (array)($literatureSynthesis['supported_claims'] ?? [])));
            $conflictingClaims = array_values(array_map('strval', (array)($literatureSynthesis['conflicting_claims'] ?? [])));
            $missingEvidence = array_values(array_map('strval', (array)($literatureSynthesis['missing_evidence'] ?? [])));
            $claimClusters = array_values((array)($literatureSynthesis['claim_clusters'] ?? []));
        }

        if ($supportedClaims === []) {
            foreach (array_slice($evidence, 0, 8) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $claim = trim((string)($item['claim'] ?? ''));
                if ($claim !== '') {
                    $supportedClaims[] = $claim;
                }
            }
        }

        if ($claimClusters === []) {
            foreach (array_slice($evidence, 0, 6) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $claim = trim((string)($item['claim'] ?? ''));
                if ($claim === '') {
                    continue;
                }
                $claimClusters[] = [
                    'claim' => $claim,
                    'summary' => trim((string)($item['body'] ?? $claim)),
                    'citations' => [],
                ];
            }
        }

        return [
            'supported_claims' => array_values(array_unique(array_filter($supportedClaims))),
            'conflicting_claims' => array_values(array_unique(array_filter($conflictingClaims))),
            'missing_evidence' => array_values(array_unique(array_filter($missingEvidence))),
            'claim_clusters' => $claimClusters,
        ];
    }

    private function buildValidatedEvidencePackage(string $question, array $analysis, array $pluginResults, string $requestId): array
    {
        $package = EvidencePackage::fromPluginResults($question, $analysis, $pluginResults, [
            'summary_max_chars' => 640,
        ]);
        $validation = EvidencePackage::validate($package);
        if (($validation['ok'] ?? false) !== true) {
            $errors = array_values(array_map('strval', (array)($validation['errors'] ?? [])));
            $this->logDiagnostic($requestId, 'evidence_package_validation_failed', [
                'errors' => $errors,
            ]);
            throw new RuntimeException('EvidencePackage validation failed: ' . implode('; ', $errors));
        }
        $this->logDiagnostic($requestId, 'evidence_package_validated', [
            'claim_count' => (int)($package['metrics']['claim_count'] ?? 0),
            'evidence_count' => (int)($package['metrics']['evidence_count'] ?? 0),
            'citation_count' => (int)($package['metrics']['citation_count'] ?? 0),
            'route_count' => (int)($package['metrics']['route_count'] ?? 0),
        ]);
        return $package;
    }

    private function buildSynthesizedEvidenceFromPackage(array $evidencePackage): array
    {
        $supportedClaims = [];
        $missingEvidence = [];
        $claimClusters = [];

        foreach ((array)($evidencePackage['claims'] ?? []) as $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $text = trim((string)($claim['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $supportedClaims[] = $text;
            $claimClusters[] = [
                'claim' => $text,
                'summary' => $text,
                'citations' => array_values((array)($claim['citation_ids'] ?? [])),
            ];
        }

        foreach ((array)($evidencePackage['errors'] ?? []) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $message = trim((string)($error['message'] ?? ''));
            if ($message !== '') {
                $missingEvidence[] = trim((string)($error['plugin'] ?? 'Plugin')) . ': ' . $message;
            }
        }

        if ($supportedClaims === []) {
            $missingEvidence[] = 'The evidence package contains no supported claims for final writing.';
        }

        return [
            'supported_claims' => array_values(array_unique($supportedClaims)),
            'conflicting_claims' => [],
            'missing_evidence' => array_values(array_unique($missingEvidence)),
            'claim_clusters' => $claimClusters,
        ];
    }

    private function citationsFromEvidencePackage(array $evidencePackage): array
    {
        $citations = [];
        foreach ((array)($evidencePackage['citation_map'] ?? []) as $item) {
            if (is_array($item) && isset($item['citation']) && is_array($item['citation'])) {
                $citations[] = $item['citation'];
            }
        }
        return $citations;
    }

    private function generateAnswerStructure(
        string $model,
        string $question,
        array $analysis,
        array $synthesizedEvidence,
        array $citations,
        array $sufficiencyDecision,
        string $requestId
    ): array {
        $payload = $this->buildAnswerStructurePayload($question, $analysis, $synthesizedEvidence, $citations, $sufficiencyDecision);
        try {
            $generated = $this->llm->generateAnswerStructure($model, $payload, max(10, min(15, (int)($this->config['llm_json_timeout'] ?? 15))));
        } catch (Throwable $error) {
            $this->logDiagnostic($requestId, 'answer_structure_error', [
                'error' => $error->getMessage(),
            ]);
            $generated = null;
        }
        if (is_array($generated)) {
            $normalized = $this->normalizeAnswerStructure($generated);
            if ($this->isValidAnswerStructure($normalized)) {
                return $normalized;
            }
        }
        $this->logDiagnostic($requestId, 'answer_structure_fallback', [
            'reason' => 'model_generation_failed_or_invalid',
        ]);
        return $this->fallbackAnswerStructure($analysis, $synthesizedEvidence);
    }

    private function isValidAnswerStructure(array $structure): bool
    {
        return in_array(trim((string)($structure['response_mode'] ?? '')), [
            'mechanism_chain',
            'contrastive',
            'literature_support',
            'lineage_explanation',
            'ranking_summary',
            'evidence_summary',
            'declarative',
        ], true)
            && is_array($structure['section_plan'] ?? null)
            && is_array($structure['claim_order'] ?? null)
            && is_array($structure['uncertainty_notes'] ?? null);
    }

    private function normalizeAnswerStructure(array $structure): array
    {
        $normalized = $structure;
        $normalized['response_mode'] = trim((string)($structure['response_mode'] ?? ''));
        $normalized['opening_claim'] = trim((string)($structure['opening_claim'] ?? ''));
        $normalized['citation_policy'] = trim((string)($structure['citation_policy'] ?? ''));
        $normalized['section_plan'] = array_values(array_filter(array_map('strval', (array)($structure['section_plan'] ?? []))));
        $normalized['claim_order'] = array_values(array_filter(array_map('strval', (array)($structure['claim_order'] ?? []))));
        $normalized['uncertainty_notes'] = array_values(array_filter(array_map('strval', (array)($structure['uncertainty_notes'] ?? []))));
        return $normalized;
    }

    private function fallbackAnswerStructure(array $analysis, array $synthesizedEvidence): array
    {
        $intent = (string)($analysis['intent'] ?? 'relationship');
        $responseMode = match ($intent) {
            'mechanism' => 'mechanism_chain',
            'comparison' => 'contrastive',
            'literature' => 'literature_support',
            'classification' => 'lineage_explanation',
            'graph_analytics' => 'ranking_summary',
            default => 'evidence_summary',
        };

        return [
            'response_mode' => $responseMode,
            'opening_claim' => (string)($synthesizedEvidence['supported_claims'][0] ?? 'State the strongest supported claim first.'),
            'section_plan' => [
                'Main judgment',
                'Supporting evidence',
                'Evidence gaps and limits',
            ],
            'claim_order' => array_values(array_slice((array)($synthesizedEvidence['supported_claims'] ?? []), 0, 6)),
            'citation_policy' => 'Use PMID-style in-text citations when available.',
            'uncertainty_notes' => array_values(array_slice((array)($synthesizedEvidence['missing_evidence'] ?? []), 0, 4)),
        ];
    }

    private function buildAnswerStructurePayload(
        string $question,
        array $analysis,
        array $synthesizedEvidence,
        array $citations,
        array $sufficiencyDecision
    ): array {
        return [
            'question' => $question,
            'answer_language' => (string)($analysis['answer_language'] ?? $analysis['language'] ?? 'english'),
            'process_language' => (string)($analysis['process_language'] ?? $analysis['answer_language'] ?? $analysis['language'] ?? 'english'),
            'intent' => (string)($analysis['intent'] ?? ''),
            'complexity' => (string)($analysis['complexity'] ?? ''),
            'normalized_entities' => array_slice((array)($analysis['normalized_entities'] ?? []), 0, 4),
            'supported_claims' => $this->limitClaimTexts((array)($synthesizedEvidence['supported_claims'] ?? []), 6),
            'conflicting_claims' => $this->limitClaimTexts((array)($synthesizedEvidence['conflicting_claims'] ?? []), 3),
            'missing_evidence' => $this->limitClaimTexts((array)($synthesizedEvidence['missing_evidence'] ?? []), 4),
            'citation_count' => count($citations),
            'sufficiency_decision' => [
                'is_sufficient' => (bool)($sufficiencyDecision['is_sufficient'] ?? false),
                'reason' => (string)($sufficiencyDecision['reason'] ?? ''),
                'missing_dimensions' => array_slice(array_values(array_map('strval', (array)($sufficiencyDecision['missing_dimensions'] ?? []))), 0, 4),
            ],
        ];
    }

    private function analysisForWriting(array $analysis): array
    {
        return [
            'intent' => (string)($analysis['intent'] ?? ''),
            'complexity' => (string)($analysis['complexity'] ?? ''),
            'normalized_entities' => array_slice((array)($analysis['normalized_entities'] ?? []), 0, 4),
            'requested_target_types' => array_slice((array)($analysis['requested_target_types'] ?? []), 0, 6),
        ];
    }

    private function limitClaimTexts(array $claims, int $limit): array
    {
        $clean = [];
        foreach ($claims as $claim) {
            $text = trim((string)$claim);
            if ($text === '') {
                continue;
            }
            $clean[] = $text;
        }
        return array_values(array_slice(array_unique($clean), 0, $limit));
    }

    private function lightweightCitations(array $citations, int $limit): array
    {
        $light = [];
        foreach (array_slice($citations, 0, $limit) as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $light[] = [
                'pmid' => (string)($citation['pmid'] ?? ''),
                'title' => (string)($citation['title'] ?? ''),
                'journal' => (string)($citation['journal'] ?? $citation['source'] ?? ''),
                'year' => (string)($citation['year'] ?? ''),
                'url' => (string)($citation['url'] ?? ''),
            ];
        }
        return $light;
    }

    private function aggregateCitations(array $pluginResults): array
    {
        if (isset($pluginResults['Citation Resolver']) && is_array($pluginResults['Citation Resolver'])) {
            $resolved = tekg_agent_plugin_result_citations((array)$pluginResults['Citation Resolver']);
            if ($resolved !== []) {
                return $this->citationResolver->normalizeMany($resolved);
            }
        }

        $all = [];
        foreach ($pluginResults as $result) {
            foreach (tekg_agent_plugin_result_citations((array)$result) as $citation) {
                $all[] = $citation;
            }
        }
        $citations = $this->citationResolver->normalizeMany($all);
        usort($citations, static function (array $left, array $right): int {
            $leftPmid = trim((string)($left['pmid'] ?? ''));
            $rightPmid = trim((string)($right['pmid'] ?? ''));
            if ($leftPmid !== '' && $rightPmid !== '') {
                return strcmp($leftPmid, $rightPmid);
            }
            return strcasecmp((string)($left['title'] ?? ''), (string)($right['title'] ?? ''));
        });
        return $citations;
    }

    private function aggregateLimits(array $pluginResults, array $evidence): array
    {
        $limits = [];
        foreach ($pluginResults as $result) {
            foreach ((array)($result['errors'] ?? []) as $error) {
                $limits[] = (string)$error;
            }
        }
        if ($evidence === []) {
            $limits[] = 'There was not enough direct structured or external evidence for a stronger answer.';
        }
        $citationCount = count($this->aggregateCitations($pluginResults));
        if ($citationCount === 0) {
            $limits[] = 'No directly traceable citation could be attached to this answer in the current round.';
        }
        return array_values(array_unique($limits));
    }

    private function inferConfidence(array $pluginResults, array $evidence, array $citations): string
    {
        $okPlugins = 0;
        foreach ($pluginResults as $result) {
            if (in_array((string)($result['status'] ?? ''), ['ok', 'partial'], true)) {
                $okPlugins++;
            }
        }

        $strongEvidence = count(array_filter($evidence, static fn(array $item): bool => (string)($item['support_strength'] ?? 'low') === 'high'));
        if ($okPlugins >= 3 && $strongEvidence >= 2 && count($citations) >= 3) {
            return 'high';
        }
        if ($okPlugins >= 2 && count($evidence) >= 3) {
            return 'medium';
        }
        return 'low';
    }

    private function updateSessionMemory(
        array $memory,
        array $analysis,
        array $planning,
        array $pluginResults,
        array $citations,
        array $evidence,
        array $collectionState,
        array $synthesizedEvidence
    ): array
    {
        $memory = array_replace(tekg_agent_default_session_memory(), $memory);
        $memory['topic_entities'] = array_values(array_unique(array_map(
            static fn(array $entity): string => (string)($entity['canonical_label'] ?? $entity['label'] ?? ''),
            (array)($analysis['normalized_entities'] ?? [])
        )));
        $memory['last_intent'] = (string)($analysis['intent'] ?? '');
        $memory['resolved_entities'] = array_values(array_map(
            static fn(array $entity): array => [
                'label' => (string)($entity['canonical_label'] ?? $entity['label'] ?? ''),
                'type' => (string)($entity['entity_type'] ?? $entity['type'] ?? ''),
                'confidence' => (float)($entity['confidence'] ?? 0.0),
            ],
            (array)($analysis['normalized_entities'] ?? [])
        ));
        $memory['active_gaps'] = array_values((array)($collectionState['active_gaps'] ?? []));
        $memory['closed_gaps'] = array_values((array)($collectionState['closed_gaps'] ?? []));
        $memory['confirmed_claims'] = array_values(array_unique(array_map(
            static fn(array $item): string => (string)($item['claim'] ?? ''),
            array_slice($evidence, 0, 8)
        )));
        $memory['strong_claims'] = array_values(array_unique(array_map(
            static fn(array $item): string => (string)($item['claim'] ?? ''),
            array_filter($evidence, static fn(array $item): bool => (string)($item['support_strength'] ?? '') === 'high')
        )));
        $memory['weak_claims'] = array_values(array_unique(array_map(
            static fn(array $item): string => (string)($item['claim'] ?? ''),
            array_filter($evidence, static fn(array $item): bool => in_array((string)($item['support_strength'] ?? ''), ['low', 'medium'], true))
        )));
        $memory['claim_status_by_source'] = tekg_agent_json_safe(array_map(
            static fn(array $item): array => [
                'claim' => (string)($item['claim'] ?? ''),
                'source_plugin' => (string)($item['source_plugin'] ?? ''),
                'support_strength' => (string)($item['support_strength'] ?? 'medium'),
            ],
            array_slice($evidence, 0, 16)
        ));
        $memory['citations'] = array_values(array_slice(array_map(
            static fn(array $citation): string => (string)($citation['pmid'] ?? $citation['title'] ?? ''),
            $citations
        ), 0, 12));
        $memory['failed_aliases'] = array_values(array_unique(array_merge(
            (array)($memory['failed_aliases'] ?? []),
            $this->collectFailedBroadAliases($analysis, $pluginResults)
        )));
        $memory['tool_history'] = array_values(array_slice(array_map(
            static fn(array $item): string => (string)($item['plugin'] ?? ''),
            (array)($planning['tool_plan'] ?? [])
        ), -10));
        $memory['expert_attempts'] = tekg_agent_json_safe(array_map(
            static fn(string $pluginName, array $result): array => [
                'plugin' => $pluginName,
                'status' => (string)($result['status'] ?? 'unknown'),
                'latency_ms' => (int)($result['latency_ms'] ?? 0),
            ],
            array_keys($pluginResults),
            array_values($pluginResults)
        ));
        $memory['failed_queries'] = tekg_agent_json_safe(array_values(array_filter(array_map(
            static fn(string $pluginName, array $result): ?array => in_array((string)($result['status'] ?? ''), ['empty', 'error'], true)
                ? [
                    'plugin' => $pluginName,
                    'status' => (string)($result['status'] ?? ''),
                    'summary' => (string)($result['display_summary'] ?? $result['query_summary'] ?? ''),
                ]
                : null,
            array_keys($pluginResults),
            array_values($pluginResults)
        ))));
        $memory['compression_notes'] = tekg_agent_json_safe(array_values(array_filter(array_map(
            static fn(array $result): ?array => isset($result['compressed_result'])
                ? [
                    'plugin' => (string)($result['plugin_name'] ?? ''),
                    'key_findings' => (array)($result['compressed_result']['key_findings'] ?? []),
                    'limitations' => (array)($result['compressed_result']['limitations'] ?? []),
                ]
                : null,
            array_values($pluginResults)
        ))));
        $memory['next_step_hints'] = tekg_agent_json_safe(array_values(array_slice(array_filter([
            (array)($planning['subtasks'] ?? []),
            (array)($collectionState['remaining_candidates'] ?? []),
            (array)($synthesizedEvidence['missing_evidence'] ?? []),
        ]), 0, 3)));
        $memory['session_snapshot'] = tekg_agent_json_safe([
            'intent' => (string)($analysis['intent'] ?? ''),
            'resolved_entities' => $memory['resolved_entities'],
            'closed_gaps' => $memory['closed_gaps'],
            'strong_claims' => array_slice((array)$memory['strong_claims'], 0, 6),
            'next_step_hints' => $memory['next_step_hints'],
        ]);

        return $memory;
    }
}
