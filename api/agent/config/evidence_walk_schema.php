<?php
declare(strict_types=1);

return [
    'type' => 'object',
    'required' => [
        'schema_version',
        'generated_at',
        'walk_steps',
        'claim_nodes',
        'support_edges',
        'citation_refs',
        'route_refs',
        'gaps',
        'coverage_metrics',
    ],
    'properties' => [
        'schema_version' => ['type' => 'string', 'const' => 'evidence_walk.v1'],
        'generated_at' => ['type' => 'string', 'format' => 'date-time'],
        'walk_steps' => ['type' => 'array'],
        'claim_nodes' => ['type' => 'array'],
        'support_edges' => ['type' => 'array'],
        'citation_refs' => ['type' => 'array'],
        'route_refs' => ['type' => 'array'],
        'gaps' => ['type' => 'array'],
        'coverage_metrics' => [
            'type' => 'object',
            'required' => [
                'claim_node_count',
                'walk_step_count',
                'support_edge_count',
                'citation_ref_count',
                'route_ref_count',
                'gap_count',
                'has_minimum_evidence',
            ],
            'properties' => [
                'claim_node_count' => ['type' => 'integer'],
                'walk_step_count' => ['type' => 'integer'],
                'support_edge_count' => ['type' => 'integer'],
                'citation_ref_count' => ['type' => 'integer'],
                'route_ref_count' => ['type' => 'integer'],
                'gap_count' => ['type' => 'integer'],
                'has_minimum_evidence' => ['type' => 'boolean'],
            ],
        ],
    ],
];
