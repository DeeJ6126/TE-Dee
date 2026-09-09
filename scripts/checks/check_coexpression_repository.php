<?php
declare(strict_types=1);

$repositoryPath = dirname(__DIR__, 2) . '/api/coexpression_repository.php';
if (!is_file($repositoryPath)) {
    fwrite(STDERR, "FAIL: missing api/coexpression_repository.php\n");
    exit(1);
}

require_once $repositoryPath;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expect_repository_error(callable $callback, string $expectedCode): CoexpressionRepositoryException
{
    try {
        $callback();
    } catch (CoexpressionRepositoryException $exception) {
        check(
            $exception->errorCode() === $expectedCode,
            "Expected {$expectedCode}, received {$exception->errorCode()}"
        );
        return $exception;
    }

    throw new RuntimeException("Expected repository error {$expectedCode}");
}

try {
    $catalog = tekg_coexpression_catalog();
    check($catalog['version'] === 'v1_abs0.4_fdr0.05_res1.8', 'Unexpected analysis version');
    check($catalog['method'] === 'spearman', 'Unexpected correlation method');
    check(count($catalog['items']) === 285, 'Catalog must contain 285 unique TE items');
    check(count($catalog['contexts']) === 3, 'Catalog must expose three contexts');

    $combinationCount = array_sum(array_map(
        static fn(array $item): int => count($item['available_contexts']),
        $catalog['items']
    ));
    check($combinationCount === 849, 'Catalog must expose 849 TE/context combinations');

    $names = array_column($catalog['items'], 'te');
    $sortedNames = $names;
    usort($sortedNames, 'strnatcasecmp');
    check($names === $sortedNames, 'Catalog TE items must be sorted case-insensitively');
    check(count(array_unique(array_map('strtolower', $names))) === 285, 'Catalog contains duplicate TE names');
    $encodedCatalog = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    check(is_string($encodedCatalog), 'Catalog payload must be JSON encodable');
    check(!str_contains($encodedCatalog, 'D:/'), 'Catalog payload leaked an absolute Windows path');
    check(!str_contains($encodedCatalog, 'D:\\\\'), 'Catalog payload leaked an absolute Windows path');
    check(!str_contains($encodedCatalog, 'json_path'), 'Catalog payload leaked tier-table file metadata');

    $l1hsItem = null;
    foreach ($catalog['items'] as $item) {
        if ($item['te'] === 'L1HS') {
            $l1hsItem = $item;
            break;
        }
    }
    check(is_array($l1hsItem), 'L1HS is missing from the catalog');
    check(
        $l1hsItem['available_contexts'] === ['cancer_cell_line', 'normal_cell_line', 'normal_tissue'],
        'L1HS context availability is incorrect'
    );
    check($l1hsItem['best_tier'] === 'core_case', 'L1HS best tier should be core_case');
    check($l1hsItem['recommended_default'] === true, 'L1HS should be recommended by default');

    $network = tekg_coexpression_load_network('l1hs', 'cancer_cell_line');
    check($network['selection']['te'] === 'L1HS', 'TE lookup should be case-insensitive and canonicalized');
    check($network['selection']['context'] === 'cancer_cell_line', 'Network context mismatch');
    check($network['selection']['display_tier'] === 'core_case', 'Network tier mismatch');
    check($network['module']['id'] === 'cancer_cell_line_M002', 'L1HS module mismatch');
    check(count($network['nodes']) === 26, 'L1HS cancer network should contain 26 nodes');
    check(count($network['edges']) === 100, 'L1HS cancer network should contain 100 edges');

    $nodeIds = [];
    foreach ($network['nodes'] as $node) {
        check(is_string($node['id'] ?? null) && trim($node['id']) !== '', 'Node ID must be nonempty');
        check(!isset($nodeIds[$node['id']]), 'Node IDs must be unique');
        $nodeIds[$node['id']] = true;
    }
    foreach ($network['edges'] as $edge) {
        check(isset($nodeIds[$edge['source']]), 'Edge source must resolve to a returned node');
        check(isset($nodeIds[$edge['target']]), 'Edge target must resolve to a returned node');
        check(is_float($edge['correlation']) || is_int($edge['correlation']), 'Correlation must remain numeric');
        check($edge['correlation'] > 0, 'Display edges must have positive correlation');
        check(is_float($edge['abs_correlation']) || is_int($edge['abs_correlation']), 'Absolute correlation must remain numeric');
        check(is_float($edge['fdr']) || is_int($edge['fdr']), 'FDR must remain numeric');
    }

    $encodedNetwork = json_encode($network, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    check(is_string($encodedNetwork), 'Network payload must be JSON encodable');
    check(!str_contains($encodedNetwork, 'D:/'), 'Network payload leaked an absolute Windows path');
    check(!str_contains($encodedNetwork, 'D:\\\\'), 'Network payload leaked an absolute Windows path');
    check(!str_contains($encodedNetwork, 'json_path'), 'Network payload leaked tier-table file metadata');
    check(!str_contains($encodedNetwork, 'edge_source'), 'Network payload leaked source-file metadata');

    $missing = expect_repository_error(
        static fn() => tekg_coexpression_load_network('CR1', 'cancer_cell_line'),
        'network_unavailable'
    );
    check(
        $missing->details()['available_contexts'] === ['normal_cell_line', 'normal_tissue'],
        'Unavailable CR1 context must report its two available contexts'
    );

    expect_repository_error(
        static fn() => tekg_coexpression_load_network('definitely-not-a-te', 'normal_tissue'),
        'unknown_te'
    );
    expect_repository_error(
        static fn() => tekg_coexpression_load_network('../L1HS', 'normal_tissue'),
        'unknown_te'
    );
    expect_repository_error(
        static fn() => tekg_coexpression_load_network('L1HS/../../CR1', 'normal_tissue'),
        'unknown_te'
    );
    expect_repository_error(
        static fn() => tekg_coexpression_load_network('%2e%2e%2fL1HS', 'normal_tissue'),
        'unknown_te'
    );
    expect_repository_error(
        static fn() => tekg_coexpression_load_network('L1HS%5c..%5cCR1', 'normal_tissue'),
        'unknown_te'
    );
    expect_repository_error(
        static fn() => tekg_coexpression_load_network('L1HS', '../normal_tissue'),
        'invalid_context'
    );

    echo "PASS: co-expression repository catalog, lookup, validation, and path boundaries\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    exit(1);
}
