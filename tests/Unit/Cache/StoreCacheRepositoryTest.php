<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Cache\StoreCacheRepository;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::store('array')->clear();
    $this->repo = new StoreCacheRepository(Cache::store('array'), 'ts-publish');
});

it('stores and retrieves array payloads with a prefix', function () {
    $this->repo->put('alpha', ['n' => 1]);

    expect($this->repo->get('alpha'))->toBe(['n' => 1])
        ->and(Cache::store('array')->has('ts-publish:alpha'))->toBeTrue();
});

it('returns null for missing keys', function () {
    expect($this->repo->get('nope'))->toBeNull();
});

it('forgets a single key and flushes only its own keys', function () {
    $this->repo->put('a', ['x' => 1]);
    $this->repo->put('b', ['x' => 2]);
    Cache::store('array')->put('unrelated', 'keep');

    $this->repo->forget('a');
    expect($this->repo->get('a'))->toBeNull()->and($this->repo->get('b'))->toBe(['x' => 2]);

    $this->repo->flush();
    expect($this->repo->get('b'))->toBeNull()
        ->and(Cache::store('array')->get('unrelated'))->toBe('keep');
});

it('does not persist the key index on put (only on commit)', function () {
    $this->repo->put('a', ['x' => 1]);

    expect(Cache::store('array')->get('ts-publish:__index__'))->toBeNull();

    $this->repo->commit();

    expect(Cache::store('array')->get('ts-publish:__index__'))->toBe(['a']);
});

it('persists the index on commit so a fresh instance can flush it', function () {
    $this->repo->put('a', ['x' => 1]);
    $this->repo->put('b', ['x' => 2]);
    $this->repo->commit();

    $fresh = new StoreCacheRepository(Cache::store('array'), 'ts-publish');
    $fresh->flush();

    expect($this->repo->get('a'))->toBeNull()
        ->and($this->repo->get('b'))->toBeNull();
});

it('rejects an unsigned attacker-written store entry', function () {
    $repo = new StoreCacheRepository(Cache::store('array'), 'ts-publish', 'signing-secret');

    // An attacker with write access to the store plants a raw array, the shape the unsigned repo accepted.
    Cache::store('array')->forever('ts-publish:evil', ['snapshot' => 'attacker-controlled']);

    expect($repo->get('evil'))->toBeNull();
});

it('round-trips a signed payload', function () {
    $repo = new StoreCacheRepository(Cache::store('array'), 'ts-publish', 'signing-secret');

    $repo->put('entry', ['snapshot' => 'legit']);

    expect($repo->get('entry'))->toBe(['snapshot' => 'legit']);
});

it('rejects a store entry whose signature does not match', function () {
    $repo = new StoreCacheRepository(Cache::store('array'), 'ts-publish', 'signing-secret');
    $repo->put('entry', ['snapshot' => 'legit']);

    Cache::store('array')->forever('ts-publish:entry', 'deadbeef:'.serialize(['snapshot' => 'evil']));

    expect($repo->get('entry'))->toBeNull();
});

it('round-trips a signed payload through a serializing cache store', function () {
    // The `array` store used elsewhere keeps values in memory untouched; FileStore really
    // serialize()s on write and unserialize()s on read, so only it exercises that path.
    $dir = sys_get_temp_dir().'/ts-publish-store-fs-'.uniqid();
    $store = new Repository(new FileStore(new Filesystem, $dir));
    $repo = new StoreCacheRepository($store, 'ts-publish', 'signing-secret');

    $repo->put('entry', ['snapshot' => 'legit']);

    expect($repo->get('entry'))->toBe(['snapshot' => 'legit']);

    (new Filesystem)->deleteDirectory($dir);
});

it('rejects a tampered entry through a serializing cache store', function () {
    $dir = sys_get_temp_dir().'/ts-publish-store-fs-'.uniqid();
    $store = new Repository(new FileStore(new Filesystem, $dir));
    $repo = new StoreCacheRepository($store, 'ts-publish', 'signing-secret');

    $repo->put('entry', ['snapshot' => 'legit']);
    $store->forever('ts-publish:entry', 'deadbeef:'.serialize(['snapshot' => 'evil']));

    expect($repo->get('entry'))->toBeNull();

    (new Filesystem)->deleteDirectory($dir);
});
