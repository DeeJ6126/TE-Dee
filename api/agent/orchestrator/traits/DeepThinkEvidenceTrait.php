<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/contracts/PluginResultProjection.php';

trait TekgDeepThinkEvidenceTrait
{
    private function augmentPluginResult(string $pluginName, array $result, array $analysis, array $planning): array
    {
        $rawResult = tekg_agent_json_safe((array)($result['results'] ?? []));
        $result['raw_result'] = $rawResult;
        $result['compressed_result'] = $this->compressPluginResult($pluginName, $result, $analysis, $planning);
        return $result;
    }

    private function compressPluginResult(string $pluginName, array $result, array $analysis, array $planning): array
    {
        return PluginResultProjection::forLlmContext($pluginName, $result, $analysis, $planning);
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
                if (is_array($item)) {
                    $claim = trim((string)($item['claim'] ?? ''));
                    if ($claim !== '') {
                        $supportedClaims[] = $claim;
                    }
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

    private function generateAnswerStructure(string $model, string $question, array $analysis, array $synthesizedEvidence, array $citations, array $decision, string $requestId): array
    {
        $payload = [
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
            'decision' => $decision,
        ];

        try {
            $generated = $this->llm->generateAnswerStructure($model, $payload, max(10, (int)($this->config['llm_json_timeout'] ?? 20)));
        } catch (Throwable $error) {
            $generated = null;
            $this->logDiagnostic($requestId, 'deepthink_answer_structure_error', ['error' => $error->getMessage()]);
        }
        if (is_array($generated)) {
            $normalized = $this->normalizeAnswerStructure($generated);
            if ($this->isValidAnswerStructure($normalized)) {
                return $normalized;
            }
        }
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
            'section_plan' => ['Main judgment', 'Supporting evidence', 'Evidence gaps and limits'],
            'claim_order' => array_values(array_slice((array)($synthesizedEvidence['supported_claims'] ?? []), 0, 6)),
            'citation_policy' => 'Use PMID-style in-text citations when available.',
            'uncertainty_notes' => array_values(array_slice((array)($synthesizedEvidence['missing_evidence'] ?? []), 0, 4)),
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

    private function extraWritingContext(string $question, array $analysis, array $pluginResults): array
    {
        $context = [];

        if (($analysis['intent'] ?? '') === 'sequence' && $this->wantsFullSequenceOutput($question)) {
            $matched = (array)($pluginResults['Sequence Plugin']['results']['matched_records'] ?? []);
            $fullSequences = [];
            foreach (array_slice($matched, 0, 1) as $match) {
                if (!is_array($match)) {
                    continue;
                }
                $entry = (array)($match['entry'] ?? []);
                $sequence = preg_replace('/\s+/u', '', (string)($entry['sequence'] ?? '')) ?? '';
                if ($sequence === '') {
                    continue;
                }
                $fullSequences[] = [
                    'label' => (string)($entry['name'] ?? $match['repbase_name'] ?? $match['entity_label'] ?? ''),
                    'length' => isset($entry['length']) ? (int)$entry['length'] : null,
                    'sequence' => $sequence,
                ];
            }
            if ($fullSequences !== []) {
                $context['full_sequences'] = $fullSequences;
            }
        }

        if (($analysis['intent'] ?? '') === 'relationship' && $this->relationshipQuestionNeedsSynthesis($question)) {
            $groups = $this->groupRelationshipRowsForWriting((array)($pluginResults['Graph Plugin']['results']['rows'] ?? []));
            if ($groups !== []) {
                $context['relationship_groups'] = $groups;
                $context['relationship_synthesis_instruction'] = 'Cover the main graph dimensions instead of focusing only on the first category: disease links, mechanisms/functions, gene/protein/RNA evidence, and limitations when present.';
            }
        }

        return $context;
    }

    private function groupRelationshipRowsForWriting(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $target = trim((string)($row['target_name'] ?? ''));
            if ($target === '') {
                continue;
            }
            $targetType = trim((string)($row['target_type'] ?? ''));
            $targetLabels = array_map('strval', (array)($row['target_labels'] ?? []));
            $resolvedType = $targetType !== '' ? $targetType : trim((string)($targetLabels[0] ?? 'Unknown'));
            if ($resolvedType === '') {
                $resolvedType = 'Unknown';
            }
            if (!isset($groups[$resolvedType])) {
                $groups[$resolvedType] = [
                    'count' => 0,
                    'examples' => [],
                ];
            }
            $groups[$resolvedType]['count']++;
            if (count($groups[$resolvedType]['examples']) >= 5) {
                continue;
            }
            $description = trim((string)($row['relation_description'] ?? ''));
            $groups[$resolvedType]['examples'][] = [
                'target' => $target,
                'relation' => trim((string)($row['relation_type'] ?? 'related_to')),
                'description' => $description,
            ];
        }

        ksort($groups);
        return $groups;
    }

    private function wantsFullSequenceOutput(string $question): bool
    {
        $normalized = tekg_agent_lower($question);
        foreach ([
            '完整序列',
            '完整的序列',
            'full sequence',
            'complete sequence',
            'entire sequence',
            'full-length sequence',
        ] as $needle) {
            if (str_contains($normalized, tekg_agent_lower($needle))) {
                return true;
            }
        }
        return false;
    }

    private function buildDeterministicAnswer(string $question, string $answerLanguage, array $analysis, array $pluginResults, array $citations): ?array
    {
        $siteNavigation = $this->buildDirectSiteNavigationAnswer($analysis, $pluginResults);
        if ($siteNavigation !== null) {
            return [
                'path' => 'direct_site_navigation',
                'body' => $siteNavigation,
                'summary_hint' => 'Preserve every Markdown link exactly. Do not rewrite URLs as plain text.',
                'summary_required' => false,
            ];
        }

        $sequenceFact = $this->buildDirectSequenceFactAnswer($question, $answerLanguage, $analysis, $pluginResults, $citations);
        if ($sequenceFact !== null) {
            return [
                'path' => 'direct_sequence_fact',
                'body' => $sequenceFact,
                'summary_hint' => 'This is already a complete local sequence fact answer.',
                'summary_required' => false,
            ];
        }

        $sequence = $this->buildDirectFullSequenceAnswer($question, $answerLanguage, $analysis, $pluginResults, $citations);
        if ($sequence !== null) {
            return [
                'path' => 'direct_full_sequence',
                'body' => $sequence,
                'summary_hint' => 'Provide only a short summary after the full sequence. Do not repeat the sequence itself.',
                'summary_required' => false,
            ];
        }

        $relationships = $this->buildDirectRelationshipAnswer($question, $answerLanguage, $analysis, $pluginResults, $citations);
        if ($relationships !== null) {
            return [
                'path' => 'direct_full_relationship_list',
                'body' => $relationships,
                'summary_hint' => 'Provide only a short summary after the full relationship list. Do not enumerate the full list again.',
                'summary_required' => false,
            ];
        }

        return null;
    }

    private function buildDirectSiteNavigationAnswer(array $analysis, array $pluginResults): ?string
    {
        if (!($analysis['asks_for_site_navigation'] ?? false) && !isset($pluginResults['Site Navigator Plugin'])) {
            return null;
        }
        $result = (array)($pluginResults['Site Navigator Plugin'] ?? []);
        if (!in_array((string)($result['status'] ?? ''), ['ok', 'partial'], true)) {
            return null;
        }
        $results = (array)($result['results'] ?? []);
        $answer = trim((string)($results['answer_markdown'] ?? ''));
        return $answer !== '' ? $answer : null;
    }

    private function buildDirectFullSequenceAnswer(string $question, string $answerLanguage, array $analysis, array $pluginResults, array $citations): ?string
    {
        if (($analysis['intent'] ?? '') !== 'sequence') {
            return null;
        }
        if (!$this->wantsFullSequenceOutput($question)) {
            return null;
        }

        $matched = (array)($pluginResults['Sequence Plugin']['results']['matched_records'] ?? []);
        $first = $matched[0] ?? null;
        if (!is_array($first)) {
            return null;
        }
        $entry = (array)($first['entry'] ?? []);
        $label = (string)($entry['name'] ?? $first['repbase_name'] ?? $first['entity_label'] ?? 'the TE');
        $sequence = preg_replace('/\s+/u', '', (string)($entry['sequence'] ?? '')) ?? '';
        if ($sequence === '') {
            return null;
        }
        $length = isset($entry['length']) ? (int)$entry['length'] : strlen($sequence);
        $citationText = $this->formatSequenceCitations($citations);

        if ($answerLanguage === 'chinese') {
            $prefix = "是的，{$label} 有完整的序列信息。\n\n";
            $prefix .= "当前匹配到的共识序列长度为 {$length} bp。下面给出完整序列：\n\n";
            $body = "```text\n{$sequence}\n```\n";
            $suffix = $citationText !== '' ? "\n参考来源：{$citationText}" : '';
            return $prefix . $body . $suffix;
        }

        $prefix = "Yes. {$label} has a complete sequence record.\n\n";
        $prefix .= "The matched consensus sequence length is {$length} bp. The full sequence is shown below:\n\n";
        $body = "```text\n{$sequence}\n```\n";
        $suffix = $citationText !== '' ? "\nSources: {$citationText}" : '';
        return $prefix . $body . $suffix;
    }

    private function buildDirectSequenceFactAnswer(string $question, string $answerLanguage, array $analysis, array $pluginResults, array $citations): ?string
    {
        if (($analysis['intent'] ?? '') !== 'sequence') {
            return null;
        }
        if ($this->wantsFullSequenceOutput($question)) {
            return null;
        }

        $matched = (array)($pluginResults['Sequence Plugin']['results']['matched_records'] ?? []);
        $first = $matched[0] ?? null;
        if (!is_array($first)) {
            return null;
        }
        $entry = (array)($first['entry'] ?? []);
        $label = (string)($entry['name'] ?? $first['repbase_name'] ?? $first['entity_label'] ?? 'the TE');
        $length = null;
        if (isset($entry['length'])) {
            $length = (int)$entry['length'];
        } elseif (isset($first['length'])) {
            $length = (int)$first['length'];
        } else {
            $sequence = preg_replace('/\s+/u', '', (string)($entry['sequence'] ?? '')) ?? '';
            $length = $sequence !== '' ? strlen($sequence) : null;
        }
        if ($length === null || $length <= 0) {
            return null;
        }

        $sourceLabels = [];
        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $source = trim((string)($citation['source'] ?? ''));
            if ($source !== '') {
                $sourceLabels[] = strcasecmp($source, 'repbase') === 0 ? 'Repbase' : $source;
            }
        }
        if ($sourceLabels === []) {
            $sourceLabels[] = 'Repbase-backed sequence library';
        }
        $sourceText = implode(', ', array_values(array_unique($sourceLabels)));
        $citationText = $this->formatSequenceCitations($citations);

        if ($answerLanguage === 'chinese') {
            $answer = "{$label} 的匹配共识序列长度为 {$length} bp。证据来源是 {$sourceText}。";
            return $citationText !== '' ? $answer . "\n参考来源：{$citationText}" : $answer;
        }

        $answer = "{$label}'s matched consensus sequence length is {$length} bp. The evidence source is {$sourceText}.";
        return $citationText !== '' ? $answer . "\nSources: {$citationText}" : $answer;
    }

    private function buildDirectRelationshipAnswer(string $question, string $answerLanguage, array $analysis, array $pluginResults, array $citations): ?string
    {
        if (($analysis['intent'] ?? '') !== 'relationship') {
            return null;
        }
        if ($this->relationshipQuestionNeedsSynthesis($question)) {
            return null;
        }
        $rows = (array)($pluginResults['Graph Plugin']['results']['rows'] ?? []);
        if ($rows === []) {
            return null;
        }

        $groupedLines = [];
        $sourceLabel = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $targetType = trim((string)($row['target_type'] ?? ''));
            $targetLabels = array_map('strval', (array)($row['target_labels'] ?? []));
            $resolvedType = $targetType !== '' ? $targetType : (string)($targetLabels[0] ?? 'Unknown');
            $sourceLabel = $sourceLabel !== '' ? $sourceLabel : trim((string)($row['source_name'] ?? ''));
            $target = trim((string)($row['target_name'] ?? ''));
            $relation = trim((string)($row['relation_type'] ?? 'related_to'));
            $description = trim((string)($row['relation_description'] ?? ''));
            if ($target === '') {
                continue;
            }
            $groupedLines[$resolvedType][] = [
                'target' => $target,
                'relation' => $relation,
                'description' => $description,
            ];
        }

        if ($groupedLines === []) {
            return null;
        }

        if ($sourceLabel === '') {
            $sourceLabel = 'the TE';
        }

        $citationText = $this->formatSequenceCitations($citations);
        $bodyLines = [];
        foreach ($groupedLines as $targetType => $items) {
            $bodyLines[] = '### ' . $targetType;
            foreach ($items as $item) {
                $line = '- ' . $item['target'] . ' [' . $item['relation'] . ']';
                if ($item['description'] !== '') {
                    $line .= ' ' . $item['description'];
                }
                $bodyLines[] = $line;
            }
            $bodyLines[] = '';
        }
        while ($bodyLines !== [] && end($bodyLines) === '') {
            array_pop($bodyLines);
        }

        if ($answerLanguage === 'chinese') {
            $prefix = "以下是当前图谱中 {$sourceLabel} 的全部已连接关系：\n\n";
            $suffix = $citationText !== '' ? "\n参考来源：{$citationText}" : '';
            return $prefix . implode("\n", $bodyLines) . $suffix;
        }

        $prefix = "Below is the full relationship list currently connected to {$sourceLabel} in the graph:\n\n";
        $suffix = $citationText !== '' ? "\nSources: {$citationText}" : '';
        return $prefix . implode("\n", $bodyLines) . $suffix;
    }

