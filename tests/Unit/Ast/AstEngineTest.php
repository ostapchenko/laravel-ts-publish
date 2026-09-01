<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Ast\AnalysisImports;
use AbeTwoThree\LaravelTsPublish\Ast\AstEngine;
use AbeTwoThree\LaravelTsPublish\Ast\ModelClassResolver;
use AbeTwoThree\LaravelTsPublish\Ast\PropertyDocblockTypeReader;
use AbeTwoThree\LaravelTsPublish\Tests\Unit\Ast\Fixtures\DeclaredProps;
use Workbench\App\Enums\Role;
use Workbench\App\Events\OrderShipped;
use Workbench\App\Events\UserNotification;
use Workbench\App\Http\Resources\PostResource;
use Workbench\App\Http\Resources\UserResource;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

it('analyzes a broadcast event constructor into typed properties', function () {
    $analysis = resolve(AstEngine::class)->analyzePublicProperties(OrderShipped::class);

    $props = collect($analysis->properties)->keyBy('name');

    expect($props->keys()->all())->toBe(['orderId', 'trackingNumber', 'carrier', 'metadata'])
        ->and($props['orderId']['type'])->toBe('number')
        ->and($props['trackingNumber']['type'])->toBe('string')
        ->and($props['metadata']['type'])->toBe('unknown[] | null')
        ->and($props['metadata']['optional'])->toBeFalse();
});

it('omits trait-declared properties so a #[TsExtends] trait cannot duplicate a field', function () {
    $analysis = resolve(AstEngine::class)->analyzePublicProperties(UserNotification::class);

    expect(array_column($analysis->properties, 'name'))->toBe(['userId', 'title', 'message']);
});

it('reads @var docblocks and class-body declared properties, excluding trait-declared ones', function () {
    $analysis = resolve(AstEngine::class)->analyzePublicProperties(DeclaredProps::class);

    $props = collect($analysis->properties)->keyBy('name');

    expect($props->keys()->all())->toBe(['tags', 'summary', 'owner', 'role', 'id', 'note'])
        ->and($props['tags']['type'])->toBe('string[]')
        ->and($props['summary']['type'])->toBe('{ label: string; count: number }')
        ->and($props['note']['type'])->toBe('string | null')
        ->and($props['note']['optional'])->toBeFalse()
        ->and($props['id']['type'])->toBe('number');
});

it('routes @var and native FQCN channels into the analysis maps', function () {
    $analysis = resolve(AstEngine::class)->analyzePublicProperties(DeclaredProps::class);

    expect($analysis->modelFqcns)->toBe(['owner' => User::class])
        ->and($analysis->directEnumFqcns)->toBe(['role' => Role::class]);
});

it('reads a property @var docblock and returns null when there is none', function () {
    $reader = resolve(PropertyDocblockTypeReader::class);
    $reflection = new ReflectionClass(DeclaredProps::class);

    expect($reader->read($reflection->getProperty('tags')))
        ->toBe(['type' => 'string[]', 'optional' => false])
        ->and($reader->read($reflection->getProperty('id')))->toBeNull();
});

it('analyzes a resource through the public entry with model resolution', function () {
    $analysis = resolve(AstEngine::class)->analyzeMethod(UserResource::class);

    expect(collect($analysis->properties)->firstWhere('name', 'id')['type'])->toBe('number')
        ->and($analysis->enumResources)->toBe(['role' => Role::class])
        ->and($analysis->nestedResources)->toBe(['posts' => PostResource::class]);
});

it('accepts an explicit model class and an arbitrary method name', function () {
    $analysis = resolve(AstEngine::class)->analyzeMethod(UserResource::class, 'toArray', Post::class);

    expect(array_column($analysis->properties, 'name'))->toContain('id', 'name', 'email');
});

it('resolves the backing model the way the resource pipeline does', function () {
    expect(resolve(ModelClassResolver::class)->resolve(new ReflectionClass(UserResource::class)))
        ->toBe(User::class);
});

it('builds import maps from an analysis', function () {
    $analysis = resolve(AstEngine::class)->analyzeMethod(UserResource::class);
    $imports = new AnalysisImports()->build($analysis, 'workbench/app/http/resources');

    expect($imports)->toHaveKeys(['typeImports', 'valueImports'])
        ->and($imports['typeImports'])->toBe([
            '../../enums' => ['RoleType'],
            '../../models' => ['Profile'],
            '.' => ['PostResource'],
        ])
        ->and($imports['valueImports'])->toBe(['../../enums' => ['Role']]);
});

it('resolves import paths relative to the importing file', function () {
    $analysis = resolve(AstEngine::class)->analyzeMethod(UserResource::class);
    $imports = new AnalysisImports()->build($analysis, 'workbench/app/events');

    expect(array_keys($imports['typeImports']))->toBe(['../enums', '../http/resources', '../models']);
});
