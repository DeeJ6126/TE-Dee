<?php
declare(strict_types=1);

/** Only QA runtime records are eligible; datasets, evaluations and snapshots are excluded. */
final class QaCacheRetention
{
    public const RETENTION_SECONDS = 7 * 86400;
    private const INTERVAL_SECONDS = 86400;

    public static function maybePrune(string $root): void
    {
        try { self::prune($root, false, time(), false); }
        catch (Throwable $_) { /* Cache maintenance must not fail a question. */ }
    }

    public static function prune(string $root, bool $dryRun = true, ?int $now = null, bool $force = true): array
    {
        $now ??= time();
        $report = ['retention_days' => 7, 'dry_run' => $dryRun, 'eligible' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0];
        if (is_link($root) || !is_dir($root)) { return $report; }
        $root = str_replace('\\', '/', (string)realpath($root));
        foreach (['.retention.lock', '.retention.json'] as $name) {
            if (is_link($root . '/' . $name)) { $report['errors']++; return $report; }
        }
        $lock = @fopen($root . '/.retention.lock', 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            $report['busy'] = true; return $report;
        }
        try {
            $previous = json_decode((string)@file_get_contents($root . '/.retention.json'), true);
            if (!$force && $now - (int)($previous['checked_at'] ?? 0) < self::INTERVAL_SECONDS) { return $report; }
            $cutoff = $now - self::RETENTION_SECONDS;
            $collections = [
                '.native_runs' => '/^[a-f0-9]{64}$/D',
                'runs' => '/^run_[a-f0-9]{16,32}$/D',
                'sessions' => '/^[a-zA-Z0-9_-]+\.json$/D',
                '.conversations' => '/^[a-f0-9]{64}\.json$/D',
                'diagnostics' => '/^answer-chain(?:-\d{4}-\d{2}-\d{2})?\.jsonl$/D',
            ];
            foreach ($collections as $collection => $pattern) {
                $directory = $root . '/' . $collection;
                if (is_link($directory) || !is_dir($directory) || str_replace('\\', '/', (string)realpath($directory)) !== $directory) { continue; }
                foreach (new DirectoryIterator($directory) as $entry) {
                    if ($entry->isDot() || preg_match($pattern, $entry->getFilename()) !== 1) { continue; }
                    $path = $entry->getPathname();
                    if ($entry->isLink()) { $report['skipped']++; continue; }
                    if (in_array($collection, ['sessions', '.conversations', 'diagnostics'], true)) {
                        if ($entry->isFile() && $entry->getMTime() < $cutoff) {
                            $report['eligible']++;
                            if (!$dryRun) { @unlink($path) ? $report['deleted']++ : $report['errors']++; }
                        }
                        continue;
                    }
                    if (!$entry->isDir()) { continue; }
                    $files = self::expiredFiles($path, $collection, $cutoff);
                    if ($files === null) { $report['skipped']++; continue; }
                    $active = self::acquireRunLock($root, $path, $collection);
                    if ($active === false) { $report['skipped']++; continue; }
                    try {
                        // Recheck after acquiring the lock, including all persisted material.
                        $files = self::expiredFiles($path, $collection, $cutoff);
                        if ($files === null) { $report['skipped']++; continue; }
                        $report['eligible']++;
                        if ($dryRun) { continue; }
                        $ok = true;
                        foreach ($files as $file) { if (!@unlink($file)) { $ok = false; } }
                    } finally {
                        if (is_resource($active)) { flock($active, LOCK_UN); fclose($active); }
                    }
                    if (!$dryRun) {
                        if (is_file($path . '/.active.lock') && !@unlink($path . '/.active.lock')) { $ok = false; }
                        if ($ok && @rmdir($path)) { $report['deleted']++; } else { $report['errors']++; }
                    }
                }
            }
            if (!$dryRun) {
                @file_put_contents($root . '/.retention.json', json_encode(['checked_at' => $now, 'report' => $report], JSON_THROW_ON_ERROR), LOCK_EX);
            }
            return $report;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function expiredFiles(string $path, string $collection, int $cutoff): ?array
    {
        $files = [];
        $pattern = $collection === '.native_runs'
            ? '/^(?:conversation|evidence_[a-f0-9]{16})\.json$/D'
            : '/^(?:state\.json|payload\.json|events\.jsonl)$/D';
        foreach (new DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) { continue; }
            if ($entry->getFilename() === '.active.lock' && !$entry->isLink() && $entry->isFile()) { continue; }
            if ($entry->isLink() || !$entry->isFile() || preg_match($pattern, $entry->getFilename()) !== 1 || $entry->getMTime() >= $cutoff) { return null; }
            $files[] = $entry->getPathname();
        }
        // An empty directory's age still matters; never prune a newly created store.
        return $files !== [] || (int)filemtime($path) < $cutoff ? $files : null;
    }

    /** Native execution holds this same lock for the entire question. */
    private static function acquireRunLock(string $root, string $path, string $collection): mixed
    {
        if ($collection === 'runs') {
            $state = json_decode((string)@file_get_contents($path . '/state.json'), true);
            if (!is_array($state)) { return false; }
            if (in_array($state['status'] ?? '', ['pending', 'running'], true)) { return false; }
            return null;
        }
        $lockPath = $path . '/.active.lock';
        if (is_link($lockPath)) { return false; }
        $lock = @fopen($lockPath, 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            return false;
        }
        return $lock;
    }
}
