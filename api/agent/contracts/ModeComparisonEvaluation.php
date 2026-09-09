<?php
declare(strict_types=1);

final class ModeComparisonEvaluation
{
    public static function fromAgentResponse(array $agentResponse, array $case = []): array
    {
        return self::agentReport($agentResponse, $case);
    }

    public static function compare(array $case, array $dtResponse, array $agentResponse): array
    {
        $dtReport = self::dtReport($dtResponse, $case);
        $agentReport = self::agentReport($agentResponse, $case);
        $expectedBestMode = self::stringValue($case['expected_best_mode'] ?? '');
        $recommendedMode = self::recommendedMode($case, $dtReport, $agentReport);
        $depthDelta = round((float)$agentReport['depth_score'] - (float)$dtReport['depth_score'], 3);
        $artifactDelta = round((float)$agentReport['artifact_score'] - (float)$dtReport['artifact_score'], 3);
        $agentOverkill = self::isDeepThinkExpected($expectedBestMode)
            && (int)$agentReport['plugin_count'] > 2
            && (float)$agentReport['artifact_score'] > 0.5;

        return [
            'schema_version' => 'mode_comparison_evaluation.v1',
            'case_id' => self::stringValue($case['case_id'] ?? ''),
            'question' => self::stringValue($case['question'] ?? ''),
            'category' => self::stringValue($case['category'] ?? ''),
            'expected_best_mode' => $expectedBestMode,
            'recommended_mode' => $recommendedMode,
            'agent_value_added' => self::agentValueAdded($expectedBestMode, $depthDelta, $artifactDelta, $agentOverkill),
            'dt_report' => $dtReport,
            'agent_report' => $agentReport,
            'comparison' => [
                'best_mode_match' => $expectedBestMode === '' || self::modeMatches($expectedBestMode, $recommendedMode),
                'agent_deeper_than_dt' => $depthDelta > 0.15,
                'depth_delta' => $depthDelta,
                'artifact_delta' => $artifactDelta,
                'agent_overkill' => $agentOverkill,
                'cost_latency_tradeoff' => self::latencyTradeoff($dtReport, $agentReport, $expectedBestMode),
                'requested_metrics' => array_values((array)($case['comparison_metrics'] ?? [])),
            ],
        ];
    }

    private static function dtReport(array $response, array $case): array
    {
        $plugins = self::pluginNames($response);
        $citationCount = self::countList($response['citations'] ?? []);
        $routeCount = self::routeCount($response);
        $answer = self::stringValue($response['answer'] ?? $response['content'] ?? '');
        $artifactScore = min(1.0, ($answer !== '' ? 0.25 : 0.0) + ($citationCount > 0 ? 0.2 : 0.0) + ($routeCount > 0 ? 0.2 : 0.0) + (count($plugins) > 0 ? 0.1 : 0.0));

        return [
            'mode' => 'deep_think',
            'answer_present' => $answer !== '',
            'plugin_count' => count($plugins),
            'used_plugins' => $plugins,
            'citation_count' => $citationCount,
            'route_count' => $routeCount,
            'latency_ms' => self::latencyMs($response),
            'artifact_score' => round($artifactScore, 3),
            'depth_score' => round($artifactScore + min(0.25, count($plugins) * 0.05), 3),
            'simple_task_fit' => self::isDeepThinkExpected(self::stringValue($case['expected_best_mode'] ?? '')),
        ];
    }

    private static function agentReport(array $response, array $case = []): array
    {
        $plugins = self::pluginNames($response);
        $evidencePackage = is_array($response['evidence_package'] ?? null) ? $response['evidence_package'] : [];
        $evidenceWalk = is_array($response['evidence_walk'] ?? null) ? $response['evidence_walk'] : [];
        $reportPlan = is_array($response['report_plan'] ?? null) ? $response['report_plan'] : [];
        $integrityReport = is_array($response['integrity_report'] ?? null) ? $response['integrity_report'] : [];
        $claimCount = self::countList($evidencePackage['claims'] ?? []);
        $walkStepCount = self::countList($evidenceWalk['walk_steps'] ?? []);
        $sectionCount = self::countList($reportPlan['sections'] ?? []);
        $citationCount = max(self::countList($response['citations'] ?? []), self::countList($evidencePackage['citation_map'] ?? []));
        $routeCount = max(self::routeCount($response), self::countList($evidencePackage['route_map'] ?? []));
        $integrityOk = self::integrityOk($integrityReport);
        $writingFailed = (bool)($response['writing_failed'] ?? false);

        $artifactScore = 0.0;
        $artifactScore += $claimCount > 0 ? 0.2 : 0.0;
        $artifactScore += $walkStepCount > 0 ? 0.2 : 0.0;
        $artifactScore += $sectionCount > 0 ? 0.15 : 0.0;
        $artifactScore += $integrityOk ? 0.2 : 0.0;
        $artifactScore += $citationCount > 0 ? 0.15 : 0.0;
        $artifactScore += $routeCount > 0 ? 0.1 : 0.0;
        $artifactScore = min(1.0, $artifactScore);

        return [
            'mode' => 'agent',
            'answer_present' => trim(self::stringValue($response['answer'] ?? '')) !== '',
            'plugin_count' => count($plugins),
            'used_plugins' => $plugins,
            'citation_count' => $citationCount,
            'route_count' => $routeCount,
            'claim_count' => $claimCount,
            'walk_step_count' => $walkStepCount,
            'report_section_count' => $sectionCount,
            'has_evidence_package' => $evidencePackage !== [],
            'has_evidence_walk' => $evidenceWalk !== [],
            'has_report_plan' => $reportPlan !== [],
            'integrity_ok' => $integrityOk,
            'writing_failed' => $writingFailed,
            'failure_stage' => self::stringValue($response['failure_stage'] ?? ''),
            'failure_reason' => self::stringValue($response['failure_reason'] ?? ''),
            'latency_ms' => self::latencyMs($response),
            'artifact_score' => round($artifactScore, 3),
            'depth_score' => round(min(1.0, $artifactScore + min(0.25, count($plugins) * 0.04)), 3),
            'models' => is_array($response['models'] ?? null) ? $response['models'] : [],
            'expected_best_mode' => self::stringValue($case['expected_best_mode'] ?? ''),
        ];
    }

