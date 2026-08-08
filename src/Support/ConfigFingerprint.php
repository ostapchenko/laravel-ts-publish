<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Support;

use Illuminate\Support\Facades\Config;
use Throwable;

class ConfigFingerprint
{
    /**
     * Hash the output-affecting `ts-publish` config so the cache busts when it changes.
     *
     * The `cache` sub-array is excluded: toggling the cache must not bust outputs.
     */
    public static function compute(): string
    {
        /** @var array<string, mixed> $config */
        $config = Config::array('ts-publish');

        unset($config['cache']);

        self::ksortRecursive($config);

        try {
            return hash('xxh128', serialize($config));
        } catch (Throwable) {
            // A non-serializable config value (e.g. a closure) must not crash generation. A per-run
            // token can never match a stored manifest header, forcing a full rebuild over stale output.
            return 'unfingerprintable-'.bin2hex(random_bytes(16));
        }
    }

    /**
     * Recursively sort an array by key so the fingerprint ignores config declaration order.
     *
     * @param  array<array-key, mixed>  $array
     */
    private static function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }

        unset($value);

        ksort($array);
    }
}
