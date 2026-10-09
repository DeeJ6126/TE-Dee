<?php
declare(strict_types=1);

final class ConversationMemory
{
    public const HISTORY_VERSION = 2;
    public const MAX_DIALOGUE_TURNS = 12;
    public const MAX_DIALOGUE_BYTES = 1048576;
    public const MAX_DIALOGUE_TURN_BYTES = 524288;
    public const HISTORY_NOTICE = 'Earlier assistant messages are conversation text, not verified scientific evidence. Recheck scientific claims against the current run\'s retrieved sources. User-supplied material remains attributed to the user.';

    private const MAX_TURNS = 3;
    private const MAX_ENTITIES = 8;
    private const MAX_ORIGINAL_QUESTION = 500;
    private const MAX_EFFECTIVE_QUESTION = 700;
    private const MAX_ANSWER_SUMMARY = 800;

    public static function appendCompletedTurn(
        array $memory,
        string $mode,
        string $originalQuestion,
        string $effectiveQuestion,
        string $answer,
        array $analysis,
        array $evidenceReferences = []
    ): array {
        $memory = array_replace(tekg_agent_default_session_memory(), $memory);
        $entities = self::entityLabels((array)($analysis['normalized_entities'] ?? []));
        $turn = [
            'mode' => self::boundedText($mode, 24),
            'original_question' => self::boundedText($originalQuestion, self::MAX_ORIGINAL_QUESTION),
            'effective_question' => self::boundedText($effectiveQuestion, self::MAX_EFFECTIVE_QUESTION),
            'answer_summary' => self::boundedText(strip_tags($answer), self::MAX_ANSWER_SUMMARY),
            'entities' => $entities,
            'intent' => self::boundedText((string)($analysis['intent'] ?? ''), 80),
        ];

        $turns = array_values(array_filter(
            (array)($memory['recent_turns'] ?? []),
            static fn($item): bool => is_array($item)
        ));
        $turns[] = $turn;
        $memory['recent_turns'] = array_values(array_slice($turns, -self::MAX_TURNS));
        $memory['last_mode'] = $turn['mode'];
        $memory['last_intent'] = $turn['intent'];
        $memory['conversation_version'] = self::HISTORY_VERSION;
        if ($entities !== []) {
            $memory['topic_entities'] = $entities;
        }

        $history = self::normalizedHistory($memory);
        $turnBytes = strlen($originalQuestion) + strlen($answer);
        $reason = '';
        if (preg_match('//u', $originalQuestion) !== 1 || preg_match('//u', $answer) !== 1) {
            $reason = 'invalid_utf8';
        } elseif ($originalQuestion === '' || $answer === '') {
            $reason = 'empty_turn';
        } elseif ($turnBytes > self::MAX_DIALOGUE_TURN_BYTES) {
            $reason = 'turn_exceeds_byte_limit';
        }

        if ($reason === '') {
            $history['turns'][] = [
                'mode' => $turn['mode'],
                'original_question' => $originalQuestion,
                'answer' => $answer,
                'evidence_references' => self::evidenceReferences($evidenceReferences),
            ];
            $history['total_bytes'] += $turnBytes;
            self::enforceHistoryLimits($history);
        } else {
            $history['omitted_turns']++;
        }
        $memory['dialogue_turns'] = $history['turns'];
        $memory['dialogue_history'] = [
            'version' => self::HISTORY_VERSION,
            'trimmed_turns' => $history['trimmed_turns'],
            'omitted_turns' => $history['omitted_turns'],
            'last_turn_status' => $reason === '' ? 'retained' : 'omitted',
            'last_omission_reason' => $reason,
            'last_turn_bytes' => $turnBytes,
        ];

        return $memory;
    }

    /** Complete user/assistant turns only; legacy summaries cannot recover missing text. */
    public static function historyView(array $memory): array
    {
        $history = self::normalizedHistory($memory);
        $messages = [];
        $references = [];
        foreach ($history['turns'] as $turn) {
            $messages[] = ['role' => 'user', 'content' => $turn['original_question']];
            $messages[] = ['role' => 'assistant', 'content' => $turn['answer']];
            $references[] = $turn['evidence_references'];
        }
        $metadata = is_array($memory['dialogue_history'] ?? null) ? $memory['dialogue_history'] : [];
        return [
            'messages' => $messages,
            'retained_turns' => count($history['turns']),
            'trimmed_turns' => $history['trimmed_turns'],
            'omitted_turns' => $history['omitted_turns'],
            'total_bytes' => $history['total_bytes'],
            'limits' => [
                'turns' => self::MAX_DIALOGUE_TURNS,
                'bytes' => self::MAX_DIALOGUE_BYTES,
                'turn_bytes' => self::MAX_DIALOGUE_TURN_BYTES,
            ],
            'version' => self::HISTORY_VERSION,
            'history_notice' => self::HISTORY_NOTICE,
            'evidence_references' => $references,
            'legacy_summary_only' => $history['turns'] === [] && !empty($memory['recent_turns']),
            'last_turn_status' => in_array($metadata['last_turn_status'] ?? '', ['retained', 'omitted'], true)
                ? $metadata['last_turn_status'] : '',
            'last_omission_reason' => in_array($metadata['last_omission_reason'] ?? '', ['invalid_utf8', 'empty_turn', 'turn_exceeds_byte_limit'], true)
                ? $metadata['last_omission_reason'] : '',
        ];
    }

