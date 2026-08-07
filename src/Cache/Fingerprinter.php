<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Cache;

class Fingerprinter
{
    /**
     * Compute an order-independent fingerprint over a file set, plus an optional non-file input.
     *
     * Missing files contribute a 'missing' marker so their later appearance or removal still moves the hash.
     *
     * @param  list<string>  $paths
     */
    public static function fromPaths(array $paths, string $extra = ''): string
    {
        $paths = array_values(array_unique($paths));
        sort($paths);

        $parts = [];

        foreach ($paths as $path) {
            $hash = is_file($path) ? hash_file('xxh128', $path) : 'missing';
            $parts[] = $path.'@'.$hash;
        }

        if ($extra !== '') {
            $parts[] = '::extra::'.$extra;
        }

        return hash('xxh128', implode("\n", $parts));
    }
}
