<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Cache;

use AbeTwoThree\LaravelTsPublish\Cache\Contracts\CacheRepository;
use AbeTwoThree\LaravelTsPublish\Support\ConfigFingerprint;
use AbeTwoThree\LaravelTsPublish\Support\PackageVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class CacheBootstrap
{
    /**
     * Whether the generation cache is enabled in config.
     */
    public static function enabled(): bool
    {
        return Config::boolean('ts-publish.cache.enabled', true);
    }

    /**
     * Build the configured cache backend: a Laravel store when
     * `ts-publish.cache.store` is set, otherwise the file backend.
     */
    public static function repository(): CacheRepository
    {
        $store = Config::get('ts-publish.cache.store');

        if (is_string($store) && $store !== '') {
            return new StoreCacheRepository(Cache::store($store), 'ts-publish', self::signingKey());
        }

        $directory = Config::string('ts-publish.cache.directory', storage_path('framework/cache/ts-publish'));

        return new FileCacheRepository($directory, self::signingKey());
    }

    /**
     * Load a manifest stamped with the package version and config hash, so either changing busts the cache.
     */
    public static function manifest(?CacheRepository $repository = null): GenerationManifest
    {
        return GenerationManifest::load(
            $repository ?? self::repository(),
            PackageVersion::current(),
            ConfigFingerprint::compute(),
        );
    }

    /**
     * Resolve the HMAC signing key for both cache backends, preferring `ts-publish.cache.key` over `app.key`.
     *
     * Returns null only when neither is set, which leaves payloads unsigned.
     */
    protected static function signingKey(): ?string
    {
        $key = Config::get('ts-publish.cache.key');

        if (is_string($key) && $key !== '') {
            return $key;
        }

        $appKey = Config::get('app.key');

        return is_string($appKey) && $appKey !== '' ? $appKey : null;
    }
}
