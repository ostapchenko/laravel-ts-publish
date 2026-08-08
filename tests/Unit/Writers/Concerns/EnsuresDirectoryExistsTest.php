<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Writers\Concerns\EnsuresDirectoryExists;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/ts-publish-writer-dir-'.uniqid();

    $this->writer = new class
    {
        use EnsuresDirectoryExists;

        public function expose(string $path): void
        {
            $this->ensureDirectoryExists($path);
        }
    };
});

afterEach(function () {
    if (is_dir($this->dir)) {
        @rmdir($this->dir);
    }
});

it('creates a missing directory', function () {
    $this->writer->expose($this->dir);

    expect(is_dir($this->dir))->toBeTrue();
});

it('is a no-op when the directory already exists', function () {
    mkdir($this->dir, 0755, true);

    $this->writer->expose($this->dir);

    expect(is_dir($this->dir))->toBeTrue();
});

it('creates nested directories recursively', function () {
    $nested = $this->dir.'/a/b/c';

    $this->writer->expose($nested);

    expect(is_dir($nested))->toBeTrue();

    // Clean up the nested tree beyond what afterEach() handles.
    @rmdir($nested);
    @rmdir($this->dir.'/a/b');
    @rmdir($this->dir.'/a');
});

it('tolerates concurrent writers racing to create the same output directory', function () {
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl extension is not available.');
    }

    // Parallel Vite builds each shell out to ts:publish, and every Writer shares one output tree,
    // so mkdir() can lose the race. The barrier file releases all children at the same instant.
    $barrier = $this->dir.'.barrier';
    $pids = [];

    for ($i = 0; $i < 8; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('Could not fork a child process.');
        }

        if ($pid === 0) {
            while (! is_file($barrier)) {
                usleep(500);
            }

            try {
                $this->writer->expose($this->dir);
                exit(0);
            } catch (Throwable) {
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    file_put_contents($barrier, '1');

    $failures = 0;

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $failures += pcntl_wexitstatus($status) === 0 ? 0 : 1;
    }

    @unlink($barrier);

    expect($failures)->toBe(0)
        ->and(is_dir($this->dir))->toBeTrue();
});
