<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/contracts/PluginResultProjection.php';
require_once __DIR__ . '/QaCacheRetention.php';

/** Run-owned original tool material. Models receive references, not lossy replacements. */
final class TekgAgentQaEvidenceStore
{
    private array $records = [];
    private array $calls = [];
    private readonly string $directory;
    private mixed $activeLock = null;

    public function __construct(string $requestId)
    {
        $this->directory = tekg_agent_ensure_dir(TEKG_DATA_FS_DIR . '/cache/agent/.native_runs/' . hash('sha256', $requestId));
        $lockPath = $this->directory . '/.active.lock';
        if (is_link($lockPath)) { throw new RuntimeException('Invalid evidence lock.'); }
        $this->activeLock = @fopen($lockPath, 'c+');
        if (!is_resource($this->activeLock) || !flock($this->activeLock, LOCK_SH | LOCK_NB)) {
            throw new RuntimeException('Evidence store is being maintained.');
        }
    }

    public function __destruct()
    {
        if (is_resource($this->activeLock)) { flock($this->activeLock, LOCK_UN); fclose($this->activeLock); }
    }

    public function add(string $tool, array $arguments, array $result, string $callId): string
    {
        if (($result['plugin_name'] ?? '') === 'Graph Plugin') {
            $mark = static function (array $citation): array {
                if (trim((string)($citation['abstract_text'] ?? '')) === '') {
                    $citation['material_level'] = trim((string)($citation['abstract_summary'] ?? '')) !== '' ? 'graph_summary' : 'metadata';
                    $citation['abstract_status'] = 'not_fetched';
                }
                return $citation;
            };
            $result['citations'] = array_map($mark, (array)($result['citations'] ?? []));
            $result['display_details']['citations'] = $result['citations'];
            foreach ($result['evidence_items'] as &$item) { $item['citations'] = array_map($mark, (array)($item['citations'] ?? [])); }
            unset($item);
        }
        $id = 'evidence_' . bin2hex(random_bytes(8));
        $record = ['artifact_id' => $id, 'tool' => $tool, 'arguments' => $arguments, 'call_id' => $callId, 'result' => $result];
        $this->records[$id] = $record;
        $this->calls[] = $result;
        $this->write($id . '.json', $record);
        return $id;
    }

    public function summary(string $id): array
    {
        $record = $this->record($id);
        $result = $record['result'];
        $citations = [];
        foreach (array_slice((array)($result['citations'] ?? []), 0, 24) as $citation) {
            $abstract = (string)($citation['abstract_text'] ?? $citation['abstract_summary'] ?? '');
            $citations[] = array_filter([
                'pmid' => (string)($citation['pmid'] ?? ''), 'title' => (string)($citation['title'] ?? ''),
                'url' => (string)($citation['url'] ?? ''), 'entity_scopes' => (array)($citation['entity_scopes'] ?? []),
                'abstract_available' => trim((string)($citation['abstract_text'] ?? '')) !== '' || (($citation['material_level'] ?? '') === 'abstract' && trim($abstract) !== ''),
                'material_level' => (string)($citation['material_level'] ?? 'metadata'), 'abstract_characters' => tekg_agent_strlen($abstract),
            ], static fn(mixed $v): bool => $v !== '' && $v !== []);
        }
        $preview = array_slice((array)($result['display_details']['preview_items'] ?? []), 0, 6);
        foreach ($preview as &$item) {
            if (isset($item['body'])) {
                $item['body'] = tekg_agent_substr((string)$item['body'], 0, 500);
            }
        }
        unset($item);
        return [
            'artifact_id' => $id, 'tool' => $record['tool'], 'arguments' => $record['arguments'],
            'status' => (string)($result['status'] ?? 'error'), 'summary' => (string)($result['display_summary'] ?? ''),
            'result_counts' => (array)($result['result_counts'] ?? []), 'preview' => $preview, 'citations' => $citations,
            'query_coverage' => (array)($result['results']['query_coverage'] ?? []),
            'errors' => (array)($result['errors'] ?? []),
            'read_instruction' => 'Use read_evidence with this artifact_id to inspect complete abstracts, original rows, or records. This preview is not the original result and absence from a preview is not absence from the source.',
        ];
    }

    public function validateReadArguments(array $arguments): array
    {
        if (array_diff(array_keys($arguments), ['artifact_id','section','pmids','offset','limit']) !== []) {
            throw new InvalidArgumentException('Unknown read_evidence argument.');
        }
        if (!is_string($arguments['artifact_id'] ?? null)) { throw new InvalidArgumentException('Evidence identifier must be a string.'); }
        $this->record($arguments['artifact_id']);
        $section = $arguments['section'] ?? 'results';
        if (!is_string($section) || !in_array($section, ['citations','results','evidence_items','sequence'], true)) { throw new InvalidArgumentException('Unknown evidence section.'); }
        $offset = $arguments['offset'] ?? 0; $limit = $arguments['limit'] ?? 12; $pmids = $arguments['pmids'] ?? [];
        if (!is_int($offset) || $offset < 0 || $offset > 300 || !is_int($limit) || $limit < 1 || $limit > 24) { throw new InvalidArgumentException('Evidence offset/limit is outside its bounds.'); }
        if (!is_array($pmids) || !array_is_list($pmids) || count($pmids)>24 || array_filter($pmids, static fn($id): bool => !is_string($id) || preg_match('/^\d{1,12}$/D',$id)!==1)) { throw new InvalidArgumentException('PMIDs must be a bounded list of supplied numeric identifiers.'); }
        if ($section !== 'citations' && $pmids !== []) { throw new InvalidArgumentException('PMID selection applies only to citations.'); }
        $pmids = array_values(array_unique($pmids)); sort($pmids);
        return ['artifact_id'=>$arguments['artifact_id'],'section'=>$section,'pmids'=>$pmids,'offset'=>$offset,'limit'=>$limit];
    }

