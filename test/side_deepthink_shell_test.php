<?php
declare(strict_types=1);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$component = (string)file_get_contents(__DIR__ . '/../templates/components/side_deepthink.php');
$script = (string)file_get_contents(__DIR__ . '/../assets/js/components/side-deepthink.js');
$style = (string)file_get_contents(__DIR__ . '/../assets/css/components/side-deepthink.css');

assert_true(str_contains($component, 'sideDeepThinkDrag'), 'side DT drawer should expose a drag handle.');
assert_true(str_contains($component, 'sideDeepThinkResizeW'), 'side DT drawer should expose west resize handle.');
assert_true(str_contains($component, 'sideDeepThinkResizeSE'), 'side DT drawer should expose southeast resize handle.');
assert_true(str_contains($component, 'sideDeepThinkMinimize'), 'side DT drawer should expose a minimize button.');

assert_true(str_contains($script, 'startFabDrag'), 'side DT script should implement FAB drag start.');
assert_true(str_contains($script, 'startDrawerMove'), 'side DT script should implement drawer move start.');
assert_true(str_contains($script, 'startResize'), 'side DT script should implement drawer resize start.');
assert_true(str_contains($script, 'side-dt-position'), 'side DT script should persist shell position.');
assert_true(str_contains($script, 'resetFabPosition'), 'side DT script should restore the FAB resting position.');
assert_true(str_contains($script, 'setPointerCapture'), 'side DT script should use pointer capture for drag/resize.');

assert_true(str_contains($style, '.side-dt-drag'), 'side DT CSS should style the drawer drag handle.');
assert_true(str_contains($style, '.side-dt-resize'), 'side DT CSS should style resize handles.');
assert_true(str_contains($style, 'cursor: nwse-resize'), 'side DT CSS should include diagonal resize cursor.');

echo "Side Deep Think shell tests passed.\n";
