<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/path_config.php';
require_once dirname(__DIR__, 2) . '/api/agent/orchestrator/QaCacheRetention.php';
$arguments = array_slice($argv, 1);
if (array_diff($arguments, ['--apply', '--dry-run']) !== [] || (in_array('--apply', $arguments, true) && in_array('--dry-run', $arguments, true))) {
    fwrite(STDERR, "Usage: php scripts/checks/prune_qa_cache.php [--dry-run|--apply]\n"); exit(2);
}
$report = QaCacheRetention::prune(TEKG_DATA_FS_DIR . '/cache/agent', !in_array('--apply', $arguments, true));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($report['errors'] > 0 ? 1 : 0);
