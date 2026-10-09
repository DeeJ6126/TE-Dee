<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/contracts/PluginResultContract.php';
require_once dirname(__DIR__) . '/contracts/SequenceDelivery.php';

/** Parameterized, bounded adapters over the existing scientific plugins. */
final class TekgAgentQaToolRegistry
{
    private const TARGET_TYPES = ['Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Paper', 'Pharmaceutical', 'Toxin', 'Lipid', 'Peptide', 'Carbohydrate', 'TE'];
    private const PLUGINS = [
        'graph_lookup' => 'Graph Plugin',
        'literature_search' => 'Literature Plugin',
        'cypher_query' => 'Cypher Explorer Plugin',
        'taxonomy_lookup' => 'Tree Plugin',
        'expression_lookup' => 'Expression Plugin',
        'eqtl_lookup' => 'eQTL Plugin',
        'genome_lookup' => 'Genome Plugin',
        'sequence_lookup' => 'Sequence Plugin',
        'graph_statistics' => 'Graph Analytics Plugin',
        'site_navigate' => 'Site Navigator Plugin',
    ];
    private const METRICS = ['diseases_by_te_count', 'target_label_distribution', 'relation_type_distribution', 'top_targets_for_te'];
    private const PAGES = ['browse', 'graph', 'path', 'expression', 'genome', 'sequence', 'download', 'about'];

    public function __construct(private readonly array $plugins)
    {
    }

    public function names(): array
    {
        return array_keys(array_filter(self::PLUGINS, fn(string $plugin): bool => isset($this->plugins[$plugin])));
    }

    public function pluginName(string $name): string
    {
        if (!isset(self::PLUGINS[$name]) || !isset($this->plugins[self::PLUGINS[$name]])) {
            throw new InvalidArgumentException('Unknown or unavailable QA tool: ' . $name);
        }
        return self::PLUGINS[$name];
    }

