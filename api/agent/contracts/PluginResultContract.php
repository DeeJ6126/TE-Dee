<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/evidence_support.php';

final class PluginResultContract
{
    private const REQUIRED_FIELDS = [
        'plugin_name',
        'status',
        'query_summary',
        'results',
        'display_label',
        'display_summary',
        'display_details',
        'result_counts',
        'evidence_items',
        'citations',
        'errors',
        'latency_ms',
    ];

    private const NATIVE_STATUSES = ['ok', 'partial', 'empty', 'error'];
    private const SUPPORT_STRENGTHS = ['high', 'medium', 'low', 'none'];

    public static function requiredFields(): array
    {
        return self::REQUIRED_FIELDS;
    }

    public static function validate(string $expectedPluginName, mixed $result): array
    {
        if (!is_array($result)) {
            return ['ok' => false, 'errors' => ['plugin result must be an array']];
        }

        $errors = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $result)) {
                $errors[] = $field . ' is required';
            }
        }

        $pluginName = trim((string)($result['plugin_name'] ?? ''));
        if ($pluginName !== $expectedPluginName) {
            $errors[] = 'plugin_name must equal ' . $expectedPluginName;
        }

        $status = strtolower(trim((string)($result['status'] ?? '')));
        if (!in_array($status, self::NATIVE_STATUSES, true)) {
            $errors[] = 'status must be one of: ' . implode(', ', self::NATIVE_STATUSES);
        }

        foreach (['query_summary', 'display_label', 'display_summary'] as $field) {
            if (array_key_exists($field, $result) && !is_string($result[$field])) {
                $errors[] = $field . ' must be a string';
            }
        }
        foreach (['results', 'display_details', 'result_counts', 'evidence_items', 'citations', 'errors'] as $field) {
            if (array_key_exists($field, $result) && !is_array($result[$field])) {
                $errors[] = $field . ' must be an array';
            }
        }

        if (array_key_exists('latency_ms', $result)
            && (!is_numeric($result['latency_ms']) || (float)$result['latency_ms'] < 0)
        ) {
            $errors[] = 'latency_ms must be a non-negative number';
        }

        foreach ((array)($result['evidence_items'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                $errors[] = "evidence_items[{$index}] must be an array";
                continue;
            }
            $normalized = tekg_agent_normalize_evidence_item($item, $expectedPluginName);
            if ($normalized === null || trim((string)($normalized['claim'] ?? '')) === '') {
                $errors[] = "evidence_items[{$index}].claim is required";
                continue;
            }
            if (!in_array((string)($item['support_strength'] ?? ''), self::SUPPORT_STRENGTHS, true)) {
                $errors[] = "evidence_items[{$index}].support_strength is invalid";
            }
            $sourcePlugin = trim((string)($item['source_plugin'] ?? $expectedPluginName));
            if ($sourcePlugin !== $expectedPluginName) {
                $errors[] = "evidence_items[{$index}].source_plugin must equal {$expectedPluginName}";
            }
        }

        foreach ((array)($result['citations'] ?? []) as $index => $citation) {
            if (!is_array($citation)) {
                $errors[] = "citations[{$index}] must be an array";
                continue;
            }
            $hasIdentity = false;
            foreach (['pmid', 'doi', 'url', 'title', 'reference', 'citation'] as $field) {
                if (trim((string)($citation[$field] ?? '')) !== '') {
                    $hasIdentity = true;
                    break;
                }
            }
            if (!$hasIdentity) {
                $errors[] = "citations[{$index}] must include an identifier, URL, or title";
            }
        }

        $nativeErrors = (array)($result['errors'] ?? []);
        foreach ($nativeErrors as $index => $error) {
            if (!is_string($error) || trim($error) === '') {
                $errors[] = "errors[{$index}] must be a non-empty string";
            }
        }
        if ($status === 'ok' && $nativeErrors !== []) {
            $errors[] = 'status ok cannot include errors';
        }
        if ($status === 'empty' && $nativeErrors !== []) {
            $errors[] = 'status empty cannot include errors';
        }
        if ($status === 'error' && $nativeErrors === []) {
            $errors[] = 'status error must include at least one error';
        }

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public static function enforce(string $expectedPluginName, mixed $result): array
    {
        $validation = self::validate($expectedPluginName, $result);
        if ($validation['ok']) {
            return $result;
        }

        $latency = is_array($result) && isset($result['latency_ms']) && is_numeric($result['latency_ms'])
            ? max(0, (int)$result['latency_ms'])
            : 0;
        $message = 'Plugin result contract violation: ' . implode('; ', $validation['errors']);

        return [
            'plugin_name' => $expectedPluginName,
            'status' => 'error',
            'query_summary' => 'The plugin returned an invalid native result.',
            'results' => [
                'contract_errors' => $validation['errors'],
            ],
            'display_label' => $expectedPluginName . ' contract error',
            'display_summary' => $message,
            'display_details' => [
                'summary' => $message,
                'preview_items' => [],
                'evidence_items' => [],
                'citations' => [],
                'result_message' => $message,
            ],
            'result_counts' => [],
            'evidence_items' => [],
            'citations' => [],
            'errors' => [$message],
            'latency_ms' => $latency,
        ];
    }
}
