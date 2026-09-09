<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker must be executed from CLI.\n");
    exit(1);
}

$runId = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--run-id=')) {
        $runId = trim(substr($argument, 9));
        break;
    }
}
if ($runId === '' && isset($argv[1])) {
    $runId = trim((string)$argv[1]);
}
if ($runId === '') {
    fwrite(STDERR, "run_id is required.\n");
    exit(1);
}

require_once __DIR__ . '/agent_run_execute.php';
exit(tekg_agent_execute_run($runId));
