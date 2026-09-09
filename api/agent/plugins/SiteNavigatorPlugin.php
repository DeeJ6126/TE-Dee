<?php
declare(strict_types=1);

final class TekgAgentSiteNavigatorPlugin implements TekgAgentPluginInterface
{
    private ?array $navigationMap = null;

    public function getName(): string
    {
        return 'Site Navigator Plugin';
    }

    public function run(array $context): array
    {
        $startedAt = microtime(true);
        $question = trim((string)($context['question'] ?? ''));
        $analysis = tekg_agent_context_analysis($context);
        $requestContext = (array)($analysis['request_context'] ?? []);
        $entity = $this->resolveEntity($analysis, $question, tekg_agent_context_resolved_entities($context, 'TE'));
        $language = tekg_agent_detect_language($question, (string)($analysis['answer_language'] ?? $analysis['language'] ?? ''));

        $routes = $this->buildRoutes($entity, (string)($requestContext['current_url'] ?? $context['current_url'] ?? ''));
        $capability = $this->detectCapability($question, $analysis);
        $candidateKeys = $this->candidateKeys($capability, $analysis);
        $candidateRoutes = [];
        foreach ($candidateKeys as $key) {
            if (isset($routes[$key])) {
                $candidateRoutes[] = $routes[$key];
            }
        }
        if ($candidateRoutes === []) {
            $candidateRoutes = array_values(array_slice($routes, 0, 5));
        }

        $primary = $candidateRoutes[0] ?? null;
        if ($primary === null) {
            $summary = 'No TE-Dee site route could be selected.';
            return [
                'plugin_name' => $this->getName(),
                'status' => 'empty',
                'query_summary' => $summary,
                'results' => [
                    'primary_route' => null,
                    'candidate_routes' => [],
                    'answer_markdown' => '',
                    'confidence' => 0.0,
                    'matched_entity' => $entity,
                    'matched_capability' => $capability,
                ],
                'display_label' => 'Site navigation',
                'display_summary' => $summary,
                'display_details' => [
                    'result_message' => $summary,
                    'preview_items' => [],
                    'evidence_items' => [],
                ],
                'result_counts' => ['routes' => 0],
                'evidence_items' => [],
                'citations' => [],
                'errors' => [],
                'confidence' => 0.0,
                'latency_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            ];
        }

        $confidence = $this->confidenceFor($capability, $question, $analysis);
        $answerMarkdown = $this->buildAnswerMarkdown($primary, array_slice($candidateRoutes, 1, 5), $entity, $language, $confidence);
        $claim = sprintf(
            'TE-Dee site navigation route for %s: [%s](%s).',
            $entity !== '' ? $entity : 'the requested item',
            (string)$primary['title'],
            (string)$primary['url']
        );
        $evidence = tekg_agent_make_diagnostic_item(
            $this->getName(),
            $claim,
            [
                'match_confidence' => $confidence,
                'matched_capability' => $capability,
            ],
            [
                'title' => (string)$primary['title'],
                'meta' => $capability,
                'body' => $claim,
            ],
            [
                'entity_scope' => $entity,
                'raw_source_ref' => $primary,
                'evidence_type' => 'site_navigation',
                'coverage_dimension' => 'navigation',
                'subject' => $entity !== '' ? $entity : null,
                'provenance' => ['source' => 'site_navigation_map'],
            ]
        );

        return [
            'plugin_name' => $this->getName(),
            'status' => 'ok',
            'query_summary' => sprintf('Matched %d TE-Dee site route candidate(s).', count($candidateRoutes)),
            'display_label' => (string)$primary['title'],
            'display_summary' => sprintf('Matched the question to the %s site route.', (string)$primary['title']),
            'display_details' => [
                'result_message' => $answerMarkdown,
                'preview_items' => array_map(static fn(array $route): array => [
                    'title' => (string)$route['title'],
                    'body' => (string)$route['description'],
                    'url' => (string)$route['url'],
                    'meta' => (string)$route['capability'],
                ], $candidateRoutes),
                'evidence_items' => [$evidence],
            ],
            'results' => [
                'primary_route' => $primary,
                'candidate_routes' => $candidateRoutes,
                'answer_markdown' => $answerMarkdown,
                'confidence' => $confidence,
                'matched_entity' => $entity,
                'matched_capability' => $capability,
            ],
            'evidence_items' => [$evidence],
            'citations' => [],
            'errors' => [],
            'confidence' => $confidence,
            'result_counts' => [
                'routes' => count($candidateRoutes),
            ],
            'latency_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function resolveEntity(array $analysis, string $question, array $entities = []): string
    {
        if ($entities === []) {
            $entities = (array)($analysis['normalized_entities'] ?? []);
        }
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            if (strtolower((string)($entity['type'] ?? '')) !== 'te') {
                continue;
            }
            $label = trim((string)($entity['canonical_label'] ?? $entity['label'] ?? $entity['canonical'] ?? $entity['name'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        if (preg_match('/\b([A-Z][A-Z0-9_-]{1,24})\b/u', $question, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function buildRoutes(string $entity, string $currentUrl): array
    {
        $routes = [];
        foreach ((array)($this->navigationMap()['routes'] ?? []) as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $routes[(string)$key] = $this->route(
                (string)($definition['title'] ?? $key),
                (string)($definition['capability'] ?? $key),
                (string)($definition['path'] ?? 'index.php'),
                $this->paramsForProfile((string)($definition['params'] ?? 'none'), $entity),
                (string)($definition['fragment'] ?? ''),
                (string)($definition['description'] ?? ''),
                $currentUrl
            );
        }
        return $routes;
    }

    private function paramsForProfile(string $profile, string $entity): array
    {
        return match ($profile) {
            'te_search' => $entity !== '' ? ['q' => $entity, 'type' => 'TE'] : ['type' => 'TE'],
            'te_param' => $entity !== '' ? ['te' => $entity] : [],
            'query_param' => $entity !== '' ? ['q' => $entity] : [],
            default => [],
        };
    }

    private function route(string $title, string $capability, string $path, array $params, string $fragment, string $description, string $currentUrl): array
    {
        $query = http_build_query(array_filter($params, static fn($value): bool => trim((string)$value) !== ''));
        $relative = tekg_app_url($path);
        if ($query !== '') {
            $relative .= '?' . $query;
        }
        if ($fragment !== '') {
            $relative .= '#' . $fragment;
        }

        return [
            'title' => $title,
            'capability' => $capability,
            'url' => $this->absoluteUrl($relative, $currentUrl),
            'path' => $path,
            'fragment' => $fragment,
            'description' => $description,
        ];
    }

    private function absoluteUrl(string $relative, string $currentUrl): string
    {
        $parts = parse_url($currentUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $relative;
        }
        $origin = (string)$parts['scheme'] . '://' . (string)$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . (string)$parts['port'];
        }
        return rtrim($origin, '/') . $relative;
    }

    private function detectCapability(string $question, array $analysis): string
    {
        $lower = tekg_agent_lower($question);
        $scores = [];
        foreach ((array)($this->navigationMap()['capability_keywords'] ?? []) as $capability => $keywords) {
            $scores[(string)$capability] = $this->score($lower, array_fill_keys(array_map('strval', (array)$keywords), 6));
        }

        arsort($scores);
        $best = (string)array_key_first($scores);
        if (($scores[$best] ?? 0) > 0) {
            return $best;
        }

        $fallbacks = (array)($this->navigationMap()['intent_fallbacks'] ?? []);
        $intent = (string)($analysis['intent'] ?? '');
        return (string)($fallbacks[$intent] ?? $fallbacks['default'] ?? 'search_summary');
    }

    private function score(string $question, array $weights): int
    {
        $score = 0;
        foreach ($weights as $needle => $weight) {
            $normalizedNeedle = tekg_agent_lower((string)$needle);
            if ($normalizedNeedle !== '' && str_contains($question, $normalizedNeedle)) {
                $score += (int)$weight;
            }
        }
        return $score;
    }

    private function candidateKeys(string $capability, array $analysis): array
    {
        $candidates = (array)($this->navigationMap()['candidate_routes'] ?? []);
        return array_values(array_map('strval', (array)($candidates[$capability] ?? $candidates['default'] ?? [])));
    }

    private function confidenceFor(string $capability, string $question, array $analysis): float
    {
        if (($analysis['asks_for_site_navigation'] ?? false) === true) {
            return 0.86;
        }
        if ($this->score(tekg_agent_lower($question), [$capability => 1]) > 0) {
            return 0.78;
        }
        return 0.68;
    }

    private function buildAnswerMarkdown(array $primary, array $alternatives, string $entity, string $language, float $confidence): string
    {
        $isChinese = in_array($language, ['zh', 'chinese'], true);
        $prefix = $isChinese
            ? ($entity !== '' ? "{$entity} 对应的站内入口是" : '对应的站内入口是')
            : ($entity !== '' ? "The TE-Dee entry for {$entity} is" : 'The TE-Dee entry is');
        $body = sprintf('%s [%s](%s).', $prefix, (string)$primary['title'], (string)$primary['url']);

        if ($alternatives !== []) {
            $body .= $isChinese ? "\n\n如果你想看的不是这个面板，也可以选择：" : "\n\nIf this is not the panel you wanted, use one of these choices:";
            foreach ($alternatives as $route) {
                $body .= sprintf("\n- [%s](%s) - %s", (string)$route['title'], (string)$route['url'], (string)$route['description']);
            }
        }

        if ($confidence < 0.75) {
            $body .= $isChinese
                ? "\n\n我没有强行判定唯一入口，因此保留了多个候选链接。"
                : "\n\nI kept multiple choices because the requested site location is not unique.";
        }

        return $body;
    }

    private function navigationMap(): array
    {
        if ($this->navigationMap !== null) {
            return $this->navigationMap;
        }
        $path = dirname(__DIR__) . '/config/site_navigation_map.php';
        $map = is_file($path) ? require $path : [];
        $this->navigationMap = is_array($map) ? $map : [];
        return $this->navigationMap;
    }
}
