<?php
declare(strict_types=1);

require_once __DIR__ . '/agent/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    tekg_agent_json_response(200, ['ok' => true]);
    exit;
}

$path = __DIR__ . '/agent/config/agent_workflow_lab.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    tekg_agent_json_response(200, [
        'ok' => true,
        'path' => $path,
        'node_contracts' => tekg_agent_node_contracts(),
        'spec' => is_array($decoded) ? $decoded : [],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tekg_agent_json_response(405, ['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid JSON body.');
    }
    $spec = is_array($payload['spec'] ?? null) ? $payload['spec'] : null;
    if (!is_array($spec)) {
        throw new InvalidArgumentException('Missing spec object.');
    }
    $nodes = is_array($spec['nodes'] ?? null) ? $spec['nodes'] : [];
    $edges = is_array($spec['edges'] ?? null) ? $spec['edges'] : [];
    if ($nodes === []) {
        throw new InvalidArgumentException('Workflow spec requires at least one node.');
    }

    $nodeIds = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            throw new InvalidArgumentException('Each node must be a JSON object.');
        }
        $id = (int)($node['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('Each node requires a positive integer id.');
        }
        if (isset($nodeIds[$id])) {
            throw new InvalidArgumentException('Duplicate node id: ' . $id);
        }
        $nodeIds[$id] = true;
    }

    foreach ($edges as $edge) {
        if (!is_array($edge)) {
            throw new InvalidArgumentException('Each edge must be a JSON object.');
        }
        $from = (int)($edge['from'] ?? 0);
        $to = (int)($edge['to'] ?? 0);
        if (!isset($nodeIds[$from]) || !isset($nodeIds[$to])) {
            throw new InvalidArgumentException('Edge references unknown node ids: ' . $from . ' -> ' . $to);
        }
    }

    file_put_contents($path, json_encode(tekg_agent_json_safe($spec), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    tekg_agent_json_response(200, ['ok' => true, 'path' => $path]);
} catch (Throwable $error) {
    tekg_agent_json_response(400, ['ok' => false, 'error' => $error->getMessage()]);
}
