<?php
declare(strict_types=1);

final class TekgAgentNeo4jClient
{
    public function __construct(private readonly array $config)
    {
    }

    public function runReadOnlyQuery(string $cypher, array $params = [], int $defaultLimit = 50): array
    {
        $prepared = $this->prepareReadOnlyCypher($cypher, $defaultLimit);
        return [
            'generated_cypher' => $prepared['cypher'],
            'validated_features' => $prepared['validated_features'],
            'rows' => $this->run($prepared['cypher'], $params),
        ];
    }

    public function prepareReadOnlyCypher(string $cypher, int $defaultLimit = 50): array
    {
        $trimmed = trim($cypher);
        if ($trimmed === '') {
            throw new RuntimeException('Cypher query is empty.');
        }

        $normalized = preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;
        $upper = strtoupper($normalized);
        $blockedPatterns = [
            '/\bCREATE\b/',
            '/\bMERGE\b/',
            '/\bSET\b/',
            '/\bDELETE\b/',
            '/\bDETACH\b/',
            '/\bDROP\b/',
            '/\bREMOVE\b/',
            '/\bLOAD\s+CSV\b/',
            '/\bCALL\s+DBMS\b/',
            '/\bCALL\s+APOC\b/',
            '/\bALTER\b/',
            '/\bINDEX\b/',
            '/\bCONSTRAINT\b/',
        ];

        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $upper) === 1) {
                throw new RuntimeException('Cypher Explorer rejected a non-read-only query.');
            }
        }

        if (preg_match('/\bMATCH\b/i', $normalized) !== 1 && preg_match('/\bOPTIONAL\s+MATCH\b/i', $normalized) !== 1) {
            throw new RuntimeException('Cypher Explorer requires a MATCH or OPTIONAL MATCH clause.');
        }
        if (preg_match('/\bRETURN\b/i', $normalized) !== 1) {
            throw new RuntimeException('Cypher Explorer requires a RETURN clause.');
        }

        $validatedFeatures = [];
        foreach ([
            'OPTIONAL MATCH' => '/\bOPTIONAL\s+MATCH\b/i',
            'WHERE' => '/\bWHERE\b/i',
            'WITH' => '/\bWITH\b/i',
            'ORDER BY' => '/\bORDER\s+BY\b/i',
            'DISTINCT' => '/\bDISTINCT\b/i',
            'AGGREGATION' => '/\b(COUNT|COLLECT|AVG|SUM|MAX|MIN)\s*\(/i',
        ] as $feature => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                $validatedFeatures[] = $feature;
            }
        }

        if (preg_match('/\bLIMIT\b/i', $normalized) !== 1) {
            $normalized .= ' LIMIT ' . max(1, $defaultLimit);
            $validatedFeatures[] = 'AUTO_LIMIT';
        }

        return [
            'cypher' => $normalized,
            'validated_features' => array_values(array_unique($validatedFeatures)),
        ];
    }

    public function run(string $cypher, array $params = []): array
    {
        $url = (string)($this->config['neo4j_url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('Neo4j URL is not configured for the academic agent.');
        }

        $payload = json_encode(['statements' => [[
            'statement' => $cypher,
            'parameters' => (object)$params,
            'resultDataContents' => ['row'],
        ]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $user = (string)($this->config['neo4j_user'] ?? '');
        $password = (string)($this->config['neo4j_password'] ?? '');
        if ($user !== '' || $password !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $password);
        }

        $response = tekg_agent_http_request($url, 'POST', $headers, $payload, 45, (bool)($this->config['ssl_verify'] ?? false));
        $decoded = json_decode((string)$response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Neo4j returned invalid JSON.');
        }
        if ((int)$response['status'] >= 400) {
            throw new RuntimeException('Neo4j returned HTTP ' . (int)$response['status']);
        }
        if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
            $message = (string)($decoded['errors'][0]['message'] ?? 'Unknown Neo4j error');
            throw new RuntimeException('Neo4j error: ' . $message);
        }
        $results = $decoded['results'][0] ?? [];
        $columns = is_array($results['columns'] ?? null) ? $results['columns'] : [];
        $rows = [];
        foreach (($results['data'] ?? []) as $entry) {
            $rowValues = is_array($entry['row'] ?? null) ? $entry['row'] : [];
            $assoc = [];
            foreach ($columns as $index => $column) {
                $assoc[(string)$column] = $rowValues[$index] ?? null;
            }
            $rows[] = $assoc;
        }
        return $rows;
    }
}
