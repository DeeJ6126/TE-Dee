<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/contracts/PluginResultEnvelope.php';
require_once dirname(__DIR__, 2) . '/contracts/PluginResultContract.php';

trait TekgDeepThinkRoutingTrait
{
    private function lightweightPlanning(array $analysis): array
    {
        $intent = (string)($analysis['intent'] ?? 'relationship');
        return [
            'question_type' => $intent,
            'required_evidence' => $this->requiredEvidenceForIntent($intent),
            'knowledge_gaps' => [],
            'subtasks' => [],
            'tool_plan' => [],
        ];
    }

    private function requiredEvidenceForIntent(string $intent): array
    {
        return match ($intent) {
            'sequence' => ['sequence'],
            'genome' => ['genome'],
            'expression' => ['expression'],
            'classification' => ['classification'],
            'graph_analytics' => ['graph structure'],
            'literature' => ['literature'],
            'mechanism' => ['structured relations', 'literature'],
            default => ['structured relations'],
        };
    }

    private function emitAnalysisThoughtFlow(?callable $emit, string $sessionId, string $model, string $processLanguage, array $analysis, int &$eventSequence): void
    {
        $entities = array_values(array_filter(array_map(function (array $entity): string {
            $label = trim((string)($entity['canonical_label'] ?? $entity['label'] ?? ''));
            $type = trim((string)($entity['entity_type'] ?? $entity['type'] ?? ''));
            if ($label === '') {
                return '';
            }
            return $label . ($type !== '' ? ' (' . $type . ')' : '');
        }, (array)($analysis['normalized_entities'] ?? []))));

        $lines = [
            $this->narrateEvent(
                $model,
                $processLanguage,
                ['type' => 'analysis', 'focus' => 'entities', 'entities' => $analysis['normalized_entities'] ?? []],
                'Recognized entities: ' . ($entities === [] ? 'none yet.' : implode(', ', $entities) . '.')
            ),
            $this->narrateEvent(
                $model,
                $processLanguage,
                ['type' => 'analysis', 'focus' => 'intent', 'intent' => $analysis['intent'] ?? '', 'complexity' => $analysis['complexity'] ?? ''],
                'Question type: ' . (string)($analysis['intent'] ?? 'relationship') . '. Complexity: ' . (string)($analysis['complexity'] ?? 'simple_lookup') . '.'
            ),
        ];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $this->emitEvent($emit, $eventSequence, [
                'type' => 'analysis',
                'session_id' => $sessionId,
                'message' => $line,
                'payload' => [
                    'intent' => $analysis['intent'] ?? '',
                    'complexity' => $analysis['complexity'] ?? '',
                    'normalized_entities' => $analysis['normalized_entities'] ?? [],
                ],
            ]);
        }
    }

    private function runPlugin(
        string $pluginName,
        string $question,
        array $analysis,
        array $planning,
        array $pluginResults,
        string $model,
        string $narratorModel,
        string $processLanguage,
        string $sessionId,
        int &$eventSequence,
        int &$detailCounter,
        string $requestId,
        ?callable $emit,
        array &$reasoningTrace,
        string $selectionReason = ''
    ): array {
        $plugin = $this->plugins[$pluginName] ?? null;
        if (!$plugin instanceof TekgAgentPluginInterface) {
            throw new RuntimeException('Unknown plugin: ' . $pluginName);
        }

        $selectedMessage = $this->toolSelectedMessage($pluginName, $processLanguage);
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'tool_selected',
            'session_id' => $sessionId,
            'plugin_name' => $pluginName,
            'message' => $selectedMessage,
            'payload' => [
                'selection_reason' => $selectionReason,
            ],
        ]);

        $result = $plugin->run([
            'question' => $question,
            'analysis' => $analysis,
            'plugin_results' => $pluginResults,
            'planning' => $planning,
            'config' => $this->expertConfig($model),
        ]);
        $result = PluginResultContract::enforce($pluginName, $result);
        $legacyResult = $result;
        $result = $this->augmentPluginResult($pluginName, $result, $analysis, $planning);
        if (!isset($result['result_envelope']) || !is_array($result['result_envelope'])) {
            $result['result_envelope'] = PluginResultEnvelope::fromPluginResult($pluginName, $legacyResult, [
                'intent' => (string)($analysis['intent'] ?? ''),
                'analysis' => $analysis,
                'planning' => $planning,
            ]);
        }
        $this->logDiagnostic($requestId, 'deepthink_plugin_completed', [
            'plugin_name' => $pluginName,
            'status' => (string)($result['status'] ?? 'unknown'),
            'result_counts' => (array)($result['result_counts'] ?? []),
            'latency_ms' => (int)($result['latency_ms'] ?? 0),
        ]);

        $payloadForUi = $this->toolPayloadForUi($result);
        $detailId = 'tool-' . (++$detailCounter);
        $resultMessage = $this->toolResultMessage($pluginName, $processLanguage);
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'tool_result',
            'session_id' => $sessionId,
            'plugin_name' => $pluginName,
            'display_label' => (string)($result['display_label'] ?? $pluginName),
            'summary' => $resultMessage,
            'message' => $resultMessage,
            'detail_payload_id' => $detailId,
            'payload' => $payloadForUi,
        ]);

        $reasoningTrace[] = [
            'step' => 'querying_plugins',
            'title' => $pluginName,
            'status' => (string)($result['status'] ?? 'ok'),
            'details' => (string)($result['display_summary'] ?? $result['query_summary'] ?? ''),
        ];

        return $result;
    }

    private function decideNextPlugin(string $question, array $analysis, array $pluginResults, string $model, string $requestId): array
    {
        $hardDecision = $this->hardStopDecision($analysis, $pluginResults);
        if ($hardDecision !== null) {
            return $hardDecision;
        }

        $candidates = $this->candidatePluginOrder($analysis, $pluginResults);
        if ($candidates === []) {
            return [
                'done' => true,
                'next_plugin' => '',
                'reason' => 'No remaining plugins are required for this question type.',
            ];
        }

        if (($analysis['asks_for_site_navigation'] ?? false) && in_array('Site Navigator Plugin', $candidates, true)) {
            return [
                'done' => false,
                'next_plugin' => 'Site Navigator Plugin',
                'reason' => 'This question asks for a TE-Dee page or panel route, so I will use the site navigator first.',
            ];
        }

        if ($this->shouldBypassRouter($analysis)) {
            $fallback = $candidates[0] ?? '';
            $this->logDiagnostic($requestId, 'deepthink_router_bypassed', [
                'intent' => (string)($analysis['intent'] ?? 'relationship'),
                'next_plugin' => $fallback,
                'candidates' => $candidates,
            ]);
            return [
                'done' => false,
                'next_plugin' => $fallback,
                'reason' => 'I will continue with the next highest-priority plugin for this simple question type.',
            ];
        }

        $payload = [
            'question' => $question,
            'analysis' => [
                'intent' => (string)($analysis['intent'] ?? ''),
                'complexity' => (string)($analysis['complexity'] ?? ''),
                'normalized_entities' => array_slice((array)($analysis['normalized_entities'] ?? []), 0, 4),
            ],
            'used_plugins' => array_values(array_keys($pluginResults)),
            'plugin_results' => $this->compressedPluginResults($pluginResults),
            'candidate_plugins' => array_map(fn(string $name): array => [
                'name' => $name,
                'purpose' => $this->pluginPurpose($name),
            ], $candidates),
        ];

        $generated = $this->llm->generateJson(
            $model,
            TekgAgentPromptLibrary::jsonInstructionPrompt('deepthink_router', (string)($analysis['answer_language'] ?? $analysis['language'] ?? 'english')),
            $payload,
            max(8, (int)($this->config['llm_json_timeout'] ?? 20)),
            'deepthink_router'
        );

        if (is_array($generated)) {
            $selected = trim((string)($generated['next_plugin'] ?? ''));
            if (($generated['done'] ?? false) === true) {
                return [
                    'done' => true,
                    'next_plugin' => '',
                    'reason' => trim((string)($generated['reason'] ?? 'The current evidence is enough.')),
                ];
            }
            if ($selected !== '' && in_array($selected, $candidates, true)) {
                return [
                    'done' => false,
                    'next_plugin' => $selected,
                    'reason' => trim((string)($generated['reason'] ?? '')),
                ];
            }
        }

        $fallback = $candidates[0] ?? '';
        $this->logDiagnostic($requestId, 'deepthink_router_fallback', [
            'next_plugin' => $fallback,
            'candidates' => $candidates,
        ]);
        return [
            'done' => false,
            'next_plugin' => $fallback,
            'reason' => 'I will continue with the next highest-priority plugin for this question type.',
        ];
    }

    private function hardStopDecision(array $analysis, array $pluginResults): ?array
    {
        $intent = (string)($analysis['intent'] ?? 'relationship');

        if ($intent === 'sequence' && $this->pluginHasUsableResult($pluginResults, 'Sequence Plugin')) {
            return [
                'done' => true,
                'next_plugin' => '',
                'reason' => 'The sequence layer already returned a direct usable hit, so no extra evidence layer is needed for this simple sequence question.',
            ];
        }
        if ($intent === 'genome' && $this->pluginHasUsableResult($pluginResults, 'Genome Plugin')) {
            return [
                'done' => true,
                'next_plugin' => '',
                'reason' => 'The genome layer already returned a direct usable hit, so no extra evidence layer is needed for this simple locus question.',
            ];
        }
        if ($intent === 'expression' && $this->pluginHasUsableResult($pluginResults, 'Expression Plugin')) {
            return [
                'done' => true,
                'next_plugin' => '',
                'reason' => 'The expression layer already returned a direct usable hit, so no extra evidence layer is needed for this simple expression question.',
            ];
        }
        if ($intent === 'classification' && $this->pluginHasUsableResult($pluginResults, 'Tree Plugin')) {
            return [
                'done' => true,
                'next_plugin' => '',
                'reason' => 'The classification layer already returned a direct usable hit, so no extra evidence layer is needed for this lineage question.',
            ];
        }
        if ($intent === 'relationship' && $this->pluginHasUsableResult($pluginResults, 'Graph Plugin') && !($analysis['asks_for_papers'] ?? false)) {
            return [
                'done' => true,
                'next_plugin' => '',
                'reason' => 'The local graph already returned direct structured relations, so no extra evidence layer is needed for this simple relationship question.',
            ];
        }
        if (($analysis['asks_for_site_navigation'] ?? false) && $this->pluginHasUsableResult($pluginResults, 'Site Navigator Plugin')) {
            return [
                'done' => true,
                'next_plugin' => '',
                'reason' => 'The site navigation layer already returned a direct TE-Dee page route.',
            ];
        }

        return null;
    }

    private function pluginHasUsableResult(array $pluginResults, string $pluginName): bool
    {
        if (!isset($pluginResults[$pluginName])) {
            return false;
        }
        $result = (array)$pluginResults[$pluginName];
        $status = (string)($result['status'] ?? '');
        if (!in_array($status, ['ok', 'partial'], true)) {
            return false;
        }
        foreach ((array)($result['result_counts'] ?? []) as $value) {
            if ((int)$value > 0) {
                return true;
            }
        }
        return !empty($result['evidence_items']) || !empty($result['results']);
    }

    private function shouldBypassRouter(array $analysis): bool
    {
        $intent = (string)($analysis['intent'] ?? 'relationship');
        if (!$this->isSimpleIntent($intent)) {
            return false;
        }
        return !($analysis['asks_for_papers'] ?? false);
    }

    private function candidatePluginOrder(array $analysis, array $pluginResults): array
    {
        $intent = (string)($analysis['intent'] ?? 'relationship');
        $order = match ($intent) {
            'sequence' => ['Sequence Plugin'],
            'genome' => ['Genome Plugin'],
            'expression' => ['Expression Plugin'],
            'classification' => ['Tree Plugin'],
            'literature' => ['Literature Plugin', 'Literature Reading Plugin'],
            'graph_analytics' => ['Graph Analytics Plugin', 'Cypher Explorer Plugin'],
            'mechanism' => ['Graph Plugin', 'Literature Plugin', 'Literature Reading Plugin'],
            'comparison' => ['Graph Plugin', 'Literature Plugin', 'Literature Reading Plugin'],
            default => ['Graph Plugin'],
        };

        if (($analysis['asks_for_papers'] ?? false) || $intent === 'literature') {
            if (!in_array('Literature Plugin', $order, true)) {
                $order[] = 'Literature Plugin';
            }
        }

        if (($analysis['asks_for_graph_analytics'] ?? false) && !in_array('Graph Analytics Plugin', $order, true)) {
            array_unshift($order, 'Graph Analytics Plugin');
        }
        if (($analysis['asks_for_site_navigation'] ?? false) && !in_array('Site Navigator Plugin', $order, true)) {
            array_unshift($order, 'Site Navigator Plugin');
        }
        if (($analysis['asks_for_sequence'] ?? false) && !in_array('Sequence Plugin', $order, true)) {
            $order[] = 'Sequence Plugin';
        }
        if (($analysis['asks_for_genome'] ?? false) && !in_array('Genome Plugin', $order, true)) {
            $order[] = 'Genome Plugin';
        }
        if (($analysis['asks_for_expression'] ?? false) && !in_array('Expression Plugin', $order, true)) {
            $order[] = 'Expression Plugin';
        }
        if (($analysis['asks_for_classification'] ?? false) && !in_array('Tree Plugin', $order, true)) {
            $order[] = 'Tree Plugin';
        }

        $filtered = [];
        foreach ($order as $pluginName) {
            if (isset($pluginResults[$pluginName])) {
                continue;
            }
            if ($pluginName === 'Literature Reading Plugin') {
                $reviewed = (int)($pluginResults['Literature Plugin']['result_counts']['reviewed'] ?? 0);
                if ($reviewed <= 0) {
                    continue;
                }
            }
            $filtered[] = $pluginName;
        }
        return array_values(array_unique($filtered));
    }

    private function pluginPurpose(string $pluginName): string
    {
        return match ($pluginName) {
            'Graph Plugin' => 'Lookup structured graph relations around the recognized entities.',
            'Graph Analytics Plugin' => 'Answer rankings, counts, and global topology questions over the knowledge graph.',
            'Cypher Explorer Plugin' => 'Run a read-only custom Cypher exploration when fixed graph templates are insufficient.',
            'Site Navigator Plugin' => 'Find the best TE-Dee page, panel, or dataset entry URL for the user request.',
            'Literature Plugin' => 'Collect local and PubMed literature evidence.',
            'Literature Reading Plugin' => 'Synthesize retrieved papers into grouped supported and conflicting claims.',
            'Tree Plugin' => 'Recover lineage and classification context.',
            'Expression Plugin' => 'Recover expression-related biological context.',
            'Genome Plugin' => 'Recover genomic locus and browser context.',
            'Sequence Plugin' => 'Recover sequence, consensus length, and structure facts.',
            default => 'Use a plugin.',
        };
    }

    private function emitReflection(?callable $emit, int &$eventSequence, string $sessionId, string $model, string $processLanguage, string $fallback, array $payload): void
    {
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'reflection',
            'session_id' => $sessionId,
            'node' => 'Deep Think',
            'source' => 'Deep Think',
            'inputs_used' => ['plugin_results'],
            'outputs_changed' => ['plugin_queue'],
            'message' => $this->narrateEvent($model, $processLanguage, $payload, $fallback),
            'payload' => $payload,
        ]);
    }

    private function toolSelectedMessage(string $pluginName, string $language = 'en'): string
    {
        return $this->isChinesePresentationLanguage($language)
            ? '我将使用 ' . $pluginName . ' 收集下一步所需证据。'
            : 'I will use ' . $pluginName . ' to collect the next required evidence.';
    }

    private function toolResultMessage(string $pluginName, string $language = 'en'): string
    {
        return $this->isChinesePresentationLanguage($language)
            ? $pluginName . ' 已完成。科研详情保留如下。'
            : $pluginName . ' completed. Scientific details are preserved below.';
    }

    private function stageDisplayLabel(string $stage, string $language = 'en'): string
    {
        return $stage;
    }

    private function localizedFailureMessage(string $stage, string $language = 'en'): string
    {
        $displayStage = $this->stageDisplayLabel($stage, $language);
        return $this->isChinesePresentationLanguage($language)
            ? $displayStage . ' 阶段失败，请稍后重试。'
            : $displayStage . ' failed. Please try again.';
    }

    private function isChinesePresentationLanguage(string $language): bool
    {
        return TekgAgentPromptLibrary::normalizeLanguage($language) === 'chinese';
    }

    private function expertConfig(string $model): array
    {
        $config = $this->config;
        $config['deepseek_model'] = $model;
        $config['deepseek_reasoner_model'] = $model;
        return $config;
    }
}