    public static function contextView(array $memory): array
    {
        $memory = array_replace(tekg_agent_default_session_memory(), $memory);
        return [
            'recent_turns' => array_values(array_slice(
                array_values(array_filter(
                    (array)($memory['recent_turns'] ?? []),
                    static fn($item): bool => is_array($item)
                )),
                -self::MAX_TURNS
            )),
            'active_entities' => array_values(array_slice(array_filter(array_map(
                static fn($label): string => trim((string)$label),
                (array)($memory['topic_entities'] ?? [])
            )), 0, self::MAX_ENTITIES)),
            'last_intent' => self::boundedText((string)($memory['last_intent'] ?? ''), 80),
            'last_mode' => self::boundedText((string)($memory['last_mode'] ?? ''), 24),
        ];
    }

    private static function entityLabels(array $entities): array
    {
        $labels = [];
        $seen = [];
        foreach ($entities as $entity) {
            if (is_array($entity)) {
                $label = trim((string)($entity['canonical_label'] ?? $entity['label'] ?? $entity['name'] ?? ''));
            } else {
                $label = trim((string)$entity);
            }
            if ($label === '') {
                continue;
            }
            $key = tekg_agent_lower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $labels[] = self::boundedText($label, 160);
            if (count($labels) >= self::MAX_ENTITIES) {
                break;
            }
        }
        return $labels;
    }

    private static function normalizedHistory(array $memory): array
    {
        $metadata = is_array($memory['dialogue_history'] ?? null) ? $memory['dialogue_history'] : [];
        $history = [
            'turns' => [],
            'total_bytes' => 0,
            'trimmed_turns' => max(0, (int)($metadata['trimmed_turns'] ?? 0)),
            'omitted_turns' => max(0, (int)($metadata['omitted_turns'] ?? 0)),
        ];
        foreach ((array)($memory['dialogue_turns'] ?? []) as $turn) {
            if (!is_array($turn)
                || !is_string($turn['original_question'] ?? null)
                || !is_string($turn['answer'] ?? null)
                || $turn['original_question'] === '' || $turn['answer'] === ''
                || preg_match('//u', $turn['original_question']) !== 1
                || preg_match('//u', $turn['answer']) !== 1
                || strlen($turn['original_question']) + strlen($turn['answer']) > self::MAX_DIALOGUE_TURN_BYTES) {
                $history['omitted_turns']++;
                continue;
            }
            $history['turns'][] = [
                'mode' => self::boundedText((string)($turn['mode'] ?? ''), 24),
                'original_question' => $turn['original_question'],
                'answer' => $turn['answer'],
                'evidence_references' => self::evidenceReferences((array)($turn['evidence_references'] ?? [])),
            ];
            $history['total_bytes'] += strlen($turn['original_question']) + strlen($turn['answer']);
        }
        self::enforceHistoryLimits($history);
        return $history;
    }

    private static function enforceHistoryLimits(array &$history): void
    {
        while (count($history['turns']) > self::MAX_DIALOGUE_TURNS || $history['total_bytes'] > self::MAX_DIALOGUE_BYTES) {
            $removed = array_shift($history['turns']);
            $history['total_bytes'] -= strlen($removed['original_question']) + strlen($removed['answer']);
            $history['trimmed_turns']++;
        }
    }

    /** Opaque source pointers only. No tool payloads, credentials, or private reasoning. */
    private static function evidenceReferences(array $references): array
    {
        $result = [];
        foreach (['request_id', 'run_id'] as $key) {
            $value = $references[$key] ?? null;
            if (is_string($value) && preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $value) === 1) {
                $result[$key] = $value;
            }
        }
        foreach (['evidence_ids', 'citation_ids', 'pmids'] as $key) {
            $values = [];
            foreach ((array)($references[$key] ?? []) as $value) {
                if (!is_string($value) && !is_int($value)) {
                    continue;
                }
                $value = (string)$value;
                $pattern = $key === 'pmids' ? '/\A[0-9]{1,12}\z/' : '/\A[a-zA-Z0-9_:-]{1,128}\z/';
                if (preg_match($pattern, $value) === 1) {
                    $values[$value] = $value;
                }
                if (count($values) >= 128) {
                    break;
                }
            }
            if ($values !== []) {
                $result[$key] = array_values($values);
            }
        }
        $urls = [];
        foreach ((array)($references['urls'] ?? []) as $url) {
            if (!is_string($url) || strlen($url) > 2048 || preg_match('/\s/u', $url) !== 0) {
                continue;
            }
            $parts = parse_url($url);
            if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $urls[$url] = $url;
            if (count($urls) >= 24) {
                break;
            }
        }
        if ($urls !== []) {
            $result['urls'] = array_values($urls);
        }
        return $result;
    }

    private static function boundedText(string $value, int $limit): string
    {
        // Resolver summaries must remain serializable even without mbstring.
        if (preg_match('//u', $value) !== 1) {
            $value = (string)json_decode((string)json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE), true);
        }
        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $bounded = tekg_agent_substr($normalized, 0, $limit);
        while ($bounded !== '' && preg_match('//u', $bounded) !== 1) {
            $bounded = substr($bounded, 0, -1);
        }
        return $bounded;
    }
}
