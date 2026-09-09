<?php
declare(strict_types=1);

final class ConversationContextResolver
{
    private const MAX_EFFECTIVE_QUESTION = 1200;

    public function __construct(
        private readonly TekgAgentEntityNormalizer $normalizer,
        private readonly TekgAgentLlmClient $llm
    ) {
    }

    public function resolve(
        string $question,
        string $language,
        array $memory,
        string $mode,
        string $model
    ): ConversationContextResult {
        $question = trim($question);
        $probe = $this->normalizer->analyze($question, $language);
        $explicitEntities = $this->mostSpecificLabels($this->mergeLabels(
            $this->entityLabels((array)($probe['normalized_entities'] ?? [])),
            $this->explicitTeTokens($question)
        ));
        if (!$this->hasFollowUpSignal($question)) {
            return ConversationContextResult::standalone($question, $explicitEntities);
        }

        if ($explicitEntities !== [] && !$this->hasBackwardReferenceBeforeExplicitEntity($question, $explicitEntities)) {
            return ConversationContextResult::standalone($question, $explicitEntities);
        }

        $context = ConversationMemory::contextView($memory);
        $allowedEntities = $this->allowedContextEntities($context);
        if ($allowedEntities === []) {
            return ConversationContextResult::clarification(
                $question,
                $explicitEntities,
                [],
                'deterministic',
                'The follow-up has no prior entity context.'
            );
        }

        $generated = $this->llm->resolveConversationContext($model, $language, [
            'current_question' => $question,
            'language' => $language,
            'mode' => $mode,
            'explicit_current_entities' => $explicitEntities,
            'allowed_context_entities' => $allowedEntities,
            'conversation' => $context,
        ]);
        $validated = $this->validatedModelResult($generated, $question, $explicitEntities, $allowedEntities);
        if ($validated instanceof ConversationContextResult) {
            return $validated;
        }

        if (count($allowedEntities) === 1) {
            $entity = $allowedEntities[0];
            $isChinese = tekg_agent_normalize_language_code($language) === 'chinese';
            $effectiveQuestion = $isChinese
                ? "关于 {$entity}，回答这个追问：{$question}"
                : "Regarding {$entity}, answer this follow-up: {$question}";
            return ConversationContextResult::resolved(
                $question,
                $effectiveQuestion,
                $explicitEntities,
                [$entity],
                'deterministic_fallback',
                'The context model was unavailable or invalid; the sole active entity was used.'
            );
        }

        return ConversationContextResult::clarification(
            $question,
            $explicitEntities,
            $allowedEntities,
            'deterministic_fallback',
            'The follow-up has multiple unresolved candidate entities.'
        );
    }

    private function validatedModelResult(
        ?array $generated,
        string $question,
        array $explicitEntities,
        array $allowedEntities
    ): ?ConversationContextResult {
        if (!is_array($generated)) {
            return null;
        }
        $status = trim((string)($generated['status'] ?? ''));
        $reason = tekg_agent_substr(trim((string)($generated['reason'] ?? '')), 0, 400);
        if ($status === 'needs_clarification') {
            return ConversationContextResult::clarification(
                $question,
                $explicitEntities,
                $allowedEntities,
                'llm',
                $reason !== '' ? $reason : 'The context model found multiple plausible antecedents.'
            );
        }
        if ($status !== 'resolved_follow_up') {
            return null;
        }

        $effectiveQuestion = trim((string)($generated['effective_question'] ?? ''));
        if ($effectiveQuestion === '' || tekg_agent_strlen($effectiveQuestion) > self::MAX_EFFECTIVE_QUESTION) {
            return null;
        }
        $inherited = $this->entityLabels((array)($generated['inherited_entities'] ?? []));
        if ($inherited === []) {
            return null;
        }

        $allowedMap = [];
        foreach ($allowedEntities as $entity) {
            $allowedMap[tekg_agent_lower($entity)] = $entity;
        }
        $validatedInherited = [];
        foreach ($inherited as $entity) {
            $key = tekg_agent_lower($entity);
            if (!isset($allowedMap[$key]) || !$this->textContainsLabel($effectiveQuestion, $allowedMap[$key])) {
                return null;
            }
            $validatedInherited[] = $allowedMap[$key];
        }
        foreach ($explicitEntities as $entity) {
            if (!$this->textContainsLabel($effectiveQuestion, $entity)) {
                return null;
            }
        }

        return ConversationContextResult::resolved(
            $question,
            $effectiveQuestion,
            $explicitEntities,
            $validatedInherited,
            'llm',
            $reason !== '' ? $reason : 'The context model resolved the follow-up.'
        );
    }

