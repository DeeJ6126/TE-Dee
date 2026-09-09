<?php
declare(strict_types=1);

return [
    'type' => 'object',
    'required' => [
        'plugin',
        'status',
        'legacy_status',
        'intent',
        'summary',
        'raw',
        'evidence_items',
        'citations',
        'routes',
        'metrics',
        'errors',
    ],
    'properties' => [
        'plugin' => ['type' => 'string'],
        'status' => ['type' => 'string', 'enum' => ['ok', 'partial', 'empty', 'failed']],
        'legacy_status' => ['type' => ['string', 'null']],
        'intent' => ['type' => 'string'],
        'summary' => ['type' => 'string'],
        'raw' => ['type' => ['object', 'array', 'string', 'number', 'boolean', 'null']],
        'evidence_items' => ['type' => 'array'],
        'citations' => ['type' => 'array'],
        'routes' => ['type' => 'array'],
        'metrics' => [
            'type' => 'object',
            'required' => ['duration_ms', 'result_count', 'confidence'],
            'properties' => [
                'duration_ms' => ['type' => ['integer', 'null']],
                'result_count' => ['type' => 'number'],
                'confidence' => ['type' => ['number', 'string', 'null']],
            ],
        ],
        'errors' => ['type' => 'array'],
    ],
];
