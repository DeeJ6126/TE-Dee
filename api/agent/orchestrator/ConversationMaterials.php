<?php
declare(strict_types=1);

/** Classifies text work without promoting prior model prose to scientific evidence. */
final class ConversationMaterials
{
    public static function isTextTask(string $question): bool
    {
        $task = self::instructions($question);
        $instructions = preg_replace('/\b(?:do not|don\x27t|without|no need to)\s+(?:search|find|query|look\s*up|fetch|retrieve|verify)\b|(?:不要|无需|不必|不需要|不用)(?:查询|查找|检索|查证|核实)/iu', '', $task) ?? $task;
        if (preg_match('/\b(?:fact[- ]?check|verify|independently|find|query|look\s*up|fetch|search|retrieve)\b|查证|核实|查询|查找|检索|独立验证/iu', $instructions)) { return false; }
        if (preg_match('/(?:\b(?:and|then|also)\b|[;；\n]|然后|另外|同时|并且).{0,50}(?:\b(?:tell|show|provide|give|explain|what|where|which|why|how)\b|告诉|给出|解释|什么|哪些|为什么).{0,160}(?:\beQTL\b|\b(?:sequence|expression|mechanism|gene|variant|locus|taxonomy|length)\b|序列|表达|机制|基因|变异|位点|分类|长度)/isu', $instructions)) { return false; }
        $action = preg_match('/\b(?:read|remember|summari[sz]e|rewrite|rephrase|translate|shorten|condense|extract)\b|阅读|读完|记住|保存|总结|概括|改写|润色|翻译|缩短|精简|提取/iu', $task);
        if ($action !== 1 && preg_match('/\banaly[sz]e\b|分析/iu', $task)) {
            // A biological "analyze it" follow-up still requires new scientific sources.
            return preg_match('/\b(?:text|material|paragraph|passage|answer|pasted)\b|材料|文本|段落|回答|这段/iu', $task) === 1;
        }
        $anchor = preg_match('/\b(?:text|material|paragraph|passage|answer|above|previous|following|pasted|this|that|it)\b|材料|文本|段落|回答|上述|上面|前面|刚才|这段|以下|下面/iu', $task);
        return $action === 1 && $anchor === 1;
    }

    private static function instructions(string $question): string
    {
        // A pasted article may contain search/find/verify as source wording.
        if (preg_match('/(?:\b(?:following|below|pasted|provided)\b|以下|下面|这段|如下|材料|文本|段落)[^\n:：]{0,512}(?:[:：]|\n)/iu', $question, $match, PREG_OFFSET_CAPTURE)) {
            return substr($question, 0, $match[0][1] + strlen($match[0][0]));
        }
        return $question;
    }

    public static function sourceText(array $history, string $question): string
    {
        return implode("\n\n", array_merge(array_column((array)($history['messages'] ?? []), 'content'), [$question]));
    }

    /** Source identities may be retained for text transformations, never for a fresh scientific assertion. */
    public static function references(array $history, string $question): array
    {
        $references = [];
        foreach ((array)($history['evidence_references'] ?? []) as $reference) {
            foreach ((array)($reference['pmids'] ?? []) as $pmid) {
                if (is_string($pmid) && preg_match('/^\d{1,12}$/D', $pmid)) {
                    $references['pmid:' . $pmid] = ['pmid' => $pmid, 'url' => 'https://pubmed.ncbi.nlm.nih.gov/' . $pmid . '/', 'material_level' => 'conversation_reference'];
                }
            }
            foreach ((array)($reference['urls'] ?? []) as $url) {
                if (is_string($url)) { $references['url:' . $url] = ['url' => $url, 'material_level' => 'conversation_reference']; }
            }
        }
        // Only user-origin source identifiers are admitted from unverified text.
        $userText = implode("\n", array_merge(array_column(array_filter((array)($history['messages'] ?? []), static fn(array $m): bool => $m['role'] === 'user'), 'content'), [$question]));
        preg_match_all('/\bPMID\s*:?\s*(\d{1,12})\b/iu', $userText, $pmids);
        foreach (array_slice(array_unique($pmids[1]), 0, 24) as $pmid) {
            $references['pmid:' . $pmid] = ['pmid' => $pmid, 'url' => 'https://pubmed.ncbi.nlm.nih.gov/' . $pmid . '/', 'material_level' => 'user_supplied_reference'];
        }
        preg_match_all('/https?:\/\/[^\s<>"\x27)\]]+/iu', $userText, $urls);
        foreach (array_slice(array_unique($urls[0]), 0, 24) as $url) {
            $url = rtrim($url, '.,;'); $parsed = parse_url($url);
            if (is_array($parsed) && !isset($parsed['user']) && !isset($parsed['pass']) && strlen($url) <= 2048) {
                $references['url:' . $url] = ['url' => $url, 'material_level' => 'user_supplied_reference'];
            }
        }
        return array_values($references);
    }
}
