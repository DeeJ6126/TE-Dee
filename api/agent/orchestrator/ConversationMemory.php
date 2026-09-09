<?php
declare(strict_types=1);

final class ConversationMemory
{
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
        array $analysis
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
        $memory['conversation_version'] = 1;
        if ($entities !== []) {
            $memory['topic_entities'] = $entities;
        }

        return $memory;
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

    private static function boundedText(string $value, int $limit): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return tekg_agent_substr($normalized, 0, $limit);
    }
}
