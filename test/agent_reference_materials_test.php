<?php
declare(strict_types=1);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__) . '/reference/agent_theory_high_quality';
$papers = [
    'papers/react_2210.03629.pdf',
    'papers/plan_and_solve_2305.04091.pdf',
    'papers/reflexion_2303.11366.pdf',
    'papers/toolformer_2302.04761.pdf',
    'papers/rag_2005.11401.pdf',
    'papers/graphrag_2404.16130.pdf',
    'papers/auto_cypher_2412.12612.pdf',
    'papers/ragas_2309.15217.pdf',
    'papers/agentbench_2308.03688.pdf',
    'papers/gaia_2311.12983.pdf',
];

foreach ($papers as $relativePath) {
    $path = $root . '/' . $relativePath;
    assert_true(is_file($path), "{$relativePath} should be downloaded.");
    assert_true(filesize($path) > 100_000, "{$relativePath} should look like a real paper PDF.");
    $handle = fopen($path, 'rb');
    $prefix = is_resource($handle) ? fread($handle, 4) : '';
    if (is_resource($handle)) {
        fclose($handle);
    }
    assert_true($prefix === '%PDF', "{$relativePath} should be a PDF file.");
}

foreach ([
    'docs_index/workflow_engineering.md',
    'docs_index/plugin_contracts_observability.md',
    'docs_index/evaluation.md',
    'docs_index/kg_qa_text2cypher.md',
] as $relativePath) {
    $path = $root . '/' . $relativePath;
    assert_true(is_file($path), "{$relativePath} should exist.");
    assert_true(filesize($path) > 500, "{$relativePath} should contain useful local notes.");
}

$manifest = $root . '/materials_manifest.md';
assert_true(is_file($manifest), 'materials_manifest.md should exist.');
assert_true(str_contains((string)file_get_contents($manifest), 'Downloaded papers'), 'manifest should distinguish downloaded papers.');

echo "Agent reference materials tests passed.\n";
