<?php
declare(strict_types=1);

return [
    'type' => 'object',
    'required' => [
        'schema_version',
        'question',
        'report_type',
        'generated_at',
        'sections',
        'claim_sequence',
        'citation_policy',
        'gap_policy',
        'coverage_metrics',
    ],
    'properties' => [
        'schema_version' => ['type' => 'string', 'const' => 'report_plan.v1'],
        'question' => ['type' => 'string'],
        'report_type' => [
            'type' => 'string',
            'enum' => ['mechanism_review', 'evidence_audit', 'batch_comparison', 'graph_ranking', 'research_report'],
        ],
        'generated_at' => ['type' => 'string', 'format' => 'date-time'],
        'sections' => ['type' => 'array'],
        'claim_sequence' => ['type' => 'array'],
        'citation_policy' => ['type' => 'object'],
        'gap_policy' => ['type' => 'object'],
        'coverage_metrics' => ['type' => 'object'],
    ],
];
