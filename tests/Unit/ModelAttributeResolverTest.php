<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use AbeTwoThree\LaravelTsPublish\LaravelTsPublish as LaravelTsPublishService;
use AbeTwoThree\LaravelTsPublish\ModelAttributeResolver;
use Workbench\App\Models\CompositeComment;
use Workbench\App\Models\Image;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderItem;
use Workbench\App\Models\Post;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;

test('resolveAttribute returns empty info for non-existent model class', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    $result = $resolver->resolveAttribute('App\\Models\\NonExistent', 'name');

    expect($result)->toBe(LaravelTsPublish::emptyTypeScriptInfo());
});

test('resolveAttribute returns empty info when DB type maps to unknown', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    // 'search_index' on Order has type 'unknown' in the DB schema
    $result = $resolver->resolveAttribute(Order::class, 'search_index');

    expect($result['type'])->toBe('unknown');
});

test('resolveRelation returns unknown for non-existent model class', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    $result = $resolver->resolveRelation('App\\Models\\NonExistent', 'posts');

    expect($result)->toBe(['type' => 'unknown', 'modelFqcn' => null]);
});

test('resolveMethodReturnType returns empty info for non-existent method', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    $result = $resolver->resolveMethodReturnType(User::class, 'nonExistentMethod');

    expect($result)->toBe(LaravelTsPublish::emptyTypeScriptInfo());
});

test('resolveMethodReturnType returns empty info for non-existent class', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    $result = $resolver->resolveMethodReturnType('App\\Models\\NonExistent', 'nonExistentMethod');

    expect($result)->toBe(LaravelTsPublish::emptyTypeScriptInfo());
});

test('resolveAccessorModelFqcn returns null for non-existent model class', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    $result = $resolver->resolveAccessorModelFqcn('App\\Models\\NonExistent', 'name');

    expect($result)->toBeNull();
});

test('resolveAccessorModelFqcn returns null for non-accessor attribute', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    // 'name' is a regular string column, not an accessor
    $result = $resolver->resolveAccessorModelFqcn(User::class, 'name');

    expect($result)->toBeNull();
});

test('resolveAccessorModelFqcn returns null when accessor does not return a Model', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    // 'initials' is an accessor that returns string, not a Model
    $result = $resolver->resolveAccessorModelFqcn(User::class, 'initials');

    expect($result)->toBeNull();
});

test('getAttributes returns null for non-existent model class', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    expect($resolver->getAttributes('App\\Models\\NonExistent'))->toBeNull();
});

test('getRelations returns null for non-existent model class', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    expect($resolver->getRelations('App\\Models\\NonExistent'))->toBeNull();
});

test('getRelationNullable returns null for non-existent model class', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    expect($resolver->getRelationNullable('App\\Models\\NonExistent'))->toBeNull();
});

test('getInstance returns null for non-existent model class', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    expect($resolver->getInstance('App\\Models\\NonExistent'))->toBeNull();
});

test('getReflection returns null for non-existent model class', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    expect($resolver->getReflection('App\\Models\\NonExistent'))->toBeNull();
});

test('buildMorphTargetMap builds map from MorphMany inverse relations', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    $resolver->buildMorphTargetMap([
        User::class,
        Post::class,
        Product::class,
        Image::class,
    ]);

    // User, Post, and Product all have morphMany(Image::class, 'imageable')
    $targets = $resolver->getMorphToTargets(Image::class);

    expect($targets)->toBe([Post::class, Product::class, User::class]);
});

test('getMorphToTargets returns empty array when no inverse relations exist', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    $resolver->buildMorphTargetMap([
        User::class,
        Post::class,
        Image::class,
    ]);

    // CompositeComment has no inverse MorphOne/MorphMany relations in the scanned models
    expect($resolver->getMorphToTargets(CompositeComment::class))->toBe([]);
});

test('getMorphToTargets returns empty array when map is not built', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    // No buildMorphTargetMap() call — default empty map
    expect($resolver->getMorphToTargets(Image::class))->toBe([]);
});

