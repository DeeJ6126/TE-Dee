<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function fail_test(string $message): void
{
    fwrite(STDERR, "Assertion failed: {$message}\n");
    exit(1);
}

function assert_true_clean(bool $condition, string $message): void
{
    if (!$condition) {
        fail_test($message);
    }
}

$registryPath = $root . '/api/agent/plugin_registry.php';
assert_true_clean(is_file($registryPath), 'plugin_registry.php should exist');

require_once $root . '/path_config.php';
require_once $root . '/site_i18n.php';
require_once $root . '/api/agent/bootstrap.php';
require_once $registryPath;

assert_true_clean(function_exists('tekg_agent_require_plugin_files'), 'plugin registry should expose tekg_agent_require_plugin_files');
assert_true_clean(function_exists('tekg_agent_create_default_plugins'), 'plugin registry should expose tekg_agent_create_default_plugins');

tekg_agent_require_plugin_files();
assert_true_clean(class_exists('TekgAgentSiteNavigatorPlugin'), 'Site Navigator plugin class should be loadable through registry');
assert_true_clean(class_exists('TekgAgentGraphPlugin'), 'Graph plugin class should be loadable through registry');

$mapPath = $root . '/api/agent/config/site_navigation_map.php';
assert_true_clean(is_file($mapPath), 'site_navigation_map.php should exist');
$map = require $mapPath;
assert_true_clean(is_array($map), 'site navigation map should return an array');
assert_true_clean(($map['routes']['genome_distribution']['fragment'] ?? '') === 'search-karyotype-panel', 'genome distribution route should live in config map');
assert_true_clean(in_array('Genome Annotation Distribution', $map['capability_keywords']['genome_distribution'] ?? [], true), 'genome distribution keywords should live in config map');

assert_true_clean(!is_file($root . '/api/qa.php'), 'api/qa.php should be removed after code references are gone');

$scanRoots = [
    $root . '/assets',
    $root . '/api',
    $root . '/templates',
    $root,
];
$codeReferences = [];
foreach ($scanRoots as $scanRoot) {
    $directory = new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $entry): bool {
        return !$entry->isDir() || !in_array($entry->getFilename(), ['.git', '.pytest_cache'], true);
    });
    $iterator = new RecursiveIteratorIterator($filter);
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (!preg_match('/\.(php|js|html)$/', $path)) {
            continue;
        }
        if (str_contains($path, '/docs/') || str_contains($path, '/test/')) {
            continue;
        }
        $content = (string)file_get_contents($file->getPathname());
        if (preg_match('/api\/qa\.php|apiUrl\([\'"]qa\.php[\'"]\)|[^a-zA-Z0-9_]qa\.php/', $content)) {
            $codeReferences[] = $path;
        }
    }
}
assert_true_clean($codeReferences === [], 'frontend/runtime code should not reference api/qa.php: ' . implode(', ', $codeReferences));

$sideCss = (string)file_get_contents($root . '/assets/css/components/side-deepthink.css');
assert_true_clean(str_contains($sideCss, '.side-dt-fab'), 'side DT CSS should define the wake button');
assert_true_clean(str_contains($sideCss, 'width: 84px') && str_contains($sideCss, 'height: 84px'), 'side DT wake button should be circular like preview');

echo "Agent infrastructure cleanup tests passed.\n";
