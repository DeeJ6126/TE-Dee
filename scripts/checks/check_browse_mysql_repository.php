<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/browse_repository.php';

function browse_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $catalog = tekg_browse_fetch_active_catalog();
    $items = $catalog['items'] ?? [];
    browse_check(is_array($items) && count($items) === 276, 'Active Browse catalog must contain 276 items.');
    browse_check((int)($catalog['rowCount'] ?? 0) === 276, 'Catalog metadata row count must be 276.');
    browse_check(trim((string)($catalog['version'] ?? '')) !== '', 'Catalog version is missing.');
    browse_check(trim((string)($catalog['importedAt'] ?? '')) !== '', 'Catalog import time is missing.');
    browse_check(preg_match('/^[a-f0-9]{64}$/i', (string)($catalog['sourceHash'] ?? '')) === 1, 'Catalog source hash is invalid.');

    $names = array_column($items, 'name');
    browse_check(count(array_unique(array_map('strtolower', $names))) === 276, 'Catalog TE names are not case-insensitively unique.');
    foreach (['L1HS', 'AluYa5', 'AluYb10', 'MLT1N2', 'PrimLTR79', 'SVA_A'] as $requiredName) {
        browse_check(in_array($requiredName, $names, true), "Catalog is missing {$requiredName}.");
    }

    $sorted = $names;
    usort($sorted, static fn(string $left, string $right): int => strcasecmp($left, $right));
    browse_check($names === $sorted, 'Catalog items are not sorted by TE name.');

    foreach ($items as $item) {
        browse_check(is_array($item['keywords'] ?? null), 'Catalog keywords must decode to an array.');
        browse_check(is_int($item['referenceCount'] ?? null), 'Catalog reference count must be an integer.');
        browse_check(($item['lengthBp'] ?? null) === null || is_int($item['lengthBp']), 'Catalog length must be null or an integer.');
    }

    echo "PASS: Browse MySQL repository active catalog contract\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    exit(1);
}
