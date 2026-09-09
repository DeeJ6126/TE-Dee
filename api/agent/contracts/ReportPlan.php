<?php
declare(strict_types=1);

final class ReportPlan
{
    private const SCHEMA_VERSION = 'report_plan.v1';
    private const REPORT_TYPES = ['mechanism_review', 'evidence_audit', 'batch_comparison', 'graph_ranking', 'research_report'];

    public static function fromEvidenceWalk(string $question, array $analysis, array $evidenceWalk, array $answerStructure = []): array
    {
        $reportType = self::selectReportType($question, $analysis, $answerStructure);
        $sections = self::sectionsForReportType($reportType);
        $claimSequence = [];

        foreach (array_values((array)($evidenceWalk['claim_nodes'] ?? [])) as $index => $claimNode) {
            if (!is_array($claimNode)) {
                continue;
            }
            $claimSequence[] = [
                'id' => 'claim_sequence_' . (count($claimSequence) + 1),
                'claim_node_id' => self::stringValue($claimNode['id'] ?? ''),
                'claim_id' => self::stringValue($claimNode['claim_id'] ?? ''),
                'order' => $index + 1,
                'role' => self::claimRole($reportType, $index),
                'section_key' => self::claimSectionKey($reportType),
            ];
        }

        $gapCount = count((array)($evidenceWalk['gaps'] ?? []));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'question' => $question,
            'report_type' => $reportType,
            'generated_at' => gmdate('c'),
            'sections' => $sections,
            'claim_sequence' => $claimSequence,
            'citation_policy' => [
                'mode' => in_array($reportType, ['mechanism_review', 'evidence_audit', 'research_report'], true) ? 'inline_required' : 'cite_ranked_claims',
                'minimum_citations_per_claim' => $reportType === 'graph_ranking' ? 0 : 1,
                'allow_uncited_claims' => $reportType === 'graph_ranking',
            ],
            'gap_policy' => [
                'mode' => $gapCount > 0 ? 'surface_gaps' : 'note_if_relevant',
                'gap_count' => $gapCount,
                'include_limitations_section' => in_array($reportType, ['mechanism_review', 'evidence_audit', 'research_report'], true),
            ],
            'coverage_metrics' => is_array($evidenceWalk['coverage_metrics'] ?? null) ? $evidenceWalk['coverage_metrics'] : [],
        ];
    }

    public static function validate(array $plan): array
    {
        $errors = [];
        $schema = require __DIR__ . '/../config/report_plan_schema.php';

        foreach ((array)($schema['required'] ?? []) as $key) {
            if (!array_key_exists($key, $plan)) {
                $errors[] = "{$key} is required";
            }
        }

        if (($plan['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version must be report_plan.v1';
        }
        if (!is_string($plan['question'] ?? null) || trim((string)$plan['question']) === '') {
            $errors[] = 'question is required';
        }
        if (!in_array($plan['report_type'] ?? null, self::REPORT_TYPES, true)) {
            $errors[] = 'report_type must be one of mechanism_review, evidence_audit, batch_comparison, graph_ranking, research_report';
        }
        if (!is_string($plan['generated_at'] ?? null) || strtotime((string)$plan['generated_at']) === false) {
            $errors[] = 'generated_at must be an ISO-8601 date-time string';
        }
        if (!array_key_exists('sections', $plan) || !is_array($plan['sections'])) {
            $errors[] = 'sections must be an array';
        } elseif ($plan['sections'] === []) {
            $errors[] = 'sections must contain at least one section';
        }
        foreach (['claim_sequence'] as $key) {
            if (array_key_exists($key, $plan) && !is_array($plan[$key])) {
                $errors[] = "{$key} must be an array";
            }
        }
        foreach (['citation_policy', 'gap_policy', 'coverage_metrics'] as $key) {
            if (array_key_exists($key, $plan) && !is_array($plan[$key])) {
                $errors[] = "{$key} must be an object";
            }
        }

        foreach ((array)($plan['sections'] ?? []) as $index => $section) {
            self::validateObject($section, "sections[{$index}]", ['id', 'key', 'title', 'purpose'], $errors);
        }
        foreach ((array)($plan['claim_sequence'] ?? []) as $index => $item) {
            self::validateObject($item, "claim_sequence[{$index}]", ['id', 'claim_node_id', 'claim_id', 'role', 'section_key'], $errors);
        }
        if (isset($plan['citation_policy']) && is_array($plan['citation_policy'])) {
            if (!is_string($plan['citation_policy']['mode'] ?? null) || trim((string)$plan['citation_policy']['mode']) === '') {
                $errors[] = 'citation_policy.mode is required';
            }
        }
        if (isset($plan['gap_policy']) && is_array($plan['gap_policy'])) {
            if (!is_string($plan['gap_policy']['mode'] ?? null) || trim((string)$plan['gap_policy']['mode']) === '') {
                $errors[] = 'gap_policy.mode is required';
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    private static function selectReportType(string $question, array $analysis, array $answerStructure): string
    {
        $preferred = self::stringValue($answerStructure['preferred_report_type'] ?? $answerStructure['report_type'] ?? $answerStructure['response_mode'] ?? '');
        if (in_array($preferred, self::REPORT_TYPES, true)) {
            return $preferred;
        }

        $normalizedQuestion = strtolower(trim($question));
        foreach (['research report', 'research-report', '研究报告', '调研报告'] as $marker) {
            if (str_contains($normalizedQuestion, $marker)) {
                return 'research_report';
            }
        }

        $intent = strtolower(self::stringValue($analysis['intent'] ?? ''));
        $taskComplexity = strtolower(self::stringValue($analysis['task_complexity'] ?? ''));

        if (str_contains($intent, 'mechanism')) {
            return 'mechanism_review';
        }
        if (str_contains($intent, 'graph_analytics') || str_contains($intent, 'analytics') || str_contains($intent, 'ranking')) {
            return 'graph_ranking';
        }
        if (str_contains($intent, 'comparison') || str_contains($intent, 'batch')) {
            return 'batch_comparison';
        }
        if (str_contains($intent, 'literature') || str_contains($intent, 'citation') || str_contains($intent, 'evidence')) {
            return 'evidence_audit';
        }
        if ($taskComplexity === 'simple') {
            return 'evidence_audit';
        }
        return 'research_report';
    }

    private static function sectionsForReportType(string $reportType): array
    {
        $keys = match ($reportType) {
            'mechanism_review' => ['background', 'mechanism_chain', 'evidence_review', 'limitations', 'answer'],
            'evidence_audit' => ['question_scope', 'evidence_inventory', 'citation_assessment', 'gaps', 'answer'],
            'batch_comparison' => ['comparison_scope', 'entities', 'evidence_matrix', 'differences', 'answer'],
            'graph_ranking' => ['question_scope', 'ranking_method', 'top_entities', 'evidence_paths', 'caveats', 'answer'],
            default => ['overview', 'requested_findings', 'limitations'],
        };

        $sections = [];
        foreach ($keys as $index => $key) {
            $sections[] = [
                'id' => 'section_' . ($index + 1),
                'key' => $key,
                'title' => self::sectionTitle($key),
                'purpose' => self::sectionPurpose($key),
            ];
        }
        return $sections;
    }

    private static function claimRole(string $reportType, int $index): string
    {
        if ($index === 0) {
            return $reportType === 'graph_ranking' ? 'top_ranked_support' : 'primary_support';
        }
        return 'supporting_context';
    }

    private static function claimSectionKey(string $reportType): string
    {
        return match ($reportType) {
            'mechanism_review' => 'mechanism_chain',
            'graph_ranking' => 'top_entities',
            'batch_comparison' => 'evidence_matrix',
            'evidence_audit' => 'evidence_inventory',
            default => 'key_findings',
        };
    }

    private static function sectionTitle(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    private static function sectionPurpose(string $key): string
    {
        return match ($key) {
            'background' => 'Frame the biological context before causal interpretation.',
            'mechanism_chain' => 'Order claims as a mechanistic evidence chain.',
            'evidence_review', 'evidence_inventory' => 'Summarize supporting evidence and provenance.',
            'overview' => 'Introduce the subject briefly without restating the request or describing the internal workflow.',
            'requested_findings' => 'Organize the findings around the dimensions explicitly requested by the user.',
            'citation_assessment' => 'Check whether literature references support the claims.',
            'ranking_method' => 'Explain graph ranking criteria and limits.',
            'top_entities' => 'Present ranked graph entities with evidence links.',
            'evidence_paths' => 'Show graph paths or runtime routes supporting the ranking.',
            'limitations', 'gaps', 'caveats' => 'Surface evidence gaps and uncertainty.',
            'answer' => 'Provide the final concise answer.',
            default => 'Organize evidence for this report section.',
        };
    }

    private static function validateObject(mixed $value, string $path, array $requiredStringKeys, array &$errors): void
    {
        if (!is_array($value)) {
            $errors[] = "{$path} must be an object";
            return;
        }
        foreach ($requiredStringKeys as $key) {
            if (!array_key_exists($key, $value) || !is_string($value[$key]) || trim($value[$key]) === '') {
                $errors[] = "{$path}.{$key} is required";
            }
        }
    }

    private static function stringValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }
        return '';
    }
}