test('buildMorphTargetMap skips non-existent model classes', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    // Should not throw, just skip the non-existent class
    $resolver->buildMorphTargetMap([
        'App\\Models\\NonExistent',
        User::class,
        Image::class,
    ]);

    $targets = $resolver->getMorphToTargets(Image::class);

    expect($targets)->toBe([User::class]);
});

test('resolveRelation returns union type for MorphTo when targets exist', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    $resolver->buildMorphTargetMap([
        User::class,
        Post::class,
        Product::class,
        Image::class,
    ]);

    $result = $resolver->resolveRelation(Image::class, 'imageable');

    expect($result['type'])->toBe('Post | Product | User')
        ->and($result['modelFqcn'])->toBeNull();
});

test('resolveRelation returns unknown for MorphTo when no targets exist', function () {
    $resolver = resolve(ModelAttributeResolver::class);

    // Build map with only Image — no inverse relations for CompositeComment
    $resolver->buildMorphTargetMap([Image::class]);

    $result = $resolver->resolveRelation(CompositeComment::class, 'commentable');

    // CompositeComment has nullable FK columns, so it gets ' | null' appended
    expect($result['type'])->toBe('unknown | null')
        ->and($result['modelFqcn'])->toBeNull();
});

test('attributeDocblockReturnTypes captures nested generic getter type', function () {
    $method = new ReflectionMethod(Order::class, 'sortedItems');
    $info = app(LaravelTsPublishService::class)->attributeDocblockReturnTypes($method);

    // The full 'Attribute<Collection<int, OrderItem>, never>' docblock resolves
    // through the generic-container pipeline to a typed array, carrying the
    // OrderItem FQCN so the import machinery still fires.
    expect($info['type'])->toBe('OrderItem[]')
        ->and($info['classFqcns'])->toBe([OrderItem::class]);
});

test('accessor with vague closure type is refined by Attribute docblock generics', function () {
    $info = resolve(ModelAttributeResolver::class)
        ->resolveAttribute(Order::class, 'sorted_items');

    expect($info['type'])->toBe('OrderItem[]');
});

test('accessor with @phpstan-return docblock resolves through docblock', function () {
    $info = resolve(ModelAttributeResolver::class)
        ->resolveAttribute(Order::class, 'score_map');

    expect($info['type'])->toBe('Record<string, number>');
});

test('bare @return Attribute docblock does not override a usable closure signature type', function () {
    // Regression fixture: 'unsortedItems' pairs a bare `@return Attribute` docblock
    // (no generic args) with a vague `: Collection` closure signature. Before the
    // fix, the fallback @return parser resolved the literal word "Attribute" to the
    // Eloquent Attribute class itself, leaking an undefined/nonsensical TS token
    // through the import machinery. It must instead degrade and fall back to the
    // closure's own (still vague, but at least real) signature type.
    $info = resolve(ModelAttributeResolver::class)
        ->resolveAttribute(Order::class, 'unsorted_items');

    expect($info['type'])->not->toBe('Attribute')
        ->and($info['type'])->toBe('unknown[] | Record<string, unknown>')
        ->and($info['classFqcns'])->toBe([]);
});

test('attributeDocblockReturnTypes resolves Attribute<> written as a fully-qualified class name', function () {
    // Loaded from a `.php.stub` fixture rather than a `.php` class: Pint's
    // fully_qualified_strict_types fixer rewrites any literal FQCN it finds in a
    // docblock — even inside a custom tag, even with no matching `use` import to
    // begin with (it adds one) — down to its short, auto-imported form. Pint's
    // finder only matches `*.php`, so the `.stub` file's literal FQCN text
    // survives untouched. See the fixture file for details.
    require_once __DIR__.'/../Fixtures/FqcnAttributeDocblockFixture.php.stub';

    $method = new ReflectionMethod(FqcnAttributeDocblockFixture::class, 'sortedByFqcnDocblock');
    $info = app(LaravelTsPublishService::class)->attributeDocblockReturnTypes($method);

    expect($info['type'])->toBe('string[]');
});