    private function relationshipQuestionNeedsSynthesis(string $question): bool
    {
        $normalized = tekg_agent_lower($question);
        $explicitListCues = [
            '列出',
            '全部关系',
            '所有关系',
            '哪些',
            '有哪些',
            'list',
            'list all',
            'enumerate',
            'show all',
            'full relationship',
            'all relationship',
        ];
        foreach ($explicitListCues as $cue) {
            if (str_contains($normalized, tekg_agent_lower($cue))) {
                return false;
            }
        }

        $synthesisCues = [
            '整合',
            '综合',
            '总结',
            '概述',
            '概览',
            '综述',
            '梳理',
            '归纳',
            '介绍',
            '报告',
            '研究',
            '信息',
            'synthesize',
            'synthesis',
            'summarize',
            'summary',
            'overview',
            'integrate',
            'profile',
            'report',
            'review',
            'what do we know',
        ];
        foreach ($synthesisCues as $cue) {
            if (str_contains($normalized, tekg_agent_lower($cue))) {
                return true;
            }
        }

        return false;
    }

    private function writeDeterministicSummary(
        string $writingModel,
        string $answerLanguage,
        string $question,
        array $analysis,
        array $synthesizedEvidence,
        array $citations,
        string $confidence,
        array $limits,
        string $hint
    ): string {
        try {
            $summaryResult = $this->llm->writeEvidenceSummary(
                $writingModel,
                $answerLanguage,
                $question,
                $this->analysisForWriting($analysis),
                $this->limitClaimTexts((array)($synthesizedEvidence['supported_claims'] ?? []), 4),
                $this->limitClaimTexts((array)($synthesizedEvidence['conflicting_claims'] ?? []), 2),
                $this->limitClaimTexts((array)($synthesizedEvidence['missing_evidence'] ?? []), 2),
                $this->lightweightCitations($citations, 4),
                $confidence,
                $limits,
                $hint,
                min(10, $this->answerTimeoutForModel($this->config, $writingModel))
            );
        } catch (Throwable) {
            return '';
        }
        if (($summaryResult['ok'] ?? false) !== true) {
            return '';
        }
        return trim((string)($summaryResult['content'] ?? ''));
    }

