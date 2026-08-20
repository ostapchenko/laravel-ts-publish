<?php

declare(strict_types=1);

/**
 * workbench/vendor is a machine-local symlink testbench recreates on every boot. Tracking it
 * commits one developer's absolute path and leaves the tree permanently dirty.
 */
test('workbench/vendor is not tracked by git', function () {
    $repoRoot = dirname(__DIR__, 2);
    $command = 'cd '.escapeshellarg($repoRoot).' && git ls-files -- workbench/vendor';
    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);

    if ($returnCode !== 0) {
        $this->markTestSkipped('git is unavailable or this is not a git checkout');

        return;
    }

    expect(trim(implode("\n", $output)))->toBe('');
});