    public function read(array $arguments): array
    {
        $arguments = $this->validateReadArguments($arguments);
        $record = $this->record($arguments['artifact_id']);
        $section = $arguments['section']; $offset = $arguments['offset']; $limit = $arguments['limit'];
        $result = $record['result'];
        if ($section === 'citations') {
            $material = array_values((array)($result['citations'] ?? []));
            $pmids = $arguments['pmids'] ?? [];
            if (!is_array($pmids) || count($pmids) > 24 || array_filter($pmids, static fn($id): bool => !is_string($id) || preg_match('/^\d{1,12}$/D', $id) !== 1)) {
                throw new InvalidArgumentException('PMIDs must be a bounded list of supplied numeric identifiers.');
            }
            if ($pmids !== []) {
                $material = array_values(array_filter($material, static fn(array $c): bool => in_array((string)($c['pmid'] ?? ''), $pmids, true)));
            }
        } elseif ($section === 'sequence') {
            $material = array_values((array)($result['display_details']['full_sequences'] ?? []));
        } elseif ($section === 'evidence_items') {
            $material = array_values((array)($result['evidence_items'] ?? []));
        } else {
            $material = (array)($result['results'] ?? []);
            if (!array_is_list($material)) {
                foreach ($material as &$value) {
                    if (is_array($value) && array_is_list($value)) {
                        $value = array_slice($value, $offset, $limit);
                    }
                }
                unset($value);
                return ['artifact_id' => $record['artifact_id'], 'source_tool' => $record['tool'], 'section' => $section, 'material' => $material, 'offset' => $offset, 'limit' => $limit];
            }
        }
        return ['artifact_id' => $record['artifact_id'], 'source_tool' => $record['tool'], 'section' => $section,
            'total_records' => count($material), 'offset' => $offset, 'limit' => $limit, 'material' => array_slice($material, $offset, $limit)];
    }

    public function allResults(): array { return $this->calls; }

    public function mergedPlugins(): array
    {
        $merged = [];
        foreach ($this->calls as $result) {
            $name = (string)$result['plugin_name'];
            if (!isset($merged[$name])) { $merged[$name] = $result; continue; }
            $previous = $merged[$name];
            $result['results'] = $this->mergeMaterial((array)$previous['results'], (array)$result['results']);
            $result['evidence_items'] = array_merge((array)$previous['evidence_items'], (array)$result['evidence_items']);
            $result['citations'] = tekg_agent_dedupe_citations(array_merge((array)$previous['citations'], (array)$result['citations']));
            $result['errors'] = array_values(array_unique(array_merge((array)$previous['errors'], (array)$result['errors'])));
            $usable = array_intersect([$previous['status'], $result['status']], ['ok', 'partial']) !== [];
            $result['status'] = $usable ? ($result['errors'] !== [] || $previous['status'] !== 'ok' || $result['status'] !== 'ok' ? 'partial' : 'ok') : $result['status'];
            $result['display_details']['evidence_items'] = $result['evidence_items'];
            $result['display_details']['citations'] = $result['citations'];
            $merged[$name] = $result;
        }
        return $merged;
    }

    public function saveConversation(array $messages, array $state): void
    {
        // The directory is denied by the site's existing dot-path rule. No configuration or Authorization is stored.
        $this->write('conversation.json', ['messages' => $messages, 'state' => $state, 'updated_at' => gmdate('c')]);
    }

    private function record(string $id): array
    {
        if (preg_match('/^evidence_[a-f0-9]{16}$/D', $id) !== 1 || !isset($this->records[$id])) {
            throw new InvalidArgumentException('Evidence identifier does not belong to this run.');
        }
        return $this->records[$id];
    }

    private function write(string $file, array $data): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 6000000) { throw new RuntimeException('Evidence storage budget exceeded.'); }
        if (file_put_contents($this->directory . '/' . $file, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Evidence persistence is unavailable.');
        }
    }

    private function mergeMaterial(array $left, array $right): array
    {
        if (array_is_list($left) && array_is_list($right)) {
            $out = []; $seen = [];
            foreach (array_merge($left, $right) as $item) {
                $key = hash('sha256', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if (!isset($seen[$key])) { $seen[$key] = true; $out[] = $item; }
            }
            return $out;
        }
        foreach ($right as $key => $value) {
            $left[$key] = is_array($value) && is_array($left[$key] ?? null) ? $this->mergeMaterial($left[$key], $value) : $value;
        }
        return $left;
    }
}