    private function limitClaimTexts(array $claims, int $limit): array
    {
        $clean = [];
        foreach ($claims as $claim) {
            $text = trim((string)$claim);
            if ($text !== '') {
                $clean[] = $text;
            }
        }
        return array_values(array_slice(array_unique($clean), 0, $limit));
    }

    private function aggregateCitations(array $pluginResults): array
    {
        if (isset($pluginResults['Citation Resolver']['citations']) && is_array($pluginResults['Citation Resolver']['citations'])) {
            return $this->citationResolver->normalizeMany($pluginResults['Citation Resolver']['citations']);
        }

        $all = [];
        foreach ($pluginResults as $result) {
            foreach ((array)($result['citations'] ?? []) as $citation) {
                $all[] = $citation;
            }
        }
        return $this->citationResolver->normalizeMany($all);
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
        return array_values(array_unique(array_filter($limits)));
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
        if ($okPlugins >= 2 && count($evidence) >= 2) {
            return 'medium';
        }
        return 'low';
    }

    private function shouldRunCitationResolver(array $pluginResults): bool
    {
        foreach ($pluginResults as $pluginName => $result) {
            if (in_array($pluginName, ['Entity Resolver', 'Citation Resolver'], true)) {
                continue;
            }
            if ((array)($result['citations'] ?? []) !== []) {
                return true;
            }
        }
        return false;
    }

    private function toolPayloadForUi(array $result): array
    {
        return PluginResultProjection::forUi($result);
    }

    private function inferProvider(string $model): string
    {
        $value = strtolower(trim($model));
        if (str_contains($value, 'qwen')) {
            return 'qwen';
        }
        return 'deepseek';
    }

    private function updateSessionMemory(array $memory, array $analysis, array $pluginResults, array $citations): array
    {
        $memory = array_replace(tekg_agent_default_session_memory(), $memory);
        $memory['topic_entities'] = array_values(array_unique(array_map(
            static fn(array $entity): string => (string)($entity['canonical_label'] ?? $entity['label'] ?? ''),
            (array)($analysis['normalized_entities'] ?? [])
        )));
        $memory['last_intent'] = (string)($analysis['intent'] ?? '');
        $memory['tool_history'] = array_values(array_slice(array_keys($pluginResults), -10));
        $memory['citations'] = array_values(array_slice(array_map(
            static fn(array $citation): string => (string)($citation['pmid'] ?? $citation['title'] ?? ''),
            $citations
        ), 0, 12));
        return $memory;
    }
}
