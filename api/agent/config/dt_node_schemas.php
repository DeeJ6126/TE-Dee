<?php
declare(strict_types=1);

return [
    'understanding' => [
        'version' => 'dt_understanding.v1',
        'stage' => 'understanding',
        'required' => [
            'schema_version',
            'stage',
            'question_summary',
            'answer_language',
            'intent',
            'entities',
            'answer_goal',
            'evidence_requirements',
            'warnings',
        ],
        'properties' => [
            'schema_version' => ['type' => 'string', 'const' => 'dt_understanding.v1'],
            'stage' => ['type' => 'string', 'const' => 'understanding'],
            'question_summary' => ['type' => 'string'],
            'answer_language' => ['type' => 'string', 'enum' => ['zh', 'en']],
            'intent' => ['type' => 'string'],
            'entities' => ['type' => 'array'],
            'answer_goal' => ['type' => 'string'],
            'evidence_requirements' => ['type' => 'array'],
            'warnings' => ['type' => 'array'],
        ],
    ],
    'planning' => [
        'version' => 'dt_planning.v1',
        'stage' => 'planning',
        'required' => [
            'schema_version',
            'stage',
            'business_plugins',
            'execution_goal',
            'citation_resolver_allowed',
            'rationale',
        ],
        'properties' => [
            'schema_version' => ['type' => 'string', 'const' => 'dt_planning.v1'],
            'stage' => ['type' => 'string', 'const' => 'planning'],
            'business_plugins' => ['type' => 'array'],
            'execution_goal' => ['type' => 'string'],
            'citation_resolver_allowed' => ['type' => 'boolean'],
            'rationale' => ['type' => 'string'],
        ],
    ],
    'executing' => [
        'version' => 'dt_executing.v1',
        'stage' => 'executing',
        'required' => [
            'schema_version',
            'stage',
            'done',
            'next_plugin',
            'reason',
            'evidence_summary',
            'gaps',
        ],
        'properties' => [
            'schema_version' => ['type' => 'string', 'const' => 'dt_executing.v1'],
            'stage' => ['type' => 'string', 'const' => 'executing'],
            'done' => ['type' => 'boolean'],
            'next_plugin' => ['type' => ['string', 'null']],
            'reason' => ['type' => 'string'],
            'evidence_summary' => ['type' => 'array'],
            'gaps' => ['type' => 'array'],
        ],
    ],
    'writing' => [
        'version' => 'dt_writing.v1',
        'stage' => 'writing',
        'required' => [
            'schema_version',
            'stage',
            'answer_markdown',
            'limitations',
        ],
        'properties' => [
            'schema_version' => ['type' => 'string', 'const' => 'dt_writing.v1'],
            'stage' => ['type' => 'string', 'const' => 'writing'],
            'answer_markdown' => ['type' => 'string'],
            'limitations' => ['type' => 'array'],
        ],
    ],
];