    private static function recommendedMode(array $case, array $dtReport, array $agentReport): string
    {
        $expected = self::stringValue($case['expected_best_mode'] ?? '');
        if (self::isAgentExpected($expected)) {
            return 'agent';
        }
        if (self::isDeepThinkExpected($expected)) {
            return 'deep_think';
        }
        return (float)$agentReport['artifact_score'] > (float)$dtReport['artifact_score'] + 0.2 ? 'agent' : 'deep_think';
    }

    private static function agentValueAdded(string $expectedBestMode, float $depthDelta, float $artifactDelta, bool $agentOverkill): string
    {
        if ($agentOverkill) {
            return 'low';
        }
        if (self::isDeepThinkExpected($expectedBestMode) && $artifactDelta < 0.35) {
            return 'none';
        }
        if ($depthDelta >= 0.45 || $artifactDelta >= 0.45) {
            return 'high';
        }
        if ($depthDelta >= 0.25 || $artifactDelta >= 0.25) {
            return 'medium';
        }
        if ($depthDelta > 0.05 || $artifactDelta > 0.05) {
            return 'low';
        }
        return 'none';
    }

    private static function latencyTradeoff(array $dtReport, array $agentReport, string $expectedBestMode): string
    {
        if (self::isAgentExpected($expectedBestMode)) {
            return 'agent_latency_allowed';
        }
        if ((int)$agentReport['latency_ms'] > max(1, (int)$dtReport['latency_ms']) * 3) {
            return 'agent_too_slow_for_simple_task';
        }
        return 'acceptable';
    }

    private static function modeMatches(string $expected, string $actual): bool
    {
        if ($expected === $actual) {
            return true;
        }
        if ($expected === 'boundary_deep_think') {
            return $actual === 'deep_think';
        }
        if ($expected === 'boundary_agent') {
            return $actual === 'agent';
        }
        return false;
    }

    private static function isDeepThinkExpected(string $mode): bool
    {
        return in_array($mode, ['deep_think', 'boundary_deep_think'], true);
    }

    private static function isAgentExpected(string $mode): bool
    {
        return in_array($mode, ['agent', 'boundary_agent'], true);
    }

    private static function pluginNames(array $response): array
    {
        $plugins = [];
        foreach ((array)($response['used_plugins'] ?? []) as $plugin) {
            if (is_string($plugin) && trim($plugin) !== '') {
                $plugins[] = trim($plugin);
            }
        }
        foreach ((array)($response['plugin_calls'] ?? []) as $call) {
            if (is_array($call)) {
                $name = self::stringValue($call['plugin_name'] ?? '');
                if ($name !== '') {
                    $plugins[] = $name;
                }
            }
        }
        return array_values(array_unique($plugins));
    }

    private static function integrityOk(array $integrityReport): bool
    {
        if ($integrityReport === []) {
            return false;
        }
        $draftOk = (bool)($integrityReport['draft']['ok'] ?? false);
        $polishOk = (bool)($integrityReport['polish']['ok'] ?? false);
        return $draftOk && ($polishOk || !isset($integrityReport['polish']));
    }

    private static function routeCount(array $response): int
    {
        $count = self::countList($response['routes'] ?? []);
        foreach ((array)($response['plugin_calls'] ?? []) as $call) {
            if (is_array($call)) {
                $count += self::countList($call['result_envelope']['routes'] ?? []);
            }
        }
        return $count;
    }

    private static function latencyMs(array $response): int
    {
        foreach (['total_ms', 'duration_ms', 'elapsed_ms', 'writing_ms'] as $key) {
            if (isset($response['timings'][$key])) {
                return (int)$response['timings'][$key];
            }
            if (isset($response[$key])) {
                return (int)$response[$key];
            }
        }
        return 0;
    }

    private static function countList(mixed $value): int
    {
        return is_array($value) ? count($value) : 0;
    }

    private static function stringValue(mixed $value): string
    {
        return trim((string)$value);
    }
}
