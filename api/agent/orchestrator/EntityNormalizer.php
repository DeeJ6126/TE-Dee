<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/taxonomy_lib.php';

final class TekgAgentEntityNormalizer
{
    private ?array $teNames = null;
    private ?array $treeNames = null;
    private ?array $diseaseNames = null;
    private ?array $teAliasIndex = null;
    private ?array $diseaseAliasIndex = null;
    private ?array $teBroadAliasIndex = null;
    private ?array $diseaseBroadAliasIndex = null;

    public function analyze(string $question, string $language = ''): array
    {
        $language = tekg_agent_detect_language($question, $language);
        $intent = $this->detectIntent($question);
        $normalizedEntities = $this->collectEntities($question);
        $targetTypes = $this->detectTargetTypes($question, $intent);
        $keywords = $this->extractKeywords($question);
        $complexity = $this->detectComplexity($question, $intent, $normalizedEntities, $targetTypes);
        $taskComplexity = $this->detectTaskComplexity($question, $intent, $normalizedEntities);
        $aliasChains = $this->buildAliasChains($normalizedEntities);
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($question)) ?: []));

        $hasResearchIntent = $this->hasResearchIntentWords($question);
        $asksForSiteNavigation = $this->asksForSiteNavigation($question) && !$hasResearchIntent;
        $asksForPapers = $this->asksForLiteratureEvidence($question);

        return array_merge([
            'language' => $language,
            'intent' => $intent,
            'complexity' => $complexity,
            'normalized_entities' => $normalizedEntities,
            'alias_chains' => $aliasChains,
            'requested_target_types' => $targetTypes,
            'question_keywords' => $keywords,
            'question_tokens' => $tokens,
            'asks_for_papers' => $asksForPapers,
            'asks_for_expression' => $this->containsAny($question, ['expression', 'expressed', 'cell line', 'cell lines', 'tissue', 'context', 'transcriptome', '表达', '组织', '细胞系', '转录组']),
            'asks_for_genome' => $intent !== 'expression' && $this->containsAny($question, ['genome', 'genomic', 'browser', 'locus', 'location', 'located', 'located at', 'where is', 'chromosome', 'chr', 'coordinate', '基因组', '基因组浏览器', '位点', '位置', '定位', '染色体', '坐标', '位于哪里', '位于哪', '位于', '在哪里', '在哪个染色体', '在哪条染色体', '哪个染色体', '哪条染色体', '基因组位置', '基因组坐标', '染色体位置']),
            'asks_for_classification' => $this->containsAny($question, ['classif', 'subfamily', 'family', 'tree', 'category', 'lineage', 'taxonomy', '分类', '亚家族', '家族', '树', '谱系']),
            'asks_for_sequence' => $this->containsAny($question, ['sequence', 'consensus', 'repbase', 'length', 'orf', 'orfs', 'utr', 'promoter', 'motif', 'structure', 'annotation', '序列', '共识序列', '长度', '结构', '注释', '启动子', '基序']),
            'asks_for_site_navigation' => $asksForSiteNavigation,
            'asks_for_mechanism' => $intent === 'mechanism',
            'asks_for_disease_links' => $this->containsAny($question, ['disease link', 'disease links', 'disease association', 'disease associations', 'associated disease', 'associated diseases', '疾病关联', '疾病联系', '相关疾病']),
            'asks_for_graph_analytics' => $intent === 'graph_analytics' || $this->containsAny($question, ['topology', 'topological', 'graph structure', 'largest number', 'most associated', 'most connected', 'highest degree', 'strongest', 'strongest association', 'graph association', 'rank', 'ranked', 'ranking', 'most common', 'node labels', 'relation type', 'relation types', 'distribution', '最多', '最大', '拓扑', '结构', '关联度', '连接最多', '分布', '关系类型']),
            'asks_for_cypher_explorer' => $this->containsAny($question, ['cypher', 'read-only cypher', 'pattern', 'patterns', 'graph exploration', 'explore the graph', 'node labels', 'most common node labels', 'path', 'paths', 'schema', '统计', '模式', '路径', '探索图谱', '探索知识图谱']),
            'asks_for_graph_structure' => $this->containsAny($question, ['topology', 'topological', 'graph structure', 'node labels', 'most common', 'relation type', 'distribution', 'degree', 'central', '结构', '拓扑', '中心性', '度数', '关系类型']),
            'compare_mode' => $this->containsAny($question, ['compare', 'versus', 'vs', 'difference', '比较', '对比', '区别', '差异']),
            'needs_external_literature' => $intent === 'mechanism'
                || $intent === 'graph_analytics'
                || $this->containsAny($question, ['recent', 'latest', '最新'])
                || $asksForPapers
                || count($normalizedEntities) <= 1,
        ], $taskComplexity);
    }

    private function collectEntities(string $question): array
    {
        $results = [];
        $results = array_merge($results, $this->matchFromAliasIndex($question, $this->loadTeAliasIndex(), 'TE', false));
        $results = array_merge($results, $this->matchFromAliasIndex($question, $this->loadDiseaseAliasIndex(), 'Disease', false));

        if ($results === []) {
            $results = array_merge($results, $this->matchFromAliasIndex($question, $this->loadTeBroadAliasIndex(), 'TE', true));
            $results = array_merge($results, $this->matchFromAliasIndex($question, $this->loadDiseaseBroadAliasIndex(), 'Disease', true));
        }

        if ($this->containsAny($question, ['human transposons', 'human transposable elements', 'transposable elements'])) {
            $results[] = $this->makeEntity('TE', 'TE', 'Transposable elements', ['TE', 'Transposable elements', 'Human transposons'], [], 'Transposable elements', false, 0.72);
        }

        $seen = [];
        $unique = [];
        foreach ($results as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $key = ($entity['type'] ?? '') . '::' . tekg_agent_normalize_lookup_token((string)($entity['canonical_label'] ?? $entity['label'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $entity;
        }

        usort($unique, static function (array $left, array $right): int {
            $leftScore = (float)($left['confidence'] ?? 0.0);
            $rightScore = (float)($right['confidence'] ?? 0.0);
            if ($leftScore !== $rightScore) {
                return $leftScore < $rightScore ? 1 : -1;
            }
            $leftLabel = (string)($left['canonical_label'] ?? $left['label'] ?? '');
            $rightLabel = (string)($right['canonical_label'] ?? $right['label'] ?? '');
            return strcasecmp($leftLabel, $rightLabel);
        });

        return $unique;
    }

    private function buildAliasChains(array $entities): array
    {
        $chains = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $chains[] = [
                'type' => (string)($entity['type'] ?? ''),
                'canonical_label' => (string)($entity['canonical_label'] ?? $entity['label'] ?? ''),
                'display_label' => (string)($entity['display_label'] ?? $entity['label'] ?? ''),
                'matched_alias' => (string)($entity['matched_alias'] ?? ''),
                'aliases' => array_values(array_unique(array_filter(array_map(
                    static fn($value): string => trim((string)$value),
                    is_array($entity['aliases'] ?? null) ? $entity['aliases'] : []
                )))),
                'broad_aliases' => array_values(array_unique(array_filter(array_map(
                    static fn($value): string => trim((string)$value),
                    is_array($entity['broad_aliases'] ?? null) ? $entity['broad_aliases'] : []
                )))),
                'used_broad_alias' => (bool)($entity['used_broad_alias'] ?? false),
                'confidence' => (float)($entity['confidence'] ?? 0.0),
            ];
        }
        return $chains;
    }

    private function matchFromAliasIndex(string $question, array $index, string $type, bool $isBroad): array
    {
        $results = [];
        $blockedGeneric = [
            'te', 'tes', 'line', 'lines', 'ltr', 'sine', 'dna', 'rna', 'protein', 'proteins', 'gene', 'genes',
            'disease', 'diseases', 'function', 'functions', 'paper', 'papers', 'mutation', 'mutations',
        ];
        foreach ($index as $canonical => $payload) {
            if (in_array(tekg_agent_normalize_lookup_token($canonical), $blockedGeneric, true)) {
                continue;
            }
            $aliases = is_array($payload['aliases'] ?? null) ? $payload['aliases'] : [];
            $broadAliases = is_array($payload['broad_aliases'] ?? null) ? $payload['broad_aliases'] : [];
            $pool = $isBroad ? $broadAliases : $aliases;
            foreach ($pool as $alias) {
                if (in_array(tekg_agent_normalize_lookup_token((string)$alias), $blockedGeneric, true)) {
                    continue;
                }
                if (!$this->questionContainsEntity($question, (string)$alias)) {
                    continue;
                }
                $results[] = $this->makeEntity(
                    $type,
                    $canonical,
                    $canonical,
                    $aliases,
                    $broadAliases,
                    (string)$alias,
                    $isBroad,
                    $isBroad ? 0.58 : 0.93
                );
                break;
            }
        }
        return $results;
    }

    private function makeEntity(
        string $type,
        string $canonical,
        string $displayLabel,
        array $aliases,
        array $broadAliases,
        string $matchedAlias,
        bool $usedBroadAlias,
        float $confidence
    ): array {
        return [
            'type' => $type,
            'label' => $canonical,
            'canonical_label' => $canonical,
            'display_label' => $displayLabel,
            'aliases' => array_values(array_unique(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                $aliases
            )))),
            'broad_aliases' => array_values(array_unique(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                $broadAliases
            )))),
            'matched_alias' => $matchedAlias,
            'used_broad_alias' => $usedBroadAlias,
            'confidence' => round($confidence, 2),
        ];
    }

    private function detectIntent(string $question): string
    {
        if ($this->containsAny($question, ['topology', 'topological', 'graph structure', 'largest number', 'most associated', 'most connected', 'highest degree', 'strongest', 'strongest association', 'graph association', 'rank', 'ranked', 'ranking', 'most common', 'node labels', 'cypher', 'graph exploration', 'relation type', 'relation types', 'distribution', '最多', '最大', '拓扑', '结构', '关联度', '关系类型'])) {
            return 'graph_analytics';
        }
        if ($this->containsAny($question, ['how', 'why', 'mechanism', 'cause', 'causal', 'pathway', 'drive', 'lead to', '机制', '为什么', '如何', '导致', '通路', '因果'])) {
            return 'mechanism';
        }
        if ($this->asksForLiteratureEvidence($question)) {
            return 'literature';
        }
        if ($this->containsAny($question, ['expression', 'expressed', 'cell line', 'tissue', 'transcriptome', '表达', '组织', '细胞系', '转录组'])) {
            return 'expression';
        }
        if ($this->containsAny($question, ['genome', 'genomic', 'browser', 'locus', 'location', 'located', 'located at', 'where is', 'chromosome', 'chr', 'coordinate', '基因组', '浏览器', '位点', '位置', '定位', '染色体', '坐标', '位于哪里', '位于哪', '位于', '在哪里', '在哪个染色体', '在哪条染色体', '哪个染色体', '哪条染色体', '基因组位置', '基因组坐标', '染色体位置'])) {
            return 'genome';
        }
        if ($this->containsAny($question, ['sequence', 'consensus', 'repbase', 'length', 'orf', 'utr', 'motif', 'structure', 'annotation', '序列', '共识序列', '长度', '结构', '注释', '启动子'])) {
            return 'sequence';
        }
        if ($this->containsAny($question, ['classif', 'subfamily', 'family', 'tree', 'category', 'taxonomy', 'lineage', '分类', '亚家族', '家族', '树', '谱系'])) {
            return 'classification';
        }
        if ($this->containsAny($question, ['compare', 'versus', 'vs', 'difference', '比较', '对比', '区别', '差异'])) {
            return 'comparison';
        }
        return 'relationship';
    }

    private function detectComplexity(string $question, string $intent, array $entities, array $targetTypes): string
    {
        $entityCount = count($entities);
        $targetCount = count($targetTypes);
        $hasMechanismWords = $this->containsAny($question, ['how', 'why', 'mechanism', 'pathway', 'causal', 'lead to', 'result in', '机制', '为什么', '如何', '导致', '通路']);
        $hasCompareWords = $this->containsAny($question, ['compare', 'versus', 'vs', 'difference', '比较', '对比', '区别', '差异']);
        $hasAnalyticsWords = $this->containsAny($question, ['topology', 'graph structure', 'largest number', 'most associated', 'most connected', 'highest degree', 'strongest', 'strongest association', 'graph association', 'rank', 'ranked', 'ranking', 'most common', 'node labels', 'cypher', 'graph exploration', 'relation type', 'distribution', '最多', '最大', '拓扑', '结构', '关联度']);

        if ($intent === 'mechanism' || $hasMechanismWords) {
            return 'mechanism_chain';
        }
        if ($intent === 'graph_analytics' || $hasAnalyticsWords) {
            return 'multi_evidence_synthesis';
        }
        if ($hasCompareWords || $intent === 'comparison' || $entityCount >= 2) {
            return 'multi_evidence_synthesis';
        }
        if (in_array($intent, ['literature', 'classification', 'expression', 'genome', 'sequence'], true) || $targetCount >= 2) {
            return 'single_hop_reasoning';
        }
        return 'simple_lookup';
    }

    private function detectTaskComplexity(string $question, string $intent, array $entities): array
    {
        $hasResearchWords = $this->hasResearchIntentWords($question);
        $hasSiteNavigationWords = $this->asksForSiteNavigation($question);

        $hasMechanismWords = $this->containsAny($question, ['how', 'why', 'mechanism', 'cause', 'causal', 'pathway', 'drive', 'lead to', 'result in', '机制', '为什么', '如何', '导致', '通路', '因果']);
        if ($intent === 'mechanism' || $hasMechanismWords) {
            return $this->makeTaskComplexity('mechanism_chain', 'agent', 'Mechanism or causal-chain question.');
        }

        if ($hasSiteNavigationWords && !$hasResearchWords) {
            return $this->makeTaskComplexity('simple_lookup', 'deepthink', 'Direct site navigation covered by Deep Think.');
        }

        if ($intent === 'literature' || $intent === 'comparison' || $intent === 'graph_analytics' || $hasResearchWords) {
            return $this->makeTaskComplexity('research_synthesis', 'agent', 'Research, evidence, comparison, analytics, or report task.');
        }

        if ($hasSiteNavigationWords) {
            return $this->makeTaskComplexity('simple_lookup', 'deepthink', 'Direct site navigation covered by Deep Think.');
        }

        if (in_array($intent, ['sequence', 'genome', 'expression', 'classification'], true) || $hasSiteNavigationWords) {
            return $this->makeTaskComplexity('simple_lookup', 'deepthink', 'Direct lookup covered by Deep Think.');
        }

        if ($intent === 'relationship' || count($entities) >= 1) {
            return $this->makeTaskComplexity('single_hop', 'deepthink', 'Single-hop relationship listing.');
        }

        return $this->makeTaskComplexity('ambiguous', 'deepthink', 'Ambiguous task; use Deep Think for clarification.');
    }

    private function makeTaskComplexity(string $taskComplexity, string $recommendedMode, string $reason): array
    {
        return [
            'task_complexity' => $taskComplexity,
            'recommended_mode' => $recommendedMode,
            'task_complexity_reason' => $reason,
        ];
    }

    private function hasResearchIntentWords(string $question): bool
    {
        return $this->asksForLiteratureEvidence($question) || $this->containsAny($question, [
            'compare',
            'versus',
            'vs',
            'difference',
            'topology',
            'topological',
            'graph structure',
            'largest number',
            'most associated',
            'most connected',
            'highest degree',
            'strongest',
            'strongest association',
            'graph association',
            'rank',
            'ranking',
            'ranked',
            'most common',
            'node labels',
            'cypher',
            'graph exploration',
            'centrality',
            'report',
            'review',
            'dossier',
            '比较',
            '对比',
            '区别',
            '差异',
            '最多',
            '最大',
            '排名',
            '拓扑',
            '结构',
            '关联度',
            '中心性',
            '报告',
            '综述',
        ]);
    }

    private function asksForLiteratureEvidence(string $question): bool
    {
        return $this->containsAny($question, [
            'paper',
            'papers',
            'reference',
            'references',
            'pubmed',
            'literature',
            'citation',
            'citations',
            'pmid',
            '文献',
            '论文',
            '参考文献',
            '引用',
        ]);
    }

    private function asksForSiteNavigation(string $question): bool
    {
        return $this->containsAny($question, [
            'where can i find',
            'where can i open',
            'where do i find',
            'where do i open',
            'where to find',
            'where to open',
            'which page',
            'what page',
            'which panel',
            'what panel',
            'open page',
            'open the',
            'open panel',
            'show page',
            'show panel',
            'url',
            'page link',
            'entry',
            'panel',
            '入口',
            '网址',
            '链接',
            '页面',
            '在哪看',
            '哪里看',
            '在哪里看',
            '从哪里看',
            '点哪里',
            '点哪',
            '应该点哪里',
            '怎么进入',
            '跳转',
            '打开',
            '查看页面',
        ]);
    }

    private function detectTargetTypes(string $question, string $intent): array
    {
        $mapping = [
            'Carbohydrate' => ['carbohydrate', 'carbohydrates'],
            'Disease' => ['disease', 'diseases', 'illness', 'phenotype', 'cancer', 'carcinoma', '疾病', '癌症', '肿瘤', '表型'],
            'DiseaseCategory' => ['diseasecategory', 'disease category', 'disease class', 'disease classes', '疾病分类', '疾病类别'],
            'Function' => ['function', 'functions', 'role', 'mechanism', 'activity', 'retrotransposition', '功能', '机制', '作用', '活性', '逆转座'],
            'Gene' => ['gene', 'genes', '基因'],
            'Lipid' => ['lipid', 'lipids', '脂质'],
            'Mutation' => ['mutation', 'mutations', 'variant', 'variants', 'insertion', '突变', '变异', '插入'],
            'Paper' => ['paper', 'papers', 'literature', 'reference', 'references', 'pubmed', 'citation', '文献', '论文', '参考文献', '引用'],
            'Peptide' => ['peptide', 'peptides', '肽'],
            'Pharmaceutical' => ['drug', 'drugs', 'pharmaceutical', 'pharmaceuticals', '药物'],
            'Protein' => ['protein', 'proteins', 'orf1p', 'orf2p', 'reverse transcriptase', '蛋白', '逆转录酶'],
            'RNA' => ['rna', 'rnas', 'mrna', 'lncrna', '转录本'],
            'TE' => ['transposable element', 'transposable elements', 'te', 'tes', 'retrotransposon', '转座子', '转座元件'],
            'Toxin' => ['toxin', 'toxins'],
        ];

        $targets = [];
        foreach ($mapping as $type => $needles) {
            if ($this->containsAny($question, $needles)) {
                $targets[] = $type;
            }
        }

        if ($targets === [] && $intent === 'mechanism') {
            $targets = ['Function', 'Gene', 'Mutation', 'Protein', 'RNA', 'Disease'];
        }
        if ($targets === [] && $intent === 'sequence') {
            $targets = ['TE'];
        }
        if ($targets === [] && $intent === 'classification') {
            $targets = ['TE'];
        }
        if ($targets === [] && $intent === 'graph_analytics') {
            $targets = ['Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Paper', 'TE'];
        }
        if ($targets === []) {
            $targets = ['Disease', 'Function', 'Gene', 'Protein', 'RNA', 'Mutation', 'Paper', 'TE'];
        }

        return array_values(array_unique($targets));
    }

    private function extractKeywords(string $question): array
    {
        $keywords = [];
        foreach ([
            'alternative splicing',
            'genome instability',
            'retrotransposition',
            'immune',
            'inflammation',
            'cancer',
            'carcinoma',
            'tumor',
            'tumour',
            'alzheimer',
            'parkinson',
            'aging',
            'metastasis',
            'oncogenic',
            'p53',
            'mutation',
            'expression',
            'sequence',
            'consensus',
            'orf1p',
            'orf2p',
            'topology',
            'graph structure',
            'relation type',
            'distribution',
            'highest degree',
            'most associated',
            'cypher',
            'pattern',
            'path',
            '机制',
            '癌症',
            '表达',
            '序列',
            '结构',
            '文献',
            '分类',
            '拓扑',
            '关系类型',
            '分布',
            '统计',
            '模式',
            '路径',
        ] as $keyword) {
            if ($this->containsAny($question, [$keyword])) {
                $keywords[] = $keyword;
            }
        }
        return $keywords;
    }

    private function containsAny(string $question, array $needles): bool
    {
        $normalized = tekg_agent_lower($question);
        foreach ($needles as $needle) {
            if (str_contains($normalized, tekg_agent_lower((string)$needle))) {
                return true;
            }
        }
        return false;
    }

    private function questionContainsEntity(string $question, string $entity): bool
    {
        $entity = trim($entity);
        if ($entity === '') {
            return false;
        }
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $entity)) {
            return str_contains($question, $entity);
        }
        $entityLower = tekg_agent_lower($entity);
        $questionLower = tekg_agent_lower($question);
        $quoted = preg_quote($entityLower, '/');
        $quoted = str_replace(['\ ', '\-', '\_'], ['[\s\-_]*', '[\s\-_]*', '[\s\-_]*'], $quoted);
        return (bool)preg_match('/(?<![a-z0-9])' . $quoted . '(?![a-z0-9])/u', $questionLower);
    }

    private function loadTeAliasIndex(): array
    {
        if (is_array($this->teAliasIndex)) {
            return $this->teAliasIndex;
        }

        $index = [];
        foreach ($this->loadTeNames() as $name) {
            $index[$name] = [
                'aliases' => $this->generateAliases($name),
                'broad_aliases' => [],
            ];
        }

        foreach (tekg_agent_entity_alias_map() as $rule) {
            if (!is_array($rule) || (string)($rule['entity_type'] ?? '') !== 'TE') {
                continue;
            }
            $canonical = trim((string)($rule['canonical_label'] ?? ''));
            if ($canonical === '') {
                continue;
            }
            $existing = $index[$canonical] ?? ['aliases' => [$canonical], 'broad_aliases' => []];
            $existing['aliases'] = array_values(array_unique(array_merge(
                $existing['aliases'],
                array_map('strval', is_array($rule['aliases'] ?? null) ? $rule['aliases'] : [])
            )));
            $existing['broad_aliases'] = array_values(array_unique(array_merge(
                $existing['broad_aliases'],
                array_map('strval', is_array($rule['broad_aliases'] ?? null) ? $rule['broad_aliases'] : [])
            )));
            $index[$canonical] = $existing;
        }

        $this->teAliasIndex = $index;
        return $this->teAliasIndex;
    }

    private function loadDiseaseAliasIndex(): array
    {
        if (is_array($this->diseaseAliasIndex)) {
            return $this->diseaseAliasIndex;
        }

        $index = [];
        foreach ($this->loadDiseaseNames() as $name) {
            $index[$name] = [
                'aliases' => $this->generateAliases($name),
                'broad_aliases' => [],
            ];
        }

        foreach (tekg_agent_entity_alias_map() as $rule) {
            if (!is_array($rule) || (string)($rule['entity_type'] ?? '') !== 'Disease') {
                continue;
            }
            $canonical = trim((string)($rule['canonical_label'] ?? ''));
            if ($canonical === '') {
                continue;
            }
            $existing = $index[$canonical] ?? ['aliases' => [$canonical], 'broad_aliases' => []];
            $existing['aliases'] = array_values(array_unique(array_merge(
                $existing['aliases'],
                array_map('strval', is_array($rule['aliases'] ?? null) ? $rule['aliases'] : [])
            )));
            $existing['broad_aliases'] = array_values(array_unique(array_merge(
                $existing['broad_aliases'],
                array_map('strval', is_array($rule['broad_aliases'] ?? null) ? $rule['broad_aliases'] : [])
            )));
            $index[$canonical] = $existing;
        }

        $this->diseaseAliasIndex = $index;
        return $this->diseaseAliasIndex;
    }

    private function loadTeBroadAliasIndex(): array
    {
        if (is_array($this->teBroadAliasIndex)) {
            return $this->teBroadAliasIndex;
        }
        $this->teBroadAliasIndex = array_filter(
            $this->loadTeAliasIndex(),
            static fn(array $payload): bool => (array)($payload['broad_aliases'] ?? []) !== []
        );
        return $this->teBroadAliasIndex;
    }

    private function loadDiseaseBroadAliasIndex(): array
    {
        if (is_array($this->diseaseBroadAliasIndex)) {
            return $this->diseaseBroadAliasIndex;
        }
        $this->diseaseBroadAliasIndex = array_filter(
            $this->loadDiseaseAliasIndex(),
            static fn(array $payload): bool => (array)($payload['broad_aliases'] ?? []) !== []
        );
        return $this->diseaseBroadAliasIndex;
    }

    private function generateAliases(string $label): array
    {
        $label = trim($label);
        if ($label === '') {
            return [];
        }
        $aliases = [$label];
        if (str_contains($label, '_')) {
            $aliases[] = str_replace('_', '-', $label);
            $aliases[] = str_replace('_', ' ', $label);
        }
        if (str_contains($label, '-')) {
            $aliases[] = str_replace('-', '', $label);
            $aliases[] = str_replace('-', ' ', $label);
        }
        if (str_contains($label, ' ')) {
            $aliases[] = str_replace(' ', '', $label);
            $aliases[] = str_replace(' ', '-', $label);
        }
        return array_values(array_unique(array_filter($aliases)));
    }

    private function loadTeNames(): array
    {
        if (is_array($this->teNames)) {
            return $this->teNames;
        }
        $names = [];
        $dbNamesPath = TEKG_DATA_FS_DIR . '/processed/_db_te_names_current.json';
        if (is_file($dbNamesPath)) {
            $decoded = json_decode((string)file_get_contents($dbNamesPath), true);
            if (is_array($decoded)) {
                foreach ($decoded as $name) {
                    if (is_string($name) && trim($name) !== '') {
                        $names[] = trim($name);
                    }
                }
            }
        }
        foreach ($this->loadTreeNames() as $name) {
            $names[] = $name;
        }
        $this->teNames = array_values(array_unique($names));
        return $this->teNames;
    }

    private function loadTreeNames(): array
    {
        if (is_array($this->treeNames)) {
            return $this->treeNames;
        }
        $names = [];
        try {
            foreach (tekg_taxonomy_fetch_items() as $item) {
                $name = trim((string)($item['name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
                foreach ((array)($item['path_labels'] ?? []) as $label) {
                    $label = trim((string)$label);
                    if ($label !== '') {
                        $names[] = $label;
                    }
                }
            }
        } catch (Throwable) {
            $names = [];
        }
        $this->treeNames = array_values(array_unique($names));
        return $this->treeNames;
    }

    private function loadDiseaseNames(): array
    {
        if (is_array($this->diseaseNames)) {
            return $this->diseaseNames;
        }
        $path = TEKG_DATA_FS_DIR . '/processed/disease_top_class_map.json';
        $names = [];
        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) {
                foreach (array_keys($decoded) as $name) {
                    if (is_string($name) && trim($name) !== '') {
                        $names[] = trim($name);
                    }
                }
            }
        }
        $this->diseaseNames = array_values(array_unique($names));
        return $this->diseaseNames;
    }
}
