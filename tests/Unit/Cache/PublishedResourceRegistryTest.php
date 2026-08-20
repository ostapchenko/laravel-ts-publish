<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Cache\PublishedResourceRegistry;
use Workbench\App\Http\Resources\TeamResource;
use Workbench\App\Http\Resources\UserResource;

afterEach(fn () => PublishedResourceRegistry::reset());

it('starts empty, so it reports no information', function () {
    expect(PublishedResourceRegistry::isEmpty())->toBeTrue();
});

it('fails open while empty: every class counts as published', function () {
    expect(PublishedResourceRegistry::isPublished(TeamResource::class))->toBeTrue()
        ->and(PublishedResourceRegistry::isPublished('Totally\Made\Up\Class'))->toBeTrue();
});

it('narrows to exactly the registered classes once populated', function () {
    PublishedResourceRegistry::register([TeamResource::class]);

    expect(PublishedResourceRegistry::isEmpty())->toBeFalse()
        ->and(PublishedResourceRegistry::isPublished(TeamResource::class))->toBeTrue()
        ->and(PublishedResourceRegistry::isPublished(UserResource::class))->toBeFalse();
});

it('accumulates across register() calls', function () {
    PublishedResourceRegistry::register([TeamResource::class]);
    PublishedResourceRegistry::register([UserResource::class]);

    expect(PublishedResourceRegistry::isPublished(TeamResource::class))->toBeTrue()
        ->and(PublishedResourceRegistry::isPublished(UserResource::class))->toBeTrue();
});

it('returns to the no-information state on reset()', function () {
    PublishedResourceRegistry::register([TeamResource::class]);
    PublishedResourceRegistry::reset();

    expect(PublishedResourceRegistry::isEmpty())->toBeTrue()
        ->and(PublishedResourceRegistry::isPublished(UserResource::class))->toBeTrue();
});
