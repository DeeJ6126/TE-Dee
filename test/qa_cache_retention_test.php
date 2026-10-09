<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/agent/orchestrator/QaCacheRetention.php';
$root = str_replace('\\', '/', sys_get_temp_dir()) . '/tedee-retention-' . bin2hex(random_bytes(8));
mkdir($root);
$now = time(); $old = $now - 8 * 86400;
function retention_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function retention_file(string $path, string $content, int $mtime): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0777, true); }
    file_put_contents($path, $content); touch($path, $mtime);
}
$native = $root . '/.native_runs/' . str_repeat('a', 64);
$active = $root . '/.native_runs/' . str_repeat('b', 64);
$recent = $root . '/.native_runs/' . str_repeat('c', 64);
$unexpected = $root . '/.native_runs/' . str_repeat('d', 64);
$done = $root . '/runs/run_' . str_repeat('1', 16);
$running = $root . '/runs/run_' . str_repeat('2', 16);
retention_file($native . '/conversation.json', '{}', $old);
retention_file($active . '/conversation.json', '{}', $old);
retention_file($recent . '/conversation.json', '{}', $now);
retention_file($unexpected . '/conversation.json', '{}', $old);
retention_file($unexpected . '/do-not-delete.txt', 'unrecognized', $old);
retention_file($done . '/state.json', '{"status":"completed"}', $old);
retention_file($done . '/events.jsonl', '{}', $old);
retention_file($running . '/state.json', '{"status":"running"}', $old);
retention_file($root . '/sessions/old.json', '{}', $old);
retention_file($root . '/sessions/fresh.json', '{}', $now);
retention_file($root . '/.conversations/' . str_repeat('a', 64) . '.json', '{}', $old);
retention_file($root . '/.conversations/' . str_repeat('b', 64) . '.json', '{}', $now);
retention_file($root . '/diagnostics/answer-chain-2020-01-01.jsonl', '{}', $old);
retention_file($root . '/diagnostics/answer-chain.jsonl', '{}', $old);
retention_file($root . '/pubmed/material.json', '{}', $old);
retention_file($root . '/maintenance_snapshots/snapshot.json', '{}', $old);
retention_file($root . '/native_loop_quality_20261008/result.json', '{}', $old);
$held = fopen($active . '/.active.lock', 'c+'); flock($held, LOCK_SH);
try {
    $preview = QaCacheRetention::prune($root, true, $now);
    retention_assert($preview['eligible'] === 6 && $preview['deleted'] === 0, 'Dry run identifies only old recognized records.');
    retention_assert(is_file($native . '/conversation.json') && is_dir($done), 'Dry run keeps material.');
    $report = QaCacheRetention::prune($root, false, $now);
    retention_assert($report['deleted'] === 6 && $report['errors'] === 0, 'Only eligible records are removed.');
    retention_assert(!is_dir($native) && !is_dir($done), 'Expired native and completed async records are removed.');
    foreach ([$active, $recent, $unexpected, $running] as $path) { retention_assert(is_dir($path), 'Active, recent and unrecognized directories survive.'); }
    foreach (['sessions/fresh.json','pubmed/material.json','maintenance_snapshots/snapshot.json','native_loop_quality_20261008/result.json'] as $file) {
        retention_assert(is_file($root . '/' . $file), 'Non-runtime and recent files survive.');
    }
    retention_file($root . '/sessions/another-old.json', '{}', $old);
    QaCacheRetention::maybePrune($root);
    retention_assert(is_file($root . '/sessions/another-old.json'), 'Automatic maintenance is throttled.');
    $busy = fopen($root . '/.retention.lock', 'c+'); flock($busy, LOCK_EX);
    retention_assert(QaCacheRetention::prune($root)['busy'] ?? false, 'Concurrent cleanup exits without waiting.');
    flock($busy, LOCK_UN); fclose($busy);
    if (PHP_OS_FAMILY !== 'Windows') {
        $outside = $root . '/pubmed';
        symlink($outside, $root . '/.native_runs/' . str_repeat('e', 64));
        symlink($outside . '/material.json', $root . '/sessions/link.json');
        $linked = $root . '/.native_runs/' . str_repeat('f', 64);
        mkdir($linked); symlink($outside . '/material.json', $linked . '/conversation.json');
        QaCacheRetention::prune($root, false, $now);
        retention_assert(is_file($outside . '/material.json') && is_link($linked . '/conversation.json'), 'Linked directories and files are not followed.');
    }
    echo "qa_cache_retention_test: PASS\n";
} finally {
    flock($held, LOCK_UN); fclose($held);
    // The fixture root is a verified, task-owned absolute temporary directory.
    retention_assert(str_starts_with($root, str_replace('\\', '/', sys_get_temp_dir()) . '/tedee-retention-'), 'Fixture cleanup remains in its temporary root.');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
