<?php
declare(strict_types=1);

require_once __DIR__ . '/SequenceDelivery.php';

final class UserFacingWritingContext
{
    public static function sanitizeAnswer(string $answer): string
    {
        $replacements = [
            '/^#{1,6}\s*(?:Evidence Inventory|Citation Assessment|Question Scope|Final Answer)\s*$/imu' => '## Findings',
            '/^#{1,6}\s*(?:证据清单|引用评估|问题范围|最终答案)\s*$/imu' => '## 结果',
            '/\bGraph Analytics Plugin\b/iu' => 'TE-Dee graph analysis',
            '/\b(?:Graph Plugin|Cypher Explorer Plugin)\b/iu' => 'TE-Dee graph data',
            '/\bExpression Plugin\b/iu' => 'TE-Dee expression data',
            '/\bGenome Plugin\b/iu' => 'TE-Dee genome browser',
            '/\bSequence Plugin\b/iu' => 'Repbase-backed sequence data',
            '/\bTree Plugin\b/iu' => 'TE-Dee taxonomy',
            '/\bLiterature(?: Reading)? Plugin\b/iu' => 'the literature search',
            '/\bSite Navigator Plugin\b/iu' => 'TE-Dee navigation',
            '/\b(?:Entity Resolver|Citation Resolver)\b/iu' => 'TE-Dee',
            '/\bclaim[ -]evidence map\b/iu' => 'available findings',
            '/\bevidence (?:package|walk|accounting)\b/iu' => 'available evidence',
            '/\bassociation[_-]not[_-]causality\b/iu' => 'reported associations do not by themselves establish causation',
            '/\bkeyword[_-]derived\b/iu' => 'inferred from record keywords',
            '/\bmetadata[_-]or[_-]abstract[_-]level\b/iu' => 'based on citation metadata or abstracts',
            '/\bsupport[_ -]strength\b/iu' => 'level of support',
            '/\bsupport accounting\b/iu' => 'sources and limits',
            '/\bevidence dimensions?\b/iu' => 'types of available data',
            '/\bclaim counts?\b/iu' => 'number of findings',
            '/\bevidence counts?\b/iu' => 'number of records',
            '/\braw quality flags?\b/iu' => 'data limitations',
            '/\b(?:claim|evidence|route)_[A-Za-z0-9_-]+\b/iu' => '',
            '/\bcitation_([0-9]+)\b/iu' => '$1',
        ];
        $sanitized = preg_replace(array_keys($replacements), array_values($replacements), $answer);
        if (!is_string($sanitized)) {
            return trim($answer);
        }
        $sanitized = preg_replace('/[ \t]{2,}/u', ' ', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\n{3,}/u', "\n\n", $sanitized) ?? $sanitized;
        return trim($sanitized);
    }

    /**
     * @return array{ok:bool,violations:array<int,string>}
     */
    public static function auditAnswer(string $answer, string $question = '', array $writingContext = []): array
    {
        $checks = [
            'registered_plugin_name' => '/\b(?:Entity Resolver|Graph(?: Analytics)? Plugin|Cypher Explorer Plugin|Expression Plugin|Genome Plugin|Sequence Plugin|Tree Plugin|Literature(?: Reading)? Plugin|Citation Resolver|Site Navigator Plugin)\b|图谱插件|序列插件|表达插件|文献插件/iu',
            'internal_identifier' => '/\b(?:claim|evidence|citation|route)(?:_id)?_[A-Za-z0-9_-]+\b/iu',
            'raw_quality_flag' => '/\b(?:association[_-]not[_-]causality|keyword[_-]derived|metadata[_-]or[_-]abstract[_-]level|raw quality flags?)\b/iu',
            'support_accounting' => '/\bsupport[_ -]strength\b|\bsupport accounting\b|\b(?:claim|evidence)[_ -](?:count|dimensions?)\b|支持强度|(?:论断|证据)(?:数量|维度|计数)/iu',
            'internal_audit_heading' => '/^#{1,6}\s*(?:Evidence Inventory|Citation Assessment|Question Scope|Final Answer|证据清单|引用评估|问题范围|最终答案)\s*$/imu',
            'internal_source_language' => '/\b(?:internal data|internal database|internal TE-Dee|internal pipeline)\b|\bTE-Dee\b.{0,40}\bpipeline\b|内部(?:数据|数据库|流程|管线)|TE-Dee.{0,20}(?:流程|管线)/iu',
            'internal_mapping_language' => '/\bclaim[ -]evidence map\b|\bevidence accounting\b|论断[ -]?证据映射|证据计数/iu',
        ];
        if (!self::explicitStructureRequest($question)) {
            $checks['unrequested_structure_hint'] = '/\bstructure hints?\b|结构线索/iu';
        }

        $violations = [];
        foreach ($checks as $name => $pattern) {
            if (preg_match($pattern, $answer) === 1) {
                $violations[] = $name;
            }
        }

        $factText = implode(' ', array_map(
            static fn(array $fact): string => (string)($fact['statement'] ?? ''),
            array_filter((array)($writingContext['facts'] ?? []), 'is_array')
        ));
        // Complete supplied abstracts are scientific source material too. Titles alone are not.
        $abstractText = implode(' ', array_map(
            static fn(array $reference): string => trim((string)($reference['abstract_text'] ?? '')),
            array_filter((array)($writingContext['references'] ?? []), 'is_array')
        ));
        $factText .= ' ' . (preg_replace('/\bORF([12])p\b/iu', 'ORF$1', $abstractText) ?? $abstractText);
        // Explicit text transformations may preserve features in the supplied text with attribution.
        $factText .= ' ' . (string)($writingContext['conversation_material'] ?? '');
        $factText = self::featureVocabulary($factText);
        $featureAnswer = self::featureVocabulary($answer);
        foreach ([
            '/\bfull[- ]length (?:element|sequence)\b/iu',
            '/\bORF\s*1(?:p)?\b/iu',
            '/\bORF\s*2(?:p)?\b/iu',
            '/\b5(?:[\x{2032}\x{2019}\x{0027}-]|-prime)?\s*UTR\b/iu',
            '/\b3(?:[\x{2032}\x{2019}\x{0027}-]|-prime)?\s*UTR\b/iu',
        ] as $unsupportedDetailPattern) {
            if (preg_match($unsupportedDetailPattern, $featureAnswer) === 1
                && preg_match($unsupportedDetailPattern, $factText) !== 1
            ) {
                $violations[] = 'unsupported_structure_expansion';
                break;
            }
        }

        return [
            'ok' => $violations === [],
            'violations' => $violations,
        ];
    }

    public static function writingGuidance(array $writingDecision, array $reportPlan = []): array
    {
        $sections = array_values(array_filter(
            array_map('strval', (array)($writingDecision['required_sections'] ?? [])),
            static fn(string $section): bool => trim($section) !== ''
                && !in_array(strtolower(trim($section)), ['question scope', 'evidence inventory', 'citation assessment', 'answer'], true)
        ));
        if ($sections === []) {
            $sections = ['Findings', 'Limitations'];
        }
        if ((string)($reportPlan['report_type'] ?? '') === 'research_report') {
            $sections = array_values(array_unique(array_map(
                static fn(string $section): string => in_array(strtolower(trim($section)), ['literature evidence', '文献证据'], true)
                    ? 'References'
                    : $section,
                $sections
            )));
        }

        return [
            'required_sections' => $sections,
            'forbidden_scientific_claims' => self::userFacingStrings((array)($writingDecision['forbidden_claims'] ?? [])),
            'citation_requirements' => self::userFacingStrings((array)($writingDecision['citation_requirements'] ?? [])),
            'tone' => self::containsInternalVocabulary((string)($writingDecision['tone'] ?? ''))
                ? 'Clear, direct, and appropriately cautious.'
                : trim((string)($writingDecision['tone'] ?? 'Clear, direct, and appropriately cautious.')),
            'final_checks' => self::userFacingStrings((array)($writingDecision['final_checks'] ?? [])),
        ];
    }

    public static function fromInternal(
        string $question,
        array $analysis,
        array $evidencePackage,
        array $evidenceWalk = [],
        array $claimEvidenceMap = [],
        array $reportPlan = []
    ): array {
        $citationsById = self::citationsById((array)($evidencePackage['citation_map'] ?? []));
        $claimsById = self::claimsById((array)($evidencePackage['claims'] ?? []));
        $facts = [];
        $references = [];
        $limitations = [];
        $seenFacts = [];
        $hasUncitedRuntimeFact = false;
        $explicitStructureRequest = self::explicitStructureRequest($question);

        foreach ((array)($evidencePackage['evidence_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $raw = is_array($item['raw'] ?? null) ? $item['raw'] : [];
            $flags = array_values(array_unique(array_map('strval', array_merge(
                (array)($item['quality_flags'] ?? []),
                (array)($raw['quality_flags'] ?? [])
            ))));
            $evidenceType = trim((string)($item['evidence_type'] ?? $raw['evidence_type'] ?? ''));
            if (($evidenceType === 'structure_hint' || in_array('keyword_derived', $flags, true)) && !$explicitStructureRequest) {
                continue;
            }

            $claimId = trim((string)($item['claim_id'] ?? ''));
            $claim = is_array($claimsById[$claimId] ?? null) ? $claimsById[$claimId] : [];
            $statement = self::naturalStatement(trim((string)($item['text'] ?? $claim['text'] ?? '')), $raw);
            if ($statement === '' || isset($seenFacts[$statement])) {
                continue;
            }

            $citations = [];
            foreach ((array)($claim['citation_ids'] ?? []) as $citationId) {
                $citation = $citationsById[(string)$citationId] ?? null;
                if (is_array($citation)) {
                    self::appendCitation($citations, $references, $citation);
                }
            }
            foreach ((array)($raw['citations'] ?? []) as $citation) {
                if (is_array($citation)) {
                    self::appendCitation($citations, $references, $citation);
                }
            }

            $source = self::sourceLabel((string)($item['plugin'] ?? $claim['source_plugin'] ?? $raw['source_plugin'] ?? ''));
            $facts[] = [
                'statement' => $statement,
                'source' => $source,
                'citations' => array_values($citations),
            ];
            $seenFacts[$statement] = true;

            if ($citations === [] && !in_array($source, ['Entity resolution', 'TE-Dee taxonomy'], true)) {
                $hasUncitedRuntimeFact = true;
            }
            if (in_array('association_not_causality', $flags, true)) {
                $limitations['association'] = 'These links describe reported associations and do not by themselves establish causation.';
            }
            if (in_array('metadata_or_abstract_level', $flags, true)) {
                $limitations['abstract'] = 'This literature summary is based on citation metadata or abstracts rather than a full-text review.';
            }
            if (in_array('keyword_derived', $flags, true) && $explicitStructureRequest) {
                $limitations['keyword'] = 'The available structural annotation was inferred from record keywords and has not been independently validated.';
            }
        }

        if ($hasUncitedRuntimeFact) {
            $limitations['runtime'] = 'Some TE-Dee runtime measurements in this answer do not have a linked publication in the supplied evidence.';
        }

        return [
            'question' => $question,
            'report_type' => trim((string)($reportPlan['report_type'] ?? 'research_report')),
            'facts' => $facts,
            'references' => array_values($references),
            'limitations' => array_values($limitations),
            'factual_boundaries' => self::factualBoundaries($facts, $question),
            'presentation_guidance' => self::presentationGuidance((string)($reportPlan['report_type'] ?? 'research_report'), $question),
        ];
    }

    public static function fromEvidenceItems(
        string $question,
        array $evidenceItems,
        array $citations = [],
        array $limitations = [],
        string $reportType = 'research_report'
    ): array {
        $facts = [];
        $references = [];
        $plainLimitations = [];
        $seenFacts = [];
        $explicitStructureRequest = self::explicitStructureRequest($question);

        foreach ($evidenceItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $flags = array_values(array_unique(array_map('strval', (array)($item['quality_flags'] ?? []))));
            $evidenceType = trim((string)($item['evidence_type'] ?? ''));
            if (($evidenceType === 'structure_hint' || in_array('keyword_derived', $flags, true)) && !$explicitStructureRequest) {
                continue;
            }

            $statement = self::naturalStatement(trim((string)($item['claim'] ?? $item['body'] ?? '')), $item);
            if ($statement === '' || isset($seenFacts[$statement])) {
                continue;
            }

            $factCitations = [];
            foreach ((array)($item['citations'] ?? []) as $citation) {
                if (is_array($citation)) {
                    self::appendCitation($factCitations, $references, $citation);
                }
            }
            $facts[] = [
                'statement' => $statement,
                'source' => self::sourceLabel((string)($item['source_plugin'] ?? '')),
                'citations' => array_values($factCitations),
            ];
            $seenFacts[$statement] = true;

            if (in_array('association_not_causality', $flags, true)) {
                $plainLimitations['association'] = 'These links describe reported associations and do not by themselves establish causation.';
            }
            if (in_array('metadata_or_abstract_level', $flags, true)) {
                $plainLimitations['abstract'] = 'This literature summary is based on citation metadata or abstracts rather than a full-text review.';
            }
            if (in_array('keyword_derived', $flags, true) && $explicitStructureRequest) {
                $plainLimitations['keyword'] = 'The available structural annotation was inferred from record keywords and has not been independently validated.';
            }
        }

        foreach ($citations as $citation) {
            if (is_array($citation)) {
                $unused = [];
                self::appendCitation($unused, $references, $citation);
            }
        }
        foreach (self::userFacingStrings($limitations) as $limitation) {
            $plainLimitations['runtime:' . strtolower($limitation)] = $limitation;
        }

        return [
            'question' => $question,
            'report_type' => trim($reportType) !== '' ? trim($reportType) : 'research_report',
            'facts' => $facts,
            'references' => array_values($references),
            'limitations' => array_values($plainLimitations),
            'factual_boundaries' => self::factualBoundaries($facts, $question),
            'presentation_guidance' => self::presentationGuidance($reportType, $question),
        ];
    }

    private static function featureVocabulary(string $text): string
    {
        $text = str_replace(['′','’'], "'", $text);
        $patterns = [
            '/\bfirst\s+open reading frame\b|\bopen reading frame\s*(?:1|one)\b/iu' => 'ORF1',
            '/\bsecond\s+open reading frame\b|\bopen reading frame\s*(?:2|two)\b/iu' => 'ORF2',
            '/\b([53])(?:[\x{0027}]?[- ]*|[- ]prime[- ]*)(?:untranslated region|UTR)\b/iu' => '$1 UTR',
            '/\bfull[- ]length\s+(?:human\s+)?(?:LINE[- ]?1|L1HS|L1Hs|L1)\b/iu' => 'full-length element',
        ];
        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }

    private static function presentationGuidance(string $reportType, string $question = ''): array
    {
        if (SequenceDelivery::requested($question)) {
            return [
                'For a sequence-delivery request, keep the explanation brief: record name, measured length, source, exact versus broad alias match, and any retrieval limitation.',
                'When a stored sequence is available, tell the reader its FASTA follows. Do not generate nucleotide bases; the application delivers the retrieved sequence.',
                'Do not introduce ORFs, UTRs, motifs, or full-length biological structure, including speculative discussions of their absence, unless the user also explicitly asks about them.',
            ];
        }
        if (trim($reportType) !== 'research_report') {
            return ['Answer directly and include only findings needed for the question.'];
        }
        return [
            'Aim for roughly 600-900 words.',
            'Select representative findings instead of enumerating every available relation.',
            'Prefer about 6-10 of the most relevant references unless the user asks for a comprehensive review.',
        ];
    }

    private static function factualBoundaries(array $facts, string $question): array
    {
        $sources = array_values(array_unique(array_map(
            static fn(array $fact): string => (string)($fact['source'] ?? ''),
            array_filter($facts, 'is_array')
        )));
        $boundaries = [
            'Use only the supplied facts. A reference title is not evidence for an additional scientific claim.',
            'When a reference supplies abstract_text, you may use findings explicitly stated in that abstract with its citation. Abstracts are not full-text review, and metadata-only records cannot establish a mechanism.',
        ];
        $normalizedQuestion = strtolower($question);
        if (SequenceDelivery::requested($question)) {
            $boundaries[] = 'The application appends the exact stored sequence as FASTA after successful writing. When a supplied fact confirms the stored nucleotide sequence is available for exact FASTA delivery, explain its source and length, and say the FASTA follows. Do not say it is unavailable merely because nucleotide bases are omitted from this writing context. Do not generate nucleotide bases yourself.';
        }
        if (in_array('Repbase-backed sequence data', $sources, true) || preg_match('/\bsequence\b|序列/iu', $normalizedQuestion) === 1) {
            $boundaries[] = 'For sequence, do not infer completeness, ORFs, UTRs, motifs, or structural features unless a supplied fact states them.';
        }
        if (in_array('TE-Dee genome browser', $sources, true) || preg_match('/\b(?:genomic|genome|locus|location)\b|基因组|位点|位置/iu', $normalizedQuestion) === 1) {
            $boundaries[] = 'Do not infer genome-wide distribution, assembly details, gene-density preferences, or truncation patterns from a representative locus or hit count.';
        }
        if (in_array('TE-Dee expression data', $sources, true) || preg_match('/\bexpression\b|表达/iu', $normalizedQuestion) === 1) {
            $boundaries[] = 'Do not infer the assay platform, normalization method, absolute abundance, or biological mechanism from expression rankings.';
        }
        if (in_array('TE-Dee taxonomy', $sources, true) || preg_match('/classification|subfamily|taxonomy|分类|亚家族/iu', $question) === 1) {
            $boundaries[] = 'Use the explicit taxonomy rank fields. Family, superfamily, subclade, leaf and subfamily are different fields. If subfamily is not recorded, say that it is unavailable and report the recorded family or lineage; never infer rank from a path position.';
        }
        if (in_array('GTEx TE-overlap associations', $sources, true)) {
            $boundaries[] = 'Report eQTL slopes, nominal p values and tissues exactly as supplied. Do not infer LD independence, replication, statistical power, normalization units, calibrated biological effect size, or between-tissue comparability unless those facts are explicitly provided. A positive slope describes the recorded association direction, not causal TE regulation.';
        }
        if (in_array('TE-Dee graph data', $sources, true)) {
            $boundaries[] = 'For rankings, state the supplied metric and its units. Counts of TE nodes, relations, or publications do not measure biological association strength, causality, or a validated effect size. Do not invent a strength score or treat a missing-value default as a measurement.';
        }
        return $boundaries;
    }

    private static function claimsById(array $claims): array
    {
        $indexed = [];
        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $id = trim((string)($claim['id'] ?? ''));
            if ($id !== '') {
                $indexed[$id] = $claim;
            }
        }
        return $indexed;
    }

    private static function citationsById(array $citationMap): array
    {
        $indexed = [];
        foreach ($citationMap as $entry) {
            if (!is_array($entry) || !is_array($entry['citation'] ?? null)) {
                continue;
            }
            $id = trim((string)($entry['id'] ?? ''));
            if ($id !== '') {
                $indexed[$id] = $entry['citation'];
            }
        }
        return $indexed;
    }

    private static function appendCitation(array &$citations, array &$references, array $citation): void
    {
        $pmid = trim((string)($citation['pmid'] ?? ''));
        $doi = trim((string)($citation['doi'] ?? ''));
        $title = trim((string)($citation['title'] ?? ''));
        $title = trim($title, " \t\n\r\0\x0B\"';");
        if ($pmid === '' && $doi === '' && $title !== '' && preg_match('/\s/u', $title) !== 1) {
            $title = '';
        }
        $url = trim((string)($citation['url'] ?? ''));
        $display = $pmid !== '' ? 'PMID ' . $pmid : ($doi !== '' ? 'DOI ' . $doi : $title);
        if ($display === '') {
            return;
        }
        $key = strtolower($pmid !== '' ? 'pmid:' . $pmid : ($doi !== '' ? 'doi:' . $doi : 'title:' . $title));
        $public = array_filter([
            'display' => $display,
            'pmid' => $pmid,
            'doi' => $doi,
            'title' => $title,
            'year' => trim((string)($citation['year'] ?? '')),
            'journal' => trim((string)($citation['journal'] ?? '')),
            'authors' => trim((string)($citation['authors'] ?? '')),
            'url' => $url,
            'abstract_text' => trim((string)($citation['abstract_text'] ?? (($citation['material_level'] ?? '') === 'graph_summary' ? '' : ($citation['abstract_summary'] ?? '')))),
            'record_summary' => ($citation['material_level'] ?? '') === 'graph_summary' ? trim((string)($citation['abstract_summary'] ?? '')) : '',
            'material_level' => trim((string)($citation['material_level'] ?? '')),
            'entity_scopes' => array_values((array)($citation['entity_scopes'] ?? [])),
        ], static fn(mixed $value): bool => is_array($value) ? $value !== [] : $value !== '');
        if (isset($references[$key]) || isset($citations[$key])) {
            require_once dirname(__DIR__) . '/orchestrator/CitationResolver.php';
            $public = (new TekgAgentCitationResolver())->mergeCitationRecords(
                (array)($references[$key] ?? $citations[$key]),
                $public
            );
        }
        $citations[$key] = $public;
        $references[$key] = $public;
    }

    private static function naturalStatement(string $statement, array $raw): string
    {
        if ($statement === '') {
            return '';
        }
        if (preg_match('/^[^(]+\((.+)\)\s*$/u', $statement, $matches) === 1) {
            $inner = trim((string)$matches[1]);
            if ($inner !== '') {
                return $inner;
            }
        }
        return trim(str_replace(' BIO_RELATION ', ' is related to ', $statement));
    }

    private static function sourceLabel(string $pluginName): string
    {
        return match (trim($pluginName)) {
            'Entity Resolver' => 'Entity resolution',
            'Graph Plugin', 'Graph Analytics Plugin', 'Cypher Explorer Plugin' => 'TE-Dee graph data',
            'Expression Plugin' => 'TE-Dee expression data',
            'Genome Plugin' => 'TE-Dee genome browser',
            'eQTL Plugin' => 'GTEx TE-overlap associations',
            'Sequence Plugin' => 'Repbase-backed sequence data',
            'Tree Plugin' => 'TE-Dee taxonomy',
            'Literature Plugin', 'Literature Reading Plugin', 'Citation Resolver' => 'Literature evidence',
            'Site Navigator Plugin' => 'TE-Dee navigation',
            default => 'TE-Dee data',
        };
    }

    private static function explicitStructureRequest(string $question): bool
    {
        $question = strtolower($question);
        foreach (['structure', 'structural', 'motif', 'domain', 'orf', 'promoter', 'annotation', '结构', '基序', '结构域', '开放阅读框', '启动子', '注释'] as $marker) {
            if (str_contains($question, $marker)) {
                return true;
            }
        }
        return false;
    }

    private static function userFacingStrings(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '', $values),
            static fn(string $value): bool => $value !== '' && !self::containsInternalVocabulary($value)
        ));
    }

    private static function containsInternalVocabulary(string $value): bool
    {
        return preg_match(
            '/\b(?:claim|evidence|citation|route)_[A-Za-z0-9_-]+\b|association_not_causality|keyword_derived|support[ -]strength|\b[A-Za-z ]+ Plugin\b|\bschema\b|evidence[ -]dimensions?|claim counts?|evidence counts?|\binternal (?:ids?|data|workflow|pipeline|expression data)\b/iu',
            $value
        ) === 1;
    }
}