    private function hasFollowUpSignal(string $question): bool
    {
        return preg_match(
            '/\b(?:it|its|they|their|them|those|these|what\s+about|how\s+about|previous\s+result|those\s+links|these\s+diseases)\b|(?:它|它的|它们|那它|那么|上述|这些关联|刚才的结果|前面的结果|还有)/iu',
            $question
        ) === 1;
    }

    private function hasBackwardReferenceBeforeExplicitEntity(string $question, array $explicitEntities): bool
    {
        if (preg_match(
            '/\b(?:it|its|they|their|them|those|these|previous\s+result|those\s+links|these\s+diseases)\b|(?:它|它的|它们|上述|这些关联|刚才的结果|前面的结果|和它)/iu',
            $question,
            $referenceMatch,
            PREG_OFFSET_CAPTURE
        ) !== 1) {
            return false;
        }

        $firstEntityOffset = null;
        foreach ($explicitEntities as $entity) {
            $offset = stripos($question, (string)$entity);
            if ($offset !== false && ($firstEntityOffset === null || $offset < $firstEntityOffset)) {
                $firstEntityOffset = $offset;
            }
        }

        return $firstEntityOffset === null || (int)$referenceMatch[0][1] < $firstEntityOffset;
    }

    private function allowedContextEntities(array $context): array
    {
        $entities = $this->entityLabels((array)($context['active_entities'] ?? []));
        foreach (array_reverse((array)($context['recent_turns'] ?? [])) as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $entities = $this->mergeLabels($entities, $this->entityLabels((array)($turn['entities'] ?? [])));
        }
        return array_values(array_slice($entities, 0, 8));
    }

    private function entityLabels(array $entities): array
    {
        $labels = [];
        foreach ($entities as $entity) {
            $label = is_array($entity)
                ? trim((string)($entity['canonical_label'] ?? $entity['label'] ?? $entity['name'] ?? ''))
                : trim((string)$entity);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        return $this->mergeLabels([], $labels);
    }

    private function explicitTeTokens(string $question): array
    {
        preg_match_all(
            '/\b(?:Alu[A-Z][A-Za-z0-9_-]*|[A-Za-z]+\d[A-Za-z0-9_-]*|[A-Z]{2,}[A-Za-z0-9]*[_-][A-Za-z0-9_-]+)\b/u',
            $question,
            $matches
        );
        return $this->mergeLabels([], (array)($matches[0] ?? []));
    }

    private function mostSpecificLabels(array $labels): array
    {
        return array_values(array_filter($labels, static function (string $candidate) use ($labels): bool {
            $candidateLower = tekg_agent_lower($candidate);
            foreach ($labels as $other) {
                $otherLower = tekg_agent_lower((string)$other);
                if ($otherLower === $candidateLower) {
                    continue;
                }
                if (str_starts_with($otherLower, $candidateLower . '_')
                    || str_starts_with($otherLower, $candidateLower . '-')) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function mergeLabels(array $left, array $right): array
    {
        $result = [];
        $seen = [];
        foreach (array_merge($left, $right) as $label) {
            $label = trim((string)$label);
            if ($label === '') {
                continue;
            }
            $key = tekg_agent_lower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $label;
        }
        return $result;
    }

    private function textContainsLabel(string $text, string $label): bool
    {
        return str_contains(tekg_agent_lower($text), tekg_agent_lower($label));
    }
}
