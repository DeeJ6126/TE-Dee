<?php
declare(strict_types=1);

final class ConversationContextResult
{
    private function __construct(
        public readonly string $status,
        public readonly string $originalQuestion,
        public readonly string $effectiveQuestion,
        public readonly array $explicitEntities,
        public readonly array $inheritedEntities,
        public readonly array $clarificationCandidates,
        public readonly string $resolutionSource,
        public readonly string $reason
    ) {
    }

    public static function standalone(string $question, array $explicitEntities): self
    {
        return new self(
            'standalone',
            $question,
            $question,
            self::labels($explicitEntities),
            [],
            [],
            'deterministic',
            'The current question is self-contained.'
        );
    }

    public static function resolved(
        string $originalQuestion,
        string $effectiveQuestion,
        array $explicitEntities,
        array $inheritedEntities,
        string $source,
        string $reason
    ): self {
        return new self(
            'resolved_follow_up',
            $originalQuestion,
            $effectiveQuestion,
            self::labels($explicitEntities),
            self::labels($inheritedEntities),
            [],
            $source,
            $reason
        );
    }

    public static function clarification(
        string $originalQuestion,
        array $explicitEntities,
        array $candidates,
        string $source,
        string $reason
    ): self {
        return new self(
            'needs_clarification',
            $originalQuestion,
            '',
            self::labels($explicitEntities),
            [],
            self::labels($candidates),
            $source,
            $reason
        );
    }

    public function clarificationMessage(string $language): string
    {
        $isChinese = tekg_agent_normalize_language_code($language) === 'chinese';
        if ($this->clarificationCandidates === []) {
            return $isChinese
                ? '你希望我在这个追问中使用哪个 TE？'
                : 'Which TE would you like me to use for this follow-up?';
        }

        $labels = implode($isChinese ? '、' : ' or ', $this->clarificationCandidates);
        return $isChinese
            ? "你指的是哪个 TE：{$labels}？"
            : "Which TE do you mean: {$labels}?";
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'original_question' => $this->originalQuestion,
            'effective_question' => $this->effectiveQuestion,
            'explicit_entities' => $this->explicitEntities,
            'inherited_entities' => $this->inheritedEntities,
            'clarification_candidates' => $this->clarificationCandidates,
            'resolution_source' => $this->resolutionSource,
            'reason' => $this->reason,
        ];
    }

    public function applyToAnalysis(array $analysis): array
    {
        $entities = array_values(array_filter(
            (array)($analysis['normalized_entities'] ?? []),
            static fn($entity): bool => is_array($entity)
        ));
        $seen = [];
        foreach ($entities as $entity) {
            $label = trim((string)($entity['canonical_label'] ?? $entity['label'] ?? $entity['name'] ?? ''));
            if ($label !== '') {
                $seen[tekg_agent_lower($label)] = true;
            }
        }
        $explicitMap = array_fill_keys(array_map('tekg_agent_lower', $this->explicitEntities), true);
        foreach (array_merge($this->explicitEntities, $this->inheritedEntities) as $label) {
            $label = trim((string)$label);
            $key = tekg_agent_lower($label);
            if ($label === '' || isset($seen[$key])) {
                continue;
            }
            $entities[] = [
                'type' => 'TE',
                'label' => $label,
                'canonical_label' => $label,
                'display_label' => $label,
                'aliases' => [$label],
                'broad_aliases' => [],
                'matched_alias' => $label,
                'used_broad_alias' => false,
                'confidence' => isset($explicitMap[$key]) ? 0.9 : 0.85,
                'context_origin' => isset($explicitMap[$key]) ? 'user_explicit' : 'conversation_inherited',
            ];
            $seen[$key] = true;
        }
        $analysis['normalized_entities'] = $entities;
        $analysis['alias_chains'] = $entities;
        return $analysis;
    }

    private static function labels(array $values): array
    {
        $labels = [];
        $seen = [];
        foreach ($values as $value) {
            $label = is_array($value)
                ? trim((string)($value['canonical_label'] ?? $value['label'] ?? $value['name'] ?? ''))
                : trim((string)$value);
            if ($label === '') {
                continue;
            }
            $key = tekg_agent_lower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $labels[] = $label;
        }
        return $labels;
    }
}
