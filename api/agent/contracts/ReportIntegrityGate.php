<?php
declare(strict_types=1);

final class ReportIntegrityGate
{
    public static function normalizeUrlsInText(string $text): string
    {
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/u',
            static function (array $matches): string {
                $url = self::normalizeUrlDashes((string)$matches[2]);
                $label = (string)$matches[1];
                $linkedPmid = self::pubmedPmidFromUrl($url);
                if ($linkedPmid !== null && preg_match('/\bPMID\s*:?\s*\d+\b/i', $label) === 1) {
                    $label = preg_replace_callback(
                        '/\b(PMID\s*:?\s*)\d+\b/i',
                        static fn(array $labelMatch): string => (string)$labelMatch[1] . $linkedPmid,
                        $label,
                        1
                    ) ?? $label;
                }
                return '[' . $label . '](' . $url . ')';
            },
            $text
        ) ?? $text;

        return preg_replace_callback(
            '/https?:\/\/[^\s<>"\']+/iu',
            static fn(array $matches): string => self::normalizeUrlDashes((string)$matches[0]),
            $text
        ) ?? $text;
    }

    /**
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,cited_pmids:array<int,string>,linked_urls:array<int,string>,unsupported_markers:array<int,string>}
     */
    public static function check(string $report, array $evidencePackage, array $evidenceWalk = [], array $reportPlan = []): array
    {
        $errors = [];
        $warnings = [];
        $unsupportedMarkers = [];

        $citedPmids = self::extractPmids($report);
        $linkedUrls = self::extractUrls($report);
        $citationIds = self::extractIds((array)($evidencePackage['citation_map'] ?? []));
        $routeIds = self::extractIds((array)($evidencePackage['route_map'] ?? []));
        $allowedPmids = self::allowedPmids($evidencePackage);
        $allowedUrls = self::allowedUrls($evidencePackage);

        foreach ($citedPmids as $pmid) {
            if (!isset($allowedPmids[$pmid])) {
                $errors[] = "PMID {$pmid} is not present in evidence_package citation_map.";
            }
        }

        foreach ($linkedUrls as $url) {
            if (!isset($allowedUrls[$url])) {
                $errors[] = "URL {$url} is not present in evidence_package citations or routes.";
            }
        }

        foreach (self::extractPmidLinkMismatches($report) as $mismatch) {
            $errors[] = "Displayed PMID {$mismatch['displayed']} does not match PubMed URL PMID {$mismatch['linked']}.";
        }

        if (self::claimsAreEmpty($evidencePackage) && self::containsStrongConclusion($report)) {
            $errors[] = 'Report uses a strong conclusion word while evidence_package claims are empty.';
        }

        foreach (self::extractMarkers($report) as $marker) {
            $type = $marker['type'];
            $id = $marker['id'];
            $exists = $type === 'citation_id' ? isset($citationIds[$id]) : isset($routeIds[$id]);
            if (!$exists) {
                $label = "{$type}: {$id}";
                $unsupportedMarkers[] = $label;
                $errors[] = "{$label} is not present in evidence_package.";
            }
        }

        foreach (self::requiredSectionTitles($reportPlan) as $title) {
            if (!self::containsNormalizedText($report, $title)) {
                $warnings[] = "Report may be missing planned section: {$title}.";
            }
        }

        foreach (self::walkClaimTexts($evidenceWalk) as $claimText) {
            if (!self::containsNormalizedText($report, $claimText)) {
                $warnings[] = "Report does not explicitly mention evidence walk claim: {$claimText}.";
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'cited_pmids' => $citedPmids,
            'linked_urls' => $linkedUrls,
            'unsupported_markers' => self::uniqueValues($unsupportedMarkers),
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function extractPmids(string $report): array
    {
        if (!preg_match_all('/\bPMID\s*:?\s*(\d+)\b/i', $report, $matches)) {
            return [];
        }
        return self::uniqueValues(array_map('strval', $matches[1]));
    }

    /**
     * @return array<int,string>
     */
    private static function extractUrls(string $report): array
    {
        $urls = [];
        $bareUrlText = preg_replace_callback(
            '/\[[^\]]+\]\(([^)\s]+)\)/',
            static function (array $matches) use (&$urls): string {
                $urls[] = self::cleanUrl((string)$matches[1]);
                return str_repeat(' ', strlen((string)$matches[0]));
            },
            $report
        ) ?? $report;
        if (preg_match_all('/https?:\/\/[^\s<>"\']+/i', $bareUrlText, $httpMatches)) {
            foreach ($httpMatches[0] as $url) {
                $urls[] = self::cleanUrl((string)$url);
            }
        }
        return self::uniqueValues(array_values(array_filter($urls, static fn (string $url): bool => $url !== '')));
    }

    /**
     * @return array<int,array{displayed:string,linked:string}>
     */
    private static function extractPmidLinkMismatches(string $report): array
    {
        if (!preg_match_all('/\[([^\]]+)\]\(([^)\s]+)\)/', $report, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $mismatches = [];
        foreach ($matches as $match) {
            if (!preg_match('/\bPMID\s*:?\s*(\d+)\b/i', (string)$match[1], $labelMatch)) {
                continue;
            }

            $url = self::cleanUrl((string)$match[2]);
            $linked = self::pubmedPmidFromUrl($url);
            if ($linked === null) {
                continue;
            }

            $displayed = (string)$labelMatch[1];
            if ($displayed !== $linked) {
                $mismatches[] = ['displayed' => $displayed, 'linked' => $linked];
            }
        }
        return $mismatches;
    }

    private static function pubmedPmidFromUrl(string $url): ?string
    {
        $parts = parse_url(self::cleanUrl($url));
        if (!is_array($parts)
            || strtolower((string)($parts['host'] ?? '')) !== 'pubmed.ncbi.nlm.nih.gov'
            || !preg_match('#^/(\d+)(?:/|$)#', (string)($parts['path'] ?? ''), $pathMatch)
        ) {
            return null;
        }
        return (string)$pathMatch[1];
    }

    /**
     * @return array<int,array{type:string,id:string}>
     */
    private static function extractMarkers(string $report): array
    {
        if (!preg_match_all('/\b(citation_id|route_id)\s*[:=]\s*([A-Za-z0-9_-]+)/i', $report, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $markers = [];
        foreach ($matches as $match) {
            $markers[] = [
                'type' => strtolower($match[1]),
                'id' => $match[2],
            ];
        }
        return $markers;
    }

    /**
     * @return array<string,true>
     */
    private static function allowedPmids(array $evidencePackage): array
    {
        $pmids = [];
        foreach ((array)($evidencePackage['citation_map'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $citation = $item['citation'] ?? null;
            if (!is_array($citation) || !isset($citation['pmid'])) {
                continue;
            }
            $pmid = trim((string)$citation['pmid']);
            if ($pmid !== '') {
                $pmids[$pmid] = true;
            }
        }
        return $pmids;
    }

    /**
     * @return array<string,true>
     */
    private static function allowedUrls(array $evidencePackage): array
    {
        $urls = [];
        foreach ((array)($evidencePackage['citation_map'] ?? []) as $item) {
            if (!is_array($item) || !is_array($item['citation'] ?? null)) {
                continue;
            }
            self::addUrl($urls, $item['citation']['url'] ?? null);
        }

        foreach ((array)($evidencePackage['route_map'] ?? []) as $item) {
            if (!is_array($item) || !is_array($item['route'] ?? null)) {
                continue;
            }
            self::addRouteUrl($urls, $item['route']['url'] ?? null);
            self::addRouteUrl($urls, $item['route']['href'] ?? null);
        }

        return $urls;
    }

    /**
     * @return array<string,true>
     */
    private static function extractIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string)($item['id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        return $ids;
    }

    private static function claimsAreEmpty(array $evidencePackage): bool
    {
        return count((array)($evidencePackage['claims'] ?? [])) === 0;
    }

    private static function containsStrongConclusion(string $report): bool
    {
        return preg_match('/\b(demonstrates|proves|establishes)\b|明确证明|证实/iu', $report) === 1;
    }

    /**
     * @return array<int,string>
     */
    private static function requiredSectionTitles(array $reportPlan): array
    {
        $titles = [];
        foreach ((array)($reportPlan['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $title = trim((string)($section['title'] ?? ''));
            if ($title !== '') {
                $titles[] = $title;
            }
        }
        return self::uniqueValues($titles);
    }

    /**
     * @return array<int,string>
     */
    private static function walkClaimTexts(array $evidenceWalk): array
    {
        $claims = [];
        foreach ((array)($evidenceWalk['claim_nodes'] ?? []) as $claimNode) {
            if (!is_array($claimNode)) {
                continue;
            }
            $text = trim((string)($claimNode['text'] ?? ''));
            if ($text !== '') {
                $claims[] = $text;
            }
        }
        return self::uniqueValues($claims);
    }

    private static function containsNormalizedText(string $haystack, string $needle): bool
    {
        $normalizedHaystack = self::normalizeForContainment($haystack);
        $normalizedNeedle = self::normalizeForContainment($needle);
        if ($normalizedNeedle === '') {
            return true;
        }
        return str_contains($normalizedHaystack, $normalizedNeedle);
    }

    private static function normalizeForContainment(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    /**
     * @param array<string,true> $urls
     */
    private static function addUrl(array &$urls, mixed $value): void
    {
        if (!is_scalar($value)) {
            return;
        }
        $url = self::cleanUrl((string)$value);
        if ($url !== '') {
            $urls[$url] = true;
        }
    }

    /**
     * @param array<string,true> $urls
     */
    private static function addRouteUrl(array &$urls, mixed $value): void
    {
        if (!is_scalar($value)) {
            return;
        }
        $url = self::cleanUrl((string)$value);
        if ($url === '') {
            return;
        }
        $urls[$url] = true;

        $fragmentPos = strpos($url, '#');
        if ($fragmentPos !== false) {
            $withoutFragment = substr($url, 0, $fragmentPos);
            if ($withoutFragment !== '') {
                $urls[$withoutFragment] = true;
            }
        }
    }

    private static function cleanUrl(string $url): string
    {
        $url = trim($url);
        $url = self::normalizeUrlDashes($url);
        $markdownFragmentPos = strpos($url, '](');
        if ($markdownFragmentPos !== false) {
            $url = substr($url, 0, $markdownFragmentPos);
        }
        return preg_replace('/[.,;:)\]`*"\'\x{201D}\x{201C}\x{2019}\x{2018}\x{3002}\x{FF0C}\x{3001}\x{FF1B}\x{FF1A}\x{FF09}\x{300B}\x{3011}\x{300D}\x{300F}]+$/u', '', $url) ?? $url;
    }

    private static function normalizeUrlDashes(string $url): string
    {
        return preg_replace('/[\x{2010}\x{2011}\x{2012}\x{2013}\x{2014}\x{2212}]/u', '-', $url) ?? $url;
    }

    /**
     * @param array<int,string> $values
     * @return array<int,string>
     */
    private static function uniqueValues(array $values): array
    {
        $seen = [];
        $unique = [];
        foreach ($values as $value) {
            if (isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $unique[] = $value;
        }
        return $unique;
    }
}
