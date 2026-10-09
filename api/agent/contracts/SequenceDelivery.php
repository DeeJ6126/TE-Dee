<?php
declare(strict_types=1);

/** Deliver retrieved bases verbatim, after successful model writing. */
final class SequenceDelivery
{
    public static function requested(string $question): bool
    {
        return preg_match('/完整(?:的)?(?:共识|核苷酸|DNA)?序列|全部序列|(?:full(?:[- ]length)?|complete|entire)\s+(?:consensus\s+|nucleotide\s+|DNA\s+)?sequence|\bFASTA\b/iu', $question) === 1;
    }

    public static function records(array $result): array
    {
        if (!in_array((string)($result['status'] ?? ''), ['ok', 'partial'], true)) {
            return [];
        }
        $records = [];
        foreach (array_slice((array)($result['results']['matched_records'] ?? []), 0, 3) as $match) {
            if (!is_array($match)) {
                continue;
            }
            $entry = (array)($match['entry'] ?? []);
            $sequence = strtoupper(preg_replace('/\s+/', '', (string)($entry['sequence'] ?? '')) ?? '');
            if ($sequence === '' || preg_match('/\A[ACGTRYSWKMBDHVN]+\z/', $sequence) !== 1) {
                continue;
            }
            $declared = (int)($entry['length'] ?? $match['length'] ?? strlen($sequence));
            $records[] = [
                'label' => (string)($entry['name'] ?? $match['repbase_name'] ?? ''),
                'entity' => (string)($match['entity_label'] ?? ''),
                'alias_mode' => (string)($match['alias_mode'] ?? 'strict'),
                'length' => strlen($sequence),
                'declared_length' => $declared,
                'complete' => $declared === strlen($sequence),
                'sha256' => hash('sha256', $sequence),
                'sequence' => $sequence,
            ];
        }
        return $records;
    }

    public static function writingContext(array $context, string $question, array $result): array
    {
        $context['sequence_delivery'] = ['requested' => self::requested($question)];
        if (!self::requested($question)) {
            $context['presentation_guidance'][] = 'No FASTA will be attached to this answer. Summarize retrieved sequence length and source only; do not say a full sequence or file is provided alongside the report.';
            return $context;
        }
        $context['sequence_records'] = array_map(static function (array $record): array {
            unset($record['sequence']);
            return $record;
        }, self::records($result));
        $context['presentation_guidance'][] = 'Explain the retrieved sequence record and its source. The application will append the exact stored nucleotide sequence as FASTA after writing succeeds. Do not generate bases, sequence excerpts, or FASTA yourself. Do not claim a complete record exists unless sequence_records.complete is true. A broad alias match is not an exact subfamily sequence.';
        return $context;
    }

    public static function append(string $answer, string $question, string $language, array $result): string
    {
        if ($answer === '' || !self::requested($question)) {
            return $answer;
        }
        $records = self::records($result);
        // Replace model-produced nucleotide blocks with authoritative retrieved bases.
        $answer = preg_replace_callback('/```([^\n]*)\n(.*?)```/s', static function (array $m): string {
            if (preg_match('/^\s*fasta\b/i', $m[1]) === 1) {
                return '';
            }
            $bases = preg_replace('/^>[^\n]*$/m', '', $m[2]);
            $bases = preg_replace('/\s+/', '', (string)$bases);
            return $bases !== '' && preg_match('/\A[ACGTRYSWKMBDHVN.]+\z/i', $bases) === 1 ? '' : $m[0];
        }, $answer) ?? $answer;
        $chinese = in_array($language, ['chinese', 'zh'], true);
        foreach ($records as $record) {
            $label = preg_replace('/[^\pL\pN_.()-]/u', '_', $record['label']) ?: 'TE';
            if (!$record['complete']) {
                $answer .= $chinese
                    ? "\n\n{$label} 的已存序列长度与记录声明长度不一致，暂不作为完整序列输出。"
                    : "\n\nThe stored sequence for {$label} does not match its declared length; it is not delivered as a complete sequence.";
                continue;
            }
            $note = $chinese ? '来源：Repbase 对齐序列库；记录共识序列，不是指定基因组位点的序列。' : 'Source: Repbase-aligned sequence library. This is the record consensus, not the sequence of a specified genomic locus.';
            if ($record['alias_mode'] === 'broad') {
                $note .= $chinese ? ' 此记录通过较宽泛别名匹配，不应视为请求亚家族的精确序列。' : ' This record is a broad alias match, not an exact sequence match for the requested subfamily.';
            }
            $answer .= "\n\n### {$label} — {$record['length']} bp\n\n{$note}\n\n```fasta\n>{$label} length={$record['length']} source=Repbase-aligned\n" . wordwrap($record['sequence'], 80, "\n", true) . "\n```";
        }
        if ($records === []) {
            $answer .= $chinese ? "\n\n本次未取得可验证的完整序列，无法提供 FASTA。" : "\n\nNo verifiable complete sequence was retrieved, so FASTA is unavailable for this request.";
        }
        return trim($answer);
    }
}
