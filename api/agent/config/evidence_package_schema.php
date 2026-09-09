<?php
declare(strict_types=1);

return [
    'type' => 'object',
    'required' => [
        'schema_version',
        'question',
        'generated_at',
        'claims',
        'evidence_items',
        'citation_map',
        'route_map',
        'metrics',
        'limits',
        'errors',
    ],
    'properties' => [
        'schema_version' => ['type' => 'string', 'const' => 'evidence_package.v1'],
        'question' => ['type' => 'string'],
        'generated_at' => ['type' => 'string', 'format' => 'date-time'],
        'claims' => ['type' => 'array'],
        'evidence_items' => ['type' => 'array'],
        'citation_map' => ['type' => 'array'],
        'route_map' => ['type' => 'array'],
        'metrics' => [
            'type' => 'object',
            'required' => [
                'plugin_count',
                'claim_count',
                'evidence_count',
                'citation_count',
                'route_count',
                'empty_plugin_count',
                'failed_plugin_count',
                'statuses',
            ],
            'properties' => [
                'plugin_count' => ['type' => 'integer'],
                'claim_count' => ['type' => 'integer'],
                'evidence_count' => ['type' => 'integer'],
                'citation_count' => ['type' => 'integer'],
                'route_count' => ['type' => 'integer'],
                'empty_plugin_count' => ['type' => 'integer'],
                'failed_plugin_count' => ['type' => 'integer'],
                'statuses' => ['type' => 'object'],
            ],
        ],
        'limits' => [
            'type' => 'object',
            'required' => ['summary_max_chars', 'truncation_count', 'truncated_summaries'],
            'properties' => [
                'summary_max_chars' => ['type' => 'integer'],
                'truncation_count' => ['type' => 'integer'],
                'truncated_summaries' => ['type' => 'array'],
            ],
        ],
        'errors' => ['type' => 'array'],
    ],
];