    public function schemas(): array
    {
        $te = ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120], 'minItems' => 1, 'maxItems' => 3,
            'description' => 'Explicit TE labels from the question, normalized entities, or previously retrieved evidence. One or more labels; never silently use the first TE of a multi-TE question.'];
        $disease = ['type' => 'string', 'minLength' => 1, 'maxLength' => 160, 'description' => 'Optional disease scope from the question or retrieved evidence.'];
        $singleTe = array_replace($te, ['maxItems' => 1, 'description' => 'Exactly one explicit TE label per invocation; compare multiple TEs by calling this tool separately for each.']);
        $types = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::TARGET_TYPES], 'minItems' => 1, 'maxItems' => count(self::TARGET_TYPES)];
        $definitions = [
            'graph_lookup' => ['Retrieve bounded local relationships for each selected TE, optionally to one selected disease. Stored relations are not causal evidence.', ['te' => $te, 'disease' => $disease, 'target_types' => $types], ['te']],
            'literature_search' => ['Search entity-scoped PubMed and local citations. Full available abstracts are retained. Refine with focus_keywords or request the next zero-based page when evidence is insufficient. Returned candidates and scope filtering do not establish scientific support.', ['te' => $te, 'disease' => $disease,
                'focus_keywords' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 80], 'maxItems' => 5, 'description' => 'Additional English biomedical phrases for mechanistic or experimental focus; entity clauses remain fixed. Empty list makes the search broad within the selected entity scope.'],
                'page' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20, 'description' => 'Zero-based PubMed relevance page; default 0.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'description' => 'Records per entity query; default 8.']], ['te']],
            'cypher_query' => ['Execute explicit read-only Cypher against tekg3. Use only MATCH/OPTIONAL MATCH, WHERE, WITH, RETURN, ORDER BY and bounded LIMIT. Labels: TE, Disease, Function, Gene, Protein, RNA, Mutation, Paper; names use name. Relationship fields include predicate, description, pmids and recorded provenance counts. No score/strength/weight/confidence exists. Counts are not biological association strength. Never use procedures, LOAD CSV or write clauses.', [
                'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 6000],
                'params' => ['type' => 'object', 'description' => 'Bound query parameters; scalar values or short lists. No connection or credential settings.'],
                'metric_definition' => ['type' => 'string', 'maxLength' => 600, 'description' => 'Explain the returned count or measurement and its unit; no invented scientific strength scores.']], ['query']],
            'taxonomy_lookup' => ['Retrieve recorded taxonomy ranks and lineage for an explicit TE or disease. Supply te, disease, or both. Missing subfamily or leaf rank stays missing; path position must not be used to infer rank.', ['te' => $te, 'disease' => $disease], []],
            'expression_lookup' => ['Retrieve existing TE expression profiles; distinguish median-ranked contexts from maximum observed expression. Empty/error is not absent biological expression.', ['te' => $te], ['te']],
            'eqtl_lookup' => ['Retrieve GTEx v11 variant-gene-tissue associations with reference-interval overlap at mapped TE loci for exactly one selected TE. Call separately for each TE in a comparison. An overlapping eQTL is not proof of TE causal regulation.', ['te' => $singleTe], ['te']],
            'genome_lookup' => ['Retrieve mapped hit counts, representative loci and sampled genomic hits. Samples do not exhaust all insertion sites.', ['te' => $te], ['te']],
            'sequence_lookup' => ['Retrieve Repbase-backed consensus length, source and annotation. Set full_sequence only for an explicit user request for complete sequence/FASTA; exact sequence is delivered by code.', ['te' => $te, 'full_sequence' => ['type' => 'boolean']], ['te']],
            'graph_statistics' => ['Run fixed, defined graph counts. diseases_by_te_count ranks Disease nodes by distinct linked TE nodes; top_targets_for_te ranks stored outgoing relation counts for one explicit TE. These counts measure local graph connectivity, not biological strength.', [
                'metric' => ['type' => 'string', 'enum' => self::METRICS], 'te' => ['type' => 'string', 'maxLength' => 120], 'target_type' => ['type' => 'string', 'enum' => self::TARGET_TYPES]], ['metric']],
            'site_navigate' => ['Return existing TE-Dee page URLs only. Navigation is not scientific evidence.', ['page' => ['type' => 'string', 'enum' => self::PAGES], 'te' => ['type' => 'string', 'maxLength' => 120]], ['page']],
        ];
        $schemas = [];
        foreach ($this->names() as $name) {
            [$description, $properties, $required] = $definitions[$name];
            $schemas[] = ['type' => 'function', 'function' => ['name' => $name, 'description' => $description,
                'parameters' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false]]];
        }
        return $schemas;
    }

    /** Returns normalized defaults for stable duplicate detection; rejects unknown fields. */
    public function validate(string $name, array $arguments): array
    {
        $this->pluginName($name);
        $schema = null;
        foreach ($this->schemas() as $tool) {
            if ($tool['function']['name'] === $name) { $schema = $tool['function']['parameters']; break; }
        }
        foreach (array_keys($arguments) as $key) {
            if (!isset($schema['properties'][$key])) { throw new InvalidArgumentException('Unexpected QA tool argument: ' . $key); }
        }
        foreach ($schema['required'] as $key) {
            if (!array_key_exists($key, $arguments)) { throw new InvalidArgumentException('Missing QA tool argument: ' . $key); }
        }
        $normalized = [];
        if (array_key_exists('te', $arguments)) {
            $labels = is_string($arguments['te']) ? [$arguments['te']] : $arguments['te'];
            if ($labels === null && in_array($name, ['graph_statistics', 'site_navigate'], true)) { $labels = []; }
            if (!is_array($labels) || !array_is_list($labels) || count($labels) > 3) { throw new InvalidArgumentException('te must contain at most three explicit TE labels.'); }
            foreach ($labels as $label) { $normalized['te'][] = $this->label($label, 'te', 120); }
            $normalized['te'] = array_values(array_unique($normalized['te'] ?? []));
            if ($normalized['te'] === [] && in_array('te', $schema['required'], true)) { throw new InvalidArgumentException('te cannot be empty.'); }
            if (in_array($name, ['graph_statistics', 'site_navigate', 'eqtl_lookup'], true) && count($normalized['te']) > 1) { throw new InvalidArgumentException('This tool requires one TE per invocation. Call separately for each TE in a comparison.'); }
        }
        if (isset($arguments['disease'])) { $normalized['disease'] = $this->label($arguments['disease'], 'disease', 160); }
        if ($name === 'taxonomy_lookup' && ($normalized['te'] ?? []) === [] && !isset($normalized['disease'])) { throw new InvalidArgumentException('taxonomy_lookup requires an explicit TE or disease.'); }
        if ($name === 'graph_lookup') {
            $values = $arguments['target_types'] ?? ['Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation'];
            if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > count(self::TARGET_TYPES)) { throw new InvalidArgumentException('target_types must be a bounded non-empty list.'); }
            foreach ($values as $value) { $this->enum($value, 'target_types', self::TARGET_TYPES); }
            $normalized['target_types'] = array_values(array_unique($values));
            sort($normalized['target_types']);
        }
        if ($name === 'literature_search') {
            $keywords = $arguments['focus_keywords'] ?? [];
            if (!is_array($keywords) || !array_is_list($keywords) || count($keywords) > 5) { throw new InvalidArgumentException('focus_keywords must contain at most five phrases.'); }
            foreach ($keywords as $keyword) {
                $keyword = $this->label($keyword, 'focus_keywords', 80);
                if (preg_match('/^[A-Za-z0-9 _\-\/\x{0027}\x{2019}]+$/u', $keyword) !== 1) { throw new InvalidArgumentException('focus_keywords must be English biomedical phrases without query operators or markup.'); }
                $normalized['focus_keywords'][] = $keyword;
            }
            $normalized['focus_keywords'] = array_values(array_unique($normalized['focus_keywords'] ?? []));
            sort($normalized['focus_keywords']);
            $normalized['page'] = $this->integer($arguments['page'] ?? 0, 'page', 0, 20);
            $normalized['limit'] = $this->integer($arguments['limit'] ?? 8, 'limit', 1, 8);
        }
        if ($name === 'cypher_query') {
            $query = $this->text($arguments['query'], 'query', 6000);
            if (preg_match('/\b(?:CALL|LOAD\s+CSV|UNION)\b|;(?!\s*$)/iu', $query) === 1) { throw new InvalidArgumentException('Cypher procedures, external loading and compound statements are not available.'); }
            $params = $arguments['params'] ?? [];
            if (!is_array($params) || ($params !== [] && array_is_list($params)) || count($params) > 30) { throw new InvalidArgumentException('params must be a bounded object.'); }
            $this->validateParameterValues($params);
            foreach ($params as $key => $_) {
                if (!is_string($key) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,63}\z/', $key) !== 1 || preg_match('/(?:password|secret|api_?key|credential|proxy|config)/i', $key) === 1) { throw new InvalidArgumentException('Invalid query parameter name.'); }
            }
            preg_match_all('/\bLIMIT\s+(\d+|\$[a-zA-Z_][a-zA-Z0-9_]*)/i', $query, $limits);
            if (preg_match('/\bLIMIT\b/i', $query) === 1
                && (count($limits[1]) !== 1 || preg_match('/\bLIMIT\s+(?:\d+|\$[a-zA-Z_][a-zA-Z0-9_]*)\s*;?\s*$/i', $query) !== 1)) {
                throw new InvalidArgumentException('Cypher LIMIT must be a single bounded literal or bound integer at the end of the query.');
            }
            foreach ($limits[1] as $limit) {
                $value = str_starts_with($limit, '$') ? ($params[substr($limit, 1)] ?? null) : (int)$limit;
                $this->integer($value, 'Cypher LIMIT', 1, 100);
            }
            ksort($params);
            $normalized['query'] = $query;
            $normalized['params'] = $params;
            $normalized['metric_definition'] = isset($arguments['metric_definition']) ? $this->text($arguments['metric_definition'], 'metric_definition', 600) : 'Read-only query values; no biological association-strength measure is available.';
        }
        if ($name === 'sequence_lookup') {
            if (isset($arguments['full_sequence']) && !is_bool($arguments['full_sequence'])) { throw new InvalidArgumentException('full_sequence must be boolean.'); }
            $normalized['full_sequence'] = $arguments['full_sequence'] ?? false;
        }
        if ($name === 'graph_statistics') {
            $normalized['metric'] = $this->enum($arguments['metric'], 'metric', self::METRICS);
            $normalized['target_type'] = $this->enum($arguments['target_type'] ?? 'Disease', 'target_type', self::TARGET_TYPES);
            if ($normalized['metric'] === 'top_targets_for_te' && ($normalized['te'] ?? []) === []) { throw new InvalidArgumentException('top_targets_for_te requires one explicit TE.'); }
            if ($normalized['metric'] !== 'top_targets_for_te' && ($normalized['te'] ?? []) !== []) { throw new InvalidArgumentException('Global graph statistics do not accept a TE filter; use top_targets_for_te.'); }
        }
        if ($name === 'site_navigate') { $normalized['page'] = $this->enum($arguments['page'], 'page', self::PAGES); }
        if (isset($normalized['te'])) { sort($normalized['te']); }
        ksort($normalized);
        return $normalized;
    }

    public function buildContext(string $name, array $arguments, array $base): array
    {
        $args = $this->validate($name, $arguments);
        if ($name === 'sequence_lookup' && ($args['full_sequence'] ?? false) && !SequenceDelivery::requested((string)($base['question'] ?? ''))) {
            throw new InvalidArgumentException('Full sequence delivery requires an explicit user request for complete sequence or FASTA.');
        }
        $entities = [];
        foreach ($args['te'] ?? [] as $label) { $entities[] = $this->resolveScopedEntity($label, 'TE', $base); }
        if (isset($args['disease'])) { $entities[] = $this->resolveScopedEntity($args['disease'], 'Disease', $base); }
        $analysis = is_array($base['analysis'] ?? null) ? $base['analysis'] : [];
        $analysis['normalized_entities'] = $entities;
        $analysis['alias_chains'] = $entities;
        $analysis['requested_target_types'] = $args['target_types'] ?? (isset($args['target_type']) ? [$args['target_type']] : []);
        $analysis['question_keywords'] = $args['focus_keywords'] ?? [];
        foreach (['compare_mode', 'asks_for_mechanism', 'asks_for_classification', 'asks_for_graph_analytics', 'asks_for_papers', 'needs_external_literature'] as $flag) { $analysis[$flag] = false; }
        $analysis['compare_mode'] = count($args['te'] ?? []) > 1;
        $analysis['intent'] = match ($name) {
            'literature_search' => 'literature', 'taxonomy_lookup' => 'classification', 'expression_lookup' => 'expression',
            'eqtl_lookup' => 'eqtl', 'genome_lookup' => 'genome', 'sequence_lookup' => 'sequence',
            'graph_statistics' => 'graph_analytics', 'site_navigate' => 'navigation', default => 'relationship',
        };
        $analysis['asks_for_classification'] = $name === 'taxonomy_lookup' && ($args['te'] ?? []) !== [];
        $analysis['asks_for_graph_analytics'] = $name === 'graph_statistics';
        $analysis['asks_for_papers'] = $analysis['needs_external_literature'] = $name === 'literature_search';
        // The graph tool always treats multiple selected TEs as independent scopes.
        if ($name === 'graph_lookup' && count($args['te'] ?? []) > 1) { $analysis['intent'] = 'comparison'; }
        $context = $base;
        $context['user_question'] = (string)($base['question'] ?? '');
        $context['qa_tool_arguments'] = $args;
        $context['analysis'] = $analysis;
        $context['entity_resolution'] = ['resolved_entities' => $entities];
        $context['plugin_results'] = is_array($base['plugin_results'] ?? null) ? $base['plugin_results'] : [];
        // Reuse only citations attached to graph rows matching this invocation.
        $scopedGraph = $this->scopedGraphResult((array)($context['plugin_results']['Graph Plugin'] ?? []), $entities);
        unset($context['plugin_results']['Graph Plugin']);
        if ($scopedGraph !== []) { $context['plugin_results']['Graph Plugin'] = $scopedGraph; }
        $context['plugin_results']['Entity Resolver'] = ['results' => ['resolved_entities' => $entities, 'alias_chains' => $entities]];
        if ($name === 'graph_statistics') {
            $context['question'] = match ($args['metric']) {
                'diseases_by_te_count' => 'Rank diseases by distinct linked TE count.',
                'relation_type_distribution' => 'Count relation types.',
                'target_label_distribution' => 'Count most commonly linked node labels.',
                default => 'Rank target nodes by stored relations for the selected TE.',
            };
        } elseif ($name === 'sequence_lookup') {
            $context['question'] = ($args['full_sequence'] ? 'Provide the complete consensus sequence in FASTA format for ' : 'Retrieve the consensus length, annotation and source for ') . implode(', ', $args['te']);
        } elseif ($name === 'site_navigate') {
            $context['question'] = 'Where can I open the ' . $args['page'] . ' page' . (isset($args['te'][0]) ? ' for ' . $args['te'][0] : '') . '?';
        }
        return $context;
    }

    public function execute(string $name, array $arguments, array $base): array
    {
        $pluginName = $this->pluginName($name);
        $context = $this->buildContext($name, $arguments, $base);
        return PluginResultContract::enforce($pluginName, $this->plugins[$pluginName]->run($context));
    }

    private function scopedGraphResult(array $result, array $entities): array
    {
        $tes = array_values(array_filter($entities, static fn(array $entity): bool => ($entity['type'] ?? '') === 'TE'));
        $diseases = array_values(array_filter($entities, static fn(array $entity): bool => ($entity['type'] ?? '') === 'Disease'));
        if ($tes === []) { return []; }
        $rows = [];
        $citationScopes = [];
        foreach ((array)($result['results']['rows'] ?? []) as $row) {
            if (!is_array($row)) { continue; }
            $teLabel = (string)($row['requested_te'] ?? $row['matched_alias'] ?? $row['source_name'] ?? '');
            $te = $this->matchingEntity($teLabel, $tes, !isset($row['requested_te']));
            if ($te === null) { continue; }
            $scope = [(string)($te['canonical_label'] ?? $te['label'])];
            if ($diseases !== []) {
                $disease = $this->matchingEntity((string)($row['requested_disease'] ?? $row['target_name'] ?? ''), $diseases, !isset($row['requested_disease']));
                if ($disease === null || (($row['target_type'] ?? '') !== 'Disease' && !in_array('Disease', (array)($row['target_labels'] ?? []), true))) { continue; }
                $scope[] = (string)($disease['canonical_label'] ?? $disease['label']);
            }
            $rows[] = $row;
            foreach ((array)($row['pmids'] ?? []) as $pmid) {
                $pmid = trim((string)$pmid);
                if ($pmid !== '') { $citationScopes['pmid:' . $pmid] = array_values(array_unique(array_merge($citationScopes['pmid:' . $pmid] ?? [], $scope))); }
            }
            foreach ((array)($row['evidence'] ?? []) as $evidence) {
                if (!is_array($evidence)) { continue; }
                $pmid = trim((string)($evidence['pmid'] ?? ''));
                $title = trim((string)($evidence['title'] ?? ''));
                $key = $pmid !== '' ? 'pmid:' . $pmid : ($title !== '' ? 'title:' . strtolower($title) : '');
                if ($key !== '') { $citationScopes[$key] = array_values(array_unique(array_merge($citationScopes[$key] ?? [], $scope))); }
            }
        }
        $citations = [];
        foreach (tekg_agent_plugin_result_citations($result) as $citation) {
            $pmid = trim((string)($citation['pmid'] ?? ''));
            $title = trim((string)($citation['title'] ?? ''));
            $key = $pmid !== '' ? 'pmid:' . $pmid : ($title !== '' ? 'title:' . strtolower($title) : '');
            if (!isset($citationScopes[$key])) { continue; }
            $citation['entity_scopes'] = $citationScopes[$key];
            $citation['query_scopes'] = [['entity_scope' => $citationScopes[$key], 'source' => 'local_graph_scoped_edge']];
            if (trim((string)($citation['abstract_text'] ?? '')) === '') {
                $citation['material_level'] = trim((string)($citation['abstract_summary'] ?? '')) !== '' ? 'graph_summary' : 'metadata';
                $citation['abstract_status'] = 'not_fetched';
            }
            $citations[] = $citation;
        }
        return $citations === [] ? [] : ['citations' => tekg_agent_dedupe_citations($citations), 'results' => ['rows' => $rows]];
    }

    private function matchingEntity(string $label, array $entities, bool $allowBroadAliases): ?array
    {
        foreach ($entities as $entity) {
            $candidates = array_merge([(string)($entity['label'] ?? ''), (string)($entity['canonical_label'] ?? '')], (array)($entity['aliases'] ?? []));
            if ($allowBroadAliases) { $candidates = array_merge($candidates, (array)($entity['broad_aliases'] ?? [])); }
            foreach ($candidates as $candidate) {
                if ($this->lookupToken($label) !== '' && $this->lookupToken($label) === $this->lookupToken((string)$candidate)) { return $entity; }
            }
        }
        return null;
    }

    private function resolveScopedEntity(string $label, string $type, array $base): array
    {
        $analysis = is_array($base['analysis'] ?? null) ? $base['analysis'] : [];
        $candidates = array_merge((array)($analysis['normalized_entities'] ?? []), (array)($analysis['alias_chains'] ?? []),
            tekg_agent_context_resolved_entities($base), (array)($base['discovered_entities'] ?? []));
        $token = $this->lookupToken($label);
        foreach ($candidates as $entity) {
            if (is_string($entity)) { $entity = ['type' => 'TE', 'label' => $entity, 'canonical_label' => $entity]; }
            if (!is_array($entity) || ($entity['type'] ?? 'TE') !== $type) { continue; }
            $names = array_merge([(string)($entity['label'] ?? ''), (string)($entity['canonical_label'] ?? '')], (array)($entity['aliases'] ?? []));
            foreach ($names as $candidate) {
                if ($token !== '' && $this->lookupToken((string)$candidate) === $token) {
                    $entity['type'] = $type;
                    $entity['label'] = trim((string)($entity['label'] ?? $label));
                    $entity['canonical_label'] = trim((string)($entity['canonical_label'] ?? $label));
                    return $entity;
                }
            }
        }
        $question = (string)($base['question'] ?? '');
        if (preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($label, '/') . '(?![\p{L}\p{N}_])/iu', $question) === 1) {
            return ['type' => $type, 'label' => $label, 'canonical_label' => $label, 'aliases' => [$label], 'broad_aliases' => [], 'resolution_source' => 'explicit_question_label'];
        }
        throw new InvalidArgumentException('Entity "' . $label . '" is outside the question and previously retrieved evidence scope. Use a known normalized entity or first retrieve evidence identifying this entity.');
    }

    private function lookupToken(string $value): string
    {
        return strtolower(preg_replace('/[\s_-]+/u', '', trim($value)) ?? '');
    }

    private function text(mixed $value, string $field, int $maximum): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maximum || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) { throw new InvalidArgumentException($field . ' must be a bounded non-empty string.'); }
        return trim($value);
    }

    private function label(mixed $value, string $field, int $maximum): string
    {
        $label = $this->text($value, $field, $maximum);
        if (str_contains($label, '://') || preg_match('/[\r\n]/', $label)) { throw new InvalidArgumentException($field . ' must be a label or phrase, not a URL or multiline content.'); }
        return $label;
    }

    private function enum(mixed $value, string $field, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) { throw new InvalidArgumentException('Invalid ' . $field . '; allowed values: ' . implode(', ', $allowed)); }
        return $value;
    }

    private function integer(mixed $value, string $field, int $minimum, int $maximum): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) { throw new InvalidArgumentException($field . ' must be an integer between ' . $minimum . ' and ' . $maximum . '.'); }
        return $value;
    }

    private function validateParameterValues(mixed $value, int $depth = 0): void
    {
        if ($depth > 3) { throw new InvalidArgumentException('Query parameter nesting is too deep.'); }
        if (is_array($value)) {
            if (count($value) > 50) { throw new InvalidArgumentException('Query parameter collection is too large.'); }
            foreach ($value as $item) { $this->validateParameterValues($item, $depth + 1); }
        } elseif ((!is_scalar($value) && $value !== null) || (is_string($value) && strlen($value) > 1000) || (is_float($value) && !is_finite($value))) {
            throw new InvalidArgumentException('Invalid or oversized query parameter value.');
        }
    }
}
