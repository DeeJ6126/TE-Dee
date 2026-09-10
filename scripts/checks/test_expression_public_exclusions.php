<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/expression_repository.php';

function expression_exclusion_test(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['(CATTC)n', '(GAATG)n', '(cattc)N'] as $hidden) {
    expression_exclusion_test(!tekg_expression_is_public_te_name($hidden), $hidden . ' must be excluded');
}
expression_exclusion_test(tekg_expression_is_public_te_name('L1HS'), 'ordinary TE names must remain public');

$catalog = tekg_expression_fetch_catalog_items('', 180);
$catalogNames = array_map(static fn(array $item): string => strtolower((string)$item['name']), $catalog);
expression_exclusion_test(!in_array('(cattc)n', $catalogNames, true), '(CATTC)n leaked into autocomplete');
expression_exclusion_test(!in_array('(gaatg)n', $catalogNames, true), '(GAATG)n leaked into autocomplete');

$page = tekg_expression_fetch_browse_page([], 1, 100, 'default');
$browseNames = array_map(static fn(array $item): string => strtolower((string)$item['te_name']), $page['rows']);
expression_exclusion_test(!in_array('(cattc)n', $browseNames, true), '(CATTC)n leaked into browse rows');
expression_exclusion_test(!in_array('(gaatg)n', $browseNames, true), '(GAATG)n leaked into browse rows');
expression_exclusion_test(tekg_expression_resolve_te_name('(CATTC)n') === null, '(CATTC)n direct detail must be unavailable');
expression_exclusion_test(tekg_expression_resolve_te_name('(GAATG)n') === null, '(GAATG)n direct detail must be unavailable');

echo "PASS Expression public exclusions\n";
